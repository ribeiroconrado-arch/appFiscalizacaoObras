<?php

namespace App\Repositories;

use App\Support\GeometriaPlana;
use Illuminate\Support\Facades\Cache;
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
    private const CAMPOS = 'id, bairro, quadra, numero_lote, desmembramento, chave, area_gis_m2, inscricao_imobiliaria, inscricao_montada, origem, importacao_id, em_revisao, frente_m, fundos_m';

    /**
     * Recorte padrão de TODA consulta de mapa, GPS e busca.
     *
     * Lote inativo — o que foi unificado ou desmembrado — continua na base
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
     * Ativo E liberado a todos: o recorte das leituras de quem não revisa.
     *
     * Lote de importação em revisão (`em_revisao`) só aparece para o curador,
     * que é quem o confere antes de o administrador publicar. Mapa, GPS, busca
     * e contagem usam ESTE; as consultas das ferramentas de desenho continuam
     * com SO_ATIVOS, porque desenhar por cima de um lote em revisão também é
     * sobreposição.
     */
    private const SO_PUBLICADOS = "situacao = 'ativo' AND em_revisao = 0";

    /**
     * Retângulo de uma importação — para a tela de revisão levar o mapa até ela.
     *
     * @return array{sul:float,oeste:float,norte:float,leste:float}|null
     */
    public function extensaoDaImportacao(int $importacaoId): ?array
    {
        $r = DB::selectOne('SELECT MIN(ST_X(p)) AS sul, MAX(ST_X(p)) AS norte,
                                   MIN(ST_Y(p)) AS oeste, MAX(ST_Y(p)) AS leste
                              FROM (SELECT ST_PointN(ST_ExteriorRing(geom), 1) AS p
                                      FROM lotes WHERE importacao_id = ?) t', [$importacaoId]);

        return $r && $r->sul !== null
            ? ['sul' => (float) $r->sul, 'norte' => (float) $r->norte,
               'oeste' => (float) $r->oeste, 'leste' => (float) $r->leste]
            : null;
    }

    /**
     * O "centro" de cada quadra de um bairro — a média do primeiro vértice dos
     * lotes dela. É onde a conferência com o cadastro põe o selo "N sem lote":
     * o imóvel do cadastro sem desenho não tem lugar no mapa, mas a quadra
     * dele tem. Mesma aproximação de extensaoDaImportacao (ST_Centroid não
     * vale em SRID geográfico): para pôr um selo, sobra.
     *
     * Chave: a quadra SEM zero à esquerda ("05" e "5" são a mesma).
     *
     * @param  list<string>  $nomes  os nomes de desenho do bairro
     * @return array<string, array{0: float, 1: float}>  quadra => [lat, lon]
     */
    public function centrosDasQuadras(array $nomes): array
    {
        if (! $nomes) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($nomes), '?'));
        $linhas = DB::select("SELECT quadra, AVG(ST_X(p)) AS lat, AVG(ST_Y(p)) AS lon
                                FROM (SELECT quadra, ST_PointN(ST_ExteriorRing(geom), 1) AS p
                                        FROM lotes
                                       WHERE situacao = 'ativo' AND quadra IS NOT NULL AND bairro IN ({$marcas})) t
                               GROUP BY quadra", $nomes);

        $centros = [];
        foreach ($linhas as $l) {
            $k = ltrim((string) $l->quadra, '0') ?: '0';
            $centros[$k] = [round((float) $l->lat, 7), round((float) $l->lon, 7)];
        }

        return $centros;
    }

    /**
     * Lote que CONTÉM a coordenada. É o caminho feliz do fluxo de GPS.
     * Usa o índice espacial via ST_Contains.
     */
    public function contendo(float $lat, float $lon): ?object
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                  FROM lotes
                 WHERE ' . self::SO_PUBLICADOS . '
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
                 WHERE ' . self::SO_PUBLICADOS . '
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
    /**
     * @param  int|null  $rascunhosDe  com revisão: além dos lotes em revisão,
     *         só os RASCUNHOS deste usuário (null = de todos, o administrador).
     */
    public function porBbox(float $oeste, float $sul, float $leste, float $norte, int $limite,
                            bool $incluirRevisao = false, ?int $rascunhosDe = null): array
    {
        $params = [$this->retangulo($oeste, $sul, $leste, $norte)];
        $alheios = '';
        if ($incluirRevisao && $rascunhosDe !== null) {
            $alheios = " AND (em_revisao = 0 OR importacao_id NOT IN (
                            SELECT id FROM importacoes_lotes WHERE status = 'rascunho' AND user_id <> ?))";
            $params[] = $rascunhosDe;
        }

        // 8 casas decimais: ~1 mm no terreno, bem abaixo do centímetro em que
        // as medidas dos lados são mostradas — e o ST_AsGeoJSON sem limite
        // escreve até 15 casas por coordenada, que só pesam na resposta.
        $sql = 'SELECT ' . self::CAMPOS . ', ST_AsGeoJSON(geom, 8) AS geojson
                  FROM lotes
                 WHERE ' . ($incluirRevisao ? self::SO_ATIVOS : self::SO_PUBLICADOS) . '
                   AND MBRIntersects(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))' . $alheios . '
                 LIMIT ' . (int) $limite;

        return DB::select($sql, $params);
    }

    /** Total de lotes carregados. Usado pelo cabeçalho do mapa e pela conferência. */
    public function total(): int
    {
        // Em cache: a rota do mapa (/) mostra o total a cada abertura, e
        // contar 50 mil linhas a cada página aberta é custo sem leitura — o
        // número é informativo, alguns minutos de atraso não mudam nada.
        return (int) Cache::remember('mapa:total-lotes', 600,
            fn () => DB::scalar('SELECT COUNT(*) FROM lotes WHERE ' . self::SO_PUBLICADOS));
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
        // A extensão da base inteira percorre todos os lotes e só muda quando
        // entra um bairro novo: guardada por 10 minutos. A de um bairro (usada
        // pelo contorno) é pequena e vem sempre fresca.
        if ($bairro === null) {
            return Cache::remember('mapa:extensao', 600, fn () => $this->calcularExtensao(null));
        }

        return $this->calcularExtensao($bairro);
    }

    /** @return array{sul:float,oeste:float,norte:float,leste:float}|null */
    private function calcularExtensao(?string $bairro): ?array
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
     * propósito: geometria inválida ou SRID errado num lote inativo é defeito
     * do mesmo jeito, e escondê-lo da conferência derrotaria o objetivo dela.
     * Os inativos aparecem contados à parte.
     */
    public function diagnostico(): object
    {
        return DB::selectOne("SELECT COUNT(*)                    AS total,
                                     SUM(situacao = 'inativo')   AS inativos,
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
     * Área de um polígono GeoJSON, em m² de GRADE (UTM). É a medida que vai
     * para `area_gis_m2` de todo lote novo.
     *
     * SAIU DO BANCO de propósito. O `ST_Area` em SRID 4326 devolve área
     * GEODÉSICA, que é área de terreno: ficava 0,126% abaixo da área de grade
     * com que os 2.235 lotes importados foram gravados — o pipeline de
     * extração os mediu em UTM, antes de reprojetar. O lote desenhado nascia
     * assim numa régua diferente da do vizinho, e a conta de desmembramento
     * (partes contra o pai) comparava as duas.
     *
     * Num lote de 360 m² a diferença é de 0,45 m² — quase toda a tolerância de
     * sobreposição, gasta sem nada ter acontecido. Ver App\Support\GeometriaPlana.
     */
    public function areaDoGeoJson(string $geojson): float
    {
        // Anel externo do Polygon. Quem chama já recusou MultiPolygon —
        // ver `tipoDoGeoJson`, que é conferido antes de gravar.
        $anel = json_decode($geojson, true)['coordinates'][0] ?? [];

        return GeometriaPlana::area(GeometriaPlana::projetar($anel));
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
    // ── CONTORNO DOS BAIRROS ─────────────────────────────────────
    //
    // O contorno é CALCULADO no navegador do curador (união + fechamento, com
    // JSTS): o ST_Buffer negativo do MySQL corrompe multipolígono grande — no
    // Buritis V a área caiu de 37 ha para 1,6 ha. Aqui só se lê, confere e grava.

    /**
     * Os lotes ativos de um bairro, em GeoJSON, para o cálculo do contorno do
     * bairro, das quadras e dos nomes de rua.
     *
     * Com o código do bairro no cadastro, cada lote vem com o LOGRADOURO do
     * seu endereço — a mesma ligação de CadastroCarregado::linhasDoLote
     * (bairro, quadra e lote, sem zeros à esquerda), feita de uma vez para o
     * bairro inteiro. Imóvel ausente da última carga não conta. Lote com
     * várias unidades leva o logradouro mais frequente entre elas.
     *
     * @return array<int,object> id, quadra, geojson, logradouro
     */
    public function lotesDoBairro(string $bairro, ?string $codigoCadastro = null): array
    {
        if ($codigoCadastro === null) {
            return DB::select('SELECT id, quadra, ST_AsGeoJSON(geom) AS geojson, NULL AS logradouro FROM lotes
                                WHERE ' . self::SO_ATIVOS . ' AND bairro = ?', [$bairro]);
        }

        return DB::select("SELECT l.id, l.quadra, ST_AsGeoJSON(l.geom) AS geojson, c.logradouro
                             FROM lotes l
                             LEFT JOIN (
                                   SELECT q, n, logradouro FROM (
                                          SELECT TRIM(LEADING '0' FROM quadra) AS q, TRIM(LEADING '0' FROM lote) AS n, logradouro,
                                                 ROW_NUMBER() OVER (PARTITION BY TRIM(LEADING '0' FROM quadra), TRIM(LEADING '0' FROM lote)
                                                                    ORDER BY COUNT(*) DESC, logradouro) AS ordem
                                            FROM cadastro_externo_imoveis
                                           WHERE TRIM(LEADING '0' FROM codigo_bairro) = ?
                                             AND ausente_desde_carga_id IS NULL
                                             AND logradouro IS NOT NULL AND logradouro <> ''
                                           GROUP BY q, n, logradouro) t
                                    WHERE ordem = 1) c
                                -- COLLATE dos DOIS lados: `lotes` e o cadastro foram criados com
                                -- collations diferentes (0900_ai_ci e unicode_ci), e o MySQL recusa
                                -- comparar as duas sem que alguém diga qual vale (erro 1267).
                                ON c.q COLLATE utf8mb4_unicode_ci = TRIM(LEADING '0' FROM l.quadra) COLLATE utf8mb4_unicode_ci
                               AND c.n COLLATE utf8mb4_unicode_ci = TRIM(LEADING '0' FROM l.numero_lote) COLLATE utf8mb4_unicode_ci
                            WHERE l." . self::SO_ATIVOS . ' AND l.bairro = ?', [ltrim($codigoCadastro, '0'), $bairro]);
    }

    /**
     * Os logradouros do cadastro num bairro — a lista oficial de onde o
     * curador escolhe o nome de um trecho de rua.
     *
     * @return list<string>
     */
    public function logradourosDoBairro(string $codigoCadastro): array
    {
        return DB::table('cadastro_externo_imoveis')
            ->whereRaw("TRIM(LEADING '0' FROM codigo_bairro) = ?", [ltrim($codigoCadastro, '0')])
            ->whereNull('ausente_desde_carga_id')
            ->whereNotNull('logradouro')->where('logradouro', '<>', '')
            ->distinct()->orderBy('logradouro')->pluck('logradouro')->all();
    }

    /**
     * Lotes de OUTROS bairros em volta deste — o que a fusão do contorno não
     * pode engolir. O recorte é a extensão do bairro com folga de ~300 m.
     *
     * @return array<int,object> geojson
     */
    public function lotesVizinhos(string $bairro): array
    {
        $e = $this->extensao($bairro);
        if (! $e) {
            return [];
        }
        $f = 0.003;   // ~300 m
        $sql = 'SELECT ST_AsGeoJSON(geom) AS geojson FROM lotes
                 WHERE ' . self::SO_ATIVOS . ' AND bairro <> ?
                   AND MBRIntersects(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))';

        return DB::select($sql, [$bairro, $this->retangulo($e['oeste'] - $f, $e['sul'] - $f, $e['leste'] + $f, $e['norte'] + $f)]);
    }

    /**
     * Os contornos gravados, já em GeoJSON, com a área medida pelo banco.
     *
     * @return array<int,object>
     */
    public function contornosDosBairros(): array
    {
        return DB::select('SELECT nome, codigo, raio_m, lotes_contados, contorno_em, isolados,
                                  ST_Area(geom) AS area_m2, ST_AsGeoJSON(geom) AS geojson
                             FROM bairros');
    }

    /**
     * Situação dos lotes de cada bairro hoje: quantos ativos, quantos publicados
     * e a alteração mais recente — o que diz se o contorno ainda vale.
     *
     * @return array<string,object> por nome do bairro
     */
    public function situacaoDosBairros(): array
    {
        $r = DB::select("SELECT bairro, COUNT(*) AS ativos, SUM(em_revisao = 0) AS publicados,
                                MAX(updated_at) AS alterado_em
                           FROM lotes WHERE situacao = 'ativo' AND bairro IS NOT NULL GROUP BY bairro");

        return collect($r)->keyBy('bairro')->all();
    }

    /**
     * Confere um contorno antes de gravar: válido, multipolígono em 4326, e
     * quantos lotes ativos do bairro ele NÃO toca.
     *
     * @return array{valido:bool, tipo:string, area_m2:float, fora:int, total:int}
     */
    public function conferirContorno(string $bairro, string $geojson): array
    {
        $g = DB::selectOne('SELECT ST_IsValid(g) AS valido, ST_GeometryType(g) AS tipo, ST_Area(g) AS area
                              FROM (SELECT ST_GeomFromGeoJSON(?, 1, 4326) g) t', [$geojson]);
        $c = DB::selectOne('SELECT COUNT(*) AS total,
                                   SUM(NOT ST_Intersects(geom, ST_GeomFromGeoJSON(?, 1, 4326))) AS fora
                              FROM lotes WHERE ' . self::SO_ATIVOS . ' AND bairro = ?', [$geojson, $bairro]);

        return [
            'valido'  => (bool) $g->valido,
            'tipo'    => (string) $g->tipo,
            'area_m2' => (float) $g->area,
            'fora'    => (int) $c->fora,
            'total'   => (int) $c->total,
        ];
    }

    /**
     * Grava (ou substitui) o contorno de um bairro.
     *
     * A hora vem da APLICAÇÃO, e não do NOW() do MySQL: é com o `updated_at`
     * dos lotes — escrito pela aplicação — que ela é comparada para dizer se o
     * contorno ficou desatualizado, e os dois relógios podem estar em fusos
     * diferentes.
     */
    public function gravarContorno(string $bairro, ?string $codigo, string $geojson, float $raio, int $lotes, array $isolados, ?int $usuario): void
    {
        $agora = now()->toDateTimeString();
        DB::statement('INSERT INTO bairros (nome, codigo, geom, raio_m, lotes_contados, contorno_em, gerado_por, isolados, created_at, updated_at)
                       VALUES (?, ?, ST_GeomFromGeoJSON(?, 1, 4326), ?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE codigo = VALUES(codigo), geom = VALUES(geom), raio_m = VALUES(raio_m),
                              lotes_contados = VALUES(lotes_contados), contorno_em = VALUES(contorno_em),
                              gerado_por = VALUES(gerado_por), isolados = VALUES(isolados), updated_at = VALUES(updated_at)',
            [$bairro, $codigo, $geojson, $raio, $lotes, $agora, $usuario, json_encode($isolados), $agora, $agora]);
    }

    /**
     * Substitui as quadras de um bairro pelas recém-calculadas. Quadra que
     * sumiu (unificada, renumerada) sai junto: o bairro inteiro é regravado.
     *
     * Cada quadra passa pelo ST_IsValid antes de entrar. A inválida (ou que o
     * banco nem consegue ler) fica de fora e é contada — uma quadra ruim não pode impedir o contorno do
     * bairro, que é o que o mapa afastado mais precisa.
     *
     * @param  list<array{numero:string, geojson:string, lat:float, lon:float, lotes:int}>  $quadras
     * @return array{gravadas:int, invalidas:list<string>}
     */
    public function gravarQuadras(string $bairro, array $quadras): array
    {
        $agora = now()->toDateTimeString();
        $bairroId = DB::table('bairros')->where('nome', $bairro)->value('id');
        DB::table('quadras')->where('bairro', $bairro)->delete();

        $invalidas = [];
        foreach ($quadras as $q) {
            try {
                $valida = DB::selectOne('SELECT ST_IsValid(ST_GeomFromGeoJSON(?, 1, 4326)) AS v', [$q['geojson']])->v ?? 0;
            } catch (\Illuminate\Database\QueryException) {
                $valida = 0;   // coordenada fora de faixa ou documento malformado
            }
            if (! $valida) {
                $invalidas[] = $q['numero'];
                continue;
            }
            DB::insert('INSERT INTO quadras (bairro_id, bairro, numero, geom, rotulo_lat, rotulo_lon, lotes_contados, created_at, updated_at)
                        VALUES (?, ?, ?, ST_GeomFromGeoJSON(?, 1, 4326), ?, ?, ?, ?, ?)',
                [$bairroId, $bairro, $q['numero'], $q['geojson'], $q['lat'], $q['lon'], $q['lotes'], $agora, $agora]);
        }

        return ['gravadas' => count($quadras) - count($invalidas), 'invalidas' => $invalidas];
    }

    /**
     * As quadras de um bairro, para o mapa na escala do bairro. Precisão de
     * 7 casas (~1 cm): o desenho é de contorno, e cada casa a mais é peso.
     *
     * @return array<int,object> numero, lat, lon, geojson
     */
    public function quadrasDoBairro(string $bairro): array
    {
        return DB::select('SELECT numero, rotulo_lat AS lat, rotulo_lon AS lon, ST_AsGeoJSON(geom, 7) AS geojson
                             FROM quadras WHERE bairro = ? ORDER BY numero', [$bairro]);
    }

    /**
     * Substitui os trechos de rua GERADOS de um bairro. Os manuais
     * (`ruas_manuais`) não são tocados: valem por cima, na leitura.
     *
     * @param  list<array{nome:?string, de:array{0:float,1:float}, ate:array{0:float,1:float}}>  $trechos
     */
    public function gravarRuas(string $bairro, array $trechos): int
    {
        $agora = now()->toDateTimeString();
        DB::table('ruas_trechos')->where('bairro', $bairro)->delete();
        foreach (array_chunk($trechos, 500) as $bloco) {
            DB::table('ruas_trechos')->insert(array_map(fn ($t) => [
                'bairro' => $bairro, 'nome' => $t['nome'],
                'de_lat' => $t['de'][0], 'de_lon' => $t['de'][1], 'ate_lat' => $t['ate'][0], 'ate_lon' => $t['ate'][1],
                'created_at' => $agora, 'updated_at' => $agora,
            ], $bloco));
        }

        return count($trechos);
    }

    /**
     * Os trechos de rua de um bairro, os gerados com os manuais por cima (ver
     * App\Cadastro\TrechosDeRua).
     *
     * @return list<array{id:?int, nome:?string, de:array, ate:array, origem:string}>
     */
    public function ruasDoBairro(string $bairro): array
    {
        $ponto = fn ($l, $p) => [(float) $l->{$p . '_lat'}, (float) $l->{$p . '_lon'}];

        $gerados = DB::table('ruas_trechos')->where('bairro', $bairro)->orderBy('id')->get()
            ->map(fn ($l) => ['nome' => $l->nome, 'de' => $ponto($l, 'de'), 'ate' => $ponto($l, 'ate')])->all();
        $manuais = DB::table('ruas_manuais')->where('bairro', $bairro)->orderBy('id')->get()
            ->map(fn ($l) => ['id' => (int) $l->id, 'nome' => $l->nome, 'oculto' => (bool) $l->oculto,
                'de' => $ponto($l, 'de'), 'ate' => $ponto($l, 'ate')])->all();

        return \App\Cadastro\TrechosDeRua::combinar($gerados, $manuais);
    }

    /** Quantas quadras cada bairro tem gravadas. @return array<string,int> */
    public function quadrasPorBairro(): array
    {
        return collect(DB::select('SELECT bairro, COUNT(*) AS n FROM quadras GROUP BY bairro'))
            ->mapWithKeys(fn ($l) => [$l->bairro => (int) $l->n])->all();
    }

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
