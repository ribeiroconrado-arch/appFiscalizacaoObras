<?php

namespace Tests\Unit;

use App\Cadastro\ColunasDaExportacao;
use App\Cadastro\DiferencaDoCadastro;
use PHPUnit\Framework\TestCase;

/**
 * O relatório "Imobiliário Urbano" da prefeitura: outros nomes de coluna, e
 * sem o código do bairro. Uma carga feita com ele em 03/10/2026 gravou 56.587
 * imóveis só com inscrição e código — a ficha deixou de achar qualquer um.
 *
 * As MESMAS linhas estão em tests/cadastro-desktop.test.cjs, com os códigos de
 * conferência daqui: o app e o sistema têm de ler igual.
 */
class RelatorioImobiliarioUrbanoTest extends TestCase
{
    private const CABECALHO = ['Código', 'Situação', 'Inscrição', 'Nome', 'Área m²', 'Edificada', 'Logradouro (cadastro)',
        'Número (cadastro)', 'Bairro (cadastro)', 'Complemento (cadastro)', 'Quadra (cadastro)', 'Lote (cadastro)'];

    private const A = ['1', 'Ativo', '010010080019000', 'FULANO DE TAL', '600', '321.89999999999998', 'CUIABA', '156', 'CIDADE PRIMAVERA I', '', '008', '0019'];

    private const B = ['2', 'Inativo', '011050350001000', '', '16885,27', '', 'RUA DAS ACÁCIAS', '', 'JARDIM EUROPA IV', 'Casa B', '', ''];

    /** Os códigos de conferência que o app desktop também tem de calcular. */
    public const HASH_A = '79b47cb5c57134164ad69a7123750d8c48a44cf9';

    public const HASH_B = '36a62bbbfee9c6fe8ce23b20b512c36d11774119';

    private function leitor(array $linha): callable
    {
        $pos = ColunasDaExportacao::cabecalho(self::CABECALHO);

        return fn (string $col) => isset($pos[$col]) ? trim($linha[$pos[$col]] ?? '') : '';
    }

    public function test_le_os_outros_nomes_de_coluna(): void
    {
        $r = ColunasDaExportacao::linha($this->leitor(self::A));

        $this->assertSame('1', $r['codigo_cadastro']);
        $this->assertSame('008', $r['quadra']);
        $this->assertSame('0019', $r['lote']);
        $this->assertSame('CIDADE PRIMAVERA I', $r['nome_bairro']);
        $this->assertSame('Ativo', $r['isencao']);
        $this->assertSame('600.00', $r['area_terreno_m2']);
        $this->assertSame('321.90', $r['area_edificada_m2']);
        $this->assertSame('CUIABA', $r['logradouro']);
        $this->assertSame('156', $r['numero_predial']);
    }

    public function test_bairro_quadra_e_lote_saem_da_inscricao_quando_a_planilha_nao_os_traz(): void
    {
        $a = ColunasDaExportacao::linha($this->leitor(self::A));
        $b = ColunasDaExportacao::linha($this->leitor(self::B));

        $this->assertSame('001', $a['codigo_bairro']);
        $this->assertSame(['105', '035', '0001'], [$b['codigo_bairro'], $b['quadra'], $b['lote']]);
        $this->assertSame('16885.27', $b['area_terreno_m2']);
    }

    public function test_proprietario_vem_da_coluna_nome_e_o_cpf_nao_e_obrigatorio(): void
    {
        $this->assertSame(
            ['nome' => 'FULANO DE TAL', 'documento' => null, 'endereco' => null],
            ColunasDaExportacao::proprietario($this->leitor(self::A))
        );
        $this->assertNull(ColunasDaExportacao::proprietario($this->leitor(self::B)));
    }

    public function test_codigo_de_conferencia_igual_ao_do_app_desktop(): void
    {
        $a = ColunasDaExportacao::linha($this->leitor(self::A));
        $b = ColunasDaExportacao::linha($this->leitor(self::B));

        $this->assertSame(self::HASH_A, DiferencaDoCadastro::hash($a, [ColunasDaExportacao::proprietario($this->leitor(self::A))]));
        $this->assertSame(self::HASH_B, DiferencaDoCadastro::hash($b, []));
    }

    public function test_diz_so_o_que_o_relatorio_de_fato_nao_traz(): void
    {
        $falta = ColunasDaExportacao::faltando(ColunasDaExportacao::cabecalho(self::CABECALHO));

        $this->assertNotContains('Quadra', $falta);
        $this->assertNotContains('Código do Bairro', $falta);
        $this->assertNotContains('Área Terreno', $falta);
        $this->assertNotContains('Logradouro', $falta);
        $this->assertContains('Testada Principal', $falta);
    }
}
