<?php

namespace App\Providers;

use App\Cadastro\CadastroCarregado;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Cadastro\FonteDoCadastro;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * De onde vem o cadastro imobiliário.
         *
         * Hoje, da exportação da prefeitura carregada por `cadastro:carregar`.
         * No dia em que houver acesso ao banco deles, troca-se ESTA LINHA por
         * outra implementação de FonteDoCadastro — nem o serviço, nem o
         * controller, nem a tela ficam sabendo.
         */
        $this->app->bind(FonteDoCadastro::class, CadastroCarregado::class);
    }

    public function boot(): void
    {
        /*
         * LIMITE DE TENTATIVAS POR IDENTIFICADOR, E NÃO SÓ POR IP.
         *
         * O `throttle:5,1` conta só pelo IP. Numa prefeitura, os fiscais saem
         * todos pelo MESMO endereço público: cinco pessoas errando a senha uma
         * vez cada trancava o login de todo mundo, inclusive de quem digitou
         * certo. Pior: quem estivesse atacando de outra rede não seria contido
         * por isso.
         *
         * Duas contagens, então. Cinco tentativas por minuto para o par
         * (identificador, IP) — que é o alvo real de um ataque de senha — e um
         * teto de 30 por minuto para o IP inteiro, que segura a varredura de
         * matrículas sequenciais sem punir a rede compartilhada.
         */
        RateLimiter::for('login', function (Request $request) {
            $quem = mb_strtolower(trim((string) $request->input('identificador')));

            return [
                Limit::perMinute(5)->by($quem . '|' . $request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

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
