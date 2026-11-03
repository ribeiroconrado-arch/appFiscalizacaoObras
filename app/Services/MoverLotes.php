<?php

namespace App\Services;

use App\Support\GeometriaPlana;
use App\Models\Lote;
use App\Repositories\LoteRepository;
use Illuminate\Support\Facades\DB;

/**
 * MOVER LOTES — vários lotes gravados de uma vez, com o contorno novo de cada
 * um (prancheta, modo "mover"; pedido de 31/10/2026).
 *
 * O caso que o motivou: a fileira em que o lote do meio tem 9,90 de frente e
 * o da esquina 10,10. Movendo os lotes entre os dois 0,10 m, com "lados
 * colados acompanham", o do meio estica e o da esquina encolhe — e mais
 * nenhum lote muda de tamanho. A geometria é feita na prancheta; aqui só se
 * confere e grava.
 *
 * As provas são as do Editar lote (EdicaoDeLote), com uma diferença: os lotes
 * que mudam juntos não acusam o desenho ANTIGO uns dos outros — o lote que
 * estica ocupa o chão de onde o vizinho acabou de sair. Entre eles, a prova
 * é feita com os desenhos NOVOS.
 *
 * Grava tudo ou nada: um lote recusado não deixa a fileira pela metade.
 */
class MoverLotes
{
    public function __construct(
        private EdicaoDeLote $edicao,
        private LoteRepository $lotes,
    ) {}

    /**
     * @param  list<array{id:int, geometry:array}>  $itens
     * @return array{0: array<int,Lote>, 1: ?string}  os lotes e o impedimento
     */
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

        // Cada um, com as provas do Editar lote, ignorando os que mudam junto.
        foreach ($itens as $i) {
            $l = $lotes[(int) $i['id']];
            $d = ['quadra' => $l->quadra, 'numero_lote' => $l->numero_lote, 'geometry' => $i['geometry']];
            if ($m = $this->edicao->impedimento($l, $d, array_values(array_diff($ids, [$l->id])))) {
                return "Q{$l->quadra} Lt{$l->numero_lote}: {$m}";
            }
        }

        // Entre eles, com os desenhos novos.
        $tol = (float) config('gis.sobreposicao_tolerada_m2', 0.5);
        $p0 = $itens[0]['geometry']['coordinates'][0][0];
        $planos = array_map(fn ($i) => GeometriaPlana::projetar($i['geometry']['coordinates'][0], $p0[1], $p0[0]), $itens);
        for ($a = 0; $a < count($itens); $a++) {
            for ($b = $a + 1; $b < count($itens); $b++) {
                $comum = GeometriaPlana::areaComum($planos[$a], $planos[$b])['area'];
                if ($comum > $tol) {
                    $la = $lotes[(int) $itens[$a]['id']];
                    $lb = $lotes[(int) $itens[$b]['id']];

                    return sprintf('Q%s Lt%s e Q%s Lt%s ficariam sobrepostos em %s m². Dois imóveis não ocupam o mesmo chão.',
                        $la->quadra, $la->numero_lote, $lb->quadra, $lb->numero_lote, number_format($comum, 2, ',', '.'));
                }
            }
        }

        return null;
    }

    /**
     * O que muda em cada lote, para a prévia: área antes e depois.
     *
     * @param  list<array{id:int, geometry:array}>  $itens
     * @return list<array{id:int, quadra:string, lote:string, area_antes:float, area_depois:float}>
     */
    public function retrato(array $itens): array
    {
        [$lotes] = $this->carregar($itens);

        return array_map(function ($i) use ($lotes) {
            $l = $lotes[(int) $i['id']] ?? null;

            return [
                'id'          => (int) $i['id'],
                'quadra'      => (string) ($l?->quadra ?? ''),
                'lote'        => (string) ($l?->numero_lote ?? ''),
                'area_antes'  => (float) ($l?->area_gis_m2 ?? 0),
                'area_depois' => round($this->lotes->areaDoGeoJson(json_encode($i['geometry'])), 2),
            ];
        }, $itens);
    }

    /**
     * Grava todos, numa transação só. Cada lote leva a própria linha na trilha
     * (EdicaoDeLote::aplicar), com o contorno de antes — o "Desfazer" de cada
     * um continua valendo.
     *
     * @param  list<array{id:int, geometry:array}>  $itens
     * @return list<Lote>
     */
    public function aplicar(array $itens): array
    {
        [$lotes] = $this->carregar($itens);

        return DB::transaction(fn () => array_map(function ($i) use ($lotes) {
            $l = $lotes[(int) $i['id']];

            return $this->edicao->aplicar($l, ['quadra' => $l->quadra, 'numero_lote' => $l->numero_lote, 'geometry' => $i['geometry']]);
        }, $itens));
    }
}
