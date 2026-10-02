<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RASCUNHO da importação — o arquivo carregado que ainda não foi salvo.
 *
 * O curador carrega o GeoJSON, faz a pré-curadoria e confere com o cadastro
 * ANTES de decidir se a importação vale. Enquanto é rascunho ela é só de quem
 * a carregou (e do administrador): não entra na trilha, não conta como "em
 * revisão" e, descartada, some sem deixar registro. Só ao SALVAR ela passa a
 * `revisao` — e daí em diante segue o ciclo de sempre: conferência, publicação
 * ou exclusão, tudo registrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE importacoes_lotes MODIFY status
            ENUM('rascunho', 'revisao', 'publicada', 'excluida') NOT NULL DEFAULT 'rascunho'");

        Schema::table('importacoes_lotes', function (Blueprint $t) {
            $t->foreignId('salvo_por')->nullable()->after('conferido_em')->constrained('users')->nullOnDelete();
            $t->timestamp('salvo_em')->nullable()->after('salvo_por');
        });

        // As que já existem foram gravadas direto em revisão: salvas no envio.
        DB::statement('UPDATE importacoes_lotes SET salvo_por = user_id, salvo_em = created_at');
    }

    public function down(): void
    {
        Schema::table('importacoes_lotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('salvo_por');
            $t->dropColumn('salvo_em');
        });
        DB::statement("DELETE FROM importacoes_lotes WHERE status = 'rascunho'");
        DB::statement("ALTER TABLE importacoes_lotes MODIFY status
            ENUM('revisao', 'publicada', 'excluida') NOT NULL DEFAULT 'revisao'");
    }
};
