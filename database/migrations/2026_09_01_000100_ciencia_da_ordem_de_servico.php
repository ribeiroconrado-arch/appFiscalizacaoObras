<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ciência do fiscal na ordem de serviço.
 *
 * Uma ordem que ninguém confirma ter recebido não delega: na hora de cobrar,
 * a resposta é "não fiquei sabendo", e não há como distinguir isso de "fiquei
 * e não fiz". A assinatura fica na LIGAÇÃO (os_fiscais) e não na ordem porque
 * cada designado dá ciência por si — três fiscais na mesma OS podem tomar
 * conhecimento em três momentos, e um pode nunca tomar.
 *
 * A imagem é copiada para cá em vez de lida do perfil na hora de imprimir:
 * a assinatura do usuário pode mudar depois, e a via impressa tem de continuar
 * mostrando o traço que ele usou NAQUELE dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_fiscais', function (Blueprint $t) {
            $t->timestamp('ciencia_em')->nullable()->after('user_id');
            $t->longText('assinatura')->nullable()->after('ciencia_em');
        });
    }

    public function down(): void
    {
        Schema::table('os_fiscais', function (Blueprint $t) {
            $t->dropColumn(['ciencia_em', 'assinatura']);
        });
    }
};
