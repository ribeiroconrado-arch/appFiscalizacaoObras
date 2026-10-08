<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige a migração 2026_10_17_000100 aplicada e um
// administrador agente ativo.
//
//   php tests/ciencia-backend.php
//
// Cobre o texto de ciência (marcadores {prazo}, {lei oficial} e {origem}, e o
// **negrito** da impressão), o documento de ORIGEM da peça e a ordem natural
// dos artigos.

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
function chamar(User $u, string $classe, string $metodo, array $corpo = [], array $args = [], string $verbo = 'POST'): array {
    global $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create('/x', $verbo, $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
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
    $lei = Legislacao::create(['numero' => 'Lei Complementar nº 9', 'nome' => 'Lei de teste da ciência', 'ano' => 2099,
        'data_publicacao' => '2023-12-15', 'prazo_defesa_dias' => 5, 'prazo_cumprimento_dias' => 10, 'ativa' => true,
        'ciencia_notificacao' => 'Fica notificado a regularizar {prazo} , nos termos da {lei oficial}.',
        'ciencia_auto' => 'Em razão do descumprimento d{origem} , fica **AUTUADO** nos termos da {lei oficial}. <b>x</b>']);

    echo "Marcadores do texto de ciência\n";
    confere($lei->citacaoOficial() === 'Lei Complementar nº 9, de 15 de dezembro de 2023', 'a citação oficial traz a data por extenso');
    confere((new Legislacao(['numero' => 'Lei 5', 'data_publicacao' => '2024-03-01']))->citacaoOficial() === 'Lei 5, de 1º de março de 2024', 'dia 1 sai "1º"');
    confere((new Legislacao(['numero' => 'Lei 5']))->citacaoOficial() === 'Lei 5', 'sem data cadastrada, só o número');
    confere($lei->ciencia('notificacao', 5) === 'Fica notificado a regularizar no prazo de 5 dias, nos termos da Lei Complementar nº 9, de 15 de dezembro de 2023.',
        '{prazo} e {lei oficial} na notificação, sem espaço antes da vírgula');

    $novo = fn (string $n, array $docs) => Artigo::create(['legislacao_id' => $lei->id, 'numero' => $n, 'apelido' => $n,
        'conduta' => 'texto', 'base_multa' => 'sem_multa', 'ativo' => true, 'documentos' => $docs]);
    $art = $novo('Art. 9', Artigo::DOCUMENTOS);
    $peca = fn (string $tipo, array $extra = []) => chamar($admin, DocumentoController::class, 'store',
        ['tipo' => $tipo, 'legislacao_id' => $lei->id, 'artigos' => [$art->id], 'prazo_dias' => 5] + $extra, [$lote]);

    echo "Documento de origem\n";
    [, $d] = $peca('notificacao_embargo');
    $ne = app(LavraturaService::class)->lavrar(Documento::find($d['documento']['id']));
    confere(str_starts_with($ne->referencia(), 'a Notificação de Embargo nº '), 'a notificação é citada no feminino');
    [, $d] = chamar($admin, DocumentoController::class, 'origens', ['tipo' => 'auto_infracao', 'lote_id' => $lote->id], [], 'GET');
    confere(collect($d['origens'])->contains(fn ($o) => $o['id'] === $ne->id), 'a notificação lavrada aparece como origem possível do auto');
    [, $d] = chamar($admin, DocumentoController::class, 'origens', ['tipo' => 'notificacao', 'lote_id' => $lote->id], [], 'GET');
    confere($d['origens'] === [], 'notificação não tem origem');
    [$s, $d] = $peca('auto_infracao', ['origem_id' => $ne->id]);
    $auto = Documento::find($d['documento']['id'] ?? 0);
    confere($s === 201 && $auto->origem_id === $ne->id, 'o auto grava a origem');
    [$s] = $peca('notificacao', ['origem_id' => $ne->id]);
    confere($s === 422, 'notificação com origem é recusada');
    [$s] = $peca('auto_embargo', ['origem_id' => $auto->id]);
    confere($s === 422, 'origem em rascunho (ou de tipo que não antecede) é recusada');

    echo "Na impressão\n";
    $dados = app(DocumentoImpressao::class)->montar($auto->fresh());
    confere($dados['origemTexto'] === 'NOTIFICAÇÃO DE EMBARGO Nº ' . $ne->numeroFormatado(), 'o topo da peça traz a origem');
    confere(str_contains($dados['ciencia'], 'descumprimento da Notificação de Embargo nº ' . $ne->numeroFormatado() . ', fica <strong>AUTUADO</strong>'),
        '{origem} vira a referência, e **texto** vira negrito');
    confere(str_contains($dados['ciencia'], '&lt;b&gt;x&lt;/b&gt;') && ! str_contains($dados['ciencia'], '<b>x'), 'HTML digitado no texto sai escapado');
    [, $d] = $peca('auto_infracao');
    $direto = app(DocumentoImpressao::class)->montar(Documento::find($d['documento']['id']));
    confere(str_contains($direto['ciencia'], 'descumprimento d, fica') && $direto['origemTexto'] === 'DIRETA', 'auto sem origem: o marcador some');
    confere(DocumentoImpressao::negrito(null) === null && DocumentoImpressao::negrito("a\nb") === "a<br />\nb", 'texto vazio e quebra de linha');

    echo "Ordem dos artigos\n";
    foreach (['Art. 121-A, II', 'Art. 4º', 'Art. 34, §1º', 'Art. 121-A, I', 'Art. 34, caput', 'Art. 120, parágrafo único', 'Art. 13', 'Art. 22, §5º', 'Art. 121-B', 'Art. 22, §1º', 'Art. 121'] as $n) {
        $novo($n, ['notificacao']);
    }
    [, $d] = chamar($admin, LegislacaoController::class, 'index', [], [], 'GET');
    $ordem = collect(collect($d['leis'])->firstWhere('id', $lei->id)['artigos'])->pluck('numero')->all();
    confere($ordem === ['Art. 4º', 'Art. 9', 'Art. 13', 'Art. 22, §1º', 'Art. 22, §5º', 'Art. 34, caput', 'Art. 34, §1º',
        'Art. 120, parágrafo único', 'Art. 121', 'Art. 121-A, I', 'Art. 121-A, II', 'Art. 121-B'], 'pelo número do artigo, depois parágrafo, depois inciso');
    confere(collect($d['leis'])->firstWhere('id', $lei->id)['citacao'] === 'Lei Complementar nº 9, de 15 de dezembro de 2023', 'Parâmetros recebe a citação pronta');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
