<?php
// Diagnóstico local explícito, no molde de importacao-backend.php: tudo roda
// dentro de uma transação que é DESFEITA no fim. Exige a migração
// 2026_10_04_000100 aplicada e pelo menos um usuário administrador.
//
//   php tests/inscricao-backend.php
//
// Cobre: inscrição montada e GRAVADA no lote; a gravada segura a ficha quando
// a amarração do bairro cai; o reparo pela trilha de auditoria; a trava do
// "nome no desenho" com lote; o apelido como rótulo do mapa; a busca pela
// inscrição gravada.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\BairrosDoDesenho;
use App\Cadastro\InscricoesGravadas;
use App\Models\CadastroBairro;
use App\Models\Lote;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}
/**
 * GET pela pilha HTTP inteira; POST direto no controlador — o POST pelo kernel
 * esbarra no token CSRF, que não existe sem sessão de navegador. O único POST
 * daqui é o de Parâmetros › Bairros.
 */
function pedir(User $u, string $metodo, string $url, array $corpo = []): Symfony\Component\HttpFoundation\Response {
    global $kernel, $app;
    Auth::guard('web')->setUser($u);
    $req = Illuminate\Http\Request::create($url, $metodo, $corpo, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    if ($metodo === 'GET') {
        return $kernel->handle($req);
    }
    try {
        return (new App\Http\Controllers\ParametroController())->salvarBairro($req);
    } catch (Illuminate\Validation\ValidationException $e) {
        return response()->json(['errors' => $e->errors()], 422);
    }
}

$admin = User::where('perfil', 'admin')->where('tipo_usuario', 'agente')->where('ativo', true)->first();
if (! $admin) { throw new RuntimeException('Sem administrador ativo para o teste local.'); }

DB::beginTransaction();
try {
    $nome = 'Bairro Teste Inscrição';
    $cb = CadastroBairro::create(['codigo' => '987', 'nome_cadastro' => 'BAIRRO TESTE INSCRICAO', 'nome_gis' => $nome]);
    $geom = "ST_GeomFromText('POLYGON((-15.56 -54.30, -15.56 -54.2999, -15.5599 -54.2999, -15.5599 -54.30, -15.56 -54.30))', 4326)";
    $novoLote = function (string $q, string $l) use ($nome, $geom) {
        DB::insert("INSERT INTO lotes (bairro, quadra, numero_lote, desmembramento, chave, situacao, origem, geom, area_gis_m2, created_at, updated_at)
                    VALUES (?, ?, ?, 0, ?, 'ativo', 'importacao', {$geom}, 100, NOW(), NOW())",
            [$nome, $q, $l, 'teste-insc-' . $q . '-' . $l . '-' . uniqid()]);
        return (int) DB::getPdo()->lastInsertId();
    };
    $a = $novoLote('3', '12');
    $b = $novoLote('3', '13');

    echo "Gravação da inscrição montada\n";
    $g = new InscricoesGravadas();
    confere($g->gravar([$nome]) === 2, 'os dois lotes do bairro tiveram a inscrição gravada');
    confere(DB::table('lotes')->where('id', $a)->value('inscricao_montada') === '019870030012000', 'gravada com 15 dígitos: 01.987.003.0012.000');
    confere($g->gravar([$nome]) === 0, 'gravar de novo sem mudança não regrava nada');

    echo "Quando a amarração cai\n";
    DB::table('cadastro_bairros')->where('id', $cb->id)->update(['nome_gis' => 'TESTE CURTO']);
    $lote = Lote::find($a);
    confere($lote->inscricao() === '019870030012000', 'Lote::inscricao devolve a gravada, mesmo sem o código do bairro');
    $linha = DB::table('lotes')->where('id', $a)->first();
    confere((new BairrosDoDesenho())->inscricaoDe($linha) === '01.987.003.0012.000', 'a ficha do mapa mostra a gravada');
    confere($g->gravar([$nome]) === 0, 'sem amarração, a gravada não é apagada');

    echo "Reparo pela trilha de auditoria\n";
    DB::table('auditoria')->insert([
        'acao' => 'alterou', 'tabela' => 'cadastro_bairros', 'registro_id' => $cb->id, 'usuario_nome' => 'teste',
        'descricao' => 'teste', 'dados_anteriores' => json_encode(['nome_gis' => $nome]),
        'dados_novos' => json_encode(['nome_gis' => 'TESTE CURTO']), 'created_at' => now(),
    ]);
    $feitos = (new InscricoesGravadas())->repararAmarracoes();
    $cb->refresh();
    confere($cb->nome_gis === $nome && $cb->apelido === 'TESTE CURTO', 'religado ao nome dos lotes; o nome curto virou apelido');
    confere(count(array_filter($feitos, fn ($f) => str_contains($f, '987'))) === 1, 'o reparo diz o que religou');
    $deNovo = (new InscricoesGravadas())->repararAmarracoes();
    confere(! array_filter($deNovo, fn ($f) => str_contains($f, '987')), 'rodar o reparo de novo não mexe no que já está ligado');

    echo "Curadoria renumera: a gravada acompanha\n";
    DB::table('lotes')->where('id', $b)->update(['numero_lote' => '99']);
    $linhas = DB::table('lotes')->whereIn('id', [$a, $b])->get();
    $mud = (new InscricoesGravadas())->mudancas($linhas);
    confere(array_keys($mud) === [$b] && $mud[$b] === '019870030099000', 'só o lote renumerado muda');
    (new InscricoesGravadas())->persistir($mud);
    confere(DB::table('lotes')->where('id', $b)->value('inscricao_montada') === '019870030099000', 'a gravada passa a ser a nova');

    echo "Parâmetros › Bairros\n";
    $r = pedir($admin, 'POST', '/api/parametros/bairros', [
        'id' => $cb->id, 'codigo' => '987', 'nome_cadastro' => 'BAIRRO TESTE INSCRICAO', 'nome_gis' => 'OUTRO NOME', 'apelido' => 'TESTE CURTO',
    ]);
    confere($r->getStatusCode() === 422 && str_contains($r->getContent(), 'apelido'), 'nome no desenho com lote não muda (aponta o apelido)');
    confere(CadastroBairro::find($cb->id)->nome_gis === $nome, 'e o nome continua o mesmo');
    $r = pedir($admin, 'POST', '/api/parametros/bairros', [
        'id' => $cb->id, 'codigo' => '987', 'nome_cadastro' => 'BAIRRO TESTE INSCRICAO', 'nome_gis' => $nome, 'apelido' => 'APELIDO NOVO',
    ]);
    confere($r->getStatusCode() === 200 && CadastroBairro::find($cb->id)->apelido === 'APELIDO NOVO', 'o apelido muda à vontade');
    confere((new BairrosDoDesenho())->apelido($nome) === 'APELIDO NOVO', 'o mapa rotula pelo apelido');
    confere((new BairrosDoDesenho())->oficial($nome) === 'BAIRRO TESTE INSCRICAO', 'documentos continuam com o nome oficial');

    echo "Atos do bairro\n";
    $base = ['id' => $cb->id, 'codigo' => '987', 'nome_cadastro' => 'BAIRRO TESTE INSCRICAO', 'nome_gis' => $nome, 'apelido' => 'APELIDO NOVO'];
    $atos = fn () => App\Models\BairroAto::where('cadastro_bairro_id', $cb->id)->orderByRaw('data IS NULL')->orderBy('data')->get();
    // Fora de ordem de propósito: a lista grava na ordem que vier e lê pela data.
    $r = pedir($admin, 'POST', '/api/parametros/bairros', $base + ['atos' => [
        ['tipo' => 'ampliacao', 'ato' => 'Decreto 655/2001', 'data' => '2001-07-20', 'area_m2' => 543490, 'observacao' => 'Ampliação II'],
        ['tipo' => 'aprovacao', 'ato' => '  Decreto 74/1988 ', 'data' => '1988-12-16', 'area_m2' => 448290],
        ['tipo' => 'aprovacao', 'ato' => 'Decreto 2325/2023', 'data' => '2023-07-17', 'observacao' => 'Aprovado de novo'],
    ]]);
    $g = CadastroBairro::find($cb->id);
    confere($r->getStatusCode() === 200 && $atos()->count() === 3, 'grava a lista de atos');
    confere($g->ato_aprovacao === 'Decreto 74/1988' && $g->data_aprovacao?->format('Y-m-d') === '1988-12-16',
        '"Aprovado por" é a aprovação mais antiga, e não a primeira da lista');
    $lista = json_decode(pedir($admin, 'GET', '/api/parametros')->getContent(), true);
    $linha = collect($lista['bairros'] ?? [])->firstWhere('id', $cb->id);
    confere(array_column($linha['atos'] ?? [], 'ato') === ['Decreto 74/1988', 'Decreto 655/2001', 'Decreto 2325/2023']
        && ($linha['atos'][1]['area_m2'] ?? null) == 543490 && count($lista['tipos_ato_bairro'] ?? []) === 7,
        'Parâmetros devolve os atos em ordem de data, com a área, e os tipos');
    $balao = (new BairrosDoDesenho())->aprovacao($nome);
    confere($balao['ato'] === 'Decreto 74/1988' && array_column($balao['outros'], 'ato') === ['Decreto 655/2001', 'Decreto 2325/2023']
        && $balao['outros'][0]['data'] === '20/07/2001', 'o balão recebe os outros atos, sem repetir a aprovação mostrada');

    // Editar um, tirar outro: o que fica guarda o id (e a trilha de auditoria).
    [$a74, $a655] = [$atos()[0], $atos()[1]];
    $r = pedir($admin, 'POST', '/api/parametros/bairros', $base + ['atos' => [
        ['id' => $a74->id, 'tipo' => 'aprovacao', 'ato' => 'Decreto 74/1988', 'data' => '1988-12-16', 'area_m2' => 448290],
        ['id' => $a655->id, 'tipo' => 'ampliacao', 'ato' => 'Decreto 655/2001', 'data' => '2001-07-20', 'area_m2' => 543490.5],
    ]]);
    confere($r->getStatusCode() === 200 && $atos()->pluck('id')->all() === [$a74->id, $a655->id] && $atos()[1]->area_m2 === 543490.5,
        'editar mantém o id; o que saiu da lista é tirado');
    $r = pedir($admin, 'POST', '/api/parametros/bairros', $base);
    confere($r->getStatusCode() === 200 && $atos()->count() === 2, 'sem a lista no pedido, os atos não mudam');
    $r = pedir($admin, 'POST', '/api/parametros/bairros', $base + ['atos' => [['tipo' => 'ampliacao', 'ato' => 'Decreto 1/2099', 'data' => now()->addDay()->format('Y-m-d')]]]);
    confere($r->getStatusCode() === 422 && str_contains(json_encode(json_decode($r->getContent(), true), JSON_UNESCAPED_UNICODE), 'não pode ser no futuro')
        && $atos()->count() === 2, 'data futura é recusada, e nada muda');
    $r = pedir($admin, 'POST', '/api/parametros/bairros', $base + ['atos' => [['tipo' => 'ampliacao', 'ato' => '']]]);
    confere($r->getStatusCode() === 422, 'ato sem lei ou decreto é recusado');
    $r = pedir($admin, 'POST', '/api/parametros/bairros', $base + ['atos' => [['tipo' => 'ampliacao', 'ato' => 'Decreto 522/1998', 'data' => '1998-06-10']]]);
    $g = CadastroBairro::find($cb->id);
    confere($r->getStatusCode() === 200 && $g->ato_aprovacao === null && $g->data_aprovacao === null,
        'sem nenhuma aprovação na lista, "Aprovado por" fica vazio (não se chuta)');
    $r = pedir($admin, 'POST', '/api/parametros/bairros', $base + ['atos' => []]);
    confere($r->getStatusCode() === 200 && $atos()->count() === 0, 'lista vazia tira todos');

    echo "Busca pela inscrição gravada\n";
    DB::table('cadastro_bairros')->where('id', $cb->id)->update(['nome_gis' => null]);   // amarração caída
    $r = json_decode(pedir($admin, 'GET', '/api/imoveis/busca?inscricao=01.987.003.0012.000')->getContent(), true);
    confere(collect($r['imoveis'] ?? [])->pluck('id')->contains($a), 'busca unitária acha pela gravada, sem amarração');
    $r = json_decode(pedir($admin, 'GET', '/api/imoveis/busca?inscricao_de=01.987.003&inscricao_ate=01.987.003')->getContent(), true);
    confere(collect($r['imoveis'] ?? [])->pluck('id')->intersect([$a, $b])->count() === 2, 'intervalo da quadra acha pelas gravadas');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
