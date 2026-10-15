<?php

namespace App\Http\Controllers;

use App\Cadastro\FonteDoCadastro;
use App\Cadastro\ProprietariosVisiveis;
use App\Models\Lote;
use App\Services\FracaoIdealDeLote;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/imoveis/{lote}/unidades — o terreno em condomínio e as unidades
 * de fração ideal, cada uma com o SEU proprietário.
 *
 * Serve às duas fichas: a do terreno (aba Unidades) e a da unidade (o caminho
 * de volta ao terreno e as outras unidades). Pedido pelo lote que está aberto,
 * seja ele o terreno ou uma das unidades.
 *
 * O proprietário vem do cadastro municipal pela inscrição da própria unidade
 * (variação 001, 002…) — ver CadastroCarregado::linhasDoLote —, e passa pela
 * mesma régua de visibilidade da aba BCI (ProprietariosVisiveis).
 */
class UnidadesDoTerrenoController extends Controller
{
    public function __construct(private FonteDoCadastro $fonte)
    {
    }

    public function mostrar(Lote $lote): JsonResponse
    {
        abort_if($lote->em_revisao && ! auth()->user()?->podeCurarCadastro(), 404);

        $terreno = $lote->terreno_id ? Lote::find($lote->terreno_id) : $lote;
        if (! $terreno || ! $terreno->condominio) {
            return response()->json(['condominio' => null, 'unidades' => []]);
        }

        $usuario = request()->user();
        $unidades = Lote::where('terreno_id', $terreno->id)->where('situacao', 'ativo')
            ->orderBy('desmembramento')->get();

        $lista = $unidades->map(function (Lote $u) use ($usuario) {
            $donos = ProprietariosVisiveis::para($usuario, $this->fonte->proprietarios($u));

            return [
                'id'              => $u->id,
                'unidade'         => str_pad((string) $u->desmembramento, 3, '0', STR_PAD_LEFT),
                'numero_lote'     => $u->numero_lote,
                'inscricao'       => $u->inscricaoFormatada(),
                'fracao_ideal_m2' => $u->fracao_ideal_m2 !== null ? (float) $u->fracao_ideal_m2 : null,
                'area_exclusiva'  => $u->unidade_desenhada ? (float) $u->area_gis_m2 : null,
                // null = o usuário não vê o bloco de proprietários (externo).
                'proprietarios'   => $donos,
                'documentos'      => DB::table('documentos')->where('lote_id', $u->id)->count(),
                'vistorias'       => DB::table('vistorias')->where('lote_id', $u->id)->count(),
            ];
        })->values();

        $somaFracoes = (float) $unidades->sum(fn ($u) => (float) $u->fracao_ideal_m2);
        $somaExclusivas = (float) $unidades->filter(fn ($u) => $u->unidade_desenhada)->sum(fn ($u) => (float) $u->area_gis_m2);
        $areaDesenho = (float) $terreno->area_gis_m2;

        $ato = DB::table('lote_ato_lotes as al')->join('lote_atos as at', 'at.id', '=', 'al.ato_id')
            ->leftJoin('protocolos as p', 'p.id', '=', 'at.protocolo_id')
            ->where('al.lote_id', $terreno->id)->where('al.papel', 'terreno')->where('at.tipo', 'fracao_ideal')
            ->orderByDesc('at.id')->first(['at.created_at', 'p.numero', 'at.observacao']);

        return response()->json([
            'atual'      => $lote->id,
            'condominio' => $terreno->condominio,
            'condominio_rotulo' => FracaoIdealDeLote::TIPOS[$terreno->condominio] ?? $terreno->condominio,
            'terreno'    => [
                'id'           => $terreno->id,
                'quadra'       => $terreno->quadra,
                'numero_lote'  => $terreno->numero_lote,
                'inscricao'    => $terreno->inscricaoFormatada(),
                'area_desenho' => $areaDesenho,
                'area_matricula' => FracaoIdealDeLote::areaMatricula($terreno->id),
                'area_comum'   => $somaExclusivas > 0 ? round(max(0, $areaDesenho - $somaExclusivas), 2) : null,
                'soma_fracoes' => round($somaFracoes, 2),
            ],
            'instituido' => $ato ? [
                'em'        => date('d/m/Y', strtotime($ato->created_at)),
                'protocolo' => $ato->numero,
                'direto'    => $ato->numero === null,
            ] : null,
            'unidades'   => $lista,
        ]);
    }
}
