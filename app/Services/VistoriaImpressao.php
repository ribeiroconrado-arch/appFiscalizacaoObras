<?php

namespace App\Services;

use App\Models\Documento;
use App\Models\Evidencia;
use App\Models\Vistoria;
use Illuminate\Support\Facades\Storage;

/**
 * O RELATÓRIO DE VISTORIA em papel.
 *
 * A vistoria era o único ato do sistema sem via impressa: depois de gravada
 * virava uma linha na linha do tempo, e não havia o que anexar ao processo nem
 * o que entregar ao proprietário. Ela é o ato que fundamenta o embargo e o
 * auto — precisa poder ser lida fora da tela, por quem não a praticou.
 *
 * Espelha `DocumentoImpressao` de propósito: mesmo cabeçalho institucional,
 * mesma decisão sobre imagem (rota no navegador, base64 no dompdf), mesmo
 * rodapé. O que muda é o conteúdo, não a receita — duas receitas diferentes
 * para o mesmo papel envelheceriam separadas.
 */
class VistoriaImpressao
{
    /**
     * @return array<string, mixed>
     */
    public function montar(Vistoria $v, bool $paraPdf = false): array
    {
        $v->loadMissing([
            'lote', 'fiscal', 'documentos', 'irregularidades',
            'itens.irregularidades', 'itens.artigos.artigo',
            'itens.exigencias', 'itens.evidencias',
        ]);

        return [
            'v'      => $v,
            'titulo' => 'RELATÓRIO DE VISTORIA',
            'orgao'  => app(CabecalhoOficial::class)->orgao(),
            'rodape' => app(CabecalhoOficial::class)->rodape(),
            'brasao' => app(CabecalhoOficial::class)->brasao($paraPdf),

            'imovel'      => $this->imovel($v),
            'constatado'  => $this->constatado($v),
            'relatorio'   => $this->relatorio($v, $paraPdf),
            'documentos'  => $this->documentos($v),
            'assinatura'  => $v->fiscal?->assinatura,
        ];
    }

    /** O imóvel como se escreve num papel: rua antes de quadra e lote. */
    private function imovel(Vistoria $v): string
    {
        if (! $v->lote) {
            return 'Imóvel não identificado';
        }

        return trim(sprintf(
            'Quadra %s, Lote %s — %s',
            $v->lote->quadra ?? '—',
            $v->lote->numero_lote ?? '—',
            $v->lote->bairro ?? '—'
        ));
    }

    /**
     * O que a vistoria apurou, campo a campo.
     *
     * O VAZIO É DITO. Num processo administrativo, "não informado" e "não
     * perguntado" são coisas diferentes: só entram aqui os campos que ESTA
     * finalidade pergunta, e os que ficaram em branco saem com a frase que
     * explica a consequência — a área é o caso que mais importa, porque sem
     * ela a multa por metro quadrado não é calculada.
     *
     * @return array<int, array{rotulo:string, valor:?string, falta:?string}>
     */
    private function constatado(Vistoria $v): array
    {
        $blocos = $v->camposDaFinalidade();
        $linhas = [];

        $põe = function (string $rotulo, ?string $valor, string $falta) use (&$linhas) {
            $linhas[] = [
                'rotulo' => $rotulo,
                'valor'  => ($valor === null || $valor === '') ? null : $valor,
                'falta'  => $falta,
            ];
        };

        $põe('Situação constatada', $v->situacaoRotulo(), 'não informada');
        $põe(
            'Acompanhante',
            $v->acompanhante_nome
                ? $v->acompanhante_nome . (
                    ($q = Vistoria::QUALIFICACOES[$v->acompanhante_qualificacao] ?? null)
                        ? " — {$q}" : ''
                  )
                : null,
            'ninguém identificado no local'
        );
        $põe(
            'Coordenada',
            $v->latitude ? sprintf('%.6f, %.6f', $v->latitude, $v->longitude) : null,
            'não capturada'
        );

        if (in_array('alvara', $blocos, true)) {
            $rotulo = Vistoria::ALVARA[$v->alvara_situacao] ?? null;
            $põe(
                'Alvará de construção',
                $rotulo ? $rotulo . ($v->alvara_numero ? " nº {$v->alvara_numero}" : '') : null,
                'não verificado'
            );
        }
        if (in_array('area', $blocos, true)) {
            $põe(
                'Área construída aferida',
                $v->areaAferidaRotulo(),
                'não medida — multa por m² não pode ser calculada'
            );
        }
        if (in_array('obra', $blocos, true)) {
            $põe('Fase da obra', Vistoria::FASES_OBRA[$v->fase_obra] ?? null, 'não informada');
            $põe('Conforme o projeto', Vistoria::CONFORMIDADES[$v->conforme_projeto] ?? null, 'não verificado');
        }
        if (in_array('uso', $blocos, true)) {
            $põe('Uso constatado', Vistoria::USOS[$v->uso_constatado] ?? null, 'não informado');
        }
        if (in_array('idade', $blocos, true)) {
            $põe(
                'Época da construção',
                $v->ano_construcao_estimado ? 'por volta de ' . $v->ano_construcao_estimado : null,
                'não estimada'
            );
        }

        return $linhas;
    }

    /**
     * O relatório NA ORDEM EM QUE FOI ESCRITO.
     *
     * `Vistoria::relatorio()` já intercala fotos e itens de lei como o fiscal
     * montou, e a ordem é conteúdo: uma foto depois do artigo que ela ilustra
     * diz o que a mesma foto no fim de uma lista não diz. Aqui só se acrescenta
     * a fonte da imagem — e se decide o que fazer com o anexo que não é imagem.
     *
     * @return array<int, array<string, mixed>>
     */
    private function relatorio(Vistoria $v, bool $paraPdf): array
    {
        $porId = $v->itens->flatMap->evidencias->keyBy('id');

        return $v->relatorioEmItens()->map(function (array $item) use ($porId, $paraPdf) {
            $item['fotos'] = array_map(function (array $f) use ($porId, $paraPdf) {
                $e = $porId->get($f['id']);
                // O sistema aceita PDF de propósito (laudo, projeto, alvará).
                // Ele não se imprime junto, mas PRECISA constar: um anexo que
                // existe no processo e some do papel é peça que ninguém sabe
                // que existe.
                $f['src'] = ($e && $f['imagem']) ? $this->fonteImagem($e, $paraPdf) : null;

                return $f;
            }, $item['fotos']);

            return $item;
        })->all();
    }

    /**
     * Caminho da foto para a view. O dompdf lê do disco; o navegador busca
     * pela rota autenticada, mais leve do que embutir a imagem no HTML.
     *
     * `null` quando o arquivo sumiu do disco — a página prefere dizer que há um
     * anexo a estampar um quadrado quebrado.
     */
    private function fonteImagem(Evidencia $e, bool $paraPdf): ?string
    {
        if (! $paraPdf) {
            return route('evidencia.arquivo', $e);
        }

        $disco = Storage::disk('private');
        if (! $disco->exists($e->arquivo)) {
            return null;
        }

        return 'data:' . ($e->mime ?: 'image/jpeg') . ';base64,'
            . base64_encode($disco->get($e->arquivo));
    }

    /**
     * O que esta constatação virou.
     *
     * A pergunta de quem reabre o caso — e a resposta "nada ainda" é a que o
     * painel cobra como "vistoria irregular sem documento".
     *
     * @return array<int, array<string, string>>
     */
    private function documentos(Vistoria $v): array
    {
        return $v->documentos->map(fn (Documento $d) => [
            'numero' => $d->numeroFormatado(),
            'tipo'   => $d->rotuloTipo(),
            'data'   => ($d->data_lavratura ?? $d->created_at)?->format('d/m/Y') ?? '—',
            'status' => $d->statusBadge()[0],
        ])->all();
    }
}
