<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa lotes de um GeoJSON (EPSG:4326) gerado pelo pipeline da Etapa 1
 * (`gis/tools/dxf_para_geojson.py`) para a tabela `lotes`.
 *
 * Idempotente por `--substituir`: reimportar um bairro apaga só o que é dele,
 * nunca a tabela inteira — o município será importado bairro a bairro, ao longo
 * de semanas, e um `TRUNCATE` acidental custaria todo o trabalho anterior.
 */
class ImportarLotes extends Command
{
    protected $signature = 'gis:importar-lotes
                            {arquivo : caminho do .geojson (EPSG:4326)}
                            {--substituir : apaga antes os lotes dos bairros presentes no arquivo}';

    protected $description = 'Importa lotes de um GeoJSON para a tabela lotes';

    /** Linhas por INSERT. 200 mantém o pacote longe do max_allowed_packet. */
    private const LOTE_INSERCAO = 200;

    public function handle(): int
    {
        $arquivo = $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");
            return self::FAILURE;
        }

        $gj = json_decode(file_get_contents($arquivo), true);
        if (! is_array($gj) || ($gj['type'] ?? null) !== 'FeatureCollection') {
            $this->error('O arquivo não é uma FeatureCollection GeoJSON.');
            return self::FAILURE;
        }

        $feicoes = $gj['features'] ?? [];
        $this->info(sprintf('Feições no arquivo: %d', count($feicoes)));

        // ── preparo das linhas ──
        $linhas = [];
        $bairros = [];
        $ignoradas = 0;
        $agora = now();

        foreach ($feicoes as $f) {
            $geom = $f['geometry'] ?? null;
            // A tabela declara POLYGON. MultiPolygon entraria como geometria de
            // tipo incompatível e o INSERT falharia no meio da importação —
            // melhor recusar aqui, contando, do que abortar no meio.
            if (! $geom || ($geom['type'] ?? null) !== 'Polygon') {
                $ignoradas++;
                continue;
            }

            $p = $f['properties'] ?? [];
            $bairro = $p['bairro'] ?? null;
            if (! $bairro) { $ignoradas++; continue; }

            $bairros[$bairro] = true;
            $linhas[] = [
                'bairro'      => $bairro,
                'quadra'      => $p['quadra'] ?? null,
                'numero_lote' => $p['numero_lote'] ?? null,
                'chave'       => $p['chave'] ?? ($bairro . '|?|?'),
                'area_gis_m2' => $p['area_gis_m2'] ?? null,
                'fonte'       => $p['fonte'] ?? basename($arquivo),
                'geojson'     => json_encode($geom),
                'ts'          => $agora,
            ];
        }

        if ($ignoradas > 0) {
            $this->warn("Feições ignoradas (sem polígono ou sem bairro): {$ignoradas}");
        }
        if (empty($linhas)) {
            $this->error('Nada a importar.');
            return self::FAILURE;
        }

        // ── substituição por bairro ──
        if ($this->option('substituir')) {
            $nomes = array_keys($bairros);
            $apagados = DB::table('lotes')->whereIn('bairro', $nomes)->delete();
            $this->warn(sprintf('Removidos %d lotes de: %s', $apagados, implode(', ', $nomes)));
        }

        // ── inserção ──
        // ST_GeomFromGeoJSON é o caminho seguro para a ordem dos eixos: o MySQL
        // interpreta o documento conforme a RFC 7946 (longitude, latitude) e
        // faz a conversão para a ordem interna sozinho. Montar WKT à mão aqui
        // exigiria o 'axis-order=long-lat' e abriria espaço para erro silencioso.
        $barra = $this->output->createProgressBar(count($linhas));
        $barra->start();
        $inseridos = 0;

        DB::transaction(function () use ($linhas, $barra, &$inseridos) {
            foreach (array_chunk($linhas, self::LOTE_INSERCAO) as $bloco) {
                $valores = [];
                $params  = [];
                foreach ($bloco as $l) {
                    $valores[] = '(?, ?, ?, ?, ?, ?, ST_GeomFromGeoJSON(?, 1, 4326), ?, ?)';
                    array_push($params,
                        $l['bairro'], $l['quadra'], $l['numero_lote'], $l['chave'],
                        $l['area_gis_m2'], $l['fonte'], $l['geojson'], $l['ts'], $l['ts']);
                }
                DB::insert(
                    'INSERT INTO lotes (bairro, quadra, numero_lote, chave, area_gis_m2,
                                        fonte, geom, created_at, updated_at) VALUES '
                    . implode(',', $valores),
                    $params
                );
                $inseridos += count($bloco);
                $barra->advance(count($bloco));
            }
        });

        $barra->finish();
        $this->newLine(2);
        $this->info("Inseridos: {$inseridos}");

        // ── conferência ──
        // SRID errado não lança erro: dá mapa vazio. Conferir aqui é mais barato
        // do que descobrir depois, olhando uma tela em branco no celular.
        $d = app(\App\Repositories\LoteRepository::class)->diagnostico();
        $this->newLine();
        $this->line('Conferência da tabela:');
        $this->table(
            ['total', 'srid≠4326', 'geom inválida', 'chaves distintas', 'bairros'],
            [[$d->total, $d->srid_errado, $d->geometria_invalida, $d->chaves_distintas, $d->bairros]]
        );

        if ((int) $d->srid_errado > 0 || (int) $d->geometria_invalida > 0) {
            $this->error('Há geometria com SRID errado ou inválida — investigar antes de usar.');
            return self::FAILURE;
        }
        if ((int) $d->chaves_distintas < (int) $d->total) {
            $this->warn(sprintf(
                'Chaves repetidas: %d lotes para %d chaves. Esperado enquanto a quadra vier '
                . 'do rótulo mais próximo — ver docs/etapa1-base-piloto.md.',
                $d->total, $d->chaves_distintas
            ));
        }

        return self::SUCCESS;
    }
}
