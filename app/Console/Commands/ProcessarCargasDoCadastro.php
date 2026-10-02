<?php

namespace App\Console\Commands;

use App\Jobs\ProcessarCargaDoCadastro;
use App\Models\CadastroCarga;
use Illuminate\Console\Command;

/**
 * Rede de segurança da carga mensal do cadastro.
 *
 * A carga roda sozinha logo depois do envio (ver ProcessarCargaDoCadastro).
 * Este comando retoma o que ficou para trás — carga na fila, ou presa em
 * "processando" porque o processo morreu — e apaga arquivos vencidos. Pode
 * rodar à mão ou num cron (docs/seguranca-servidor.md).
 */
class ProcessarCargasDoCadastro extends Command
{
    protected $signature = 'cadastro:processar-cargas';

    protected $description = 'Retoma cargas do cadastro paradas e apaga planilhas vencidas';

    public function handle(): int
    {
        $pendentes = CadastroCarga::query()
            ->where('status', 'na_fila')
            ->orWhere(fn ($q) => $q->where('status', 'processando')->where('updated_at', '<', now()->subMinutes(30)))
            ->orderBy('id')
            ->get();

        foreach ($pendentes as $carga) {
            $this->line("Carga {$carga->id} ({$carga->arquivo_nome})…");
            ProcessarCargaDoCadastro::dispatchSync($carga->id);
            $carga->refresh();
            $this->line("  {$carga->status}" . ($carga->mensagem ? " — {$carga->mensagem}" : ''));
        }

        $apagados = CadastroCarga::limparArquivosVencidos();
        $this->info(sprintf('%d carga(s) retomada(s), %d arquivo(s) apagado(s).', $pendentes->count(), $apagados));

        return self::SUCCESS;
    }
}
