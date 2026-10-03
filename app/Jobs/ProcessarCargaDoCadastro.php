<?php

namespace App\Jobs;

use App\Cadastro\CargaDoCadastro;
use App\Models\CadastroCarga;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Processa uma carga do cadastro municipal.
 *
 * Disparado com `dispatchAfterResponse()`: quem enviou a planilha recebe a
 * resposta na hora e o MESMO processo do PHP segue trabalhando — 56 mil linhas
 * não cabem numa requisição, e assim não é preciso worker de fila nem cron no
 * servidor. De propósito NÃO implementa ShouldQueue: sem worker rodando, um
 * job enfileirado ficaria parado para sempre na tabela `jobs`.
 *
 * Se o processo morrer no meio, a carga fica em `processando`;
 * `php artisan cadastro:processar-cargas` e o "Tentar de novo" da tela a
 * retomam. Retomar é seguro: o que já foi gravado tem hash novo e passa a
 * contar como igual.
 */
class ProcessarCargaDoCadastro
{
    use Dispatchable;

    public function __construct(public int $cargaId, public bool $confirmarAusencias = false)
    {
    }

    public function handle(CargaDoCadastro $servico): void
    {
        $carga = CadastroCarga::find($this->cargaId);
        if (! $carga || $carga->status === 'concluida') {
            return;
        }

        // Uma carga por vez: duas planilhas comparando contra o mesmo banco
        // ao mesmo tempo contariam as mudanças uma da outra.
        $trava = Cache::lock('cadastro-carga', 3600);
        if (! $trava->get()) {
            $carga->update(['status' => 'na_fila', 'mensagem' => 'Aguardando outra carga terminar.']);

            return;
        }

        try {
            $disco = Storage::disk('private');
            if (! $disco->exists($carga->caminhoDoArquivo())) {
                $carga->update(['status' => 'falhou',
                    'mensagem' => 'O arquivo desta carga não está mais no servidor. Envie o arquivo de novo.']);

                return;
            }

            $servico->processar($carga, $disco->path($carga->caminhoDoArquivo()), $this->confirmarAusencias);

            // Concluída: a planilha traz CPF de milhares de pessoas e os dados já
            // estão no banco. Não há motivo para guardá-la.
            if ($carga->status === 'concluida') {
                $disco->delete($carga->caminhoDoArquivo());
            }
        } catch (Throwable $e) {
            report($e);
            $carga->update(['status' => 'falhou', 'mensagem' => mb_substr($e->getMessage(), 0, 500)]);
        } finally {
            $trava->release();
        }
    }
}
