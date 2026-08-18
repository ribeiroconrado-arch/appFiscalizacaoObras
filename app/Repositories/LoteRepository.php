<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Todo o SQL espacial do sistema vive AQUI, e só aqui.
 *
 * O motivo é concreto: a escolha do MySQL sobre o PostGIS foi tomada com
 * critério registrado (docs/ADR-001-banco-espacial.md) e pode um dia ser
 * revista. Concentrando as consultas espaciais num arquivo, trocar de banco
 * significa reescrever esta classe e as migrations — não a aplicação inteira.
 *
 * ⚠ ORDEM DOS EIXOS ⚠
 * Em SRID 4326 o MySQL usa a ordem lat/long. Todo WKT construído aqui declara
 * 'axis-order=long-lat'. Sem isso a consulta não falha: ela devolve vazio, ou
 * pior, devolve o lote errado — o erro mais caro de diagnosticar deste módulo.
 */
class LoteRepository
{
    /** Colunas devolvidas nas consultas de identificação (sem a geometria). */
    private const CAMPOS = 'id, bairro, quadra, numero_lote, chave, area_gis_m2, inscricao_imobiliaria';

    /**
     * Lote que CONTÉM a coordenada. É o caminho feliz do fluxo de GPS.
     * Usa o índice espacial via ST_Contains.
     */
    public function contendo(float $lat, float $lon): ?object
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                  FROM lotes
                 WHERE ST_Contains(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))
                 LIMIT 1';

        return DB::selectOne($sql, [$this->ponto($lat, $lon)]);
    }

    /**
     * Lotes próximos, ordenados por distância. Chamado quando o ponto não caiu
     * dentro de nenhum lote — tipicamente porque o fiscal está na rua.
     *
     * O MySQL não tem `ST_DWithin`, e `ST_Buffer` não aceita SRID geográfico.
     * Por isso a busca é em dois tempos:
     *
     *   1. um envelope em volta do ponto reduz o universo usando o ÍNDICE
     *      espacial (`MBRIntersects`);
     *   2. `ST_Distance` mede e ordena de verdade, já sobre poucas linhas.
     *
     * Fazer só o passo 2 seria igualmente correto e inaceitavelmente lento:
     * `ST_Distance` não usa índice, então seria varredura da tabela toda a cada
     * toque no botão de GPS.
     *
     * Sobre a função de distância: em SRS geográfico, `ST_Distance(polígono,
     * ponto)` devolve METROS até a divisa do lote — e é essa a medida que
     * interessa ("a que distância estou do lote"), não a distância até o
     * centroide. Vale registrar por que não é o óbvio: `ST_Distance_Sphere` só
     * aceita Point/MultiPoint, e `ST_Centroid`, `ST_Envelope` e `ST_Buffer`
     * simplesmente NÃO são implementados para SRS geográfico no MySQL 8 —
     * levantam `ERROR 3618` em tempo de execução, não em tempo de escrita.
     *
     * @return array<int,object> cada item traz `dist_m`
     */
    public function proximos(float $lat, float $lon, float $toleranciaM, int $limite = 6): array
    {
        // Graus por metro. A conversão da longitude depende da latitude, porque
        // os meridianos convergem em direção aos polos.
        $dLat = $toleranciaM / 111320;
        $dLon = $toleranciaM / (111320 * max(cos(deg2rad($lat)), 0.01));

        $envelope = $this->envelope($lat, $lon, $dLat, $dLon);

        $sql = 'SELECT ' . self::CAMPOS . ',
                       ST_Distance(geom,
                           ST_GeomFromText(?, 4326, \'axis-order=long-lat\')) AS dist_m
                  FROM lotes
                 WHERE MBRIntersects(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))
                HAVING dist_m <= ?
              ORDER BY dist_m
                 LIMIT ' . (int) $limite;

        return DB::select($sql, [$this->ponto($lat, $lon), $envelope, $toleranciaM]);
    }

    /**
     * Lotes dentro de um retângulo do mapa, já como GeoJSON.
     *
     * A API nunca devolve a cidade inteira: são 23.662 lotes no município, e
     * despejar isso de uma vez trava o aparelho do fiscal. O `$limite` é a rede
     * de segurança para um bbox absurdamente grande — quem controla o volume de
     * verdade é o zoom mínimo aplicado no cliente.
     *
     * @return array<int,object> cada item traz `geojson` (string)
     */
    public function porBbox(float $oeste, float $sul, float $leste, float $norte, int $limite): array
    {
        $sql = 'SELECT ' . self::CAMPOS . ', ST_AsGeoJSON(geom) AS geojson
                  FROM lotes
                 WHERE MBRIntersects(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))
                 LIMIT ' . (int) $limite;

        return DB::select($sql, [$this->retangulo($oeste, $sul, $leste, $norte)]);
    }

    /** Total de lotes carregados. Usado pelo cabeçalho do mapa e pela conferência. */
    public function total(): int
    {
        return (int) DB::scalar('SELECT COUNT(*) FROM lotes');
    }

    /**
     * Retângulo que contém toda a base — para o mapa abrir enquadrando o que
     * existe, em vez de uma coordenada fixa no código.
     *
     * Usa o PRIMEIRO VÉRTICE do anel externo de cada lote, e não o envelope da
     * geometria, porque `ST_Envelope` não é implementado para SRS geográfico
     * no MySQL. Para enquadrar o mapa a aproximação é irrelevante: erra por
     * alguns metros na borda de um lote de 12 m.
     *
     * Atenção à ordem dos eixos: em SRID 4326 o MySQL guarda lat/long, então
     * `ST_X` devolve a LATITUDE e `ST_Y` a longitude.
     *
     * @return array{sul:float,oeste:float,norte:float,leste:float}|null
     */
    public function extensao(?string $bairro = null): ?array
    {
        $sql = 'SELECT MIN(ST_X(p)) AS sul, MAX(ST_X(p)) AS norte,
                       MIN(ST_Y(p)) AS oeste, MAX(ST_Y(p)) AS leste
                  FROM (SELECT ST_PointN(ST_ExteriorRing(geom), 1) AS p
                          FROM lotes' . ($bairro ? ' WHERE bairro = ?' : '') . ') t';

        $r = DB::selectOne($sql, $bairro ? [$bairro] : []);

        return $r && $r->sul !== null
            ? ['sul' => (float) $r->sul, 'norte' => (float) $r->norte,
               'oeste' => (float) $r->oeste, 'leste' => (float) $r->leste]
            : null;
    }

    /**
     * Conferência pós-importação. Um SRID errado não lança erro — dá mapa vazio,
     * que é bem pior de diagnosticar. Por isso a verificação é explícita.
     */
    public function diagnostico(): object
    {
        return DB::selectOne('SELECT COUNT(*)                    AS total,
                                     SUM(ST_SRID(geom) <> 4326)  AS srid_errado,
                                     SUM(NOT ST_IsValid(geom))   AS geometria_invalida,
                                     COUNT(DISTINCT chave)       AS chaves_distintas,
                                     COUNT(DISTINCT bairro)      AS bairros
                                FROM lotes');
    }

    // ── construção de WKT ────────────────────────────────────────
    // Sempre na ordem (longitude latitude), casando com 'axis-order=long-lat'.

    private function ponto(float $lat, float $lon): string
    {
        return sprintf('POINT(%.10F %.10F)', $lon, $lat);
    }

    private function envelope(float $lat, float $lon, float $dLat, float $dLon): string
    {
        return $this->retangulo($lon - $dLon, $lat - $dLat, $lon + $dLon, $lat + $dLat);
    }

    private function retangulo(float $oeste, float $sul, float $leste, float $norte): string
    {
        return sprintf(
            'POLYGON((%1$.10F %2$.10F, %3$.10F %2$.10F, %3$.10F %4$.10F, %1$.10F %4$.10F, %1$.10F %2$.10F))',
            $oeste, $sul, $leste, $norte
        );
    }
}
