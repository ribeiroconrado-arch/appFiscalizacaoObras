# Etapa 1 — Bairro piloto e pontos de controle

Data: 16/08/2026
Base: [etapa0-conclusoes.md](etapa0-conclusoes.md) · dados brutos: [bairro-piloto.md](bairro-piloto.md)

> **A seção 2 deste documento (pontos de controle) está superada.** O
> georreferenciamento foi resolvido a partir do `EUROPA IV.dwg`, com resíduo zero
> — ver [etapa1-georreferenciamento.md](etapa1-georreferenciamento.md). A seção 1
> (bairro piloto) continua válida.

---

## Achado novo que muda o entendimento da base

A análise por grade revelou algo que a contagem global escondia:
**o layer `GIS_LOTES` cobre apenas 4 pequenas regiões da cidade, não o município.**

| | |
|---|---:|
| Números de lote no município (`GIS_TEXTOS`) | 23.662 |
| Polígonos de lote já fechados | 2.712 (**11,5%**) |
| Linhas soltas em `GIS_LOTES` | 9.252 |
| Regiões com trabalho de organização feito | **4** |

Os ~23 mil lotes restantes **não estão sem geometria** — estão desenhados nos
~150 layers nomeados por bairro (`CastelândiaI` com 1.409 entidades, `PvaI` com
2.818, `Eldorado` com 1.333, `JardimRiva` com 832 …), que são os projetos de
loteamento originais. O trabalho de consolidar isso em `GIS_LOTES` está no começo.

Isso é importante para o cronograma: a migração para o GIS não é "converter um
layer pronto", é **poligonizar bairro a bairro**. Motivo a mais para o piloto ser
um bairro só, e para medir o rendimento antes de prometer prazo do município todo.

---

## 1. Bairro piloto recomendado: **Cidade Primavera 3** (layer `CidadePva3`)

| Critério | Valor |
|---|---|
| Lotes numerados na região | **716** |
| Polígonos já fechados | 74 |
| Linhas de divisa a poligonizar | 599 |
| Área | ~44 ha (compacta, ~7 células de 250 m) |
| Composição do layer | 119 polilinhas · 47 linhas · 36 arcos |

**Por que este:**

1. **É o único bairro residencial real entre as 4 regiões já organizadas.** Você já
   começou a trabalhar nele — há 74 polígonos e 599 linhas migradas para
   `GIS_LOTES`. Aproveita trabalho feito em vez de começar do zero.
2. **Tamanho certo para piloto**: 716 lotes é grande o bastante para ser um teste
   honesto de campo e pequeno o bastante para refazer inteiro se der errado.
3. **Compacto** (~44 ha): dá para percorrer a pé/de carro numa manhã na validação
   de GPS.
4. **Alta proporção de polilinha** (119 de 202 entidades), o que melhora o
   rendimento da poligonização em relação a bairros desenhados só com `Line`.

**Alternativa menor, se quiser um teste ainda mais rápido:** `SÃOJOSÉ` — 289 lotes
em apenas 10,9 ha, e o layer tem a melhor proporção de polilinhas do desenho
(139 polilinhas para 84 linhas). Serve para calibrar o processo em meia hora antes
de rodar no Cidade Primavera 3.

**Descarte deliberado — as regiões 1 e 2 do relatório.** Elas aparecem no topo da
tabela com cobertura de 40.000% e 3.503%, o que é sinal de que a métrica não se
aplica ali: são ~2.600 polígonos com quase nenhum número de lote associado
(4 e 29 textos). Pelos layers presentes (`Diretrizes`, `Matricula 22.997`,
`BURITIS II EXP`) são **loteamentos novos ainda em projeto** — lotes desenhados,
não implantados e não numerados. Péssimo piloto para fiscalização de obras: não há
obra, nem histórico, nem cadastro imobiliário correspondente.

---

## 2. Pontos de controle: de onde puxar

Primeiro, a má notícia sobre a pista que investigamos: as 7 entidades em
coordenada UTM verdadeira (`UR - Topografia`) **não servem**. Foram extraídas e são
textos de cota — `30.00`, `15.27`, `15.00` — larguras de via, não coordenadas. E
ficam em E≈788.100 / N≈8.302.000, que reprojetado dá ~24 km ao norte do centro,
fora da malha urbana. É um fragmento de levantamento solto, sem vínculo geométrico
com o desenho da cidade.

Também procurei coordenadas UTM nos memoriais descritivos em disco
(`MEMORIAL APROVAÇÃO - RES. PORTO SEGURO`): não há.

### Recomendação: imagem de satélite no Georreferenciador do QGIS

É a opção que eu tomaria hoje mesmo, sem sair da mesa.

1. No QGIS, adicionar uma camada **XYZ Tiles** de satélite (Esri World Imagery vem
   embutido; Google Satellite se adicionar a URL).
2. Definir o CRS do projeto como **EPSG:31981 — SIRGAS 2000 / UTM 21S**.
3. Abrir o **Georreferenciador** com a camada do DXF e marcar **3 a 4 cruzamentos
   de via** bem definidos, **o mais afastados possível entre si** — a precisão da
   rotação depende quase inteiramente dessa distância. Cruzamentos de avenidas
   largas, em esquinas com meio-fio visível, são os melhores alvos.
4. Tipo de transformação: **Helmert / Similaridade**, com **escala travada em 1**.
   Como o desenho já está em metros reais (confirmado: mediana de lote = 200 m²),
   só faltam translação e rotação — não deixe o QGIS reescalar, senão ele
   "conserta" o erro dos seus cliques distorcendo a base inteira.
5. Marcar **1 ponto a mais e não usá-lo no ajuste**: ele é o teste independente.
   O resíduo nesse ponto é a precisão real do georreferenciamento.

**Precisão esperada: 1 a 3 m.** É suficiente, porque o GPS do celular do fiscal
erra ±5 a 10 m de qualquer forma — a tolerância do `POST /api/localizacao/identificar`
já foi desenhada para absorver isso. Investir em precisão centimétrica aqui não
melhora em nada a identificação do lote.

### Se quiser precisão de levantamento, em ordem de esforço

| Fonte | Precisão | Custo | Observação |
|---|---|---|---|
| **Memorial descritivo de loteamento recente** | cm | baixo, se existir | Loteamentos aprovados na última década têm memorial com vértices em UTM. Vale abrir os PDFs de `LOTEAMENTO JARDINS IPÊS I–IV`, `EUROPA IV`, `Santa Felicidade`, `Buritis` — se um deles trouxer coordenadas de vértice, você tem par de controle exato, porque esses vértices são identificáveis no desenho |
| **Marco geodésico do IBGE (BDG)** | cm | baixo | Consulta gratuita das estações de Primavera do Leste. Só serve se o marco for identificável no desenho — normalmente não é |
| **GPS em campo** | ±3–5 m | uma manhã | 3 esquinas afastadas, com o celular parado ~2 min em cada uma para a leitura estabilizar. Use como **validação** do georreferenciamento por satélite, não como fonte principal |
| **Arquivos topográficos do seu acervo** | cm | médio | Os layers `TOP_MF2_2020`, `TOP_Limites`, `PONTOS_LOCADOS` sugerem levantamentos georreferenciados nos seus projetos. Se algum cobrir área urbana e for em UTM, é a melhor fonte de todas |

---

## 3. Sequência imediata

1. Georreferenciar por satélite (Helmert, escala travada) e medir o resíduo no
   ponto de checagem.
2. Recortar o **Cidade Primavera 3** e poligonizar as divisas (`Polygonize` do
   QGIS, ou `v.clean` + `v.build` do GRASS se houver muitos vértices soltos).
3. **Medir o aproveitamento**: quantos dos 716 lotes viraram polígono válido. Meta
   ≥ 90%. Abaixo disso, o caminho é fechar a malha no CAD antes de insistir no GIS.
4. Fazer o *join* espacial dos números de lote (`GIS_TEXTOS`) e de quadra
   (`GIS_TEXTOS_QUADRAS`) nos polígonos, e padronizar a chave `bairro+quadra+lote`.
5. Validar em campo com GPS em 3 pontos do bairro.
