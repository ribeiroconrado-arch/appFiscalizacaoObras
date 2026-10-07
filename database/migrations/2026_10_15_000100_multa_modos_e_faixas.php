<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * As formas de calcular a multa de um artigo.
 *
 * Antes havia três: valor fixo, UPF por m² construído e UPF por m² de terreno.
 * A base misturava DUAS perguntas numa coluna só — como calcula e sobre qual
 * área —, e por isso não cabia nela nem a multa por faixa de metragem, nem o
 * artigo que usa a área da obra quando há obra e a do terreno quando não há
 * (escavação), nem a única multa que está na letra da LC 001/2023: de 1 a 10
 * vezes o valor do alvará (art. 120, parágrafo único; 3 vezes no art. 12, §4º).
 *
 * Agora são dois eixos:
 *
 *   base_multa   sem_multa | fixa | por_m2 | faixas | multiplo_alvara
 *   multa_area   construida | terreno | construida_ou_terreno
 *                (só para por_m2 e faixas)
 *
 * E os campos de cada forma:
 *
 *   multa_faixas      [{ate_m2: 60, upf: 50}, …, {ate_m2: null, upf: 600}]
 *                     valor FECHADO por faixa; "até 60" inclui 60; a última é
 *                     sempre aberta.
 *   multa_mult_min    multiplicador do alvará: iguais = fixo (3×); diferentes
 *   multa_mult_max    = o fiscal informa dentro do intervalo (1 a 10×).
 *
 * Na peça: o valor do alvará (documentos.alvara_valor, em reais), e por artigo
 * o multiplicador usado, qual área valeu e a MEMÓRIA do cálculo, congelada —
 * é ela que sai impressa.
 */
return new class extends Migration
{
    private const NOVOS = "'sem_multa','fixa','por_m2','faixas','multiplo_alvara'";
    private const ANTIGOS = "'fixa','area_construida','area_terreno','sem_multa'";

    public function up(): void
    {
        Schema::table('artigos', function (Blueprint $t) {
            $t->enum('multa_area', ['construida', 'terreno', 'construida_ou_terreno'])->nullable()->after('base_multa');
            $t->json('multa_faixas')->nullable()->after('multa_max_upf');
            $t->decimal('multa_mult_min', 6, 2)->nullable()->after('multa_faixas');
            $t->decimal('multa_mult_max', 6, 2)->nullable()->after('multa_mult_min');
        });
        Schema::table('documento_artigos', function (Blueprint $t) {
            $t->enum('multa_area', ['construida', 'terreno', 'construida_ou_terreno'])->nullable()->after('base_multa');
            // Qual área valeu de fato (no "obra, senão terreno" é o que decide).
            $t->enum('area_usada', ['construida', 'terreno'])->nullable()->after('area_m2');
            $t->json('multa_faixas')->nullable()->after('area_usada');
            $t->decimal('multiplicador', 6, 2)->nullable()->after('multa_faixas');
            $t->decimal('valor_reais', 14, 2)->nullable()->after('valor_upf');
            $t->string('memoria', 500)->nullable()->after('valor_reais');
        });
        Schema::table('documentos', function (Blueprint $t) {
            $t->decimal('alvara_valor', 14, 2)->nullable()->after('area_construida_m2');
        });

        // A base antiga vira modo + área. O ENUM aceita os dois conjuntos só
        // durante a troca.
        foreach (['artigos' => 'fixa', 'documento_artigos' => 'fixa'] as $tabela => $padrao) {
            DB::statement("ALTER TABLE {$tabela} MODIFY base_multa ENUM(" . self::ANTIGOS . ",'por_m2','faixas','multiplo_alvara') NOT NULL DEFAULT '{$padrao}'");
            DB::table($tabela)->where('base_multa', 'area_construida')->update(['base_multa' => 'por_m2', 'multa_area' => 'construida']);
            DB::table($tabela)->where('base_multa', 'area_terreno')->update(['base_multa' => 'por_m2', 'multa_area' => 'terreno']);
            DB::statement("ALTER TABLE {$tabela} MODIFY base_multa ENUM(" . self::NOVOS . ") NOT NULL DEFAULT '{$padrao}'");
        }
        DB::table('documento_artigos')->where('base_multa', 'por_m2')->whereNotNull('area_m2')
            ->update(['area_usada' => DB::raw('multa_area')]);
    }

    public function down(): void
    {
        foreach (['artigos', 'documento_artigos'] as $tabela) {
            DB::statement("ALTER TABLE {$tabela} MODIFY base_multa ENUM(" . self::NOVOS . ",'area_construida','area_terreno') NOT NULL DEFAULT 'fixa'");
            DB::table($tabela)->where('base_multa', 'por_m2')->where('multa_area', 'terreno')->update(['base_multa' => 'area_terreno']);
            DB::table($tabela)->where('base_multa', 'por_m2')->update(['base_multa' => 'area_construida']);
            // O que não existia antes fica sem multa.
            DB::table($tabela)->whereIn('base_multa', ['faixas', 'multiplo_alvara'])->update(['base_multa' => 'sem_multa']);
            DB::statement("ALTER TABLE {$tabela} MODIFY base_multa ENUM(" . self::ANTIGOS . ") NOT NULL DEFAULT 'fixa'");
        }

        Schema::table('documentos', fn (Blueprint $t) => $t->dropColumn('alvara_valor'));
        Schema::table('documento_artigos', fn (Blueprint $t) => $t->dropColumn(
            ['multa_area', 'area_usada', 'multa_faixas', 'multiplicador', 'valor_reais', 'memoria']));
        Schema::table('artigos', fn (Blueprint $t) => $t->dropColumn(
            ['multa_area', 'multa_faixas', 'multa_mult_min', 'multa_mult_max']));
    }
};
