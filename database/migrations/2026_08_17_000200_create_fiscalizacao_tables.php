<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Núcleo da fiscalização: obras, vistorias, irregularidades e evidências.
 *
 * Regra estruturante do projeto (§32 do documento): o IMÓVEL é a entidade
 * central. Aqui o imóvel é o `lote`, e tudo pendura nele —
 * lote → obra → vistoria → {irregularidades, evidências}.
 *
 * A vistoria aponta para o lote DIRETAMENTE, e não só através da obra, de
 * propósito: boa parte das vistorias de campo acontece justamente onde não há
 * obra cadastrada — é a construção sem alvará que motiva a visita. Exigir uma
 * obra antes de registrar a vistoria inverteria a ordem do trabalho real.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── RESPONSÁVEIS TÉCNICOS ────────────────────────────────
        Schema::create('responsaveis_tecnicos', function (Blueprint $t) {
            $t->id();
            $t->string('nome', 160);
            $t->string('documento', 20)->nullable();          // CPF/CNPJ
            $t->string('registro', 30)->nullable();           // número CREA/CAU
            $t->enum('conselho', ['CREA', 'CAU', 'CFT'])->nullable();
            $t->string('telefone', 25)->nullable();
            $t->string('email', 160)->nullable();
            $t->timestamps();
            $t->index('nome');
            $t->index('documento');
        });

        // ── OBRAS ────────────────────────────────────────────────
        Schema::create('obras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lote_id')->constrained('lotes')->cascadeOnDelete();
            $t->foreignId('responsavel_tecnico_id')->nullable()
              ->constrained('responsaveis_tecnicos')->nullOnDelete();
            $t->string('alvara', 40)->nullable();
            $t->string('tipo', 60)->nullable();                // residencial, comercial...
            $t->enum('situacao', [
                'nao_iniciada', 'em_andamento', 'paralisada',
                'concluida', 'embargada', 'irregular',
            ])->default('em_andamento');
            $t->decimal('area_construida', 10, 2)->nullable();
            $t->decimal('area_terreno', 10, 2)->nullable();
            $t->unsignedSmallInteger('pavimentos')->nullable();
            $t->date('data_inicio')->nullable();
            $t->text('observacoes')->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['lote_id', 'situacao']);
            $t->index('alvara');
        });

        // ── CATÁLOGO DE IRREGULARIDADES ──────────────────────────
        // Lista fechada, mantida por administrador. O fiscal marca no
        // checklist em vez de digitar — é o que torna o dado agregável no
        // dashboard depois ("irregularidades mais frequentes", §19).
        Schema::create('irregularidades', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 20)->unique();
            $t->string('descricao', 200);
            $t->enum('gravidade', ['leve', 'media', 'grave'])->default('media');
            // Fundamentação legal fica aqui por enquanto, como texto. Vira
            // relação com `legislacoes`/`artigos` na Etapa 6, quando o motor de
            // legislação existir — não antes, para não criar tabela vazia.
            $t->string('base_legal', 200)->nullable();
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->boolean('ativo')->default(true);
            $t->timestamps();
            $t->index(['ativo', 'ordem']);
        });

        // ── VISTORIAS ────────────────────────────────────────────
        Schema::create('vistorias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lote_id')->constrained('lotes')->cascadeOnDelete();
            $t->foreignId('obra_id')->nullable()->constrained('obras')->nullOnDelete();
            $t->foreignId('fiscal_id')->constrained('users');

            // DATETIME, não TIMESTAMP: guarda o horário LOCAL "ingênuo" que o
            // fiscal viu na tela. TIMESTAMP converteria para UTC e, no fuso de
            // Cuiabá, uma vistoria das 21h voltaria como do dia seguinte —
            // exatamente o bug que o AppPOSTURAS documenta e que já custou
            // registro com data errada lá.
            $t->dateTime('data_hora');

            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('accuracy', 7, 2)->nullable();

            $t->enum('situacao', [
                'regular', 'irregular', 'em_acompanhamento', 'nao_localizado',
            ])->default('irregular');

            $t->text('observacoes')->nullable();
            $t->timestamps();

            $t->index(['lote_id', 'data_hora']);
            $t->index(['fiscal_id', 'data_hora']);
            $t->index('situacao');
        });

        // ── IRREGULARIDADES CONSTATADAS ──────────────────────────
        Schema::create('vistoria_irregularidades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vistorias')->cascadeOnDelete();
            $t->foreignId('irregularidade_id')->constrained('irregularidades');
            $t->text('observacao')->nullable();
            $t->timestamps();
            $t->unique(['vistoria_id', 'irregularidade_id']);
        });

        // ── EVIDÊNCIAS ───────────────────────────────────────────
        // Arquivos ficam em disco PRIVADO (storage/app/private/evidencias) e
        // são servidos por rota autenticada. Foto de fiscalização mostra o
        // interior de propriedade privada e identifica pessoas: publicar em
        // `public/` deixaria tudo acessível por URL a quem descobrisse o
        // caminho, sem login.
        Schema::create('evidencias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vistorias')->cascadeOnDelete();
            $t->enum('tipo', ['foto', 'video', 'audio', 'documento'])->default('foto');
            $t->string('arquivo', 255);
            $t->string('nome_original', 255)->nullable();
            $t->string('mime', 100)->nullable();
            $t->unsignedBigInteger('tamanho')->nullable();
            $t->string('titulo', 160);
            $t->text('descricao')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->dateTime('data_hora')->nullable();
            // Autoria da evidência: só quem cadastrou pode excluir depois,
            // regra herdada do AppPOSTURAS — e ali admin não é exceção.
            $t->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('vistoria_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias');
        Schema::dropIfExists('vistoria_irregularidades');
        Schema::dropIfExists('vistorias');
        Schema::dropIfExists('irregularidades');
        Schema::dropIfExists('obras');
        Schema::dropIfExists('responsaveis_tecnicos');
    }
};
