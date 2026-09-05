<?php

namespace App\Services;

use App\Models\Documento;
use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Vistoria;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Desfaz uma alteração do cadastro — como ATO NOVO, nunca apagando o anterior.
 *
 * A linha original permanece na trilha. A reversão grava a sua própria linha,
 * com o nome de quem reverteu. Quem ler daqui a dois anos vê as duas: o que foi
 * feito e o que foi corrigido. Num processo administrativo, apagar o erro é pior
 * que o erro — o que se espera é que a correção apareça, não que o engano
 * desapareça.
 *
 * ── As recusas não são conservadorismo ──
 *
 * Desfazer uma unificação APAGA o lote resultante. Se alguém já lavrou um auto
 * contra ele, esse auto perde o imóvel — e auto sem imóvel identificado não
 * sustenta sanção. É a mesma regra que já impede apagar lote com história, e
 * ela precisa NOMEAR o que prende, senão a pessoa fica adivinhando.
 */
class DesfazerAlteracao
{
    public function __construct(private LotesApagados $apagados)
    {
    }

    /**
     * @param  object  $a  a linha de `auditoria`
     * @return string  o que aconteceu, para o toast
     */
    public function executar(object $a, int $usuarioId, string $usuarioNome): string
    {
        if ($a->tabela !== 'lotes') {
            throw new RuntimeException('Só alterações de lote podem ser desfeitas por aqui.');
        }

        return match ($a->acao) {
            'excluiu'         => $this->desfazerExclusao($a),
            'unificou'        => $this->desfazerUnificacao($a),
            'desmembrou'      => $this->desfazerDesmembramento($a),
            'corrigiu quadra',
            'renumerou'       => $this->desfazerCampo($a),
            default           => throw new RuntimeException(
                'A ação "' . $a->acao . '" não tem reversão automática.'
            ),
        };
    }

    /** O lote volta ao desenho, com id novo. Ver LotesApagados::restaurar. */
    private function desfazerExclusao(object $a): string
    {
        $g = DB::table('lotes_apagados')->where('lote_id', $a->registro_id)
            ->whereNull('restaurado_em')->first();

        if (! $g) {
            throw new RuntimeException(
                'Não há desenho guardado desta exclusão. Ela aconteceu antes de o '
                . 'sistema passar a guardar o polígono, e não há de onde restaurar.'
            );
        }

        $lote = $this->apagados->restaurar($g->id);

        return sprintf('Lote restaurado como %s (id %d). O registro da exclusão continua na trilha.',
            $lote->rotulo(), $lote->id);
    }

    /**
     * Escreve de volta o valor anterior de um campo simples.
     *
     * Recusa quando o lote foi alterado DEPOIS: a volta escreveria por cima de
     * uma decisão mais recente, e quem a tomou não saberia.
     */
    private function desfazerCampo(object $a): string
    {
        $lote = Lote::find($a->registro_id);
        if (! $lote) {
            throw new RuntimeException('O lote desta alteração não existe mais.');
        }

        $antes = json_decode($a->dados_anteriores ?? 'null', true) ?: [];
        $novos = json_decode($a->dados_novos ?? 'null', true) ?: [];

        $campos = array_diff(array_keys($novos), ['updated_at', 'created_at', 'chave', 'chave_identidade']);
        if (! $campos) {
            throw new RuntimeException('Esta linha não registra mudança de campo nenhum.');
        }

        foreach ($campos as $c) {
            $atual = $lote->{$c};
            $depois = $novos[$c] ?? null;
            // Comparação frouxa de propósito: quadra é string no banco e pode
            // ter voltado como número no JSON.
            if ((string) $atual !== (string) $depois) {
                throw new RuntimeException(sprintf(
                    'O campo "%s" já vale "%s" — foi alterado de novo depois desta linha. '
                    . 'Desfazer agora apagaria a decisão mais recente.',
                    $c, $atual ?? '—'
                ));
            }
        }

        $volta = [];
        foreach ($campos as $c) {
            $volta[$c] = $antes[$c] ?? null;
        }

        // A chave é derivada dos três campos; recompor à mão deixaria o lote
        // achável pelo valor velho e invisível pelo novo.
        $lote->fill($volta);
        $lote->chave = $lote->bairro . '|' . ($lote->quadra ?? '?') . '|' . ($lote->numero_lote ?? '?');
        $lote->save();

        return sprintf('Desfeito: %s voltou para "%s".',
            implode(', ', $campos), $volta[array_key_first($volta)] ?? '—');
    }

    /**
     * O resultante é apagado, os originais voltam a ativos, e o ato sai de
     * `lote_atos`.
     *
     * O resultante é apagado por LotesApagados — com o desenho guardado —, e não
     * por um `delete()` seco: desfazer um desfazer tem de ser possível, e um
     * polígono de união não se remonta à mão.
     */
    private function desfazerUnificacao(object $a): string
    {
        $novo = Lote::find($a->registro_id);
        if (! $novo) {
            throw new RuntimeException('O lote resultante da unificação não existe mais.');
        }

        if ($preso = $this->oQuePrende($novo)) {
            throw new RuntimeException(
                'Não dá para desfazer: o lote resultante já tem história própria — '
                . $preso . ' Desfazer o apagaria, e o processo ficaria sem imóvel.'
            );
        }

        $ato = DB::table('lote_atos as at')
            ->join('lote_ato_lotes as al', 'al.ato_id', '=', 'at.id')
            ->where('al.lote_id', $novo->id)->where('al.papel', 'posterior')
            ->where('at.tipo', 'unificacao')
            ->orderByDesc('at.id')
            ->first(['at.id', 'at.protocolo_id']);

        if (! $ato) {
            throw new RuntimeException('Não encontrei o ato de unificação deste lote.');
        }
        if ($ato->protocolo_id) {
            throw new RuntimeException(
                'Esta unificação veio de um protocolo deferido. Desfazê-la por aqui '
                . 'deixaria o protocolo dizendo que foi executado. O caminho é pelo protocolo.'
            );
        }

        $anteriores = DB::table('lote_ato_lotes')->where('ato_id', $ato->id)
            ->where('papel', 'anterior')->pluck('lote_id');

        return DB::transaction(function () use ($novo, $ato, $anteriores) {
            foreach ($anteriores as $id) {
                $lote = Lote::find($id);
                if ($lote) {
                    $lote->update(['situacao' => 'ativo', 'inativado_em' => null]);
                }
            }

            // O vínculo sai ANTES do lote: `lote_ato_lotes` é RESTRICT, e a
            // exclusão do resultante esbarraria nele.
            DB::table('lote_ato_lotes')->where('ato_id', $ato->id)->delete();
            DB::table('lote_atos')->where('id', $ato->id)->delete();

            $this->apagados->guardar($novo, 'Unificação desfeita pela trilha');
            $novo->delete();

            return sprintf('Unificação desfeita: %d lote(s) voltaram a ativos e o resultante saiu do desenho.',
                $anteriores->count());
        });
    }

    /**
     * As partes são apagadas e o pai volta a ativo.
     *
     * Espelho da unificação, ao contrário: lá o resultante some e as origens
     * voltam; aqui as partes somem e a origem volta. As partes saem por
     * LotesApagados — com o desenho guardado —, e não por um `delete()` seco:
     * desfazer um desfazer tem de ser possível, e polígono de corte não se
     * remonta à mão.
     */
    private function desfazerDesmembramento(object $a): string
    {
        $pai = Lote::find($a->registro_id);
        if (! $pai) {
            throw new RuntimeException('O lote de origem do desmembramento não existe mais.');
        }

        $ato = DB::table('lote_atos as at')
            ->join('lote_ato_lotes as al', 'al.ato_id', '=', 'at.id')
            ->where('al.lote_id', $pai->id)->where('al.papel', 'anterior')
            ->where('at.tipo', 'desmembramento')
            ->orderByDesc('at.id')
            ->first(['at.id', 'at.protocolo_id']);

        if (! $ato) {
            throw new RuntimeException('Não encontrei o ato de desmembramento deste lote.');
        }
        if ($ato->protocolo_id) {
            throw new RuntimeException(
                'Este desmembramento veio de um protocolo deferido. Desfazê-lo por aqui '
                . 'deixaria o protocolo dizendo que foi executado. O caminho é pelo protocolo.'
            );
        }

        $ids = DB::table('lote_ato_lotes')->where('ato_id', $ato->id)
            ->where('papel', 'posterior')->pluck('lote_id');

        // TUDO OU NADA, e a recusa NOMEIA o que prende. Apagar duas partes e
        // deixar a terceira porque ela tem um auto produziria um lote pai ativo
        // sobreposto a um filho vivo — dois imóveis sobre o mesmo terreno.
        $presos = [];
        foreach ($ids as $id) {
            $parte = Lote::find($id);
            if (! $parte) {
                continue;
            }
            if ($preso = $this->oQuePrende($parte)) {
                $presos[] = $parte->rotulo() . ' (' . rtrim($preso, '.') . ')';
            }
        }
        if ($presos) {
            throw new RuntimeException(
                'Não dá para desfazer: ' . implode('; ', $presos)
                . '. Desfazer apagaria essa(s) parte(s), e o processo ficaria sem imóvel.'
            );
        }

        return DB::transaction(function () use ($pai, $ato, $ids) {
            // O vínculo sai ANTES dos lotes: `lote_ato_lotes` é RESTRICT, e a
            // exclusão das partes esbarraria nele.
            DB::table('lote_ato_lotes')->where('ato_id', $ato->id)->delete();
            DB::table('lote_atos')->where('id', $ato->id)->delete();

            $n = 0;
            foreach ($ids as $id) {
                $parte = Lote::find($id);
                if (! $parte) {
                    continue;
                }
                $this->apagados->guardar($parte, 'Desmembramento desfeito pelo histórico');
                $parte->delete();
                $n++;
            }

            $pai->update(['situacao' => 'ativo', 'inativado_em' => null]);

            return sprintf('Desmembramento desfeito: %d parte(s) saíram do desenho e %s voltou a ativo.',
                $n, $pai->rotulo());
        });
    }


    /** O mesmo teste da exclusão de lote, com o mesmo texto. */
    private function oQuePrende(Lote $lote): ?string
    {
        $presos = [];
        if ($n = Vistoria::where('lote_id', $lote->id)->count()) { $presos[] = $n . ' vistoria(s)'; }
        if ($n = Documento::where('lote_id', $lote->id)->count()) { $presos[] = $n . ' documento(s)'; }
        if ($n = Protocolo::where('lote_id', $lote->id)->count()) { $presos[] = $n . ' protocolo(s)'; }

        return $presos ? 'há ' . implode(', ', $presos) . ' ligados a ele.' : null;
    }
}
