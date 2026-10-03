<?php
// Gera `cadastro-dificil.esperado.json`: o que o PHP do sistema extrai de
// `cadastro-dificil.xlsx` — o mesmo laço de CargaDoCadastro::ler sobre
// LeitorXlsx e ColunasDaExportacao. O teste do app desktop
// (tests/cadastro-desktop.test.cjs) confere o núcleo em JS contra este arquivo.
//
// Rodar SEMPRE que a regra de leitura mudar no PHP, e só depois de mudar o
// espelho em ferramentas/cadastro-desktop/src/nucleo.js:
//
//   php tests/fixtures/gerar-esperado.php

require __DIR__ . '/../../vendor/autoload.php';

use App\Cadastro\ColunasDaExportacao;
use App\Cadastro\DiferencaDoCadastro;
use App\Cadastro\LeitorXlsx;

$leitor = new LeitorXlsx(__DIR__ . '/cadastro-dificil.xlsx');
$posicao = null;
$lidas = 0;
$registros = $donos = $bairros = [];

foreach ($leitor->linhas() as $celulas) {
    if ($posicao === null) {
        $posicao = ColunasDaExportacao::cabecalho($celulas);
        continue;
    }
    $ler = fn (string $col) => isset($posicao[$col]) ? trim($celulas[$posicao[$col]] ?? '') : '';
    $r = ColunasDaExportacao::linha($ler);
    if ($r === null) {
        continue;
    }
    $insc = $r['inscricao'];
    $registros[$insc] = $r;
    if ($dono = ColunasDaExportacao::proprietario($ler)) {
        $donos[$insc][mb_strtolower($dono['nome'] . '|' . $dono['documento'])] = $dono;
    }
    if ($r['codigo_bairro'] !== null) {
        $bairros[ltrim($r['codigo_bairro'], '0')] = true;
    }
    $lidas++;
}

$imoveis = [];
foreach ($registros as $insc => $r) {
    $d = array_values($donos[$insc] ?? []);
    $imoveis[$insc] = ['registro' => $r, 'donos' => $d, 'hash' => DiferencaDoCadastro::hash($r, $d)];
}

file_put_contents(__DIR__ . '/cadastro-dificil.esperado.json', json_encode([
    'imoveis' => $imoveis,
    'bairros' => array_map('strval', array_keys($bairros)),
    'lidas'   => $lidas,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo count($imoveis), " imóveis, {$lidas} linhas.\n";
