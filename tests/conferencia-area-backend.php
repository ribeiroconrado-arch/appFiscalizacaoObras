<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige um bairro com lotes ativos já ligado a um código do
// cadastro (Parâmetros → Bairros).
//
//   php tests/conferencia-area-backend.php
//
// Cobre a divergência de ÁREA na conferência com o cadastro: a inscrição casa,
// mas a área do desenho tem de ser EXATAMENTE a "Área Terreno" do cadastro (ao
// centésimo de m²). A diferença não impede nada — entra na lista de
// divergências, e some quando o desenho é corrigido.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\BairrosDoDesenho;
use App\Cadastro\FonteDoCadastro;
use App\Cadastro\RetratoBci;
use App\Models\Lote;
use App\Services\ConferenciaComCadastro;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}

$bairros = new BairrosDoDesenho();
$campos = ['id', 'bairro', 'quadra', 'numero_lote', 'desmembramento', 'inscricao_imobiliaria', 'inscricao_montada', 'importacao_id', 'area_gis_m2'];

// Um bairro ligado ao cadastro, com pelo menos quatro lotes de inscrição montável e área.
$bairro = null; $lotes = collect();
foreach (array_keys($bairros->codigos()) as $chave) {
    $nome = DB::table('lotes')->where('situacao', 'ativo')->distinct()->pluck('bairro')
        ->first(fn ($b) => BairrosDoDesenho::chave((string) $b) === $chave);
    if (! $nome) { continue; }
    $lotes = DB::table('lotes')->where('situacao', 'ativo')->where('bairro', $nome)->whereNotNull('area_gis_m2')->limit(400)->get($campos)
        ->map(fn ($l) => [$l, InscricaoImobiliaria::normalizar($bairros->inscricaoDe($l))])
        ->filter(fn ($p) => $p[1] !== null)->unique(fn ($p) => $p[1])->values();
    if ($lotes->count() >= 4) { $bairro = $nome; break; }
}
if (! $bairro) { throw new RuntimeException('Sem bairro ligado ao cadastro, com lotes, para o teste local.'); }

/** Um cadastro de mentira: devolve as linhas que o teste montar. */
$fonte = new class implements FonteDoCadastro {
    public array $linhas = [];
    public function consultar(Lote $l): ?RetratoBci { return null; }
    public function porQueVazio(Lote $l): string { return ''; }
    public function nome(): string { return 'exportacao'; }
    public function imoveisDoBairro(string $c): iterable { return $this->linhas; }
    public function proprietarios(Lote $l): array { return []; }
    public function situacao(Lote $l): array { return ['inscricoes' => [], 'carga_id' => null, 'em' => null, 'alterado_em' => null, 'ausente_desde' => null]; }
};
$linha = fn (object $l, string $insc, ?float $area) => ['inscricao' => $insc, 'quadra' => $l->quadra, 'lote' => $l->numero_lote,
    'isencao' => 'Normal', 'area_terreno_m2' => $area, 'endereco' => null];

DB::beginTransaction();
try {
    [$igual, $inscIgual]       = $lotes[0];   // área idêntica à do cadastro
    [$centavo, $inscCentavo]   = $lotes[1];   // um centésimo de diferença
    [$maior, $inscMaior]       = $lotes[2];   // cadastro bem maior
    [$semArea, $inscSemArea]   = $lotes[3];   // cadastro não informa a área

    // Área do desenho com casas além do centésimo: o que conta é o valor arredondado.
    DB::table('lotes')->where('id', $igual->id)->update(['area_gis_m2' => 360.004]);
    DB::table('lotes')->where('id', $centavo->id)->update(['area_gis_m2' => 250.00]);
    DB::table('lotes')->where('id', $maior->id)->update(['area_gis_m2' => 300.50]);
    $fonte->linhas = [
        $linha($igual, $inscIgual, 360.00),
        $linha($centavo, $inscCentavo, 250.01),
        $linha($maior, $inscMaior, 312.00),
        $linha($semArea, $inscSemArea, null),
    ];

    echo "Conferir: a área tem de ser exata\n";
    $svc = app(ConferenciaComCadastro::class);
    $r = $svc->conferirBairro($bairro, $fonte, 'Cadastro de teste');
    $dif = collect($r['area_diferente'])->keyBy('lote_id');
    confere($r['casaram'] === 4, 'os quatro lotes casam pela inscrição');
    confere(! $dif->has($igual->id), 'área igual ao centésimo (360,004 e 360,00) NÃO é divergência');
    confere($dif->has($centavo->id) && $dif[$centavo->id]['diferenca'] === -0.01
        && $dif[$centavo->id]['area_desenho'] === 250.0 && $dif[$centavo->id]['area_cadastro'] === 250.01,
        'um centésimo de m² já é divergência, com as duas áreas e a diferença');
    confere($dif->has($maior->id) && $dif[$maior->id]['diferenca'] === -11.5, 'diferença grande também, com o valor certo');
    confere(! $dif->has($semArea->id) && $r['sem_area_no_cadastro'] === 1, 'cadastro sem a área do terreno: não compara, e conta à parte');
    $outros = count($r['nao_encontrados']) + count($r['inativos']) + count($r['sem_lote']) + count($r['sem_inscricao']);
    confere($r['total_divergencias'] === $outros + 2, 'as duas diferenças de área entram no total de divergências');

    echo "Corrigir o desenho resolve, sem precisar da planilha\n";
    DB::table('lotes')->where('id', $centavo->id)->update(['area_gis_m2' => 250.01]);
    DB::table('lotes')->where('id', $maior->id)->update(['area_gis_m2' => 305.00]);
    $rv = $svc->revisarBairro($bairro);
    $dif = collect($rv['area_diferente'])->keyBy('lote_id');
    confere(! $dif->has($centavo->id), 'lote acertado para a área do cadastro sai da lista');
    confere($dif->has($maior->id) && $dif[$maior->id]['area_desenho'] === 305.0 && $dif[$maior->id]['diferenca'] === -7.0,
        'lote ainda diferente continua, com a área de agora');
    confere($rv['total_divergencias'] === $r['total_divergencias'] - 1, 'e o total cai em um');

    echo "Variação de área aceitável\n";
    // O lote "maior" está 7,00 m² abaixo do cadastro (305,00 contra 312,00).
    $temMaior = fn (array $res) => collect($res['area_diferente'])->contains('lote_id', $maior->id);
    $r5 = $svc->comTolerancia(5.0, 'm2')->conferirBairro($bairro, $fonte, 'Cadastro de teste');
    confere($temMaior($r5) && $r5['tolerancia_area'] === ['valor' => 5.0, 'unidade' => 'm2'],
        'com 5 m² de variação, 7 m² de diferença ainda é divergência — e a variação fica guardada');
    confere(! $temMaior($svc->comTolerancia(8.0, 'm2')->conferirBairro($bairro, $fonte, 'Cadastro de teste')), 'com 8 m², deixa de ser');
    confere($temMaior($svc->comTolerancia(2.0, 'pct')->conferirBairro($bairro, $fonte, 'Cadastro de teste')),
        'em %: 2% de 312 m² são 6,24 m², e 7 m² passa disso');
    confere(! $temMaior($svc->comTolerancia(3.0, 'pct')->conferirBairro($bairro, $fonte, 'Cadastro de teste')), 'com 3% (9,36 m²), cabe');
    $r0 = $svc->comTolerancia(0.0, 'm2')->conferirBairro($bairro, $fonte, 'Cadastro de teste');
    confere($temMaior($r0) && $r0['tolerancia_area']['valor'] === 0.0, 'variação zero volta a exigir a área exata');

    echo "A área que vale para o lote é a do cadastro\n";
    $codigo = ltrim((string) $r0['codigo_bairro'], '0');
    $doLote = fn () => DB::table('cadastro_externo_imoveis')
        ->whereRaw("TRIM(LEADING '0' FROM codigo_bairro) = ?", [$codigo])
        ->whereRaw("TRIM(LEADING '0' FROM quadra) = ?", [ltrim((string) $maior->quadra, '0')])
        ->whereRaw("TRIM(LEADING '0' FROM lote) = ?", [ltrim((string) $maior->numero_lote, '0')]);
    $loteMaior = DB::table('lotes')->where('id', $maior->id)->first();
    $doLote()->delete();
    Illuminate\Support\Facades\Cache::forget('cadastro:areas:' . $codigo);
    $areas = new App\Cadastro\AreaOficial();
    confere($areas->doCadastro($loteMaior) === null && $areas->doLote($loteMaior) === 305.0, 'lote sem imóvel no cadastro: vale a área do desenho');
    DB::table('cadastro_externo_imoveis')->insert(['inscricao' => '99.999.999.9999.999', 'codigo_bairro' => $r0['codigo_bairro'],
        'quadra' => $maior->quadra, 'lote' => $maior->numero_lote, 'area_terreno_m2' => 312.00, 'created_at' => now(), 'updated_at' => now()]);
    Illuminate\Support\Facades\Cache::forget('cadastro:areas:' . $codigo);
    $areas = new App\Cadastro\AreaOficial();
    confere($areas->doCadastro($loteMaior) === 312.0 && $areas->doLote($loteMaior) === 312.0, 'com imóvel no cadastro: vale a área do cadastro, e não a do desenho');
    Illuminate\Support\Facades\Cache::forget('cadastro:areas:' . $codigo);

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
}
