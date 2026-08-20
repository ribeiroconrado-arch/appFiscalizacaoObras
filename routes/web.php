<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\LegislacaoController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\ParametroController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProtocoloController;
use App\Http\Controllers\VistoriaController;
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

        Route::get('/mapa/lotes', [MapaController::class, 'lotes']);
        Route::get('/mapa/extensao', [MapaController::class, 'extensao']);
        Route::get('/mapa/google-sessao', [MapaController::class, 'googleSessao']);
        Route::post('/localizacao/identificar', [MapaController::class, 'identificar']);

        // Fiscalização
        Route::get('/irregularidades', [VistoriaController::class, 'catalogo']);
        Route::get('/lotes/{lote}/historico', [VistoriaController::class, 'historico']);
        Route::post('/lotes/{lote}/vistorias', [VistoriaController::class, 'store']);
        Route::delete('/evidencias/{evidencia}', [VistoriaController::class, 'excluirEvidencia']);

        // Etapa 6 — legislação e documentos
        Route::get('/documentos', [DocumentoController::class, 'index']);
        Route::get('/documentos/opcoes', [DocumentoController::class, 'opcoes']);
        Route::get('/vistorias/{vistoria}/sugestao', [DocumentoController::class, 'sugestao']);
        Route::post('/lotes/{lote}/documentos', [DocumentoController::class, 'store']);
        Route::post('/documentos/{documento}/lavrar', [DocumentoController::class, 'lavrar']);

        // Parâmetros > Legislação (escrita só para administrador)
        Route::get('/legislacao', [LegislacaoController::class, 'index']);
        Route::post('/legislacao', [LegislacaoController::class, 'salvarLei']);
        Route::post('/legislacao/artigos', [LegislacaoController::class, 'salvarArtigo']);
        Route::delete('/legislacao/artigos/{artigo}', [LegislacaoController::class, 'excluirArtigo']);

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
});
