<?php

namespace App\Console\Commands;

use App\Services\QuadraDoQuarteirao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Versão de terminal do preenchimento de quadra por quarteirão.
 *
 * A regra mora em QuadraDoQuarteirao, que a tela também usa — as duas portas
 * aplicam as mesmas provas. Esta existe para o trabalho em lote, logo depois
 * de importar um loteamento novo, quando ainda não vale a pena abrir o mapa
 * lote a lote.
 */
class QuadraPorSemente extends Command
{
    protected $signature = 'lotes:quadra
                            {--bairro= : bairro a trabalhar}
                            {--listar : mostra os quarteirões sem quadra e sai}
                            {--bloco= : número do quarteirão, conforme o --listar}
                            {--lote-id= : alternativa ao --bloco: id de qualquer lote dele}
                            {--quadra= : a quadra a gravar no quarteirão}
                            {--aplicar : grava; sem esta opção só simula}';

    protected $description = 'Preenche a quadra de um quarteirão inteiro a partir de um lote';

    public function handle(QuadraDoQuarteirao $svc): int
    {
        $bairro = $this->option('bairro');
        if (! $bairro) {
            $this->error('Informe --bairro. Ex.: --bairro="Residencial Buritis V"');
            return self::FAILURE;
        }

        $blocos = $svc->quarteiroes($bairro);
        if (! $blocos) {
            $this->info("Nenhum lote sem quadra em {$bairro}. Nada a fazer.");
            return self::SUCCESS;
        }

        if ($this->option('listar') || (! $this->option('bloco') && ! $this->option('lote-id'))) {
            $this->listar($bairro, $blocos, $svc);
            return self::SUCCESS;
        }

        $escolhido = $this->escolherBloco($blocos, $bairro);
        if ($escolhido === null) {
            return self::FAILURE;
        }

        $quadra = trim((string) $this->option('quadra'));
        if ($quadra === '') {
            $this->error('Informe --quadra com o número da quadra deste quarteirão.');
            return self::FAILURE;
        }
        $quadra = $svc->normalizar($quadra);

        $ids = $blocos[$escolhido];

        if ($impedimento = $svc->impedimento($bairro, $ids, $quadra)) {
            $this->error('ABORTADO: ' . $impedimento);
            return self::FAILURE;
        }

        $numeros = DB::table('lotes')->whereIn('id', $ids)
            ->orderByRaw('CAST(numero_lote AS UNSIGNED)')->pluck('numero_lote')->filter();

        $this->info(sprintf('Quarteirão %d de %s: %d lote(s) -> quadra %s',
            $escolhido, $bairro, count($ids), $quadra));
        $this->line('  lotes: ' . ($numeros->isEmpty() ? '(sem número)' : $numeros->implode(', ')));

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->comment('Simulação. Rode de novo com --aplicar para gravar.');
            return self::SUCCESS;
        }

        $n = $svc->aplicar($bairro, $ids, $quadra);
        $this->info("Gravado: {$n} lote(s).");

        $restam = DB::table('lotes')->where('bairro', $bairro)->whereNull('quadra')->count();
        $this->line("  Ainda sem quadra em {$bairro}: {$restam} lote(s).");

        return self::SUCCESS;
    }

    /** @param array<int, list<int>> $blocos */
    private function escolherBloco(array $blocos, string $bairro): ?int
    {
        if ($this->option('lote-id')) {
            $id = (int) $this->option('lote-id');
            foreach ($blocos as $n => $ids) {
                if (in_array($id, $ids, true)) {
                    return $n;
                }
            }
            $this->error("O lote {$id} não está entre os lotes sem quadra de {$bairro}.");
            $this->line('  Rode com --listar para ver os quarteirões.');
            return null;
        }

        $n = (int) $this->option('bloco');
        if (! isset($blocos[$n])) {
            $this->error("Quarteirão {$n} não existe. Rode com --listar.");
            return null;
        }

        return $n;
    }

    /** @param array<int, list<int>> $blocos */
    private function listar(string $bairro, array $blocos, QuadraDoQuarteirao $svc): void
    {
        $this->info("Quarteirões sem quadra em {$bairro}:");
        $this->newLine();

        $linhas = [];
        foreach ($blocos as $n => $ids) {
            $r = $svc->retrato($bairro, $ids);
            $linhas[] = [
                $n,
                $r['lotes'],
                $ids[0],
                $r['numeracao'] ?? '—',
                $r['vizinhas'] ? implode(', ', $r['vizinhas']) : '—',
                $r['coerente'] ? 'sim' : 'NÃO — quadras coladas',
            ];
        }

        $this->table(
            ['quarteirão', 'lotes', 'id de um lote', 'numeração', 'quadras vizinhas', 'uma quadra só?'],
            $linhas
        );

        if ($sug = $svc->sugestao($bairro)) {
            $this->line("Falta a quadra {$sug} na sequência do bairro — provável candidata.");
        }
        $this->line('Para gravar a quadra de um quarteirão inteiro:');
        $this->line(sprintf('  php artisan lotes:quadra --bairro="%s" --bloco=1 --quadra=50 --aplicar', $bairro));
        $this->line('  (sem --aplicar ele apenas simula)');
    }
}
