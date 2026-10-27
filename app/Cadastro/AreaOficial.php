<?php

namespace App\Cadastro;

use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A ÁREA DO TERRENO QUE VALE: a do cadastro oficial, quando o lote do mapa tem
 * um imóvel correspondente lá; a do desenho, quando não tem.
 *
 * Regra da fiscalização (11/10/2026): existe o imóvel do MAPA e o imóvel do
 * CADASTRO. Havendo referência de um para o outro, a área do terreno segue
 * sempre o cadastro oficial — na ficha, no balão, na consulta e na peça. A
 * área do desenho continua guardada (`lotes.area_gis_m2`) e serve à geometria:
 * desmembrar, unificar, somar partes. A diferença entre as duas não aparece
 * como dois números pela tela; aparece num lugar só, a conferência com o
 * cadastro (ConferenciaComCadastro), como divergência a corrigir.
 *
 * A correspondência é a mesma da aba BCI (CadastroCarregado::linhasDoLote):
 * código do bairro + quadra + lote, sem os zeros à esquerda.
 *
 * As áreas de um bairro são lidas de uma vez e guardadas por alguns minutos:
 * o mapa pede milhares de lotes por vez, e uma consulta por lote o derrubaria.
 */
class AreaOficial
{
    /** @var array<string, array<string,float>> código do bairro => ["quadra|lote" => área] */
    private array $porBairro = [];

    public function __construct(private BairrosDoDesenho $bairros = new BairrosDoDesenho()) {}

    /**
     * A área do cadastro para o lote, ou null se o lote não tem imóvel
     * correspondente (ou o cadastro não informa a área dele).
     *
     * @param object $lote qualquer coisa com `bairro`, `quadra` e `numero_lote`
     */
    public function doCadastro(object $lote): ?float
    {
        if (($lote->quadra ?? null) === null || ($lote->numero_lote ?? null) === null) {
            return null;
        }
        $codigo = $this->bairros->codigos()[BairrosDoDesenho::chave($lote->bairro ?? null)] ?? null;
        if (! $codigo) {
            return null;
        }
        $codigo = ltrim((string) $codigo, '0');
        $this->porBairro[$codigo] ??= $this->areasDoBairro($codigo);

        // Primeiro pela quadra como ela entra na inscrição (82A → 821, ver
        // InscricaoImobiliaria::numeroDaQuadra); depois pela grafia do desenho.
        $areas = $this->porBairro[$codigo];

        return $areas[self::chave(InscricaoImobiliaria::numeroDaQuadra($lote->quadra) ?: $lote->quadra, $lote->numero_lote)]
            ?? $areas[self::chave($lote->quadra, $lote->numero_lote)]
            ?? null;
    }

    /** A área que VALE para o lote: a do cadastro, ou a do desenho na falta dela. */
    public function doLote(object $lote): ?float
    {
        return $this->doCadastro($lote) ?? (isset($lote->area_gis_m2) ? (float) $lote->area_gis_m2 : null);
    }

    /** @return array<string,float> "quadra|lote" => área do terreno */
    private function areasDoBairro(string $codigo): array
    {
        return Cache::remember('cadastro:areas:' . $codigo, now()->addMinutes(5), function () use ($codigo) {
            $areas = [];
            $linhas = DB::table('cadastro_externo_imoveis')
                ->whereRaw("TRIM(LEADING '0' FROM codigo_bairro) = ?", [$codigo])
                ->whereNotNull('area_terreno_m2')
                // Imóvel que saiu da última carga não é referência: os presentes primeiro.
                ->orderByRaw('ausente_desde_carga_id IS NOT NULL')
                ->orderBy('inscricao')
                ->get(['inscricao', 'quadra', 'lote', 'area_terreno_m2']);
            foreach ($linhas as $l) {
                // Várias linhas do mesmo lote são as construções dele, e repetem
                // o mesmo terreno: vale a primeira.
                $areas[self::chave($l->quadra, $l->lote)] ??= (float) $l->area_terreno_m2;
                // E pela quadra da INSCRIÇÃO: o cadastro grava "801" na coluna
                // quadra do lote que o desenho chama de "80A".
                if ($p = InscricaoImobiliaria::partes($l->inscricao)) {
                    $areas[self::chave($p['quadra'], $l->lote)] ??= (float) $l->area_terreno_m2;
                }
            }

            return $areas;
        });
    }

    private static function chave(mixed $quadra, mixed $lote): string
    {
        return (ltrim((string) $quadra, '0') ?: '0') . '|' . (ltrim((string) $lote, '0') ?: '0');
    }
}
