<?php
// Diagnóstico local explícito: tudo roda dentro de uma transação que é
// DESFEITA no fim. Exige um bairro com lotes ativos já ligado a um código do
// cadastro (Parâmetros → Bairros).
//
//   php tests/conferencia-desmembrados-backend.php
//
// Cobre a numeração dos lotes desmembrados (4A, 4B…) na conferência com o
// cadastro — a regra de 03/11/2026 (App\Cadastro\SequenciaDoDesmembramento):
// sequência a partir da .000 ativa, troca pela área, empate pela sequência, e
// a parte que sobra fica sem inscrição.

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

// Um bairro ligado ao cadastro, com uma quadra numérica de pelo menos 7 lotes.
$bairro = null; $lotes = collect();
foreach (array_keys($bairros->codigos()) as $chave) {
    $nome = DB::table('lotes')->where('situacao', 'ativo')->distinct()->pluck('bairro')
        ->first(fn ($b) => BairrosDoDesenho::chave((string) $b) === $chave);
    if (! $nome) { continue; }
    $q = DB::table('lotes')->where('situacao', 'ativo')->where('bairro', $nome)->whereRaw("quadra REGEXP '^[0-9]+$'")
        ->whereNull('inscricao_imobiliaria')->whereNull('terreno_id')
        ->groupBy('quadra')->havingRaw('count(*) >= 7')->value('quadra');
    if ($q === null) { continue; }
    $lotes = DB::table('lotes')->where('situacao', 'ativo')->where('bairro', $nome)->where('quadra', $q)
        ->whereNull('inscricao_imobiliaria')->whereNull('terreno_id')->limit(7)->get();
    $bairro = $nome;
    break;
}
if (! $bairro) { throw new RuntimeException('Sem bairro ligado ao cadastro, com uma quadra de 7 lotes, para o teste local.'); }
$codigo = $bairros->codigos()[BairrosDoDesenho::chave($bairro)];
$quadra = $lotes[0]->quadra;
$familia = fn (int $n) => substr(InscricaoImobiliaria::montar($codigo, $quadra, $n, 0), 0, 12);

$fonte = new class implements FonteDoCadastro {
    public array $linhas = [];
    public function consultar(Lote $l): ?RetratoBci { return null; }
    public function porQueVazio(Lote $l): string { return ''; }
    public function nome(): string { return 'exportacao'; }
    public function imoveisDoBairro(string $c): iterable { return $this->linhas; }
    public function proprietarios(Lote $l): array { return []; }
    public function situacao(Lote $l): array { return ['inscricoes' => [], 'carga_id' => null, 'em' => null, 'alterado_em' => null, 'ausente_desde' => null]; }
};
$cad = fn (int $n, int $final, ?float $area, string $isencao = 'Normal') => ['inscricao' => $familia($n) . sprintf('%03d', $final),
    'quadra' => $quadra, 'lote' => (string) $n, 'isencao' => $isencao, 'area_terreno_m2' => $area, 'endereco' => null];

DB::beginTransaction();
try {
    // Os 7 lotes viram três desmembramentos, com números que a quadra não usa.
    $poe = function (int $i, string $numero, float $area) use ($lotes) {
        DB::table('lotes')->where('id', $lotes[$i]->id)->update(['numero_lote' => $numero, 'desmembramento' => 0,
            'area_gis_m2' => $area, 'origem' => 'importacao']);
        return (int) $lotes[$i]->id;
    };
    $a90 = $poe(0, '90A', 200.00); $b90 = $poe(1, '90B', 300.00);                       // desenho invertido
    $l91 = $poe(2, '91', 250.00);  $a91 = $poe(3, '91A', 250.00);                       // .000 ativa, empate
    $a92 = $poe(4, '92A', 200.00); $b92 = $poe(5, '92B', 300.00); $c92 = $poe(6, '92C', 150.00);   // uma a mais
    $fonte->linhas = [
        $cad(90, 0, 500.00, 'Inativo'), $cad(90, 1, 300.00), $cad(90, 2, 200.00),
        $cad(91, 0, 250.00), $cad(91, 1, 250.00),
        $cad(92, 0, 650.00, 'Inativo'), $cad(92, 1, 300.00), $cad(92, 2, 150.00),
    ];

    echo "Conferir: os desmembrados recebem a inscrição do cadastro\n";
    $svc = app(ConferenciaComCadastro::class);
    $r = $svc->conferirBairro($bairro, $fonte, 'Cadastro de teste');
    $final = fn (int $id) => (int) DB::table('lotes')->where('id', $id)->value('desmembramento');
    $insc = fn (int $id) => InscricaoImobiliaria::normalizar($bairros->inscricaoDe(DB::table('lotes')->where('id', $id)->first()));

    confere($final($a90) === 2 && $final($b90) === 1, '90A/90B: a área inverte a sequência (A fica com a .002)');
    confere($insc($a90) === $familia(90) . '002', 'a inscrição montada acompanha o final novo');
    confere($final($l91) === 0 && $final($a91) === 1, '91/91A: .000 ativa fica com o sem letra, e o empate segue a sequência');
    confere($final($b92) === 1 && $final($c92) === 2, '92B/92C: cada um com a área dele');
    $sem = collect($r['sem_inscricao'])->keyBy('lote_id');
    confere(isset($sem[$a92]) && ($sem[$a92]['desmembrado'] ?? false), '92A: a parte a mais fica sem inscrição (pendência)');
    $ids = [$a90, $b90, $l91, $a91, $a92, $b92, $c92];
    confere(! collect($r['area_diferente'])->whereIn('lote_id', $ids)->count(), 'nenhuma divergência de área nos que casaram');
    confere(! collect($r['nao_encontrados'])->whereIn('lote_id', $ids)->count() && ! collect($r['inativos'])->whereIn('lote_id', $ids)->count(),
        'nenhum ficou "não encontrado" nem "inativo"');
    confere(collect($r['numerados'])->pluck('lote_id')->sort()->values()->all() === collect([$a90, $b90, $a91, $b92, $c92])->sort()->values()->all(),
        'o resultado lista os renumerados (o 91 já estava com a .000)');

    echo "Conferir de novo: nada muda\n";
    $r2 = $svc->conferirBairro($bairro, $fonte, 'Cadastro de teste');
    confere($r2['numerados'] === [], 'segunda conferência não renumera');

    echo "Revisar sem planilha: a pendência do 92A continua\n";
    $rv = $svc->revisarBairro($bairro);
    confere(collect($rv['sem_inscricao'])->contains(fn ($s) => (int) $s['lote_id'] === $a92), '92A continua sem inscrição');

    echo "Inscrição informada à mão: o grupo fica de fora\n";
    DB::table('lotes')->where('id', $a90)->update(['inscricao_imobiliaria' => $familia(90) . '001']);
    DB::table('lotes')->where('id', $b90)->update(['desmembramento' => 0]);
    $svc->conferirBairro($bairro, $fonte, 'Cadastro de teste');
    confere($final($b90) === 0, 'com uma inscrição à mão no grupo, a regra não mexe');

    echo "\nOK: {$ok} verificações.\n";
} finally {
    DB::rollBack();
}
