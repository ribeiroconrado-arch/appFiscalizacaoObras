<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OS ANEXOS PRÓPRIOS DO DOCUMENTO.
 *
 * Até aqui a peça não tinha anexo seu: imprimia TODAS as fotos da vistoria
 * vinculada. Agora cada documento tem a sua lista, e o fiscal escolhe:
 *
 *   proprio     foto (câmera ou galeria) ou PDF juntado na própria peça;
 *   vistoria    foto da vistoria vinculada, trazida por escolha;
 *   documento   anexo da peça de origem (a notificação ou o embargo de que o
 *               auto nasceu), também por escolha.
 *
 * Os trazidos NÃO copiam o arquivo: apontam para o mesmo, e por isso excluir
 * um deles nunca apaga o arquivo de quem o cedeu.
 *
 * `juntado_depois` marca o que entrou com a peça já lavrada — a juntada
 * posterior é permitida, mas tem de aparecer como tal.
 *
 * `documentos.anexos_proprios` separa as peças novas das antigas: as antigas
 * (false) continuam imprimindo as fotos da vistoria, como sempre imprimiram —
 * uma peça já lavrada não muda de conteúdo porque o sistema mudou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documento_anexos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
            $t->enum('origem', ['proprio', 'vistoria', 'documento'])->default('proprio');
            // De onde veio o trazido: a evidência da vistoria ou o anexo da peça de origem.
            $t->unsignedBigInteger('origem_ref')->nullable();
            $t->string('arquivo', 255);
            $t->string('mime', 80);
            $t->string('nome_original', 255)->nullable();
            $t->string('titulo', 160)->nullable();
            $t->dateTime('data_hora')->nullable();     // quando a foto foi feita
            $t->boolean('imprime')->default(true);
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->boolean('juntado_depois')->default(false);
            $t->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['documento_id', 'ordem']);
        });

        Schema::table('documentos', function (Blueprint $t) {
            $t->boolean('anexos_proprios')->default(false)->after('vistoria_id');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', fn (Blueprint $t) => $t->dropColumn('anexos_proprios'));
        Schema::dropIfExists('documento_anexos');
    }
};
