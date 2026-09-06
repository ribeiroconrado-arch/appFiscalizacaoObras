<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Segredo do webhook de deploy automático
    |--------------------------------------------------------------------------
    |
    | O MESMO segredo é cadastrado aqui e nas configurações do webhook no
    | GitHub (Settings > Webhooks > Secret). O GitHub assina cada entrega com
    | HMAC-SHA256 usando esse segredo; sem ele configurado nos dois lados, o
    | endpoint recusa toda requisição — nunca aceita sem assinatura.
    |
    | Gere com: openssl rand -hex 32
    */
    'webhook_secret' => env('DEPLOY_WEBHOOK_SECRET'),

];
