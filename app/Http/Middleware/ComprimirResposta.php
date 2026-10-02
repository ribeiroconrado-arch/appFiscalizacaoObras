<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Compacta (gzip) as respostas grandes do mapa.
 *
 * Um bloco de lotes de ~1 km tem ~1,8 MB de GeoJSON e ~100 KB compactado
 * (medido com 50 mil lotes). O Nginx do Ubuntu, na configuração padrão, só
 * compacta HTML — o JSON do mapa ia inteiro para o tablet do fiscal em campo.
 * Compactar aqui funciona com qualquer servidor web; se o Nginx também estiver
 * configurado para isso, ele vê o `Content-Encoding` e não compacta de novo.
 */
class ComprimirResposta
{
    /** Abaixo disso, compactar custa mais do que economiza. */
    private const MINIMO_BYTES = 8192;

    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        if ($resposta instanceof BinaryFileResponse || $resposta instanceof StreamedResponse
            || $resposta->headers->has('Content-Encoding')
            || ! str_contains((string) $request->header('Accept-Encoding'), 'gzip')
            || ! function_exists('gzencode')) {
            return $resposta;
        }

        $corpo = $resposta->getContent();
        if ($corpo === false || strlen($corpo) < self::MINIMO_BYTES) {
            return $resposta;
        }

        $compactado = gzencode($corpo, 5);
        if ($compactado === false) {
            return $resposta;
        }

        $resposta->setContent($compactado);
        $resposta->headers->set('Content-Encoding', 'gzip');
        $resposta->headers->set('Content-Length', (string) strlen($compactado));
        $resposta->setVary('Accept-Encoding', false);

        return $resposta;
    }
}
