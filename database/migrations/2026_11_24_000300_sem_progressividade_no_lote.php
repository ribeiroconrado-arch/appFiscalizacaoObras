<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A progressividade deixa de morar no lote (24/11/2026).
 *
 * Ela vem do SISTEMA TRIBUTÁRIO e só é alterada lá: o sistema apenas lê, da
 * coluna PROGRESSIVIDADE da planilha do cadastro (ColunasDaExportacao). O
 * campo que a fiscalização preenchia na ficha (2026_10_12_000100) sai, com o
 * que houver nele — decisão dele.
 *
 * O down() devolve a coluna vazia: o que foi lançado não volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lotes', 'tem_progressividade')) {
            Schema::table('lotes', function (Blueprint $t) {
                $t->dropColumn('tem_progressividade');
            });
        }
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->boolean('tem_progressividade')->nullable()->after('area_matricula_m2');
        });
    }
};
