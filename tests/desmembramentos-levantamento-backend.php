<?php
// Diagnóstico local explícito: gravação inteiramente revertida na transação.
// gis:desmembramentos-levantamento — o cadastro confirma, a curadoria aplica,
// e cada caso vai para a aba "Levantamento" da conferência do bairro.
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
$lote = fn (string $b, string $q, string $l, string $insc, array $geom, float $area) => $repo->criarComGeometria([
    'bairro' => $b, 'quadra' => $q, 'numero_lote' => $l, 'chave' => "{$b}|{$q}|{$l}",
    'inscricao_imobiliaria' => $insc, 'area_gis_m2' => $area,
    'fonte' => 'teste', 'origem' => 'importacao', 'situacao' => 'ativo', 'em_revisao' => 0,
], json_encode($geom));

DB::beginTransaction();
try {
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
    $ok(! DB::table('conferencias_bairro')->where('bairro', 'PRIMAVERA II')->exists(), 'A simulação gravou na conferência.');
    $sit = [];
    foreach (array_slice(array_map(fn ($l) => str_getcsv($l, ';'), file($csv, FILE_IGNORE_NEW_LINES)), 1) as $l) {
        $sit[$l[0] . ' ' . $l[1] . '|' . $l[2] . '|' . $l[3]] = [$l[7], $l[8], $l[6]];
    }
    $ok(($sit['desmembrado PRIMAVERA II|13|4'][0] ?? null) === 'pronto', 'Desmembrado confirmado não ficou pronto: '.json_encode($sit, JSON_UNESCAPED_UNICODE));
    $ok(($sit['desmembrado PRIMAVERA II|71|9'][0] ?? null) === 'pronto' && str_contains($sit['desmembrado PRIMAVERA II|71|9'][2], '.001'),
        'Metades iguais não seguiram a sequência.');
    $ok(str_contains($sit['desmembrado PQ. ELDORADO|37|13'][1] ?? '', 'continua inteiro'), 'Lote inteiro no cadastro não ficou de fora.');
    $ok(str_contains($sit['desmembrado JARDIM RIVA|08|8'][1] ?? '', 'mudou'), 'Mãe deslocada não ficou de fora.');
    $ok(($sit['unificado PQ. ELDORADO|90|01 + 02'][0] ?? null) === 'pronto' && str_contains($sit['unificado PQ. ELDORADO|90|01 + 02'][2], '0102'),
        'Unificação confirmada pela 0102 não ficou pronta.');
    $ok(str_contains($sit['unificado PQ. ELDORADO|91|01 + 02'][1] ?? '', 'separados e ativos'), 'Lotes ativos e separados no cadastro não ficaram de fora.');

    // ── aplicação ──
    $codigo = Artisan::call('gis:desmembramentos-levantamento', ['arquivo' => $arquivo, '--planilha' => $csv,
        '--aplicar' => true, '--usuario' => $user->email]);
    $ok($codigo === 0, "Aplicação falhou:\n".Artisan::output());

    foreach (['PRIMAVERA II|13|4', 'PRIMAVERA II|71|9'] as $c) {
        $pai = Lote::find($casos[$c]['id']);
        $ok($pai->situacao === 'inativo', "{$c}: a mãe não foi inativada.");
        $filhos = DB::table('lote_ato_lotes')->where('papel', 'posterior')
            ->whereIn('ato_id', DB::table('lote_ato_lotes')->where('lote_id', $pai->id)->where('papel', 'anterior')->pluck('ato_id'))
            ->pluck('lote_id');
        $ok($filhos->count() === count($casos[$c]['partes']), "{$c}: número de partes errado.");
        $ok(abs(Lote::whereIn('id', $filhos)->sum('area_gis_m2') - array_sum($casos[$c]['partes'])) < 1.0, "{$c}: as partes não somam a mãe.");
        $ato = DB::table('lote_atos')->whereIn('id', DB::table('lote_ato_lotes')->where('lote_id', $pai->id)->pluck('ato_id'))->first();
        $ok($ato && str_contains($ato->observacao, 'Levantamento cadastral de 2022') && (int) $ato->user_id === $user->id, "{$c}: ato sem justificativa ou usuário.");
    }
    $ok(Lote::find($casos['PQ. ELDORADO|37|13']['id'])->situacao === 'ativo', 'O lote inteiro no cadastro foi mexido.');
    $ok(Lote::whereIn('id', $u1)->where('situacao', 'inativo')->count() === 2, 'A unificação confirmada não inativou os lotes.');
    $novo = Lote::where('bairro', 'PQ. ELDORADO')->where('quadra', '90')->where('situacao', 'ativo')->first();
    $ok($novo && $novo->origem === 'unificacao' && $novo->inscricao() === '999020900102000', 'O lote unificado não ficou com a 0102.');
    $somaU = $pa['properties']['area_m2'] + $pb['properties']['area_m2'];
    $ok(abs($novo->area_gis_m2 - $somaU) < 0.005 * $somaU, "O lote unificado não tem a área dos dois ({$novo->area_gis_m2} × {$somaU}).");
    $ok(Lote::whereIn('id', $u2)->where('situacao', 'ativo')->count() === 2, 'Os lotes separados no cadastro foram unificados.');

    // ── a aba: os casos na conferência de cada bairro ──
    Illuminate\Support\Facades\Auth::login($user);
    $mostrar = fn (string $b) => app(App\Http\Controllers\ConferenciaBairroController::class)
        ->mostrar(tap(Illuminate\Http\Request::create('/x', 'GET', ['bairro' => $b]), fn ($r) => $r->setUserResolver(fn () => $user)))->getData(true);
    $d = $mostrar('PQ. ELDORADO');
    $ok($d['resultado'] === null, 'Linha só com o levantamento apareceu como bairro conferido.');
    $itens = collect($d['levantamento']['itens'] ?? [])->keyBy('chave');
    $ok(($itens['unificado|PQ. ELDORADO|90|01+PQ. ELDORADO|90|02']['situacao'] ?? null) === 'aplicado', 'Unificação aplicada fora da aba.');
    $ok(($itens['desmembrado|PQ. ELDORADO|37|13']['situacao'] ?? null) === 'nao_aplicado', 'Caso não aplicado fora da aba.');
    $prim = collect($mostrar('PRIMAVERA II')['levantamento']['itens'])->keyBy('chave');
    $it = $prim['desmembrado|PRIMAVERA II|13|4'];
    $ok($it['situacao'] === 'aplicado' && count($it['novos']) === 2 && $it['ato_id'] && ! $it['desfeito'], 'Desmembramento aplicado sem os lotes novos ou o ato.');

    // desfeito pela curadoria (o ato sai de lote_atos) → a aba mostra "desfeito"
    DB::table('lote_ato_lotes')->where('ato_id', $it['ato_id'])->delete();
    DB::table('lote_atos')->where('id', $it['ato_id'])->delete();
    $ok(collect($mostrar('PRIMAVERA II')['levantamento']['itens'])->firstWhere('chave', 'desmembrado|PRIMAVERA II|13|4')['desfeito'],
        'O desfeito não apareceu na aba.');

    // aplicar de novo: nada muda, e o que foi aplicado continua registrado
    $antes = DB::table('lotes')->count();
    Artisan::call('gis:desmembramentos-levantamento', ['arquivo' => $arquivo, '--planilha' => $csv, '--aplicar' => true, '--usuario' => $user->email]);
    $ok(DB::table('lotes')->count() === $antes, 'Segunda aplicação criou lote.');
    $ok(collect($mostrar('PRIMAVERA II')['levantamento']['itens'])->firstWhere('chave', 'desmembrado|PRIMAVERA II|71|9')['situacao'] === 'aplicado',
        'Segunda aplicação apagou o registro do que foi aplicado.');

    // conferir o bairro de novo com o cadastro não apaga a aba
    $r = App\Services\ConferenciaComCadastro::manterLevantamento('PRIMAVERA II', ['total_divergencias' => 0]);
    $ok(! empty($r['levantamento']['itens']), 'A conferência nova perderia a lista do levantamento.');

    echo "OK: simulação, confirmação pelo cadastro, desmembramento, unificação, aba, desfeito e reaplicação.\n";
} finally {
    DB::rollBack();
    @unlink($csv);
    @unlink($arquivo);
}
