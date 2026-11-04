<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os casos do levantamento de 2022 (gis:desmembramentos-levantamento) ficam
 * com a IMPORTAÇÃO do bairro, na aba "Levantamento" da ficha dela — é no
 * momento da importação que eles importam. Ver App\Services\LevantamentoDaImportacao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacoes_lotes', function (Blueprint $t) {
            $t->json('levantamento')->nullable()->after('conferencia_cadastro');
        });
    }

    public function down(): void
    {
        Schema::table('importacoes_lotes', function (Blueprint $t) {
            $t->dropColumn('levantamento');
        });
    }
};
