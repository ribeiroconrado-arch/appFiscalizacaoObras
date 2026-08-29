<?php

namespace App\Http\Controllers;

use App\Models\Protocolo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProtocoloController extends Controller
{
    /**
     * GET /api/protocolos
     *
     * Padrão de agente aqui é "todos", ao contrário dos documentos: protocolo
     * chega sem dono e alguém precisa distribuí-lo. Abrir filtrado por
     * "meus" esconderia justamente o que ainda não foi atribuído a ninguém.
     */
    /**
     * UM protocolo.
     *
     * A ficha do protocolo era montada só com o que a LISTA já tinha em mãos,
     * e por isso só abria de dentro da aba Protocolos. Clicando no marco da
     * linha do tempo do imóvel — onde o protocolo também aparece — a tela não
     * fazia nada: a lista estava vazia e a função saía calada.
     */
    public function mostrar(Protocolo $protocolo): JsonResponse
    {
        $protocolo->load(['lote:id,bairro,quadra,numero_lote', 'responsavel:id,name']);

        return response()->json(['protocolo' => $this->emFormato($protocolo)]);
    }

    /**
     * O protocolo como a tela o consome — um lugar só.
     *
     * Lista e ficha liam o mesmo protocolo por caminhos diferentes; bastava
     * alguém acrescentar um campo em um deles para as duas telas passarem a
     * mostrar coisas diferentes do mesmo processo.
     *
     * @return array<string, mixed>
     */
    private function emFormato(Protocolo $p): array
    {
        [$sTxt, $sCls] = $p->situacaoBadge();
        $prazo = $p->situacaoPrazo();

        return [
            'id'          => $p->id,
            'numero'      => $p->numero,
            'tipo'        => $p->tipo,
            'tipo_rotulo' => $p->rotuloTipo(),
            'data'        => $p->protocolado_em?->format('d/m/Y'),
            'situacao'    => ['texto' => $sTxt, 'classe' => $sCls],
            'prazo'       => $prazo ? ['texto' => $prazo[0], 'classe' => $prazo[1]] : null,
            'requerente'  => $p->requerente_nome,
            'imovel'      => $p->lote
                ? sprintf('Quadra %s · Lote %s — %s', $p->lote->quadra ?? '—', $p->lote->numero_lote ?? '—', $p->lote->bairro)
                : 'Não vinculado a lote',
            'responsavel' => $p->responsavel?->name ?? 'Não distribuído',
            'objeto'      => $p->objeto,
            'lote_id'     => $p->lote_id,
            // Este protocolo está esperando a vistoria que vai fundamentar
            // o ato cadastral? É o que faz aparecer o botão "Registrar
            // vistoria" — o caminho de quem parte da tela de protocolos.
            'espera_vistoria' => in_array($p->tipo, ['desmembramento', 'unificacao'], true)
                && $p->situacao === 'deferido'
                && $p->vistoria_id === null
                && $p->lote_id !== null,
            'vistoria_id' => $p->vistoria_id,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo'     => ['nullable', Rule::in(array_keys(Protocolo::TIPOS))],
            'situacao' => ['nullable', Rule::in(array_keys(Protocolo::SITUACOES))],
            'agente'   => ['nullable', 'in:eu,todos,sem_dono'],
            'busca'    => ['nullable', 'string', 'max:80'],
        ]);

        $q = Protocolo::query()
            ->with(['lote:id,bairro,quadra,numero_lote', 'responsavel:id,name'])
            ->orderByRaw('prazo_resposta IS NULL, prazo_resposta ASC')  // vencendo primeiro
            ->orderByDesc('protocolado_em');

        if (! empty($d['tipo']))     { $q->where('tipo', $d['tipo']); }
        if (! empty($d['situacao'])) { $q->where('situacao', $d['situacao']); }

        match ($d['agente'] ?? 'todos') {
            'eu'       => $q->where('responsavel_id', $request->user()->id),
            'sem_dono' => $q->whereNull('responsavel_id'),
            default    => null,
        };

        if ($texto = $d['busca'] ?? null) {
            $q->where(function ($s) use ($texto) {
                $s->where('numero', 'like', "%{$texto}%")
                  ->orWhere('requerente_nome', 'like', "%{$texto}%")
                  ->orWhereHas('lote', fn ($l) => $l
                      ->where('quadra', 'like', "%{$texto}%")
                      ->orWhere('numero_lote', 'like', "%{$texto}%")
                      ->orWhere('bairro', 'like', "%{$texto}%"));
            });
        }

        $itens = $q->limit(300)->get()->map(fn (Protocolo $p) => $this->emFormato($p));

        return response()->json([
            'protocolos' => $itens,
            'total'      => $itens->count(),
            'tipos'      => collect(Protocolo::TIPOS)->map(fn ($r, $v) => ['valor' => $v, 'rotulo' => $r])->values(),
        ]);
    }

    /** POST /api/protocolos — registra um requerimento recebido. */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->canEdit()) {
            return response()->json(['message' => 'Seu perfil não permite registrar protocolos.'], 403);
        }

        $d = $request->validate([
            'numero'               => ['required', 'string', 'max:30', 'unique:protocolos,numero'],
            'tipo'                 => ['required', Rule::in(array_keys(Protocolo::TIPOS))],
            'lote_id'              => ['nullable', 'exists:lotes,id'],
            'requerente_nome'      => ['required', 'string', 'max:160'],
            'requerente_documento' => ['nullable', 'string', 'max:20'],
            'requerente_contato'   => ['nullable', 'string', 'max:120'],
            'protocolado_em'       => ['required', 'date'],
            'prazo_resposta'       => ['nullable', 'date', 'after_or_equal:protocolado_em'],
            'objeto'               => ['nullable', 'string', 'max:2000'],
        ]);

        $p = Protocolo::create($d + ['situacao' => 'aguardando_vistoria']);

        return response()->json([
            'message'   => 'Protocolo ' . $p->numero . ' registrado.',
            'protocolo' => ['id' => $p->id, 'numero' => $p->numero],
        ], 201);
    }

    /** PATCH /api/protocolos/{protocolo} — distribui, muda situação, dá parecer. */
    public function update(Request $request, Protocolo $protocolo): JsonResponse
    {
        if (! $request->user()->canEdit()) {
            return response()->json(['message' => 'Seu perfil não permite alterar protocolos.'], 403);
        }

        $d = $request->validate([
            'situacao'       => ['nullable', Rule::in(array_keys(Protocolo::SITUACOES))],
            'responsavel_id' => ['nullable', 'exists:users,id'],
            'prazo_resposta' => ['nullable', 'date'],
            'parecer'        => ['nullable', 'string', 'max:4000'],
            'vistoria_id'    => ['nullable', 'exists:vistorias,id'],
        ]);

        // Deferir ou indeferir sem parecer deixa o processo sem motivação —
        // e ato administrativo sem motivação é anulável.
        if (in_array($d['situacao'] ?? '', ['deferido', 'indeferido'], true)
            && ! ($d['parecer'] ?? $protocolo->parecer)) {
            return response()->json([
                'message' => 'Informe o parecer antes de deferir ou indeferir: a decisão precisa ser motivada.',
            ], 422);
        }

        $protocolo->update(array_filter($d, fn ($v) => $v !== null));

        return response()->json(['message' => 'Protocolo atualizado.']);
    }
}
