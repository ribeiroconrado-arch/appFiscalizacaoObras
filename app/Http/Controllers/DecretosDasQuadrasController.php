<?php

namespace App\Http\Controllers;

use App\Cadastro\DecretosDasQuadras;
use App\Repositories\LoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quadras por decreto (19/11/2026): que ato criou cada quadra e quais a
 * alteraram. O balão do lote lê (todos); a ferramenta do mapa grava (curador
 * ou administrador, os mesmos que geram o contorno do bairro).
 */
class DecretosDasQuadrasController extends Controller
{
    public function __construct(private DecretosDasQuadras $decretos) {}

    /**
     * GET /api/bairros/decretos-das-quadras?bairro=… [&areas=1]
     * `areas` traz a área de cada quadra desenhada, para a conferência da
     * ferramenta; o balão não precisa.
     */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['bairro' => ['required', 'string', 'max:160'], 'areas' => ['nullable', 'boolean']]);
        $saida = $this->decretos->doBairro($this->decretos->bairro($d['bairro']));

        if (! empty($d['areas'])) {
            $saida['areas'] = [];
            foreach (DB::select('SELECT numero, ST_Area(geom) AS area FROM quadras WHERE bairro = ?', [$d['bairro']]) as $q) {
                $saida['areas'][$q->numero] = round((float) $q->area, 2);
            }
        }

        return response()->json($saida);
    }

    /**
     * GET /api/mapa/etapas — a camada "Etapas dos loteamentos". Segue a regra
     * do contorno: bairro sem lote não vai ao mapa; só com lotes em revisão,
     * só para a curadoria.
     */
    public function etapas(Request $r, LoteRepository $lotes): JsonResponse
    {
        $curador = $r->user()->podeCurarCadastro() || $r->user()->isAdmin();
        $situacao = $lotes->situacaoDosBairros();

        return response()->json($this->decretos->etapasDaCidade(function (string $bairro) use ($situacao, $curador) {
            $s = $situacao[$bairro] ?? null;

            return $s && ($curador || (int) $s->publicados > 0);
        }));
    }

    /** PUT /api/bairros/decretos-das-quadras — {bairro, criou:{quadra:id}, alterou:{quadra:[ids]}} */
    public function gravar(Request $r): JsonResponse
    {
        if (! ($r->user()->podeCurarCadastro() || $r->user()->isAdmin())) {
            return response()->json(['message' => 'Só a curadoria marca as quadras dos decretos.'], 403);
        }
        $d = $r->validate([
            'bairro'  => ['required', 'string', 'max:160'],
            'criou'   => ['nullable', 'array'],
            'criou.*' => ['integer'],
            'alterou'   => ['nullable', 'array'],
            'alterou.*' => ['array'],
            'alterou.*.*' => ['integer'],
        ]);
        $b = $this->decretos->bairro($d['bairro']);
        if (! $b) {
            return response()->json(['message' => 'Bairro sem cadastro em Parâmetros › Bairros.'], 422);
        }
        $this->decretos->gravar($b, $d['criou'] ?? [], $d['alterou'] ?? []);

        return response()->json($this->decretos->doBairro($b));
    }
}
