<?php
// Diagnóstico local, no molde de sinalizacao-backend.php: roda numa transação
// DESFEITA no fim. Cobre a vistoria guiada ligada ao resto do sistema
// (26/11/2026): sugestão para a peça (artigos, área, exigências), fotos que a
// peça traz, conclusão que resolve a sinalização e atende o protocolo
// cadastral, histórico e resumo do imóvel, painel e denúncia.
//
//   php tests/vistoria-guiada-integracao.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CadastroLoteController;
use App\Http\Controllers\DenunciaController;
use App\Http\Controllers\DocumentoAnexoController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\VistoriaController;
use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Sinalizacao;
use App\Models\User;
use App\Models\Vg\VgArquivo;
use App\Models\Vg\VgModelo;
use App\Models\Vg\VgModeloVersao;
use App\Models\Vg\VgVistoria;
use App\Services\SucessaoDeLotes;
use App\Services\VistoriaGuiadaConcluida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }
function req(User $u, array $q = []): Request {
    Auth::guard('web')->setUser($u);
    $r = Request::create('/', 'GET', $q, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $r->setUserResolver(fn () => $u);
    app()->instance('request', $r);
    return $r;
}
function json($r): array { return json_decode($r->getContent(), true); }
function privado(object $o, string $m, ...$a) { $x = new ReflectionMethod($o, $m); $x->setAccessible(true); return $x->invoke($o, ...$a); }

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->firstOrFail();
session(['modulo' => 'obras']);
$lote = Lote::where('situacao', 'ativo')->where('em_revisao', false)->firstOrFail();
$artigo = Artigo::whereHas('legislacao', fn ($l) => $l->where('modulo', 'obras'))->where('ativo', true)->firstOrFail();

DB::beginTransaction();
try {
    // ── um modelo com área da multa e um item confere ──
    $def = ['local' => 'nao', 'nao_foto' => false, 'vinculos' => [], 'conclusoes' => ['Regular', 'Irregular'], 'anexos' => [],
        'etapas' => [['id' => 'e1', 'titulo' => 'Obra', 'instrucao' => '', 'foto' => 'opcional', 'foto_min' => 1, 'foto_dica' => '', 'anexo' => '',
            'itens' => [
                ['id' => 'ia', 'rotulo' => 'Área construída', 'obrig' => true, 'tipo' => 'numero', 'unidade' => 'm²', 'metodo' => true, 'papel' => 'area'],
                ['id' => 'ir', 'rotulo' => 'Recuos', 'obrig' => true, 'tipo' => 'confere'],
            ]]]];
    $m = VgModelo::create(['nome' => 'Teste integração', 'rascunho' => $def, 'rascunho_alterado' => false, 'versao_atual' => 1, 'user_id' => $admin->id]);
    $ver = VgModeloVersao::create(['modelo_id' => $m->id, 'versao' => 1, 'nome' => $m->nome, 'definicao' => $def, 'publicado_por' => $admin->id, 'publicado_em' => now()]);

    $prot = Protocolo::create(['numero' => 'TESTE-VG-1', 'tipo' => 'desmembramento', 'situacao' => 'deferido', 'lote_id' => $lote->id,
        'requerente_nome' => 'Requerente T', 'protocolado_em' => now()->toDateString(), 'modulo' => 'obras']);
    $sin = Sinalizacao::create(['modulo' => 'obras', 'lote_id' => $lote->id, 'tipo' => 'outro', 'comentario' => 'pendência de teste',
        'user_id' => $admin->id, 'lat' => -15.56, 'lng' => -54.29]);

    $v = VgVistoria::create(['versao_id' => $ver->id, 'exercicio' => 2099, 'numero' => 1, 'modulo' => 'obras', 'user_id' => $admin->id,
        'iniciada_em' => now(), 'estado' => 'em_andamento', 'mapa_lat' => -15.56, 'mapa_lng' => -54.29,
        'respostas' => [
            'ia' => ['v' => 88.5, 'm' => 'trena'],
            'ir' => ['v' => 'nao', 'obs' => 'Recuo de 2 m', 'art' => [$artigo->id], 'exig' => 'Adequar o recuo', 'prazo' => 30],
        ]]);
    $v->vinculos()->create(['tipo' => 'imovel', 'ref_id' => $lote->id, 'rotulo' => 'Lote T', 'user_id' => $admin->id]);
    $v->vinculos()->create(['tipo' => 'protocolo', 'ref_id' => $prot->id, 'rotulo' => 'Protocolo T', 'user_id' => $admin->id]);
    $v->fiscais()->create(['user_id' => $admin->id]);
    $foto = VgArquivo::create(['vistoria_id' => $v->id, 'papel' => 'foto', 'etapa_chave' => 'e1', 'item_chave' => 'ir', 'nome' => 'Foto',
        'caminho' => 'vistorias-guiadas/teste/x.jpg', 'mime' => 'image/jpeg', 'tamanho' => 10, 'user_id' => $admin->id]);
    $v->refresh();

    echo "Leitura da vistoria\n";
    confere($v->areaDaMulta()['valor'] === 88.5 && str_contains($v->areaDaMulta()['rotulo'], 'trena'), 'área da multa com o método');
    confere($v->artigosCitadosIds() === [$artigo->id], 'artigo citado no "não confere"');
    confere(count($v->exigencias()) === 1 && $v->exigencias()[0]['prazo_dias'] === 30, 'exigência com prazo');
    confere($v->loteId() === $lote->id, 'imóvel ligado');
    confere($v->situacao() === 'em_andamento', 'em andamento antes de concluir');

    echo "Sugestão para a peça\n";
    $s = json((new DocumentoController(app(App\Services\LavraturaService::class)))->sugestaoGuiada(req($admin), $v));
    confere($s['vistoria']['guiada'] === true && $s['vistoria']['area_construida_m2'] == 88.5, 'sugestão traz a área');
    confere(collect($s['artigos'])->pluck('id')->all() === [$artigo->id], 'sugestão traz o artigo');
    confere(str_contains($s['exigencias'][0]['rotulo'], '30 dias'), 'sugestão traz a exigência com prazo');

    echo "Peça ligada à guiada\n";
    $doc = Documento::create(['modulo' => 'obras', 'tipo' => 'notificacao', 'lote_id' => $lote->id, 'vg_vistoria_id' => $v->id,
        'anexos_proprios' => true, 'agente_id' => $admin->id, 'status' => 'rascunho', 'data_fato' => now()]);
    confere($doc->numeroDaVistoria() === $v->numeroFormatado(), 'a peça mostra o número da guiada');
    $an = new DocumentoAnexoController();
    $a = json($an->index(req($admin), $doc));
    confere(count($a['da_vistoria']) === 1 && $a['da_vistoria'][0]['id'] === $foto->id && str_contains($a['da_vistoria'][0]['titulo'], 'Recuos'), 'a foto da guiada aparece para trazer');
    confere($a['vistoria'] === $v->numeroFormatado(), 'a aba Anexos cita a guiada');
    $t = $an->trazer(req($admin, ['de' => 'vistoria', 'id' => $foto->id]), $doc);
    confere($t->getStatusCode() === 201, 'trazer a foto da guiada');
    confere($doc->anexos()->where('origem', 'vistoria')->where('origem_ref', $foto->id)->value('arquivo') === $foto->caminho, 'o anexo aponta para o arquivo da guiada');
    $outra = VgArquivo::create(['vistoria_id' => $v->id, 'papel' => 'anexo', 'nome' => 'PDF', 'caminho' => 'x.pdf', 'mime' => 'application/pdf', 'tamanho' => 1, 'user_id' => $admin->id]);
    confere($an->trazer(req($admin, ['de' => 'vistoria', 'id' => $outra->id]), $doc)->getStatusCode() === 422, 'PDF da vistoria não entra como foto');
    $auto = Documento::create(['modulo' => 'obras', 'tipo' => 'auto_infracao', 'lote_id' => $lote->id, 'vg_vistoria_id' => $v->id,
        'anexos_proprios' => true, 'agente_id' => $admin->id, 'status' => 'rascunho', 'data_fato' => now()]);
    confere($auto->numeroDaVistoria() === null && json($an->index(req($admin), $auto))['da_vistoria'] === [], 'auto de infração não traz vistoria');

    echo "Antes de concluir\n";
    $cad = new CadastroLoteController(app(SucessaoDeLotes::class));
    $prot->update(['vg_vistoria_id' => null]);

    echo "Conclusão\n";
    $v->update(['estado' => 'concluida', 'conclusao' => 'Regular', 'concluida_em' => now()]);
    $ef = app(VistoriaGuiadaConcluida::class)->aoConcluir($v->fresh(), $admin);
    confere($ef['sinalizacoes'] === 1 && $sin->fresh()->status === 'resolvida' && $sin->fresh()->vg_vistoria_id === $v->id, 'a conclusão resolve a sinalização do imóvel');
    confere($ef['protocolos'] === 1 && $prot->fresh()->vg_vistoria_id === $v->id, 'a conclusão atende o protocolo cadastral');
    confere(privado($cad, 'recusarSemVistoria', $prot->fresh()) === null, 'guiada Regular libera o ato cadastral');
    $ato = app(SucessaoDeLotes::class)->atoDaVistoria($v->fresh());
    confere(($ato['protocolo']['id'] ?? null) === $prot->id, 'o ato cadastral acha o protocolo pela guiada');
    $v->update(['conclusao' => 'Irregular']);
    $r = privado($cad, 'recusarSemVistoria', $prot->fresh());
    confere($r !== null && $r->getStatusCode() === 422 && str_contains(json($r)['message'], 'Irregular'), 'guiada Irregular não libera o ato');
    confere(app(SucessaoDeLotes::class)->atoDaVistoria($v->fresh())['pode'] === false, 'nem pelo histórico');
    $v->update(['conclusao' => 'Regular']);

    echo "Sinalização de rua\n";
    $rua = Sinalizacao::create(['modulo' => 'obras', 'tipo' => 'outro', 'comentario' => 'na rua, sem lote', 'user_id' => $admin->id, 'lat' => -15.5, 'lng' => -54.2]);
    $v2 = VgVistoria::create(['versao_id' => $ver->id, 'exercicio' => 2099, 'numero' => 2, 'modulo' => 'obras', 'user_id' => $admin->id,
        'estado' => 'concluida', 'conclusao' => 'Regular', 'sinalizacao_id' => $rua->id, 'respostas' => []]);
    confere(app(VistoriaGuiadaConcluida::class)->aoConcluir($v2, $admin)['sinalizacoes'] === 1 && $rua->fresh()->status === 'resolvida', 'a vistoria nascida da sinalização de rua a resolve');

    echo "Histórico e resumo do imóvel\n";
    $h = json((new VistoriaController(app(App\Services\LavraturaService::class)))->historico($lote));
    $ev = collect($h['eventos'])->firstWhere('tipo', 'vistoria_guiada');
    confere($ev && $ev['id'] === $v->id && $ev['badge']['texto'] === 'Regular' && $ev['badge']['classe'] === 'bd-ok', 'a guiada entra na linha do tempo');
    confere(in_array($artigo->numero . ($artigo->apelido ? ' — ' . $artigo->apelido : ''), $ev['itens'], true), 'com o artigo citado');
    confere($h['resumo']['vistorias'] >= 1 && $h['resumo']['ultima_vistoria'] !== null, 'o resumo conta a guiada');
    confere(str_contains($h['resumo']['fachada']['url'] ?? '', '/vistorias-guiadas/fotos/'), 'a fachada vem da foto da guiada');

    echo "Painel\n";
    $p = new PainelController();
    $inf = privado($p, 'infracoesFrequentes', now()->subDay());
    confere(collect($inf)->contains(fn ($x) => str_contains($x['rotulo'], $artigo->numero)), 'o artigo da guiada entra nas infrações frequentes');

    echo "Denúncia\n";
    $den = App\Models\Denuncia::first()
        ?? App\Models\Denuncia::create(['data_hora' => now(), 'logradouro' => 'Rua de Teste', 'agente_id' => $admin->id]);
    if ($den) {
        $v->vinculos()->create(['tipo' => 'denuncia', 'ref_id' => $den->id, 'rotulo' => 'Denúncia T', 'user_id' => $admin->id]);
        $d = json((new DenunciaController())->show(req($admin), $den))['denuncia'];
        confere(collect($d['vistorias'])->contains(fn ($x) => $x['id'] === $v->id && $x['conclusao'] === 'Regular'), 'a denúncia mostra a conclusão da vistoria');
    } else { echo "  --  sem denúncia no banco local; pulei\n"; }

    echo "Situação lida da conclusão\n";
    $sit = fn ($c) => (new VgVistoria(['estado' => 'concluida', 'conclusao' => $c]))->situacao();
    confere($sit('Regular') === 'regular' && $sit('Apto ao habite-se') === 'regular' && $sit('Improcedente') === 'regular' && $sit('Não constatado') === 'regular', 'regular');
    confere($sit('Irregular') === 'irregular' && $sit('Não apto') === 'irregular' && $sit('Procedente') === 'irregular', 'irregular');
    confere($sit('Apto com ressalvas') === 'em_acompanhamento' && $sit('Com ressalvas') === 'em_acompanhamento' && $sit('Qualquer') === 'em_acompanhamento', 'em acompanhamento');
    confere($sit('Não localizado') === 'nao_localizado', 'não localizado');

    echo "\n{$ok} conferências passaram.\n";
} finally {
    DB::rollBack();
}
