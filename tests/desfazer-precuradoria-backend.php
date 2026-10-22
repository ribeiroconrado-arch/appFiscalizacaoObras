<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige uma importação em revisão (ou rascunho) com lotes.
//
//   php tests/desfazer-precuradoria-backend.php
//
// Cobre o DESFAZER DOS AJUSTES DA PRÉ-CURADORIA (DesfazerAlteracao, auditoria
// `lotes_em_revisao`): renumerar e desfazer devolve o número; editar o contorno
// guarda o anterior e desfazer o devolve; apagar guarda o desenho e desfazer
// traz o lote DE VOLTA PARA A IMPORTAÇÃO (em revisão, não publicado); o
// ajuste que já mudou de novo é recusado com o motivo.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ImportacaoLote;
use App\Models\Lote;
use App\Services\DesfazerAlteracao;
use App\Services\EdicaoDeLote;
use App\Services\PreCuradoriaDeLotes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}

DB::beginTransaction();
try {
    Auth::onceUsingId((int) DB::table('users')->value('id'));
    $imp = ImportacaoLote::whereIn('status', ['rascunho', 'revisao'])
        ->whereExists(fn ($q) => $q->from('lotes')->whereColumn('lotes.importacao_id', 'importacoes_lotes.id')->where('situacao', 'ativo'))
        ->firstOrFail();
    $pre = app(PreCuradoriaDeLotes::class);
    $desfazer = app(DesfazerAlteracao::class);
    $ultimo = fn () => DB::table('auditoria')->where('importacao_id', $imp->id)->where('tabela', 'lotes_em_revisao')->orderByDesc('id')->first();
    $lotes = Lote::where('importacao_id', $imp->id)->where('situacao', 'ativo')->whereNotNull('quadra')->limit(3)->get();
    [$a, $b, $c] = [$lotes[0], $lotes[1], $lotes[2]];

    // 1. Renumerar e desfazer.
    $antes = $a->numero_lote;
    $pre->numerar($imp, $a, 'T' . $a->id);
    $linha = $ultimo();
    confere($linha->acao === 'renumerou' && $desfazer->motivoNaRevisao($linha) === null, 'renumerou: o desfazer está liberado');
    $desfazer->executar($linha, 0, 'teste');
    confere((string) $a->fresh()->numero_lote === (string) $antes, "desfeito: o lote voltou a ser o nº {$antes}");
    confere($desfazer->motivoNaRevisao($linha) !== null, 'o mesmo ajuste não se desfaz duas vezes (o número já mudou)');

    // 2. Editar o contorno e desfazer.
    $g0 = DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [$b->id]);
    $geo = json_decode($g0, true);
    $poli = $geo['type'] === 'MultiPolygon' ? $geo['coordinates'][0] : $geo['coordinates'];
    $poli[0] = array_map(fn ($p) => [$p[0] + 0.000001, $p[1]], $poli[0]);   // ~10 cm para leste
    app(EdicaoDeLote::class)->aplicar($b, ['quadra' => $b->quadra, 'numero_lote' => $b->numero_lote,
        'geometry' => ['type' => 'Polygon', 'coordinates' => $poli]]);
    $linha = $ultimo();
    confere($linha->acao === 'editou' && isset(json_decode($linha->dados_anteriores, true)['contorno']), 'editou: o contorno anterior ficou guardado');
    $desfazer->executar($linha, 0, 'teste');
    $volta = DB::scalar('SELECT ST_Equals(geom, ST_GeomFromGeoJSON(?, 1, 4326)) FROM lotes WHERE id = ?', [$g0, $b->id]);
    confere((bool) $volta, 'desfeito: o contorno voltou ao de antes');

    // 3. Apagar e desfazer: o lote volta PARA A IMPORTAÇÃO, em revisão.
    $pre->excluir($imp, [$c->id]);
    $linha = $ultimo();
    confere($linha->acao === 'excluiu' && DB::table('lotes_apagados')->where('lote_id', $c->id)->exists(), 'apagou: o desenho ficou guardado');
    $desfazer->executar($linha, 0, 'teste');
    $novo = Lote::where('importacao_id', $imp->id)->where('chave', $c->chave)->where('situacao', 'ativo')->first();
    confere($novo && $novo->em_revisao, 'desfeito: o lote voltou, dentro da importação e ainda em revisão');
} finally {
    DB::rollBack();
}

echo "\n{$ok} verificações passaram.\n";
