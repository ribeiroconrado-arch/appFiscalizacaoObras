<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * A listagem da conferência com o cadastro, em PDF.
 *
 * Serve às duas telas que conferem — a do BAIRRO (Base de lotes ›
 * Conferência) e a da IMPORTAÇÃO (a ficha) —, porque o resultado das duas tem
 * o mesmo formato (ConferenciaComCadastro). Sai o que a tela está mostrando:
 * todas as divergências, ou só as do cartão escolhido.
 *
 * O PDF é o retrato da ÚLTIMA conferência guardada; não confere de novo.
 */
class ConferenciaPdf
{
    /** Os tipos de divergência, na ordem da tela, com o título de cada grupo. */
    public const TIPOS = [
        'nao_encontrados' => 'Não está no cadastro',
        'inativos'        => 'Inativo no cadastro',
        'sem_lote'        => 'No cadastro, sem lote no mapa',
        'sem_inscricao'   => 'Sem quadra ou número (inscrição não se monta)',
        'area_diferente'  => 'Área do desenho diferente da do cadastro',
    ];

    public function __construct(private CabecalhoOficial $cabecalho) {}

    /**
     * @param array<string,mixed>  $resultado      o resultado guardado da conferência
     * @param string               $onde           "Jardim Europa IV", "Importação nº 12 · Buritis V"
     * @param string|null          $tipo           só este tipo de divergência; null = todas
     * @param array<string,array>  $justificativas "tipo|chave" => [motivo, por, em] (só o bairro tem)
     */
    public function gerar(array $resultado, string $onde, ?string $tipo, array $justificativas, string $arquivo): Response
    {
        $num = fn ($v) => (int) preg_replace('/\D/', '', (string) $v);
        $grupos = [];
        foreach (self::TIPOS as $chave => $rotulo) {
            if ($tipo !== null && $tipo !== $chave) {
                continue;
            }
            $linhas = [];
            foreach ($resultado[$chave] ?? [] as $l) {
                $id = $chave === 'sem_inscricao' ? 'lote:' . ($l['lote_id'] ?? '') : (string) ($l['inscricao'] ?? '');
                $linhas[] = $l + ['justificativa' => $justificativas[$chave . '|' . $id] ?? null];
            }
            if (! $linhas) {
                continue;
            }
            // A mesma ordem da tela: pendentes antes das justificadas; a área,
            // da maior diferença para a menor; o resto, por quadra e lote.
            usort($linhas, fn ($a, $b) => [(int) ! empty($a['justificativa']),
                    $chave === 'area_diferente' ? -abs((float) ($a['diferenca'] ?? 0)) : 0, $num($a['quadra'] ?? ''), $num($a['lote'] ?? '')]
                <=> [(int) ! empty($b['justificativa']),
                    $chave === 'area_diferente' ? -abs((float) ($b['diferenca'] ?? 0)) : 0, $num($b['quadra'] ?? ''), $num($b['lote'] ?? '')]);
            $grupos[] = ['tipo' => $chave, 'rotulo' => $rotulo, 'linhas' => $linhas,
                'pendentes' => count(array_filter($linhas, fn ($l) => empty($l['justificativa'])))];
        }

        $pdf = Pdf::loadView('impressao.conferencia', [
            'navegador' => false,
            'orgao'     => $this->cabecalho->orgao(),
            'brasao'    => $this->cabecalho->brasao(true),
            'r'         => $resultado,
            'onde'      => $onde,
            'filtro'    => $tipo ? self::TIPOS[$tipo] : null,
            'grupos'    => $grupos,
            'emitido'   => now()->format('d/m/Y H:i'),
            'por'       => auth()->user()?->name,
        ])->setPaper('a4');

        return $pdf->stream($arquivo);
    }
}
