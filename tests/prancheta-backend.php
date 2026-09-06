<?php
// Diagnóstico local explícito: gravação inteiramente revertida na transação.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::where('curador_cadastral', true)->get()->first(fn ($u) => $u->podeCurarCadastro());
$id = App\Models\Lote::ativos()->value('id');
if (!$user || !$id) { throw new RuntimeException('Sem usuário curador ou lote ativo para o teste local.'); }
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $controller = new App\Http\Controllers\PranchetaController;
    $body = ['tipo'=>'desmembramento', 'ids'=>[$id], 'protocolo_id'=>null, 'estado'=>['versao'=>1, 'linhas'=>[], 'dados'=>[], 'teste_transacional'=>true]];
    $request = Illuminate\Http\Request::create('/api/pranchetas/salvar', 'POST', $body);
    $request->setUserResolver(fn () => $user);
    $response = $controller->salvar($request);
    if ($response->getStatusCode() !== 200) throw new RuntimeException('Falha ao salvar.');
    $loaded = $controller->carregar($request)->getData(true);
    if (($loaded['estado']['teste_transacional'] ?? null) !== true) throw new RuntimeException('Falha ao recuperar.');
    $inscricao = '01.105.035.0001.000';
    foreach ([0=>'0001', 1=>'0001A', 2=>'0001B', 26=>'0001Z', 27=>'0001AA', 28=>'0001AB'] as $sufixo=>$apelido) {
        if (App\Support\InscricaoImobiliaria::apelidoDesmembrado($inscricao, $sufixo) !== $apelido) throw new RuntimeException('Apelido incorreto.');
    }
    foreach ([-1,1000] as $invalido) {
        try { App\Support\InscricaoImobiliaria::apelidoDesmembrado($inscricao, $invalido); throw new RuntimeException('Sufixo inválido aceito.'); }
        catch (InvalidArgumentException $e) {}
    }
    foreach ([0=>'0001A', 1=>'0001B', 2=>'0001C', 25=>'0001Z', 26=>'0001AA'] as $sufixo=>$apelido) {
        if (App\Support\InscricaoImobiliaria::apelidoDesmembrado($inscricao, $sufixo, true) !== $apelido) throw new RuntimeException('Sequência iniciada em zero incorreta.');
    }
    foreach (['importacao'=>'ORIGINAL','desmembramento'=>'DESMEMBRADO','unificacao'=>'UNIFICADO'] as $origem=>$tag) {
        if (App\Models\Lote::tagOrigem($origem) !== $tag) throw new RuntimeException('Tag incorreta.');
    }
    $pai = App\Models\Lote::findOrFail($id);
    $pai->inscricao_imobiliaria = '011050350001000';
    $svc = app(App\Services\DesmembramentoDeLote::class);
    foreach ([-1,1000] as $invalido) {
        $erro = $svc->impedimento(null,$pai,[['desmembramento'=>$invalido],['desmembramento'=>1]],false,true);
        if (!str_contains($erro ?? '', '000 a 999')) throw new RuntimeException('Servidor não recusou sufixo inválido.');
    }
    $erro = $svc->impedimento(null,$pai,[['desmembramento'=>1],['desmembramento'=>1]],false,true);
    if (!str_contains($erro ?? '', 'já foi utilizado')) throw new RuntimeException('Servidor não recusou duplicidade.');
    // Mesma inscrição .000: permitido no histórico, nunca em dois ativos.
    $prefixoTeste = '991050350001000';
    Illuminate\Support\Facades\DB::table('lotes')->where('id',$id)->update(['inscricao_imobiliaria'=>$prefixoTeste]);
    $pai->inscricao_imobiliaria = $prefixoTeste;
    $outro = App\Models\Lote::where('id','<>',$id)->value('id');
    if (!$outro) throw new RuntimeException('Teste requer dois lotes.');
    Illuminate\Support\Facades\DB::table('lotes')->where('id',$outro)->update(['inscricao_imobiliaria'=>$prefixoTeste,'situacao'=>'inativo']);
    if (in_array(0,$svc->identidade($pai)['usados'],true)) throw new RuntimeException('Histórico ou pai bloqueou .000.');
    $erro = $svc->impedimento(null,$pai,[['desmembramento'=>0],['desmembramento'=>0]],false,true);
    if (!str_contains($erro ?? '', 'já foi utilizado')) throw new RuntimeException('Duas partes .000 aceitas.');
    Illuminate\Support\Facades\DB::table('lotes')->where('id',$outro)->update(['situacao'=>'ativo']);
    if (!in_array(0,$svc->identidade($pai)['usados'],true)) throw new RuntimeException('Outro ativo não bloqueou .000.');
    Illuminate\Support\Facades\DB::table('lotes')->where('id',$id)->update(['inscricao_imobiliaria'=>'010900180026000']);
    Illuminate\Support\Facades\DB::table('lotes')->where('id',$outro)->update(['inscricao_imobiliaria'=>'010900180029000']);
    $unificar = app(App\Services\UnificacaoDeLotes::class);
    $identidadeUniao = new ReflectionMethod($unificar,'identidade');
    $identidade = $identidadeUniao->invoke($unificar,[$outro,$id]);
    if (($identidade['numero'] ?? null) !== '2629' || ($identidade['inscricao'] ?? null) !== '010900182629000') throw new RuntimeException('Identidade da união incorreta.');
    Illuminate\Support\Facades\DB::table('lotes')->where('id',$outro)->update(['inscricao_imobiliaria'=>'010900190029000']);
    if (!isset($identidadeUniao->invoke($unificar,[$id,$outro])['erro'])) throw new RuntimeException('União de inscrições de quadras diferentes aceita.');
    echo "PASS: salvar e recuperar no banco local; transação revertida.\n";
} finally { Illuminate\Support\Facades\DB::rollBack(); }
