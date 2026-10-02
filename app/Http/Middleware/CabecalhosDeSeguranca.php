<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança em toda resposta, pela própria aplicação — valem
 * qualquer que seja o servidor web na frente (Nginx da VPS, Herd local).
 *
 * - X-Frame-Options: nenhum outro site embute o sistema num iframe e engana o
 *   fiscal para clicar em "Lavrar" sem ver (clickjacking).
 * - X-Content-Type-Options: o navegador não "adivinha" que um anexo é script.
 * - Referrer-Policy: a URL interna (com id de vistoria, documento) não vaza
 *   para os servidores de tile do mapa.
 * - Permissions-Policy: só este site pede GPS e câmera; microfone, nunca.
 *
 * HSTS fica no Nginx (só faz sentido em HTTPS) — ver docs/seguranca-servidor.md.
 * CSP ainda não: as telas usam `onclick` embutido, que uma CSP séria proíbe.
 */
class CabecalhosDeSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        $resposta->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $resposta->headers->set('X-Content-Type-Options', 'nosniff', false);
        $resposta->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $resposta->headers->set('Permissions-Policy', 'geolocation=(self), camera=(self), microphone=()', false);

        return $resposta;
    }
}
