<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Sinalizacao;
use App\Models\Vistoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sinalizações e lembretes de revistoria. Ver App\Models\Sinalizacao.
 *
 * Qualquer usuário sinaliza — inclusive topógrafo, arquiteto e contribuinte,
 * para quem este é o canal natural. A fiscalização vê todas; quem é de fora vê
 * as próprias (scopeVisiveisPara).
 */
class SinalizacaoController extends Controller
{
    /** GET /api/sinalizacoes?bbox=oeste,sul,leste,norte — as bandeiras do mapa. */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['bbox' => ['required', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/']]);
        [$o, $s, $l, $n] = array_map('floatval', explode(',', $d['bbox']));

        $lista = Sinalizacao::query()->pendentes()->visiveisPara($r->user())
            ->with(['lote:id,bairro,quadra,numero_lote', 'autor:id,name'])
            ->latest()->limit(500)->get();

        $pontos = $this->pontos($lista->pluck('lote_id')->unique()->all());

        return response()->json([
            'sinalizacoes' => $lista->map(fn ($x) => $this->item($x, $pontos[$x->lote_id] ?? null))
                ->filter(fn ($i) => $i['lat'] !== null
                    && $i['lon'] >= $o && $i['lon'] <= $l && $i['lat'] >= $s && $i['lat'] <= $n)
                ->values(),
        ]);
    }

    /** GET /api/sinalizacoes/hoje — o "Para hoje" do Painel. */
    public function hoje(Request $r): JsonResponse
    {
        $lista = Sinalizacao::query()->pendentes()->visiveisPara($r->user())
            ->with(['lote:id,bairro,quadra,numero_lote', 'autor:id,name'])
            // O lembrete é de quem o deixou; a sinalização é de todos.
            ->where(fn ($q) => $q->where('tipo', '<>', 'lembrete')->orWhere('user_id', $r->user()->id))
            ->orderBy('created_at')->limit(60)->get();

        return response()->json(['itens' => $lista->map(fn ($x) => $this->item($x, null))]);
    }

    /** GET /api/lotes/{lote}/sinalizacoes — as abertas do lote (ficha e formulário da vistoria). */
    public function doLote(Request $r, Lote $lote): JsonResponse
    {
        $lista = Sinalizacao::where('lote_id', $lote->id)->where('status', 'aberta')
            ->visiveisPara($r->user())->with('autor:id,name')->latest()->get();

        // O QUE JÁ ESTÁ SINALIZADO NO LOTE, de qualquer pessoa — só o tipo e
        // desde quando. É o que permite avisar "isto já foi sinalizado" a quem
        // não pode ver a sinalização dos outros, sem mostrar quem foi nem o quê
        // foi escrito. O lembrete não entra: é pessoal.
        $jaExistem = Sinalizacao::where('lote_id', $lote->id)->pendentes()->where('tipo', '<>', 'lembrete')
            ->get(['tipo', 'created_at'])
            ->map(fn ($x) => ['tipo' => $x->tipo, 'rotulo' => $x->rotulo(), 'desde' => $x->created_at?->format('d/m/Y')])
            ->unique('tipo')->values();

        return response()->json([
            'ja_existem' => $jaExistem,
            'pendentes' => $lista->map(fn ($x) => $this->item($x, null))->values(),
            'tipos'     => collect(Sinalizacao::TIPOS)->map(fn ($t, $k) => ['valor' => $k, 'rotulo' => $t])->values(),
        ]);
    }

    /** POST /api/lotes/{lote}/sinalizacoes */
    public function store(Request $r, Lote $lote): JsonResponse
    {
        $d = $r->validate([
            'tipo'       => ['required', Rule::in(array_keys(Sinalizacao::TIPOS))],
            'comentario' => ['nullable', 'string', 'max:500'],
            'lembrar_em' => ['nullable', 'date', 'after_or_equal:today'],
            'confirmar_duplicada' => ['nullable', 'boolean'],
        ], ['tipo.required' => 'Escolha o que você viu.']);

        // A MESMA sinalização ainda pendente no lote: avisa antes de duplicar.
        // A tela já avisa ao escolher o tipo; isto é a mesma regra no servidor,
        // para quem chegou aqui sem ver o aviso. Enviar assim mesmo é possível,
        // confirmando — às vezes é outro problema do mesmo tipo.
        if ($d['tipo'] !== 'lembrete' && empty($d['confirmar_duplicada'])) {
            $existente = Sinalizacao::where('lote_id', $lote->id)->pendentes()->where('tipo', $d['tipo'])->oldest()->first();
            if ($existente) {
                return response()->json([
                    'message'   => 'Este imóvel já tem "' . $existente->rotulo() . '" sinalizado desde '
                        . $existente->created_at->format('d/m/Y') . ', ainda pendente.',
                    'duplicada' => true,
                ], 409);
            }
        }

        if ($d['tipo'] === 'lembrete' && empty($d['lembrar_em'])) {
            $d['lembrar_em'] = now()->toDateString();
        }

        $s = Sinalizacao::create([
            'lote_id'    => $lote->id,
            'tipo'       => $d['tipo'],
            'comentario' => $d['comentario'] ?? null,
            'lembrar_em' => $d['lembrar_em'] ?? null,
            'user_id'    => $r->user()->id,
        ]);

        return response()->json([
            'message' => $s->tipo === 'lembrete' && $s->lembrar_em->isFuture()
                ? 'Lembrete marcado para ' . $s->lembrar_em->format('d/m/Y') . '.'
                : 'Sinalização enviada.',
            'id' => $s->id,
        ], 201);
    }

    /** POST /api/sinalizacoes/{sinalizacao}/resolver — o "Resolvido" de um toque. */
    public function resolver(Request $r, Sinalizacao $sinalizacao): JsonResponse
    {
        $u = $r->user();
        // Agente e administrador resolvem qualquer uma; os demais, só a sua.
        if (! $u->veTodasSinalizacoes() && (int) $sinalizacao->user_id !== (int) $u->id) {
            return response()->json(['message' => 'Só a fiscalização resolve esta sinalização.'], 403);
        }
        if ($sinalizacao->status !== 'aberta') {
            return response()->json(['message' => 'Esta sinalização já foi resolvida.'], 422);
        }
        $d = $r->validate(['resolucao' => ['required', 'string', 'min:2', 'max:300']],
            ['resolucao.required' => 'Diga em uma palavra como foi resolvida.']);

        $sinalizacao->update([
            'status' => 'resolvida', 'resolvida_em' => now(), 'resolvida_por' => $u->id,
            'resolucao' => $d['resolucao'],
        ]);

        return response()->json(['message' => 'Sinalização resolvida.']);
    }

    /**
     * Chamado ao gravar uma vistoria: resolve as pendências do lote e deixa o
     * lembrete de volta, se pedido.
     *
     * `$ids` null = o formulário não mandou a lista (cliente antigo): resolve
     * TODAS as pendentes do lote, que é a regra ("a vistoria atende"). Lista
     * vazia = o fiscal desmarcou todas, e nenhuma é resolvida.
     */
    public static function aoRegistrarVistoria(Vistoria $v, ?array $ids, ?string $lembrarEm, ?string $motivo): void
    {
        $q = Sinalizacao::query()->pendentes()->where('lote_id', $v->lote_id);
        if ($ids !== null) {
            $q->whereIn('id', array_map('intval', $ids));
        }
        $q->update([
            'status' => 'resolvida', 'resolvida_em' => now(), 'resolvida_por' => $v->fiscal_id,
            'vistoria_id' => $v->id, 'resolucao' => 'Atendida pela vistoria ' . $v->numeroFormatado(),
            'updated_at' => now(),
        ]);

        if ($lembrarEm) {
            Sinalizacao::create([
                'lote_id' => $v->lote_id, 'tipo' => 'lembrete', 'comentario' => $motivo ?: null,
                'lembrar_em' => $lembrarEm, 'user_id' => $v->fiscal_id, 'vistoria_origem_id' => $v->id,
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function item(Sinalizacao $x, ?array $ponto): array
    {
        $l = $x->lote;

        return [
            'id'         => $x->id,
            'tipo'       => $x->tipo,
            'rotulo'     => $x->rotulo(),
            'comentario' => $x->comentario,
            'autor'      => $x->autor?->name ?? '—',
            'criada_em'  => $x->created_at?->format('d/m/Y'),
            'ha'         => $x->created_at ? $this->ha($x->created_at) : null,
            'lembrar_em' => $x->lembrar_em?->format('d/m/Y'),
            'lote_id'    => $x->lote_id,
            'imovel'     => $l ? sprintf('Q %s · Lt %s', $l->quadra ?? '—', $l->numero_lote ?? '—') : '—',
            'lat'        => $ponto['lat'] ?? null,
            'lon'        => $ponto['lon'] ?? null,
        ];
    }

    private function ha($quando): string
    {
        $dias = (int) $quando->copy()->startOfDay()->diffInDays(now()->startOfDay());

        return match (true) { $dias === 0 => 'hoje', $dias === 1 => 'ontem', default => "há {$dias} dias" };
    }

    /**
     * Onde fincar a bandeira: o meio dos vértices do lote. `ST_Centroid` não
     * existe para SRS geográfico no MySQL (ver LoteRepository), e para um lote
     * de cidade a média dos vértices cai dentro dele.
     *
     * @param  list<int>  $ids
     * @return array<int,array{lat:float,lon:float}>
     */
    private function pontos(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $saida = [];
        foreach (DB::select('SELECT id, ST_AsGeoJSON(geom) AS g FROM lotes WHERE id IN ('
                     . implode(',', array_map('intval', $ids)) . ')') as $l) {
            $anel = json_decode($l->g, true)['coordinates'][0] ?? [];
            array_pop($anel);
            if (! $anel) { continue; }
            $saida[$l->id] = [
                'lon' => array_sum(array_column($anel, 0)) / count($anel),
                'lat' => array_sum(array_column($anel, 1)) / count($anel),
            ];
        }

        return $saida;
    }
}
