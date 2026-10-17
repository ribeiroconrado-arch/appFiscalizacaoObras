<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDIÇÃO DO CONTORNO DO BAIRRO À MÃO — vértice a vértice, como no AutoCAD.
 *
 * O contorno gerado pelo raio deixa lacunas entre bairros vizinhos; o curador
 * as fecha puxando vértices (ou "encostando" um lado no vizinho). Regras em
 * App\Services\EdicaoDeContorno.
 *
 *   editado_manual   o contorno foi mexido à mão: gerar de novo pelo raio
 *                    apagaria a edição, e por isso pede confirmação.
 *   bairro_contornos_anteriores
 *                    o contorno de antes de cada edição (e de cada geração que
 *                    sobrescreveu uma edição): é o que o "Desfazer" devolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bairros', function (Blueprint $t) {
            $t->boolean('editado_manual')->default(false)->after('isolados');
            $t->timestamp('editado_em')->nullable()->after('editado_manual');
            $t->foreignId('editado_por')->nullable()->after('editado_em')->constrained('users')->nullOnDelete();
        });

        Schema::create('bairro_contornos_anteriores', function (Blueprint $t) {
            $t->id();
            $t->string('bairro', 120)->index();
            $t->longText('geojson');
            $t->boolean('era_editado')->default(false);
            // 'edicao' (antes de gravar uma edição) ou 'geracao' (antes de
            // gerar de novo por cima de uma edição).
            $t->string('motivo', 20);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bairro_contornos_anteriores');
        Schema::table('bairros', function (Blueprint $t) {
            $t->dropConstrainedForeignId('editado_por');
            $t->dropColumn(['editado_manual', 'editado_em']);
        });
    }
};
