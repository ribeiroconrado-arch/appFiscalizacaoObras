<?php
// Diagnóstico local do contorno dos bairros, no molde de importacao-backend.php:
// tudo numa transação DESFEITA no fim. Exige a migração 2026_10_01_000100.
//
//   php tests/contorno-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\BairroContornoController;
use App\Models\User;
use App\Repositories\LoteRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) throw new RuntimeException('FALHOU: ' . $o); $ok++; echo "  ok  {$o}\n"; }

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->firstOrFail();
$bairro = DB::table('lotes')->where('situacao', 'ativo')->where('em_revisao', false)->value('bairro');
if (! $bairro) throw new RuntimeException('Sem lote publicado na base local.');

$ctl = app(BairroContornoController::class);
$chama = function (string $metodo, User $u, array $corpo = []) use ($ctl) {
    Auth::guard('web')->setUser($u);
    $r = Request::create('/x', $metodo === 'gravar' ? 'POST' : 'GET', $corpo);
    $r->setUserResolver(fn () => $u);
    try { return $ctl->{$metodo}($r); }
    catch (Illuminate\Validation\ValidationException $e) { return response()->json($e->errors(), 422); }
};
$quadrado = fn ($o, $s, $l, $n) => ['type' => 'MultiPolygon', 'coordinates' => [[[[$o, $s], [$l, $s], [$l, $n], [$o, $n], [$o, $s]]]]];

DB::beginTransaction();
try {
    $total = DB::table('lotes')->where('bairro', $bairro)->where('situacao', 'ativo')->count();
    $e = app(LoteRepository::class)->extensao($bairro);
    $m = 0.01;   // o bairro inteiro, com folga: cobre também lote de canto
    // Lote isolado (coordenada corrompida) fica fora de qualquer quadrado razoável:
    // o teste usa a extensão REAL dos lotes, que já o inclui se existir.
    $todos = $quadrado($e['oeste'] - $m, $e['sul'] - $m, $e['leste'] + $m, $e['norte'] + $m);

    echo "Gravar ({$bairro}, {$total} lotes)\n";
    $r = $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $quadrado(-54.31, -15.51, -54.30, -15.50), 'raio_m' => 25, 'lotes_contados' => $total]);
    confere($r->getStatusCode() === 422 && str_contains($r->getContent(), 'deixa de fora'), 'contorno que não cobre os lotes é recusado');

    $gravata = ['type' => 'MultiPolygon', 'coordinates' => [[[[$e['oeste'], $e['sul']], [$e['leste'], $e['norte']], [$e['leste'], $e['sul']], [$e['oeste'], $e['norte']], [$e['oeste'], $e['sul']]]]]];
    $r = $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $gravata, 'raio_m' => 25, 'lotes_contados' => $total]);
    confere($r->getStatusCode() === 422, 'contorno que se cruza é recusado (' . json_decode($r->getContent())->message . ')');

    $r = $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total, 'isolados' => []]);
    confere($r->getStatusCode() === 200, 'contorno que cobre os lotes é gravado (' . json_decode($r->getContent())->message . ')');

    echo "Ler\n";
    $f = collect(json_decode($chama('index', $admin)->getContent(), true)['features'])->firstWhere('properties.nome', $bairro);
    confere($f !== null && $f['geometry']['type'] === 'MultiPolygon', 'o mapa recebe o contorno do bairro');
    confere($f['properties']['desatualizado'] === false, 'recém-gerado, está em dia');

    DB::table('lotes')->where('bairro', $bairro)->where('situacao', 'ativo')->limit(1)->update(['updated_at' => now()->addMinute()]);
    $f = collect(json_decode($chama('index', $admin)->getContent(), true)['features'])->firstWhere('properties.nome', $bairro);
    confere($f['properties']['desatualizado'] === true, 'lote alterado depois do contorno o deixa desatualizado');

    echo "Bairro só em revisão\n";
    $externo = User::create(['name' => 'Contribuinte Teste', 'email' => 'contrib.contorno@exemplo.test', 'password' => 'x-teste-12345',
        'perfil' => 'viewer', 'tipo_usuario' => 'contribuinte', 'ativo' => true]);
    DB::table('lotes')->where('bairro', $bairro)->update(['em_revisao' => true]);
    $nomes = collect(json_decode($chama('index', $externo)->getContent(), true)['features'])->pluck('properties.nome');
    confere(! $nomes->contains($bairro), 'externo não vê o contorno de bairro só em revisão');
    $r = $chama('gravar', $externo, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total]);
    confere($r->getStatusCode() === 403, 'quem não é curador nem admin não grava contorno');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
