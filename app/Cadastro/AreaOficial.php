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
 * SEMPRE PELA INSCRIÇÃO (17/11/2026). Por quadra e número, o desmembrado "9B"
 * não achava o lote 9 · 001 do cadastro e o balão mostrava "A. BCI —".
 *
 * As áreas de um bairro são lidas de uma vez e guardadas por alguns minutos:
 * o mapa pede milhares de lotes por vez, e uma consulta por lote o derrubaria.
 */
class AreaOficial
{
    /** @var array<string, array<string,float>> código do bairro => [inscrição (15 dígitos) => área] */
    private array $porBairro = [];

    public function __construct(private BairrosDoDesenho $bairros = new BairrosDoDesenho()) {}

    /**
     * A área do cadastro para o lote, ou null se o lote não tem imóvel
     * correspondente (ou o cadastro não informa a área dele).
     *
     * @param object $lote qualquer coisa com o que BairrosDoDesenho::inscricaoDe lê
     *                     (`bairro`, `quadra`, `numero_lote`, `desmembramento`,
     *                     `inscricao_imobiliaria`, `inscricao_montada`)
     */
    public function doCadastro(object $lote): ?float
    {
        $insc = InscricaoImobiliaria::normalizar($this->bairros->inscricaoDe($lote));
        if ($insc === null) {
            return null;
        }
        // O código do bairro é o da própria inscrição (dígitos 3 a 5).
        $codigo = ltrim(substr($insc, 2, 3), '0') ?: '0';
        $this->porBairro[$codigo] ??= $this->areasDoBairro($codigo);

        return $this->porBairro[$codigo][$insc] ?? null;
    }

    /** A área que VALE para o lote: a do cadastro, ou a do desenho na falta dela. */
    public function doLote(object $lote): ?float
    {
        return $this->doCadastro($lote) ?? (isset($lote->area_gis_m2) ? (float) $lote->area_gis_m2 : null);
    }

    /** @return array<string,float> inscrição (15 dígitos) => área do terreno */
    private function areasDoBairro(string $codigo): array
    {
        return Cache::remember('cadastro:areas-por-inscricao:' . $codigo, now()->addMinutes(5), function () use ($codigo) {
            $areas = [];
            $linhas = DB::table('cadastro_externo_imoveis')
                ->whereRaw("TRIM(LEADING '0' FROM codigo_bairro) = ?", [$codigo])
                ->whereNotNull('area_terreno_m2')
                ->get(['inscricao', 'area_terreno_m2']);
            foreach ($linhas as $l) {
                if ($insc = InscricaoImobiliaria::normalizar($l->inscricao)) {
                    $areas[$insc] = (float) $l->area_terreno_m2;
                }
            }

            return $areas;
        });
    }
}
