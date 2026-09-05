# ROADMAP

> O que vem pela frente, em ordem de dependência. Cada item diz **por que**
> importa e **o que trava** se não for feito.
> Atualizado em 04/09/2026.

## Como ler

| Marca | Significa |
|---|---|
| 🔴 | trava o uso real do sistema |
| 🟡 | atrapalha, tem contorno |
| 🟢 | melhoria — nada trava |

Itens marcados **[dado]** não são código: dependem de alguém alimentar ou
conferir informação.

---

## 1 · Destravar o uso real

Sem estes, o sistema está pronto e ninguém consegue usá-lo para valer.

### 🔴 [dado] Alimentar a legislação

18 das 20 irregularidades não têm artigo vinculado, e **sem fundamentação legal
o sistema recusa lavrar o auto** — corretamente. É a maior trava isolada.

Onde: Parâmetros → Legislação. O painel já lista as que faltam.

### 🔴 [dado] Carregar o cadastro dos bairros levantados

A exportação carregada é do bairro 124, que não tem desenho. Os dois bairros
com desenho (105 e 90) não têm exportação — então a aba BCI está vazia para
**todos** os imóveis do sistema.

Onde: `php artisan cadastro:carregar <arquivo.xlsx>`.

---

## 2 · Fechar buracos que já morderam

### 🟡 Recorte de colunas em `Documento` — antes de a assinatura entrar em uso

`DocumentoController::index` traz **todas** as colunas de `documentos`, e duas
delas são assinaturas em data URL (~14 KB cada). Hoje é grátis: em produção há 4
documentos e nenhuma assinatura gravada. No dia em que o contribuinte passar a
assinar, cada abertura da lista puxa até 300 × 28 KB ≈ 8 MB para desenhar uma
tabela que não mostra assinatura nenhuma. A ficha do imóvel tem o mesmo padrão.

O remédio é o que o `Lote` já faz: constante `COLUNAS` no model, assinatura
fora, e carregamento explícito na impressão. **Fazer ANTES de a captura de
assinatura existir** — depois vira investigação de lentidão sem causa aparente,
que é o jeito caro de descobrir a mesma coisa.

Ver ARQUITETURA → "Coluna pesada e o `SELECT *`" para os números medidos.

### 🟢 Limpar as assinaturas antigas da auditoria

Treze linhas de agosto carregam PNG em base64 dentro de `dados_novos` — 613 KB,
40% da tabela. A causa está corrigida (regra por nome em `limparAuditoria`); o
que sobra é o passado. Trocar o base64 por `[omitido]`, preservando quem,
quando e os demais campos.

**É escrita sobre trilha de auditoria** — não se faz sem o usuário mandar.


### 🟡 Combo no lugar do "Nome no desenho"

Campo de texto livre que precisa bater exato com um nome do DWG, e **errar não
avisa**: salva quieto, o bairro não amarra, e o sintoma aparece dias depois
como "sumiu a inscrição". Já custou 711 lotes sem inscrição em produção por um
`VI` no lugar de `IV`.

Como: substituir o input por um `<select>` alimentado pelos bairros distintos
de `lotes`. São 2 hoje; serão dezenas.

**Custo baixo, elimina uma classe inteira de erro.** É o melhor item de
custo/benefício da lista.

### 🟡 Unificar a colação de `lotes.bairro` e `cadastro_bairros.nome_gis`

Migração `ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4 COLLATE
utf8mb4_unicode_ci`. Hoje o código contorna resolvendo nomes em PHP; a
divergência continua esperando a próxima consulta que junte as tabelas.

### 🟢 Aviso quando a amarração não casa

Ao salvar bairro com "nome no desenho" que não existe em `lotes`, dizer
"nenhum lote do desenho usa este nome" — mesmo tratamento que o sistema já dá
a "N lotes ficaram órfãos".

---

## 3 · Tirar operações do terminal

Hoje quem não tem a máquina de desenvolvimento não consegue.

### 🟡 Importar bairro pela tela

O caminho é DWG → QGIS → GeoJSON → `lotes:importar`. A conversão é do domínio
do técnico e continua fora do sistema; **a importação do GeoJSON deveria ter
tela** — com prévia (quantos lotes, quais quadras, sobreposição com o que já
existe) e confirmação.

Depende de decidir o que fazer quando o bairro já tem lotes: substituir,
completar ou recusar.

### 🟢 Carregar cadastro (XLSX) pela tela

Mesmo raciocínio, para `cadastro:carregar`.

### 🟢 Rodar as conferências pela tela

`gis:conferir` e `inscricao:conferir` produzem informação de curadoria que hoje
só existe no terminal. Caberiam em Parâmetros, como relatório.

---

## 4 · Completar o ciclo do processo

### 🟡 A sucessão na ficha — payload calculado que ninguém lê

`BuscaController::sucessao()` monta as origens e os destinos de cada lote a
**toda abertura de ficha**, e `busca.js` não usa o campo: `d.sucessao` não
aparece uma vez no front. É trabalho de banco pago em toda consulta para nada.

O resto da Etapa 5 do plano de lotes **já está pronto** (conferido em 05/09):
selo com a data no resultado da Consulta (`.bs-selo-inativo`), filtro "incluir
imóveis inativos", e "Ver o contorno antigo no mapa" desenhando só o lote,
tracejado (`dashArray '7,6'`), num pane próprio.

Falta a ficha do inativo dizer **o ato que o inativou e para quais lotes ele
foi** — que é justamente o `sucessao` já calculado. É render, não consulta.

Deixou de ser hipotético: **há 4 lotes inativos em produção** desde 04/09, da
unificação da Q21.

### 🟢 Área construída vinda das edificações

As edificações já são desenhadas e somadas; a soma vira sugestão na vistoria ao
lado da área aferida em campo. Falta usar isso na **memória de cálculo da
multa** — hoje ela usa a aferida.

Decisão pendente: qual das duas prevalece, e o que a peça imprime quando
divergem.

### 🟢 Controle de prazos com aviso ativo

Os prazos existem e o painel mostra vencidos. Falta notificação que **procure**
o fiscal (o sino já existe; não há disparo por prazo).

---

## 5 · Sustentação

### 🟢 Testes que rodem

`phpunit.xml` aponta para SQLite em memória, incompatível com as migrações
espaciais do MySQL. Ou se aponta para um MySQL de teste, ou se aceita que a
verificação é manual — mas o arquivo hoje promete algo que não entrega.

Prioridade para: `InscricaoImobiliaria`, `GeometriaPlana`, os `impedimento()`
dos serviços de sucessão. São regras puras, fáceis de testar e caras de
quebrar.

### 🟢 Quebrar `mapa.blade.php`

A aplicação inteira numa view. Blade tem `@include`; separar por tela não muda
comportamento e torna o arquivo navegável.

### 🟢 README do projeto

Ainda é o padrão do Laravel. `COMO-RODAR.md` já cobre o ambiente; falta a porta
de entrada apontar para cá.

---

## Fora de escopo por decisão

- **PostGIS** — ver ADR-001. Só se aparecer necessidade de correção topológica
  no banco, buffers reais ou vector tiles.
- **Framework de front** — sem build no servidor é o que permite `git pull`
  publicar. Trocar exigiria Node em produção.
- **Termo de Advertência** — saiu da lista de peças; o valor segue aceito pela
  coluna para não quebrar histórico.
