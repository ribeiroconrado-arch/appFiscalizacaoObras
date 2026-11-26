<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A VISTORIA GUIADA LIGADA AO RESTO DO SISTEMA (26/11/2026).
 *
 * A guiada substitui a vistoria de obra. Coluna NOVA, e não a `vistoria_id`
 * que já existe: durante a troca as duas vistorias convivem, e a coluna antiga
 * tem chave estrangeira para `vistorias`. Quando a antiga sair, sai também a
 * coluna dela.
 *
 *   documentos.vg_vistoria_id    a vistoria que fundamenta a peça: fotos,
 *                                artigos citados, área e exigências;
 *   protocolos.vg_vistoria_id    a vistoria que atende o protocolo (ato
 *                                cadastral só com vistoria Regular);
 *   sinalizacoes.vg_vistoria_id  a vistoria que resolveu a sinalização;
 *   vg_vistorias.sinalizacao_id  a sinalização de que a vistoria nasceu
 *                                (a de rua não tem lote a que se ligar).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['documentos', 'protocolos', 'sinalizacoes'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->foreignId('vg_vistoria_id')->nullable()->after('vistoria_id')
                    ->constrained('vg_vistorias')->nullOnDelete();
            });
        }
        Schema::table('vg_vistorias', function (Blueprint $t) {
            $t->foreignId('sinalizacao_id')->nullable()->after('descricao')
                ->constrained('sinalizacoes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vg_vistorias', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sinalizacao_id');
        });
        foreach (['documentos', 'protocolos', 'sinalizacoes'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->dropConstrainedForeignId('vg_vistoria_id');
            });
        }
    }
};
