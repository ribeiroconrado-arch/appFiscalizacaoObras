<?php
// Diagnóstico local explícito, no molde de embargo-backend.php: tudo roda
// dentro de uma transação que é DESFEITA no fim. Exige a migração
// 2026_10_15_000100 aplicada e um administrador agente ativo.
//
//   php tests/multa-backend.php
//
// Cobre as formas de multa do artigo: fixa, por m², por faixa de área e
// múltiplo do alvará; a escolha da área ("obra, senão terreno"); a validação
// em Parâmetros; a prévia (/api/multas/simular); a memória congelada na peça;
// e a recusa de lavrar Auto de Infração com multa por calcular.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\LegislacaoController;
use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\Lote;
use App\Models\Upf;
use App\Models\User;
use App\Services\DocumentoImpressao;
use App\Services\LavraturaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}
/** Direto no controlador (o POST pelo kernel esbarra no token CSRF). Devolve [status, corpo]. */
function chamar(User $u, string $classe, string $metodo, array $corpo = [], array $args = []): array {
    global $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create('/x', $corpo ? 'POST' : 'GET', $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    try {
        $r = $app->make($classe)->{$metodo}($req, ...$args);
    } catch (Illuminate\Validation\ValidationException $e) {
        return [422, ['errors' => $e->errors()]];
    }
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador agente ativo para o teste local.'); }
$lote = Lote::where('situacao', 'ativo')->where('em_revisao', false)->first();
if (! $lote) { throw new RuntimeException('Sem lote para o teste local.'); }

DB::beginTransaction();
try {
    // UPF conhecida, para a conversão do alvará dar número redondo.
    Upf::query()->delete();
    Upf::create(['exercicio' => 2000, 'valor' => 250, 'vigencia_inicio' => '2000-01-01']);

    $lei = Legislacao::create(['numero' => 'TESTE MULTA 1/2099', 'nome' => 'Lei de teste da multa', 'ano' => 2099,
        'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true]);
    $novo = fn (string $numero, array $extra) => Artigo::create(['legislacao_id' => $lei->id, 'numero' => $numero,
        'apelido' => $numero, 'conduta' => 'conduta de teste', 'ativo' => true] + $extra);
    $faixas = [['ate_m2' => 60, 'upf' => 50], ['ate_m2' => 120, 'upf' => 100], ['ate_m2' => null, 'upf' => 600]];

    $fixa   = $novo('Art. M1', ['base_multa' => 'fixa', 'multa_upf' => 30]);
    $m2     = $novo('Art. M2', ['base_multa' => 'por_m2', 'multa_area' => 'construida', 'multa_upf_m2' => 0.5, 'multa_min_upf' => 20, 'multa_max_upf' => 80]);
    $porFx  = $novo('Art. M3', ['base_multa' => 'faixas', 'multa_area' => 'construida', 'multa_faixas' => $faixas]);
    $escav  = $novo('Art. M4', ['base_multa' => 'por_m2', 'multa_area' => 'construida_ou_terreno', 'multa_upf_m2' => 0.2]);
    $tres   = $novo('Art. M5', ['base_multa' => 'multiplo_alvara', 'multa_mult_min' => 3, 'multa_mult_max' => 3]);
    $umDez  = $novo('Art. M6', ['base_multa' => 'multiplo_alvara', 'multa_mult_min' => 1, 'multa_mult_max' => 10]);
    $terr   = $novo('Art. M7', ['base_multa' => 'faixas', 'multa_area' => 'terreno', 'multa_faixas' => $faixas]);

    echo "Cada forma calcula\n";
    confere($fixa->calcularMulta(null, null)['valor'] === 30.0, 'valor fixo');
    $c = $m2->calcularMulta(500, 100);
    confere($c['valor'] === 50.0 && $c['area_usada'] === 'construida' && str_contains($c['memoria'], '0,5000 UPF/m² × 100,00 m²'), 'por m² construído');
    confere($m2->calcularMulta(500, 10)['valor'] === 20.0 && str_contains($m2->calcularMulta(500, 10)['memoria'], 'piso'), 'por m²: piso aplicado');
    confere($m2->calcularMulta(500, 900)['valor'] === 80.0 && str_contains($m2->calcularMulta(500, 900)['memoria'], 'teto'), 'por m²: teto aplicado');

    echo "Faixas de área\n";
    confere($porFx->calcularMulta(null, 60)['valor'] === 50.0, '60 m² cai em "até 60"');
    $c = $porFx->calcularMulta(null, 60.01);
    confere($c['valor'] === 100.0 && str_contains($c['memoria'], 'acima de 60,00 até 120,00 m²'), '60,01 m² cai na faixa seguinte, e a memória diz qual');
    $c = $porFx->calcularMulta(null, 5000);
    confere($c['valor'] === 600.0 && str_contains($c['memoria'], 'acima de 120,00 m²'), 'acima do último limite cai na faixa aberta');
    $c = $terr->calcularMulta(100, 9999);
    confere($c['valor'] === 100.0 && $c['area_usada'] === 'terreno' && str_contains($c['memoria'], 'terreno de 100,00 m²'), 'faixa por área do TERRENO ignora a construída');
    $c = $porFx->calcularMulta(500, null);
    confere($c['valor'] === 0.0 && $c['pendencia'] === 'a área construída', 'sem a área, não calcula e diz o que falta');

    echo "Obra, senão terreno\n";
    $c = $escav->calcularMulta(400, 150);
    confere($c['valor'] === 30.0 && $c['area_usada'] === 'construida' && ! str_contains($c['memoria'], 'terreno'), 'com obra, usa a área da obra');
    $c = $escav->calcularMulta(400, null);
    confere($c['valor'] === 80.0 && $c['area_usada'] === 'terreno' && str_contains($c['memoria'], 'Sem área construída informada — calculado sobre o terreno'),
        'sem obra, usa o terreno, e a memória diz por quê');
    confere($escav->calcularMulta(400, 0.0)['area_usada'] === 'terreno', 'área construída zero é "sem obra"');
    confere($escav->calcularMulta(null, null)['pendencia'] === 'a área construída ou a do terreno', 'sem nenhuma das duas, pede uma delas');

    echo "Múltiplo do alvará\n";
    $c = $tres->calcularMulta(null, null, 1250.0, null, 250.0);
    confere($c['valor'] === 15.0 && $c['valor_reais'] === 3750.0 && str_contains($c['memoria'], '3,00 × R$ 1.250,00 (valor do alvará) = R$ 3.750,00 = 15,00 UPF'),
        'multiplicador fixo: 3 × alvará, convertido em UPF');
    confere($tres->calcularMulta(null, null, null, null, 250.0)['pendencia'] === 'o valor do alvará', 'sem o valor do alvará, pede');
    confere(str_contains($umDez->calcularMulta(null, null, 1000.0, null, 250.0)['pendencia'], 'multiplicador'), 'intervalo sem multiplicador: pede');
    confere($umDez->calcularMulta(null, null, 1000.0, 11.0, 250.0)['valor'] === 0.0
        && str_contains($umDez->calcularMulta(null, null, 1000.0, 11.0, 250.0)['pendencia'], '1,00 a 10,00×'), 'multiplicador fora do intervalo é recusado');
    confere($umDez->calcularMulta(null, null, 1000.0, 4.0, 250.0)['valor'] === 16.0, 'multiplicador dentro do intervalo calcula');
    confere(str_contains($umDez->calcularMulta(null, null, 1000.0, 4.0, null)['pendencia'], 'UPF'), 'sem UPF vigente, não converte e pede');

    echo "Entre mínimo e máximo, a critério do fiscal\n";
    $tapume = $novo('Art. M8', ['base_multa' => 'intervalo', 'multa_min_upf' => 50, 'multa_max_upf' => 200, 'multa_dobra_reincidencia' => true]);
    confere(str_contains($tapume->calcularMulta(null, null)['pendencia'], 'o valor da multa do Art. M8 (50,00 a 200,00 UPF)'), 'sem o valor, pede — e diz o intervalo');
    confere($tapume->calcularMulta(null, null, null, 250.0)['valor'] === 0.0 && $tapume->calcularMulta(null, null, null, 49.0)['pendencia'] !== null, 'valor fora do intervalo é recusado');
    $c = $tapume->calcularMulta(null, null, null, 120.0);
    confere($c['valor'] === 120.0 && str_contains($c['memoria'], '120,00 UPF (fixado pelo fiscal, de 50,00 a 200,00 UPF)'), 'dentro do intervalo, vale o que o fiscal fixou');
    confere($tapume->rotuloMulta() === '50 a 200 UPF', 'resumo do intervalo');

    echo "Reincidência dobra\n";
    $c = $tapume->calcularMulta(null, null, null, 120.0, null, 2);
    confere($c['valor'] === 240.0 && $c['fator'] === 2 && str_contains($c['memoria'], '× 2 (reincidência) = 240,00 UPF'), 'artigo marcado: a multa dobra, e a memória diz');
    confere($tapume->calcularMulta(null, null, null, 120.0, null, 4)['valor'] === 480.0, 'segunda reincidência: quatro vezes');
    confere($fixa->calcularMulta(null, null, null, null, null, 2)['valor'] === 30.0, 'artigo NÃO marcado não dobra');
    confere($tapume->calcularMulta(null, null, null, null, null, 2)['valor'] === 0.0, 'com pendência não há o que dobrar');

    echo "Parâmetros: gravar o artigo\n";
    $base = ['legislacao_id' => $lei->id, 'numero' => 'Art. M9', 'termos' => ['teste'], 'documentos' => ['notificacao', 'auto_infracao']];
    [$s] = $grava0 = chamar($admin, LegislacaoController::class, 'salvarArtigo', $base + ['base_multa' => 'intervalo', 'multa_min_upf' => 50]);
    confere($s === 422, 'multa "entre mínimo e máximo" sem o máximo é recusada');
    $grava = fn (array $extra) => chamar($admin, LegislacaoController::class, 'salvarArtigo', $base + $extra);
    [$s] = $grava(['base_multa' => 'por_m2', 'multa_upf_m2' => 1]);
    confere($s === 422, '"por m²" sem dizer qual área é recusado');
    [$s] = $grava(['base_multa' => 'faixas', 'multa_area' => 'terreno', 'multa_faixas' => [['ate_m2' => 60, 'upf' => 50], ['ate_m2' => 120, 'upf' => 90]]]);
    confere($s === 422, 'faixas sem a faixa aberta no fim são recusadas');
    [$s] = $grava(['base_multa' => 'faixas', 'multa_area' => 'terreno', 'multa_faixas' => [['ate_m2' => 120, 'upf' => 50], ['ate_m2' => 60, 'upf' => 90], ['ate_m2' => null, 'upf' => 99]]]);
    confere($s === 422, 'faixas fora de ordem são recusadas');
    [$s] = $grava(['base_multa' => 'multiplo_alvara', 'multa_mult_min' => 5, 'multa_mult_max' => 2]);
    confere($s === 422, 'multiplicador máximo menor que o mínimo é recusado');
    [$s, $d] = $grava(['base_multa' => 'faixas', 'multa_area' => 'construida_ou_terreno', 'multa_upf' => 77, 'multa_faixas' => $faixas]);
    $m9 = Artigo::find($d['id'] ?? 0);
    confere($s === 200 && $m9 && count($m9->multa_faixas) === 3 && $m9->multa_faixas[2]['ate_m2'] === null && $m9->multa_upf === null,
        'faixas válidas gravam, e o valor fixo esquecido é limpo');
    [, $d] = chamar($admin, LegislacaoController::class, 'index');
    $naLista = collect(collect($d['leis'])->firstWhere('id', $lei->id)['artigos'])->firstWhere('id', $m9->id);
    confere($naLista['multa_area'] === 'construida_ou_terreno' && $naLista['multa_rotulo'] === '3 faixa(s) · obra ou terreno', 'a lista devolve a configuração e o resumo');
    confere($umDez->rotuloMulta() === '1 a 10× o alvará' && $tres->rotuloMulta() === '3× o alvará' && $m2->rotuloMulta() === '0,5 UPF/m² · obra', 'resumos dos outros modos');

    echo "Prévia (POST /api/multas/simular)\n";
    [$s, $d] = chamar($admin, DocumentoController::class, 'simularMulta', ['artigos' => [$fixa->id, $porFx->id, $umDez->id],
        'area_construida_m2' => 98.5, 'alvara_valor' => 1000, 'multiplicadores' => [$umDez->id => 2]]);
    confere($s === 200 && $d['total_upf'] == 138.0 && $d['pendencias'] === [] && $d['total_reais'] == 34500.0, 'soma as três formas: 30 + 100 + 8 UPF');
    [, $d] = chamar($admin, DocumentoController::class, 'simularMulta', ['artigos' => [$porFx->id, $umDez->id], 'alvara_valor' => 1000]);
    confere(count($d['pendencias']) === 2, 'a prévia lista o que falta, sem inventar valor');

    echo "Na peça: gravar, lavrar, imprimir\n";
    $peca = fn (string $tipo, array $extra) => chamar($admin, DocumentoController::class, 'store',
        ['tipo' => $tipo, 'legislacao_id' => $lei->id, 'prazo_dias' => 5] + $extra, [$lote]);
    [$s, $d] = $peca('auto_infracao', ['artigos' => [$porFx->id, $umDez->id], 'area_terreno_m2' => 300]);
    $auto = Documento::find($d['documento']['id']);
    confere($s === 201 && $auto->valor_upf === null, 'rascunho de auto com multa por calcular grava (sem total)');
    try {
        app(LavraturaService::class)->lavrar($auto);
        confere(false, 'lavrar auto com multa por calcular — não recusou');
    } catch (RuntimeException $e) {
        confere(str_contains($e->getMessage(), 'a área construída') && str_contains($e->getMessage(), 'multiplicador'), 'Auto de Infração com multa por calcular NÃO lavra, e diz o que falta');
    }
    [$s] = chamar($admin, DocumentoController::class, 'update', ['tipo' => 'auto_infracao', 'data_fato' => now()->format('Y-m-d H:i'),
        'legislacao_id' => $lei->id, 'artigos' => [$porFx->id, $umDez->id], 'area_terreno_m2' => 300, 'area_construida_m2' => 98.5,
        'alvara_valor' => 1000, 'multiplicadores' => [$umDez->id => 2]], [$auto]);
    $auto->refresh();
    confere($s === 200 && $auto->valor_upf === 108.0 && $auto->alvara_valor === 1000.0, 'com área, alvará e multiplicador, o total fecha: 100 + 8 UPF');
    $lavrado = app(LavraturaService::class)->lavrar($auto);
    $copia = $lavrado->artigos()->where('artigo_id', $umDez->id)->first();
    confere($lavrado->status === 'lavrado' && $lavrado->valor_upf === 108.0 && $copia->multiplicador === 2.0 && $copia->valor_reais === 2000.0,
        'lavra, e a cópia do artigo guarda multiplicador e valor em reais');
    $copiaFx = $lavrado->artigos()->where('artigo_id', $porFx->id)->first();
    confere($copiaFx->area_usada === 'construida' && str_contains($copiaFx->memoria, 'Faixa acima de 60,00 até 120,00 m² (obra de 98,50 m²) = 100,00 UPF'), 'a memória fica congelada na peça');
    // A lei muda depois: a peça lavrada não muda.
    $porFx->update(['multa_faixas' => [['ate_m2' => 10, 'upf' => 1], ['ate_m2' => null, 'upf' => 2]]]);
    $dados = app(DocumentoImpressao::class)->montar($lavrado->fresh());
    $linha = collect($dados['memoria']['linhas'])->firstWhere('numero', 'Art. M3');
    confere($linha['valor'] === 100.0 && str_contains($linha['conta'], '98,50 m²') && $linha['base'] === 'Por faixa de área', 'a impressão usa a memória congelada, não a lei de hoje');

    [$s, $d] = $peca('notificacao', ['artigos' => [$porFx->id]]);
    $notif = app(LavraturaService::class)->lavrar(Documento::find($d['documento']['id']));
    confere($notif->status === 'lavrado' && $notif->valor_upf === null, 'Notificação lavra mesmo sem a área: ela não multa');

    echo "Reincidência na peça\n";
    // O auto lavrado acima (108 UPF) vira base de um auto de reincidência.
    $porFx->update(['multa_faixas' => $faixas, 'multa_dobra_reincidencia' => true]);
    [, $d] = chamar($admin, DocumentoController::class, 'autosAnteriores', [], []);
    confere($d['autos'] === [], 'sem imóvel nem CPF não há o que listar');
    $reqAnt = Illuminate\Http\Request::create('/x', 'GET', ['lote_id' => $lote->id]);
    Auth::guard('web')->setUser($admin);
    $ant = json_decode(app(DocumentoController::class)->autosAnteriores($reqAnt)->getContent(), true)['autos'];
    confere(collect($ant)->contains(fn ($a) => $a['id'] === $lavrado->id && $a['proximo_fator'] === 2), 'o auto lavrado aparece como base, com o fator 2');
    [$s, $d] = $peca('notificacao', ['artigos' => [$porFx->id], 'reincidencia_de_id' => $lavrado->id]);
    confere($s === 422, 'notificação não pode ser reincidência');
    [$s, $d] = $peca('auto_infracao', ['artigos' => [$porFx->id], 'reincidencia_de_id' => $notif->id, 'area_construida_m2' => 98.5]);
    confere($s === 422, 'reincidência tem de apontar para um Auto de Infração');
    [$s, $d] = $peca('auto_infracao', ['artigos' => [$porFx->id, $fixa->id], 'reincidencia_de_id' => $lavrado->id, 'area_construida_m2' => 98.5]);
    $re = Documento::find($d['documento']['id'] ?? 0);
    confere($s === 201 && $re->reincidencia_nivel === 1 && $re->valor_upf === 230.0, 'auto de reincidência: 100 × 2 (marcado) + 30 (não marcado) = 230 UPF');
    $copia = $re->artigos()->where('artigo_id', $porFx->id)->first();
    confere($copia->fator_reincidencia === 2 && str_contains($copia->memoria, '× 2 (reincidência) = 200,00 UPF'), 'a cópia guarda o fator e a memória da dobra');
    [, $sim] = chamar($admin, DocumentoController::class, 'simularMulta', ['artigos' => [$porFx->id, $fixa->id],
        'area_construida_m2' => 98.5, 'reincidencia_de_id' => $lavrado->id]);
    confere($sim['total_upf'] == 230.0 && $sim['fator_reincidencia'] === 2, 'a prévia mostra a mesma conta dobrada');
    $re2 = app(LavraturaService::class)->lavrar($re);
    [$s, $d] = $peca('auto_infracao', ['artigos' => [$porFx->id], 'reincidencia_de_id' => $re2->id, 'area_construida_m2' => 98.5]);
    confere($s === 201 && Documento::find($d['documento']['id'])->valor_upf === 400.0, 'reincidência da reincidência: 100 × 4');

    echo "Migração: a base antiga\n";
    confere(DB::table('artigos')->whereIn('base_multa', ['area_construida', 'area_terreno'])->count() === 0
        && DB::table('documento_artigos')->whereIn('base_multa', ['area_construida', 'area_terreno'])->count() === 0, 'não sobra artigo na base antiga');
    confere(DB::table('artigos')->where('base_multa', 'por_m2')->whereNull('multa_area')->count() === 0, 'todo "por m²" tem a área definida');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
