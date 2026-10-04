<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endereço do autuado e do imóvel EM PARTES, como o formulário passou a pedir:
 * logradouro, número, bairro, cidade e UF (autuado); logradouro e número
 * (imóvel).
 *
 * As colunas de texto único (`autuado_endereco`, `endereco`) CONTINUAM, e são
 * montadas a partir das partes na gravação: é delas que a impressão, a lista e
 * as peças antigas leem. As partes ficam em JSON porque só o formulário as
 * usa — não são filtro nem coluna de lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->json('autuado_endereco_partes')->nullable()->after('autuado_endereco');
            $t->json('imovel_endereco_partes')->nullable()->after('endereco');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn(['autuado_endereco_partes', 'imovel_endereco_partes']);
        });
    }
};
