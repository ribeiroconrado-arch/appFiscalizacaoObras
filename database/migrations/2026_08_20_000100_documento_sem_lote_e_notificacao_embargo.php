<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Duas mudanças no cadastro de documentos.
 *
 * 1. `lote_id` passa a aceitar nulo. O documento nasce antes de o imóvel estar
 *    identificado: o fiscal abre a peça em campo, com o que tem, e amarra a
 *    inscrição imobiliária depois. Exigir o imóvel na criação obrigava a
 *    passar pelo mapa — que é justamente o caminho pago — só para começar a
 *    escrever. A obrigatoriedade continua existindo, mas na LAVRATURA, que é
 *    onde o documento vira ato: auto sem imóvel identificado não se sustenta.
 *
 * 2. `tipo` ganha `notificacao_embargo`. Entre a notificação comum e o auto de
 *    embargo existe a peça que ADVERTE sobre o embargo iminente — ela tem
 *    prazo de cumprimento, como notificação, mas anuncia a paralisação.
 *
 * O enum mantém `termo_advertencia`, que saiu da lista oferecida mas pode
 * existir em documento histórico.
 */
return new class extends Migration
{
    private const TIPOS_NOVO = "'vistoria','notificacao','notificacao_embargo','auto_infracao','auto_embargo','termo_advertencia'";
    private const TIPOS_ANTIGO = "'vistoria','notificacao','auto_infracao','auto_embargo','termo_advertencia'";

    public function up(): void
    {
        // ALTER cru: o Doctrine não enxerga ENUM do MySQL, e o Schema Builder
        // do Laravel recria a coluna — o que perderia os dados.
        DB::statement('ALTER TABLE documentos MODIFY tipo ENUM(' . self::TIPOS_NOVO . ") NOT NULL");
        DB::statement('ALTER TABLE documentos MODIFY lote_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Documento sem lote impede voltar a NOT NULL: em vez de falhar no
        // meio do rollback, o registro órfão é apagado — ele só existe como
        // rascunho, e rascunho sem imóvel não é peça de processo.
        DB::table('documentos')->whereNull('lote_id')->delete();
        DB::table('documentos')->where('tipo', 'notificacao_embargo')->update(['tipo' => 'notificacao']);

        DB::statement('ALTER TABLE documentos MODIFY lote_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE documentos MODIFY tipo ENUM(' . self::TIPOS_ANTIGO . ") NOT NULL");
    }
};
