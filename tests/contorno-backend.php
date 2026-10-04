<?php
// Diagnóstico local do contorno dos bairros, no molde de importacao-backend.php:
// tudo numa transação DESFEITA no fim. Exige as migrações 2026_10_01_000100
// 2026_10_07_000100 (quadras) e 2026_10_08_000100 (nomes de rua).
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

    // Com o código do cadastro, a leitura junta `lotes` com o cadastro — duas
    // tabelas de collations diferentes. Em 03/10/2026 isso derrubava a
    // ferramenta de contorno em produção com "Server Error" (erro 1267).
    echo "Lotes do bairro com o logradouro do cadastro\n";
    confere(count(app(LoteRepository::class)->lotesDoBairro($bairro, '105')) === $total,
        'a junção com o cadastro devolve todos os lotes, sem erro de collation');

    $e = app(LoteRepository::class)->extensao($bairro);
    $m = 0.01;   // o bairro inteiro, com folga: cobre também lote de canto
    // Lote isolado (coordenada corrompida) fica fora de qualquer quadrado razoável:
    // o teste usa a extensão REAL dos lotes, que já o inclui se existir.
    $todos = $quadrado($e['oeste'] - $m, $e['sul'] - $m, $e['leste'] + $m, $e['norte'] + $m);

    echo "Gravar ({$bairro}, {$total} lotes)\n";
    $r = $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $quadrado(-54.31, -15.51, -54.30, -15.50), 'raio_m' => 25, 'lotes_contados' => $total]);
    confere($r->getStatusCode() === 422 && str_contains($r->getContent(), 'deixa de fora'), 'contorno que não cobre os lotes é recusado');
    $fora = json_decode($r->getContent(), true)['fora'] ?? [];
    confere(count($fora) > 0 && isset($fora[0]['id']) && array_key_exists('quadra', $fora[0]) && array_key_exists('lote', $fora[0]),
        'e a recusa diz QUAIS lotes ficaram de fora (' . count($fora) . ' listados)');

    $gravata = ['type' => 'MultiPolygon', 'coordinates' => [[[[$e['oeste'], $e['sul']], [$e['leste'], $e['norte']], [$e['leste'], $e['sul']], [$e['oeste'], $e['norte']], [$e['oeste'], $e['sul']]]]]];
    $r = $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $gravata, 'raio_m' => 25, 'lotes_contados' => $total]);
    confere($r->getStatusCode() === 422, 'contorno que se cruza é recusado (' . json_decode($r->getContent())->message . ')');

    $r = $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total, 'isolados' => []]);
    confere($r->getStatusCode() === 200, 'contorno que cobre os lotes é gravado (' . json_decode($r->getContent())->message . ')');

    echo "Ler\n";
    $f = collect(json_decode($chama('index', $admin)->getContent(), true)['features'])->firstWhere('properties.nome', $bairro);
    confere($f !== null && $f['geometry']['type'] === 'MultiPolygon', 'o mapa recebe o contorno do bairro');
    confere($f['properties']['desatualizado'] === false, 'recém-gerado, está em dia');

    echo "Quadras\n";
    $centro = [($e['sul'] + $e['norte']) / 2, ($e['oeste'] + $e['leste']) / 2];
    $quadras = [
        ['numero' => '01', 'geometry' => $quadrado($e['oeste'], $e['sul'], ($e['oeste'] + $e['leste']) / 2, $e['norte']), 'rotulo' => $centro, 'lotes' => 3],
        ['numero' => '02', 'geometry' => $quadrado(($e['oeste'] + $e['leste']) / 2, $e['sul'], $e['leste'], $e['norte']), 'rotulo' => $centro, 'lotes' => 2],
        ['numero' => '03', 'geometry' => $gravata, 'rotulo' => $centro, 'lotes' => 1],
    ];
    $r = $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total, 'quadras' => $quadras]);
    $d = json_decode($r->getContent(), true);
    confere($r->getStatusCode() === 200 && $d['quadras'] === 2, 'quadras gravadas junto com o bairro (' . $d['message'] . ')');
    confere($d['quadras_invalidas'] === ['03'], 'quadra de desenho inválido fica de fora sem barrar o bairro');
    $f = collect(json_decode($chama('index', $admin)->getContent(), true)['features'])->firstWhere('properties.nome', $bairro);
    confere($f['properties']['quadras'] === 2, 'o mapa sabe quantas quadras o bairro tem');
    $q = json_decode($chama('quadras', $admin, ['bairro' => $bairro])->getContent(), true);
    confere(count($q['features']) === 2 && $q['features'][0]['properties']['numero'] === '01'
        && $q['features'][0]['geometry']['type'] === 'MultiPolygon', 'o mapa recebe contorno e número das quadras');
    $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total]);
    confere(DB::table('quadras')->where('bairro', $bairro)->count() === 2, 'envio sem quadras (página antiga em cache) não apaga as gravadas');
    $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total, 'quadras' => [$quadras[1]]]);
    confere(DB::table('quadras')->where('bairro', $bairro)->pluck('numero')->all() === ['02'], 'gerar de novo substitui as quadras do bairro');

    echo "Nomes de rua\n";
    $trecho = ['nome' => 'RUA TESTE', 'de' => [$e['sul'], $e['oeste']], 'ate' => [$e['sul'], $e['leste']]];
    $semNome = ['nome' => null, 'de' => [$e['norte'], $e['oeste']], 'ate' => [$e['norte'], $e['leste']]];
    $r = $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total, 'ruas' => [$trecho, $semNome]]);
    confere($r->getStatusCode() === 200, 'trechos de rua gravados junto (' . json_decode($r->getContent())->message . ')');
    $ruas = json_decode($chama('quadras', $admin, ['bairro' => $bairro])->getContent(), true)['ruas'];
    confere(array_column($ruas, 'origem') === ['cadastro', 'sem_nome'], 'o mapa recebe os trechos, com a origem');

    $ctlRua = app(\App\Http\Controllers\RuaManualController::class);
    $rua = function (string $metodo, User $u, array $corpo = [], $modelo = null) use ($ctlRua) {
        Auth::guard('web')->setUser($u);
        $req = Request::create('/x', 'POST', $corpo);
        $req->setUserResolver(fn () => $u);
        try { return $modelo ? $ctlRua->{$metodo}($req, $modelo) : $ctlRua->{$metodo}($req); }
        catch (Illuminate\Validation\ValidationException $ex) { return response()->json($ex->errors(), 422); }
    };
    $r = $rua('criar', $admin, ['bairro' => $bairro, 'de' => $semNome['de'], 'ate' => $semNome['ate'], 'nome' => 'AVENIDA DO TESTE']);
    $d = json_decode($r->getContent(), true);
    confere($r->getStatusCode() === 200 && in_array('manual', array_column($d['ruas'], 'origem'), true)
        && ! in_array('sem_nome', array_column($d['ruas'], 'origem'), true), 'o nome informado cobre o trecho sem nome');
    $manual = \App\Models\RuaManual::where('bairro', $bairro)->latest('id')->first();
    confere(DB::table('auditoria')->where('tabela', 'ruas_manuais')->where('registro_id', $manual->id)->exists(), 'fica na auditoria');
    $chama('gravar', $admin, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total, 'ruas' => [$trecho, $semNome]]);
    confere(\App\Models\RuaManual::whereKey($manual->id)->exists(), 'gerar de novo não apaga o nome informado à mão');
    $r = $rua('alterar', $admin, ['oculto' => true], $manual);
    confere(in_array('oculto', array_column(json_decode($r->getContent(), true)['ruas'], 'origem'), true), 'ocultar o trecho');
    $r = $rua('excluir', $admin, [], $manual->fresh());
    confere(array_column(json_decode($r->getContent(), true)['ruas'], 'origem') === ['cadastro', 'sem_nome'], 'voltar ao cadastro');

    DB::table('lotes')->where('bairro', $bairro)->where('situacao', 'ativo')->limit(1)->update(['updated_at' => now()->addMinute()]);
    $f = collect(json_decode($chama('index', $admin)->getContent(), true)['features'])->firstWhere('properties.nome', $bairro);
    confere($f['properties']['desatualizado'] === true, 'lote alterado depois do contorno o deixa desatualizado');

    echo "Bairro só em revisão\n";
    $externo = User::create(['name' => 'Contribuinte Teste', 'email' => 'contrib.contorno@exemplo.test', 'password' => 'x-teste-12345',
        'perfil' => 'viewer', 'tipo_usuario' => 'contribuinte', 'ativo' => true]);
    DB::table('lotes')->where('bairro', $bairro)->update(['em_revisao' => true]);
    $nomes = collect(json_decode($chama('index', $externo)->getContent(), true)['features'])->pluck('properties.nome');
    confere(! $nomes->contains($bairro), 'externo não vê o contorno de bairro só em revisão');
    confere(json_decode($chama('quadras', $externo, ['bairro' => $bairro])->getContent(), true)['features'] === [],
        'externo também não vê as quadras dele');
    $r = $chama('gravar', $externo, ['bairro' => $bairro, 'geometry' => $todos, 'raio_m' => 25, 'lotes_contados' => $total]);
    confere($r->getStatusCode() === 403, 'quem não é curador nem admin não grava contorno');
    $r = $rua('criar', $externo, ['bairro' => $bairro, 'de' => $semNome['de'], 'ate' => $semNome['ate'], 'nome' => 'X']);
    confere($r->getStatusCode() === 403, 'quem não é curador nem admin não informa nome de rua');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
