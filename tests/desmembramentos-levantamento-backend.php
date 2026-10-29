<?php
// Diagnóstico local explícito: gravação inteiramente revertida na transação.
// gis:desmembramentos-levantamento — simulação, numeração pelo cadastro e aplicação,
// sobre 4 casos reais do levantamento de 2022 (tests/fixtures/desmembramentos-levantamento.geojson).
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Lote;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$user = App\Models\User::where('curador_cadastral', true)->get()->first(fn ($u) => $u->podeCurarCadastro());
if (! $user) { throw new RuntimeException('Sem usuário curador para o teste local.'); }

$fixture = __DIR__.'/fixtures/desmembramentos-levantamento.geojson';
$csv = sys_get_temp_dir().'/desmembramentos-levantamento-teste.csv';
$fc = json_decode(file_get_contents($fixture), true);
$repo = app(App\Repositories\LoteRepository::class);
$ok = fn ($cond, $msg) => $cond ?: throw new RuntimeException($msg);

DB::beginTransaction();
try {
    // ── cenário: as 4 mães no mapa, com inscrição; o cadastro já desmembrado ──
    $casos = [];
    $prefixos = ['PRIMAVERA II|13|4' => '999010130004', 'PRIMAVERA II|71|9' => '999010710009',
                 'PQ. ELDORADO|37|13' => '999020370013', 'JARDIM RIVA|08|8' => '999030080008'];
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
            $casos[$c]['id'] = $repo->criarComGeometria([
                'bairro' => $b, 'quadra' => $q, 'numero_lote' => $l, 'chave' => $c,
                'inscricao_imobiliaria' => $prefixos[$c].'000', 'area_gis_m2' => $p['area_m2'],
                'fonte' => 'teste', 'origem' => 'importacao', 'situacao' => 'ativo', 'em_revisao' => 0,
            ], json_encode($geom));
        } else {
            $casos[$c]['partes'][] = $p['area_m2'];
        }
    }
    // cadastro: a mãe .000 inativa, cada parte com a área dela (+0,3 %); o ELDORADO fica sem cadastro
    foreach (['PRIMAVERA II|13|4', 'PRIMAVERA II|71|9', 'JARDIM RIVA|08|8'] as $c) {
        DB::table('cadastro_externo_imoveis')->insert(['inscricao' => $prefixos[$c].'000', 'isencao' => 'Inativo', 'area_terreno_m2' => array_sum($casos[$c]['partes'])]);
        foreach ($casos[$c]['partes'] as $i => $a) {
            // metades "iguais" no cadastro: 225 e 225, que é como a prefeitura lança
            $area = $c === 'PRIMAVERA II|71|9' ? 225.0 : round($a * 1.003, 2);
            DB::table('cadastro_externo_imoveis')->insert(['inscricao' => $prefixos[$c].sprintf('%03d', $i + 1), 'isencao' => 'Ativo', 'area_terreno_m2' => $area]);
        }
    }

    // ── simulação ──
    $antes = DB::table('lotes')->count();
    Artisan::call('gis:desmembramentos-levantamento', ['arquivo' => $fixture, '--planilha' => $csv]);
    $ok(DB::table('lotes')->count() === $antes, 'A simulação gravou lote.');
    $linhas = array_map(fn ($l) => str_getcsv($l, ';'), array_slice(file($csv, FILE_IGNORE_NEW_LINES), 1));
    $sit = [];
    $obs = [];
    foreach ($linhas as $l) { $sit[$l[0]] = $l[10]; $obs[$l[0]] = $l[11]; }
    $ok(($sit['PRIMAVERA II|13|4'] ?? null) === 'pronta', 'Caso limpo não ficou pronto: '.json_encode([$sit, $obs], JSON_UNESCAPED_UNICODE));
    $ok(($sit['PRIMAVERA II|71|9'] ?? null) === 'sem_numero', 'Metades iguais não ficaram em branco (empate).');
    $ok(($sit['PQ. ELDORADO|37|13'] ?? null) === 'sem_numero', 'Sem cadastro deveria ficar sem número.');
    $ok(($sit['JARDIM RIVA|08|8'] ?? null) === 'desatualizada', 'Mãe deslocada não ficou desatualizada.');
    foreach ($linhas as $l) {
        if ($l[0] === 'PRIMAVERA II|71|9') $ok($l[6] === '' && str_contains($l[11], 'áreas iguais'), 'Empate sem aviso ou com inscrição chutada.');
        if ($l[0] === 'PRIMAVERA II|13|4') $ok($l[6] !== '' && $l[12] === 'sim', 'Caso limpo sem inscrição ou sem "sim".');
    }

    // ── aplicação: o limpo, e o empate resolvido à mão na planilha ──
    // como ele faria no Excel: preenche as duas inscrições do empate e marca "sim"
    $todas = array_map(fn ($l) => str_getcsv($l, ';'), file($csv, FILE_IGNORE_NEW_LINES));
    $n = 0;
    foreach ($todas as $k => $l) {
        if ($k > 0 && $l[0] === 'PRIMAVERA II|71|9') {
            $n++;
            $todas[$k][6] = App\Support\InscricaoImobiliaria::formatar($prefixos['PRIMAVERA II|71|9'].sprintf('%03d', $n));
            $todas[$k][12] = 'sim';
        }
    }
    $ok($n === 2, 'Não achei as duas linhas do empate na planilha.');
    $f = fopen($csv, 'w');
    foreach ($todas as $l) fputcsv($f, $l, ';');
    fclose($f);

    $codigo = Artisan::call('gis:desmembramentos-levantamento', ['arquivo' => $fixture, '--planilha' => $csv,
        '--aplicar' => true, '--usuario' => $user->email]);
    $saida = Artisan::output();
    $ok($codigo === 0, "Aplicação falhou:\n".$saida);

    foreach (['PRIMAVERA II|13|4', 'PRIMAVERA II|71|9'] as $c) {
        $pai = Lote::find($casos[$c]['id']);
        $ok($pai->situacao === 'inativo', "{$c}: a mãe não foi inativada.");
        $filhos = DB::table('lote_ato_lotes')->where('papel', 'posterior')
            ->whereIn('ato_id', DB::table('lote_ato_lotes')->where('lote_id', $pai->id)->where('papel', 'anterior')->pluck('ato_id'))
            ->pluck('lote_id');
        $ok($filhos->count() === count($casos[$c]['partes']), "{$c}: número de partes errado.");
        $soma = Lote::whereIn('id', $filhos)->sum('area_gis_m2');
        $ok(abs($soma - array_sum($casos[$c]['partes'])) < 1.0, "{$c}: as partes não somam a mãe ({$soma}).");
        foreach (Lote::whereIn('id', $filhos)->get() as $f) {
            $ok($f->origem === 'desmembramento' && str_starts_with($f->inscricao_imobiliaria, $prefixos[$c]), "{$c}: filho sem origem ou inscrição.");
        }
        $ato = DB::table('lote_atos')->whereIn('id', DB::table('lote_ato_lotes')->where('lote_id', $pai->id)->pluck('ato_id'))->first();
        $ok($ato && str_contains($ato->observacao, 'Levantamento cadastral de 2022') && (int) $ato->user_id === $user->id, "{$c}: ato sem justificativa ou usuário.");
    }
    foreach (['PQ. ELDORADO|37|13', 'JARDIM RIVA|08|8'] as $c) {
        $ok(Lote::find($casos[$c]['id'])->situacao === 'ativo', "{$c}: não marcada e mesmo assim mexeu.");
    }

    // aplicar de novo não desmembra outra vez (a mãe já está inativa)
    $codigo = Artisan::call('gis:desmembramentos-levantamento', ['arquivo' => $fixture, '--planilha' => $csv,
        '--aplicar' => true, '--usuario' => $user->email]);
    $ok($codigo !== 0 && str_contains(Artisan::output(), 'inativado'), 'Segunda aplicação não foi recusada.');

    echo "OK: simulação, empate, desatualizada, aplicação e reaplicação.\n";
} finally {
    DB::rollBack();
    @unlink($csv);
}
