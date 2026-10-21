<?php

namespace App\Http\Controllers;

use App\Models\BairroEndereco;
use App\Models\Denuncia;
use App\Models\Logradouro;
use App\Models\User;
use App\Posturas\ProtocoloDenuncia;
use App\Repositories\LoteRepository;
use App\Rules\CpfCnpj;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A aba Denúncias do módulo Posturas — o módulo Perturbação do AppPOSTURAS.
 *
 * A denúncia é o REGISTRO do atendimento em campo: o agente vai ao endereço,
 * anota a fonte do barulho e o que fez (a tratativa). Não há fila nem
 * triagem. Daqui pode nascer uma notificação ("Gerar notificação"), que leva a
 * denúncia como origem (Documento::MOTIVOS_DE_ORIGEM).
 *
 * As rotas ficam atrás de `modulo:posturas`. Quem vê, vê todas — como lá; o
 * CPF e o CNPJ do responsável saem mascarados para quem não pode vê-los
 * (User::podeVerDadosDoProprietario), o mesmo papel da view
 * `denuncia_publica` de lá.
 */
class DenunciaController extends Controller
{
    /** Até quantas a lista traz de uma vez. A busca e os filtros recortam o resto. */
    private const LIMITE_LISTA = 500;

    /** Teto do CSV — o mesmo TETO_CARGA_TOTAL do AppPOSTURAS. */
    private const LIMITE_CSV = 5000;

    // ── LISTA ────────────────────────────────────────────────────

    /** GET /api/denuncias — a lista, com os filtros da tela. */
    public function index(Request $r): JsonResponse
    {
        $q = $this->filtrada($r);
        $total = (clone $q)->count();
        $lista = $q->with('agente:id,name')->orderByDesc('data_hora')->orderByDesc('id')->limit(self::LIMITE_LISTA)->get();

        $verDoc = $r->user()->podeVerDadosDoProprietario();

        return response()->json([
            'denuncias' => $lista->map(fn (Denuncia $d) => $this->item($d, $verDoc)),
            'total'     => $total,
            'limite'    => self::LIMITE_LISTA,
        ]);
    }

    /**
     * A consulta com os filtros da tela — a mesma para a lista e para o CSV,
     * senão o arquivo baixado não seria o que estava na tela.
     */
    private function filtrada(Request $r): Builder
    {
        $f = $r->validate([
            'busca'     => ['nullable', 'string', 'max:120'],
            'tratativa' => ['nullable', 'string', 'max:60'],
            'agente'    => ['nullable', 'in:eu,todos'],
            'bairro'    => ['nullable', 'string', 'max:160'],
            'de'        => ['nullable', 'date_format:Y-m-d'],
            'ate'       => ['nullable', 'date_format:Y-m-d'],
            'fonte'     => ['nullable', Rule::in(Denuncia::FONTES)],
        ]);

        return Denuncia::query()
            ->when($f['busca'] ?? null, function (Builder $q, string $t) {
                $t = '%' . trim($t) . '%';
                $q->where(fn ($b) => $b->where('protocolo', 'like', $t)->orWhere('logradouro', 'like', $t)
                    ->orWhere('bairro', 'like', $t)->orWhere('resp_nome', 'like', $t)
                    ->orWhere('empresa_nome', 'like', $t)->orWhere('placa', 'like', str_replace('-', '', $t))
                    ->orWhere('agente_nome', 'like', $t));
            })
            // "pendente" e "ativas" não são tratativas: são os recortes que a
            // tela oferece no mesmo seletor (vazio = Pendente, como lá).
            ->when($f['tratativa'] ?? null, function (Builder $q, string $t) {
                match ($t) {
                    'pendente' => $q->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', '')),
                    'ativas'   => $q->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', '<>', Denuncia::CANCELADO)),
                    default    => $q->where('tratativa', $t),
                };
            })
            ->when(($f['agente'] ?? null) === 'eu', fn ($q) => $this->doUsuario($q, $r->user()))
            ->when($f['bairro'] ?? null, fn ($q, $b) => $q->where('bairro', mb_strtoupper(trim($b))))
            ->when($f['fonte'] ?? null, fn ($q, $x) => $q->where('fonte_sonora', $x))
            ->when($f['de'] ?? null, fn ($q, $d) => $q->where('data_hora', '>=', $d . ' 00:00:00'))
            ->when($f['ate'] ?? null, fn ($q, $d) => $q->where('data_hora', '<=', $d . ' 23:59:59'));
    }

    /**
     * "Minhas": as que o usuário registrou. A importada sem usuário ligado é
     * reconhecida pelo nome, como no AppPOSTURAS.
     */
    private function doUsuario(Builder $q, User $u): Builder
    {
        return $q->where(fn ($b) => $b->where('agente_id', $u->id)
            ->orWhere(fn ($c) => $c->whereNull('agente_id')->where('agente_nome', $u->name)));
    }

    /** Uma linha da lista. @return array<string, mixed> */
    private function item(Denuncia $d, bool $verDoc): array
    {
        return [
            'id'          => $d->id,
            'protocolo'   => $d->protocolo,
            // A hora é LOCAL e sem fuso (como no Posturas): sai como está.
            'data'        => $d->data_hora?->format('d/m/Y H:i'),
            'data_iso'    => $d->data_hora?->format('Y-m-d\TH:i'),
            'agente'      => $d->agente?->name ?? $d->agente_nome,
            'logradouro'  => $d->logradouro,
            'numero'      => $d->numero,
            'bairro'      => $d->bairro,
            'endereco'    => $d->endereco(),
            'local_tipo'  => $d->local_tipo,
            'fonte'       => $d->fonte_sonora,
            'responsavel' => $d->resp_nome ?: $d->empresa_nome ?: ($d->placa ? 'Veículo ' . $d->placa : null),
            'documento'   => $verDoc ? ($d->resp_cpf ?: $d->empresa_cnpj) : Denuncia::mascarar($d->resp_cpf ?: $d->empresa_cnpj),
            'tratativa'   => $d->tratativa ?: null,
            'cancelada'   => $d->cancelada(),
            'lat'         => $d->lat,
            'lng'         => $d->lng,
        ];
    }

    // ── FICHA ────────────────────────────────────────────────────

    /** GET /api/denuncias/{denuncia} — tudo, para ver ou editar. */
    public function show(Request $r, Denuncia $denuncia): JsonResponse
    {
        $denuncia->load(['agente:id,name,matricula', 'documentos' => fn ($q) => $q->orderBy('id')]);
        $verDoc = $r->user()->podeVerDadosDoProprietario() || $denuncia->podeEditar($r->user());
        $doc = fn (?string $v) => $verDoc ? $v : Denuncia::mascarar($v);

        return response()->json(['denuncia' => $this->item($denuncia, $verDoc) + [
            'matricula'     => $denuncia->agente?->matricula ?? $denuncia->agente_matricula,
            'gps_precisao'  => $denuncia->gps_precisao,
            'resp_nome'     => $denuncia->resp_nome,
            'resp_cpf'      => $doc($denuncia->resp_cpf),
            'empresa_nome'  => $denuncia->empresa_nome,
            'empresa_cnpj'  => $doc($denuncia->empresa_cnpj),
            'placa'         => $denuncia->placa,
            'veic_marca'    => $denuncia->veic_marca,
            'veic_modelo'   => $denuncia->veic_modelo,
            'veic_cor'      => $denuncia->veic_cor,
            'obs'           => $denuncia->obs,
            'cancelado_por' => $denuncia->cancelado_por_nome,
            'cancelado_em'  => $denuncia->cancelado_em?->format('d/m/Y H:i'),
            'logs'          => array_values(array_map(fn ($l) => [
                'acao'     => $l['acao'] ?? '',
                'agente'   => $l['agente'] ?? null,
                'mat'      => $l['mat'] ?? null,
                // Carimbo do servidor, em UTC: a tela mostra no fuso de lá.
                'em'       => ! empty($l['ts']) ? Carbon::parse($l['ts'])->setTimezone('America/Cuiaba')->format('d/m/Y H:i') : null,
                'mudancas' => $l['mudancas'] ?? [],
            ], $denuncia->logs ?? [])),
            'documentos'    => $denuncia->documentos->map(fn ($x) => [
                'id' => $x->id, 'tipo' => $x->rotuloTipo(), 'numero' => $x->numeroFormatado(), 'status' => $x->status,
            ]),
            'pode_editar'   => $denuncia->podeEditar($r->user()),
            'pode_notificar' => ! $denuncia->cancelada() && $r->user()->podeLavrarDocumento(),
        ]]);
    }

    // ── GRAVAR ───────────────────────────────────────────────────

    /** As regras do formulário — as mesmas na criação e na edição. */
    private function regras(): array
    {
        return [
            'data_hora'    => ['required', 'date_format:Y-m-d\TH:i'],
            'logradouro'   => ['required', 'string', 'max:180'],
            'numero'       => ['nullable', 'string', 'max:20'],
            'bairro'       => ['required', 'string', 'max:160'],
            'local_tipo'   => ['nullable', Rule::in(Denuncia::LOCAIS)],
            'lat'          => ['nullable', 'numeric', 'between:-90,90'],
            'lng'          => ['nullable', 'numeric', 'between:-180,180'],
            'gps_precisao' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'fonte_sonora' => ['nullable', Rule::in(Denuncia::FONTES)],
            'resp_nome'    => ['nullable', 'string', 'max:160'],
            'resp_cpf'     => ['nullable', 'string', 'max:20', new CpfCnpj],
            'empresa_nome' => ['nullable', 'string', 'max:160'],
            'empresa_cnpj' => ['nullable', 'string', 'max:20', new CpfCnpj],
            'placa'        => ['nullable', 'string', 'max:12'],
            'veic_marca'   => ['nullable', 'string', 'max:60'],
            'veic_modelo'  => ['nullable', 'string', 'max:60'],
            'veic_cor'     => ['nullable', 'string', 'max:40'],
            'tratativa'    => ['nullable', Rule::in(Denuncia::TRATATIVAS)],
            'obs'          => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * O que vai ao banco, normalizado como no AppPOSTURAS: endereço em
     * maiúsculas, placa sem hífen, e só os dados da fonte escolhida — trocar
     * de Residência para Veículo não deixa o CPF digitado antes escondido na
     * linha.
     */
    private function dados(array $d): array
    {
        $fonte = $d['fonte_sonora'] ?? null;
        $pf = $fonte === 'Residência' || $fonte === null;

        return [
            'data_hora'    => str_replace('T', ' ', $d['data_hora']) . ':00',
            'logradouro'   => Logradouro::normalizar($d['logradouro']),
            'numero'       => trim((string) ($d['numero'] ?? '')) ?: null,
            'bairro'       => Logradouro::normalizar($d['bairro']),
            'local_tipo'   => $d['local_tipo'] ?? 'Local Exato',
            'lat'          => $d['lat'] ?? null,
            'lng'          => $d['lng'] ?? null,
            'gps_precisao' => $d['gps_precisao'] ?? null,
            'fonte_sonora' => $fonte,
            'resp_nome'    => $pf ? ($d['resp_nome'] ?? null) : null,
            'resp_cpf'     => $pf ? ($d['resp_cpf'] ?? null) : null,
            'empresa_nome' => $fonte === 'Empresa' ? ($d['empresa_nome'] ?? null) : null,
            'empresa_cnpj' => $fonte === 'Empresa' ? ($d['empresa_cnpj'] ?? null) : null,
            'placa'        => $fonte === 'Veículo' && ! empty($d['placa']) ? strtoupper(str_replace('-', '', $d['placa'])) : null,
            'veic_marca'   => $fonte === 'Veículo' ? ($d['veic_marca'] ?? null) : null,
            'veic_modelo'  => $fonte === 'Veículo' ? ($d['veic_modelo'] ?? null) : null,
            'veic_cor'     => $fonte === 'Veículo' ? ($d['veic_cor'] ?? null) : null,
            'tratativa'    => $d['tratativa'] ?? null,
            'obs'          => trim((string) ($d['obs'] ?? '')) ?: null,
        ];
    }

    /** POST /api/denuncias */
    public function store(Request $r, LoteRepository $lotes): JsonResponse
    {
        $u = $r->user();
        if (! $u->canEdit()) {
            return response()->json(['message' => 'Seu nível de acesso não permite registrar denúncias.'], 403);
        }

        $dados = $this->dados($r->validate($this->regras()));
        $this->cadastrarEndereco($dados['logradouro'], $dados['bairro']);

        $den = Denuncia::create($dados + [
            'protocolo'        => ProtocoloDenuncia::gerar(fn ($p) => Denuncia::where('protocolo', $p)->exists()),
            'agente_id'        => $u->id,
            'agente_nome'      => $u->name,
            'agente_matricula' => $u->matricula,
            'lote_id'          => $this->loteDoPonto($lotes, $dados),
            'logs'             => [],
        ]);

        return response()->json([
            'message' => 'Denúncia registrada. Protocolo ' . $den->protocolo . '.',
            'id' => $den->id, 'protocolo' => $den->protocolo,
        ], 201);
    }

    /** PATCH /api/denuncias/{denuncia} */
    public function update(Request $r, Denuncia $denuncia, LoteRepository $lotes): JsonResponse
    {
        if (! $denuncia->podeEditar($r->user())) {
            return response()->json(['message' => $denuncia->cancelada()
                ? 'Denúncia cancelada não se altera.' : 'Só quem registrou (ou o administrador) altera a denúncia.'], 403);
        }

        $dados = $this->dados($r->validate($this->regras()));
        $this->cadastrarEndereco($dados['logradouro'], $dados['bairro']);

        // O histórico de lá: o que mudou de relevante, em palavras.
        $mudancas = [];
        if (($denuncia->tratativa ?: null) !== $dados['tratativa']) {
            $mudancas[] = 'Tratativa: "' . ($denuncia->tratativa ?: 'Pendente') . '" → "' . ($dados['tratativa'] ?: 'Pendente') . '"';
        }
        if ($denuncia->logradouro !== $dados['logradouro'] || $denuncia->numero !== $dados['numero']) {
            $mudancas[] = 'Endereço alterado';
        }
        if (($denuncia->obs ?: null) !== $dados['obs']) {
            $mudancas[] = 'Observação alterada';
        }

        $mudouPonto = (float) $denuncia->lat !== (float) $dados['lat'] || (float) $denuncia->lng !== (float) $dados['lng'];
        $denuncia->update($dados + [
            'logs' => $this->comLog($denuncia, $r->user(), 'EDIÇÃO', $mudancas),
        ] + ($mudouPonto ? ['lote_id' => $this->loteDoPonto($lotes, $dados)] : []));

        return response()->json(['message' => 'Denúncia atualizada.']);
    }

    /** POST /api/denuncias/{denuncia}/cancelar — com o motivo, se houver. */
    public function cancelar(Request $r, Denuncia $denuncia): JsonResponse
    {
        if (! $denuncia->podeEditar($r->user())) {
            return response()->json(['message' => 'Só quem registrou (ou o administrador) cancela a denúncia.'], 403);
        }
        $motivo = trim((string) $r->validate(['motivo' => ['nullable', 'string', 'max:500']])['motivo'] ?? '');

        $u = $r->user();
        $denuncia->update([
            'tratativa'           => Denuncia::CANCELADO,
            'cancelado_por_nome'  => $u->name,
            'cancelado_matricula' => $u->matricula,
            'cancelado_em'        => now(),
            // Como lá: o motivo vai também para a observação, que é o que se lê.
            'obs'  => $motivo !== '' ? trim(($denuncia->obs ? $denuncia->obs . "\n" : '') . '[Cancelamento: ' . $motivo . ']') : $denuncia->obs,
            'logs' => $this->comLog($denuncia, $u, 'CANCELAMENTO', $motivo !== '' ? ['Motivo: ' . $motivo] : []),
        ]);

        return response()->json(['message' => 'Denúncia cancelada.']);
    }

    /** O histórico com mais uma entrada, no formato de lá. */
    private function comLog(Denuncia $d, User $u, string $acao, array $mudancas): array
    {
        return [...($d->logs ?? []), [
            'ts' => now()->toIso8601String(), 'agente' => $u->name, 'mat' => $u->matricula ?? '',
            'acao' => $acao, 'mudancas' => $mudancas,
        ]];
    }

    /**
     * Rua e bairro novos entram nas listas de sugestão — o auto-cadastro do
     * AppPOSTURAS, que mantém o autocomplete em dia sem cadastro prévio. A
     * relação rua↔bairro continua sendo do administrador (Parâmetros).
     */
    private function cadastrarEndereco(string $logradouro, string $bairro): void
    {
        Logradouro::firstOrCreate(['nome' => $logradouro]);
        BairroEndereco::firstOrCreate(['nome' => $bairro]);
    }

    /** O lote em que o ponto do GPS caiu — quando há GPS, e quando cai num. */
    private function loteDoPonto(LoteRepository $lotes, array $dados): ?int
    {
        if ($dados['lat'] === null || $dados['lng'] === null) {
            return null;
        }
        try {
            return $lotes->contendo((float) $dados['lat'], (float) $dados['lng'])?->id;
        } catch (\Throwable) {
            return null;   // sem base espacial (ambiente de teste): segue sem lote
        }
    }

    // ── APOIO AO FORMULÁRIO ──────────────────────────────────────

    /**
     * GET /api/denuncias/reincidencia — o alerta do formulário: as últimas
     * quatro no mesmo endereço (mesma rua e, se houver, mesmo número), sem as
     * canceladas e sem a que está sendo editada.
     */
    public function reincidencia(Request $r): JsonResponse
    {
        $d = $r->validate([
            'logradouro' => ['required', 'string', 'max:180'],
            'numero'     => ['nullable', 'string', 'max:20'],
            'exceto'     => ['nullable', 'integer'],
        ]);

        $lista = Denuncia::noEndereco($d['logradouro'], $d['numero'] ?? null)
            ->when($d['exceto'] ?? null, fn ($q, $id) => $q->where('id', '<>', $id))
            ->limit(4)->get(['id', 'protocolo', 'tratativa', 'data_hora']);

        return response()->json(['denuncias' => $lista->map(fn ($x) => [
            'id' => $x->id, 'protocolo' => $x->protocolo, 'tratativa' => $x->tratativa ?: null,
            'data' => $x->data_hora?->format('d/m/Y H:i'),
        ])]);
    }

    // ── PAINEL ───────────────────────────────────────────────────

    /**
     * GET /api/denuncias/painel — os números do período (padrão: últimos 12
     * meses): totais, por tratativa, por mês, por fonte e os bairros que mais
     * denunciam. O dashboard do AppPOSTURAS, sem as que foram canceladas.
     */
    public function painel(Request $r): JsonResponse
    {
        $f = $r->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);
        $de = $f['de'] ?? now('America/Cuiaba')->subMonths(11)->startOfMonth()->format('Y-m-d');
        $ate = $f['ate'] ?? now('America/Cuiaba')->format('Y-m-d');

        $base = fn () => Denuncia::query()->whereBetween('data_hora', [$de . ' 00:00:00', $ate . ' 23:59:59']);
        $ativas = fn () => $base()->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', '<>', Denuncia::CANCELADO));

        // Agrupado em PHP, e não com DATE_FORMAT: o mesmo código roda no MySQL
        // e no sqlite dos testes, e o volume (milhares) cabe na memória.
        $datas = $ativas()->pluck('data_hora');
        $porMes = [];
        for ($m = Carbon::parse($de)->startOfMonth(); $m->lte(Carbon::parse($ate)); $m->addMonth()) {
            $porMes[$m->format('Y-m')] = 0;
        }
        foreach ($datas as $d) {
            $chave = Carbon::parse($d)->format('Y-m');
            if (isset($porMes[$chave])) {
                $porMes[$chave]++;
            }
        }

        $contar = fn (string $col) => $ativas()->selectRaw("$col as valor, count(*) as n")->groupBy($col)
            ->orderByDesc('n')->get()->map(fn ($x) => ['valor' => $x->valor ?: null, 'n' => (int) $x->n]);

        return response()->json([
            'de' => $de, 'ate' => $ate,
            'total'      => $datas->count(),
            'pendentes'  => $ativas()->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', ''))->count(),
            'canceladas' => $base()->where('tratativa', Denuncia::CANCELADO)->count(),
            'com_gps'    => $ativas()->whereNotNull('lat')->count(),
            'por_tratativa' => $contar('tratativa'),
            'por_fonte'     => $contar('fonte_sonora'),
            'por_bairro'    => $contar('bairro')->take(10)->values(),
            'por_mes'       => collect($porMes)->map(fn ($n, $mes) => ['mes' => $mes, 'n' => $n])->values(),
        ]);
    }

    // ── MAPA ─────────────────────────────────────────────────────

    /**
     * GET /api/denuncias/mapa?bbox= — as que têm GPS dentro do quadro, para a
     * camada Denúncias. Canceladas ficam fora: não são ocorrência.
     */
    public function mapa(Request $r): JsonResponse
    {
        $d = $r->validate([
            'bbox' => ['required', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        [$o, $s, $l, $n] = array_map('floatval', explode(',', $d['bbox']));

        $lista = Denuncia::query()
            ->whereNotNull('lat')->whereBetween('lat', [$s, $n])->whereBetween('lng', [$o, $l])
            ->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', '<>', Denuncia::CANCELADO))
            ->when($d['dias'] ?? null, fn ($q, $dias) => $q->where('data_hora', '>=', now('America/Cuiaba')->subDays($dias)->format('Y-m-d 00:00:00')))
            ->orderByDesc('data_hora')->limit(1500)
            ->get(['id', 'protocolo', 'data_hora', 'logradouro', 'numero', 'bairro', 'tratativa', 'fonte_sonora', 'lat', 'lng']);

        return response()->json(['denuncias' => $lista->map(fn (Denuncia $x) => [
            'id' => $x->id, 'protocolo' => $x->protocolo, 'data' => $x->data_hora?->format('d/m/Y H:i'),
            'endereco' => $x->endereco(), 'bairro' => $x->bairro, 'tratativa' => $x->tratativa ?: null,
            'fonte' => $x->fonte_sonora, 'lat' => $x->lat, 'lng' => $x->lng,
        ])]);
    }

    // ── CSV ──────────────────────────────────────────────────────

    /**
     * GET /api/denuncias/csv — a lista filtrada em planilha (o exportacao.js
     * de lá). Separador ";" e BOM, que é o que o Excel em português abre
     * direto com acento e coluna certa.
     */
    public function csv(Request $r): StreamedResponse
    {
        $q = $this->filtrada($r)->with('agente:id,name')->orderByDesc('data_hora')->limit(self::LIMITE_CSV);
        $verDoc = $r->user()->podeVerDadosDoProprietario();

        return response()->streamDownload(function () use ($q, $verDoc) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Protocolo', 'Data/hora', 'Agente', 'Logradouro', 'Número', 'Bairro', 'Localização',
                'Fonte sonora', 'Responsável', 'CPF/CNPJ', 'Placa', 'Tratativa', 'Latitude', 'Longitude', 'Observação'], ';');
            foreach ($q->cursor() as $d) {
                $doc = $d->resp_cpf ?: $d->empresa_cnpj;
                fputcsv($out, [
                    $d->protocolo, $d->data_hora?->format('d/m/Y H:i'), $d->agente?->name ?? $d->agente_nome,
                    $d->logradouro, $d->numero, $d->bairro, $d->local_tipo, $d->fonte_sonora,
                    $d->resp_nome ?: $d->empresa_nome, $verDoc ? $doc : Denuncia::mascarar($doc), $d->placa,
                    $d->tratativa ?: 'Pendente', $d->lat, $d->lng, $d->obs,
                ], ';');
            }
            fclose($out);
        }, 'denuncias-' . now('America/Cuiaba')->format('Ymd-Hi') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
