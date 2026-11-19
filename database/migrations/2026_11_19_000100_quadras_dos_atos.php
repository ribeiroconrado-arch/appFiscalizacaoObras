<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AS QUADRAS DE CADA ATO DO BAIRRO (19/11/2026).
 *
 * A regra dele: quadra sem marcação pertence à APROVAÇÃO do loteamento (o ato
 * de aprovação mais antigo — o "Aprovado por" do bairro). Loteamento de um
 * decreto só não precisa de nada; um decreto novo (ampliação, etapa) só marca
 * as quadras dele. Por isso esta tabela guarda apenas as EXCEÇÕES:
 *
 *  - papel `criou`:   a quadra nasceu de uma ampliação/etapa, não da
 *                     aprovação. Uma por quadra (garantido ao gravar).
 *  - papel `alterou`: regularização, retificação, alteração — mexeram na
 *                     quadra sem trocar quem a criou. Várias por quadra.
 *
 * A quadra é guardada pelo NÚMERO (sem zero à esquerda, NumeroDoLote), e não
 * pelo id de `quadras`: aquela tabela é apagada e refeita a cada contorno
 * gerado. O bairro vem pelo ato.
 *
 * O lote não recebe cópia: lê da quadra dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bairro_ato_quadras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bairro_ato_id')->constrained('bairro_atos')->cascadeOnDelete();
            $t->string('quadra', 20);
            $t->string('papel', 10);   // criou | alterou
            $t->timestamps();
            $t->unique(['bairro_ato_id', 'quadra']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bairro_ato_quadras');
    }
};
