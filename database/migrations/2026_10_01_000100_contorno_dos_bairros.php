<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O CONTORNO de cada bairro, derivado dos lotes.
 *
 * A tabela `bairros` existe desde a primeira migração, com geometria e índice
 * espacial, justamente para o mapa afastado não precisar dos milhares de lotes —
 * mas nunca foi preenchida. Agora ela recebe o contorno calculado no navegador
 * do curador (BairroContornoController): união dos lotes + fechamento de R
 * metros, que cobre as ruas entre as quadras e mantém a borda do loteamento.
 *
 * O que entra aqui é o que o sistema precisa para dizer se o contorno ainda
 * vale: com quantos lotes e quando ele foi feito. "Desatualizado" é calculado
 * na leitura, comparando isso com os lotes de hoje — nenhuma ferramenta da
 * curadoria precisa lembrar de avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bairros', function (Blueprint $t) {
            $t->decimal('raio_m', 6, 2)->nullable()->after('geom');
            $t->unsignedInteger('lotes_contados')->default(0)->after('raio_m');
            $t->timestamp('contorno_em')->nullable()->after('lotes_contados');
            $t->foreignId('gerado_por')->nullable()->after('contorno_em')->constrained('users')->nullOnDelete();
            // Lotes que ficaram FORA do contorno por estarem isolados (ex.: coordenada corrompida).
            $t->json('isolados')->nullable()->after('gerado_por');
        });
    }

    public function down(): void
    {
        Schema::table('bairros', function (Blueprint $t) {
            $t->dropConstrainedForeignId('gerado_por');
            $t->dropColumn(['raio_m', 'lotes_contados', 'contorno_em', 'isolados']);
        });
    }
};
