<?php

namespace App\Posturas;

use InvalidArgumentException;

/**
 * A MULTA DA LEI 723/2002 — pela diferença entre o ruído medido e o limite.
 *
 * Portada do AppPOSTURAS (`_upfPorDiferencaDb`, autos.js), com as mesmas
 * regras:
 *  - o limite vem da ZONA (`areas_db` da lei: {area, diurno, noturno}) e do
 *    PERÍODO — noturno das 18h às 6h;
 *  - a diferença (aferido − limite) cai na primeira faixa (`faixa_db`:
 *    {ate_db, upf, nivel}) cujo `ate_db` a comporta; `ate_db` nulo é a faixa
 *    de cima, sem teto;
 *  - dentro do limite (diferença ≤ 0) não há multa;
 *  - a reincidência dobra a UPF.
 *
 * Regra pura: recebe os parâmetros da lei e a medição, devolve a conta e a
 * memória de cálculo que vai impressa na peça.
 */
final class MultaPorRuido
{
    /**
     * @param list<array{area:string,diurno:int|float,noturno:int|float}> $areas
     * @param list<array{ate_db:int|float|null,upf:int|float,nivel?:string}> $faixas
     */
    public function __construct(private array $areas, private array $faixas) {}

    /** Noturno das 18h às 6h, como no Posturas. */
    public static function noturno(int $hora): bool
    {
        return $hora >= 18 || $hora < 6;
    }

    /** O limite em dB da zona no período. */
    public function limite(string $area, int $hora): float
    {
        foreach ($this->areas as $a) {
            if (mb_strtolower(trim($a['area'])) === mb_strtolower(trim($area))) {
                return (float) (self::noturno($hora) ? $a['noturno'] : $a['diurno']);
            }
        }

        throw new InvalidArgumentException('Zona não cadastrada na lei: ' . $area);
    }

    /**
     * @return array{limite:float,periodo:string,diferenca:float,nivel:?string,upf:float,fator:int,memoria:string}
     */
    public function calcular(string $area, int $hora, float $aferido, bool $reincidente = false): array
    {
        $limite   = $this->limite($area, $hora);
        $periodo  = self::noturno($hora) ? 'noturno' : 'diurno';
        $diferenca = round($aferido - $limite, 1);
        $n = fn (float $v, int $c = 1) => number_format($v, $c, ',', '.');

        if ($diferenca <= 0) {
            return ['limite' => $limite, 'periodo' => $periodo, 'diferenca' => $diferenca, 'nivel' => null,
                'upf' => 0.0, 'fator' => 1,
                'memoria' => sprintf('Aferido %s dB · limite %s dB (%s) — dentro do permitido, sem multa.',
                    $n($aferido), $n($limite, 0), $periodo)];
        }

        $faixa = $this->faixaDa($diferenca);
        $fator = $reincidente ? 2 : 1;
        $upf   = (float) $faixa['upf'] * $fator;

        return [
            'limite' => $limite, 'periodo' => $periodo, 'diferenca' => $diferenca,
            'nivel' => $faixa['nivel'] ?? null, 'upf' => $upf, 'fator' => $fator,
            'memoria' => sprintf('Aferido %s dB − limite %s dB (%s) = +%s dB · faixa %s: %s UPF%s',
                $n($aferido), $n($limite, 0), $periodo, $n($diferenca),
                mb_strtolower($faixa['nivel'] ?? ('até ' . ($faixa['ate_db'] ?? '∞') . ' dB')),
                $n((float) $faixa['upf'], 0),
                $reincidente ? ' × 2 (reincidência) = ' . $n($upf, 0) . ' UPF' : ''),
        ];
    }

    /** A primeira faixa que comporta a diferença; a de `ate_db` nulo não tem teto. */
    private function faixaDa(float $diferenca): array
    {
        $ordenadas = $this->faixas;
        usort($ordenadas, fn ($a, $b) => ($a['ate_db'] ?? INF) <=> ($b['ate_db'] ?? INF));

        foreach ($ordenadas as $f) {
            if ($f['ate_db'] === null || $diferenca <= (float) $f['ate_db']) {
                return $f;
            }
        }

        // Sem faixa aberta e acima de todas: vale a mais alta.
        return end($ordenadas) ?: throw new InvalidArgumentException('A lei não tem faixas de decibéis.');
    }
}
