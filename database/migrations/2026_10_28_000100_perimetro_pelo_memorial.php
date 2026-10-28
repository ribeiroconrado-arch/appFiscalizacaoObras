<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O perímetro também nasce do MEMORIAL DESCRITIVO da lei (Lei 2.121/2022 e as
 * que vierem): os vértices P-01 … P-n com as coordenadas oficiais, e não um
 * desenho feito a partir deles. Ver PerimetrosUrbanos::lerMemorial.
 *
 *   origem          geojson (arquivo do conversor) ou memorial
 *   vertices        [{p, n, e, azimute, distancia, trecho}] em UTM 21S (31981) —
 *                   é o que a versão seguinte edita, sem redigitar a lei inteira
 *   memorial_texto  o texto colado, como veio
 *   conferencia_memorial  o resultado das conferências (trecho a trecho,
 *                   fechamento, área e perímetro declarados)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perimetros_urbanos', function (Blueprint $t) {
            $t->string('origem', 10)->default('geojson')->after('situacao');
            $t->json('vertices')->nullable()->after('camada');
            $t->longText('memorial_texto')->nullable()->after('vertices');
            $t->json('conferencia_memorial')->nullable()->after('memorial_texto');
        });
    }

    public function down(): void
    {
        Schema::table('perimetros_urbanos', function (Blueprint $t) {
            $t->dropColumn(['origem', 'vertices', 'memorial_texto', 'conferencia_memorial']);
        });
    }
};
