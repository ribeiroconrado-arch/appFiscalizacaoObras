<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O FORMULÁRIO DE POSTURAS (07/11/2026) — o que a notificação e os autos do
 * AppPOSTURAS pediam e a peça ainda não guardava:
 *
 *   autuado_inscricao_municipal  do contribuinte com CNPJ (aba Contribuinte);
 *   autuado_email                opcional, como lá;
 *   local_gps                    a posição do LOCAL da infração (lat, lng e
 *                                precisão) — em Posturas o ato é do local, não
 *                                do lote; vem do GPS na aba Local/Origem ou da
 *                                denúncia que originou a peça;
 *   lavratura_imediata           o inciso do art. 16 da Lei 723 que justifica
 *                                lavrar o auto sem notificação antes: o número
 *                                e o TEXTO copiado da lei (a peça imprime o
 *                                que valia no dia, mesmo que a lei mude).
 *
 * Só Posturas preenche; em Obras ficam nulos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->string('autuado_inscricao_municipal', 40)->nullable()->after('autuado_documento');
            $t->string('autuado_email', 160)->nullable()->after('autuado_inscricao_municipal');
            $t->json('local_gps')->nullable()->after('imovel_lote');
            $t->json('lavratura_imediata')->nullable()->after('apreensao_subtipo');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn(['autuado_inscricao_municipal', 'autuado_email', 'local_gps', 'lavratura_imediata']);
        });
    }
};
