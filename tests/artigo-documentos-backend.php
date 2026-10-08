<?php
// Diagnóstico local explícito, no molde de pesquisa-mapa-backend.php: tudo roda
// dentro de uma transação que é DESFEITA no fim. Exige a migração
// 2026_10_16_000100 aplicada e um administrador agente ativo.
//
//   php tests/artigo-documentos-backend.php
//
// Cobre os DOCUMENTOS em que cada artigo entra (marcados um a um): a regra
// Artigo::serveA, a recusa ao gravar e ao lavrar, o filtro da sugestão da
// vistoria, as opções do formulário, o prazo sugerido da notificação e o aviso
// (que não bloqueia) do Auto de Embargo sem Notificação de Embargo vencida.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\LegislacaoController;
use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\Lote;
use App\Models\User;
use App\Services\LavraturaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}
/**
 * Direto no controlador (o POST pelo kernel esbarra no token CSRF, que não
 * existe sem sessão de navegador). Devolve [status, corpo].
 */
function chamar(User $u, string $classe, string $metodo, array $corpo = [], array $args = []): array {
    global $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create('/x', $corpo ? 'POST' : 'GET', $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    try {
        $r = $app->make($classe)->{$metodo}($req, ...$args);
    } catch (Illuminate\Validation\ValidationException $e) {
        return [422, ['errors' => $e->errors()]];
    }
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador agente ativo para o teste local.'); }
$lote = Lote::where('situacao', 'ativo')->where('em_revisao', false)->first();
if (! $lote) { throw new RuntimeException('Sem lote para o teste local.'); }

DB::beginTransaction();
try {
    $lei = Legislacao::create(['numero' => 'TESTE DOC 1/2099', 'nome' => 'Lei de teste dos documentos', 'ano' => 2099,
        'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true]);
    $novo = fn (string $numero, ?array $documentos, array $extra = []) => Artigo::create(['legislacao_id' => $lei->id, 'numero' => $numero,
        'apelido' => $numero, 'conduta' => 'texto de teste', 'base_multa' => 'sem_multa', 'ativo' => true, 'documentos' => $documentos] + $extra);
    $comum    = $novo('Art. T1', ['notificacao', 'auto_infracao']);
    $notifica = $novo('Art. T2', ['notificacao', 'notificacao_embargo'], ['prazo_notificacao_dias' => 5]);   // o art. 22: só notifica
    $embarga  = $novo('Art. T3', ['auto_embargo']);                                                          // o art. 121-A
    $antigo   = $novo('Art. T0', null);                                                                      // cadastro sem peça marcada

    echo "A regra (Artigo::serveA)\n";
    $serve = fn (Artigo $a) => implode(',', array_keys(array_filter([
        'N' => $a->serveA('notificacao'), 'NE' => $a->serveA('notificacao_embargo'),
        'AE' => $a->serveA('auto_embargo'), 'AI' => $a->serveA('auto_infracao')])));
    confere($serve($comum) === 'N,AI', 'artigo comum: notificação e auto de infração');
    confere($serve($notifica) === 'N,NE', 'artigo de notificação: as duas notificações, nenhum auto');
    confere($serve($embarga) === 'AE', 'artigo do auto de embargo: só ele');
    confere($serve($antigo) === 'N,NE,AE,AI', 'artigo sem peça marcada serve a todas');
    confere($notifica->rotuloDocumentos() === 'NOT · NE' && $embarga->rotuloEmbargo() === 'cabe embargo' && $comum->rotuloEmbargo() === null,
        'os rótulos dizem em quais peças entra');

    echo "Parâmetros: gravar o artigo\n";
    $base = ['legislacao_id' => $lei->id, 'numero' => 'Art. T4', 'base_multa' => 'sem_multa', 'termos' => ['teste']];
    [$s] = chamar($admin, LegislacaoController::class, 'salvarArtigo', $base);
    confere($s === 422, 'artigo sem nenhum documento marcado é recusado');
    [$s] = chamar($admin, LegislacaoController::class, 'salvarArtigo', $base + ['documentos' => ['vistoria']]);
    confere($s === 422, 'documento que não é peça de sanção é recusado');
    [$s, $d] = chamar($admin, LegislacaoController::class, 'salvarArtigo', $base + ['documentos' => ['auto_infracao', 'auto_embargo'], 'prazo_notificacao_dias' => 9]);
    $t4 = Artigo::find($d['id'] ?? 0);
    confere($s === 200 && $t4 && $t4->documentos === ['auto_embargo', 'auto_infracao'] && $t4->prazo_notificacao_dias === null,
        'grava na ordem das peças, e sem prazo de notificação quando não entra em notificação');
    [$s] = chamar($admin, LegislacaoController::class, 'salvarArtigo', ['id' => $t4->id, 'documentos' => ['notificacao'], 'prazo_notificacao_dias' => 5] + $base);
    confere($s === 200 && $t4->fresh()->documentos === ['notificacao'] && (int) $t4->fresh()->prazo_notificacao_dias === 5, 'artigo de notificação guarda o prazo sugerido');
    [, $d] = chamar($admin, LegislacaoController::class, 'index');
    $naLista = collect(collect($d['leis'])->firstWhere('id', $lei->id)['artigos'])->firstWhere('id', $notifica->id);
    confere($naLista['documentos'] === ['notificacao', 'notificacao_embargo'] && $naLista['documentos_rotulo'] === 'NOT · NE'
        && $naLista['prazo_notificacao_dias'] === 5, 'a lista de Parâmetros devolve a configuração');

    echo "Formulário: opções e gravação\n";
    [, $d] = chamar($admin, DocumentoController::class, 'opcoes');
    $op = collect(collect($d['leis'])->firstWhere('id', $lei->id)['artigos']);
    confere($op->firstWhere('id', $embarga->id)['documentos'] === ['auto_embargo']
        && count($op->firstWhere('id', $antigo->id)['documentos']) === 4, 'as opções do formulário trazem as peças de cada artigo');

    $peca = fn (string $tipo, array $artigos) => chamar($admin, DocumentoController::class, 'store',
        ['tipo' => $tipo, 'legislacao_id' => $lei->id, 'artigos' => $artigos, 'prazo_dias' => 5], [$lote]);
    [$s, $d] = $peca('auto_embargo', [$notifica->id]);
    confere($s === 422 && str_contains($d['message'], 'Art. T2 não se aplica a Auto de Embargo'), 'Auto de Embargo com artigo só de notificação é recusado, dizendo qual');
    [$s, $d] = $peca('notificacao_embargo', [$comum->id, $notifica->id]);
    confere($s === 422 && str_contains($d['message'], 'Art. T1') && ! str_contains($d['message'], 'Art. T2'), 'a recusa aponta só o artigo que não serve');
    [$s] = $peca('auto_infracao', [$embarga->id]);
    confere($s === 422, 'Auto de Infração com artigo do auto de embargo é recusado');
    [$s] = $peca('notificacao', [$comum->id, $notifica->id]);
    confere($s === 201, 'Notificação aceita os artigos marcados para ela');
    [$s, $d] = $peca('notificacao_embargo', [$notifica->id]);
    confere($s === 201 && $d['avisos'] === [], 'Notificação de Embargo aceita o artigo de notificação, sem aviso');

    echo "Auto de Embargo: aviso de notificar antes (não bloqueia)\n";
    DB::table('documentos')->where('lote_id', $lote->id)->where('tipo', 'notificacao_embargo')->where('status', '!=', 'rascunho')->update(['status' => 'anulado']);
    [$s, $d] = $peca('auto_embargo', [$embarga->id]);
    $autoId = $d['documento']['id'] ?? null;
    confere($s === 201 && count($d['avisos']) === 1 && str_contains($d['avisos'][0], 'Notificação de Embargo'),
        'sem Notificação de Embargo vencida no imóvel: grava, com aviso');
    DB::table('documentos')->insert(['tipo' => 'notificacao_embargo', 'lote_id' => $lote->id, 'agente_id' => $admin->id,
        'status' => 'lavrado', 'numero' => 99991, 'exercicio' => 2099, 'data_fato' => now()->subDays(10),
        'data_lavratura' => now()->subDays(10), 'prazo_dias' => 5, 'prazo_ate' => now()->subDays(5)->toDateString(),
        'created_at' => now(), 'updated_at' => now()]);
    confere(app(LavraturaService::class)->avisosDeEmbargo(Documento::find($autoId)) === [], 'com a notificação vencida no imóvel, o aviso some');

    echo "A última barreira: a lavratura\n";
    // Rascunho gravado certo, e o artigo é reconfigurado depois.
    $rascunho = Documento::find($autoId);
    $embarga->update(['documentos' => ['notificacao']]);
    try {
        app(LavraturaService::class)->lavrar($rascunho);
        confere(false, 'lavrar com artigo que deixou de servir à peça — não recusou');
    } catch (RuntimeException $e) {
        confere(str_contains($e->getMessage(), 'Art. T3 não se aplica a Auto de Embargo'), 'lavrar recusa o artigo que deixou de servir à peça');
    }
    $embarga->update(['documentos' => ['auto_embargo']]);

    echo "Sugestão da vistoria filtra pelo tipo\n";
    $vistoria = App\Models\Vistoria::where('lote_id', $lote->id)->first() ?? App\Models\Vistoria::first();
    if ($vistoria) {
        $item = DB::table('vistoria_itens')->where('vistoria_id', $vistoria->id)->value('id');
        foreach ([$comum, $notifica, $embarga] as $i => $a) {
            DB::table('vistoria_artigos')->insert(['vistoria_id' => $vistoria->id, 'item_id' => $item, 'artigo_id' => $a->id,
                'tipo' => 'citacao', 'ordem' => 900 + $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        $nossos = [$comum->id, $notifica->id, $embarga->id];
        $ids = fn (?string $tipo) => app(LavraturaService::class)->artigosSugeridos($vistoria->id, $tipo)->pluck('id')->intersect($nossos)->values()->all();
        confere($ids(null) === $nossos, 'sem tipo, vêm todos os citados');
        confere($ids('auto_embargo') === [$embarga->id], 'para Auto de Embargo, só o artigo dele');
        confere($ids('notificacao') === [$comum->id, $notifica->id], 'para Notificação, os dois marcados para ela');
    } else {
        echo "  --  sem vistoria no banco local: sugestão não conferida\n";
    }

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
