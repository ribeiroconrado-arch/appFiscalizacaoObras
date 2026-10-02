<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usuário desativado sai na próxima requisição.
 *
 * O login já recusa quem tem `ativo = false`, mas desativar alguém em
 * Parâmetros não derrubava a sessão que ele tinha aberta, nem o cookie
 * "lembrar-me" — o servidor desligado continuava navegando no mapa e no
 * cadastro até a sessão expirar sozinha.
 */
class ContaAtiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->ativo) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $mensagem = 'Seu acesso foi desativado.';

            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => $mensagem], 401)
                : redirect()->route('login')->withErrors(['identificador' => $mensagem]);
        }

        return $next($request);
    }
}
