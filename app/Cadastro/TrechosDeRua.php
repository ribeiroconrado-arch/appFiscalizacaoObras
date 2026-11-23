<?php

namespace App\Cadastro;

/**
 * Os trechos de rua de um bairro como o mapa os vê: os gerados (voto dos
 * lotes, ver bairros-contorno.js) com os informados pelo curador por cima.
 *
 * O manual é aplicado na LEITURA, e não na gravação: o "Gerar" refaz os
 * gerados à vontade e o manual continua valendo, sem nada a reconciliar.
 *
 * Um manual COBRE um gerado quando os dois são o mesmo pedaço de rua:
 *  - paralelos (até 15°);
 *  - o meio do gerado a até 10 m da reta do manual;
 *  - sobrepostos ao longo da rua em pelo menos metade do menor dos dois.
 * O manual que não cobre nenhum (rua sem lote de frente, desenhada à mão)
 * entra como está.
 *
 * Regra pura, sem banco: entra e sai array.
 */
final class TrechosDeRua
{
    private const ANGULO_MAX = 15.0;
    private const DISTANCIA_MAX_M = 10.0;
    private const SOBREPOSICAO_MIN = 0.5;

    /**
     * @param  list<array{id?:int, nome:?string, de:array{0:float,1:float}, ate:array{0:float,1:float}}>  $gerados
     * @param  list<array{id:int, nome:?string, oculto:bool, de:array{0:float,1:float}, ate:array{0:float,1:float}}>  $manuais
     * @return list<array{id:?int, nome:?string, de:array, ate:array, origem:string}>
     *         origem: cadastro | sem_nome | manual | oculto | excluido
     *         (o excluído volta só para a ferramenta poder desfazê-lo; o mapa não o desenha)
     */
    public static function combinar(array $gerados, array $manuais): array
    {
        $saida = [];
        foreach ($gerados as $g) {
            $coberto = false;
            foreach ($manuais as $m) {
                if (self::cobre($m, $g)) {
                    $coberto = true;
                    break;
                }
            }
            if (! $coberto) {
                $saida[] = ['id' => null, 'nome' => $g['nome'], 'de' => $g['de'], 'ate' => $g['ate'],
                    'origem' => $g['nome'] === null ? 'sem_nome' : 'cadastro'];
            }
        }
        foreach ($manuais as $m) {
            $saida[] = ['id' => $m['id'], 'nome' => $m['nome'], 'de' => $m['de'], 'ate' => $m['ate'],
                'origem' => ! empty($m['excluido']) ? 'excluido' : ($m['oculto'] ? 'oculto' : 'manual')];
        }

        return $saida;
    }

    /** O manual `$m` é o mesmo pedaço de rua que o gerado `$g`? */
    public static function cobre(array $m, array $g): bool
    {
        return self::mesmoPedaco($m, $g, self::ANGULO_MAX, self::DISTANCIA_MAX_M, self::SOBREPOSICAO_MIN);
    }

    /**
     * O manual EXCLUÍDO só passa para um gerado novo quando é o mesmo traço:
     * a exclusão foi feita naquela linha, não na rua.
     */
    private const EXCLUIDO_ANGULO = 10.0;
    private const EXCLUIDO_DISTANCIA_M = 1.5;
    private const EXCLUIDO_SOBREPOSICAO = 0.8;

    /**
     * O "Gerar" refez os trechos: os manuais passam para os NOVOS
     * (23/11/2026). O manual guarda o desenho do gerado de quando foi salvo;
     * com a regra nova (o meio entre as quadras), o desenho velho ficava por
     * cima do novo, torto, e escondia o novo que estivesse perto.
     *
     *  - com nome ou oculto: vira um manual por gerado novo que ele cobre
     *    (cobre()), com o mesmo nome/oculto e o desenho do novo;
     *  - excluído: só passa para o gerado que é o mesmo traço (1,5 m, 80%) —
     *    a exclusão feita na regra velha era de lixo que a nova não gera, e
     *    sobre a rua de verdade esconderia a linha certa. Sem par, sai;
     *  - com nome ou oculto que não cobre nenhum (rua desenhada à mão) fica.
     *
     * Um gerado fica com um manual só: com nome ganha de oculto, que ganha de
     * excluído; empate, o mais novo. O manual que já é exatamente um gerado
     * fica como está.
     *
     * @param  list<array{nome:?string, de:array, ate:array}>  $gerados
     * @param  list<array{id:int, nome:?string, oculto:bool, excluido?:bool, de:array, ate:array, user_id?:?int}>  $manuais
     * @return array{apagar: list<int>, novos: list<array>, passados: int, descartados: int}
     */
    public static function reconciliar(array $gerados, array $manuais): array
    {
        $peso = fn (array $m) => ! empty($m['excluido']) ? 0 : ($m['oculto'] ? 1 : 2);
        usort($manuais, fn ($a, $b) => [$peso($b), $b['id']] <=> [$peso($a), $a['id']]);
        $igual = fn (array $m, array $g) => (self::perto($m['de'], $g['de']) && self::perto($m['ate'], $g['ate']))
            || (self::perto($m['de'], $g['ate']) && self::perto($m['ate'], $g['de']));

        $tomado = [];
        $apagar = $novos = [];
        $passados = $descartados = 0;
        foreach ($manuais as $m) {
            $excluido = ! empty($m['excluido']);
            $seus = [];
            $jaDeOutro = false;
            foreach ($gerados as $i => $g) {
                $casa = $excluido
                    ? self::mesmoPedaco($m, $g, self::EXCLUIDO_ANGULO, self::EXCLUIDO_DISTANCIA_M, self::EXCLUIDO_SOBREPOSICAO)
                    : self::cobre($m, $g);
                if (! $casa) {
                    continue;
                }
                if (isset($tomado[$i])) {
                    $jaDeOutro = true;
                } else {
                    $seus[] = $i;
                }
            }
            if (! $seus) {
                // Tudo o que ele cobre já ficou com outro manual (de mais peso,
                // ou mais novo): ficaria duplicado por cima, e sai.
                if ($jaDeOutro) {
                    $apagar[] = $m['id'];
                    $passados++;
                } elseif ($excluido) {
                    $apagar[] = $m['id'];
                    $descartados++;
                }
                continue;
            }
            foreach ($seus as $i) {
                $tomado[$i] = true;
            }
            if (count($seus) === 1 && $igual($m, $gerados[$seus[0]])) {
                continue;   // já é o gerado: nada muda
            }
            $apagar[] = $m['id'];
            $passados++;
            foreach ($seus as $i) {
                $novos[] = ['nome' => $m['nome'], 'oculto' => (bool) $m['oculto'], 'excluido' => $excluido,
                    'de' => $gerados[$i]['de'], 'ate' => $gerados[$i]['ate'], 'user_id' => $m['user_id'] ?? null];
            }
        }

        return ['apagar' => $apagar, 'novos' => $novos, 'passados' => $passados, 'descartados' => $descartados];
    }

    /** Dois pontos [lat,lon] a menos de ~1 cm. */
    private static function perto(array $a, array $b): bool
    {
        return abs($a[0] - $b[0]) < 1e-7 && abs($a[1] - $b[1]) < 1e-7;
    }

    private static function mesmoPedaco(array $m, array $g, float $anguloMax, float $distanciaMax, float $sobreposicaoMin): bool
    {
        // Plano local em metros, com origem no início do manual: a escala do
        // grau de longitude muda com a latitude, e comparar em grau distorce
        // a distância conforme a direção da rua.
        $lat0 = deg2rad($m['de'][0]);
        $mx = fn (array $p) => [($p[1] - $m['de'][1]) * 111320 * cos($lat0), ($p[0] - $m['de'][0]) * 110574];

        [$ax, $ay] = $mx($m['de']);
        [$bx, $by] = $mx($m['ate']);
        [$cx, $cy] = $mx($g['de']);
        [$dx, $dy] = $mx($g['ate']);

        $lm = hypot($bx - $ax, $by - $ay);
        $lg = hypot($dx - $cx, $dy - $cy);
        if ($lm < 0.01 || $lg < 0.01) {
            return false;
        }
        $ux = ($bx - $ax) / $lm;
        $uy = ($by - $ay) / $lm;

        // Ângulo entre as retas, sem sentido (uma rua desenhada ao contrário é a mesma rua).
        $cos = abs($ux * ($dx - $cx) / $lg + $uy * ($dy - $cy) / $lg);
        if ($cos < cos(deg2rad($anguloMax))) {
            return false;
        }

        // Distância do meio do gerado à reta do manual.
        $mxg = ($cx + $dx) / 2 - $ax;
        $myg = ($cy + $dy) / 2 - $ay;
        if (abs($mxg * $uy - $myg * $ux) > $distanciaMax) {
            return false;
        }

        // Sobreposição ao longo da rua.
        $t1 = $cx * $ux + $cy * $uy - ($ax * $ux + $ay * $uy);
        $t2 = $dx * $ux + $dy * $uy - ($ax * $ux + $ay * $uy);
        $sobre = min($lm, max($t1, $t2)) - max(0.0, min($t1, $t2));

        return $sobre >= $sobreposicaoMin * min($lm, $lg);
    }
}
