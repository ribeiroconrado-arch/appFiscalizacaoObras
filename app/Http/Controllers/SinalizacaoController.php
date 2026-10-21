<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Sinalizacao;
use App\Models\SinalizacaoReforco;
use App\Models\User;
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
            ->with(['lote:id,bairro,quadra,numero_lote', 'autor:id,name', 'reforcos.autor:id,name'])
            ->latest()->limit(500)->get();

        $pontos = $this->pontos($lista->pluck('lote_id')->unique()->all());

        return response()->json([
            'sinalizacoes' => $lista->map(fn ($x) => $this->item($x, $pontos[$x->lote_id] ?? null, $r->user()))
                ->filter(fn ($i) => $i['lat'] !== null
                    && $i['lon'] >= $o && $i['lon'] <= $l && $i['lat'] >= $s && $i['lat'] <= $n)
                ->values(),
        ]);
    }

    /** GET /api/sinalizacoes/hoje — o "Para hoje" do Painel. */
    public function hoje(Request $r): JsonResponse
    {
        // Sinalização é aviso de obra (entulho, placa, avanço): o "Para hoje"
        // do painel de outro módulo não a mostra.
        if (\App\Support\Modulo::ativo($r->user()) !== 'obras') {
            return response()->json(['itens' => []]);
        }

        $lista = Sinalizacao::query()->pendentes()->visiveisPara($r->user())
            ->with(['lote:id,bairro,quadra,numero_lote', 'autor:id,name', 'reforcos.autor:id,name'])
            // O lembrete é de quem o deixou; a sinalização é de todos.
            ->where(fn ($q) => $q->where('tipo', '<>', 'lembrete')->orWhere('user_id', $r->user()->id))
            ->orderBy('created_at')->limit(60)->get();

        return response()->json(['itens' => $lista->map(fn ($x) => $this->item($x, null, $r->user()))]);
    }

    /** GET /api/lotes/{lote}/sinalizacoes — as abertas do lote (ficha e formulário da vistoria). */
    public function doLote(Request $r, Lote $lote): JsonResponse
    {
        $lista = Sinalizacao::where('lote_id', $lote->id)->where('status', 'aberta')
            ->visiveisPara($r->user())->with(['autor:id,name', 'reforcos.autor:id,name'])->latest()->get();

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
            'pendentes' => $lista->map(fn ($x) => $this->item($x, null, $r->user()))->values(),
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
        ], ['tipo.required' => 'Escolha o que você viu.']);

        // A MESMA COISA JÁ ESTÁ SINALIZADA E PENDENTE NO IMÓVEL: o aviso é SOMADO
        // à sinalização que existe, e não vira uma segunda bandeira. (O lembrete
        // é pessoal e fica de fora: cada um tem o seu.)
        if ($d['tipo'] !== 'lembrete') {
            $existente = Sinalizacao::where('lote_id', $lote->id)->pendentes()->where('tipo', $d['tipo'])->oldest()->first();
            if ($existente) {
                return $this->somar($existente, $r->user(), $d['comentario'] ?? null);
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

    /**
     * Soma o aviso de mais uma pessoa a uma sinalização pendente.
     *
     * Quem já avisou (abriu a sinalização, ou já somou) não conta duas vezes:
     * se voltar com um comentário, ele é guardado; sem comentário, só é
     * lembrado de que o aviso dele continua valendo.
     */
    private function somar(Sinalizacao $s, User $u, ?string $comentario): JsonResponse
    {
        $comentario = trim((string) $comentario) ?: null;
        $jaAvisou = (int) $s->user_id === (int) $u->id || $s->reforcos()->where('user_id', $u->id)->exists();

        if ($jaAvisou && ! $comentario) {
            return response()->json([
                'message' => 'Você já sinalizou "' . $s->rotulo() . '" neste imóvel, e continua pendente.',
                'somada'  => true, 'id' => $s->id, 'avisos' => $s->load('reforcos')->avisos(),
            ]);
        }

        SinalizacaoReforco::create(['sinalizacao_id' => $s->id, 'user_id' => $u->id, 'comentario' => $comentario]);
        // Mexer na sinalização a traz para o topo de quem ordena por atualização.
        $s->touch();
        $n = $s->load('reforcos')->avisos();

        return response()->json([
            'message' => $jaAvisou
                ? 'Seu comentário foi acrescentado à sinalização.'
                : 'Este imóvel já tinha "' . $s->rotulo() . '" sinalizado desde ' . $s->created_at->format('d/m/Y')
                    . '. Seu aviso foi somado: ' . $n . ' pessoas já avisaram.',
            'somada' => true, 'id' => $s->id, 'avisos' => $n,
        ]);
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

    /**
     * A sinalização como a tela a mostra.
     *
     * QUEM VÊ O QUÊ: a fiscalização vê o autor, o comentário e cada aviso
     * somado. Quem é de fora só chega aqui pelas próprias — as que abriu e as
     * a que somou — e, numa que NÃO abriu, vê só a parte dele: não o nome nem o
     * comentário de quem sinalizou primeiro, nem os dos outros.
     *
     * @return array<string,mixed>
     */
    private function item(Sinalizacao $x, ?array $ponto, ?User $u = null): array
    {
        $l = $x->lote;
        $tudo = ! $u || $u->veTodasSinalizacoes();
        $minha = $u && (int) $x->user_id === (int) $u->id;
        $reforcos = $x->relationLoaded('reforcos') ? $x->reforcos : collect();
        $visiveis = $tudo ? $reforcos : $reforcos->filter(fn ($r) => (int) $r->user_id === (int) $u->id);

        return [
            'id'         => $x->id,
            'tipo'       => $x->tipo,
            'rotulo'     => $x->rotulo(),
            'comentario' => ($tudo || $minha) ? $x->comentario : null,
            'autor'      => ($tudo || $minha) ? ($x->autor?->name ?? '—') : 'outra pessoa',
            'criada_em'  => $x->created_at?->format('d/m/Y'),
            'ha'         => $x->created_at ? $this->ha($x->created_at) : null,
            'lembrar_em' => $x->lembrar_em?->format('d/m/Y'),
            'lote_id'    => $x->lote_id,
            'imovel'     => $l ? sprintf('Q %s · Lt %s', $l->quadra ?? '—', $l->numero_lote ?? '—') : '—',
            'lat'        => $ponto['lat'] ?? null,
            'lon'        => $ponto['lon'] ?? null,
            // Quantas pessoas avisaram, e os avisos somados que este usuário pode ler.
            'avisos'     => $x->relationLoaded('reforcos') ? $x->avisos() : 1,
            'reforcos'   => $visiveis->map(fn ($r) => [
                'autor'      => $r->autor?->name ?? '—',
                'comentario' => $r->comentario,
                'em'         => $r->created_at?->format('d/m/Y'),
            ])->values(),
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
