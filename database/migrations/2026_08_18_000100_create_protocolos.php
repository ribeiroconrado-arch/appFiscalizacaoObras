<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protocolos — vistorias SOLICITADAS pelo contribuinte.
 *
 * Extensão do plano original, que só previa a fiscalização de ofício. Mas boa
 * parte do trabalho do setor entra por requerimento: habite-se, vistoria de
 * calçada, contestação de área, renovação de alvará, desmembramento.
 *
 * A diferença que justifica a tabela própria: aqui existe um REQUERENTE e um
 * PRAZO DE RESPOSTA DO MUNICÍPIO. Na fiscalização de ofício o prazo corre
 * contra o administrado; no protocolo corre contra a administração, e passar
 * do prazo tem consequência para o município — silêncio administrativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocolos', function (Blueprint $t) {
            $t->id();

            // Número vindo do protocolo geral da prefeitura (2026/0412). É
            // string porque a formatação é da prefeitura, não nossa, e pode
            // mudar de formato sem quebrar o sistema.
            $t->string('numero', 30)->unique();

            $t->enum('tipo', [
                'habite_se',
                'vistoria_calcada',
                'contestacao_area',
                'renovacao_alvara',
                'desmembramento',
                'outro',
            ]);

            $t->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();

            $t->string('requerente_nome', 160);
            $t->string('requerente_documento', 20)->nullable();
            $t->string('requerente_contato', 120)->nullable();

            $t->date('protocolado_em');
            // Prazo de resposta DO MUNICÍPIO. Nulo quando a lei não fixa prazo.
            $t->date('prazo_resposta')->nullable();

            $t->enum('situacao', [
                'aguardando_vistoria',
                'em_analise',
                'deferido',
                'indeferido',
                'arquivado',
            ])->default('aguardando_vistoria');

            // Agente responsável. Nulo = ainda não distribuído.
            $t->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();

            // Vistoria que atendeu o protocolo, quando houver.
            $t->foreignId('vistoria_id')->nullable()->constrained('vistorias')->nullOnDelete();

            $t->text('objeto')->nullable();       // o que o contribuinte pede
            $t->text('parecer')->nullable();      // conclusão do setor
            $t->timestamps();

            $t->index(['tipo', 'situacao']);
            $t->index(['responsavel_id', 'situacao']);
            $t->index('prazo_resposta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocolos');
    }
};
