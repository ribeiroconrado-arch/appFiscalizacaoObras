-- ══════════════════════════════════════════════════════════════
-- 001 — Esquema espacial (MySQL 8)
-- Projeto: Sistema Municipal de Fiscalização de Obras
-- CRS de armazenamento: EPSG:4326 (WGS84), que é o que o Leaflet consome.
--
-- A base municipal vem em SIRGAS 2000 / UTM 21S (EPSG:31981) e é reprojetada
-- para 4326 ANTES de chegar aqui, no `gis/tools/dxf_para_geojson.py`. Isso é
-- deliberado: o `ST_Transform` do MySQL só converte entre SRSs geográficos,
-- então projetado → geográfico não pode ser feito no banco. Ver
-- docs/ADR-001-banco-espacial.md.
--
-- ⚠ ARMADILHA DE ORDEM DOS EIXOS ⚠
-- Em SRID 4326 o MySQL usa a ordem lat/long, não long/lat. Toda leitura de WKT
-- ou GeoJSON PRECISA declarar a ordem, senão os lotes vão parar no oceano
-- Índico sem nenhum erro:
--     ST_GeomFromText(@wkt,     4326, 'axis-order=long-lat')
--     ST_GeomFromGeoJSON(@json, 1, 4326)
-- ══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── LOTES ─────────────────────────────────────────────────────
-- Tabela central do MVP-1. `bairro` e `quadra` ficam denormalizados de
-- propósito: a base cartográfica ainda não tem polígono de bairro nem de
-- quadra (ver docs/etapa0-conclusoes.md), e criar tabelas-pai vazias só para
-- respeitar a forma normal atrapalharia a importação sem dar nada em troca.
-- Viram chave estrangeira quando existirem os polígonos.
CREATE TABLE lotes (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    bairro                VARCHAR(120)     NOT NULL,
    quadra                VARCHAR(20)      NULL,
    numero_lote           VARCHAR(20)      NULL,

    -- Chave de integração com o cadastro imobiliário. O DWG NÃO traz inscrição
    -- imobiliária, então a identidade do lote é a tripla bairro+quadra+lote.
    -- Ainda NÃO é UNIQUE: ~5% dos lotes do piloto repetem a chave por erro de
    -- atribuição de quadra (docs/etapa1-base-piloto.md). Vira UNIQUE quando os
    -- polígonos de quadra existirem — e é justamente o índice que vai provar
    -- que o problema acabou.
    chave                 VARCHAR(180)     NOT NULL,

    -- Preenchida na Etapa 4, ao casar com o cadastro da prefeitura.
    inscricao_imobiliaria VARCHAR(50)      NULL,

    area_gis_m2           DECIMAL(12,2)    NULL,
    fonte                 VARCHAR(180)     NULL,  -- DWG de origem, para auditoria

    -- SRID declarado e NOT NULL são exigência do índice espacial do MySQL.
    geom                  POLYGON          NOT NULL SRID 4326,

    created_at            TIMESTAMP        NULL,
    updated_at            TIMESTAMP        NULL,

    SPATIAL INDEX idx_lotes_geom (geom),
    INDEX idx_lotes_chave     (chave),
    INDEX idx_lotes_bairro    (bairro),
    INDEX idx_lotes_inscricao (inscricao_imobiliaria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── BAIRROS ───────────────────────────────────────────────────
-- Populada por dissolve das quadras quando elas existirem. Criada agora para
-- que a API de mapa tenha o que devolver em zoom baixo, no lugar de despejar
-- 23 mil polígonos de lote no celular do fiscal.
CREATE TABLE bairros (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo     VARCHAR(30)   NULL,
    nome       VARCHAR(120)  NOT NULL,
    geom       MULTIPOLYGON  NOT NULL SRID 4326,
    created_at TIMESTAMP     NULL,
    updated_at TIMESTAMP     NULL,
    SPATIAL INDEX idx_bairros_geom (geom),
    UNIQUE KEY uq_bairros_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── QUADRAS ───────────────────────────────────────────────────
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ══════════════════════════════════════════════════════════════
-- CONSULTAS DE REFERÊNCIA
-- Vão para app/Repositories/LoteRepository.php. Ficam aqui documentadas
-- porque é nelas que moram as limitações do MySQL Spatial.
-- ══════════════════════════════════════════════════════════════

-- ── 1. GPS → LOTE (acerto direto) ─────────────────────────────
-- Usa o índice espacial via ST_Contains.
--
--   SET @p = ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326,
--                            'axis-order=long-lat');   -- (lon, lat)
--   SELECT id, bairro, quadra, numero_lote, chave, area_gis_m2
--     FROM lotes
--    WHERE ST_Contains(geom, @p)
--    LIMIT 1;

-- ── 2. GPS → LOTES PRÓXIMOS (quando não há acerto direto) ─────
-- O MySQL NÃO tem ST_DWithin, e ST_Buffer não aceita SRID geográfico. A
-- tolerância se faz em duas etapas: um envelope (que usa o índice espacial)
-- para reduzir o universo, e ST_Distance_Sphere para ordenar de verdade.
-- Fazer só o ST_Distance_Sphere seria correto e lentíssimo: varredura total.
--
--   SET @tol = GREATEST(?, 25);                    -- accuracy do GPS, piso 25 m
--   SET @dlat = @tol / 111320;
--   SET @dlon = @tol / (111320 * COS(RADIANS(?))); -- ? = latitude
--   SET @env = ST_GeomFromText(CONCAT('POLYGON((',
--       @lon-@dlon,' ',@lat-@dlat,',', @lon+@dlon,' ',@lat-@dlat,',',
--       @lon+@dlon,' ',@lat+@dlat,',', @lon-@dlon,' ',@lat+@dlat,',',
--       @lon-@dlon,' ',@lat-@dlat,'))'), 4326, 'axis-order=long-lat');
--
--   SELECT id, bairro, quadra, numero_lote, chave,
--          ST_Distance_Sphere(ST_Centroid(geom), @p) AS dist_m
--     FROM lotes
--    WHERE MBRIntersects(geom, @env)
--    ORDER BY dist_m
--    LIMIT 6;

-- ── 3. MAPA POR BBOX ──────────────────────────────────────────
-- A API nunca devolve a cidade inteira: só o que está na tela. Sem isso, o
-- celular do fiscal trava ao carregar 23 mil polígonos.
--
--   SELECT id, chave, quadra, numero_lote, area_gis_m2,
--          ST_AsGeoJSON(geom) AS geojson
--     FROM lotes
--    WHERE MBRIntersects(geom, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))
--    LIMIT 3000;

-- ── 4. CONFERÊNCIA PÓS-IMPORTAÇÃO ─────────────────────────────
-- Rodar sempre depois de importar. Um SRID errado não dá erro: dá mapa vazio.
--
--   SELECT COUNT(*) AS total,
--          SUM(ST_SRID(geom) <> 4326)  AS srid_errado,
--          SUM(NOT ST_IsValid(geom))   AS geometria_invalida,
--          COUNT(DISTINCT chave)       AS chaves_distintas
--     FROM lotes;
