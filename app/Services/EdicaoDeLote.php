<?php

namespace App\Services;

use App\Models\Lote;
use App\Repositories\LoteRepository;
use Illuminate\Support\Facades\DB;

/**
 * Redesenha um lote EXISTENTE — contorno, quadra e número. Só na
 * pré-curadoria, isto é, em lote de importação ainda em revisão.
 *
 * Por que só lá: um lote publicado já é o imóvel que todos consultam, e pode
 * ter vistoria, auto e protocolo pendurados. Mudar o chão dele por baixo
 * desses documentos é o que a unificação, o desmembramento e a correção de
 * quadra fazem com prova e sucessão. Antes de publicar, o desenho ainda é
 * rascunho do DWG, e corrigi-lo livremente é justamente a revisão.
 *
 * As provas são as do Desenhar lote (DesenhoDeLote::impedimento), com o
 * próprio lote fora da conta: ele não invade a si mesmo nem repete o próprio
 * número.
 */
class EdicaoDeLote
{
    public function __construct(
        private DesenhoDeLote $desenho,
        private LoteRepository $lotes,
    ) {}

    /** @param array<string,mixed> $d geometry, quadra, numero_lote */
    public function impedimento(Lote $lote, array $d): ?string
    {
        if (! $lote->em_revisao) {
            return 'Editar lote vale só na pré-curadoria: este lote já está publicado. '
                . 'Use unificação, desmembramento ou correção de quadra.';
        }
        if ($lote->situacao !== 'ativo') {
            return 'Este lote já foi inativado e não pode mais ser editado.';
        }

        return $this->desenho->impedimento($this->dados($lote, $d), $lote->id);
    }

    /** @return array<string,mixed> */
    public function retrato(Lote $lote, array $d): array
    {
        $r = $this->desenho->retrato($this->dados($lote, $d), $lote->id);
        $r['area_anterior_m2'] = (float) $lote->area_gis_m2;

        return $r;
    }

    public function aplicar(Lote $lote, array $d): Lote
    {
        $dados = $this->dados($lote, $d);
        $geojson = json_encode($dados['geometry']);
        $area = round($this->lotes->areaDoGeoJson($geojson), 2);

        return DB::transaction(function () use ($lote, $dados, $geojson, $area) {
            $antes = $lote->only(['quadra', 'numero_lote', 'chave', 'area_gis_m2']);
            $depois = [
                'quadra'      => $dados['quadra'],
                'numero_lote' => $dados['numero_lote'],
                'chave'       => $lote->bairro . '|' . $dados['quadra'] . '|' . $dados['numero_lote'],
                'area_gis_m2' => $area,
            ];

            // UPDATE cru: `geom` só se escreve por expressão SQL. O evento do
            // Eloquent não dispara, e a trilha é gravada à mão logo abaixo —
            // no registro da importação, porque o lote está em revisão.
            DB::update('UPDATE lotes SET quadra = ?, numero_lote = ?, chave = ?, area_gis_m2 = ?,
                               geom = ST_GeomFromGeoJSON(?, 1, 4326), updated_at = ?
                         WHERE id = ?',
                [$depois['quadra'], $depois['numero_lote'], $depois['chave'], $area,
                 $geojson, now(), $lote->id]);

            $novo = Lote::findOrFail($lote->id);
            $novo->registrarAuditoria('editou', $antes, $depois + ['contorno' => 'redesenhado']);

            return $novo;
        });
    }

    /** @return array<string,mixed> */
    private function dados(Lote $lote, array $d): array
    {
        return [
            'bairro'      => $lote->bairro,
            'quadra'      => $d['quadra'],
            'numero_lote' => $d['numero_lote'],
            'geometry'    => $d['geometry'],
        ];
    }
}
