<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem determina também assina.
 *
 * A via saía com o traço de quem RECEBEU a ordem e só o nome datilografado de
 * quem a DEU. Numa peça de coordenação isso é o lado que mais importa: é a
 * assinatura do superior que transforma um pedido em determinação, e é dela
 * que o fiscal se vale se depois perguntarem por que ele estava ali.
 *
 * Cópia e não referência, como na lavratura do documento (LavraturaService):
 * quem redesenhar a própria assinatura amanhã não reescreve o que já assinou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $t) {
            $t->longText('assinatura_emitente')->nullable()->after('emitida_por');
            $t->timestamp('assinada_em')->nullable()->after('assinatura_emitente');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $t) {
            $t->dropColumn(['assinatura_emitente', 'assinada_em']);
        });
    }
};
