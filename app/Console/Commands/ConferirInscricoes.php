<?php

namespace App\Console\Commands;

use App\Support\InscricaoImobiliaria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Confere a fórmula da inscrição contra as inscrições REAIS da prefeitura.
 *
 * A fórmula (ver App\Support\InscricaoImobiliaria) foi dita de boca; este
 * comando a prova contra o dado. Para cada linha da exportação já carregada,
 * monta a inscrição a partir das colunas bairro/quadra/lote DA PRÓPRIA LINHA e
 * compara com a inscrição que veio junto. O que não bate é divergência da
 * exportação — a coluna discorda do número —, e é justamente o que precisa
 * aparecer: o sistema casa desenho com cadastro POR ESSAS COLUNAS, então onde
 * elas mentem o imóvel é casado errado, em silêncio.
 *
 * Só lê. Não corrige nada: quem decide qual dos dois está certo é a prefeitura.
 */
class ConferirInscricoes extends Command
{
    protected $signature = 'inscricao:conferir {--erros : lista todas as divergências}';

    protected $description = 'Confere a fórmula da inscrição imobiliária contra a exportação do cadastro';

    public function handle(): int
    {
        $linhas = DB::table('cadastro_externo_imoveis')
            ->get(['inscricao', 'codigo_bairro', 'quadra', 'lote']);

        if ($linhas->isEmpty()) {
            $this->warn('Nenhuma exportação do cadastro foi carregada — nada a conferir.');
            $this->line('Carregue com `cadastro:carregar` antes.');

            return self::SUCCESS;
        }

        $ok = 0;
        $malFormadas = [];
        $divergentes = [];

        foreach ($linhas as $l) {
            $real = InscricaoImobiliaria::normalizar($l->inscricao);

            if ($real === null) {
                $malFormadas[] = (string) $l->inscricao;
                continue;
            }

            // A variação sai da própria inscrição: ela não está nas colunas, e
            // é o único pedaço que a exportação não repete fora do número.
            $variacao = InscricaoImobiliaria::partes($real)['variacao'];

            $montada = InscricaoImobiliaria::montar(
                $l->codigo_bairro, $l->quadra, $l->lote, $variacao
            );

            if ($montada === $real) {
                $ok++;
            } else {
                $divergentes[] = [
                    InscricaoImobiliaria::formatar($real),
                    InscricaoImobiliaria::formatar($montada) ?? '—',
                    "b={$l->codigo_bairro} q={$l->quadra} lt={$l->lote}",
                ];
            }
        }

        $this->info("Conferidas {$linhas->count()} inscrições da exportação.");
        $this->line("  batem com a fórmula: {$ok}");
        $this->line('  divergem:            ' . count($divergentes));
        $this->line('  mal formadas:        ' . count($malFormadas));

        if ($divergentes) {
            $this->newLine();
            $this->warn('Nestas, a COLUNA discorda do número da própria inscrição.');
            $this->line('O casamento com o desenho usa as colunas — onde elas mentem,');
            $this->line('o imóvel é ligado ao lote errado, sem aviso.');
            $this->newLine();
            $this->table(
                ['inscrição (real)', 'montada das colunas', 'colunas'],
                $this->option('erros') ? $divergentes : array_slice($divergentes, 0, 10)
            );

            if (! $this->option('erros') && count($divergentes) > 10) {
                $this->line('… e mais ' . (count($divergentes) - 10) . '. Use --erros para ver todas.');
            }
        }

        if ($malFormadas) {
            $this->newLine();
            $this->warn('Inscrições que não têm 15 dígitos: ' . implode(', ', array_slice($malFormadas, 0, 10)));
        }

        return self::SUCCESS;
    }
}
