<?php
// Diagnóstico local explícito: gravação inteiramente revertida na transação.
// gis:desmembramentos-levantamento — o cadastro confirma, o comando aplica SEM
// trilha (correção da carga inicial), em bairro em revisão e publicado, e cada
// caso vai para a aba "Levantamento" da ficha da importação, de onde se desfaz.
// Sobre 4 casos reais do levantamento de 2022 (tests/fixtures/desmembramentos-levantamento.geojson)
// mais duas unificações montadas aqui com os mesmos desenhos.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Lote;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$user = App\Models\User::where('curador_cadastral', true)->get()->first(fn ($u) => $u->podeCurarCadastro());
if (! $user) { throw new RuntimeException('Sem usuário curador para o teste local.'); }

$fc = json_decode(file_get_contents(__DIR__.'/fixtures/desmembramentos-levantamento.geojson'), true);
$arquivo = sys_get_temp_dir().'/levantamento-teste.geojson';
$csv = sys_get_temp_dir().'/levantamento-teste-relatorio.csv';
$repo = app(App\Repositories\LoteRepository::class);
$ok = fn ($cond, $msg) => $cond ?: throw new RuntimeException($msg);
$cad = fn (string $insc, string $isencao, ?float $area) => DB::table('cadastro_externo_imoveis')
    ->insert(['inscricao' => $insc, 'isencao' => $isencao, 'area_terreno_m2' => $area]);
// PRIMAVERA II numa importação EM REVISÃO; PQ. ELDORADO numa PUBLICADA; JARDIM RIVA sem importação.
$imps = [];
$lote = function (string $b, string $q, string $l, string $insc, array $geom, float $area) use ($repo, &$imps) {
    $imp = $imps[$b] ?? null;
    return $repo->criarComGeometria([
        'bairro' => $b, 'quadra' => $q, 'numero_lote' => $l, 'chave' => "{$b}|{$q}|{$l}",
        'inscricao_imobiliaria' => $insc, 'area_gis_m2' => $area,
        'fonte' => 'teste', 'origem' => 'importacao', 'situacao' => 'ativo',
        'importacao_id' => $imp['id'] ?? null, 'em_revisao' => ($imp['status'] ?? '') === 'revisao' ? 1 : 0,
    ], json_encode($geom));
};

DB::beginTransaction();
try {
    foreach (['PRIMAVERA II' => 'revisao', 'PQ. ELDORADO' => 'publicada'] as $b => $st) {
        $imps[$b] = ['status' => $st, 'id' => DB::table('importacoes_lotes')->insertGetId(['bairro' => $b, 'arquivo_nome' => 'teste.geojson',
            'arquivo_hash' => str_repeat('0', 64), 'user_id' => $user->id, 'status' => $st, 'created_at' => now(), 'updated_at' => now()])];
    }
    $auditoriaAntes = DB::table('auditoria')->max('id') ?? 0;
    // ── desmembrados: as 4 mães do arquivo no mapa ──
    $prefixos = ['PRIMAVERA II|13|4' => '999010130004', 'PRIMAVERA II|71|9' => '999010710009',
                 'PQ. ELDORADO|37|13' => '999020370013', 'JARDIM RIVA|08|8' => '999030080008'];
    $casos = [];
    $partesGeo = [];
    foreach ($fc['features'] as $f) {
        $p = $f['properties'];
        $c = $p['chave_mae'];
        if ($p['tipo'] === 'mae') {
            [$b, $q, $l] = explode('|', $c);
            $geom = $f['geometry'];
            // JARDIM RIVA|08|8: a mãe mudou no sistema depois da base (5 m para o lado)
            if ($c === 'JARDIM RIVA|08|8') {
                $geom['coordinates'][0] = array_map(fn ($pt) => [$pt[0] + 0.00005, $pt[1]], $geom['coordinates'][0]);
            }
            $casos[$c]['id'] = $lote($b, $q, $l, $prefixos[$c].'000', $geom, $p['area_m2']);
        } else {
            $casos[$c]['partes'][] = $p['area_m2'];
            $partesGeo[$c][] = $f;
        }
    }
    // PRIMAVERA II|13|4: .000 inativa, as partes ativas com a área delas → aplica
    // PRIMAVERA II|71|9: .000 inativa, metades "iguais" de 225 → aplica, em sequência
    foreach (['PRIMAVERA II|13|4', 'PRIMAVERA II|71|9', 'JARDIM RIVA|08|8'] as $c) {
        $cad($prefixos[$c].'000', 'Inativo', array_sum($casos[$c]['partes']));
        foreach ($casos[$c]['partes'] as $i => $a) {
            $cad($prefixos[$c].sprintf('%03d', $i + 1), 'Ativo', $c === 'PRIMAVERA II|71|9' ? 225.0 : round($a * 1.003, 2));
        }
    }
    // PQ. ELDORADO|37|13: no cadastro o lote continua inteiro (.000 ativa, sozinha) → não aplica
    $cad($prefixos['PQ. ELDORADO|37|13'].'000', 'Ativo', array_sum($casos['PQ. ELDORADO|37|13']['partes']));

    // ── unificados: as duas partes do ELDORADO viram dois lotes, longe dali ──
    $longe = function (array $f, float $dy) { $g = $f['geometry']; $g['coordinates'][0] = array_map(fn ($pt) => [$pt[0], $pt[1] + $dy], $g['coordinates'][0]); return $g; };
    [$pa, $pb] = $partesGeo['PQ. ELDORADO|37|13'];
    // quadra 90: o cadastro tem a 0102 ativa (= 01 + 02) e as dos lotes inativas → aplica, como 0102
    $u1 = [$lote('PQ. ELDORADO', '90', '01', '999020900001000', $longe($pa, 0.01), $pa['properties']['area_m2']),
           $lote('PQ. ELDORADO', '90', '02', '999020900002000', $longe($pb, 0.01), $pb['properties']['area_m2'])];
    $cad('999020900001000', 'Inativo', $pa['properties']['area_m2']);
    $cad('999020900002000', 'Inativo', $pb['properties']['area_m2']);
    $cad('999020900102000', 'Ativo', $pa['properties']['area_m2'] + $pb['properties']['area_m2']);
    // quadra 91: os dois continuam ativos e separados no cadastro → não aplica
    $u2 = [$lote('PQ. ELDORADO', '91', '01', '999020910001000', $longe($pa, 0.02), $pa['properties']['area_m2']),
           $lote('PQ. ELDORADO', '91', '02', '999020910002000', $longe($pb, 0.02), $pb['properties']['area_m2'])];
    $cad('999020910001000', 'Ativo', $pa['properties']['area_m2']);
    $cad('999020910002000', 'Ativo', $pb['properties']['area_m2']);
    foreach ([['90', $u1], ['91', $u2]] as [$q]) {
        $fc['features'][] = ['type' => 'Feature', 'geometry' => null, 'properties' => ['tipo' => 'unificado',
            'chaves' => ["PQ. ELDORADO|{$q}|01", "PQ. ELDORADO|{$q}|02"], 'bairro' => 'PQ. ELDORADO', 'quadra' => $q, 'lotes' => ['01', '02']]];
    }
    file_put_contents($arquivo, json_encode($fc));

    // ── simulação: não grava nada ──
    $antes = DB::table('lotes')->where('situacao', 'ativo')->count();
    Artisan::call('gis:desmembramentos-levantamento', ['arquivo' => $arquivo, '--planilha' => $csv]);
    $ok(DB::table('lotes')->where('situacao', 'ativo')->count() === $antes, 'A simulação gravou lote.');
    $ok(DB::table('importacoes_lotes')->whereIn('id', array_column($imps, 'id'))->whereNotNull('levantamento')->doesntExist(), 'A simulação gravou na importação.');
    $sit = [];
    foreach (array_slice(array_map(fn ($l) => str_getcsv($l, ';'), file($csv, FILE_IGNORE_NEW_LINES)), 1) as $l) {
        $sit[$l[0] . ' ' . $l[1] . '|' . $l[2] . '|' . $l[3]] = [$l[7], $l[8], $l[6]];
    }
    $ok(($sit['desmembrado PRIMAVERA II|13|4'][0] ?? null) === 'pronto', 'Desmembrado em bairro em revisão não ficou pronto: '.json_encode($sit, JSON_UNESCAPED_UNICODE));
    $ok(($sit['desmembrado PRIMAVERA II|71|9'][0] ?? null) === 'pronto' && str_contains($sit['desmembrado PRIMAVERA II|71|9'][2], '.001'),
        'Metades iguais não seguiram a sequência.');
    $ok(str_contains($sit['desmembrado PQ. ELDORADO|37|13'][1] ?? '', 'continua inteiro'), 'Lote inteiro no cadastro não ficou de fora.');
    $ok(str_contains($sit['desmembrado JARDIM RIVA|08|8'][1] ?? '', 'mudou'), 'Mãe deslocada não ficou de fora.');
    $ok(($sit['unificado PQ. ELDORADO|90|01 + 02'][0] ?? null) === 'pronto' && str_contains($sit['unificado PQ. ELDORADO|90|01 + 02'][2], '0102'),
        'Unificação confirmada pela 0102 (bairro publicado) não ficou pronta.');
    $ok(str_contains($sit['unificado PQ. ELDORADO|91|01 + 02'][1] ?? '', 'separados e ativos'), 'Lotes ativos e separados no cadastro não ficaram de fora.');

    // ── aplicação: sem usuário, sem trilha ──
    $codigo = Artisan::call('gis:desmembramentos-levantamento', ['arquivo' => $arquivo, '--planilha' => $csv, '--aplicar' => true]);
    $ok($codigo === 0, "Aplicação falhou:\n".Artisan::output());
    $ok(DB::table('auditoria')->where('id', '>', $auditoriaAntes)->whereIn('tabela', ['lotes', 'lotes_em_revisao'])->doesntExist(),
        'O levantamento deixou registro na auditoria (Histórico ou pré-curadoria).');

    foreach (['PRIMAVERA II|13|4', 'PRIMAVERA II|71|9'] as $c) {
        $pai = Lote::find($casos[$c]['id']);
        $ok($pai->situacao === 'inativo', "{$c}: a mãe não ficou inativa.");
        $filhos = DB::table('lote_ato_lotes')->where('papel', 'posterior')
            ->whereIn('ato_id', DB::table('lote_ato_lotes')->where('lote_id', $pai->id)->where('papel', 'anterior')->pluck('ato_id'))
            ->pluck('lote_id');
        $ok($filhos->count() === count($casos[$c]['partes']), "{$c}: número de partes errado.");
        $ok(abs(Lote::whereIn('id', $filhos)->sum('area_gis_m2') - array_sum($casos[$c]['partes'])) < 1.0, "{$c}: as partes não somam a mãe.");
        $ok(Lote::whereIn('id', $filhos)->where('em_revisao', 1)->where('importacao_id', $imps['PRIMAVERA II']['id'])
            ->where('fonte', 'Levantamento cadastral de 2022')->count() === $filhos->count(), "{$c}: as partes não ficaram na importação em revisão.");
        $ato = DB::table('lote_atos')->whereIn('id', DB::table('lote_ato_lotes')->where('lote_id', $pai->id)->pluck('ato_id'))->first();
        $ok($ato && $ato->observacao === 'Levantamento cadastral de 2022' && $ato->user_id === null, "{$c}: a sucessão tem autor ou outra observação.");
    }
    $ok(Lote::find($casos['PQ. ELDORADO|37|13']['id'])->situacao === 'ativo', 'O lote inteiro no cadastro foi mexido.');
    $ok(Lote::whereIn('id', $u1)->where('situacao', 'inativo')->count() === 2, 'A unificação confirmada não deixou os lotes de antes inativos.');
    $novo = Lote::where('bairro', 'PQ. ELDORADO')->where('quadra', '90')->where('situacao', 'ativo')->first();
    $ok($novo && $novo->origem === 'unificacao' && $novo->inscricao() === '999020900102000' && ! $novo->em_revisao, 'O lote unificado não ficou com a 0102, publicado.');
    $somaU = $pa['properties']['area_m2'] + $pb['properties']['area_m2'];
    $ok(abs($novo->area_gis_m2 - $somaU) < 0.005 * $somaU, "O lote unificado não tem a área dos dois ({$novo->area_gis_m2} × {$somaU}).");
    $ok(Lote::whereIn('id', $u2)->where('situacao', 'ativo')->count() === 2, 'Os lotes separados no cadastro foram unificados.');
    $ok(DB::table('conferencias_bairro')->whereIn('bairro', ['PRIMAVERA II', 'PQ. ELDORADO'])->doesntExist(), 'O levantamento gravou na conferência do bairro.');

    // ── a aba: os casos na importação de cada bairro ──
    $svc = app(App\Services\LevantamentoDaImportacao::class);
    $aba = fn (string $b) => collect($svc->daImportacao(App\Models\ImportacaoLote::find($imps[$b]['id']))['itens'] ?? [])->keyBy('chave');
    $eld = $aba('PQ. ELDORADO');
    $ok(($eld['unificado|PQ. ELDORADO|90|01+PQ. ELDORADO|90|02']['situacao'] ?? null) === 'aplicado', 'Unificação aplicada fora da aba.');
    $ok(($eld['desmembrado|PQ. ELDORADO|37|13']['situacao'] ?? null) === 'nao_aplicado', 'Caso não aplicado fora da aba.');
    $it = $aba('PRIMAVERA II')['desmembrado|PRIMAVERA II|13|4'];
    $ok($it['situacao'] === 'aplicado' && count($it['novos']) === 2 && $it['ato_id'], 'Desmembramento aplicado sem os lotes novos ou o ato.');

    // ── desfazer pela aba: a mãe volta, as partes e a sucessão saem, sem trilha ──
    $impP = App\Models\ImportacaoLote::find($imps['PRIMAVERA II']['id']);
    $svc->desfazer($impP, 'desmembrado|PRIMAVERA II|13|4');
    $ok(Lote::find($casos['PRIMAVERA II|13|4']['id'])->situacao === 'ativo', 'Desfazer não reativou a mãe.');
    $ok(DB::table('lotes')->whereIn('id', array_column($it['novos'], 'lote_id'))->doesntExist(), 'Desfazer deixou as partes.');
    $ok(DB::table('lote_atos')->where('id', $it['ato_id'])->doesntExist(), 'Desfazer deixou a sucessão.');
    $ok($aba('PRIMAVERA II')['desmembrado|PRIMAVERA II|13|4']['situacao'] === 'desfeito', 'O desfeito não apareceu na aba.');
    $ok(DB::table('auditoria')->where('id', '>', $auditoriaAntes)->whereIn('tabela', ['lotes', 'lotes_em_revisao'])->doesntExist(), 'Desfazer deixou registro na auditoria.');
    try { $svc->desfazer($impP, 'desmembrado|PRIMAVERA II|13|4'); $ok(false, 'Desfez duas vezes.'); } catch (RuntimeException $e) { /* esperado */ }

    // ── aplicar de novo: o desfeito não volta, o aplicado continua ──
    $antes = DB::table('lotes')->count();
    Artisan::call('gis:desmembramentos-levantamento', ['arquivo' => $arquivo, '--planilha' => $csv, '--aplicar' => true]);
    $ok(DB::table('lotes')->count() === $antes, 'Segunda aplicação criou lote (refez o desfeito?).');
    $ok(Lote::find($casos['PRIMAVERA II|13|4']['id'])->situacao === 'ativo', 'Segunda aplicação desmembrou de novo o que foi desfeito.');
    $prim = $aba('PRIMAVERA II');
    $ok($prim['desmembrado|PRIMAVERA II|71|9']['situacao'] === 'aplicado' && $prim['desmembrado|PRIMAVERA II|13|4']['situacao'] === 'desfeito',
        'Segunda aplicação mudou o registro do que já foi tratado.');

    echo "OK: simulação, revisão e publicado, sem trilha, aba na importação, desfazer e reaplicação.\n";
} finally {
    DB::rollBack();
    @unlink($csv);
    @unlink($arquivo);
}
