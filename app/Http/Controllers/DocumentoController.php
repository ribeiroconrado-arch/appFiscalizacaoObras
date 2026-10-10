<?php

namespace App\Http\Controllers;

use App\Models\Artigo;
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
     * A cópia do cadastro municipal guardada na peça, num formato só.
     *
     * Peça lavrada antes de 04/10/2026 guardou só o terreno, solto; as de
     * depois guardam terreno, características e unidades. Quem lê recebe sempre
     * as três chaves, com os números como número.
     *
     * @param  array<string,mixed>|null  $retrato
     * @return array{imovel: array<string,mixed>, caracteristicas: list<array{chave:string, valor:?string}>, unidades: list<array<string,mixed>>}|null
     */
    private function retratoDoCadastro(?array $retrato): ?array
    {
        if (! $retrato) {
            return null;
        }
        $novo = array_key_exists('imovel', $retrato);
        $imovel = $novo ? ($retrato['imovel'] ?? []) : $retrato;
        $num = fn ($v) => $v === null || $v === '' ? null : (float) $v;
        foreach (['area_terreno_m2', 'area_edificada_m2', 'fracao_ideal', 'testada_m',
                  'medida_lado_direito', 'medida_lado_esquerdo', 'medida_fundo'] as $campo) {
            if (array_key_exists($campo, $imovel)) {
                $imovel[$campo] = $num($imovel[$campo]);
            }
        }

        return [
            'imovel' => $imovel,
            'caracteristicas' => collect($novo ? ($retrato['caracteristicas'] ?? []) : [])
                ->map(fn ($valor, $chave) => ['chave' => (string) $chave, 'valor' => $valor])->values()->all(),
            'unidades' => array_map(fn (array $u) => [
                'numero' => $u['numero'] ?? null,
                'ano'    => isset($u['ano_construcao']) ? (int) $u['ano_construcao'] : null,
                'area'   => $num($u['area_edificada_m2'] ?? null),
                'padrao' => ($u['padrao'] ?? null) ?: (($u['pontos'] ?? null) ? $u['pontos'] . ' pts' : null),
            ], $novo ? ($retrato['unidades'] ?? []) : []),
        ];
    }

    /** Os campos do formulário que viram as partes do endereço — não são colunas. */
    private const PARTES_DE_ENDERECO = ['autuado_logradouro', 'autuado_numero', 'autuado_bairro',
        'autuado_cidade', 'autuado_uf', 'imovel_logradouro', 'imovel_numero'];

    /**
     * O endereço do autuado e o do imóvel, como a peça os guarda: as PARTES (em
     * JSON, para o formulário reabrir cada campo no seu lugar) e o TEXTO ÚNICO
     * montado a partir delas — que é o que a impressão e as listas leem.
     *
     * Pedido sem parte nenhuma (cliente antigo, importação) mantém o texto
     * único que veio, sem inventar partes.
     *
     * @param  array<string,mixed>  $d  dados validados
     * @return array<string,mixed>
     */
    private function enderecosDaPeca(array $d): array
    {
        $limpo = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;
        $partes = fn (array $campos) => array_filter(
            array_map(fn ($c) => $limpo($d[$c] ?? null), $campos), fn ($v) => $v !== null);
        $rua = fn (array $p) => implode(', ', array_filter([$p['logradouro'] ?? null, $p['numero'] ?? null]));

        $a = $partes(['logradouro' => 'autuado_logradouro', 'numero' => 'autuado_numero', 'bairro' => 'autuado_bairro',
            'cidade' => 'autuado_cidade', 'uf' => 'autuado_uf']);
        if (isset($a['uf'])) { $a['uf'] = mb_strtoupper($a['uf']); }
        $i = $partes(['logradouro' => 'imovel_logradouro', 'numero' => 'imovel_numero']);

        $cidade = implode('/', array_filter([$a['cidade'] ?? null, $a['uf'] ?? null]));

        return [
            'autuado_endereco_partes' => $a ?: null,
            'autuado_endereco' => $a
                ? mb_substr(implode(' — ', array_filter([$rua($a), $a['bairro'] ?? null, $cidade])), 0, 300)
                : ($d['autuado_endereco'] ?? null),
            'imovel_endereco_partes' => $i ?: null,
            'endereco' => $i ? mb_substr($rua($i), 0, 200) : ($d['endereco'] ?? null),
        ];
    }

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
            'status' => ['nullable', Rule::in(['rascunho', 'gravado', 'lavrado', 'atendido', 'anulado', 'cancelado', 'defendido'])],
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
                // `rotuloCompleto` traz o bairro pelo NOME OFICIAL: peça e
                // lista de peça citam o bairro como o registro o chama.
                'imovel'      => $doc->lote?->rotuloCompleto() ?? '—',
                // As mesmas partes, separadas: a tabela da lista tem uma coluna
                // para o lote e outra para o bairro.
                'lote_curto'  => $this->loteCurto($doc->lote),
                'bairro'      => $doc->lote?->bairroOficial() ?? '—',
                'agente'      => $doc->agente?->name ?? '—',
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
    /** "Qd 35 · Lt 1" — o lote sem o bairro, que na tabela tem coluna própria. */
    private function loteCurto(?Lote $l): string
    {
        return $l ? sprintf('Qd %s · Lt %s', $l->quadra ?? '—', $l->numero_lote ?? '—') : '—';
    }

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
            'imovel'      => $v->lote?->rotuloCompleto() ?? '—',
            'lote_curto'  => $this->loteCurto($v->lote),
            'bairro'      => $v->lote?->bairroOficial() ?? '—',
            'agente'      => $v->fiscal?->name ?? '—',
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
                    'artigos'            => $l->artigos->sortBy(fn ($a) => $a->ordem())->values()->map(fn ($a) => [
                        'id' => $a->id, 'numero' => $a->numero, 'rotulo' => $a->rotulo(),
                        'conduta' => $a->conduta,
                        // Os termos de busca do artigo: o campo de artigo do
                        // documento também acha por eles.
                        'termos'  => $a->termos ?? [],
                        // É com isto que o formulário filtra a lista de artigos
                        // pelo tipo da peça (a regra é Artigo::serveA).
                        'documentos'             => $a->documentos ?: Artigo::DOCUMENTOS,
                        'prazo_notificacao_dias' => $a->prazo_notificacao_dias,
                        // Para o campo do valor que o fiscal informa (multa "de X a Y UPF").
                        'multa_min_upf'  => $a->multa_min_upf,
                        'multa_max_upf'  => $a->multa_max_upf,
                        'multa_dobra_reincidencia' => $a->multa_dobra_reincidencia,
                        // A tela NÃO calcula multa (pede a /api/multas/simular);
                        // isto é só para ela saber que campos mostrar.
                        'base_multa'     => $a->base_multa,
                        'multa_area'     => $a->multa_area,
                        'multa_mult_min' => $a->multa_mult_min,
                        'multa_mult_max' => $a->multa_mult_max,
                        'multa_rotulo'   => $a->rotuloMulta(),
                    ]),
                ]),
        ]);
    }

    /**
     * GET /api/vistorias/{vistoria}/sugestao
     *
     * Devolve os artigos CITADOS naquela vistoria — o passo que dispensa o
     * fiscal de procurar de novo, na mesa, o dispositivo que ele já achou em
     * campo.
     */
    public function sugestao(Request $request, Vistoria $vistoria): JsonResponse
    {
        // Com o tipo da peça, só vêm os artigos da vistoria que servem a ela:
        // o que não cabe embargo não entra numa peça de embargo, e vice-versa.
        $tipo = $request->query('tipo');
        $tipo = is_string($tipo) && isset(Documento::TIPOS[$tipo]) ? $tipo : null;
        $artigos = $this->lavratura->artigosSugeridos($vistoria->id, $tipo);

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
                ? 'Nenhum artigo citado nesta vistoria. Escolha a fundamentação abaixo.'
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
            'autuado_endereco'  => ['nullable', 'string', 'max:300'],
            'endereco'       => ['nullable', 'string', 'max:200'],
            // O endereço EM PARTES (o formulário). O texto único de cada um é
            // montado a partir delas — ver enderecosDaPeca().
            'autuado_logradouro' => ['nullable', 'string', 'max:160'],
            'autuado_numero'     => ['nullable', 'string', 'max:20'],
            'autuado_bairro'     => ['nullable', 'string', 'max:120'],
            'autuado_cidade'     => ['nullable', 'string', 'max:120'],
            'autuado_uf'         => ['nullable', 'string', 'size:2'],
            'imovel_logradouro'  => ['nullable', 'string', 'max:160'],
            'imovel_numero'      => ['nullable', 'string', 'max:20'],
            // A identificação do imóvel como está na peça: do cadastro
            // municipal, ou digitada quando o imóvel não está nele.
            'imovel_inscricao' => ['nullable', 'string', 'max:30'],
            'imovel_bairro'    => ['nullable', 'string', 'max:160'],
            'imovel_quadra'    => ['nullable', 'string', 'max:20'],
            'imovel_lote'      => ['nullable', 'string', 'max:20'],
            'descricao'      => ['nullable', 'string', 'max:5000'],
            'observacoes'    => ['nullable', 'string', 'max:5000'],
            // Área do terreno vem do GIS e é só conferida; a construída tem
            // de ser medida em campo — sem ela, artigo "por m² construído"
            // não calcula multa (ver Artigo::calcularMulta).
            'area_terreno_m2'    => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'area_construida_m2' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            ...self::REGRAS_DO_ALVARA,
            'artigos'        => ['array'],
            'artigos.*'      => ['integer', 'exists:artigos,id'],
        ]);

        if ($recusa = $this->recusarArtigosForaDoTipo($d['tipo'], $d['artigos'] ?? [])) {
            return $recusa;
        }
        if ($msg = $this->recusaDaOrigem($d['tipo'], $d['origem_id'] ?? null)) {
            return response()->json(['message' => $msg], 422);
        }
        $motivo = $this->motivoDeOrigem($d['tipo'], $d);
        if (is_string($motivo)) {
            return response()->json(['message' => $motivo], 422);
        }

        $doc = Documento::create([
            'tipo'          => $d['tipo'],
            'origem_id'     => $d['origem_id'] ?? null,
            ...$motivo,
            'lote_id'       => $lote?->id,
            // AUTO DE INFRAÇÃO NÃO SE VINCULA A VISTORIA: nasce de uma
            // notificação ou de um embargo (origem_id). O que vier é ignorado.
            'vistoria_id'   => $d['tipo'] === 'auto_infracao' ? null : ($d['vistoria_id'] ?? null),
            // Peça nova: os anexos são os que o fiscal escolher, e não mais
            // todas as fotos da vistoria (ver DocumentoImpressao::anexos).
            'anexos_proprios' => true,
            'legislacao_id' => $d['legislacao_id'] ?? null,
            'agente_id'     => $request->user()->id,
            'status'        => 'rascunho',
            'data_fato'     => isset($d['data_fato']) ? str_replace('T', ' ', $d['data_fato']) . ':00' : now(),
            'prazo_dias'    => $d['prazo_dias'] ?? null,
            'autuado_nome'  => $d['autuado_nome'] ?? null,
            'autuado_documento' => $d['autuado_documento'] ?? null,
            ...$this->enderecosDaPeca($d),
            'imovel_inscricao' => $d['imovel_inscricao'] ?? null,
            'imovel_bairro'    => $d['imovel_bairro'] ?? null,
            'imovel_quadra'    => $d['imovel_quadra'] ?? null,
            'imovel_lote'      => $d['imovel_lote'] ?? null,
            'descricao'     => $d['descricao'] ?? null,
            'observacoes'   => $d['observacoes'] ?? null,
            'area_terreno_m2'    => $d['area_terreno_m2'] ?? $lote?->area_gis_m2 ?? null,
            'area_construida_m2' => $d['area_construida_m2'] ?? null,
            'alvara_valor'       => $d['alvara_valor'] ?? null,
        ]);

        try {
            $this->lavratura->vincularReincidencia($doc, $d['reincidencia_de_id'] ?? null);
            $doc->save();
        } catch (RuntimeException $e) {
            $doc->delete();

            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! empty($d['artigos'])) {
            $this->lavratura->fixarArtigos($doc, $d['artigos'], $d['multiplicadores'] ?? []);
        }

        return response()->json([
            'message'   => 'Rascunho salvo.',
            'documento' => ['id' => $doc->id, 'numero' => $doc->numeroFormatado()],
            'avisos'    => $this->lavratura->avisosDeEmbargo($doc),
        ], 201);
    }

    /**
     * O que a multa por MÚLTIPLO DO ALVARÁ pede da peça: o valor do alvará, em
     * reais, e o multiplicador de cada artigo que tem intervalo (1 a 10×).
     * Fora do intervalo não é recusado aqui — o rascunho grava, e a lavratura
     * é que não passa (LavraturaService::pendenciasDeMulta).
     */
    private const REGRAS_DO_ALVARA = [
        'alvara_valor'      => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        // Por artigo, o número que o FISCAL informa: o multiplicador do alvará
        // ou o valor em UPF da multa "entre mínimo e máximo".
        'multiplicadores'   => ['nullable', 'array'],
        'multiplicadores.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        // O auto anterior, de que este é reincidência (a multa dobra).
        'reincidencia_de_id' => ['nullable', 'integer', 'exists:documentos,id'],
        // A peça de que esta nasceu (notificação ou embargo anterior).
        'origem_id'          => ['nullable', 'integer', 'exists:documentos,id'],
        ...self::REGRAS_DO_MOTIVO,
    ];

    /**
     * O que levou à notificação: direta, ordem de serviço (qual) ou denúncia
     * da ouvidoria (o número dela). Ver Documento::MOTIVOS_DE_ORIGEM.
     */
    private const REGRAS_DO_MOTIVO = [
        'origem_motivo'     => ['nullable', 'in:direta,ordem_servico,ouvidoria'],
        'origem_os_id'      => ['nullable', 'integer', 'exists:ordens_servico,id'],
        'origem_referencia' => ['nullable', 'string', 'max:80'],
    ];

    /**
     * O motivo de origem como vai para o banco, ou a mensagem da recusa.
     * Só notificação tem motivo; ordem de serviço pede a ordem, ouvidoria pede
     * o número da denúncia — origem pela metade não serve de prova depois.
     *
     * @return array<string,mixed>|string
     */
    private function motivoDeOrigem(string $tipo, array $d): array|string
    {
        $limpo = ['origem_motivo' => null, 'origem_os_id' => null, 'origem_referencia' => null];
        if (! in_array($tipo, Documento::COM_CUMPRIMENTO, true)) {
            return $limpo;
        }
        $motivo = $d['origem_motivo'] ?? 'direta';
        if ($motivo === 'ordem_servico') {
            if (empty($d['origem_os_id'])) {
                return 'Informe a ordem de serviço que originou a notificação.';
            }

            return ['origem_motivo' => $motivo, 'origem_os_id' => (int) $d['origem_os_id']] + $limpo;
        }
        if ($motivo === 'ouvidoria') {
            $ref = trim((string) ($d['origem_referencia'] ?? ''));
            if ($ref === '') {
                return 'Informe o número da denúncia da ouvidoria.';
            }

            return ['origem_motivo' => $motivo, 'origem_referencia' => $ref] + $limpo;
        }

        return ['origem_motivo' => 'direta'] + $limpo;
    }

    /**
     * PATCH /api/documentos/{documento}/origem — corrige a origem de uma
     * notificação JÁ LAVRADA.
     *
     * É a única coisa da peça que se altera depois da lavratura (além dos
     * anexos): a origem é dado de processo — a denúncia que só foi vinculada
     * depois, a ordem de serviço informada errada —, e não conteúdo do ato.
     * A alteração fica na trilha de auditoria, como toda mudança do documento.
     */
    public function atualizarOrigem(Request $request, Documento $documento): JsonResponse
    {
        if (! $documento->podeEditarOrigem($request->user())) {
            return response()->json(['message' => 'Só o autor (ou o administrador) altera a origem de uma notificação lavrada.'], 403);
        }
        $d = $request->validate(self::REGRAS_DO_MOTIVO);
        $motivo = $this->motivoDeOrigem($documento->tipo, $d);
        if (is_string($motivo)) {
            return response()->json(['message' => $motivo], 422);
        }
        $documento->update($motivo);

        return response()->json(['message' => 'Origem atualizada.', 'origem_texto' => $documento->fresh()->origemTexto()]);
    }

    /** GET /api/documentos/ordens-de-servico — as ordens que podem ter originado uma notificação. */
    public function ordensParaOrigem(Request $request): JsonResponse
    {
        return response()->json(['ordens' => \App\Models\OrdemServico::orderByDesc('ano')->orderByDesc('sequencia')
            ->limit(200)->get(['id', 'numero', 'objeto'])
            ->map(fn ($o) => ['id' => $o->id, 'rotulo' => 'OS ' . $o->numero . ($o->objeto ? ' — ' . \Illuminate\Support\Str::limit($o->objeto, 60) : '')])]);
    }

    /**
     * GET /api/documentos/origens — as peças lavradas do imóvel de que um
     * auto pode NASCER (Documento::ORIGENS): a notificação ou o embargo que
     * veio antes. É o que o marcador {origem} cita no texto de ciência.
     */
    public function origens(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo'    => ['required', Rule::in(array_keys(Documento::TIPOS))],
            'lote_id' => ['nullable', 'integer'],
            'exceto'  => ['nullable', 'integer'],
        ]);
        $tipos = Documento::ORIGENS[$d['tipo']] ?? [];
        if (! $tipos || empty($d['lote_id'])) {
            return response()->json(['origens' => []]);
        }

        $pecas = Documento::where('lote_id', $d['lote_id'])->whereIn('tipo', $tipos)
            ->whereNotIn('status', Documento::SEM_VALOR_DE_ATO)
            ->when($d['exceto'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->orderByDesc('data_lavratura')->limit(40)->get();

        return response()->json(['origens' => $pecas->map(fn (Documento $p) => [
            'id'     => $p->id,
            'rotulo' => $p->rotuloTipo() . ' nº ' . $p->numeroFormatado(),
            'data'   => $p->data_lavratura?->format('d/m/Y'),
        ])]);
    }

    /**
     * A origem informada serve a esta peça? Tem de ser peça LAVRADA, de um
     * tipo de que esta pode nascer. Devolve a mensagem da recusa, ou null.
     */
    private function recusaDaOrigem(string $tipo, ?int $origemId, ?int $proprioId = null): ?string
    {
        if (! $origemId) {
            return null;
        }
        $origem = Documento::find($origemId);
        if (! $origem || $origem->id === $proprioId || in_array($origem->status, Documento::SEM_VALOR_DE_ATO, true)
            || ! in_array($origem->tipo, Documento::ORIGENS[$tipo] ?? [], true)) {
            return 'O documento de origem tem de ser uma peça lavrada e não anulada, de um tipo que anteceda ' . Documento::TIPOS[$tipo][0] . '.';
        }

        return null;
    }

    /**
     * GET /api/documentos/autos-anteriores — os Autos de Infração lavrados de
     * que um auto novo pode ser reincidência: os do mesmo imóvel, ou, sem
     * imóvel, os do mesmo autuado (CPF/CNPJ).
     */
    public function autosAnteriores(Request $request): JsonResponse
    {
        $d = $request->validate([
            'lote_id'   => ['nullable', 'integer'],
            'documento' => ['nullable', 'string', 'max:20'],
            'exceto'    => ['nullable', 'integer'],
        ]);
        if (empty($d['lote_id']) && empty($d['documento'])) {
            return response()->json(['autos' => []]);
        }

        $autos = Documento::where('tipo', 'auto_infracao')->whereNotIn('status', Documento::SEM_VALOR_DE_ATO)
            ->when($d['exceto'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where(fn ($q) => $q
                ->when($d['lote_id'] ?? null, fn ($x, $id) => $x->orWhere('lote_id', $id))
                ->when($d['documento'] ?? null, fn ($x, $doc) => $x->orWhere('autuado_documento', $doc)))
            ->orderByDesc('data_lavratura')->limit(40)->get();

        return response()->json(['autos' => $autos->map(fn (Documento $a) => [
            'id'     => $a->id,
            'numero' => $a->numeroFormatado(),
            'data'   => $a->data_lavratura?->format('d/m/Y'),
            'autuado' => $a->autuado_nome,
            'valor_upf' => $a->valor_upf,
            // Quantas vezes a multa do PRÓXIMO auto será multiplicada.
            'proximo_fator' => 2 ** min((int) $a->reincidencia_nivel + 1, 6),
        ])]);
    }

    /**
     * POST /api/multas/simular — a multa de um conjunto de artigos, com as
     * áreas e o alvará que estão NA TELA.
     *
     * A tela não tem a regra da multa: pede aqui. É o mesmo
     * Artigo::calcularMulta da lavratura, então a prévia que o fiscal vê é a
     * conta que vai valer. Não grava nada.
     */
    public function simularMulta(Request $request): JsonResponse
    {
        $d = $request->validate([
            'artigos'            => ['required', 'array', 'max:60'],
            'artigos.*'          => ['integer'],
            'area_terreno_m2'    => ['nullable', 'numeric', 'min:0'],
            'area_construida_m2' => ['nullable', 'numeric', 'min:0'],
            'data_fato'          => ['nullable', 'date'],
            ...self::REGRAS_DO_ALVARA,
        ]);
        $anterior = isset($d['reincidencia_de_id']) ? Documento::find($d['reincidencia_de_id']) : null;
        $fator = $anterior ? 2 ** min((int) $anterior->reincidencia_nivel + 1, 6) : 1;

        $num = fn ($v) => $v === null || $v === '' ? null : (float) $v;
        $upf = \App\Models\Upf::vigente(isset($d['data_fato']) ? \Illuminate\Support\Carbon::parse($d['data_fato']) : now())?->valor;
        $porId = \App\Models\Artigo::whereIn('id', $d['artigos'])->get()->keyBy('id');

        $total = 0.0;
        $linhas = [];
        // Na ordem em que o fiscal os pôs na peça.
        foreach ($d['artigos'] as $id) {
            if (! ($a = $porId[$id] ?? null)) { continue; }
            $c = $a->calcularMulta($num($d['area_terreno_m2'] ?? null), $num($d['area_construida_m2'] ?? null),
                $num($d['alvara_valor'] ?? null), $num($d['multiplicadores'][$id] ?? null), $upf ? (float) $upf : null, $fator);
            $total += $c['valor'];
            $linhas[] = ['artigo_id' => $a->id, 'numero' => $a->numero, 'base' => $a->base_multa] + $c;
        }

        return response()->json([
            'linhas'    => $linhas,
            'fator_reincidencia' => $fator,
            'total_upf' => round($total, 2),
            'upf_valor' => $upf ? (float) $upf : null,
            'total_reais' => $upf ? round($total * (float) $upf, 2) : null,
            'pendencias'  => collect($linhas)->pluck('pendencia')->filter()->unique()->values(),
        ]);
    }

    /** POST /api/documentos/{documento}/lavrar — atribui número e fecha. */
    /**
     * 422 quando algum artigo não serve ao tipo da peça — artigo sem embargo
     * numa peça de embargo, ou artigo exclusivo de embargo fora dela. A regra
     * é Artigo::serveA; a tela já filtra, e isto é o que vale.
     *
     * @param  array<int,int>  $artigos
     */
    private function recusarArtigosForaDoTipo(string $tipo, array $artigos): ?JsonResponse
    {
        try {
            $this->lavratura->conferirArtigosDoTipo($tipo, $artigos);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return null;
    }

    /**
     * GET /api/documentos/testemunhas — quem pode testemunhar uma recusa de
     * assinatura: os usuários ativos, menos o próprio fiscal (ele não é
     * testemunha do próprio ato). Fora da lista, o nome é digitado.
     */
    public function testemunhas(Request $request): JsonResponse
    {
        return response()->json([
            'usuarios' => \App\Models\User::where('ativo', true)->where('id', '!=', $request->user()->id)
                ->orderBy('name')->get(['id', 'name', 'matricula'])
                ->map(fn ($u) => ['nome' => $u->name, 'matricula' => $u->matricula]),
            // A rubrica do próprio fiscal, para ele ver com o que vai assinar.
            'minha_assinatura' => $request->user()->assinatura,
        ]);
    }

    /**
     * POST /api/documentos/{documento}/lavrar — atribui número e fecha.
     *
     * A lavratura é o ATO: além de numerar, colhe as assinaturas. A do fiscal
     * vem do cadastro dele; a do autuado é desenhada na tela — ou, se ele se
     * recusar, registra-se a recusa com uma testemunha (nome e assinatura),
     * que é o que sustenta o Termo de Recusa.
     */
    public function lavrar(Request $request, Documento $documento, \App\Services\Assinatura $assinatura): JsonResponse
    {
        if (! $request->user()->podeLavrarDocumento()) {
            return response()->json(['message' => 'Só agente de fiscalização pode lavrar.'], 403);
        }
        if ($documento->agente_id !== $request->user()->id) {
            return response()->json(['message' => 'Só o autor do rascunho pode lavrá-lo.'], 403);
        }

        $png = ['string', 'max:1000000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/'];
        $d = $request->validate([
            'recusa'                => ['nullable', 'boolean'],
            'assinatura_autuado'    => ['nullable', 'required_unless:recusa,1,true', ...$png],
            'testemunha_nome'       => ['nullable', 'required_if:recusa,1,true', 'string', 'max:120'],
            'assinatura_testemunha' => ['nullable', 'required_if:recusa,1,true', ...$png],
        ], [
            'assinatura_autuado.required_unless' => 'Colha a assinatura do autuado, ou registre a recusa.',
            'testemunha_nome.required_if'        => 'Informe a testemunha da recusa.',
            'assinatura_testemunha.required_if'  => 'Colha a assinatura da testemunha.',
            'assinatura_autuado.regex'           => 'Formato de assinatura inválido.',
            'assinatura_testemunha.regex'        => 'Formato de assinatura inválido.',
        ]);
        // A lavratura é indelegável: sai com a rubrica de quem lavra.
        if (! $request->user()->assinatura) {
            return response()->json(['message' => 'Cadastre a sua assinatura em Meu perfil › Assinatura antes de lavrar.'], 422);
        }

        $recusa = (bool) ($d['recusa'] ?? false);
        try {
            $documento->loadMissing('legislacao');
            // Aparadas até o traço, como a do fiscal: o canvas tem a largura
            // da tela e a pessoa assina num pedaço dele.
            $doc = $this->lavratura->lavrar($documento, [
                'recusa'                => $recusa,
                'assinatura_autuado'    => $recusa ? null : $assinatura->aparar($d['assinatura_autuado']),
                'testemunha_nome'       => $recusa ? trim($d['testemunha_nome']) : null,
                'assinatura_testemunha' => $recusa ? $assinatura->aparar($d['assinatura_testemunha']) : null,
            ]);
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
        $documento->load(['lote', 'legislacao', 'agente', 'artigos', 'origem', 'origemOs', 'anuladoPor', 'vistoria.evidencias']);

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
            'origem_id'      => $documento->origem_id,
            // O motivo de origem das notificações — e se este usuário ainda
            // pode corrigi-lo com a peça já lavrada.
            'origem_motivo'      => $documento->temMotivoDeOrigem() ? ($documento->origem_motivo ?: 'direta') : null,
            'origem_os_id'       => $documento->origem_os_id,
            'origem_os_rotulo'   => $documento->origemOs ? 'OS ' . $documento->origemOs->numero : null,
            'origem_referencia'  => $documento->origem_referencia,
            'origem_texto'       => $documento->origemTexto(),
            'pode_editar_origem' => $documento->podeEditarOrigem($request->user()),
            'origem_rotulo'  => $documento->origem ? $documento->origem->rotuloTipo() . ' nº ' . $documento->origem->numeroFormatado() : null,

            'imovel' => [
                // O id do lote: é por ele que o formulário lê o cadastro
                // municipal (BCI) do imóvel da peça.
                'lote_id'   => $documento->lote_id,
                // Derivada, e bairro pelo nome OFICIAL: é esta a identificação
                // que entra na peça.
                // O que a PEÇA guarda tem precedência; peça antiga (colunas
                // nulas) cai no lote.
                'inscricao' => $documento->imovel_inscricao ?: $documento->lote?->inscricaoFormatada(),
                'bairro'    => $documento->imovel_bairro ?: $documento->lote?->bairroOficial(),
                'quadra'    => $documento->imovel_quadra ?? $documento->lote?->quadra,
                'lote'      => $documento->imovel_lote ?? $documento->lote?->numero_lote,
                'endereco'  => $documento->endereco,
                // Peça antiga, sem as partes: o texto único inteiro vai no logradouro.
                'logradouro' => $documento->imovel_endereco_partes['logradouro'] ?? $documento->endereco,
                'numero'     => $documento->imovel_endereco_partes['numero'] ?? null,
                'terreno'   => $documento->area_terreno_m2,
                'construida'=> $documento->area_construida_m2,
                'alvara_valor' => $documento->alvara_valor,
            ],

            'autuado'   => [
                'nome' => $documento->autuado_nome, 'documento' => $documento->autuado_documento,
                'endereco' => $documento->autuado_endereco,
                'logradouro' => $documento->autuado_endereco_partes['logradouro'] ?? $documento->autuado_endereco,
                'numero'     => $documento->autuado_endereco_partes['numero'] ?? null,
                'bairro'     => $documento->autuado_endereco_partes['bairro'] ?? null,
                'cidade'     => $documento->autuado_endereco_partes['cidade'] ?? null,
                'uf'         => $documento->autuado_endereco_partes['uf'] ?? null,
            ],

            // De quando é o dado cadastral que esta peça usou. Vai para a ficha
            // e para o PDF, e para lugar nenhum mais: é dado de conferência,
            // consultado quando alguém questiona — não coluna de lista nem
            // filtro, que seria pagar por ele a cada abertura de tela.
            'cadastro'  => $documento->naoLavrado() ? null : [
                'consultado_em' => $documento->cadastro_consultado_em?->format('d/m/Y'),
                'fonte'         => $documento->cadastro_fonte,
                // QUANDO a cópia foi tirada: na lavratura. E a própria cópia —
                // é ela que a peça reaberta mostra, e não o cadastro do dia.
                'copiado_em'    => $documento->data_lavratura?->format('d/m/Y H:i'),
                'retrato'       => $this->retratoDoCadastro($documento->cadastro_retrato),
            ],
            'descricao' => $documento->descricao,
            'observacoes' => $documento->observacoes,

            'lei'     => $documento->legislacao?->rotulo(),
            // Os ids: sem eles o formulário reabria a peça sem lei e sem
            // artigos, e gravar de novo apagava o enquadramento.
            'legislacao_id' => $documento->legislacao_id,
            'artigos' => $documento->artigos->map(fn ($a) => [
                'artigo_id' => $a->artigo_id,
                'numero'  => $a->numero,
                'conduta' => $a->conduta,
                'sancao'  => $a->sancao,
                'base'    => $a->base_multa,
                // A memória congelada; peça antiga, sem ela, mostra a conta curta.
                'calculo' => $a->memoria ?: ($a->base_multa === 'fixa'
                    ? $a->multa_upf . ' UPF (fixo)'
                    : ($a->base_multa === 'sem_multa'
                        ? 'sem multa'
                        : $a->multa_upf_m2 . ' UPF/m²' . ($a->area_m2 ? ' × ' . $a->area_m2 . ' m²' : ''))),
                'multiplicador' => $a->multiplicador,
                'fator_reincidencia' => $a->fator_reincidencia,
                'valor'   => $a->valor_upf,
            ]),

            // Reincidência: o auto anterior e por quanto a multa foi multiplicada.
            'reincidencia' => $documento->reincidencia_de_id ? [
                'id'     => $documento->reincidencia_de_id,
                'numero' => $documento->reincidenciaDe?->numeroFormatado(),
                'fator'  => $documento->fatorReincidencia(),
            ] : null,

            'valor_upf' => $documento->valor_upf,
            'upf_valor' => $documento->upf_valor,
            'valor_reais' => $documento->valor_upf && $documento->upf_valor
                ? $documento->valor_upf * $documento->upf_valor
                : null,

            'prazo_ate'  => $documento->prazo_ate?->format('d/m/Y'),
            'defesa_ate' => $documento->defesa_ate?->format('d/m/Y'),

            'anexos' => $documento->anexos_proprios
                ? $documento->anexos()->count()
                : ($documento->vistoria?->evidencias->count() ?? 0),
            'vistoria_id' => $documento->vistoria_id,

            // As assinaturas colhidas na lavratura, para o resumo da peça.
            'assinaturas' => $documento->naoLavrado() ? null : [
                'agente'          => $documento->assinatura_agente,
                'autuado'         => $documento->recusa_assinatura ? null : $documento->assinatura_autuado,
                'recusa'          => (bool) $documento->recusa_assinatura,
                'testemunha_nome' => $documento->testemunha_nome,
                'testemunha'      => $documento->assinatura_testemunha,
            ],

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
     * POST /api/documentos/{documento}/gravar — o rascunho ganha número e
     * passa a "gravado" (LavraturaService::gravar). Daqui em diante a peça não
     * se exclui: edita-se, lavra-se ou cancela-se.
     */
    public function gravar(Request $request, Documento $documento): JsonResponse
    {
        if ($documento->agente_id !== $request->user()->id) {
            return response()->json(['message' => 'Só o autor grava o próprio documento.'], 403);
        }
        if ($documento->status !== 'rascunho') {
            return response()->json(['message' => 'Este documento já foi gravado.'], 422);
        }
        // O que a peça precisa ter para ganhar número: quem responde por ela.
        // O imóvel e os artigos continuam sendo cobrados na lavratura.
        if (! trim((string) $documento->autuado_nome)) {
            return response()->json(['message' => 'Informe o nome do autuado antes de gravar.'], 422);
        }

        $this->lavratura->gravar($documento);

        return response()->json([
            'message'   => $documento->rotuloTipo() . ' gravado sob o número ' . $documento->numeroFormatado() . '.',
            'documento' => ['id' => $documento->id, 'numero' => $documento->numeroFormatado()],
        ]);
    }

    /**
     * POST /api/documentos/{documento}/cancelar — encerra a peça que já tem
     * número. Ela NÃO some: fica na série como cancelada, com quem, quando e
     * por quê.
     *
     * GRAVADA (ainda não lavrada): basta o motivo.
     * LAVRADA: é ato assinado — além do motivo, a SENHA de quem está
     * cancelando, conferida aqui. Sessão aberta num aparelho esquecido não
     * pode cancelar um auto.
     */
    public function cancelar(Request $request, Documento $documento): JsonResponse
    {
        $d = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
            'senha'  => ['nullable', 'string', 'max:200'],
        ], [
            'motivo.required' => 'Informe a justificativa do cancelamento.',
            'motivo.min'      => 'Descreva a justificativa com pelo menos 10 caracteres.',
        ]);

        if (! in_array('cancelar', $documento->opcoesPara($request->user()), true)) {
            return response()->json(['message' => 'Este documento não pode ser cancelado por você.'], 403);
        }

        if ($documento->status !== 'gravado'
            && ! \Illuminate\Support\Facades\Hash::check((string) ($d['senha'] ?? ''), (string) $request->user()->password)) {
            return response()->json(['message' => 'Senha incorreta. O cancelamento de documento lavrado exige a sua senha.'], 422);
        }

        $documento->update([
            'status'          => 'cancelado',
            'anulado_em'      => now(),
            'anulado_por'     => $request->user()->id,
            'anulacao_motivo' => $d['motivo'],
        ]);

        return response()->json(['message' => 'Documento cancelado.']);
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
        if (! $documento->naoLavrado()) {
            return response()->json(['message' => 'Documento lavrado não pode ser alterado. Use o cancelamento.'], 422);
        }
        if ($documento->agente_id !== $request->user()->id) {
            return response()->json(['message' => 'Só o autor pode alterar o próprio documento.'], 403);
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
            'autuado_endereco'   => ['nullable', 'string', 'max:300'],
            'endereco'           => ['nullable', 'string', 'max:200'],
            // O endereço EM PARTES (o formulário). O texto único de cada um é
            // montado a partir delas — ver enderecosDaPeca().
            'autuado_logradouro' => ['nullable', 'string', 'max:160'],
            'autuado_numero'     => ['nullable', 'string', 'max:20'],
            'autuado_bairro'     => ['nullable', 'string', 'max:120'],
            'autuado_cidade'     => ['nullable', 'string', 'max:120'],
            'autuado_uf'         => ['nullable', 'string', 'size:2'],
            'imovel_logradouro'  => ['nullable', 'string', 'max:160'],
            'imovel_numero'      => ['nullable', 'string', 'max:20'],
            'imovel_inscricao'   => ['nullable', 'string', 'max:30'],
            'imovel_bairro'      => ['nullable', 'string', 'max:160'],
            'imovel_quadra'      => ['nullable', 'string', 'max:20'],
            'imovel_lote'        => ['nullable', 'string', 'max:20'],
            'descricao'          => ['nullable', 'string', 'max:5000'],
            'observacoes'        => ['nullable', 'string', 'max:5000'],
            'prazo_dias'         => ['nullable', 'integer', 'min:0', 'max:365'],
            'area_terreno_m2'    => ['nullable', 'numeric', 'min:0'],
            'area_construida_m2' => ['nullable', 'numeric', 'min:0'],
            ...self::REGRAS_DO_ALVARA,
            'artigos'            => ['nullable', 'array'],
            'artigos.*'          => ['integer', 'exists:artigos,id'],
        ]);

        if ($recusa = $this->recusarArtigosForaDoTipo($d['tipo'], $d['artigos'] ?? [])) {
            return $recusa;
        }

        // array_merge, e não `+`: o texto único montado das partes tem de VENCER o
        // que veio no pedido.
        if ($msg = $this->recusaDaOrigem($d['tipo'], $d['origem_id'] ?? null, $documento->id)) {
            return response()->json(['message' => $msg], 422);
        }

        $motivo = $this->motivoDeOrigem($d['tipo'], $d);
        if (is_string($motivo)) {
            return response()->json(['message' => $motivo], 422);
        }

        // GRAVADO NÃO TROCA DE TIPO: o número é da série do tipo dele.
        if ($documento->status === 'gravado' && $documento->tipo !== $d['tipo']) {
            return response()->json(['message' => 'Documento gravado já tem número na série do tipo dele e não muda de tipo. Cancele-o e abra outro.'], 422);
        }

        try {
            $documento->tipo = $d['tipo'];
            if ($d['tipo'] === 'auto_infracao') {
                $documento->vistoria_id = null;   // auto de infração não se vincula a vistoria
            }
            $this->lavratura->vincularReincidencia($documento, $d['reincidencia_de_id'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $documento->update(array_merge(
            collect($d)->except(['artigos', 'multiplicadores', 'reincidencia_de_id', ...self::PARTES_DE_ENDERECO])->all(), $this->enderecosDaPeca($d), $motivo));

        // Os artigos são refixados por inteiro: manter os antigos e somar os
        // novos deixaria no documento um enquadramento que o fiscal removeu
        // da tela e acredita ter tirado.
        $documento->artigos()->delete();
        if (! empty($d['artigos'])) {
            $this->lavratura->fixarArtigos($documento, $d['artigos'], $d['multiplicadores'] ?? []);
        }

        return response()->json([
            'message' => $documento->status === 'gravado' ? 'Alterações salvas.' : 'Rascunho salvo.',
            'avisos'  => $this->lavratura->avisosDeEmbargo($documento),
        ]);
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

        // Os arquivos que eram SÓ deste rascunho saem com ele; os trazidos da
        // vistoria ou de outra peça ficam com quem os cedeu.
        foreach ($documento->anexos()->where('origem', 'proprio')->get() as $anexo) {
            if (! \App\Models\DocumentoAnexo::where('arquivo', $anexo->arquivo)->where('documento_id', '!=', $documento->id)->exists()) {
                \Illuminate\Support\Facades\Storage::disk('private')->delete($anexo->arquivo);
            }
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
     * POST /api/documentos/previa — a via A4 do que está NA TELA, sem gravar.
     *
     * O resumo do formulário mostra sempre a via A4. Para a peça gravada, ela
     * vem de /documentos/{id}/impressao; para a que ainda não foi gravada (ou
     * está em edição), vem daqui: o pedido é o MESMO do Gravar, a peça é
     * criada ou alterada dentro de uma transação, a página é montada e a
     * transação é DESFEITA. Nada fica no banco — e a prévia passa exatamente
     * pelas mesmas regras e pela mesma impressão da peça de verdade.
     *
     * `documento_id` diz qual rascunho está em edição; sem ele é peça nova, e
     * `lote_id` (opcional) é o imóvel escolhido.
     */
    public function previa(Request $request, DocumentoImpressao $impressao): Response|JsonResponse
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            if ($id = $request->input('documento_id')) {
                $doc = Documento::findOrFail($id);
                $resposta = $this->update($request, $doc);
            } else {
                $lote = $request->input('lote_id') ? Lote::find($request->input('lote_id')) : null;
                $resposta = $this->store($request, $lote);
                $doc = Documento::find($resposta->getData(true)['documento']['id'] ?? 0);
            }
            if ($resposta->getStatusCode() >= 400 || ! $doc) {
                return $resposta;
            }

            $html = view('impressao.a4', $impressao->montar($doc->fresh(), paraPdf: false, comAnexos: true)
                + ['navegador' => false, 'previa' => true])->render();

            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        } finally {
            \Illuminate\Support\Facades\DB::rollBack();
        }
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

        // PRÉVIA (?previa=1): a MESMA via A4, sem a barra nem a impressão
        // automática — é o que o resumo do documento embute na tela, para o
        // autuado ler exatamente o que vai assinar.
        $previa = $request->boolean('previa');

        return response()->view('impressao.' . $formato, $dados + ['navegador' => ! $previa, 'previa' => $previa]);
    }
}
