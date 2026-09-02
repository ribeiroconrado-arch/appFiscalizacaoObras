<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AS MEDIDAS DA MATRÍCULA, AO LADO DAS MEDIDAS DO DESENHO.
 *
 * Até aqui o lote guardava uma única área — `area_gis_m2`, medida pelo MySQL
 * sobre o polígono. É ela que serve de base para a multa por m², e continua
 * sendo: não é substituída por nada do que entra aqui.
 *
 * ── Por que duas áreas, e não uma corrigindo a outra ──
 *
 * São coisas de naturezas diferentes:
 *
 *   area_matricula_m2   o que o registro de imóveis diz — FATO JURÍDICO
 *   area_gis_m2         o que o desenho mede — AFERIÇÃO
 *
 * Elas divergem, e a divergência é informação: um lote cuja matrícula diz 300
 * e cujo desenho mede 340 pode ter avanço sobre a via, erro de conversão do
 * DWG, ou desmembramento não averbado. Guardar só uma seria escolher em
 * silêncio qual das duas some — e a que some é sempre a que faria falta.
 *
 * Por isso as colunas são NULAS: lote convertido do DWG não tem medida de
 * matrícula nenhuma, e preencher com a medida do desenho faria as duas
 * baterem sempre, transformando a conferência em teatro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            // Perímetro como a matrícula o descreve. 10,2 comporta 99.999.999,99
            // metros — folga absurda para um lado de lote, mas é o formato já
            // usado em `cadastro_externo_imoveis` para as mesmas medidas, e
            // divergir de formato entre as duas tabelas só criaria conversão.
            $t->decimal('frente_m', 10, 2)->nullable()->after('area_gis_m2');
            $t->decimal('fundos_m', 10, 2)->nullable()->after('frente_m');
            $t->decimal('lado_direito_m', 10, 2)->nullable()->after('fundos_m');
            $t->decimal('lado_esquerdo_m', 10, 2)->nullable()->after('lado_direito_m');
            $t->decimal('area_matricula_m2', 12, 2)->nullable()->after('lado_esquerdo_m');
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->dropColumn([
                'frente_m', 'fundos_m', 'lado_direito_m',
                'lado_esquerdo_m', 'area_matricula_m2',
            ]);
        });
    }
};
