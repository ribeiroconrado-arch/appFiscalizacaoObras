<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordem de Serviço: como a coordenação delega trabalho a quem vai a campo.
 *
 * Até aqui o sistema registrava o que o fiscal FEZ — vistoria, documento,
 * protocolo. Não havia onde dizer o que ele DEVE fazer, e por isso a
 * distribuição do trabalho vivia fora: no papel, no grupo de mensagens, na
 * memória de quem coordena. O que fica de fora do sistema não entra em
 * relatório, não cobra prazo e não responde "quem estava incumbido disto?".
 *
 * Duas formas de marcar o trabalho no tempo, e elas não são a mesma coisa:
 *
 *   período   uma janela contínua ("de 1º a 30 de setembro"), típica de
 *             serviço permanente — ronda de bairro, acompanhamento de obra;
 *   dias      datas específicas, cada uma com o seu horário ("dia 12 das 8h
 *             às 12h; dia 19 das 14h às 18h"), típica de operação marcada.
 *
 * Guardar as duas como um par de datas obrigaria a inventar um "das 8h às 18h
 * todos os dias" que ninguém combinou. Por isso as jornadas são linhas: um dia
 * pode ter horário e o outro não, e três dias soltos não viram um intervalo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordens_servico', function (Blueprint $t) {
            $t->id();
            // Numeração por ano, como a dos documentos e protocolos: é assim
            // que se cita uma OS em ofício ("OS 2026/0007").
            $t->string('numero', 20)->unique();
            $t->unsignedSmallInteger('ano');
            $t->unsignedInteger('sequencia');

            $t->string('objeto', 200);
            $t->text('descricao')->nullable();

            // 'continua'   serviço que se repete enquanto durar a ordem;
            // 'especifica' uma tarefa que termina quando é cumprida.
            $t->enum('natureza', ['continua', 'especifica'])->default('especifica');
            $t->enum('regime', ['periodo', 'dias'])->default('periodo');

            // Só valem no regime 'periodo'. No regime 'dias' quem manda são as
            // linhas de os_jornadas.
            $t->date('inicio')->nullable();
            $t->date('fim')->nullable();

            $t->enum('situacao', ['aberta', 'em_andamento', 'concluida', 'cancelada'])
              ->default('aberta');
            $t->enum('prioridade', ['normal', 'alta'])->default('normal');

            // Quem determinou. A OS é ato de coordenação, e sem o autor ela
            // não tem de quem cobrar nem a quem recorrer.
            $t->foreignId('emitida_por')->constrained('users')->restrictOnDelete();
            $t->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $t->foreignId('protocolo_id')->nullable()->constrained('protocolos')->nullOnDelete();

            $t->text('encerramento')->nullable();
            $t->timestamp('encerrada_em')->nullable();
            $t->timestamps();

            $t->index(['situacao', 'ano']);
        });

        // Mais de um fiscal por ordem — é o caso comum numa operação.
        Schema::create('os_fiscais', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ordem_servico_id')->constrained('ordens_servico')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['ordem_servico_id', 'user_id']);
        });

        // Um dia com o seu horário. Linha, e não coluna: o número de dias não
        // se sabe de antemão, e cada um tem horário próprio.
        Schema::create('os_jornadas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ordem_servico_id')->constrained('ordens_servico')->cascadeOnDelete();
            $t->date('data');
            // Nulos de propósito: "dia 12" sem hora marcada é ordem legítima —
            // e diferente de "dia 12 o dia inteiro", que ninguém disse.
            $t->time('hora_inicio')->nullable();
            $t->time('hora_fim')->nullable();
            $t->string('observacao', 200)->nullable();
            $t->timestamps();
            $t->index(['ordem_servico_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_jornadas');
        Schema::dropIfExists('os_fiscais');
        Schema::dropIfExists('ordens_servico');
    }
};
