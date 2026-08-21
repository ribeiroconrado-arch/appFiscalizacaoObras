<?php

namespace App\Services;

use App\Models\Documento;
use App\Models\Evidencia;
use App\Models\Parametro;
use Illuminate\Support\Facades\Storage;

/**
 * Monta o pacote de dados de UM documento pronto para impressão.
 *
 * Existe para que os três destinos — PDF (dompdf), janela de impressão A4 e
 * bobina térmica 80mm — leiam exatamente o mesmo conteúdo. No AppPOSTURAS
 * essa unificação é o `_gerarHtmlImpressao`, que monta o HTML uma vez só e
 * troca apenas o corpo e a folha de estilo; aqui o papel equivalente é este
 * serviço, e as views ficam só com a diagramação.
 *
 * Sem isso, corrigir um rótulo obrigaria a lembrar de corrigi-lo em três
 * lugares — e é assim que uma via impressa passa a divergir do PDF anexado
 * ao processo.
 */
class DocumentoImpressao
{
    /**
     * @param  bool  $paraPdf   true quando o destino é o dompdf: a imagem
     *                          precisa virar data URI, porque o dompdf não
     *                          tem sessão para buscar o arquivo pela rota.
     * @param  bool  $comAnexos false omite a seção de anexos (escolha do
     *                          usuário, igual ao `#m-pdf-anexos` do POSTURAS).
     * @return array<string,mixed>
     */
    public function montar(Documento $doc, bool $paraPdf = false, bool $comAnexos = true): array
    {
        $doc->loadMissing(['lote', 'legislacao', 'agente', 'artigos', 'origem', 'vistoria.evidencias']);

        $prazoDias = in_array($doc->tipo, Documento::COM_CUMPRIMENTO, true) ? $doc->prazo_dias : null;

        return [
            'doc'    => $doc,
            'titulo' => mb_strtoupper($doc->rotuloTipo()),
            'orgao'  => $this->orgao(),
            'rodape' => array_values(array_filter([
                Parametro::get('rodape_protocolo'),
                Parametro::get('rodape_ouvidoria'),
            ])),
            'brasao'      => $this->brasao($paraPdf),
            'imovel'      => $this->imovel($doc),
            'origemTexto' => $this->origem($doc),
            'ciencia'     => $doc->legislacao?->ciencia($doc->tipo, $prazoDias),
            'memoria'     => $this->memoria($doc),
            'anexos'      => $comAnexos ? $this->anexos($doc, $paraPdf) : [],
            'termoRecusa' => Parametro::get('termo_recusa'),
            'marca'       => $this->marca($doc),
            'prazo'       => $this->prazo($doc),
        ];
    }

    /** Cabeçalho institucional — cadastrado em Parâmetros, nunca fixo no código. */
    private function orgao(): array
    {
        return [
            'nome'         => Parametro::get('orgao_nome'),
            'secretaria'   => Parametro::get('orgao_secretaria'),
            'departamento' => Parametro::get('orgao_departamento'),
            'divisao'      => Parametro::get('orgao_divisao'),
            'municipio'    => Parametro::get('orgao_municipio'),
            'endereco'     => Parametro::get('orgao_endereco'),
            'telefone'     => Parametro::get('orgao_telefone'),
            'cnpj'         => Parametro::get('orgao_cnpj'),
            'selo'         => Parametro::get('impressao_selo'),
        ];
    }

    /**
     * Brasão do município. Ausente, o cabeçalho fecha sem ele — travar a
     * emissão de um auto porque falta uma imagem seria pior do que emiti-lo
     * sem o símbolo.
     *
     * O dompdf não segue URL: recebe o caminho do arquivo no disco.
     */
    private function brasao(bool $paraPdf): ?string
    {
        foreach (['img/brasao-prefeitura.png', 'img/brasao.png'] as $relativo) {
            $caminho = public_path($relativo);
            if (is_file($caminho)) {
                return $paraPdf ? $caminho : '/' . $relativo;
            }
        }

        return null;
    }

    /**
     * Identificação do imóvel — o que substitui, em obras, o "Local da
     * Infração" do POSTURAS. Aqui o imóvel não é um endereço solto: é lote
     * cadastrado, com inscrição imobiliária e área vinda do GIS.
     */
    private function imovel(Documento $doc): array
    {
        $l = $doc->lote;

        return [
            'inscricao' => $l?->inscricao_imobiliaria,
            'bairro'    => $l?->bairro,
            'quadra'    => $l?->quadra,
            'lote'      => $l?->numero_lote,
            'endereco'  => $doc->endereco,
            'areaGis'   => $l?->area_gis_m2,
        ];
    }

    /** "DIRETA", ou o documento que originou este. */
    private function origem(Documento $doc): string
    {
        if (! $doc->origem) {
            return 'DIRETA';
        }

        return mb_strtoupper($doc->origem->rotuloTipo()) . ' Nº ' . $doc->origem->numeroFormatado();
    }

    /**
     * Memória de cálculo da multa, linha a linha.
     *
     * É a diferença central entre obras e posturas: lá a multa quase sempre é
     * valor fixo em UPF; aqui a maioria é por metro quadrado de terreno ou de
     * construção. Um auto que traz só o total não é defensável — o autuado
     * precisa poder conferir a conta, e o piso/teto aplicado precisa aparecer
     * como aplicado, não dissolvido dentro do resultado.
     *
     * @return array<string,mixed>
     */
    private function memoria(Documento $doc): array
    {
        $linhas = [];

        foreach ($doc->artigos as $a) {
            $base = match ($a->base_multa) {
                'fixa'            => 'Valor fixo',
                'sem_multa'       => 'Sem multa',
                'area_terreno'    => 'Por área do terreno',
                'area_construida' => 'Por área construída',
                default           => (string) $a->base_multa,
            };

            $conta = match ($a->base_multa) {
                'sem_multa' => '—',
                'fixa'      => $this->num($a->multa_upf) . ' UPF',
                default     => $a->area_m2
                    ? $this->num($a->multa_upf_m2, 4) . ' UPF/m² × ' . $this->num($a->area_m2) . ' m²'
                    : $this->num($a->multa_upf_m2, 4) . ' UPF/m² (área não informada)',
            };

            // Piso e teto: quando o cálculo bruto difere do valor gravado, foi
            // o limite da lei que decidiu — e isso tem de sair impresso.
            $bruto = in_array($a->base_multa, ['area_terreno', 'area_construida'], true) && $a->area_m2
                ? (float) $a->multa_upf_m2 * (float) $a->area_m2
                : null;

            $limite = null;
            if ($bruto !== null && $a->valor_upf !== null && abs($bruto - (float) $a->valor_upf) > 0.005) {
                $limite = $bruto > (float) $a->valor_upf ? 'teto da lei aplicado' : 'piso da lei aplicado';
            }

            $linhas[] = [
                'numero'  => $a->numero,
                'conduta' => $a->conduta,
                'sancao'  => $a->sancao,
                'base'    => $base,
                'conta'   => $conta,
                'limite'  => $limite,
                'valor'   => $a->valor_upf,
            ];
        }

        return [
            'linhas'  => $linhas,
            'total'   => $doc->valor_upf,
            'upf'     => $doc->upf_valor,
            'emReais' => $doc->valor_upf && $doc->upf_valor ? $doc->valor_upf * $doc->upf_valor : null,
        ];
    }

    /**
     * Anexos do documento — as evidências da vistoria que o originou.
     *
     * Obras não tem anexo próprio do documento: a prova é fotografada na
     * vistoria, e é ela que instrui o auto. Trazer aqui a evidência da
     * vistoria vinculada é o que faz a via impressa valer como peça completa.
     *
     * @return array<int,array<string,mixed>>
     */
    private function anexos(Documento $doc, bool $paraPdf): array
    {
        $evidencias = $doc->vistoria?->evidencias ?? collect();

        return $evidencias
            ->map(function (Evidencia $e) use ($paraPdf) {
                $ehFoto = str_starts_with((string) $e->mime, 'image/');

                return [
                    'foto'      => $ehFoto,
                    'titulo'    => $e->titulo ?: $e->nome_original,
                    'descricao' => $e->descricao,
                    'dataHora'  => $e->data_hora?->format('d/m/Y H:i'),
                    'src'       => $ehFoto ? $this->fonteImagem($e, $paraPdf) : null,
                ];
            })
            ->filter(fn ($a) => ! $a['foto'] || $a['src'])
            ->values()
            ->all();
    }

    /**
     * Caminho da foto para a view. O dompdf lê do disco; o navegador busca
     * pela rota autenticada, mais leve do que embutir a imagem inteira dentro
     * do HTML.
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

    /** Marca d'água — rascunho ainda não vale, anulado deixou de valer. */
    private function marca(Documento $doc): ?string
    {
        return match ($doc->status) {
            'rascunho'             => 'RASCUNHO',
            'anulado', 'cancelado' => 'ANULADO',
            default                => null,
        };
    }

    /** Rótulo e data do prazo, já resolvidos por tipo de documento. */
    private function prazo(Documento $doc): ?array
    {
        if ($doc->defesa_ate) {
            return [
                'rotulo' => 'Prazo de defesa',
                'data'   => $doc->defesa_ate->format('d/m/Y'),
                'nota'   => 'Dias úteis, contados da data da lavratura.',
            ];
        }

        if ($doc->prazo_ate) {
            return [
                'rotulo' => 'Prazo para cumprimento',
                'data'   => $doc->prazo_ate->format('d/m/Y'),
                'nota'   => $doc->prazo_dias === 0
                    ? 'Cumprimento imediato.'
                    : $doc->prazo_dias . ' dias corridos.',
            ];
        }

        return null;
    }

    private function num(?float $v, int $casas = 2): string
    {
        return number_format((float) $v, $casas, ',', '.');
    }
}
