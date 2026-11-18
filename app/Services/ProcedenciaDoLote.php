<?php

namespace App\Services;

use App\Models\Lote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De quais lotes um lote veio (origens) e para quais foi (destinos), com o
 * ato que os ligou — a procedência que a ficha mostra na aba Dados.
 *
 * Sai da BuscaController::sucessao para que a ficha do mapa (aba Dados, via
 * /api/lotes/{lote}/historico) e a busca leiam o mesmo dado. O ato é devolvido
 * pelo id, para a tela de identidades abri-lo.
 */
final class ProcedenciaDoLote
{
    /**
     * @param  callable(?string):string  $bairro  como o nome do bairro aparece (ex.: BuscaController::bairroOficial)
     * @return array{origens: list<array<string,mixed>>, destinos: list<array<string,mixed>>}
     */
    public static function de(Lote $lote, callable $bairro): array
    {
        // Base ainda sem a migração da sucessão: devolve vazio em vez de estourar.
        if (! Schema::hasTable('lote_ato_lotes')) {
            return ['origens' => [], 'destinos' => []];
        }

        $lado = fn (string $euSou, string $procuro) => DB::table('lote_ato_lotes as eu')
            ->join('lote_ato_lotes as outro', 'outro.ato_id', '=', 'eu.ato_id')
            ->join('lote_atos as ato', 'ato.id', '=', 'eu.ato_id')
            ->join('lotes as l', 'l.id', '=', 'outro.lote_id')
            ->where('eu.lote_id', $lote->id)->where('eu.papel', $euSou)
            ->where('outro.papel', $procuro)
            ->orderBy('l.numero_lote')
            ->get([
                'l.id', 'l.bairro', 'l.quadra', 'l.numero_lote', 'l.situacao',
                'ato.id as ato_id', 'ato.tipo', 'ato.protocolo_id', 'ato.created_at',
                'outro.area_m2',
            ])
            ->map(fn ($r) => [
                'id'        => $r->id,
                'rotulo'    => 'Quadra ' . ($r->quadra ?? '—') . ' · Lote ' . ($r->numero_lote ?? '—'),
                'bairro'    => $bairro($r->bairro),
                'situacao'  => $r->situacao,
                'tipo'      => $r->tipo,
                'ato'       => $r->ato_id,
                'protocolo' => $r->protocolo_id,
                'area'      => $r->area_m2,
                'data'      => $r->created_at ? date('d/m/Y', strtotime($r->created_at)) : null,
            ])->all();

        return [
            'origens'  => $lado('posterior', 'anterior'),
            'destinos' => $lado('anterior', 'posterior'),
        ];
    }
}
