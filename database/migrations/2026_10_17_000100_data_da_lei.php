<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DATA da lei, para a citação oficial.
 *
 * Os textos de ciência ganham o marcador {lei oficial}, que vira "Lei
 * Complementar nº 1, de 15 de dezembro de 2023" — e a data não existia em
 * lugar nenhum (só o ano). Sem ela o marcador cita a lei só pelo número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legislacoes', function (Blueprint $t) {
            $t->date('data_publicacao')->nullable()->after('ano');
        });
    }

    public function down(): void
    {
        Schema::table('legislacoes', fn (Blueprint $t) => $t->dropColumn('data_publicacao'));
    }
};
