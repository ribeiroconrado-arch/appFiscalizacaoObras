<?php

namespace App\Services;

use App\Models\Lote;
use App\Repositories\LoteRepository;
use Illuminate\Support\Facades\DB;

/**
 * MOVER LOTES — os lotes marcados deslocados no mapa, como o MOVER do AutoCAD
 * (ponto de base, destino ou medida digitada; mover-lotes.js). Só desloca: a
 * forma e a área de cada lote não mudam (01/11/2026).
 *
 * As provas são as do Editar lote (EdicaoDeLote), com duas diferenças:
 *   - os lotes que andam juntos não acusam o desenho ANTIGO uns dos outros;
 *   - invadir um lote que NÃO andou é AVISO, não recusa. É o passo do meio de
 *     um acerto: a fileira desliza 0,10 m e encosta no lote da esquina, que
 *     o curador acerta em seguida com Editar lote. Recusar travaria o acerto.
 *
 * Grava tudo ou nada.
 */
class MoverLotes
{
    public function __construct(
        private EdicaoDeLote $edicao,
        private DesenhoDeLote $desenho,
        private LoteRepository $lotes,
    ) {}

    /** @param list<array{id:int, geometry:array}> $itens @return array{0: array<int,Lote>, 1: ?string} */
    private function carregar(array $itens): array
    {
        $ids = array_map(fn ($i) => (int) $i['id'], $itens);
        if (count($ids) !== count(array_unique($ids))) {
            return [[], 'O mesmo lote veio duas vezes.'];
        }
        $lotes = Lote::whereIn('id', $ids)->get()->keyBy('id')->all();
        if (count($lotes) !== count($ids)) {
            return [[], 'Algum dos lotes não existe mais. Recarregue o mapa e tente de novo.'];
        }

        return [$lotes, null];
    }

    /** @return array{quadra:string, numero_lote:string, geometry:array} */
    private function dados(Lote $l, array $i): array
    {
        return ['quadra' => $l->quadra, 'numero_lote' => $l->numero_lote, 'geometry' => $i['geometry']];
    }

    /** @param list<array{id:int, geometry:array}> $itens */
    public function impedimento(array $itens): ?string
    {
        if (! $itens) {
            return 'Nenhum lote para mover.';
        }
        [$lotes, $erro] = $this->carregar($itens);
        if ($erro) {
            return $erro;
        }
        $ids = array_keys($lotes);
        foreach ($itens as $i) {
            $l = $lotes[(int) $i['id']];
            if ($m = $this->edicao->impedimento($l, $this->dados($l, $i), array_values(array_diff($ids, [$l->id])), false)) {
                return "Q{$l->quadra} Lt{$l->numero_lote}: {$m}";
            }
        }

        return null;
    }

    /**
     * Os lotes que NÃO andaram e ficariam invadidos, um aviso por par.
     *
     * @param  list<array{id:int, geometry:array}>  $itens
     * @return list<string>
     */
    public function avisos(array $itens): array
    {
        [$lotes] = $this->carregar($itens);
        $ids = array_keys($lotes);
        $tol = (float) config('gis.sobreposicao_tolerada_m2', 0.5);
        $avisos = [];
        foreach ($itens as $i) {
            $l = $lotes[(int) $i['id']] ?? null;
            if (! $l) {
                continue;
            }
            $d = $this->dados($l, $i) + ['bairro' => $l->bairro];
            foreach ($this->desenho->retrato($d, $ids)['vizinhos'] as $v) {
                if ($v['area_comum'] > $tol) {
                    $avisos[] = sprintf('Q%s Lt%s fica sobre Q%s Lt%s em %s m².', $l->quadra, $l->numero_lote,
                        $v['quadra'], $v['lote'], number_format($v['area_comum'], 2, ',', '.'));
                }
            }
        }

        return $avisos;
    }

    /**
     * Grava todos, numa transação só. Cada lote leva a própria linha na trilha
     * (EdicaoDeLote::aplicar), com o contorno de antes.
     *
     * @param  list<array{id:int, geometry:array}>  $itens
     * @return list<Lote>
     */
    public function aplicar(array $itens): array
    {
        [$lotes] = $this->carregar($itens);

        return DB::transaction(fn () => array_map(function ($i) use ($lotes) {
            $l = $lotes[(int) $i['id']];

            return $this->edicao->aplicar($l, $this->dados($l, $i));
        }, $itens));
    }
}
