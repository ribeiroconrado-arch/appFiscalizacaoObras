<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tolerância de GPS (metros)
    |--------------------------------------------------------------------------
    | Usada em POST /api/localizacao/identificar quando a coordenada não cai
    | dentro de nenhum lote. O piso existe porque nenhum GPS de celular é
    | confiável abaixo disso, mesmo quando afirma que é; o teto evita devolver
    | meia quadra de candidatos quando o sinal está ruim.
    |
    | Os dois valores são para ajustar DEPOIS do teste de campo — a tolerância
    | definitiva sai de medição no bairro piloto, não de estimativa.
    */
    'tolerancia_min' => env('GPS_TOLERANCIA_MIN', 25),
    'tolerancia_max' => env('GPS_TOLERANCIA_MAX', 120),

    /*
    |--------------------------------------------------------------------------
    | Teto de lotes por resposta do mapa
    |--------------------------------------------------------------------------
    | Rede de segurança de GET /api/mapa/lotes. Quem controla o volume de fato
    | é o bbox somado ao zoom mínimo aplicado no cliente; este limite existe
    | para o caso de um bbox absurdamente grande. Ao ser atingido, a resposta
    | vem com `truncado: true` e o cliente avisa o fiscal — nunca truncar em
    | silêncio, que é a regra herdada do AppPOSTURAS.
    */
    'max_lotes' => env('MAPA_MAX_LOTES', 3000),

    /*
    |--------------------------------------------------------------------------
    | Sistema de referência
    |--------------------------------------------------------------------------
    | Armazenamento em EPSG:4326, que é o que o Leaflet consome. A base
    | municipal vem em EPSG:31981 (SIRGAS 2000 / UTM 21S), confirmado em
    | 16/08/2026, e é reprojetada fora do banco — o ST_Transform do MySQL só
    | converte entre SRSs geográficos. Ver docs/ADR-001-banco-espacial.md.
    |
    | A translação abaixo converte o desenho local do mapa municipal para UTM,
    | e é aplicada no pipeline Python, não aqui. Fica registrada para quem
    | precisar conferir a procedência de uma coordenada.
    */
    'srid_armazenamento' => 4326,
    'srid_origem'        => 31981,
    'translacao_local'   => ['dx' => 792035.2782, 'dy' => 8260796.2988],

];
