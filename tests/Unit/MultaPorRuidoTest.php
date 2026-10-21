<?php

namespace Tests\Unit;

use App\Posturas\MultaPorRuido;
use PHPUnit\Framework\TestCase;

/** A multa da Lei 723/2002, com as faixas e zonas do AppPOSTURAS. */
class MultaPorRuidoTest extends TestCase
{
    private function lei(): MultaPorRuido
    {
        return new MultaPorRuido(
            [['area' => 'Área mista, predominantemente residencial', 'diurno' => 55, 'noturno' => 50]],
            [
                ['ate_db' => 10, 'upf' => 250, 'nivel' => 'Leve'],
                ['ate_db' => 30, 'upf' => 500, 'nivel' => 'Moderada'],
                ['ate_db' => 40, 'upf' => 750, 'nivel' => 'Grave'],
                ['ate_db' => null, 'upf' => 1000, 'nivel' => 'Gravíssima'],
            ],
        );
    }

    public function test_noturno_vai_das_18_as_6(): void
    {
        $this->assertTrue(MultaPorRuido::noturno(18));
        $this->assertTrue(MultaPorRuido::noturno(5));
        $this->assertFalse(MultaPorRuido::noturno(6));
        $this->assertFalse(MultaPorRuido::noturno(17));
    }

    public function test_dentro_do_limite_nao_ha_multa(): void
    {
        $r = $this->lei()->calcular('Área mista, predominantemente residencial', 14, 55.0);
        $this->assertSame(0.0, $r['upf']);
        $this->assertNull($r['nivel']);
    }

    public function test_faixa_pela_diferenca_no_periodo_noturno(): void
    {
        // 23h: limite 50; 68,5 − 50 = 18,5 → Moderada, 500 UPF.
        $r = $this->lei()->calcular('Área mista, predominantemente residencial', 23, 68.5);
        $this->assertSame(50.0, $r['limite']);
        $this->assertSame(18.5, $r['diferenca']);
        $this->assertSame('Moderada', $r['nivel']);
        $this->assertSame(500.0, $r['upf']);
    }

    public function test_limite_da_faixa_e_inclusivo(): void
    {
        $r = $this->lei()->calcular('Área mista, predominantemente residencial', 10, 65.0); // +10
        $this->assertSame('Leve', $r['nivel']);
    }

    public function test_acima_de_todas_cai_na_faixa_aberta(): void
    {
        $r = $this->lei()->calcular('Área mista, predominantemente residencial', 23, 120.0);
        $this->assertSame('Gravíssima', $r['nivel']);
        $this->assertSame(1000.0, $r['upf']);
    }

    public function test_reincidencia_dobra(): void
    {
        $r = $this->lei()->calcular('Área mista, predominantemente residencial', 23, 68.5, true);
        $this->assertSame(1000.0, $r['upf']);
        $this->assertSame(2, $r['fator']);
        $this->assertStringContainsString('reincidência', $r['memoria']);
    }

    public function test_zona_desconhecida_recusa(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lei()->limite('Zona que não existe', 12);
    }
}
