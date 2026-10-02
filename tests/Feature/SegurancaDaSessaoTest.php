<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Regras de sessão e de resposta que não dependem do banco espacial.
 *
 * O usuário é montado em memória (`make`, sem gravar): o SQLite dos testes não
 * roda as migrações espaciais, e nada aqui precisa de tabela — o que se testa
 * é o middleware, que decide antes de qualquer controller.
 */
class SegurancaDaSessaoTest extends TestCase
{
    private function usuario(bool $ativo): User
    {
        return User::factory()->make([
            'perfil'         => 'comum',
            'tipo_usuario'   => 'agente',
            'ativo'          => $ativo,
            'remember_token' => null,   // sem token, o logout não tenta gravar
        ]);
    }

    public function test_usuario_desativado_perde_a_sessao_na_api(): void
    {
        $this->actingAs($this->usuario(false))
            ->getJson('/api/perfil')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Seu acesso foi desativado.']);

        $this->assertGuest();
    }

    public function test_usuario_desativado_volta_para_o_login_na_tela(): void
    {
        $this->actingAs($this->usuario(false))
            ->get('/')
            ->assertRedirect('/entrar');

        $this->assertGuest();
    }

    public function test_cabecalhos_de_seguranca_em_toda_resposta(): void
    {
        $this->get('/entrar')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'geolocation=(self), camera=(self), microphone=()');
    }
}
