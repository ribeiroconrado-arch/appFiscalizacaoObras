<?php

namespace App\Http\Controllers;

use App\Models\OrdemServico;
use App\Models\Protocolo;
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
            'tipo'     => ['nullable', 'in:protocolo,os'],
            'situacao' => ['nullable', 'string', 'max:40'],
            'agente'   => ['nullable', 'in:eu,todos,sem_dono'],
            'busca'    => ['nullable', 'string', 'max:80'],
        ]);

        $tipo   = $d['tipo'] ?? '';
        $escopo = $d['agente'] ?? 'todos';
        $texto  = $d['busca'] ?? null;
        $sit    = $d['situacao'] ?? null;

        $linhas = collect();

        if ($tipo !== 'os') {
            $linhas = $linhas->concat($this->protocolos($u->id, $escopo, $texto, $sit));
        }
        if ($tipo !== 'protocolo') {
            $linhas = $linhas->concat($this->ordens($u->id, $escopo, $texto, $sit));
        }

        // Ordena pela data, do mais recente para o mais antigo. `ordem` é a
        // data em formato comparável (aaaa-mm-dd), montada em cada fonte — a
        // exibida ("11/08/2026") ordenaria por dia antes de por ano.
        $linhas = $linhas->sortByDesc('ordem')->values();

        return response()->json([
            'demandas' => $linhas,
            'total'    => $linhas->count(),
            // Quantos de cada tipo, para o filtro dizer o que existe.
            'contagem' => [
                'protocolo' => $linhas->where('tipo', 'protocolo')->count(),
                'os'        => $linhas->where('tipo', 'os')->count(),
            ],
            'tipos_protocolo' => collect(Protocolo::TIPOS)
                ->map(fn ($r, $v) => ['valor' => $v, 'rotulo' => $r])->values(),
            // Agrupadas por tipo: as situações não são as mesmas nos dois, e
            // um seletor plano faria "Deferido" e "Concluída" parecerem
            // alternativas da mesma pergunta.
            'situacoes' => [
                'protocolo' => collect(Protocolo::SITUACOES)
                    ->map(fn ($s, $v) => ['valor' => $v, 'rotulo' => $s[0]])->values(),
                'os' => collect(OrdemServico::SITUACOES)
                    ->map(fn ($s, $v) => ['valor' => $v, 'rotulo' => $s['texto']])->values(),
            ],
            'pode_protocolar' => $u->canEdit(),
            'pode_emitir_os'  => $u->isAdmin(),
        ]);
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

        $q = OrdemServico::query()
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
                'numero'      => $os->numero,
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
