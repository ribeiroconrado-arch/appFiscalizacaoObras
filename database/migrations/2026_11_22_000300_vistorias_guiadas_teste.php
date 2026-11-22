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
 * TUDO AQUI É TESTE, e por isso separado do resto: prefixo `vg_`, nenhuma
 * chave para `vistorias`, `lotes` ou `protocolos`. A vistoria de obra que
 * existe continua intacta. Se o formato for aprovado, a ligação com o imóvel e
 * com a vistoria atual é decidida depois; se não for, um `migrate:rollback`
 * deste arquivo (e a pasta storage/app/private/vistorias-guiadas) somem com
 * tudo.
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
            $t->string('protocolo', 40)->nullable();
            $t->string('imovel', 255);
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
        Schema::dropIfExists('vg_vistorias');
        Schema::dropIfExists('vg_modelo_versoes');
        Schema::dropIfExists('vg_modelos');
    }
};
