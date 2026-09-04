# ARQUITETURA

> Como o código está organizado, e as razões que explicam as escolhas estranhas.
> Atualizado em 04/09/2026.

## Pilha

| | |
|---|---|
| PHP | 8.4 (mínimo declarado: 8.3) |
| Laravel | 13.x |
| Banco | MySQL 8.0.46 — **espacial**, base `fiscalizacao_obras` |
| Mapa | Leaflet 1.9.4 (CDN unpkg) |
| PDF | `barryvdh/laravel-dompdf` 3.1 |
| Front | **JavaScript puro**, sem build, sem framework |
| CRS | armazenamento **EPSG:4326** · origem EPSG:31981 (SIRGAS 2000 / UTM 21S) |

### Por que MySQL espacial e não PostGIS

Decidido em ADR-001 com critério fixado antes do diagnóstico: PostGIS só se
aparecesse necessidade de correção topológica no banco, buffers reais ou vector
tiles. Não apareceu — os lotes chegam do DWG já fechados e válidos, e a
poligonização acontece no QGIS, antes da importação.

**Consequência que atrapalha:** `ST_Centroid` não é implementado para SRS
geográfico no MySQL. Onde seria natural usá-lo, o código pega o primeiro
vértice do anel externo (`ST_PointN(ST_ExteriorRing(geom), 1)`) — para
centralizar o mapa num lote de 12 m o erro é irrelevante.

**Armadilha do eixo:** em SRID 4326 o MySQL guarda lat/long, então `ST_X`
devolve a **latitude**. Consultas que trocam isso não falham — devolvem vazio,
ou o lote errado. É o erro mais caro de diagnosticar do módulo GIS, e está
comentado no topo de `LoteRepository`.

### Por que front sem build

Não há Node no servidor nem etapa de compilação: os `.js` e `.css` são servidos
como estão, com cache-busting por `@assetv` (parâmetro de versão pelo mtime).
O custo é não ter módulos ES nem TypeScript; o ganho é que um `git pull` no
servidor já publica o front, e o fiscal em campo carrega arquivos pequenos.

## Mapa do código

```
app/
├── Cadastro/          ponte com o cadastro imobiliário da prefeitura
│   ├── FonteDoCadastro.php      (contrato)
│   ├── CadastroCarregado.php    (implementação: exportação XLSX carregada)
│   ├── BairrosDoDesenho.php     (nome do desenho ↔ código/nome oficial)
│   ├── RetratoBci.php           (o que a consulta devolve)
│   ├── SincronizaBci.php
│   └── LeitorXlsx.php
├── Console/Commands/  7 comandos de manutenção da base
├── Http/Controllers/  16 controllers, todos finos
├── Models/            22 modelos + Bci/ + Concerns/
├── Providers/
├── Repositories/
│   └── LoteRepository.php       TODA consulta espacial passa por aqui
├── Services/          11 serviços — é onde vivem as regras
└── Support/
    ├── GeometriaPlana.php       projeção local em metros
    └── InscricaoImobiliaria.php formato da inscrição, num lugar só
```

### A regra mora no Service, não no Controller

Os controllers validam, chamam e devolvem JSON. Quem decide é o serviço:

| Serviço | Responde |
|---|---|
| `LavraturaService` | que artigos enquadram as irregularidades constatadas |
| `UnificacaoDeLotes` | dois lotes podem virar um? o que isso produz? |
| `DesmembramentoDeLote` | um lote pode virar N? as partes preservam o contorno? |
| `SucessaoDeLotes` | quem sucedeu quem, e o que fica pendurado no baixado |
| `DesenhoDeLote` | o polígono desenhado é aceitável (não sobrepõe, fecha figura)? |
| `QuadraDoQuarteirao` / `QuadraDeLotesSelecionados` | correção de quadra em massa |
| `DocumentoImpressao` / `VistoriaImpressao` | montam o que vai para o papel |
| `CabecalhoOficial` | o cabeçalho A4 do município, num lugar só |
| `Assinatura` | assinatura em canvas, aparada |

Cada um desses expõe um par **`impedimento()` / `aplicar()`**: primeiro se
pergunta se dá, e a resposta é o *motivo* de não dar; só então se executa. É o
que permite a tela mostrar "por que não" antes de o fiscal preencher tudo.

### O front por módulo

22 arquivos em `public/js/`, um por assunto, todos em escopo global (sem
módulos ES — ver acima):

| Arquivo | Assunto |
|---|---|
| `app.js` | navegação entre telas, ficha do imóvel, utilidades |
| `ui.js` | modais, toast, confirmação, data/hora, setas de aba |
| `mapa.js` | Leaflet, camadas, seleção, balão |
| `mapa-cores.js` | pintura temática do mapa |
| `geo.js` / `coordenadas.js` | distâncias, formatação de coordenada |
| `desenho.js` | **motor de desenho próprio** (ver abaixo) |
| `corte.js` | corte de polígono por linha |
| `cadastro.js` | mesa de edição cadastral |
| `desmembramento.js` / `edificacoes.js` | atos sobre a geometria |
| `vistoria.js` | o formulário de vistoria (o maior) |
| `documento-form.js` / `documentos.js` | peças |
| `protocolos.js` / `os.js` | protocolo e ordem de serviço |
| `busca.js` | consulta de imóveis |
| `painel.js` | painel inicial |
| `parametros.js` / `perfil.js` | configuração |
| `cadastro-imobiliario.js` | aba BCI |
| `tema.js` | troca de tema (carregado **sem defer**, antes do primeiro pintar) |

**Uma view só:** `resources/views/mapa.blade.php` é a aplicação inteira —
todas as telas e modais, mostrados e escondidos por JS. `login.blade.php` é a
única outra tela real; `impressao/` são os documentos.

## O motor de desenho é próprio

`desenho.js` não usa Leaflet.draw nem geoman. Desenha polígono, arrasta
vértice, insere e remove ponto, trava ângulo em 90°/45°, aceita medida digitada
e encaixa no vértice do vizinho.

**Por que próprio:** o encaixe em vértice do lote vizinho elimina a fresta na
origem, em vez de mascará-la com tolerância. Nenhuma biblioteca genérica faz
isso porque nenhuma sabe que os polígonos ao lado são lotes que precisam
compartilhar divisa.

**A conta é feita num plano local em metros** (`planoLocal()` no JS,
espelhando `GeometriaPlana::projetar` no PHP): elipsoide WGS84, raio meridional
+ raio normal. Não é aproximação esférica. Ângulo reto calculado em graus fica
certo na tela e errado no terreno — e a diferença já foi medida em ~0,4% com
haversine, o que não serve para medida cadastral.

**Se mexer nas medidas do desenho, mexa nos dois lados.** A projeção existe
duplicada de propósito (PHP valida, JS desenha) e as duas têm de concordar.

## Fluxo de uma requisição típica

```
navegador                     Laravel                        MySQL
    │
    │ GET /api/mapa/lotes?bbox=…
    ├──────────────────────────►│
    │                           │ MapaController
    │                           │   └─► LoteRepository::porBbox
    │                           │         ST_Within + ST_AsGeoJSON  ──►│
    │                           │   └─► BairrosDoDesenho (1 consulta,
    │                           │        memorizada por requisição)
    │ ◄─── FeatureCollection ───┤
    │      properties: bairro (desenho) + bairro_oficial + inscricao derivada
```

**Detalhe que importa:** o mapa devolve milhares de feições por chamada.
Qualquer derivação por lote tem de ser resolvida **uma vez** e guardada em
memória — foi assim que `BairrosDoDesenho` nasceu. Derivar consultando o banco
por linha põe o mapa de joelhos.

## A inscrição imobiliária

Formato `XX.XXX.XXX.XXXX.XXX` = setor(2) + bairro(3) + quadra(3) + lote(4) +
variação(3). 15 dígitos, largura fixa.

**É derivada, não guardada.** A coluna `lotes.inscricao_imobiliaria` existe e
tem precedência, mas está vazia nos 2.239 lotes — o desenho vem do DWG e não a
traz. Guardar cópia do que se calcula criaria duas verdades, que divergiriam na
primeira renumeração de quadra.

Um lugar só conhece o formato: `App\Support\InscricaoImobiliaria`. Ele foi
conferido contra as 990 inscrições reais da prefeitura (`inscricao:conferir`):
**984 batem**; as 6 restantes são divergência da exportação, não da fórmula.

Como o intervalo de busca precisa ser um `WHERE`, a mesma fórmula é escrita
**também em SQL**, em `BuscaController::inscricaoEmSql()`. As duas têm de
concordar.

## Auditoria

O trait `App\Models\Concerns\RegistraAuditoria` registra criação, alteração e
exclusão na tabela `auditoria`, com nome e matrícula de quem fez. Quando roda
por terminal, grava `terminal: <comando>` — o que é honesto, mas significa que
**correção feita por script não leva o nome de ninguém**. Alteração de
identificação de imóvel deveria ser feita pela tela.

Modelos com auditoria têm `acaoAuditoria()` sobrescrito para dizer o que
mudou em vez de "alterou" genérico: `Lote` distingue "corrigiu quadra",
"renumerou", "alterou inscrição", "baixou", "reativou".

## Armadilha do `Lote::COLUNAS`

`Lote` restringe o `SELECT` a uma lista fixa de colunas — `geom` fica fora de
propósito (viria como WKB binário e estouraria a serialização JSON).

**Coluna nova que não entrar em `COLUNAS` fica invisível ao Eloquent.** Não dá
erro: `$lote->campoNovo` devolve `null`, o filtro passa como se estivesse tudo
certo, e a auditoria compara contra um original que não tem o campo. Quem
acrescenta coluna em `lotes` acrescenta ali também.

## Duas colações diferentes — cuidado ao juntar tabelas

`lotes.bairro` é `utf8mb4_0900_ai_ci`; `cadastro_bairros.nome_gis` é
`utf8mb4_unicode_ci`. **O MySQL recusa comparar as duas diretamente** — "Illegal
mix of collations". Consulta que junte as tabelas quebra.

Onde isso aparece, o código resolve os nomes em PHP e compara com literais. A
divergência de esquema continua lá, esperando uma migração.

## Comandos de manutenção

| Comando | Faz |
|---|---|
| `lotes:importar` | carrega o GeoJSON convertido do DWG |
| `cadastro:carregar` | carrega a exportação XLSX do cadastro da prefeitura |
| `gis:conferir` | procura defeito na base (sobreposição, sufixo solto, órfão) |
| `inscricao:conferir` | prova a fórmula da inscrição contra os dados reais |
| `quadras:corrigir` / `quadra:semente` | correção de quadra em massa |
| `assinaturas:aparar` | apara o canvas das assinaturas |
