<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige dois bairros com contorno gravado.
//
//   php tests/contorno-edicao-backend.php
//
// Cobre a EDIÇÃO DO CONTORNO À MÃO (App\Services\EdicaoDeContorno): o
// contorno atual passa; encolhido, deixa lotes de fora e é recusado; colado
// num vizinho, acusa a invasão; gravar marca "editado à mão" e guarda o
// anterior; desfazer devolve; gerar por cima de uma edição pede confirmação.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\EdicaoDeContorno;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}

DB::beginTransaction();
try {
    $svc = app(EdicaoDeContorno::class);
    $nomes = DB::table('bairros')->whereExists(fn ($q) => $q->from('lotes')->whereColumn('lotes.bairro', 'bairros.nome')->where('situacao', 'ativo'))
        ->orderBy('nome')->pluck('nome')->all();
    if (count($nomes) < 2) { throw new RuntimeException('Precisa de dois bairros com contorno e lotes.'); }
    [$b, $outro] = $nomes;
    $g = json_decode(DB::scalar('SELECT ST_AsGeoJSON(geom) FROM bairros WHERE nome = ?', [$b]), true);

    $c = $svc->conferir($b, $g);
    confere($c['valido'] && $c['pode'], "o contorno gravado de {$b} passa nas três regras");

    $anel = $g['coordinates'][0][0];
    $cx = array_sum(array_column($anel, 0)) / count($anel);
    $cy = array_sum(array_column($anel, 1)) / count($anel);
    $enc = $g;
    $enc['coordinates'][0][0] = array_map(fn ($p) => [$cx + ($p[0] - $cx) * .5, $cy + ($p[1] - $cy) * .5], $anel);
    $c2 = $svc->conferir($b, $enc);
    confere(! $c2['pode'] && count($c2['lotes_fora']) > 0, 'encolhido, deixa lotes de fora e não pode');

    $go = json_decode(DB::scalar('SELECT ST_AsGeoJSON(geom) FROM bairros WHERE nome = ?', [$outro]), true);
    $c3 = $svc->conferir($b, ['type' => 'MultiPolygon', 'coordinates' => array_merge($g['coordinates'], $go['coordinates'])]);
    confere(! $c3['pode'] && ($c3['invasoes'][0]['bairro'] ?? null) === $outro, "colado em {$outro}, acusa a invasão");

    $u = (int) DB::table('users')->value('id');
    $svc->gravar($b, $g, $u);
    confere((bool) DB::table('bairros')->where('nome', $b)->value('editado_manual'), 'gravar marca o bairro como editado à mão');
    confere($svc->temAnterior($b), 'o contorno de antes fica guardado');

    $recusou = false;
    try { $svc->gravar($b, $enc, $u); } catch (RuntimeException) { $recusou = true; }
    confere($recusou, 'gravar o encolhido é recusado');

    $svc->desfazer($b);
    confere(! DB::table('bairros')->where('nome', $b)->value('editado_manual') && ! $svc->temAnterior($b), 'desfazer devolve o anterior');

    // Gerar por cima de uma edição: 409 sem a confirmação.
    $svc->gravar($b, $g, $u);
    $req = Illuminate\Http\Request::create('/api/bairros/contorno', 'POST', ['bairro' => $b, 'geometry' => $g, 'raio_m' => 25, 'lotes_contados' => 1]);
    $usuario = App\Models\User::query()->get()->first(fn ($x) => $x->podeCurarCadastro() || $x->isAdmin());
    $req->setUserResolver(fn () => $usuario);
    $resp = app(App\Http\Controllers\BairroContornoController::class)->gravar($req);
    confere($resp->status() === 409, 'gerar por cima de uma edição pede confirmação (409)');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
