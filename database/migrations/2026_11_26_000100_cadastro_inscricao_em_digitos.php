<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A inscrição do cadastro SÓ EM DÍGITOS, em coluna gerada e indexada, na
 * colação da tabela `lotes` (26/11/2026).
 *
 * Serve à Consulta de imóveis, que ordena por logradouro, número, área e
 * situação — dados do cadastro, casados com o lote pela inscrição
 * (BuscaController::ordenar). A coluna `inscricao` pode vir com pontos, e o
 * cadastro tem outra colação (utf8mb4_unicode_ci contra utf8mb4_0900_ai_ci
 * de `lotes`): comparar as duas direto obrigava a função dos dois lados, e o
 * MySQL varria o cadastro inteiro a cada lote. Aqui a coluna já sai limpa e
 * na colação de `lotes`, e a comparação usa o índice. Calculada pelo banco: a
 * carga mensal não muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE cadastro_externo_imoveis
            ADD COLUMN insc_digitos VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci
                GENERATED ALWAYS AS (REPLACE(REPLACE(inscricao, '.', ''), ' ', '')) STORED,
            ADD INDEX cad_imoveis_insc_digitos (insc_digitos)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cadastro_externo_imoveis
            DROP INDEX cad_imoveis_insc_digitos, DROP COLUMN insc_digitos');
    }
};
