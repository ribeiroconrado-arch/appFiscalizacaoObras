<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuscaController;
use App\Http\Controllers\CadastroImobiliarioController;
use App\Http\Controllers\CadastroLoteController;
use App\Http\Controllers\DemandaController;
use App\Http\Controllers\EdificacaoController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\LegislacaoController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\ParametroController;
use App\Http\Controllers\TrilhaController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\OrdemServicoController;
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
        ->middleware('throttle:login')   // ver AppServiceProvider: conta por identificador E por IP
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
        // DOIS ENDPOINTS DE BAIRRO, de propósito.
        //
        // `/imoveis/bairros` é FILTRO DE BUSCA: devolve os bairros que têm
        // lote no desenho, porque oferecer no filtro um bairro que não vai
        // trazer nenhum resultado é oferecer trabalho perdido.
        //
        // `/bairros` é CADASTRO: devolve os bairros do município, todos os
        // que a prefeitura reconhece, porque é ali que se escolhe o bairro de
        // um lote NOVO — e lote novo pode ser o primeiro do bairro dele.
        Route::get('/imoveis/bairros', [BuscaController::class, 'bairros']);
        Route::get('/bairros', [BuscaController::class, 'bairrosDoMunicipio']);
        // As ruas que a busca pode achar — só as dos bairros cujo cadastro já
        // foi carregado e amarrado. Ver BuscaController::logradouros.
        Route::get('/imoveis/logradouros', [BuscaController::class, 'logradouros']);
        Route::get('/imoveis/busca', [BuscaController::class, 'buscar']);
        Route::get('/imoveis/pins', [BuscaController::class, 'pins']);
        // Depois das rotas fixas: registrada antes, a curinga engoliria
        // "bairros" e "busca" como se fossem id de lote.
        Route::get('/imoveis/{lote}', [BuscaController::class, 'ficha']);
        // Cadastro imobiliário: só quando a aba é aberta, nunca junto do mapa.
        // O contorno de um imóvel, sob demanda — ver BuscaController::geometria.
        Route::get('/imoveis/{lote}/geometria', [BuscaController::class, 'geometria']);
        Route::get('/imoveis/{lote}/bci', [CadastroImobiliarioController::class, 'mostrar']);
        Route::post('/imoveis/{lote}/bci/atualizar', [CadastroImobiliarioController::class, 'atualizar']);
        // Edificações desenhadas dentro do lote — o croqui e a área construída.
        Route::get('/imoveis/{lote}/edificacoes', [EdificacaoController::class, 'listar']);
        Route::post('/imoveis/{lote}/edificacoes', [EdificacaoController::class, 'criar']);
        Route::delete('/edificacoes/{edificacao}', [EdificacaoController::class, 'excluir']);

        Route::get('/mapa/lotes', [MapaController::class, 'lotes']);
        Route::get('/mapa/extensao', [MapaController::class, 'extensao']);
        Route::get('/mapa/google-sessao', [MapaController::class, 'googleSessao']);
        Route::post('/localizacao/identificar', [MapaController::class, 'identificar']);

        // Fiscalização
        Route::get('/irregularidades', [VistoriaController::class, 'catalogo']);
        // O enquadramento conferido em CAMPO, antes de a vistoria existir.
        Route::get('/artigos-sugeridos', [VistoriaController::class, 'artigosSugeridos']);
        // Quadra vazia e uma pendencia de importacao, nao um defeito eterno:
        // o extrator prefere deixar em branco a chutar. Estas duas rotas sao
        // como se corrige — de uma vez, o quarteirao inteiro.
        Route::get('/lotes/{lote}/quarteirao', [QuarteiraoController::class, 'mostrar']);
        Route::post('/lotes/{lote}/quadra', [QuarteiraoController::class, 'aplicar']);

        // Correcao de quadra ERRADA, a partir de selecao feita a dedo no mapa.
        // O par acima so preenche quadra vazia; este sobrescreve, e por isso
        // tem provas proprias — ver App\Services\QuadraDeLotesSelecionados.
        //
        // A previa e POST apesar de nao alterar nada: leva a lista de ids, que
        // nao cabe confortavelmente numa query string.
        Route::post('/lotes/quadra-em-massa/previa', [CadastroLoteController::class, 'previaQuadra']);
        Route::post('/lotes/quadra-em-massa', [CadastroLoteController::class, 'aplicarQuadra']);

        // ATOS DIRETOS — o cadastro em dia com o que o DWG não acompanhou.
        // Sem protocolo, porque não há nada a decidir: só o curador do cadastro
        // executa, e cada ato guarda a justificativa e quem assinou.
        Route::post('/lotes/unificacao-direta/previa', [CadastroLoteController::class, 'previaUnificacaoDireta']);
        Route::post('/lotes/unificacao-direta', [CadastroLoteController::class, 'unificarDireto']);
        Route::post('/lotes/desmembramento-direto/previa', [CadastroLoteController::class, 'previaDesmembramentoDireto']);
        Route::post('/lotes/desmembramento-direto', [CadastroLoteController::class, 'desmembrarDireto']);

        // Apagar resíduo do desenho — um ou VÁRIOS de uma vez, marcados no
        // mapa. Pede a senha no corpo, e por isso não é DELETE /lotes/{id}:
        // corpo em DELETE é aceito mas mal suportado por proxies e clientes.
        Route::post('/lotes/excluir', [CadastroLoteController::class, 'excluir']);

        // Desenhar lote que a importacao nao trouxe. O extrator suprime lote em
        // silencio quando o desenho do DWG nao coopera — foi assim que a Quadra
        // 05 do Jardim Europa, um lote unico de 12.008 m2, simplesmente nao veio.
        Route::post('/lotes/previa', [CadastroLoteController::class, 'previaDesenho']);
        Route::post('/lotes', [CadastroLoteController::class, 'criarDesenho']);

        // Atos cadastrais. O portao NAO e o perfil: e a VISTORIA regular
        // amarrada ao protocolo deferido. O deferimento diz que o pedido
        // procede no papel; a vistoria diz que o papel bate com o chao.
        Route::post('/protocolos/{protocolo}/unificacao/previa', [CadastroLoteController::class, 'previaUnificacao']);
        Route::post('/protocolos/{protocolo}/unificacao', [CadastroLoteController::class, 'unificar']);
        Route::post('/protocolos/{protocolo}/desmembramento/previa', [CadastroLoteController::class, 'previaDesmembramento']);
        Route::post('/protocolos/{protocolo}/desmembramento', [CadastroLoteController::class, 'desmembrar']);

        // Protocolos de desmembramento/unificacao a espera de vistoria. A
        // vistoria e o portao do ato cadastral: o deferimento diz que o pedido
        // procede no papel, a vistoria diz que o papel bate com o chao.
        Route::get('/lotes/{lote}/protocolos-cadastrais', [VistoriaController::class, 'protocolosCadastrais']);
        Route::get('/lotes/{lote}/historico', [VistoriaController::class, 'historico']);
        Route::get('/vistorias/{vistoria}', [VistoriaController::class, 'mostrar']);
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
        // Ordens de servico: a coordenacao determina, o fiscal cumpre. As
        // rotas fixas vem antes da curinga {ordem}, senao "fiscais" seria lido
        // como id de uma ordem.
        Route::get('/os/fiscais', [OrdemServicoController::class, 'fiscais']);
        Route::get('/os', [OrdemServicoController::class, 'index']);
        Route::post('/os', [OrdemServicoController::class, 'store']);
        Route::get('/os/{ordem}', [OrdemServicoController::class, 'show']);
        Route::post('/os/{ordem}/situacao', [OrdemServicoController::class, 'situacao']);
        Route::post('/os/{ordem}/ciencia', [OrdemServicoController::class, 'ciencia']);

        // A FILA NUMA LISTA SÓ: protocolo e ordem de serviço respondem à mesma
        // pergunta ("o que há para fazer?") e viviam em duas abas. As tabelas
        // continuam separadas — ver DemandaController para o porquê.
        Route::get('/demandas', [DemandaController::class, 'index']);
        Route::get('/protocolos', [ProtocoloController::class, 'index']);
        Route::post('/protocolos', [ProtocoloController::class, 'store']);
        Route::get('/protocolos/{protocolo}', [ProtocoloController::class, 'mostrar']);
        Route::patch('/protocolos/{protocolo}', [ProtocoloController::class, 'update']);

        // Meu perfil — qualquer usuário autenticado, só sobre si mesmo
        Route::get('/perfil', [PerfilController::class, 'index']);
        Route::post('/perfil/senha', [PerfilController::class, 'trocarSenha']);
        Route::post('/perfil/assinatura', [PerfilController::class, 'salvarAssinatura']);
        Route::delete('/perfil/assinatura', [PerfilController::class, 'excluirAssinatura']);

        // Parâmetros do sistema (só administrador — trava no controller)
        // A TRILHA DE ALTERAÇÕES — quem mexeu no quê, e o que mudou.
        //
        // Fica no grupo de parâmetros porque é onde ela mora na tela, mas a
        // permissão é decidida no controller e não aqui: ela vale para
        // administrador OU curador do cadastro, e o middleware do grupo não
        // distingue os dois.
        Route::get('/trilha', [TrilhaController::class, 'index']);
        Route::post('/trilha/{id}/desfazer', [TrilhaController::class, 'desfazer']);

        // O histórico DO MAPA — recorte do cadastro, para a mesa de curadoria.
        // Separado de `/trilha` porque a regra de quem vê é outra: aqui é o
        // curador, o mesmo que a mesa já exige para as ferramentas vizinhas.
        Route::get('/cadastro/historico', [TrilhaController::class, 'cadastro']);

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
        Route::post('/parametros/bairros', [ParametroController::class, 'salvarBairro']);
        Route::delete('/parametros/bairros/{bairro}', [ParametroController::class, 'excluirBairro']);
        Route::post('/parametros/irregularidades', [ParametroController::class, 'salvarIrregularidade']);
        Route::delete('/parametros/irregularidades/{irregularidade}', [ParametroController::class, 'excluirIrregularidade']);
    });

    // Fora do prefixo /api: é download de arquivo, não JSON.
    Route::get('/evidencias/{evidencia}/arquivo', [VistoriaController::class, 'arquivo'])
        ->name('evidencia.arquivo');

    // O relatório de vistoria em papel, nos dois destinos do documento: a
    // janela de impressão do navegador e o PDF do dompdf.
    Route::get('/vistorias/{vistoria}/impressao', [VistoriaController::class, 'impressao'])
        ->name('vistoria.impressao');
    Route::get('/vistorias/{vistoria}/pdf', [VistoriaController::class, 'pdf'])
        ->name('vistoria.pdf');
    Route::get('/documentos/{documento}/pdf', [DocumentoController::class, 'pdf'])
        ->name('documento.pdf');
    // Página HTML que se imprime sozinha — é o caminho da bobina 80mm, que o
    // dompdf não consegue gerar (página de altura variável).
    Route::get('/documentos/{documento}/impressao', [DocumentoController::class, 'impressao'])
        ->name('documento.impressao');
    // A via em papel da ordem de servico, no mesmo lugar e pelo mesmo motivo:
    // devolve HTML para a impressora, e a tela abre com window.open.
    Route::get('/os/{ordem}/impressao', [OrdemServicoController::class, 'impressao'])
        ->name('os.impressao');
});
