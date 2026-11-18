<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Services\TrocaDeIdentidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Troca de identidade entre as partes de um desmembramento (TrocaDeIdentidades).
 *
 * Só a curadoria do cadastro: é ela que sabe qual inscrição é de qual lado.
 * O ato é resolvido a partir de UM dos lotes — a tela não manda o id do ato,
 * para que ninguém troque as partes de um ato que não é o do lote aberto.
 */
class IdentidadesController extends Controller
{
    public function __construct(private TrocaDeIdentidades $servico) {}

    /** O ato do lote: as partes na ordem atual e se dá para trocar. */
    public function mostrar(Request $request, Lote $lote): JsonResponse
    {
        if ($erro = $this->recusar($request)) {
            return $erro;
        }

        $ato = $this->servico->consultar($lote);
        if ($ato === null) {
            return response()->json(['message' => 'Este lote não é parte de um desmembramento.'], 404);
        }

        return response()->json($ato);
    }

    /** A prévia: o que cada parte passa a ser. Não grava. */
    public function previa(Request $request, Lote $lote): JsonResponse
    {
        if ($erro = $this->recusar($request)) {
            return $erro;
        }
        $d = $this->validar($request);

        return response()->json($this->servico->previa($lote, $d['ordem'], $d['motivo']));
    }

    /** Grava a troca, tudo ou nada, com trilha por lote. */
    public function aplicar(Request $request, Lote $lote): JsonResponse
    {
        if ($erro = $this->recusar($request)) {
            return $erro;
        }
        $d = $this->validar($request, exigeMotivo: true);

        $ids = $this->servico->aplicar($lote, $d['ordem'], $d['motivo']);

        return response()->json([
            'message' => 'Identidades trocadas: ' . count($ids) . ' partes.',
            'ids'     => $ids,
        ]);
    }

    /** @return array{ordem: list<int>, motivo: string} */
    private function validar(Request $request, bool $exigeMotivo = false): array
    {
        $d = $request->validate([
            'ordem'  => ['required', 'array', 'min:2', 'max:999'],
            'ordem.*' => ['required', 'integer', 'min:1'],
            'motivo' => [$exigeMotivo ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'ordem.required' => 'Informe a ordem das partes.',
            'motivo.required' => 'Descreva o motivo da troca.',
        ]);

        return [
            'ordem'  => array_map('intval', $d['ordem']),
            'motivo' => (string) ($d['motivo'] ?? ''),
        ];
    }

    private function recusar(Request $request): ?JsonResponse
    {
        if ($request->user()?->podeCurarCadastro()) {
            return null;
        }

        return response()->json([
            'message' => 'Só o curador do cadastro pode trocar as identidades das partes.',
        ], 403);
    }
}
