<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O ITEM DO RELATÓRIO passa a ser um GRUPO.
 *
 * Até aqui cada linha do relatório era UMA coisa: uma foto, um artigo citado,
 * um parecer, uma exigência. Mas em campo o que se constata não vem separado
 * assim — "muro sem recuo" é uma irregularidade, mais o que o fiscal escreveu
 * sobre ela, mais os artigos que a enquadram, mais as três fotos que a provam.
 * Eram quatro linhas soltas que precisavam ser lidas juntas e podiam ser
 * reordenadas em separado, desmontando o raciocínio.
 *
 * Agora o item é o grupo, e cada bloco pendura nele. O que se move para cima e
 * para baixo é o grupo inteiro.
 *
 * As irregularidades vinham de um checklist único da vistoria, sem ordem e sem
 * dono; passam a pertencer a um item — e a vistoria continua "tendo" todas
 * elas, pela soma dos itens (é o que a regra da situação e a sugestão de
 * artigos leem).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vistoria_itens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vistorias')->cascadeOnDelete();
            // A ORDEM É CONTEÚDO: é a sequência em que o fiscal montou o
            // raciocínio, e o relatório impresso a segue.
            $t->unsignedInteger('ordem')->default(0);
            $t->text('texto')->nullable();
            $t->timestamps();

            $t->index(['vistoria_id', 'ordem']);
        });

        // `nullOnDelete` e não `cascade`: apagar o item não pode levar junto a
        // FOTO, que é prova. Ela fica sem grupo, e a tela a recolhe.
        foreach (['evidencias', 'vistoria_artigos', 'vistoria_exigencias', 'vistoria_irregularidades'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->foreignId('item_id')->nullable()->after('id')
                  ->constrained('vistoria_itens')->nullOnDelete();
            });
        }

        // ── O QUE JÁ EXISTE GANHA UM ITEM ──
        //
        // Um item por vistoria, com tudo dentro, em vez de deixar as linhas
        // antigas sem grupo: assim quem lê tem UM caminho, e não um para o
        // relatório novo e outro para o histórico. O texto do item fica vazio —
        // inventar um resumo seria pôr palavra na boca de quem vistoriou.
        DB::transaction(function () {
            $comConteudo = DB::table('vistorias')->pluck('id')->filter(function ($id) {
                return DB::table('evidencias')->where('vistoria_id', $id)->exists()
                    || DB::table('vistoria_artigos')->where('vistoria_id', $id)->exists()
                    || DB::table('vistoria_exigencias')->where('vistoria_id', $id)->exists()
                    || DB::table('vistoria_irregularidades')->where('vistoria_id', $id)->exists();
            });

            foreach ($comConteudo as $vistoriaId) {
                $item = DB::table('vistoria_itens')->insertGetId([
                    'vistoria_id' => $vistoriaId,
                    'ordem'       => 0,
                    'texto'       => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

                foreach (['evidencias', 'vistoria_artigos', 'vistoria_exigencias', 'vistoria_irregularidades'] as $tabela) {
                    DB::table($tabela)->where('vistoria_id', $vistoriaId)->update(['item_id' => $item]);
                }
            }
        });
    }

    public function down(): void
    {
        foreach (['evidencias', 'vistoria_artigos', 'vistoria_exigencias', 'vistoria_irregularidades'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) use ($tabela) {
                $t->dropForeign([$tabela . '_item_id_foreign']);
                $t->dropColumn('item_id');
            });
        }

        Schema::dropIfExists('vistoria_itens');
    }
};
