<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CADA MÓDULO TEM OS SEUS MODELOS DE VISTORIA (decisão dele, 27/11/2026):
 * Obras tem os de Obras, Posturas os de Posturas. O administrador monta os
 * modelos do módulo em que está, e a vistoria nova só oferece os dele.
 *
 * Os que já existem ficam em Obras, menos os dois exemplos que são de
 * Posturas (terreno sem limpeza e averiguação de denúncia).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vg_modelos', 'modulo')) {
            Schema::table('vg_modelos', function (Blueprint $t) {
                $t->string('modulo', 20)->default('obras')->after('nome');
                $t->index('modulo');
            });
        }
        DB::table('vg_modelos')->whereIn('nome', ['Terreno sem limpeza', 'Averiguação de denúncia'])->update(['modulo' => 'posturas']);
    }

    public function down(): void
    {
        Schema::table('vg_modelos', function (Blueprint $t) {
            $t->dropIndex(['modulo']);
            $t->dropColumn('modulo');
        });
    }
};
