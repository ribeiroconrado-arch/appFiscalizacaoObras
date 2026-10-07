<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quais artigos sustentam EMBARGO.
 *
 * Nem todo artigo embarga. Na LC 001/2023 (Código de Obras de Primavera do
 * Leste) o embargo está em três dispositivos, e só neles:
 *
 *   Art. 22, §4º   obra em desacordo com o projeto ou o alinhamento → IMEDIATO
 *   Art. 22, §5º   alvará e projeto não apresentados → após 5 dias
 *   Art. 32, §2º   sem novo responsável técnico → após 5 dias, "embargo e/ou multa"
 *
 * Daí os três campos:
 *
 *   embargo             nao | cabe | exclusivo
 *                       `cabe` serve também à notificação e ao auto de infração;
 *                       `exclusivo` só aparece nas peças de embargo.
 *   embargo_modo        imediato | apos_prazo — é o que decide entre lavrar
 *                       direto o Auto de Embargo ou dar antes a Notificação de
 *                       Embargo, com prazo.
 *   embargo_prazo_dias  o prazo do `apos_prazo`.
 *
 * Artigo existente fica `nao`: nada muda até alguém configurar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artigos', function (Blueprint $t) {
            $t->enum('embargo', ['nao', 'cabe', 'exclusivo'])->default('nao')->after('multa_max_upf');
            $t->enum('embargo_modo', ['imediato', 'apos_prazo'])->nullable()->after('embargo');
            $t->unsignedSmallInteger('embargo_prazo_dias')->nullable()->after('embargo_modo');
        });
    }

    public function down(): void
    {
        Schema::table('artigos', function (Blueprint $t) {
            $t->dropColumn(['embargo', 'embargo_modo', 'embargo_prazo_dias']);
        });
    }
};
