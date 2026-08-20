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

    /*
    |--------------------------------------------------------------------------
    | Imagem aérea alternativa (opcional)
    |--------------------------------------------------------------------------
    | O satélite gratuito da Esri termina no zoom 17 em Primavera do Leste —
    | verificado no centro, no Jardim Europa, no Buritis e na entrada sul, e
    | nas 196 capturas do acervo histórico Wayback. Acima disso o tile é só
    | ampliado, e o detalhe que não foi fotografado não aparece.
    |
    | Preenchendo estas chaves, uma terceira opção entra no seletor de camadas.
    | Serve para provedor comercial com chave (Mapbox, MapTiler, Bing) e,
    | principalmente, para a ORTOFOTO DO MUNICÍPIO servida em tiles — que é a
    | solução de fato, a única que chega a 10-15 cm/px.
    |
    | Exemplo com ortofoto própria:
    |   SATELITE_ALT_URL="https://gis.primaveradoleste.mt.gov.br/orto/{z}/{x}/{y}.png"
    |   SATELITE_ALT_ROTULO="Ortofoto 2025"
    |   SATELITE_ALT_MAXZOOM=20
    */
    /*
    | Mapbox Satellite — acervo Maxar, resolução tipicamente entre 15 e 30 cm/px
    | em cidades deste porte, contra ~1,15 m/px do satélite gratuito da Esri.
    |
    | O token é do tipo público (pk.…) e vai para o HTML: é assim que o Mapbox
    | funciona no navegador. Por isso, RESTRINJA o token por URL no painel da
    | conta (Account > Tokens > URL restrictions), senão qualquer um que veja o
    | código-fonte pode consumir a cota da prefeitura.
    |
    | Plano gratuito: verifique a franquia mensal vigente no painel do Mapbox
    | antes de liberar para todos os fiscais.
    */
    /*
    | Google Map Tiles API — imagem aérea de alta resolução.
    |
    | Diferente de Mapbox e Esri, o Google não aceita URL fixa de tile: exige
    | criar uma SESSÃO antes e usar o token dela em cada requisição. Daí o
    | endpoint /api/mapa/google-sessao, que cria o token e o guarda em cache
    | até expirar.
    |
    | A chave vai para o navegador — é assim que a API funciona no cliente.
    | RESTRINJA por referrer HTTP no console do Google: sem isso, quem ler o
    | código-fonte gera faturamento no projeto da prefeitura.
    |
    | Exige a Map Tiles API habilitada no projeto do Google Cloud.
    */
    'google_key'    => env('GOOGLE_MAPS_API_KEY'),

    'mapbox_token'  => env('MAPBOX_TOKEN'),
    'mapbox_estilo' => env('MAPBOX_ESTILO', 'mapbox.satellite'),

    'satelite_alt_url'        => env('SATELITE_ALT_URL'),
    'satelite_alt_rotulo'     => env('SATELITE_ALT_ROTULO', 'Imagem HD'),
    'satelite_alt_atribuicao' => env('SATELITE_ALT_ATRIBUICAO', ''),
    'satelite_alt_maxzoom'    => env('SATELITE_ALT_MAXZOOM', 19),

    /*
    | Modo híbrido: a ortofoto entra POR CIMA do satélite, não no lugar dele.
    |
    | _MINZOOM  a partir de que zoom ela vale. Abaixo disso o satélite dá mais
    |           contexto, e baixar a ortofoto seria desperdício. 17 é onde o
    |           acervo da Esri se esgota nesta região.
    | _BOUNDS   retângulo coberto pela imagem, no formato sul,oeste;norte,leste
    |           Fora dele o Leaflet nem pede o tile, então uma ortofoto que
    |           cubra só a mancha urbana convive com o satélite no resto do
    |           município: sem buraco no mapa e sem requisição inútil.
    |
    | Exemplo, mancha urbana de Primavera do Leste:
    |   SATELITE_ALT_BOUNDS=-15.60,-54.40;-15.50,-54.22
    */
    'satelite_alt_minzoom'    => env('SATELITE_ALT_MINZOOM', 17),
    'satelite_alt_bounds'     => env('SATELITE_ALT_BOUNDS'),

];
