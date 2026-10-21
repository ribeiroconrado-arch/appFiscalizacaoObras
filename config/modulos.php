<?php

/*
|--------------------------------------------------------------------------
| Módulos do sistema
|--------------------------------------------------------------------------
| As duas fiscalizações que dividem esta aplicação. O que difere entre elas
| mora aqui, e não espalhado em `if ($modulo === ...)` pelo código:
|
|  - `tipos`: as peças que o módulo emite, na ordem do menu "Novo documento".
|    O rótulo e a sigla de cada uma estão em Documento::TIPOS, o catálogo
|    único — uma Notificação é a mesma peça nos dois módulos, só a numeração
|    é separada (documento_contadores.modulo).
|  - `abas`: o menu da tela, na ordem. As chaves são os destinos de irPara().
|  - `parametros`: valores próprios do módulo para chaves de Parametro. Valem
|    quando o administrador não gravou outro em "chave.modulo"; na falta dos
|    dois, vale o valor global. Ver Parametro::doModulo.
|
| A ordem dos módulos é a ordem do seletor no cabeçalho, e o primeiro que o
| usuário tiver é o que abre por padrão (App\Support\Modulo::ativo).
*/

return [

    'obras' => [
        'rotulo' => 'Obras',
        'titulo' => 'Fiscalização de Obras',
        'tipos'  => ['vistoria', 'notificacao', 'notificacao_embargo', 'auto_embargo', 'auto_infracao'],
        'abas'   => ['painel', 'busca', 'mapa', 'documentos', 'protocolos'],
        'parametros' => [],
    ],

    'posturas' => [
        'rotulo' => 'Posturas',
        'titulo' => 'Fiscalização de Posturas',
        // Cadeia do AppPOSTURAS: Notificação → Auto de Infração → Auto de Apreensão.
        'tipos'  => ['notificacao', 'auto_infracao', 'auto_apreensao'],
        // Denúncias é a tela principal de Posturas; Consulta é do cadastro de lotes, de Obras.
        // "protocolos" aqui mostra só as ordens de serviço (DemandaController).
        'abas'   => ['painel', 'denuncias', 'mapa', 'documentos', 'protocolos'],
        'parametros' => [
            'impressao_selo' => 'FISCALIZAÇÃO DE POSTURAS',
        ],
    ],

];
