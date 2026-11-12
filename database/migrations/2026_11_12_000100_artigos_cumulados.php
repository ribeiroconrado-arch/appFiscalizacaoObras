<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUMULADO COM (c/c) — decisão de 11/11/2026.
 *
 * O documento continua com UMA lei: a busca de artigos só oferece os dela. O
 * artigo de outra lei entra só pelo vínculo cadastrado em Parâmetros ›
 * Legislação › Artigos ("Art. 42 da LC 1/2023 c/c Art. 10 da Lei 500/2010"):
 * ao adicionar o artigo, o sistema pergunta se quer o vinculado junto, ou o
 * põe sozinho quando o vínculo é obrigatório.
 *
 * Na peça, cada artigo copia também a lei dele e de qual artigo ele é c/c —
 * cópia, como o resto do documento: o que vale é o que estava na lavratura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artigo_cumulados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artigo_id')->constrained('artigos')->cascadeOnDelete();
            $t->foreignId('cumulado_id')->constrained('artigos')->cascadeOnDelete();
            // Obrigatório entra sozinho com o artigo; senão, a tela pergunta.
            $t->boolean('obrigatorio')->default(false);
            $t->timestamps();
            $t->unique(['artigo_id', 'cumulado_id']);
        });

        Schema::table('documento_artigos', function (Blueprint $t) {
            $t->string('legislacao_rotulo', 220)->nullable()->after('numero');
            // O artigo (de `artigos`) de quem este é c/c; nulo = artigo da lei do documento.
            $t->unsignedBigInteger('cumulado_de_artigo_id')->nullable()->after('legislacao_rotulo');
        });
    }

    public function down(): void
    {
        Schema::table('documento_artigos', function (Blueprint $t) {
            $t->dropColumn(['legislacao_rotulo', 'cumulado_de_artigo_id']);
        });
        Schema::dropIfExists('artigo_cumulados');
    }
};
