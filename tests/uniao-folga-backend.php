<?php
// A união dos lotes no PLANO e com FOLGA (LoteRepository::uniao, 06/11/2026).
// Em SRID 4326 o ST_Union perdia pedaços conforme a ORDEM dos lotes (Q28 do
// Buritis I: 5.640, 5.400 ou 1.200 m² em vez de 5.880). Só lê o banco.
//
//   php tests/uniao-folga-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\LoteRepository;
use App\Support\GeometriaPlana;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) throw new RuntimeException('FALHOU: ' . $o); $ok++; echo "  ok  {$o}\n"; }

$rep = app(LoteRepository::class);
$quadra = DB::table('lotes')->where('situacao', 'ativo')->whereNotNull('quadra')->whereNull('terreno_id')
    ->groupBy('bairro', 'quadra')->havingRaw('COUNT(*) >= 6')
    ->selectRaw('bairro, quadra, GROUP_CONCAT(id ORDER BY id) ids, SUM(area_gis_m2) soma')->first();
if (! $quadra) { throw new RuntimeException('Sem quadra com 6 lotes para testar.'); }
$ids = array_map('intval', explode(',', $quadra->ids));
$soma = (float) $quadra->soma;
$tol = max(0.5, 0.10 * sqrt($soma));

$cantos = [];
foreach ($ids as $id) {
    foreach (json_decode(DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [$id]), true)['coordinates'][0] as $p) {
        $cantos[] = $p;
    }
}
$ref = $cantos[0];

$areas = [];
mt_srand(3);
foreach (['por id' => $ids, 'invertida' => array_reverse($ids), 'embaralhada' => (function ($x) { shuffle($x); return $x; })($ids)] as $nome => $ordem) {
    $u = $rep->uniao($ordem);
    confere($u['tipo'] === 'POLYGON' && $u['furos'] === 0, "{$quadra->bairro} Q{$quadra->quadra}, ordem {$nome}: um polígono só, sem furo");
    confere(abs($u['area_m2'] - $soma) <= $tol, sprintf('ordem %s: união %.2f m² = soma %.2f m²', $nome, $u['area_m2'], $soma));
    $areas[] = round($u['area_m2'], 1);

    // Cada canto da união está a menos de 1 mm de um canto de algum lote.
    $anel = json_decode($u['geojson'], true)['coordinates'][0];
    $pior = 0.0;
    foreach ($anel as $p) {
        $perto = INF;
        foreach ($cantos as $c) {
            [$dx, $dy] = GeometriaPlana::projetar([$p, $c], $ref[1], $ref[0])[1];
            [$ax, $ay] = GeometriaPlana::projetar([$p, $c], $ref[1], $ref[0])[0];
            $perto = min($perto, hypot($dx - $ax, $dy - $ay));
        }
        $pior = max($pior, $perto);
    }
    confere($pior < 0.001, sprintf('ordem %s: o contorno não se deforma (pior canto a %.4f mm de um canto original)', $nome, $pior * 1000));
}
confere(max($areas) - min($areas) < 0.1, 'a ordem dos lotes não muda a união');

echo "\n{$ok} conferências ok\n";
