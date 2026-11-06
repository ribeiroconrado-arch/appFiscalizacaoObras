<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bairro, quadra e lote do cadastro SEM os zeros à esquerda, em colunas
 * geradas e indexadas (CadastroCarregado::linhasDoLote).
 *
 * A busca comparava `TRIM(LEADING '0' FROM ...)` direto nas colunas, e coluna
 * dentro de função não usa índice: cada balão e cada aba BCI varriam a tabela
 * inteira, ~320 ms por lote na produção (06/11/2026). Um índice funcional
 * sobre a mesma expressão foi tentado e o MySQL não o usou (a coluna oculta
 * sai com outra collation). As colunas geradas têm a collation da tabela, e a
 * consulta passa a ler delas. São calculadas pelo banco: a carga não muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE cadastro_externo_imoveis
            ADD COLUMN sz_bairro VARCHAR(10) COLLATE utf8mb4_unicode_ci
                GENERATED ALWAYS AS (TRIM(LEADING '0' FROM codigo_bairro)) STORED,
            ADD COLUMN sz_quadra VARCHAR(10) COLLATE utf8mb4_unicode_ci
                GENERATED ALWAYS AS (TRIM(LEADING '0' FROM quadra)) STORED,
            ADD COLUMN sz_lote VARCHAR(12) COLLATE utf8mb4_unicode_ci
                GENERATED ALWAYS AS (TRIM(LEADING '0' FROM lote)) STORED,
            ADD INDEX cad_imoveis_lote_sem_zero (sz_bairro, sz_quadra, sz_lote)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cadastro_externo_imoveis
            DROP INDEX cad_imoveis_lote_sem_zero,
            DROP COLUMN sz_bairro, DROP COLUMN sz_quadra, DROP COLUMN sz_lote');
    }
};
