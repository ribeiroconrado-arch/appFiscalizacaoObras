<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A carga do cadastro passa a aceitar o JSON de diferenças gerado pelo app
 * desktop (ferramentas/cadastro-desktop), além da planilha .xlsx.
 *
 * `gravacao_iniciada_em` marca a hora em que a carga COMEÇOU A GRAVAR. O JSON
 * só vale sobre o banco exatamente como estava quando a referência foi tirada
 * (ver App\Cadastro\ReferenciaDoCadastro); essa conferência é feita antes da
 * primeira gravação. Uma carga que caiu no meio e é retomada já mexeu no
 * banco por conta própria — a conferência não bateria mais, e não precisa:
 * o que ela gravou tem hash novo e passa a contar como igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cadastro_cargas', function (Blueprint $t) {
            $t->timestamp('gravacao_iniciada_em')->nullable()->after('iniciada_em');
        });
    }

    public function down(): void
    {
        Schema::table('cadastro_cargas', function (Blueprint $t) {
            $t->dropColumn('gravacao_iniciada_em');
        });
    }
};
