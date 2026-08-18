<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * @assetv('css/app.css') — asset com versão pela data de modificação.
         *
         * Este projeto serve CSS e JS estáticos de `public/`, sem Vite (o
         * front-end veio pronto do AppPOSTURAS e não precisa de build). Sem
         * versionamento, o navegador serve a folha antiga depois de cada
         * alteração — o que já aconteceu aqui: uma tela de login apareceu sem
         * estilo por cache, não por erro de CSS.
         *
         * `filemtime` muda a cada edição, então a URL muda junto e o cache
         * cai sozinho. Em produção, se o arquivo sumir, cai para `asset()`
         * puro em vez de derrubar a página.
         */
        Blade::directive('assetv', function (string $expression) {
            return "<?php
                \$__caminho = {$expression};
                \$__abs = public_path(\$__caminho);
                echo asset(\$__caminho) . (is_file(\$__abs) ? '?v=' . filemtime(\$__abs) : '');
            ?>";
        });
    }
}
