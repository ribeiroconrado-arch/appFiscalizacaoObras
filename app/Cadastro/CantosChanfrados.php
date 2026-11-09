<?php

namespace App\Cadastro;

use App\Support\GeometriaPlana;

/**
 * Acha e desfaz o MICROCHANFRO que o conversor antigo deixava nos cantos.
 *
 * Quando duas divisas do DWG não se encontravam, o conversor fechava a fresta
 * engrossando as linhas 25 cm e devolvia a folga com junção redonda de 2
 * segmentos: cada canto virava 2 ou 3 ladinhos de ~19 cm (um retângulo com 12
 * vértices). O conversor já devolve em esquadro; isto conserta o que foi
 * importado antes (gis:esquadrejar-cantos, decisão de 09/11/2026).
 *
 * O canto chanfrado é: entre dois lados longos, de 1 a 3 lados curtos que,
 * juntos, viram o contorno num ângulo de canto (45° a 135°). Ele é trocado
 * pelo ENCONTRO das duas divisas longas — onde o canto estava no DWG.
 * Curva de verdade (a esquina arredondada da rua) tem raio de metros e lados
 * de 40 cm em diante, muitos seguidos: não cabe na regra e fica como está.
 */
class CantosChanfrados
{
    /** Lado curto: o ladinho do chanfro (o de 25 cm em 2 segmentos dá ~19 cm). */
    private const CURTO_MIN = 0.05;
    private const CURTO_MAX = 0.35;
    /** Lado longo: a divisa de verdade. */
    private const LONGO_MIN = 1.0;
    private const CURTOS_MAX = 3;
    /** O canto novo não pode cair longe do chanfro. */
    private const DESLOCAMENTO_MAX = 0.40;

    /**
     * @param  list<array{0:float,1:float}>  $anel  [lon, lat], fechado ou não
     * @return array{anel: list<array{0:float,1:float}>, cantos: int}|null  o anel
     *         esquadrejado (fechado, [lon, lat]) e quantos cantos mudaram; null se nenhum
     */
    public static function esquadrejar(array $anel): ?array
    {
        if (count($anel) < 5) return null;
        [$lon0, $lat0] = $anel[0];
        $passo = GeometriaPlana::projetar([[$lon0 + 0.001, $lat0 + 0.001]], $lat0, $lon0)[0];
        [$fx, $fy] = [$passo[0] / 0.001, $passo[1] / 0.001];

        $p = array_map(fn ($c) => [($c[0] - $lon0) * $fx, ($c[1] - $lat0) * $fy], $anel);
        $u = end($p);
        if (abs($u[0] - $p[0][0]) < 1e-9 && abs($u[1] - $p[0][1]) < 1e-9) array_pop($p);
        $n = count($p);
        if ($n < 5) return null;

        $len = fn ($i) => hypot($p[($i + 1) % $n][0] - $p[$i][0], $p[($i + 1) % $n][1] - $p[$i][1]);
        $L = array_map($len, range(0, $n - 1));   // lado i: do vértice i ao i+1
        $curto = fn ($i) => $L[$i] >= self::CURTO_MIN && $L[$i] <= self::CURTO_MAX;

        // Comece num lado longo, para nenhum chanfro ficar cortado pela emenda do anel.
        $inicio = null;
        for ($i = 0; $i < $n; $i++) if ($L[$i] >= self::LONGO_MIN) { $inicio = $i; break; }
        if ($inicio === null) return null;

        $trocas = [];   // vértice inicial do primeiro curto => [quantos curtos, ponto novo]
        $i = $inicio;
        $vistos = 0;
        while ($vistos < $n) {
            if ($L[$i] < self::LONGO_MIN) { $i = ($i + 1) % $n; $vistos++; continue; }
            // lado longo em $i; os curtos seguintes
            $k = 0;
            while ($k < self::CURTOS_MAX + 1 && $curto(($i + 1 + $k) % $n)) $k++;
            $depois = ($i + 1 + $k) % $n;
            if ($k >= 1 && $k <= self::CURTOS_MAX && $L[$depois] >= self::LONGO_MIN) {
                $novo = self::canto($p, $n, $i, $depois);
                if ($novo) $trocas[($i + 1) % $n] = [$k, $novo];
            }
            $i = ($i + 1 + max($k, 0)) % $n;
            $vistos += 1 + $k;
        }
        if (! $trocas) return null;

        // Remonta: os k+1 vértices de cada chanfro (do fim do longo ao começo do
        // próximo longo, exclusive) viram um só, o encontro das divisas.
        $sai = [];
        $pular = [];
        foreach ($trocas as $v => [$k, $novo]) {
            for ($j = 1; $j <= $k; $j++) $pular[($v + $j) % $n] = true;
        }
        for ($v = 0; $v < $n; $v++) {
            if (isset($pular[$v])) continue;
            $sai[] = isset($trocas[$v]) ? $trocas[$v][1] : $p[$v];
        }
        if (count($sai) < 3) return null;
        $sai[] = $sai[0];

        return [
            'anel'   => array_map(fn ($q) => [$lon0 + $q[0] / $fx, $lat0 + $q[1] / $fy], $sai),
            'cantos' => count($trocas),
        ];
    }

    /**
     * O encontro das retas do lado longo $a (vértices a→a+1) e do lado longo $b
     * (b→b+1), se o canto entre elas for de 45° a 135° e o ponto cair perto.
     */
    private static function canto(array $p, int $n, int $a, int $b): ?array
    {
        $a0 = $p[$a]; $a1 = $p[($a + 1) % $n];
        $b0 = $p[$b]; $b1 = $p[($b + 1) % $n];
        $da = [$a1[0] - $a0[0], $a1[1] - $a0[1]];
        $db = [$b1[0] - $b0[0], $b1[1] - $b0[1]];
        $na = hypot($da[0], $da[1]); $nb = hypot($db[0], $db[1]);
        $giro = rad2deg(acos(max(-1, min(1, ($da[0] * $db[0] + $da[1] * $db[1]) / ($na * $nb)))));
        if ($giro < 45 || $giro > 135) return null;

        $den = $da[0] * $db[1] - $da[1] * $db[0];
        if (abs($den) < 1e-9) return null;
        $t = (($b0[0] - $a0[0]) * $db[1] - ($b0[1] - $a0[1]) * $db[0]) / $den;
        $q = [$a0[0] + $t * $da[0], $a0[1] + $t * $da[1]];

        // Perto dos dois cantos do chanfro, senão não é chanfro.
        if (hypot($q[0] - $a1[0], $q[1] - $a1[1]) > self::DESLOCAMENTO_MAX
            || hypot($q[0] - $b0[0], $q[1] - $b0[1]) > self::DESLOCAMENTO_MAX) return null;

        return $q;
    }
}
