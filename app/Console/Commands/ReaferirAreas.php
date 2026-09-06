<?php

namespace App\Console\Commands;

use App\Support\GeometriaPlana;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Põe na régua da GRADE o `area_gis_m2` dos lotes que nasceram na régua do
 * TERRENO.
 *
 * ── O que aconteceu ──
 *
 * Os lotes vindos da importação sempre tiveram área de GRADE: o pipeline de
 * extração os mediu em UTM (EPSG:31981) antes de reprojetar para 4326. Já os
 * lotes criados DENTRO do sistema — desenho, desmembramento, unificação —
 * recebiam a área do `ST_Area` do MySQL, que é geodésica, isto é, de terreno.
 *
 * As duas diferem por 0,126% aqui, de forma sistemática. O lote novo nascia
 * mais leve que o vizinho importado, e a conferência de desmembramento (soma
 * das partes contra o pai) comparava as duas réguas — gastando quase toda a
 * tolerância de sobreposição antes de medir qualquer coisa de verdade.
 *
 * Hoje `area_gis_m2` sai da GeometriaPlana em todo lote novo (ver
 * LoteRepository::areaDoGeoJson). Este comando acerta os que já estavam
 * gravados quando a régua mudou.
 *
 * ── Por que comando, e não migration ──
 *
 * Porque `area_gis_m2` é a base da multa por metro quadrado. Reescrevê-la em
 * silêncio, no meio de um deploy, é exatamente o tipo de coisa que ninguém
 * consegue explicar depois. Aqui a mudança é pedida, mostrada lote a lote e
 * conferida antes de valer: sem `--aplicar`, o comando só relata.
 *
 * Os lotes de importação NÃO são tocados: a área deles já é de grade, e
 * remedi-los aqui trocaria a medida do DWG por uma medida derivada do
 * polígono reprojetado — que é pior, porque perde a fonte primária.
 */
class ReaferirAreas extends Command
{
    protected $signature = 'gis:reaferir-areas
                            {--aplicar : grava as áreas recalculadas (sem isto, só relata)}';

    protected $description = 'Recalcula area_gis_m2 dos lotes criados no sistema, na régua da grade (UTM)';

    /** Abaixo disto a diferença é ruído de arredondamento, não mudança de régua. */
    private const RUIDO_M2 = 0.005;

    public function handle(): int
    {
        $lotes = DB::table('lotes')
            ->select('id', 'bairro', 'quadra', 'numero_lote', 'origem', 'area_gis_m2')
            ->selectRaw('ST_AsGeoJSON(geom) AS gj')
            ->where('origem', '!=', 'importacao')
            ->whereNotNull('geom')
            ->orderBy('id')
            ->get();

        if ($lotes->isEmpty()) {
            $this->info('Nenhum lote criado no sistema — não há área a reaferir.');

            return self::SUCCESS;
        }

        $linhas = [];
        $mudam = 0;

        foreach ($lotes as $l) {
            $anel = json_decode($l->gj, true)['coordinates'][0] ?? [];
            if (count($anel) < 4) {
                $this->warn("Lote {$l->id} tem anel degenerado — ignorado.");
                continue;
            }

            $nova = round(GeometriaPlana::area(GeometriaPlana::projetar($anel)), 2);
            $velha = (float) $l->area_gis_m2;
            $dif = $nova - $velha;

            if (abs($dif) < self::RUIDO_M2) {
                continue;
            }

            $mudam++;
            $linhas[] = [
                $l->id,
                trim("{$l->bairro} Q{$l->quadra} L{$l->numero_lote}"),
                $l->origem,
                number_format($velha, 2, ',', '.'),
                number_format($nova, 2, ',', '.'),
                sprintf('%+.2f', $dif),
            ];

            if ($this->option('aplicar')) {
                DB::table('lotes')->where('id', $l->id)->update(['area_gis_m2' => $nova]);
            }
        }

        $this->newLine();
        if (! $mudam) {
            $this->info("{$lotes->count()} lote(s) conferido(s): todos já estão na régua da grade.");

            return self::SUCCESS;
        }

        $this->table(['id', 'lote', 'origem', 'área antes', 'área depois', 'dif. m²'], $linhas);

        if ($this->option('aplicar')) {
            $this->info("{$mudam} lote(s) reaferido(s) na régua da grade.");
        } else {
            $this->warn("{$mudam} lote(s) fora da régua. Nada foi gravado — rode com --aplicar para valer.");
        }

        return self::SUCCESS;
    }
}
