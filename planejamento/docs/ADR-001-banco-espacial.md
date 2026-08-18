# ADR-001 — Banco espacial: MySQL 8 Spatial

**Data:** 16/08/2026 · **Situação:** aceita

## Contexto

O plano deixou a escolha do banco espacial para depois do diagnóstico da base
cartográfica, com critério objetivo definido de antemão:

> Se os lotes fecharem limpos e a tolerância de GPS resolver com envelope +
> distância → **MySQL 8 Spatial**. Se aparecer necessidade de correção
> topológica no banco, buffers reais, `ST_Subdivide` ou vector tiles servidas
> pelo banco → **PostgreSQL + PostGIS**.

## Decisão

**MySQL 8 Spatial**, como no documento original do projeto.

## Justificativa — o que o diagnóstico mostrou

1. **Os lotes chegam prontos.** Os 2.225 lotes do piloto já são polígonos
   fechados e válidos na origem (`EUROPA IV.dwg`, `BURITIS V.dwg`), com número e
   quadra. Não há correção topológica a fazer *no banco* — a poligonização dos
   demais bairros acontece no QGIS, antes da importação.
2. **A tolerância de GPS resolve sem `ST_DWithin`.** O padrão envelope
   (`MBRIntersects`, que usa o índice espacial) + `ST_Distance_Sphere` para
   ordenar cobre o fluxo do §9 do projeto por completo. Está implementado e
   testado no protótipo (`web/js/geo.js`) e documentado em `sql/001_gis.sql`.
3. **Volume modesto.** 23.662 lotes no município inteiro. Uma tabela com índice
   espacial e consulta por bbox atende com folga; não há caso para vector tiles
   servidas pelo banco.
4. **Hospedagem.** MySQL é o que a prefeitura tipicamente já opera, e o
   documento original do projeto o adotava. Escolher PostGIS criaria uma
   dependência de infraestrutura nova sem ganho correspondente.

## Consequências — limitações aceitas e como cada uma é contornada

| Limitação do MySQL 8 | Contorno adotado |
|---|---|
| Não existe `ST_DWithin` | Envelope com `MBRIntersects` + `ST_Distance` para ordenar |
| **`ST_Centroid`, `ST_Envelope` e `ST_Buffer` NÃO são implementados para SRS geográfico** — levantam `ERROR 3618` em tempo de execução | Usar `ST_Distance(polígono, ponto)`, que funciona em SRS geográfico e devolve **metros até a divisa** — medida melhor que a distância ao centroide, aliás. Confirmado em 17/08/2026 contra o MySQL 8.0.46 |
| `ST_Distance_Sphere` só aceita `Point`/`MultiPoint` | Idem acima: `ST_Distance` cobre polígono × ponto |
| `ST_Transform` só converte entre SRSs geográficos | A reprojeção 31981 → 4326 acontece no QGIS/Python, na geração do GeoJSON — nunca no banco |
| SRID 4326 usa ordem **lat/long** | Todo WKT declara `'axis-order=long-lat'`. É a armadilha mais perigosa: erra em silêncio, sem lançar erro |
| `SPATIAL INDEX` exige `NOT NULL` + SRID declarado | Colunas `geom` declaradas assim desde a criação |

## Como reverter, se um dia for preciso

Todo SQL espacial vive em **um único arquivo**, `app/Repositories/LoteRepository.php`.
Migrar para PostGIS significa reescrever esse arquivo e as migrations — não o
resto da aplicação. O gatilho para reconsiderar seria: necessidade de correção
topológica no banco, mapas temáticos pesados com agregação espacial, ou a base
crescer a ponto de exigir vector tiles.

## Registro relacionado

- CRS de origem confirmado: **SIRGAS 2000 / UTM 21S (EPSG:31981)** —
  [etapa1-georreferenciamento.md](etapa1-georreferenciamento.md)
- Armazenamento em **EPSG:4326**, para consumo direto pelo Leaflet
