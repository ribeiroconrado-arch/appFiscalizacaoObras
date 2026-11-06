<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige três lotes ativos publicados no mesmo bairro e quadra.
//
//   php tests/inscricao-acompanha-backend.php
//
// A inscrição acompanha a troca de número e de quadra (06/11/2026) — também a
// informada à mão — e a troca é recusada quando a inscrição nova já é de outro
// lote ativo (App\Cadastro\InscricaoQueAcompanha).

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\InscricaoQueAcompanha;
use App\Models\Lote;
use App\Services\EdicaoDeLote;
use App\Services\QuadraDeLotesSelecionados;
use App\Support\InscricaoImobiliaria;
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
    $grupo = DB::table('lotes')->where('situacao', 'ativo')->whereNull('importacao_id')->whereNull('terreno_id')
        ->whereNotNull('quadra')->whereNotNull('numero_lote')
        ->select('bairro', 'quadra')->groupBy('bairro', 'quadra')->havingRaw('COUNT(*) >= 3')->first();
    if (! $grupo) { throw new RuntimeException('Sem quadra publicada com 3 lotes para testar.'); }
    [$a, $b, $c] = Lote::where('situacao', 'ativo')->where('bairro', $grupo->bairro)->where('quadra', $grupo->quadra)
        ->whereNull('terreno_id')->orderBy('id')->take(3)->get()->all();

    // A com inscrição informada (com pontos), variação 000.
    $base = '01' . '072' . str_pad((string) (int) InscricaoImobiliaria::numeroDaQuadra($a->quadra), 3, '0', STR_PAD_LEFT);
    DB::table('lotes')->where('id', $a->id)->update(['inscricao_imobiliaria' => InscricaoImobiliaria::formatar($base . '0777000')]);
    $a->refresh();
    $insc = new InscricaoQueAcompanha();

    // ── a conta ──
    $nova = $insc->nova($a, $a->quadra, '812');
    confere($nova === $base . '0812000', 'número 812: só o pedaço do lote muda (' . InscricaoImobiliaria::formatar($nova) . ')');
    confere($insc->nova($a, '985', '777') === '01072985' . '0777000', 'quadra 985: só o pedaço da quadra muda');
    $campos = $insc->campos($a, $nova);
    confere($campos['inscricao_imobiliaria'] === InscricaoImobiliaria::formatar($nova), 'a informada é trocada, na mesma grafia com pontos');
    confere($campos['inscricao_montada'] === $nova, 'a montada gravada também');

    // ── a recusa ──
    DB::table('lotes')->where('id', $b->id)->update(['inscricao_imobiliaria' => $base . '0812000']);
    $erro = $insc->impedimento($nova, [$a->id]);
    confere($erro !== null && str_contains($erro, 'Já existe a inscrição ' . InscricaoImobiliaria::formatar($nova)), 'inscrição de outro lote é recusada: ' . $erro);
    confere($insc->impedimento($nova, [$a->id, $b->id]) === null, 'o próprio lote que muda não se acusa');

    // ── Editar lote recusa antes de gravar ──
    $geo = json_decode(DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [$a->id]), true);
    $imp = app(EdicaoDeLote::class)->impedimento($a, ['quadra' => $a->quadra, 'numero_lote' => '812', 'geometry' => $geo], [], false);
    confere($imp !== null && str_contains($imp, 'Já existe a inscrição'), 'Editar lote: número que leva à inscrição de outro lote é recusado');

    // ── Corrigir quadra ──
    $svc = app(QuadraDeLotesSelecionados::class);
    $numA = (string) (int) preg_replace('/\D/', '', $a->numero_lote);
    // A nova leva a quadra 986 e o número ATUAL do lote (não o 0777 da informada).
    $esperada = '01072986' . str_pad($numA, 4, '0', STR_PAD_LEFT) . '000';
    DB::table('lotes')->where('id', $c->id)->update(['inscricao_imobiliaria' => $esperada]);
    $imp = $svc->impedimento([$a->id], '986');
    confere($imp !== null && str_contains($imp, "Já existe a inscrição"), "Corrigir quadra: inscrição de outro lote é recusada (" . ($imp ?? "nada") . ")");

    DB::table('lotes')->where('id', $c->id)->update(['inscricao_imobiliaria' => null]);
    confere($svc->impedimento([$a->id], '986') === null, 'quadra livre: pode gravar');
    $ret = $svc->retrato([$a->id], '986');
    confere(($ret['de_para'][0]['inscricao_para'] ?? null) === InscricaoImobiliaria::formatar($esperada), 'a prévia mostra a inscrição nova');
    $svc->aplicar([$a->id], '986');
    $a->refresh();
    confere($a->quadra === '986' && InscricaoImobiliaria::normalizar($a->inscricao_imobiliaria) === $esperada, 'gravado: quadra e inscrição juntas');
    confere($a->inscricaoFormatada() === InscricaoImobiliaria::formatar($esperada), 'a ficha lê a inscrição nova');
} finally {
    DB::rollBack();
}

echo "\n{$ok} verificações passaram.\n";
