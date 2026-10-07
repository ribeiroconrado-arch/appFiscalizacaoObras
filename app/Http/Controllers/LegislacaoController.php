<?php

namespace App\Http\Controllers;

use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Legislacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parâmetros > Legislação — cadastro de leis e artigos, e dos TERMOS DE BUSCA
 * de cada artigo: o vocabulário de campo ("escavação", "terraplenagem") pelo
 * qual o fiscal acha o artigo na vistoria (Artigo::casaCom).
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

    /** GET /api/legislacao — leis com os artigos e os termos de busca. */
    public function index(): JsonResponse
    {
        $leis = Legislacao::with(['artigos' => fn ($q) => $q->orderBy('numero')])
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
                    'multa_area'    => $a->multa_area,
                    'multa_faixas'  => $a->multa_faixas,
                    'multa_mult_min' => $a->multa_mult_min,
                    'multa_mult_max' => $a->multa_mult_max,
                    'multa_rotulo'  => $a->rotuloMulta(),
                    'ativo'         => $a->ativo,
                    'termos'        => $a->termos ?? [],
                    'embargo'            => $a->embargo,
                    'embargo_modo'       => $a->embargo_modo,
                    'embargo_prazo_dias' => $a->embargo_prazo_dias,
                    'embargo_rotulo'     => $a->rotuloEmbargo(),
                ]),
            ]);

        return response()->json(['leis' => $leis]);
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

    /** POST /api/legislacao/artigos — cria ou atualiza o artigo e os termos de busca. */
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
            // Sobre QUAL área — só para "por m²" e "por faixa".
            'multa_area'      => ['nullable', 'required_if:base_multa,por_m2,faixas', Rule::in(array_keys(Artigo::AREAS_MULTA))],
            // Faixas: valor FECHADO por faixa de área; a última é aberta.
            'multa_faixas'          => ['nullable', 'required_if:base_multa,faixas', 'array', 'min:2', 'max:12'],
            'multa_faixas.*.ate_m2' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'multa_faixas.*.upf'    => ['required', 'numeric', 'min:0', 'max:999999'],
            // Múltiplo do alvará: iguais = fixo; diferentes = o fiscal informa.
            'multa_mult_min'  => ['nullable', 'required_if:base_multa,multiplo_alvara', 'numeric', 'gt:0', 'max:1000'],
            'multa_mult_max'  => ['nullable', 'required_if:base_multa,multiplo_alvara', 'numeric', 'gt:0', 'max:1000', 'gte:multa_mult_min'],
            'ativo'           => ['nullable', 'boolean'],
            'termos'          => ['array', 'max:40'],
            'termos.*'        => ['string', 'max:60'],
            // Embargo: se o artigo embarga, tem de dizer QUANDO — de imediato,
            // ou só depois de um prazo (e de quantos dias).
            'embargo'            => ['nullable', Rule::in(array_keys(Artigo::EMBARGO))],
            'embargo_modo'       => ['nullable', 'required_if:embargo,cabe,exclusivo', Rule::in(array_keys(Artigo::EMBARGO_MODOS))],
            'embargo_prazo_dias' => ['nullable', 'required_if:embargo_modo,apos_prazo', 'integer', 'min:1', 'max:365'],
        ], [
            'multa_area.required_if'         => 'Diga sobre qual área a multa é calculada.',
            'multa_faixas.required_if'       => 'Informe as faixas de área e o valor de cada uma.',
            'multa_faixas.min'               => 'São precisas pelo menos duas faixas (a última é a "acima de").',
            'multa_mult_min.required_if'     => 'Informe o multiplicador do alvará.',
            'multa_mult_max.required_if'     => 'Informe o multiplicador do alvará.',
            'multa_mult_max.gte'             => 'O multiplicador máximo não pode ser menor que o mínimo.',
            'embargo_modo.required_if'       => 'Diga se o embargo é imediato ou após prazo.',
            'embargo_prazo_dias.required_if' => 'Informe de quantos dias é o prazo antes do embargo.',
        ]);

        // Cada forma de multa guarda só os campos DELA: artigo "valor fixo" com
        // faixas esquecidas de uma edição anterior é dado que engana.
        $porArea = in_array($d['base_multa'], Artigo::BASES_POR_AREA, true);
        $d['multa_area'] = $porArea ? $d['multa_area'] : null;
        if ($d['base_multa'] !== 'fixa') { $d['multa_upf'] = null; }
        if ($d['base_multa'] !== 'por_m2') { $d['multa_upf_m2'] = $d['multa_min_upf'] = $d['multa_max_upf'] = null; }
        if ($d['base_multa'] !== 'multiplo_alvara') { $d['multa_mult_min'] = $d['multa_mult_max'] = null; }
        if ($d['base_multa'] === 'faixas') {
            // Limites crescentes, e a faixa aberta ("acima de") por último e
            // única: sem ela, uma obra maior que o último limite ficaria sem multa.
            $faixas = array_values($d['multa_faixas']);
            $anterior = 0.0;
            foreach ($faixas as $i => $fx) {
                $ate = $fx['ate_m2'] ?? null;
                $ultima = $i === count($faixas) - 1;
                if ($ultima !== ($ate === null)) {
                    return response()->json(['message' => 'Só a última faixa fica aberta ("acima de"); as outras precisam do limite em m².'], 422);
                }
                if ($ate !== null && (float) $ate <= $anterior) {
                    return response()->json(['message' => 'Os limites das faixas precisam crescer de uma para a outra.'], 422);
                }
                $anterior = (float) ($ate ?? $anterior);
                $faixas[$i] = ['ate_m2' => $ate === null ? null : (float) $ate, 'upf' => (float) $fx['upf']];
            }
            $d['multa_faixas'] = $faixas;
        } else {
            $d['multa_faixas'] = null;
        }

        // Sem embargo, modo e prazo não significam nada; embargo imediato não
        // tem prazo. Gravar limpo evita artigo "não cabe embargo, após 5 dias".
        $d['embargo'] = $d['embargo'] ?? 'nao';
        if ($d['embargo'] === 'nao') {
            $d['embargo_modo'] = null;
        }
        if (($d['embargo_modo'] ?? null) !== 'apos_prazo') {
            $d['embargo_prazo_dias'] = null;
        }

        // Termos limpos e sem repetir ("Escavação" e "escavacao" são o mesmo
        // para a busca, que ignora acento e caixa): fica a primeira grafia.
        $termos = [];
        foreach ($d['termos'] ?? [] as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', $t));
            if ($t !== '') {
                $termos[Artigo::normalizar($t)] ??= $t;
            }
        }

        $artigo = Artigo::updateOrCreate(
            ['id' => $d['id'] ?? null],
            collect($d)->except(['id', 'termos'])->all()
                + ['ativo' => $d['ativo'] ?? true, 'termos' => $termos ? array_values($termos) : null]
        );

        return response()->json([
            'message' => 'Artigo gravado.',
            'id'      => $artigo->id,
            'aviso'   => ! $termos
                ? 'Artigo sem termos de busca: na vistoria ele só é achado pelo número, apelido ou conduta.'
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

    /**
     * DELETE /api/legislacao/{legislacao} — exclui a lei e seus artigos.
     *
     * Recusa quando algum documento cita a lei: o cadastro é o que sustenta a
     * fundamentação exibida na tela e na reimpressão, e apagá-lo deixaria o
     * documento apontando para o vazio. Nesse caso o caminho é DESATIVAR a lei
     * — ela some das opções de novos documentos e continua sustentando os
     * antigos.
     */
    public function excluirLei(Request $r, Legislacao $legislacao): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $emUso = Documento::where('legislacao_id', $legislacao->id)->count();
        if ($emUso) {
            return response()->json([
                'message' => "Esta lei é citada por {$emUso} documento(s) e não pode ser excluída. "
                           . 'Desative-a para que deixe de aparecer em novos documentos.',
            ], 422);
        }

        $legislacao->artigos()->delete();
        $legislacao->delete();

        return response()->json(['message' => 'Lei excluída.']);
    }
}
