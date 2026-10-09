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
    /** Usa somente o endereço guardado na peça, sem confundi-lo com o imóvel. */
    public static function enderecoDestinatario(Documento $doc): string
    {
        $endereco = trim((string) $doc->autuado_endereco);
        if ($endereco === '') {
            $p = array_map(fn ($v) => trim((string) $v), $doc->autuado_endereco_partes ?? []);
            $preenchido = fn ($v) => $v !== null && $v !== '';
            $rua = implode(', ', array_filter([$p['logradouro'] ?? null, $p['numero'] ?? null], $preenchido));
            $cidade = implode('/', array_filter([$p['cidade'] ?? null, $p['uf'] ?? null], $preenchido));
            $endereco = implode(' — ', array_filter([$rua, $p['bairro'] ?? null, $cidade], $preenchido));
        }

        return trim(preg_replace('/(?:[,;\s]|—|-)*CEP\s*:?\s*\d{5}-?\d{3}/iu', '', $endereco)) ?: 'Não informado';
    }

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
        $doc->loadMissing(['lote', 'legislacao', 'agente', 'artigos', 'origem', 'origemOs', 'vistoria.evidencias']);

        $prazoDias = in_array($doc->tipo, Documento::COM_CUMPRIMENTO, true) ? $doc->prazo_dias : null;

        return [
            'doc'    => $doc,
            'titulo' => mb_strtoupper($doc->rotuloTipo()),
            'orgao'  => $this->orgao(),
            'rodape' => app(CabecalhoOficial::class)->rodape(),
            'brasao'      => $this->brasao($paraPdf),
            'imovel'      => $this->imovel($doc),
            'origemTexto' => $this->origem($doc),
            // Já em HTML seguro: escapado, com o **negrito** virando <strong>.
            'ciencia'     => self::negrito($doc->legislacao?->ciencia($doc->tipo, $prazoDias, $doc->origem)),
            'memoria'     => $this->memoria($doc),
            'anexos'      => $comAnexos ? $this->anexos($doc, $paraPdf) : [],
            'termoRecusa' => Parametro::get('termo_recusa'),
            'marca'       => $this->marca($doc),
            'prazo'       => $this->prazo($doc),
        ];
    }

    /**
     * Cabeçalho institucional — agora em CabecalhoOficial, porque a ordem de
     * serviço imprime o mesmo. Duas cópias envelheceriam separadas: mudar o
     * nome da secretaria em Parâmetros corrigiria um papel e não o outro.
     */
    private function orgao(): array
    {
        return app(CabecalhoOficial::class)->orgao();
    }

    private function brasao(bool $paraPdf): ?string
    {
        return app(CabecalhoOficial::class)->brasao($paraPdf);
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
            // A informada, a montada agora ou a última gravada (Lote::inscricao):
            // a coluna crua sai vazia em quase todo lote vindo do desenho.
            // O que a peça guarda (do cadastro municipal ou digitado) vem antes.
            'inscricao' => $doc->imovel_inscricao ?: ($l?->inscricaoFormatada() ?? $l?->inscricao_imobiliaria),
            'bairro'    => $doc->imovel_bairro ?: $l?->bairro,
            'quadra'    => $doc->imovel_quadra ?? $l?->quadra,
            'lote'      => $doc->imovel_lote ?? $l?->numero_lote,
            'endereco'  => $doc->endereco,
            'areaGis'   => $l?->area_gis_m2,
        ];
    }

    /** "DIRETA", ou o documento que originou este. */
    private function origem(Documento $doc): string
    {
        // A regra é do documento: peça anterior (autos) ou o motivo da
        // notificação — direta, ordem de serviço, ouvidoria.
        return $doc->origemTexto();
    }

    /**
     * **texto** vira negrito na impressão — e só isso. O texto é ESCAPADO
     * antes, então nada que o administrador digite em Parâmetros vira marcação:
     * as vistas imprimem o resultado sem escapar de novo ({!! !!}).
     */
    public static function negrito(?string $texto): ?string
    {
        if ($texto === null || $texto === '') {
            return null;
        }

        return nl2br(preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', e($texto)));
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
            // Peça gravada depois das formas novas de multa traz a memória
            // CONGELADA: é ela que sai, sem refazer conta nenhuma aqui.
            if ($a->memoria !== null && $a->memoria !== '') {
                $linhas[] = [
                    'numero'  => $a->numero,
                    'conduta' => $a->conduta,
                    'sancao'  => $a->sancao,
                    'base'    => \App\Models\Artigo::BASES_MULTA[$a->base_multa] ?? (string) $a->base_multa,
                    'conta'   => $a->base_multa === 'sem_multa' ? '—' : $a->memoria,
                    'limite'  => null,
                    'valor'   => $a->valor_upf,
                ];
                continue;
            }

            $base = match ($a->base_multa) {
                'fixa'            => 'Valor fixo',
                'sem_multa'       => 'Sem multa',
                'por_m2'          => $a->multa_area === 'terreno' ? 'Por área do terreno' : 'Por área construída',
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
            $bruto = $a->base_multa === 'por_m2' && $a->area_m2
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
        // PEÇA COM ANEXOS PRÓPRIOS: saem os que o fiscal marcou, na ordem que
        // ele deu — fotos e PDFs juntados na peça, e o que ele TROUXE da
        // vistoria ou da peça de origem. A peça antiga (sem a marca) continua
        // imprimindo as fotos da vistoria vinculada, como sempre imprimiu.
        if ($doc->anexos_proprios) {
            return $doc->anexos()->where('imprime', true)->get()
                ->map(function (\App\Models\DocumentoAnexo $a) use ($paraPdf) {
                    $ehFoto = $a->ehFoto();

                    return [
                        'foto'      => $ehFoto,
                        'titulo'    => $a->titulo ?: $a->nome_original,
                        'descricao' => trim(($ehFoto ? '' : 'Arquivo PDF, juntado aos autos. ')
                            . ($a->juntado_depois ? 'Juntado depois da lavratura, em ' . $a->created_at?->format('d/m/Y H:i') . '.' : '')) ?: null,
                        'dataHora'  => ($a->data_hora ?? $a->created_at)?->format('d/m/Y H:i'),
                        'src'       => $ehFoto ? $this->fonteDoArquivo($a->arquivo, $a->mime, route('documento.anexo.arquivo', $a), $paraPdf) : null,
                    ];
                })
                ->filter(fn ($a) => ! $a['foto'] || $a['src'])
                ->values()
                ->all();
        }

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

    /** A imagem para a view: endereço no navegador, conteúdo embutido no PDF. */
    private function fonteDoArquivo(string $arquivo, ?string $mime, string $url, bool $paraPdf): ?string
    {
        if (! $paraPdf) {
            return $url;
        }
        $disco = Storage::disk('private');

        return $disco->exists($arquivo)
            ? 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode($disco->get($arquivo))
            : null;
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
