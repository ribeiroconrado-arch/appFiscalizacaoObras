<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fecha para o usuário EXTERNO (topógrafo, arquiteto, contribuinte) tudo o que
 * é da fiscalização: vistoria, documento, protocolo, ordem de serviço, painel,
 * parâmetros e as vias em papel.
 *
 * ── Negar por padrão ──
 *
 * Em routes/web.php este middleware cobre o grupo de "todo o resto", e o que o
 * externo pode ver fica num grupo à parte, listado rota a rota. Rota nova que
 * alguém esqueça de classificar nasce FECHADA para ele. O contrário — marcar
 * rota por rota o que é restrito — falharia do jeito mais caro: em silêncio,
 * abrindo auto de infração a quem não é servidor.
 *
 * Esconder abas e botões na tela é conveniência. Quem autoriza é isto.
 */
class SoInterno
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isExterno()) {
            $mensagem = 'Este conteúdo é restrito à Fiscalização.';

            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => $mensagem], 403)
                : abort(403, $mensagem);
        }

        return $next($request);
    }
}
