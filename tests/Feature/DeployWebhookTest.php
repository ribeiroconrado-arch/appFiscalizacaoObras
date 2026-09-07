<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A ÚNICA PORTA DESTE ENDPOINT É A ASSINATURA.
 *
 * Ele é público de propósito — o GitHub não tem sessão nem CSRF desta
 * aplicação — e é exatamente por isso que cada caminho de recusa importa:
 * é a única coisa entre a internet e um arquivo que dispara `git pull` no
 * servidor de produção. Ver DeployWebhookController.
 *
 * NÃO usa `Storage::fake()`: foi exatamente esse fake que deixou passar o
 * bug de produção. `Storage::fake('local')` troca o disco inteiro por um
 * temporário isolado — ele confere a LÓGICA ("grava ou não grava"), mas
 * nunca toca no caminho real, e foi ali que o Laravel 11 mudou a raiz do
 * disco `local` para `storage/app/private` sem o cron (que olha
 * `storage/app/deploy.trigger`, sem `/private`) saber. Os testes com fake
 * passavam, e em produção o gatilho nunca era encontrado. Aqui o arquivo é
 * conferido no caminho ABSOLUTO de verdade, o mesmo que o cron usa.
 */
class DeployWebhookTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste-nao-e-o-de-producao';

    protected function tearDown(): void
    {
        @unlink(storage_path('app/deploy.trigger'));

        parent::tearDown();
    }

    private function assinar(string $corpo): string
    {
        return 'sha256=' . hash_hmac('sha256', $corpo, self::SEGREDO);
    }

    public function test_sem_segredo_configurado_recusa_mesmo_sem_tentar_validar(): void
    {
        config(['deploy.webhook_secret' => null]);

        $this->postJson('/webhooks/deploy', ['ref' => 'refs/heads/main'])
            ->assertNotFound();
    }

    public function test_assinatura_ausente_e_recusada(): void
    {
        config(['deploy.webhook_secret' => self::SEGREDO]);

        $this->postJson('/webhooks/deploy', ['ref' => 'refs/heads/main'])
            ->assertForbidden();
    }

    public function test_assinatura_errada_e_recusada(): void
    {
        config(['deploy.webhook_secret' => self::SEGREDO]);

        $this->post('/webhooks/deploy', ['ref' => 'refs/heads/main'], [
            'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
            'X-GitHub-Event'      => 'push',
        ])->assertForbidden();
    }

    public function test_assinatura_certa_mas_evento_diferente_de_push_e_ignorado(): void
    {
        config(['deploy.webhook_secret' => self::SEGREDO]);

        $corpo = json_encode(['ref' => 'refs/heads/main']);

        $this->call('POST', '/webhooks/deploy', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $this->assinar($corpo),
            'HTTP_X-GitHub-Event'      => 'ping',
            'CONTENT_TYPE'             => 'application/json',
        ], $corpo)->assertOk();

        $this->assertFileDoesNotExist(storage_path('app/deploy.trigger'));
    }

    public function test_assinatura_certa_mas_branch_diferente_de_main_e_ignorado(): void
    {
        config(['deploy.webhook_secret' => self::SEGREDO]);

        $corpo = json_encode(['ref' => 'refs/heads/uma-feature-qualquer']);

        $this->call('POST', '/webhooks/deploy', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $this->assinar($corpo),
            'HTTP_X-GitHub-Event'      => 'push',
            'CONTENT_TYPE'             => 'application/json',
        ], $corpo)->assertOk();

        $this->assertFileDoesNotExist(storage_path('app/deploy.trigger'));
    }

    public function test_push_valido_em_main_grava_o_gatilho_no_caminho_que_o_cron_usa(): void
    {
        config(['deploy.webhook_secret' => self::SEGREDO]);

        $corpo = json_encode(['ref' => 'refs/heads/main', 'after' => 'abc123', 'pusher' => ['name' => 'fulano']]);

        $this->call('POST', '/webhooks/deploy', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $this->assinar($corpo),
            'HTTP_X-GitHub-Event'      => 'push',
            'CONTENT_TYPE'             => 'application/json',
        ], $corpo)->assertStatus(202);

        // storage_path('app/deploy.trigger') — SEM '/private' — é o mesmo
        // caminho literal que a linha de cron em docs/deploy.md verifica.
        $this->assertFileExists(storage_path('app/deploy.trigger'));
        $this->assertSame('abc123', file_get_contents(storage_path('app/deploy.trigger')));
    }
}
