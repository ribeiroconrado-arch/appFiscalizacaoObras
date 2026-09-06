<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O ALVARÁ TEM VALIDADE, E A VISTORIA PASSA A REGISTRAR QUANDO ELA ACABA.
 *
 * Até aqui a tela perguntava só "possui alvará?" e o número. Um alvará
 * vencido na data da vistoria é achado tão relevante quanto "não possui" —
 * e sem a data gravada, ninguém descobre isso relendo o processo depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vistorias', function (Blueprint $t) {
            $t->date('alvara_vencimento')->nullable()->after('alvara_numero');
        });
    }

    public function down(): void
    {
        Schema::table('vistorias', fn (Blueprint $t) => $t->dropColumn('alvara_vencimento'));
    }
};
