<?php

use App\Support\NumeroDoLote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "4" e "04" passam a ser o MESMO lote (App\Support\NumeroDoLote).
 *
 * 1. Duas grafias do mesmo número já convivendo na mesma quadra (na produção,
 *    três pares, todos em importação em revisão) recebem o tratamento do
 *    número repetido na importação: ficam SEM número e entram na lista de
 *    repetidos da ficha da importação, para o curador decidir no mapa qual é
 *    qual. Escolher um aqui seria chute. Lote publicado mantém o número; se
 *    houver dois publicados no mesmo caso, a migração para e diz quais.
 * 2. Todo número com zero à esquerda é regravado sem ele ("04" → "4",
 *    "0004A" → "4A"), e a chave de integração acompanha. É só grafia: não
 *    entra na trilha de auditoria.
 * 3. A coluna gerada `chave_identidade` (índice único) passa a comparar o
 *    número sem zero à esquerda — a garantia fica no banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = NumeroDoLote::SQL;

        DB::transaction(function () use ($sql) {
            $grupos = DB::select("SELECT bairro, quadra, {$sql} AS n, GROUP_CONCAT(id ORDER BY id) AS ids
                                    FROM lotes
                                   WHERE situacao = 'ativo' AND quadra IS NOT NULL AND numero_lote IS NOT NULL
                                   GROUP BY bairro, quadra, n
                                  HAVING COUNT(*) > 1");

            $repetidos = [];   // importacao_id => list<item da lista de repetidos>
            foreach ($grupos as $g) {
                $lotes = DB::table('lotes')->whereIn('id', explode(',', $g->ids))
                    ->get(['id', 'quadra', 'numero_lote', 'em_revisao', 'importacao_id']);
                $publicados = $lotes->where('em_revisao', 0);
                if ($publicados->count() > 1) {
                    throw new RuntimeException("Lotes publicados com o mesmo número em grafias diferentes "
                        . "({$g->bairro}, quadra {$g->quadra}, lote {$g->n}): ids "
                        . $publicados->pluck('id')->implode(', ') . '. Renumere um deles antes de migrar.');
                }
                foreach ($lotes->where('em_revisao', 1) as $l) {
                    DB::update("UPDATE lotes SET numero_lote = NULL, chave = CONCAT(bairro, '|', quadra, '|?')
                                 WHERE id = ?", [$l->id]);
                    if ($l->importacao_id) {
                        $repetidos[$l->importacao_id][] = ['lote_id' => (int) $l->id, 'quadra' => $l->quadra,
                            'lote' => NumeroDoLote::normalizar($l->numero_lote)];
                    }
                }
            }

            foreach ($repetidos as $imp => $itens) {
                $conf = json_decode((string) DB::table('importacoes_lotes')->where('id', $imp)->value('conferencia'), true) ?: [];
                $conf['repetidos_lotes'] = array_merge($conf['repetidos_lotes'] ?? [], $itens);
                DB::table('importacoes_lotes')->where('id', $imp)
                    ->update(['conferencia' => json_encode($conf, JSON_UNESCAPED_UNICODE)]);
            }

            // A chave ANTES do número: o MySQL aplica o SET da esquerda para a
            // direita, e a comparação precisa do número ainda antigo.
            DB::update("UPDATE lotes
                           SET chave = IF(SUBSTRING_INDEX(chave, '|', -1) = numero_lote,
                                          CONCAT(LEFT(chave, CHAR_LENGTH(chave) - CHAR_LENGTH(numero_lote)), {$sql}),
                                          chave),
                               numero_lote = {$sql}
                         WHERE numero_lote REGEXP '^0+[0-9]'");
        });

        DB::statement("ALTER TABLE lotes MODIFY chave_identidade VARCHAR(200)
            GENERATED ALWAYS AS (
              CASE WHEN situacao = 'ativo' AND quadra IS NOT NULL AND numero_lote IS NOT NULL
                   THEN CONCAT(bairro, '|', quadra, '|', {$sql})
              END
            ) STORED");
    }

    public function down(): void
    {
        // Volta só a coluna: os números regravados ficam sem zero (não há
        // como saber qual tinha).
        DB::statement("ALTER TABLE lotes MODIFY chave_identidade VARCHAR(200)
            GENERATED ALWAYS AS (
              CASE WHEN situacao = 'ativo' AND quadra IS NOT NULL AND numero_lote IS NOT NULL
                   THEN CONCAT(bairro, '|', quadra, '|', numero_lote)
              END
            ) STORED");
    }
};
