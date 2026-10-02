<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importação de bairro pela tela, com revisão antes de valer para todos — e o
 * acesso de quem é de fora da fiscalização.
 *
 * ── Por que a importação vira registro ──
 *
 * Até aqui `gis:importar-lotes` gravava direto na base, sem deixar rastro de
 * QUAIS lotes vieram de qual arquivo. Isso tornava duas coisas impossíveis:
 * desfazer um bairro importado errado (quais linhas apagar?) e mostrar o bairro
 * só para quem vai revisá-lo. `importacao_id` é a resposta às duas.
 *
 * ── Por que `em_revisao` é coluna, e não um JOIN com a importação ──
 *
 * O filtro entra na consulta ESPACIAL do mapa, a mais quente do sistema. Um
 * JOIN a cada movimento do mapa, para perguntar algo que só muda no instante da
 * publicação, seria custo pago para sempre por uma resposta que cabe num bit.
 *
 * ── Cargos externos ──
 *
 * Topógrafo, arquiteto e contribuinte entram no sistema para ver o mapa. São
 * CARGO, e não perfil, porque o perfil já tem a trava que interessa: quem não é
 * agente é visualizador (ver User::perfilEfetivo). O cargo diz, além disso,
 * que a pessoa não é servidor da fiscalização — e é isso que tira dela o
 * conteúdo de vistorias e autos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes_lotes', function (Blueprint $t) {
            $t->id();
            $t->string('bairro', 120);
            $t->string('arquivo_nome', 200);
            // Para reconhecer o MESMO arquivo enviado duas vezes.
            $t->char('arquivo_hash', 64);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->enum('status', ['revisao', 'publicada', 'excluida'])->default('revisao');
            $t->unsignedInteger('total_lotes')->default(0);

            // O que a conferência do ARQUIVO disse no envio (sem quadra,
            // repetidos, ignorados). Retrato do momento, não é recalculado.
            $t->json('conferencia')->nullable();

            // A conferência com o CADASTRO DA PREFEITURA: só as divergências,
            // a fonte e quem conferiu. Refeita quantas vezes for preciso.
            $t->json('conferencia_cadastro')->nullable();
            $t->timestamp('conferido_em')->nullable();

            $t->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('publicado_em')->nullable();
            // Obrigatória quando se publica com divergência em aberto.
            $t->text('justificativa_publicacao')->nullable();

            $t->foreignId('excluido_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('excluido_em')->nullable();
            $t->text('motivo_exclusao')->nullable();

            $t->timestamps();
            $t->index('status');
        });

        Schema::table('lotes', function (Blueprint $t) {
            $t->foreignId('importacao_id')->nullable()->after('origem')
                ->constrained('importacoes_lotes')->nullOnDelete();
            $t->boolean('em_revisao')->default(false)->after('importacao_id')->index();
        });

        // ALTER cru: o `change()` de enum depende do doctrine/dbal em versões
        // antigas e reescreve a coluna inteira; aqui só se acrescentam valores.
        DB::statement("ALTER TABLE users MODIFY tipo_usuario
            ENUM('agente','coordenador','secretario','topografo','arquiteto','contribuinte') NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET tipo_usuario = NULL
                        WHERE tipo_usuario IN ('topografo','arquiteto','contribuinte')");
        DB::statement("ALTER TABLE users MODIFY tipo_usuario
            ENUM('agente','coordenador','secretario') NULL");

        Schema::table('lotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('importacao_id');
            $t->dropColumn('em_revisao');
        });

        Schema::dropIfExists('importacoes_lotes');
    }
};
