<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QUEM DESENHOU A EDIFICAÇÃO, E POR QUÊ.
 *
 * O croqui passa a servir a várias demandas — vistoria, desmembramento,
 * unificação, atualização cadastral — e a ficha precisa dizer de qual delas
 * ele veio e quem o fez (pedido de 29/10/2026). Até aqui isso só existia na
 * trilha de auditoria, que ninguém abre para ler um croqui.
 *
 * As linhas que já existem herdam o autor da própria trilha (ação
 * "desenhou"): é o registro do que aconteceu, não um palpite. A origem delas
 * fica "atualizacao_cadastral" — era a única porta de entrada até hoje (a
 * ficha e a curadoria).
 *
 * As CARACTERÍSTICAS da construção ficam para depois: ele vai mandar a
 * planilha com os campos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edificacoes', function (Blueprint $t) {
            $t->foreignId('criado_por')->nullable()->after('descricao')->constrained('users')->nullOnDelete();
            $t->string('origem', 30)->default('atualizacao_cadastral')->after('criado_por');
            // Número do protocolo, do processo ou da vistoria que pediu o desenho.
            $t->string('origem_ref', 40)->nullable()->after('origem');
        });

        DB::statement("UPDATE edificacoes e
            JOIN auditoria a ON a.tabela = 'edificacoes' AND a.registro_id = e.id AND a.acao = 'desenhou'
            SET e.criado_por = a.user_id
            WHERE e.criado_por IS NULL AND a.user_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('edificacoes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('criado_por');
            $t->dropColumn(['origem', 'origem_ref']);
        });
    }
};
