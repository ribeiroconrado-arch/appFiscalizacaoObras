# Analise dos layers GIS — CRS, extensao e viabilidade de poligonos

Arquivo: `base.dxf` · entidades: 136468

## 1. Sistema de coordenadas

Centro das entidades georreferenciadas: **X=-2956.48  Y=19710.43**

Reprojetando esse centro por cada CRS candidato e comparando com o centro
conhecido de Primavera do Leste (-15.5556, -54.2961):

| CRS candidato | EPSG | lat obtida | lon obtida | erro (km) |
|---|---:|---:|---:|---:|
| WGS 84 / UTM 22S | 32722 | -85.4970 | -138.9894 | **11960.4** |
| SIRGAS 2000 / UTM 22S | 31982 | -85.4970 | -138.9894 | **11960.4** |
| SAD69 / UTM 22S | 29192 | -85.4966 | -138.9907 | **11960.5** |
| SAD69 / UTM 21S | 29191 | -85.4966 | -144.9891 | **12455.7** |
| WGS 84 / UTM 21S | 32721 | -85.4970 | -144.9894 | **12455.7** |
| SIRGAS 2000 / UTM 21S | 31981 | -85.4970 | -144.9894 | **12455.7** |

## 2. Extensao por layer (so entidades em faixa UTM)

| Layer | Entidades em faixa | Fora da faixa | X min | X max | Y min | Y max | Largura (m) | Altura (m) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `UR - Topografia` | 7 | 14 | -2178 | 788240 | 20295 | 8302172 | 790418 | 8281876 |
| `CohabTancredoNeves` | 0 | 114 | -1680 | -1383 | 16520 | 18402 | 297 | 1882 |
| `GIS_TEXTOS` | 0 | 23662 | -12497 | 4261 | 14355 | 26890 | 16758 | 12534 |
| `SÃOJOSÃ` | 0 | 241 | -997 | -576 | 16832 | 17092 | 421 | 260 |
| `CRISTOREI` | 0 | 439 | -1204 | -464 | 16451 | 16832 | 739 | 382 |
| `JARDIM MILANO` | 0 | 121 | -1385 | -1181 | 16583 | 16823 | 204 | 239 |
| `JardimProgresso` | 0 | 221 | -1436 | -997 | 16833 | 17030 | 439 | 197 |
| `TREVO` | 0 | 426 | -12921 | 3237 | 14458 | 20890 | 16158 | 6431 |
| `CastelÃ¢ndiaII` | 0 | 266 | -1966 | -1571 | 16468 | 17013 | 395 | 545 |
| `CastelÃ¢ndiaVI` | 0 | 110 | -1775 | -1394 | 16099 | 16553 | 381 | 454 |
| `CastelÃ¢ndiaIII` | 0 | 49 | -1584 | -1504 | 16838 | 16997 | 80 | 159 |
| `Serra das Flores` | 0 | 68 | -1381 | -1181 | 16431 | 16585 | 200 | 154 |
| `JardimVoltaGrande` | 0 | 162 | -4692 | -4308 | 15650 | 16072 | 384 | 422 |
| `STCLARA` | 0 | 52 | -1503 | -1418 | 16833 | 17046 | 85 | 213 |
| `StClaraPref` | 0 | 9 | -1528 | -1499 | 16952 | 16994 | 29 | 42 |
| `CastelÃ¢ndiaI` | 0 | 1409 | -3051 | -1593 | 15573 | 16816 | 1459 | 1243 |
| `CasteÃ¢ndiaIV` | 0 | 158 | -3111 | -2809 | 15558 | 16252 | 302 | 693 |
| `Gnoato` | 0 | 142 | -1411 | -1149 | 16297 | 16484 | 262 | 187 |
| `JardimUnivesitÃ¡rio` | 0 | 118 | -407 | -23 | 17659 | 17815 | 384 | 155 |
| `0` | 0 | 1698 | -12326 | 788241 | -573 | 8302184 | 800567 | 8302757 |
| `PvaI` | 0 | 2818 | -3033 | -1534 | 16552 | 18418 | 1499 | 1865 |
| `GIS_QUADRAS` | 0 | 2896 | -9303 | 1246 | 14896 | 23239 | 10548 | 8343 |
| `GIS_TEXTOS_RUAS` | 0 | 1165 | -7444 | 1189 | 16424 | 22408 | 8633 | 5985 |
| `Eldorado` | 0 | 1333 | -1664 | 188 | 17119 | 18649 | 1852 | 1530 |
| `GIS_RUAS_E_CALCADAS` | 0 | 3729 | -12587 | 1553 | 14461 | 23266 | 14140 | 8805 |
| `JardimRiva2` | 0 | 110 | -3404 | -2702 | 18208 | 18390 | 702 | 182 |
| `JardimRiva` | 0 | 832 | -3374 | -2811 | 16497 | 18240 | 563 | 1744 |
| `JardimRiva2AmpliaÃ§Ã£o` | 0 | 913 | -3699 | -2800 | 16251 | 18191 | 899 | 1940 |
| `ChacaraGaporÃ©` | 0 | 83 | -4427 | -2788 | 15679 | 18114 | 1639 | 2434 |
| `Parque Industrial` | 0 | 146 | -6449 | -4541 | 15544 | 17589 | 1908 | 2045 |
| `SERRANO` | 0 | 132 | -3518 | -3241 | 15702 | 15935 | 276 | 233 |
| `NSAparecida` | 0 | 4 | -3642 | -3250 | 15690 | 15858 | 392 | 168 |
| `CASTELÃNDIA` | 0 | 100 | -3352 | -3184 | 15509 | 15748 | 169 | 239 |
| `PIONEIRO` | 0 | 105 | -3238 | -3048 | 15549 | 15788 | 190 | 240 |
| `RES. PRIMAVERA` | 0 | 126 | -3528 | -3306 | 15499 | 15747 | 221 | 247 |
| `PLANALTO` | 0 | 146 | -3642 | -3043 | 15437 | 15697 | 599 | 260 |
| `CastelandiaV` | 0 | 39 | -3306 | -3026 | 15415 | 15558 | 281 | 143 |
| `GIS_TEXTOS_QUADRAS` | 0 | 4539 | -7740 | 287 | 14984 | 22670 | 8027 | 7687 |
| `Firenze` | 0 | 54 | -3712 | -2034 | 15688 | 19254 | 1678 | 3566 |
| `CondTuiuiu` | 0 | 439 | -6474 | -6039 | 16292 | 16716 | 435 | 424 |

Entidades fora da faixa UTM (moldura/legenda/desenho fora de escala): **136461** de 136468 (100.0%).

## 3. Polilinhas fechadas nos layers GIS — distribuicao de area

### `GIS_LOTES` — 4887 polilinhas fechadas

Tipos: AcDbPolyline: 2917, AcDbBlockReference: 1354, AcDbCircle: 582, AcDb3dPolyline: 34

| Faixa de area (m2) | Quantidade |
|---|---:|
| 0 – 100 | 1934 |
| 100 – 200 | 303 |
| 200 – 360 | 2286 |
| 360 – 600 | 115 |
| 600 – 1000 | 25 |
| 1000 – 2000 | 43 |
| 2000 – 5000 | 31 |
| 5000 – 20000 | 148 |
| > 20000 | 2 |

Mediana: **200.0 m2** · minimo: 0.0 · maximo: 302177.7


### `GIS_QUADRAS` — 645 polilinhas fechadas

Tipos: AcDbPolyline: 341, AcDbCircle: 194, AcDbBlockReference: 74, AcDb3dPolyline: 36

| Faixa de area (m2) | Quantidade |
|---|---:|
| 0 – 100 | 244 |
| 100 – 200 | 24 |
| 200 – 360 | 3 |
| 360 – 600 | 74 |
| 600 – 1000 | 17 |
| 1000 – 2000 | 12 |
| 2000 – 5000 | 41 |
| 5000 – 20000 | 223 |
| > 20000 | 7 |

Mediana: **468.8 m2** · minimo: 3.0 · maximo: 35486.5


### `GIS_RUAS_E_CALCADAS` — 439 polilinhas fechadas

Tipos: AcDbPolyline: 370, AcDbCircle: 69

| Faixa de area (m2) | Quantidade |
|---|---:|
| 0 – 100 | 84 |
| 100 – 200 | 62 |
| 200 – 360 | 17 |
| 360 – 600 | 25 |
| 600 – 1000 | 14 |
| 1000 – 2000 | 16 |
| 2000 – 5000 | 16 |
| 5000 – 20000 | 201 |
| > 20000 | 4 |

Mediana: **2295.3 m2** · minimo: 0.7 · maximo: 34739.0


### `GIS_AREAS_AMBIENTAIS` — 22 polilinhas fechadas

Tipos: AcDbPolyline: 20, AcDbCircle: 1, AcDbSpline: 1

| Faixa de area (m2) | Quantidade |
|---|---:|
| 0 – 100 | 1 |
| 200 – 360 | 2 |
| 360 – 600 | 1 |
| 2000 – 5000 | 6 |
| 5000 – 20000 | 6 |
| > 20000 | 6 |

Mediana: **5667.0 m2** · minimo: 28.3 · maximo: 240783.9

