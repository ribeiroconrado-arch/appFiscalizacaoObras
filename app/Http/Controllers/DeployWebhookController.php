<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PONTO DE ENTRADA DO GITHUB, e nada mais.
 *
 * Este endpoint não roda `git pull`, não roda `composer`, não toca em
 * `sudo` — ele só CONFERE a assinatura do GitHub e larga um arquivo-gatilho
 * em `storage/app/deploy.trigger`. Quem de fato executa o deploy é um cron
 * do usuário `ubuntu` (fora do PHP-FPM, ver docs/deploy.md), rodando o mesmo
 * `deploy.sh` que já era rodado à mão.
 *
 * A separação existe por confiança: este processo roda como `www-data`, o
 * mesmo usuário que serve toda requisição da internet. Se ele executasse
 * `git pull`/`sudo` diretamente, um defeito aqui — mesmo um não relacionado
 * a deploy — herdaria o poder de reescrever o código da aplicação e reiniciar
 * serviço do sistema. Aqui, o pior que um bug neste controller pode fazer é
 * criar ou apagar um arquivo vazio dentro de `storage/app`.
 *
 * A ÚNICA porta de entrada é a ASSINATURA HMAC-SHA256 que o GitHub calcula
 * sobre o corpo cru da requisição, com o segredo cadastrado nos dois lados
 * (aqui em `config('deploy.webhook_secret')`, lá em Settings > Webhooks).
 * Sem ela batendo, nada acontece — nem em modo de teste.
 */
class DeployWebhookController extends Controller
{
    public function receber(Request $request): Response
    {
        $segredo = config('deploy.webhook_secret');
        if (! $segredo) {
            // Configurado errado é tratado como FECHADO, não como aberto: um
            // webhook sem segredo cadastrado não pode ser "modo permissivo".
            Log::warning('Webhook de deploy chamado sem DEPLOY_WEBHOOK_SECRET configurado.');

            return response('não configurado', 404);
        }

        $corpo = $request->getContent();
        $assinaturaRecebida = (string) $request->header('X-Hub-Signature-256', '');
        $assinaturaEsperada = 'sha256=' . hash_hmac('sha256', $corpo, $segredo);

        // hash_equals, e não ===: comparação de string comum vaza, por
        // temporização, quantos caracteres iniciais bateram — o suficiente
        // para um atacante paciente reconstruir a assinatura byte a byte.
        if (! hash_equals($assinaturaEsperada, $assinaturaRecebida)) {
            Log::warning('Webhook de deploy com assinatura inválida.', [
                'ip' => $request->ip(),
            ]);

            return response('assinatura inválida', 403);
        }

        // Só o evento de push, e só em main. Pull request, criação de branch
        // e os demais eventos que o GitHub pode enviar não devem disparar
        // deploy nenhum — e a filtragem por branch fica aqui porque a
        // interface do GitHub não oferece esse filtro na configuração do
        // webhook em si.
        if ($request->header('X-GitHub-Event') !== 'push') {
            return response('ignorado: não é push', 200);
        }
        if ($request->input('ref') !== 'refs/heads/main') {
            return response('ignorado: não é a branch main', 200);
        }

        Storage::put('deploy.trigger', (string) $request->input('after', now()->toISOString()));

        Log::info('Deploy solicitado via webhook.', [
            'commit' => $request->input('after'),
            'autor'  => $request->input('pusher.name'),
        ]);

        // 202: aceito, ainda não feito. Quem executa é o cron, em até um
        // minuto — este endpoint não espera pelo `git pull` para responder.
        return response('deploy agendado', 202);
    }
}
