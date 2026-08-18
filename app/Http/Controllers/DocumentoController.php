<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\Lote;
use App\Models\Parametro;
use App\Models\Vistoria;
use App\Services\LavraturaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use RuntimeException;

class DocumentoController extends Controller
{
    public function __construct(private LavraturaService $lavratura) {}

    /**
     * GET /api/documentos — lista filtrada.
     *
     * Os filtros são os da aba Documentos: tipo, status, agente e busca.
     * O padrão de agente é "meus documentos", como no AppPOSTURAS: o fiscal
     * abre a tela para ver o próprio trabalho, não o da equipe inteira.
     */
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo'   => ['nullable', Rule::in(array_keys(Documento::TIPOS))],
            'status' => ['nullable', Rule::in(['rascunho', 'lavrado', 'atendido', 'anulado', 'cancelado'])],
            'agente' => ['nullable', 'in:eu,todos'],
            'busca'  => ['nullable', 'string', 'max:80'],
        ]);

        $q = Documento::query()
            ->with(['lote:id,bairro,quadra,numero_lote', 'agente:id,name', 'legislacao:id,numero,nome'])
            ->withCount('artigos')
            ->latest('created_at');

        if (! empty($d['tipo']))   { $q->where('tipo', $d['tipo']); }
        if (! empty($d['status'])) { $q->where('status', $d['status']); }
        if (($d['agente'] ?? 'eu') === 'eu') { $q->where('agente_id', $request->user()->id); }

        if ($texto = $d['busca'] ?? null) {
            $q->where(function ($s) use ($texto) {
                $s->where('autuado_nome', 'like', "%{$texto}%")
                  ->orWhere('numero', 'like', "%{$texto}%")
                  ->orWhereHas('lote', fn ($l) => $l
                      ->where('quadra', 'like', "%{$texto}%")
                      ->orWhere('numero_lote', 'like', "%{$texto}%")
                      ->orWhere('bairro', 'like', "%{$texto}%"));
            });
        }

        $itens = $q->limit(300)->get()->map(function (Documento $doc) {
            [$stTxt, $stCls] = $doc->statusBadge();
            $prazo = $doc->situacaoPrazo();

            return [
                'id'          => $doc->id,
                'tipo'        => $doc->tipo,
                'tipo_rotulo' => $doc->rotuloTipo(),
                'numero'      => $doc->numeroFormatado(),
                'data'        => ($doc->data_lavratura ?? $doc->created_at)?->format('d/m/Y'),
                'status'      => ['texto' => $stTxt, 'classe' => $stCls],
                'prazo'       => $prazo ? ['texto' => $prazo[0], 'classe' => $prazo[1]] : null,
                'imovel'      => $doc->lote
                    ? sprintf('Quadra %s · Lote %s — %s', $doc->lote->quadra ?? '—', $doc->lote->numero_lote ?? '—', $doc->lote->bairro)
                    : '—',
                'autuado'     => $doc->autuado_nome ?: '—',
                'lei'         => $doc->legislacao?->rotulo() ?: '—',
                'artigos'     => $doc->artigos_count,
                'valor_upf'   => $doc->valor_upf,
            ];
        });

        return response()->json(['documentos' => $itens, 'total' => $itens->count()]);
    }

    /** GET /api/documentos/opcoes — dados para montar o formulário. */
    public function opcoes(): JsonResponse
    {
        return response()->json([
            'tipos' => collect(Documento::TIPOS)->map(fn ($v, $k) => [
                'valor'          => $k,
                'rotulo'         => $v[0],
                'sigla'          => $v[1],
                'exige_artigos'  => ! in_array($k, Documento::SEM_SANCAO, true),
                'prazo'          => match (true) {
                    in_array($k, Documento::COM_DEFESA, true)      => 'defesa',
                    in_array($k, Documento::COM_CUMPRIMENTO, true) => 'cumprimento',
                    default                                        => null,
                },
            ])->values(),
            'leis' => Legislacao::ativas()->with(['artigos' => fn ($q) => $q->ativos()])->get()
                ->map(fn (Legislacao $l) => [
                    'id'                 => $l->id,
                    'rotulo'             => $l->rotulo(),
                    'prazo_defesa_dias'  => $l->prazo_defesa_dias,
                    'prazo_cumpr_dias'   => $l->prazo_cumprimento_dias,
                    'artigos'            => $l->artigos->map(fn ($a) => [
                        'id' => $a->id, 'numero' => $a->numero, 'rotulo' => $a->rotulo(),
                        'conduta' => $a->conduta,
                        'base_multa'    => $a->base_multa,
                        'multa_upf'     => $a->multa_upf,
                        'multa_upf_m2'  => $a->multa_upf_m2,
                        'multa_min_upf' => $a->multa_min_upf,
                        'multa_max_upf' => $a->multa_max_upf,
                    ]),
                ]),
        ]);
    }

    /**
     * GET /api/vistorias/{vistoria}/sugestao
     *
     * Devolve os artigos que enquadram as irregularidades daquela vistoria —
     * o passo que dispensa o fiscal de procurar dispositivo na lei impressa.
     */
    public function sugestao(Vistoria $vistoria): JsonResponse
    {
        $artigos = $this->lavratura->artigosSugeridos($vistoria->id);

        return response()->json([
            'vistoria' => [
                'id'        => $vistoria->id,
                'data_hora' => $vistoria->data_hora?->format('d/m/Y H:i'),
                'lote_id'   => $vistoria->lote_id,
            ],
            'irregularidades' => $vistoria->irregularidades()->get(['irregularidades.id', 'codigo', 'descricao']),
            'artigos' => $artigos->map(fn ($a) => [
                'id' => $a->id, 'numero' => $a->numero, 'rotulo' => $a->rotulo(),
                'conduta' => $a->conduta, 'sancao' => $a->sancao,
                'multa_upf' => $a->multa_upf,
                'lei' => $a->legislacao?->rotulo(),
                'legislacao_id' => $a->legislacao_id,
            ]),
            // Sem artigo cadastrado, não há o que sugerir. Dizer isso é melhor
            // do que devolver lista vazia e deixar o fiscal achar que é bug.
            'aviso' => $artigos->isEmpty()
                ? 'Nenhum artigo vinculado às irregularidades desta vistoria. '
                  . 'Cadastre a fundamentação legal em Parâmetros > Legislação.'
                : null,
        ]);
    }

    /** POST /api/lotes/{lote}/documentos — cria como RASCUNHO, sem número. */
    public function store(Request $request, Lote $lote): JsonResponse
    {
        if (! $request->user()->podeLavrarDocumento()) {
            return response()->json([
                'message' => 'Só agente de fiscalização pode emitir documentos.',
            ], 403);
        }

        $d = $request->validate([
            'tipo'           => ['required', Rule::in(array_keys(Documento::TIPOS))],
            'vistoria_id'    => ['nullable', 'exists:vistorias,id'],
            'legislacao_id'  => ['nullable', 'exists:legislacoes,id'],
            'data_fato'      => ['nullable', 'date_format:Y-m-d\TH:i'],
            'prazo_dias'     => ['nullable', 'integer', 'min:0', 'max:365'],
            'autuado_nome'   => ['nullable', 'string', 'max:160'],
            'autuado_documento' => ['nullable', 'string', 'max:20'],
            'endereco'       => ['nullable', 'string', 'max:200'],
            'descricao'      => ['nullable', 'string', 'max:5000'],
            'observacoes'    => ['nullable', 'string', 'max:5000'],
            // Área do terreno vem do GIS e é só conferida; a construída tem
            // de ser medida em campo — sem ela, artigo "por m² construído"
            // não calcula multa (ver Artigo::calcularMulta).
            'area_terreno_m2'    => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'area_construida_m2' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'artigos'        => ['array'],
            'artigos.*'      => ['integer', 'exists:artigos,id'],
        ]);

        $doc = Documento::create([
            'tipo'          => $d['tipo'],
            'lote_id'       => $lote->id,
            'vistoria_id'   => $d['vistoria_id'] ?? null,
            'legislacao_id' => $d['legislacao_id'] ?? null,
            'agente_id'     => $request->user()->id,
            'status'        => 'rascunho',
            'data_fato'     => isset($d['data_fato']) ? str_replace('T', ' ', $d['data_fato']) . ':00' : now(),
            'prazo_dias'    => $d['prazo_dias'] ?? null,
            'autuado_nome'  => $d['autuado_nome'] ?? null,
            'autuado_documento' => $d['autuado_documento'] ?? null,
            'endereco'      => $d['endereco'] ?? null,
            'descricao'     => $d['descricao'] ?? null,
            'observacoes'   => $d['observacoes'] ?? null,
            'area_terreno_m2'    => $d['area_terreno_m2'] ?? $lote->area_gis_m2 ?? null,
            'area_construida_m2' => $d['area_construida_m2'] ?? null,
        ]);

        if (! empty($d['artigos'])) {
            $this->lavratura->fixarArtigos($doc, $d['artigos']);
        }

        return response()->json([
            'message'   => 'Rascunho criado.',
            'documento' => ['id' => $doc->id, 'numero' => $doc->numeroFormatado()],
        ], 201);
    }

    /** POST /api/documentos/{documento}/lavrar — atribui número e fecha. */
    public function lavrar(Request $request, Documento $documento): JsonResponse
    {
        if (! $request->user()->podeLavrarDocumento()) {
            return response()->json(['message' => 'Só agente de fiscalização pode lavrar.'], 403);
        }
        if ($documento->agente_id !== $request->user()->id) {
            return response()->json(['message' => 'Só o autor do rascunho pode lavrá-lo.'], 403);
        }

        try {
            $documento->loadMissing('legislacao');
            $doc = $this->lavratura->lavrar($documento);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'   => 'Documento lavrado sob o número ' . $doc->numeroFormatado() . '.',
            'documento' => [
                'id'         => $doc->id,
                'numero'     => $doc->numeroFormatado(),
                'prazo_ate'  => $doc->prazo_ate?->format('d/m/Y'),
                'defesa_ate' => $doc->defesa_ate?->format('d/m/Y'),
            ],
        ]);
    }

    /**
     * GET /documentos/{documento}/pdf — impressão do documento.
     *
     * Fora do prefixo /api de propósito, como a rota de evidência: devolve um
     * arquivo, não JSON, e o navegador precisa poder abri-la direto numa aba
     * (o front usa window.open, não fetch).
     */
    public function pdf(Documento $documento): Response
    {
        $documento->load(['lote', 'legislacao', 'agente', 'artigos']);

        $prazoDias = $documento->tipo === 'notificacao' ? $documento->prazo_dias : null;

        $pdf = Pdf::loadView('pdf.documento', [
            'doc'             => $documento,
            'orgaoNome'       => Parametro::get('orgao_nome'),
            'orgaoSecretaria' => Parametro::get('orgao_secretaria'),
            'orgaoEndereco'   => Parametro::get('orgao_endereco'),
            'orgaoTelefone'   => Parametro::get('orgao_telefone'),
            'textoCiencia'    => $documento->legislacao?->ciencia($documento->tipo, $prazoDias),
        ])->setPaper('a4');

        // O nome do arquivo não aceita "/" — e numeroFormatado() tem um
        // ("AI 2026/0002"), por ser o formato natural de citar o documento.
        $nomeArquivo = str_replace('/', '-', $documento->numeroFormatado()) . '.pdf';

        // Inline (não "attachment"): abre na aba, como qualquer visualizador
        // de PDF do navegador — o fiscal só baixa se quiser, via o próprio Chrome.
        return $pdf->stream($nomeArquivo);
    }
}
