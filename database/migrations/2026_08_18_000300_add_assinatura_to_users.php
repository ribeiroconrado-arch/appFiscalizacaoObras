<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura do agente, desenhada uma vez no perfil e reaproveitada na
 * lavratura.
 *
 * Diferente da assinatura do AUTUADO, que é capturada no próprio documento e
 * nunca se repete (§17 do projeto): aquela prova que a pessoa recebeu aquele
 * papel naquele momento. A do agente é a rubrica dele — pedir que redesenhe a
 * cada documento só produziria assinaturas diferentes entre si, o que
 * enfraquece justamente o que a assinatura deveria demonstrar.
 *
 * Guardada como data URL de PNG (canvas), no banco e não em disco: é um dado
 * pessoal pequeno, sempre lido junto do usuário, e um arquivo solto no
 * storage seria mais uma coisa para sincronizar em backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->longText('assinatura')->nullable()->after('tipo_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('assinatura');
        });
    }
};
