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
    if ($metodo === 'GET' && preg_match('#/lotes/(\d+)/sinalizacoes$#', $url, $m)) {
        // Direto no controlador: pelo kernel, só o PRIMEIRO pedido do script
        // reconhece o usuário trocado — os seguintes voltavam "Unauthenticated".
        $r = (new SinalizacaoController())->doLote($req, App\Models\Lote::findOrFail($m[1]));
    } elseif ($metodo === 'GET') {
        $r = $kernel->handle($req);
    } else {
        $c = new SinalizacaoController();
        try {
            $r = match (true) {
                (bool) preg_match('#/lotes/(\d+)/sinalizacoes$#', $url, $m) => $c->store($req, App\Models\Lote::findOrFail($m[1])),
                $url === '/api/sinalizacoes' => $c->storePonto($req, app(App\Repositories\LoteRepository::class)),
                default => $c->resolver($req, Sinalizacao::findOrFail(preg_replace('#\D+(\d+)\D*#', '$1', $url))),
            };
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
    [$s, $d] = chama($contrib, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'obra_iniciada', 'comentario' => 'fundação aberta']);
    confere($s === 201, 'contribuinte sinaliza' . ($s === 201 ? '' : " (HTTP {$s}: " . json_encode($d) . ')'));
    $nova = Sinalizacao::find($d['id']);
    confere($nova->modulo === 'obras' && $nova->lat !== null && $nova->lng !== null, 'nasce em Obras e com o ponto (o meio do lote)');
    [$s, $d] = chama($coord, 'GET', "/api/lotes/{$lote->id}/sinalizacoes");
    confere(collect($d['ja_existem'])->contains('tipo', 'obra_iniciada') && $d['pendentes'] === [],
        'o coordenador sabe que já existe, sem ver a sinalização do outro');
    confere(count($d['tipos']) === 6 && collect($d['tipos'])->pluck('valor')->all() === App\Models\Sinalizacao::POR_MODULO['obras'],
        'o balão de Obras oferece os seis tipos de Obras');
    [$s] = chama($coord, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'veiculo_abandonado']);
    confere($s === 422, 'tipo de Posturas é recusado em Obras');

    echo "O mesmo tipo é SOMADO, e não duplicado\n";
    $bandeiras = fn () => Sinalizacao::where('lote_id', $lote->id)->where('tipo', 'obra_iniciada')->where('status', 'aberta')->count();
    [$s, $d] = chama($coord, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'obra_iniciada', 'comentario' => 'vi também']);
    confere($s === 200 && ! empty($d['somada']) && $d['avisos'] === 2 && $bandeiras() === 1,
        'o aviso do coordenador é somado: continua uma bandeira, agora com 2 avisos (' . ($d['message'] ?? '') . ')');
    [$s, $d] = chama($coord, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'obra_iniciada']);
    confere($s === 200 && $d['avisos'] === 2 && str_contains($d['message'], 'já sinalizou'), 'a mesma pessoa de novo, sem comentário, não conta duas vezes');
    [$s, $d] = chama($contrib, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'obra_iniciada', 'comentario' => 'continua']);
    confere($s === 200 && $d['avisos'] === 2 && $bandeiras() === 1, 'quem abriu pode acrescentar um comentário, sem virar outro aviso');
    [$s, $d] = chama($agente, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'obra_iniciada']);
    confere($s === 200 && $d['avisos'] === 3, 'um terceiro soma: 3 avisos');
    [$s, $d] = chama($coord, 'POST', "/api/lotes/{$lote->id}/sinalizacoes", ['tipo' => 'entulho']);
    confere($s === 201, 'outro tipo no mesmo lote abre a própria bandeira');

    echo "Quem vê\n";
    $n = fn (User $u) => Sinalizacao::query()->pendentes()->visiveisPara($u)->where('lote_id', $lote->id)->count();
    confere($n($agente) === 2 && $n($admin) === 2, 'agente e administrador veem as 2 bandeiras');
    confere($n($coord) === 2, 'coordenador vê a dele e a que ele reforçou');
    confere($n($contrib) === 1, 'contribuinte vê só a dele');
    [, $d] = chama($agente, 'GET', "/api/lotes/{$lote->id}/sinalizacoes");
    $placa = collect($d['pendentes'])->firstWhere('tipo', 'obra_iniciada');
    confere($placa['avisos'] === 3 && count($placa['reforcos']) === 3 && $placa['autor'] === 'Contrib T',
        'a fiscalização vê quantos avisaram, quem abriu e cada aviso somado');
    [, $d] = chama($coord, 'GET', "/api/lotes/{$lote->id}/sinalizacoes");
    $placa = collect($d['pendentes'])->firstWhere('tipo', 'obra_iniciada');
    confere($placa['avisos'] === 3 && $placa['autor'] === 'outra pessoa' && $placa['comentario'] === null
        && count($placa['reforcos']) === 1 && $placa['reforcos'][0]['comentario'] === 'vi também',
        'quem só reforçou vê a contagem e o próprio aviso — não o nome nem o comentário dos outros');

    echo "Resolver\n";
    $doContrib = Sinalizacao::where('user_id', $contrib->id)->value('id');
    [$s] = chama($coord, 'POST', "/api/sinalizacoes/{$doContrib}/resolver", ['resolucao' => 'feito']);
    confere($s === 403, 'coordenador não resolve a do contribuinte');
    $doCoord = Sinalizacao::where('user_id', $coord->id)->where('tipo', 'entulho')->value('id');
    [$s] = chama($coord, 'POST', "/api/sinalizacoes/{$doCoord}/resolver", ['resolucao' => 'já retirado']);
    confere($s === 200, 'coordenador resolve a dele');

    echo "No ponto: dentro do lote é do imóvel; na via, só o ponto\n";
    $repo = app(App\Repositories\LoteRepository::class);
    $anel = json_decode(DB::selectOne('SELECT ST_AsGeoJSON(geom) g FROM lotes WHERE id = ?', [$lote->id])->g, true)['coordinates'][0];
    array_pop($anel);
    $meio = ['lat' => array_sum(array_column($anel, 1)) / count($anel), 'lng' => array_sum(array_column($anel, 0)) / count($anel)];
    [$s, $d] = chama($agente, 'POST', '/api/sinalizacoes', ['tipo' => 'risco', 'lat' => $meio['lat'], 'lng' => $meio['lng']]);
    $dentro = $repo->contendo($meio['lat'], $meio['lng']);
    confere($s === 201 && $d['na_rua'] === false && Sinalizacao::find($d['id'])->lote_id === $dentro?->id,
        'ponto dentro de um lote liga a sinalização ao imóvel');

    // Um ponto da via: anda a partir do lote até sair de qualquer lote.
    $rua = null;
    foreach ([12, 18, 25, 35, 50] as $m) {
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [-1, -1], [1, -1], [-1, 1]] as [$a, $b]) {
            $p = ['lat' => $meio['lat'] + $a * $m / 111320, 'lng' => $meio['lng'] + $b * $m / (111320 * cos(deg2rad($meio['lat'])))];
            if (! $repo->contendo($p['lat'], $p['lng'])) { $rua = $p; $dir = [$a, $b]; break 2; }
        }
    }
    confere($rua !== null, 'achou um ponto na via perto do lote');
    [$s, $d] = chama($agente, 'POST', '/api/sinalizacoes', ['tipo' => 'outro', 'comentario' => 'carro parado', 'lat' => $rua['lat'], 'lng' => $rua['lng']]);
    $naRua = Sinalizacao::find($d['id'] ?? 0);
    confere($s === 201 && $d['na_rua'] === true && $naRua->lote_id === null && str_starts_with((string) $naRua->referencia, 'Próximo a'),
        'na via fica só o ponto, com referência "' . ($naRua->referencia ?? '') . '"');
    // 5 m adiante, na mesma direção — ainda na via (se cair num lote, o próprio ponto).
    $perto = ['lat' => $rua['lat'] + $dir[0] * 5 / 111320, 'lng' => $rua['lng'] + $dir[1] * 5 / (111320 * cos(deg2rad($rua['lat'])))];
    if ($repo->contendo($perto['lat'], $perto['lng'])) { $perto = $rua; }
    [$s, $d] = chama($coord, 'POST', '/api/sinalizacoes', ['tipo' => 'outro', 'lat' => $perto['lat'], 'lng' => $perto['lng']]);
    confere($s === 200 && ! empty($d['somada']) && $d['id'] === $naRua->id, 'a mesma coisa marcada a poucos metros é somada à que existe'
        . ($s === 200 ? '' : " (HTTP {$s}: " . json_encode($d) . ')'));
    $longe = Sinalizacao::where('id', '<>', $naRua->id)->whereNull('lote_id')->count();
    confere($longe === 0, 'não abriu uma segunda bandeira na via');
    [$s, $d] = chama($agente, 'GET', '/api/sinalizacoes?bbox=' . implode(',', [$rua['lng'] - .001, $rua['lat'] - .001, $rua['lng'] + .001, $rua['lat'] + .001]));
    $item = collect($d['sinalizacoes'])->firstWhere('id', $naRua->id);
    confere($item && $item['na_rua'] && $item['lote_id'] === null && abs($item['lat'] - $rua['lat']) < 1e-6, 'aparece no mapa no ponto marcado');

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
    confere(collect($d['eventos'])->where('tipo', 'sinalizacao')->count() >= 2, 'as resolvidas ficam no Histórico do imóvel');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
