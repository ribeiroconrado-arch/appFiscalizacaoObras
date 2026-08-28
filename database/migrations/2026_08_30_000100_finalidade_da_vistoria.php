<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vistoria passa a ter FINALIDADE — e é ela que decide o que se pergunta.
 *
 * A tela nasceu para fiscalização de obra, e trazia "A obra" como passo fixo:
 * alvará, área aferida, fase. Mas o fiscal também vai a campo para atualizar
 * cadastro, conferir habite-se, instruir regularização de imóvel já pronto ou
 * simplesmente constatar um fato. Perguntar "qual a fase da obra?" numa
 * atualização cadastral não é só ruído: é campo que alguém vai preencher com
 * qualquer coisa para poder avançar, e aí o dado passa a mentir.
 *
 * Os três campos novos são o que as outras finalidades precisam e a de obra
 * não tinha por que ter. Todos nullable: cada finalidade usa os seus, e o
 * servidor descarta o que não pertence — ver Vistoria::FINALIDADES, que é a
 * fonte única dessa regra, lida tanto pela tela quanto pela gravação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vistorias', function (Blueprint $t) {
            // 'obras' como padrão porque é o que as vistorias já gravadas são:
            // a tela só existia para isso. Marcar o passado como o que ele de
            // fato foi é melhor do que deixá-lo nulo e indistinguível.
            $t->enum('finalidade', [
                'obras', 'cadastral', 'habite_se', 'regularizacao', 'constatacao',
            ])->default('obras')->after('situacao');

            // Habite-se e regularização: o que foi construído bate com o que
            // foi aprovado? "Sem projeto" é resposta legítima e diferente de
            // "não confere" — e é justamente o caso da regularização.
            $t->enum('conforme_projeto', [
                'sim', 'nao', 'sem_projeto', 'nao_verificado',
            ])->nullable()->after('area_metodo');

            // Regularização e atualização cadastral: idade aproximada da
            // construção. Ano, e não data: ninguém sabe o dia, e um campo de
            // data pediria uma precisão que não existe.
            $t->unsignedSmallInteger('ano_construcao_estimado')->nullable()->after('conforme_projeto');

            // O uso REAL, que é o que a atualização cadastral vai a campo
            // conferir — e que costuma divergir do uso declarado no cadastro.
            $t->enum('uso_constatado', [
                'residencial', 'comercial', 'industrial', 'misto',
                'institucional', 'religioso', 'vago',
            ])->nullable()->after('ano_construcao_estimado');
        });
    }

    public function down(): void
    {
        Schema::table('vistorias', function (Blueprint $t) {
            $t->dropColumn(['finalidade', 'conforme_projeto',
                            'ano_construcao_estimado', 'uso_constatado']);
        });
    }
};
