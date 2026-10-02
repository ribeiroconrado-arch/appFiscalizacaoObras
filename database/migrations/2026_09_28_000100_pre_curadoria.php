<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro da PRÉ-CURADORIA — os ajustes feitos num bairro importado enquanto
 * ele está em revisão.
 *
 * Eles não vão para o Histórico do cadastro: esse histórico é a lista do que o
 * curador pode desfazer no cadastro que todos veem, e um lote que ainda não foi
 * publicado não faz parte dele. Mas também não somem — ficam na mesma tabela
 * de auditoria, com `tabela = 'lotes_em_revisao'` (fora do filtro do
 * histórico, que lê só 'lotes') e amarrados à importação por esta coluna, que
 * é o que a ficha da importação lê para mostrar o que foi ajustado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auditoria', function (Blueprint $t) {
            $t->unsignedBigInteger('importacao_id')->nullable()->after('registro_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('auditoria', function (Blueprint $t) {
            $t->dropIndex(['importacao_id']);
            $t->dropColumn('importacao_id');
        });
    }
};
