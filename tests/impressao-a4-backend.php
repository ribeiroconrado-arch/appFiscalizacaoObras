<?php

// Valida o modelo com dados em memória, sem consultar ou alterar documentos reais.
// php tests/impressao-a4-backend.php [diretorio-para-previas]
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

$doc = new Documento([
    'tipo' => 'auto_infracao', 'numero' => 2, 'exercicio' => 2026, 'status' => 'lavrado',
    'autuado_nome' => 'Empresa de Teste Ltda', 'autuado_documento' => '00.000.000/0001-00',
    'autuado_endereco' => 'Rua de Teste, 100 — Centro — Primavera do Leste/MT — CEP 78850-000',
    'data_fato' => '2026-07-23 18:14', 'data_lavratura' => '2026-08-06 15:59',
    'area_terreno_m2' => 1413.86, 'area_construida_m2' => 120,
    'descricao' => 'Constatada obra sem licença e sem placa de identificação.',
    'observacoes' => 'Observação registrada pelo agente.',
]);
$doc->setRelation('agente', new User(['name' => 'Fiscal de Teste', 'matricula' => '00000/1']));
$doc->setRelation('legislacao', new Legislacao(['numero' => 'Lei Complementar nº 1/2023', 'nome' => 'Código de Obras']));
$dados = [
    'doc' => $doc, 'titulo' => 'AUTO DE INFRAÇÃO', 'navegador' => false,
    'orgao' => ['secretaria' => 'Secretaria de Segurança Pública do Município de Primavera do Leste-MT',
        'nome' => 'Departamento de Fiscalização', 'departamento' => 'Divisão de Fiscalização de Obras e Posturas',
        'divisao' => '', 'endereco' => 'Rua Maringá, 444 – Centro', 'telefone' => '(66) 3500-4779',
        'municipio' => 'Primavera do Leste – MT', 'selo' => 'FISCALIZAÇÃO DE POSTURAS'],
    'brasao' => public_path('img/brasao-prefeitura.png'), 'origemTexto' => 'NOTIFICAÇÃO Nº NOT 2026/0003',
    'imovel' => ['inscricao' => '01.105.024.0009.000', 'bairro' => 'Jardim Europa IV', 'quadra' => 24,
        'lote' => 9, 'endereco' => 'Rua de Teste, 100'],
    'ciencia' => 'Fica o autuado cientificado das infrações. Apresente defesa no protocolo municipal, Rua Maringá, 444.',
    'prazo' => ['rotulo' => 'Prazo de defesa', 'data' => '20/08/2026', 'nota' => 'Dias úteis, contados da lavratura.'],
    'memoria' => ['total' => 58, 'upf' => 5.8234, 'emReais' => 337.7572, 'linhas' => [
        ['numero' => 'TESTE-1', 'conduta' => 'Executar obra sem o competente alvará de licença.',
            'sancao' => 'Multa e embargo.', 'base' => 'Valor fixo', 'conta' => '12,00 UPF', 'limite' => null, 'valor' => 12],
        ['numero' => 'TESTE-2', 'conduta' => 'Manter obra sem placa de identificação.',
            'sancao' => '', 'base' => 'Valor fixo', 'conta' => '4,00 UPF', 'limite' => null, 'valor' => 4],
        ['numero' => '55', 'conduta' => 'Executar obra sem alvará de construção expedido pela prefeitura.',
            'sancao' => 'Multa proporcional à área construída, sem prejuízo do embargo.',
            'base' => 'Por área construída', 'conta' => '0,3500 UPF/m² × 120,00 m²', 'limite' => null, 'valor' => 42],
    ]],
    'marca' => null, 'termoRecusa' => 'Certifico a recusa de assinatura do autuado.', 'anexos' => [], 'rodape' => [],
];
if (! is_file($dados['brasao'])) { $dados['brasao'] = null; }
$checks = 0;
$check = function (bool $ok, string $mensagem) use (&$checks) {
    if (! $ok) { throw new RuntimeException($mensagem); }
    $checks++;
};
$render = fn (array $d) => view('impressao.a4', $d)->render();
$html = $render($dados);
foreach (['01   Identificação', '02   Local', '03   Infração', '04   Ciência', $doc->descricao,
    $doc->observacoes, '0,3500 UPF/m² × 120,00 m²', 'Multa e embargo.', '20/08/2026', 'Fiscal de Teste'] as $texto) {
    $check(str_contains($html, $texto), 'Conteúdo ausente: '.$texto);
}
foreach (['Total da multa', '58,00 UPF', 'R$ 337', 'CEP 78850', '05   Termo', '06   Anexos'] as $texto) {
    $check(! str_contains($html, $texto), 'Conteúdo indevido: '.$texto);
}
$check(strpos($html, '>Cálculo<') < strpos($html, '>Multa · UPF<'), 'Ordem das colunas incorreta');
$pdf = Pdf::loadHTML($html)->setPaper('a4')->output();
$check(str_starts_with($pdf, '%PDF-'), 'PDF não gerado');
if (isset($argv[1])) {
    if (! is_dir($argv[1])) { mkdir($argv[1], 0777, true); }
    file_put_contents($argv[1].'/auto-a4.pdf', $pdf);
    file_put_contents($argv[1].'/auto-a4.html', $render(array_replace($dados, ['navegador' => true])));
}

$doc->recusa_assinatura = 'Recusou-se a assinar após a leitura.';
$dados['anexos'] = [['foto' => false, 'src' => null, 'titulo' => 'Anexo de teste', 'descricao' => 'Evidência registrada.', 'dataHora' => '23/07/2026 18:14']];
$html = $render($dados);
$check(str_contains($html, '05   Termo de Recusa'), 'Recusa não exibida');
$check(str_contains($html, $doc->recusa_assinatura), 'Relato da recusa perdido');
$doc->recusa_assinatura = null;
$check(str_contains($render($dados), '06   Anexos'), 'Anexos renumerados sem recusa');
$dados['memoria']['linhas'][2]['limite'] = 'teto da lei aplicado';
$check(str_contains($render($dados), 'teto da lei aplicado'), 'Limite legal omitido');
$doc->tipo = 'notificacao';
$dados['memoria']['total'] = null;
$html = $render($dados);
$check(! str_contains($html, '>Multa · UPF<'), 'Notificação anuncia multa não aplicada');
$check(str_contains($html, 'Manter obra sem placa'), 'Notificação perdeu enquadramento');

// Conteúdo longo: validar o motor PDF e fornecer páginas para inspeção visual.
if (isset($argv[1])) {
    $doc->tipo = 'auto_infracao';
    $doc->recusa_assinatura = 'Recusou-se a assinar após a leitura.';
    $dados['memoria']['total'] = 58;
    $dados['memoria']['linhas'] = array_merge(...array_fill(0, 8, $dados['memoria']['linhas']));
    file_put_contents($argv[1].'/auto-a4-longo.pdf', Pdf::loadHTML($render($dados))->setPaper('a4')->output());
}
echo "OK: {$checks} verificações do modelo A4.\n";
