<?php

namespace App\Services;

use App\Models\Protocolo;
use App\Models\Sinalizacao;
use App\Models\User;
use App\Models\Vg\VgVistoria;

/**
 * O QUE A CONCLUSÃO DE UMA VISTORIA GUIADA FAZ NO RESTO DO SISTEMA.
 *
 * É o que a vistoria de obra fazia ao ser gravada (VistoriaController::store),
 * agora a partir dos VÍNCULOS da guiada:
 *
 *   sinalizações  as pendentes do imóvel ligado — e a de que a vistoria
 *                 nasceu, que pode ser de rua, sem lote — ficam resolvidas
 *                 por ela;
 *   protocolo     o de ato cadastral (desmembramento, unificação, fração
 *                 ideal) ligado e ainda sem vistoria passa a ser atendido
 *                 por ela. Protocolo já atendido não é sobrescrito: apagaria
 *                 em silêncio a vistoria que fundamentou um ato.
 *
 * Chamado dentro da transação da conclusão: vistoria concluída com a
 * sinalização ainda aberta seria a bandeira que não sai do mapa.
 */
class VistoriaGuiadaConcluida
{
    public const TIPOS_CADASTRAIS = ['desmembramento', 'unificacao', 'fracao_ideal'];

    public function aoConcluir(VgVistoria $v, User $u): array
    {
        $v->loadMissing('vinculos');
        $lotes = $v->idsLigados('imovel');

        $sin = Sinalizacao::query()->pendentes()
            ->where(fn ($q) => $q->when($lotes, fn ($w) => $w->whereIn('lote_id', $lotes))
                ->when($v->sinalizacao_id, fn ($w) => $w->orWhere('id', $v->sinalizacao_id))
                ->when(! $lotes && ! $v->sinalizacao_id, fn ($w) => $w->whereRaw('1 = 0')))
            ->update([
                'status' => 'resolvida', 'resolvida_em' => now(), 'resolvida_por' => $u->id,
                'vg_vistoria_id' => $v->id, 'resolucao' => 'Atendida pela vistoria ' . $v->numeroFormatado(),
                'updated_at' => now(),
            ]);

        $prot = ($ids = $v->idsLigados('protocolo'))
            ? Protocolo::whereIn('id', $ids)->whereIn('tipo', self::TIPOS_CADASTRAIS)
                ->whereNull('vistoria_id')->whereNull('vg_vistoria_id')
                ->update(['vg_vistoria_id' => $v->id])
            : 0;

        return ['sinalizacoes' => $sin, 'protocolos' => $prot];
    }
}
