<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXCLUIR trecho de rua (22/11/2026): o trecho gerado que não é rua (linha
 * cruzando quadra, sobra de esquina) some do mapa, linha e rótulo.
 *
 * É um manual por cima do gerado, como o oculto, porque o "Gerar" refaz os
 * gerados e o trecho voltaria. O desenhado à mão não precisa disso: é apagado.
 * Desfaz-se como os outros manuais, com "Voltar ao cadastro".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ruas_manuais', function (Blueprint $t) {
            $t->boolean('excluido')->default(false)->after('oculto');
        });
    }

    public function down(): void
    {
        Schema::table('ruas_manuais', function (Blueprint $t) {
            $t->dropColumn('excluido');
        });
    }
};
