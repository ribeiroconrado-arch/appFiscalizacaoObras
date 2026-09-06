<?php

namespace Tests\Unit;

use App\Support\GeometriaPlana;
use PHPUnit\Framework\TestCase;

/**
 * A RÉGUA DO SISTEMA É A GRADE DO UTM.
 *
 * O lote usado aqui é real: Residencial Buritis V, quadra 47, lote 29, com as
 * coordenadas exatamente como saíram da importação do DWG. Ele foi PROJETADO
 * com 10,00 × 21,50 m e 215,00 m² — números redondos, porque é assim que um
 * loteamento é desenhado —, e é contra esses números que a medida do sistema
 * tem de fechar.
 *
 * Antes do fator de escala, este mesmo lote era medido como 9,99 × 21,49 m e
 * 214,74 m²: a diferença entre medir no chão e medir na grade. O teste existe
 * para que ninguém remova o fator achando que é arredondamento.
 */
class GeometriaPlanaTest extends TestCase
{
    /** O anel como veio do DWG, em EPSG:4326. */
    private const BURITIS_Q47_L29 = [
        [-54.342388840838254, -15.527585038628917],
        [-54.342480424355244, -15.527601561662465],
        [-54.342443783309136, -15.527792466496976],
        [-54.342352199715720, -15.527775943435666],
        [-54.342388840838254, -15.527585038628917],
    ];

    /** Área de grade que o pipeline de extração mediu em UTM, antes de reprojetar. */
    private const BURITIS_AREA_GRADE_M2 = 215.00;

    public function test_lados_do_lote_batem_com_a_medida_do_projeto(): void
    {
        $p = GeometriaPlana::projetar(self::BURITIS_Q47_L29);

        $lados = [];
        for ($i = 0; $i < count($p) - 1; $i++) {
            $lados[] = hypot($p[$i + 1][0] - $p[$i][0], $p[$i + 1][1] - $p[$i][1]);
        }

        // Um centímetro de tolerância: o lote foi projetado nestes valores, e o
        // que sobra é o resíduo de digitalização do próprio DWG.
        $this->assertEqualsWithDelta(10.00, $lados[0], 0.01, 'frente');
        $this->assertEqualsWithDelta(21.50, $lados[1], 0.01, 'lado direito');
        $this->assertEqualsWithDelta(10.00, $lados[2], 0.01, 'fundos');
        $this->assertEqualsWithDelta(21.50, $lados[3], 0.01, 'lado esquerdo');
    }

    public function test_area_bate_com_a_area_de_grade_da_importacao(): void
    {
        $area = GeometriaPlana::area(GeometriaPlana::projetar(self::BURITIS_Q47_L29));

        // 5 cm² de folga. Sem o fator de escala isto dava 214,74 m² — 0,26 m²
        // abaixo, que é a diferença que o desmembramento acusava como fantasma.
        $this->assertEqualsWithDelta(self::BURITIS_AREA_GRADE_M2, $area, 0.05);
    }

    public function test_fator_de_escala_bate_com_o_do_utm_21s(): void
    {
        // Primavera do Leste está a ~2,66° do meridiano central da zona 21
        // (57°O). O fator cresce com essa distância, partindo de k0 = 0,9996.
        // 1,000605 quer dizer: 0,0605% a mais em cada medida linear, e o dobro
        // disso em área.
        $k = GeometriaPlana::fatorDeEscala(-15.5276, -54.3424);

        $this->assertEqualsWithDelta(1.0006053, $k, 1e-6);
    }

    public function test_no_meridiano_central_o_fator_e_o_k0(): void
    {
        $this->assertEqualsWithDelta(0.9996, GeometriaPlana::fatorDeEscala(-15.5, -57.0), 1e-12);
    }

    /**
     * O fator depende da distância ao meridiano central, e não do hemisfério
     * nem do sinal da longitude — dois pontos simétricos em torno do meridiano
     * têm o mesmo fator. Sem isto, um erro de sinal passaria despercebido.
     */
    public function test_fator_e_simetrico_em_torno_do_meridiano_central(): void
    {
        $oeste = GeometriaPlana::fatorDeEscala(-15.5, -59.7);
        $leste = GeometriaPlana::fatorDeEscala(-15.5, -54.3);

        $this->assertEqualsWithDelta($oeste, $leste, 1e-12);
    }
}
