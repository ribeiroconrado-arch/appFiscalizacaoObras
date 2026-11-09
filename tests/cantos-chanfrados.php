<?php
// App\Cadastro\CantosChanfrados: o microchanfro do conversor antigo vira canto reto;
// lote reto e esquina em curva ficam como estão. Sem banco.
require __DIR__.'/../vendor/autoload.php';

use App\Cadastro\CantosChanfrados;
use App\Support\GeometriaPlana;

$ok = fn ($cond, $msg) => $cond ?: throw new RuntimeException($msg);
[$lat0, $lon0] = [-15.56, -54.30];
$m = GeometriaPlana::projetar([[$lon0 + 0.001, $lat0 + 0.001]], $lat0, $lon0)[0];
$geo = fn (array $pts) => array_map(fn ($q) => [$lon0 + $q[0] / ($m[0] / 0.001), $lat0 + $q[1] / ($m[1] / 0.001)], $pts);
$area = fn (array $anel) => GeometriaPlana::area(GeometriaPlana::projetar($anel, $lat0, $lon0));

// Retângulo 12 × 30 com os 4 cantos chanfrados como o buffer de 25 cm em 2 segmentos:
// cada canto vira 3 vértices (2 ladinhos de ~19 cm).
$r = 0.25; $s = $r * (1 - cos(M_PI / 4)); $c = $r * sin(M_PI / 4);
$cantos = [
    [[0, $r], [$r - $c, $r - $c], [$r, 0]],
    [[12 - $r, 0], [12 - $r + $c, $r - $c], [12, $r]],
    [[12, 30 - $r], [12 - $r + $c, 30 - $r + $c], [12 - $r, 30]],
    [[$r, 30], [$r - $c, 30 - $r + $c], [0, 30 - $r]],
];
$pts = array_merge(...$cantos);
$pts[] = $pts[0];
$chanfrado = $geo($pts);
$res = CantosChanfrados::esquadrejar($chanfrado);
$ok($res && $res['cantos'] === 4, 'Não achou os 4 cantos chanfrados: ' . json_encode($res['cantos'] ?? null));
$ok(count($res['anel']) === 5, 'O retângulo não voltou a ter 4 vértices: ' . count($res['anel']));
$ok(abs($area($res['anel']) - 360) < 0.01, 'A área não voltou a 360 m²: ' . $area($res['anel']));

// Retângulo reto: nada muda.
$ok(CantosChanfrados::esquadrejar($geo([[0, 0], [12, 0], [12, 30], [0, 30], [0, 0]])) === null, 'Mexeu em lote reto.');

// Esquina em curva de verdade (raio 3 m, 10 em 10 graus): fica como está.
$curva = [[0, 0], [12, 0], [12, 27]];
for ($a = 10; $a < 90; $a += 10) $curva[] = [9 + 3 * cos(deg2rad($a)), 27 + 3 * sin(deg2rad($a))];
array_push($curva, [9, 30], [0, 30], [0, 0]);
$ok(CantosChanfrados::esquadrejar($geo($curva)) === null, 'Esquadrejou uma esquina em curva.');

// Os lotes do Jardim Maringá como o conversor de 18/10 os entregou (se o arquivo estiver aqui).
$arq = 'C:/Users/Avell_A52ION/Documents/Conversor GeoJSON/Projetos/pva-do-leste-regiao-pva-2-chacara-fontana-jardm-das-americas/importacoes/a4dbd9c5743c/resultado.geojson';
if (is_file($arq)) {
    [$n, $mexidos, $ganho] = [0, 0, 0.0];
    foreach (json_decode(file_get_contents($arq), true)['features'] as $f) {
        if (! preg_match('/maring/i', $f['properties']['bairro'] ?? '') || $f['geometry']['type'] !== 'Polygon') continue;
        $n++;
        $anel = $f['geometry']['coordinates'][0];
        if ($r2 = CantosChanfrados::esquadrejar($anel)) {
            $mexidos++;
            $d = GeometriaPlana::area(GeometriaPlana::projetar($r2['anel'])) - GeometriaPlana::area(GeometriaPlana::projetar($anel));
            $ok($d > -0.01 && $d < 0.5, "Lote {$f['properties']['quadra']}/{$f['properties']['numero_lote']} mudou {$d} m².");
            $ganho += $d;
        }
    }
    echo "Jardim Maringá: {$mexidos} de {$n} lotes esquadrejados, " . number_format($ganho, 2, ',', '.') . " m² a mais no total.\n";
}

echo "OK: chanfro vira canto reto; lote reto e esquina em curva ficam.\n";
