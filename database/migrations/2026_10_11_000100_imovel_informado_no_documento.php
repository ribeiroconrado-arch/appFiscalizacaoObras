<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A identificação do imóvel COMO ESTÁ NA PEÇA.
 *
 * Inscrição, bairro, quadra e lote vinham sempre do lote do desenho. Agora o
 * formulário os traz do cadastro municipal quando o imóvel está lá e, quando
 * não está, deixa o fiscal informar à mão — e o que ele informa precisa de
 * onde ficar. Nulo = "o do lote", que é como estão todas as peças anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->string('imovel_inscricao', 30)->nullable()->after('endereco');
            $t->string('imovel_bairro', 160)->nullable()->after('imovel_inscricao');
            $t->string('imovel_quadra', 20)->nullable()->after('imovel_bairro');
            $t->string('imovel_lote', 20)->nullable()->after('imovel_quadra');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn(['imovel_inscricao', 'imovel_bairro', 'imovel_quadra', 'imovel_lote']);
        });
    }
};
