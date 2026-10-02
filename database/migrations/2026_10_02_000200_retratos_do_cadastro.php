<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RETRATO DO CADASTRO — os imóveis de UM bairro, como estavam na planilha
 * usada numa conferência.
 *
 * A planilha avulsa é lida só em memória (PlanilhaDoCadastro) e some com a
 * requisição. Sem guardar o que ela dizia daquele bairro, cada correção feita
 * no mapa exigia anexar o Excel de novo para ver a pendência sair da lista.
 * Com o retrato, "Conferir de novo" — e a reconferência automática depois de
 * cada correção — usa a mesma planilha sem pedir o arquivo (RetratoDoCadastro).
 *
 * Guarda só as colunas que a conferência lê, e só do bairro conferido: algumas
 * centenas de linhas, não a planilha de 56 mil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cadastro_retratos', function (Blueprint $t) {
            $t->id();
            $t->string('codigo_bairro', 10);
            $t->string('fonte_descricao', 200);
            // A planilha tinha coluna de situação (ativo/inativo)?
            $t->boolean('tem_situacao')->nullable();
            $t->longText('linhas');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cadastro_retratos');
    }
};
