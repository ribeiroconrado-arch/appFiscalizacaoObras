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
     *         origem: cadastro | sem_nome | manual | oculto
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
                'origem' => $m['oculto'] ? 'oculto' : 'manual'];
        }

        return $saida;
    }

    /** O manual `$m` é o mesmo pedaço de rua que o gerado `$g`? */
    public static function cobre(array $m, array $g): bool
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
        if ($cos < cos(deg2rad(self::ANGULO_MAX))) {
            return false;
        }

        // Distância do meio do gerado à reta do manual.
        $mxg = ($cx + $dx) / 2 - $ax;
        $myg = ($cy + $dy) / 2 - $ay;
        if (abs($mxg * $uy - $myg * $ux) > self::DISTANCIA_MAX_M) {
            return false;
        }

        // Sobreposição ao longo da rua.
        $t1 = $cx * $ux + $cy * $uy - ($ax * $ux + $ay * $uy);
        $t2 = $dx * $ux + $dy * $uy - ($ax * $ux + $ay * $uy);
        $sobre = min($lm, max($t1, $t2)) - max(0.0, min($t1, $t2));

        return $sobre >= self::SOBREPOSICAO_MIN * min($lm, $lg);
    }
}
