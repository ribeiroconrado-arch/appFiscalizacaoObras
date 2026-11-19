<?php
// Diagnóstico local, numa transação DESFEITA no fim. Quadras por decreto
// (19/11/2026): quadra sem marcação é da aprovação; ampliação e etapa marcam
// as suas; regularização, retificação e alteração marcam as que alteraram.
//
//   php tests/decretos-quadras-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\DecretosDasQuadras;
use App\Http\Controllers\DecretosDasQuadrasController;
use App\Models\BairroAto;
use App\Models\CadastroBairro;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

/** GET pela pilha HTTP; PUT direto no controlador (sem sessão, não há token CSRF). */
function pedir(User $u, string $metodo, string $url, array $corpo = []): Symfony\Component\HttpFoundation\Response {
    global $kernel, $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create($url, $metodo, $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    if ($metodo === 'GET') {
        return $kernel->handle($req);
    }
    try {
        return $app->make(DecretosDasQuadrasController::class)->gravar($req);
    } catch (Illuminate\Validation\ValidationException $e) {
        return response()->json(['errors' => $e->errors()], 422);
    }
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador ativo para o teste local.'); }
$leitor = User::where('ativo', true)->get()->first(fn (User $u) => ! $u->podeCurarCadastro() && ! $u->isAdmin());

DB::beginTransaction();
try {
    $nome = 'Bairro Teste Decretos';
    $cb = CadastroBairro::create(['codigo' => '986', 'nome_cadastro' => 'BAIRRO TESTE DECRETOS', 'nome_gis' => $nome]);
    $ato = fn (string $tipo, string $num, string $data, ?float $area = null) => BairroAto::create(
        ['cadastro_bairro_id' => $cb->id, 'tipo' => $tipo, 'ato' => "Decreto {$num}", 'data' => $data, 'area_m2' => $area]);
    $aprov = $ato('aprovacao', '74/1988', '1988-03-01', 20000);
    $d = new DecretosDasQuadras();

    echo "Decreto único\n";
    $r = $d->doBairro($cb->fresh());
    confere($r['padrao'] === $aprov->id, 'a aprovação é o padrão do bairro');
    confere($r['criou'] === [] && $r['alterou'] === [], 'nada gravado: toda quadra já é da aprovação');

    // Quatro quadras de ~100 m × 100 m (as áreas da conferência).
    foreach (['1', '2', '3', '4'] as $i => $n) {
        $x = -54.30 + $i * 0.002;
        DB::insert("INSERT INTO quadras (bairro, numero, geom, rotulo_lat, rotulo_lon) VALUES (?, ?,
            ST_GeomFromText(?, 4326, 'axis-order=long-lat'), -15.55, ?)",
            [$nome, $n, sprintf('MULTIPOLYGON(((%1$.6f -15.55, %2$.6f -15.55, %2$.6f -15.549, %1$.6f -15.549, %1$.6f -15.55)))', $x, $x + 0.00093), $x]);
    }

    echo "Ampliação marca as suas quadras\n";
    $amp = $ato('ampliacao', '522/1998', '1998-05-10', 10000);
    $etapa = $ato('etapa', '600/2000', '2000-01-01');
    $reg = $ato('regularizacao', '1183/2011', '2011-06-01');
    $rev = $ato('revogacao', '9/2015', '2015-01-01');
    $d->gravar($cb, ['03' => $amp->id, '4' => $amp->id, '1' => $aprov->id], ['2' => [$reg->id]]);
    $r = $d->doBairro($cb->fresh());
    confere($r['criou'] === ['3' => $amp->id, '4' => $amp->id], 'quadras 3 e 4 da ampliação, gravadas sem zero');
    confere(DB::table('bairro_ato_quadras')->where('bairro_ato_id', $aprov->id)->doesntExist(), 'marcar a aprovação não grava nada (é o padrão)');
    confere($r['alterou'] === ['2' => [$reg->id]], 'a quadra 2 alterada pela regularização');
    confere(! in_array($rev->id, array_column($r['atos'], 'id'), true), 'a revogação não entra na lista de quem marca quadra');
    confere(array_column($r['atos'], 'papel', 'id')[$etapa->id] === 'criou', 'etapa cria quadra');

    echo "Trocar e devolver\n";
    $d->gravar($cb, ['3' => $etapa->id], []);
    $r = $d->doBairro($cb->fresh());
    confere($r['criou'] === ['3' => $etapa->id], 'a 3 passa para a etapa e a 4 volta à aprovação');
    confere($r['alterou'] === [], 'a regularização desmarcada sai');

    echo "Recusas\n";
    $recusou = fn (callable $f) => (function () use ($f) { try { $f(); return false; } catch (Illuminate\Validation\ValidationException) { return true; } })();
    confere($recusou(fn () => $d->gravar($cb, ['1' => $reg->id], [])), 'regularização não cria quadra');
    confere($recusou(fn () => $d->gravar($cb, [], ['1' => [$amp->id]])), 'ampliação não entra em "alterou"');
    $outro = CadastroBairro::create(['codigo' => '985', 'nome_cadastro' => 'OUTRO TESTE', 'nome_gis' => 'Outro Teste Decretos']);
    $alheio = BairroAto::create(['cadastro_bairro_id' => $outro->id, 'tipo' => 'ampliacao', 'ato' => 'Decreto 1/2000']);
    confere($recusou(fn () => $d->gravar($cb, ['1' => $alheio->id], [])), 'ato de outro bairro é recusado');
    confere($d->doBairro($cb->fresh())['criou'] === ['3' => $etapa->id], 'e a recusa não mexeu no que estava gravado');

    echo "Pela rota\n";
    $resp = pedir($admin, 'GET', '/api/bairros/decretos-das-quadras?areas=1&bairro=' . rawurlencode($nome));
    $j = json_decode($resp->getContent(), true);
    confere($resp->getStatusCode() === 200 && $j['padrao'] === $aprov->id, 'a leitura responde com o padrão');
    confere(count($j['areas']) === 4 && abs($j['areas']['1'] - 11000) < 300, 'e a área de cada quadra (~11.000 m²)');
    $resp = pedir($admin, 'PUT', '/api/bairros/decretos-das-quadras', ['bairro' => $nome, 'criou' => ['2' => $amp->id], 'alterou' => []]);
    confere($resp->getStatusCode() === 200 && json_decode($resp->getContent(), true)['criou'] === ['2' => $amp->id], 'o administrador grava');
    if ($leitor) {
        $resp = pedir($leitor, 'PUT', '/api/bairros/decretos-das-quadras', ['bairro' => $nome, 'criou' => []]);
        confere($resp->getStatusCode() === 403, 'quem não é da curadoria não grava');
    }
    $resp = pedir($admin, 'PUT', '/api/bairros/decretos-das-quadras', ['bairro' => 'Bairro Que Não Existe', 'criou' => []]);
    confere($resp->getStatusCode() === 422, 'bairro sem cadastro é recusado');

    echo "Ato excluído\n";
    $amp->delete();
    confere(DB::table('bairro_ato_quadras')->where('bairro_ato_id', $amp->id)->doesntExist(), 'as quadras dele voltam à aprovação');
    confere(DB::table('auditoria')->where('tabela', 'bairro_ato_quadras')->exists(), 'as marcações entram na auditoria');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
