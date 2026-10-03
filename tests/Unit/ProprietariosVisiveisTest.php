<?php

namespace Tests\Unit;

use App\Cadastro\ColunasDaExportacao;
use App\Cadastro\ProprietariosVisiveis;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Quem vê o quê do proprietário — e como a linha da planilha vira proprietário.
 * Regra pura: usuário montado em memória, sem banco.
 */
class ProprietariosVisiveisTest extends TestCase
{
    private const DONOS = [
        ['nome' => 'Maria da Silva', 'documento' => '123.456.789-00', 'endereco' => 'Rua A, 10'],
    ];

    private function usuario(string $perfil, string $tipo, bool $ativo = true): User
    {
        return (new User())->forceFill(['perfil' => $perfil, 'tipo_usuario' => $tipo, 'ativo' => $ativo]);
    }

    public function test_agente_ve_tudo(): void
    {
        $this->assertSame(self::DONOS, ProprietariosVisiveis::para($this->usuario('comum', 'agente'), self::DONOS));
    }

    public function test_administrador_ve_tudo(): void
    {
        $this->assertSame(self::DONOS, ProprietariosVisiveis::para($this->usuario('admin', 'agente'), self::DONOS));
    }

    public function test_coordenador_ve_so_o_nome(): void
    {
        $this->assertSame(
            [['nome' => 'Maria da Silva']],
            ProprietariosVisiveis::para($this->usuario('viewer', 'coordenador'), self::DONOS)
        );
    }

    public function test_externo_nao_recebe_o_bloco(): void
    {
        foreach (['topografo', 'arquiteto', 'contribuinte'] as $tipo) {
            $this->assertNull(ProprietariosVisiveis::para($this->usuario('viewer', $tipo), self::DONOS), $tipo);
        }
    }

    public function test_desativado_e_anonimo_nao_veem(): void
    {
        $this->assertNull(ProprietariosVisiveis::para($this->usuario('comum', 'agente', false), self::DONOS));
        $this->assertNull(ProprietariosVisiveis::para(null, self::DONOS));
    }

    public function test_linha_da_planilha_vira_proprietario(): void
    {
        $celulas = ['Proprietário' => ' João Souza ', 'CPF/CNPJ' => '111.222.333-44', 'Endereço de Correspondência' => '-'];
        $ler = fn (string $c) => $celulas[$c] ?? '';

        $this->assertSame(
            ['nome' => 'João Souza', 'documento' => '111.222.333-44', 'endereco' => null],
            ColunasDaExportacao::proprietario($ler)
        );
    }

    public function test_linha_sem_nome_nao_tem_proprietario(): void
    {
        $this->assertNull(ColunasDaExportacao::proprietario(fn (string $c) => $c === 'CPF/CNPJ' ? '1' : ''));
    }
}
