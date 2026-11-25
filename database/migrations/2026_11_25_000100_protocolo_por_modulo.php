<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROTOCOLO POR MÓDULO (25/11/2026).
 *
 * Posturas passa a registrar protocolos e DENÚNCIAS GERAIS (as que não são de
 * perturbação do sossego — essas continuam na tela Denúncias). Os dois usam o
 * mesmo formulário do protocolo de Obras, com o número do protocolo/processo,
 * a data e as informações básicas — pedido dele: "informações triviais a
 * princípio".
 *
 *  - `modulo`: de qual fiscalização é. Os que existem são de Obras.
 *  - `local`:  onde é, em texto. Em Posturas quase nunca há lote (calçada,
 *              via, comércio), e sem o lugar o protocolo não diz aonde ir.
 *  - `requerente_nome` passa a aceitar vazio: denúncia pode ser anônima. Em
 *    Obras ele continua obrigatório — quem exige é o ProtocoloController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocolos', function (Blueprint $t) {
            $t->string('modulo', 20)->default('obras')->after('id');
            $t->string('local', 255)->nullable()->after('lote_id');
            $t->index(['modulo', 'situacao']);
        });
        Schema::table('protocolos', function (Blueprint $t) {
            $t->string('requerente_nome', 160)->nullable()->change();
            // Era um enum com os tipos de Obras: os de Posturas (requerimento,
            // denúncia) não cabiam. A lista válida mora em Protocolo::tiposDo,
            // e quem confere é o ProtocoloController — como nos demais tipos
            // do sistema.
            $t->string('tipo', 40)->change();
        });
    }

    public function down(): void
    {
        // Os de Posturas não cabem no esquema antigo.
        \Illuminate\Support\Facades\DB::table('protocolos')->where('modulo', '<>', 'obras')->delete();
        Schema::table('protocolos', function (Blueprint $t) {
            $t->enum('tipo', ['habite_se', 'vistoria_calcada', 'contestacao_area', 'renovacao_alvara',
                'desmembramento', 'unificacao', 'fracao_ideal', 'outro'])->change();
        });
        Schema::table('protocolos', function (Blueprint $t) {
            $t->dropIndex(['modulo', 'situacao']);
            $t->dropColumn(['modulo', 'local']);
        });
    }
};
