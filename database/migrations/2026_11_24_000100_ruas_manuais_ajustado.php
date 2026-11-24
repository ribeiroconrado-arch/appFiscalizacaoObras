<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENCOSTAR trecho de rua (24/11/2026): o curador leva a ponta de um trecho até
 * o outro (Nomes de rua → Encostar pontas). O desenho do trecho passa a ser o
 * dele, e o "Gerar" / "Refazer nomes de rua" não o troca pelo gerado novo
 * (TrechosDeRua::reconciliar) — senão o ajuste sumia na primeira regeração.
 * "Voltar ao cadastro" apaga o manual e desfaz o ajuste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ruas_manuais', function (Blueprint $t) {
            $t->boolean('ajustado')->default(false)->after('excluido');
        });
    }

    public function down(): void
    {
        Schema::table('ruas_manuais', function (Blueprint $t) {
            $t->dropColumn('ajustado');
        });
    }
};
