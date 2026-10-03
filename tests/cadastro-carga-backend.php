<?php
// Diagnóstico local explícito, no molde de importacao-backend.php: tudo roda
// dentro de uma transação que é DESFEITA no fim. Exige as migrações
// 2026_10_04_000100 e 2026_10_05_000100 aplicadas.
//
//   php tests/cadastro-carga-backend.php
//
// Cobre a carga mensal do cadastro (App\Cadastro\CargaDoCadastro) com
// planilhas geradas aqui, em dois bairros fictícios (900 e 901) para não se
// misturar com o cadastro de verdade: linha legada vira base, mesma planilha
// não muda nada, mudança vem campo a campo, ausente é marcado e reaparece,
// arquivo de um bairro não dá os outros como ausentes, planilha cortada para
// em "aguardando confirmação".

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Cadastro\CargaDoCadastro;
use App\Models\CadastroCarga;
use Illuminate\Support\Facades\DB;

$ok = 0;
function confere(bool $cond, string $o_que): void {
    global $ok;
    if (! $cond) { throw new RuntimeException('FALHOU: ' . $o_que); }
    $ok++;
    echo "  ok  {$o_que}\n";
}

/** .xlsx mínimo, com texto em linha e a posição de cada célula, como o Excel grava. */
function xlsx(string $path, array $linhas): void {
    $col = function ($i) { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; };
    $rows = '';
    foreach ($linhas as $n => $l) {
        $rows .= '<row r="' . ($n + 1) . '">';
        foreach (array_values($l) as $i => $v) {
            $rows .= '<c r="' . $col($i) . ($n + 1) . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $v, ENT_XML1) . '</t></is></c>';
        }
        $rows .= '</row>';
    }
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="a" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData>' . $rows . '</sheetData></worksheet>');
    $z->close();
}

$cab = ['Inscrição', 'Código do Bairro', 'Nome do Bairro', 'Quadra', 'Lote', 'Área Terreno', 'Isenção ou Imunidade',
    'Tipo de Logradouro', 'Nome do Logradouro', 'Proprietário', 'CPF/CNPJ', 'ASFALTO'];
$imovel = fn ($b, $q, $l) => [sprintf('01.%03d.%03d.%04d.000', $b, $q, $l), sprintf('%06d', $b), "Bairro $b",
    sprintf('%03d', $q), sprintf('%04d', $l), '350', 'Não', 'Rua', 'A', 'Fulano', '111', 'SIM'];
$base = [];
foreach ([900, 901] as $b) { for ($q = 1; $q <= 5; $q++) { for ($l = 1; $l <= 10; $l++) { $base[] = $imovel($b, $q, $l); } } }

$arquivo = sys_get_temp_dir() . '/cadastro-carga-teste.xlsx';
$carregar = function (array $linhas, bool $confirmar = false) use ($cab, $arquivo): CadastroCarga {
    xlsx($arquivo, array_merge([['Relatório do cadastro'], $cab], $linhas));
    $c = CadastroCarga::create(['arquivo_nome' => 'teste.xlsx', 'arquivo_bytes' => filesize($arquivo),
        'arquivo_sha256' => hash_file('sha256', $arquivo), 'status' => 'na_fila']);

    return app(CargaDoCadastro::class)->processar($c, $arquivo, $confirmar);
};
$nossos = fn () => DB::table('cadastro_externo_imoveis')->where('inscricao', 'like', '01.90_.%');

DB::beginTransaction();
try {
    echo "1. linha legada (sem hash) vira base\n";
    DB::table('cadastro_externo_imoveis')->insert(['inscricao' => '01.900.001.0001.000', 'codigo_bairro' => '000900',
        'quadra' => '001', 'lote' => '0001', 'area_terreno_m2' => 350]);
    $c = $carregar($base);
    confere($c->status === 'concluida' && $c->novos === 99 && $c->primeira, '99 novos e 1 base');
    confere(DB::table('cadastro_alteracoes')->where('inscricao', '01.900.001.0001.000')->doesntExist(), 'base não gera histórico');

    echo "2. mesma planilha de novo\n";
    $c = $carregar($base);
    confere($c->novos === 0 && $c->alterados === 0 && $c->iguais === 100, '0 novos, 0 alterados, 100 iguais');
    confere($nossos()->where('vista_na_carga_id', $c->id)->count() === 100, '"Últ. integração" atualizada em todos');

    echo "3. três mudanças\n";
    $l = $base; $l[0][5] = '400'; $l[1][9] = 'Beltrano'; $l[2][11] = 'NAO';
    $c = $carregar($l);
    $alt = DB::table('cadastro_alteracoes')->where('carga_id', $c->id)->get();
    confere($c->alterados === 3 && $alt->count() === 3, '3 alterados, 3 registros');
    confere($alt->contains(fn ($a) => $a->campo === 'area_terreno_m2' && $a->antes === '350.00' && $a->depois === '400.00'), 'área 350.00 → 400.00');
    confere($alt->contains(fn ($a) => $a->campo === 'proprietarios'), 'troca de proprietário registrada');

    echo "4. uma linha some e volta\n";
    $sem = $l; array_pop($sem);
    $c = $carregar($sem);
    confere($c->ausentes === 1 && $nossos()->whereNotNull('ausente_desde_carga_id')->count() === 1, '1 ausente, marcado e não apagado');
    $c = $carregar($l);
    confere($c->reaparecidos === 1 && $nossos()->whereNotNull('ausente_desde_carga_id')->doesntExist(), 'reapareceu');

    echo "5. arquivo de um bairro só\n";
    $c = $carregar(array_values(array_filter($l, fn ($r) => $r[1] === '000900')));
    confere($c->ausentes === 0, 'não dá o bairro 901 como ausente');

    echo "6. planilha cortada\n";
    $c = $carregar(array_slice($l, 0, 60));
    confere($c->status === 'aguardando_confirmacao', 'mais de 20% sumiu: pede confirmação');
    $c = app(CargaDoCadastro::class)->processar($c, $arquivo, true);
    confere($c->status === 'concluida' && $c->ausentes === 40, 'confirmada: 40 ausentes');

    echo "\n{$ok} verificações passaram.\n";
} finally {
    DB::rollBack();
    @unlink($arquivo);
}
