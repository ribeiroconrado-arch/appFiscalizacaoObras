<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O CICLO DA PEÇA ganha dois estados:
 *
 *   gravado   — tem número, ainda não foi lavrado (edita e cancela)
 *   defendido — a defesa foi deferida: a peça não gera custa
 *
 * "anulado" era o nome do cancelamento; as peças antigas passam a
 * "cancelado", que é como o estado se chama daqui em diante. O valor continua
 * no enum para a migração poder ser desfeita.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE documentos MODIFY status ENUM('rascunho','gravado','lavrado','atendido','anulado','cancelado','defendido') NOT NULL DEFAULT 'rascunho'");
        DB::table('documentos')->where('status', 'anulado')->update(['status' => 'cancelado']);
    }

    public function down(): void
    {
        DB::table('documentos')->where('status', 'gravado')->update(['status' => 'rascunho']);
        DB::table('documentos')->where('status', 'defendido')->update(['status' => 'cancelado']);
        DB::statement("ALTER TABLE documentos MODIFY status ENUM('rascunho','lavrado','atendido','anulado','cancelado') NOT NULL DEFAULT 'rascunho'");
    }
};
