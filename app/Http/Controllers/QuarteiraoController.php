<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Services\QuadraDoQuarteirao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correção da quadra de um lote — e, com ela, do quarteirão inteiro.
 *
 * O extrator deixa a quadra vazia quando o desenho não permite prová-la. Essa
 * escolha só se sustenta se houver como corrigir depois sem digitar lote a
 * lote: um loteamento novo pode chegar com centenas de lotes assim, e o
 * sistema precisa ser replicável em outra cidade, com outro desenhista e
 * outro DWG.
 *
 * Daí esta porta. A regra vive em QuadraDoQuarteirao e é a mesma do comando
 * de terminal (php artisan lotes:quadra) — as duas aplicam as mesmas provas.
 */
class QuarteiraoController extends Controller
{
    /**
     * GET /api/lotes/{lote}/quarteirao
     *
     * O que o usuário precisa ver antes de decidir: quantos lotes herdam, qual
     * a numeração deles, que quadras fazem divisa e qual número falta na
     * sequência do bairro.
     */
    public function mostrar(Lote $lote, QuadraDoQuarteirao $svc): JsonResponse
    {
        $ids = $svc->quarteiraoDoLote($lote);

        if ($ids === null) {
            return response()->json([
                'aplicavel' => false,
                'motivo'    => 'Este lote já tem quadra identificada.',
            ]);
        }

        return response()->json([
            'aplicavel' => true,
            'bairro'    => $lote->bairro,
        ] + $svc->retrato($lote->bairro, $ids));
    }

    /**
     * POST /api/lotes/{lote}/quadra
     *
     * Grava a quadra informada em todo o quarteirão. Só administrador: a
     * identificação do imóvel é a chave de integração com o cadastro
     * imobiliário, e mudá-la muda a que imóvel um auto se refere.
     */
    public function aplicar(Request $request, Lote $lote, QuadraDoQuarteirao $svc): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'message' => 'Só o administrador pode alterar a identificação do imóvel.',
            ], 403);
        }

        $d = $request->validate([
            'quadra' => ['required', 'string', 'max:20'],
        ]);

        $ids = $svc->quarteiraoDoLote($lote);
        if ($ids === null) {
            return response()->json([
                'message' => 'Este lote já tem quadra identificada.',
            ], 422);
        }

        $quadra = $svc->normalizar($d['quadra']);

        if ($impedimento = $svc->impedimento($lote->bairro, $ids, $quadra)) {
            return response()->json(['message' => $impedimento], 422);
        }

        $n = $svc->aplicar($lote->bairro, $ids, $quadra);

        return response()->json([
            'quadra'    => $quadra,
            'alterados' => $n,
            'message'   => "Quadra {$quadra} aplicada a {$n} lote(s) do quarteirão.",
        ]);
    }
}
