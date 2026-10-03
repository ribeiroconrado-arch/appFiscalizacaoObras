<?php

namespace App\Http\Controllers;

use App\Cadastro\FonteDoCadastro;
use App\Cadastro\ProprietariosVisiveis;
use App\Jobs\ProcessarCargaDoCadastro;
use App\Models\CadastroCarga;
use App\Models\Lote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Parâmetros → Cadastro municipal: a planilha mensal da prefeitura.
 *
 * Enviar e acompanhar é só do administrador. O histórico de um imóvel
 * (`historico`) é dos servidores — com o proprietário filtrado por
 * App\Cadastro\ProprietariosVisiveis, igual à aba BCI.
 */
class CadastroCargaController extends Controller
{
    /** Teto do upload, em KB. A exportação do município inteiro cabe com folga. */
    private const TETO_KB = 65536;

    private function exigirAdmin(Request $r): ?JsonResponse
    {
        return $r->user()->isAdmin()
            ? null
            : response()->json(['message' => 'Só administrador carrega o cadastro municipal.'], 403);
    }

    /** GET /api/cadastro/cargas */
    public function index(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        CadastroCarga::limparArquivosVencidos();

        return response()->json([
            'cargas' => CadastroCarga::with('usuario:id,name')->latest('id')->limit(24)->get()
                ->map(fn (CadastroCarga $c) => $c->resumo()),
            'imoveis' => DB::table('cadastro_externo_imoveis')->whereNull('ausente_desde_carga_id')->count(),
        ]);
    }

    /** POST /api/cadastro/cargas — recebe a planilha e processa logo depois da resposta. */
    public function store(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $r->validate(['arquivo' => ['required', 'file', 'max:' . self::TETO_KB]], [
            'arquivo.max' => 'A planilha passa de 64 MB.',
        ]);
        $arq = $r->file('arquivo');
        if (strtolower($arq->getClientOriginalExtension()) !== 'xlsx') {
            return response()->json(['message' => 'A planilha precisa ser .xlsx (Excel).'], 422);
        }

        CadastroCarga::limparArquivosVencidos();

        $andamento = CadastroCarga::whereIn('status', ['na_fila', 'processando'])
            ->where('updated_at', '>', now()->subMinutes(30))->first();
        if ($andamento) {
            return response()->json(['message' => 'Já há uma carga em andamento. Aguarde ela terminar.'], 409);
        }

        // A mesma planilha duas vezes não muda nada — e costuma ser engano de
        // arquivo. Recusa, a menos que quem envia insista.
        $sha = hash_file('sha256', $arq->getRealPath());
        $ultima = CadastroCarga::where('status', 'concluida')->latest('id')->first();
        if ($ultima && $ultima->arquivo_sha256 === $sha && ! $r->boolean('forcar')) {
            return response()->json([
                'message' => 'Esta é a mesma planilha da última carga (' . $ultima->arquivo_nome . '). Nada mudaria.',
                'repetida' => true,
            ], 422);
        }

        $carga = CadastroCarga::create([
            'arquivo_nome'   => mb_substr($arq->getClientOriginalName(), 0, 200),
            'arquivo_bytes'  => $arq->getSize(),
            'arquivo_sha256' => $sha,
            'user_id'        => $r->user()->id,
            'status'         => 'na_fila',
        ]);
        Storage::disk('private')->putFileAs('cargas', $arq, "{$carga->id}.xlsx");

        ProcessarCargaDoCadastro::dispatchAfterResponse($carga->id);

        return response()->json(['carga' => $carga->resumo()], 202);
    }

    /** GET /api/cadastro/cargas/{carga} — a tela consulta enquanto processa. */
    public function show(Request $r, CadastroCarga $carga): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        return response()->json(['carga' => $carga->load('usuario:id,name')->resumo()]);
    }

    /** POST /api/cadastro/cargas/{carga}/confirmar — aceita as ausências acima do limite. */
    public function confirmar(Request $r, CadastroCarga $carga): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }
        if ($carga->status !== 'aguardando_confirmacao') {
            return response()->json(['message' => 'Esta carga não está aguardando confirmação.'], 422);
        }

        $carga->update(['status' => 'na_fila', 'mensagem' => null]);
        ProcessarCargaDoCadastro::dispatchAfterResponse($carga->id, true);

        return response()->json(['carga' => $carga->resumo()], 202);
    }

    /** POST /api/cadastro/cargas/{carga}/reprocessar — "Tentar de novo". */
    public function reprocessar(Request $r, CadastroCarga $carga): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $parada = $carga->status === 'falhou' || $carga->status === 'na_fila'
            || ($carga->status === 'processando' && $carga->updated_at < now()->subMinutes(30));
        if (! $parada) {
            return response()->json(['message' => 'Esta carga não está parada.'], 422);
        }
        if (! Storage::disk('private')->exists($carga->caminhoDoArquivo())) {
            return response()->json(['message' => 'O arquivo desta carga já foi apagado. Envie a planilha de novo.'], 422);
        }

        $carga->update(['status' => 'na_fila', 'mensagem' => null]);
        ProcessarCargaDoCadastro::dispatchAfterResponse($carga->id);

        return response()->json(['carga' => $carga->resumo()], 202);
    }

    /** GET /api/cadastro/cargas/{carga}/alteracoes?campo=&tipo=&pagina= */
    public function alteracoes(Request $r, CadastroCarga $carga): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $q = DB::table('cadastro_alteracoes')->where('carga_id', $carga->id)
            ->when($r->query('campo'), fn ($q, $c) => $q->where('campo', $c))
            ->when($r->query('tipo'), fn ($q, $t) => $q->where('tipo', $t));

        $pagina = max(1, (int) $r->query('pagina', 1));
        $total = (clone $q)->count();

        return response()->json([
            'total'  => $total,
            'pagina' => $pagina,
            'paginas' => (int) ceil($total / 50),
            'campos' => DB::table('cadastro_alteracoes')->where('carga_id', $carga->id)
                ->whereNotNull('campo')->distinct()->orderBy('campo')->pluck('campo'),
            'itens'  => $q->orderBy('inscricao')->orderBy('id')->forPage($pagina, 50)
                ->get(['inscricao', 'tipo', 'campo', 'antes', 'depois']),
        ]);
    }

    /**
     * GET /api/imoveis/{lote}/cadastro/historico — o que mudou no cadastro
     * deste imóvel, carga a carga. Proprietário filtrado por perfil.
     */
    public function historico(Request $r, Lote $lote, FonteDoCadastro $fonte): JsonResponse
    {
        $inscricoes = $fonte->situacao($lote)['inscricoes'];
        if (! $inscricoes) {
            return response()->json(['itens' => []]);
        }

        $itens = DB::table('cadastro_alteracoes as a')
            ->join('cadastro_cargas as c', 'c.id', '=', 'a.carga_id')
            ->whereIn('a.inscricao', $inscricoes)
            ->orderByDesc('a.id')->limit(200)
            ->get(['a.inscricao', 'a.tipo', 'a.campo', 'a.antes', 'a.depois', 'c.arquivo_nome', 'c.concluida_em', 'c.created_at'])
            ->map(function ($a) use ($r) {
                if ($a->campo === 'proprietarios') {
                    $a->antes = $this->donosVisiveis($r, $a->antes);
                    $a->depois = $this->donosVisiveis($r, $a->depois);
                    if ($a->antes === null && $a->depois === null) {
                        return null;   // quem não vê proprietário não vê que ele mudou
                    }
                }

                return [
                    'inscricao' => $a->inscricao, 'tipo' => $a->tipo, 'campo' => $a->campo,
                    'antes' => $a->antes, 'depois' => $a->depois,
                    'carga' => $a->arquivo_nome, 'em' => $a->concluida_em ?? $a->created_at,
                ];
            })->filter()->values();

        return response()->json(['itens' => $itens]);
    }

    /** JSON de proprietários reduzido ao que este usuário vê, em texto legível. */
    private function donosVisiveis(Request $r, ?string $json): ?string
    {
        $donos = ProprietariosVisiveis::para($r->user(), json_decode((string) $json, true) ?: []);
        if (! $donos) {
            return null;
        }

        return implode('; ', array_map(
            fn ($d) => implode(' · ', array_filter([$d['nome'] ?? null, $d['documento'] ?? null, $d['endereco'] ?? null])),
            $donos
        ));
    }
}
