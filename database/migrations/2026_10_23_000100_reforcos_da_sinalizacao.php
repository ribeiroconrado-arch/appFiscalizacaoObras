<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REFORÇO DA SINALIZAÇÃO.
 *
 * Quando alguém sinaliza num imóvel algo que JÁ está sinalizado e pendente
 * (o mesmo tipo), o aviso não vira uma segunda bandeira: é SOMADO à que
 * existe. O mapa continua com uma bandeira por assunto, e ela passa a dizer
 * quantas pessoas avisaram — que é a informação que interessa a quem decide
 * aonde ir primeiro.
 *
 * Cada linha é o aviso de uma pessoa a mais, com o comentário dela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sinalizacao_reforcos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sinalizacao_id')->constrained('sinalizacoes')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('comentario', 500)->nullable();
            $t->timestamps();
            $t->index(['sinalizacao_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sinalizacao_reforcos');
    }
};
