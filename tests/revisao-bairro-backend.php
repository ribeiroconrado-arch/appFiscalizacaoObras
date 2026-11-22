<?php
// Diagnóstico local, numa transação DESFEITA no fim. REVISÃO de parte de um
// bairro que já existe (RevisaoDoBairro, 21/11/2026): o arquivo traz uma
// quadra inteira de novo, com um lote igual, um redesenhado, um renumerado,
// um desmembrado, dois unificados, um que sumiu, um duvidoso e um novo.
//
//   php tests/revisao-bairro-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\RevisaoDoBairro;
use App\Models\BairroAto;
use App\Models\CadastroBairro;
use App\Models\ImportacaoLote;
use App\Models\User;
use App\Services\ImportacaoDeBairro;
use App\Support\GeometriaPlana;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

/** Fecho convexo (cadeia monótona): a união de dois lotes vizinhos retangulares. */
function fecho(array $pts): array
{
    $pts = array_map(fn ($p) => [$p[0], $p[1]], $pts);
    usort($pts, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    $cruz = fn ($o, $a, $b) => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
    $baixo = $cima = [];
    foreach ($pts as $p) { while (count($baixo) >= 2 && $cruz($baixo[count($baixo) - 2], $baixo[count($baixo) - 1], $p) <= 0) array_pop($baixo); $baixo[] = $p; }
    foreach (array_reverse($pts) as $p) { while (count($cima) >= 2 && $cruz($cima[count($cima) - 2], $cima[count($cima) - 1], $p) <= 0) array_pop($cima); $cima[] = $p; }
    array_pop($baixo); array_pop($cima);
    $h = array_merge($baixo, $cima);
    $h[] = $h[0];

    return $h;
}

$admin = User::where('ativo', true)->get()->first(fn (User $u) => $u->isAdmin() && $u->podeCurarCadastro());
$arquivo = tempnam(sys_get_temp_dir(), 'revisao') . '.geojson';

DB::beginTransaction();
try {
    // Uma quadra de lotes quadriláteros, com inscrição, sem fração ideal, num bairro sem importação aberta.
    $abertos = ImportacaoLote::whereIn('status', ['rascunho', 'revisao'])->pluck('bairro')->all();
    $candidatas = DB::table('lotes')->where('situacao', 'ativo')->where('em_revisao', 0)->whereNull('terreno_id')
        ->whereNotNull('quadra')->whereNotNull('numero_lote')->whereNotNull('inscricao_montada')
        ->whereNotIn('bairro', $abertos ?: ['-'])
        ->selectRaw('bairro, quadra, COUNT(*) n')->groupBy('bairro', 'quadra')->havingRaw('COUNT(*) BETWEEN 10 AND 30')
        ->orderByDesc('n')->limit(80)->get();
    $escolha = null;
    foreach ($candidatas as $q) {
        $ls = DB::table('lotes')->where('bairro', $q->bairro)->where('quadra', $q->quadra)->where('situacao', 'ativo')
            ->whereNull('terreno_id')->orderBy('id')
            ->get(['id', 'quadra', 'numero_lote', 'inscricao_montada', 'area_gis_m2', DB::raw('ST_AsGeoJSON(geom, 9) g')]);
        if ($ls->contains(fn ($l) => DB::table('lotes')->where('terreno_id', $l->id)->exists())) continue;
        $anel = fn ($l) => json_decode($l->g, true)['coordinates'][0] ?? [];
        $quad = $ls->filter(fn ($l) => count($anel($l)) === 5 && $l->inscricao_montada)->values();
        if ($quad->count() < 8) continue;
        // Dois vizinhos cujo fecho é a união (área do fecho ≈ soma).
        $par = null;
        foreach ($quad as $a) {
            foreach ($quad as $b) {
                if ($a->id >= $b->id) continue;
                $h = fecho(array_merge($anel($a), $anel($b)));
                $ref = $anel($a)[0];
                $ah = GeometriaPlana::area(GeometriaPlana::projetar($h, $ref[1], $ref[0]));
                $s = GeometriaPlana::area(GeometriaPlana::projetar($anel($a), $ref[1], $ref[0])) + GeometriaPlana::area(GeometriaPlana::projetar($anel($b), $ref[1], $ref[0]));
                if (abs($ah - $s) / $s < 0.01) { $par = [$a, $b, $h]; break 2; }
            }
        }
        if (! $par) continue;
        $escolha = [$q->bairro, $ls, $quad, $par];
        break;
    }
    confere($escolha !== null, 'achou uma quadra para o ensaio');
    [$bairro, $lotes, $quad, $par] = $escolha;
    $anel = fn ($l) => json_decode($l->g, true)['coordinates'][0];
    $mover = fn (array $a, float $dx, float $dy) => array_map(fn ($c) => [$c[0] + $dx, $c[1] + $dy], $a);
    $larg = fn (array $a) => max(array_column($a, 0)) - min(array_column($a, 0));
    $f = fn ($q, $n, array $a) => ['type' => 'Feature', 'properties' => ['bairro' => $bairro, 'quadra' => (string) $q, 'numero_lote' => $n === null ? null : (string) $n],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [$a]]];

    [$uA, $uB, $fechoAB] = $par;
    $resto = $quad->reject(fn ($l) => in_array($l->id, [$uA->id, $uB->id], true))->values();
    [$igual, $redes, $renum, $desm, $sumiu] = [$resto[0], $resto[1], $resto[2], $resto[3], $resto[4]];
    $usados = [$uA->id, $uB->id, $igual->id, $redes->id, $renum->id, $desm->id, $sumiu->id];

    $feicoes = [];
    // Os outros lotes da quadra, iguais (a revisão traz a quadra inteira).
    foreach ($lotes as $l) {
        if (! in_array($l->id, $usados, true)) $feicoes[] = $f($l->quadra, $l->numero_lote, $anel($l));
    }
    $feicoes[] = $f($igual->quadra, $igual->numero_lote, $anel($igual));
    $feicoes[] = $f($redes->quadra, $redes->numero_lote, $mover($anel($redes), $larg($anel($redes)) * 0.08, 0));   // contorno acertado
    $feicoes[] = $f($renum->quadra, '9901', $anel($renum));                                                             // número trocado
    $d = $anel($desm);                                                                                                 // em duas metades
    $m01 = [($d[0][0] + $d[1][0]) / 2, ($d[0][1] + $d[1][1]) / 2];
    $m32 = [($d[3][0] + $d[2][0]) / 2, ($d[3][1] + $d[2][1]) / 2];
    $feicoes[] = $f($desm->quadra, $desm->numero_lote . 'X', [$d[0], $m01, $m32, $d[3], $d[0]]);
    $feicoes[] = $f($desm->quadra, $desm->numero_lote . 'Y', [$m01, $d[1], $d[2], $m32, $m01]);
    $feicoes[] = $f($uA->quadra, $uA->numero_lote, $fechoAB);                                                         // os dois num só
    $feicoes[] = $f($igual->quadra, '9902', $mover($anel($igual), $larg($anel($igual)) * 0.5, 0));                     // meio em cima do igual: duvidoso
    $longe = $mover($anel($igual), 0.05, 0.05);
    $feicoes[] = $f('9990', '1', $longe);                                                                              // novo
    // ($sumiu fica de fora do arquivo)
    file_put_contents($arquivo, json_encode(['type' => 'FeatureCollection', 'features' => $feicoes]));

    $cb = CadastroBairro::where('nome_gis', $bairro)->first()
        ?? CadastroBairro::create(['codigo' => '983', 'nome_cadastro' => 'TESTE REVISAO', 'nome_gis' => $bairro]);
    if (! BairroAto::where('cadastro_bairro_id', $cb->id)->where('tipo', 'aprovacao')->exists()) {
        BairroAto::create(['cadastro_bairro_id' => $cb->id, 'tipo' => 'aprovacao', 'ato' => 'Decreto 1/1980']);
    }
    $alt = BairroAto::create(['cadastro_bairro_id' => $cb->id, 'tipo' => 'alteracao', 'ato' => 'Decreto 9998/2026']);
    $svc = app(ImportacaoDeBairro::class);

    echo "A leitura da revisão\n";
    $rev = ['de' => $bairro, 'modo' => 'revisao', 'grupos' => null, 'so_novos' => true, 'ato_id' => $alt->id];
    $c = $svc->conferirArquivo($arquivo, 'revisao.geojson', $rev);
    $e = $c['etapa'];
    $n = $e['contagem'];
    echo '     ' . json_encode($n) . "\n";
    confere($e['modo'] === 'revisao' && $e['ato']['id'] === $alt->id, 'é revisão, com o decreto de alteração');
    confere($n['desenho'] === 1 && $n['numero'] === 1, 'um desenho corrigido e um número trocado');
    confere($n['desmembrado'] === 2 && $n['desmembrado_mapa'] === 1, 'um lote do mapa desmembrado em 2');
    confere($n['unificado'] === 1 && $n['unificado_mapa'] === 2, '2 lotes do mapa unificados em 1');
    confere($n['novo'] === 1 && $n['duvidoso'] === 1 && $n['sumiu'] === 1, 'um novo, um duvidoso e um que sumiu');
    confere($n['igual'] === count($lotes) - 7 + 1, 'o resto é igual ao mapa');
    $porGrupo = collect($e['mudancas'])->groupBy('grupo');
    confere($porGrupo['sumiu'][0]['lote_id'] === $sumiu->id, 'o que sumiu é o lote que ficou fora do arquivo');
    confere($porGrupo['numero'][0]['lote_id'] === $renum->id && $porGrupo['numero'][0]['para_lote'] === '9901', 'o renumerado vai para 9901');
    confere(collect($porGrupo['unificado'][0]['lote_ids'])->sort()->values()->all() === collect([$uA->id, $uB->id])->sort()->values()->all(), 'a unificação junta os dois vizinhos');
    confere(! isset($e['mudancas'][0]['geometria']), 'a tela recebe as mudanças sem as geometrias');
    confere($c['pode_gravar'], 'o que já existe não barra a revisão');
    confere($e['grupos'] === RevisaoDoBairro::GRUPOS_PADRAO, '"sumiu" fica desmarcado por padrão');
    $outro = $svc->conferirArquivo($arquivo, 'revisao.geojson', ['ato_id' => $cb->atos()->where('tipo', 'aprovacao')->value('id')] + $rev);
    confere(! $outro['pode_gravar'], 'decreto de aprovação não serve para revisão');
    $nada = $svc->conferirArquivo($arquivo, 'revisao.geojson', ['grupos' => []] + $rev);
    confere($nada['pode_gravar'], 'sem grupo marcado, o duvidoso ainda entra (pendência)');

    echo "O rascunho\n";
    Auth::setUser($admin);
    $comSumiu = ['grupos' => array_merge(RevisaoDoBairro::GRUPOS_PADRAO, ['sumiu'])] + $rev;
    $imp = $svc->gravar($arquivo, 'revisao.geojson', $admin, null, $comSumiu);
    $novos = DB::table('lotes')->where('importacao_id', $imp->id)->get(['id', 'quadra', 'numero_lote']);
    confere($novos->count() === 2, 'no rascunho entram só o novo e o duvidoso');
    confere($novos->whereNull('numero_lote')->count() === 1, 'o duvidoso sem número');
    confere(count($imp->conferencia['revisao_operacoes']) === 5, 'guarda as 5 mudanças marcadas');
    confere(DB::table('lotes')->where('id', $sumiu->id)->value('situacao') === 'ativo'
        && (float) DB::table('lotes')->where('id', $redes->id)->value('area_gis_m2') === (float) $redes->area_gis_m2, 'o mapa ainda não mudou');
    $p = $svc->pendenciasDoDesenho($imp->fresh());
    confere(count($p['etapa']) === 1 && $p['etapa'][0]['tipo'] === 'duvidoso', 'o duvidoso aparece nas pendências');

    echo "Ao publicar\n";
    $imp->update(['status' => 'revisao', 'salvo_por' => $admin->id, 'salvo_em' => now()]);
    $codigo = (new App\Cadastro\BairrosDoDesenho())->codigos()[App\Cadastro\BairrosDoDesenho::chave($bairro)] ?? null;
    $imp->update(['conferido_em' => now()->addMinute(),
        'conferencia_cadastro' => ['codigo_bairro' => $codigo, 'lotes_conferidos' => 2, 'total_divergencias' => 0]]);
    $svc->publicar($imp->fresh(), $admin, null);
    $imp = $imp->fresh();
    $res = $imp->conferencia['etapa']['resultado'];
    echo '     ' . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
    confere($imp->status === 'publicada', 'publicada');
    confere($res['aplicadas'] === 5 && $res['falhas'] === [], 'as 5 mudanças passaram');
    $l = fn ($id) => DB::table('lotes')->where('id', $id)->first();
    confere($l($sumiu->id)->situacao === 'inativo', 'o que sumiu foi inativado');
    confere($l($renum->id)->numero_lote === '9901' && $l($renum->id)->situacao === 'ativo', 'o renumerado é o mesmo lote, com o número novo');
    confere(abs((float) $l($redes->id)->area_gis_m2 - (float) $redes->area_gis_m2) < 1 && DB::table('auditoria')->where('tabela', 'lotes')->where('registro_id', $redes->id)->where('acao', 'editou')->exists(), 'o redesenhado continua o mesmo lote, com a trilha "editou"');
    confere($l($desm->id)->situacao === 'inativo' && DB::table('lote_atos')->join('lote_ato_lotes', 'lote_ato_lotes.ato_id', '=', 'lote_atos.id')
        ->where('lote_atos.tipo', 'desmembramento')->where('lote_ato_lotes.lote_id', $desm->id)->exists(), 'o desmembrado virou ato de desmembramento');
    confere($l($uA->id)->situacao === 'inativo' && $l($uB->id)->situacao === 'inativo', 'os unificados saíram');
    confere(in_array((string) App\Support\NumeroDoLote::normalizar($igual->quadra), $res['quadras_marcadas'], true)
        && DB::table('bairro_ato_quadras')->where('bairro_ato_id', $alt->id)->where('papel', 'alterou')->exists(), 'as quadras revisadas ficam como alteradas pelo decreto');
    confere(DB::table('auditoria')->where('tabela', 'importacoes_lotes')->where('registro_id', $imp->id)->where('acao', 'revisou')->exists(), 'a importação registra a revisão');

    echo "O que mudou depois da leitura não passa\n";
    $op = ['tipo' => 'editar', 'grupo' => 'desenho', 'lote_id' => $igual->id, 'quadra' => $igual->quadra, 'lote' => $igual->numero_lote,
        'area' => (float) $igual->area_gis_m2 + 5, 'para_quadra' => $igual->quadra, 'para_lote' => $igual->numero_lote, 'geometria' => json_decode($igual->g, true)];
    $falso = new ImportacaoLote(['bairro' => $bairro, 'arquivo_nome' => 'x', 'conferencia' => ['revisao_operacoes' => [$op]]]);
    $falso->id = 0;
    $r = app(RevisaoDoBairro::class)->aplicar($falso);
    confere($r['aplicadas'] === [] && str_contains($r['falhas'][0]['motivo'], 'mudou depois'), 'o lote alterado no meio do caminho fica relatado');

    echo "Pela rota\n";
    $req = Illuminate\Http\Request::create('/api/importacoes/' . $imp->id, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $admin);
    Auth::guard('web')->setUser($admin);
    $app->instance('request', $req);
    $resp = $kernel->handle($req);
    $j = json_decode($resp->getContent(), true);
    confere($resp->getStatusCode() === 200 && ! isset($j['conferencia']['revisao_operacoes']) && count($j['conferencia']['etapa']['mudancas']) === 5, 'a ficha traz as mudanças sem as geometrias');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
    @unlink($arquivo);
}
