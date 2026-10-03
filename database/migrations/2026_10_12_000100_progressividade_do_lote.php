<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progressividade do imóvel — lançada pela fiscalização, na aba BCI da ficha.
 *
 * Mora no LOTE, e não no cadastro municipal: a planilha da prefeitura não traz
 * esse dado, e a carga mensal regrava o cadastro inteiro. Três estados, de
 * propósito: `null` é "ninguém informou", e é diferente de "não tem".
 *
 * Quem lançou e quando ficam na trilha de auditoria do Lote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->boolean('tem_progressividade')->nullable()->after('area_matricula_m2');
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->dropColumn('tem_progressividade');
        });
    }
};
