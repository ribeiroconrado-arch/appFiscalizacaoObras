<?php
// Diagnóstico local, em transação DESFEITA no fim: contorno inválido e número
// repetido no arquivo NÃO barram mais o carregamento. Os lotes entram no
// rascunho, a ficha os lista como pendências do desenho, e quem segura o
// contorno inválido é o salvar().
//
//   php tests/importacao-pendencias-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Lote;
use App\Models\User;
use App\Services\ImportacaoDeBairro;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

$bairro = 'TESTE PENDENCIAS DO DESENHO';
$quadrado = fn (float $x, float $y) => [[[$x, $y], [$x + 0.0002, $y], [$x + 0.0002, $y + 0.0002], [$x, $y + 0.0002], [$x, $y]]];
// "Gravata": as diagonais se cruzam — inválido para o banco.
$gravata = fn (float $x, float $y) => [[[$x, $y], [$x + 0.0002, $y + 0.0002], [$x + 0.0002, $y], [$x, $y + 0.0002], [$x, $y]]];
$feicao = fn (string $q, string $l, array $coords) => ['type' => 'Feature',
    'properties' => ['bairro' => $bairro, 'quadra' => $q, 'numero_lote' => $l, 'chave' => "{$bairro}|{$q}|{$l}"],
    'geometry' => ['type' => 'Polygon', 'coordinates' => $coords]];

$arquivo = tempnam(sys_get_temp_dir(), 'imp') . '.geojson';
file_put_contents($arquivo, json_encode(['type' => 'FeatureCollection', 'features' => [
    $feicao('1', '7', $quadrado(-54.3000, -15.5000)),   // repetido
    $feicao('1', '7', $quadrado(-54.3005, -15.5000)),   // repetido
    $feicao('1', '8', $quadrado(-54.3010, -15.5000)),   // bom
    $feicao('1', '9', $gravata(-54.3015, -15.5000)),    // contorno cruzado
    'nonce' => uniqid('', true),                        // hash único a cada rodada
]]));

$svc = $app->make(ImportacaoDeBairro::class);
$autor = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->firstOrFail();
Auth::guard('web')->setUser($autor);

DB::beginTransaction();
try {
    echo "Leitura\n";
    $c = $svc->conferirArquivo($arquivo, 'pendencias.geojson');
    confere($c['repetidos_total'] === 1 && $c['pode_gravar'] === true,
        'número repetido aparece na leitura, mas não impede carregar');

    echo "Carregamento\n";
    $imp = $svc->gravar($arquivo, 'pendencias.geojson', $autor);
    $lotes = DB::table('lotes')->where('importacao_id', $imp->id)->get(['id', 'quadra', 'numero_lote']);
    confere($lotes->count() === 4, 'os 4 lotes entram no rascunho, inclusive o de contorno inválido');
    confere($lotes->whereNull('numero_lote')->count() === 2 && $lotes->where('numero_lote', '7')->count() === 0,
        'os dois de número repetido entram SEM número');
    confere($lotes->where('numero_lote', '8')->count() === 1 && $lotes->where('numero_lote', '9')->count() === 1,
        'os demais guardam o número');

    echo "Pendências do desenho\n";
    $p = $svc->pendenciasDoDesenho($imp->fresh());
    confere(count($p['invalidos']) === 1 && $p['invalidos'][0]['lote'] === '9', 'o contorno cruzado é listado, com o lote');
    confere(count($p['repetidos']) === 2 && $p['repetidos'][0]['lote'] === '7' && $p['repetidos'][0]['lote_id'] > 0,
        'os repetidos são listados com o número que tinham e o id para ir ao mapa');

    echo "O lote inválido não derruba o mapa\n";
    $repo = $app->make(App\Repositories\LoteRepository::class);
    $noMapa = $repo->porBbox(-54.3020, -15.5005, -54.2995, -15.4995, 100, true, null);
    confere(collect($noMapa)->where('importacao_id', $imp->id)->count() === 4, 'a carga do mapa devolve os 4, com o inválido');
    confere(count($repo->anel($p['invalidos'][0]['lote_id']) ?? []) === 5, 'e o contorno dele abre para o Editar lote');
    confere($repo->extensaoDaImportacao($imp->id) !== null, 'e a extensão da importação continua calculável');

    echo "Salvar\n";
    try { $svc->salvar($imp->fresh(), $autor); $recusou = false; }
    catch (RuntimeException $e) { $recusou = str_contains($e->getMessage(), 'contorno inválido'); }
    confere($recusou, 'com contorno inválido o rascunho não é salvo');

    echo "Depois de corrigir\n";
    $um = $p['repetidos'][0]['lote_id'];
    Lote::findOrFail($um)->update(['numero_lote' => '7']);
    confere(count($svc->pendenciasDoDesenho($imp->fresh())['repetidos']) === 1, 'o repetido que ganhou número sai da lista');
    DB::table('lotes')->where('id', $p['invalidos'][0]['lote_id'])->delete();
    confere($svc->pendenciasDoDesenho($imp->fresh())['invalidos'] === [], 'o inválido excluído sai da lista');
    $svc->salvar($imp->fresh(), $autor);
    confere($imp->fresh()->status === 'revisao', 'e aí o rascunho é salvo');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
    @unlink($arquivo);
}
