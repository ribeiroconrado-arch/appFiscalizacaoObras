<?php
// Diagnóstico local, numa transação DESFEITA no fim. Importar uma ETAPA de um
// bairro que já existe (EtapaDoBairro, 21/11/2026): o arquivo traz lotes que
// já existem (ficam de fora), um com o mesmo número e outro desenho, um
// sobreposto a outro lote e dois novos.
//
//   php tests/etapa-importacao-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\EtapaDoBairro;
use App\Models\BairroAto;
use App\Models\CadastroBairro;
use App\Models\ImportacaoLote;
use App\Models\User;
use App\Services\ImportacaoDeBairro;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

$curador = User::where('ativo', true)->get()->first(fn (User $u) => $u->podeCurarCadastro());
$arquivo = tempnam(sys_get_temp_dir(), 'etapa') . '.geojson';

DB::beginTransaction();
try {
    // Um bairro com lotes publicados e sem importação aberta.
    $abertos = ImportacaoLote::whereIn('status', ['rascunho', 'revisao'])->pluck('bairro')->all();
    $bairro = DB::table('lotes')->where('situacao', 'ativo')->where('em_revisao', 0)->whereNotNull('quadra')
        ->whereNotIn('bairro', $abertos ?: ['-'])->selectRaw('bairro, COUNT(*) n')->groupBy('bairro')
        ->havingRaw('COUNT(*) >= 10')->orderByDesc('n')->value('bairro');
    $lotes = DB::table('lotes')->where('bairro', $bairro)->where('situacao', 'ativo')->whereNotNull('quadra')->whereNotNull('numero_lote')
        ->orderBy('id')->limit(5)->get(['id', 'quadra', 'numero_lote', DB::raw('ST_AsGeoJSON(geom, 8) g')]);
    $anel = fn ($l) => json_decode($l->g, true)['coordinates'][0];
    $mover = fn (array $a, float $dx, float $dy) => array_map(fn ($c) => [$c[0] + $dx, $c[1] + $dy], $a);
    $larg = fn (array $a) => max(array_column($a, 0)) - min(array_column($a, 0));
    $f = fn (string $q, string $n, array $a) => ['type' => 'Feature',
        'properties' => ['bairro' => $bairro . ' II', 'quadra' => $q, 'numero_lote' => $n],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [$a]]];
    $feicoes = [];
    foreach ($lotes->take(3) as $l) {                   // já existem, mesmo desenho
        $feicoes[] = $f($l->quadra, $l->numero_lote, $anel($l));
    }
    $l4 = $lotes[3];                                     // mesmo número, desenho deslocado meio lote
    $feicoes[] = $f($l4->quadra, $l4->numero_lote, $mover($anel($l4), $larg($anel($l4)) * 0.6, 0));
    $feicoes[] = $f('9991', '1', $anel($lotes[4]));      // número livre, em cima de outro lote
    $longe = $mover($anel($lotes[0]), 0.05, 0.05);      // ~7 km dali: novos
    $feicoes[] = $f('9990', '1', $longe);
    $feicoes[] = $f('9990', '2', $mover($longe, $larg($longe) * 1.2, 0));
    file_put_contents($arquivo, json_encode(['type' => 'FeatureCollection', 'features' => $feicoes]));

    $cb = CadastroBairro::where('nome_gis', $bairro)->first()
        ?? CadastroBairro::create(['codigo' => '983', 'nome_cadastro' => 'TESTE ETAPA', 'nome_gis' => $bairro]);
    $temAprov = BairroAto::where('cadastro_bairro_id', $cb->id)->where('tipo', 'aprovacao')->exists();
    if (! $temAprov) { BairroAto::create(['cadastro_bairro_id' => $cb->id, 'tipo' => 'aprovacao', 'ato' => 'Decreto 1/1980']); }
    $amp = BairroAto::create(['cadastro_bairro_id' => $cb->id, 'tipo' => 'ampliacao', 'ato' => 'Decreto 9999/2026']);
    $svc = app(ImportacaoDeBairro::class);

    echo "A conferência da etapa\n";
    $etapa = ['de' => $bairro, 'so_novos' => true, 'ato_id' => $amp->id];
    $c = $svc->conferirArquivo($arquivo, 'etapa.geojson', $etapa);
    $e = $c['etapa'];
    confere($c['bairro'] === $bairro && $e['nome_no_arquivo'] === [$bairro . ' II'], 'o arquivo "' . $bairro . ' II" entra como ' . $bairro);
    confere($e['contagem'] === ['existe' => 3, 'novo' => 2, 'numero' => 1, 'sobre' => 1], 'separa: 3 já existem, 2 novos, 1 mesmo número, 1 sobreposto');
    confere($e['sobre'][0]['cobre_id'] === $lotes[4]->id && $e['sobre'][0]['porcento'] >= 90, 'o sobreposto aponta o lote que cobre');
    confere($e['ato']['id'] === $amp->id, 'com o decreto da etapa');
    confere($e['quadras_novas'] === array_values(array_unique([(string) $l4->quadra, '9990', '9991'])) || in_array('9990', $e['quadras_novas'], true), 'as quadras que entram');
    confere($c['pode_gravar'], 'com "só os novos", o que já existe não barra');
    $sem = $svc->conferirArquivo($arquivo, 'etapa.geojson', ['so_novos' => false] + $etapa);
    confere(! $sem['pode_gravar'], 'sem a opção, vale a regra de hoje: o lote que já existe barra');
    $outro = $svc->conferirArquivo($arquivo, 'etapa.geojson', ['ato_id' => 999999999] + $etapa);
    confere(! $outro['pode_gravar'], 'decreto de outro bairro é recusado');

    echo "O rascunho\n";
    Auth::setUser($curador);
    $imp = $svc->gravar($arquivo, 'etapa.geojson', $curador, null, $etapa);
    $novos = DB::table('lotes')->where('importacao_id', $imp->id)->get(['id', 'bairro', 'quadra', 'numero_lote']);
    confere($novos->count() === 4 && $imp->total_lotes === 4, 'entram 4 lotes: os 3 que já existiam ficam de fora');
    confere($novos->every(fn ($l) => $l->bairro === $bairro), 'todos com o nome do bairro');
    confere($novos->whereNull('numero_lote')->count() === 2, 'os 2 conflitos entram sem número');
    $p = $svc->pendenciasDoDesenho($imp->fresh());
    confere(count($p['etapa']) === 2 && collect($p['etapa'])->pluck('tipo')->sort()->values()->all() === ['numero', 'sobre'], 'e aparecem nas pendências da etapa');
    confere(($imp->conferencia['etapa']['ato_id'] ?? null) === $amp->id, 'a importação guarda o decreto');

    echo "Ao publicar\n";
    // Um conflito resolvido: o sobreposto é excluído; o de mesmo número, ignorado aqui.
    $q = app(EtapaDoBairro::class)->marcarQuadras($imp->fresh(), $amp->id);
    confere(in_array('9990', $q['marcadas'], true) && in_array('9991', $q['marcadas'], true), 'as quadras só da etapa ficam com o decreto');
    confere(in_array((string) \App\Support\NumeroDoLote::normalizar($l4->quadra), $q['mistas'], true), 'a quadra que já tinha lotes fica para a curadoria');
    confere(DB::table('bairro_ato_quadras')->where('bairro_ato_id', $amp->id)->where('quadra', '9990')->where('papel', 'criou')->exists(), 'gravadas em Quadras por decreto');

    $ant = ['area_diferente' => [['lote_id' => $lotes[1]->id, 'inscricao' => 'x'], ['lote_id' => $novos->first()->id, 'inscricao' => 'y']],
        'total_divergencias' => 2, 'lotes_conferidos' => 400];
    $nova = ['area_diferente' => [], 'nao_encontrados' => [], 'inativos' => [], 'sem_inscricao' => [], 'sem_lote' => [],
        'total_divergencias' => 0, 'lotes_conferidos' => 4];
    $s = ImportacaoDeBairro::somarConferencia($nova, $ant, $imp->id);
    confere(array_column($s['area_diferente'], 'lote_id') === [$lotes[1]->id], 'a conferência soma: o alerta do lote antigo continua, o desta importação vem da nova');
    confere($s['total_divergencias'] === 1 && $s['lotes_conferidos'] === 404, 'com os totais somados');

    echo "Pela rota\n";
    $req = Illuminate\Http\Request::create('/api/importacoes/bairros-com-lotes', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $curador);
    Auth::guard('web')->setUser($curador);
    $app->instance('request', $req);
    $r = $kernel->handle($req);
    $lista = collect(json_decode($r->getContent(), true));
    $este = $lista->firstWhere('nome', $bairro);
    confere($r->getStatusCode() === 200 && $este && collect($este['atos'])->contains('id', $amp->id), 'a lista de bairros traz o bairro e a ampliação');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
    @unlink($arquivo);
}
