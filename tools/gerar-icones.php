<?php
/**
 * Gera o conjunto de ícones do app a partir da arte nova, removendo o fundo
 * branco de fora do squircle.
 *
 * O fundo não é apagado por limiar simples: isso deixaria uma franja branca
 * serrilhada na borda arredondada. Em vez disso:
 *   1. inundação a partir dos quatro cantos marca só a região EXTERNA — a
 *      área branca de dentro do desenho é fechada pelo verde, então a
 *      inundação não a alcança e ela continua branca, como deve;
 *   2. na região externa o alfa é calculado desfazendo a mistura com branco
 *      (a = (255 - pixel) / (255 - cor_da_forma)), o que devolve a borda
 *      suave original em vez de recortá-la em degraus.
 */

/*
 * Uso: php tools/gerar-icones.php <arte.jpg> [sufixo]
 *
 * O sufixo existe porque o app tem DOIS conjuntos de ícone, um por tema: o
 * verde (institucional, sem sufixo) e o âmbar (sufixo "-ambar"). Quem troca de
 * tema troca também a marca — app verde com ícone laranja na aba do navegador
 * não parece o mesmo app.
 */
$origem  = $argv[1] ?? null;
$sufixo  = $argv[2] ?? '';
$destino = __DIR__ . '/../public/img';

if (! $origem || ! is_file($origem)) {
    fwrite(STDERR, "uso: php tools/gerar-icones.php <arte.jpg> [sufixo]\n");
    exit(1);
}

$src = imagecreatefromjpeg($origem);
$L = imagesx($src);
$A = imagesy($src);
echo "origem: {$L}x{$A}\n";

// Cor da forma: amostrada no meio da faixa verde à esquerda, longe de bordas.
$amostra = imagecolorat($src, (int) ($L * 0.06), (int) ($A * 0.5));
$fr = ($amostra >> 16) & 0xFF;
$fg = ($amostra >> 8) & 0xFF;
$fb = $amostra & 0xFF;
printf("verde da marca: #%02X%02X%02X\n", $fr, $fg, $fb);

// ── 1. Inundação a partir dos cantos ─────────────────────────
// Limiar generoso (>=140 no menor canal) para capturar quase toda a rampa de
// antisserrilhado. Seguro porque a inundação não chega ao branco interno.
$fora = array_fill(0, $L * $A, false);
$pilha = [];
foreach ([[0, 0], [$L - 1, 0], [0, $A - 1], [$L - 1, $A - 1]] as [$x0, $y0]) {
    $pilha[] = $y0 * $L + $x0;
}

$perto_do_branco = function (int $cor): bool {
    $r = ($cor >> 16) & 0xFF;
    $g = ($cor >> 8) & 0xFF;
    $b = $cor & 0xFF;
    return min($r, $g, $b) >= 140;
};

while ($pilha) {
    $i = array_pop($pilha);
    if ($fora[$i]) {
        continue;
    }
    $x = $i % $L;
    $y = intdiv($i, $L);
    if (! $perto_do_branco(imagecolorat($src, $x, $y))) {
        continue;
    }
    $fora[$i] = true;
    if ($x > 0)      { $pilha[] = $i - 1; }
    if ($x < $L - 1) { $pilha[] = $i + 1; }
    if ($y > 0)      { $pilha[] = $i - $L; }
    if ($y < $A - 1) { $pilha[] = $i + $L; }
}
echo 'pixels marcados como fundo: ' . count(array_filter($fora)) . "\n";

// ── 2. Monta o PNG com alfa ──────────────────────────────────
$out = imagecreatetruecolor($L, $A);
imagealphablending($out, false);
imagesavealpha($out, true);

$denom = 255 - $fr;   // canal vermelho: maior contraste entre branco e verde

for ($y = 0; $y < $A; $y++) {
    for ($x = 0; $x < $L; $x++) {
        $i = $y * $L + $x;
        $c = imagecolorat($src, $x, $y);
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = $c & 0xFF;

        if (! $fora[$i]) {
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, 0));
            continue;
        }

        // Quanto deste pixel ainda é forma? 0 = branco puro, 1 = verde puro.
        $cobertura = $denom > 0 ? (255 - $r) / $denom : 0.0;
        $cobertura = max(0.0, min(1.0, $cobertura));

        if ($cobertura <= 0.004) {
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, 127));
            continue;
        }

        // Alfa da GD é invertido: 0 opaco, 127 transparente.
        $alfa = (int) round(127 * (1 - $cobertura));
        imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $fr, $fg, $fb, $alfa));
    }
}

// ── 3. Recorta na caixa do desenho ───────────────────────────
$minX = $L; $minY = $A; $maxX = -1; $maxY = -1;
for ($y = 0; $y < $A; $y++) {
    for ($x = 0; $x < $L; $x++) {
        $a = (imagecolorat($out, $x, $y) >> 24) & 0x7F;
        if ($a < 120) {
            if ($x < $minX) { $minX = $x; }
            if ($x > $maxX) { $maxX = $x; }
            if ($y < $minY) { $minY = $y; }
            if ($y > $maxY) { $maxY = $y; }
        }
    }
}
$lado = max($maxX - $minX + 1, $maxY - $minY + 1);
$cx = intdiv($minX + $maxX, 2);
$cy = intdiv($minY + $maxY, 2);
$x0 = max(0, $cx - intdiv($lado, 2));
$y0 = max(0, $cy - intdiv($lado, 2));
$lado = min($lado, $L - $x0, $A - $y0);
echo "recorte: {$lado}x{$lado} a partir de ({$x0},{$y0})\n";

$quadrado = imagecreatetruecolor($lado, $lado);
imagealphablending($quadrado, false);
imagesavealpha($quadrado, true);
imagecopy($quadrado, $out, 0, 0, $x0, $y0, $lado, $lado);

// ── 4. Escreve cada tamanho ──────────────────────────────────
$tamanhos = [
    'favicon-16'       => 16,
    'favicon-32'       => 32,
    'favicon-48'       => 48,
    'logo-64'          => 64,
    'logo-128'         => 128,
    'apple-touch-icon' => 180,
    'icone-192'        => 192,
    'icone-512'        => 512,
];

foreach ($tamanhos as $base => $t) {
    $nome = $base . $sufixo . '.png';
    $img = imagecreatetruecolor($t, $t);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagealphablending($img, true);
    imagecopyresampled($img, $quadrado, 0, 0, 0, 0, $t, $t, $lado, $lado);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagepng($img, "$destino/$nome", 9);
    imagedestroy($img);
    printf("  %-22s %3dpx  %6d bytes\n", $nome, $t, filesize("$destino/$nome"));
}

// SVG oficial: embrulha o PNG de 512 para haver uma peça única citável.
$b64 = base64_encode(file_get_contents("$destino/icone-512$sufixo.png"));
file_put_contents("$destino/icone$sufixo.svg",
    '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
    . 'viewBox="0 0 512 512" width="512" height="512">'
    . '<image width="512" height="512" xlink:href="data:image/png;base64,' . $b64 . '"/></svg>');
printf("  %-22s        %6d bytes\n", "icone$sufixo.svg", filesize("$destino/icone$sufixo.svg"));

echo "pronto\n";
