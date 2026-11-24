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

    // ── reconciliar: o "Gerar" refez os trechos ──

    private function m(int $id, float $x1, float $y1, float $x2, float $y2, ?string $nome, bool $oculto = false, bool $excluido = false): array
    {
        return $this->t($x1, $y1, $x2, $y2, $nome, ['id' => $id, 'oculto' => $oculto, 'excluido' => $excluido, 'user_id' => 3]);
    }

    public function test_reconciliar_o_nome_passa_para_os_trechos_novos(): void
    {
        // O manual velho (uma reta 4 m ao lado) cobre os dois pedaços novos da rua.
        $novos = [$this->t(0, 0, 50, 0, null), $this->t(50, 0, 100, 1, null)];
        $r = TrechosDeRua::reconciliar($novos, [$this->m(9, 0, 4, 100, 4, 'RUA B')]);
        $this->assertSame([9], $r['apagar']);
        $this->assertSame(1, $r['passados']);
        $this->assertSame(['RUA B', 'RUA B'], array_column($r['novos'], 'nome'));
        $this->assertSame($novos[1]['de'], $r['novos'][1]['de'], 'com o desenho do novo');
        $this->assertSame(3, $r['novos'][0]['user_id']);
    }

    public function test_reconciliar_manual_que_ja_e_o_gerado_fica(): void
    {
        $r = TrechosDeRua::reconciliar([$this->t(0, 0, 100, 0, null)], [$this->m(9, 0, 0, 100, 0, 'RUA B')]);
        $this->assertSame([], $r['apagar']);
        $this->assertSame([], $r['novos']);
    }

    public function test_reconciliar_exclusao_da_linha_velha_sai(): void
    {
        // Excluído 5 m ao lado da rua nova: era a linha torta da regra antiga.
        $r = TrechosDeRua::reconciliar([$this->t(0, 0, 100, 0, null)], [$this->m(9, 0, 5, 100, 5, null, false, true)]);
        $this->assertSame([9], $r['apagar']);
        $this->assertSame(1, $r['descartados']);
        $this->assertSame([], $r['novos']);
    }

    public function test_reconciliar_exclusao_no_mesmo_traco_continua(): void
    {
        $r = TrechosDeRua::reconciliar([$this->t(0, 0, 100, 0, null)], [$this->m(9, 0, 0.5, 100, 0.5, null, false, true)]);
        $this->assertSame(0, $r['descartados']);
        $this->assertCount(1, $r['novos']);
        $this->assertTrue($r['novos'][0]['excluido']);
    }

    public function test_reconciliar_com_nome_ganha_do_excluido_no_mesmo_trecho(): void
    {
        $r = TrechosDeRua::reconciliar([$this->t(0, 0, 100, 0, null)],
            [$this->m(8, 0, 0.5, 100, 0.5, null, false, true), $this->m(9, 0, 3, 100, 3, 'RUA B')]);
        $this->assertSame(['RUA B'], array_column($r['novos'], 'nome'));
        $this->assertContains(8, $r['apagar'], 'a exclusão fica sem trecho e sai');
    }

    public function test_reconciliar_manual_repetido_no_mesmo_trecho_sai(): void
    {
        // Dois manuais da mesma rua sobre o mesmo trecho novo: um fica com ele, o outro sai.
        $r = TrechosDeRua::reconciliar([$this->t(0, 0, 100, 0, null)],
            [$this->m(8, 0, 2, 100, 2, 'RUA B'), $this->m(9, 0, 4, 100, 4, 'RUA B')]);
        $this->assertCount(1, $r['novos']);
        $this->assertEqualsCanonicalizing([8, 9], $r['apagar']);
    }

    public function test_manual_sem_nome_encostado_continua_sem_nome(): void
    {
        // O sem nome que o curador encostou em outro trecho (24/11/2026).
        $r = TrechosDeRua::combinar([$this->t(0, 0, 100, 0, null)], [$this->manual(0, 0, 108, 0, null)]);
        $this->assertSame(['sem_nome'], array_column($r, 'origem'));
        $this->assertSame(7, $r[0]['id'], 'com o id do manual, para o nome dado depois cair nele');
    }

    public function test_reconciliar_ajustado_fica_com_o_desenho_dele(): void
    {
        // Encostado à mão 8 m além do gerado: o "Gerar" não o troca pelo novo,
        // e o novo que ele cobre não ganha outro manual por cima.
        $ajustado = $this->m(9, 0, 3, 108, 3, 'RUA B') + ['ajustado' => true];
        $r = TrechosDeRua::reconciliar([$this->t(0, 0, 100, 0, null)], [$this->m(8, 0, 2, 100, 2, 'RUA C'), $ajustado]);
        $this->assertNotContains(9, $r['apagar']);
        $this->assertSame([], array_filter($r['novos'], fn ($n) => $n['nome'] === 'RUA B'));
        $this->assertContains(8, $r['apagar'], 'o outro manual do mesmo trecho sai: o ajustado já ficou com ele');
    }

    public function test_reconciliar_rua_desenhada_a_mao_fica(): void
    {
        // Não cobre nenhum gerado (rua sem lote de frente): fica como está.
        $r = TrechosDeRua::reconciliar([$this->t(0, 0, 100, 0, null)], [$this->m(9, 0, 200, 100, 200, 'RUA NOVA')]);
        $this->assertSame([], $r['apagar']);
        $this->assertSame(0, $r['passados']);
    }
}
