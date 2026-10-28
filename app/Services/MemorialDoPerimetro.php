<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * O MEMORIAL DESCRITIVO do perímetro urbano: lê, confere e vira polígono.
 *
 * A lei define o perímetro pelos vértices (P-01 … P-n) com coordenadas UTM,
 * e diz para cada trecho o azimute e a distância. As coordenadas definem o
 * vértice; azimute e distância servem para CONFERIR — se não batem, é erro de
 * digitação em algum lugar da lei, e a tela aponta o trecho.
 *
 * Aceita o memorial como veio da lei (texto corrido: "…do vértice P-01, de
 * coordenadas N = 8.287.874,960 m e E = 793.640,650 m; deste, segue com azimute
 * de 125°24'46" e distância de 703,012 m até o vértice P-02…") ou colado de uma
 * planilha (vértice, N, E por linha). N e E se distinguem pelo tamanho: N tem 7
 * dígitos inteiros, E tem 6.
 *
 * Coordenadas em SIRGAS 2000 / UTM 21S (EPSG:31981), a base do município. A
 * conversão para o mapa é do MySQL (ST_Transform).
 */
class MemorialDoPerimetro
{
    public const EPSG = 31981;

    /** Tolerâncias da conferência trecho a trecho. */
    private const TOL_DISTANCIA_M = 0.05;
    private const TOL_AZIMUTE_SEG = 5.0;

    /** Diferença de área/perímetro declarados que só avisa (o plano da lei pode ser o topográfico). */
    private const TOL_DECLARADO = 0.0001;

    // ── leitura ───────────────────────────────────────────────────

    /**
     * @return array{vertices: list<array{p:string,n:float,e:float,azimute:?string,distancia:?float,trecho:string}>,
     *               area_declarada_m2: ?float, perimetro_declarado_m: ?float}
     */
    public function ler(string $texto): array
    {
        $t = self::normalizar($texto);
        $vertices = $this->lerTabela($t) ?: $this->lerTexto($t);

        $area = null;
        if (preg_match('/[ÁA]rea(?:\s+total)?[^\d]{0,40}?([\d.]+,\d+|\d+(?:\.\d+)?)\s*(ha|m²|m2)/iu', $t, $m)) {
            $area = self::numero($m[1]) * (mb_strtolower($m[2]) === 'ha' ? 10000 : 1);
        }
        $perimetro = null;
        if (preg_match('/Per[íi]metro(?:\s+total)?[^\d]{0,40}?([\d.]+,\d+)\s*m\b/iu', $t, $m)) {
            $perimetro = self::numero($m[1]);
        }

        return ['vertices' => $vertices, 'area_declarada_m2' => $area, 'perimetro_declarado_m' => $perimetro];
    }

    /**
     * O texto sem a "roupa": LaTeX, negrito, aspas tipográficas, a crase usada
     * como minuto (Lei 2.121/2022: 125º24`46"), rodapé e número de página do
     * PDF, e o rótulo partido na quebra de linha ("vértice P-" / "01").
     */
    private static function normalizar(string $t): string
    {
        $t = implode("\n", array_filter(preg_split('/\R/u', $t),
            fn ($l) => ! preg_match('#https?://|^\s*\d+\s*/\s*\d+\s*$#u', $l)));
        $t = str_replace(['^\\circ', '\\circ', 'º', '˚'], '°', $t);
        $t = preg_replace('/\\\\text\{\s*([^}]*)\}/u', ' $1', $t);
        $t = str_replace(['$', '**', '’', '′', '´', '‘', '`'], ['', '', "'", "'", "'", "'", "'"], $t);
        $t = str_replace(['”', '″', '“', "''"], '"', $t);
        $t = preg_replace('/\b([PVM])\s*-\s*\R?\s*(\d)/u', '$1-$2', $t);

        return preg_replace('/[ \t]+/u', ' ', $t);
    }

    /** "8.287.874,960" ou "8287874.960" → 8287874.96 */
    public static function numero(string $s): float
    {
        $s = trim($s);
        if (str_contains($s, ',')) {
            return (float) str_replace(['.', ','], ['', '.'], $s);
        }

        return (float) $s;
    }

    /** Linha a linha: vértice, N, E (em qualquer ordem dos dois números). */
    private function lerTabela(string $t): array
    {
        $v = [];
        foreach (preg_split('/\R/u', $t) as $linha) {
            if (! preg_match('/^\s*((?:P|V|M)?-?\s?\d+[A-Z]?)\s*[;\t|,]?\s*([\d.]+,\d+|\d+\.\d+)\s*[;\t|]?\s*([\d.]+,\d+|\d+\.\d+)\s*$/iu', $linha, $m)) {
                continue;
            }
            [$a, $b] = [self::numero($m[2]), self::numero($m[3])];
            [$n, $e] = $a > $b ? [$a, $b] : [$b, $a];
            $v[] = ['p' => self::rotulo($m[1]), 'n' => $n, 'e' => $e, 'azimute' => null, 'distancia' => null, 'trecho' => 'seca'];
        }

        return count($v) >= 3 ? $v : [];
    }

    /**
     * Texto corrido: cada vértice citado com N e E é um ponto, na ordem em que
     * aparece; o azimute e a distância que vêm ANTES dele são os do trecho que
     * chega nele. O trecho que fecha (do último de volta ao primeiro) é o que
     * sobra depois do último.
     */
    private function lerTexto(string $t): array
    {
        $rotulo = '/\b(P-?\s?\d+[A-Z]?)\b/u';
        preg_match_all($rotulo, $t, $rots, PREG_OFFSET_CAPTURE);
        $marcas = $rots[1];
        $mencoes = [];
        foreach ($marcas as $i => [$nome, $ini]) {
            $fim = $marcas[$i + 1][1] ?? strlen($t);
            $trecho = substr($t, $ini, $fim - $ini);
            $n = preg_match('/\bN\s*[=:]?\s*([\d.]+,\d+|\d{7}\.\d+)/u', $trecho, $mn, PREG_OFFSET_CAPTURE) ? $mn : null;
            $e = preg_match('/\bE\s*[=:]?\s*([\d.]+,\d+|\d{6}\.\d+)/u', $trecho, $me, PREG_OFFSET_CAPTURE) ? $me : null;
            if (! $n || ! $e) {
                continue;
            }
            $fimDaMencao = $ini + max($mn[0][1] + strlen($mn[0][0]), $me[0][1] + strlen($me[0][0]));
            $mencoes[] = ['p' => self::rotulo($nome), 'n' => self::numero($mn[1][0]), 'e' => self::numero($me[1][0]),
                'ini' => $ini, 'fim' => $fimDaMencao];
        }

        // Por onde a linha passa vale até o memorial dizer outra coisa ("deste segue
        // pelo Córrego abaixo" vale para os trechos seguintes, até "deste segue
        // por linhas secas").
        $v = [];
        $vistos = [];
        $passa = 'seca';
        foreach ($mencoes as $i => $m) {
            $antes = $i > 0 ? substr($t, $mencoes[$i - 1]['fim'], $m['ini'] - $mencoes[$i - 1]['fim']) : '';
            $passa = self::porOndePassa($antes) ?? $passa;
            $dados = self::trechoDe($antes) + ['trecho' => $passa];
            if (isset($vistos[$m['p']])) {
                // O primeiro vértice citado de novo é o FECHAMENTO: o trecho que chega nele fecha o polígono.
                if ($m['p'] === ($v[0]['p'] ?? null) && ($dados['azimute'] || $dados['distancia'])) {
                    $v[] = ['p' => $m['p'], 'n' => $m['n'], 'e' => $m['e']] + $dados;
                }
                continue;
            }
            $vistos[$m['p']] = true;
            $v[] = ['p' => $m['p'], 'n' => $m['n'], 'e' => $m['e']] + $dados;
        }

        return $v;
    }

    /** Azimute e distância, num pedaço de texto entre dois vértices. */
    private static function trechoDe(string $s): array
    {
        // Os segundos às vezes vêm só com espaço (Lei 2.121/2022: 22º12 5").
        $az = preg_match('/(\d{1,3})\s*°\s*(\d{1,2})\s*[\'\s]\s*(\d{1,2}(?:[.,]\d+)?)\s*"/u', $s, $m)
            ? sprintf('%d°%02d\'%s"', $m[1], $m[2], str_pad(str_replace('.', ',', $m[3]), 2, '0', STR_PAD_LEFT)) : null;
        $dist = preg_match('/(?<![\d.,])(\d{1,3}(?:\.\d{3})*,\d+|\d+[.,]\d+)\s*m\b/u', $s, $m) ? self::numero($m[1]) : null;

        return ['azimute' => $az, 'distancia' => $dist];
    }

    /** "deste segue pelo Córrego abaixo" → corrego; null quando o pedaço não muda o caminho. */
    private static function porOndePassa(string $s): ?string
    {
        if (! preg_match_all('/segue\s+(.{0,60})/isu', $s, $m)) {
            return null;
        }
        $depois = mb_strtolower(end($m[1]));
        return match (true) {
            (bool) preg_match('/^(por\s+)?linhas?\s+secas?/u', $depois)               => 'seca',
            (bool) preg_match('/c[óo]rrego|ribeir[ãa]o|\brio\b|margem/u', $depois)    => 'corrego',
            (bool) preg_match('/serra|divisor|espig[ãa]o/u', $depois)                  => 'serra',
            (bool) preg_match('/estrada|rodovia|\bmt-|\bbr-|avenida|rua/u', $depois)   => 'estrada',
            default                                                                     => null,
        };
    }

    private static function rotulo(string $s): string
    {
        $s = strtoupper(preg_replace('/\s+/', '', $s));
        if (preg_match('/^([A-Z]*)-?(\d+)([A-Z]?)$/', $s, $m)) {
            return ($m[1] ?: 'P') . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . $m[3];
        }

        return $s;
    }

    /** "125°24'46"" → graus decimais (null se não der para ler). */
    public static function grausDe(?string $az): ?float
    {
        if ($az === null || ! preg_match('/(\d{1,3})\D+(\d{1,2})\D+(\d{1,2}(?:[.,]\d+)?)/u', $az, $m)) {
            return null;
        }

        return $m[1] + $m[2] / 60 + (float) str_replace(',', '.', $m[3]) / 3600;
    }

    // ── conferência ───────────────────────────────────────────────

    /**
     * Confere os vértices: sequência, cada trecho (coordenadas × azimute ×
     * distância), fechamento, área e perímetro declarados. Só BLOQUEIA o que
     * impede de haver polígono; o resto avisa e fica registrado.
     *
     * @param  list<array>  $vertices
     * @return array{itens: list<array{nivel:string,texto:string}>, trechos: list<array>, vertices: list<array>,
     *               area_m2: float, perimetro_m: float, bloqueia: bool,
     *               area_declarada_m2: ?float, perimetro_declarado_m: ?float}
     */
    public function conferir(array $vertices, ?float $areaDeclarada, ?float $perimetroDeclarado): array
    {
        $itens = [];
        $bloqueia = false;
        $erro = function (string $texto) use (&$itens, &$bloqueia) { $itens[] = ['nivel' => 'erro', 'texto' => $texto]; $bloqueia = true; };
        $aviso = function (string $texto) use (&$itens) { $itens[] = ['nivel' => 'aviso', 'texto' => $texto]; };
        $ok = function (string $texto) use (&$itens) { $itens[] = ['nivel' => 'ok', 'texto' => $texto]; };

        $v = array_values(array_filter(array_map(fn ($x) => [
            'p'         => trim((string) ($x['p'] ?? '')),
            'n'         => is_numeric($x['n'] ?? null) ? (float) $x['n'] : null,
            'e'         => is_numeric($x['e'] ?? null) ? (float) $x['e'] : null,
            'azimute'   => ($x['azimute'] ?? '') !== '' ? (string) $x['azimute'] : null,
            'distancia' => is_numeric($x['distancia'] ?? null) ? (float) $x['distancia'] : null,
            'trecho'    => in_array($x['trecho'] ?? '', ['seca', 'corrego', 'serra', 'estrada'], true) ? $x['trecho'] : 'seca',
        ], $vertices), fn ($x) => $x['p'] !== '' || $x['n'] !== null || $x['e'] !== null));

        // O fechamento escrito como último vértice (igual ao primeiro) é o trecho final, não ponto novo.
        $fechamento = null;
        if (count($v) > 3 && $v[0]['p'] === end($v)['p']) {
            $fechamento = array_pop($v);
        }

        foreach ($v as $i => $x) {
            if ($x['n'] === null || $x['e'] === null) {
                $erro("Vértice {$x['p']} (linha " . ($i + 1) . ') sem coordenada N ou E.');
            } elseif ($x['n'] < 7_000_000 || $x['n'] > 10_000_000 || $x['e'] < 100_000 || $x['e'] > 900_000) {
                $erro("Vértice {$x['p']}: N {$x['n']} / E {$x['e']} não parecem coordenadas UTM (N tem 7 dígitos, E tem 6).");
            }
        }
        if (count($v) < 3) {
            $erro('São precisos ao menos 3 vértices.');
        }
        $repetidos = array_keys(array_filter(array_count_values(array_column($v, 'p')), fn ($c) => $c > 1));
        if ($repetidos) {
            $erro('Vértice repetido: ' . implode(', ', $repetidos) . '.');
        }
        if ($bloqueia) {
            return ['itens' => $itens, 'trechos' => [], 'vertices' => $v, 'area_m2' => 0.0, 'perimetro_m' => 0.0, 'bloqueia' => true,
                'area_declarada_m2' => $areaDeclarada, 'perimetro_declarado_m' => $perimetroDeclarado];
        }

        // Sequência: P-01, P-02… sem pular número.
        $nums = array_map(fn ($x) => preg_match('/(\d+)/', $x['p'], $m) ? (int) $m[1] : null, $v);
        $pulos = [];
        for ($i = 1; $i < count($nums); $i++) {
            if ($nums[$i] !== null && $nums[$i - 1] !== null && $nums[$i] !== $nums[$i - 1] + 1) {
                $pulos[] = $v[$i - 1]['p'] . ' → ' . $v[$i]['p'];
            }
        }
        $pulos ? $aviso('A numeração pula: ' . implode('; ', array_slice($pulos, 0, 8)) . (count($pulos) > 8 ? '…' : '') . '. Confira se falta vértice.')
               : $ok(count($v) . " vértices, de {$v[0]['p']} a " . end($v)['p'] . ', em sequência.');

        // Trecho a trecho. O azimute e a distância do vértice i são os do trecho que CHEGA nele.
        $trechos = [];
        $perimetro = 0.0;
        $ruins = 0;
        $n = count($v);
        for ($i = 0; $i < $n; $i++) {
            $de = $v[$i];
            $para = $v[($i + 1) % $n];
            $dados = $i + 1 < $n ? $para : ($fechamento ?? ['azimute' => null, 'distancia' => null, 'trecho' => $v[0]['trecho']]);
            $dn = $para['n'] - $de['n'];
            $deE = $para['e'] - $de['e'];
            $dist = hypot($dn, $deE);
            $az = fmod(rad2deg(atan2($deE, $dn)) + 360, 360);
            $perimetro += $dist;

            $difDist = $dados['distancia'] !== null ? $dist - $dados['distancia'] : null;
            $azLei = self::grausDe($dados['azimute']);
            $difAz = $azLei !== null ? (fmod($az - $azLei + 540, 360) - 180) * 3600 : null;
            $bom = ($difDist === null || abs($difDist) <= self::TOL_DISTANCIA_M) && ($difAz === null || abs($difAz) <= self::TOL_AZIMUTE_SEG);
            $ruins += $bom ? 0 : 1;
            $trechos[] = [
                'de' => $de['p'], 'para' => $para['p'], 'trecho' => $dados['trecho'] ?? 'seca',
                'distancia_calc' => round($dist, 3), 'azimute_calc' => self::azimuteTexto($az),
                'distancia_lei' => $dados['distancia'], 'azimute_lei' => $dados['azimute'],
                'dif_distancia' => $difDist !== null ? round($difDist, 3) : null,
                'dif_azimute_seg' => $difAz !== null ? round($difAz, 1) : null,
                'ok' => $bom,
            ];
        }
        $comLei = count(array_filter($trechos, fn ($x) => $x['distancia_lei'] !== null || $x['azimute_lei'] !== null));
        if (! $comLei) {
            $aviso('O memorial não trouxe azimute nem distância: os trechos não puderam ser conferidos (valem as coordenadas).');
        } elseif ($ruins) {
            $aviso("{$ruins} trecho(s) com azimute ou distância que não batem com as coordenadas (tolerância "
                . number_format(self::TOL_DISTANCIA_M, 2, ',', '') . ' m e ' . (int) self::TOL_AZIMUTE_SEG . '"). Valem as coordenadas; confira a lei.');
        } else {
            $ok("{$comLei} trecho(s) conferidos: azimute e distância batem com as coordenadas.");
        }
        if (! $fechamento && $comLei && $trechos[$n - 1]['distancia_lei'] === null) {
            $aviso('O trecho que fecha (de ' . $v[$n - 1]['p'] . ' de volta a ' . $v[0]['p'] . ') não tem azimute e distância no memorial.');
        }

        // Área (UTM, pela fórmula do cadarço) e perímetro, contra os declarados.
        $s = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $s += $v[$i]['e'] * $v[$j]['n'] - $v[$j]['e'] * $v[$i]['n'];
        }
        $area = abs($s) / 2;
        // $a: a terminação do gênero ("a" para área, "o" para perímetro).
        $declarado = function (string $o_que, string $a, float $calc, ?float $lei, string $un, int $casas) use ($ok, $aviso) {
            $fmt = fn ($x) => number_format($x, $casas, ',', '.') . " {$un}";
            if ($lei === null) {
                $ok("{$o_que} calculad{$a}: " . $fmt($calc) . ' (o memorial não declara).');
            } elseif (abs($calc - $lei) <= $lei * self::TOL_DECLARADO) {
                $ok("{$o_que}: " . $fmt($calc) . " — bate com {$a} declarad{$a} (" . $fmt($lei) . ').');
            } else {
                $aviso("{$o_que}: calculad{$a} " . $fmt($calc) . ", declarad{$a} " . $fmt($lei)
                    . ' (' . number_format(($calc - $lei) / $lei * 100, 3, ',', '.') . ' %). Confira se falta ou sobra vértice.');
            }
        };
        $declarado('Área', 'a', $area / 10000, $areaDeclarada !== null ? $areaDeclarada / 10000 : null, 'ha', 3);
        $declarado('Perímetro', 'o', $perimetro, $perimetroDeclarado, 'm', 1);

        return ['itens' => $itens, 'trechos' => $trechos, 'vertices' => $fechamento ? [...$v, $fechamento] : $v,
            'area_m2' => round($area, 2), 'perimetro_m' => round($perimetro, 2), 'bloqueia' => false,
            'area_declarada_m2' => $areaDeclarada, 'perimetro_declarado_m' => $perimetroDeclarado];
    }

    public static function azimuteTexto(float $graus): string
    {
        $seg = (int) round($graus * 3600);
        return sprintf('%d°%02d\'%02d"', intdiv($seg, 3600) % 360, intdiv($seg % 3600, 60), $seg % 60);
    }

    // ── polígono ──────────────────────────────────────────────────

    /**
     * Os vértices (UTM) como MultiPolygon em 4326, para o mapa. O vértice de
     * fechamento repetido é ignorado.
     *
     * @param  list<array{p:string,n:float,e:float}>  $vertices
     */
    public function poligono(array $vertices): array
    {
        $pts = $vertices;
        if (count($pts) > 3 && end($pts)['p'] === $pts[0]['p']) {
            array_pop($pts);
        }
        if (count($pts) < 3) {
            throw new RuntimeException('São precisos ao menos 3 vértices.');
        }
        $pts[] = $pts[0];
        $wkt = 'POLYGON((' . implode(',', array_map(fn ($x) => sprintf('%.3F %.3F', $x['e'], $x['n']), $pts)) . '))';
        $gj = DB::scalar('SELECT ST_AsGeoJSON(ST_Transform(ST_GeomFromText(?, ' . self::EPSG . '), 4326), 9)', [$wkt]);
        $g = json_decode((string) $gj, true);
        if (($g['type'] ?? null) !== 'Polygon') {
            throw new RuntimeException('Os vértices não formam um polígono.');
        }

        return ['type' => 'MultiPolygon', 'coordinates' => [$g['coordinates']]];
    }
}
