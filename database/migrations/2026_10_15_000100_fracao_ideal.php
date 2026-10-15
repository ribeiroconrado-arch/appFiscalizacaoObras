<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FRAÇÃO IDEAL — um terreno, várias unidades, cada uma com seu dono.
 *
 * Parecido com o desmembramento, com uma diferença que muda tudo: o terreno
 * NÃO deixa de existir. Ele continua ativo ("terreno em condomínio") e as
 * unidades nascem DENTRO dele, cada uma com a inscrição do terreno mais a
 * variação 001, 002… — o mesmo que o cadastro da prefeitura já faz.
 *
 * A unidade é uma linha de `lotes`, e não uma tabela à parte, de propósito:
 * vistoria, documento, auto, multa, histórico e a aba BCI já apontam para um
 * lote. Unidade em tabela própria pediria refazer cada um deles.
 *
 *   terreno_id        a unidade aponta para o terreno (nulo no lote comum).
 *   fracao_ideal_m2   a fração do terreno que cabe à unidade, em m² — como
 *                     o cadastro grava (área do terreno da inscrição).
 *   unidade_desenhada a unidade tem área de uso exclusivo no chão (casa
 *                     geminada) ou não (apartamento). Sem desenho, a coluna
 *                     `geom` guarda o próprio terreno, porque ela é NOT NULL
 *                     (índice espacial) — e essa unidade fica fora do mapa e
 *                     das contas de vizinhança.
 *   condominio        no TERRENO: 'casas' (unidades individuais) ou 'predio'.
 *                     Decide a área da multa: no prédio vale a obra toda; nas
 *                     unidades individuais, a área individual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->foreignId('terreno_id')->nullable()->after('id')
                ->constrained('lotes')->restrictOnDelete();
            $t->decimal('fracao_ideal_m2', 12, 2)->nullable()->after('area_matricula_m2');
            $t->boolean('unidade_desenhada')->nullable()->after('fracao_ideal_m2');
            $t->enum('condominio', ['casas', 'predio'])->nullable()->after('unidade_desenhada');
        });

        DB::statement("ALTER TABLE lotes MODIFY origem
            ENUM('importacao','desenho','desmembramento','unificacao','fracao_ideal') NOT NULL");
        DB::statement("ALTER TABLE lote_atos MODIFY tipo
            ENUM('desmembramento','unificacao','fracao_ideal') NOT NULL");
        // O terreno entra no ato como REFERÊNCIA: participa, mas não é baixado.
        DB::statement("ALTER TABLE lote_ato_lotes MODIFY papel
            ENUM('anterior','posterior','terreno') NOT NULL");
        DB::statement("ALTER TABLE protocolos MODIFY tipo ENUM(
            'habite_se','vistoria_calcada','contestacao_area',
            'renovacao_alvara','desmembramento','unificacao','fracao_ideal','outro'
        ) NOT NULL");
    }

    public function down(): void
    {
        $emUso = DB::table('lotes')->whereNotNull('terreno_id')->count()
            + DB::table('protocolos')->where('tipo', 'fracao_ideal')->count();
        if ($emUso > 0) {
            throw new RuntimeException("Rollback recusado: {$emUso} unidade(s) ou protocolo(s) de fração ideal existem.");
        }

        DB::statement("ALTER TABLE protocolos MODIFY tipo ENUM(
            'habite_se','vistoria_calcada','contestacao_area',
            'renovacao_alvara','desmembramento','unificacao','outro'
        ) NOT NULL");
        DB::statement("ALTER TABLE lote_ato_lotes MODIFY papel ENUM('anterior','posterior') NOT NULL");
        DB::statement("ALTER TABLE lote_atos MODIFY tipo ENUM('desmembramento','unificacao') NOT NULL");
        DB::statement("ALTER TABLE lotes MODIFY origem
            ENUM('importacao','desenho','desmembramento','unificacao') NOT NULL");

        Schema::table('lotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('terreno_id');
            $t->dropColumn(['fracao_ideal_m2', 'unidade_desenhada', 'condominio']);
        });
    }
};
