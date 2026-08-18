<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 6 — motor de legislação e documentos fiscais.
 *
 * O encadeamento do §18 do projeto:
 *   irregularidade -> legislação -> artigo -> conduta -> sanção -> documento
 *
 * O objetivo declarado é reduzir digitação e erro na fundamentação legal: o
 * fiscal escolhe a irregularidade constatada e o sistema já sabe qual artigo
 * a enquadra. Fundamentação errada em auto de infração é vício insanável —
 * derruba a autuação e expõe o município.
 *
 * Modelo herdado do módulo Autos do AppPOSTURAS, que já passou por uso real:
 * prazo de defesa em dias úteis vindo da LEI (não digitado por documento),
 * texto de ciência cadastrado por lei, e numeração por tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── LEIS ─────────────────────────────────────────────────
        Schema::create('legislacoes', function (Blueprint $t) {
            $t->id();
            $t->string('numero', 40);                  // "Lei Complementar 1/2023"
            $t->string('nome', 160);                   // "Código de Obras"
            $t->year('ano')->nullable();
            $t->text('ementa')->nullable();

            // Prazo de defesa é FIXO POR LEI e contado em dias úteis — não se
            // digita por documento. Vem do AppPOSTURAS (leis.prazo_defesa_dias):
            // deixar isso editável por documento foi fonte de prazo errado lá.
            $t->unsignedSmallInteger('prazo_defesa_dias')->default(5);

            // Prazo padrão de cumprimento sugerido para notificação (esse sim
            // varia por documento; aqui é só o valor inicial do formulário).
            $t->unsignedSmallInteger('prazo_cumprimento_dias')->default(10);

            // Textos de ciência/intimação, um por tipo de documento: falam de
            // prazos diferentes (defesa x cumprimento), por isso são dois.
            // NUNCA embutir no código — citação legal errada é risco jurídico.
            // O de notificação aceita o marcador {prazo}, trocado na impressão.
            $t->text('ciencia_notificacao')->nullable();
            $t->text('ciencia_auto')->nullable();

            $t->boolean('ativa')->default(true);
            $t->timestamps();
            $t->unique('numero');
        });

        // ── ARTIGOS ──────────────────────────────────────────────
        Schema::create('artigos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('legislacao_id')->constrained('legislacoes')->cascadeOnDelete();
            $t->string('numero', 30);                  // "Art. 42, par. 1, II"
            $t->string('apelido', 60)->nullable();     // rótulo curto na lista
            $t->text('conduta')->nullable();           // o que a norma proíbe
            $t->text('sancao')->nullable();            // penalidade prevista
            // Multa em UPF (Unidade Padrão Fiscal), como no AppPOSTURAS.
            $t->decimal('multa_upf', 8, 2)->nullable();
            $t->boolean('ativo')->default(true);
            $t->timestamps();
            $t->index(['legislacao_id', 'ativo']);
            $t->unique(['legislacao_id', 'numero']);
        });

        // Irregularidade x artigo: é ISTO que evita o fiscal procurar artigo à
        // mão. N:N porque a mesma conduta pode infringir mais de um dispositivo.
        Schema::create('artigo_irregularidade', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artigo_id')->constrained('artigos')->cascadeOnDelete();
            $t->foreignId('irregularidade_id')->constrained('irregularidades')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['artigo_id', 'irregularidade_id'], 'uq_artigo_irreg');
        });

        // ── DOCUMENTOS ───────────────────────────────────────────
        // Uma tabela para todos os tipos, como no AppPOSTURAS: o que muda entre
        // notificação, auto e termo é regra de negócio, não estrutura. Tabelas
        // separadas duplicariam prazo, assinatura, anexo e numeração.
        Schema::create('documentos', function (Blueprint $t) {
            $t->id();
            $t->enum('tipo', [
                'vistoria',        // registro de imóvel regular: não gera sanção
                'notificacao',
                'auto_infracao',
                'auto_embargo',
                'termo_advertencia',
            ]);

            // Nulo enquanto rascunho. O número só é atribuído ao LAVRAR —
            // numerar rascunho queima sequência e cria buraco na série, que é
            // exatamente o que um processo administrativo não pode ter.
            $t->unsignedInteger('numero')->nullable();
            $t->year('exercicio')->nullable();

            $t->foreignId('lote_id')->constrained('lotes');
            $t->foreignId('vistoria_id')->nullable()->constrained('vistorias')->nullOnDelete();
            $t->foreignId('legislacao_id')->nullable()->constrained('legislacoes')->nullOnDelete();
            $t->foreignId('agente_id')->constrained('users');

            // Documento gerado a partir de outro (notificação -> auto por
            // descumprimento; auto -> auto por recorrência).
            $t->foreignId('origem_id')->nullable()->constrained('documentos')->nullOnDelete();

            $t->enum('status', [
                'rascunho', 'lavrado', 'atendido', 'anulado', 'cancelado',
            ])->default('rascunho');

            $t->dateTime('data_fato')->nullable();
            $t->dateTime('data_lavratura')->nullable();

            // Prazo de CUMPRIMENTO (notificação). 0 = imediato.
            $t->unsignedSmallInteger('prazo_dias')->nullable();
            $t->date('prazo_ate')->nullable();
            // Prazo de DEFESA (auto). Calculado em dias úteis a partir da lei —
            // congelado na lavratura, para reabrir o documento não renovar prazo.
            $t->date('defesa_ate')->nullable();

            $t->decimal('valor_upf', 8, 2)->nullable();

            $t->string('autuado_nome', 160)->nullable();
            $t->string('autuado_documento', 20)->nullable();
            $t->string('endereco', 200)->nullable();

            $t->text('descricao')->nullable();
            $t->text('observacoes')->nullable();

            // Assinaturas como imagem (data URL do canvas). A do autuado é
            // capturada NO documento e não se reaproveita em outro — regra
            // explícita do §17 do projeto.
            $t->longText('assinatura_agente')->nullable();
            $t->longText('assinatura_autuado')->nullable();
            $t->string('recusa_assinatura', 160)->nullable();

            $t->timestamps();

            $t->index(['tipo', 'status']);
            $t->index(['lote_id', 'created_at']);
            $t->index(['agente_id', 'created_at']);
            $t->unique(['tipo', 'exercicio', 'numero'], 'uq_doc_numero');
        });

        // Artigos citados no documento. Cópia dos textos no momento da
        // lavratura, de propósito: se a lei for alterada depois, o documento
        // emitido tem de continuar mostrando o que foi imputado na época.
        Schema::create('documento_artigos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
            $t->foreignId('artigo_id')->nullable()->constrained('artigos')->nullOnDelete();
            $t->string('numero', 30);
            $t->text('conduta')->nullable();
            $t->text('sancao')->nullable();
            $t->decimal('multa_upf', 8, 2)->nullable();
            $t->timestamps();
        });

        // Contador por tipo e exercício. Linha travada com lockForUpdate() na
        // hora de lavrar: dois fiscais lavrando ao mesmo tempo não podem
        // receber o mesmo número.
        Schema::create('documento_contadores', function (Blueprint $t) {
            $t->id();
            $t->string('tipo', 30);
            $t->year('exercicio');
            $t->unsignedInteger('ultimo')->default(0);
            $t->timestamps();
            $t->unique(['tipo', 'exercicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_contadores');
        Schema::dropIfExists('documento_artigos');
        Schema::dropIfExists('documentos');
        Schema::dropIfExists('artigo_irregularidade');
        Schema::dropIfExists('artigos');
        Schema::dropIfExists('legislacoes');
    }
};
