# Etapa 1 — Como confirmar o datum (passo a passo)

Última pendência do georreferenciamento. **Não exige ir a campo.**

---

## O que está em jogo, em uma frase

A transformação que resolvemos entrega coordenadas **UTM fuso 21S**, mas UTM 21S
existe em mais de um datum, e cada um coloca o mesmo ponto num lugar diferente:

| Hipótese | EPSG | Centro do Jardim Europa IV |
|---|---|---|
| **SIRGAS 2000 / UTM 21S** | 31981 | -15,516552 ; -54,310543 |
| **SAD69 / UTM 21S** | 29191 | -15,516931 ; -54,311042 |

**Diferença: 68,2 m** (42 m no sentido norte-sul, 53 m no leste-oeste).

Como um lote urbano tem 12 m de testada, escolher errado desloca tudo em ~5 lotes:
o fiscal apontaria o GPS na frente da casa certa e o sistema abriria a ficha de um
vizinho a meia quadra dali. Por isso isso se confirma **antes** de importar para o
banco, não depois.

Já deixei as duas hipóteses geradas, com a mesma geometria, para comparação:

```
gis/piloto/_teste_datum_SIRGAS2000.geojson   (e .kml)
gis/piloto/_teste_datum_SAD69.geojson        (e .kml)
```

---

## Método 1 — QGIS sobre imagem de satélite *(recomendado)*

Você já tem o QGIS instalado. Leva uns 5 minutos.

**1. Adicionar a imagem de satélite**

No painel **Navegador**, clique com o botão direito em **XYZ Tiles** → **Nova
conexão**:

- Nome: `Esri Satélite`
- URL: `https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}`

Confirme e dê duplo clique na conexão criada para carregá-la no mapa.

**2. Carregar as duas hipóteses**

Arraste para a janela do QGIS os dois arquivos:

- `_teste_datum_SIRGAS2000.geojson`
- `_teste_datum_SAD69.geojson`

**3. Deixar as bordas visíveis**

Para cada camada: duplo clique → **Simbologia** → **Preenchimento simples** →
*Estilo de preenchimento* = **Sem pincel**, e *Cor do traço* diferente em cada uma
(por exemplo **vermelho** para SIRGAS e **amarelo** para SAD69), espessura 0,5.

Sem isso as camadas ficam preenchidas e uma esconde a outra.

**4. Ler o resultado**

Dê zoom no Jardim Europa até enxergar os lotes na imagem. Você vai ver duas malhas
de quadriculado deslocadas ~68 m uma da outra.

> **A malha correta é a que assenta sobre os lotes e as ruas da imagem de
> satélite** — os traços coincidem com os muros e meios-fios. A errada fica
> visivelmente atravessada, cortando as casas ao meio.

Anote qual cor coincidiu. É essa a resposta.

---

## Método 2 — Google Earth *(se preferir não abrir o QGIS)*

Abra os dois arquivos `.kml` no Google Earth (Arquivo → Abrir, ou arraste para a
janela). O Google Earth já mostra a imagem de satélite por baixo.

Mesma leitura do método 1: a malha que assenta sobre os lotes reais é a correta.
Dá para acender e apagar cada uma na barra lateral, o que facilita a comparação.

---

## Método 3 — GPS em campo *(só se os dois anteriores ficarem duvidosos)*

Se a imagem de satélite estiver desatualizada ou o loteamento ainda não tiver
construções que sirvam de referência:

1. Vá até um **canto bem definido** de um lote do Jardim Europa — um marco de
   divisa ou o encontro de dois muros, não um ponto no meio da rua.
2. Fique **parado uns 2 minutos** com o celular antes de anotar. A leitura de GPS
   melhora bastante nesse tempo; anotar de imediato costuma dar erro de 15–20 m.
3. Anote a coordenada em **graus decimais** (formato `-15.5165, -54.3105`). No
   Google Maps: toque e segure no ponto, e a coordenada aparece na barra de busca.
4. Me passe a coordenada e diga **de qual lote e quadra** é aquele canto. Eu
   comparo com as duas hipóteses e digo qual bate.

O celular entrega WGS84, que para esta finalidade é a mesma coisa que SIRGAS 2000.
Como a diferença entre as hipóteses é de 68 m e o erro do celular parado é de
5–10 m, não tem como dar empate.

---

## Se as duas parecerem erradas

Aí a hipótese de fuso ou datum é outra — por exemplo Córrego Alegre, comum em
levantamentos antigos. Me avise que eu gero as variantes adicionais para
comparação. É improvável, mas o teste também detecta isso.

---

## Qual é a aposta

**SIRGAS 2000 (EPSG:31981).** É o sistema geodésico oficial brasileiro e é
obrigatório em levantamentos desde 2015; o Jardim Europa IV é loteamento recente.
O teste visual serve para **confirmar**, não para descobrir do zero — mas confirme
mesmo assim, porque o custo de estar errado aparece só lá na frente, com o fiscal
em campo apontando para o lote errado.

Nenhum dos arquivos do acervo declara o datum em texto — procurei em todos os
DXFs convertidos e não há menção a SIRGAS, SAD69, datum ou sistema de referência.
Por isso o teste visual é o caminho.
