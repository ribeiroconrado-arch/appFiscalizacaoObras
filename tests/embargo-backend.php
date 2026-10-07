<?php
// Diagnóstico local explícito, no molde de pesquisa-mapa-backend.php: tudo roda
// dentro de uma transação que é DESFEITA no fim. Exige a migração
// 2026_10_14_000100 aplicada e um administrador agente ativo.
//
//   php tests/embargo-backend.php
//
// Cobre a parametrização de embargo por artigo: a regra Artigo::serveA nos
// três níveis × quatro peças, a recusa ao gravar e ao lavrar, o filtro da
// sugestão da vistoria, as opções do formulário e o aviso (que não bloqueia)
// do Auto de Embargo por artigo que só embarga após prazo.

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
    $lei = Legislacao::create(['numero' => 'TESTE EMB 1/2099', 'nome' => 'Lei de teste do embargo', 'ano' => 2099,
        'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true]);
    $novo = fn (string $numero, array $extra = []) => Artigo::create(['legislacao_id' => $lei->id, 'numero' => $numero,
        'apelido' => $numero, 'conduta' => 'conduta de teste', 'base_multa' => 'sem_multa', 'ativo' => true] + $extra);
    $comum = $novo('Art. T1');                                                                        // não cabe embargo
    $cabe  = $novo('Art. T2', ['embargo' => 'cabe', 'embargo_modo' => 'apos_prazo', 'embargo_prazo_dias' => 5]);
    $so    = $novo('Art. T3', ['embargo' => 'exclusivo', 'embargo_modo' => 'imediato']);
    $comum->refresh();

    echo "A regra (Artigo::serveA)\n";
    $serve = fn (Artigo $a) => implode(',', array_keys(array_filter([
        'N' => $a->serveA('notificacao'), 'NE' => $a->serveA('notificacao_embargo'),
        'AE' => $a->serveA('auto_embargo'), 'AI' => $a->serveA('auto_infracao')])));
    confere($serve($comum) === 'N,AI', 'artigo sem embargo: só notificação e auto de infração');
    confere($serve($cabe) === 'N,NE,AE,AI', 'artigo que cabe embargo: as quatro peças');
    confere($serve($so) === 'NE,AE', 'artigo exclusivo de embargo: só as duas peças de embargo');
    confere($cabe->rotuloEmbargo() === 'embarga após 5 dia(s)' && $so->rotuloEmbargo() === 'embarga de imediato' && $comum->rotuloEmbargo() === null,
        'o rótulo diz quando embarga');

    echo "Parâmetros: gravar o artigo\n";
    $base = ['legislacao_id' => $lei->id, 'numero' => 'Art. T4', 'base_multa' => 'sem_multa', 'termos' => ['teste']];
    [$s] = chamar($admin, LegislacaoController::class, 'salvarArtigo', $base + ['embargo' => 'cabe']);
    confere($s === 422, 'embargo sem dizer imediato ou após prazo é recusado');
    [$s] = chamar($admin, LegislacaoController::class, 'salvarArtigo', $base + ['embargo' => 'cabe', 'embargo_modo' => 'apos_prazo']);
    confere($s === 422, '"após prazo" sem o número de dias é recusado');
    [$s, $d] = chamar($admin, LegislacaoController::class, 'salvarArtigo', $base + ['embargo' => 'exclusivo', 'embargo_modo' => 'imediato', 'embargo_prazo_dias' => 9]);
    $t4 = Artigo::find($d['id'] ?? 0);
    confere($s === 200 && $t4 && $t4->embargo === 'exclusivo' && $t4->embargo_prazo_dias === null, 'embargo imediato grava sem prazo');
    [$s, $d] = chamar($admin, LegislacaoController::class, 'salvarArtigo', ['id' => $t4->id, 'embargo' => 'nao', 'embargo_modo' => 'imediato'] + $base);
    confere($s === 200 && $t4->fresh()->embargo === 'nao' && $t4->fresh()->embargo_modo === null, 'voltar para "não cabe" limpa o modo');
    [, $d] = chamar($admin, LegislacaoController::class, 'index');
    $naLista = collect(collect($d['leis'])->firstWhere('id', $lei->id)['artigos'])->firstWhere('id', $cabe->id);
    confere($naLista['embargo'] === 'cabe' && $naLista['embargo_modo'] === 'apos_prazo' && $naLista['embargo_prazo_dias'] === 5
        && $naLista['embargo_rotulo'] === 'embarga após 5 dia(s)', 'a lista de Parâmetros devolve a configuração');

    echo "Formulário: opções e gravação\n";
    [, $d] = chamar($admin, DocumentoController::class, 'opcoes');
    $op = collect(collect($d['leis'])->firstWhere('id', $lei->id)['artigos'])->firstWhere('id', $so->id);
    confere($op['embargo'] === 'exclusivo' && $op['embargo_modo'] === 'imediato' && $op['embargo_rotulo'] === 'embarga de imediato',
        'as opções do formulário trazem o embargo de cada artigo');

    $peca = fn (string $tipo, array $artigos) => chamar($admin, DocumentoController::class, 'store',
        ['tipo' => $tipo, 'legislacao_id' => $lei->id, 'artigos' => $artigos, 'prazo_dias' => 5], [$lote]);
    [$s, $d] = $peca('auto_embargo', [$comum->id]);
    confere($s === 422 && str_contains($d['message'], 'Não cabe embargo por Art. T1'), 'Auto de Embargo com artigo sem embargo é recusado, dizendo qual');
    [$s, $d] = $peca('notificacao_embargo', [$comum->id, $cabe->id]);
    confere($s === 422 && str_contains($d['message'], 'Art. T1') && ! str_contains($d['message'], 'Art. T2'), 'a recusa aponta só o artigo que não serve');
    [$s, $d] = $peca('notificacao', [$so->id]);
    confere($s === 422 && str_contains($d['message'], 'exclusivo de embargo'), 'Notificação comum com artigo exclusivo de embargo é recusada');
    [$s] = $peca('auto_infracao', [$so->id]);
    confere($s === 422, 'Auto de Infração com artigo exclusivo de embargo é recusado');
    [$s] = $peca('notificacao', [$comum->id, $cabe->id]);
    confere($s === 201, 'Notificação aceita o artigo comum e o que cabe embargo');
    [$s, $d] = $peca('notificacao_embargo', [$cabe->id, $so->id]);
    confere($s === 201 && $d['avisos'] === [], 'Notificação de Embargo aceita cabe + exclusivo, sem aviso');

    echo "Auto de Embargo: aviso do prazo (não bloqueia)\n";
    [$s, $d] = $peca('auto_embargo', [$so->id]);
    confere($s === 201 && $d['avisos'] === [], 'embargo imediato: sem aviso');
    [$s, $d] = $peca('auto_embargo', [$cabe->id]);
    $autoId = $d['documento']['id'] ?? null;
    confere($s === 201 && count($d['avisos']) === 1 && str_contains($d['avisos'][0], 'Art. T2 (5 dias)'),
        'artigo "após prazo" sem Notificação de Embargo vencida: grava, com aviso');
    // Uma Notificação de Embargo lavrada, com o prazo vencido, para o mesmo imóvel.
    DB::table('documentos')->insert(['tipo' => 'notificacao_embargo', 'lote_id' => $lote->id, 'agente_id' => $admin->id,
        'status' => 'lavrado', 'numero' => 99991, 'exercicio' => 2099, 'data_fato' => now()->subDays(10),
        'data_lavratura' => now()->subDays(10), 'prazo_dias' => 5, 'prazo_ate' => now()->subDays(5)->toDateString(),
        'created_at' => now(), 'updated_at' => now()]);
    confere(app(LavraturaService::class)->avisosDeEmbargo(Documento::find($autoId)) === [], 'com a notificação vencida no imóvel, o aviso some');

    echo "A última barreira: a lavratura\n";
    // Rascunho gravado certo, e o artigo é reconfigurado depois.
    [, $d] = $peca('auto_embargo', [$so->id]);
    $rascunho = Documento::find($d['documento']['id']);
    $so->update(['embargo' => 'nao', 'embargo_modo' => null]);
    try {
        app(LavraturaService::class)->lavrar($rascunho);
        confere(false, 'lavrar com artigo que deixou de caber embargo — não recusou');
    } catch (RuntimeException $e) {
        confere(str_contains($e->getMessage(), 'Não cabe embargo por Art. T3'), 'lavrar recusa o artigo que deixou de caber embargo');
    }
    $so->update(['embargo' => 'exclusivo', 'embargo_modo' => 'imediato']);

    echo "Sugestão da vistoria filtra pelo tipo\n";
    $vistoria = App\Models\Vistoria::where('lote_id', $lote->id)->first() ?? App\Models\Vistoria::first();
    if ($vistoria) {
        $item = DB::table('vistoria_itens')->where('vistoria_id', $vistoria->id)->value('id');
        foreach ([$comum, $cabe, $so] as $i => $a) {
            DB::table('vistoria_artigos')->insert(['vistoria_id' => $vistoria->id, 'item_id' => $item, 'artigo_id' => $a->id,
                'tipo' => 'citacao', 'ordem' => 900 + $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        $nossos = [$comum->id, $cabe->id, $so->id];
        $ids = fn (?string $tipo) => app(LavraturaService::class)->artigosSugeridos($vistoria->id, $tipo)->pluck('id')->intersect($nossos)->values()->all();
        confere($ids(null) === $nossos, 'sem tipo, vêm todos os citados');
        confere($ids('auto_embargo') === [$cabe->id, $so->id], 'para Auto de Embargo, só os que embargam');
        confere($ids('auto_infracao') === [$comum->id, $cabe->id], 'para Auto de Infração, sem o exclusivo de embargo');
    } else {
        echo "  --  sem vistoria no banco local: sugestão não conferida\n";
    }

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
