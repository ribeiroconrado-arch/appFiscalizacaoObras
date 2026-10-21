<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AS PEÇAS DE POSTURAS — o que a notificação e os autos do AppPOSTURAS têm e
 * a peça de Obras não tinha:
 *
 *   ruido              a MEDIÇÃO da Lei 723/2002: aparelho, fonte causadora,
 *                      zona, dB permitido e aferido. É dela que sai a multa
 *                      pela diferença de decibéis (App\Posturas\MultaPorRuido).
 *                      O aparelho vai copiado (rótulo e certificado), como lá:
 *                      a peça cita o equipamento do dia, e o cadastro dele
 *                      pode mudar depois.
 *   veiculo            o carro da fonte sonora, ou o retido no Auto de
 *                      Retenção: placa, marca, modelo, cor (e, na retenção,
 *                      ano, proprietário e condutor).
 *   apreensao_subtipo  o Auto de Apreensão apreende BENS ou retém VEÍCULO.
 *   documento_itens    os bens apreendidos, um por linha.
 *
 * JSON, e não colunas, para ruído e veículo: são blocos que só existem em
 * Posturas, lidos e impressos inteiros, nunca filtrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->json('ruido')->nullable()->after('alvara_valor');
            $t->json('veiculo')->nullable()->after('ruido');
            $t->string('apreensao_subtipo', 10)->nullable()->after('veiculo');
        });

        Schema::create('documento_itens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->string('descricao', 300);
            $t->decimal('quantidade', 10, 2)->default(1);
            $t->string('unidade', 20)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_itens');
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn(['ruido', 'veiculo', 'apreensao_subtipo']);
        });
    }
};
