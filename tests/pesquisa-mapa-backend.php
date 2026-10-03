<?php
// Diagnóstico local explícito, no molde de importacao-backend.php: tudo roda
// dentro de uma transação que é DESFEITA no fim.
//
//   php tests/pesquisa-mapa-backend.php
//
// Cobre a pesquisa do mapa por filtros combinados: bairro, faixa de quadra e
// lote, inscrição pelo começo, rua e número (exato, trecho, par/ímpar),
// pendências (qualquer/todas), nunca vistoriado, sem pendência, e o fecho
// para quem é de fora.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\InscricoesGravadas;
use App\Models\CadastroBairro;
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
/** GET pela pilha HTTP inteira (rotas + middleware), como usuário $u. */
function pedir(User $u, string $url, array $params = []): array {
    global $kernel, $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create($url, 'GET', $params, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $app->instance('request', $req);
    $r = $kernel->handle($req);
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}

/**
 * Direto no controlador, como usuário $u. Para o usuário criado DENTRO da
 * transação do teste: o middleware de sessão não o reconhece (401), e o que
 * se quer conferir aqui é a regra do controlador, não o login.
 */
function direto(User $u, string $metodo, array $params = []): array {
    global $app;
    // O guard primeiro: registrar o pedido no contêiner refaz o "quem é o
    // usuário" a partir dele, por cima do que se puser no pedido.
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create('/x', 'GET', $params, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    $r = (new App\Http\Controllers\PesquisaMapaController())->{$metodo}($req);
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador ativo para o teste local.'); }

DB::beginTransaction();
try {
    $nome = 'Bairro Teste Pesquisa';
    $oficial = 'BAIRRO TESTE PESQUISA OFICIAL';
    CadastroBairro::create(['codigo' => '986', 'nome_cadastro' => $oficial, 'nome_gis' => $nome]);
    $ids = [];
    // Quadras 1 e 2, lotes 1..4. Rua Teste A na quadra 1 (nº 100,102,104,106), Rua Teste B na 2 (nº 101,103,105,107).
    foreach ([1, 2] as $q) {
        foreach ([1, 2, 3, 4] as $l) {
            $x = -54.30 + $q * 0.001 + $l * 0.0001;
            $geom = sprintf("ST_GeomFromText('POLYGON((-15.56 %1\$.5F, -15.56 %2\$.5F, -15.5599 %2\$.5F, -15.5599 %1\$.5F, -15.56 %1\$.5F))', 4326)", $x, $x + 0.00008);
            DB::insert("INSERT INTO lotes (bairro, quadra, numero_lote, desmembramento, chave, situacao, origem, geom, area_gis_m2, created_at, updated_at)
                        VALUES (?, ?, ?, 0, ?, 'ativo', 'importacao', {$geom}, 100, NOW(), NOW())",
                [$nome, (string) $q, (string) $l, "teste-pesq-{$q}-{$l}-" . uniqid()]);
            $ids["{$q}-{$l}"] = (int) DB::getPdo()->lastInsertId();
            DB::table('cadastro_externo_imoveis')->insert([
                'inscricao' => sprintf('01.986.%03d.%04d.000', $q, $l), 'codigo_bairro' => '0986',
                'quadra' => sprintf('%03d', $q), 'lote' => sprintf('%04d', $l),
                'logradouro' => $q === 1 ? 'RUA TESTE A' : 'RUA TESTE B',
                'numero_predial' => (string) (($q === 1 ? 98 : 99) + $l * 2),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
    (new InscricoesGravadas())->gravar([$nome]);
    DB::table('sinalizacoes')->insert([
        ['lote_id' => $ids['1-2'], 'tipo' => 'entulho', 'user_id' => $admin->id, 'status' => 'aberta', 'lembrar_em' => null, 'created_at' => now(), 'updated_at' => now()],
        ['lote_id' => $ids['2-3'], 'tipo' => 'lembrete', 'user_id' => $admin->id, 'status' => 'aberta', 'lembrar_em' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ['lote_id' => $ids['1-2'], 'tipo' => 'lembrete', 'user_id' => $admin->id, 'status' => 'aberta', 'lembrar_em' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ['lote_id' => $ids['2-4'], 'tipo' => 'lembrete', 'user_id' => $admin->id, 'status' => 'aberta', 'lembrar_em' => now()->addDays(20)->toDateString(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    $externo = User::create(['name' => 'Contribuinte Pesquisa', 'email' => 'contrib.pesq@exemplo.test',
        'password' => 'x-teste-x-12345', 'perfil' => 'viewer', 'tipo_usuario' => 'contribuinte', 'ativo' => true]);

    $achou = fn (array $p) => collect(pedir($admin, '/api/mapa/pesquisa', $p)[1]['ids'] ?? [])->sort()->values()->all();
    $de = fn (string ...$k) => collect($k)->map(fn ($x) => $ids[$x])->sort()->values()->all();
    $b = ['bairros' => [$oficial]];

    echo "Onde\n";
    [$s, $d] = pedir($admin, '/api/mapa/pesquisa', []);
    confere($s === 422, 'sem filtro nenhum é recusado');
    [$s, $d] = pedir($admin, '/api/mapa/pesquisa', $b);
    confere($s === 200 && $d['total'] === 8 && count($d['imoveis']) === 8, 'bairro (nome oficial) traz os 8 lotes');
    confere(is_array($d['caixa']) && count($d['caixa']) === 4 && $d['caixa'][0] <= $d['caixa'][2], 'vem a caixa para enquadrar o resultado');
    confere($d['imoveis'][0]['endereco'] === 'RUA TESTE A, 100' && $d['imoveis'][0]['inscricao'] === '01.986.001.0001.000', 'a linha traz endereço e inscrição');
    confere($achou($b + ['quadra_de' => 2, 'quadra_ate' => 2]) === $de('2-1', '2-2', '2-3', '2-4'), 'faixa de quadra');
    confere($achou($b + ['quadra_de' => 1, 'quadra_ate' => 1, 'lote_de' => 2, 'lote_ate' => 3]) === $de('1-2', '1-3'), 'quadra E faixa de lote se somam');
    confere($achou(['inscricao' => '01.986.002']) === $de('2-1', '2-2', '2-3', '2-4'), 'inscrição pelo começo traz a quadra inteira');

    echo "Endereço\n";
    confere($achou(['ruas' => ['RUA TESTE A']]) === $de('1-1', '1-2', '1-3', '1-4'), 'rua da lista');
    confere($achou(['ruas' => ['RUA TESTE A', 'RUA TESTE B']]) === array_values(collect($ids)->sort()->values()->all()), 'duas ruas valem como OU');
    confere($achou(['ruas' => ['RUA TESTE A'], 'numero_modo' => 'exato', 'numero_de' => 104]) === $de('1-3'), 'rua e número exato');
    confere($achou(['ruas' => ['RUA TESTE A'], 'numero_modo' => 'faixa', 'numero_de' => 102, 'numero_ate' => 104]) === $de('1-2', '1-3'), 'rua e trecho de número');
    confere($achou($b + ['numero_modo' => 'impar']) === $de('2-1', '2-2', '2-3', '2-4'), 'lado ímpar, dentro do bairro');
    confere($achou(['ruas' => ['RUA TESTE A'], 'numero_modo' => 'impar']) === [], 'combinação sem imóvel devolve vazio');

    echo "Pendências\n";
    confere($achou($b + ['pendencias' => ['sinalizacao']]) === $de('1-2'), 'sinalização aberta (lembrete não conta)');
    confere($achou($b + ['pendencias' => ['lembrete']]) === $de('1-2', '2-3'), 'lembrete vencido (o futuro não entra)');
    confere($achou($b + ['pendencias' => ['sinalizacao', 'lembrete'], 'pendencias_modo' => 'qualquer']) === $de('1-2', '2-3'), 'qualquer uma das escolhidas');
    confere($achou($b + ['pendencias' => ['sinalizacao', 'lembrete'], 'pendencias_modo' => 'todas']) === $de('1-2'), 'todas as escolhidas');
    confere($achou($b + ['pendencias' => ['embargo', 'prazo_vencido', 'prazo_a_vencer', 'obra_sem_vistoria']]) === [], 'pendências de documento e obra consultam sem erro');
    confere($achou($b + ['sem_pendencia' => 1]) === $de('1-1', '1-3', '1-4', '2-1', '2-2', '2-4'), 'sem nenhuma pendência');
    confere($achou($b + ['vistorias' => ['nunca']]) === array_values(collect($ids)->sort()->values()->all()), 'nunca vistoriado');
    confere($achou($b + ['vistorias' => ['irregular']]) === [], 'situação da última vistoria');
    [$s, $d] = pedir($admin, '/api/mapa/pesquisa', $b + ['pendencias' => ['sinalizacao']]);
    confere($d['imoveis'][0]['pendencias'] === ['Sinalização aberta', 'Lembrete vencido'], 'a linha diz quais pendências o lote tem');

    echo "Quem é de fora\n";
    [$s] = direto($externo, 'pesquisar', $b + ['pendencias' => ['sinalizacao']]);
    confere($s === 403, 'externo não filtra por pendência');
    [$s, $d] = direto($externo, 'pesquisar', $b);
    confere($s === 200 && $d['total'] === 8 && $d['imoveis'][0]['pendencias'] === [], 'externo pesquisa por local, sem ver pendência');
    [$s, $d] = direto($externo, 'opcoes');
    confere($s === 200 && $d['pendencias'] === [] && $d['vistorias'] === [], 'as opções de pendência nem são oferecidas a ele');

    echo "Opções dos seletores\n";
    [$s, $d] = pedir($admin, '/api/mapa/pesquisa/opcoes');
    $bo = collect($d['bairros'])->firstWhere('nome', $oficial);
    confere($bo && (string) $bo['codigo'] === '986', 'bairro vem com o nome oficial e o código');
    $rua = collect($d['ruas'])->firstWhere('rua', 'RUA TESTE A');
    confere($rua && $rua['bairros'] === [$oficial], 'rua vem com o bairro em que fica');
    confere(isset($d['pendencias']['sinalizacao'], $d['vistorias']['nunca']), 'pendências e vistorias para quem é de dentro');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
