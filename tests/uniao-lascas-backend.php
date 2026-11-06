<?php
// A união dos lotes sem as LASCAS (LoteRepository::semLascas, 06/11/2026).
// Na Q06 do Lot Belvedere dez lotes encostados davam "MULTIPOLYGON" com uma
// parte de área zero, e a unificação não aparecia. Não toca no banco.
//
//   php tests/uniao-lascas-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\LoteRepository;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) throw new RuntimeException('FALHOU: ' . $o); $ok++; echo "  ok  {$o}\n"; }

$m = new ReflectionMethod(LoteRepository::class, 'semLascas');
$limpa = fn (array $g) => json_decode($m->invoke(null, json_encode($g)), true);

// Um quarteirão de ~100 m × 100 m em Primavera do Leste.
$o = -54.3140; $s = -15.5140; $d = 0.0009;
$terreno = [[[$o, $s], [$o + $d, $s], [$o + $d, $s + $d], [$o, $s + $d], [$o, $s]]];
// A lasca: quatro vértices quase alinhados num lado do terreno (área ~0).
$lasca = [[[$o + $d, $s], [$o + $d + 1e-12, $s + $d / 2], [$o + $d, $s + $d], [$o + $d - 1e-12, $s + $d / 2], [$o + $d, $s]]];

$r = $limpa(['type' => 'MultiPolygon', 'coordinates' => [$terreno, $lasca]]);
confere($r['type'] === 'Polygon' && $r['coordinates'] === $terreno, 'terreno + lasca de área zero vira um polígono só');

$outro = [[[$o + 2 * $d, $s], [$o + 3 * $d, $s], [$o + 3 * $d, $s + $d], [$o + 2 * $d, $s + $d], [$o + 2 * $d, $s]]];
$r = $limpa(['type' => 'MultiPolygon', 'coordinates' => [$terreno, $outro]]);
confere($r['type'] === 'MultiPolygon' && count($r['coordinates']) === 2, 'dois terrenos de verdade continuam separados');

$furo = [[$o + $d / 2, $s + $d / 2], [$o + $d / 2 + 1e-12, $s + $d / 2], [$o + $d / 2, $s + $d / 2 + 1e-12], [$o + $d / 2, $s + $d / 2]];
$r = $limpa(['type' => 'Polygon', 'coordinates' => [...$terreno, $furo]]);
confere(count($r['coordinates']) === 1, 'furo de área zero sai');

$furoReal = [[$o + $d / 4, $s + $d / 4], [$o + $d / 2, $s + $d / 4], [$o + $d / 2, $s + $d / 2], [$o + $d / 4, $s + $d / 4]];
$r = $limpa(['type' => 'Polygon', 'coordinates' => [...$terreno, $furoReal]]);
confere(count($r['coordinates']) === 2, 'furo de verdade fica (a unificação recusa pelo vazio no meio)');

// A face que sobrou de dois lotes unificados: o canto do meio sai (16,50 + 18,00 = 34,50).
$meio = [[[$o, $s], [$o + $d / 2, $s], [$o + $d, $s], [$o + $d, $s + $d], [$o, $s + $d], [$o, $s]]];
$r = $limpa(['type' => 'Polygon', 'coordinates' => $meio]);
confere($r['coordinates'] === $terreno, 'vértice alinhado no meio da face sai: a face vira uma só');

// Um dente de 5 cm não é alinhamento: fica.
$dente = [[[$o, $s], [$o + $d / 2, $s + 0.0000005], [$o + $d, $s], [$o + $d, $s + $d], [$o, $s + $d], [$o, $s]]];
$r = $limpa(['type' => 'Polygon', 'coordinates' => $dente]);
confere(count($r['coordinates'][0]) === 6, 'canto de 5 cm fora da reta continua');


echo "\n{$ok} conferências ok\n";
