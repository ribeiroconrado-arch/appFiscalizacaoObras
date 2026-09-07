<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * NINGUÉM VÊ O CADASTRO SEM ENTRAR.
 *
 * Este arquivo substitui o `ExampleTest` que veio no scaffold do Laravel. Ele
 * pedia `/` e exigia 200 — o que nunca foi verdade aqui: a rota do mapa nasceu
 * dentro do grupo `middleware('auth')`, e visitante anônimo é mandado para o
 * login. O teste falhava desde o primeiro commit, e falhava por estar certo o
 * sistema, não o teste.
 *
 * Trocar por "não testa nada" seria perder a vaga. O que ele guarda agora é a
 * regra que de fato importa: o sistema está publicado na internet, e a base é
 * o cadastro imobiliário do município. Se um dia alguém tirar a rota do mapa
 * de dentro do grupo `auth` — ou afrouxar o middleware para "resolver" um
 * problema de sessão —, isto acusa antes de ir para o ar.
 */
class AcessoAnonimoTest extends TestCase
{
    public function test_visitante_anonimo_nao_alcanca_o_mapa(): void
    {
        $this->get('/')->assertRedirect('/entrar');
    }

    public function test_visitante_anonimo_nao_alcanca_os_lotes(): void
    {
        // A tela é conveniência; a regra real está no servidor. Vale para a API
        // tanto quanto para a página — é dela que sai a geometria do cadastro.
        //
        // 401, e não redirecionamento para o login: a resposta é consumida por
        // `fetch`, e mandar HTML de login para dentro de um XHR faria a tela
        // falhar com erro de parse em vez de dizer "sua sessão caiu".
        $this->get('/api/mapa/lotes?bbox=-54.4,-15.6,-54.2,-15.5')->assertUnauthorized();
    }

    public function test_a_porta_de_entrada_continua_aberta(): void
    {
        // O contraponto: se ISTO quebrar, ninguém entra no sistema.
        $this->get('/entrar')->assertOk();
    }
}
