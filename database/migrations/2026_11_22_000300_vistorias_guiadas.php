<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VISTORIA GUIADA — PÁGINA DE TESTE (22/11/2026).
 *
 * Roteiro de vistoria montado por modelo (Habite-se, Início de obra,
 * Averiguação de denúncia…): etapas, itens "Confere / Não confere / Não se
 * aplica", fotos mínimas, localização exigida antes de começar, PDFs anexados
 * pelo fiscal ao criar a vistoria e itens livres em qualquer momento.
 *
 * Nasceu como página de teste, e por isso o prefixo `vg_` e nenhuma chave
 * estrangeira para `lotes`, `protocolos` ou `denuncias` (o vínculo guarda
 * tipo + id). Em 26/11/2026 foi aprovada para SUBSTITUIR a vistoria de obra:
 * documentos, protocolos e sinalizações passam a apontar para `vg_vistorias`
 * (migração 2026_11_26_000100).
 *
 * ── Por que a definição do modelo é JSON ──
 *
 * A vistoria precisa do roteiro EXATO em que nasceu: mudar o modelo depois não
 * pode mudar o que o fiscal respondeu. Cada publicação grava uma versão
 * imutável (`vg_modelo_versoes`), e a vistoria aponta para a versão. Um
 * documento inteiro por versão é a forma natural disso — tabelas de etapas e
 * itens teriam de ser copiadas a cada publicação para dar o mesmo resultado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vg_modelos', function (Blueprint $t) {
            $t->id();
            $t->string('nome', 120);
            $t->boolean('ativo')->default(true);
            // O que o administrador está editando. Só vira roteiro de vistoria
            // ao ser publicado.
            $t->json('rascunho');
            $t->boolean('rascunho_alterado')->default(true);
            $t->unsignedInteger('versao_atual')->default(0);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('vg_modelo_versoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('modelo_id')->constrained('vg_modelos')->restrictOnDelete();
            $t->unsignedInteger('versao');
            $t->string('nome', 120);
            $t->json('definicao');
            $t->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('publicado_em');
            $t->unique(['modelo_id', 'versao']);
        });

        Schema::create('vg_vistorias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('versao_id')->constrained('vg_modelo_versoes')->restrictOnDelete();
            // "VG 2026/0001": número por exercício, como as demais peças.
            $t->unsignedSmallInteger('exercicio');
            $t->unsignedInteger('numero');
            // Obras ou Posturas: decide o cabeçalho do relatório. O motor é o
            // mesmo — nenhuma vistoria tem campo fixo de obra.
            $t->string('modulo', 20)->default('obras');
            // Referência do lugar, em texto: serve à vistoria que não tem
            // imóvel cadastrado (via pública, terreno sem inscrição…).
            $t->string('local', 255)->nullable();
            $t->text('descricao')->nullable();
            $t->enum('estado', ['pendente', 'em_andamento', 'concluida'])->default('pendente');
            // A posição do aparelho e o ponto marcado no mapa ficam separados:
            // o modelo pode exigir um, outro ou os dois, e o relatório diz qual.
            $t->decimal('gps_lat', 10, 7)->nullable();
            $t->decimal('gps_lng', 10, 7)->nullable();
            $t->unsignedInteger('gps_precisao')->nullable();
            $t->decimal('mapa_lat', 10, 7)->nullable();
            $t->decimal('mapa_lng', 10, 7)->nullable();
            // { "<item>": { "v": "sim|nao|na", "obs": "..." } }
            $t->json('respostas')->nullable();
            $t->string('conclusao', 120)->nullable();
            $t->timestamp('iniciada_em')->nullable();
            $t->timestamp('concluida_em')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['exercicio', 'numero']);
        });

        // A que a vistoria está ligada. Nenhum vínculo é obrigatório por si —
        // o modelo é que pode exigir um tipo. O rótulo é copiado na hora:
        // o relatório diz o que o fiscal viu ao ligar, mesmo que o cadastro
        // mude depois.
        Schema::create('vg_vinculos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vg_vistorias')->cascadeOnDelete();
            $t->enum('tipo', ['imovel', 'pessoa', 'protocolo', 'denuncia']);
            $t->unsignedBigInteger('ref_id');
            $t->string('rotulo', 255);
            $t->boolean('depois')->default(false);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['vistoria_id', 'tipo', 'ref_id']);
            $t->index(['tipo', 'ref_id']);
        });

        // Os fiscais relacionados: só eles mexem na vistoria, e cada um assina
        // o relatório. A assinatura é COPIADA do perfil, como na OS —
        // redesenhar a assinatura amanhã não reescreve o que já foi assinado.
        Schema::create('vg_fiscais', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vg_vistorias')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->longText('assinatura')->nullable();
            $t->timestamp('assinada_em')->nullable();
            $t->timestamps();
            $t->unique(['vistoria_id', 'user_id']);
        });

        Schema::create('vg_livres', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vg_vistorias')->cascadeOnDelete();
            $t->string('etapa_chave', 40)->nullable();
            $t->string('titulo', 160);
            $t->text('observacao')->nullable();
            // Criado depois de concluída: fica marcado, e o que já estava não muda.
            $t->boolean('depois')->default(false);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('vg_arquivos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vg_vistorias')->cascadeOnDelete();
            // anexo = PDF juntado ao criar · foto = tirada durante a vistoria
            $t->enum('papel', ['anexo', 'foto']);
            $t->string('anexo_chave', 40)->nullable();   // qual anexo do modelo
            $t->string('etapa_chave', 40)->nullable();   // foto da etapa
            $t->string('item_chave', 40)->nullable();    // foto do "não confere"
            $t->foreignId('livre_id')->nullable()->constrained('vg_livres')->cascadeOnDelete();
            $t->string('nome', 160);
            $t->string('nome_original', 255)->nullable();
            $t->string('caminho', 255);
            $t->string('mime', 80);
            $t->unsignedInteger('tamanho');
            $t->boolean('depois')->default(false);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vg_arquivos');
        Schema::dropIfExists('vg_livres');
        Schema::dropIfExists('vg_fiscais');
        Schema::dropIfExists('vg_vinculos');
        Schema::dropIfExists('vg_vistorias');
        Schema::dropIfExists('vg_modelo_versoes');
        Schema::dropIfExists('vg_modelos');
    }
};
