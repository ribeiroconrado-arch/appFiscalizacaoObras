<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Sinalizacao;
use App\Models\SinalizacaoReforco;
use App\Models\User;
use App\Models\Vistoria;
use App\Repositories\LoteRepository;
use App\Support\Modulo;
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
    /** Raio em que a mesma coisa, marcada na rua, é considerada a mesma sinalização. */
    const MESMO_LUGAR_M = 25;

    /** Tipos que não se explicam sozinhos: exigem descrição, nos dois módulos. */
    const EXIGEM_DESCRICAO = ['risco', 'outro'];
    const DESCRICAO_MIN = 10;

    /** GET /api/sinalizacoes?bbox=oeste,sul,leste,norte — as bandeiras do mapa. */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['bbox' => ['required', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/']]);
        [$o, $s, $l, $n] = array_map('floatval', explode(',', $d['bbox']));

        // Toda sinalização guarda o ponto (migração 2026_11_24_000200): o
        // recorte do mapa é feito no banco, e só as do módulo em que se está.
        $lista = Sinalizacao::query()->pendentes()->visiveisPara($r->user())->doModulo(Modulo::ativo($r->user()))
            ->whereBetween('lng', [$o, $l])->whereBetween('lat', [$s, $n])
            ->with(['lote:id,bairro,quadra,numero_lote', 'autor:id,name', 'reforcos.autor:id,name'])
            ->latest()->limit(500)->get();

        return response()->json([
            'sinalizacoes' => $lista->map(fn ($x) => $this->item($x, null, $r->user()))->values(),
        ]);
    }

    /** GET /api/sinalizacoes/hoje — o "Para hoje" do Painel. */
    public function hoje(Request $r): JsonResponse
    {
        // Cada módulo vê as próprias: Posturas tem os seus tipos desde 24/11/2026.
        $lista = Sinalizacao::query()->pendentes()->visiveisPara($r->user())->doModulo(Modulo::ativo($r->user()))
            ->with(['lote:id,bairro,quadra,numero_lote', 'autor:id,name', 'reforcos.autor:id,name'])
            // O lembrete é de quem o deixou; a sinalização é de todos.
            ->where(fn ($q) => $q->where('tipo', '<>', 'lembrete')->orWhere('user_id', $r->user()->id))
            ->orderBy('created_at')->limit(60)->get();

        return response()->json(['itens' => $lista->map(fn ($x) => $this->item($x, null, $r->user()))]);
    }

    /** GET /api/lotes/{lote}/sinalizacoes — as abertas do lote (ficha e formulário da vistoria). */
    public function doLote(Request $r, Lote $lote): JsonResponse
    {
        $modulo = Modulo::ativo($r->user());
        // O ficha mostra as pendências do imóvel em qualquer módulo — quem
        // vistoria precisa saber de tudo o que há nele. O balão de criar, só os
        // tipos do módulo.
        $lista = Sinalizacao::where('lote_id', $lote->id)->where('status', 'aberta')
            ->visiveisPara($r->user())->with(['autor:id,name', 'reforcos.autor:id,name'])->latest()->get();

        // O QUE JÁ ESTÁ SINALIZADO NO LOTE, de qualquer pessoa — só o tipo e
        // desde quando. É o que permite avisar "isto já foi sinalizado" a quem
        // não pode ver a sinalização dos outros, sem mostrar quem foi nem o quê
        // foi escrito. O lembrete não entra: é pessoal.
        $jaExistem = Sinalizacao::where('lote_id', $lote->id)->doModulo($modulo)->pendentes()->where('tipo', '<>', 'lembrete')
            ->get(['tipo', 'created_at'])
            ->map(fn ($x) => ['tipo' => $x->tipo, 'rotulo' => $x->rotulo(), 'desde' => $x->created_at?->format('d/m/Y')])
            ->unique('tipo')->values();

        return response()->json([
            'ja_existem' => $jaExistem,
            'pendentes' => $lista->map(fn ($x) => $this->item($x, null, $r->user()))->values(),
            'tipos'     => collect(Sinalizacao::tiposDo($modulo))->map(fn ($t, $k) => ['valor' => $k, 'rotulo' => $t])->values(),
        ]);
    }

    /** POST /api/lotes/{lote}/sinalizacoes — pelo balão ou pela ficha do imóvel. */
    public function store(Request $r, Lote $lote): JsonResponse
    {
        $d = $this->validar($r);

        // O ponto do toque, se veio e cai no próprio lote; senão, o meio dele.
        $p = isset($d['lat'], $d['lng']) ? ['lat' => (float) $d['lat'], 'lon' => (float) $d['lng']] : null;
        if ($p && ! DB::selectOne('SELECT ST_Contains(geom, ST_GeomFromText(?, 4326, \'axis-order=long-lat\')) AS d FROM lotes WHERE id = ?',
                ['POINT(' . $p['lon'] . ' ' . $p['lat'] . ')', $lote->id])?->d) {
            $p = null;
        }
        $p ??= self::pontos([$lote->id])[$lote->id] ?? null;

        return $this->criar($r->user(), $d, $lote, $p, null);
    }

    /**
     * POST /api/sinalizacoes — marcada num PONTO do mapa.
     *
     * Caiu dentro de um lote: é sinalização do imóvel, como pelo balão. Caiu
     * na via: fica só o ponto, com a referência do lote mais próximo em texto
     * — o imóvel NÃO é ligado, porque o problema não é dele (veículo
     * abandonado na frente de uma casa não é da casa).
     */
    public function storePonto(Request $r, LoteRepository $lotes): JsonResponse
    {
        $d = $this->validar($r, true);
        $lat = (float) $d['lat'];
        $lon = (float) $d['lng'];

        $dentro = $lotes->contendo($lat, $lon);
        if ($dentro) {
            return $this->criar($r->user(), $d, Lote::findOrFail($dentro->id), ['lat' => $lat, 'lon' => $lon], null);
        }

        $perto = $lotes->proximos($lat, $lon, 80, 1)[0] ?? null;
        $ref = trim((string) ($d['referencia'] ?? ''));
        if ($ref === '') {
            $ref = $perto
                ? 'Próximo a ' . (Lote::find($perto->id)?->rotuloCompleto() ?? 'Qd. ' . $perto->quadra . ' · Lt. ' . $perto->numero_lote)
                : 'Ponto marcado no mapa';
        }

        return $this->criar($r->user(), $d, null, ['lat' => $lat, 'lon' => $lon], mb_substr($ref, 0, 255));
    }

    /** @return array<string,mixed> */
    private function validar(Request $r, bool $exigePonto = false): array
    {
        $modulo = Modulo::ativo($r->user());

        // "Risco" e "Outro" não dizem o que é: sem descrição, quem vai atender
        // não sabe o que procurar. Pelo menos 10 letras (pedido de 25/11/2026),
        // contadas sem os espaços das pontas.
        $r->merge(['comentario' => is_string($r->input('comentario')) ? trim($r->input('comentario')) : $r->input('comentario')]);

        return $r->validate([
            'comentario' => ['nullable', 'string', 'max:500',
                Rule::requiredIf(in_array($r->input('tipo'), self::EXIGEM_DESCRICAO, true)),
                function ($campo, $valor, $falha) use ($r) {
                    if (in_array($r->input('tipo'), self::EXIGEM_DESCRICAO, true) && mb_strlen((string) $valor) < self::DESCRICAO_MIN) {
                        $falha('Descreva o que você viu, com pelo menos ' . self::DESCRICAO_MIN . ' caracteres.');
                    }
                }],
            // Só os tipos do balão do módulo — e o lembrete, que a vistoria cria.
            'tipo'       => ['required', Rule::in([...Sinalizacao::POR_MODULO[$modulo] ?? [], 'lembrete'])],
            'lembrar_em' => ['nullable', 'date', 'after_or_equal:today'],
            'lat'        => [$exigePonto ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'lng'        => [$exigePonto ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'referencia' => ['nullable', 'string', 'max:255'],
        ], ['tipo.required' => 'Escolha o que você viu.', 'tipo.in' => 'Este tipo não é do módulo em que você está.',
            'comentario.required' => 'Descreva o que você viu, com pelo menos ' . self::DESCRICAO_MIN . ' caracteres.']);
    }

    /**
     * Cria, ou SOMA o aviso a uma pendente igual: no imóvel, o mesmo tipo; na
     * rua, o mesmo tipo a até MESMO_LUGAR_M metros.
     *
     * @param  array{lat:float,lon:float}|null  $p
     */
    private function criar(User $u, array $d, ?Lote $lote, ?array $p, ?string $referencia): JsonResponse
    {
        $modulo = Modulo::ativo($u);

        // A MESMA COISA JÁ ESTÁ SINALIZADA E PENDENTE: o aviso é SOMADO à
        // sinalização que existe, e não vira uma segunda bandeira. (O lembrete
        // é pessoal e fica de fora: cada um tem o seu.)
        if ($d['tipo'] !== 'lembrete') {
            $q = Sinalizacao::query()->doModulo($modulo)->pendentes()->where('tipo', $d['tipo'])->oldest();
            $existente = $lote
                ? $q->where('lote_id', $lote->id)->first()
                : ($p ? $this->naRuaPerto($q->whereNull('lote_id'), $p) : null);
            if ($existente) {
                return $this->somar($existente, $u, $d['comentario'] ?? null);
            }
        }

        if ($d['tipo'] === 'lembrete' && empty($d['lembrar_em'])) {
            $d['lembrar_em'] = now()->toDateString();
        }

        $s = Sinalizacao::create([
            'modulo'     => $modulo,
            'lote_id'    => $lote?->id,
            'lat'        => $p['lat'] ?? null,
            'lng'        => $p['lon'] ?? null,
            'referencia' => $referencia,
            'tipo'       => $d['tipo'],
            'comentario' => $d['comentario'] ?? null,
            'lembrar_em' => $d['lembrar_em'] ?? null,
            'user_id'    => $u->id,
        ]);

        return response()->json([
            'message' => $s->tipo === 'lembrete' && $s->lembrar_em->isFuture()
                ? 'Lembrete marcado para ' . $s->lembrar_em->format('d/m/Y') . '.'
                : ($s->naRua() ? 'Sinalização enviada: ' . $s->referencia . '.' : 'Sinalização enviada.'),
            'id' => $s->id,
            'na_rua' => $s->naRua(),
        ], 201);
    }

    /**
     * A pendente igual marcada perto: um envelope em graus reduz no banco, a
     * distância de verdade é medida aqui.
     *
     * @param  array{lat:float,lon:float}  $p
     */
    private function naRuaPerto($q, array $p): ?Sinalizacao
    {
        $dLat = self::MESMO_LUGAR_M / 111320;
        $dLon = self::MESMO_LUGAR_M / (111320 * max(cos(deg2rad($p['lat'])), 0.01));

        return $q->whereBetween('lat', [$p['lat'] - $dLat, $p['lat'] + $dLat])
            ->whereBetween('lng', [$p['lon'] - $dLon, $p['lon'] + $dLon])->get()
            ->first(fn ($s) => self::metros($p['lat'], $p['lon'], $s->lat, $s->lng) <= self::MESMO_LUGAR_M);
    }

    private static function metros(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $r = 6371000;
        $a = sin(deg2rad($la2 - $la1) / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin(deg2rad($lo2 - $lo1) / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
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
                'message' => 'Você já sinalizou "' . $s->rotulo() . '" ' . ($s->naRua() ? 'neste ponto' : 'neste imóvel') . ', e continua pendente.',
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
                : ($s->naRua() ? 'Este ponto já tinha "' : 'Este imóvel já tinha "') . $s->rotulo() . '" sinalizado desde ' . $s->created_at->format('d/m/Y')
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
            $p = self::pontos([$v->lote_id])[$v->lote_id] ?? null;
            Sinalizacao::create([
                // A vistoria de obra é de Obras: o lembrete que ela deixa também.
                'modulo' => 'obras', 'lat' => $p['lat'] ?? null, 'lng' => $p['lon'] ?? null,
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
            'modulo'     => $x->modulo,
            'lote_id'    => $x->lote_id,
            'na_rua'     => $x->naRua(),
            // Na rua, a referência em texto ocupa o lugar do imóvel.
            'imovel'     => $l ? sprintf('Q %s · Lt %s', $l->quadra ?? '—', $l->numero_lote ?? '—') : ($x->referencia ?: 'Na via'),
            'referencia' => $x->referencia,
            'lat'        => $x->lat ?? $ponto['lat'] ?? null,
            'lon'        => $x->lng ?? $ponto['lon'] ?? null,
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
    private static function pontos(array $ids): array
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
