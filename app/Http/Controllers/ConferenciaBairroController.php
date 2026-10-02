<?php

namespace App\Http\Controllers;

use App\Cadastro\BairrosDoDesenho;
use App\Cadastro\FonteDoCadastro;
use App\Cadastro\PlanilhaDoCadastro;
use App\Services\ConferenciaComCadastro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Conferência do BAIRRO com o cadastro da prefeitura — a que continua depois
 * da importação. Só curador: é ferramenta de curadoria, e a lista mostra
 * inscrição e situação de imóveis do cadastro.
 *
 *   GET  /api/conferencias            os bairros e a última conferência de cada
 *   GET  /api/conferencias/bairro     a última conferência de um bairro + justificativas
 *   POST /api/conferencias/bairro     confere de novo (cadastro carregado ou planilha)
 *   POST /api/conferencias/justificar justifica (ou desfaz a justificativa de) uma pendência
 */
class ConferenciaBairroController extends Controller
{
    private const TIPOS = ['nao_encontrados', 'inativos', 'sem_lote', 'sem_inscricao'];

    private function exigirCurador(Request $r): ?JsonResponse
    {
        return $r->user()->podeCurarCadastro()
            ? null
            : response()->json(['message' => 'A conferência com o cadastro é do curador.'], 403);
    }

    public function index(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }

        $b = new BairrosDoDesenho();
        $codigos = $b->codigos();
        $ultimas = DB::table('conferencias_bairro')->get()->keyBy('bairro');
        $justif = DB::table('conferencia_justificativas')->selectRaw('bairro, COUNT(*) n')
            ->groupBy('bairro')->pluck('n', 'bairro');

        $bairros = DB::table('lotes')->where('situacao', 'ativo')->whereNotNull('bairro')->where('bairro', '<>', '')
            ->distinct()->orderBy('bairro')->pluck('bairro')
            ->map(function ($nome) use ($b, $codigos, $ultimas, $justif) {
                $u = $ultimas[$nome] ?? null;
                $res = $u ? json_decode($u->resultado, true) : null;

                return [
                    'bairro'       => $nome,
                    'oficial'      => $b->oficial($nome),
                    'ligado'       => isset($codigos[BairrosDoDesenho::chave($nome)]),
                    'conferido_em' => $u ? date('d/m/Y H:i', strtotime($u->conferido_em)) : null,
                    'divergencias' => $res ? max(0, (int) $res['total_divergencias'] - (int) ($justif[$nome] ?? 0)) : null,
                ];
            })->values();

        return response()->json(['bairros' => $bairros]);
    }

    public function mostrar(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $bairro = (string) $r->query('bairro', '');

        $u = DB::table('conferencias_bairro')->where('bairro', $bairro)->first();
        $res = $u ? json_decode($u->resultado, true) : null;

        return response()->json([
            'bairro'         => $bairro,
            'oficial'        => (new BairrosDoDesenho())->oficial($bairro),
            'resultado'      => $res,
            // Algum lote mudou depois da conferência? A tela reconfere sozinha
            // (com a mesma fonte) e a pendência resolvida sai da lista.
            'em_dia'         => $res ? app(ConferenciaComCadastro::class)->bairroEmDia($res, $u->conferido_em) : null,
            'justificativas' => $this->justificativas($bairro),
        ]);
    }

    public function conferir(Request $r, ConferenciaComCadastro $conferencia): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $d = $r->validate([
            'bairro'   => ['required', 'string', 'max:160'],
            'planilha' => ['nullable', 'file', 'max:30720'],
            'fonte'    => ['nullable', 'in:ultima,carregado'],
        ]);
        if (! DB::table('lotes')->where('bairro', $d['bairro'])->where('situacao', 'ativo')->exists()) {
            return response()->json(['message' => 'Esse bairro não tem lote no mapa.'], 422);
        }
        $anterior = DB::table('conferencias_bairro')->where('bairro', $d['bairro'])->value('resultado');

        try {
            // Planilha anexada, a MESMA da última conferência (fonte=ultima,
            // sem arquivo), ou o cadastro carregado.
            [$fonte, $descricao] = $conferencia->fonteDoPedido($r, $anterior ? json_decode($anterior, true) : null);
            $resultado = $conferencia->conferirBairro($d['bairro'], $fonte, $descricao);
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json([
            'bairro'         => $d['bairro'],
            'resultado'      => $resultado,
            'justificativas' => $this->justificativas($d['bairro']),
        ]);
    }

    public function justificar(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $d = $r->validate([
            'bairro'  => ['required', 'string', 'max:160'],
            'chave'   => ['required', 'string', 'max:60'],
            'tipo'    => ['required', 'in:' . implode(',', self::TIPOS)],
            'motivo'  => ['nullable', 'string', 'max:500'],
            'remover' => ['nullable', 'boolean'],
        ]);
        $onde = ['bairro' => $d['bairro'], 'chave' => $d['chave'], 'tipo' => $d['tipo']];

        if (! empty($d['remover'])) {
            DB::table('conferencia_justificativas')->where($onde)->delete();

            return response()->json(['message' => 'A pendência voltou para a lista.',
                'justificativas' => $this->justificativas($d['bairro'])]);
        }

        if (mb_strlen(trim((string) ($d['motivo'] ?? ''))) < 10) {
            return response()->json(['message' => 'Descreva o motivo em ao menos 10 caracteres.'], 422);
        }
        DB::table('conferencia_justificativas')->updateOrInsert($onde, [
            'motivo'     => trim($d['motivo']),
            'user_id'    => $r->user()->id,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Pendência justificada: sai da lista, e o motivo fica guardado.',
            'justificativas' => $this->justificativas($d['bairro'])]);
    }

    /** @return array<string, array{motivo:string, por:?string, em:string}> "tipo|chave" => ... */
    private function justificativas(string $bairro): array
    {
        return DB::table('conferencia_justificativas as j')
            ->leftJoin('users as u', 'u.id', '=', 'j.user_id')
            ->where('j.bairro', $bairro)
            ->get(['j.tipo', 'j.chave', 'j.motivo', 'u.name', 'j.updated_at'])
            ->mapWithKeys(fn ($j) => [$j->tipo . '|' . $j->chave => [
                'motivo' => $j->motivo, 'por' => $j->name, 'em' => date('d/m/Y', strtotime($j->updated_at)),
            ]])->all();
    }
}
