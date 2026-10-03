<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CARGAS DO CADASTRO MUNICIPAL — a planilha do mês, gravando só a diferença.
 *
 * A prefeitura manda o município inteiro (~56 mil imóveis) todo mês. Guardar
 * a planilha inteira a cada mês custaria ~60 MB/mês; o que interessa guardar é
 * o que MUDOU. Então:
 *
 * - `cadastro_cargas`: uma linha por planilha recebida — quem, quando, o
 *   SHA-256 do arquivo (para recusar a mesma planilha duas vezes) e os totais.
 *   O arquivo em si é APAGADO ao fim da carga: traz CPF de milhares de
 *   pessoas, e os dados já estão no banco.
 * - `cadastro_alteracoes`: o histórico, campo a campo, só do que mudou.
 * - Em `cadastro_externo_imoveis`:
 *     `hash`                   impressão digital do registro, para comparar;
 *     `vista_na_carga_id`      última carga em que o imóvel veio ("Últ. integração");
 *     `alterado_na_carga_id`   última carga em que algo mudou ("Últ. alteração");
 *     `ausente_desde_carga_id` imóvel que sumiu da planilha — marcado, nunca apagado.
 *
 * Ver App\Cadastro\CargaDoCadastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cadastro_cargas', function (Blueprint $t) {
            $t->id();
            $t->string('arquivo_nome', 200);
            $t->unsignedBigInteger('arquivo_bytes')->default(0);
            $t->char('arquivo_sha256', 64)->index();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // na_fila | processando | aguardando_confirmacao | concluida | falhou
            $t->string('status', 30)->default('na_fila')->index();
            $t->text('mensagem')->nullable();
            $t->unsignedInteger('linhas_lidas')->default(0);

            $t->unsignedInteger('novos')->default(0);
            $t->unsignedInteger('alterados')->default(0);
            $t->unsignedInteger('iguais')->default(0);
            $t->unsignedInteger('ausentes')->default(0);
            $t->unsignedInteger('reaparecidos')->default(0);

            $t->json('bairros')->nullable();          // códigos presentes no arquivo
            $t->boolean('primeira')->default(false);  // linhas sem hash viraram base
            $t->timestamp('iniciada_em')->nullable();
            $t->timestamp('concluida_em')->nullable();
            $t->timestamps();
        });

        Schema::create('cadastro_alteracoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('carga_id')->constrained('cadastro_cargas')->cascadeOnDelete();
            $t->string('inscricao', 30);
            $t->string('tipo', 20);                   // novo | alterado | ausente | reapareceu
            $t->string('campo', 60)->nullable();
            $t->text('antes')->nullable();
            $t->text('depois')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['inscricao', 'id']);
        });

        Schema::table('cadastro_externo_imoveis', function (Blueprint $t) {
            $t->char('hash', 40)->nullable();
            $t->foreignId('vista_na_carga_id')->nullable()->constrained('cadastro_cargas')->nullOnDelete();
            $t->foreignId('alterado_na_carga_id')->nullable()->constrained('cadastro_cargas')->nullOnDelete();
            $t->foreignId('ausente_desde_carga_id')->nullable()->constrained('cadastro_cargas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cadastro_externo_imoveis', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vista_na_carga_id');
            $t->dropConstrainedForeignId('alterado_na_carga_id');
            $t->dropConstrainedForeignId('ausente_desde_carga_id');
            $t->dropColumn('hash');
        });
        Schema::dropIfExists('cadastro_alteracoes');
        Schema::dropIfExists('cadastro_cargas');
    }
};
