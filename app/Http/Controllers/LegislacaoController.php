<?php

namespace App\Http\Controllers;

use App\Models\Artigo;
use App\Models\Irregularidade;
use App\Models\Legislacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parâmetros > Legislação — cadastro de leis, artigos e do vínculo com as
 * irregularidades.
 *
 * É o que destrava o uso real do sistema: sem artigo vinculado, a lavratura
 * de qualquer documento com sanção fica bloqueada (ver LavraturaService).
 *
 * Só administrador escreve aqui. Fundamentação legal errada é vício insanável
 * no auto de infração, então a lista de quem pode alterá-la é a menor possível.
 */
class LegislacaoController extends Controller
{
    /** Barra escrita para quem não é administrador. */
    private function exigirAdmin(Request $r): ?JsonResponse
    {
        return $r->user()->isAdmin()
            ? null
            : response()->json(['message' => 'Só administrador altera a legislação.'], 403);
    }

    /** GET /api/legislacao — leis com artigos e irregularidades vinculadas. */
    public function index(): JsonResponse
    {
        $leis = Legislacao::with(['artigos' => fn ($q) => $q->orderBy('numero'), 'artigos.irregularidades:id,codigo'])
            ->orderBy('nome')
            ->get()
            ->map(fn (Legislacao $l) => [
                'id'                => $l->id,
                'numero'            => $l->numero,
                'nome'              => $l->nome,
                'ano'               => $l->ano,
                'ementa'            => $l->ementa,
                'prazo_defesa_dias' => $l->prazo_defesa_dias,
                'prazo_cumprimento_dias' => $l->prazo_cumprimento_dias,
                'ciencia_notificacao' => $l->ciencia_notificacao,
                'ciencia_auto'      => $l->ciencia_auto,
                'ativa'             => $l->ativa,
                'artigos'           => $l->artigos->map(fn (Artigo $a) => [
                    'id'            => $a->id,
                    'numero'        => $a->numero,
                    'apelido'       => $a->apelido,
                    'conduta'       => $a->conduta,
                    'sancao'        => $a->sancao,
                    'base_multa'    => $a->base_multa,
                    'multa_upf'     => $a->multa_upf,
                    'multa_upf_m2'  => $a->multa_upf_m2,
                    'multa_min_upf' => $a->multa_min_upf,
                    'multa_max_upf' => $a->multa_max_upf,
                    'ativo'         => $a->ativo,
                    'irregularidades' => $a->irregularidades->pluck('codigo'),
                    'irregularidade_ids' => $a->irregularidades->pluck('id'),
                ]),
            ]);

        return response()->json([
            'leis' => $leis,
            'irregularidades' => Irregularidade::ativas()->get(['id', 'codigo', 'descricao', 'gravidade']),
            // Contagem que interessa ao administrador: quantas irregularidades
            // ainda não têm artigo. Cada uma dessas é um auto que não pode ser
            // lavrado.
            'sem_enquadramento' => Irregularidade::ativas()
                ->whereDoesntHave('artigos')
                ->count(),
        ]);
    }

    /** POST /api/legislacao — cria ou atualiza uma lei. */
    public function salvarLei(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $d = $r->validate([
            'id'                     => ['nullable', 'exists:legislacoes,id'],
            'numero'                 => ['required', 'string', 'max:40'],
            'nome'                   => ['required', 'string', 'max:160'],
            'ano'                    => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'ementa'                 => ['nullable', 'string', 'max:2000'],
            'prazo_defesa_dias'      => ['required', 'integer', 'min:1', 'max:120'],
            'prazo_cumprimento_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'ciencia_notificacao'    => ['nullable', 'string', 'max:4000'],
            'ciencia_auto'           => ['nullable', 'string', 'max:4000'],
            'ativa'                  => ['nullable', 'boolean'],
        ]);

        $lei = Legislacao::updateOrCreate(
            ['id' => $d['id'] ?? null],
            collect($d)->except('id')->all() + ['ativa' => $d['ativa'] ?? true]
        );

        return response()->json(['message' => 'Lei gravada.', 'id' => $lei->id]);
    }

    /** POST /api/legislacao/artigos — cria ou atualiza artigo e seus vínculos. */
    public function salvarArtigo(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $d = $r->validate([
            'id'              => ['nullable', 'exists:artigos,id'],
            'legislacao_id'   => ['required', 'exists:legislacoes,id'],
            'numero'          => ['required', 'string', 'max:30'],
            'apelido'         => ['nullable', 'string', 'max:60'],
            'conduta'         => ['nullable', 'string', 'max:2000'],
            'sancao'          => ['nullable', 'string', 'max:2000'],
            // A maioria das multas de obras é por área, não valor fixo — ver
            // App\Models\Artigo::calcularMulta(). `fixa` é a exceção, não a regra.
            'base_multa'      => ['required', Rule::in(array_keys(Artigo::BASES_MULTA))],
            'multa_upf'       => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'multa_upf_m2'    => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'multa_min_upf'   => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'multa_max_upf'   => ['nullable', 'numeric', 'min:0', 'max:999999', 'gte:multa_min_upf'],
            'ativo'           => ['nullable', 'boolean'],
            'irregularidades' => ['array'],
            'irregularidades.*' => ['integer', 'exists:irregularidades,id'],
        ]);

        $artigo = Artigo::updateOrCreate(
            ['id' => $d['id'] ?? null],
            collect($d)->except(['id', 'irregularidades'])->all() + ['ativo' => $d['ativo'] ?? true]
        );

        // O vínculo é o que faz o motor de legislação funcionar: sem ele o
        // artigo existe mas nunca é sugerido em vistoria nenhuma.
        $artigo->irregularidades()->sync($d['irregularidades'] ?? []);

        return response()->json([
            'message' => 'Artigo gravado.',
            'id'      => $artigo->id,
            'aviso'   => empty($d['irregularidades'])
                ? 'Artigo sem irregularidade vinculada: ele não será sugerido automaticamente em nenhuma vistoria.'
                : null,
        ]);
    }

    /** DELETE /api/legislacao/artigos/{artigo} */
    public function excluirArtigo(Request $r, Artigo $artigo): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        // Documento já lavrado guarda CÓPIA do artigo (documento_artigos), então
        // excluir o cadastro não altera peça de processo já emitida. Ainda
        // assim, desativar é o caminho normal — excluir só faz sentido para
        // registro criado por engano.
        $artigo->delete();

        return response()->json(['message' => 'Artigo excluído. Documentos já lavrados mantêm a redação original.']);
    }
}
