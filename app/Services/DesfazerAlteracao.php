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
        $revisao = $a->tabela === 'lotes_em_revisao';
        if ($a->tabela !== 'lotes' && ! $revisao) {
            throw new RuntimeException('Só alterações de lote podem ser desfeitas por aqui.');
        }
        // PRÉ-CURADORIA: as mesmas recusas que apagam o botão na tela.
        if ($revisao && ($motivo = $this->motivoNaRevisao($a))) {
            throw new RuntimeException($motivo . '.');
        }

        return match ($a->acao) {
            'excluiu'         => $this->desfazerExclusao($a),
            'unificou'        => $this->desfazerUnificacao($a),
            'desmembrou'      => $this->desfazerDesmembramento($a),
            'instituiu frações' => $this->desfazerFracoes($a),
            'corrigiu quadra',
            'renumerou'       => $this->desfazerCampo($a),
            // Só na pré-curadoria (ver motivoNaRevisao).
            'alterou'         => $revisao ? $this->desfazerCampo($a) : $this->semReversao($a),
            'editou'          => $revisao ? $this->desfazerEdicao($a) : $this->semReversao($a),
            'desenhou',
            'criou'           => $revisao ? $this->desfazerDesenho($a) : $this->semReversao($a),
            'inativou'        => $revisao ? $this->desfazerInativacao($a) : $this->semReversao($a),
            default           => $this->semReversao($a),
        };
    }

    private function semReversao(object $a): never
    {
        throw new RuntimeException('A ação "' . $a->acao . '" não tem reversão automática.');
    }

    /** As ações que a pré-curadoria sabe desfazer. */
    public const REVERSIVEIS_NA_REVISAO = ['renumerou', 'corrigiu quadra', 'alterou', 'editou', 'desenhou', 'criou',
        'inativou', 'excluiu', 'unificou', 'desmembrou', 'instituiu frações'];

    /**
     * Por que um ajuste da PRÉ-CURADORIA (auditoria `lotes_em_revisao`) não
     * pode ser desfeito agora — ou null se pode. A tela usa para mostrar ou não
     * o botão; `executar` refaz o teste antes de mexer.
     *
     *   operação     a linha "inativou"/"criou" que faz parte de unificação,
     *                desmembramento ou fração ideal se desfaz pela linha da
     *                operação, e não sozinha;
     *   a mais nova  contorno, desenho e inativação só voltam se forem o último
     *                ajuste do lote — senão apagariam o que veio depois;
     *   sem desenho  contorno editado e lote apagado antes de o sistema guardar
     *                o desenho anterior (22/10/2026) não têm de onde voltar.
     */
    public function motivoNaRevisao(object $a): ?string
    {
        if (! in_array($a->acao, self::REVERSIVEIS_NA_REVISAO, true)) {
            return 'Este ajuste não tem reversão automática';
        }
        if ($a->acao === 'excluiu') {
            $g = DB::table('lotes_apagados')->where('lote_id', $a->registro_id)->orderByDesc('id')->first();
            if (! $g) {
                return 'Apagado antes de o sistema guardar o desenho: não há de onde restaurar';
            }

            return $g->restaurado_em ? 'Já restaurado (lote ' . $g->restaurado_como . ')' : null;
        }

        $lote = Lote::find($a->registro_id);
        if (! $lote) {
            return 'O lote deste ajuste não existe mais';
        }

        // Parte de uma operação: o papel do lote no ato diz qual.
        $papeis = DB::table('lote_ato_lotes as al')->join('lote_atos as at', 'at.id', '=', 'al.ato_id')
            ->where('al.lote_id', $lote->id)->get(['al.papel', 'at.tipo'])
            ->map(fn ($p) => $p->tipo . ':' . $p->papel)->all();
        $nomes = ['unificacao' => 'da unificação', 'desmembramento' => 'do desmembramento', 'fracao_ideal' => 'das frações'];
        $linha = ['unificacao' => 'Unificou', 'desmembramento' => 'Desmembrou', 'fracao_ideal' => 'Instituiu frações'];
        $parteDe = function (array $quais) use ($papeis, $nomes, $linha): ?string {
            foreach ($quais as $q) {
                if (in_array($q, $papeis, true)) {
                    $tipo = explode(':', $q)[0];
                    return "Faz parte {$nomes[$tipo]}: desfaça pela linha \"{$linha[$tipo]}\"";
                }
            }

            return null;
        };
        if ($a->acao === 'inativou' && ($m = $parteDe(['unificacao:anterior', 'desmembramento:anterior']))) {
            return $m;
        }
        if ($a->acao === 'criou' && ($m = $parteDe(['desmembramento:posterior', 'fracao_ideal:posterior', 'unificacao:posterior']))) {
            return $m;
        }
        $novos = json_decode($a->dados_novos ?? 'null', true) ?: [];
        if ($a->acao === 'alterou' && array_key_exists('condominio', $novos) && ($m = $parteDe(['fracao_ideal:terreno']))) {
            return $m;
        }

        // Contorno, desenho e inativação: só o ajuste mais recente do lote.
        if (in_array($a->acao, ['editou', 'desenhou', 'criou', 'inativou'], true)
            && DB::table('auditoria')->where('registro_id', $lote->id)->whereIn('tabela', ['lotes', 'lotes_em_revisao'])
                ->where('id', '>', $a->id)->exists()) {
            return 'O lote foi ajustado de novo depois: desfaça primeiro o ajuste mais recente dele';
        }

        return match ($a->acao) {
            'editou'   => isset((json_decode($a->dados_anteriores ?? 'null', true) ?: [])['contorno']) ? null
                : 'Editado antes de o sistema guardar o contorno anterior: não há de onde voltar',
            'inativou' => $lote->situacao === 'inativo' ? null : 'O lote já está ativo',
            'desenhou', 'criou' => ($p = $this->oQuePrende($lote)) ? 'O lote já tem ' . rtrim(substr($p, 3), '.') : null,
            'renumerou', 'corrigiu quadra', 'alterou' => $this->campoMudouDepois($lote, $novos),
            default    => null,
        };
    }

    /** O campo já não tem o valor que o ajuste gravou? Diz qual. */
    private function campoMudouDepois(Lote $lote, array $novos): ?string
    {
        foreach (array_diff(array_keys($novos), ['updated_at', 'created_at', 'chave', 'chave_identidade']) as $c) {
            if ((string) $lote->{$c} !== (string) ($novos[$c] ?? null)) {
                return 'Alterado de novo depois (' . $c . ' agora é "' . ($lote->{$c} ?? '—') . '")';
            }
        }

        return null;
    }

    /**
     * O contorno de antes volta (com quadra, número e área de antes). Só na
     * pré-curadoria, e só quando a edição guardou o contorno anterior.
     */
    private function desfazerEdicao(object $a): string
    {
        $antes = json_decode($a->dados_anteriores ?? 'null', true) ?: [];
        $lote = Lote::findOrFail($a->registro_id);

        return DB::transaction(function () use ($antes, $lote) {
            $volta = [
                'quadra'      => $antes['quadra'] ?? $lote->quadra,
                'numero_lote' => $antes['numero_lote'] ?? $lote->numero_lote,
                'area_gis_m2' => $antes['area_gis_m2'] ?? $lote->area_gis_m2,
            ];
            $volta['chave'] = $lote->bairro . '|' . ($volta['quadra'] ?? '?') . '|' . ($volta['numero_lote'] ?? '?');
            $de = $lote->only(['quadra', 'numero_lote', 'chave', 'area_gis_m2']);
            DB::update('UPDATE lotes SET quadra = ?, numero_lote = ?, chave = ?, area_gis_m2 = ?,
                               geom = ST_GeomFromGeoJSON(?, 1, 4326), updated_at = ? WHERE id = ?',
                [$volta['quadra'], $volta['numero_lote'], $volta['chave'], $volta['area_gis_m2'],
                 $antes['contorno'], now(), $lote->id]);
            Lote::findOrFail($lote->id)->registrarAuditoria('editou', $de, $volta + ['contorno' => 'desfeito: voltou o anterior']);

            return 'Edição desfeita: o contorno de antes voltou (' . number_format((float) $volta['area_gis_m2'], 2, ',', '.') . ' m²).';
        });
    }

    /** O lote desenhado (ou criado à parte) sai — com o desenho guardado, para poder voltar. */
    private function desfazerDesenho(object $a): string
    {
        $lote = Lote::findOrFail($a->registro_id);

        return DB::transaction(function () use ($lote) {
            $this->apagados->guardar($lote, 'Desenho desfeito na pré-curadoria');
            $rotulo = $lote->rotulo();
            $lote->delete();

            return "Desenho desfeito: {$rotulo} saiu da importação.";
        });
    }

    /** O lote inativado à parte volta a ativo. */
    private function desfazerInativacao(object $a): string
    {
        $lote = Lote::findOrFail($a->registro_id);
        $lote->update(['situacao' => 'ativo', 'inativado_em' => null]);

        return 'Inativação desfeita: ' . $lote->rotulo() . ' voltou a ativo.';
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

        // Na pré-curadoria o lote volta PARA A IMPORTAÇÃO, ainda em revisão —
        // e não publicado, antes do resto do bairro.
        $extras = $a->tabela === 'lotes_em_revisao' && $a->importacao_id
            ? ['importacao_id' => (int) $a->importacao_id, 'em_revisao' => 1] : [];
        $lote = $this->apagados->restaurar($g->id, $extras);

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

        $nomes = ['numero_lote' => 'o número do lote', 'quadra' => 'a quadra', 'condominio' => 'o condomínio'];

        return sprintf('Desfeito: %s voltou para "%s".',
            implode(', ', array_map(fn ($c) => $nomes[$c] ?? $c, $campos)), $volta[array_key_first($volta)] ?? '—');
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


    /**
     * Desfaz a fração ideal: as unidades saem, e o terreno volta a ser um
     * lote comum. Mesmas regras do desmembramento — tudo ou nada, e não pelo
     * histórico quando veio de protocolo.
     */
    private function desfazerFracoes(object $a): string
    {
        $terreno = Lote::find($a->registro_id);
        if (! $terreno) {
            throw new RuntimeException('O terreno das frações não existe mais.');
        }

        return $this->desfazerFracoesDoTerreno($terreno);
    }

    /**
     * O mesmo desfazer, chamado pelo TERRENO — o botão da aba Unidades da
     * ficha. Serve também ao lote de importação em revisão, cujo ato fica no
     * registro da importação e não aparece no Histórico do cadastro.
     */
    public function desfazerFracoesDoTerreno(Lote $terreno): string
    {

        $ato = DB::table('lote_atos as at')
            ->join('lote_ato_lotes as al', 'al.ato_id', '=', 'at.id')
            ->where('al.lote_id', $terreno->id)->where('al.papel', 'terreno')
            ->where('at.tipo', 'fracao_ideal')
            ->orderByDesc('at.id')
            ->first(['at.id', 'at.protocolo_id']);

        if (! $ato) {
            throw new RuntimeException('Não encontrei o ato de fração ideal deste terreno.');
        }
        if ($ato->protocolo_id) {
            throw new RuntimeException(
                'Estas frações vieram de um protocolo deferido. Desfazê-las por aqui '
                . 'deixaria o protocolo dizendo que foi executado. O caminho é pelo protocolo.'
            );
        }

        $ids = DB::table('lote_ato_lotes')->where('ato_id', $ato->id)
            ->where('papel', 'posterior')->pluck('lote_id');

        $presos = [];
        foreach ($ids as $id) {
            $u = Lote::find($id);
            if ($u && ($preso = $this->oQuePrende($u))) {
                $presos[] = 'unidade ' . $u->numero_lote . ' (' . rtrim($preso, '.') . ')';
            }
        }
        if ($presos) {
            throw new RuntimeException(
                'Não dá para desfazer: ' . implode('; ', $presos)
                . '. Desfazer apagaria essa(s) unidade(s), e o processo ficaria sem imóvel.'
            );
        }

        return DB::transaction(function () use ($terreno, $ato, $ids) {
            DB::table('lote_ato_lotes')->where('ato_id', $ato->id)->delete();
            DB::table('lote_atos')->where('id', $ato->id)->delete();

            $n = 0;
            foreach ($ids as $id) {
                $u = Lote::find($id);
                if (! $u) {
                    continue;
                }
                $this->apagados->guardar($u, 'Fração ideal desfeita pelo histórico');
                $u->delete();
                $n++;
            }

            $terreno->update(['condominio' => null]);

            return sprintf('Frações desfeitas: %d unidade(s) saíram, e %s voltou a ser um lote comum.',
                $n, $terreno->rotulo());
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
