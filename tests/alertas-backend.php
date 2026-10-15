<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige um bairro com ao menos quatro lotes ativos publicados.
//
//   php tests/alertas-backend.php
//
// Cobre os ALERTAS DO CADASTRO no mapa: a última conferência gravada do
// bairro marca o lote publicado desatualizado (inativo, fora do cadastro,
// área diferente, sem inscrição), a justificativa aceita vira "justificado",
// e o lote em revisão não leva alerta. Também a publicação de uma importação,
// que passa a conferência dela para o bairro.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ConferenciaComCadastro;
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
    $bairro = DB::table('lotes')->where('situacao', 'ativo')->where('em_revisao', 0)
        ->groupBy('bairro')->havingRaw('COUNT(*) >= 4')->value('bairro');
    if (! $bairro) { throw new RuntimeException('Nenhum bairro com quatro lotes publicados.'); }
    $ids = DB::table('lotes')->where('bairro', $bairro)->where('situacao', 'ativo')->where('em_revisao', 0)
        ->orderBy('id')->limit(4)->pluck('id')->all();
    [$a, $b, $c, $d] = $ids;
    $item = fn ($id, $extra = []) => ['lote_id' => $id, 'inscricao' => '01.999.000.' . str_pad((string) $id, 4, '0', STR_PAD_LEFT) . '.000', 'quadra' => '1', 'lote' => (string) $id] + $extra;

    DB::table('conferencias_bairro')->where('bairro', $bairro)->delete();
    confere(ConferenciaComCadastro::alertasDoBairro($bairro) === [], 'bairro sem conferência não tem alerta');

    DB::table('conferencias_bairro')->insert(['bairro' => $bairro, 'codigo_bairro' => '999', 'conferido_em' => now(),
        'created_at' => now(), 'updated_at' => now(), 'resultado' => json_encode([
            'inativos' => [$item($a)],
            'nao_encontrados' => [$item($b), $item($a)],
            'area_diferente' => [$item($c, ['area_desenho' => 300.02, 'area_cadastro' => 300])],
            'sem_inscricao' => [$item($d, ['inscricao' => null])],
        ])]);
    DB::table('conferencia_justificativas')->where('bairro', $bairro)->delete();
    DB::table('conferencia_justificativas')->insert(['bairro' => $bairro, 'chave' => 'lote:' . $d, 'tipo' => 'sem_inscricao',
        'motivo' => 'Lote institucional.', 'created_at' => now(), 'updated_at' => now()]);

    $al = ConferenciaComCadastro::alertasDoBairro($bairro);
    confere(($al[$a]['tipo'] ?? null) === 'inativos', 'lote em duas listas fica com o alerta mais grave (inativo)');
    confere(($al[$b]['tipo'] ?? null) === 'nao_encontrados', 'não está no cadastro');
    confere(($al[$c]['tipo'] ?? null) === 'area_diferente' && str_contains($al[$c]['detalhe'], '300,02'), 'área diferente com o detalhe das duas áreas');
    confere(($al[$d]['justificado'] ?? false) === true && $al[$d]['motivo'] === 'Lote institucional.', 'sem inscrição justificado, com o motivo');
    confere(($al[$a]['justificado'] ?? true) === false, 'sem justificativa não é justificado');

    // O mapa leva o alerta no lote publicado, e não no lote em revisão.
    DB::table('lotes')->where('id', $b)->update(['em_revisao' => 1]);
    $req = Illuminate\Http\Request::create('/api/mapa/lotes', 'GET');
    $l = DB::table('lotes')->where('id', $a)->first();
    $g = json_decode(DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [$a]), true)['coordinates'][0][0];
    $bbox = [$g[0] - 0.003, $g[1] - 0.003, $g[0] + 0.003, $g[1] + 0.003];
    $usuario = App\Models\User::query()->first();
    $req = Illuminate\Http\Request::create('/api/mapa/lotes', 'GET', ['bbox' => implode(',', $bbox), 'revisao' => 1]);
    $req->setUserResolver(fn () => $usuario);
    auth()->setUser($usuario);
    $resp = app(App\Http\Controllers\MapaController::class)->lotes($req)->getData(true);
    $porId = collect($resp['features'])->keyBy(fn ($f) => $f['properties']['id']);
    confere(($porId[$a]['properties']['alerta']['tipo'] ?? null) === 'inativos', 'o mapa leva o alerta do lote publicado');
    if (isset($porId[$b])) {
        confere(($porId[$b]['properties']['alerta'] ?? null) === null, 'o lote em revisão não leva alerta');
    }

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
