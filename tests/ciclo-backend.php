<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige a migração 2026_10_21_000100 aplicada, um
// administrador agente ativo e um lote.
//
//   php tests/ciclo-backend.php
//
// Cobre o CICLO DA PEÇA:
//   rascunho  sem número   edita e exclui
//   gravado   com número   edita e cancela (com motivo); não se exclui
//   lavrado   com número   não edita; cancela com motivo e a senha de quem cancela

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\DocumentoController;
use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\Lote;
use App\Models\User;
use App\Services\DocumentoImpressao;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}
function chamar(User $u, string $metodo, array $corpo = [], array $args = [], string $verbo = 'POST'): array {
    global $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create('/x', $verbo, $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    try {
        $r = $app->call([$app->make(DocumentoController::class), $metodo], ['request' => $req] + $args);
    } catch (Illuminate\Validation\ValidationException $e) {
        return [422, ['errors' => $e->errors(), 'message' => collect($e->errors())->flatten()->first()]];
    }
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}
/** Uma "assinatura": PNG transparente com um traço, como o canvas produz. */
function rabisco(): string {
    $img = imagecreatetruecolor(300, 120);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagesetthickness($img, 3);
    imageline($img, 60, 40, 220, 90, imagecolorallocate($img, 27, 42, 39));
    ob_start(); imagepng($img); $png = ob_get_clean();
    return 'data:image/png;base64,' . base64_encode($png);
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador agente ativo para o teste local.'); }
$lote = Lote::where('situacao', 'ativo')->where('em_revisao', false)->first();
if (! $lote) { throw new RuntimeException('Sem lote para o teste local.'); }

DB::beginTransaction();
try {
    $lei = Legislacao::create(['numero' => 'TESTE CICLO 1/2099', 'nome' => 'Lei de teste do ciclo', 'ano' => 2099,
        'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true]);
    $art = Artigo::create(['legislacao_id' => $lei->id, 'numero' => 'Art. C1', 'apelido' => 'C1', 'conduta' => 'texto',
        'base_multa' => 'sem_multa', 'ativo' => true, 'documentos' => Artigo::DOCUMENTOS]);
    // A senha do teste é sorteada aqui e só vale dentro da transação.
    $senha = bin2hex(random_bytes(8));
    $admin->forceFill(['assinatura' => rabisco(), 'password' => bcrypt($senha)])->save();
    $outro = User::create(['name' => 'OUTRO FISCAL CICLO', 'email' => 'outro-ciclo@teste.local', 'password' => bcrypt(bin2hex(random_bytes(8))),
        'perfil' => 'comum', 'tipo_usuario' => 'agente', 'ativo' => true]);

    $corpo = ['tipo' => 'notificacao', 'legislacao_id' => $lei->id, 'artigos' => [$art->id], 'prazo_dias' => 5, 'autuado_nome' => 'FULANO DE TAL'];
    $rascunho = function (array $mais = []) use ($admin, $corpo, $lote): Documento {
        [, $d] = chamar($admin, 'store', $mais + $corpo, ['lote' => $lote]);
        return Documento::find($d['documento']['id']);
    };
    $editar = fn (Documento $doc, array $mais = []) => chamar($admin, 'update', $mais + ['data_fato' => now()->format('Y-m-d\TH:i')] + $corpo, ['documento' => $doc], 'PATCH');
    $ultimo = fn (string $tipo) => (int) (DB::table('documento_contadores')->where('tipo', $tipo)->where('exercicio', (int) now()->format('Y'))->value('ultimo') ?? 0);
    $opcoes = fn (Documento $doc, User $u) => $doc->fresh()->opcoesPara($u);

    echo "Rascunho: sem número, edita e exclui\n";
    $antes = $ultimo('notificacao');
    $r = $rascunho();
    confere($r->status === 'rascunho' && $r->numero === null && $ultimo('notificacao') === $antes, 'salvar rascunho NÃO gasta número');
    confere(in_array('excluir', $opcoes($r, $admin), true) && ! in_array('cancelar', $opcoes($r, $admin), true) && ! in_array('lavrar', $opcoes($r, $admin), true),
        'rascunho oferece excluir; não cancelar nem lavrar');
    [$s] = $editar($r, ['autuado_nome' => 'FULANO EDITADO']);
    confere($s === 200 && $r->fresh()->autuado_nome === 'FULANO EDITADO', 'rascunho é editável');
    [$s] = chamar($admin, 'cancelar', ['motivo' => 'Cancelando um rascunho, o que não existe.'], ['documento' => $r]);
    confere($s === 403, 'rascunho não se cancela');
    [$s] = chamar($admin, 'destroy', [], ['documento' => $r], 'DELETE');
    confere($s === 200 && Documento::find($r->id) === null, 'rascunho é excluído');

    echo "Gravar: ganha número\n";
    $semNome = $rascunho(['autuado_nome' => null]);
    [$s, $d] = chamar($admin, 'gravar', [], ['documento' => $semNome]);
    confere($s === 422 && str_contains($d['message'], 'autuado'), 'sem o nome do autuado, não grava');
    $g = $rascunho();
    [$s] = chamar($outro, 'gravar', [], ['documento' => $g]);
    confere($s === 403, 'só o autor grava');
    [$s, $d] = chamar($admin, 'gravar', [], ['documento' => $g]);
    $g->refresh();
    confere($s === 200 && $g->status === 'gravado' && (int) $g->numero === $antes + 1 && str_contains($d['message'], $g->numeroFormatado()),
        'gravado: recebe o próximo número da série, e a resposta o informa');
    [$s] = chamar($admin, 'gravar', [], ['documento' => $g]);
    confere($s === 422, 'não se grava duas vezes');
    confere(str_contains(app(DocumentoImpressao::class)->montar($g)['marca'] ?? '', 'NÃO LAVRADO'), 'a via do gravado sai com a marca NÃO LAVRADO');

    echo "Gravado: edita, não exclui, não troca de tipo\n";
    $op = $opcoes($g, $admin);
    confere(in_array('lavrar', $op, true) && in_array('cancelar', $op, true) && ! in_array('excluir', $op, true), 'gravado oferece lavrar e cancelar; não excluir');
    [$s] = $editar($g, ['autuado_nome' => 'FULANO GRAVADO']);
    confere($s === 200 && $g->fresh()->autuado_nome === 'FULANO GRAVADO' && (int) $g->fresh()->numero === $antes + 1, 'gravado é editável e conserva o número');
    [$s] = chamar($admin, 'destroy', [], ['documento' => $g], 'DELETE');
    confere($s === 403 && Documento::find($g->id) !== null, 'gravado NÃO se exclui');
    [$s, $d] = $editar($g, ['tipo' => 'auto_embargo']);
    confere($s === 422 && str_contains($d['message'], 'não muda de tipo'), 'gravado não troca de tipo (o número é da série dele)');

    echo "Cancelar o gravado: basta o motivo\n";
    $g2 = $rascunho(); chamar($admin, 'gravar', [], ['documento' => $g2]);
    [$s] = chamar($admin, 'cancelar', ['motivo' => 'curto'], ['documento' => $g2]);
    confere($s === 422, 'sem justificativa suficiente, não cancela');
    [$s] = chamar($outro, 'cancelar', ['motivo' => 'Outro fiscal tentando cancelar a peça.'], ['documento' => $g2]);
    confere($s === 403, 'outro fiscal não cancela');
    [$s] = chamar($admin, 'cancelar', ['motivo' => 'Aberto em duplicidade com outra notificação.'], ['documento' => $g2]);
    $g2->refresh();
    confere($s === 200 && $g2->status === 'cancelado' && (int) $g2->numero === $antes + 2 && $g2->anulado_por === $admin->id
        && str_contains((string) $g2->anulacao_motivo, 'duplicidade'), 'cancelado sem senha: fica na série, com quem e por quê');
    [$s] = $editar($g2);
    confere($s === 422, 'cancelado não se edita');
    confere(! in_array('cancelar', $opcoes($g2, $admin), true), 'nem se cancela de novo');

    echo "Lavrar\n";
    [$s] = chamar($admin, 'lavrar', ['assinatura_autuado' => rabisco()], ['documento' => $g]);
    $g->refresh();
    confere($s === 200 && $g->status === 'lavrado' && (int) $g->numero === $antes + 1 && $ultimo('notificacao') === $antes + 2,
        'lavrado conserva o número do gravado, sem gastar outro');
    [$s] = $editar($g);
    confere($s === 422, 'lavrado não se edita');
    [$s] = chamar($admin, 'destroy', [], ['documento' => $g], 'DELETE');
    confere($s === 403, 'nem se exclui');

    echo "Cancelar o lavrado: motivo e senha\n";
    [$s, $d] = chamar($admin, 'cancelar', ['motivo' => 'Lavrado contra o imóvel errado.'], ['documento' => $g]);
    confere($s === 422 && str_contains($d['message'], 'Senha'), 'sem senha, não cancela');
    [$s] = chamar($admin, 'cancelar', ['motivo' => 'Lavrado contra o imóvel errado.', 'senha' => 'senha-errada'], ['documento' => $g]);
    confere($s === 422 && $g->fresh()->status === 'lavrado', 'com a senha errada, também não');
    [$s] = chamar($admin, 'cancelar', ['motivo' => 'Lavrado contra o imóvel errado.', 'senha' => $senha], ['documento' => $g]);
    $g->refresh();
    confere($s === 200 && $g->status === 'cancelado' && (int) $g->numero === $antes + 1, 'com motivo e a senha de quem cancela: cancelado, e o número fica');
    confere(str_contains(app(DocumentoImpressao::class)->montar($g)['marca'] ?? '', 'CANCELADO'), 'a via sai com a marca CANCELADO');

    echo "Peça que não vale como ato\n";
    $g3 = $rascunho(); chamar($admin, 'gravar', [], ['documento' => $g3]);
    [, $o] = chamar($admin, 'origens', ['tipo' => 'auto_infracao', 'lote_id' => $lote->id], [], 'GET');
    $ids = collect($o['origens'] ?? $o)->pluck('id')->all();
    confere(! in_array($g3->id, $ids, true) && ! in_array($g->id, $ids, true), 'gravada e cancelada não servem de origem a um auto');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
