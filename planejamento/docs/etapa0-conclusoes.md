# Etapa 0 — Conclusões do diagnóstico da base cartográfica

Data: 16/08/2026
Arquivo analisado: `BAIRROS DE PVA DO LESTE 2026-04-06_ORGANIZADO_PARA_GIS_OTIMIZADO_ORGANIZADO_PARA_GIS.dwg` (33,3 MB)
Relatórios brutos: [diagnostico-dwg.md](diagnostico-dwg.md) · [analise-gis-layers.md](analise-gis-layers.md)
Ferramentas: [gis/tools/](../gis/tools/) — reexecutáveis a cada nova versão do DWG.

**136.468 entidades · 273 layers.** O original nunca foi alterado: todo o trabalho
foi feito sobre cópia, e o DXF intermediário foi gerado com o `accoreconsole` do
AutoCAD 2025 (o driver DWG do GDAL só lê até R2000).

---

## Veredito: viável, com um trabalho de georreferenciamento e poligonização pela frente

Nenhum achado inviabiliza o projeto. Dois problemas reais foram confirmados, ambos
com solução conhecida, e um terceiro achado **reduz** o trabalho previsto.

---

## 1. A base NÃO está georreferenciada — mas está em metros reais

O corpo do desenho ocupa **X entre −12.500 e +4.300, Y entre 14.000 e 27.000**.
Nenhum CRS UTM candidato aproxima essas coordenadas de Primavera do Leste (o
melhor erra ~12.000 km). É um **sistema local/arbitrário**.

**A boa notícia está na escala.** A extensão do desenho é de ~16,7 km × 12,5 km —
compatível com a área urbana real — e a mediana de área dos lotes fechados dá
**200 m²**, com 2.286 lotes entre 200 e 360 m² (o padrão 10×20 / 12×30). Ou seja:
**a unidade do desenho já é o metro, e a escala está correta.**

Consequência prática: o georreferenciamento é uma **transformação de similaridade**
(translação + rotação, escala travada em 1), não um rubber-sheeting. Bastam **2 a 3
pontos de controle** para resolver a base inteira — trabalho de uma tarde, não de
semanas.

> **Pista a investigar:** as únicas 7 entidades do arquivo que estão em coordenadas
> UTM reais (X≈788.240, Y≈8.302.172) estão no layer `UR - Topografia`, e são cotas
> (`AcDbAlignedDimension`). Isso parece resto de um levantamento topográfico
> inserido em coordenadas verdadeiras. Vale abrir esse layer no AutoCAD antes de
> sair medindo pontos em campo — pode já ser o par de controle que falta.

**CRS de destino:** essas coordenadas caem no **fuso 21S** (Primavera do Leste está
em longitude −54,30, logo a oeste do meridiano −54 que divide os fusos 21 e 22).
O alvo é **EPSG:31981 — SIRGAS 2000 / UTM 21S**, e daí para EPSG:4326 na exportação.
*Atenção: o `.md` original e a primeira versão do script assumiram fuso 22S. É 21S.*

**Como obter os pontos de controle, em ordem de preferência:**
1. o layer `UR - Topografia`, se as cotas confirmarem coordenadas conhecidas;
2. duas esquinas bem definidas medidas com GPS em campo, o mais distantes possível
   entre si (a precisão da rotação depende dessa distância);
3. dois cruzamentos de via identificáveis em imagem de satélite georreferenciada.

---

## 2. Só ~20% dos lotes são polígonos fechados hoje

É o número que a Etapa 0 tinha que produzir:

| | |
|---|---:|
| Entidades no layer `GIS_LOTES` | 14.288 |
| Polilinhas **fechadas** | ~2.951 |
| …destas, com área de lote urbano (200–360 m²) | **2.286** |
| Lotes rotulados (textos em `GIS_TEXTOS` / `GIS_TEXTOS_LOTES`) | ~12.000 |
| **Cobertura atual em polígono** | **≈ 20%** |

Os outros ~80% existem como **6.528 `AcDbLine` soltas** — divisas desenhadas segmento
a segmento. Isso é o esperado num desenho de cadastro e é exatamente o caso que a
poligonização resolve: linhas que se tocam formando malha fechada viram polígonos
por `Polygonize` (QGIS) ou `v.clean` + `v.build` (GRASS). O que decide o rendimento
é a quantidade de vértices soltos (*undershoot*/*overshoot*), que só se mede rodando.

Ruído a descartar antes de poligonizar, já identificado no `GIS_LOTES`:
**1.354 blocos**, **582 círculos** e **349 arcos** — símbolos, não divisas. E 1.934
polilinhas fechadas com menos de 100 m², que são hachuras e marcações, não lotes.

**Meta de aceite para a Etapa 1:** ≥ 90% dos lotes do bairro piloto virando polígono
após a poligonização. Abaixo disso, o caminho é corrigir a malha no CAD antes de
insistir no GIS.

---

## 3. Achado que reduz trabalho: os bairros já estão nos nomes dos layers

O layer `GIS_BAIRROS` **não tem polígono nenhum** — são 198 `AcDbLeader` (chamadas de
texto). À primeira vista é uma camada faltando.

Mas o desenho tem **~150 layers nomeados por bairro/loteamento** — `BELVEDERE`,
`CRISTOREI`, `CastelândiaI` … `VI`, `JardimRiva`, `PonchoVerde2Etapa`,
`RESIDENCIAL BURITIS 2`, `JARDIM MILANO`, `Parque Industrial` — e as extensões deles
são **aglomerados espacialmente separados**, cada um com algumas centenas de metros
de lado. Ou seja: **o nome do layer já é o atributo `bairro`**, e o polígono de cada
bairro sai do *dissolve* das suas quadras. Não é preciso desenhar os bairros à mão.

---

## 4. O que o desenho tem — e o que não tem — de dado cadastral

| Informação | Onde está | Situação |
|---|---|---|
| Número do lote | `GIS_TEXTOS` (23.662 textos: `01`, `03`, `05`…) | ✅ presente |
| Número da quadra | `GIS_TEXTOS_QUADRAS` (4.538) | ✅ presente |
| Nome de logradouro | `GIS_TEXTOS_RUAS` (1.165) + `0_TEXTOS RUAS` | ✅ presente |
| Bairro | nome do layer | ✅ presente (item 3) |
| **Inscrição imobiliária** | — | ❌ **não existe no DWG** |

Apesar do nome, `GIS_TEXTOS_LOTES` (11.989 textos) contém **medidas de testada**
(`21.00m`, `12.50m`), não identificadores — não serve como rótulo de lote.
`GIS_TEXTOS_QUADRAS` mistura números com nomes de proprietário (`PREFEITURA`,
`PRIMAVERA ARMAZÉNS GERAIS LTDA`).

**Impacto direto na chave de integração:** como não há inscrição no desenho, a chave
com o cadastro imobiliário terá de ser a tripla **bairro + quadra + lote**, com a
inscrição vindo depois pelo lado do cadastro. Isso torna a padronização dos números
de quadra/lote (zeros à esquerda, sufixos como `12A`) uma tarefa da Etapa 1, e não
um detalhe da Etapa 4. É a diferença entre casar 90% e casar 40% dos registros.

**Nota técnica:** os textos e nomes de layer saem com acentuação corrompida
(`CastelÃ¢ndiaI`, `VEGETAÃÃO1`) — o DXF está em CP1252 e o GDAL leu como UTF-8.
Corrigir na importação com `--config DXF_ENCODING CP1252`; não é perda de dado.

---

## 5. Ajustes que estes achados provocam no plano

| Item do plano | Ajuste |
|---|---|
| CRS de destino | **EPSG:31981 (UTM 21S)**, não 22S |
| Etapa 1 — georreferenciamento | Deixa de ser "se necessário" e passa a ser **obrigatório**, com 2–3 pontos de controle e transformação de similaridade |
| Etapa 1 — poligonização | Vira o item de maior esforço: ~80% dos lotes dependem dela |
| Etapa 1 — bairros | Não precisa desenhar: extrair do nome do layer + dissolve das quadras |
| Etapa 1 — normalização | **Novo item:** padronizar quadra/lote como chave textual, antes do banco |
| Etapa 4 — chave de integração | Deixa de ser inscrição e passa a ser bairro+quadra+lote |
| Bairro piloto | Escolher entre os layers de bairro com maior taxa de polilinha fechada |

---

## 6. Próximo passo

Rodar a poligonização num único bairro e medir o aproveitamento real. É o teste que
converte a estimativa de 20% numa taxa medida — e é ele que diz se o caminho é
seguir no GIS ou voltar ao CAD para fechar a malha.

Antes disso, é preciso definir **qual bairro** será o piloto e **de onde virão os
pontos de controle** (item 1).
