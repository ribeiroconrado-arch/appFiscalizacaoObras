<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail deixa de ser obrigatório para quem tem matrícula.
 *
 * O login já aceita matrícula ou e-mail (AuthController). Servidor de campo
 * nem sempre tem e-mail institucional, e exigir um inventava endereço só para
 * passar no formulário. A regra "um dos dois" fica em
 * ParametroController::salvarUsuario; aqui só a coluna aceita nulo.
 *
 * O índice único continua: no MySQL cada NULL é distinto, então vários
 * usuários sem e-mail convivem, e dois com o mesmo e-mail seguem recusados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('email')->nullable(false)->change();
        });
    }
};
