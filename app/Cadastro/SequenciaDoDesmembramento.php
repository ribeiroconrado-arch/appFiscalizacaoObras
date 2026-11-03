<?php

namespace App\Cadastro;

/**
 * Qual inscrição do cadastro cada parte de um lote desmembrado recebe — a
 * regra dele, de 03/11/2026, para TODO o sistema (conferência com o cadastro
 * e o levantamento de 2022):
 *
 *   1. SEQUÊNCIA: as partes na ordem do desenho (o lote sem letra, depois A,
 *      B, C…) recebem as inscrições ATIVAS da família em ordem crescente de
 *      final. Se a .000 está ativa, ela é a da primeira parte (o A); se não,
 *      a sequência começa na .001.
 *   2. ÁREA: a sequência é só o ponto de partida. Cada inscrição vai para a
 *      parte de "Área Terreno" mais próxima — se o 4A tem 200 m² e o 4B 300,
 *      e no cadastro a .001 tem 300 e a .002 tem 200, o desenho está
 *      invertido: 4A fica com a .002 e 4B com a .001.
 *   3. EMPATE (áreas iguais, como metades de 225 m²): vale a sequência, em
 *      ordem crescente.
 *   4. Parte que sobra (mais letras que inscrições ativas) fica sem
 *      inscrição. Área muito diferente não impede: a conferência marca a
 *      divergência, como em qualquer lote.
 *
 * "Mais próxima" é a soma das diferenças, entre todas as formas de distribuir
 * — e não a parte a parte, que podia dar a melhor inscrição à primeira parte e
 * deixar a pior para a segunda.
 */
final class SequenciaDoDesmembramento
{
    /** Diferença de soma de áreas, em m², que ainda é empate. */
    private const EMPATE_M2 = 0.01;

    /** Teto da busca: acima disso fica a melhor distribuição achada até ali. */
    private const MAX_PASSOS = 200000;

    /**
     * @param  list<?float>        $areas     área de cada parte, NA ORDEM (sem letra, A, B…)
     * @param  array<int,?float>   $cadastro  final => "Área Terreno", só das inscrições ATIVAS da família
     * @return list<?int>  o final de cada parte, na mesma ordem; null = sobrou
     */
    public static function atribuir(array $areas, array $cadastro): array
    {
        $areas = array_values($areas);
        ksort($cadastro);
        $finais = array_keys($cadastro);
        $n = count($areas);
        $m = count($finais);
        $sobram = max(0, $n - $m);   // quantas partes ficam sem inscrição

        $melhor = null;
        $melhorCusto = INF;
        $passos = 0;
        $atual = [];
        $usado = [];

        // Busca em profundidade com as inscrições em ordem crescente: a
        // primeira distribuição completa é a própria sequência, e uma outra só
        // a substitui se for MELHOR que ela além do empate — por isso o empate
        // fica com a sequência.
        $buscar = function (int $i, float $custo, int $semInscricao) use (&$buscar, &$melhor, &$melhorCusto, &$passos, &$atual, &$usado, $areas, $cadastro, $finais, $n, $m, $sobram): void {
            if (++$passos > self::MAX_PASSOS || $custo >= $melhorCusto - self::EMPATE_M2) {
                return;
            }
            if ($i === $n) {
                $melhor = $atual;
                $melhorCusto = $custo;
                return;
            }
            for ($k = 0; $k < $m; $k++) {
                if (isset($usado[$k])) continue;
                $usado[$k] = true;
                $atual[$i] = $finais[$k];
                $buscar($i + 1, $custo + self::diferenca($areas[$i], $cadastro[$finais[$k]]), $semInscricao);
                unset($usado[$k]);
            }
            if ($semInscricao < $sobram) {
                $atual[$i] = null;
                $buscar($i + 1, $custo, $semInscricao + 1);
            }
        };
        $buscar(0, 0.0, 0);

        return $melhor ?? array_fill(0, $n, null);
    }

    /**
     * Ordem das partes no desenho: o número sem letra primeiro, depois A, B, C…
     * Null quando o número não é "dígitos + no máximo uma letra" — 12AB é
     * unificação, e fica fora desta regra.
     *
     * @return array{0:int, 1:string}|null  [número, letra ('' sem letra)]
     */
    public static function numeroELetra(?string $numeroLote): ?array
    {
        if ($numeroLote === null || ! preg_match('/^\s*0*(\d+)\s*([A-Za-z]?)\s*$/', $numeroLote, $m)) {
            return null;
        }

        return [(int) $m[1], strtoupper($m[2])];
    }

    /** Área desconhecida não pesa: quem decide é a sequência. */
    private static function diferenca(?float $a, ?float $b): float
    {
        return $a === null || $b === null ? 0.0 : abs($a - $b);
    }
}
