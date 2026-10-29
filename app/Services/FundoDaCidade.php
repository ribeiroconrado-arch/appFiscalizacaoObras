<?php

namespace App\Services;

use App\Repositories\LoteRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FUNDO DA CIDADE — o mapa cadastral completado com o OpenStreetMap nos VAZIOS.
 *
 * O mapa cadastral (fundo-cadastral.js) só desenha o que foi importado: fora
 * dos bairros, o chão fica liso, sem rua nem nome. Este serviço baixa do
 * OpenStreetMap, uma vez, as vias, o verde e a água do perímetro urbano e
 * guarda um arquivo pronto que o mapa desenha POR BAIXO dos bairros, no mesmo
 * estilo (faixa branca, borda cinza). É complemento VISUAL: nada vira
 * cadastro, nada vai para o banco, nenhuma via ganha inscrição ou trecho.
 *
 * O RECORTE é feito aqui, ao atualizar, e não a cada tela:
 *   - via: só os pedaços DENTRO do perímetro e FORA dos bairros importados —
 *     é o que impede o nome do OpenStreetMap de aparecer sobre a rua que o
 *     cadastro já nomeia;
 *   - verde e água: o que está inteiro dentro de um bairro sai; o que cruza a
 *     linha do perímetro é cortado nela. O pedaço que cai dentro de bairro
 *     fica, mas o branco do bairro (desenhado por cima) o cobre.
 * Bairro importado DEPOIS da última atualização já cobre o fundo no mapa (o
 * branco dele vem por cima); o nome do OpenStreetMap que ficasse ali é
 * descartado no navegador. A próxima atualização tira o resto.
 *
 * PISTA DUPLA. O OpenStreetMap desenha avenida com canteiro como duas vias de
 * mão única lado a lado. Desenhadas assim, saem dois trilhos finos; aqui cada
 * pista é puxada para o meio das duas e ganha a largura da avenida inteira —
 * no mapa vira uma faixa só, como as avenidas dos bairros.
 *
 * Licença: dados © colaboradores do OpenStreetMap (ODbL). O mapa cita a fonte
 * sempre que a camada está ligada.
 */
class FundoDaCidade
{
    /** O arquivo pronto, fora de public: é servido pela rota, com a sessão. */
    private const ARQUIVO = 'app/fundo-cidade.json';

    /** Início, fim e erro da última atualização (andamento()). */
    private const ESTADO = 'app/fundo-cidade.estado.json';

    /** Tentados em ordem; o principal às vezes devolve 429/502. */
    private const SERVIDORES = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    /** Largura da faixa branca, em metros, por tipo de via do OpenStreetMap. */
    private const LARGURA = [
        'motorway' => 22, 'trunk' => 20, 'primary' => 18, 'secondary' => 16, 'tertiary' => 13,
        'motorway_link' => 9, 'trunk_link' => 9, 'primary_link' => 9, 'secondary_link' => 9, 'tertiary_link' => 9,
        'unclassified' => 9, 'residential' => 10, 'living_street' => 8, 'pedestrian' => 6, 'service' => 6,
        'track' => 4, 'cycleway' => 2.5, 'footway' => 1.6, 'path' => 1.6,
        // Água corrente, em linha.
        'river' => 10, 'canal' => 5, 'stream' => 3,
    ];

    /** Só estas recebem o tratamento de pista dupla. */
    private const VIAS_LARGAS = ['motorway', 'trunk', 'primary', 'secondary', 'tertiary'];

    /** Pistas paralelas a esta distância (m) entre os eixos são a mesma avenida. */
    private const PISTA_DUPLA_MIN_M = 3;
    private const PISTA_DUPLA_MAX_M = 40;

    /** Lado da célula do índice de arestas, em graus (~200 m). */
    private const CELULA = 0.002;

    /** @var array<int, array{0:float,1:float,2:float,3:float}> as arestas que limitam a região */
    private array $arestas = [];

    /** @var array<string, int[]> célula → índices em $arestas */
    private array $grade = [];

    /** @var array<int, array<int, array<int, array{0:float,1:float}>>> polígonos do perímetro (anéis) */
    private array $perimetro = [];

    /** @var array<int, array{caixa:float[], aneis:array}> um por polígono de bairro */
    private array $bairros = [];

    public function __construct(private PerimetrosUrbanos $perimetros, private LoteRepository $lotes) {}

    /** O arquivo pronto, ou null se nunca foi gerado. */
    public function caminho(): ?string
    {
        $c = storage_path(self::ARQUIVO);

        return is_file($c) ? $c : null;
    }

    /** O que a tela de Parâmetros mostra: data da cópia e quantidades. */
    public function resumo(): ?array
    {
        $c = $this->caminho();
        if (! $c) {
            return null;
        }
        $d = json_decode((string) file_get_contents($c), true);

        return $d['resumo'] ?? null;
    }

    /**
     * A atualização em curso (ou a última que falhou). Ela roda DEPOIS da
     * resposta (FundoDaCidadeController): leva de 30 s a um minuto, mais que o
     * servidor web espera por uma requisição.
     *
     * @return array{andamento:bool, erro:?string}
     */
    public function andamento(): array
    {
        $e = is_file($c = storage_path(self::ESTADO)) ? json_decode((string) file_get_contents($c), true) : [];
        // Começou há mais de 8 min e não terminou: o processo morreu no meio.
        $vivo = isset($e['inicio']) && ! isset($e['fim']) && time() - $e['inicio'] < 480;

        return ['andamento' => $vivo, 'erro' => $vivo ? null : ($e['erro'] ?? null),
                'etapa' => $vivo ? ($e['etapa'] ?? 'Começando…') : null, 'pct' => $vivo ? (int) ($e['pct'] ?? 0) : null,
                'decorrido' => $vivo ? time() - $e['inicio'] : null];
    }

    /** Quando a atualização roda pela tela: a hora de início, que o estado guarda. */
    private ?int $inicio = null;

    /**
     * Grava a etapa e o percentual para a barra de progresso da tela.
     * Fora da tela (CLI, testes) não faz nada.
     */
    private function progresso(string $etapa, int $pct): void
    {
        if ($this->inicio !== null) {
            file_put_contents(storage_path(self::ESTADO),
                json_encode(['inicio' => $this->inicio, 'etapa' => $etapa, 'pct' => $pct], JSON_UNESCAPED_UNICODE));
        }
    }

    /** Marca o início; a tela passa a perguntar até terminar. */
    public function marcarInicio(): void
    {
        file_put_contents(storage_path(self::ESTADO), json_encode(['inicio' => time()]));
    }

    /** Atualiza e registra o fim (ou o erro) para a tela. */
    public function atualizarRegistrando(): void
    {
        $estado = ['inicio' => $this->inicio = time()];
        try {
            $this->atualizar();
        } catch (\Throwable $ex) {
            report($ex);
            $estado['erro'] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Falha ao montar o fundo: ' . class_basename($ex);
        }
        $this->inicio = null;
        $estado['fim'] = time();
        file_put_contents(storage_path(self::ESTADO), json_encode($estado, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Baixa do OpenStreetMap, recorta e grava o arquivo. Só administrador
     * (FundoDaCidadeController). Leva de alguns segundos a um minuto.
     */
    public function atualizar(): array
    {
        @set_time_limit(420);
        // ~80 MB no local (2 bairros); em produção há dezenas de bairros na grade.
        @ini_set('memory_limit', '256M');
        $this->progresso('Lendo o perímetro e os bairros…', 2);
        $this->montarRegiao();
        // O download é a parte longa e não tem como medir: a barra fica em 5%
        // com a etapa escrita, e anda depois que os dados chegam.
        $this->progresso('Baixando do OpenStreetMap…', 5);
        $osm = $this->baixar();
        $this->progresso('Separando vias, verde e água…', 40);

        $vias = [];
        $areas = [];
        foreach ($osm['elements'] ?? [] as $e) {
            $t = $e['tags'] ?? [];
            if ($e['type'] === 'way' && ($tipo = $this->tipoDeVia($t))) {
                $pts = array_map(fn ($p) => [(float) $p['lon'], (float) $p['lat']], $e['geometry'] ?? []);
                if (count($pts) >= 2) {
                    $vias[] = ['t' => $tipo, 'n' => trim($t['name'] ?? '') ?: null, 'l' => self::LARGURA[$tipo] ?? 8,
                               'mao' => ($t['oneway'] ?? '') === 'yes' || ($t['junction'] ?? '') === 'roundabout', 'c' => $pts];
                }
            } elseif ($tipo = $this->tipoDeArea($t)) {
                foreach ($this->aneisExternos($e) as $anel) {
                    $areas[] = ['t' => $tipo, 'c' => $anel];
                }
            }
        }

        $this->progresso('Juntando as pistas duplas…', 45);
        $this->juntarPistasDuplas($vias);

        // O recorte.
        $saida = [];
        $total = max(1, count($vias));
        foreach ($vias as $k => $v) {
            if ($k % 200 === 0) {
                $this->progresso('Recortando as vias (' . $k . ' de ' . $total . ')…', 50 + intdiv(40 * $k, $total));
            }
            foreach ($this->recortarLinha($v['c']) as $pedaco) {
                if ($this->comprimento($pedaco) < 5) {
                    continue;
                }
                $saida[] = array_filter(['t' => $v['t'], 'n' => $v['n'], 'l' => $v['l'],
                    'c' => $this->arredondar($pedaco)], fn ($x) => $x !== null);
            }
        }
        $areasSaida = [];
        $this->progresso('Recortando o verde e a água…', 92);
        foreach ($areas as $a) {
            foreach ($this->recortarArea($a['c']) as $anel) {
                $areasSaida[] = ['t' => $a['t'], 'c' => $this->arredondar($anel)];
            }
        }

        $vig = $this->perimetros->vigente();
        $resumo = [
            'gerado_em' => now()->format('d/m/Y H:i'),
            'perimetro' => $vig ? $vig->rotulo() : 'retângulo de reserva (nenhum perímetro vigente)',
            'vias'      => count($saida),
            'nomes'     => count(array_unique(array_filter(array_column($saida, 'n')))),
            'verde'     => count(array_filter($areasSaida, fn ($a) => $a['t'] !== 'agua')),
            'agua'      => count(array_filter($areasSaida, fn ($a) => $a['t'] === 'agua'))
                + count(array_filter($saida, fn ($v) => in_array($v['t'], ['river', 'canal', 'stream'], true))),
            'bairros'   => count($this->bairros),
        ];
        $json = json_encode(['resumo' => $resumo, 'vias' => $saida, 'areas' => $areasSaida],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->progresso('Gravando…', 98);
        // Grava num temporário e troca: quem estiver lendo nunca pega o arquivo pela metade.
        $destino = storage_path(self::ARQUIVO);
        file_put_contents($destino . '.tmp', $json);
        rename($destino . '.tmp', $destino);

        return $resumo;
    }

    // ── DOWNLOAD ─────────────────────────────────────────────────

    private function baixar(): array
    {
        [$o, $s, $l, $n] = PerimetrosUrbanos::retanguloParaOMapa();
        $c = "($s,$o,$n,$l)";
        $q = '[out:json][timeout:110];('
            . 'way["highway"~"^(motorway|trunk|primary|secondary|tertiary|motorway_link|trunk_link|primary_link|secondary_link|tertiary_link|unclassified|residential|living_street|pedestrian|service|track|cycleway|footway|path)$"]' . $c . ';'
            . 'way["waterway"~"^(river|canal|stream)$"]' . $c . ';'
            . 'way["natural"~"^(wood|scrub|grassland|wetland|water)$"]' . $c . ';'
            . 'way["leisure"~"^(park|garden|nature_reserve|pitch)$"]' . $c . ';'
            . 'way["landuse"~"^(grass|forest|meadow|recreation_ground|village_green|reservoir|basin)$"]' . $c . ';'
            . 'relation["natural"~"^(wood|scrub|grassland|wetland|water)$"]' . $c . ';'
            . 'relation["leisure"~"^(park|nature_reserve)$"]' . $c . ';'
            . 'relation["landuse"~"^(grass|forest|meadow|reservoir)$"]' . $c . ';'
            . ');out tags geom;';

        $falhas = [];
        foreach (self::SERVIDORES as $n => $url) {
            // Qual servidor está sendo tentado: na tela, mostra que não travou.
            if ($n > 0) {
                $this->progresso('Baixando do OpenStreetMap — tentando o servidor ' . ($n + 1) . ' de ' . count(self::SERVIDORES) . '…', 5);
            }
            try {
                $r = Http::connectTimeout(20)->timeout(120)->asForm()
                    ->withHeaders(['User-Agent' => 'FiscalizacaoObras/1.0 (mapa municipal; complemento visual)'])
                    ->post($url, ['data' => $q]);
                if ($r->successful() && is_array($r->json('elements'))) {
                    return $r->json();
                }
                $falhas[] = parse_url($url, PHP_URL_HOST) . ' ' . $r->status();
            } catch (\Throwable $ex) {
                $falhas[] = parse_url($url, PHP_URL_HOST) . ' ' . class_basename($ex);
            }
        }
        throw new RuntimeException('O OpenStreetMap não respondeu (' . implode('; ', $falhas) . '). Tente de novo em alguns minutos.');
    }

    private function tipoDeVia(array $t): ?string
    {
        if (isset($t['waterway'])) {
            return in_array($t['waterway'], ['river', 'canal', 'stream'], true) ? $t['waterway'] : null;
        }
        $h = $t['highway'] ?? null;
        if (! $h || ! isset(self::LARGURA[$h]) || ($t['area'] ?? '') === 'yes') {
            return null;
        }
        // Calçada, entrada de garagem e corredor de estacionamento: no desenho
        // da cidade inteira só emaranham.
        if ($h === 'footway' && in_array($t['footway'] ?? '', ['sidewalk', 'crossing'], true)) {
            return null;
        }
        if ($h === 'service' && in_array($t['service'] ?? '', ['driveway', 'parking_aisle', 'drive-through'], true)) {
            return null;
        }

        return $h;
    }

    /** verde | campo | agua, ou null. */
    private function tipoDeArea(array $t): ?string
    {
        if (($t['natural'] ?? '') === 'water' || in_array($t['landuse'] ?? '', ['reservoir', 'basin'], true)) {
            return 'agua';
        }
        if (($t['leisure'] ?? '') === 'pitch') {
            return 'campo';
        }
        if (in_array($t['natural'] ?? '', ['wood', 'scrub', 'grassland', 'wetland'], true)
            || in_array($t['leisure'] ?? '', ['park', 'garden', 'nature_reserve'], true)
            || in_array($t['landuse'] ?? '', ['grass', 'forest', 'meadow', 'recreation_ground', 'village_green'], true)) {
            return 'verde';
        }

        return null;
    }

    /**
     * Os anéis externos de uma área: o próprio caminho fechado, ou os pedaços
     * "outer" de uma relação costurados ponta com ponta. Furo não entra: o
     * lago dentro do parque é desenhado por cima, em azul.
     */
    private function aneisExternos(array $e): array
    {
        $pts = fn ($g) => array_map(fn ($p) => [(float) $p['lon'], (float) $p['lat']], $g ?? []);
        if ($e['type'] === 'way') {
            $c = $pts($e['geometry'] ?? []);

            return count($c) >= 4 && $c[0] === end($c) ? [$c] : [];
        }
        $pedacos = [];
        foreach ($e['members'] ?? [] as $m) {
            if (($m['role'] ?? '') === 'outer' && ! empty($m['geometry'])) {
                $pedacos[] = $pts($m['geometry']);
            }
        }
        $aneis = [];
        while ($pedacos) {
            $anel = array_shift($pedacos);
            $mudou = true;
            while ($anel[0] !== end($anel) && $mudou) {
                $mudou = false;
                foreach ($pedacos as $i => $p) {
                    if ($p[0] === end($anel)) {
                        $anel = array_merge($anel, array_slice($p, 1));
                    } elseif (end($p) === end($anel)) {
                        $anel = array_merge($anel, array_slice(array_reverse($p), 1));
                    } else {
                        continue;
                    }
                    unset($pedacos[$i]);
                    $mudou = true;
                    break;
                }
            }
            if (count($anel) >= 4 && $anel[0] === end($anel)) {
                $aneis[] = $anel;
            }
        }

        return $aneis;
    }

    // ── PISTA DUPLA ──────────────────────────────────────────────

    /**
     * Cada vértice de uma pista de mão única vai para o meio entre ela e a
     * pista CONTRÁRIA de mesmo nome, se houver uma a 3–40 m. A via ganha a
     * largura da avenida inteira: distância entre os eixos + uma pista.
     *
     * @param  array<int, array>  $vias  alteradas no lugar
     */
    private function juntarPistasDuplas(array &$vias): void
    {
        $porNome = [];
        foreach ($vias as $i => $v) {
            if ($v['mao'] && $v['n'] && in_array(preg_replace('/_link$/', '', $v['t']), self::VIAS_LARGAS, true)) {
                $porNome[mb_strtoupper($v['n'])][] = $i;
            }
        }
        foreach ($porNome as $indices) {
            if (count($indices) < 2) {
                continue;
            }
            $lat0 = $vias[$indices[0]]['c'][0][1];
            $kx = 111320 * cos(deg2rad($lat0));
            $ky = 110540;
            $m = fn ($p) => [$p[0] * $kx, $p[1] * $ky];
            // Todos os lados de todas as pistas deste nome, em metros.
            $lados = [];
            foreach ($indices as $i) {
                $c = $vias[$i]['c'];
                for ($k = 0; $k < count($c) - 1; $k++) {
                    $lados[] = [$i, $m($c[$k]), $m($c[$k + 1])];
                }
            }
            $novas = [];
            foreach ($indices as $i) {
                $c = $vias[$i]['c'];
                $dists = [];
                $nova = $c;
                foreach ($c as $k => $p) {
                    // A direção da pista neste vértice.
                    $a = $m($c[max(0, $k - 1)]);
                    $b = $m($c[min(count($c) - 1, $k + 1)]);
                    $dx = $b[0] - $a[0];
                    $dy = $b[1] - $a[1];
                    $len = hypot($dx, $dy) ?: 1;
                    $q = $m($p);
                    $melhor = null;
                    foreach ($lados as [$j, $u, $w]) {
                        if ($j === $i) {
                            continue;
                        }
                        $ex = $w[0] - $u[0];
                        $ey = $w[1] - $u[1];
                        $el = hypot($ex, $ey);
                        // Contrária: sentido oposto, quase paralela.
                        if ($el < 0.5 || ($dx * $ex + $dy * $ey) / ($len * $el) > -0.85) {
                            continue;
                        }
                        $t = max(0, min(1, (($q[0] - $u[0]) * $ex + ($q[1] - $u[1]) * $ey) / ($el * $el)));
                        $proj = [$u[0] + $t * $ex, $u[1] + $t * $ey];
                        $d = hypot($q[0] - $proj[0], $q[1] - $proj[1]);
                        if ($d >= self::PISTA_DUPLA_MIN_M && $d <= self::PISTA_DUPLA_MAX_M && (! $melhor || $d < $melhor[0])) {
                            $melhor = [$d, $proj];
                        }
                    }
                    if ($melhor) {
                        $dists[] = $melhor[0];
                        $nova[$k] = [($q[0] + $melhor[1][0]) / 2 / $kx, ($q[1] + $melhor[1][1]) / 2 / $ky];
                    }
                }
                // Só conta como pista dupla se a maior parte dela tem a contrária ao lado.
                if (count($dists) >= max(2, count($c) * 0.5)) {
                    sort($dists);
                    $mediana = $dists[intdiv(count($dists), 2)];
                    $novas[$i] = ['c' => $nova, 'l' => round($mediana + (self::LARGURA[$vias[$i]['t']] ?? 10) * 0.55, 1)];
                }
            }
            foreach ($novas as $i => $n) {
                $vias[$i]['c'] = $n['c'];
                $vias[$i]['l'] = $n['l'];
            }
        }
    }

    // ── RECORTE ──────────────────────────────────────────────────

    /** O perímetro vigente (ou o retângulo de reserva) e os contornos dos bairros, indexados. */
    private function montarRegiao(): void
    {
        $this->arestas = [];
        $this->grade = [];
        $this->bairros = [];

        $vig = $this->perimetros->vigente();
        $g = $vig ? $this->perimetros->geometria($vig) : null;
        if ($g) {
            $this->perimetro = $g['type'] === 'MultiPolygon' ? $g['coordinates'] : [$g['coordinates']];
        } else {
            [$o, $s, $l, $n] = PerimetrosUrbanos::retanguloParaOMapa();
            $this->perimetro = [[[[$o, $s], [$l, $s], [$l, $n], [$o, $n], [$o, $s]]]];
        }
        foreach ($this->perimetro as $poligono) {
            foreach ($poligono as $anel) {
                $this->indexarAnel($anel);
            }
        }

        // Os bairros que o mapa desenha: os que têm lote ativo publicado.
        $situacao = $this->lotes->situacaoDosBairros();
        foreach ($this->lotes->contornosDosBairros() as $b) {
            $s = $situacao[$b->nome] ?? null;
            if (! $s || (int) $s->publicados === 0) {
                continue;
            }
            $geo = json_decode($b->geojson, true);
            $poligonos = ($geo['type'] ?? '') === 'MultiPolygon' ? $geo['coordinates'] : [$geo['coordinates'] ?? []];
            foreach ($poligonos as $poligono) {
                if (! $poligono) {
                    continue;
                }
                $xs = array_column($poligono[0], 0);
                $ys = array_column($poligono[0], 1);
                $this->bairros[] = ['caixa' => [min($xs), min($ys), max($xs), max($ys)], 'aneis' => $poligono];
                foreach ($poligono as $anel) {
                    $this->indexarAnel($anel);
                }
            }
        }
    }

    private function indexarAnel(array $anel): void
    {
        for ($k = 0; $k < count($anel) - 1; $k++) {
            $a = $anel[$k];
            $b = $anel[$k + 1];
            $i = count($this->arestas);
            $this->arestas[] = [(float) $a[0], (float) $a[1], (float) $b[0], (float) $b[1]];
            foreach ($this->celulas(min($a[0], $b[0]), min($a[1], $b[1]), max($a[0], $b[0]), max($a[1], $b[1])) as $cel) {
                $this->grade[$cel][] = $i;
            }
        }
    }

    /** @return string[] */
    private function celulas(float $x0, float $y0, float $x1, float $y1): array
    {
        $r = [];
        for ($i = (int) floor($x0 / self::CELULA); $i <= (int) floor($x1 / self::CELULA); $i++) {
            for ($j = (int) floor($y0 / self::CELULA); $j <= (int) floor($y1 / self::CELULA); $j++) {
                $r[] = $i . ':' . $j;
            }
        }

        return $r;
    }

    /** O ponto está dentro do perímetro e fora de todos os bairros? */
    private function naRegiao(float $x, float $y): bool
    {
        $dentro = false;
        foreach ($this->perimetro as $poligono) {
            if ($this->noPoligono($x, $y, $poligono)) {
                $dentro = true;
                break;
            }
        }
        if (! $dentro) {
            return false;
        }
        foreach ($this->bairros as $b) {
            [$x0, $y0, $x1, $y1] = $b['caixa'];
            if ($x >= $x0 && $x <= $x1 && $y >= $y0 && $y <= $y1 && $this->noPoligono($x, $y, $b['aneis'])) {
                return false;
            }
        }

        return true;
    }

    /** Par-ímpar sobre todos os anéis (o furo conta como fora). */
    private function noPoligono(float $x, float $y, array $aneis): bool
    {
        $dentro = false;
        foreach ($aneis as $anel) {
            $n = count($anel);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$xi, $yi] = $anel[$i];
                [$xj, $yj] = $anel[$j];
                if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                    $dentro = ! $dentro;
                }
            }
        }

        return $dentro;
    }

    /**
     * Os pedaços da linha que ficam na região. Cada lado é partido onde cruza
     * a linha do perímetro ou de um bairro, e cada parte é mantida ou não pelo
     * ponto do meio dela.
     *
     * @return array<int, array<int, array{0:float,1:float}>>
     */
    private function recortarLinha(array $pts): array
    {
        $pedacos = [];
        $atual = [];
        for ($k = 0; $k < count($pts) - 1; $k++) {
            [$ax, $ay] = $pts[$k];
            [$bx, $by] = $pts[$k + 1];
            $ts = [0.0, 1.0];
            $vistas = [];
            foreach ($this->celulas(min($ax, $bx), min($ay, $by), max($ax, $bx), max($ay, $by)) as $cel) {
                foreach ($this->grade[$cel] ?? [] as $i) {
                    if (isset($vistas[$i])) {
                        continue;
                    }
                    $vistas[$i] = true;
                    $t = $this->cruzamento($ax, $ay, $bx, $by, ...$this->arestas[$i]);
                    if ($t !== null) {
                        $ts[] = $t;
                    }
                }
            }
            sort($ts);
            for ($i = 0; $i < count($ts) - 1; $i++) {
                [$t0, $t1] = [$ts[$i], $ts[$i + 1]];
                if ($t1 - $t0 < 1e-9) {
                    continue;
                }
                $tm = ($t0 + $t1) / 2;
                if ($this->naRegiao($ax + ($bx - $ax) * $tm, $ay + ($by - $ay) * $tm)) {
                    if (! $atual) {
                        $atual[] = [$ax + ($bx - $ax) * $t0, $ay + ($by - $ay) * $t0];
                    }
                    $atual[] = [$ax + ($bx - $ax) * $t1, $ay + ($by - $ay) * $t1];
                } elseif ($atual) {
                    if (count($atual) > 1) {
                        $pedacos[] = $atual;
                    }
                    $atual = [];
                }
            }
        }
        if (count($atual) > 1) {
            $pedacos[] = $atual;
        }

        return $pedacos;
    }

    /** Onde (0–1, ao longo de AB) o lado AB cruza CD, ou null. */
    private function cruzamento(float $ax, float $ay, float $bx, float $by, float $cx, float $cy, float $dx, float $dy): ?float
    {
        $rx = $bx - $ax;
        $ry = $by - $ay;
        $sx = $dx - $cx;
        $sy = $dy - $cy;
        $den = $rx * $sy - $ry * $sx;
        if (abs($den) < 1e-18) {
            return null;
        }
        $t = (($cx - $ax) * $sy - ($cy - $ay) * $sx) / $den;
        $u = (($cx - $ax) * $ry - ($cy - $ay) * $rx) / $den;

        return $t > 0 && $t < 1 && $u >= 0 && $u <= 1 ? $t : null;
    }

    /**
     * A área, se fica: fora se inteira dentro de um bairro ou fora do
     * perímetro; cortada na linha do perímetro se a cruza (no MySQL, no plano).
     *
     * @return array<int, array> anéis
     */
    private function recortarArea(array $anel): array
    {
        $xs = array_column($anel, 0);
        $ys = array_column($anel, 1);
        $caixa = [min($xs), min($ys), max($xs), max($ys)];
        // Inteira dentro de um bairro: o branco a cobriria toda.
        foreach ($this->bairros as $b) {
            [$x0, $y0, $x1, $y1] = $b['caixa'];
            if ($caixa[0] >= $x0 && $caixa[2] <= $x1 && $caixa[1] >= $y0 && $caixa[3] <= $y1
                && ! array_filter($anel, fn ($p) => ! $this->noPoligono($p[0], $p[1], $b['aneis']))) {
                return [];
            }
        }
        $dentro = 0;
        foreach ($anel as $p) {
            foreach ($this->perimetro as $poligono) {
                if ($this->noPoligono($p[0], $p[1], $poligono)) {
                    $dentro++;
                    break;
                }
            }
        }
        if ($dentro === 0 && ! $this->cruzaAlgumaAresta($anel)) {
            return [];
        }
        if ($dentro === count($anel)) {
            return [$anel];
        }
        try {
            $per = ['type' => 'MultiPolygon', 'coordinates' => $this->perimetro];
            $gj = DB::scalar('SELECT ST_AsGeoJSON(ST_Intersection(ST_GeomFromGeoJSON(?, 1, 0), ST_GeomFromGeoJSON(?, 1, 0)), 7)',
                [json_encode(['type' => 'Polygon', 'coordinates' => [$anel]]), json_encode($per)]);
            $g = json_decode((string) $gj, true);
        } catch (\Throwable) {
            return [];   // polígono inválido do OpenStreetMap: fica de fora
        }
        $poligonos = match ($g['type'] ?? null) {
            'Polygon' => [$g['coordinates']],
            'MultiPolygon' => $g['coordinates'],
            default => [],
        };

        return array_map(fn ($p) => $p[0], $poligonos);
    }

    /** Algum lado do anel cruza a linha do perímetro ou de um bairro? */
    private function cruzaAlgumaAresta(array $anel): bool
    {
        for ($k = 0; $k < count($anel) - 1; $k++) {
            [$ax, $ay] = $anel[$k];
            [$bx, $by] = $anel[$k + 1];
            foreach ($this->celulas(min($ax, $bx), min($ay, $by), max($ax, $bx), max($ay, $by)) as $cel) {
                foreach ($this->grade[$cel] ?? [] as $i) {
                    if ($this->cruzamento($ax, $ay, $bx, $by, ...$this->arestas[$i]) !== null) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    // ── ÚTEIS ────────────────────────────────────────────────────

    /** Comprimento aproximado, em metros. */
    private function comprimento(array $pts): float
    {
        $s = 0;
        $k = 111320 * cos(deg2rad($pts[0][1]));
        for ($i = 1; $i < count($pts); $i++) {
            $s += hypot(($pts[$i][0] - $pts[$i - 1][0]) * $k, ($pts[$i][1] - $pts[$i - 1][1]) * 110540);
        }

        return $s;
    }

    /** 6 casas: 10 cm, mais que o bastante para um fundo. */
    private function arredondar(array $pts): array
    {
        return array_map(fn ($p) => [round($p[0], 6), round($p[1], 6)], $pts);
    }
}
