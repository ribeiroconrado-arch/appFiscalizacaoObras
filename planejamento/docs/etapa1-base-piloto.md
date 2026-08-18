# Etapa 1 — Estado da base piloto

Data: 16/08/2026 · CRS confirmado: **SIRGAS 2000 / UTM 21S (EPSG:31981)** → exportado em **EPSG:4326**

## O que já é dado real

| Arquivo | Bairro | Lotes | Nº do lote | Quadra | Origem |
|---|---|---:|---:|---:|---|
| `gis/piloto/lotes_jardim_europa.geojson` | Jardim Europa IV | **707** | 100% | 100% | `EUROPA IV.dwg` |
| `gis/piloto/lotes_buritis_v.geojson` | Residencial Buritis V | **1.518** | 100% | 100% | `MAPA PVA_RESIDENCIAL BURITIS V.dwg` |
| | **Total** | **2.225** | | | |

Todos são polígonos já fechados na origem — **nenhuma poligonização foi
necessária**. Cada feição carrega `bairro`, `quadra`, `numero_lote`, `chave`
(`bairro|quadra|lote`), `area_gis_m2` e `fonte`.

Isso equivale a ~9,4% dos 23.662 lotes do município, obtidos sem tratamento
geométrico algum.

## Pendência conhecida: quadra duplicada em parte dos lotes

A chave `bairro|quadra|lote` ainda **não é única**:

| Bairro | Lotes com chave repetida | % |
|---|---:|---:|
| Jardim Europa IV | 33 de 707 | 4,7% |
| Residencial Buritis V | 226 de 1.518 | 14,9% |

**Diagnóstico:** os lotes de mesma chave ficam a 31–260 m um do outro (mediana
~80 m), ou seja, a uma quadra de distância. Isso é **erro de atribuição de
quadra**, não numeração repetida de verdade — se fosse numeração repetida, os
pares estariam a quilômetros. Só 8 pares em Buritis passam de 200 m.

**Causa:** nem todo loteamento desenha a quadra como polígono. No Jardim Europa,
o layer `QUADRAS` são apenas arcos, e os números de quadra vivem como atributos
de bloco (37 pontos no layer `0`) — então a quadra é atribuída pelo **rótulo mais
próximo**, o que erra nos lotes de divisa entre duas quadras. Em Buritis existem
52 polígonos de quadra, mas os lotes fora deles caem no mesmo plano B.

**Correção prevista:** gerar polígonos de quadra de verdade a partir da malha
viária (poligonizar `UR - Rua` / `BURITIS V - MEIO FIO`) e atribuir a quadra por
contenção, sem plano B. É trabalho de GIS, não de dado faltante — a informação
existe nos dois arquivos.

**Quando isso trava o projeto:** só na integração com o cadastro imobiliário
(Etapa 4), que é onde a chave passa a valer. **Não trava** o mapa nem a
identificação por GPS (Etapa 3), que dependem só da geometria — e a geometria
está correta.

## Jardins dos Ipês: mapeamento de camada ainda não resolvido

`LOTEAMENTO JARDINS IPÊS IV.dwg` produziu 257 polígonos, mas **0% de número e 0%
de quadra**: os rótulos de `_C-LAREA-LOTES-TXT` (5.894 textos) não caem dentro dos
polígonos de `PMV - Lote`. Provável que sejam etapas diferentes do loteamento em
posições distintas, ou que a camada de lote correta seja outra. Precisa de uma
inspeção visual no AutoCAD para identificar o par certo de camadas — os outros
dois arquivos levaram menos de um minuto cada quando o par estava certo.

## Ferramentas

`gis/tools/dxf_para_geojson.py` — recebe as camadas por parâmetro, porque cada
loteamento do acervo usa uma convenção própria:

| Loteamento | `--lotes` | `--textos` | `--quadras` | `--textos-quadra` |
|---|---|---|---|---|
| Jardim Europa IV | `UR - Polyline Lote` | `UR - Txt - n. lote` | — | `0` |
| Buritis V | `BURITIS V - LOTES` | `BURITIS V - TEXTO LOTES` | `BURITIS V - QUADRAS` | `BURITIS V - TEXTO QUADRAS` |

Exemplo:

```
python-qgis.bat dxf_para_geojson.py entrada.dxf saida.geojson \
  --lotes "BURITIS V - LOTES" --textos "BURITIS V - TEXTO LOTES" \
  --quadras "BURITIS V - QUADRAS" --textos-quadra "BURITIS V - TEXTO QUADRAS" \
  --bairro "Residencial Buritis V"
```

Para arquivos em coordenadas locais (o mapa da cidade), acrescentar
`--dx 792035.2782 --dy 8260796.2988`.
