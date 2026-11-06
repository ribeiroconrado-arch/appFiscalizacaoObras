<?php

use App\Support\NumeroDoLote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "5" e "05" passam a ser a MESMA quadra (App\Support\NumeroDoLote), como
 * já é com o número do lote desde 2026_11_05_000100.
 *
 * Até aqui a base gravava a quadra com dois dígitos ("07"); agora, sem zero à
 * esquerda ("7"). Regrava `lotes.quadra` e a chave de integração, o número das
 * quadras desenhadas (`quadras.numero`, que sai dos lotes) e a coluna gerada
 * do índice único, que passa a comparar quadra e número normalizados. É só
 * grafia: não entra na trilha de auditoria.
 *
 * Duas grafias da mesma quadra com o mesmo lote ativo não existiam na
 * produção quando isto foi escrito; se aparecer, a migração para e diz quais.
 */
return new class extends Migration
{
    public function up(): void
    {
        $q = NumeroDoLote::SQL_QUADRA;
        $n = NumeroDoLote::SQL;

        $choques = DB::select("SELECT bairro, {$q} AS q, {$n} AS n, GROUP_CONCAT(id ORDER BY id) AS ids
                                 FROM lotes
                                WHERE situacao = 'ativo' AND quadra IS NOT NULL AND numero_lote IS NOT NULL
                                GROUP BY bairro, q, n HAVING COUNT(*) > 1");
        if ($choques) {
            throw new RuntimeException('Lotes ativos que viram o mesmo com a quadra sem zero: '
                . implode('; ', array_map(fn ($c) => "{$c->bairro} Q{$c->q} L{$c->n} (ids {$c->ids})", $choques)));
        }
        $quadrasRepetidas = DB::select("SELECT bairro, REGEXP_REPLACE(numero, '^0+([0-9])', '$1') AS q
                                          FROM quadras GROUP BY bairro, q HAVING COUNT(*) > 1");
        if ($quadrasRepetidas) {
            throw new RuntimeException('Quadras desenhadas com duas grafias: '
                . implode('; ', array_map(fn ($c) => "{$c->bairro} Q{$c->q}", $quadrasRepetidas)));
        }

        DB::transaction(function () use ($q) {
            // A chave ANTES da quadra (o SET vai da esquerda para a direita, e a
            // comparação precisa da quadra antiga). Só quando o pedaço do meio
            // da chave É a quadra: "bairro|quadra|lote".
            DB::update("UPDATE lotes
                           SET chave = IF(SUBSTRING_INDEX(SUBSTRING_INDEX(chave, '|', -2), '|', 1) = quadra,
                                          CONCAT(SUBSTRING_INDEX(chave, '|', CHAR_LENGTH(chave) - CHAR_LENGTH(REPLACE(chave, '|', '')) - 1),
                                                 '|', {$q}, '|', SUBSTRING_INDEX(chave, '|', -1)),
                                          chave),
                               quadra = {$q}
                         WHERE quadra REGEXP '^0+[0-9]'");

            DB::update("UPDATE quadras SET numero = REGEXP_REPLACE(numero, '^0+([0-9])', '$1')
                         WHERE numero REGEXP '^0+[0-9]'");
        });

        DB::statement("ALTER TABLE lotes MODIFY chave_identidade VARCHAR(200)
            GENERATED ALWAYS AS (
              CASE WHEN situacao = 'ativo' AND quadra IS NOT NULL AND numero_lote IS NOT NULL
                   THEN CONCAT(bairro, '|', {$q}, '|', {$n})
              END
            ) STORED");
    }

    public function down(): void
    {
        $n = NumeroDoLote::SQL;
        DB::statement("ALTER TABLE lotes MODIFY chave_identidade VARCHAR(200)
            GENERATED ALWAYS AS (
              CASE WHEN situacao = 'ativo' AND quadra IS NOT NULL AND numero_lote IS NOT NULL
                   THEN CONCAT(bairro, '|', quadra, '|', {$n})
              END
            ) STORED");
    }
};
