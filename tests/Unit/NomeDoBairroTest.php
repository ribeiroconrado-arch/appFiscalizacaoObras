<?php

namespace Tests\Unit;

use App\Cadastro\NomeDoBairro;
use PHPUnit\Framework\TestCase;

/** O nome do bairro encurtado só onde não cabe — a mesma regra de public/js/nome-do-bairro.js. */
class NomeDoBairroTest extends TestCase
{
    public function test_nome_que_cabe_fica_como_esta(): void
    {
        $this->assertSame('JARDIM EUROPA IV', NomeDoBairro::curto('JARDIM EUROPA IV'));
        $this->assertSame('PARQUE CASTELANDIA III', NomeDoBairro::curto('PARQUE CASTELANDIA III'));
        $this->assertSame('', NomeDoBairro::curto(null));
    }

    public function test_abrevia_a_primeira_palavra_e_so_depois_as_do_meio(): void
    {
        $this->assertSame('LOT. JARDIM PARAISO', NomeDoBairro::curto('LOTEAMENTO JARDIM PARAISO'));
        $this->assertSame('LOT. JD. DOS IPES III', NomeDoBairro::curto('LOTEAMENTO JARDIM DOS IPES III'));
        $this->assertSame('COND. RESID. VILLA PADOVA', NomeDoBairro::curto('CONDOMINIO RESIDENCIAL VILLA PADOVA'));
    }

    public function test_acento_parentese_e_palavras_compostas(): void
    {
        $this->assertSame('COND. RESID. PORTO SEGURO', NomeDoBairro::curto('CONDOMÍNIO RESIDENCIAL PORTO SEGURO'));
        $this->assertSame('CHÁC. NOVA ESPERANCA', NomeDoBairro::curto('CHACARAS NOVA ESPERANCA'));
        $this->assertSame('JOSE DE ALENCAR G. DA SILVA (DIST. IND. II)', NomeDoBairro::curto('JOSE DE ALENCAR G. DA SILVA (DISTRITO INDUSTRIAL II)'));
    }

    public function test_ampliacao_e_expansao_so_no_meio_ou_no_fim(): void
    {
        $this->assertSame('LOT. JD. LUCIANA 1ª AMPL.', NomeDoBairro::curto('LOTEAMENTO JARDIM LUCIANA 1ª AMPLIAÇÃO'));
        $this->assertSame('EXPANSÃO NORTE DO BAIRRO DE TESTE', NomeDoBairro::curto('EXPANSÃO NORTE DO BAIRRO DE TESTE'));
    }

    public function test_nome_proprio_e_vila_nao_se_abreviam(): void
    {
        $this->assertSame('COND. CIDADE JARDIM', NomeDoBairro::curto('CONDOMINIO CIDADE JARDIM', 10));
        $this->assertSame('VILA POPULAR', NomeDoBairro::curto('VILA POPULAR', 5));
    }

    public function test_segue_a_caixa_do_nome(): void
    {
        $this->assertSame('Cond. Resid. Villa Padova', NomeDoBairro::curto('Condomínio Residencial Villa Padova'));
    }
}
