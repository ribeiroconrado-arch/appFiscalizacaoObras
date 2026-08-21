<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anulação de documento lavrado.
 *
 * A tabela já aceitava o status `anulado`, mas não guardava QUEM anulou, QUANDO
 * e POR QUÊ — e sem isso a anulação não é um ato administrativo, é só um
 * registro que mudou de valor. Um auto anulado continua no processo: a via
 * impressa sai com a marca "ANULADO" e o motivo tem de poder ser lido nela.
 *
 * Segue o mesmo desenho da anulação do AppPOSTURAS: motivo obrigatório, autor
 * registrado, e o documento nunca é apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->timestamp('anulado_em')->nullable()->after('status');
            $t->foreignId('anulado_por')->nullable()->after('anulado_em')->constrained('users');
            $t->text('anulacao_motivo')->nullable()->after('anulado_por');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('anulado_por');
            $t->dropColumn(['anulado_em', 'anulacao_motivo']);
        });
    }
};
