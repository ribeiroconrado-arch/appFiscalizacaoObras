<?php

namespace App\Cadastro;

use App\Models\Lote;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;

/**
 * A inscrição acompanha a troca de QUADRA ou de NÚMERO do lote.
 *
 * A inscrição é setor + bairro + quadra + lote + variação. Quando a quadra ou
 * o número mudam, ela muda junto — também a informada à mão
 * (`inscricao_imobiliaria`), que tem precedência na leitura e por isso ficava
 * para trás: o lote virava o nº 2 e a ficha seguia dizendo ...0001.000.
 *
 * Troca só os pedaços da quadra e do lote; setor, bairro e variação ficam os
 * que o lote já tinha. Sem inscrição nenhuma, monta pelas partes.
 *
 * E confere ANTES de gravar: inscrição que outro lote ativo já usa é recusada
 * (dois imóveis com a mesma identificação no cadastro imobiliário).
 *
 * Usado por: número do lote na revisão (PreCuradoriaDeLotes), Editar lote
 * (EdicaoDeLote), Corrigir quadra (QuadraDeLotesSelecionados), quadra pelo
 * quarteirão (QuadraDoQuarteirao) e o Desfazer da edição.
 */
final class InscricaoQueAcompanha
{
    public function __construct(private ?BairrosDoDesenho $bairros = null)
    {
        $this->bairros ??= new BairrosDoDesenho();
    }

    /**
     * A inscrição (15 dígitos) que o lote passa a ter com esta quadra e este número, ou null.
     *
     * @param  Lote|object  $lote  modelo ou linha crua (bairro, quadra, numero_lote, desmembramento, inscricao_*)
     */
    public function nova(object $lote, ?string $quadra, ?string $numero): ?string
    {
        $q = InscricaoImobiliaria::numeroDaQuadra($quadra);
        $n = preg_replace('/\D/', '', (string) $numero);
        $atual = InscricaoImobiliaria::normalizar($this->bairros->inscricaoDe($lote));

        if ($atual !== null && $q !== null && $q !== '' && $n !== '' && strlen((string) (int) $q) <= 3 && strlen((string) (int) $n) <= 4) {
            return substr($atual, 0, 5)
                . str_pad((string) (int) $q, 3, '0', STR_PAD_LEFT)
                . str_pad((string) (int) $n, 4, '0', STR_PAD_LEFT)
                . substr($atual, 12, 3);
        }

        return $this->bairros->montadaAgora((object) [
            'bairro' => $lote->bairro, 'quadra' => $quadra, 'numero_lote' => $numero,
            'desmembramento' => $lote->desmembramento ?? 0,
        ]);
    }

    /**
     * Outro lote ativo que já responde por esta inscrição — pela informada ou,
     * sem ela, pela montada gravada —, ou null.
     *
     * @param  list<int>  $exceto  os lotes que estão mudando
     */
    public function emUso(?string $inscricao, array $exceto): ?object
    {
        if ($inscricao === null) {
            return null;
        }

        return DB::table('lotes')->where('situacao', 'ativo')->whereNotIn('id', $exceto ?: [0])
            ->where(fn ($q) => $q->whereRaw("REPLACE(inscricao_imobiliaria, '.', '') = ?", [$inscricao])
                ->orWhere(fn ($s) => $s->where(fn ($v) => $v->whereNull('inscricao_imobiliaria')->orWhere('inscricao_imobiliaria', ''))
                    ->where('inscricao_montada', $inscricao)))
            ->first(['id', 'bairro', 'quadra', 'numero_lote']);
    }

    /** A mensagem de recusa para a inscrição já usada, ou null. */
    public function impedimento(?string $inscricao, array $exceto): ?string
    {
        $outro = $this->emUso($inscricao, $exceto);

        return $outro
            ? 'Já existe a inscrição ' . InscricaoImobiliaria::formatar($inscricao)
                . " (quadra {$outro->quadra}, lote {$outro->numero_lote} de {$outro->bairro}). Nada foi gravado."
            : null;
    }

    /**
     * A recusa para levar VÁRIOS lotes a uma quadra (Corrigir quadra, quadra
     * pelo quarteirão): a primeira inscrição nova que outro lote já usa, ou
     * que se repete dentro da própria seleção.
     *
     * @param  iterable<Lote|object>  $lotes
     */
    public function impedimentoNaQuadra(iterable $lotes, string $quadra): ?string
    {
        $ids = [];
        $novas = [];
        foreach ($lotes as $l) {
            $ids[] = (int) $l->id;
            if ((string) $l->quadra === $quadra) {
                continue;
            }
            $nova = $this->nova($l, $quadra, $l->numero_lote);
            if ($nova === null) {
                continue;
            }
            if (isset($novas[$nova])) {
                return 'Dois lotes da seleção ficariam com a mesma inscrição '
                    . InscricaoImobiliaria::formatar($nova) . '. Nada foi gravado.';
            }
            $novas[$nova] = true;
        }
        foreach (array_keys($novas) as $nova) {
            if ($erro = $this->impedimento((string) $nova, $ids)) {
                return $erro;
            }
        }

        return null;
    }

    /**
     * As colunas a gravar junto com a quadra/número novos. A informada só é
     * trocada se o lote tinha uma, e na mesma grafia (com ou sem pontos).
     *
     * @return array<string,mixed>
     */
    public function campos(Lote $lote, ?string $nova): array
    {
        if ($nova === null) {
            return [];
        }
        $c = ['inscricao_montada' => $nova, 'inscricao_montada_em' => now()];
        $informada = (string) $lote->inscricao_imobiliaria;
        if (InscricaoImobiliaria::normalizar($informada) !== null) {
            $c['inscricao_imobiliaria'] = str_contains($informada, '.') ? InscricaoImobiliaria::formatar($nova) : $nova;
        }

        return $c;
    }
}
