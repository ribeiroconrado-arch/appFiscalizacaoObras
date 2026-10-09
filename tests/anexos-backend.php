<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim; os arquivos gravados no disco privado são apagados no fim.
// Exige a migração 2026_10_20_000100 aplicada e um administrador agente ativo.
//
//   php tests/anexos-backend.php
//
// Cobre os anexos próprios do documento: juntar foto e PDF, trazer da vistoria
// e da peça de origem (por escolha), quem altera e quem exclui antes e depois
// da lavratura, o que sai na impressão, e o auto de infração sem vistoria.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\DocumentoAnexoController as Anexos;
use App\Http\Controllers\DocumentoController;
use App\Models\Artigo;
use App\Models\Documento;
use App\Models\DocumentoAnexo;
use App\Models\Evidencia;
use App\Models\Legislacao;
use App\Models\Lote;
use App\Models\User;
use App\Models\Vistoria;
use App\Services\DocumentoImpressao;
use App\Services\LavraturaService;
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
        return [422, ['message' => collect($e->errors())->flatten()->first()]];
    }
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}
$temporarios = [];
/** Um JPEG de verdade, como a tela envia depois de preparar a foto. */
function foto(string $nome = 'foto.jpg'): UploadedFile {
    global $temporarios;
    $img = imagecreatetruecolor(320, 240);
    imagefill($img, 0, 0, imagecolorallocate($img, 110, 140, 120));
    $caminho = tempnam(sys_get_temp_dir(), 'anx') . '.jpg';
    imagejpeg($img, $caminho, 80);
    $temporarios[] = $caminho;
    return new UploadedFile($caminho, $nome, 'image/jpeg', null, true);
}
function pdf(): UploadedFile {
    global $temporarios;
    $caminho = tempnam(sys_get_temp_dir(), 'anx') . '.pdf';
    file_put_contents($caminho, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    $temporarios[] = $caminho;
    return new UploadedFile($caminho, 'alvara.pdf', 'application/pdf', null, true);
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador agente ativo para o teste local.'); }
$lote = Lote::where('situacao', 'ativo')->where('em_revisao', false)->first();
if (! $lote) { throw new RuntimeException('Sem lote para o teste local.'); }

$gravados = [];
DB::beginTransaction();
try {
    $outroAdmin = User::create(['name' => 'OUTRO ADMIN TESTE', 'email' => 'outro-admin-anexos@teste.local', 'password' => bcrypt(bin2hex(random_bytes(8))),
        'perfil' => 'admin', 'tipo_usuario' => 'agente', 'ativo' => true]);
    $lei = Legislacao::create(['numero' => 'TESTE ANX 1/2099', 'nome' => 'Lei de teste dos anexos', 'ano' => 2099,
        'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true]);
    $art = Artigo::create(['legislacao_id' => $lei->id, 'numero' => 'Art. A1', 'apelido' => 'A1', 'conduta' => 'texto',
        'base_multa' => 'sem_multa', 'ativo' => true, 'documentos' => Artigo::DOCUMENTOS]);
    $peca = function (string $tipo, array $extra = []) use ($admin, $lei, $art, $lote): array {
        return chamar($admin, DocumentoController::class, 'store',
            ['tipo' => $tipo, 'legislacao_id' => $lei->id, 'artigos' => [$art->id], 'prazo_dias' => 5] + $extra, ['lote' => $lote]);
    };
    $juntar = function (User $u, Documento $doc, UploadedFile $arq, array $extra = []) use (&$gravados): array {
        $r = chamar($u, Anexos::class, 'store', $extra, ['documento' => $doc], 'POST', ['arquivo' => $arq]);
        if ($r[0] === 201) { $gravados[] = DocumentoAnexo::find($r[1]['anexo']['id'])->arquivo; }
        return $r;
    };

    echo "Juntar na própria peça\n";
    [, $d] = $peca('notificacao');
    $not = Documento::find($d['documento']['id']);
    confere($not->anexos_proprios === true || (bool) $not->fresh()->anexos_proprios, 'peça nova nasce com anexos próprios (não imprime mais a vistoria inteira)');
    [$s, $d] = $juntar($admin, $not, foto('fachada.jpg'), ['titulo' => 'FACHADA DA OBRA']);
    confere($s === 201 && $d['anexo']['foto'] === true && $d['anexo']['titulo'] === 'FACHADA DA OBRA' && $d['anexo']['juntado_depois'] === false, 'foto entra, com título');
    $a1 = DocumentoAnexo::find($d['anexo']['id']);
    confere(Storage::disk('private')->exists($a1->arquivo), 'o arquivo fica no disco privado');
    [$s, $d] = $juntar($admin, $not, pdf());
    confere($s === 201 && $d['anexo']['foto'] === false && $d['anexo']['titulo'] === 'alvara', 'PDF entra como arquivo, com o nome por título');
    $a2 = DocumentoAnexo::find($d['anexo']['id']);
    $txt = tempnam(sys_get_temp_dir(), 'anx'); file_put_contents($txt, 'texto'); $temporarios[] = $txt;
    [$s] = chamar($admin, Anexos::class, 'store', [], ['documento' => $not], 'POST', ['arquivo' => new UploadedFile($txt, 'nota.txt', 'text/plain', null, true)]);
    confere($s === 422, 'outro tipo de arquivo é recusado');
    [$s] = $juntar($outroAdmin, $not, foto());
    confere($s === 403, 'em rascunho, só o autor junta — nem o administrador');

    echo "Alterar e ordenar\n";
    [$s] = chamar($admin, Anexos::class, 'update', ['titulo' => 'FRENTE DO IMÓVEL', 'imprime' => false], ['anexo' => $a1], 'PATCH');
    confere($s === 200 && $a1->fresh()->titulo === 'FRENTE DO IMÓVEL' && $a1->fresh()->imprime === false, 'o autor muda o título e tira da impressão');
    [$s] = chamar($outroAdmin, Anexos::class, 'update', ['titulo' => 'X'], ['anexo' => $a1], 'PATCH');
    confere($s === 403, 'outro usuário não altera');
    [$s] = chamar($admin, Anexos::class, 'ordenar', ['ids' => [$a2->id, $a1->id]], ['documento' => $not]);
    confere($s === 200 && $not->anexos()->pluck('id')->all() === [$a2->id, $a1->id], 'a ordem escolhida é a que vale');

    echo "Fotos da vistoria: por escolha\n";
    $vistoria = Vistoria::create(['lote_id' => $lote->id, 'fiscal_id' => $admin->id, 'data_hora' => now(), 'situacao' => 'regular'] + (function () {
        // Colunas obrigatórias da vistoria que não têm padrão no banco local.
        return [];
    })());
    Storage::disk('private')->put('teste-anexos/evidencia.jpg', file_get_contents((string) foto()->getRealPath()));
    $gravados[] = 'teste-anexos/evidencia.jpg';
    $ev = Evidencia::create(['vistoria_id' => $vistoria->id, 'arquivo' => 'teste-anexos/evidencia.jpg', 'mime' => 'image/jpeg',
        'nome_original' => 'tapume.jpg', 'titulo' => 'TAPUME AUSENTE', 'data_hora' => now()->subDay(), 'criado_por' => $admin->id]);
    [, $d] = $peca('notificacao', ['vistoria_id' => $vistoria->id]);
    $comVistoria = Documento::find($d['documento']['id']);
    [, $l] = chamar($admin, Anexos::class, 'index', [], ['documento' => $comVistoria], 'GET');
    confere(count($l['anexos']) === 0 && count($l['da_vistoria']) === 1 && $l['da_vistoria'][0]['usada'] === false, 'a foto da vistoria aparece para escolher, e NÃO entra sozinha');
    confere(app(DocumentoImpressao::class)->montar($comVistoria->fresh())['anexos'] === [], 'sem escolher, nada da vistoria sai na impressão');
    [$s] = chamar($admin, Anexos::class, 'trazer', ['de' => 'vistoria', 'id' => $ev->id], ['documento' => $comVistoria]);
    $trazido = $comVistoria->anexos()->first();
    confere($s === 201 && $trazido->origem === 'vistoria' && $trazido->arquivo === $ev->arquivo, 'trazida por escolha, aponta para o mesmo arquivo (sem cópia)');
    [$s] = chamar($admin, Anexos::class, 'trazer', ['de' => 'vistoria', 'id' => $ev->id], ['documento' => $comVistoria]);
    confere($s === 422, 'a mesma foto não entra duas vezes');
    confere(count(app(DocumentoImpressao::class)->montar($comVistoria->fresh())['anexos']) === 1, 'agora ela sai na impressão');
    [$s] = chamar($admin, Anexos::class, 'destroy', [], ['anexo' => $trazido], 'DELETE');
    confere($s === 200 && Storage::disk('private')->exists($ev->arquivo), 'excluir o trazido não apaga o arquivo da vistoria');

    echo "Auto de infração: sem vistoria, com os anexos da peça de origem\n";
    $admin->forceFill(['assinatura' => $admin->assinatura ?: 'data:image/png;base64,AAAA'])->save();
    $a1->update(['imprime' => true]);
    $lavrada = app(LavraturaService::class)->lavrar($not->fresh());
    [$s, $d] = $peca('auto_infracao', ['vistoria_id' => $vistoria->id, 'origem_id' => $lavrada->id]);
    $auto = Documento::find($d['documento']['id'] ?? 0);
    confere($s === 201 && $auto->vistoria_id === null && $auto->origem_id === $lavrada->id, 'auto de infração NÃO fica vinculado à vistoria; fica à peça de origem');
    [, $l] = chamar($admin, Anexos::class, 'index', [], ['documento' => $auto], 'GET');
    confere($l['da_vistoria'] === [] && count($l['da_origem']) === 2 && str_contains($l['origem'], 'Notificação'), 'os anexos da notificação de origem aparecem para escolher');
    [$s] = chamar($admin, Anexos::class, 'trazer', ['de' => 'vistoria', 'id' => $ev->id], ['documento' => $auto]);
    confere($s === 422, 'foto de vistoria não entra em auto de infração');
    [$s] = chamar($admin, Anexos::class, 'trazer', ['de' => 'documento', 'id' => $a1->id], ['documento' => $auto]);
    confere($s === 201 && $auto->anexos()->first()->arquivo === $a1->arquivo, 'o anexo da origem é herdado por escolha');
    [$s] = chamar($admin, Anexos::class, 'trazer', ['de' => 'documento', 'id' => 999999999], ['documento' => $auto]);
    confere($s === 422, 'anexo que não é da peça de origem é recusado');

    echo "Depois da lavratura\n";
    [$s, $d] = $juntar($admin, $lavrada, foto('depois.jpg'));
    confere($s === 201 && $d['anexo']['juntado_depois'] === true, 'a juntada continua aberta, e sai marcada "juntado depois"');
    $depois = DocumentoAnexo::find($d['anexo']['id']);
    [$s, $d2] = $juntar($outroAdmin, $lavrada, foto('do-admin.jpg'));
    confere($s === 201, 'o administrador também pode juntar à peça lavrada');
    $doAdmin = DocumentoAnexo::find($d2['anexo']['id']);
    [$s] = chamar($outroAdmin, Anexos::class, 'destroy', [], ['anexo' => $depois], 'DELETE');
    confere($s === 403, 'EXCLUIR: administrador não é exceção — nem o anexo que ele mesmo não juntou');
    [$s] = chamar($outroAdmin, Anexos::class, 'destroy', [], ['anexo' => $doAdmin], 'DELETE');
    confere($s === 403, '…nem o que ele juntou: só quem lavrou exclui');
    [$s] = chamar($admin, Anexos::class, 'destroy', [], ['anexo' => $a1->fresh()], 'DELETE');
    confere($s === 200 && Storage::disk('private')->exists($a1->arquivo), 'quem lavrou exclui — e o arquivo fica, porque o auto o herdou');
    [$s] = chamar($admin, Anexos::class, 'destroy', [], ['anexo' => $depois], 'DELETE');
    confere($s === 200 && ! Storage::disk('private')->exists($depois->arquivo), 'anexo que ninguém mais usa: o arquivo sai junto');
    $linhas = app(DocumentoImpressao::class)->montar($lavrada->fresh())['anexos'];
    confere(count($linhas) === 2 && collect($linhas)->contains(fn ($x) => str_contains((string) $x['descricao'], 'Juntado depois da lavratura'))
        && collect($linhas)->contains(fn ($x) => $x['foto'] === false && str_contains((string) $x['descricao'], 'Arquivo PDF')),
        'na impressão: o PDF entra como arquivo, e o juntado depois vem identificado');

    $lavrada->update(['status' => 'anulado']);
    [$s] = $juntar($admin, $lavrada->fresh(), foto());
    confere($s === 403, 'peça anulada não recebe anexo');
    [$s] = chamar($admin, Anexos::class, 'destroy', [], ['anexo' => $a2->fresh()], 'DELETE');
    confere($s === 403, 'nem perde');

    echo "Peça antiga continua como era\n";
    $antiga = Documento::find($comVistoria->id);
    $antiga->forceFill(['anexos_proprios' => false])->saveQuietly();
    confere(count(app(DocumentoImpressao::class)->montar($antiga->fresh())['anexos']) === 1, 'peça de antes desta mudança ainda imprime as fotos da vistoria');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
    foreach (array_unique($gravados) as $arq) { Storage::disk('private')->delete($arq); }
    Storage::disk('private')->deleteDirectory('teste-anexos');
    foreach ($temporarios as $t) { @unlink($t); @unlink(preg_replace('/\.(jpg|pdf)$/', '', $t)); }
}
