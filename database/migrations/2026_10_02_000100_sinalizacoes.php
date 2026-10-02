<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SINALIZAÇÃO — o aviso rápido sobre um imóvel, no estilo do Waze.
 *
 * Alguém vê algo (obra sem placa, entulho, risco) e marca o lote em dois
 * toques; ou o fiscal, ao terminar uma vistoria, deixa um LEMBRETE para voltar
 * depois de tantos dias. Os dois são a mesma coisa: uma pendência no lote, que
 * aparece no mapa, na ficha e no Painel, e se resolve de dois jeitos —
 * sozinha, quando alguém registra uma vistoria no lote, ou com um toque em
 * "Resolvido" e uma palavra de motivo.
 *
 * Resolvida, ela sai do mapa e do Painel e fica no Histórico do imóvel.
 * Não tem número, fila nem triagem, de propósito: o que precisa de rito vira
 * protocolo ou ordem de serviço; isto é o bilhete na geladeira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sinalizacoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lote_id')->constrained('lotes')->cascadeOnDelete();
            $t->string('tipo', 30);
            $t->string('comentario', 500)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // LEMBRETE: só passa a "valer" (mapa, Painel) a partir desta data.
            $t->date('lembrar_em')->nullable();
            // O lembrete deixado ao fim de uma vistoria aponta para ela.
            $t->foreignId('vistoria_origem_id')->nullable()->constrained('vistorias')->nullOnDelete();

            $t->enum('status', ['aberta', 'resolvida'])->default('aberta');
            $t->timestamp('resolvida_em')->nullable();
            $t->foreignId('resolvida_por')->nullable()->constrained('users')->nullOnDelete();
            $t->string('resolucao', 300)->nullable();
            // Resolvida POR uma vistoria registrada no lote.
            $t->foreignId('vistoria_id')->nullable()->constrained('vistorias')->nullOnDelete();

            $t->timestamps();
            $t->index(['status', 'lote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sinalizacoes');
    }
};
