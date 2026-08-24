<?php

namespace App\Support;

/**
 * Medida de área e sobreposição entre lotes, calculada FORA do banco.
 *
 * Existe por um motivo medido: em SRID 4326 o `ST_Intersection` do MySQL 8
 * devolve resultado errado. Dois lotes vizinhos do Residencial Buritis V
 * (Q40, lotes 1 e 30), de 214,47 m² cada, foram reportados pelo banco com
 * 215,5 m² de área comum — mais do que a área de qualquer um dos dois, o que
 * é aritmeticamente impossível numa interseção. A interseção verdadeira,
 * conferida com o mesmo GeoJSON que originou os registros, é ZERO: eles são
 * vizinhos, não sobrepostos.
 *
 * O mesmo par também engana o `ST_Overlaps`, que responde "sim" para lotes que
 * apenas se encostam. Nenhum dos dois serve para provar sobreposição.
 *
 * A saída é dividir o trabalho: o banco continua achando os CANDIDATOS pelo
 * índice espacial (`MBRIntersects`, que é comparação de retângulos e não erra),
 * e a medida sai daqui.
 *
 * ── REGRA QUE NÃO PODE SER QUEBRADA: não misture réguas ──
 *
 * Existem três medidas de área em jogo, e elas NÃO coincidem:
 *
 *   ST_Area do MySQL   área geodésica. Erra ±0,35% de forma ruidosa, sem viés.
 *   GeometriaPlana     área real de terreno. Constante e previsível.
 *   UTM (EPSG:31981)   área de GRADE. É o que o DWG traz e o que o agrimensor
 *                      usa, e vem inflada ~0,12% aqui: Primavera fica a 2,7°
 *                      do meridiano central e o fator k0=0,9996 do UTM cresce
 *                      com a distância. Medido: GeometriaPlana fica 0,126%
 *                      abaixo do UTM em TODOS os lotes conferidos — que é
 *                      exatamente a distorção esperada, não erro.
 *
 * Daí a regra: `area_gis_m2` continua vindo do `ST_Area`, para o lote novo
 * ficar na mesma régua dos 2.239 que vieram da importação. E toda COMPARAÇÃO
 * — partes contra o pai, união contra a soma, sobreposição contra tolerância —
 * mede os dois lados com a MESMA régua, sempre esta. Comparar área medida por
 * motores diferentes produziria diferença de 0,25% sem nada ter acontecido: num
 * lote de 360 m² são 0,9 m² fantasmas, metade da tolerância gasta à toa.
 *
 * ── Sobre projetar em plano ──
 *
 * Lote urbano tem dezenas de metros. Nessa escala, projetar latitude e
 * longitude em metros locais é exato para o que se pergunta aqui, e é o mesmo
 * que o pipeline de extração já faz (a base nasce em UTM, EPSG:31981, e é
 * reprojetada para 4326 só para o Leaflet consumir). Trabalhar em graus
 * diretamente seria pior: um grau de longitude vale ~107 km nesta latitude e
 * um de latitude ~110 km, então área em graus² não é área.
 */
class GeometriaPlana
{
    /** Semieixo maior do GRS80/SIRGAS 2000, em metros. */
    private const A = 6378137.0;

    /** Primeira excentricidade ao quadrado do GRS80. */
    private const E2 = 0.00669438002290;

    /**
     * Converte um anel [[lon,lat],…] em metros locais, com origem no próprio
     * anel. A origem local evita trabalhar com números de sete dígitos, onde a
     * precisão do float se gasta antes de chegar aos centímetros.
     *
     * ── Por que as fórmulas do elipsoide, e não constantes redondas ──
     *
     * A primeira versão usava 110.540 m por grau de latitude e 111.320·cos(φ)
     * por grau de longitude — os valores de esfera, que se acham em qualquer
     * lugar. Medido contra o CRS de ORIGEM da base (SIRGAS 2000 / UTM 21S, de
     * onde os lotes vieram), aquilo errava −0,252% em TODOS os lotes.
     *
     * Erro constante em comparação de áreas se cancela, então não estragava a
     * conta de sobreposição. Mas estragaria a de desmembramento: ali a área do
     * lote-pai vem de `area_gis_m2` (medida pelo MySQL) e a das partes viria
     * daqui, e 0,25% de um lote de 360 m² são 0,9 m² de diferença fantasma —
     * metade da tolerância, gasta sem nada ter acontecido.
     *
     * Com o raio meridional e o raio da grande normal do próprio elipsoide, o
     * viés sistemático desaparece.
     *
     * @param  list<array{0:float,1:float}>  $anel
     * @return list<array{0:float,1:float}>
     */
    public static function projetar(array $anel, ?float $latRef = null, ?float $lonRef = null): array
    {
        if (! $anel) {
            return [];
        }

        $latRef ??= $anel[0][1];
        $lonRef ??= $anel[0][0];

        $sen = sin(deg2rad($latRef));
        $w   = 1 - self::E2 * $sen * $sen;

        // Raio meridional (M) e raio da grande normal (N), em metros por radiano.
        $m = self::A * (1 - self::E2) / ($w * sqrt($w));
        $n = self::A / sqrt($w);

        $porGrauLat = $m * M_PI / 180;
        $porGrauLon = $n * M_PI / 180 * cos(deg2rad($latRef));

        return array_map(
            fn ($c) => [($c[0] - $lonRef) * $porGrauLon, ($c[1] - $latRef) * $porGrauLat],
            $anel
        );
    }

    /**
     * Área de um anel projetado, em m². Fórmula do cadarço (shoelace).
     *
     * @param  list<array{0:float,1:float}>  $anel
     */
    public static function area(array $anel): float
    {
        $n = count($anel);
        if ($n < 3) {
            return 0.0;
        }

        $s = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $s += $anel[$i][0] * $anel[$j][1] - $anel[$j][0] * $anel[$i][1];
        }

        return abs($s) / 2;
    }

    /**
     * Área comum a dois anéis, em m².
     *
     * Recorte de Sutherland–Hodgman: exato quando o polígono que RECORTA é
     * convexo, que é o caso de praticamente todo lote urbano (quadrilátero).
     * Para o polígono recortado não há exigência.
     *
     * Quando nenhum dos dois é convexo o método pode superestimar, então o
     * resultado sai marcado como não confiável — melhor dizer "não sei medir"
     * do que devolver um número que ninguém pode conferir. É exatamente o
     * defeito que este arquivo veio corrigir no banco.
     *
     * @param  list<array{0:float,1:float}>  $a  anel em metros
     * @param  list<array{0:float,1:float}>  $b  anel em metros
     * @return array{area: float, confiavel: bool}
     */
    public static function areaComum(array $a, array $b): array
    {
        if (self::ehConvexo($b)) {
            return ['area' => self::area(self::recortar($a, $b)), 'confiavel' => true];
        }
        if (self::ehConvexo($a)) {
            return ['area' => self::area(self::recortar($b, $a)), 'confiavel' => true];
        }

        return ['area' => self::area(self::recortar($a, $b)), 'confiavel' => false];
    }

    /**
     * Recorta o polígono `$sujeito` pelas arestas de `$corte`.
     *
     * @param  list<array{0:float,1:float}>  $sujeito
     * @param  list<array{0:float,1:float}>  $corte
     * @return list<array{0:float,1:float}>
     */
    private static function recortar(array $sujeito, array $corte): array
    {
        $saida = self::semFechamento($sujeito);
        $corte = self::semFechamento($corte);
        $n = count($corte);
        if ($n < 3) {
            return [];
        }

        // Orientação anti-horária, para "dentro" ser sempre o lado esquerdo.
        if (self::areaAssinada($corte) < 0) {
            $corte = array_reverse($corte);
        }

        for ($i = 0; $i < $n; $i++) {
            $p1 = $corte[$i];
            $p2 = $corte[($i + 1) % $n];
            $entrada = $saida;
            $saida = [];
            $m = count($entrada);

            for ($k = 0; $k < $m; $k++) {
                $atual = $entrada[$k];
                $anterior = $entrada[($k + $m - 1) % $m];
                $dentroAtual = self::aEsquerda($p1, $p2, $atual);
                $dentroAnterior = self::aEsquerda($p1, $p2, $anterior);

                if ($dentroAtual) {
                    if (! $dentroAnterior) {
                        $saida[] = self::cruzamento($anterior, $atual, $p1, $p2);
                    }
                    $saida[] = $atual;
                } elseif ($dentroAnterior) {
                    $saida[] = self::cruzamento($anterior, $atual, $p1, $p2);
                }
            }

            if (! $saida) {
                return [];   // recorte esvaziou: não há área comum
            }
        }

        return $saida;
    }

    /** @param list<array{0:float,1:float}> $anel */
    public static function ehConvexo(array $anel): bool
    {
        $a = self::semFechamento($anel);
        $n = count($a);
        if ($n < 4) {
            return true;   // triângulo é sempre convexo
        }

        $sinal = 0;
        for ($i = 0; $i < $n; $i++) {
            $p = $a[$i];
            $q = $a[($i + 1) % $n];
            $r = $a[($i + 2) % $n];
            $cruz = ($q[0] - $p[0]) * ($r[1] - $q[1]) - ($q[1] - $p[1]) * ($r[0] - $q[0]);
            if (abs($cruz) < 1e-9) {
                continue;   // vértices colineares não decidem nada
            }
            $atual = $cruz > 0 ? 1 : -1;
            if ($sinal === 0) {
                $sinal = $atual;
            } elseif ($sinal !== $atual) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{0:float,1:float}> $anel */
    private static function semFechamento(array $anel): array
    {
        $n = count($anel);
        if ($n > 1 && abs($anel[0][0] - $anel[$n - 1][0]) < 1e-12
                   && abs($anel[0][1] - $anel[$n - 1][1]) < 1e-12) {
            array_pop($anel);
        }

        return array_values($anel);
    }

    /** @param list<array{0:float,1:float}> $anel */
    private static function areaAssinada(array $anel): float
    {
        $n = count($anel);
        $s = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $s += $anel[$i][0] * $anel[$j][1] - $anel[$j][0] * $anel[$i][1];
        }

        return $s / 2;
    }

    /** O ponto está à esquerda da reta p1→p2 (ou sobre ela)? */
    private static function aEsquerda(array $p1, array $p2, array $ponto): bool
    {
        return (($p2[0] - $p1[0]) * ($ponto[1] - $p1[1])
              - ($p2[1] - $p1[1]) * ($ponto[0] - $p1[0])) >= -1e-9;
    }

    /** Onde o segmento a→b cruza a reta p1→p2. */
    private static function cruzamento(array $a, array $b, array $p1, array $p2): array
    {
        $dc = [$p1[0] - $p2[0], $p1[1] - $p2[1]];
        $dp = [$a[0] - $b[0], $a[1] - $b[1]];
        $n1 = $p1[0] * $p2[1] - $p1[1] * $p2[0];
        $n2 = $a[0] * $b[1] - $a[1] * $b[0];
        $den = $dc[0] * $dp[1] - $dc[1] * $dp[0];

        if (abs($den) < 1e-12) {
            return $b;   // paralelos: devolve o vértice, sem inventar ponto
        }

        return [($n1 * $dp[0] - $n2 * $dc[0]) / $den, ($n1 * $dp[1] - $n2 * $dc[1]) / $den];
    }
}
