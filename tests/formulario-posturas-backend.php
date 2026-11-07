<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim.
//
//   php tests/formulario-posturas-backend.php
//
// O formulário de Posturas como o do AppPOSTURAS (07/11/2026): inscrição
// municipal e e-mail do contribuinte, o GPS do local, o inciso da lavratura
// imediata (art. 16 da Lei 723) e o cadastro de contribuintes alimentado pela
// peça — e Obras sem nada disso.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\DocumentoController;
use App\Models\Contribuinte;
use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) throw new RuntimeException('FALHOU: ' . $o); $ok++; echo "  ok  {$o}\n"; }

/** Chama o controller como a rota chamaria, com o usuário logado. */
function chamar(User $u, string $metodo, array $corpo, callable $acao): Illuminate\Http\JsonResponse {
    global $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create('/x', $metodo, $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    try {
        return $acao(app(DocumentoController::class), $req);
    } catch (Illuminate\Validation\ValidationException $e) {
        return response()->json(['errors' => $e->errors()], 422);
    }
}

DB::beginTransaction();
try {
    $agente = User::create(['name' => 'Agente Teste Posturas', 'email' => 'agente.posturas.' . uniqid() . '@local.test',
        'password' => bcrypt(bin2hex(random_bytes(8))), 'perfil' => 'comum', 'tipo_usuario' => 'agente', 'modulos' => ['posturas'], 'ativo' => true]);
    $lei = Legislacao::create(['modulo' => 'posturas', 'numero' => '723', 'nome' => 'LEI TESTE DO RUÍDO', 'ano' => 2002, 'ativa' => true,
        'formulario_tipo' => 'lei_723_2002', 'prazo_defesa_dias' => 5,
        'areas_db' => [['area' => 'Zona residencial', 'diurno' => 50, 'noturno' => 45]],
        'lavratura_imediata_itens' => [['numero' => 1, 'texto' => 'Inc. I — reincidência no mesmo dia.'], ['numero' => 2, 'texto' => 'Inc. II — **evento sem licença**.']]]);

    $base = [
        'legislacao_id' => $lei->id, 'data_fato' => now()->format('Y-m-d\TH:i'),
        'autuado_nome' => 'BAR DO TESTE LTDA', 'autuado_documento' => '11.222.333/0001-81',
        'autuado_inscricao_municipal' => '12345', 'autuado_email' => 'Contato@BarTeste.com.br',
        'autuado_logradouro' => 'RUA DAS FLORES', 'autuado_numero' => '10', 'autuado_bairro' => 'CENTRO',
        'autuado_cidade' => 'PRIMAVERA DO LESTE', 'autuado_uf' => 'MT',
        'imovel_logradouro' => 'AV. DO COMÉRCIO', 'imovel_numero' => '500', 'imovel_bairro' => 'CENTRO',
        'local_lat' => -15.5612345, 'local_lng' => -54.2987654, 'local_precisao' => 8,
    ];

    echo "Auto de infração direto (sem notificação antes)\n";
    $r = chamar($agente, 'POST', $base + ['tipo' => 'auto_infracao', 'lavratura_imediata_item' => 2], fn ($c, $q) => $c->storeSemLote($q));
    confere($r->getStatusCode() === 201, 'o rascunho é criado (' . $r->getStatusCode() . ')');
    $doc = Documento::find($r->getData(true)['documento']['id']);
    confere($doc->autuado_inscricao_municipal === '12345' && $doc->autuado_email === 'contato@barteste.com.br', 'inscrição municipal e e-mail (minúsculo) gravados');
    confere(abs($doc->local_gps['lat'] - -15.5612345) < 1e-7 && $doc->local_gps['precisao'] === 8, 'o GPS do local é gravado');
    confere(($doc->lavratura_imediata['numero'] ?? null) === 2 && str_contains($doc->lavratura_imediata['texto'], 'evento sem licença'), 'o inciso vai COPIADO da lei (número e texto)');
    confere($doc->pedeLavraturaImediata(), 'o auto direto pede a lavratura imediata');
    confere(! in_array('o inciso da lavratura imediata (art. 16)', $doc->pendenciasDePosturas(), true), 'com o inciso escolhido, não é pendência');

    $c = Contribuinte::where('cpf_cnpj', '11.222.333/0001-81')->first();
    confere($c && $c->nome === 'BAR DO TESTE LTDA' && $c->inscricao_municipal === '12345' && $c->cidade === 'PRIMAVERA DO LESTE', 'o contribuinte entra no cadastro');
    $busca = app(DocumentoController::class)->contribuinte('11222333000181');
    confere($busca->getStatusCode() === 200 && $busca->getData(true)['contribuinte']['email'] === 'contato@barteste.com.br', 'e é achado pelo CNPJ só com dígitos');
    confere(app(DocumentoController::class)->contribuinte('99888777000166')->getStatusCode() === 404, 'CNPJ sem cadastro: 404 (o formulário tenta a Receita)');

    $ficha = chamar($agente, 'GET', [], fn ($ctl, $q) => $ctl->ficha($q, $doc))->getData(true);
    confere($ficha['autuado']['inscricao_municipal'] === '12345' && $ficha['local_gps']['lng'] !== null && $ficha['lavratura_imediata']['numero'] === 2,
        'a ficha devolve os campos novos para o formulário reabrir');

    echo "Sem o inciso\n";
    $r = chamar($agente, 'PATCH', $base + ['tipo' => 'auto_infracao', 'lavratura_imediata_item' => null, 'autuado_email' => ''], fn ($ctl, $q) => $ctl->update($q, $doc));
    confere($r->getStatusCode() === 200, 'o rascunho é alterado');
    $doc->refresh();
    confere($doc->lavratura_imediata === null && in_array('o inciso da lavratura imediata (art. 16)', $doc->pendenciasDePosturas(), true), 'sem inciso, a lavratura acusa a falta');
    confere(Contribuinte::where('cpf_cnpj', '11.222.333/0001-81')->value('email') === 'contato@barteste.com.br', 'e-mail apagado na peça não apaga o do cadastro');

    echo "Auto que nasce de notificação\n";
    $notif = Documento::create(['modulo' => 'posturas', 'tipo' => 'notificacao', 'status' => 'lavrado', 'agente_id' => $agente->id,
        'legislacao_id' => $lei->id, 'data_fato' => now(), 'autuado_documento' => '11.222.333/0001-81', 'numero' => 999, 'exercicio' => (int) date('Y')]);
    $doc->origem_id = $notif->id;
    $doc->save();
    $doc->load('origem');
    confere(! $doc->pedeLavraturaImediata(), 'com notificação na origem, não há lavratura imediata');

    echo "A notificação\n";
    $r = chamar($agente, 'POST', $base + ['tipo' => 'notificacao', 'prazo_dias' => 0, 'lavratura_imediata_item' => 1], fn ($ctl, $q) => $ctl->storeSemLote($q));
    $n = Documento::find($r->getData(true)['documento']['id']);
    confere($n->prazo_dias === 0, 'prazo 0 (imediato) é aceito');
    confere($n->lavratura_imediata === null, 'notificação não guarda inciso de lavratura imediata');

    echo "Obras não muda\n";
    $obras = User::create(['name' => 'Agente Teste Obras', 'email' => 'agente.obras.' . uniqid() . '@local.test',
        'password' => bcrypt(bin2hex(random_bytes(8))), 'perfil' => 'comum', 'tipo_usuario' => 'agente', 'modulos' => ['obras'], 'ativo' => true]);
    $antes = Contribuinte::count();
    $r = chamar($obras, 'POST', ['tipo' => 'notificacao', 'autuado_nome' => 'FULANO', 'autuado_documento' => '529.982.247-25',
        'autuado_inscricao_municipal' => 'X', 'autuado_email' => 'a@b.com', 'local_lat' => -15.5, 'local_lng' => -54.3], fn ($ctl, $q) => $ctl->storeSemLote($q));
    $o = Documento::find($r->getData(true)['documento']['id']);
    confere($o->modulo === 'obras' && $o->autuado_inscricao_municipal === null && $o->autuado_email === null && $o->local_gps === null, 'em Obras os campos de Posturas ficam nulos');
    confere(Contribuinte::count() === $antes, 'e a peça de Obras não mexe no cadastro de contribuintes');
} finally {
    DB::rollBack();
}

echo "\n{$ok} conferências ok\n";
