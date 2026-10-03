<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proprietários do imóvel, como vêm na exportação do cadastro municipal.
 *
 * Pertencem ao CADASTRO, não ao lote: a chave é a inscrição imobiliária, igual
 * a `cadastro_externo_imoveis`. O lote do mapa chega até eles pela mesma
 * amarração (bairro + quadra + lote) que a aba BCI já usa. Assim a carga
 * mensal da planilha substitui o que mudou sem tocar em nada da aplicação.
 *
 * Dado pessoal: quem vê o quê é decidido no servidor, em
 * App\Cadastro\ProprietariosVisiveis — nome para os servidores, CPF/CNPJ e
 * endereço só para agente de fiscalização e administrador, nada para o
 * usuário externo. Nunca vai para a auditoria nem para log.
 *
 * Substitui `bci_proprietarios` (removida em 03/10), que era presa à cópia por
 * lote e nunca chegou a ser preenchida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cadastro_proprietarios', function (Blueprint $t) {
            $t->id();
            $t->string('inscricao', 30)->index();
            $t->string('nome', 200);
            $t->string('documento', 24)->nullable();   // CPF ou CNPJ, como veio
            $t->string('endereco', 300)->nullable();   // de correspondência
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cadastro_proprietarios');
    }
};
