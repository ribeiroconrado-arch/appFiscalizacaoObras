<?php
// Diagnóstico local, em transação DESFEITA no fim: o tipo do logradouro que o
// cadastro municipal manda sem ele, completado pela lista de Ruas e bairros
// (App\Cadastro\TipoDoLogradouro), e a ligação rua↔bairro só com bairro da
// aba Bairros.
//
//   php tests/tipo-logradouro-backend.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\TipoDoLogradouro as T;
use App\Http\Controllers\ParametroController;
use App\Models\CadastroBairro;
use App\Models\Logradouro;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $c, string $o): void { global $ok; if (! $c) { throw new RuntimeException('FALHOU: ' . $o); } $ok++; echo "  ok  {$o}\n"; }

$pedido = function (User $u, array $corpo) use ($app): Request {
    Auth::guard('web')->setUser($u);
    $req = Request::create('/x', 'POST', $corpo);
    $req->setUserResolver(fn () => $u);
    $app->instance('request', $req);
    return $req;
};

DB::beginTransaction();
T::esquecer();
try {
    CadastroBairro::create(['codigo' => '9901', 'nome_cadastro' => 'JARDIM TESTE ALFA', 'nome_gis' => 'Teste Alfa']);
    CadastroBairro::create(['codigo' => '9902', 'nome_cadastro' => 'JARDIM TESTE BETA']);
    $rua = fn (string $nome, array $bairros) => tap(Logradouro::create(['nome' => $nome]),
        fn ($l) => array_map(fn ($b) => $l->vinculos()->create(['bairro' => $b]), $bairros));
    $rua('AVENIDA ZZTESTE CUIABÁ', ['JARDIM TESTE ALFA']);
    $rua('RUA ZZTESTE GOIÁS', []);
    $rua('RUA ZZTESTE DUPLA', ['JARDIM TESTE ALFA']);
    $rua('AVENIDA ZZTESTE DUPLA', ['JARDIM TESTE BETA']);
    T::esquecer();

    echo "Completar o tipo\n";
    confere(T::completar('ZZTESTE CUIABÁ') === 'AV. ZZTESTE CUIABÁ', 'sem tipo: acha a avenida pelo nome');
    confere(T::completar('zzteste  cuiaba') === 'AV. ZZTESTE CUIABÁ', 'sem acento, minúscula e espaço sobrando também casam');
    confere(T::completar('RUA ZZTESTE CUIABÁ') === 'R. ZZTESTE CUIABÁ', 'já com tipo: fica o dele, abreviado');
    confere(T::completar('AV ZZTESTE GOIÁS') === 'AV. ZZTESTE GOIÁS', '"AV" sem ponto conta como tipo');
    confere(T::completar('ZZTESTE DUPLA') === 'ZZTESTE DUPLA', 'duas ruas e sem bairro: não chuta');
    confere(T::completar('ZZTESTE DUPLA', 'JARDIM TESTE BETA') === 'AV. ZZTESTE DUPLA', 'o bairro desempata');
    confere(T::completar('ZZTESTE DUPLA', 'Teste Alfa') === 'R. ZZTESTE DUPLA', 'o nome do desenho também desempata');
    confere(T::completar('ZZTESTE INEXISTENTE') === 'ZZTESTE INEXISTENTE', 'sem rua na lista: fica como veio');
    confere(T::completar(null) === null && T::completar('') === '', 'vazio continua vazio');
    confere(T::semTipo('AV. ZZTESTE CUIABÁ') === 'ZZTESTE CUIABÁ', 'semTipo tira o tipo para a busca');

    echo "Rua repetida e desempate pelo código\n";
    $rua('AV ZZTESTE CARNAUBA', ['JARDIM TESTE ALFA']);
    $rua('AV. ZZTESTE CARNAÚBA', []);
    T::esquecer();
    confere(T::completar('ZZTESTE CARNAUBA') === 'AV. ZZTESTE CARNAÚBA', 'a mesma rua cadastrada duas vezes conta como uma (e fica a grafia acentuada)');
    confere(T::completar('ZZTESTE DUPLA', 'JARDIM EUROPA I (grafia do cadastro)', '9902') === 'AV. ZZTESTE DUPLA', 'o código do bairro desempata, mesmo com o nome diferente');
    confere(T::completar('ZZTESTE DUPLA', 'JARDIM TESTE BETA', '009901') === 'R. ZZTESTE DUPLA', 'o código vale antes do nome, com zeros à esquerda');
    confere(T::completar('ZZTESTE DUPLA', null, '99999') === 'ZZTESTE DUPLA', 'código desconhecido e sem nome: não chuta');

    echo "Pendências\n";
    $p = T::pendencias([
        (object) ['logradouro' => 'ZZTESTE CUIABÁ', 'bairro' => 'JARDIM TESTE ALFA', 'n' => 4],
        (object) ['logradouro' => 'ZZTESTE INEXISTENTE', 'bairro' => 'JARDIM TESTE ALFA', 'n' => 2],
        (object) ['logradouro' => 'ZZTESTE INEXISTENTE', 'bairro' => 'JARDIM TESTE BETA', 'n' => 1],
        (object) ['logradouro' => 'ZZTESTE DUPLA', 'bairro' => null, 'n' => 5],
        (object) ['logradouro' => 'RUA ZZTESTE QUALQUER', 'bairro' => null, 'n' => 9],
    ]);
    $nomes = array_column($p, 'nome');
    confere($nomes === ['ZZTESTE DUPLA', 'ZZTESTE INEXISTENTE'], 'só o que não completa, do mais frequente ao menos');
    confere($p[1]['imoveis'] === 3 && $p[1]['bairros'] === ['JARDIM TESTE ALFA', 'JARDIM TESTE BETA'], 'soma os imóveis e junta os bairros');
    confere(count($p[0]['candidatos']) === 2, 'a ambígua mostra as duas ruas');

    echo "Ligação rua↔bairro\n";
    $admin = User::where('perfil', 'admin')->first() ?? User::create(['name' => 'Admin Teste Rua', 'email' => 'adm.rua@exemplo.test',
        'password' => 'x-teste-x-12345', 'perfil' => 'admin', 'tipo_usuario' => 'agente', 'ativo' => true]);
    $ctl = $app->make(ParametroController::class);
    $r = $ctl->salvarRua($pedido($admin, ['nome' => 'Rua Zzteste Nova', 'bairros' => ['JARDIM TESTE FORA']]));
    confere($r->status() === 422 && str_contains($r->getData()->message, 'JARDIM TESTE FORA'), 'bairro fora da aba Bairros é recusado');
    $r = $ctl->salvarRua($pedido($admin, ['nome' => 'Rua Zzteste Nova', 'bairros' => ['jardim teste beta']]));
    confere($r->status() === 200, 'bairro da aba Bairros é aceito (sem importar a caixa)');
    $nova = Logradouro::where('nome', 'RUA ZZTESTE NOVA')->first();
    confere($nova?->bairros() === ['JARDIM TESTE BETA'], 'a ligação foi gravada');
    confere(T::completar('ZZTESTE NOVA') === 'R. ZZTESTE NOVA', 'gravar a rua refaz o índice na hora');
    $nova->vinculos()->create(['bairro' => 'BAIRRO ANTIGO DO POSTURAS']);
    $r = $ctl->salvarRua($pedido($admin, ['id' => $nova->id, 'nome' => 'RUA ZZTESTE NOVA', 'bairros' => ['BAIRRO ANTIGO DO POSTURAS', 'JARDIM TESTE BETA']]));
    confere($r->status() === 200, 'a ligação antiga, de fora da lista, continua aceita ao regravar');
    confere(! Illuminate\Support\Facades\Schema::hasTable('bairros_endereco'), 'a lista de bairros herdada do Posturas não existe mais');

    echo "Sugestão de bairro pela rua\n";
    $end = $app->make(App\Http\Controllers\EnderecoController::class);
    $sug = fn (array $q) => $end->bairros(Request::create('/x', 'GET', $q))->getData(true);
    $d = $sug(['rua' => 'Avenida Zzteste Cuiabá']);
    confere($d['da_rua'] && array_column($d['bairros'], 'nome') === ['JARDIM TESTE ALFA'], 'rua com bairro ligado sugere só ele');
    $d = $sug(['rua' => 'RUA ZZTESTE GOIÁS']);
    $nomes = array_column($d['bairros'], 'nome');
    confere(! $d['da_rua'] && in_array('JARDIM TESTE ALFA', $nomes, true) && in_array('JARDIM TESTE BETA', $nomes, true), 'rua sem ligação sugere a aba Bairros');
    confere(! in_array('BAIRRO ANTIGO DO POSTURAS', $nomes, true), 'nome de fora da aba Bairros não é sugerido');

    echo "Denúncia\n";
    $den = $app->make(App\Http\Controllers\DenunciaController::class);
    $repo = $app->make(App\Repositories\LoteRepository::class);
    $denuncia = fn (string $rua, string $bairro) => $den->store($pedido($admin, ['data_hora' => now()->format('Y-m-d\TH:i'),
        'logradouro' => $rua, 'bairro' => $bairro, 'fonte_sonora' => 'Residência']), $repo);
    $r = $denuncia('Avenida Zzteste Cuiabá', 'Bairro Inventado Zz');
    confere($r->status() === 422, 'bairro fora da aba Bairros é recusado');
    $r = $denuncia('Avenida Zzteste Cuiabá', 'jardim teste beta');
    confere($r->status() === 201, 'bairro da lista que a rua não tem ligado é aceito (a tela só avisa)');
    $r = $denuncia('zzteste rua digitada', 'JARDIM TESTE ALFA');
    confere($r->status() === 201 && Logradouro::where('nome', 'ZZTESTE RUA DIGITADA')->exists(), 'rua nova entra na lista de ruas');
    $ruas = collect($ctl->index($pedido($admin, []))->getData(true)['ruas']);
    $digitada = $ruas->firstWhere('nome', 'ZZTESTE RUA DIGITADA');
    confere($digitada['sem_tipo'] === true && $digitada['bairros'] === [], 'e aparece sem tipo e sem bairro, para as pendências');
    confere($ruas->firstWhere('nome', 'AVENIDA ZZTESTE CUIABÁ')['sem_tipo'] === false, 'rua com tipo não é pendência');

    echo "\n{$ok} conferências ok\n";
} finally {
    DB::rollBack();
    T::esquecer();
}
