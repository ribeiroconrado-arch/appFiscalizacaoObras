<?php

namespace App\Cadastro;

use App\Support\GeometriaPlana;

/**
 * Divide um lote em FAIXAS com as áreas pedidas, cortando na direção que o
 * levantamento de 2022 mostra (decisão de 04/11/2026).
 *
 * O levantamento dá só a DIREÇÃO e a ORDEM das partes. A POSIÇÃO de cada corte
 * sai da área: o desenho de 2022 vem deslocado do lote importado, e cortar
 * pela linha dele dividia 600 m² em 278 + 322 quando o cadastro diz 300 + 300.
 * Aqui o corte desliza, paralelo ao lado do lote, até cada faixa ter a sua
 * área (a do cadastro, na proporção do lote importado).
 *
 * Só serve a divisão em faixas — cada parte de lado a lado do lote. Quando o
 * levantamento divide de outro jeito (o condomínio de 20 partes em grade),
 * devolve null com o motivo, e o caso fica de fora em vez de sair torto.
 *
 * Tudo no plano local (GeometriaPlana), com origem no primeiro vértice do lote.
 */
class RecorteEmFaixas
{
    /** Uma faixa do levantamento precisa atravessar ao menos isto do lote. */
    private const FAIXA_MIN = 0.75;

    /** Diferença de área tolerada entre a faixa e o alvo. */
    private const AREA_TOL = 0.005;

    /**
     * @param  list<array{0:float,1:float}>        $mae     anel do lote, [lon, lat]
     * @param  list<list<array{0:float,1:float}>>  $partes  anéis das partes do levantamento, na ordem do caso
     * @param  list<float|null>                    $areas   área desejada de cada parte (a do cadastro); null = partes iguais
     * @return array{aneis?: list<list<array{0:float,1:float}>>, motivo?: string}
     *         os anéis em [lon, lat], na mesma ordem de $partes
     */
    public static function dividir(array $mae, array $partes, array $areas): array
    {
        $n = count($partes);
        if ($n < 2 || count($mae) < 4) {
            return ['motivo' => 'Lote ou partes sem desenho.'];
        }
        [$lon0, $lat0] = $mae[0];
        // A projeção é linear: os fatores saem de um passo pequeno.
        $passo = GeometriaPlana::projetar([[$lon0 + 0.001, $lat0 + 0.001]], $lat0, $lon0)[0];
        [$fx, $fy] = [$passo[0] / 0.001, $passo[1] / 0.001];
        $plano = fn (array $anel) => self::semFecho(array_map(fn ($c) => [($c[0] - $lon0) * $fx, ($c[1] - $lat0) * $fy], $anel));
        $volta = fn (array $anel) => self::comFecho(array_map(fn ($p) => [$lon0 + $p[0] / $fx, $lat0 + $p[1] / $fy], $anel));

        $M = $plano($mae);
        $P = array_map($plano, $partes);
        $areaMae = GeometriaPlana::area($M);
        if ($areaMae <= 0) {
            return ['motivo' => 'O lote não tem área.'];
        }

        // ── a direção do corte: o lado do lote mais "atravessado" pela fila das partes ──
        $cent = array_map(fn ($a) => self::centroide($a), $P);
        $u = self::eixo($cent);
        if (! $u) {
            return ['motivo' => 'As partes do levantamento estão uma sobre a outra.'];
        }
        $melhor = null;
        $k = count($M);
        for ($i = 0; $i < $k; $i++) {
            $e = [$M[($i + 1) % $k][0] - $M[$i][0], $M[($i + 1) % $k][1] - $M[$i][1]];
            $len = hypot($e[0], $e[1]);
            if ($len < 1e-6) continue;
            $peso = abs($e[0] * $u[1] - $e[1] * $u[0]);   // |e × u| = comprimento × seno
            if (! $melhor || $peso > $melhor[0]) $melhor = [$peso, [$e[0] / $len, $e[1] / $len]];
        }
        $d = $melhor[1];
        $nrm = [-$d[1], $d[0]];
        if ($nrm[0] * $u[0] + $nrm[1] * $u[1] < 0) $nrm = [-$nrm[0], -$nrm[1]];
        $proj = fn ($p) => $p[0] * $nrm[0] + $p[1] * $nrm[1];
        $ao = fn ($p) => $p[0] * $d[0] + $p[1] * $d[1];

        // ── é divisão em faixas? cada parte atravessa o lote ao longo do corte ──
        $alcance = fn (array $anel, callable $f) => max(array_map($f, $anel)) - min(array_map($f, $anel));
        $larguraMae = $alcance($M, $ao);
        foreach ($P as $a) {
            if ($alcance($a, $ao) < self::FAIXA_MIN * $larguraMae) {
                return ['motivo' => 'O levantamento não divide este lote em faixas lado a lado: o corte tem de ser feito à mão.'];
            }
        }

        // ── a ordem das faixas ao longo do lote, e a área de cada uma ──
        $ordem = range(0, $n - 1);
        usort($ordem, fn ($a, $b) => $proj($cent[$a]) <=> $proj($cent[$b]));
        $conhecidas = array_filter($areas, fn ($v) => $v !== null && $v > 0);
        $alvo = count($conhecidas) === $n
            ? array_map(fn ($v) => $v * $areaMae / array_sum($areas), $areas)
            : array_fill(0, $n, $areaMae / $n);

        // ── os cortes: cada um desliza até a área acumulada bater ──
        $vals = array_map($proj, $M);
        [$lo, $hi] = [min($vals), max($vals)];
        $cortes = [$lo];
        $acum = 0.0;
        foreach (array_slice($ordem, 0, -1) as $i) {
            $acum += $alvo[$i];
            [$a, $b] = [$cortes[count($cortes) - 1], $hi];
            for ($it = 0; $it < 80; $it++) {
                $c = ($a + $b) / 2;
                if (GeometriaPlana::area(self::recortar($M, $nrm, $c, true)) < $acum) $a = $c; else $b = $c;
            }
            $cortes[] = ($a + $b) / 2;
        }
        $cortes[] = $hi;

        $aneis = [];
        foreach ($ordem as $pos => $i) {
            $faixa = self::recortar(self::recortar($M, $nrm, $cortes[$pos], false), $nrm, $cortes[$pos + 1], true);
            $area = GeometriaPlana::area($faixa);
            if (count($faixa) < 3 || abs($area - $alvo[$i]) > self::AREA_TOL * $alvo[$i]) {
                return ['motivo' => 'Não deu para dividir o lote nas áreas do cadastro (contorno irregular): o corte tem de ser feito à mão.'];
            }
            $aneis[$i] = $volta($faixa);
        }
        ksort($aneis);

        return ['aneis' => array_values($aneis)];
    }

    /**
     * Sutherland–Hodgman contra um semiplano: fica o lado p·n ≤ c (ou ≥ c).
     *
     * @param  list<array{0:float,1:float}>  $anel
     * @return list<array{0:float,1:float}>
     */
    private static function recortar(array $anel, array $nrm, float $c, bool $abaixo): array
    {
        $dentro = fn ($p) => $abaixo ? ($p[0] * $nrm[0] + $p[1] * $nrm[1] <= $c) : ($p[0] * $nrm[0] + $p[1] * $nrm[1] >= $c);
        $saida = [];
        $k = count($anel);
        for ($i = 0; $i < $k; $i++) {
            $p = $anel[$i];
            $q = $anel[($i + 1) % $k];
            $dp = $dentro($p);
            $dq = $dentro($q);
            if ($dp) $saida[] = $p;
            if ($dp !== $dq) {
                $vp = $p[0] * $nrm[0] + $p[1] * $nrm[1];
                $vq = $q[0] * $nrm[0] + $q[1] * $nrm[1];
                $t = ($c - $vp) / ($vq - $vp);
                $saida[] = [$p[0] + $t * ($q[0] - $p[0]), $p[1] + $t * ($q[1] - $p[1])];
            }
        }

        return $saida;
    }

    /** A direção principal da fila de centróides (a das partes lado a lado). */
    private static function eixo(array $pts): ?array
    {
        $n = count($pts);
        $mx = array_sum(array_column($pts, 0)) / $n;
        $my = array_sum(array_column($pts, 1)) / $n;
        [$sxx, $syy, $sxy] = [0.0, 0.0, 0.0];
        foreach ($pts as $p) {
            $sxx += ($p[0] - $mx) ** 2;
            $syy += ($p[1] - $my) ** 2;
            $sxy += ($p[0] - $mx) * ($p[1] - $my);
        }
        if ($sxx + $syy < 1e-6) return null;
        $ang = 0.5 * atan2(2 * $sxy, $sxx - $syy);

        return [cos($ang), sin($ang)];
    }

    private static function centroide(array $anel): array
    {
        [$a, $cx, $cy] = [0.0, 0.0, 0.0];
        $k = count($anel);
        for ($i = 0; $i < $k; $i++) {
            [$x0, $y0] = $anel[$i];
            [$x1, $y1] = $anel[($i + 1) % $k];
            $f = $x0 * $y1 - $x1 * $y0;
            $a += $f;
            $cx += ($x0 + $x1) * $f;
            $cy += ($y0 + $y1) * $f;
        }
        if (abs($a) < 1e-9) {
            return [array_sum(array_column($anel, 0)) / $k, array_sum(array_column($anel, 1)) / $k];
        }

        return [$cx / (3 * $a), $cy / (3 * $a)];
    }

    private static function semFecho(array $anel): array
    {
        $u = end($anel);
        return count($anel) > 1 && $u[0] === $anel[0][0] && $u[1] === $anel[0][1] ? array_slice($anel, 0, -1) : $anel;
    }

    private static function comFecho(array $anel): array
    {
        $anel[] = $anel[0];
        return $anel;
    }
}
