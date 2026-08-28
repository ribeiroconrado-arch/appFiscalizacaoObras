<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A VISTORIA PASSA A REGISTRAR A OBRA.
 *
 * Até aqui a vistoria guardava quando, onde, a situação e o texto livre. Para
 * fiscalização de obra isso não fecha, por três motivos:
 *
 * ── 1. A área que calcula a multa não nascia em lugar nenhum ──
 *
 * `Artigo::calcularMulta()` devolve "Área não informada — multa não calculada"
 * quando a área é nula, e ela era SEMPRE nula: o documento cai em null e a
 * sugestão a partir da vistoria não a devolvia. Em obras a multa é por m², então
 * o número que a fundamenta faltava no sistema inteiro. A vistoria é o único
 * momento em que alguém está diante da construção com uma trena — é aqui que
 * ele tem de ser gravado, junto do MÉTODO: perito que contesta a multa contesta
 * a medição, e "estimativa visual" precisa aparecer como o que é.
 *
 * ── 2. A exigência não existia como item ──
 *
 * O que o fiscal exige do administrado cabia no campo de observações. Virava
 * notificação por transcrição manual, e uma lista de providências vira
 * parágrafo. Em tabela própria, cada exigência tem texto e prazo, e a peça
 * seguinte a imprime como lista.
 *
 * ── 3. O enquadramento legal se perdia entre a vistoria e o auto ──
 *
 * Os artigos ficam em tabela separada da do documento, de propósito: o artigo
 * citado na vistoria é o enquadramento CONSTATADO em campo; o do documento é o
 * efetivamente lavrado. São dois momentos, podem divergir, e o auto costuma ser
 * lavrado dias depois — às vezes por outra pessoa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vistorias', function (Blueprint $t) {
            // Quem recebeu o fiscal. Sustenta a notificação pessoal na peça
            // seguinte — sem isso, "ninguém foi encontrado no local" é uma
            // afirmação que o processo não consegue provar.
            $t->string('acompanhante_nome', 160)->nullable()->after('observacoes');
            $t->string('acompanhante_qualificacao', 60)->nullable()->after('acompanhante_nome');

            // "não verificado" é um estado legítimo e diferente de "não possui":
            // o fiscal pode não ter conseguido conferir. Forçar a escolha entre
            // possui e não possui produziria afirmação falsa em peça de processo.
            $t->enum('alvara_situacao', ['possui', 'nao_possui', 'nao_verificado'])
                ->nullable()->after('acompanhante_qualificacao');
            $t->string('alvara_numero', 40)->nullable()->after('alvara_situacao');

            $t->decimal('area_construida_aferida_m2', 12, 2)->nullable()->after('alvara_numero');
            $t->enum('area_metodo', ['trena', 'estimativa', 'projeto', 'croqui'])
                ->nullable()->after('area_construida_aferida_m2');

            $t->enum('fase_obra', [
                'fundacao', 'alvenaria', 'cobertura', 'acabamento', 'concluida', 'parada',
            ])->nullable()->after('area_metodo');
        });

        Schema::table('evidencias', function (Blueprint $t) {
            // Qual foto responde "como está o imóvel hoje". A ficha mostrava a
            // última foto qualquer — que pode ser o detalhe de uma trinca.
            $t->boolean('fachada')->default(false)->after('descricao');
        });

        Schema::create('vistoria_exigencias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vistorias')->cascadeOnDelete();
            // A ordem é do fiscal, não do banco: ele escreve na sequência em que
            // as providências devem ser tomadas, e a notificação imprime assim.
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->string('texto', 500);
            $t->unsignedSmallInteger('prazo_dias')->nullable();
            $t->timestamps();
            $t->index(['vistoria_id', 'ordem']);
        });

        Schema::create('vistoria_artigos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vistoria_id')->constrained('vistorias')->cascadeOnDelete();
            $t->foreignId('artigo_id')->constrained('artigos')->cascadeOnDelete();
            $t->unique(['vistoria_id', 'artigo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vistoria_artigos');
        Schema::dropIfExists('vistoria_exigencias');

        Schema::table('evidencias', fn (Blueprint $t) => $t->dropColumn('fachada'));
        Schema::table('vistorias', fn (Blueprint $t) => $t->dropColumn([
            'acompanhante_nome', 'acompanhante_qualificacao',
            'alvara_situacao', 'alvara_numero',
            'area_construida_aferida_m2', 'area_metodo', 'fase_obra',
        ]));
    }
};
