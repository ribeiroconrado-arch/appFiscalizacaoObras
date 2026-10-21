<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // O Supabase do AppPOSTURAS, de onde `php artisan posturas:importar` traz
    // denúncias, cadastros, leis e configurações. A chave é a PÚBLICA (a mesma
    // do VITE_SUPABASE_KEY de lá): o acesso de verdade vem do login de um
    // administrador do Posturas, pedido pelo comando na hora e nunca gravado.
    'posturas_supabase' => [
        'url'   => env('POSTURAS_SUPABASE_URL'),
        'chave' => env('POSTURAS_SUPABASE_CHAVE'),
    ],

];
