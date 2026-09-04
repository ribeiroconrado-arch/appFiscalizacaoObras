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
    /**
     * Colunas devolvidas nas consultas de identificação (sem a geometria).
     *
     * `desmembramento` entra porque é a ÚLTIMA parte da inscrição imobiliária,
     * derivada a partir daqui (ver App\Cadastro\BairrosDoDesenho). Hoje é 0 em
     * todos os lotes, então a falta não aparecia; apareceria na primeira parte
     * de desmembramento desenhada, com a variação saindo 000 em vez de 001.
     */
    private const CAMPOS = 'id, bairro, quadra, numero_lote, desmembramento, chave, area_gis_m2, inscricao_imobiliaria';

    /**
     * Recorte padrão de TODA consulta de mapa, GPS e busca.
     *
     * Lote baixado — o que foi unificado ou desmembrado — continua na base
     * para o histórico do imóvel responder por si, mas não é mais um imóvel
     * que existe: não pode ser pintado no mapa, encontrado pelo GPS do fiscal
     * em campo nem devolvido numa busca de balcão.
     *
     * É constante, e não um método, porque entra por concatenação em SQL cru.
     * Fica a um lugar só para a próxima consulta espacial não esquecer dela —
     * esquecer é silencioso: devolve dado a mais, nunca erro.
     */
    private const SO_ATIVOS = "situacao = 'ativo'";

    /**
     * Lote que CONTÉM a coordenada. É o caminho feliz do fluxo de GPS.
     * Usa o índice espacial via ST_Contains.
     */
    public function contendo(float $lat, float $lon): ?object
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                  FROM lotes
                 WHERE ' . self::SO_ATIVOS . '
                   AND ST_Contains(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))
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
                 WHERE ' . self::SO_ATIVOS . '
                   AND MBRIntersects(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))
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
                 WHERE ' . self::SO_ATIVOS . '
                   AND MBRIntersects(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))
                 LIMIT ' . (int) $limite;

        return DB::select($sql, [$this->retangulo($oeste, $sul, $leste, $norte)]);
    }

    /** Total de lotes carregados. Usado pelo cabeçalho do mapa e pela conferência. */
    public function total(): int
    {
        return (int) DB::scalar('SELECT COUNT(*) FROM lotes WHERE ' . self::SO_ATIVOS);
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
                          FROM lotes
                         WHERE ' . self::SO_ATIVOS
                            . ($bairro ? ' AND bairro = ?' : '') . ') t';

        $r = DB::selectOne($sql, $bairro ? [$bairro] : []);

        return $r && $r->sul !== null
            ? ['sul' => (float) $r->sul, 'norte' => (float) $r->norte,
               'oeste' => (float) $r->oeste, 'leste' => (float) $r->leste]
            : null;
    }

    /**
     * Conferência pós-importação. Um SRID errado não lança erro — dá mapa vazio,
     * que é bem pior de diagnosticar. Por isso a verificação é explícita.
     *
     * É a ÚNICA consulta deste repositório que não filtra por lote ativo, e de
     * propósito: geometria inválida ou SRID errado num lote baixado é defeito
     * do mesmo jeito, e escondê-lo da conferência derrotaria o objetivo dela.
     * Os baixados aparecem contados à parte.
     */
    public function diagnostico(): object
    {
        return DB::selectOne("SELECT COUNT(*)                    AS total,
                                     SUM(situacao = 'baixado')   AS baixados,
                                     SUM(ST_SRID(geom) <> 4326)  AS srid_errado,
                                     SUM(NOT ST_IsValid(geom))   AS geometria_invalida,
                                     COUNT(DISTINCT chave)       AS chaves_distintas,
                                     COUNT(DISTINCT bairro)      AS bairros
                                FROM lotes");
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

    // ── ESCRITA E MEDIDA DE GEOMETRIA ────────────────────────────
    //
    // Tudo abaixo serve às correções cadastrais feitas pelo mapa: desenhar
    // lote faltante, desmembrar, unificar.
    //
    // Uma divisão de trabalho vale a pena registrar, porque não é arbitrária:
    // o BANCO responde o que sabe responder — se a geometria é válida, qual a
    // área de UM polígono, quais lotes um retângulo alcança (índice espacial) —
    // e o PHP mede o que o banco erra. Em SRID 4326 o `ST_Intersection` do
    // MySQL devolveu 215,5 m² de área comum entre dois lotes de 214,47 m² que
    // na verdade não se sobrepõem; a medida certa sai de
    // App\Support\GeometriaPlana, conferida contra o shapely com divergência
    // máxima de 0,00005 m² em 60 lotes reais.

    /**
     * Anel externo de um lote, em [[lon,lat],…] — a forma que GeometriaPlana
     * e o GeoJSON usam.
     *
     * @return list<array{0:float,1:float}>|null
     */
    public function anel(int $id): ?array
    {
        $gj = DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [$id]);

        return $gj ? (json_decode($gj, true)['coordinates'][0] ?? null) : null;
    }

    /**
     * Área de um polígono GeoJSON, medida pelo BANCO, em m².
     *
     * `ST_Area` de um polígono só (sem operação booleana antes) é confiável em
     * SRID 4326 — foi conferido contra a área cadastral do DWG com diferença
     * de 0,23%. É a medida que vai para `area_gis_m2`, para o lote desenhado
     * ficar na mesma régua dos 2.239 que vieram da importação.
     */
    public function areaDoGeoJson(string $geojson): float
    {
        return (float) DB::scalar(
            'SELECT ST_Area(ST_GeomFromGeoJSON(?, 1, 4326))', [$geojson]
        );
    }

    /** O polígono fecha, não se cruza e é aceito pelo MySQL? */
    public function ehValido(string $geojson): bool
    {
        return (bool) DB::scalar(
            'SELECT ST_IsValid(ST_GeomFromGeoJSON(?, 1, 4326))', [$geojson]
        );
    }

    /** Tipo devolvido pelo MySQL ao ler o documento — recusa MultiPolygon. */
    public function tipoDoGeoJson(string $geojson): string
    {
        return (string) DB::scalar(
            'SELECT ST_GeometryType(ST_GeomFromGeoJSON(?, 1, 4326))', [$geojson]
        );
    }

    /**
     * Lotes ATIVOS cujo retângulo envolvente alcança este polígono.
     *
     * É só o primeiro filtro, de propósito: `MBRIntersects` compara retângulos,
     * usa o índice espacial e não erra — mas responde "talvez", não "sim".
     * Quem decide se há sobreposição de verdade é GeometriaPlana, sobre os
     * anéis devolvidos aqui.
     *
     * @return array<int,object> com id, quadra, numero_lote e `anel`
     */
    public function candidatosASobrepor(string $geojson, string $bairro, array $ignorar = []): array
    {
        $linhas = DB::select(
            'SELECT id, quadra, numero_lote, ST_AsGeoJSON(geom) AS geojson
               FROM lotes
              WHERE ' . self::SO_ATIVOS . '
                AND bairro = ?
                AND MBRIntersects(geom, ST_GeomFromGeoJSON(?, 1, 4326))'
            . ($ignorar ? ' AND id NOT IN (' . implode(',', array_map('intval', $ignorar)) . ')' : ''),
            [$bairro, $geojson]
        );

        foreach ($linhas as $l) {
            $l->anel = json_decode($l->geojson, true)['coordinates'][0] ?? [];
            unset($l->geojson);
        }

        return $linhas;
    }

    /**
     * Distância em metros até o lote ativo mais próximo do bairro.
     *
     * Serve para recusar desenho no lugar errado. Não há polígono de limite
     * municipal no banco — o contorno do mapa vem de um GeoJSON no front —,
     * então "está perto de outro lote do mesmo bairro" é a prova possível.
     *
     * `ST_Distance` é uma das funções que o MySQL implementa corretamente em
     * SRS geográfico, e devolve metros até a DIVISA, que é a medida certa aqui.
     */
    public function distanciaAoBairro(string $geojson, string $bairro): ?float
    {
        $d = DB::scalar(
            'SELECT MIN(ST_Distance(geom, ST_GeomFromGeoJSON(?, 1, 4326)))
               FROM lotes WHERE ' . self::SO_ATIVOS . ' AND bairro = ?',
            [$geojson, $bairro]
        );

        return $d === null ? null : (float) $d;
    }

    /**
     * União de vários lotes, encadeada.
     *
     * O `ST_Union` do MySQL é binário — não existe versão agregada —, então a
     * acumulação acontece aqui, id a id, em GeoJSON.
     *
     * O que se devolve é matéria-prima, não veredito: o TIPO importa tanto
     * quanto a geometria. Lotes que não se encostam produzem `MULTIPOLYGON`,
     * que a coluna `geom POLYGON` recusaria no INSERT — melhor devolver o tipo
     * e deixar o serviço dar a mensagem do que colher uma exceção de banco.
     *
     * A ÁREA daqui é a do MySQL e serve só de referência cruzada. Quem decide
     * é GeometriaPlana: em SRID 4326 as operações booleanas do MySQL já se
     * mostraram erradas (o `ST_Intersection` reportou 215,5 m² entre dois lotes
     * de 214,47 m² que não se sobrepõem).
     *
     * @param  list<int>  $ids
     * @return array{geojson:string, tipo:string, area_m2:float, furos:int}|null
     */
    public function uniao(array $ids): ?array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) < 2) {
            return null;
        }

        $acumulado = DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [array_shift($ids)]);
        if (! $acumulado) {
            return null;
        }

        foreach ($ids as $id) {
            $acumulado = DB::scalar(
                'SELECT ST_AsGeoJSON(ST_Union(ST_GeomFromGeoJSON(?, 1, 4326), geom))
                   FROM lotes WHERE id = ?',
                [$acumulado, $id]
            );
            if (! $acumulado) {
                return null;
            }
        }

        $r = DB::selectOne(
            'SELECT ST_GeometryType(g) tipo, ST_Area(g) area,
                    IF(ST_GeometryType(g) = "POLYGON", ST_NumInteriorRing(g), 0) furos
               FROM (SELECT ST_GeomFromGeoJSON(?, 1, 4326) g) t',
            [$acumulado]
        );

        return [
            'geojson' => $acumulado,
            'tipo'    => (string) $r->tipo,
            'area_m2' => (float) $r->area,
            'furos'   => (int) $r->furos,
        ];
    }

    /**
     * O que sobra de um lote depois de tirar dele os polígonos informados.
     *
     * É como a ÚLTIMA parte de um desmembramento é obtida: em vez de exigir
     * que o operador desenhe N partes perfeitamente encaixadas, ele desenha
     * N−1 e a última é o resto — e aí `∪ partes = pai` por construção, exato,
     * sem depender de tolerância nenhuma.
     *
     * O tipo do resultado importa: `MULTIPOLYGON` significa que as partes
     * desenhadas partiram o resto em ilhas separadas, e isso não é um lote.
     *
     * A ÁREA devolvida é a do MySQL e serve de referência cruzada apenas —
     * quem mede é GeometriaPlana, pelo anel devolvido aqui.
     *
     * @param  list<string>  $geojsons  partes a subtrair
     * @return array{geojson:string, tipo:string, area_m2:float, furos:int}|null
     */
    public function diferenca(int $paiId, array $geojsons): ?array
    {
        $acumulado = DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [$paiId]);
        if (! $acumulado) {
            return null;
        }

        foreach ($geojsons as $g) {
            $acumulado = DB::scalar(
                'SELECT ST_AsGeoJSON(ST_Difference(
                    ST_GeomFromGeoJSON(?, 1, 4326), ST_GeomFromGeoJSON(?, 1, 4326)))',
                [$acumulado, $g]
            );
            if (! $acumulado) {
                return null;   // a subtração esvaziou: as partes cobrem o pai inteiro
            }
        }

        $r = DB::selectOne(
            'SELECT ST_GeometryType(g) tipo, ST_Area(g) area,
                    IF(ST_GeometryType(g) = "POLYGON", ST_NumInteriorRing(g), 0) furos
               FROM (SELECT ST_GeomFromGeoJSON(?, 1, 4326) g) t',
            [$acumulado]
        );

        return [
            'geojson' => $acumulado,
            'tipo'    => (string) $r->tipo,
            'area_m2' => (float) $r->area,
            'furos'   => (int) $r->furos,
        ];
    }

    /**
     * Cria um lote com geometria e devolve o id.
     *
     * O INSERT é cru porque `geom` é NOT NULL e só se escreve por expressão
     * SQL — `Lote::create()` não dá conta. A auditoria fica por conta de quem
     * chama (`registrarAuditoria`, que é público justamente para isto), e o
     * SQL espacial continua morando só aqui, como manda a ADR-001.
     *
     * `ST_GeomFromGeoJSON` e não WKT: com GeoJSON o MySQL aplica a RFC 7946
     * (longitude, latitude) sozinho, e a ordem dos eixos — o erro mais caro de
     * diagnosticar deste módulo — nunca precisa ser pensada de novo.
     *
     * @param  array<string,mixed>  $atributos
     */
    public function criarComGeometria(array $atributos, string $geojson): int
    {
        $colunas = array_keys($atributos);
        $marcas  = array_fill(0, count($colunas), '?');

        DB::insert(
            'INSERT INTO lotes (' . implode(', ', $colunas) . ', geom, created_at, updated_at)
             VALUES (' . implode(', ', $marcas) . ', ST_GeomFromGeoJSON(?, 1, 4326), ?, ?)',
            [...array_values($atributos), $geojson, now(), now()]
        );

        return (int) DB::getPdo()->lastInsertId();
    }
}
