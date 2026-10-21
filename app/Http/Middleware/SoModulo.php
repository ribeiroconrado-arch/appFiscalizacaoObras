<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fecha uma rota a quem não tem o módulo: `->middleware('modulo:posturas')`.
 *
 * Mesma lógica de SoInterno: esconder a aba é conveniência, quem autoriza é
 * isto. O que vale é User::temModulo — o administrador tem todos.
 */
class SoModulo
{
    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        if (! $request->user()?->temModulo($modulo)) {
            $mensagem = 'Você não tem acesso a este módulo.';

            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => $mensagem], 403)
                : abort(403, $mensagem);
        }

        return $next($request);
    }
}
