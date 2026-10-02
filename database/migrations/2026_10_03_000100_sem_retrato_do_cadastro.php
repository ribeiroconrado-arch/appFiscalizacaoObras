<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PLANILHA NÃO É GUARDADA — só as divergências.
 *
 * `cadastro_retratos` guardava os imóveis do bairro como vinham na planilha da
 * conferência, para "Conferir de novo" sem anexar o arquivo. O usuário não
 * quer o conteúdo da planilha no sistema: o que fica é o resultado (as
 * divergências), e "Conferir de novo" sem anexo REVISA essas divergências
 * contra os lotes de agora (ConferenciaComCadastro::revisar). A tabela sai, e
 * com ela o que já tinha sido guardado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cadastro_retratos');
    }

    public function down(): void
    {
        Schema::create('cadastro_retratos', function (Blueprint $t) {
            $t->id();
            $t->string('codigo_bairro', 10);
            $t->string('fonte_descricao', 200);
            $t->boolean('tem_situacao')->nullable();
            $t->longText('linhas');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
        });
    }
};
