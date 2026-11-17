<?php
// Diagnóstico local, numa transação DESFEITA no fim. O lote desmembrado com
// letra (9A, 9B) acha o imóvel dele no cadastro carregado: no mapa ele é "9B",
// no cadastro é o lote 9 com a variação 001. Antes de 17/11/2026 a busca era só
// por quadra e número, e o balão dizia "sem imóvel no cadastro" (Q 31 · 9B do
// Parque Eldorado), embora a conferência casasse pela inscrição.
//
//   php tests/cadastro-desmembrado-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\CadastroCarregado;
use App\Models\Lote;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

$bairro = DB::table('cadastro_bairros')->whereNotNull('nome_gis')->first(['nome_gis', 'codigo']);
if (! $bairro) { echo "Nenhum bairro amarrado ao cadastro nesta base: nada a conferir.\n"; exit(0); }

DB::beginTransaction();
try {
    // Uma quadra que não existe no bairro, para o teste não esbarrar em imóvel de verdade.
    $quadra = '987';
    $insc = fn (int $variacao) => InscricaoImobiliaria::montar($bairro->codigo, $quadra, '9', $variacao);
    foreach ([0 => 250.0, 1 => 225.0] as $variacao => $area) {
        DB::table('cadastro_externo_imoveis')->insert([
            'inscricao' => $insc($variacao), 'codigo_bairro' => $bairro->codigo, 'nome_bairro' => 'BAIRRO DE TESTE',
            'quadra' => $quadra, 'lote' => '9', 'logradouro' => 'RUA DE TESTE', 'area_terreno_m2' => $area,
            'isencao' => 'Ativo',
        ]);
    }
    DB::table('cadastro_proprietarios')->insert([
        'inscricao' => $insc(1), 'ordem' => 1, 'nome' => 'Dona do 9B', 'documento' => '123.456.789-00', 'endereco' => 'Rua B, 9',
    ]);

    $lote = fn (string $numero, int $desm) => new Lote([
        'bairro' => $bairro->nome_gis, 'quadra' => $quadra, 'numero_lote' => $numero, 'desmembramento' => $desm,
    ]);
    $fonte = new CadastroCarregado();

    echo "Desmembrado com letra\n";
    $r = $fonte->consultar($lote('9B', 1));
    confere($r !== null && (float) $r->imovel['area_terreno_m2'] === 225.0, '9B (variação 001) acha o imóvel dele no cadastro');
    confere(($fonte->proprietarios($lote('9B', 1))[0]['nome'] ?? null) === 'Dona do 9B', 'e o proprietário dele');
    $r = $fonte->consultar($lote('9A', 0));
    confere($r !== null && (float) $r->imovel['area_terreno_m2'] === 250.0, '9A (variação 000) acha o da 000');
    confere($fonte->consultar($lote('9C', 2)) === null, '9C sem inscrição no cadastro continua sem imóvel');

    echo "Sempre pela inscrição\n";
    $r = $fonte->consultar($lote('9', 0));
    confere($r !== null && (float) $r->imovel['area_terreno_m2'] === 250.0, 'o lote 9 sem letra acha a 000');
    confere($fonte->proprietarios($lote('9', 0)) === [], 'e não leva o dono da 001 junto');
    confere($fonte->consultar($lote('77', 0)) === null, 'lote que o cadastro não tem continua sem imóvel');
    confere(str_contains($fonte->porQueVazio($lote('77', 0)), InscricaoImobiliaria::formatar(InscricaoImobiliaria::montar($bairro->codigo, $quadra, '77', 0))),
        'a ficha diz qual inscrição faltou no cadastro');

    echo "Área do BCI\n";
    $areas = new App\Cadastro\AreaOficial();
    confere($areas->doCadastro($lote('9B', 1)) === 225.0, 'o 9B tem a área do BCI (a da 001)');
    confere($areas->doCadastro($lote('9A', 0)) === 250.0, 'o 9A, a da 000');
    confere($areas->doCadastro($lote('77', 0)) === null, 'sem imóvel, sem área do BCI');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
