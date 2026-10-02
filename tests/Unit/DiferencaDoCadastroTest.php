<?php

namespace Tests\Unit;

use App\Cadastro\ColunasDaExportacao;
use App\Cadastro\DiferencaDoCadastro;
use PHPUnit\Framework\TestCase;

/**
 * A carga mensal grava só o que mudou. Estas regras decidem o que é "mudou":
 * se errarem para mais, todo imóvel parece alterado todo mês; para menos, uma
 * mudança real some do histórico. Regra pura, sem banco.
 */
class DiferencaDoCadastroTest extends TestCase
{
    private function ler(array $celulas): callable
    {
        return fn (string $c) => $celulas[$c] ?? '';
    }

    public function test_linha_da_planilha_vira_registro_canonico(): void
    {
        $r = ColunasDaExportacao::linha($this->ler([
            'Inscrição' => '01.105.001.0001.000', 'Área Terreno' => '1.234,5', 'ANO CONSTRUÇÃO' => '1998',
            'Tipo de Logradouro' => 'Rua', 'Nome do Logradouro' => ' A ', 'ASFALTO' => '-', 'AGUA' => 'SIM',
            'Complemento do Endereço' => '  ',
        ]));

        $this->assertSame('1234.50', $r['area_terreno_m2']);
        $this->assertSame('1998', $r['unidade_ano']);
        $this->assertSame('Rua A', $r['logradouro']);
        $this->assertNull($r['complemento']);
        $this->assertSame('{"AGUA":"SIM"}', $r['caracteristicas']);   // "-" não é valor
    }

    public function test_linha_sem_inscricao_e_ignorada(): void
    {
        $this->assertNull(ColunasDaExportacao::linha($this->ler(['Área Terreno' => '350'])));
    }

    public function test_numero_do_banco_e_da_planilha_sao_o_mesmo_valor(): void
    {
        $banco = ['area_terreno_m2' => '350.00', 'unidade_ano' => 1998, 'caracteristicas' => '{"AGUA": "SIM", "ASFALTO": "SIM"}'];
        $planilha = ['area_terreno_m2' => '350.00', 'unidade_ano' => '1998', 'caracteristicas' => '{"ASFALTO":"SIM","AGUA":"SIM"}'];

        $this->assertSame([], DiferencaDoCadastro::campos($banco, $planilha));
    }

    public function test_mudanca_real_aparece_com_antes_e_depois(): void
    {
        $this->assertSame(
            ['area_terreno_m2' => ['350.00', '400.00'], 'isencao' => [null, 'Imune']],
            DiferencaDoCadastro::campos(
                ['area_terreno_m2' => '350.00', 'isencao' => null, 'hash' => 'x'],
                ['area_terreno_m2' => '400.00', 'isencao' => 'Imune']
            )
        );
    }

    public function test_hash_muda_com_o_dono_e_nao_com_a_ordem_das_colunas(): void
    {
        $a = ['inscricao' => '1', 'area_terreno_m2' => '350.00', 'caracteristicas' => null];
        $b = ['area_terreno_m2' => '350.00', 'caracteristicas' => null, 'inscricao' => '1'];
        $dono = [['nome' => 'Maria', 'documento' => '1', 'endereco' => null]];

        $this->assertSame(DiferencaDoCadastro::hash($a, $dono), DiferencaDoCadastro::hash($b, $dono));
        $this->assertNotSame(DiferencaDoCadastro::hash($a, $dono),
            DiferencaDoCadastro::hash($a, [['nome' => 'João', 'documento' => '1', 'endereco' => null]]));
    }
}
