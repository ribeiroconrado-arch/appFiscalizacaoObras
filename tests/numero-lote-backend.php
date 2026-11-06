<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige um lote ativo publicado com quadra e número.
//
//   php tests/numero-lote-backend.php
//
// "4" e "04" são o MESMO lote (App\Support\NumeroDoLote, 05/11/2026): a regra
// de grafia, a chave, o Eloquent, o apelido do desmembrado e o índice único
// do banco, que recusa a segunda grafia na mesma quadra.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Lote;
use App\Repositories\LoteRepository;
use App\Support\InscricaoImobiliaria;
use App\Support\NumeroDoLote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}

// ── A regra ──
foreach (['04' => '4', '4' => '4', '0004A' => '4A', '00' => '0', '0' => '0', ' 07 ' => '7',
          '4-001' => '4-001', '04-001' => '4-001', '10/12' => '10/12', 'A' => 'A', '0A' => '0A'] as $de => $para) {
    confere(NumeroDoLote::normalizar($de) === $para, "\"{$de}\" vira \"{$para}\"");
}
confere(NumeroDoLote::normalizar(null) === null, 'sem número continua sem número');
confere(NumeroDoLote::naChave('Buritis I|66|04') === 'Buritis I|66|4', 'a chave acompanha o número');
confere(NumeroDoLote::naChave('Buritis I|07|04') === 'Buritis I|7|4', 'a chave acompanha a quadra');
confere(app(\App\Services\QuadraDoQuarteirao::class)->normalizar('07') === '7', 'troca de quadra grava "07" como "7"');
confere(NumeroDoLote::naChave('Buritis I|?|?') === 'Buritis I|?|?', 'chave de lote sem número fica igual');
confere(InscricaoImobiliaria::apelidoDesmembrado('010710100004000', 1) === '4A', 'desmembrado do lote 0004 é o 4A, não 0004A');

DB::beginTransaction();
try {
    // O banco: nenhum número gravado com zero à esquerda depois da migração.
    confere(DB::table('lotes')->where('numero_lote', 'REGEXP', '^0+[0-9]')->count() === 0,
        'nenhum lote do banco ficou com zero à esquerda');
    confere(DB::table('lotes')->where('quadra', 'REGEXP', '^0+[0-9]')->count() === 0
        && DB::table('quadras')->where('numero', 'REGEXP', '^0+[0-9]')->count() === 0,
        'nenhuma quadra do banco ficou com zero à esquerda');

    $l = DB::table('lotes')->where('situacao', 'ativo')->where('em_revisao', 0)
        ->whereNotNull('quadra')->where('numero_lote', 'REGEXP', '^[1-9][0-9]*$')->first();
    if (! $l) { throw new RuntimeException('Nenhum lote publicado com número simples.'); }

    // Eloquent grava sem zero.
    $lote = Lote::findOrFail($l->id);
    $lote->update(['numero_lote' => '0' . $l->numero_lote, 'chave' => "{$l->bairro}|{$l->quadra}|0{$l->numero_lote}"]);
    $gravado = DB::table('lotes')->where('id', $l->id)->first(['numero_lote', 'chave']);
    confere($gravado->numero_lote === $l->numero_lote, 'Eloquent: "0' . $l->numero_lote . '" gravado como "' . $l->numero_lote . '"');
    confere(str_ends_with($gravado->chave, '|' . $l->numero_lote), 'Eloquent: a chave também');

    // O caminho cru de criação (desenho, desmembramento, unificação) também.
    $geo = DB::table('lotes')->where('id', $l->id)->selectRaw('ST_AsGeoJSON(geom) g')->value('g');
    $novo = app(LoteRepository::class)->criarComGeometria([
        'bairro' => $l->bairro, 'quadra' => $l->quadra, 'numero_lote' => '0099',
        'chave' => "{$l->bairro}|{$l->quadra}|0099", 'situacao' => 'inativo',
    ], $geo);
    confere(DB::table('lotes')->where('id', $novo)->value('numero_lote') === '99', 'criarComGeometria: "0099" vira "99"');

    // O índice único: "04" ao lado do "4" na mesma quadra é recusado pelo banco,
    // mesmo gravado por SQL cru, sem passar por nenhuma normalização.
    $recusou = false;
    try {
        DB::insert('INSERT INTO lotes (bairro, quadra, numero_lote, chave, situacao, geom, created_at, updated_at)
                    VALUES (?, ?, ?, ?, "ativo", ST_GeomFromGeoJSON(?, 1, 4326), NOW(), NOW())',
            [$l->bairro, $l->quadra, '0' . $l->numero_lote, 'teste', $geo]);
    } catch (QueryException $e) {
        $recusou = str_contains($e->getMessage(), 'Duplicate');
    }
    confere($recusou, 'o banco recusa "0' . $l->numero_lote . '" na quadra que já tem o "' . $l->numero_lote . '"');

    // A quadra: Eloquent grava sem zero, e o banco recusa o mesmo lote em "0Q".
    $lote->refresh()->update(['quadra' => '0' . $l->quadra]);
    confere(DB::table('lotes')->where('id', $l->id)->value('quadra') === NumeroDoLote::normalizar($l->quadra),
        'Eloquent: quadra "0' . $l->quadra . '" gravada sem zero');
    $recusou = false;
    try {
        DB::insert('INSERT INTO lotes (bairro, quadra, numero_lote, chave, situacao, geom, created_at, updated_at)
                    VALUES (?, ?, ?, ?, "ativo", ST_GeomFromGeoJSON(?, 1, 4326), NOW(), NOW())',
            [$l->bairro, '00' . $l->quadra, $l->numero_lote, 'teste', $geo]);
    } catch (QueryException $e) {
        $recusou = str_contains($e->getMessage(), 'Duplicate');
    }
    confere($recusou, 'o banco recusa o mesmo lote na quadra "00' . $l->quadra . '"');
} finally {
    DB::rollBack();
}

echo "\n{$ok} conferências ok\n";
