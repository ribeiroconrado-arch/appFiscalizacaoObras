<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorização em DOIS eixos independentes, espelhando o AppPOSTURAS.
 *
 * Não é redundância: os fiscais são os mesmos servidores nos dois sistemas, e
 * divergir no modelo de acesso criaria duas matrizes de permissão para explicar
 * ao mesmo usuário.
 *
 *   perfil        = NÍVEL DE ACESSO — o que pode fazer no sistema
 *                   admin  · comum · viewer (exibido como "Visualizador")
 *
 *   tipo_usuario  = CARGO — o que a pessoa é na estrutura
 *                   agente · coordenador · secretario
 *
 * Regra herdada: só `tipo_usuario = 'agente'` pode ter perfil admin/comum;
 * qualquer outro cargo fica travado em `viewer`. No AppPOSTURAS isso é
 * garantido apenas no formulário; aqui vai também para o modelo, porque uma
 * regra de autorização que mora só na tela é uma regra que não existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('matricula', 30)->nullable()->after('email');
            $t->enum('perfil', ['admin', 'comum', 'viewer'])->default('viewer')->after('matricula');
            $t->enum('tipo_usuario', ['agente', 'coordenador', 'secretario'])->nullable()->after('perfil');
            $t->boolean('ativo')->default(true)->after('tipo_usuario');
            $t->timestamp('ultimo_acesso_em')->nullable()->after('ativo');

            $t->index('perfil');
            $t->unique('matricula');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['matricula']);
            $t->dropIndex(['perfil']);
            $t->dropColumn(['matricula', 'perfil', 'tipo_usuario', 'ativo', 'ultimo_acesso_em']);
        });
    }
};
