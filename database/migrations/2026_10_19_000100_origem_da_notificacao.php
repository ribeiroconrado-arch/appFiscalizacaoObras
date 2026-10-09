<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DE ONDE VEIO A NOTIFICAÇÃO.
 *
 * O auto nasce de outra peça (`origem_id`: a notificação ou o embargo que veio
 * antes). A notificação é o começo da cadeia, mas também tem origem — só que
 * não é um documento: é o que levou o fiscal até a obra.
 *
 *   direta          vistoria em campo, por iniciativa da fiscalização;
 *   ordem_servico   determinada numa ordem de serviço (`origem_os_id`);
 *   ouvidoria       denúncia recebida pela ouvidoria (`origem_referencia`
 *                   guarda o número da denúncia).
 *
 * É o ÚNICO dado da peça que continua editável depois da lavratura (além dos
 * anexos): a origem é informação de processo, descoberta ou corrigida depois,
 * e não conteúdo do ato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->enum('origem_motivo', ['direta', 'ordem_servico', 'ouvidoria'])->nullable()->after('origem_id');
            $t->foreignId('origem_os_id')->nullable()->after('origem_motivo')->constrained('ordens_servico')->nullOnDelete();
            $t->string('origem_referencia', 80)->nullable()->after('origem_os_id');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('origem_os_id');
            $t->dropColumn(['origem_motivo', 'origem_referencia']);
        });
    }
};
