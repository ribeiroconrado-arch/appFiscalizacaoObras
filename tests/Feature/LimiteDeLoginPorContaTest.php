<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * LIMITE DE LOGIN POR CONTA (AuthController::entrar, 23/10/2026): senhas
 * erradas de UMA conta, vindas de IPs diferentes, travam o login dela por 15
 * minutos — mesmo com a senha certa. Conta inexistente trava igual, com a
 * mesma mensagem. Login certo zera a contagem.
 *
 * O SQLite dos testes não roda as migrações (espaciais): a tabela de usuários
 * é montada aqui, só com o que o login usa.
 */
class LimiteDeLoginPorContaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('matricula')->nullable();
            $t->string('password');
            $t->boolean('ativo')->default(true);
            $t->string('perfil')->nullable();
            $t->string('tipo_usuario')->nullable();
            $t->string('remember_token')->nullable();
            $t->timestamp('ultimo_acesso_em')->nullable();
            $t->timestamps();
        });
        DB::table('users')->insert(['name' => 'Fiscal Teste', 'email' => 'fiscal@teste.local', 'matricula' => '9001',
            'password' => Hash::make('senhaCerta123'), 'ativo' => true, 'perfil' => 'comum', 'tipo_usuario' => 'agente']);
    }

    /** Uma tentativa vinda de um IP próprio: os limites por IP não entram na conta. */
    private function tentar(string $quem, string $senha, int $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.' . intdiv($ip, 250) . '.' . ($ip % 250 + 1)])
            ->post('/entrar', ['identificador' => $quem, 'password' => $senha]);
    }

    public function test_dez_senhas_erradas_de_ips_diferentes_travam_a_conta_mesmo_com_a_senha_certa(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->tentar('9001', 'errada' . $i, $i)->assertSessionHasErrors('identificador');
        }
        $r = $this->tentar('9001', 'senhaCerta123', 99);
        $r->assertSessionHasErrors(['identificador' => 'Muitas tentativas para este acesso. Tente de novo em 15 minuto(s).']);
        $this->assertGuest();
    }

    public function test_conta_inexistente_trava_igual_e_com_a_mesma_mensagem(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->tentar('naoexiste@teste.local', 'x' . $i, $i);
        }
        $this->tentar('naoexiste@teste.local', 'x', 99)
            ->assertSessionHasErrors(['identificador' => 'Muitas tentativas para este acesso. Tente de novo em 15 minuto(s).']);
    }

    public function test_login_certo_zera_a_contagem(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->tentar('9001', 'errada' . $i, $i);
        }
        $this->tentar('9001', 'senhaCerta123', 50)->assertRedirect();
        $this->assertAuthenticated();
        $this->post('/sair');

        // Mais 9 erros depois do acerto: a conta ainda não trava (a contagem recomeçou).
        for ($i = 0; $i < 9; $i++) {
            $this->tentar('9001', 'errada' . $i, 100 + $i);
        }
        $this->tentar('9001', 'senhaCerta123', 200)->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_outra_conta_nao_e_afetada(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->tentar('naoexiste@teste.local', 'x' . $i, $i);
        }
        $this->tentar('fiscal@teste.local', 'senhaCerta123', 99)->assertRedirect();
        $this->assertAuthenticated();
    }

    /** Senha nova: mínimo 8, com ao menos uma letra e um número — e o aviso em português. */
    public function test_senha_precisa_de_letra_e_numero_com_aviso_em_portugues(): void
    {
        app()->setLocale('pt_BR');
        $regra = ['senha' => [Password::min(8)->letters()->numbers()]];
        $this->assertSame(['A senha precisa ter ao menos um número.'],
            Validator::make(['senha' => 'somenteletras'], $regra)->errors()->get('senha'));
        $this->assertSame(['A senha precisa ter ao menos uma letra.'],
            Validator::make(['senha' => '1234567890'], $regra)->errors()->get('senha'));
        $this->assertTrue(Validator::make(['senha' => 'fiscal2026'], $regra)->passes());
        $this->assertFalse(Validator::make(['senha' => 'abc12'], $regra)->passes());   // menos de 8
    }
}
