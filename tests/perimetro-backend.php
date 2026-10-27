<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige lotes ativos na base local.
//
//   php tests/perimetro-backend.php
//
// Cobre o PERÍMETRO URBANO (App\Services\PerimetrosUrbanos): o arquivo do
// conversor é conferido e recusado quando não serve; vira rascunho comparado
// com o vigente; a publicação encerra a versão anterior na véspera; o rascunho
// se descarta; e o "ver tudo" do mapa passa a usar o vigente.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PerimetroUrbano;
use App\Models\User;
use App\Services\PerimetrosUrbanos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}
function recusa(callable $f, string $trecho, string $o_que): void {
    try { $f(); } catch (RuntimeException $e) {
        confere(str_contains($e->getMessage(), $trecho), $o_que . ' — "' . mb_substr($e->getMessage(), 0, 70) . '…"');
        return;
    }
    confere(false, $o_que . ' (não recusou)');
}

/** Um retângulo [oeste, sul, leste, norte] como arquivo do conversor. */
function arquivo(array $r, array $props = ['tipo' => 'perimetro', 'fonte' => 'TESTE.dwg'], ?array $furo = null, array $extras = []): string {
    [$o, $s, $l, $n] = $r;
    $aneis = [[[$o, $s], [$l, $s], [$l, $n], [$o, $n], [$o, $s]]];
    if ($furo) { [$fo, $fs, $fl, $fn] = $furo; $aneis[] = [[$fo, $fs], [$fo, $fn], [$fl, $fn], [$fl, $fs], [$fo, $fs]]; }
    $gj = ['type' => 'FeatureCollection', 'features' => array_merge([[
        'type' => 'Feature', 'properties' => $props, 'geometry' => ['type' => 'Polygon', 'coordinates' => $aneis],
    ]], $extras)];
    $c = tempnam(sys_get_temp_dir(), 'per') . '.geojson';
    file_put_contents($c, json_encode($gj));
    return $c;
}

$svc = app(PerimetrosUrbanos::class);
$u = User::where('ativo', true)->orderBy('id')->firstOrFail();
Auth::login($u);

// A extensão real dos lotes: o perímetro "grande" cobre tudo; o "pequeno", a metade oeste.
$e = DB::selectOne("SELECT MIN(ST_Longitude(ST_PointN(ST_ExteriorRing(geom),1))) o, MAX(ST_Longitude(ST_PointN(ST_ExteriorRing(geom),1))) l,
                           MIN(ST_Latitude(ST_PointN(ST_ExteriorRing(geom),1))) s, MAX(ST_Latitude(ST_PointN(ST_ExteriorRing(geom),1))) n
                      FROM lotes WHERE situacao = 'ativo'");
$grande = [$e->o - 0.01, $e->s - 0.01, $e->l + 0.01, $e->n + 0.01];
$meio = ($e->o + $e->l) / 2;
$pequeno = [$e->o - 0.01, $e->s - 0.01, $meio, $e->n + 0.01];

DB::beginTransaction();
try {
    DB::table('perimetros_urbanos')->delete();
    Cache::forget('perimetro-urbano:retangulo');

    echo "Arquivo\n";
    $l = $svc->lerArquivo(arquivo($grande, ['tipo' => 'perimetro', 'fonte' => 'TESTE.dwg'], [$meio - 0.001, $e->s, $meio + 0.001, $e->s + 0.002]));
    confere($l['partes'] === 1 && $l['furos'] === 1 && $l['area_m2'] > 1_000_000, 'retângulo com furo: 1 parte, 1 furo, área em m²');
    confere(PerimetrosUrbanos::arquivoEhDePerimetro(arquivo($grande)), 'arquivo do perímetro é reconhecido');
    recusa(fn () => $svc->lerArquivo(arquivo($grande, ['tipo' => 'lote'])), 'não traz o perímetro', 'arquivo de lotes é recusado');
    recusa(fn () => $svc->lerArquivo(arquivo($grande, extras: [['type' => 'Feature', 'properties' => ['tipo' => 'lote'],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]]]])), 'mistura', 'arquivo misturado é recusado');
    recusa(fn () => $svc->lerArquivo(arquivo([785000, 8260000, 790000, 8265000])), 'UTM', 'coordenada em UTM é recusada');
    recusa(fn () => $svc->lerArquivo(arquivo([-47.9, -15.8, -47.8, -15.7])), 'região do município', 'perímetro longe da cidade (Brasília) é recusado');
    recusa(fn () => $svc->lerArquivo(arquivo([$e->o, $e->s, $e->o + 0.001, $e->s + 0.001])), 'fora do esperado', 'área pequena demais é recusada');

    echo "Primeira versão\n";
    $t = microtime(true);
    $p1 = $svc->carregar(arquivo($pequeno), 'pequeno.geojson', ['lei_numero' => '100/2020', 'vigente_desde' => '2020-01-01'], $u);
    $ms = (int) ((microtime(true) - $t) * 1000);
    $c1 = $p1->comparacao;
    confere($p1->situacao === 'rascunho' && $c1['vigente'] === null, 'entra como rascunho, sem vigente para comparar');
    confere($c1['lotes_fora'] > 0 && $c1['passam_fora']['total'] === $c1['lotes_fora'], "lotes da metade leste ficam fora ({$c1['lotes_fora']}) — comparação em {$ms} ms");
    confere(PerimetrosUrbanos::retanguloParaOMapa() === config('gis.perimetro_urbano'), 'rascunho não muda o "ver tudo" do mapa');
    $svc->publicar($p1, $u);
    confere($p1->fresh()->situacao === 'vigente', 'publicado: vira o vigente');
    $ret = PerimetrosUrbanos::retanguloParaOMapa();
    confere(abs($ret[2] - $meio) < 1e-6 && abs($ret[0] - $pequeno[0]) < 1e-6, '"ver tudo" passa a ser o retângulo do vigente');

    echo "Segunda versão (ampliação)\n";
    $p2 = $svc->carregar(arquivo($grande), 'grande.geojson', ['lei_numero' => '200/2026'], $u);
    $c2 = $p2->comparacao;
    confere($c2['vigente_id'] === $p1->id && $c2['area_entra_m2'] > 0 && $c2['area_sai_m2'] < 1, 'compara com o vigente: só entra área');
    confere($c2['passam_dentro']['total'] === $c1['lotes_fora'] && $c2['passam_fora']['total'] === 0, 'os lotes que estavam fora passam para dentro');
    [$entra, $sai] = $svc->diferencas(json_encode($svc->geometria($p2)), json_encode($svc->geometria($p1)));
    confere(abs(PerimetrosUrbanos::area($entra) - $c2['area_entra_m2']) < 1, 'o "entra" do mapa tem a área da ficha');
    recusa(fn () => $svc->publicar($p2, $u), 'número da lei e a data', 'sem data de vigência não publica');
    $svc->atualizarLei($p2, ['vigente_desde' => '2019-06-01']);
    recusa(fn () => $svc->publicar($p2->fresh(), $u), 'depois da do atual', 'vigência anterior à do atual não publica');
    $svc->atualizarLei($p2, ['vigente_desde' => '2026-11-01']);
    $svc->publicar($p2->fresh(), $u);
    $p1 = $p1->fresh();
    confere($p1->situacao === 'anterior' && $p1->vigente_ate->format('Y-m-d') === '2026-10-31', 'o anterior fica no histórico, encerrado na véspera');
    confere(PerimetroUrbano::where('situacao', 'vigente')->count() === 1 && $svc->vigente()->id === $p2->id, 'um vigente só');
    recusa(fn () => $svc->atualizarLei($p1, ['lei_numero' => 'x']), 'Só o rascunho', 'versão publicada não muda a lei');
    recusa(fn () => $svc->descartar($p2->fresh()), 'não se descarta', 'publicado não se descarta');

    echo "Rascunho descartado\n";
    $p3 = $svc->carregar(arquivo($pequeno), 'teste.geojson', [], $u);
    $svc->descartar($p3);
    confere(! PerimetroUrbano::find($p3->id), 'o rascunho sai');
    confere(DB::table('auditoria')->where('tabela', 'perimetros_urbanos')->where('registro_id', $p3->id)->where('acao', 'descartou')->exists(),
        'e a trilha registra o descarte');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
    Cache::forget('perimetro-urbano:retangulo');
}
