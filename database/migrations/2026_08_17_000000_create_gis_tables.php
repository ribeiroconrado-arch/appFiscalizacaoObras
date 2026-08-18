<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema espacial da base cartográfica municipal.
 *
 * As colunas de geometria são criadas por SQL cru, e não pelo Blueprint: o
 * construtor de esquema do Laravel não expressa `SRID 4326` na definição da
 * coluna, e sem SRID declarado o MySQL recusa criar o SPATIAL INDEX.
 *
 * CRS: as geometrias são armazenadas em EPSG:4326, que é o que o Leaflet
 * consome. A base vem em SIRGAS 2000 / UTM 21S (EPSG:31981) e é reprojetada
 * fora do banco, na geração do GeoJSON — o `ST_Transform` do MySQL só converte
 * entre SRSs geográficos, então projetado → geográfico não é possível aqui.
 * Ver docs/ADR-001-banco-espacial.md do projeto.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── BAIRROS ──────────────────────────────────────────────
        // Populada por dissolve das quadras quando elas existirem. Serve para a
        // API devolver contorno de bairro em zoom baixo, em vez de despejar
        // milhares de polígonos de lote no celular do fiscal.
        DB::statement(<<<'SQL'
            CREATE TABLE bairros (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                codigo     VARCHAR(30)   NULL,
                nome       VARCHAR(120)  NOT NULL,
                geom       MULTIPOLYGON  NOT NULL SRID 4326,
                created_at TIMESTAMP     NULL,
                updated_at TIMESTAMP     NULL,
                SPATIAL INDEX idx_bairros_geom (geom),
                UNIQUE KEY uq_bairros_nome (nome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // ── QUADRAS ──────────────────────────────────────────────
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

        // ── LOTES ────────────────────────────────────────────────
        // `bairro` e `quadra` ficam como texto de propósito: a base ainda não
        // tem polígono de bairro nem de quadra, e criar tabelas-pai vazias só
        // para respeitar a forma normal atrapalharia a importação sem dar nada
        // em troca. Viram chave estrangeira quando os polígonos existirem.
        //
        // `chave` (bairro|quadra|lote) é a identidade do lote: o DWG não traz
        // inscrição imobiliária. NÃO é UNIQUE ainda — ~5% dos lotes do piloto
        // repetem a chave por erro de atribuição de quadra. Tornar essa coluna
        // única é justamente o teste que vai provar que o problema acabou.
        DB::statement(<<<'SQL'
            CREATE TABLE lotes (
                id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bairro                VARCHAR(120)  NOT NULL,
                quadra                VARCHAR(20)   NULL,
                numero_lote           VARCHAR(20)   NULL,
                chave                 VARCHAR(180)  NOT NULL,
                inscricao_imobiliaria VARCHAR(50)   NULL,
                area_gis_m2           DECIMAL(12,2) NULL,
                fonte                 VARCHAR(180)  NULL,
                geom                  POLYGON       NOT NULL SRID 4326,
                created_at            TIMESTAMP     NULL,
                updated_at            TIMESTAMP     NULL,
                SPATIAL INDEX idx_lotes_geom (geom),
                INDEX idx_lotes_chave     (chave),
                INDEX idx_lotes_bairro    (bairro),
                INDEX idx_lotes_inscricao (inscricao_imobiliaria)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
        Schema::dropIfExists('quadras');
        Schema::dropIfExists('bairros');
    }
};
