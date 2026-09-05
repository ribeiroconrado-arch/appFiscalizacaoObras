# ESTADO ATUAL

> O que funciona, o que falta e o que está quebrado — medido, não estimado.
> Atualizado em **04/09/2026**, commit `fea0041`, contra o banco de produção.

## Em produção

<https://fiscobras.duckdns.org> · Oracle Always Free (Ubuntu 24.04) ·
2 vCPU / 1 GB + 2 GB swap · PHP 8.4 · MySQL 8.0.46

## Números da base

| Tabela | Linhas | Observação |
|---|---:|---|
| `lotes` | **2.235** | 2 bairros levantados; 0 inativos; **0 sem quadra** |
| `cadastro_bairros` | 125 | **só 2 amarrados ao desenho** |
| `cadastro_externo_imoveis` | 990 | exportação da prefeitura, **só do bairro 124** |
| `irregularidades` | 20 | **18 sem artigo vinculado** |
| `legislacoes` / `artigos` | 4 / 4 | catálogo legal ainda raso |
| `vistorias` | 12 | uso ainda de teste |
| `documentos` | 4 | |
| `protocolos` / `ordens_servico` | 4 / 2 | |
| `evidencias` | 3 | |
| `edificacoes` | 0 | recurso novo, sem uso ainda |
| `lotes_apagados` | 2 | dois resultantes de unificação desfeita, com o desenho guardado |
| `auditoria` | 255 | |
| `users` | 3 | |

**Leitura honesta:** a base cartográfica está carregada e a aplicação está
completa nos fluxos; o que ainda não aconteceu é o **uso real** — e o catálogo
legal, que é o que dá força à peça, está quase vazio.

## O que funciona ponta a ponta

| Fluxo | Situação |
|---|---|
| Mapa, seleção, ficha do imóvel | ✅ |
| Consulta de imóveis (bairro, quadra/lote, inscrição, intervalo, filtros) | ✅ |
| Vistoria completa (finalidade, obra, relatório em itens, fotos, revisão) | ✅ |
| Relatório em itens (irregularidade, relato, artigos, exigências, fotos) | ✅ |
| Foto com data/hora e coordenada **próprias**, marcação e visualizador | ✅ |
| Impressão de vistoria (HTML e PDF, numerada) | ✅ |
| Documentos: notificação, not. embargo, auto de embargo, auto de infração | ✅ |
| Lavratura, numeração, prazos, anulação | ✅ |
| Memória de cálculo da multa por m² | ✅ |
| Protocolos e ordens de serviço (com ciência e assinatura) | ✅ |
| Desenho de lote com medidas, esquadro e encaixe no vizinho | ✅ |
| Desmembramento por corte de linha (preserva o contorno) e unificação | ✅ |
| Curadoria: correção de quadra em massa, exclusão de resíduo | ✅ |
| Bairros e irregularidades em Parâmetros (CRUD) | ✅ |
| Inscrição imobiliária derivada, exibida e buscável | ✅ |
| Auditoria de tudo que altera identificação | ✅ |
| Dois temas (institucional / F) | ✅ |

## O que está pendente ou quebrado

### 🔴 O BCI não resolve para nenhum lote existente

A aba BCI da ficha continua vazia — e agora por um motivo **correto e dito**: a
única exportação carregada é do bairro **124 (Residencial Buritis Primavera
VI)**, que ainda não tem desenho importado. Os dois bairros que têm desenho
(105 e 90) não têm exportação carregada.

Para resolver: carregar a exportação XLSX dos bairros 105 e 90 com
`cadastro:carregar`.

*(O defeito que impedia o casamento — comparação do código do bairro sem tirar
o zero à esquerda — foi corrigido em `5adc833`. A consulta antiga achava 0
linhas; a corrigida acha 990.)*

### ✅ Os 101 lotes sem quadra — resolvido

Eram 101 lotes sem quadra e 4 sem número, todos no Buritis, sem inscrição
imobiliária e fora da busca por intervalo de BCI. **Corrigidos pelo usuário na
tela**, em 04–05/09, com a ferramenta de correção em massa.

Hoje a base tem **zero** lotes sem quadra e zero sem número.

### 🟡 18 das 20 irregularidades não têm artigo vinculado

Sem fundamentação legal o sistema **bloqueia a lavratura do auto**. O painel já
avisa. Depende de alimentar a legislação em Parâmetros.

### 🟡 Só 2 dos 125 bairros estão amarrados

`cadastro_bairros.nome_gis` está preenchido em 2 registros:

| código | oficial | desenho |
|---:|---|---|
| 105 | JARDIM EUROPA IV | Jardim Europa IV |
| 90 | RESIDENCIAL BURITIS PRIMAVERA V - PRIME | RESIDENCIAL BURITIS V |

Os demais só entram quando o bairro for levantado (DWG → GeoJSON → importação).

### 🟡 Importar bairro novo exige terminal

Converter DWG→GeoJSON (`gis/tools/dxf_para_geojson.py`) e rodar
`lotes:importar` só funciona da máquina de desenvolvimento. **Não há tela.**

### 🟡 Colação divergente entre `lotes` e `cadastro_bairros`

`lotes.bairro` é `utf8mb4_0900_ai_ci`; `cadastro_bairros.nome_gis` é
`utf8mb4_unicode_ci`. Juntar as duas em SQL falha ("Illegal mix of
collations"). O código contorna resolvendo nomes em PHP. **Arrumar de verdade
pede migração.**

### 🟡 "Nome no desenho" é texto livre

Precisa bater com um nome que existe no DWG, e **errar não avisa nada**: salva
quieto e o bairro simplesmente não amarra. Já aconteceu em produção — `Jardim
Europa VI` no lugar de `IV`, com o I e o V trocados, deixando 711 lotes sem
inscrição até alguém notar. Um combo com os nomes que existem no desenho
eliminaria a classe inteira de erro.

### ⚪ 6 divergências na exportação da prefeitura

Em 6 das 990 linhas, a coluna `lote` contradiz o lote embutido na própria
inscrição. Como o casamento usa as colunas, esses 6 imóveis seriam ligados ao
lote errado. Listadas por `php artisan inscricao:conferir`. **Quem decide qual
está certo é a prefeitura** — o sistema não corrige.

## Verificação disponível

| Comando | Confere |
|---|---|
| `php artisan gis:conferir` | sobreposição, sufixo de desmembramento solto, órfão |
| `php artisan inscricao:conferir` | a fórmula da inscrição contra os dados reais |

**Não há suíte de testes automatizados.** `phpunit.xml` aponta para SQLite em
memória, que não roda as migrações espaciais do MySQL. A verificação é feita no
navegador, contra o sistema real, com usuário temporário criado e removido ao
fim.

## Dívida conhecida

- `resources/views/mapa.blade.php` concentra a aplicação inteira (todas as
  telas e modais). Funciona, mas é um arquivo muito grande.
- `public/js/vistoria.js` passa de 2.900 linhas.
- Front sem módulos ES: tudo em escopo global.
- `README.md` ainda é o padrão do Laravel.
