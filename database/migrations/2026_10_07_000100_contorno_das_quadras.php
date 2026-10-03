<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O CONTORNO de cada quadra, gerado junto com o do bairro.
 *
 * A tabela `quadras` existe desde a primeira migração, vazia, à espera de
 * polígono. Agora ela recebe o que o navegador do curador calcula ao gerar o
 * contorno do bairro (public/js/bairros-contorno.js): a união dos lotes de cada
 * quadra. É o que o mapa desenha na escala do bairro — contorno e número das
 * quadras, sem as milhares de linhas de lote.
 *
 * Recriada em vez de alterada (estava vazia, nunca foi escrita):
 *  - MULTIPOLYGON no lugar de POLYGON: quadra cortada por rua com o mesmo
 *    número (as tiras compridas do Buritis V) é um desenho em pedaços;
 *  - `bairro` em texto, a mesma chave de `lotes.bairro` e de `bairros.nome`
 *    (o bairro_id continua, preenchido ao gravar);
 *  - o ponto do rótulo vem pronto: dentro da quadra, mesmo em quadra em L,
 *    onde o centro geométrico cairia na rua.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS quadras');
        DB::statement(<<<'SQL'
            CREATE TABLE quadras (
                id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bairro_id      BIGINT UNSIGNED NULL,
                bairro         VARCHAR(120)    NOT NULL,
                numero         VARCHAR(20)     NOT NULL,
                geom           MULTIPOLYGON    NOT NULL SRID 4326,
                rotulo_lat     DECIMAL(10,7)   NOT NULL,
                rotulo_lon     DECIMAL(10,7)   NOT NULL,
                lotes_contados INT UNSIGNED    NOT NULL DEFAULT 0,
                created_at     TIMESTAMP       NULL,
                updated_at     TIMESTAMP       NULL,
                SPATIAL INDEX idx_quadras_geom (geom),
                UNIQUE KEY uq_quadras_bairro_numero (bairro, numero),
                INDEX idx_quadras_bairro (bairro_id),
                CONSTRAINT fk_quadras_bairro FOREIGN KEY (bairro_id) REFERENCES bairros(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS quadras');
        DB::statement(<<<'SQL'
            CREATE TABLE quadras (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bairro_id  BIGINT UNSIGNED NULL,
                numero     VARCHAR(20)     NULL,
                geom       POLYGON         NOT NULL SRID 4326,
                created_at TIMESTAMP       NULL,
                updated_at TIMESTAMP       NULL,
                SPATIAL INDEX idx_quadras_geom (geom),
                INDEX idx_quadras_bairro (bairro_id),
                CONSTRAINT fk_quadras_bairro FOREIGN KEY (bairro_id) REFERENCES bairros(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }
};
