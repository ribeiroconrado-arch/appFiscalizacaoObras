<?php

namespace App\Http\Controllers;

use App\Services\FundoDaCidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * O fundo do mapa completado com o OpenStreetMap nos vazios entre os bairros
 * (App\Services\FundoDaCidade). O mapa de todos lê; só o administrador
 * atualiza, em Parâmetros › Mapa.
 */
class FundoDaCidadeController extends Controller
{
    public function __construct(private FundoDaCidade $fundo) {}

    /** GET /api/mapa/fundo-cidade — o arquivo pronto; vazio se nunca foi gerado. */
    public function mapa(Request $r): Response|JsonResponse
    {
        $c = $this->fundo->caminho();
        if (! $c) {
            return response()->json(['vias' => [], 'areas' => []]);
        }
        $etag = '"' . md5((string) filemtime($c)) . '"';
        if ($r->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response((string) file_get_contents($c), 200, [
            'Content-Type'  => 'application/json',
            'ETag'          => $etag,
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /** GET /api/parametros/fundo-cidade — última cópia, quantidades e se há atualização em curso. */
    public function resumo(Request $r): JsonResponse
    {
        if ($n = $this->exigirAdmin($r)) {
            return $n;
        }

        return response()->json(['resumo' => $this->fundo->resumo()] + $this->fundo->andamento());
    }

    /**
     * POST /api/parametros/fundo-cidade — baixa e recorta de novo.
     *
     * Responde na hora e trabalha DEPOIS da resposta: o download e o recorte
     * levam até um minuto, e o servidor web cortaria a requisição antes. A
     * tela pergunta pelo resumo até o andamento acabar.
     */
    public function atualizar(Request $r): JsonResponse
    {
        if ($n = $this->exigirAdmin($r)) {
            return $n;
        }
        if ($this->fundo->andamento()['andamento']) {
            return response()->json(['message' => 'Já há uma atualização em curso.', 'andamento' => true], 202);
        }
        $this->fundo->marcarInicio();
        app()->terminating(fn () => $this->fundo->atualizarRegistrando());

        return response()->json(['message' => 'Atualizando o fundo…', 'andamento' => true], 202);
    }

    private function exigirAdmin(Request $r): ?JsonResponse
    {
        return $r->user()->isAdmin()
            ? null
            : response()->json(['message' => 'Só administrador atualiza o fundo do mapa.'], 403);
    }
}
