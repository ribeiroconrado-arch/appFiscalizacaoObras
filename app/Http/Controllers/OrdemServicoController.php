<?php

namespace App\Http\Controllers;

use App\Models\OrdemServico;
use App\Models\User;
use App\Services\CabecalhoOficial;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Ordens de Serviço: a coordenação determina, o fiscal cumpre.
 *
 * Quem EMITE é o administrador — é o perfil que o sistema tem para a
 * coordenação. Quem CUMPRE é qualquer agente designado. A distinção não é
 * decoração: uma OS que qualquer um pudesse emitir para qualquer um não
 * delegaria nada, porque não haveria de quem cobrar.
 */
class OrdemServicoController extends Controller
{
    /**
     * GET /api/os
     *
     * Padrão "minhas" para o agente comum e "todas" para o administrador: o
     * fiscal abre a tela para ver o que lhe cabe; a coordenação abre para ver
     * o que distribuiu.
     */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();

        $d = $request->validate([
            'situacao'  => ['nullable', Rule::in(array_keys(OrdemServico::SITUACOES))],
            'natureza'  => ['nullable', Rule::in(array_keys(OrdemServico::NATUREZAS))],
            'agente'    => ['nullable', 'in:eu,todas'],
            'busca'     => ['nullable', 'string', 'max:80'],
        ]);

        $q = OrdemServico::query()
            ->with(['fiscais:id,name', 'emitente:id,name', 'lote:id,bairro,quadra,numero_lote'])
            ->withCount('jornadas')
            ->orderByDesc('ano')->orderByDesc('sequencia');

        if (! empty($d['situacao'])) { $q->where('situacao', $d['situacao']); }
        if (! empty($d['natureza'])) { $q->where('natureza', $d['natureza']); }

        $escopo = $d['agente'] ?? ($u->isAdmin() ? 'todas' : 'eu');
        if ($escopo === 'eu') {
            $q->whereHas('fiscais', fn ($f) => $f->where('users.id', $u->id));
        }

        if ($texto = $d['busca'] ?? null) {
            $q->where(fn ($s) => $s
                ->where('numero', 'like', "%{$texto}%")
                ->orWhere('objeto', 'like', "%{$texto}%"));
        }

        $itens = $q->limit(300)->get()->map(fn (OrdemServico $os) => [
            'id'        => $os->id,
            'numero'    => $os->numero,
            'objeto'    => $os->objeto,
            'natureza'  => OrdemServico::NATUREZAS[$os->natureza] ?? $os->natureza,
            'regime'    => $os->regime,
            'quando'    => $os->quandoRotulo(),
            'dias'      => $os->jornadas_count,
            'situacao'  => $os->situacaoTag(),
            'prioridade'=> $os->prioridade,
            'fiscais'   => $os->fiscais->pluck('name'),
            'emitente'  => $os->emitente?->name,
            'imovel'    => $os->lote ? trim("Q{$os->lote->quadra} L{$os->lote->numero_lote} · {$os->lote->bairroOficial()}") : null,
        ]);

        return response()->json([
            'ordens' => $itens,
            'total'  => $itens->count(),
            'escopo' => $escopo,
            // Quem pode emitir decide o que a tela oferece — mas quem autoriza
            // de verdade é o `store` aqui embaixo.
            'pode_emitir' => $u->isAdmin(),
        ]);
    }

    /** GET /api/os/fiscais — a quem se pode designar uma ordem. */
    public function fiscais(): JsonResponse
    {
        return response()->json(
            User::where('ativo', true)
                ->whereIn('perfil', ['admin', 'comum'])
                ->orderBy('name')
                ->get(['id', 'name', 'perfil'])
        );
    }

    /** GET /api/os/{ordem} — a ficha, com os dias e os designados. */
    public function show(OrdemServico $ordem): JsonResponse
    {
        $ordem->load(['fiscais:id,name', 'emitente:id,name', 'jornadas',
                      'lote:id,bairro,quadra,numero_lote', 'protocolo:id,numero']);

        return response()->json([
            'id'        => $ordem->id,
            'numero'    => $ordem->numero,
            'objeto'    => $ordem->objeto,
            'descricao' => $ordem->descricao,
            'natureza'  => $ordem->natureza,
            'natureza_rotulo' => OrdemServico::NATUREZAS[$ordem->natureza] ?? null,
            'regime'    => $ordem->regime,
            'inicio'    => $ordem->inicio?->format('Y-m-d'),
            'fim'       => $ordem->fim?->format('Y-m-d'),
            'quando'    => $ordem->quandoRotulo(),
            'situacao'  => $ordem->situacao,
            'situacao_tag' => $ordem->situacaoTag(),
            'prioridade'=> $ordem->prioridade,
            'emitente'  => $ordem->emitente?->name,
            'emitida_em'=> $ordem->created_at?->format('d/m/Y H:i'),
            'assinada_em' => $ordem->assinada_em?->format('d/m/Y H:i'),
            // Quem emitiu pode ter emitido sem ter assinatura cadastrada no
            // perfil. A ordem vale assim mesmo, mas a via sai com a linha em
            // branco — e é ele, e mais ninguém, quem pode fechá-la depois.
            'sou_emitente'  => $ordem->emitida_por === request()->user()->id,
            'falta_assinar' => $ordem->assinatura_emitente === null,
            'encerramento' => $ordem->encerramento,
            'encerrada_em' => $ordem->encerrada_em?->format('d/m/Y H:i'),
            'imovel'    => $ordem->lote ? trim("Quadra {$ordem->lote->quadra} · Lote {$ordem->lote->numero_lote} — {$ordem->lote->bairroOficial()}") : null,
            'protocolo' => $ordem->protocolo?->numero,
            'fiscais'   => $ordem->fiscais->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name,
                'ciencia_em' => $f->pivot->ciencia_em
                    ? \Illuminate\Support\Carbon::parse($f->pivot->ciencia_em)->format('d/m/Y H:i')
                    : null,
            ]),
            // O que a tela precisa saber sobre QUEM está olhando: se lhe cabe
            // dar ciência, e se já deu.
            'sou_designado' => $ordem->fiscais->contains('id', request()->user()->id),
            'minha_ciencia' => $ordem->fiscais->firstWhere('id', request()->user()->id)
                ?->pivot?->ciencia_em !== null,
            'jornadas'  => $ordem->jornadas->map(fn ($j) => [
                'id' => $j->id,
                'data' => $j->data->format('Y-m-d'),
                'hora_inicio' => $j->hora_inicio ? substr($j->hora_inicio, 0, 5) : null,
                'hora_fim'    => $j->hora_fim ? substr($j->hora_fim, 0, 5) : null,
                'observacao'  => $j->observacao,
                'rotulo'      => $j->rotulo(),
            ]),
        ]);
    }

    /** POST /api/os — emite a ordem. */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Só a coordenação emite ordem de serviço.',
            ], 403);
        }

        $d = $this->validar($request);

        if ($erro = $this->conferirRegime($d)) { return $erro; }

        $os = DB::transaction(function () use ($d, $request) {
            $n = OrdemServico::proximoNumero();

            $os = OrdemServico::create([
                'numero'     => $n['numero'],
                'ano'        => $n['ano'],
                'sequencia'  => $n['sequencia'],
                'objeto'     => trim($d['objeto']),
                'descricao'  => $d['descricao'] ?? null,
                'natureza'   => $d['natureza'],
                'regime'     => $d['regime'],
                // No regime de dias marcados as datas de período não são
                // gravadas: quem responde "quando" são as jornadas, e guardar
                // as duas coisas abriria a porta para elas se contradizerem.
                'inicio'     => $d['regime'] === 'periodo' ? ($d['inicio'] ?? null) : null,
                'fim'        => $d['regime'] === 'periodo' ? ($d['fim'] ?? null) : null,
                'prioridade' => $d['prioridade'] ?? 'normal',
                'emitida_por'=> $request->user()->id,
                // Quem determina assina no ato: emitir a ordem É o ato dele, e
                // pedir um segundo clique para confirmar o que ele acabou de
                // fazer não acrescenta nada. Cópia, e não referência — quem
                // redesenhar a própria assinatura amanhã não reescreve o que
                // já assinou (mesmo princípio de LavraturaService).
                'assinatura_emitente' => $request->user()->assinatura,
                'assinada_em'         => $request->user()->assinatura ? now() : null,
                'lote_id'    => $d['lote_id'] ?? null,
                'protocolo_id' => $d['protocolo_id'] ?? null,
            ]);

            $os->fiscais()->sync($d['fiscais']);

            if ($d['regime'] === 'dias') {
                foreach ($d['jornadas'] as $j) {
                    $os->jornadas()->create([
                        'data'        => $j['data'],
                        'hora_inicio' => $j['hora_inicio'] ?? null,
                        'hora_fim'    => $j['hora_fim'] ?? null,
                        'observacao'  => $j['observacao'] ?? null,
                    ]);
                }
            }

            return $os;
        });

        return response()->json([
            'message' => "Ordem de serviço {$os->numero} emitida.",
            'ordem'   => ['id' => $os->id, 'numero' => $os->numero,
                          'quando' => $os->quandoRotulo(),
                          'fiscais' => $os->fiscais()->count()],
        ], 201);
    }

    /**
     * POST /api/os/{ordem}/situacao — anda com a ordem.
     *
     * O fiscal designado pode dizer que começou e que concluiu; cancelar é da
     * coordenação. Quem executa não decide que a determinação perdeu o efeito.
     */
    public function situacao(Request $request, OrdemServico $ordem): JsonResponse
    {
        $u = $request->user();
        $designado = $ordem->fiscais()->where('users.id', $u->id)->exists();

        if (! $u->isAdmin() && ! $designado) {
            return response()->json(['message' => 'Esta ordem não é sua.'], 403);
        }

        $d = $request->validate([
            'situacao'     => ['required', Rule::in(array_keys(OrdemServico::SITUACOES))],
            'encerramento' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($d['situacao'] === 'cancelada' && ! $u->isAdmin()) {
            return response()->json([
                'message' => 'Só a coordenação cancela uma ordem de serviço.',
            ], 403);
        }

        $fecha = in_array($d['situacao'], ['concluida', 'cancelada'], true);

        $ordem->update([
            'situacao'     => $d['situacao'],
            'encerramento' => $d['encerramento'] ?? $ordem->encerramento,
            'encerrada_em' => $fecha ? now() : null,
        ]);

        return response()->json([
            'message' => 'Ordem atualizada.',
            'situacao_tag' => $ordem->fresh()->situacaoTag(),
        ]);
    }

    /**
     * POST /api/os/{ordem}/ciencia — o fiscal assina a ordem pelo sistema.
     *
     * A assinatura é COPIADA do perfil para a ligação, e não lida de lá na
     * hora de imprimir: o traço guardado no perfil pode mudar depois, e a via
     * impressa tem de continuar mostrando o que ele usou naquele dia.
     */
    public function ciencia(Request $request, OrdemServico $ordem): JsonResponse
    {
        $u = $request->user();

        // Quem EMITIU assina como autoridade que determinou, e não como quem
        // tomou ciência — são dois papéis, e o papel sai impresso em blocos
        // diferentes. Um coordenador designado na própria ordem assina os
        // dois, cada um no seu lugar.
        if ($ordem->emitida_por === $u->id && ! $ordem->assinatura_emitente) {
            if (! $u->assinatura) {
                return response()->json([
                    'message' => 'Cadastre a sua assinatura no perfil antes de assinar.',
                ], 422);
            }

            $ordem->update([
                'assinatura_emitente' => $u->assinatura,
                'assinada_em'         => now(),
            ]);

            return response()->json([
                'message'    => 'Ordem assinada.',
                'ciencia_em' => now()->format('d/m/Y H:i'),
            ]);
        }

        $vinculo = $ordem->fiscais()->where('users.id', $u->id)->first();
        if (! $vinculo) {
            return response()->json([
                'message' => 'Só quem foi designado dá ciência nesta ordem.',
            ], 403);
        }

        if ($vinculo->pivot->ciencia_em) {
            return response()->json([
                'message' => 'Você já deu ciência nesta ordem.',
            ], 409);
        }

        if (! $u->assinatura) {
            return response()->json([
                'message' => 'Cadastre a sua assinatura no perfil antes de dar ciência.',
            ], 422);
        }

        $ordem->fiscais()->updateExistingPivot($u->id, [
            'ciencia_em' => now(),
            'assinatura' => $u->assinatura,
        ]);

        return response()->json([
            'message'    => 'Ciência registrada.',
            'ciencia_em' => now()->format('d/m/Y H:i'),
        ]);
    }

    /**
     * GET /os/{ordem}/impressao — a via em papel.
     *
     * Fora do prefixo /api de propósito, como a do documento: devolve HTML
     * para a impressora, e o front abre com window.open, não com fetch.
     */
    public function impressao(Request $request, OrdemServico $ordem, CabecalhoOficial $cabecalho): Response
    {
        $u = $request->user();
        $designado = $ordem->fiscais()->where('users.id', $u->id)->exists();

        if (! $u->isAdmin() && ! $designado) {
            abort(403, 'Esta ordem não é sua.');
        }

        $ordem->load(['fiscais', 'emitente', 'jornadas',
                      'lote:id,bairro,quadra,numero_lote', 'protocolo:id,numero']);

        return response()->view('impressao.os', [
            'os'        => $ordem,
            'orgao'     => $cabecalho->orgao(),
            'brasao'    => $cabecalho->brasao(false),
            'rodape'    => $cabecalho->rodape(),
            'navegador' => true,
        ]);
    }

    /** As regras de forma, iguais na emissão. */
    private function validar(Request $request): array
    {
        return $request->validate([
            'objeto'     => ['required', 'string', 'max:200'],
            'descricao'  => ['nullable', 'string', 'max:5000'],
            'natureza'   => ['required', Rule::in(array_keys(OrdemServico::NATUREZAS))],
            'regime'     => ['required', Rule::in(array_keys(OrdemServico::REGIMES))],
            'prioridade' => ['nullable', Rule::in(array_keys(OrdemServico::PRIORIDADES))],

            // Ao menos um designado: ordem sem destinatário não delega nada.
            'fiscais'    => ['required', 'array', 'min:1', 'max:20'],
            'fiscais.*'  => ['integer', 'exists:users,id'],

            'inicio'     => ['nullable', 'date_format:Y-m-d'],
            'fim'        => ['nullable', 'date_format:Y-m-d'],

            'jornadas'               => ['array', 'max:60'],
            'jornadas.*.data'        => ['required', 'date_format:Y-m-d'],
            'jornadas.*.hora_inicio' => ['nullable', 'date_format:H:i'],
            'jornadas.*.hora_fim'    => ['nullable', 'date_format:H:i'],
            'jornadas.*.observacao'  => ['nullable', 'string', 'max:200'],

            'lote_id'      => ['nullable', 'integer', 'exists:lotes,id'],
            'protocolo_id' => ['nullable', 'integer', 'exists:protocolos,id'],
        ], [
            'fiscais.required' => 'Designe ao menos um fiscal para a ordem.',
        ]);
    }

    /**
     * O que o regime exige, e o que ele torna sem sentido.
     *
     * Fica aqui, e não nas regras de forma, porque depende de outro campo: a
     * mesma remessa é válida ou inválida conforme o regime escolhido.
     */
    private function conferirRegime(array $d): ?JsonResponse
    {
        $erro = fn (string $msg, string $campo) => response()->json([
            'message' => $msg, 'errors' => [$campo => [$msg]],
        ], 422);

        if ($d['regime'] === 'dias') {
            if (empty($d['jornadas'])) {
                return $erro('Marque ao menos um dia de trabalho.', 'jornadas');
            }

            foreach ($d['jornadas'] as $i => $j) {
                if (! empty($j['hora_inicio']) && ! empty($j['hora_fim'])
                    && $j['hora_fim'] <= $j['hora_inicio']) {
                    return $erro('No dia ' . date('d/m/Y', strtotime($j['data']))
                        . ', o fim do horário vem antes do começo.', "jornadas.{$i}.hora_fim");
                }
            }
            return null;
        }

        // Regime de período: a ordem pode não ter prazo (serviço contínuo sem
        // data para acabar), mas se tem as duas pontas, elas têm de fazer
        // sentido uma com a outra.
        if (! empty($d['inicio']) && ! empty($d['fim']) && $d['fim'] < $d['inicio']) {
            return $erro('O fim do período vem antes do início.', 'fim');
        }

        return null;
    }
}
