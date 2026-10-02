<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONFERÊNCIA DO BAIRRO COM O CADASTRO — a que fica.
 *
 * A conferência nasceu dentro da importação, e lá ela some com a publicação.
 * Mas os problemas que ela acha (lote inativo no cadastro, imóvel sem lote,
 * número que não monta inscrição) não se resolvem todos antes de publicar:
 * vários dependem da prefeitura, de vistoria, de documento. Esta tabela guarda
 * a última conferência de cada bairro, para ela continuar no mapa como lista
 * de pendências (ConferenciaBairroController, conferencia-bairro.js).
 *
 * `conferencia_justificativas` é o "não dá para resolver agora": a pendência
 * sai da lista com um motivo e quem justificou — sem apagar nada. Ela vale
 * enquanto o item continuar aparecendo nas conferências seguintes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conferencias_bairro', function (Blueprint $t) {
            $t->id();
            // O nome do DESENHO (lotes.bairro): é por ele que o mapa e a
            // curadoria chegam ao bairro.
            $t->string('bairro', 160)->unique();
            $t->string('codigo_bairro', 10)->nullable();
            $t->json('resultado');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('conferido_em')->nullable();
            $t->timestamps();
        });

        Schema::create('conferencia_justificativas', function (Blueprint $t) {
            $t->id();
            $t->string('bairro', 160);
            // A inscrição formatada, ou "lote:<id>" para o lote sem inscrição.
            $t->string('chave', 60);
            // nao_encontrados | inativos | sem_lote | sem_inscricao
            $t->string('tipo', 30);
            $t->text('motivo');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['bairro', 'chave', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conferencia_justificativas');
        Schema::dropIfExists('conferencias_bairro');
    }
};
