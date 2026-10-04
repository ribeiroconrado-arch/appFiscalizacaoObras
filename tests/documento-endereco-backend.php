<?php
// Diagnóstico local, em transação DESFEITA no fim: o endereço do autuado e o do
// imóvel gravados EM PARTES pelo formulário, e o texto único que a impressão
// lê montado a partir delas.
//
//   php tests/documento-endereco-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\DocumentoController;
use App\Models\Documento;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

$pedido = function (User $u, string $metodo, array $corpo) use ($app): Request {
    Auth::guard('web')->setUser($u);
    $req = Request::create('/x', $metodo, $corpo);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    return $req;
};

DB::beginTransaction();
try {
    $agente = User::create(['name' => 'Agente Endereço', 'email' => 'ag.end@exemplo.test', 'password' => 'x-teste-x-12345',
        'perfil' => 'comum', 'tipo_usuario' => 'agente', 'ativo' => true]);
    $ctl = $app->make(DocumentoController::class);

    echo "Criar com o endereço em partes\n";
    $r = $ctl->storeSemLote($pedido($agente, 'POST', [
        'tipo' => 'notificacao', 'autuado_nome' => 'Empresa de Teste Ltda', 'autuado_documento' => '01.974.088/0001-05',
        'autuado_logradouro' => 'Rua Maringá', 'autuado_numero' => '444', 'autuado_bairro' => 'Centro',
        'autuado_cidade' => 'Primavera do Leste', 'autuado_uf' => 'mt',
        'imovel_logradouro' => 'Rua Paris', 'imovel_numero' => '10',
    ]));
    $id = json_decode($r->getContent(), true)['documento']['id'] ?? null;
    confere($r->getStatusCode() < 300 && $id, 'o rascunho é criado' . ($id ? '' : ' (' . $r->getContent() . ')'));
    $doc = Documento::findOrFail($id);
    confere($doc->autuado_endereco === 'Rua Maringá, 444 — Centro — Primavera do Leste/MT',
        "o texto único do autuado é montado das partes ({$doc->autuado_endereco})");
    confere($doc->endereco === 'Rua Paris, 10', "o endereço da obra também ({$doc->endereco})");
    confere($doc->autuado_endereco_partes['uf'] === 'MT' && $doc->imovel_endereco_partes['numero'] === '10',
        'as partes ficam guardadas, com a UF em maiúsculas');

    echo "Reabrir\n";
    $f = json_decode($ctl->ficha($pedido($agente, 'GET', []), $doc)->getContent(), true);
    confere($f['autuado']['logradouro'] === 'Rua Maringá' && $f['autuado']['cidade'] === 'Primavera do Leste'
        && $f['imovel']['logradouro'] === 'Rua Paris' && $f['imovel']['numero'] === '10',
        'a ficha devolve cada parte no seu campo');

    echo "Alterar\n";
    $r = $ctl->update($pedido($agente, 'PATCH', [
        'tipo' => 'notificacao', 'data_fato' => now()->format('Y-m-d\TH:i'),
        'autuado_logradouro' => 'Av. Brasil', 'autuado_numero' => null, 'autuado_cidade' => 'Cuiabá', 'autuado_uf' => 'MT',
        'imovel_logradouro' => 'Rua Paris', 'imovel_numero' => '12',
        // Texto único velho mandado junto: as partes têm de vencer.
        'autuado_endereco' => 'TEXTO VELHO', 'endereco' => 'TEXTO VELHO',
    ]), $doc);
    $doc->refresh();
    confere($r->getStatusCode() === 200 && $doc->autuado_endereco === 'Av. Brasil — Cuiabá/MT' && $doc->endereco === 'Rua Paris, 12',
        "as partes vencem o texto único que veio junto ({$doc->autuado_endereco} · {$doc->endereco})");

    echo "Peça antiga, sem partes\n";
    $antiga = Documento::create(['tipo' => 'notificacao', 'agente_id' => $agente->id, 'status' => 'rascunho',
        'data_fato' => now(), 'autuado_endereco' => 'Rua Velha, 1, Centro', 'endereco' => 'Rua da Obra, 5']);
    $f = json_decode($ctl->ficha($pedido($agente, 'GET', []), $antiga)->getContent(), true);
    confere($f['autuado']['logradouro'] === 'Rua Velha, 1, Centro' && $f['imovel']['logradouro'] === 'Rua da Obra, 5',
        'o texto único inteiro reabre no logradouro, sem se perder');
    $ctl->update($pedido($agente, 'PATCH', ['tipo' => 'notificacao', 'data_fato' => now()->format('Y-m-d\TH:i'),
        'autuado_endereco' => 'Rua Velha, 1, Centro', 'endereco' => 'Rua da Obra, 5']), $antiga);
    confere($antiga->refresh()->autuado_endereco === 'Rua Velha, 1, Centro' && $antiga->autuado_endereco_partes === null,
        'pedido sem partes mantém o texto único e não inventa partes');

    echo "Cópia do cadastro na peça lavrada\n";
    $lavrada = fn (?array $retrato) => Documento::create(['tipo' => 'notificacao', 'agente_id' => $agente->id,
        'status' => 'lavrado', 'data_fato' => now(), 'data_lavratura' => '2026-10-04 11:26:00',
        'cadastro_consultado_em' => '2026-10-03 18:04:00', 'cadastro_fonte' => 'exportacao', 'cadastro_retrato' => $retrato]);
    $c = json_decode($ctl->ficha($pedido($agente, 'GET', []), $lavrada([
        'imovel' => ['codigo_cadastro' => '42988', 'area_terreno_m2' => '1414.40', 'logradouro' => 'DAS NACOES UNIDAS', 'nome_bairro' => 'JARDIM EUROPA'],
        'caracteristicas' => ['CALCADA' => 'NÃO'],
        'unidades' => [['numero' => '1', 'ano_construcao' => '2004', 'area_edificada_m2' => '98.50', 'pontos' => null, 'padrao' => 'MEDIO']],
    ]))->getContent(), true)['cadastro'];
    confere($c['copiado_em'] === '04/10/2026 11:26' && $c['consultado_em'] === '03/10/2026',
        'a ficha diz QUANDO a cópia foi tirada (a lavratura) e de que carga era o cadastro');
    confere($c['retrato']['imovel']['area_terreno_m2'] === 1414.4 && $c['retrato']['caracteristicas'][0] === ['chave' => 'CALCADA', 'valor' => 'NÃO']
        && $c['retrato']['unidades'][0]['ano'] === 2004 && $c['retrato']['unidades'][0]['area'] === 98.5,
        'e devolve a cópia inteira: terreno, características e unidades');
    $c = json_decode($ctl->ficha($pedido($agente, 'GET', []), $lavrada(['codigo_cadastro' => '24148', 'area_terreno_m2' => '200.00']))->getContent(), true)['cadastro'];
    confere($c['retrato']['imovel']['codigo_cadastro'] === '24148' && $c['retrato']['caracteristicas'] === [] && $c['retrato']['unidades'] === [],
        'peça lavrada antes (só o terreno guardado) é lida no mesmo formato');
    $c = json_decode($ctl->ficha($pedido($agente, 'GET', []), $lavrada(null))->getContent(), true)['cadastro'];
    confere($c['retrato'] === null, 'sem cópia (imóvel fora do cadastro), o retrato vem nulo');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
