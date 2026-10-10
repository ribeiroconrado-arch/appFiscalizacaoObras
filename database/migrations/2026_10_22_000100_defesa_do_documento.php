<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DEFESA do auto, como no AppPOSTURAS.
 *
 * O estado "em_defesa" marca a peça com defesa protocolada e ainda não
 * julgada. O que foi protocolado e o que foi decidido ficam na coluna
 * `defesa` (JSON): protocolo, data, anexo; resultado, data, parecer, anexo do
 * julgamento; e quem registrou cada passo.
 *
 *   deferida   → a peça vira "defendido" e não gera custa
 *   indeferida → volta a "lavrado", agora APTA à cobrança
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE documentos MODIFY status ENUM('rascunho','gravado','lavrado','em_defesa','atendido','anulado','cancelado','defendido') NOT NULL DEFAULT 'rascunho'");
        Schema::table('documentos', function (Blueprint $t) {
            $t->json('defesa')->nullable()->after('defesa_ate');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn('defesa');
        });
        DB::table('documentos')->where('status', 'em_defesa')->update(['status' => 'lavrado']);
        DB::statement("ALTER TABLE documentos MODIFY status ENUM('rascunho','gravado','lavrado','atendido','anulado','cancelado','defendido') NOT NULL DEFAULT 'rascunho'");
    }
};
