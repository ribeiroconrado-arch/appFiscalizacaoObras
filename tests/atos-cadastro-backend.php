<?php
// Diagnóstico local, numa transação DESFEITA no fim. A tela do ato e a tela
// Histórico (AtosDoCadastro, 20/11/2026): o ato com a mãe, as partes e o
// desenho; a troca de identidade agrupada numa linha ("A ↔ B"); a lista com
// filtro e busca.
//
//   php tests/atos-cadastro-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\AtosDoCadastro;
use App\Models\Lote;
use App\Models\User;
use App\Services\TrocaDeIdentidades;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

$curador = User::where('ativo', true)->get()->first(fn (User $u) => $u->podeCurarCadastro());
if (! $curador) { throw new RuntimeException('Sem curador ativo para o teste local.'); }

DB::beginTransaction();
try {
    $grupo = DB::table('lotes')->where('situacao', 'ativo')->whereNull('terreno_id')->whereNull('importacao_id')
        ->whereNotNull('quadra')->whereNotNull('numero_lote')
        ->select('bairro', 'quadra')->groupBy('bairro', 'quadra')->havingRaw('COUNT(*) >= 3')->first();
    [$mae, $a, $b] = Lote::where('situacao', 'ativo')->where('bairro', $grupo->bairro)->where('quadra', $grupo->quadra)
        ->whereNull('terreno_id')->orderBy('id')->take(3)->get()->all();
    $prefixo = substr(InscricaoImobiliaria::normalizar($a->inscricao()), 0, 12);
    foreach ([[$a, '9901A', 1], [$b, '9901B', 2]] as [$l, $n, $s]) {
        DB::table('lotes')->where('id', $l->id)->update(['numero_lote' => $n, 'desmembramento' => $s,
            'chave' => $l->bairro . '|' . $l->quadra . '|' . $n,
            'inscricao_imobiliaria' => InscricaoImobiliaria::formatar($prefixo . sprintf('%03d', $s))]);
    }
    DB::table('lotes')->where('id', $mae->id)->update(['numero_lote' => '9901', 'chave' => $mae->bairro . '|' . $mae->quadra . '|9901', 'situacao' => 'inativo']);
    $atoId = DB::table('lote_atos')->insertGetId(['tipo' => 'desmembramento', 'user_id' => $curador->id,
        'observacao' => 'teste dos atos', 'created_at' => now()->subMinute(), 'updated_at' => now()]);
    DB::table('lote_ato_lotes')->insert([
        ['ato_id' => $atoId, 'lote_id' => $mae->id, 'papel' => 'anterior', 'area_m2' => 500],
        ['ato_id' => $atoId, 'lote_id' => $a->id, 'papel' => 'posterior', 'area_m2' => 250],
        ['ato_id' => $atoId, 'lote_id' => $b->id, 'papel' => 'posterior', 'area_m2' => 250],
    ]);
    $atos = new AtosDoCadastro();

    echo "A tela do ato\n";
    confere($atos->atoDoLote($a->id) === $atoId && $atos->atoDoLote($mae->id) === $atoId, 'o ato é achado pela parte e pela mãe');
    $d = $atos->ato($atoId);
    confere(array_column($d['anteriores'], 'numero') === ['9901'], 'a mãe é o 9901');
    confere(array_column($d['posteriores'], 'numero') === ['9901A', '9901B'], 'as partes são 9901A e 9901B');
    confere(isset($d['posteriores'][0]['geometria']['coordinates']), 'com o desenho de cada lote');
    confere($d['anteriores'][0]['area_no_ato'] === 500.0, 'a área congelada no ato');
    confere($d['troca']['bloqueio'] === null, 'duas partes ativas: a troca é possível');
    confere(count($d['historico']) === 1 && $d['historico'][0]['tipo'] === 'ato', 'o histórico começa com o ato aplicado');

    echo "A troca entra no histórico\n";
    Auth::setUser($curador);
    (new TrocaDeIdentidades())->aplicar(Lote::find($a->id), [$b->id, $a->id], 'Placa mostra a esquerda como 9901B');
    $d = $atos->ato($atoId);
    confere($d['historico'][0]['tipo'] === 'troca' && str_contains($d['historico'][0]['titulo'], '9901A ↔ 9901B'), 'a troca de duas partes vira "9901A ↔ 9901B"');
    confere($d['historico'][0]['motivo'] === 'Placa mostra a esquerda como 9901B', 'com o motivo');
    confere(Lote::find($a->id)->numero_lote === '9901B', 'e o lote trocou mesmo de número');

    echo "A lista do Histórico\n";
    $l = $atos->lista(null, '9901a');
    $tipos = array_column($l['itens'], 'tipo');
    confere(in_array('desmembramento', $tipos, true) && in_array('troca', $tipos, true), 'a busca pelo número acha o ato e a troca');
    confere($tipos[0] === 'troca', 'a mais recente primeiro');
    confere(array_column($atos->lista('troca', '9901')['itens'], 'tipo') === ['troca'], 'o filtro "troca" só traz trocas');
    confere($atos->lista('unificacao', '9901')['itens'] === [], 'o filtro "unificação" não traz o desmembramento');
    $porInscricao = $atos->lista(null, $prefixo);
    confere(count($porInscricao['itens']) >= 2, 'a busca pela inscrição também acha');

    echo "Pelas rotas\n";
    $pedir = function (string $url) use ($kernel, $app, $curador) {
        $req = Illuminate\Http\Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $req->setUserResolver(fn () => $curador);
        Auth::guard('web')->setUser($curador);
        $app->instance('request', $req);
        return $kernel->handle($req);
    };
    $r = $pedir("/api/atos/{$atoId}");
    confere($r->getStatusCode() === 200 && json_decode($r->getContent(), true)['pode_trocar'] === true, 'o ato pela rota, com a troca liberada ao curador');
    $r = $pedir("/api/lotes/{$a->id}/ato");
    confere($r->getStatusCode() === 200 && json_decode($r->getContent(), true)['id'] === $atoId, 'o ato pelo lote');
    $r = $pedir('/api/atos?busca=9901');
    confere($r->getStatusCode() === 200 && count(json_decode($r->getContent(), true)['itens']) >= 2, 'a lista pela rota');
    confere($pedir('/api/atos/999999999')->getStatusCode() === 404, 'ato que não existe: 404');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
