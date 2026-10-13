<?php

namespace App\Services;

use App\Models\Lote;
use App\Repositories\LoteRepository;
use Illuminate\Support\Facades\DB;

/**
 * Redesenha um lote EXISTENTE — contorno, quadra e número.
 *
 * Vale para o lote em revisão (pré-curadoria) e, desde 13/10/2026, também
 * para o PUBLICADO: é como o curador acerta o desenho que não bate com o
 * cadastro oficial (a pendência de área da conferência). Editar não é ato de
 * sucessão — o imóvel continua o mesmo, com as vistorias e peças que já tem;
 * quando o imóvel MUDA, o caminho segue sendo unificação ou desmembramento.
 *
 * A trilha vai para onde o lote está: registro da importação, se em revisão;
 * Histórico do cadastro, se publicado — e aí leva o contorno de antes, que é
 * o que permite refazê-lo.
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
            // Lote publicado: o contorno de antes fica na trilha.
            if (! $lote->em_revisao) {
                $antes['contorno'] = DB::table('lotes')->where('id', $lote->id)->selectRaw('ST_AsGeoJSON(geom) g')->value('g');
            }
            $depois = [
                'quadra'      => $dados['quadra'],
                'numero_lote' => $dados['numero_lote'],
                'chave'       => $lote->bairro . '|' . $dados['quadra'] . '|' . $dados['numero_lote'],
                'area_gis_m2' => $area,
            ];

            // UPDATE cru: `geom` só se escreve por expressão SQL. O evento do
            // Eloquent não dispara, e a trilha é gravada à mão logo abaixo
            // (Lote::tabelaDaAuditoria decide onde).
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
