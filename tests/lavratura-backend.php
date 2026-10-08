<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige a migração 2026_10_18_000100 aplicada e um
// administrador agente ativo.
//
//   php tests/lavratura-backend.php
//
// Cobre a LAVRATURA como ato: a assinatura do fiscal (do cadastro), a do
// autuado colhida na tela, e a recusa com testemunha (nome e assinatura) — o
// que o servidor exige, o que grava e o que sai na impressão.

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
use App\Services\Assinatura;
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
    $lei = Legislacao::create(['numero' => 'TESTE LAV 1/2099', 'nome' => 'Lei de teste da lavratura', 'ano' => 2099,
        'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true]);
    $art = Artigo::create(['legislacao_id' => $lei->id, 'numero' => 'Art. L1', 'apelido' => 'L1', 'conduta' => 'texto',
        'base_multa' => 'sem_multa', 'ativo' => true, 'documentos' => Artigo::DOCUMENTOS]);
    $rascunho = function () use ($admin, $lei, $art, $lote): Documento {
        [, $d] = chamar($admin, 'store', ['tipo' => 'notificacao', 'legislacao_id' => $lei->id, 'artigos' => [$art->id],
            'prazo_dias' => 5, 'autuado_nome' => 'FULANO DE TAL'], ['lote' => $lote]);
        return Documento::find($d['documento']['id']);
    };
    $lavrar = fn (Documento $doc, array $corpo) => chamar($admin, 'lavrar', $corpo, ['documento' => $doc]);

    echo "A assinatura do fiscal\n";
    $admin->forceFill(['assinatura' => null])->save();
    [$s, $d] = $lavrar($rascunho(), ['assinatura_autuado' => rabisco()]);
    confere($s === 422 && str_contains($d['message'], 'Meu perfil'), 'fiscal sem assinatura cadastrada não lavra');
    $admin->forceFill(['assinatura' => app(Assinatura::class)->aparar(rabisco())])->save();
    [, $d] = chamar($admin, 'testemunhas', [], [], 'GET');
    confere($d['minha_assinatura'] === $admin->assinatura && ! collect($d['usuarios'])->contains('nome', $admin->name),
        'a tela recebe a rubrica do fiscal, e ele não aparece como testemunha de si mesmo');

    echo "O que o ato exige\n";
    [$s, $d] = $lavrar($rascunho(), []);
    confere($s === 422 && str_contains($d['message'], 'assinatura do autuado'), 'sem assinatura do autuado e sem recusa: não lavra');
    [$s] = $lavrar($rascunho(), ['recusa' => true]);
    confere($s === 422, 'recusa sem testemunha: não lavra');
    [$s, $d] = $lavrar($rascunho(), ['recusa' => true, 'testemunha_nome' => 'BELTRANO']);
    confere($s === 422 && str_contains($d['message'], 'testemunha'), 'recusa sem a assinatura da testemunha: não lavra');
    [$s] = $lavrar($rascunho(), ['assinatura_autuado' => 'data:image/jpeg;base64,AAAA']);
    confere($s === 422, 'imagem que não é PNG de canvas é recusada');

    echo "Assinado pelo autuado\n";
    $doc = $rascunho();
    [$s, $d] = $lavrar($doc, ['assinatura_autuado' => rabisco()]);
    $doc->refresh();
    confere($s === 200 && $doc->status === 'lavrado' && $doc->numero > 0, 'lavra e numera');
    confere(str_starts_with((string) $doc->assinatura_autuado, 'data:image/png;base64,') && $doc->assinatura_agente === $admin->assinatura
        && $doc->recusa_assinatura === null && $doc->testemunha_nome === null, 'guarda a assinatura do autuado e a rubrica do fiscal, sem recusa');
    [, $f] = chamar($admin, 'ficha', [], ['documento' => $doc], 'GET');
    confere($f['assinaturas']['recusa'] === false && $f['assinaturas']['autuado'] === $doc->assinatura_autuado
        && $f['assinaturas']['agente'] === $doc->assinatura_agente, 'a ficha devolve as assinaturas para o resumo');
    $html = view('impressao.a4', app(App\Services\DocumentoImpressao::class)->montar($doc))->render();
    confere(substr_count($html, 'class="assina-img"') === 2 && ! str_contains($html, 'Termo de Recusa'), 'no A4 saem as duas assinaturas, sem termo de recusa');

    echo "Recusa, com testemunha\n";
    $doc = $rascunho();
    [$s] = $lavrar($doc, ['recusa' => true, 'testemunha_nome' => ' BELTRANO DA SILVA ', 'assinatura_testemunha' => rabisco(), 'assinatura_autuado' => rabisco()]);
    $doc->refresh();
    confere($s === 200 && $doc->status === 'lavrado' && $doc->assinatura_autuado === null, 'lavra; com recusa, a assinatura do autuado NÃO é guardada');
    confere($doc->testemunha_nome === 'BELTRANO DA SILVA' && str_starts_with((string) $doc->assinatura_testemunha, 'data:image/png')
        && str_contains($doc->recusa_assinatura, 'Testemunha: BELTRANO DA SILVA'), 'guarda a testemunha (nome e assinatura) e o registro da recusa');
    [, $f] = chamar($admin, 'ficha', [], ['documento' => $doc], 'GET');
    confere($f['assinaturas']['recusa'] === true && $f['assinaturas']['autuado'] === null && $f['assinaturas']['testemunha_nome'] === 'BELTRANO DA SILVA',
        'a ficha diz que houve recusa e quem testemunhou');
    $html = view('impressao.a4', app(App\Services\DocumentoImpressao::class)->montar($doc))->render();
    confere(str_contains($html, 'Termo de Recusa') && str_contains($html, 'BELTRANO DA SILVA — Testemunha')
        && substr_count($html, 'class="assina-img"') === 2, 'no A4: termo de recusa, com a assinatura da testemunha (e a do fiscal)');
    $termica = view('impressao.termica', app(App\Services\DocumentoImpressao::class)->montar($doc))->render();
    confere(str_contains($termica, 'BELTRANO DA SILVA — Testemunha'), 'na térmica também');

    [$s] = $lavrar($doc, ['assinatura_autuado' => rabisco()]);
    confere($s === 422, 'peça já lavrada não se lavra de novo');
    [, $f] = chamar($admin, 'ficha', [], ['documento' => $rascunho()], 'GET');
    confere($f['assinaturas'] === null, 'rascunho não tem assinaturas');

    echo "Prévia A4 do que está na tela (sem gravar)\n";
    $previa = function (array $corpo) use ($admin, $app) {
        Auth::guard('web')->setUser($admin);
        $req = Illuminate\Http\Request::create('/x', 'POST', $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $req->setUserResolver(fn () => $admin);
        $app->instance('request', $req);
        try {
            $r = $app->call([$app->make(DocumentoController::class), 'previa'], ['request' => $req]);
        } catch (Illuminate\Validation\ValidationException $e) {
            return [422, ''];
        }
        return [$r->getStatusCode(), (string) $r->getContent()];
    };
    $antes = Documento::count();
    [$s, $html] = $previa(['tipo' => 'notificacao', 'legislacao_id' => $lei->id, 'artigos' => [$art->id], 'prazo_dias' => 7,
        'autuado_nome' => 'CICLANO DA PREVIA', 'descricao' => 'OBRA SEM TAPUME', 'lote_id' => $lote->id]);
    confere($s === 200 && str_contains($html, 'CICLANO DA PREVIA') && str_contains($html, 'OBRA SEM TAPUME') && str_contains($html, 'TESTE LAV 1/2099'),
        'peça nova: a via A4 traz o que foi digitado');
    confere(! str_contains($html, 'imp-barra') && ! str_contains($html, 'window.print'), 'sem a barra nem a impressão automática');
    confere(Documento::count() === $antes, 'e NADA fica gravado');

    $rasc = $rascunho();
    [$s, $html] = $previa(['documento_id' => $rasc->id, 'tipo' => 'notificacao', 'data_fato' => now()->format('Y-m-d\TH:i'),
        'legislacao_id' => $lei->id, 'artigos' => [$art->id], 'prazo_dias' => 5, 'autuado_nome' => 'NOME ALTERADO NA TELA']);
    confere($s === 200 && str_contains($html, 'NOME ALTERADO NA TELA'), 'rascunho em edição: a via mostra a alteração ainda não gravada');
    confere($rasc->fresh()->autuado_nome === 'FULANO DE TAL', 'e o rascunho no banco continua como estava');
    [$s] = $previa(['tipo' => 'notificacao', 'artigos' => [999999999]]);
    confere($s === 422, 'peça que o Gravar recusaria também é recusada na prévia');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
