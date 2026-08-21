<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuscaController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\LegislacaoController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\ParametroController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProtocoloController;
use App\Http\Controllers\VistoriaController;
use App\Http\Controllers\QuarteiraoController;
use App\Repositories\LoteRepository;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas
|--------------------------------------------------------------------------
| As rotas de API vivem AQUI, e não em routes/api.php, de propósito: o grupo
| `api` do Laravel é stateless, e o cliente deste sistema é a própria tela do
| mapa, autenticada por sessão. Registrando-as no grupo `web` elas herdam
| sessão, autenticação e proteção CSRF — em vez de exigir um segundo mecanismo
| de credencial (token) só para o front-end falar com o próprio back-end.
|
| Se um dia existir consumidor externo (app nativo, integração da prefeitura),
| aí sim entra Sanctum e um routes/api.php de verdade.
*/

// ── Público ──────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AuthController::class, 'mostrarLogin'])->name('login');
    Route::post('/entrar', [AuthController::class, 'entrar'])
        ->middleware('throttle:5,1')   // trava tentativa de força bruta
        ->name('login.entrar');
});

Route::post('/sair', [AuthController::class, 'sair'])->name('logout');

// ── Autenticado ──────────────────────────────────────────────
Route::middleware('auth')->group(function () {

    Route::get('/', function (LoteRepository $lotes) {
        return view('mapa', ['total' => $lotes->total()]);
    })->name('mapa');

    Route::prefix('api')->group(function () {
        // Painel e notificações
        Route::get('/painel', [PainelController::class, 'index']);
        Route::get('/notificacoes', [PainelController::class, 'notificacoes']);

        // Busca de imóveis sem abrir o mapa — a camada de satélite é paga por
        // requisição, e consulta de balcão não precisa de imagem aérea.
        Route::get('/imoveis/bairros', [BuscaController::class, 'bairros']);
        Route::get('/imoveis/busca', [BuscaController::class, 'buscar']);
        Route::get('/imoveis/pins', [BuscaController::class, 'pins']);
        // Depois das rotas fixas: registrada antes, a curinga engoliria
        // "bairros" e "busca" como se fossem id de lote.
        Route::get('/imoveis/{lote}', [BuscaController::class, 'ficha']);

        Route::get('/mapa/lotes', [MapaController::class, 'lotes']);
        Route::get('/mapa/extensao', [MapaController::class, 'extensao']);
        Route::get('/mapa/google-sessao', [MapaController::class, 'googleSessao']);
        Route::post('/localizacao/identificar', [MapaController::class, 'identificar']);

        // Fiscalização
        Route::get('/irregularidades', [VistoriaController::class, 'catalogo']);
        // Quadra vazia e uma pendencia de importacao, nao um defeito eterno:
        // o extrator prefere deixar em branco a chutar. Estas duas rotas sao
        // como se corrige — de uma vez, o quarteirao inteiro.
        Route::get('/lotes/{lote}/quarteirao', [QuarteiraoController::class, 'mostrar']);
        Route::post('/lotes/{lote}/quadra', [QuarteiraoController::class, 'aplicar']);

        Route::get('/lotes/{lote}/historico', [VistoriaController::class, 'historico']);
        Route::post('/lotes/{lote}/vistorias', [VistoriaController::class, 'store']);
        Route::delete('/evidencias/{evidencia}', [VistoriaController::class, 'excluirEvidencia']);

        // Etapa 6 — legislação e documentos
        Route::get('/documentos', [DocumentoController::class, 'index']);
        Route::get('/documentos/opcoes', [DocumentoController::class, 'opcoes']);
        Route::get('/vistorias/{vistoria}/sugestao', [DocumentoController::class, 'sugestao']);
        Route::post('/lotes/{lote}/documentos', [DocumentoController::class, 'store']);
        // Sem imóvel: o fiscal abre a peça em campo e amarra o lote depois.
        // A obrigatoriedade não sumiu — mudou para a lavratura.
        Route::post('/documentos', [DocumentoController::class, 'storeSemLote']);
        Route::post('/documentos/{documento}/lavrar', [DocumentoController::class, 'lavrar']);
        // Depois de /documentos/opcoes: registrada antes, a rota curinga
        // engoliria "opcoes" como se fosse o id de um documento.
        Route::get('/documentos/{documento}', [DocumentoController::class, 'ficha']);
        Route::post('/documentos/{documento}/anular', [DocumentoController::class, 'anular']);
        Route::patch('/documentos/{documento}', [DocumentoController::class, 'update']);
        Route::delete('/documentos/{documento}', [DocumentoController::class, 'destroy']);

        // Parâmetros > Legislação (escrita só para administrador)
        Route::get('/legislacao', [LegislacaoController::class, 'index']);
        Route::post('/legislacao', [LegislacaoController::class, 'salvarLei']);
        Route::post('/legislacao/artigos', [LegislacaoController::class, 'salvarArtigo']);
        Route::delete('/legislacao/artigos/{artigo}', [LegislacaoController::class, 'excluirArtigo']);
        // Depois da rota de artigos: registrada antes, a curinga engoliria
        // "artigos" como se fosse o id de uma lei.
        Route::delete('/legislacao/{legislacao}', [LegislacaoController::class, 'excluirLei']);

        // Protocolos — vistorias solicitadas pelo contribuinte
        Route::get('/protocolos', [ProtocoloController::class, 'index']);
        Route::post('/protocolos', [ProtocoloController::class, 'store']);
        Route::patch('/protocolos/{protocolo}', [ProtocoloController::class, 'update']);

        // Meu perfil — qualquer usuário autenticado, só sobre si mesmo
        Route::get('/perfil', [PerfilController::class, 'index']);
        Route::post('/perfil/senha', [PerfilController::class, 'trocarSenha']);
        Route::post('/perfil/assinatura', [PerfilController::class, 'salvarAssinatura']);
        Route::delete('/perfil/assinatura', [PerfilController::class, 'excluirAssinatura']);

        // Parâmetros do sistema (só administrador — trava no controller)
        Route::get('/parametros', [ParametroController::class, 'index']);
        Route::post('/parametros/usuarios', [ParametroController::class, 'salvarUsuario']);
        Route::post('/parametros/geral', [ParametroController::class, 'salvarGeral']);
        // Brasão do município: é ele que torna o sistema replicável em outra
        // prefeitura sem tocar no código.
        Route::post('/parametros/brasao', [ParametroController::class, 'salvarBrasao']);
        Route::delete('/parametros/brasao', [ParametroController::class, 'excluirBrasao']);
        Route::post('/parametros/upf', [ParametroController::class, 'salvarUpf']);
        Route::delete('/parametros/upf/{upf}', [ParametroController::class, 'excluirUpf']);
        Route::post('/parametros/feriados', [ParametroController::class, 'salvarFeriado']);
        Route::delete('/parametros/feriados/{feriado}', [ParametroController::class, 'excluirFeriado']);
    });

    // Fora do prefixo /api: é download de arquivo, não JSON.
    Route::get('/evidencias/{evidencia}/arquivo', [VistoriaController::class, 'arquivo'])
        ->name('evidencia.arquivo');
    Route::get('/documentos/{documento}/pdf', [DocumentoController::class, 'pdf'])
        ->name('documento.pdf');
    // Página HTML que se imprime sozinha — é o caminho da bobina 80mm, que o
    // dompdf não consegue gerar (página de altura variável).
    Route::get('/documentos/{documento}/impressao', [DocumentoController::class, 'impressao'])
        ->name('documento.impressao');
});
