<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O sistema não guarda dado pessoal de proprietário.
 *
 * `bci_proprietarios` (nome, CPF/CNPJ, RG, endereço) nasceu com o BCI e nunca
 * teve quem a preenchesse: a exportação do cadastro é lida por uma lista
 * fechada de colunas (ColunasDaExportacao) que não inclui proprietário. Mas a
 * ficha do imóvel exibia o que houvesse nela — inclusive para o usuário
 * externo — e uma tabela pronta para receber CPF é um convite para alguém
 * preenchê-la um dia.
 *
 * Regra: CPF/CNPJ só existe DIGITADO no documento (notificação, auto), no
 * campo `autuado_documento`, na hora em que a peça precisa dele.
 *
 * O `down()` recria a estrutura vazia, só para o rollback não quebrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bci_proprietarios');
    }

    public function down(): void
    {
        Schema::create('bci_proprietarios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bci_imovel_id')->constrained('bci_imoveis')->cascadeOnDelete();
            $t->string('nome', 160);
            $t->string('documento', 24)->nullable();
            $t->string('rg_ie', 30)->nullable();
            $t->string('nacionalidade', 40)->nullable();
            $t->string('estado_civil', 40)->nullable();
            $t->string('endereco_logradouro', 160)->nullable();
            $t->string('endereco_numero', 20)->nullable();
            $t->string('endereco_bairro', 120)->nullable();
            $t->string('endereco_cidade', 120)->nullable();
            $t->string('endereco_uf', 2)->nullable();
            $t->timestamps();
        });
    }
};
