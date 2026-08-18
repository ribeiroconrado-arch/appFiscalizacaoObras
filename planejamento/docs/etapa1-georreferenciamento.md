# Etapa 1 — Georreferenciamento RESOLVIDO

Data: 16/08/2026
Supera a seção 2 de [etapa1-decisoes.md](etapa1-decisoes.md) (pontos de controle por satélite): não é mais necessária.

---

## Resultado

A base cartográfica municipal está georreferenciada. A transformação de
coordenadas locais para UTM é uma **translação pura**:

```
X_utm = x_local + 792.035,2782
Y_utm = y_local + 8.260.796,2988
```

| Parâmetro | Valor | Leitura |
|---|---|---|
| Escala | **1,00000000** | O desenho já estava em metros reais — confirma a hipótese da Etapa 0 |
| Rotação | **0,000189°** | 0,05 m ao longo dos 16 km da cidade: desprezível, é ruído numérico |
| Translação X | 792.035,2782 m | |
| Translação Y | 8.260.796,2988 m | |
| Resíduo nos 19 pontos de controle | **0,000 m** | Exato, não é ajuste estatístico |

Alguém simplesmente **arrastou o desenho georreferenciado para perto da origem**
— prática comum no AutoCAD para não trabalhar com coordenadas de 7 dígitos. Não
houve rotação nem mudança de escala. É por isso que o resíduo é zero: não há erro
a ajustar, apenas um deslocamento constante a desfazer.

---

## Como foi obtido

Sem trabalho de campo, sem satélite, com um arquivo que já estava no seu disco:
`Prefeitura - Arquivos DWG\EUROPA IV.dwg` (0,5 MB).

1. **Triagem** — `checar_georreferenciamento.py` varreu os DWGs candidatos.
   `EUROPA IV.dwg` está **100% em coordenadas UTM** (X 787.970–789.202,
   Y 8.282.074–8.283.274), com 707 polígonos de lote em `UR - Polyline Lote`.
2. **Confirmação de que é a mesma área** — 13 nomes de rua coincidem entre esse
   arquivo e os layers `UR - *` do mapa da cidade (Rua Alemanha, Rua Bruxelas,
   Rua Moscou, Avenida Espanha, Av. das Nações Unidas…). É o mesmo Jardim Europa,
   presente nos dois arquivos: um em UTM, outro em coordenadas locais.
3. **Pareamento automático** — `resolver_transformacao.py` casou os rótulos de
   texto que aparecem **exatamente uma vez em cada arquivo** (19 pares: nomes de
   rua e rótulos de área como `A: 10.204,43m²`). Sem clique manual.
4. **Ajuste** — Helmert por mínimos quadrados, escala resolvida junto e depois
   conferida. Deu 1,0 exato.

## Validação independente

O ponto crítico: a transformação foi deduzida de **um** loteamento de 1,2 km. Ela
vale para a cidade toda?

Aplicando-a aos **23.662 números de lote** do município e tomando a mediana:

```
mediana local          -2.272,5 ; 17.929,5
+ translação      ->    789.762,8 ; 8.278.725,8   (UTM 21S)
reprojetado       ->    -15,552547 ; -54,298518   (WGS84)
centro de Primavera do Leste  -15,5556 ; -54,2961
DISTÂNCIA: 0,43 km
```

O centro de massa do tecido urbano cai a **430 m** do centro nominal da cidade —
dentro da margem do próprio ponto de referência. A base inteira, e não apenas o
Jardim Europa, está no lugar certo.

---

## Datum: CONFIRMADO — SIRGAS 2000 / UTM 21S (EPSG:31981)

*Confirmado em 16/08/2026* por sobreposição das duas hipóteses à imagem de
satélite (procedimento em [etapa1-confirmar-datum.md](etapa1-confirmar-datum.md)):
a malha gerada com **EPSG:31981** assenta sobre os lotes e ruas da imagem; a
variante SAD69 fica deslocada 68,2 m. Nenhum arquivo do acervo declarava o datum
em texto — a confirmação é visual, e é conclusiva porque 68 m é muito maior que a
incerteza do teste.

**Portanto, a cadeia de coordenadas do projeto está fechada:**

```
desenho local  --(+792.035,2782 ; +8.260.796,2988)-->  EPSG:31981  --reprojeção-->  EPSG:4326
```

O texto abaixo registra como a dúvida foi levantada e resolvida.

### Como a dúvida foi levantada

A transformação entrega coordenadas **UTM fuso 21S**. Falta confirmar o datum do
levantamento de origem:

| Datum | Posição resultante | Diferença |
|---|---|---|
| SIRGAS 2000 (EPSG:31981) | -15,552547 ; -54,298518 | referência |
| SAD69 (EPSG:29191) | -15,552926 ; -54,299017 | **~55 m** ao sul/oeste |

**Isso não é detalhe.** Um deslocamento sistemático de 55 m erra o lote por 4 ou
5 posições — o GPS do fiscal apontaria o vizinho errado da quadra.

**Como resolver, em ordem de custo:**

1. **Abrir a prancha do `EUROPA IV.dwg`** e ler o carimbo/legenda: projetos de
   loteamento declaram o sistema geodésico adotado. Custo zero.
2. **Uma única leitura de GPS** em campo, num canto bem definido do Jardim Europa
   (o celular entrega WGS84, que para este fim equivale a SIRGAS 2000). Os 55 m
   de diferença entre os datums são grandes demais para dar empate — uma leitura
   já decide. Isso substitui toda a campanha de campo que estava prevista.
3. Sobrepor a base a uma imagem de satélite no QGIS e ver qual datum encaixa nas
   esquinas. Serve como conferência visual rápida.

Como o loteamento é recente e o padrão brasileiro desde 2015 é SIRGAS 2000,
**EPSG:31981 é a aposta**, mas precisa ser confirmada antes da importação para o
banco — não depois.

---

## Impacto no plano

| Item | Antes | Agora |
|---|---|---|
| Georreferenciamento | Etapa obrigatória, com pontos de controle a levantar | **Resolvido**, resíduo zero |
| Trabalho de campo | Campanha de GPS em 3+ esquinas | **1 leitura de GPS** só para decidir o datum |
| Precisão esperada | 1–3 m (satélite) | **Exata**, limitada só pela precisão do levantamento original |
| Conversão para o banco | Georreferenciar, depois reprojetar | Somar a translação e reprojetar 31981 → 4326 |

O maior risco da Etapa 0 saiu da frente. **O que sobra como esforço real da
Etapa 1 é a poligonização** — os ~80% de lotes que ainda são linhas soltas.

## Bônus: `EUROPA IV.dwg` é um lote pronto de graça

Aquele arquivo já tem **707 polígonos de lote fechados** (`UR - Polyline Lote`),
com número do lote (`UR - Txt - n. lote`, 1.430 textos) e área (`UR - Txt - lote m²`,
707 textos) — tudo em UTM. É geometria pronta, sem poligonizar nada.

Vale checar se os demais loteamentos do acervo (`JARDINS IPÊS I–IV`,
`Santa Felicidade`, `Buritis`, `Porto Seguro`) seguem o mesmo padrão de layers
`UR - *`. Se seguirem, uma parte grande da cidade entra no GIS por importação
direta, sem passar pela poligonização. Rodar `checar_georreferenciamento.py`
neles é o próximo teste barato.

---

## Ferramentas criadas

| Script | Função |
|---|---|
| [checar_georreferenciamento.py](../gis/tools/checar_georreferenciamento.py) | Diz se um DXF está em UTM verdadeiro ou em sistema local |
| [resolver_transformacao.py](../gis/tools/resolver_transformacao.py) | Acha pares homólogos por rótulo e resolve o Helmert |
| [validar_transformacao.py](../gis/tools/validar_transformacao.py) | Confere se a transformação põe a base sobre a cidade |

Todos rodam com o Python do QGIS e são somente-leitura.
