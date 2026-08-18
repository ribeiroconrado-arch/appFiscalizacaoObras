# Bairro piloto — regioes ja organizadas no CAD

Arquivo: `base.dxf` · grade de 250 m

Uma **regiao** e um bloco contiguo de celulas que ja tem poligono de lote
fechado. **Lotes** = numeros de lote (`GIS_TEXTOS`) na regiao. **Prontos** =
poligonos fechados com area entre 150 e 800 m2. **Cobertura** = prontos/lotes.
**Bairro provavel** = layer de bairro com mais entidades na regiao.

## Regioes candidatas, por cobertura

| # | Bairro provavel | Lotes | Prontos | Cobertura | Linhas a poligonizar | Celulas | Area (ha) | Centro (x, y) |
|---:|---|---:|---:|---:|---:|---:|---:|---|
| 1 | `Diretrizes` | 4 | 1600 | **40000%** | 511 | 20 | 125.0 | -6975, 20388 |
| 2 | `(indefinido)` | 29 | 1016 | **3503%** | 922 | 20 | 125.0 | -3750, 21812 |
| 3 | `Pva4AmpliaÃ§Ã£o` | 17 | 17 | **100%** | 61 | 2 | 12.5 | -2000, 20125 |
| 4 | `CidadePva3` | 716 | 74 | **10%** | 599 | 7 | 43.8 | -5482, 21768 |

## Detalhe das 5 primeiras regioes

**Regiao 1 — `Diretrizes`** · 4 lotes · 1600 prontos (40000%) · 511 linhas · 125.0 ha
  Layers de bairro presentes: `Diretrizes` (7), `Matricula 22.997` (3), `BURITIS II EXP` (2), `BURITIS V - ÃREA VERDE` (1), `Torre Energia` (1)

**Regiao 2 — `(indefinido)`** · 29 lotes · 1016 prontos (3503%) · 922 linhas · 125.0 ha
  Layers de bairro presentes: 

**Regiao 3 — `Pva4AmpliaÃ§Ã£o`** · 17 lotes · 17 prontos (100%) · 61 linhas · 12.5 ha
  Layers de bairro presentes: `Pva4AmpliaÃ§Ã£o` (33), `CALÃADA` (7), `PAREDE-ALTA` (5)

**Regiao 4 — `CidadePva3`** · 716 lotes · 74 prontos (10%) · 599 linhas · 43.8 ha
  Layers de bairro presentes: `CidadePva3` (27), `AV CA ESQ` (4), `ÃREA TOTAL` (2), `Ã¡rea` (2), `ChacaraNEsperanÃ§a` (1), `ÃREA VERDE` (1)


## Total do municipio

- Numeros de lote: **23662**
- Poligonos de lote prontos: **2712** (11.5%)
- Linhas soltas em `GIS_LOTES`: **9252**
- Regioes ja organizadas: **4**