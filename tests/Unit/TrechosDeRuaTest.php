<?php

namespace Tests\Unit;

use App\Cadastro\TrechosDeRua;
use PHPUnit\Framework\TestCase;

class TrechosDeRuaTest extends TestCase
{
    /** Um grau de latitude ~110,6 km; aqui, metros viram graus perto de Primavera do Leste. */
    private const M_LAT = 1 / 110574;
    private const M_LON = 1 / (111320 * 0.9634);   // cos(15,55°)

    /** Trecho de (x1,y1) a (x2,y2), em metros a partir de um ponto da cidade. */
    private function t(float $x1, float $y1, float $x2, float $y2, ?string $nome = 'RUA A', array $extra = []): array
    {
        $p = fn ($x, $y) => [-15.55 + $y * self::M_LAT, -54.29 + $x * self::M_LON];

        return ['nome' => $nome, 'de' => $p($x1, $y1), 'ate' => $p($x2, $y2)] + $extra;
    }

    private function manual(float $x1, float $y1, float $x2, float $y2, ?string $nome, bool $oculto = false): array
    {
        return $this->t($x1, $y1, $x2, $y2, $nome, ['id' => 7, 'oculto' => $oculto]);
    }

    public function test_sem_manual_os_gerados_passam_com_a_origem(): void
    {
        $r = TrechosDeRua::combinar([$this->t(0, 0, 100, 0), $this->t(0, 50, 100, 50, null)], []);
        $this->assertSame(['cadastro', 'sem_nome'], array_column($r, 'origem'));
    }

    public function test_manual_cobre_o_gerado_do_mesmo_trecho(): void
    {
        // Mesmo trecho, desenhado ao contrário e 3 m ao lado: é a mesma rua.
        $r = TrechosDeRua::combinar([$this->t(0, 0, 100, 0, null)], [$this->manual(95, 3, 5, 3, 'RUA B')]);
        $this->assertCount(1, $r);
        $this->assertSame('manual', $r[0]['origem']);
        $this->assertSame('RUA B', $r[0]['nome']);
    }

    public function test_rua_paralela_vizinha_nao_e_coberta(): void
    {
        // 30 m ao lado: é a rua de trás.
        $r = TrechosDeRua::combinar([$this->t(0, 0, 100, 0)], [$this->manual(0, 30, 100, 30, 'RUA B')]);
        $this->assertSame(['cadastro', 'manual'], array_column($r, 'origem'));
    }

    public function test_rua_transversal_nao_e_coberta(): void
    {
        $r = TrechosDeRua::combinar([$this->t(0, 0, 100, 0)], [$this->manual(50, -50, 50, 50, 'RUA B')]);
        $this->assertCount(2, $r);
    }

    public function test_trecho_seguinte_da_mesma_rua_nao_e_coberto(): void
    {
        // A quadra seguinte, depois do cruzamento: alinhada, mas sem sobreposição.
        $r = TrechosDeRua::combinar([$this->t(120, 0, 220, 0)], [$this->manual(0, 0, 100, 0, 'RUA B')]);
        $this->assertCount(2, $r);
    }

    public function test_manual_comprido_cobre_varios_gerados(): void
    {
        $r = TrechosDeRua::combinar(
            [$this->t(0, 0, 90, 0), $this->t(110, 0, 200, 0)],
            [$this->manual(0, 0, 200, 0, 'AVENIDA C')]);
        $this->assertSame(['manual'], array_column($r, 'origem'));
    }

    public function test_oculto_cobre_e_sai_marcado(): void
    {
        $r = TrechosDeRua::combinar([$this->t(0, 0, 100, 0)], [$this->manual(0, 0, 100, 0, null, true)]);
        $this->assertSame(['oculto'], array_column($r, 'origem'));
    }
}
