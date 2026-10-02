<?php
// Diagnóstico local, no molde de importacao-backend.php: roda numa transação
// DESFEITA no fim. Cobre quem vê, o aviso de duplicada, o "Resolvido" e a
// resolução automática pela vistoria, com o lembrete de voltar.
//
//   php tests/sinalizacao-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SinalizacaoController;
use App\Models\Sinalizacao;
use App\Models\User;
use App\Models\Vistoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }
/**
 * GET pela pilha HTTP inteira; POST direto no controlador — o POST pelo kernel
 * esbarra no token CSRF, que não existe sem sessão de navegador.
 */
function chama(User $u, string $metodo, string $url, array $corpo = []): array {
    global $kernel, $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create($url, $metodo, $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    if ($metodo === 'GET') {
        $r = $kernel->handle($req);
    } else {
        $c = new SinalizacaoController();
        try {
            $r = preg_match('#/lotes/(\d+)/sinalizacoes$#', $url, $m)
                ? $c->store($req, App\Models\Lote::findOrFail($m[1]))
                : $c->resolver($req, Sinalizacao::findOrFail(preg_replace('#\D+(\d+)\D*#', '$1', $url)));
        } catch (Illuminate\Validation\ValidationException $e) {
            return [422, ['errors' => $e->errors()]];
        }
    }
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->firstOrFail();
$lote = DB::table('lotes')->where('situacao', 'ativo')->where('em_revisao', false)->first();

DB::beginTransaction();
try {
    $agente = User::create(['name' => 'Agente T', 'email' => 'ag.t@exemplo.test', 'password' => 'x-teste-x-12345',
        'perfil' => 'comum', 'tipo_usuario' => 'agente', 'ativo' => true]);
    $coord = User::create(['name' => 'Coord T', 'email' => 'co.t@exemplo.test', 'password' => 'x-teste-x-12345',
        'perfil' => 'viewer', 'tipo_usuario' => 'coordenador', 'ativo' => true]);
    $contrib = User::create(['name' => 'Contrib T', 'email' => 'ct.t@exemplo.test', 'password' => 'x-teste-x-12345',
        'perfil' => 'viewer', 'tipo_usuario' => 'contribuinte', 'ativo' => true]);

    echo "Criar e duplicada\n";
    [$s, $d] = chama($contrib, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'obra_sem_placa', 'comentario' => 'sem placa']);
    confere($s === 201, 'contribuinte sinaliza' . ($s === 201 ? '' : " (HTTP {$s}: " . json_encode($d) . ')'));
    [$s, $d] = chama($coord, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'obra_sem_placa']);
    confere($s === 409 && ! empty($d['duplicada']), 'a mesma sinalização pendente é avisada antes (' . ($d['message'] ?? '') . ')');
    [$s, $d] = chama($coord, 'GET', "/api/lotes/{$lote->id}/sinalizacoes");
    confere(collect($d['ja_existem'])->contains('tipo', 'obra_sem_placa') && $d['pendentes'] === [],
        'o coordenador sabe que já existe, sem ver a sinalização do outro');
    [$s, $d] = chama($coord, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'obra_sem_placa', 'confirmar_duplicada' => true]);
    confere($s === 201, 'confirmando, envia mesmo assim');
    [$s, $d] = chama($coord, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'entulho']);
    confere($s === 201, 'outro tipo no mesmo lote entra direto');

    echo "Quem vê\n";
    $n = fn (User $u) => Sinalizacao::query()->pendentes()->visiveisPara($u)->where('lote_id', $lote->id)->count();
    confere($n($agente) === 3 && $n($admin) === 3, 'agente e administrador veem as 3');
    confere($n($coord) === 2, 'coordenador vê só as 2 dele');
    confere($n($contrib) === 1, 'contribuinte vê só a dele');

    echo "Resolver\n";
    $doContrib = Sinalizacao::where('user_id', $contrib->id)->value('id');
    [$s] = chama($coord, 'POST', "/api/sinalizacoes/{$doContrib}/resolver", ['resolucao' => 'feito']);
    confere($s === 403, 'coordenador não resolve a do contribuinte');
    $doCoord = Sinalizacao::where('user_id', $coord->id)->where('tipo', 'entulho')->value('id');
    [$s] = chama($coord, 'POST', "/api/sinalizacoes/{$doCoord}/resolver", ['resolucao' => 'já retirado']);
    confere($s === 200, 'coordenador resolve a dele');

    echo "Vistoria resolve e deixa lembrete\n";
    Auth::guard('web')->setUser($agente);
    $v = Vistoria::create(['lote_id' => $lote->id, 'fiscal_id' => $agente->id, 'exercicio' => 2026, 'numero' => 99990,
        'data_hora' => now(), 'situacao' => 'regular', 'finalidade' => 'obras']);
    SinalizacaoController::aoRegistrarVistoria($v, null, now()->addDays(30)->toDateString(), 'conferir placa');
    confere(Sinalizacao::where('lote_id', $lote->id)->where('tipo', '<>', 'lembrete')->where('status', 'aberta')->count() === 0,
        'a vistoria resolveu as pendentes do lote');
    confere(Sinalizacao::where('lote_id', $lote->id)->where('tipo', 'lembrete')->whereDate('lembrar_em', now()->addDays(30))->exists(),
        'e deixou o lembrete de voltar em 30 dias');
    confere(Sinalizacao::query()->pendentes()->where('lote_id', $lote->id)->count() === 0, 'lembrete futuro ainda não pede nada');
    [$s, $d] = chama($agente, 'GET', "/api/lotes/{$lote->id}/historico");
    confere(collect($d['eventos'])->where('tipo', 'sinalizacao')->count() >= 3, 'as resolvidas ficam no Histórico do imóvel');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
