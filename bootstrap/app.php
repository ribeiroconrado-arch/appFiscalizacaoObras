<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // As rotas /api/* vivem em web.php, no grupo `web`, para herdarem
        // sessão e CSRF — ver o comentário no topo daquele arquivo.
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Visitante sem sessão vai para o login, não para a rota 'login'
        // inexistente que o Laravel assume por padrão.
        $middleware->redirectGuestsTo(fn () => route('login'));

        /*
         * ATRÁS DE PROXY (Cloudflare, balanceador, Nginx da VPS).
         *
         * Sem isto o Laravel enxerga a conexão do PROXY, não a do usuário: gera
         * link em http dentro de página https (o navegador bloqueia como
         * conteúdo misto), acha que o domínio é `localhost` e registra o IP do
         * proxy em toda auditoria — que num sistema onde o auto é assinado por
         * um agente é perder a identificação de quem fez o quê.
         *
         * `'*'` porque o IP do proxy não é conhecido de antemão e muda: no
         * túnel é a Cloudflare, na VPS é o Nginx local. O que torna isso seguro
         * é o servidor de aplicação NÃO estar exposto direto na internet —
         * só o proxy fala com ele.
         */
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
