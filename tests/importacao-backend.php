<?php
// Diagnóstico local explícito, no molde de prancheta-backend.php: tudo roda
// dentro de uma transação que é DESFEITA no fim. Exige a migração
// 2026_09_27_000100 aplicada e pelo menos um usuário administrador.
//
//   php tests/importacao-backend.php
//
// Cobre: acesso externo (rotas fechadas, histórico e ficha redigidos),
// importação em revisão (conferência do arquivo, gravação, visibilidade),
// conferência com o cadastro nos dois sentidos, publicação com e sem
// justificativa, exclusão com e sem vínculo.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ImportacaoLote;
use App\Models\Lote;
use App\Models\User;
use App\Repositories\LoteRepository;
use App\Services\ConferenciaComCadastro;
use App\Services\ImportacaoDeBairro;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}
function recusa(callable $f, string $trecho, string $o_que): void {
    try { $f(); } catch (RuntimeException $e) {
        confere(str_contains($e->getMessage(), $trecho), $o_que . ' (' . $e->getMessage() . ')');
        return;
    }
    confere(false, $o_que . ' — não recusou');
}
/** GET pela pilha HTTP inteira (rotas + middleware), como usuário $u. */
function pedir(User $u, string $url): Illuminate\Http\Response|Symfony\Component\HttpFoundation\Response {
    global $kernel, $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $app->instance('request', $req);
    return $kernel->handle($req);
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador ativo para o teste local.'); }

DB::beginTransaction();
try {
    // ── Usuários de teste ──
    $topografo = User::create(['name' => 'Topógrafo Teste', 'email' => 'topo.teste@exemplo.test',
        'password' => 'x-teste-x-12345', 'perfil' => 'comum', 'tipo_usuario' => 'topografo', 'ativo' => true,
        'curador_cadastral' => true]);
    $contribuinte = User::create(['name' => 'Contribuinte Teste', 'email' => 'contrib.teste@exemplo.test',
        'password' => 'x-teste-x-12345', 'perfil' => 'viewer', 'tipo_usuario' => 'contribuinte', 'ativo' => true]);

    echo "Perfis externos\n";
    confere($topografo->isExterno() && $topografo->perfilEfetivo() === 'viewer', 'topógrafo é externo e visualizador, mesmo gravado como comum');
    confere(! $topografo->canEdit() && ! $topografo->podeLavrarDocumento(), 'topógrafo não escreve fiscalização');
    confere($topografo->podeCurarCadastro(), 'topógrafo marcado como curador cura o cadastro');
    confere(! $contribuinte->podeCurarCadastro(), 'contribuinte sem marcação não cura');
    confere(! $topografo->podeVerDocumentos() && $admin->podeVerDocumentos(), 'externo não vê documento; admin vê');

    echo "Rotas fechadas ao externo\n";
    foreach (['/api/documentos', '/api/painel', '/api/protocolos', '/api/os', '/api/trilha', '/api/parametros'] as $url) {
        confere(pedir($contribuinte, $url)->getStatusCode() === 403, "externo recebe 403 em {$url}");
    }
    $comVistoria = DB::table('vistorias')->value('id');
    if ($comVistoria) {
        confere(pedir($contribuinte, "/api/vistorias/{$comVistoria}")->getStatusCode() === 403, 'externo não abre vistoria');
        confere(pedir($contribuinte, "/vistorias/{$comVistoria}/pdf")->getStatusCode() === 403, 'externo não baixa PDF de vistoria');
    }
    confere(pedir($contribuinte, '/api/perfil')->getStatusCode() === 200, 'externo abre o próprio perfil');

    echo "Histórico e ficha redigidos\n";
    $loteComHistorico = DB::table('documentos')->whereNotNull('lote_id')->value('lote_id')
        ?? DB::table('vistorias')->value('lote_id');
    if ($loteComHistorico) {
        $h = json_decode(pedir($contribuinte, "/api/lotes/{$loteComHistorico}/historico")->getContent(), true);
        confere(count($h['eventos']) > 0, 'externo vê que há eventos no lote');
        confere(collect($h['eventos'])->every(fn ($e) => ! isset($e['id']) && ! isset($e['obs']) && ! isset($e['detalhe']) && $e['restrito']),
            'eventos do externo vêm sem id, sem observação, sem detalhe');
        confere($h['vistorias'] === [], 'lista de vistorias vem vazia para externo');
        $hi = json_decode(pedir($admin, "/api/lotes/{$loteComHistorico}/historico")->getContent(), true);
        confere(isset($hi['eventos'][0]['id']), 'interno continua recebendo o id do evento');

        $f = json_decode(pedir($contribuinte, "/api/imoveis/{$loteComHistorico}")->getContent(), true);
        confere(collect($f['documentos'])->every(fn ($d) => $d['id'] === null && $d['agente'] === null),
            'ficha do externo lista documentos sem id e sem agente');
    } else {
        echo "  --  (sem documento/vistoria na base local: redação não exercitada)\n";
    }

    // ── Importação ──
    echo "Importação — conferência do arquivo\n";
    $bairro = 'Bairro Teste Importação';
    $quadrado = function (float $x, float $y) {
        $d = 0.0002;
        return ['type' => 'Polygon', 'coordinates' => [[[$x, $y], [$x + $d, $y], [$x + $d, $y + $d], [$x, $y + $d], [$x, $y]]]];
    };
    $feicoes = [];
    $lotesArquivo = [['01', '1'], ['01', '2'], ['01', '3'], ['02', '1'], [null, '9']];
    foreach ($lotesArquivo as $k => [$q, $l]) {
        $feicoes[] = ['type' => 'Feature', 'geometry' => $quadrado(-54.9 + $k * 0.0003, -15.9),
            'properties' => ['bairro' => $bairro, 'quadra' => $q, 'numero_lote' => $l,
                             'chave' => $bairro . '|' . ($q ?? '?') . '|' . $l, 'area_gis_m2' => 480]];
    }
    $feicoes[] = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [-54.9, -15.9]],
        'properties' => ['bairro' => $bairro]];
    $arquivo = tempnam(sys_get_temp_dir(), 'imp') . '.geojson';
    file_put_contents($arquivo, json_encode(['type' => 'FeatureCollection', 'features' => $feicoes]));

    Auth::guard('web')->setUser($topografo);
    $svc = app(ImportacaoDeBairro::class);
    $c = $svc->conferirArquivo($arquivo, 'teste.geojson');
    confere($c['lidos'] === 5 && $c['ignoradas'] === 1, 'conferência lê 5 polígonos e ignora o ponto');
    confere($c['sem_quadra'] === 1, 'conferência conta o lote sem quadra');
    confere($c['pode_gravar'], 'arquivo novo pode ser gravado');

    $repetido = $feicoes; $repetido[] = $feicoes[0];
    $arqRep = tempnam(sys_get_temp_dir(), 'imp') . '.geojson';
    file_put_contents($arqRep, json_encode(['type' => 'FeatureCollection', 'features' => $repetido]));
    $cr = $svc->conferirArquivo($arqRep, 'rep.geojson');
    // Repetido não barra mais: os lotes entram sem número (ver
    // importacao-pendencias-backend.php).
    confere($cr['pode_gravar'] && $cr['repetidos_total'] === 1, 'número repetido no arquivo é avisado, sem barrar');

    echo "Importação — carregar como rascunho\n";
    $imp = $svc->gravar($arquivo, 'teste.geojson', $topografo);
    confere($imp->status === 'rascunho' && $imp->total_lotes === 5, 'arquivo carregado como rascunho com 5 lotes');
    confere(DB::table('lotes')->where('importacao_id', $imp->id)->where('em_revisao', true)->count() === 5, 'os 5 lotes estão marcados em revisão');
    confere(! DB::table('auditoria')->where('tabela', 'importacoes_lotes')->where('registro_id', $imp->id)->exists(),
        'rascunho não entra na trilha');
    recusa(fn () => $svc->gravar($arquivo, 'teste.geojson', $topografo), 'rascunho', 'mesmo arquivo não entra duas vezes');
    recusa(fn () => $svc->publicar($imp, $admin, null), 'não publicada', 'rascunho não se publica');
    recusa(fn () => $svc->excluir($imp, $admin, 'motivo de teste qualquer'), 'descarte', 'rascunho não se exclui: descarta');

    $outroCurador = User::where('id', '<>', $topografo->id)->where('id', '<>', $admin->id)->first() ?? $admin;
    confere(! $imp->visivelPara($outroCurador) || $outroCurador->isAdmin(), 'rascunho não aparece para outro curador');

    // Descartar: some tudo, sem registro — e o mesmo arquivo pode voltar.
    $svc->descartar($imp);
    confere(! App\Models\ImportacaoLote::find($imp->id), 'descartar apaga o registro do rascunho');
    confere(DB::table('lotes')->where('importacao_id', $imp->id)->count() === 0, 'descartar apaga os lotes do rascunho');
    $imp = $svc->gravar($arquivo, 'teste.geojson', $topografo);
    confere($imp->emRascunho(), 'depois do descarte, o mesmo arquivo carrega de novo');

    $repo = app(LoteRepository::class);
    $bb = [-54.91, -15.91, -54.89, -15.89];
    confere(count($repo->porBbox(...[...$bb, 500])) === 0, 'mapa de quem não revisa não mostra o bairro em revisão');
    confere(count($repo->porBbox(...[...$bb, 500, true])) === 5, 'mapa do curador mostra os 5 lotes');
    confere(count($repo->porBbox(...[...$bb, 500, true, $topografo->id])) === 5, 'quem carregou vê o próprio rascunho');
    confere(count($repo->porBbox(...[...$bb, 500, true, -1])) === 0, 'outro curador não vê rascunho alheio no mapa');
    confere(Lote::query()->publicados()->where('importacao_id', $imp->id)->count() === 0, 'busca (publicados) não acha lote em revisão');
    $loteRev = DB::table('lotes')->where('importacao_id', $imp->id)->value('id');
    confere(pedir($contribuinte, "/api/imoveis/{$loteRev}")->getStatusCode() === 404, 'ficha de lote em revisão é 404 para quem não revisa');

    echo "Lote desenhado no bairro em revisão entra na revisão\n";
    $extra = ImportacaoDeBairro::emRevisaoNoBairro($bairro);
    confere(($extra['importacao_id'] ?? null) === $imp->id && $extra['em_revisao'] === true, 'emRevisaoNoBairro aponta a importação aberta');

    echo "Pré-curadoria\n";
    $idDe = fn ($q, $l) => DB::table('lotes')->where('importacao_id', $imp->id)->where('quadra', $q)->where('numero_lote', $l)->value('id');
    $q2l1 = $idDe('02', '1');
    $edicao = app(App\Services\EdicaoDeLote::class);
    $maior = $quadrado(-54.9 + 3 * 0.0003, -15.9);
    $maior['coordinates'][0][1][0] += 0.00002;
    $maior['coordinates'][0][2][0] += 0.00002;
    $dEd = ['quadra' => '02', 'numero_lote' => '1', 'geometry' => $maior];
    confere($edicao->impedimento(Lote::find($q2l1), $dEd) === null, 'lote em revisão pode ser redesenhado');
    $antesArea = (float) DB::table('lotes')->where('id', $q2l1)->value('area_gis_m2');
    $edicao->aplicar(Lote::find($q2l1), $dEd);
    confere((float) DB::table('lotes')->where('id', $q2l1)->value('area_gis_m2') > $antesArea, 'Editar lote gravou o contorno maior');
    confere(DB::table('auditoria')->where('registro_id', $q2l1)->where('tabela', 'lotes_em_revisao')
        ->where('importacao_id', $imp->id)->where('acao', 'editou')->exists(), 'a edição ficou no registro da importação');
    confere(! DB::table('auditoria')->where('registro_id', $q2l1)->where('tabela', 'lotes')->exists(),
        'e nada foi para o Histórico do cadastro');
    $invade = ['quadra' => '02', 'numero_lote' => '1', 'geometry' => $quadrado(-54.9 + 2 * 0.0003 + 0.0001, -15.9)];
    recusa(fn () => throw new RuntimeException((string) $edicao->impedimento(Lote::find($q2l1), $invade)), 'invade', 'edição que invade o vizinho é recusada');
    $publicado = DB::table('lotes')->where('em_revisao', false)->where('situacao', 'ativo')->value('id');
    recusa(fn () => throw new RuntimeException((string) $edicao->impedimento(Lote::find($publicado), $dEd)), 'pré-curadoria', 'lote publicado não é editável');
    recusa(fn () => throw new RuntimeException((string) ImportacaoDeBairro::misturaRevisao([$q2l1, $publicado])), 'mistura', 'curadoria não mistura lote em revisão com publicado');
    confere(ImportacaoDeBairro::misturaRevisao([$q2l1, $idDe('01', '1')]) === null, 'lotes da mesma importação podem ir juntos');

    $semQuadra = DB::table('lotes')->where('importacao_id', $imp->id)->whereNull('quadra')->value('id');
    Lote::find($semQuadra)->update(['quadra' => '05']);
    Lote::find($semQuadra)->update(['quadra' => null]);
    confere(DB::table('auditoria')->where('registro_id', $semQuadra)->where('tabela', 'lotes_em_revisao')->count() === 2,
        'corrigir quadra na pré-curadoria também vai para o registro da importação');

    // Sucessão INTERNA (dois lotes da própria importação) não prende a importação.
    $ato = DB::table('lote_atos')->insertGetId(['tipo' => 'unificacao', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('lote_ato_lotes')->insert([
        ['ato_id' => $ato, 'lote_id' => $idDe('01', '1'), 'papel' => 'anterior'],
        ['ato_id' => $ato, 'lote_id' => $idDe('01', '2'), 'papel' => 'posterior'],
    ]);
    confere(! isset($svc->vinculos($imp->fresh())['sucessao']), 'sucessão interna da pré-curadoria não conta como vínculo');

    echo "Pré-curadoria: informar número e excluir lotes\n";
    $pre = app(App\Services\PreCuradoriaDeLotes::class);
    DB::beginTransaction();   // ponto de retorno: as contagens seguintes contam com os 5 lotes
    $l3 = Lote::find($idDe('01', '3'));
    $pre->numerar($imp, $l3, '33');
    confere(DB::table('lotes')->where('id', $l3->id)->value('chave') === $bairro . '|01|33', 'informar número grava o número e a chave');
    confere(DB::table('auditoria')->where('registro_id', $l3->id)->where('tabela', 'lotes_em_revisao')
        ->where('acao', 'renumerou')->exists(), 'o número informado vai para o registro da importação');
    recusa(fn () => $pre->numerar($imp, Lote::find($idDe('01', '33')), '2'), 'Já existe', 'número repetido na quadra é recusado');
    recusa(fn () => $pre->numerar($imp, Lote::find($publicado), '999'), 'só se alteram', 'lote publicado não se renumera por aqui');
    recusa(fn () => $pre->excluir($imp, [$idDe('01', '1')]), 'unificação', 'lote preso a ato da pré-curadoria não se exclui');
    $n = $pre->excluir($imp, [$l3->id]);
    confere($n === 1 && ! DB::table('lotes')->where('id', $l3->id)->exists(), 'excluir apaga o lote da importação');
    confere(DB::table('auditoria')->where('registro_id', $l3->id)->where('tabela', 'lotes_em_revisao')
        ->where('acao', 'excluiu')->exists(), 'a exclusão vai para o registro da importação');
    recusa(fn () => $pre->excluir($imp, [$publicado]), 'só se alteram', 'lote publicado não se exclui por aqui');
    DB::rollBack();

    echo "Conferência com o cadastro\n";
    recusa(fn () => app(ConferenciaComCadastro::class)->conferir($imp, app(App\Cadastro\FonteDoCadastro::class), 'x'),
        'ainda não foi ligado', 'sem amarração do bairro a conferência explica e para');

    // Amarra o bairro de teste a um código livre e monta um cadastro:
    //   Q01 L1 ativo (casa) · Q01 L2 inativo · Q01 L3 ausente · Q02 L1 ativo (casa) · Q03 L7 sem lote
    $codigo = '987';
    $vinc = app(App\Cadastro\VinculoDoBairro::class);
    confere($vinc->situacao($bairro, $imp->id)['atual'] === null, 'bairro do arquivo começa sem vínculo');
    $cbId = DB::table('cadastro_bairros')->insertGetId(['codigo' => $codigo,
        'nome_cadastro' => 'BAIRRO TESTE', 'created_at' => now(), 'updated_at' => now()]);
    $comLotes = DB::table('lotes')->where('situacao', 'ativo')->whereNull('importacao_id')->distinct()->pluck('bairro')->all();
    $ocupado = DB::table('cadastro_bairros')->whereNotNull('nome_gis')->get()
        ->first(fn ($b) => in_array($b->nome_gis, $comLotes, true))?->id;
    if ($ocupado) {
        recusa(fn () => $vinc->vincular($bairro, $ocupado, $imp->id), 'já está ligado', 'não toma bairro do cadastro já ligado a nome com lotes');
    }
    $vinc->vincular($bairro, $cbId, $imp->id);
    $s = $vinc->situacao($bairro, $imp->id);
    confere(($s['atual']['codigo'] ?? null) === $codigo, 'vincular liga o bairro do arquivo ao código do cadastro');
    confere(DB::table('auditoria')->where('tabela', 'cadastro_bairros')->where('registro_id', $cbId)->exists(),
        'a ligação fica na trilha do cadastro de bairros');
    $linha = fn ($q, $l, $isencao) => ['inscricao' => App\Support\InscricaoImobiliaria::montar($codigo, $q, $l),
        'codigo_bairro' => '000' . $codigo, 'quadra' => str_pad($q, 3, '0', STR_PAD_LEFT),
        'lote' => str_pad($l, 4, '0', STR_PAD_LEFT), 'isencao' => $isencao,
        'created_at' => now(), 'updated_at' => now()];
    DB::table('cadastro_externo_imoveis')->insert([
        $linha('1', '1', 'Normal'), $linha('1', '2', 'Inativo'), $linha('2', '1', 'Normal'), $linha('3', '7', 'Normal'),
    ]);

    $r = app(ConferenciaComCadastro::class)->conferir($imp->fresh(), app(App\Cadastro\FonteDoCadastro::class), 'Cadastro de teste');
    confere($r['casaram'] === 2, 'casaram Q01 L1 e Q02 L1');
    confere(count($r['inativos']) === 1 && $r['inativos'][0]['lote'] === '2', 'Q01 L2 acusado como inativo no cadastro');
    confere(count($r['nao_encontrados']) === 1 && $r['nao_encontrados'][0]['lote'] === '3', 'Q01 L3 acusado como não encontrado');
    confere(count($r['sem_lote']) === 1 && str_contains($r['sem_lote'][0]['inscricao'], '.003.0007.'), 'Q03 L7 do cadastro acusado sem lote');
    confere(count($r['sem_inscricao']) === 1, 'lote sem quadra acusado');
    confere($r['total_divergencias'] === 4, 'quatro divergências no total');
    confere(! DB::table('auditoria')->where('tabela', 'importacoes_lotes')->where('registro_id', $imp->id)->exists(),
        'conferência de rascunho não entra na trilha');

    echo "Conferência do BAIRRO (a que fica depois da importação)\n";
    $rb = app(ConferenciaComCadastro::class)->conferirBairro($bairro, app(App\Cadastro\FonteDoCadastro::class), 'Cadastro de teste');
    confere($rb['total_divergencias'] === 4 && $rb['casaram'] === 2, 'o bairro inteiro dá a mesma conferência da importação');
    $guardada = DB::table('conferencias_bairro')->where('bairro', $bairro)->first();
    confere($guardada && json_decode($guardada->resultado, true)['total_divergencias'] === 4, 'a conferência do bairro fica guardada');
    confere(isset($rb['centros_quadras']['1'], $rb['centros_quadras']['2']), 'cada quadra tem o seu centro, para o selo "sem lote"');
    confere($imp->fresh()->conferencia_cadastro['total_divergencias'] === 4, 'a conferência da importação não foi tocada');

    echo "Conferir de novo SEM planilha: revisa só as divergências guardadas\n";
    $svcConf = app(ConferenciaComCadastro::class);
    DB::beginTransaction();   // ponto de retorno: os testes seguintes contam com o bairro como estava
    // Como se a última conferência tivesse sido com a PLANILHA (que não é guardada).
    $comPlanilha = ['fonte' => 'planilha', 'fonte_descricao' => 'Planilha teste.xlsx'] + $rb;
    DB::table('conferencias_bairro')->where('bairro', $bairro)->update(['resultado' => json_encode($comPlanilha)]);
    // Correções no mapa: o "não encontrado" Q01 L3 vira Q03 L7 — que é o "cadastro sem lote";
    // o lote sem quadra ganha a quadra 05, inscrição que a lista não conhece.
    DB::table('lotes')->where('id', $idDe('01', '3'))->update(['quadra' => '03', 'numero_lote' => '7']);
    DB::table('lotes')->where('id', $semQuadra)->update(['quadra' => '05']);
    $rv = $svcConf->revisarBairro($bairro);
    confere(count($rv['sem_lote']) === 0 && $rv['casaram'] === $rb['casaram'] + 1,
        'lote renumerado para a inscrição do "cadastro sem lote": as duas pendências se resolvem');
    confere(count($rv['sem_inscricao']) === 0 && count($rv['nao_encontrados']) === 1 && ! empty($rv['nao_encontrados'][0]['alterado']),
        'lote que ganhou inscrição desconhecida fica pendente, marcado "alterado" (sem planilha não há como confirmar)');
    confere(count($rv['inativos']) === 1, 'o inativo no cadastro, sem mudança no lote, continua pendente');
    confere($rv['fonte'] === 'planilha' && ($rv['revisao']['resolvidas'] ?? null) === 1, 'a revisão diz quantas resolveu e mantém a fonte');
    confere(! DB::getSchemaBuilder()->hasTable('cadastro_retratos'), 'a planilha não é guardada em lugar nenhum');
    $fonteVazia = new class implements App\Cadastro\FonteDoCadastro {
        public function consultar(App\Models\Lote $l): ?App\Cadastro\RetratoBci { return null; }
        public function porQueVazio(App\Models\Lote $l): string { return ''; }
        public function nome(): string { return 'exportacao'; }
        public function imoveisDoBairro(string $c): iterable { return []; }
        public function proprietarios(App\Models\Lote $l): array { return []; }
        public function situacao(App\Models\Lote $l): array { return ['inscricoes' => [], 'carga_id' => null, 'em' => null, 'alterado_em' => null, 'ausente_desde' => null]; }
    };
    recusa(fn () => $svcConf->conferirBairro($bairro, $fonteVazia, 'Cadastro vazio'), 'não tem nenhum imóvel',
        'fonte sem nenhum imóvel do bairro é recusada (não vira "todos não encontrados")');
    DB::rollBack();
    $rr = $rb;
    $conferidoEm = now()->subMinute()->toDateTimeString();
    DB::table('lotes')->where('importacao_id', $imp->id)->limit(1)->update(['updated_at' => now()]);
    confere(! $svcConf->bairroEmDia($rr, $conferidoEm), 'lote corrigido depois da conferência: ela não está mais em dia');
    confere(! $svc->mudouDepoisDaConferencia($imp->fresh()), 'conferência em dia logo depois de conferir');
    $cb2 = DB::table('cadastro_bairros')->insertGetId(['codigo' => '986', 'nome_cadastro' => 'BAIRRO TESTE B',
        'created_at' => now(), 'updated_at' => now()]);
    $vinc->vincular($bairro, $cb2, $imp->id);
    confere($svc->mudouDepoisDaConferencia($imp->fresh()), 'trocar o bairro do cadastro pede nova conferência');
    $vinc->vincular($bairro, $cbId, $imp->id);
    confere(DB::table('cadastro_bairros')->where('id', $cb2)->value('nome_gis') === null, 'a ligação anterior é solta ao trocar');

    echo "Salvar\n";
    $svc->salvar($imp->fresh(), $topografo);
    $imp = $imp->fresh();
    confere($imp->status === 'revisao' && $imp->salvo_em !== null, 'salvo, o rascunho vira importação em revisão');
    confere(DB::table('auditoria')->where('tabela', 'importacoes_lotes')->where('registro_id', $imp->id)
        ->where('acao', 'importou')->exists(), 'salvar registra a importação na trilha');
    recusa(fn () => $svc->descartar($imp), 'descarta', 'importação salva não se descarta');

    echo "Excluir (só a salva, não publicada)\n";
    DB::beginTransaction();   // ponto de retorno: a mesma importação segue para a publicação
    $idLote = DB::table('lotes')->where('importacao_id', $imp->id)->value('id');
    $vist = DB::table('vistorias')->first();
    if ($vist) {
        DB::table('vistorias')->where('id', $vist->id)->update(['lote_id' => $idLote]);
        recusa(fn () => $svc->excluir($imp->fresh(), $admin, 'motivo de teste qualquer'), 'vínculos', 'lote com vistoria impede a exclusão');
        DB::table('vistorias')->where('id', $vist->id)->update(['lote_id' => $vist->lote_id]);
    }
    $n = $svc->excluir($imp->fresh(), $topografo, 'motivo de teste qualquer');
    confere($n === 5, 'exclusão da importação salva apaga os 5 lotes');
    confere(DB::table('lotes_apagados')->where('motivo', 'like', "Importação nº {$imp->id} excluída%")->count() === 5,
        'o desenho dos 5 lotes ficou guardado em lotes_apagados');
    confere($imp->fresh()->status === 'excluida', 'o registro da importação fica, como excluída');
    confere(DB::table('auditoria')->where('tabela', 'importacoes_lotes')->where('registro_id', $imp->id)->count() >= 2,
        'importou (ao salvar) e excluiu ficaram na trilha');
    DB::rollBack();

    echo "Publicar\n";
    $imp = $imp->fresh();
    recusa(fn () => $svc->publicar($imp, $admin, null), 'justificativa', 'com divergência e sem justificativa não publica');
    recusa(fn () => $svc->publicar($imp, $admin, 'curta'), 'justificativa', 'justificativa curta não serve');

    DB::table('lotes')->where('importacao_id', $imp->id)->limit(1)->update(['updated_at' => now()->addMinute()]);
    recusa(fn () => $svc->publicar($imp->fresh(), $admin, str_repeat('j', 30)), 'Confira de novo', 'lote alterado depois da conferência pede nova conferência');
    DB::table('lotes')->where('importacao_id', $imp->id)->update(['updated_at' => now()->subMinute()]);

    Auth::guard('web')->setUser($admin);
    $svc->publicar($imp->fresh(), $admin, 'Quadra 03 ainda não lançada no cadastro; Q01 L2 será reativado.');
    $imp = $imp->fresh();
    confere($imp->status === 'publicada' && $imp->justificativa_publicacao !== null, 'publicada com a justificativa gravada');
    confere(count($repo->porBbox(...[...$bb, 500])) === 5, 'depois de publicar, o bairro aparece para todos');

    recusa(fn () => $svc->excluir($imp->fresh(), $admin, 'motivo de teste qualquer'), 'publicada não se exclui',
        'importação publicada não se exclui');
    confere(DB::table('auditoria')->where('tabela', 'importacoes_lotes')->where('registro_id', $imp->id)->count() >= 2,
        'importou (ao salvar) e publicou ficaram na trilha');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
    @unlink($arquivo ?? '');
    @unlink($arqRep ?? '');
}
