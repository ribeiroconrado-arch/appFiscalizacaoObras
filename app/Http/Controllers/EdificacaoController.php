<?php

namespace App\Http\Controllers;

use App\Models\Edificacao;
use App\Models\Lote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * As edificações desenhadas dentro do lote.
 *
 * O croqui do processo e a área construída da vistoria saem daqui. Só quem
 * pode corrigir a base cadastral desenha — é geometria do cadastro, da mesma
 * família do desenho de lote.
 */
class EdificacaoController extends Controller
{
    private function recusarSemCuradoria(Request $r): ?JsonResponse
    {
        return $r->user()->podeCurarCadastro()
            ? null
            : response()->json([
                'message' => 'Só quem responde pelo cadastro desenha edificação.',
            ], 403);
    }

    /**
     * GET /api/imoveis/{lote}/edificacoes
     *
     * Devolve a geometria como GeoJSON, porque é o que o mapa desenha — e
     * porque o binário do MySQL não atravessa JSON.
     */
    public function listar(Lote $lote): JsonResponse
    {
        $linhas = DB::table('edificacoes')
            ->where('lote_id', $lote->id)
            ->orderBy('id')
            ->get(['id', 'area_m2', 'descricao', DB::raw('ST_AsGeoJSON(geom) AS geojson')]);

        return response()->json([
            'edificacoes' => $linhas->map(fn ($e) => [
                'id'        => $e->id,
                'area_m2'   => (float) $e->area_m2,
                'descricao' => $e->descricao,
                'geometry'  => json_decode($e->geojson),
            ]),
            'area_construida_m2' => round((float) $linhas->sum('area_m2'), 2),
        ]);
    }

    /**
     * POST /api/imoveis/{lote}/edificacoes
     *
     * O polígono TEM de estar dentro do lote. Não é formalidade: uma
     * edificação que atravessa a divisa ou está no lote errado entra na conta
     * da área construída de quem não construiu nada — e essa conta vira multa
     * em reais.
     *
     * A prova é do banco (`ST_Within`) e não da tela, pela mesma razão de
     * sempre: validação que só existe no navegador não é validação.
     */
    public function criar(Request $r, Lote $lote): JsonResponse
    {
        if ($erro = $this->recusarSemCuradoria($r)) {
            return $erro;
        }

        $d = $r->validate([
            'geometry'             => ['required', 'array'],
            'geometry.type'        => ['required', 'string'],
            // Sem esta linha o `validate()` devolveria a geometria sem os
            // pontos — o mesmo defeito que derrubava o desenho de lote.
            'geometry.coordinates' => ['required', 'array'],
            'descricao'            => ['nullable', 'string', 'max:160'],
        ]);

        $geojson = json_encode($d['geometry']);

        if (! DB::scalar('SELECT ST_IsValid(ST_GeomFromGeoJSON(?, 1, 4326))', [$geojson])) {
            return response()->json([
                'message' => 'O contorno da edificação não fecha ou se cruza. Refaça o desenho.',
            ], 422);
        }

        $dentro = DB::scalar(
            'SELECT ST_Within(ST_GeomFromGeoJSON(?, 1, 4326), geom) FROM lotes WHERE id = ?',
            [$geojson, $lote->id]
        );

        if (! $dentro) {
            $fora = (float) DB::scalar(
                'SELECT ST_Area(ST_Difference(ST_GeomFromGeoJSON(?, 1, 4326), geom))
                   FROM lotes WHERE id = ?',
                [$geojson, $lote->id]
            );

            return response()->json([
                'message' => sprintf(
                    'A edificação sai %s m² para fora do lote. Ela precisa caber inteira '
                    . 'dentro dele — se a construção invade o vizinho ou a via, isso é '
                    . 'matéria de auto, não de desenho.',
                    number_format($fora, 2, ',', '.')),
            ], 422);
        }

        $area = (float) DB::scalar('SELECT ST_Area(ST_GeomFromGeoJSON(?, 1, 4326))', [$geojson]);

        $id = DB::transaction(function () use ($lote, $d, $geojson, $area) {
            DB::insert(
                'INSERT INTO edificacoes (lote_id, area_m2, descricao, geom, created_at, updated_at)
                 VALUES (?, ?, ?, ST_GeomFromGeoJSON(?, 1, 4326), ?, ?)',
                [$lote->id, round($area, 2), $d['descricao'] ?? null, $geojson, now(), now()]
            );

            return (int) DB::getPdo()->lastInsertId();
        });

        // O INSERT foi cru, então o evento `created` do Eloquent não disparou.
        // A trilha é registrada à mão — mesmo caminho do desenho de lote.
        $e = Edificacao::find($id);
        $e->registrarAuditoria('desenhou', null, [
            'lote_id' => $lote->id, 'area_m2' => round($area, 2), 'descricao' => $d['descricao'] ?? null,
        ]);

        return response()->json([
            'id'      => $id,
            'area_m2' => round($area, 2),
            'message' => sprintf('Edificação de %s m² desenhada no lote.',
                number_format($area, 2, ',', '.')),
        ]);
    }

    /** DELETE /api/edificacoes/{edificacao} */
    public function excluir(Request $r, Edificacao $edificacao): JsonResponse
    {
        if ($erro = $this->recusarSemCuradoria($r)) {
            return $erro;
        }

        $edificacao->delete();

        return response()->json(['message' => 'Edificação removida do desenho.']);
    }
}
