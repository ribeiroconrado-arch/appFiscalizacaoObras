<?php

namespace App\Services;

use App\Models\ImportacaoLote;
use App\Models\Lote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Os casos do levantamento cadastral de 2022 guardados com a IMPORTAÇÃO do
 * bairro (`importacoes_lotes.levantamento`), para a aba "Levantamento" da
 * ficha dela. Quem os produz é gis:desmembramentos-levantamento.
 *
 * O levantamento corrige a CARGA INICIAL: o que ele aplica entra sem trilha
 * de auditoria (RegistraAuditoria::semTrilha) — nada no Histórico do cadastro
 * nem na pré-curadoria. O lote de antes fica INATIVO, com a inscrição e o BCI
 * dele, e a sucessão (lote_atos) liga um ao outro. Desfazer é por aqui, e
 * também sem trilha: volta o lote de antes e some o que o levantamento criou.
 *
 * Situação de um caso: `aplicado`, `nao_aplicado` ou `desfeito`. Rodar o
 * comando de novo nunca mexe num caso aplicado nem num desfeito.
 */
class LevantamentoDaImportacao
{
    /** Situações que uma nova rodada do comando não mexe. Descartado some da aba. */
    public const SITUACOES_FIXAS = ['aplicado', 'desfeito', 'descartado'];

    /**
     * Situação guardada de cada caso, em todas as importações.
     *
     * @return array<string,string>  chave do caso => situação
     */
    public static function situacoes(): array
    {
        $saida = [];
        foreach (ImportacaoLote::whereNotNull('levantamento')->where('status', '<>', 'excluida')->get(['id', 'levantamento']) as $imp) {
            foreach ($imp->levantamento['itens'] ?? [] as $it) {
                $saida[$it['chave']] = $it['situacao'];
            }
        }

        return $saida;
    }

    /**
     * Grava os casos, cada um na importação do bairro dele: a dos lotes do
     * caso ou, sem eles, a mais recente do bairro. Caso aplicado ou desfeito
     * que já está gravado fica como está.
     *
     * @param  list<array<string,mixed>>  $casos
     * @return array{importacoes:int, sem_importacao:int}
     */
    public function gravar(array $casos): array
    {
        $porImportacao = [];
        $sem = 0;
        foreach ($casos as $c) {
            $id = $this->importacaoDoCaso($c);
            if (! $id) { $sem++; continue; }
            $porImportacao[$id][] = $c;
        }

        foreach ($porImportacao as $id => $novos) {
            DB::transaction(function () use ($id, $novos) {
                $imp = ImportacaoLote::whereKey($id)->lockForUpdate()->first();
                $itens = [];
                foreach ($imp->levantamento['itens'] ?? [] as $it) $itens[$it['chave']] = $it;
                foreach ($novos as $c) {
                    if (in_array($itens[$c['chave']]['situacao'] ?? null, self::SITUACOES_FIXAS, true)) continue;
                    $itens[$c['chave']] = $c;
                }
                $imp->update(['levantamento' => ['itens' => array_values($itens), 'em' => now()->format('d/m/Y H:i')]]);
            });
        }

        return ['importacoes' => count($porImportacao), 'sem_importacao' => $sem];
    }

    /** Os casos para a ficha da importação, ou null se ela não tem nenhum. */
    public function daImportacao(ImportacaoLote $imp): ?array
    {
        $lev = $imp->levantamento;
        if ($lev) {
            $lev['itens'] = array_values(array_filter($lev['itens'] ?? [], fn ($it) => $it['situacao'] !== 'descartado'));
        }

        return $lev && $lev['itens'] ? $lev : null;
    }

    /**
     * Descarta um caso NÃO aplicado: ele sai da aba, e o comando não o traz de
     * volta. Nenhum lote muda — o caso só dizia que não havia o que fazer.
     */
    public function descartar(ImportacaoLote $imp, string $chave): string
    {
        return DB::transaction(function () use ($imp, $chave) {
            $imp = ImportacaoLote::whereKey($imp->id)->lockForUpdate()->firstOrFail();
            $itens = $imp->levantamento['itens'] ?? [];
            $k = array_search($chave, array_column($itens, 'chave'), true);
            if ($k === false) {
                throw new RuntimeException('Este caso não é desta importação.');
            }
            if ($itens[$k]['situacao'] !== 'nao_aplicado') {
                throw new RuntimeException('Só se descarta caso não aplicado. O aplicado se desfaz.');
            }
            $itens[$k]['situacao'] = 'descartado';
            $itens[$k]['descartado_em'] = now()->format('d/m/Y H:i');
            $imp->update(['levantamento' => ['itens' => $itens] + $imp->levantamento]);

            return 'Descartado: Q ' . $itens[$k]['quadra'] . ' · L ' . implode(' + ', $itens[$k]['lotes']) . ' saiu da lista.';
        });
    }

    /**
     * Desfaz um caso aplicado, SEM trilha: o lote (ou os lotes) de antes volta
     * a ser ativo, e o que o levantamento criou some com a sucessão dele.
     * Recusa se o lote novo já ganhou alguma coisa que dependa dele.
     */
    public function desfazer(ImportacaoLote $imp, string $chave): string
    {
        return DB::transaction(function () use ($imp, $chave) {
            $imp = ImportacaoLote::whereKey($imp->id)->lockForUpdate()->firstOrFail();
            $itens = $imp->levantamento['itens'] ?? [];
            $k = array_search($chave, array_column($itens, 'chave'), true);
            if ($k === false) {
                throw new RuntimeException('Este caso não é desta importação.');
            }
            $it = $itens[$k];
            if ($it['situacao'] !== 'aplicado') {
                throw new RuntimeException('Só se desfaz caso aplicado.');
            }

            $novos = array_map('intval', array_column($it['novos'] ?? [], 'lote_id'));
            $antes = array_map('intval', $it['lote_ids'] ?? []);
            $ato = (int) ($it['ato_id'] ?? 0);
            if (! $novos || ! $antes || ! $ato) {
                throw new RuntimeException('O caso não guardou os lotes: não há o que desfazer.');
            }

            $lotes = Lote::whereIn('id', array_merge($novos, $antes))->lockForUpdate()->get()->keyBy('id');
            foreach ($novos as $id) {
                $l = $lotes[$id] ?? null;
                if (! $l || $l->situacao !== 'ativo') {
                    throw new RuntimeException('O lote ' . ($l?->numero_lote ?? $id) . ' já mudou depois do levantamento (não está mais ativo). Desfaça pelo Histórico o que veio depois.');
                }
                if ($prende = $this->oQuePrende($id, $ato)) {
                    throw new RuntimeException("O lote {$l->numero_lote} já tem {$prende}: não dá para desfazer.");
                }
            }
            foreach ($antes as $id) {
                $l = $lotes[$id] ?? null;
                if (! $l || $l->situacao !== 'inativo') {
                    throw new RuntimeException('O lote de antes (' . ($l?->numero_lote ?? $id) . ') não está mais inativo.');
                }
                $outro = DB::table('lote_ato_lotes')->where('lote_id', $id)->where('ato_id', '<>', $ato)
                    ->where('papel', 'anterior')->exists();
                if ($outro) {
                    throw new RuntimeException("O lote de antes ({$l->numero_lote}) entrou em outro desmembramento ou unificação.");
                }
            }

            DB::table('lote_ato_lotes')->where('ato_id', $ato)->delete();
            DB::table('lote_atos')->where('id', $ato)->delete();
            if (Schema::hasTable('lotes_apagados')) {
                DB::table('lotes_apagados')->whereIn('lote_id', $novos)->delete();
            }
            DB::table('lotes')->whereIn('id', $novos)->delete();
            DB::table('lotes')->whereIn('id', $antes)->update(['situacao' => 'ativo', 'inativado_em' => null, 'updated_at' => now()]);

            $itens[$k]['situacao'] = 'desfeito';
            $itens[$k]['desfeito_em'] = now()->format('d/m/Y H:i');
            $imp->update(['levantamento' => ['itens' => $itens] + $imp->levantamento]);

            $rotulo = 'Q ' . $it['quadra'] . ' · L ' . implode(' + ', $it['lotes']);

            return "Desfeito: {$rotulo} voltou a ser como na importação.";
        });
    }

    // ── apoio ────────────────────────────────────────────────────

    /** A importação do caso: a dos lotes dele, ou a mais recente do bairro. */
    private function importacaoDoCaso(array $c): ?int
    {
        $ids = array_merge($c['lote_ids'] ?? [], array_column($c['novos'] ?? [], 'lote_id'));
        if ($ids) {
            $id = DB::table('lotes')->whereIn('id', $ids)->whereNotNull('importacao_id')->value('importacao_id');
            if ($id && DB::table('importacoes_lotes')->where('id', $id)->where('status', '<>', 'excluida')->exists()) {
                return (int) $id;
            }
        }
        $id = DB::table('importacoes_lotes')->where('bairro', $c['bairro'])->where('status', '<>', 'excluida')
            ->orderByDesc('id')->value('id');

        return $id ? (int) $id : null;
    }

    /** O que já depende do lote novo, fora a própria sucessão do levantamento. */
    private function oQuePrende(int $id, int $ato): ?string
    {
        $conta = fn (string $t) => Schema::hasTable($t) ? DB::table($t)->where('lote_id', $id)->count() : 0;
        $itens = array_filter([
            'vistoria(s)'          => $conta('vistorias'),
            'documento(s)'         => $conta('documentos'),
            'protocolo(s)'         => $conta('protocolos'),
            'ordem(ns) de serviço' => $conta('ordens_servico'),
            'obra(s)'              => $conta('obras'),
            'outro desmembramento ou unificação' => DB::table('lote_ato_lotes')->where('lote_id', $id)->where('ato_id', '<>', $ato)->count(),
        ]);

        return $itens ? implode(', ', array_map(fn ($k, $n) => "{$n} {$k}", array_keys($itens), $itens)) : null;
    }
}
