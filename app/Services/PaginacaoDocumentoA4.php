<?php

namespace App\Services;

use Barryvdh\DomPDF\PDF;

class PaginacaoDocumentoA4
{
    public static function aplicar(PDF $pdf): PDF
    {
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text(28.35, $canvas->get_height() - 24, 'pág.: {PAGE_NUM}/{PAGE_COUNT}', $font, 7, [0.33, 0.33, 0.33]);

        return $pdf;
    }
}
