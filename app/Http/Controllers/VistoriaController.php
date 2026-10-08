<?php

namespace App\Http\Controllers;

use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Obra;
use App\Models\Evidencia;
use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Vistoria;
use App\Models\VistoriaArtigo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use App\Services\LavraturaService;
use App\Services\SucessaoDeLotes;
use App\Services\VistoriaImpressao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * O relatório na janela de impressão do navegador.
     *
     * Mesma dupla do documento: esta rota serve HTML (imagens pela rota
     * autenticada) e a de baixo serve PDF (imagens embutidas em base64).
     */
    public function impressao(Vistoria $vistoria, VistoriaImpressao $impressao): Response
    {
        $dados = $impressao->montar($vistoria, paraPdf: false);

        return response()->view('impressao.vistoria', $dados + ['navegador' => true]);
    }

    /** O mesmo relatório em PDF, para anexar ao processo. */
    public function pdf(Vistoria $vistoria, VistoriaImpressao $impressao): Response
    {
        $dados = $impressao->montar($vistoria, paraPdf: true);

        $pdf = Pdf::loadView('impressao.vistoria', $dados + ['navegador' => false])->setPaper('a4');

        // O nome do arquivo não aceita "/" — e numeroFormatado() tem um
        // ("VIS 2026/0001"), por ser o formato natural de citar a vistoria.
        return $pdf->stream(str_replace('/', '-', $vistoria->numeroFormatado()) . '.pdf');
    }

    /**
     * UMA vistoria, para leitura.
     *
     * Existia só o formulário de criar: depois de gravada, a vistoria virava
     * uma linha na linha do tempo e mais nada — as fotos, o relatório e o que
     * o fiscal escreveu sobre cada artigo não tinham como ser revistos. Num
     * processo administrativo isso é o oposto do que se espera: o ato tem de
     * poder ser reaberto e conferido, inclusive por quem não o praticou.
     *
     * O que sai daqui é O QUE FOI CONSTATADO, na ordem em que foi escrito —
     * `relatorio()` já intercala fotos e itens de lei como o fiscal montou.
     */
    public function mostrar(Vistoria $vistoria): JsonResponse
    {
        $vistoria->load([
            'fiscal:id,name', 'lote:id,bairro,quadra,numero_lote,inscricao_imobiliaria',
            'itens.artigos.artigo', 'itens.exigencias', 'itens.evidencias',
            'evidencias', 'itensDeArtigo.artigo', 'exigencias', 'artigos',
            'documentos:id,vistoria_id,tipo,numero,exercicio,status,data_lavratura',
        ]);

        // As fotos entram no relatório pela URL do arquivo — a mesma rota
        // protegida que a ficha usa, e não um caminho de disco.
        $fotos = $vistoria->evidencias->keyBy('id');

        // A URL vale para IMAGEM e para PDF: no primeiro caso a tela exibe, no
        // segundo oferece para abrir. Quem decide é `imagem`, que vem do mime
        // real do arquivo — ver `Vistoria::relatorioEmItens()`.
        $relatorio = $vistoria->relatorioEmItens()->map(function (array $item) use ($fotos) {
            $item['fotos'] = array_map(function (array $f) use ($fotos) {
                if ($e = $fotos->get($f['id'])) {
                    $f['url'] = route('evidencia.arquivo', $e);
                }

                return $f;
            }, $item['fotos']);

            return $item;
        })->all();

        return response()->json(['vistoria' => [
            'id'          => $vistoria->id,
            'numero'      => $vistoria->numeroFormatado(),
            'quando'      => $vistoria->data_hora?->format('d/m/Y H:i'),
            'finalidade'  => $vistoria->finalidadeRotulo(),
            'situacao'    => [
                'texto'  => $vistoria->situacaoRotulo(),
                'classe' => $vistoria->situacaoBadge(),
            ],
            'fiscal'      => $vistoria->fiscal?->name,
            'imovel'      => $vistoria->lote
                ? trim("Qd. {$vistoria->lote->quadra} Lt. {$vistoria->lote->numero_lote} — {$vistoria->lote->bairroOficial()}")
                : null,
            'observacoes' => $vistoria->observacoes,
            'acompanhante' => $vistoria->acompanhante_nome ? [
                'nome'  => $vistoria->acompanhante_nome,
                'qual'  => Vistoria::QUALIFICACOES[$vistoria->acompanhante_qualificacao] ?? null,
            ] : null,
            // Só os blocos desta finalidade: numa atualização cadastral não se
            // pergunta fase de obra, e mostrar o campo vazio faria parecer que
            // alguém olhou e não achou.
            'obra'        => $this->obraDaVistoria($vistoria),
            'gps'         => $vistoria->latitude ? [
                'lat'  => (float) $vistoria->latitude,
                'lon'  => (float) $vistoria->longitude,
                'prec' => $vistoria->accuracy,
            ] : null,
            'artigos'     => $vistoria->artigos->map(fn ($a) => [
                'numero' => $a->numero, 'texto' => $a->texto ?? null,
            ])->all(),
            'exigencias'  => $vistoria->exigencias->map(fn ($e) => [
                'texto' => $e->texto, 'prazo' => $e->prazo_dias,
            ])->all(),
            'relatorio'   => $relatorio,
            // O que esta constatação virou. É a pergunta de quem reabre o caso
            // meses depois — e a resposta "nada ainda" também importa: é ela
            // que o painel cobra como "vistoria irregular sem documento".
            'documentos'  => $vistoria->documentos->map(fn (Documento $d) => [
                'id'     => $d->id,
                'numero' => $d->numeroFormatado(),
                'tipo'   => $d->rotuloTipo(),
                'data'   => ($d->data_lavratura ?? $d->created_at)?->format('d/m/Y'),
                'status' => $d->statusBadge()[0],
            ])->all(),
        ]]);
    }

    /** Os campos de obra que pertencem à finalidade desta vistoria. */
    private function obraDaVistoria(Vistoria $v): array
    {
        $blocos = $v->camposDaFinalidade();
        $dados = [];

        if (in_array('alvara', $blocos, true)) {
            $rotulo = Vistoria::ALVARA[$v->alvara_situacao] ?? null;
            $dados['Alvará'] = $rotulo
                ? $rotulo
                    . ($v->alvara_numero ? " nº {$v->alvara_numero}" : '')
                    . ($v->alvara_vencimento ? ' — vencimento ' . $v->alvara_vencimento->format('d/m/Y') : '')
                : null;
        }
        if (in_array('area', $blocos, true)) {
            // A frase que falta importa mais que as outras: sem área medida a
            // multa por metro quadrado não é calculada, e quem lê o relatório
            // precisa saber disso antes de contar com o valor.
            $dados['Área aferida'] = $v->areaAferidaRotulo();
        }
        if (in_array('obra', $blocos, true)) {
            $dados['Fase da obra'] = Vistoria::FASES_OBRA[$v->fase_obra] ?? null;
            $dados['Conforme o projeto'] = Vistoria::CONFORMIDADES[$v->conforme_projeto] ?? null;
        }
        if (in_array('uso', $blocos, true)) {
            $dados['Uso constatado'] = Vistoria::USOS[$v->uso_constatado] ?? null;
        }
        if (in_array('idade', $blocos, true)) {
            $dados['Época da construção'] = $v->ano_construcao_estimado
                ? 'por volta de ' . $v->ano_construcao_estimado
                : null;
        }

        // O VAZIO É DITO, e não escondido.
        //
        // Antes a linha sumia, e uma vistoria pouco preenchida abria quase em
        // branco — parecendo falha de carregamento. Pior: num processo, "não
        // informado" e "não perguntado" são coisas diferentes, e esconder a
        // linha apagava essa diferença. Só aparecem os campos DESTA finalidade;
        // os que ela não pergunta continuam fora, porque aí ninguém deveria
        // mesmo ter respondido.
        return array_map(
            fn ($x) => ($x === null || $x === '') ? null : $x,
            $dados
        );
    }

    public function historico(Lote $lote): JsonResponse
    {
        $vistorias = $lote->vistorias()
            ->with(['fiscal:id,name', 'itensDeArtigo.artigo:id,numero,apelido'])
            ->withCount('evidencias')
            ->get()
            ->map(fn (Vistoria $v) => [
                'id'               => $v->id,
                'numero'           => $v->numeroFormatado(),
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
                // As infrações são os artigos CITADOS (o parecer do fiscal
                // não é enquadramento), um por linha, sem repetir.
                'artigos'          => $v->itensDeArtigo->where('tipo', 'citacao')
                    ->map(fn ($a) => $a->artigo ? 'Art. ' . $a->artigo->numero
                        . ($a->artigo->apelido ? ' — ' . $a->artigo->apelido : '') : null)
                    ->filter()->unique()->values()->all(),
            ]);

        // Linha do tempo: vistoria não é o único fato da vida do imóvel. O que
        // o fiscal precisa ver antes da visita é a sequência inteira — vistoria,
        // documento lavrado e requerimento do contribuinte —, porque é ela que
        // explica em que pé o processo está.
        $eventos = [];

        foreach ($vistorias as $v) {
            $eventos[] = [
                'tipo'    => 'vistoria',
                // O id abre o registro: cada marco da linha do tempo leva ao
                // ato que o produziu, e sem ele o clique nao teria destino.
                'id'      => $v['id'],
                // O ato de desmembramento precisa saber QUAL lote dividir, e a
                // linha do tempo e a unica coisa que a tela tem em maos ali.
                'lote_id' => $lote->id,
                'quando'  => $v['data_hora'],
                'titulo'  => $v['numero'] . ' — ' . $v['situacao_rotulo'],
                'detalhe' => $v['fiscal'] ? 'Fiscal: ' . $v['fiscal'] : null,
                'badge'   => ['texto' => $v['situacao_rotulo'], 'classe' => $v['situacao_badge']],
                'ato_cadastral' => $v['ato_cadastral'],
                'itens'   => $v['artigos'],
                'obs'     => $v['observacoes'],
            ];
        }

        // Rascunho é trabalho em curso do fiscal, não ato: quem é de fora só
        // fica sabendo do documento depois de lavrado.
        $externo = ! auth()->user()?->podeVerDocumentos();
        foreach (Documento::where('lote_id', $lote->id)
                     ->when($externo, fn ($q) => $q->where('status', '<>', 'rascunho'))->get() as $d) {
            [$sTxt, $sCls] = $d->statusBadge();
            $eventos[] = [
                'tipo'    => 'documento',
                'id'      => $d->id,
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
                'id'      => $p->id,
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

        // QUEM É DE FORA SABE QUE HOUVE, NÃO O QUE HOUVE.
        //
        // Topógrafo, arquiteto e contribuinte veem a linha do tempo com o tipo,
        // o número, a data e a situação — o bastante para saber que o imóvel
        // tem processo. Sai o id (sem ele não há o que abrir), o fiscal, o
        // autuado, o requerente, os artigos citados e a descrição. As rotas de
        // abertura estão fechadas de qualquer forma (middleware `interno`);
        // cortar aqui é não mandar ao navegador o que ele não vai mostrar.
        if ($externo) {
            $eventos = array_map(fn ($e) => [
                'tipo'     => $e['tipo'],
                'quando'   => $e['quando'] ? substr($e['quando'], 0, 10) : null,
                'titulo'   => $e['titulo'],
                'badge'    => $e['badge'],
                'itens'    => [],
                'restrito' => true,
            ], $eventos);
            $vistorias = collect();
        }

        // SINALIZAÇÕES — abertas e resolvidas: é aqui que ficam registradas
        // depois de sair do mapa e do Painel. Entram DEPOIS da redação acima
        // porque não são conteúdo da fiscalização: quem é de fora vê as dele
        // por inteiro (e só as dele — scopeVisiveisPara). O lembrete ainda
        // não vencido não entra: ele não aconteceu.
        $pendentes = [];
        $sins = \App\Models\Sinalizacao::where('lote_id', $lote->id)
            ->visiveisPara(auth()->user())
            ->where(fn ($q) => $q->whereNull('lembrar_em')->orWhere('lembrar_em', '<=', now()->toDateString())
                ->orWhere('status', 'resolvida'))
            ->with(['autor:id,name', 'resolvedor:id,name'])->get();
        foreach ($sins as $s) {
            $aberta = $s->status === 'aberta';
            $eventos[] = [
                'tipo'     => 'sinalizacao',
                'quando'   => ($s->lembrar_em && $s->tipo === 'lembrete' ? $s->lembrar_em : $s->created_at)?->format('d/m/Y H:i'),
                'titulo'   => ($s->tipo === 'lembrete' ? 'Lembrete' : 'Sinalização · ' . $s->rotulo()),
                'detalhe'  => 'Aberta em ' . $s->created_at?->format('d/m/Y') . ' por ' . ($s->autor?->name ?? '—')
                    . ($aberta ? '' : ' · resolvida por ' . ($s->resolvedor?->name ?? '—') . ': ' . $s->resolucao),
                'badge'    => $aberta ? ['texto' => 'Pendente', 'classe' => 'bd-pe']
                    : ['texto' => 'Resolvida ' . $s->resolvida_em?->format('d/m'), 'classe' => 'bd-ok'],
                'itens'    => [],
                'obs'      => $s->comentario,
            ];
            if ($aberta) {
                $pendentes[] = ['id' => $s->id, 'rotulo' => $s->rotulo(), 'comentario' => $s->comentario,
                    'autor' => $s->autor?->name, 'criada_em' => $s->created_at?->format('d/m/Y')];
            }
        }
        usort($eventos, fn ($a, $b) => strcmp(
            $this->chaveOrdem($b['quando']), $this->chaveOrdem($a['quando'])
        ));

        return response()->json([
            'lote'      => $lote->only(['id', 'bairro', 'quadra', 'numero_lote', 'chave']),
            'vistorias' => $vistorias,
            'eventos'   => $eventos,
            'resumo'    => $this->resumoDoImovel($lote),
            // Para o aviso âmbar da ficha.
            'sinalizacoes_pendentes' => $pendentes,
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
     *   inativo    o lote deixou de existir por unificação ou desmembramento,
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
            $lote->situacao === 'inativo' => ['Inativo', 'bd-cx'],
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

    /**
     * Lê as marcações de uma foto, vindas como texto JSON no multipart.
     *
     * Só passa o que tem forma de marcação: número e um par (x, y) dentro do
     * quadro. Coordenada fora de 0..1 apontaria para fora da imagem, e uma
     * marcação que aponta para lugar nenhum é pior do que marcação nenhuma.
     *
     * @return array<int, array{n:int, x:float, y:float}>|null
     */
    private static function marcacoesDe(?string $bruto): ?array
    {
        if (! $bruto) { return null; }

        $lidas = json_decode($bruto, true);
        if (! is_array($lidas)) { return null; }

        $boas = [];
        foreach ($lidas as $m) {
            if (! isset($m['x'], $m['y'])) { continue; }
            $x = (float) $m['x'];
            $y = (float) $m['y'];
            if ($x < 0 || $x > 1 || $y < 0 || $y > 1) { continue; }
            $boas[] = ['n' => (int) ($m['n'] ?? count($boas) + 1),
                       'x' => round($x, 4), 'y' => round($y, 4)];
        }

        return $boas ?: null;
    }

    /** Teto de artigos numa busca: o que passa disso é busca vaga demais para ler. */
    private const MAX_BUSCA_ARTIGOS = 30;

    /**
     * GET /api/artigos/busca?q=escav — os artigos que tratam do que o fiscal viu.
     * GET /api/artigos/busca?ids=3,7 — os mesmos dados, pelos ids (rascunho
     * retomado: o item já cita artigos que a tela ainda não conhece).
     *
     * O fiscal procura o PROBLEMA, não o número do dispositivo: casa com os
     * termos de busca do artigo ("escavação", "terraplenagem"…), com número,
     * apelido e conduta, sem acento e sem caixa (Artigo::casaCom). Busca em
     * TODAS as leis — antes só aparecia artigo sugerido por irregularidade
     * marcada, e sem marcação a lista ficava vazia.
     *
     * A comparação é feita aqui, sobre os artigos ativos, e não em SQL: o
     * catálogo é de dezenas a poucas centenas de linhas, e "sem acento" em
     * SQL dependeria da colação de cada servidor.
     */
    public function buscarArtigos(Request $request): JsonResponse
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $q = (string) $request->query('q', '');

        $artigos = Artigo::query()->with('legislacao:id,numero,nome')
            ->when($ids, fn ($b) => $b->whereIn('id', $ids), fn ($b) => $b->ativos())
            ->get();

        $achados = [];
        foreach ($artigos as $a) {
            $casou = $ids ? null : $a->casaCom($q);
            if (! $ids && $casou === null) {
                continue;
            }
            $achados[] = [
                'id'            => $a->id,
                'numero'        => $a->numero,
                'rotulo'        => $a->rotulo(),
                'conduta'       => $a->conduta,
                'base'          => Artigo::BASES_MULTA[$a->base_multa] ?? null,
                // A multa deste artigo depende da área CONSTRUÍDA (por m² ou
                // por faixa): é o aviso para o fiscal medir a obra em campo.
                'por_m2'        => in_array($a->base_multa, Artigo::BASES_POR_AREA, true) && $a->multa_area !== 'terreno',
                'lei'           => $a->legislacao?->numero,
                'legislacao_id' => $a->legislacao_id,
                'termos'        => $a->termos ?? [],
                // A vistoria cita qualquer artigo; a etiqueta só avisa o
                // fiscal de que este entra em peça de embargo.
                'embargo'       => $a->rotuloEmbargo(),
                'casou'         => $casou,
            ];
            if (count($achados) >= self::MAX_BUSCA_ARTIGOS) {
                break;
            }
        }

        return response()->json(['artigos' => $achados]);
    }

    /**
     * POST /api/lotes/{lote}/vistorias
     *
     * Grava a vistoria, os itens (com os artigos citados) e as fotos, tudo numa
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
            // A finalidade decide o que a vistoria pergunta — ver
            // Vistoria::FINALIDADES. Sem ela, o padrão é fiscalização de obra,
            // que é o que a tela fazia antes de haver escolha.
            'finalidade'         => ['nullable', Rule::in(array_keys(Vistoria::FINALIDADES))],
            'observacoes'        => ['nullable', 'string', 'max:5000'],
            'latitude'           => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'          => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy'           => ['nullable', 'numeric', 'min:0'],
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
            // Posição da foto no RELATÓRIO — a sequência é compartilhada com
            // os itens de lei, e é ela que dá sentido de leitura ao conjunto.
            'ordens'             => ['array'],
            'ordens.*'           => ['nullable', 'integer', 'min:0', 'max:200'],
            // Marcações sobre a imagem, em JSON: [{n, x, y}] com x e y de 0 a 1.
            'marcacoes'          => ['array'],
            'marcacoes.*'        => ['nullable', 'string', 'max:4000'],
            // QUANDO E ONDE CADA FOTO FOI FEITA — colunas que existiam desde a
            // primeira migração e vinham preenchidas com os dados DA VISTORIA,
            // iguais para todas. Chegam por índice, pareadas com evidencias[],
            // e caem no valor da vistoria quando o aparelho não soube dizer.
            'fotos_quando'       => ['array'],
            'fotos_quando.*'     => ['nullable', 'date'],
            'fotos_lat'          => ['array'],
            'fotos_lat.*'        => ['nullable', 'numeric', 'between:-90,90'],
            'fotos_lon'          => ['array'],
            'fotos_lon.*'        => ['nullable', 'numeric', 'between:-180,180'],

            // ── a obra ──
            'acompanhante_nome'         => ['nullable', 'string', 'max:160'],
            'acompanhante_qualificacao' => ['nullable', Rule::in(array_keys(Vistoria::QUALIFICACOES))],
            'alvara_situacao'    => ['nullable', Rule::in(array_keys(Vistoria::ALVARA))],
            'alvara_numero'      => ['nullable', 'string', 'max:40'],
            'alvara_vencimento'  => ['nullable', 'date'],
            'area_construida_aferida_m2' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'area_metodo'        => ['nullable', Rule::in(array_keys(Vistoria::METODOS_AREA))],
            'fase_obra'          => ['nullable', Rule::in(array_keys(Vistoria::FASES_OBRA))],
            'conforme_projeto'   => ['nullable', Rule::in(array_keys(Vistoria::CONFORMIDADES))],
            'uso_constatado'     => ['nullable', Rule::in(array_keys(Vistoria::USOS))],
            'ano_construcao_estimado' => ['nullable', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],

            // ── o relatório ──
            // `artigos[]` continua sendo o CONJUNTO de dispositivos que a
            // vistoria envolve — é o que a lavratura consulta. Os itens
            // abaixo são o TEXTO que o fiscal escreveu sobre cada um.
            // ── O RELATÓRIO EM ITENS ──
            //
            // Cada item é um grupo: artigos, texto livre,
            // exigências e fotos. As FOTOS não vêm aninhadas aqui — arquivo
            // sobe na remessa achatada `evidencias[]`, que é como upload
            // funciona; o item aponta para elas pelo índice.
            'itens'                          => ['array', 'max:60'],
            'itens.*.texto'                  => ['nullable', 'string', 'max:5000'],
            'itens.*.artigos'                => ['array', 'max:50'],
            'itens.*.artigos.*.artigo_id'    => ['required', 'integer', 'exists:artigos,id'],
            'itens.*.artigos.*.tipo'         => ['required', Rule::in(array_keys(VistoriaArtigo::TIPOS))],
            'itens.*.artigos.*.observacao'   => ['nullable', 'string', 'max:2000'],
            'itens.*.exigencias'             => ['array', 'max:30'],
            'itens.*.exigencias.*.texto'     => ['required', 'string', 'max:500'],
            'itens.*.exigencias.*.prazo_dias' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'itens.*.fotos'                  => ['array'],
            'itens.*.fotos.*'                => ['integer', 'min:0', 'max:200'],
            // Protocolo de desmembramento/unificacao que esta vistoria atende.
            // E o vinculo que, mais tarde, libera o ato cadastral — ver
            // App\Services\SucessaoDeLotes::atoDaVistoria().
            'protocolo_id'       => ['nullable', 'integer', 'exists:protocolos,id'],
            // SINALIZAÇÕES: as pendências do lote que esta vistoria atende (o
            // formulário as traz marcadas) e o lembrete de voltar. O sinal
            // `sinalizacoes_enviadas` distingue "desmarcou todas" de "o
            // formulário não mandou a lista" — ver SinalizacaoController.
            'sinalizacoes_enviadas'   => ['nullable', 'boolean'],
            'resolver_sinalizacoes'   => ['array'],
            'resolver_sinalizacoes.*' => ['integer'],
            'lembrar_em'              => ['nullable', 'date', 'after:today'],
            'lembrete_motivo'         => ['nullable', 'string', 'max:300'],
        ], [
            'data_hora.date_format' => 'Informe data e hora da vistoria.',
            'evidencias.*.max'      => 'Cada arquivo deve ter no máximo 12 MB.',
            'evidencias.*.mimetypes' => 'Envie apenas imagens ou PDF.',
            'exigencias.*.texto.required' => 'Exigência sem texto não pode ser gravada.',
        ]);

        // Área medida sem dizer COMO é número que não se sustenta em defesa. E
        // método sem área é campo órfão. Os dois andam juntos ou nenhum anda.
        $finalidade = $d['finalidade'] ?? 'obras';
        $blocos = Vistoria::FINALIDADES[$finalidade]['campos'];

        if (in_array('area', $blocos, true)
            && ! empty($d['area_construida_aferida_m2']) && empty($d['area_metodo'])) {
            return response()->json([
                'message' => 'Informe como a área foi obtida (trena, estimativa, projeto ou croqui).',
                'errors'  => ['area_metodo' => ['Escolha o método.']],
            ], 422);
        }

        // Uma vistoria irregular sem nenhum artigo CITADO é um registro que
        // não sustenta documento nenhum depois: só se atua no que está fora da
        // lei, e é o artigo que diz o que está. Barrar aqui evita descobrir
        // isso na hora de lavrar a notificação. (Parecer não conta: é a
        // opinião do fiscal sobre o artigo, não o enquadramento.)
        $citados = collect($d['itens'] ?? [])
            ->flatMap(fn ($i) => $i['artigos'] ?? [])
            ->where('tipo', 'citacao');
        if ($d['situacao'] === 'irregular' && $citados->isEmpty()) {
            return response()->json([
                'message' => 'Cite ao menos um artigo para uma vistoria irregular.',
                'errors'  => ['itens' => ['Cite ao menos um artigo.']],
            ], 422);
        }

        $vistoria = DB::transaction(function () use ($request, $lote, $u, $d, $finalidade) {
            // O número nasce COM a vistoria, e não numa etapa depois: ela não
            // tem "lavrar" — nasce valendo, e é por ele que o relatório será
            // citado. A série é a mesma máquina dos autos, travada em
            // transação (estamos dentro de uma).
            ['numero' => $numero, 'exercicio' => $exercicio] =
                LavraturaService::proximoNumero('vistoria');

            $v = Vistoria::create([
                'lote_id'     => $lote->id,
                'fiscal_id'   => $u->id,
                'exercicio'   => $exercicio,
                'numero'      => $numero,
                // Gravado como string local "ingênua" (aaaa-mm-ddThh:mm), sem
                // conversão de fuso — ver comentário na migration.
                'data_hora'   => str_replace('T', ' ', $d['data_hora']) . ':00',
                'situacao'    => $d['situacao'],
                'observacoes' => $d['observacoes'] ?? null,
                'latitude'    => $d['latitude'] ?? null,
                'longitude'   => $d['longitude'] ?? null,
                'accuracy'    => $d['accuracy'] ?? null,

                'finalidade'                => $finalidade,
                'acompanhante_nome'         => $d['acompanhante_nome'] ?? null,
                'acompanhante_qualificacao' => $d['acompanhante_qualificacao'] ?? null,
                'alvara_situacao'    => $d['alvara_situacao'] ?? null,
                'alvara_numero'      => $d['alvara_numero'] ?? null,
                'alvara_vencimento'  => $d['alvara_vencimento'] ?? null,
                'area_construida_aferida_m2' => $d['area_construida_aferida_m2'] ?? null,
                'area_metodo'        => $d['area_metodo'] ?? null,
                'fase_obra'          => $d['fase_obra'] ?? null,
                'conforme_projeto'   => $d['conforme_projeto'] ?? null,
                'ano_construcao_estimado' => $d['ano_construcao_estimado'] ?? null,
                'uso_constatado'     => $d['uso_constatado'] ?? null,
            ]);

            // O que veio sobrando é apagado, e não guardado "por via das
            // dúvidas": campo fora da finalidade é dado que ninguém conferiu
            // em campo, e dado não conferido com cara de conferido é pior, num
            // processo administrativo, do que campo vazio.
            $fora = Vistoria::colunasForaDa($finalidade);
            if ($fora) {
                $v->forceFill(array_fill_keys($fora, null))->save();
            }

            // ── OS ITENS DO RELATÓRIO ──
            //
            // A ordem entre itens é a que o fiscal montou — é a sequência em
            // que ele percorreu a obra, e o relatório impresso a segue.
            // A ordem DENTRO do item é fixa e não se escolhe (ver VistoriaItem).
            $itensCriados = [];

            foreach ($d['itens'] ?? [] as $n => $bloco) {
                $item = $v->itens()->create([
                    'ordem' => $n,
                    'texto' => isset($bloco['texto']) ? (trim($bloco['texto']) ?: null) : null,
                ]);
                $itensCriados[$n] = $item;

                foreach ($bloco['artigos'] ?? [] as $j => $art) {
                    $item->artigos()->create([
                        'vistoria_id' => $v->id,
                        'artigo_id'   => $art['artigo_id'],
                        'tipo'        => $art['tipo'],
                        'observacao'  => isset($art['observacao']) ? (trim($art['observacao']) ?: null) : null,
                        'ordem'       => $j,
                    ]);
                }

                foreach ($bloco['exigencias'] ?? [] as $j => $ex) {
                    $item->exigencias()->create([
                        'vistoria_id' => $v->id,
                        'ordem'       => $j,
                        'texto'       => trim($ex['texto']),
                        'prazo_dias'  => $ex['prazo_dias'] ?? null,
                    ]);
                }
            }

            // De qual item é cada arquivo da remessa. O upload é achatado —
            // `evidencias[]` — e o item aponta pelo índice; este mapa faz o
            // caminho de volta na hora de gravar cada evidência.
            $itemDaFoto = [];
            foreach ($d['itens'] ?? [] as $n => $bloco) {
                foreach ($bloco['fotos'] ?? [] as $indice) {
                    $itemDaFoto[(int) $indice] = $itensCriados[$n]->id ?? null;
                }
            }

            // Enquadramento constatado em campo: os artigos nascem DENTRO do
            // item, no laço acima, cada um com o texto que o fiscal escreveu
            // sobre ele. (Havia um `artigos[]` achatado, sincronizado à parte,
            // que podia apagar o que os itens gravaram; saiu.)

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

            // `item_id` vem do mapa montado acima: o arquivo sobe achatado e o
            // item o reivindica pelo índice da remessa. Sem dono, a foto ainda
            // é gravada — prova não se descarta por falta de grupo.
            foreach ($request->file('evidencias', []) as $i => $arquivo) {
                // Disco privado: foto de fiscalização mostra propriedade
                // privada e identifica pessoas.
                $caminho = $arquivo->store("evidencias/{$v->id}", 'private');
                Evidencia::create([
                    'vistoria_id'   => $v->id,
                    'item_id'       => $itemDaFoto[$i] ?? null,
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
                    'ordem'         => $d['ordens'][$i] ?? $i,
                    // Chega como JSON de texto porque o envio é multipart —
                    // decodificado aqui, e descartado em silêncio se vier
                    // corrompido: marcação perdida não pode custar a foto.
                    'marcacoes'     => self::marcacoesDe($d['marcacoes'][$i] ?? null),
                    // A da FOTO quando o aparelho soube dizer; a da VISTORIA
                    // como piso. Sem isso, toda evidência nascia com a hora do
                    // lançamento e a coordenada de um ponto só — o que, num
                    // processo, é justamente o que a foto deveria provar.
                    'latitude'      => $d['fotos_lat'][$i] ?? $d['latitude'] ?? null,
                    'longitude'     => $d['fotos_lon'][$i] ?? $d['longitude'] ?? null,
                    'data_hora'     => isset($d['fotos_quando'][$i])
                        ? Carbon::parse($d['fotos_quando'][$i])
                        : str_replace('T', ' ', $d['data_hora']) . ':00',
                    'criado_por'    => $u->id,
                ]);
            }

            // A vistoria ATENDE as sinalizações do lote, e pode deixar o
            // lembrete de voltar. Na mesma transação: vistoria gravada com a
            // pendência ainda aberta seria a bandeira que não sai do mapa.
            SinalizacaoController::aoRegistrarVistoria(
                $v,
                ! empty($d['sinalizacoes_enviadas']) ? ($d['resolver_sinalizacoes'] ?? []) : null,
                $d['lembrar_em'] ?? null,
                $d['lembrete_motivo'] ?? null,
            );

            return $v;
        });

        return response()->json([
            'message'  => 'Vistoria registrada.',
            'vistoria' => [
                'id'          => $vistoria->id,
                'numero'      => $vistoria->numeroFormatado(),
                'situacao'    => $vistoria->situacao,
                'data_hora'   => $vistoria->data_hora?->format('d/m/Y H:i'),
                'finalidade'  => $vistoria->finalidadeRotulo(),
                'area'        => $vistoria->areaAferidaRotulo(),
                'exigencias'  => $vistoria->exigencias()->count(),
                'artigos'     => $vistoria->itensDeArtigo()->count() ?: $vistoria->artigos()->count(),
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
