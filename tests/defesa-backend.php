<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige a migração 2026_10_22_000100 aplicada, um
// administrador agente ativo e um lote.
//
//   php tests/defesa-backend.php
//
// Cobre a DEFESA do auto: o protocolo (autor ou administrador) leva a peça a
// "em defesa"; o julgamento (só o administrador) a leva a "defendido"
// (deferida) ou de volta a "lavrado", apta (indeferida).

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\DocumentoDefesaController as Defesa;
use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\Lote;
use App\Models\User;
use App\Services\DocumentoImpressao;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}
function chamar(User $u, string $classe, string $metodo, array $corpo = [], array $args = [], string $verbo = 'POST', array $arquivos = []): array {
    global $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create('/x', $verbo, $corpo, [], $arquivos, ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    try {
        $r = $app->call([$app->make($classe), $metodo], ['request' => $req] + $args);
    } catch (Illuminate\Validation\ValidationException $e) {
        return [422, ['errors' => $e->errors(), 'message' => collect($e->errors())->flatten()->first()]];
    }
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}
function rabisco(): string {
    $img = imagecreatetruecolor(300, 120);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagesetthickness($img, 3);
    imageline($img, 60, 40, 220, 90, imagecolorallocate($img, 27, 42, 39));
    ob_start(); imagepng($img); $png = ob_get_clean();
    return 'data:image/png;base64,' . base64_encode($png);
}
$temporarios = [];
function pdf(): UploadedFile {
    global $temporarios;
    $caminho = tempnam(sys_get_temp_dir(), 'def') . '.pdf';
    file_put_contents($caminho, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    $temporarios[] = $caminho;
    return new UploadedFile($caminho, 'defesa.pdf', 'application/pdf', null, true);
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador agente ativo para o teste local.'); }
$lote = Lote::where('situacao', 'ativo')->where('em_revisao', false)->first();
if (! $lote) { throw new RuntimeException('Sem lote para o teste local.'); }

$gravados = [];
DB::beginTransaction();
try {
    $lei = Legislacao::create(['numero' => 'TESTE DEF 1/2099', 'nome' => 'Lei de teste da defesa', 'ano' => 2099,
        'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true]);
    $art = Artigo::create(['legislacao_id' => $lei->id, 'numero' => 'Art. D1', 'apelido' => 'D1', 'conduta' => 'texto',
        'base_multa' => 'sem_multa', 'ativo' => true, 'documentos' => Artigo::DOCUMENTOS]);
    $admin->forceFill(['assinatura' => rabisco()])->save();
    $fiscal = User::create(['name' => 'FISCAL DA DEFESA', 'email' => 'fiscal-defesa@teste.local', 'password' => bcrypt(bin2hex(random_bytes(8))),
        'perfil' => 'comum', 'tipo_usuario' => 'agente', 'ativo' => true, 'assinatura' => rabisco()]);
    $outro = User::create(['name' => 'OUTRO FISCAL DEFESA', 'email' => 'outro-defesa@teste.local', 'password' => bcrypt(bin2hex(random_bytes(8))),
        'perfil' => 'comum', 'tipo_usuario' => 'agente', 'ativo' => true]);

    /** Uma peça lavrada pelo fiscal. */
    $lavrada = function (string $tipo) use ($fiscal, $lei, $art, $lote): Documento {
        [, $d] = chamar($fiscal, DocumentoController::class, 'store', ['tipo' => $tipo, 'legislacao_id' => $lei->id, 'artigos' => [$art->id],
            'prazo_dias' => 5, 'autuado_nome' => 'FULANO DE TAL'], ['lote' => $lote]);
        $doc = Documento::find($d['documento']['id']);
        [$s, $r] = chamar($fiscal, DocumentoController::class, 'lavrar', ['assinatura_autuado' => rabisco()], ['documento' => $doc]);
        if ($s !== 200) { throw new RuntimeException('não lavrou: ' . ($r['message'] ?? $s)); }
        return $doc->fresh();
    };
    $hoje = now()->format('Y-m-d');
    $protocolar = fn (User $u, Documento $doc, array $corpo = [], array $arq = []) =>
        chamar($u, Defesa::class, 'protocolar', $corpo + ['protocolo' => '2026/123', 'data_protocolo' => $hoje], ['documento' => $doc], 'POST', $arq);
    $julgar = fn (User $u, Documento $doc, string $resultado, array $arq = []) =>
        chamar($u, Defesa::class, 'julgar', ['resultado' => $resultado, 'data_resultado' => $hoje, 'parecer' => 'Parecer de teste do julgamento.'], ['documento' => $doc], 'POST', $arq);

    echo "Quem tem defesa\n";
    $not = $lavrada('notificacao');
    confere(! in_array('defesa', $not->opcoesPara($fiscal), true), 'notificação não tem defesa');
    [$s] = $protocolar($fiscal, $not);
    confere($s === 403, 'e o servidor recusa');
    $auto = $lavrada('auto_embargo');
    confere(in_array('defesa', $auto->opcoesPara($fiscal), true) && in_array('defesa', $auto->opcoesPara($admin), true), 'auto lavrado oferece a defesa ao autor e ao administrador');
    confere(! in_array('defesa', $auto->opcoesPara($outro), true), 'e não a outro fiscal');

    echo "O protocolo\n";
    [$s] = $protocolar($outro, $auto);
    confere($s === 403, 'outro fiscal não registra a defesa');
    [$s] = chamar($fiscal, Defesa::class, 'protocolar', ['protocolo' => '', 'data_protocolo' => $hoje], ['documento' => $auto]);
    confere($s === 422, 'sem número de protocolo, não registra');
    [$s] = $protocolar($fiscal, $auto, ['data_protocolo' => now()->addDay()->format('Y-m-d')]);
    confere($s === 422, 'data futura é recusada');
    [$s, $d] = $protocolar($fiscal, $auto, [], ['anexo' => pdf()]);
    $auto->refresh();
    if (! empty($auto->defesa['anexo']['arquivo'])) { $gravados[] = $auto->defesa['anexo']['arquivo']; }
    confere($s === 200 && $auto->status === 'em_defesa' && $auto->defesa['protocolo'] === '2026/123' && $auto->defesa['registrado_por'] === $fiscal->id,
        'o autor registra: a peça passa a "em defesa", com quem registrou');
    confere(Storage::disk('private')->exists($auto->defesa['anexo']['arquivo']) && $d['defesa']['anexo']['pdf'] === true, 'o arquivo da defesa fica guardado');
    confere($auto->statusBadge()[0] === 'Em defesa' && $auto->situacaoPrazo() === null, 'selo "Em defesa", e o prazo de defesa deixa de ser cobrado');
    confere(app(DocumentoImpressao::class)->montar($auto)['marca'] === null, 'em defesa a via continua valendo (sem marca)');
    [$s] = $protocolar($admin, $auto, ['protocolo' => '2026/124']);
    confere($s === 200 && $auto->fresh()->defesa['protocolo'] === '2026/124' && ! empty($auto->fresh()->defesa['anexo']), 'o protocolo pode ser corrigido antes do julgamento, e o arquivo fica');
    confere(in_array('cancelar', $auto->fresh()->opcoesPara($fiscal), true), 'em defesa, a peça ainda pode ser cancelada');

    echo "O julgamento\n";
    [$s] = $julgar($fiscal, $auto, 'deferida');
    confere($s === 403, 'o fiscal não julga');
    [$s] = $julgar($admin, $lavrada('auto_embargo'), 'deferida');
    confere($s === 422, 'sem defesa protocolada, não há o que julgar');
    [$s] = chamar($admin, Defesa::class, 'julgar', ['resultado' => 'deferida', 'data_resultado' => $hoje, 'parecer' => 'curto'], ['documento' => $auto]);
    confere($s === 422, 'sem o texto da decisão, não julga');
    [$s, $d] = $julgar($admin, $auto, 'deferida', ['anexo' => pdf()]);
    $auto->refresh();
    if (! empty($auto->defesa['julgamento_anexo']['arquivo'])) { $gravados[] = $auto->defesa['julgamento_anexo']['arquivo']; }
    confere($s === 200 && $auto->status === 'defendido' && $auto->defesa['resultado'] === 'deferida' && $auto->defesa['julgado_por'] === $admin->id,
        'DEFERIDA: a peça passa a "defendido"');
    confere($auto->encerrado() && in_array($auto->status, Documento::SEM_VALOR_DE_ATO, true), 'defendido não vale como ato (não gera custa, não serve de origem)');
    confere(app(DocumentoImpressao::class)->montar($auto)['marca'] === 'DEFENDIDO', 'e a via sai com a marca DEFENDIDO');
    confere(! in_array('cancelar', $auto->opcoesPara($admin), true) && in_array('defesa', $auto->opcoesPara($outro), true), 'defendido não se cancela; a defesa fica para consulta');
    [$s] = $julgar($admin, $auto, 'indeferida');
    confere($s === 422, 'julgada, a defesa não se julga de novo');
    [$s] = $protocolar($admin, $auto);
    confere($s === 403, 'nem se altera o protocolo');

    echo "Indeferida\n";
    $auto2 = $lavrada('auto_embargo');
    $protocolar($fiscal, $auto2);
    [$s, $d] = $julgar($admin, $auto2, 'indeferida');
    $auto2->refresh();
    confere($s === 200 && $auto2->status === 'lavrado' && $auto2->defesa['resultado'] === 'indeferida', 'INDEFERIDA: a peça volta a lavrado');
    confere($auto2->statusBadge()[0] === 'Lavrado · apto', 'com o selo "Lavrado · apto"');
    confere(! in_array($auto2->status, Documento::SEM_VALOR_DE_ATO, true) && app(DocumentoImpressao::class)->montar($auto2)['marca'] === null, 'e continua valendo como ato');
    confere(! $auto2->podeProtocolarDefesa($admin), 'não se abre nova defesa sobre a mesma peça');
    [, $ficha] = chamar($admin, DocumentoController::class, 'ficha', [], ['documento' => $auto2], 'GET');
    confere($ficha['defesa']['resultado'] === 'indeferida' && $ficha['defesa']['pode_julgar'] === false && $ficha['defesa']['parecer'] !== null, 'a ficha traz a defesa e o julgamento para consulta');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
    foreach ($gravados as $g) { Storage::disk('private')->delete($g); }
    foreach ($temporarios as $t) { @unlink($t); }
}
