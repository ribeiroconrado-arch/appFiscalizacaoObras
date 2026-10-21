<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O NÚMERO É ÚNICO DENTRO DO MÓDULO.
 *
 * Desde 2026_10_25 a numeração é por módulo (documento_contadores.modulo):
 * o Auto de Infração 1/2026 de Posturas convive com o 1/2026 de Obras. O
 * índice único continuava sendo (tipo, exercício, número), e a primeira
 * lavratura de Posturas batia nele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropUnique('uq_doc_numero');
            $t->unique(['modulo', 'tipo', 'exercicio', 'numero'], 'uq_doc_numero');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropUnique('uq_doc_numero');
            $t->unique(['tipo', 'exercicio', 'numero'], 'uq_doc_numero');
        });
    }
};
