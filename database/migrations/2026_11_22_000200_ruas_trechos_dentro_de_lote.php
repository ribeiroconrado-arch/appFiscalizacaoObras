<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LIMPEZA ÚNICA (22/11/2026): apaga os trechos de rua GERADOS que ficam
 * dentro de lote — dois loteamentos encostados pelos fundos ganhavam uma
 * "rua" atravessando os lotes do vizinho. Mesma regra de
 * LoteRepository::trechoDentroDeLote (pontos a 20, 50 e 80 % dentro de lote
 * ativo), que daqui em diante o "Gerar" aplica ao gravar.
 *
 * Só `ruas_trechos`: é dado calculado, que o "Gerar" refaz. Os manuais (o que
 * o curador informou) não são tocados.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sub = "EXISTS (SELECT 1 FROM lotes WHERE situacao = 'ativo'
                    AND ST_Contains(geom, ST_GeomFromText(?, 4326, 'axis-order=long-lat')))";
        $apagar = [];
        foreach (DB::table('ruas_trechos')->select('id', 'de_lat', 'de_lon', 'ate_lat', 'ate_lon')->cursor() as $t) {
            $pontos = array_map(fn ($f) => sprintf('POINT(%.7F %.7F)',
                $t->de_lon + ($t->ate_lon - $t->de_lon) * $f, $t->de_lat + ($t->ate_lat - $t->de_lat) * $f), [0.2, 0.5, 0.8]);
            if (DB::selectOne("SELECT ($sub AND $sub AND $sub) AS dentro", $pontos)->dentro) {
                $apagar[] = $t->id;
            }
        }
        foreach (array_chunk($apagar, 500) as $ids) {
            DB::table('ruas_trechos')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // Sem volta: o "Gerar" do bairro recalcula os trechos.
    }
};
