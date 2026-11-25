<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\OrdemServico;
use App\Models\Protocolo;
use App\Models\Sinalizacao;
use App\Models\Vistoria;
use App\Support\Modulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A FILA DE TRABALHO, numa lista só.
 *
 * Protocolo e ordem de serviço respondem à mesma pergunta — "o que há para
 * fazer?" — e viviam em duas abas, obrigando a olhar duas listas para saber o
 * que estava pendente. Agora são uma lista, com o TIPO em coluna.
 *
 * ── O que NÃO foi unificado, e por quê ──
 *
 * As duas tabelas continuam separadas no banco, e devem continuar. Só quatro
 * colunas coincidem em significado (`numero`, `lote_id`, `situacao`,
 * `objeto`): o protocolo tem requerente, CPF, prazo legal de resposta e
 * parecer; a ordem tem designados, natureza, regime, jornadas (uma tabela
 * filha), assinatura de quem emite e encerramento. Numa tabela só, 23 das 30
 * colunas ficariam nulas metade do tempo.
 *
 * E a ordem REFERENCIA o protocolo (`ordens_servico.protocolo_id`): uma nasce
 * da outra. São parentes, não a mesma coisa — e é justamente por serem
 * parentes que aparecem juntas na fila.
 *
 * O que se unificou foi a LEITURA. Cada uma continua com o seu formulário,
 * a sua ficha e as suas regras.
 */
class DemandaController extends Controller
{
    /**
     * GET /api/demandas
     *
     * As duas fontes normalizadas para a mesma forma de linha, juntas e
     * ordenadas pela data — que é como a fila se lê.
     */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();

        $d = $request->validate([
            'tipo'     => ['nullable', 'in:protocolo,os,vistoria,sinalizacao,documento'],
            'situacao' => ['nullable', 'string', 'max:40'],
            'agente'   => ['nullable', 'in:eu,todos,sem_dono'],
            'busca'    => ['nullable', 'string', 'max:80'],
        ]);

        $modulo = Modulo::ativo($u);
        $tipos  = self::TIPOS_DO_MODULO[$modulo] ?? self::TIPOS_DO_MODULO['obras'];
        // Um tipo que não é deste módulo não traz nada — e não "tudo".
        $tipo   = $d['tipo'] ?? '';
        if ($tipo !== '' && ! in_array($tipo, $tipos, true)) {
            $tipo = '__nenhum';
        }
        $escopo = $d['agente'] ?? 'todos';
        $texto  = $d['busca'] ?? null;
        $sit    = $d['situacao'] ?? null;

        $quer = fn (string $t) => in_array($t, $tipos, true) && ($tipo === '' || $tipo === $t);

        $linhas = collect();
        if ($quer('protocolo'))   { $linhas = $linhas->concat($this->protocolos($u->id, $escopo, $texto, $sit)); }
        if ($quer('os'))          { $linhas = $linhas->concat($this->ordens($u->id, $escopo, $texto, $sit)); }
        if ($quer('vistoria'))    { $linhas = $linhas->concat($this->vistorias($u->id, $escopo, $texto, $sit)); }
        if ($quer('documento'))   { $linhas = $linhas->concat($this->documentos($u->id, $escopo, $texto, $sit, $modulo)); }
        if ($quer('sinalizacao')) { $linhas = $linhas->concat($this->sinalizacoes($u, $escopo, $texto, $sit, $modulo)); }

        // Ordena pela data, do mais recente para o mais antigo. `ordem` é a
        // data em formato comparável (aaaa-mm-dd), montada em cada fonte — a
        // exibida ("11/08/2026") ordenaria por dia antes de por ano.
        $linhas = $linhas->sortByDesc('ordem')->values();

        return response()->json([
            'demandas' => $linhas,
            'total'    => $linhas->count(),
            // Quantos de cada tipo, para o filtro dizer o que existe.
            'contagem' => collect($tipos)->mapWithKeys(fn ($t) => [$t => $linhas->where('tipo', $t)->count()]),
            'tipos'    => collect($tipos)->map(fn ($t) => ['valor' => $t, 'rotulo' => self::ROTULOS[$t]])->values(),
            'tipos_protocolo' => collect(Protocolo::TIPOS)
                ->map(fn ($r, $v) => ['valor' => $v, 'rotulo' => $r])->values(),
            // Agrupadas por tipo: as situações não são as mesmas entre eles, e
            // um seletor plano faria "Deferido" e "Concluída" parecerem
            // alternativas da mesma pergunta. Só as dos tipos deste módulo.
            'situacoes' => collect([
                'protocolo' => fn () => collect(Protocolo::SITUACOES)->map(fn ($s, $v) => ['valor' => $v, 'rotulo' => $s[0]])->values(),
                'os'        => fn () => collect(OrdemServico::SITUACOES)->map(fn ($s, $v) => ['valor' => $v, 'rotulo' => $s['texto']])->values(),
                'vistoria'  => fn () => collect(Vistoria::SITUACOES)->map(fn ($r, $v) => ['valor' => 'vis_' . $v, 'rotulo' => $r])->values(),
                'documento' => fn () => collect(self::STATUS_DOCUMENTO)->map(fn ($r, $v) => ['valor' => 'doc_' . $v, 'rotulo' => $r])->values(),
            ])->only($tipos)->map(fn ($f) => $f()),
            'pode_protocolar' => $u->canEdit() && in_array('protocolo', $tipos, true),
            'pode_emitir_os'  => $u->isAdmin(),
            'pode_lavrar'     => $u->podeLavrarDocumento(),
        ]);
    }

    /**
     * O que entra na fila de SERVIÇOS de cada módulo (decisão de 24/11/2026):
     *   Obras    — a antiga "Protocolos e ordens de serviço", mais as
     *              vistorias e as sinalizações. Documentos continuam na
     *              tela Documentos.
     *   Posturas — Serviços SUBSTITUI a tela Documentos: os documentos, as
     *              ordens de serviço e as sinalizações. As denúncias de
     *              perturbação do sossego continuam na tela Denúncias.
     */
    const TIPOS_DO_MODULO = [
        'obras'    => ['protocolo', 'os', 'vistoria', 'sinalizacao'],
        'posturas' => ['documento', 'os', 'sinalizacao'],
    ];

    const ROTULOS = [
        'protocolo' => 'Protocolos', 'os' => 'Ordens de serviço', 'vistoria' => 'Vistorias',
        'sinalizacao' => 'Sinalizações', 'documento' => 'Documentos',
    ];

    /** Os status do documento como o filtro os oferece (Documento::statusBadge). */
    const STATUS_DOCUMENTO = [
        'rascunho' => 'Rascunho', 'gravado' => 'Gravado', 'lavrado' => 'Lavrado', 'em_defesa' => 'Em defesa',
        'atendido' => 'Atendido', 'cancelado' => 'Cancelado', 'defendido' => 'Defendido',
    ];

    /** Vistorias de obra: registro do que foi constatado, com o fiscal como responsável. */
    private function vistorias(int $usuarioId, string $escopo, ?string $texto, ?string $situacao)
    {
        // Vistoria nasce feita, com fiscal: não há "sem dono".
        if ($escopo === 'sem_dono') {
            return collect();
        }
        $q = Vistoria::query()->with(['lote:id,bairro,quadra,numero_lote', 'fiscal:id,name']);
        if ($situacao && str_starts_with($situacao, 'vis_') && array_key_exists(substr($situacao, 4), Vistoria::SITUACOES)) {
            $q->where('situacao', substr($situacao, 4));
        } elseif ($situacao) {
            return collect();
        }
        if ($escopo === 'eu') {
            $q->where('fiscal_id', $usuarioId);
        }
        if ($texto) {
            $q->where(fn ($s) => $s->where('numero', 'like', "%{$texto}%")
                ->orWhereHas('lote', fn ($l) => $l->where('quadra', 'like', "%{$texto}%")
                    ->orWhere('numero_lote', 'like', "%{$texto}%")->orWhere('bairro', 'like', "%{$texto}%")));
        }

        return $q->latest('data_hora')->limit(300)->get()->map(fn (Vistoria $v) => [
            'tipo'        => 'vistoria',
            'tipo_rotulo' => 'Vistoria',
            'id'          => $v->id,
            'numero'      => $v->numeroFormatado(),
            'assunto'     => $v->finalidadeRotulo(),
            'quem'        => $v->fiscal?->name,
            'quem_rotulo' => 'Fiscal',
            'imovel'      => $v->lote?->rotuloCompleto(),
            'responsavel' => $v->fiscal?->name,
            'data'        => $v->data_hora?->format('d/m/Y'),
            'ordem'       => $v->data_hora?->format('Y-m-d') ?? '',
            'situacao'    => ['texto' => $v->situacaoRotulo(), 'classe' => $v->situacaoBadge()],
            'alerta'      => null,
        ]);
    }

    /** Documentos do módulo (em Posturas, Serviços faz o papel da tela Documentos). */
    private function documentos(int $usuarioId, string $escopo, ?string $texto, ?string $situacao, string $modulo)
    {
        if ($escopo === 'sem_dono') {
            return collect();
        }
        $q = Documento::query()->doModulo($modulo)->with(['lote:id,bairro,quadra,numero_lote', 'agente:id,name']);
        if ($situacao && str_starts_with($situacao, 'doc_') && array_key_exists(substr($situacao, 4), self::STATUS_DOCUMENTO)) {
            $st = substr($situacao, 4);
            // "Cancelado" junta o nome novo e o antigo do cancelamento.
            $st === 'cancelado' ? $q->whereIn('status', ['cancelado', 'anulado']) : $q->where('status', $st);
        } elseif ($situacao) {
            return collect();
        }
        if ($escopo === 'eu') {
            $q->where('agente_id', $usuarioId);
        }
        if ($texto) {
            $q->where(fn ($s) => $s->where('numero', 'like', "%{$texto}%")
                ->orWhere('autuado_nome', 'like', "%{$texto}%")
                ->orWhere('endereco', 'like', "%{$texto}%"));
        }

        return $q->latest('id')->limit(300)->get()->map(function (Documento $doc) {
            [$txt, $cls] = $doc->statusBadge();
            $quando = $doc->data_lavratura ?? $doc->created_at;
            return [
                'tipo'        => 'documento',
                'tipo_rotulo' => 'Documento',
                'id'          => $doc->id,
                'numero'      => $doc->numeroFormatado(),
                'assunto'     => $doc->rotuloTipo(),
                'quem'        => $doc->autuado_nome,
                'quem_rotulo' => 'Autuado',
                'imovel'      => $doc->lote?->rotuloCompleto() ?? ($doc->endereco ?: null),
                'responsavel' => $doc->agente?->name,
                'data'        => $quando?->format('d/m/Y'),
                'ordem'       => $quando?->format('Y-m-d') ?? '',
                'situacao'    => ['texto' => $txt, 'classe' => $cls],
                'alerta'      => null,
            ];
        });
    }

    /** Sinalizações pendentes do módulo — as mesmas bandeiras do mapa. */
    private function sinalizacoes($u, string $escopo, ?string $texto, ?string $situacao, string $modulo)
    {
        // Sinalização não tem situação além de "aberta": qualquer filtro de
        // situação é de outro tipo.
        if ($situacao) {
            return collect();
        }
        $q = Sinalizacao::query()->pendentes()->visiveisPara($u)->doModulo($modulo)
            ->with(['lote:id,bairro,quadra,numero_lote', 'autor:id,name', 'reforcos']);
        // Ainda não tem responsável: "Meus" são as que a pessoa abriu.
        if ($escopo === 'eu') {
            $q->where('user_id', $u->id);
        }
        if ($texto) {
            $q->where(fn ($s) => $s->where('comentario', 'like', "%{$texto}%")->orWhere('referencia', 'like', "%{$texto}%")
                ->orWhereIn('tipo', collect(Sinalizacao::TIPOS)->filter(fn ($r) => mb_stripos($r, $texto) !== false)->keys()->all())
                ->orWhereHas('lote', fn ($l) => $l->where('quadra', 'like', "%{$texto}%")
                    ->orWhere('numero_lote', 'like', "%{$texto}%")->orWhere('bairro', 'like', "%{$texto}%")));
        }

        return $q->latest()->limit(300)->get()->map(function (Sinalizacao $s) {
            $n = $s->avisos();
            return [
                'tipo'        => 'sinalizacao',
                'tipo_rotulo' => 'Sinalização',
                'id'          => $s->id,
                // Sinalização não tem número: o selo de tipo já diz o que é.
                'numero'      => '—',
                'assunto'     => $s->rotulo() . ($s->comentario ? ' — ' . $s->comentario : ''),
                'quem'        => $s->autor?->name,
                'quem_rotulo' => 'Sinalizada por',
                'imovel'      => $s->lote?->rotuloCompleto() ?? ($s->referencia ?: 'Na via'),
                'responsavel' => null,
                'data'        => $s->created_at?->format('d/m/Y'),
                'ordem'       => $s->created_at?->format('Y-m-d') ?? '',
                'situacao'    => ['texto' => 'Aberta', 'classe' => 'bd-al'],
                'alerta'      => $n > 1 ? ['texto' => $n . ' avisos', 'classe' => 'bd-al'] : null,
                // Para abrir: o lote no mapa, ou o ponto na via.
                'lote_id'     => $s->lote_id,
                'lat'         => $s->lat,
                'lon'         => $s->lng,
            ];
        });
    }

    /** @return \Illuminate\Support\Collection<int,array<string,mixed>> */
    private function protocolos(int $usuarioId, string $escopo, ?string $texto, ?string $situacao)
    {
        $q = Protocolo::query()
            ->with(['lote:id,bairro,quadra,numero_lote', 'responsavel:id,name']);

        // A situação chega de um seletor que mistura os dois tipos; a que não
        // for deste aqui simplesmente não filtra nada — é do outro.
        if ($situacao && array_key_exists($situacao, Protocolo::SITUACOES)) {
            $q->where('situacao', $situacao);
        } elseif ($situacao) {
            return collect();
        }

        match ($escopo) {
            'eu'       => $q->where('responsavel_id', $usuarioId),
            // Protocolo é o único dos dois que pode estar SEM DONO: ele chega
            // de fora e alguém precisa assumir.
            'sem_dono' => $q->whereNull('responsavel_id'),
            default    => null,
        };

        if ($texto) {
            $q->where(function ($s) use ($texto) {
                $s->where('numero', 'like', "%{$texto}%")
                  ->orWhere('requerente_nome', 'like', "%{$texto}%")
                  ->orWhereHas('lote', fn ($l) => $l
                      ->where('quadra', 'like', "%{$texto}%")
                      ->orWhere('numero_lote', 'like', "%{$texto}%")
                      ->orWhere('bairro', 'like', "%{$texto}%"));
            });
        }

        return $q->limit(300)->get()->map(function (Protocolo $p) {
            [$txt, $cls] = $p->situacaoBadge();
            $prazo = $p->situacaoPrazo();

            return [
                'tipo'        => 'protocolo',
                'tipo_rotulo' => 'Protocolo',
                'id'          => $p->id,
                'numero'      => $p->numero,
                'assunto'     => $p->rotuloTipo(),
                'quem'        => $p->requerente_nome,
                'quem_rotulo' => 'Requerente',
                'imovel'      => $p->lote?->rotuloCompleto(),
                'responsavel' => $p->responsavel?->name,
                'data'        => $p->protocolado_em?->format('d/m/Y'),
                'ordem'       => $p->protocolado_em?->format('Y-m-d') ?? '',
                'situacao'    => ['texto' => $txt, 'classe' => $cls],
                // O prazo do município para responder ao cidadão. É o alerta
                // que faz a fila ser trabalhada por urgência, e não por data.
                'alerta'      => $prazo ? ['texto' => $prazo[0], 'classe' => $prazo[1]] : null,
            ];
        });
    }

    /** @return \Illuminate\Support\Collection<int,array<string,mixed>> */
    private function ordens(int $usuarioId, string $escopo, ?string $texto, ?string $situacao)
    {
        // Ordem de serviço tem dono por construção — ela nasce designada. Não
        // há "sem dono" a procurar aqui, então o filtro devolve nada em vez de
        // devolver tudo, que seria o oposto do que se pediu.
        if ($escopo === 'sem_dono') {
            return collect();
        }

        $q = OrdemServico::query()->doModulo()
            ->with(['fiscais:id,name', 'emitente:id,name', 'lote:id,bairro,quadra,numero_lote']);

        if ($situacao && array_key_exists($situacao, OrdemServico::SITUACOES)) {
            $q->where('situacao', $situacao);
        } elseif ($situacao) {
            return collect();
        }

        if ($escopo === 'eu') {
            $q->whereHas('fiscais', fn ($f) => $f->where('users.id', $usuarioId));
        }

        if ($texto) {
            $q->where(fn ($s) => $s
                ->where('numero', 'like', "%{$texto}%")
                ->orWhere('objeto', 'like', "%{$texto}%"));
        }

        return $q->limit(300)->get()->map(function (OrdemServico $os) {
            $sit = $os->situacaoTag();

            return [
                'tipo'        => 'os',
                'tipo_rotulo' => 'Ordem de serviço',
                'id'          => $os->id,
                'numero'      => $os->numeroFormatado(),
                'assunto'     => $os->objeto,
                'quem'        => $os->fiscais->pluck('name')->implode(', ') ?: null,
                'quem_rotulo' => 'Designados',
                'imovel'      => $os->lote?->rotuloCompleto(),
                // Na ordem, quem responde pela emissão é a coordenação.
                'responsavel' => $os->emitente?->name,
                'data'        => $os->created_at?->format('d/m/Y'),
                'ordem'       => $os->created_at?->format('Y-m-d') ?? '',
                'situacao'    => is_array($sit) ? $sit : ['texto' => $sit, 'classe' => 'bd-in'],
                'alerta'      => $os->prioridade === 'alta'
                    ? ['texto' => 'Prioridade alta', 'classe' => 'bd-al'] : null,
            ];
        });
    }
}
