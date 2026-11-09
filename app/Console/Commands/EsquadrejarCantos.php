<?php

namespace App\Console\Commands;

use App\Cadastro\CantosChanfrados;
use App\Repositories\LoteRepository;
use App\Support\GeometriaPlana;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz os MICROCHANFROS que o conversor antigo deixou nos cantos dos lotes
 * importados (App\Cadastro\CantosChanfrados) — uso único, decisão de 09/11/2026.
 *
 * É CORREÇÃO DA CARGA INICIAL, como o levantamento de 2022: entra sem trilha
 * (nada no Histórico), porque o chanfro é defeito da conversão e não ato de
 * ninguém. Lote que alguém editou depois (há linha "editou" na auditoria)
 * fica como está: o desenho dele já é decisão de uma pessoa.
 *
 *   php artisan gis:esquadrejar-cantos              simula: conta por bairro, não grava
 *   php artisan gis:esquadrejar-cantos --aplicar    grava
 *   --bairro="JARDIM MARINGA"                       só um bairro
 */
class EsquadrejarCantos extends Command
{
    protected $signature = 'gis:esquadrejar-cantos
                            {--aplicar : grava (sem isto, só simula)}
                            {--bairro= : só este bairro}';

    protected $description = 'Desfaz os microchanfros dos cantos dos lotes importados (simula ou aplica, sem histórico)';

    /** Mais que isto de área a mais num lote não é chanfro: fica de fora. */
    private const GANHO_MAX_M2 = 0.5;

    public function handle(LoteRepository $repo): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $editados = DB::table('auditoria')->whereIn('tabela', ['lotes', 'lotes_em_revisao'])
            ->whereIn('acao', ['editou', 'alterou'])->distinct()->pluck('registro_id')->flip();

        $porBairro = [];
        $exemplos = [];
        $q = DB::table('lotes')->where('situacao', 'ativo')
            ->when($this->option('bairro'), fn ($w, $b) => $w->where('bairro', $b))
            ->selectRaw('id, bairro, quadra, numero_lote, ST_AsGeoJSON(geom) AS gj');

        $q->orderBy('id')->chunk(1000, function ($lotes) use ($repo, $aplicar, $editados, &$porBairro, &$exemplos) {
            foreach ($lotes as $l) {
                $g = json_decode($l->gj, true);
                if (($g['type'] ?? '') !== 'Polygon' || count($g['coordinates']) !== 1) continue;
                $r = CantosChanfrados::esquadrejar($g['coordinates'][0]);
                if (! $r) continue;

                $b = &$porBairro[$l->bairro];
                $b ??= ['lotes' => 0, 'cantos' => 0, 'editados' => 0, 'recusados' => 0];
                if (isset($editados[$l->id])) { $b['editados']++; continue; }

                $antes = GeometriaPlana::area(GeometriaPlana::projetar($g['coordinates'][0]));
                $depois = GeometriaPlana::area(GeometriaPlana::projetar($r['anel']));
                $novo = json_encode(['type' => 'Polygon', 'coordinates' => [$r['anel']]]);
                if ($depois - $antes > self::GANHO_MAX_M2 || $depois < $antes - 0.01 || ! $repo->ehValido($novo)) {
                    $b['recusados']++;
                    continue;
                }
                $b['lotes']++;
                $b['cantos'] += $r['cantos'];
                if (count($exemplos) < 12) {
                    $exemplos[] = [$l->bairro, $l->quadra, $l->numero_lote, $r['cantos'], '+' . number_format($depois - $antes, 2, ',', '.') . ' m²'];
                }
                if ($aplicar) {
                    $repo->trocarGeometriaSemTrilha((int) $l->id, $novo);
                }
            }
        });

        if (! $porBairro) {
            $this->info('Nenhum canto chanfrado encontrado.');
            return self::SUCCESS;
        }
        ksort($porBairro);
        $this->table(['Bairro', 'Lotes', 'Cantos', 'Editados depois (fora)', 'Recusados'],
            array_map(fn ($b, $x) => [$b, $x['lotes'], $x['cantos'], $x['editados'], $x['recusados']], array_keys($porBairro), $porBairro));
        $this->info(sprintf('Total: %d lotes, %d cantos.', array_sum(array_column($porBairro, 'lotes')), array_sum(array_column($porBairro, 'cantos'))));
        if ($exemplos) {
            $this->table(['Bairro', 'Quadra', 'Lote', 'Cantos', 'Área'], $exemplos);
        }
        $this->line($aplicar ? 'Gravado, sem histórico.' : 'Nada foi gravado. Para gravar: --aplicar');

        return self::SUCCESS;
    }
}
