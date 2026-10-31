<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice que COBRE a contagem de lotes por bairro (LoteRepository::situacaoDosBairros).
 *
 * Essa contagem roda a cada abertura do mapa, duas vezes (contorno dos bairros
 * e silhueta das quadras), e lia a linha inteira de cada lote — geometria
 * junto — só para contar: 361 ms em produção, com 35 mil lotes (31/10/2026).
 * Era a maior parte dos 2–3 s de tela branca antes do fundo aparecer. Com as
 * quatro colunas que ela usa no índice, o MySQL responde sem abrir a tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->index(['situacao', 'bairro', 'em_revisao', 'updated_at'], 'idx_lotes_situacao_bairro');
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->dropIndex('idx_lotes_situacao_bairro');
        });
    }
};
