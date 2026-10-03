<?php
// Diagnóstico local, no molde de sinalizacao-backend.php: roda numa transação
// DESFEITA no fim. Cobre a aba BCI da ficha: o bairro do cadastro no retrato, a
// progressividade (quem lança, os três estados) e o CPF por tipo de usuário.
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
function lancar(User $u, Lote $lote, array $corpo): int {
    global $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create("/api/imoveis/{$lote->id}/progressividade", 'PUT', $corpo);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    try {
        return $app->make(CadastroImobiliarioController::class)->progressividade($req, $lote)->getStatusCode();
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        return $e->getStatusCode();
    } catch (Illuminate\Validation\ValidationException $e) {
        return 422;
    }
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
    DB::table('cadastro_externo_imoveis')
        ->whereRaw("TRIM(LEADING '0' FROM codigo_bairro) = ?", [ltrim($bairro->codigo, '0')])
        ->whereRaw("TRIM(LEADING '0' FROM quadra) = ?", [ltrim($lote->quadra, '0')])
        ->whereRaw("TRIM(LEADING '0' FROM lote) = ?", [ltrim($lote->numero_lote, '0')])->delete();
    $linha = (object) ['inscricao' => '019999990000000', 'nome_bairro' => 'BAIRRO COMO O CADASTRO CHAMA'];
    DB::table('cadastro_externo_imoveis')->insert([
        'inscricao' => $linha->inscricao, 'codigo_bairro' => $bairro->codigo, 'nome_bairro' => $linha->nome_bairro,
        'quadra' => $lote->quadra, 'lote' => $lote->numero_lote, 'logradouro' => 'RUA DE TESTE', 'numero_predial' => '10',
        'inscricao_alternativa' => '01.999', 'area_terreno_m2' => 360,
        'caracteristicas' => json_encode(['CALCADA' => 'NÃO', 'AGUA' => 'SIM']),
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
    confere($d['fiscalizacao'] === ['tem_progressividade' => null], 'progressividade nasce "não informada"');

    echo "Proprietário\n";
    confere($d['proprietarios'][0]['documento'] === '123.456.789-00' && $d['proprietarios'][0]['endereco'] === 'Rua A, 10',
        'agente vê CPF e endereço');
    [$s, $d] = ler($coord, $lote);
    confere(($d['proprietarios'][0] ?? "HTTP {$s} " . json_encode($d)) ===['nome' => 'Fulana de Teste', 'documento_mascarado' => '***.456.***-00'],
        'coordenador vê o nome e o CPF mascarado, sem endereço');
    [, $d] = ler($externo, $lote);
    confere(! array_key_exists('proprietarios', $d), 'externo não recebe o proprietário');
    confere($d['fiscalizacao'] === ['tem_progressividade' => null], 'mas vê a progressividade');

    echo "Progressividade\n";
    confere(lancar($coord, $lote, ['tem_progressividade' => true]) === 403, 'coordenador não lança');
    confere(lancar($externo, $lote, ['tem_progressividade' => true]) === 403, 'externo não lança');
    confere(lancar($agente, $lote, ['tem_progressividade' => true]) === 200, 'agente lança "sim"');
    [, $d] = ler($coord, $lote);
    confere($d['fiscalizacao']['tem_progressividade'] === true, 'e todos passam a ver "sim"');
    confere(lancar($agente, $lote, ['tem_progressividade' => false]) === 200
        && $lote->fresh()->tem_progressividade === false, '"não" é gravado como não, e não como vazio');
    confere(lancar($agente, $lote, ['tem_progressividade' => null]) === 200
        && $lote->fresh()->tem_progressividade === null, 'e dá para voltar a "não informado"');
    confere(lancar($agente, $lote, []) === 422, 'sem o campo, recusa');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
