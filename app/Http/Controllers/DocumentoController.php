<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\Legislacao;
use App\Models\Lote;
use App\Models\Parametro;
use App\Models\Vistoria;
use App\Services\DocumentoImpressao;
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
            // "vistoria" não é um tipo de documento — é o recorte que mostra
            // só os atos de campo. Entra aqui porque, para quem usa, os dois
            // estão na mesma lista e o filtro é um só.
            'tipo'   => ['nullable', Rule::in([...array_keys(Documento::TIPOS), 'vistoria'])],
            'status' => ['nullable', Rule::in(['rascunho', 'lavrado', 'atendido', 'anulado', 'cancelado'])],
            'agente' => ['nullable', 'in:eu,todos'],
            'busca'  => ['nullable', 'string', 'max:80'],
        ]);

        $q = Documento::query()
            ->with(['lote:id,bairro,quadra,numero_lote', 'agente:id,name', 'legislacao:id,numero,nome'])
            ->withCount('artigos')
            ->latest('created_at');

        if (! empty($d['tipo']) && $d['tipo'] !== 'vistoria') { $q->where('tipo', $d['tipo']); }
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

        $usuario = $request->user();

        // Filtro de TIPO específico de documento, ou de STATUS de documento,
        // exclui os documentos? Não: exclui as VISTORIAS. "Lavrado" e
        // "anulado" são estados de peça, e vistoria não os tem — mostrá-la sob
        // esse filtro seria dizer que ela está num estado que não existe.
        $soVistorias = ($d['tipo'] ?? '') === 'vistoria';
        $documentos = $soVistorias ? collect() : $q->limit(300)->get();

        $itens = $documentos->map(function (Documento $doc) use ($usuario) {
            [$stTxt, $stCls] = $doc->statusBadge();
            $prazo = $doc->situacaoPrazo();

            return [
                'id'          => $doc->id,
                'tipo'        => $doc->tipo,
                'tipo_rotulo' => $doc->rotuloTipo(),
                'numero'      => $doc->numeroFormatado(),
                'data'        => ($doc->data_lavratura ?? $doc->created_at)?->format('d/m/Y'),
                // `valor` além do texto: o cartão da lista pinta a barra
                // lateral pelo status, e comparar rótulo traduzido para
                // decidir cor é o tipo de coisa que quebra ao mudar uma
                // palavra na tela.
                'status'      => ['valor' => $doc->status, 'texto' => $stTxt, 'classe' => $stCls],
                'prazo'       => $prazo ? ['texto' => $prazo[0], 'classe' => $prazo[1]] : null,
                'imovel'      => $doc->lote
                    ? sprintf('Quadra %s · Lote %s — %s', $doc->lote->quadra ?? '—', $doc->lote->numero_lote ?? '—', $doc->lote->bairro)
                    : '—',
                'autuado'     => $doc->autuado_nome ?: '—',
                'lei'         => $doc->legislacao?->rotulo() ?: '—',
                'artigos'     => $doc->artigos_count,
                'valor_upf'   => $doc->valor_upf,
                // O cartão da lista também tem menu de opções, como no
                // AppPOSTURAS: imprimir ou anular sem precisar abrir a ficha.
                'opcoes'      => $doc->opcoesPara($usuario),
                // De que registro esta linha veio. A lista mistura peças e
                // atos de campo, e a tela precisa saber qual janela abrir.
                'registro'    => 'documento',
                '_ordem'      => ($doc->data_lavratura ?? $doc->created_at)?->format('Y-m-d H:i:s') ?? '',
            ];
        });

        $itens = $itens
            ->concat(empty($d['status']) ? $this->vistoriasNaLista($request, $d) : collect())
            // Uma ordem só para os dois: quem abre a lista quer a última coisa
            // que aconteceu no topo, seja ela auto ou vistoria.
            ->sortByDesc('_ordem')
            ->values()
            ->map(function (array $i) { unset($i['_ordem']); return $i; });

        return response()->json(['documentos' => $itens, 'total' => $itens->count()]);
    }

    /**
     * As VISTORIAS na lista de documentos, no mesmo formato de linha.
     *
     * Elas aparecem ao lado das peças porque é a mesma pergunta — "o que foi
     * feito neste imóvel?" — e separá-las em duas telas obrigava a procurar
     * duas vezes. O que não se faz é forçá-las no molde da peça: vistoria não
     * tem AUTUADO (ninguém é autuado por uma visita) nem PRAZO de defesa, e
     * inventar um valor para preencher a coluna seria pior que o travessão.
     *
     * @param  array<string, mixed> $d filtros já validados
     * @return \Illuminate\Support\Collection<int, array>
     */
    private function vistoriasNaLista(Request $request, array $d)
    {
        $tipo = $d['tipo'] ?? '';
        if ($tipo !== '' && $tipo !== 'vistoria') { return collect(); }

        $q = Vistoria::query()
            ->with(['lote:id,bairro,quadra,numero_lote', 'fiscal:id,name'])
            ->withCount('artigos')
            ->latest('data_hora');

        if (($d['agente'] ?? 'eu') === 'eu') { $q->where('fiscal_id', $request->user()->id); }

        if ($texto = $d['busca'] ?? null) {
            $q->whereHas('lote', fn ($l) => $l
                ->where('quadra', 'like', "%{$texto}%")
                ->orWhere('numero_lote', 'like', "%{$texto}%")
                ->orWhere('bairro', 'like', "%{$texto}%"));
        }

        return $q->limit(300)->get()->map(fn (Vistoria $v) => [
            'id'          => $v->id,
            'registro'    => 'vistoria',
            'tipo'        => 'vistoria',
            'tipo_rotulo' => $v->finalidadeRotulo(),
            'numero'      => $v->numeroFormatado(),
            'data'        => $v->data_hora?->format('d/m/Y'),
            'status'      => [
                'valor'  => $v->situacao,
                'texto'  => $v->situacaoRotulo(),
                'classe' => $v->situacaoBadge(),
            ],
            'prazo'       => null,
            'imovel'      => $v->lote
                ? sprintf('Quadra %s · Lote %s — %s', $v->lote->quadra ?? '—', $v->lote->numero_lote ?? '—', $v->lote->bairro)
                : '—',
            'autuado'     => '—',
            'lei'         => '—',
            'artigos'     => $v->artigos_count,
            'valor_upf'   => null,
            'fiscal'      => $v->fiscal?->name,
            'opcoes'      => [],
            '_ordem'      => $v->data_hora?->format('Y-m-d H:i:s') ?? '',
        ]);
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
                // O número vai para a tela do formulário: é ele que deixa o
                // fiscal ver A QUAL vistoria a peça se prendeu, antes de
                // gravar. Vínculo errado descoberto na defesa é tarde demais.
                'numero'    => $vistoria->numeroFormatado(),
                'data_hora' => $vistoria->data_hora?->format('d/m/Y H:i'),
                'lote_id'   => $vistoria->lote_id,
                // A área medida em campo é o número que fecha a conta da multa
                // por metro quadrado. Sem ela aqui, Artigo::calcularMulta()
                // devolve "Área não informada" e o auto sai sem valor — que
                // era exatamente o que acontecia antes desta linha existir.
                'area_construida_m2' => $vistoria->area_construida_aferida_m2,
                'area_rotulo'        => $vistoria->areaAferidaRotulo(),
            ],
            // As providências já escritas em campo, na ordem em que o fiscal as
            // ditou. A peça nasce com a lista pronta, em vez de alguém
            // reescrevê-la de memória dias depois.
            'exigencias' => $vistoria->exigencias->map(fn ($e) => [
                'texto' => $e->texto, 'prazo_dias' => $e->prazo_dias, 'rotulo' => $e->rotulo(),
            ]),
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
    /**
     * POST /api/documentos — mesma criação, SEM imóvel definido.
     *
     * O fiscal abre a peça com o que tem em campo e amarra o imóvel depois,
     * pela aba Imóvel. A obrigatoriedade não sumiu: mudou de lugar, para a
     * lavratura (ver LavraturaService::lavrar). Exigi-la aqui obrigava a
     * passar pelo mapa — que é o caminho pago — só para começar a escrever.
     */
    public function storeSemLote(Request $request): JsonResponse
    {
        return $this->store($request, null);
    }

    public function store(Request $request, ?Lote $lote = null): JsonResponse
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
            'lote_id'       => $lote?->id,
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
            'area_terreno_m2'    => $d['area_terreno_m2'] ?? $lote?->area_gis_m2 ?? null,
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
     * GET /api/documentos/{documento} — ficha completa, para o modal.
     *
     * A lista traz só o que cabe no cartão. Antes desta rota, clicar num
     * documento abria o PDF direto: não havia onde ver o conteúdo na tela nem
     * onde pendurar o menu de opções. É a ficha do AppPOSTURAS.
     */
    public function ficha(Request $request, Documento $documento): JsonResponse
    {
        $documento->load(['lote', 'legislacao', 'agente', 'artigos', 'origem', 'anuladoPor', 'vistoria.evidencias']);

        [$stTxt, $stCls] = $documento->statusBadge();
        $prazo = $documento->situacaoPrazo();

        return response()->json([
            'id'          => $documento->id,
            'tipo'        => $documento->tipo,
            'tipo_rotulo' => $documento->rotuloTipo(),
            'numero'      => $documento->numeroFormatado(),
            'status'      => ['valor' => $documento->status, 'texto' => $stTxt, 'classe' => $stCls],
            'prazo_badge' => $prazo ? ['texto' => $prazo[0], 'classe' => $prazo[1]] : null,

            'data_fato'      => $documento->data_fato?->format('d/m/Y H:i'),
            'data_lavratura' => $documento->data_lavratura?->format('d/m/Y H:i'),
            'criado_em'      => $documento->created_at?->format('d/m/Y H:i'),
            'agente'         => $documento->agente?->name,
            'matricula'      => $documento->agente?->matricula,
            'origem'         => $documento->origem?->numeroFormatado(),

            'imovel' => [
                'inscricao' => $documento->lote?->inscricao_imobiliaria,
                'bairro'    => $documento->lote?->bairro,
                'quadra'    => $documento->lote?->quadra,
                'lote'      => $documento->lote?->numero_lote,
                'endereco'  => $documento->endereco,
                'terreno'   => $documento->area_terreno_m2,
                'construida'=> $documento->area_construida_m2,
            ],

            'autuado'   => ['nome' => $documento->autuado_nome, 'documento' => $documento->autuado_documento],
            'descricao' => $documento->descricao,
            'observacoes' => $documento->observacoes,

            'lei'     => $documento->legislacao?->rotulo(),
            'artigos' => $documento->artigos->map(fn ($a) => [
                'numero'  => $a->numero,
                'conduta' => $a->conduta,
                'sancao'  => $a->sancao,
                'base'    => $a->base_multa,
                'calculo' => $a->base_multa === 'fixa'
                    ? $a->multa_upf . ' UPF (fixo)'
                    : ($a->base_multa === 'sem_multa'
                        ? 'sem multa'
                        : $a->multa_upf_m2 . ' UPF/m²' . ($a->area_m2 ? ' × ' . $a->area_m2 . ' m²' : '')),
                'valor'   => $a->valor_upf,
            ]),

            'valor_upf' => $documento->valor_upf,
            'upf_valor' => $documento->upf_valor,
            'valor_reais' => $documento->valor_upf && $documento->upf_valor
                ? $documento->valor_upf * $documento->upf_valor
                : null,

            'prazo_ate'  => $documento->prazo_ate?->format('d/m/Y'),
            'defesa_ate' => $documento->defesa_ate?->format('d/m/Y'),

            'anexos' => $documento->vistoria?->evidencias->count() ?? 0,

            'anulacao' => $documento->anulado_em ? [
                'em'     => $documento->anulado_em->format('d/m/Y H:i'),
                'por'    => $documento->anuladoPor?->name,
                'motivo' => $documento->anulacao_motivo,
            ] : null,

            // As opções vêm do servidor, não do JavaScript: é o servidor que
            // recusa a ação de verdade, e um menu que oferece o que a regra
            // depois nega é pior do que um menu curto.
            'opcoes' => $documento->opcoesPara($request->user()),
        ]);
    }

    /**
     * POST /api/documentos/{documento}/anular — cancela um documento lavrado.
     *
     * Não apaga: um auto anulado continua sendo peça do processo. Ele passa a
     * sair impresso com a marca "ANULADO", e o motivo fica registrado com o
     * nome de quem anulou — anulação sem motivo declarado não é ato, é sumiço.
     */
    public function anular(Request $request, Documento $documento): JsonResponse
    {
        $d = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'motivo.required' => 'Informe o motivo da anulação.',
            'motivo.min'      => 'Descreva o motivo com pelo menos 10 caracteres.',
        ]);

        if (! in_array('anular', $documento->opcoesPara($request->user()), true)) {
            return response()->json(['message' => 'Este documento não pode ser anulado por você.'], 403);
        }

        $documento->update([
            'status'          => 'anulado',
            'anulado_em'      => now(),
            'anulado_por'     => $request->user()->id,
            'anulacao_motivo' => $d['motivo'],
        ]);

        return response()->json(['message' => 'Documento anulado.']);
    }

    /**
     * PATCH /api/documentos/{documento} — altera um rascunho.
     *
     * Só rascunho, e só do autor. Documento lavrado é peça de processo: seu
     * conteúdo não muda depois de assinado — para desfazê-lo existe a
     * anulação, que deixa rastro de quem, quando e por quê.
     */
    public function update(Request $request, Documento $documento): JsonResponse
    {
        if ($documento->status !== 'rascunho') {
            return response()->json(['message' => 'Documento lavrado não pode ser alterado. Use a anulação.'], 422);
        }
        if ($documento->agente_id !== $request->user()->id) {
            return response()->json(['message' => 'Só o autor pode alterar o próprio rascunho.'], 403);
        }

        $d = $request->validate([
            'tipo'               => ['required', Rule::in(array_keys(Documento::TIPOS))],
            'data_fato'          => ['required', 'date'],
            // O imóvel pode entrar aqui, depois da criação — é o caminho de
            // quem abriu a peça em campo sem tê-lo identificado ainda.
            'lote_id'            => ['nullable', 'exists:lotes,id'],
            'legislacao_id'      => ['nullable', 'exists:legislacoes,id'],
            'origem_id'          => ['nullable', 'exists:documentos,id'],
            'autuado_nome'       => ['nullable', 'string', 'max:160'],
            'autuado_documento'  => ['nullable', 'string', 'max:20'],
            'endereco'           => ['nullable', 'string', 'max:200'],
            'descricao'          => ['nullable', 'string', 'max:5000'],
            'observacoes'        => ['nullable', 'string', 'max:5000'],
            'prazo_dias'         => ['nullable', 'integer', 'min:0', 'max:365'],
            'area_terreno_m2'    => ['nullable', 'numeric', 'min:0'],
            'area_construida_m2' => ['nullable', 'numeric', 'min:0'],
            'artigos'            => ['nullable', 'array'],
            'artigos.*'          => ['integer', 'exists:artigos,id'],
        ]);

        $documento->update(collect($d)->except('artigos')->all());

        // Os artigos são refixados por inteiro: manter os antigos e somar os
        // novos deixaria no documento um enquadramento que o fiscal removeu
        // da tela e acredita ter tirado.
        $documento->artigos()->delete();
        if (! empty($d['artigos'])) {
            $this->lavratura->fixarArtigos($documento, $d['artigos']);
        }

        return response()->json(['message' => 'Rascunho atualizado.']);
    }

    /**
     * DELETE /api/documentos/{documento} — descarta um rascunho.
     *
     * Só rascunho, e só do próprio autor. Documento lavrado nunca é excluído:
     * para desfazê-lo existe a anulação, que deixa rastro.
     */
    public function destroy(Request $request, Documento $documento): JsonResponse
    {
        if (! in_array('excluir', $documento->opcoesPara($request->user()), true)) {
            return response()->json(['message' => 'Só o autor pode excluir o próprio rascunho.'], 403);
        }

        $documento->artigos()->delete();
        $documento->delete();

        return response()->json(['message' => 'Rascunho excluído.']);
    }

    /**
     * GET /documentos/{documento}/pdf — PDF do documento, no layout oficial.
     *
     * Fora do prefixo /api de propósito, como a rota de evidência: devolve um
     * arquivo, não JSON, e o navegador precisa poder abri-la direto numa aba
     * (o front usa window.open, não fetch).
     */
    public function pdf(Request $request, Documento $documento, DocumentoImpressao $impressao): Response
    {
        $dados = $impressao->montar(
            $documento,
            paraPdf: true,
            comAnexos: $request->boolean('anexos', true),
        );

        $pdf = Pdf::loadView('impressao.a4', $dados + ['navegador' => false])->setPaper('a4');

        // O nome do arquivo não aceita "/" — e numeroFormatado() tem um
        // ("AI 2026/0002"), por ser o formato natural de citar o documento.
        $nomeArquivo = str_replace('/', '-', $documento->numeroFormatado()) . '.pdf';

        // Inline (não "attachment"): abre na aba, como qualquer visualizador
        // de PDF do navegador — o fiscal só baixa se quiser, via o próprio Chrome.
        return $pdf->stream($nomeArquivo);
    }

    /**
     * GET /documentos/{documento}/impressao?formato=a4|termica&anexos=0|1
     *
     * Página HTML que se manda para a impressora sozinha. Existe ao lado do
     * PDF porque a bobina térmica de 80mm tem altura variável (`size:80mm
     * auto`) e o dompdf só trabalha com página de altura fixa — a via que o
     * fiscal entrega em campo não sairia certa por lá.
     */
    public function impressao(Request $request, Documento $documento, DocumentoImpressao $impressao): Response
    {
        $d = $request->validate([
            'formato' => ['nullable', Rule::in(['a4', 'termica'])],
        ]);

        $formato = $d['formato'] ?? 'a4';

        $dados = $impressao->montar(
            $documento,
            paraPdf: false,
            comAnexos: $request->boolean('anexos', true),
        );

        return response()->view('impressao.' . $formato, $dados + ['navegador' => true]);
    }
}
