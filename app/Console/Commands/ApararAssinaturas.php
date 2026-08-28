<?php

namespace App\Console\Commands;

use App\Models\Documento;
use App\Models\OrdemServico;
use App\Models\User;
use App\Services\Assinatura;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apara as assinaturas que já estavam gravadas antes do corte existir.
 *
 * Assinatura nova já entra aparada (PerfilController). Isto é para o passado:
 * os perfis e as peças assinadas até aqui carregam a margem vazia do canvas, e
 * é ela que faz a rubrica sair minúscula no papel.
 *
 * Roda com `--simular` primeiro: aparar é irreversível, e uma assinatura é um
 * dado que ninguém redesenha por prazer.
 */
class ApararAssinaturas extends Command
{
    protected $signature = 'assinaturas:aparar {--simular : só mostra o que faria}';

    protected $description = 'Corta a margem vazia das assinaturas já gravadas';

    public function handle(Assinatura $assinatura): int
    {
        $simular = $this->option('simular');
        $total = 0;

        // Perfis, peças lavradas e ordens de serviço. As três guardam CÓPIAS
        // do traço, e é de propósito — cada uma tem de continuar mostrando a
        // assinatura do dia em que foi assinada. Por isso as três são aparadas
        // separadamente: reaproveitar a do perfil reescreveria o passado.
        $alvos = [
            ['users',           'assinatura',          'perfis'],
            ['documentos',      'assinatura_agente',   'documentos (agente)'],
            ['documentos',      'assinatura_autuado',  'documentos (autuado)'],
            ['ordens_servico',  'assinatura_emitente', 'ordens de serviço'],
            ['os_fiscais',      'assinatura',          'ciência de fiscais'],
        ];

        foreach ($alvos as [$tabela, $coluna, $rotulo]) {
            $linhas = DB::table($tabela)
                ->whereNotNull($coluna)
                ->where($coluna, '!=', '')
                ->get(['id', $coluna]);

            $mexidas = 0;
            $bytesAntes = 0;
            $bytesDepois = 0;

            foreach ($linhas as $linha) {
                $antes = $linha->{$coluna};
                $depois = $assinatura->aparar($antes);
                if ($depois === $antes) { continue; }

                $mexidas++;
                $bytesAntes += strlen($antes);
                $bytesDepois += strlen($depois);

                if (! $simular) {
                    DB::table($tabela)->where('id', $linha->id)->update([$coluna => $depois]);
                }
            }

            $total += $mexidas;
            $this->line(sprintf(
                '  %-24s %d de %d aparada(s)%s',
                $rotulo, $mexidas, $linhas->count(),
                $mexidas ? sprintf('  —  %s → %s', $this->kb($bytesAntes), $this->kb($bytesDepois)) : ''
            ));
        }

        $this->newLine();
        $this->info($simular
            ? "Simulação: {$total} assinatura(s) seriam aparadas. Rode sem --simular para valer."
            : "{$total} assinatura(s) aparadas.");

        return self::SUCCESS;
    }

    private function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
}
