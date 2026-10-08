<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A TESTEMUNHA da recusa de assinatura.
 *
 * A lavratura passa a colher a assinatura do autuado na tela. Quando ele se
 * recusa, o ato só fica de pé com uma testemunha: o nome dela e a assinatura,
 * que saem no Termo de Recusa da peça impressa. As colunas da assinatura do
 * autuado e do registro da recusa já existiam (`assinatura_autuado`,
 * `recusa_assinatura`); faltava onde guardar a testemunha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->string('testemunha_nome', 160)->nullable()->after('recusa_assinatura');
            $t->longText('assinatura_testemunha')->nullable()->after('testemunha_nome');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', fn (Blueprint $t) => $t->dropColumn(['testemunha_nome', 'assinatura_testemunha']));
    }
};
