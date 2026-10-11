<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DE ONDE VEIO A ÁREA DA OBRA informada no documento: medida pelo fiscal em
 * campo ("aferida") ou tirada do projeto apresentado ("projeto").
 *
 * A área é base de multa; quem lê a peça precisa saber se o número é uma
 * medição da fiscalização ou uma declaração do interessado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->string('area_obra_origem', 10)->nullable()->after('area_construida_m2');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn('area_obra_origem');
        });
    }
};
