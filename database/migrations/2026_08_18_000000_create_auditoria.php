<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria (§27 do projeto).
 *
 * Num sistema de fiscalização a trilha não é conforto de TI: é o que responde,
 * dois anos depois, quem lavrou, quem alterou prazo e quem anulou um auto —
 * perguntas que aparecem em processo administrativo e em ação judicial.
 *
 * Grava sempre o autor, o IP e o antes/depois. O "antes" é o que dá sentido ao
 * registro: saber que alguém mudou um prazo não vale nada sem saber de quanto
 * para quanto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $t) {
            $t->id();

            // Nulo em ação de console (seeder, importação): não há usuário
            // autenticado, e forçar um id falso mentiria sobre a autoria.
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('usuario_nome', 120)->nullable();   // cópia: usuário pode ser excluído
            $t->string('matricula', 30)->nullable();

            $t->string('acao', 40);            // criou, alterou, excluiu, lavrou, anulou...
            $t->string('tabela', 60);
            $t->unsignedBigInteger('registro_id')->nullable();
            $t->string('descricao', 200)->nullable();  // resumo legível na tela

            $t->json('dados_anteriores')->nullable();
            $t->json('dados_novos')->nullable();

            $t->string('ip', 45)->nullable();          // 45 cobre IPv6
            $t->string('dispositivo', 255)->nullable();

            $t->timestamp('created_at')->useCurrent();

            $t->index(['tabela', 'registro_id']);
            $t->index(['user_id', 'created_at']);
            $t->index('acao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
