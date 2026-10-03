<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O endereço DOMICILIAR do autuado, separado do endereço da obra.
 *
 * `documentos.endereco` é o do imóvel fiscalizado. O autuado nem sempre mora
 * lá — obra em terreno vazio é o caso comum —, e é no domicílio dele que a
 * peça é entregue ou enviada. Sem o campo, o endereço de correspondência do
 * cadastro municipal não tinha onde ficar na peça.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->string('autuado_endereco', 300)->nullable()->after('autuado_documento');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn('autuado_endereco');
        });
    }
};
