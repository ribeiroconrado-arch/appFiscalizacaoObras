<?php

namespace App\Http\Controllers;

use App\Support\Modulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O seletor Obras | Posturas do cabeçalho.
 *
 * Só troca o módulo da sessão; a tela recarrega em seguida, e cada lista já
 * vem do servidor recortada pelo módulo novo (Modulo::ativo). Recarregar é de
 * propósito: são dezenas de telas com estado próprio no navegador, e limpar
 * uma por uma deixaria sempre alguma mostrando dado do módulo anterior.
 */
class ModuloController extends Controller
{
    /** POST /api/modulo {modulo} */
    public function trocar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'modulo' => ['required', Rule::in(Modulo::todos())],
        ]);

        if (! $request->user()->temModulo($d['modulo'])) {
            return response()->json(['message' => 'Você não tem acesso a este módulo.'], 403);
        }

        Modulo::definir($d['modulo']);

        return response()->json(['modulo' => $d['modulo'], 'titulo' => Modulo::titulo($d['modulo'])]);
    }
}
