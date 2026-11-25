<?php
// Diagnóstico local, no molde de sinalizacao-backend.php: roda numa transação
// DESFEITA no fim. Cobre a aba BCI da ficha: o bairro do cadastro no retrato, a
// progressividade (lida do cadastro, ninguém lança) e o CPF por tipo de usuário.
//
//   php tests/bci-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CadastroImobiliarioController;
use App\Models\Lote;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

/**
 * Direto no controlador, e não pela pilha HTTP: sem sessão de navegador não há
 * token CSRF para o PUT, e o `auth.session` derruba o segundo usuário no GET.
 */
function ler(User $u, Lote $lote): array {
    global $kernel, $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create("/api/imoveis/{$lote->id}/bci", 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    $r = $app->make(CadastroImobiliarioController::class)->mostrar($lote);
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}
// Um lote que o cadastro carregado conhece — sem carga, não há o que conferir.
// Um lote de bairro já amarrado ao cadastro. O imóvel do cadastro é criado
// AQUI, dentro da transação: a base local pode ter a amarração sem ter a carga
// daquele bairro, e o teste não pode depender de qual planilha foi carregada.
$bairro = DB::table('cadastro_bairros')->whereNotNull('nome_gis')->first(['nome_gis', 'codigo']);
$lote = $bairro ? Lote::query()->ativos()->where('bairro', $bairro->nome_gis)
    ->whereNotNull('quadra')->whereNotNull('numero_lote')->first() : null;
if (! $lote) { echo "Nenhum bairro amarrado ao cadastro nesta base: nada a conferir.\n"; exit(0); }

DB::beginTransaction();
try {
    // O cadastro casa pela INSCRIÇÃO do lote (17/11/2026): o imóvel de teste
    // nasce com ela, e o que a base tiver com a mesma inscrição sai antes.
    $inscDoLote = App\Support\InscricaoImobiliaria::normalizar((new App\Cadastro\BairrosDoDesenho())->inscricaoDe($lote));
    DB::table('cadastro_externo_imoveis')->where('inscricao', $inscDoLote)->delete();
    $linha = (object) ['inscricao' => $inscDoLote, 'nome_bairro' => 'BAIRRO COMO O CADASTRO CHAMA'];
    DB::table('cadastro_externo_imoveis')->insert([
        'inscricao' => $linha->inscricao, 'codigo_bairro' => $bairro->codigo, 'nome_bairro' => $linha->nome_bairro,
        'quadra' => $lote->quadra, 'lote' => $lote->numero_lote, 'logradouro' => 'RUA DE TESTE', 'numero_predial' => '10',
        'inscricao_alternativa' => '01.999', 'area_terreno_m2' => 360,
        'caracteristicas' => json_encode(['CALCADA' => 'NÃO', 'AGUA' => 'SIM', 'PROGRESSIVIDADE' => 'SIM']),
    ]);

    $novo = fn (string $email, string $perfil, string $tipo) => User::create(['name' => 'T ' . $tipo, 'email' => $email,
        'password' => 'x-teste-x-12345', 'perfil' => $perfil, 'tipo_usuario' => $tipo, 'ativo' => true]);
    $agente  = $novo('ag.bci@exemplo.test', 'comum', 'agente');
    $coord   = $novo('co.bci@exemplo.test', 'viewer', 'coordenador');
    $externo = $novo('ex.bci@exemplo.test', 'viewer', 'contribuinte');

    DB::table('cadastro_proprietarios')->where('inscricao', $linha->inscricao)->delete();
    DB::table('cadastro_proprietarios')->insert(['inscricao' => $linha->inscricao, 'ordem' => 0,
        'nome' => 'Fulana de Teste', 'documento' => '123.456.789-00', 'endereco' => 'Rua A, 10']);

    echo "Retrato\n";
    [$s, $d] = ler($agente, $lote);
    confere($s === 200 && $d['tem'] === true, 'o lote tem cadastro');
    confere($d['imovel']['nome_bairro'] === $linha->nome_bairro, "o bairro vem do cadastro ({$linha->nome_bairro})");
    confere(array_key_exists('logradouro', $d['imovel']) && array_key_exists('inscricao_alternativa', $d['imovel']),
        'logradouro e inscrição alternativa vão no retrato');
    $prog = collect($d['caracteristicas'])->firstWhere('chave', 'PROGRESSIVIDADE');
    confere(($prog['valor'] ?? null) === 'SIM', 'a progressividade vem do cadastro');
    confere(! array_key_exists('fiscalizacao', $d), 'e não há mais o dado lançado pela fiscalização');

    echo "Proprietário\n";
    confere($d['proprietarios'][0]['documento'] === '123.456.789-00' && $d['proprietarios'][0]['endereco'] === 'Rua A, 10',
        'agente vê CPF e endereço');
    [$s, $d] = ler($coord, $lote);
    confere(($d['proprietarios'][0] ?? "HTTP {$s} " . json_encode($d)) ===['nome' => 'Fulana de Teste', 'documento_mascarado' => '***.456.***-00'],
        'coordenador vê o nome e o CPF mascarado, sem endereço');
    [, $d] = ler($externo, $lote);
    confere(! array_key_exists('proprietarios', $d), 'externo não recebe o proprietário');
    confere(collect($d['caracteristicas'])->contains('chave', 'PROGRESSIVIDADE'), 'mas vê a progressividade');

    echo "Progressividade\n";
    confere(! collect(Illuminate\Support\Facades\Route::getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'progressividade')),
        'não há rota para lançar a progressividade');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
