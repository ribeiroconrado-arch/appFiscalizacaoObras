<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O PERÍMETRO URBANO, com vigência — App\Services\PerimetrosUrbanos.
 *
 * Uma linha por versão, e nenhuma é sobrescrita: a lei nova encerra a vigência
 * da anterior (`vigente_ate`) e ela fica no histórico. A pergunta da
 * fiscalização é "o imóvel era urbano NA DATA do auto?", e o auto de 2026 tem
 * de continuar coerente depois que a lei de 2028 mudar a linha.
 *
 *   rascunho   carregado pela tela de Importações, ainda não vale
 *   vigente    o que vale hoje (no máximo um)
 *   anterior   valeu de vigente_desde até vigente_ate
 *
 * Substitui o retângulo PERIMETRO_URBANO do .env (config/gis.php), que fica só
 * como reserva enquanto nenhuma versão foi publicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perimetros_urbanos', function (Blueprint $t) {
            $t->id();
            $t->string('situacao', 10)->default('rascunho')->index();
            $t->string('lei_numero', 40)->nullable();
            $t->date('lei_data')->nullable();
            $t->date('vigente_desde')->nullable();
            $t->date('vigente_ate')->nullable();
            $t->decimal('area_m2', 16, 2);
            $t->string('arquivo_nome', 200);
            $t->string('fonte', 200)->nullable();
            $t->string('camada', 200)->nullable();
            $t->text('observacao')->nullable();
            // A comparação com o vigente do momento (o que entra, o que sai, lotes
            // e bairros afetados). Refeita quando o vigente muda.
            $t->json('comparacao')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('publicado_em')->nullable();
            $t->timestamps();
        });
        // Geometria por SQL: o Blueprint não declara SRID com índice espacial.
        DB::statement('ALTER TABLE perimetros_urbanos ADD geom MULTIPOLYGON NOT NULL SRID 4326 AFTER area_m2');
        DB::statement('ALTER TABLE perimetros_urbanos ADD SPATIAL INDEX perimetros_urbanos_geom (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('perimetros_urbanos');
    }
};
