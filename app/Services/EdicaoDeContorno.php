<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Edição do contorno do bairro à mão (vértices, "encostar no vizinho").
 *
 * Três regras, conferidas a cada passo na tela e de novo ao gravar:
 *
 *   1. o contorno é um polígono válido (sem lados se cruzando);
 *   2. nenhum lote ativo nem quadra do bairro fica fora dele;
 *   3. não invade outro bairro — encostar na divisa pode, sobrepor não.
 *
 * As contas são feitas NO PLANO (SRID 0), como em LoteRepository::
 * conferirContorno: o ST_Intersects geográfico do MySQL 8.0 erra com polígono
 * grande. Uma folga de ~0,3 m (FOLGA_GRAUS) absorve o arredondamento das
 * coordenadas: o lote encostado na borda continua "dentro", e o vértice
 * grudado na divisa do vizinho não conta como invasão.
 */
class EdicaoDeContorno
{
    /** ~0,33 m: abaixo disso é arredondamento, não lote de fora. */
    private const FOLGA_GRAUS = 0.000003;

    /** Sobreposição com o vizinho abaixo disso (m²) é a própria divisa. */
    private const INVASAO_TOLERADA_M2 = 1.0;

    /**
     * @param  array<string,mixed>  $geometry  Polygon ou MultiPolygon (lon/lat)
     * @return array{valido:bool, area_m2:float, lotes_fora:list<array<string,mixed>>, quadras_fora:list<string>, invasoes:list<array<string,mixed>>, pode:bool}
     */
    public function conferir(string $bairro, array $geometry): array
    {
        $gj = json_encode($this->multi($geometry));
        $g = DB::selectOne('SELECT ST_IsValid(g) AS valido, ST_Area(g) AS area,
                                   ST_Latitude(ST_PointN(ST_ExteriorRing(ST_GeometryN(g, 1)), 1)) AS lat
                              FROM (SELECT ST_GeomFromGeoJSON(?, 1, 4326) g) t', [$gj]);
        $valido = (bool) $g->valido;
        $res = ['valido' => $valido, 'area_m2' => (float) $g->area, 'lotes_fora' => [], 'quadras_fora' => [], 'invasoes' => []];
        if (! $valido) {
            $res['pode'] = false;
            return $res;
        }

        $plano = 'ST_SRID(ST_GeomFromGeoJSON(?, 1, 4326), 0)';
        $comFolga = 'ST_Buffer(' . $plano . ', ' . self::FOLGA_GRAUS . ')';

        $res['lotes_fora'] = array_map(fn ($l) => ['id' => (int) $l->id, 'quadra' => $l->quadra, 'lote' => $l->numero_lote],
            DB::select("SELECT id, quadra, numero_lote FROM lotes
                         WHERE situacao = 'ativo' AND bairro = ? AND terreno_id IS NULL
                           AND NOT ST_Within(ST_SRID(geom, 0), {$comFolga})
                         ORDER BY quadra, numero_lote LIMIT 200", [$bairro, $gj]));

        $res['quadras_fora'] = array_map(fn ($q) => (string) $q->numero,
            DB::select("SELECT numero FROM quadras WHERE bairro = ? AND NOT ST_Within(ST_SRID(geom, 0), {$comFolga})
                         ORDER BY numero LIMIT 200", [$bairro, $gj]));

        // Graus² → m², na latitude do bairro.
        $fator = 111320.0 ** 2 * cos(deg2rad((float) $g->lat));
        foreach (DB::select("SELECT nome, ST_Area(i) AS a, ST_AsGeoJSON(ST_SRID(i, 4326), 8) AS gj FROM (
                                SELECT nome, ST_Intersection(ST_SRID(geom, 0), {$plano}) AS i FROM bairros
                                 WHERE nome <> ? AND ST_Intersects(ST_SRID(geom, 0), {$plano})) t", [$gj, $bairro, $gj]) as $v) {
            $m2 = (float) $v->a * $fator;
            if ($m2 > self::INVASAO_TOLERADA_M2) {
                $res['invasoes'][] = ['bairro' => $v->nome, 'area_m2' => round($m2, 1), 'geometry' => json_decode($v->gj)];
            }
        }

        $res['pode'] = ! $res['lotes_fora'] && ! $res['quadras_fora'] && ! $res['invasoes'];

        return $res;
    }

    /**
     * Grava a edição: guarda o contorno de antes e marca o bairro como
     * editado à mão. Recusa (RuntimeException) se alguma regra não fecha.
     *
     * @param  array<string,mixed>  $geometry
     * @return array<string,mixed>  a conferência
     */
    public function gravar(string $bairro, array $geometry, int $usuario): array
    {
        $c = $this->conferir($bairro, $geometry);
        if (! $c['pode']) {
            throw new RuntimeException($this->motivo($c));
        }

        DB::transaction(function () use ($bairro, $geometry, $usuario) {
            $atual = DB::table('bairros')->where('nome', $bairro)->lockForUpdate()
                ->first([DB::raw('ST_AsGeoJSON(geom) AS gj'), 'editado_manual']);
            if (! $atual) {
                throw new RuntimeException('Este bairro ainda não tem contorno: gere-o antes de editar.');
            }
            $this->guardarAnterior($bairro, $atual->gj, (bool) $atual->editado_manual, 'edicao', $usuario);
            DB::update('UPDATE bairros SET geom = ST_GeomFromGeoJSON(?, 1, 4326), editado_manual = 1,
                               editado_em = ?, editado_por = ?, updated_at = ? WHERE nome = ?',
                [json_encode($this->multi($geometry)), now(), $usuario, now(), $bairro]);
        });

        return $c;
    }

    /** Volta o contorno de antes da última edição (ou geração por cima dela). */
    public function desfazer(string $bairro): void
    {
        DB::transaction(function () use ($bairro) {
            $ant = DB::table('bairro_contornos_anteriores')->where('bairro', $bairro)->orderByDesc('id')->lockForUpdate()->first();
            if (! $ant) {
                throw new RuntimeException('Não há edição anterior do contorno deste bairro para desfazer.');
            }
            DB::update('UPDATE bairros SET geom = ST_GeomFromGeoJSON(?, 1, 4326), editado_manual = ?, updated_at = ? WHERE nome = ?',
                [$ant->geojson, $ant->era_editado ? 1 : 0, now(), $bairro]);
            DB::table('bairro_contornos_anteriores')->where('id', $ant->id)->delete();
        });
    }

    /** Antes de GERAR de novo por cima de uma edição: o contorno editado não se perde. */
    public function guardarAntesDeGerar(string $bairro, int $usuario): void
    {
        $atual = DB::table('bairros')->where('nome', $bairro)->first([DB::raw('ST_AsGeoJSON(geom) AS gj'), 'editado_manual']);
        if ($atual) {
            $this->guardarAnterior($bairro, $atual->gj, (bool) $atual->editado_manual, 'geracao', $usuario);
        }
    }

    public function temAnterior(string $bairro): bool
    {
        return DB::table('bairro_contornos_anteriores')->where('bairro', $bairro)->exists();
    }

    /** A recusa em uma frase, com o que segura a gravação. */
    public function motivo(array $c): string
    {
        if (! $c['valido']) {
            return 'O contorno tem lados se cruzando. Desfaça o último passo ou ajuste o vértice.';
        }
        $partes = [];
        if ($n = count($c['lotes_fora'])) $partes[] = "{$n} lote(s) ficariam fora do contorno";
        if ($n = count($c['quadras_fora'])) $partes[] = "{$n} quadra(s) ficariam fora";
        foreach ($c['invasoes'] as $i) $partes[] = sprintf('invade %s em %s m²', $i['bairro'], number_format($i['area_m2'], 1, ',', '.'));

        return 'Não dá para gravar: ' . implode('; ', $partes) . '.';
    }

    private function guardarAnterior(string $bairro, string $gj, bool $eraEditado, string $motivo, int $usuario): void
    {
        DB::table('bairro_contornos_anteriores')->insert([
            'bairro' => $bairro, 'geojson' => $gj, 'era_editado' => $eraEditado,
            'motivo' => $motivo, 'user_id' => $usuario, 'created_at' => now(),
        ]);
    }

    /** O banco guarda MULTIPOLYGON: o Polygon da tela vira um de um só. */
    private function multi(array $g): array
    {
        return ($g['type'] ?? null) === 'Polygon' ? ['type' => 'MultiPolygon', 'coordinates' => [$g['coordinates']]] : $g;
    }
}
