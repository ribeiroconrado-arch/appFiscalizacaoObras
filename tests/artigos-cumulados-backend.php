<?php
// Diagnóstico local explícito: tudo dentro de uma transação DESFEITA no fim.
// Exige a migração 2026_11_12_000100 e um administrador agente ativo.
//
//   php tests/artigos-cumulados-backend.php
//
// Cobre o c/c (cumulado com): o vínculo em Parâmetros (só artigo de outra lei
// do mesmo módulo), as opções do formulário, a regra "uma lei por documento —
// outra lei só pelo c/c cadastrado", a cópia na peça (lei de cada artigo, o c/c
// logo depois do dele) e o cabeçalho da impressão ("… c/c …").

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\LegislacaoController;
use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\Lote;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}
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
    $novaLei = fn (string $numero, string $nome) => Legislacao::create(['numero' => $numero, 'nome' => $nome, 'ano' => 2099,
        'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true]);
    $obras = $novaLei('TESTE LC 1/2099', 'Código de teste');
    $outra = $novaLei('TESTE L 500/2099', 'Posturas de teste');
    $terceira = $novaLei('TESTE L 9/2099', 'Lei sem vínculo');
    $art = fn (Legislacao $l, string $n) => Artigo::create(['legislacao_id' => $l->id, 'numero' => $n, 'apelido' => $n,
        'conduta' => "conduta {$n}", 'base_multa' => 'sem_multa', 'ativo' => true, 'documentos' => ['notificacao', 'auto_infracao']]);
    $a42 = $art($obras, 'Art. 42');
    $a43 = $art($obras, 'Art. 43');
    $a10 = $art($outra, 'Art. 10');
    $a11 = $art($outra, 'Art. 11');
    $a90 = $art($terceira, 'Art. 90');

    echo "Parâmetros: o vínculo c/c\n";
    $base = ['legislacao_id' => $obras->id, 'numero' => 'Art. 42', 'base_multa' => 'sem_multa', 'documentos' => ['notificacao', 'auto_infracao'], 'termos' => ['x']];
    [$s] = chamar($admin, LegislacaoController::class, 'salvarArtigo', ['id' => $a42->id, 'cumulados' => [['id' => $a43->id]]] + $base);
    confere($s === 422, 'c/c com artigo da MESMA lei é recusado');
    [$s] = chamar($admin, LegislacaoController::class, 'salvarArtigo', ['id' => $a42->id, 'cumulados' => [['id' => $a10->id, 'obrigatorio' => false], ['id' => $a11->id, 'obrigatorio' => true]]] + $base);
    $pivo = $a42->fresh()->cumulados->keyBy('id');
    confere($s === 200 && $pivo->count() === 2 && ! $pivo[$a10->id]->pivot->obrigatorio && $pivo[$a11->id]->pivot->obrigatorio,
        'grava os dois c/c, um perguntado e um obrigatório');
    [, $d] = chamar($admin, LegislacaoController::class, 'index');
    $naLista = collect(collect($d['leis'])->firstWhere('id', $obras->id)['artigos'])->firstWhere('id', $a42->id);
    confere(count($naLista['cumulados']) === 2 && str_contains($naLista['cumulados'][0]['lei'], 'TESTE L 500/2099'), 'a lista de Parâmetros traz os c/c com a lei');
    [$s] = chamar($admin, LegislacaoController::class, 'salvarArtigo', ['id' => $a43->id, 'numero' => 'Art. 43'] + $base);
    confere($s === 200 && $a42->fresh()->cumulados->count() === 2, 'gravar outro artigo sem mandar c/c não mexe nos vínculos');

    echo "Formulário: as opções trazem o c/c\n";
    [, $d] = chamar($admin, DocumentoController::class, 'opcoes');
    $op = collect(collect($d['leis'])->firstWhere('id', $obras->id)['artigos'])->firstWhere('id', $a42->id);
    confere(collect($op['cumulados'])->pluck('id')->sort()->values()->all() === collect([$a10->id, $a11->id])->sort()->values()->all(),
        'cada artigo diz os c/c dele');

    echo "Uma lei por documento; outra só pelo c/c\n";
    $peca = fn (array $artigos, ?int $lei) => chamar($admin, DocumentoController::class, 'store',
        ['tipo' => 'notificacao', 'legislacao_id' => $lei, 'artigos' => $artigos, 'prazo_dias' => 5], [$lote]);
    [$s, $d] = $peca([$a42->id, $a90->id], $obras->id);
    confere($s === 422 && str_contains($d['message'], 'Art. 90'), 'artigo de outra lei SEM vínculo é recusado, dizendo qual');
    [$s] = $peca([$a43->id, $a10->id], $obras->id);
    confere($s === 422, 'o c/c só vale junto do artigo que o tem (Art. 10 sem o Art. 42 é recusado)');
    [$s] = $peca([$a42->id, $a90->id], null);
    confere($s === 422, 'sem lei escolhida, artigos de leis diferentes são recusados');
    [$s, $d] = $peca([$a10->id, $a42->id, $a43->id], $obras->id);
    confere($s === 201, 'artigo da lei + o c/c cadastrado são aceitos');

    echo "A cópia na peça e a impressão\n";
    $doc = Documento::find($d['documento']['id']);
    $copias = $doc->artigos()->orderBy('id')->get();
    confere($copias->pluck('numero')->all() === ['Art. 42', 'Art. 10', 'Art. 43'], 'o c/c fica logo depois do artigo dele');
    $c10 = $copias->firstWhere('numero', 'Art. 10');
    confere((int) $c10->cumulado_de_artigo_id === $a42->id && str_contains($c10->legislacao_rotulo, 'TESTE L 500/2099')
        && $copias->firstWhere('numero', 'Art. 42')->cumulado_de_artigo_id === null, 'cada cópia guarda a lei e de quem é c/c');
    $mem = (new ReflectionMethod(App\Services\DocumentoImpressao::class, 'memoria'));
    $mem->setAccessible(true);
    $m = $mem->invoke(app(App\Services\DocumentoImpressao::class), $doc->fresh(['artigos', 'legislacao']));
    confere($m['lei'] === 'TESTE LC 1/2099 — Código de teste c/c TESTE L 500/2099 — Posturas de teste', 'o cabeçalho junta as leis com "c/c"');
    confere($m['linhas'][1]['cc'] === true && $m['linhas'][0]['cc'] === false, 'a linha do c/c sai marcada');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
