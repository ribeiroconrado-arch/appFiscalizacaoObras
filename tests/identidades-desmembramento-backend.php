<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Cria um ato de desmembramento de teste sobre dois lotes
// reais da mesma quadra, troca as identidades e confere.
//
//   php tests/identidades-desmembramento-backend.php
//
// A troca de identidade (App\Services\TrocaDeIdentidades) inverte número e
// sufixo entre as partes de um ato, sem mexer no desenho. Cada lote guarda o
// que tinha antes na trilha, com o motivo.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Lote;
use App\Services\TrocaDeIdentidades;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}

// ── A permutação pura (sem banco) ──
$partes = [['id' => 10, 'numero' => '7A', 'sufixo' => 1], ['id' => 11, 'numero' => '7B', 'sufixo' => 2]];
$nova = TrocaDeIdentidades::permutar($partes, [11, 10]);
confere($nova[11] === ['numero' => '7A', 'sufixo' => 1], 'permutação: a parte 11, na 1ª posição, recebe 7A · 001');
confere($nova[10] === ['numero' => '7B', 'sufixo' => 2], 'permutação: a parte 10, na 2ª posição, recebe 7B · 002');
$tres = [['id' => 1, 'numero' => '3A', 'sufixo' => 1], ['id' => 2, 'numero' => '3B', 'sufixo' => 2], ['id' => 3, 'numero' => '3C', 'sufixo' => 3]];
$r = TrocaDeIdentidades::permutar($tres, [3, 1, 2]);
confere($r[3]['sufixo'] === 1 && $r[1]['sufixo'] === 2 && $r[2]['sufixo'] === 3, 'permutação com três partes: cada posição recebe a identidade de mesma posição');

// ── o ato sobre lotes reais ──
DB::beginTransaction();
try {
    $grupo = DB::table('lotes')->where('situacao', 'ativo')->whereNull('terreno_id')->whereNull('importacao_id')
        ->whereNotNull('quadra')->whereNotNull('numero_lote')
        ->select('bairro', 'quadra')->groupBy('bairro', 'quadra')->havingRaw('COUNT(*) >= 2')->first();
    if (! $grupo) { throw new RuntimeException('Sem quadra com dois lotes ativos para o teste.'); }
    [$a, $b] = Lote::where('situacao', 'ativo')->where('bairro', $grupo->bairro)->where('quadra', $grupo->quadra)
        ->whereNull('terreno_id')->orderBy('id')->take(2)->get()->all();

    // Prefixo de família: os dois compartilham os 12 primeiros dígitos.
    $digitos = InscricaoImobiliaria::normalizar($a->inscricao());
    if ($digitos === null) { throw new RuntimeException('Lote sem inscrição para o teste.'); }
    $prefixo = substr($digitos, 0, 12);

    // Rótulos únicos na quadra, para não colidir com ninguém.
    DB::table('lotes')->where('id', $a->id)->update([
        'numero_lote' => '9901A', 'desmembramento' => 1, 'chave' => $a->bairro . '|' . $a->quadra . '|9901A',
        'inscricao_imobiliaria' => InscricaoImobiliaria::formatar($prefixo . '001'),
    ]);
    DB::table('lotes')->where('id', $b->id)->update([
        'numero_lote' => '9901B', 'desmembramento' => 2, 'chave' => $b->bairro . '|' . $b->quadra . '|9901B',
        'inscricao_imobiliaria' => InscricaoImobiliaria::formatar($prefixo . '002'),
    ]);

    // O ato: os dois são partes (posterior) de um desmembramento direto.
    $atoId = DB::table('lote_atos')->insertGetId([
        'tipo' => 'desmembramento', 'protocolo_id' => null, 'user_id' => null,
        'modo' => null, 'observacao' => 'teste', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([$a->id, $b->id] as $id) {
        DB::table('lote_ato_lotes')->insert(['ato_id' => $atoId, 'lote_id' => $id, 'papel' => 'posterior', 'area_m2' => null]);
    }

    $svc = new TrocaDeIdentidades();
    $motivo = 'Placa e vistoria mostram a esquerda como 9901A';
    $a->refresh();

    // ── consulta ──
    $estado = $svc->consultar($a);
    confere($estado !== null && count($estado['partes']) === 2, 'consulta: o ato tem as duas partes');
    confere($estado['partes'][0]['id'] === $a->id, 'consulta: as partes vêm pelo sufixo (A primeiro)');
    confere($estado['bloqueio'] === null, 'consulta: sem bloqueio para duas partes ativas');

    // ── prévia recusa ──
    $p = $svc->previa($a, [$a->id, $b->id], $motivo);
    confere($p['erro'] !== null && str_contains($p['erro'], 'Nada a trocar'), 'prévia: ordem igual é recusada (' . $p['erro'] . ')');
    $p = $svc->previa($a, [$b->id, $a->id], 'curto');
    confere($p['erro'] !== null && str_contains($p['erro'], 'motivo'), 'prévia: motivo curto é recusado');
    $p = $svc->previa($a, [$a->id], $motivo);
    confere($p['erro'] !== null, 'prévia: ordem incompleta é recusada');

    // ── prévia ok ──
    $p = $svc->previa($a, [$b->id, $a->id], $motivo);
    confere($p['erro'] === null && count($p['trocas']) === 2, 'prévia: a troca é possível');
    $trocaA = collect($p['trocas'])->firstWhere('id', $a->id);
    confere($trocaA !== null && str_contains($trocaA['para'], '9901B'), 'prévia: a parte A passa a ser 9901B (' . $trocaA['para'] . ')');

    // ── aplica ──
    $ids = $svc->aplicar($a, [$b->id, $a->id], $motivo);
    confere(count($ids) === 2, 'aplicar: as duas partes trocaram');

    $a2 = Lote::find($a->id);
    $b2 = Lote::find($b->id);
    confere($a2->numero_lote === '9901B' && (int) $a2->desmembramento === 2, 'depois: o lote A ficou com 9901B · sufixo 002');
    confere($b2->numero_lote === '9901A' && (int) $b2->desmembramento === 1, 'depois: o lote B ficou com 9901A · sufixo 001');
    confere($a2->situacao === 'ativo' && $b2->situacao === 'ativo', 'depois: as duas continuam ativas');
    confere($a2->chave === $a->bairro . '|' . $a->quadra . '|9901B', 'depois: a chave acompanha o número novo');
    confere(InscricaoImobiliaria::normalizar($a2->inscricao_imobiliaria) === $prefixo . '002', 'depois: a inscrição informada de A passou a ser ...002');
    confere(InscricaoImobiliaria::normalizar($b2->inscricao_imobiliaria) === $prefixo . '001', 'depois: a inscrição informada de B passou a ser ...001');
    confere(abs((float) $a2->area_gis_m2 - (float) $a->area_gis_m2) < 0.001, 'depois: a área do desenho não mudou');

    // ── trilha ──
    $trilha = DB::table('auditoria')->where('acao', TrocaDeIdentidades::ACAO)->where('registro_id', $a->id)->count();
    confere($trilha === 1, 'trilha: o lote A tem o registro da troca');

    // ── a ordem de antes volta a ser o estado atual (desfazer = mesma operação) ──
    $svc->aplicar($a2, [$a->id, $b->id], $motivo);
    confere(Lote::find($a->id)->numero_lote === '9901A', 'desfazer: aplicar a ordem anterior restaura o 9901A');

    echo "\nTodos os {$ok} testes passaram.\n";
} finally {
    DB::rollBack();
}
