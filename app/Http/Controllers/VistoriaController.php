<?php

namespace App\Http\Controllers;

use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Obra;
use App\Models\Evidencia;
use App\Models\Irregularidade;
use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Vistoria;
use App\Services\SucessaoDeLotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VistoriaController extends Controller
{
    /** Tamanho máximo por evidência (KB). Foto de celular moderno passa de 5 MB. */
    private const MAX_KB = 12288;

    /** Tipos aceitos. Validados por MIME real, não pela extensão do nome. */
    private const MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
        'application/pdf',
    ];

    /**
     * GET /api/lotes/{lote}/historico
     *
     * Histórico cronológico do imóvel (§20 do projeto). É o que o fiscal
     * consulta ANTES de vistoriar: saber que o lote já foi notificado no mês
     * passado muda o que ele vai fazer na visita de hoje.
     */
    /**
     * GET /api/lotes/{lote}/protocolos-cadastrais
     *
     * Protocolos de desmembramento/unificação deste imóvel que ainda esperam
     * vistoria. Alimenta o seletor do formulário de vistoria — o caminho de
     * quem parte do mapa, em campo, e não da tela de protocolos.
     *
     * Só os DEFERIDOS: antes da decisão não há ato a fundamentar, e oferecer
     * um protocolo em análise faria a vistoria nascer amarrada a algo que
     * ainda pode ser indeferido.
     */
    public function protocolosCadastrais(Lote $lote): JsonResponse
    {
        $protocolos = Protocolo::where('lote_id', $lote->id)
            ->whereIn('tipo', ['desmembramento', 'unificacao'])
            ->where('situacao', 'deferido')
            ->whereNull('vistoria_id')
            ->orderByDesc('protocolado_em')
            ->get(['id', 'numero', 'tipo', 'objeto'])
            ->map(fn (Protocolo $p) => [
                'id'     => $p->id,
                'numero' => $p->numero,
                'tipo'   => $p->tipo,
                'rotulo' => $p->numero . ' — ' . $p->rotuloTipo(),
            ]);

        return response()->json(['protocolos' => $protocolos]);
    }

    public function historico(Lote $lote): JsonResponse
    {
        $vistorias = $lote->vistorias()
            ->with(['fiscal:id,name', 'irregularidades:id,codigo,descricao,gravidade'])
            ->withCount('evidencias')
            ->get()
            ->map(fn (Vistoria $v) => [
                'id'               => $v->id,
                // O que a tela precisa para oferecer (ou explicar) o ato
                // cadastral: unificar ou desmembrar a partir DESTA vistoria.
                'ato_cadastral'    => app(SucessaoDeLotes::class)->atoDaVistoria($v),
                'data_hora'        => $v->data_hora?->format('d/m/Y H:i'),
                'situacao'         => $v->situacao,
                'situacao_rotulo'  => $v->situacaoRotulo(),
                'situacao_badge'   => $v->situacaoBadge(),
                'fiscal'           => $v->fiscal?->name,
                'observacoes'      => $v->observacoes,
                'evidencias'       => $v->evidencias_count,
                'irregularidades'  => $v->irregularidades->map(fn ($i) => [
                    'codigo' => $i->codigo, 'descricao' => $i->descricao, 'gravidade' => $i->gravidade,
                ]),
            ]);

        // Linha do tempo: vistoria não é o único fato da vida do imóvel. O que
        // o fiscal precisa ver antes da visita é a sequência inteira — vistoria,
        // documento lavrado e requerimento do contribuinte —, porque é ela que
        // explica em que pé o processo está.
        $eventos = [];

        foreach ($vistorias as $v) {
            $eventos[] = [
                'tipo'    => 'vistoria',
                // O ato de desmembramento precisa saber QUAL lote dividir, e a
                // linha do tempo e a unica coisa que a tela tem em maos ali.
                'lote_id' => $lote->id,
                'quando'  => $v['data_hora'],
                'titulo'  => 'Vistoria — ' . $v['situacao_rotulo'],
                'detalhe' => $v['fiscal'] ? 'Fiscal: ' . $v['fiscal'] : null,
                'badge'   => ['texto' => $v['situacao_rotulo'], 'classe' => $v['situacao_badge']],
                'ato_cadastral' => $v['ato_cadastral'],
                'itens'   => collect($v['irregularidades'])->pluck('descricao')->all(),
                'obs'     => $v['observacoes'],
            ];
        }

        foreach (Documento::where('lote_id', $lote->id)->get() as $d) {
            [$sTxt, $sCls] = $d->statusBadge();
            $eventos[] = [
                'tipo'    => 'documento',
                'quando'  => ($d->data_lavratura ?? $d->created_at)?->format('d/m/Y H:i'),
                'titulo'  => $d->numeroFormatado() . ' — ' . $d->rotuloTipo(),
                'detalhe' => $d->autuado_nome ? 'Autuado: ' . $d->autuado_nome : null,
                'badge'   => ['texto' => $sTxt, 'classe' => $sCls],
                'itens'   => [],
                'obs'     => $d->descricao,
            ];
        }

        foreach (Protocolo::where('lote_id', $lote->id)->get() as $p) {
            [$sTxt, $sCls] = $p->situacaoBadge();
            $eventos[] = [
                'tipo'    => 'protocolo',
                'quando'  => $p->protocolado_em?->format('d/m/Y'),
                'titulo'  => $p->numero . ' — ' . $p->rotuloTipo(),
                'detalhe' => $p->requerente_nome ? 'Requerente: ' . $p->requerente_nome : null,
                'badge'   => ['texto' => $sTxt, 'classe' => $sCls],
                'itens'   => [],
                'obs'     => $p->objeto,
            ];
        }

        // Mais recente primeiro: o último fato é o que decide o que fazer hoje.
        usort($eventos, fn ($a, $b) => strcmp(
            $this->chaveOrdem($b['quando']), $this->chaveOrdem($a['quando'])
        ));

        return response()->json([
            'lote'      => $lote->only(['id', 'bairro', 'quadra', 'numero_lote', 'chave']),
            'vistorias' => $vistorias,
            'eventos'   => $eventos,
            'resumo'    => $this->resumoDoImovel($lote),
        ]);
    }

    /**
     * O que a aba Dados mostra sem precisar ler a linha do tempo inteira:
     * em que pé está o imóvel, quantas vistorias já teve e quando foi a última.
     *
     * ── Sobre o STATUS ──
     *
     * Ele é DERIVADO do que já está registrado, não digitado por ninguém. A
     * ordem abaixo é de precedência, e cada degrau existe por um motivo:
     *
     *   baixado    o lote deixou de existir por unificação ou desmembramento,
     *              e isso vence qualquer outra informação;
     *   embargado  há auto ou notificação de embargo lavrado e não anulado —
     *              é o estado que MUDA o que o fiscal pode fazer em campo;
     *   irregular  a última vistoria apontou irregularidade;
     *   obra       havendo obra cadastrada, vale a situação dela;
     *   regular    houve vistoria e ela não apontou nada;
     *   sem visita nunca foi vistoriado — que é diferente de estar tudo bem.
     *
     * É provisório de propósito: "vazio / em construção" depende de uma
     * definição que ainda não existe (§ combinar com o usuário). Enquanto ela
     * não vem, o status responde pelo que o sistema PODE provar, em vez de
     * inventar um estado que ninguém registrou.
     */
    private function resumoDoImovel(Lote $lote): array
    {
        $vistorias = $lote->vistorias()->orderByDesc('data_hora')->get();
        $ultima = $vistorias->first();

        $embargo = Documento::where('lote_id', $lote->id)
            ->whereIn('tipo', ['auto_embargo', 'notificacao_embargo'])
            ->whereNotNull('data_lavratura')
            ->where('status', '!=', 'anulado')
            ->exists();

        $obra = Obra::where('lote_id', $lote->id)->latest('id')->first();

        $status = match (true) {
            $lote->situacao === 'baixado' => ['Baixado', 'bd-cx'],
            $embargo                      => ['Embargado', 'bd-cx'],
            $ultima?->situacao === 'irregular' => ['Irregular', 'bd-al'],
            $obra !== null                => [$obra->situacaoRotulo(), 'bd-in'],
            $ultima !== null              => ['Regular', 'bd-ok'],
            default                       => ['Sem vistoria', 'bd-in'],
        };

        // Fachada: a foto mais recente de qualquer vistoria do imóvel. É a
        // pergunta "como está hoje", e quem responde é sempre a última.
        $foto = Evidencia::whereIn('vistoria_id', $vistorias->pluck('id'))
            ->where('tipo', 'foto')
            ->orderByDesc('data_hora')->orderByDesc('id')
            ->first();

        return [
            'status'          => ['texto' => $status[0], 'classe' => $status[1]],
            'vistorias'       => $vistorias->count(),
            'ultima_vistoria' => $ultima?->data_hora?->format('d/m/Y'),
            'fachada'         => $foto ? [
                'url'    => route('evidencia.arquivo', $foto),
                'quando' => $foto->data_hora?->format('d/m/Y'),
            ] : null,
        ];
    }

    /** "dd/mm/aaaa hh:mm" -> "aaaammddhhmm", para ordenar comparando texto. */
    private function chaveOrdem(?string $data): string
    {
        if (! $data) { return '0'; }
        $partes = explode(' ', $data);
        [$d, $m, $a] = array_pad(explode('/', $partes[0]), 3, '00');
        return $a . $m . $d . str_replace(':', '', $partes[1] ?? '0000');
    }

    /** GET /api/irregularidades — catálogo para montar o checklist. */
    public function catalogo(): JsonResponse
    {
        return response()->json(
            Irregularidade::ativas()->get(['id', 'codigo', 'descricao', 'gravidade'])
        );
    }

    /**
     * GET /api/artigos-sugeridos?irregularidades=1,2,3
     *
     * Os artigos que enquadram as irregularidades marcadas — a mesma consulta
     * de LavraturaService::artigosSugeridos(), mas por IDS e não por vistoria,
     * porque aqui a vistoria ainda não existe: o fiscal está diante da obra,
     * marcando o checklist, e é esse o momento de conferir o enquadramento —
     * com os fatos à vista, e não semanas depois, na mesa.
     */
    public function artigosSugeridos(Request $request): JsonResponse
    {
        $ids = array_filter(array_map(
            'intval',
            explode(',', (string) $request->query('irregularidades'))
        ));

        if (! $ids) {
            return response()->json(['artigos' => [], 'sem_artigo' => []]);
        }

        $artigos = Artigo::query()->ativos()
            ->with('legislacao:id,numero,nome')
            ->whereHas('irregularidades', fn ($q) => $q->whereIn('irregularidades.id', $ids))
            ->get();

        // As irregularidades que NENHUM artigo enquadra. Escondê-las faria a
        // tela mentir por omissão: o fiscal veria três artigos e concluiria
        // que as cinco marcações estão fundamentadas. Hoje são 18 no catálogo.
        $cobertas = DB::table('artigo_irregularidade')
            ->whereIn('irregularidade_id', $ids)
            ->distinct()->pluck('irregularidade_id')->all();

        return response()->json([
            'artigos' => $artigos->map(fn ($a) => [
                'id'      => $a->id,
                'numero'  => $a->numero,
                'conduta' => $a->conduta,
                'base'    => Artigo::BASES_MULTA[$a->base_multa] ?? null,
                'por_m2'  => $a->base_multa === 'area_construida',
                'lei'     => $a->legislacao?->numero,
            ]),
            'sem_artigo' => Irregularidade::whereIn('id', array_diff($ids, $cobertas))
                ->pluck('descricao'),
        ]);
    }

    /**
     * POST /api/lotes/{lote}/vistorias
     *
     * Grava a vistoria, as irregularidades marcadas e as fotos, tudo numa
     * transação: uma vistoria salva pela metade — sem as fotos que a
     * fundamentam — é pior do que vistoria nenhuma, porque parece completa.
     */
    public function store(Request $request, Lote $lote): JsonResponse
    {
        $u = $request->user();
        if (! $u->canEdit()) {
            return response()->json(['message' => 'Seu perfil não permite registrar vistorias.'], 403);
        }

        $d = $request->validate([
            'data_hora'          => ['required', 'date_format:Y-m-d\TH:i'],
            'situacao'           => ['required', Rule::in(array_keys(Vistoria::SITUACOES))],
            'observacoes'        => ['nullable', 'string', 'max:5000'],
            'latitude'           => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'          => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy'           => ['nullable', 'numeric', 'min:0'],
            'irregularidades'    => ['array'],
            'irregularidades.*'  => ['integer', 'exists:irregularidades,id'],
            'evidencias'         => ['array', 'max:20'],
            'evidencias.*'       => ['file', 'max:' . self::MAX_KB, 'mimetypes:' . implode(',', self::MIMES)],
            'titulos'            => ['array'],
            'titulos.*'          => ['nullable', 'string', 'max:160'],
            // A legenda da foto, por índice, pareada com titulos[]. A coluna
            // existia no banco desde a primeira migração e nunca fora usada —
            // foto sem legenda é prova que depende de alguém para explicá-la.
            'descricoes'         => ['array'],
            'descricoes.*'       => ['nullable', 'string', 'max:1000'],
            // Índice da foto que responde "como está o imóvel hoje".
            'fachada'            => ['nullable', 'integer', 'min:0'],

            // ── a obra ──
            'acompanhante_nome'         => ['nullable', 'string', 'max:160'],
            'acompanhante_qualificacao' => ['nullable', Rule::in(array_keys(Vistoria::QUALIFICACOES))],
            'alvara_situacao'    => ['nullable', Rule::in(array_keys(Vistoria::ALVARA))],
            'alvara_numero'      => ['nullable', 'string', 'max:40'],
            'area_construida_aferida_m2' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'area_metodo'        => ['nullable', Rule::in(array_keys(Vistoria::METODOS_AREA))],
            'fase_obra'          => ['nullable', Rule::in(array_keys(Vistoria::FASES_OBRA))],

            // ── constatações ──
            'artigos'            => ['array'],
            'artigos.*'          => ['integer', 'exists:artigos,id'],
            'exigencias'         => ['array', 'max:30'],
            'exigencias.*.texto' => ['required', 'string', 'max:500'],
            'exigencias.*.prazo_dias' => ['nullable', 'integer', 'min:1', 'max:3650'],
            // Protocolo de desmembramento/unificacao que esta vistoria atende.
            // E o vinculo que, mais tarde, libera o ato cadastral — ver
            // App\Services\SucessaoDeLotes::atoDaVistoria().
            'protocolo_id'       => ['nullable', 'integer', 'exists:protocolos,id'],
        ], [
            'data_hora.date_format' => 'Informe data e hora da vistoria.',
            'evidencias.*.max'      => 'Cada arquivo deve ter no máximo 12 MB.',
            'evidencias.*.mimetypes' => 'Envie apenas imagens ou PDF.',
            'exigencias.*.texto.required' => 'Exigência sem texto não pode ser gravada.',
        ]);

        // Área medida sem dizer COMO é número que não se sustenta em defesa. E
        // método sem área é campo órfão. Os dois andam juntos ou nenhum anda.
        if (! empty($d['area_construida_aferida_m2']) && empty($d['area_metodo'])) {
            return response()->json([
                'message' => 'Informe como a área foi obtida (trena, estimativa, projeto ou croqui).',
                'errors'  => ['area_metodo' => ['Escolha o método.']],
            ], 422);
        }

        // Uma vistoria irregular sem nenhuma irregularidade marcada é um
        // registro que não sustenta documento nenhum depois. Barrar aqui evita
        // descobrir isso na hora de lavrar a notificação.
        if ($d['situacao'] === 'irregular' && empty($d['irregularidades'])) {
            return response()->json([
                'message' => 'Marque ao menos uma irregularidade para uma vistoria irregular.',
                'errors'  => ['irregularidades' => ['Selecione ao menos uma.']],
            ], 422);
        }

        $vistoria = DB::transaction(function () use ($request, $lote, $u, $d) {
            $v = Vistoria::create([
                'lote_id'     => $lote->id,
                'fiscal_id'   => $u->id,
                // Gravado como string local "ingênua" (aaaa-mm-ddThh:mm), sem
                // conversão de fuso — ver comentário na migration.
                'data_hora'   => str_replace('T', ' ', $d['data_hora']) . ':00',
                'situacao'    => $d['situacao'],
                'observacoes' => $d['observacoes'] ?? null,
                'latitude'    => $d['latitude'] ?? null,
                'longitude'   => $d['longitude'] ?? null,
                'accuracy'    => $d['accuracy'] ?? null,

                'acompanhante_nome'         => $d['acompanhante_nome'] ?? null,
                'acompanhante_qualificacao' => $d['acompanhante_qualificacao'] ?? null,
                'alvara_situacao'    => $d['alvara_situacao'] ?? null,
                'alvara_numero'      => $d['alvara_numero'] ?? null,
                'area_construida_aferida_m2' => $d['area_construida_aferida_m2'] ?? null,
                'area_metodo'        => $d['area_metodo'] ?? null,
                'fase_obra'          => $d['fase_obra'] ?? null,
            ]);

            if (! empty($d['irregularidades'])) {
                $v->irregularidades()->sync($d['irregularidades']);
            }

            // Enquadramento constatado em campo. Ver a relação `artigos()` em
            // Vistoria para por que ele não divide tabela com o do documento.
            if (! empty($d['artigos'])) {
                $v->artigos()->sync($d['artigos']);
            }

            // A ORDEM é a que o fiscal escreveu: ela é a sequência em que as
            // providências devem ser tomadas, e a notificação imprime assim.
            foreach ($d['exigencias'] ?? [] as $i => $e) {
                $v->exigencias()->create([
                    'ordem'      => $i,
                    'texto'      => trim($e['texto']),
                    'prazo_dias' => $e['prazo_dias'] ?? null,
                ]);
            }

            // Amarra a vistoria ao protocolo que ela atende.
            //
            // O vinculo mora em `protocolos.vistoria_id`, que ja existia e nao
            // tinha como ser preenchido pela interface. So aceita protocolo de
            // desmembramento/unificacao ainda sem vistoria: sobrescrever o
            // vinculo de um protocolo ja atendido apagaria, em silencio, a
            // vistoria que fundamentou um ato.
            if (! empty($d['protocolo_id'])) {
                Protocolo::where('id', $d['protocolo_id'])
                    ->whereIn('tipo', ['desmembramento', 'unificacao'])
                    ->whereNull('vistoria_id')
                    ->update(['vistoria_id' => $v->id]);
            }

            foreach ($request->file('evidencias', []) as $i => $arquivo) {
                // Disco privado: foto de fiscalização mostra propriedade
                // privada e identifica pessoas.
                $caminho = $arquivo->store("evidencias/{$v->id}", 'private');
                Evidencia::create([
                    'vistoria_id'   => $v->id,
                    'tipo'          => str_starts_with($arquivo->getMimeType(), 'image/') ? 'foto' : 'documento',
                    'arquivo'       => $caminho,
                    'nome_original' => $arquivo->getClientOriginalName(),
                    'mime'          => $arquivo->getMimeType(),
                    'tamanho'       => $arquivo->getSize(),
                    'titulo'        => $d['titulos'][$i] ?? ('Evidência ' . ($i + 1)),
                    'descricao'     => $d['descricoes'][$i] ?? null,
                    // Uma fachada por vistoria: é a resposta a "como está o
                    // imóvel", e duas respostas não respondem nada.
                    'fachada'       => isset($d['fachada']) && (int) $d['fachada'] === $i,
                    'latitude'      => $d['latitude'] ?? null,
                    'longitude'     => $d['longitude'] ?? null,
                    'data_hora'     => str_replace('T', ' ', $d['data_hora']) . ':00',
                    'criado_por'    => $u->id,
                ]);
            }

            return $v;
        });

        return response()->json([
            'message'  => 'Vistoria registrada.',
            'vistoria' => [
                'id'          => $vistoria->id,
                'data_hora'   => $vistoria->data_hora?->format('d/m/Y H:i'),
                'area'        => $vistoria->areaAferidaRotulo(),
                'exigencias'  => $vistoria->exigencias()->count(),
                'artigos'     => $vistoria->artigos()->count(),
                'evidencias'  => $vistoria->evidencias()->count(),
            ],
        ], 201);
    }

    /**
     * GET /evidencias/{evidencia}/arquivo
     *
     * Serve o arquivo por rota autenticada. Nunca por link direto em
     * `public/`: quem descobrisse o caminho veria a foto sem login.
     */
    public function arquivo(Evidencia $evidencia)
    {
        abort_unless(Storage::disk('private')->exists($evidencia->arquivo), 404);

        return Storage::disk('private')->response(
            $evidencia->arquivo,
            $evidencia->nome_original,
            ['Content-Type' => $evidencia->mime ?? 'application/octet-stream']
        );
    }

    /** DELETE /api/evidencias/{evidencia} — só o autor, admin não é exceção. */
    public function excluirEvidencia(Request $request, Evidencia $evidencia): JsonResponse
    {
        if (! $evidencia->podeSerExcluidaPor($request->user())) {
            return response()->json([
                'message' => 'Só quem cadastrou a evidência pode excluí-la.',
            ], 403);
        }

        Storage::disk('private')->delete($evidencia->arquivo);
        $evidencia->delete();

        return response()->json(['message' => 'Evidência excluída.']);
    }
}
