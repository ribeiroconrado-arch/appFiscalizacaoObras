<?php

namespace App\Services;

use App\Models\ImportacaoLote;
use App\Models\Lote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Informar o número e excluir lotes — SÓ NA PRÉ-CURADORIA, isto é, em lote de
 * importação que ainda não foi publicada (rascunho ou em revisão).
 *
 * Fora dela as duas coisas têm caminho próprio e mais pesado, de propósito:
 * número de lote publicado muda por unificação ou desmembramento, com
 * sucessão; e lote publicado só se apaga como resíduo, com senha e motivo
 * (CadastroLoteController::excluir). Antes de publicar, o desenho ainda é o
 * DWG sendo revisado — corrigir o número que a conversão errou, ou tirar o
 * polígono que sobrou, é exatamente a revisão.
 *
 * Os dois gravam pelo Eloquent, então a trilha vai sozinha para o registro da
 * importação (Lote::tabelaDaAuditoria devolve 'lotes_em_revisao'), fora do
 * Histórico do cadastro.
 */
class PreCuradoriaDeLotes
{
    /** O número do lote; a quadra fica como está. */
    public function numerar(ImportacaoLote $imp, Lote $lote, string $numero): Lote
    {
        $this->exigirDaImportacao($imp, [$lote->id]);

        $numero = trim($numero);
        if ($numero === '') {
            throw new RuntimeException('Informe o número do lote.');
        }
        if ((string) $lote->numero_lote === $numero) {
            return $lote;
        }

        // A mesma prova do índice único, dita antes dele e em português.
        $repetido = Lote::where('situacao', 'ativo')->where('bairro', $lote->bairro)
            ->where('quadra', $lote->quadra)->where('numero_lote', $numero)
            ->where('id', '<>', $lote->id)->exists();
        if ($repetido && $lote->quadra !== null) {
            throw new RuntimeException("Já existe o lote {$numero} na quadra {$lote->quadra} de {$lote->bairro}.");
        }

        $lote->update([
            'numero_lote' => $numero,
            'chave'       => $lote->bairro . '|' . ($lote->quadra ?? '?') . '|' . $numero,
        ]);

        return $lote;
    }

    /**
     * Apaga lotes da importação. Tudo ou nada: se um deles está preso a
     * alguma coisa, nenhum sai — e a mensagem diz qual e por quê.
     *
     * @param  list<int>  $ids
     * @return int quantos foram apagados
     */
    public function excluir(ImportacaoLote $imp, array $ids): int
    {
        $this->exigirDaImportacao($imp, $ids);

        $presos = [];
        foreach (Lote::whereIn('id', $ids)->get() as $l) {
            if ($p = $this->oQuePrende($l->id)) {
                $presos[] = 'Q' . ($l->quadra ?? '?') . ' L' . ($l->numero_lote ?? '?') . " ({$p})";
            }
        }
        if ($presos) {
            throw new RuntimeException('Nada foi apagado. ' . implode('; ', $presos)
                . '. Lote que faz parte de unificação ou desmembramento sai desfazendo o ato.');
        }

        return DB::transaction(function () use ($ids) {
            $n = 0;
            // Um a um, pelo Eloquent: cada exclusão deixa a sua linha no
            // registro da importação.
            foreach (Lote::whereIn('id', $ids)->lockForUpdate()->get() as $l) {
                $l->delete();
                $n++;
            }

            return $n;
        });
    }

    /** @param list<int> $ids */
    private function exigirDaImportacao(ImportacaoLote $imp, array $ids): void
    {
        if (! $imp->emAndamento()) {
            throw new RuntimeException('Esta importação já foi publicada ou excluída: os lotes dela não se alteram mais por aqui.');
        }
        $deFora = DB::table('lotes')->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('importacao_id')->orWhere('importacao_id', '<>', $imp->id)
                ->orWhere('em_revisao', false)->orWhere('situacao', '<>', 'ativo'))
            ->count();
        if ($deFora > 0 || DB::table('lotes')->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
            throw new RuntimeException('Na pré-curadoria só se alteram lotes ativos desta importação.');
        }
    }

    private function oQuePrende(int $id): ?string
    {
        $conta = fn (string $t) => Schema::hasTable($t) ? DB::table($t)->where('lote_id', $id)->count() : 0;
        $itens = array_filter([
            'vistoria(s)'       => $conta('vistorias'),
            'documento(s)'      => $conta('documentos'),
            'protocolo(s)'      => $conta('protocolos'),
            'ordem(ns) de serviço' => $conta('ordens_servico'),
            'obra(s)'           => $conta('obras'),
            'ficha(s) do BCI'   => $conta('bci_imoveis'),
            'ato(s) de unificação/desmembramento' => $conta('lote_ato_lotes'),
        ]);

        return $itens ? implode(', ', array_map(fn ($k, $n) => "{$n} {$k}", array_keys($itens), $itens)) : null;
    }
}
