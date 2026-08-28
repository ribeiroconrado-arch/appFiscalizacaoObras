<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vistoria deixa de ser "checklist + fotos" e passa a ser um RELATÓRIO.
 *
 * O motivo é de campo: a maioria das vistorias não constata irregularidade
 * nenhuma. Obrigar o fiscal a atravessar uma tela de irregularidades para
 * depois anexar fotos noutra fazia o registro do trabalho REGULAR parecer um
 * desvio do caminho — quando é o caso comum.
 *
 * Agora há uma lista só, na ordem em que o fiscal a monta, com quatro tipos de
 * item: foto, artigo citado com observação, parecer sobre artigo e exigência.
 * Para isso, o que já existia ganha ORDEM (a sequência é o que transforma
 * anotações soltas em relatório) e as citações de artigo ganham texto próprio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidencias', function (Blueprint $t) {
            if (Schema::hasColumn('evidencias', 'ordem')) { return; }

            // Posição da foto DENTRO do relatório, e não só entre as fotos: a
            // sequência é compartilhada com os itens de artigo.
            $t->unsignedSmallInteger('ordem')->default(0)->after('fachada');

            // Marcações numeradas sobre a imagem, em coordenadas relativas
            // (0 a 1), para a legenda poder dizer "1" e "2" e a foto mostrar
            // ONDE. Relativas porque a mesma foto é exibida em tamanhos
            // diferentes — pixel absoluto sairia do lugar em cada tela.
            $t->json('marcacoes')->nullable()->after('ordem');
        });

        // A tabela de ligação vira um ITEM do relatório: o mesmo artigo pode
        // aparecer duas vezes — citado no que se viu e de novo no parecer —,
        // então a unicidade por (vistoria, artigo) sai.
        // A chave estrangeira de vistoria_id se apoiava NO índice único (é o
        // primeiro campo dele). Derrubá-lo direto o MySQL recusa — 1553 —,
        // então o índice simples entra antes de o único sair.
        Schema::table('vistoria_artigos', function (Blueprint $t) {
            $t->index('vistoria_id', 'vistoria_artigos_vistoria_id_index');
        });

        Schema::table('vistoria_artigos', function (Blueprint $t) {
            $t->dropUnique('vistoria_artigos_vistoria_id_artigo_id_unique');
        });

        Schema::table('vistoria_artigos', function (Blueprint $t) {
            $t->unsignedSmallInteger('ordem')->default(0)->after('artigo_id');
            // 'citacao'  o que se constatou em relação ao dispositivo;
            // 'parecer'  a conclusão do fiscal sobre ele.
            // São coisas diferentes na peça seguinte: a citação vira fato, o
            // parecer vira fundamentação — e quem lê meses depois precisa
            // saber qual é qual.
            $t->enum('tipo', ['citacao', 'parecer'])->default('citacao')->after('ordem');
            $t->string('observacao', 2000)->nullable()->after('tipo');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('evidencias', function (Blueprint $t) {
            $t->dropColumn(['ordem', 'marcacoes']);
        });

        Schema::table('vistoria_artigos', function (Blueprint $t) {
            $t->dropColumn(['ordem', 'tipo', 'observacao', 'created_at', 'updated_at']);
            $t->unique(['vistoria_id', 'artigo_id']);
        });
    }
};
