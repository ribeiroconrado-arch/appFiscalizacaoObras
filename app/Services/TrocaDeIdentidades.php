<?php

namespace App\Services;

use App\Cadastro\InscricaoQueAcompanha;
use App\Models\Lote;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Troca a IDENTIDADE entre as partes de um desmembramento já feito.
 *
 * Caso que motiva: as partes têm a mesma área, então a área não diz qual é
 * qual. O 07A (esquerda) e o 07B (direita) foram desenhados invertidos, e o
 * cadastro sabe qual inscrição é de qual lado. Corrigir é trocar a identidade
 * — número do lote e sufixo, juntos — entre os lotes; o desenho, a área e a
 * ficha de cada um ficam onde estão, e nada é excluído nem recadastrado.
 *
 * Como funciona:
 *   - As identidades do ato (número + sufixo) são as que as partes têm hoje,
 *     ordenadas pelo sufixo. A lista de `ordem` diz qual parte fica com cada
 *     uma: a 1ª da lista recebe a 1ª identidade, e assim por diante. Uma troca
 *     de posições é, portanto, uma permutação — com 2 ou com N partes.
 *   - Não cria inscrição nova: o conjunto de identidades é o mesmo, só muda
 *     de dono. Por isso não há duplicidade, e a troca é possível numa transação.
 *   - Recusa quando a inscrição de alguma parte nova já é de outro lote ativo
 *     (InscricaoQueAcompanha::impedimento), ou quando o número colide.
 *
 * Cada lote recebe um registro de troca (antes e depois) com o motivo, e o
 * ato é o mesmo. Desfazer = aplicar a ordem anterior, que é a mesma operação.
 *
 * Só a curadoria chega aqui (CadastroLoteController::recusarSemCuradoria
 * vale para as rotas, e o controlador de identidades repete a checagem).
 */
final class TrocaDeIdentidades
{
    /** Ação gravada na trilha: a troca de identidade, distinta de 'editou'. */
    public const ACAO = 'trocou identidade';

    /** Motivo mínimo, em caracteres, para a troca ser gravada. */
    public const MIN_MOTIVO = 10;

    /**
     * A permutação pura: a parte `ordem[i]` recebe a `i`-ésima identidade
     * (ordenadas pelo sufixo). Sem banco, para o teste conferir só a conta.
     *
     * @param  list<array{id:int, numero:string, sufixo:int}>  $partes  identidades atuais
     * @param  list<int>  $ordem  ids das partes, na ordem nova
     * @return array<int, array{numero:string, sufixo:int}>  id => identidade nova
     */
    public static function permutar(array $partes, array $ordem): array
    {
        $identidades = array_values($partes);
        usort($identidades, fn ($a, $b) => $a['sufixo'] <=> $b['sufixo']);

        $nova = [];
        foreach (array_values($ordem) as $i => $id) {
            $nova[(int) $id] = ['numero' => $identidades[$i]['numero'], 'sufixo' => $identidades[$i]['sufixo']];
        }

        return $nova;
    }

    /** O ato de desmembramento em que este lote é parte ativa/posterior, ou null. */
    public function atoDoLote(Lote $lote): ?object
    {
        return DB::table('lote_ato_lotes as al')
            ->join('lote_atos as at', 'at.id', '=', 'al.ato_id')
            ->where('al.lote_id', $lote->id)->where('al.papel', 'posterior')
            ->where('at.tipo', 'desmembramento')
            ->orderByDesc('at.id')
            ->first(['at.id', 'at.protocolo_id', 'at.created_at', 'at.observacao']);
    }

    /**
     * Estado do ato para a tela: as partes na ordem atual e se dá para trocar.
     *
     * @return array<string,mixed>|null  null se o lote não é parte de um desmembramento
     */
    public function consultar(Lote $lote): ?array
    {
        $ato = $this->atoDoLote($lote);
        if (! $ato) {
            return null;
        }

        $partes = $this->partes((int) $ato->id, false);
        $bloqueio = $this->bloqueio($partes);

        return [
            'ato' => [
                'id'        => (int) $ato->id,
                'direto'    => $ato->protocolo_id === null,
                'data'      => $ato->created_at ? date('d/m/Y', strtotime($ato->created_at)) : null,
                'protocolo' => $ato->protocolo_id ? DB::table('protocolos')->where('id', $ato->protocolo_id)->value('numero') : null,
            ],
            'partes'   => $partes->map(fn (Lote $p) => $this->resumoDaParte($p))->values()->all(),
            'bloqueio' => $bloqueio,
        ];
    }

    /**
     * A prévia da troca, sem gravar: o que cada parte passa a ser, e o erro se
     * houver. Usada pela tela antes da confirmação.
     *
     * @param  list<int>  $ordem
     * @return array{erro: ?string, trocas: list<array<string,mixed>>}
     */
    public function previa(Lote $lote, array $ordem, string $motivo): array
    {
        $ato = $this->atoDoLote($lote);
        if (! $ato) {
            return ['erro' => 'Este lote não é parte de um desmembramento.', 'trocas' => []];
        }

        $plano = $this->planejar((int) $ato->id, $ordem, $motivo, false);

        return [
            'erro'   => $plano['erro'],
            'trocas' => $plano['erro'] ? [] : array_values(array_map(function (Lote $p) use ($plano) {
                $nova = $plano['novas'][$p->id];

                return [
                    'id'              => $p->id,
                    'de'              => $this->rotulo($p->numero_lote, $p->desmembramento, $p->inscricao()),
                    'para'            => $this->rotulo($nova['numero'], $nova['sufixo'], $nova['inscricao']),
                    'vinculos'        => $this->vinculos($p),
                ];
            }, $plano['partes']->all())),
        ];
    }

    /**
     * Grava a troca. Tudo ou nada.
     *
     * @param  list<int>  $ordem
     * @return list<int>  ids dos lotes que trocaram de identidade
     */
    public function aplicar(Lote $lote, array $ordem, string $motivo): array
    {
        return DB::transaction(function () use ($lote, $ordem, $motivo) {
            $ato = $this->atoDoLote($lote);
            if (! $ato) {
                throw ValidationException::withMessages(['lote' => 'Este lote não é parte de um desmembramento.']);
            }

            $plano = $this->planejar((int) $ato->id, $ordem, $motivo, true);
            if ($plano['erro']) {
                throw ValidationException::withMessages(['ordem' => $plano['erro']]);
            }

            $partes = $plano['partes'];
            $ids = $partes->pluck('id')->all();

            // Fase 1: tira as partes do índice de identidade (só conta quem está
            // ativo). Sem isso, cada uma tentaria assumir a identidade que a
            // outra ainda tem, e o índice único recusaria no meio da troca.
            DB::table('lotes')->whereIn('id', $ids)->update(['situacao' => 'inativo', 'updated_at' => now()]);

            $insc = app(InscricaoQueAcompanha::class);
            $antes = [];
            foreach ($partes as $p) {
                $antes[$p->id] = $this->snapshot($p);
                $nova = $plano['novas'][$p->id];

                // Fase 2: cada parte volta ativa com a identidade nova. Como a
                // troca é uma permutação, nenhum valor fica duplicado.
                $campos = $insc->campos($p, $nova['inscricao']);
                DB::table('lotes')->where('id', $p->id)->update($campos + [
                    'numero_lote'    => $nova['numero'],
                    'desmembramento' => $nova['sufixo'],
                    'chave'          => $p->bairro . '|' . $p->quadra . '|' . $nova['numero'],
                    'situacao'       => 'ativo',
                    'updated_at'     => now(),
                ]);
            }

            foreach ($partes as $p) {
                $nova = $plano['novas'][$p->id];
                $fresco = Lote::findOrFail($p->id);
                $fresco->registrarAuditoria(self::ACAO, $antes[$p->id], [
                    'numero_lote'           => $nova['numero'],
                    'desmembramento'        => $nova['sufixo'],
                    'inscricao_montada'     => $nova['inscricao'],
                    'ato'                   => (int) $ato->id,
                    'motivo'                => trim($motivo),
                ]);
            }

            return $ids;
        });
    }

    // ── internos ─────────────────────────────────────────────────

    /**
     * Confere tudo e monta o plano (quem recebe o quê). Não grava.
     *
     * @param  list<int>  $ordem
     * @return array{erro: ?string, partes: Collection<int,Lote>, novas: array<int, array{numero:string, sufixo:int, inscricao:string}>}
     */
    private function planejar(int $atoId, array $ordem, string $motivo, bool $travar): array
    {
        $vazio = ['partes' => collect(), 'novas' => []];

        if (mb_strlen(trim($motivo)) < self::MIN_MOTIVO) {
            return ['erro' => 'Descreva o motivo da troca (mínimo de ' . self::MIN_MOTIVO . ' caracteres).'] + $vazio;
        }

        $partes = $this->partes($atoId, $travar);
        if ($erro = $this->bloqueio($partes)) {
            return ['erro' => $erro] + $vazio;
        }

        $ids = $partes->pluck('id')->map(fn ($i) => (int) $i)->all();
        $ordem = array_map('intval', array_values($ordem));

        if (count($ordem) !== count(array_unique($ordem)) || count($ordem) !== count($ids)) {
            return ['erro' => 'A ordem precisa listar cada parte do ato exatamente uma vez.'] + $vazio;
        }
        $a = $ordem; sort($a);
        $b = $ids;   sort($b);
        if ($a !== $b) {
            return ['erro' => 'A ordem não confere com as partes ativas do ato. Recarregue a tela.'] + $vazio;
        }

        // Ordem atual, pelo sufixo: se a nova é a mesma, não há o que trocar.
        if ($ordem === $ids) {
            return ['erro' => 'Nada a trocar: a ordem já é esta.'] + $vazio;
        }

        $prefixo = $this->prefixoDaFamilia($partes);
        if ($prefixo === null) {
            return ['erro' => 'As partes não têm a mesma inscrição de origem. Corrija o cadastro antes.'] + $vazio;
        }

        $identidades = $partes->map(fn (Lote $p) => [
            'id' => (int) $p->id, 'numero' => (string) $p->numero_lote, 'sufixo' => (int) $p->desmembramento,
        ])->all();
        $novas = [];
        foreach (self::permutar($identidades, $ordem) as $id => $ident) {
            $inscricao = $prefixo . str_pad((string) $ident['sufixo'], 3, '0', STR_PAD_LEFT);
            $novas[$id] = $ident + ['inscricao' => $inscricao];
        }

        // Número e inscrição nova não podem ser de lote ativo fora do ato.
        $base = $partes->first();
        foreach ($novas as $id => $n) {
            $outro = DB::table('lotes')->where('bairro', $base->bairro)->where('quadra', $base->quadra)
                ->where('numero_lote', $n['numero'])->where('situacao', 'ativo')
                ->whereNotIn('id', $ids)->exists();
            if ($outro) {
                return ['erro' => "A quadra {$base->quadra} já tem o lote {$n['numero']} fora deste ato."] + $vazio;
            }
            if ($erro = app(InscricaoQueAcompanha::class)->impedimento($n['inscricao'], $ids)) {
                return ['erro' => $erro] + $vazio;
            }
        }

        // Devolve as partes na ordem nova, para a prévia listar do jeito que o usuário vê.
        $porId = $partes->keyBy('id');
        $ordenadas = collect($ordem)->map(fn ($id) => $porId[$id])->values();

        return ['erro' => null, 'partes' => $ordenadas, 'novas' => $novas];
    }

    /**
     * Partes ativas do ato, ordenadas pelo sufixo (a ordem atual).
     *
     * @return Collection<int,Lote>
     */
    private function partes(int $atoId, bool $travar): Collection
    {
        $ids = DB::table('lote_ato_lotes')->where('ato_id', $atoId)->where('papel', 'posterior')->pluck('lote_id');
        $q = Lote::whereIn('id', $ids)->orderBy('desmembramento')->orderBy('id');

        return ($travar ? $q->lockForUpdate() : $q)->get();
    }

    /** Todas as partes são da mesma quadra (e do mesmo bairro). */
    private function bairroOuQuadraUnica(Collection $partes): bool
    {
        return $partes->pluck('bairro')->unique()->count() === 1
            && $partes->pluck('quadra')->unique()->count() === 1;
    }

    /** O motivo que impede a troca com estas partes, ou null. */
    private function bloqueio(Collection $partes): ?string
    {
        if ($partes->count() < 2) {
            return 'O ato tem menos de duas partes ativas: não há o que trocar.';
        }
        if ($partes->contains(fn (Lote $p) => $p->situacao !== 'ativo')) {
            return 'Uma das partes já não está ativa. Recarregue a tela.';
        }
        if (! $this->bairroOuQuadraUnica($partes)) {
            return 'As partes não são da mesma quadra.';
        }

        return null;
    }

    /** O prefixo de 12 dígitos da família, se todas as partes têm o mesmo; senão null. */
    private function prefixoDaFamilia(Collection $partes): ?string
    {
        $prefixos = [];
        foreach ($partes as $p) {
            $digitos = InscricaoImobiliaria::normalizar($p->inscricao());
            if ($digitos === null) {
                return null;
            }
            $prefixos[] = substr($digitos, 0, 12);
        }
        $unicos = array_values(array_unique($prefixos));

        return count($unicos) === 1 ? $unicos[0] : null;
    }

    /** @return array<string,mixed> */
    private function snapshot(Lote $p): array
    {
        return [
            'numero_lote'           => $p->numero_lote,
            'desmembramento'        => $p->desmembramento,
            'chave'                 => $p->chave,
            'inscricao_montada'     => $p->inscricao_montada,
            'inscricao_imobiliaria' => $p->inscricao_imobiliaria,
        ];
    }

    /** @return array<string,mixed> */
    private function resumoDaParte(Lote $p): array
    {
        $digitos = InscricaoImobiliaria::normalizar($p->inscricao());

        return [
            'id'             => $p->id,
            'numero_lote'    => $p->numero_lote,
            'desmembramento' => (int) $p->desmembramento,
            'rotulo'         => $this->rotulo($p->numero_lote, $p->desmembramento, $digitos),
            'inscricao'      => $digitos ? InscricaoImobiliaria::formatar($digitos) : null,
            'area'           => round((float) $p->area_gis_m2, 2),
            'vinculos'       => $this->vinculos($p),
        ];
    }

    /** @return array{documentos:int, vistorias:int, obras:int} */
    private function vinculos(Lote $p): array
    {
        return [
            'documentos' => DB::table('documentos')->where('lote_id', $p->id)->count(),
            'vistorias'  => DB::table('vistorias')->where('lote_id', $p->id)->count(),
            'obras'      => DB::table('obras')->where('lote_id', $p->id)->count(),
        ];
    }

    private function rotulo(?string $numero, ?int $sufixo, ?string $inscricao): string
    {
        $ins = $inscricao ? InscricaoImobiliaria::formatar($inscricao) : '—';

        return 'Lote ' . ($numero ?? '—') . ' · ' . $ins;
    }
}
