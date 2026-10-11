<?php

// Gera casos em memória para inspeção de paginação, sem consultar documentos reais.
$destino = $argv[1] ?? sys_get_temp_dir();
unset($argv[1]);
require __DIR__.'/impressao-a4-backend.php';
$doc->tipo = 'notificacao';
$doc->recusa_assinatura = null;
$dados['titulo'] = 'NOTIFICAÇÃO';
$dados['memoria']['total'] = null;
$dados['brasao'] = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('img/brasao-prefeitura.png')));
$dados['anexos'] = [];
for ($i = 1; $i <= 6; $i++) {
    $dados['anexos'][] = ['foto' => true, 'src' => $dados['brasao'], 'titulo' => 'Foto de teste '.$i,
        'descricao' => 'Imagem ilustrativa para validar a disposição dos anexos.', 'dataHora' => '11/10/2026 09:00'];
}
$gerar = function ($nome) use (&$dados, $destino) {
    $html = view('impressao.a4', $dados)->render();
    $pdf = \App\Services\PaginacaoDocumentoA4::aplicar(\Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4'));
    file_put_contents($destino.'/'.$nome.'.pdf', $pdf->output());
};
$gerar('a4-anexos-curto');
file_put_contents($destino.'/a4-anexos-navegador.html', view('impressao.a4', array_replace($dados, ['navegador' => true]))->render());
$dados['memoria']['linhas'] = array_merge(...array_fill(0, 8, $dados['memoria']['linhas']));
$doc->recusa_assinatura = 'Recusa registrada para conferir a numeração sequencial.';
$gerar('a4-anexos-longo');
echo "Casos de paginação gerados.\n";
