<?php

namespace Tests\Unit;

use App\Services\ConferenciaComCadastro;
use PHPUnit\Framework\TestCase;

class AreaArredondamentoTest extends TestCase
{
    /** Retângulo em metros, fechado. */
    private function lote(float $frente, float $fundo): array
    {
        return [[0, 0], [$frente, 0], [$frente, $fundo], [0, $fundo], [0, 0]];
    }

    public function test_lado_com_centimetros_e_medida_quebrada(): void
    {
        $this->assertTrue(ConferenciaComCadastro::temMedidaQuebrada($this->lote(10.19, 22.5)));
        $this->assertTrue(ConferenciaComCadastro::temMedidaQuebrada($this->lote(12, 22.53)));
    }

    public function test_metro_inteiro_com_milimetro_de_desenho_nao_e_quebrada(): void
    {
        $this->assertFalse(ConferenciaComCadastro::temMedidaQuebrada($this->lote(12, 20)));
        $this->assertFalse(ConferenciaComCadastro::temMedidaQuebrada($this->lote(12.002, 20.001)));
    }

    public function test_vertice_repetido_ou_no_meio_da_reta_nao_parte_o_lado(): void
    {
        // [12, 0.013]: 1,3 cm de "lado"; [6, 20]: no meio do fundo. O lote é 12 × 20.
        $anel = [[0, 0], [12, 0], [12, 0.013], [12, 20], [6, 20], [0, 20], [0, 0]];
        $this->assertFalse(ConferenciaComCadastro::temMedidaQuebrada($anel));
    }

    public function test_lote_de_esquina_com_arco_tem_medida_quebrada(): void
    {
        $anel = [[0, 0], [10, 0], [11.5, 0.2], [12, 1.5], [12, 20], [0, 20], [0, 0]];
        $this->assertTrue(ConferenciaComCadastro::temMedidaQuebrada($anel));
    }
}
