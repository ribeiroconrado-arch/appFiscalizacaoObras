<?php

// Reutiliza os dados em memória; não consulta nem modifica documentos reais.
$saidaTermica = $argv[1] ?? null;
unset($argv[1]);
require __DIR__.'/impressao-a4-backend.php';
$checks = 0;
$render = fn (array $d) => view('impressao.termica', $d)->render();
$dados['memoria']['total'] = 58;
$dados['brasao'] = is_file(public_path('img/brasao-prefeitura.png'))
    ? 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('img/brasao-prefeitura.png'))) : null;
foreach (['auto_infracao', 'auto_embargo', 'notificacao', 'notificacao_embargo', 'vistoria'] as $tipo) {
    $doc->tipo = $tipo;
    $dados['titulo'] = mb_strtoupper($doc->rotuloTipo());
    $dados['memoria']['total'] = in_array($tipo, ['auto_infracao', 'auto_embargo']) ? 58 : null;
    foreach ([null, 'Recusou-se a assinar.'] as $recusa) {
        $doc->recusa_assinatura = $recusa;
        $html = $render($dados);
        preg_match_all('/class="faixa">(\d+) - /', $html, $grupos);
        $check(array_map('intval', $grupos[1]) === range(1, count($grupos[1])), 'Numeração térmica: '.$tipo);
        $nome = in_array($tipo, \App\Models\Documento::COM_CUMPRIMENTO) ? 'notificado' : ($tipo === 'vistoria' ? 'interessado' : 'autuado');
        $check(str_contains($html, 'Identificação do '.$nome), 'Destinatário térmico: '.$tipo);
        $check(!str_contains($html, 'CEP 78850'), 'CEP indevido');
        $check(!str_contains($html, 'R$ 337'), 'Total indevido');
        $check(str_contains($html, 'Termo de Recusa') === (bool)$recusa, 'Recusa indevida');
        if ($tipo === 'auto_infracao') {
            $check(str_contains($html, '0,3500 UPF/m² × 120,00 m²'), 'Cálculo ausente');
            $check(str_contains($html, 'Multa e embargo.'), 'Sanção ausente');
        }
        if ($saidaTermica && $tipo === 'auto_infracao' && !$recusa) {
            file_put_contents($saidaTermica, $html);
        }
    }
}
echo "OK: {$checks} verificações do modelo térmico.\n";
