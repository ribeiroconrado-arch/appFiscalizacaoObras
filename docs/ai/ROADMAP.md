# ROADMAP

> O que vem pela frente, em ordem de dependência. Cada item diz **por que**
> importa e **o que trava** se não for feito.
> Atualizado em 02/10/2026.

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

### ✅ Unificar a colação de `lotes.bairro` e `cadastro_bairros.nome_gis` — feito em 10/2026

### 🟡 [dado] Contorno de todos os bairros (e das quadras)

Com o mapa afastado, o sistema mostra só o CONTORNO do bairro; um pouco mais
perto, o contorno e o número das QUADRAS. Os lotes vêm só ao aproximar mais.
Bairro sem contorno gerado fica sem nada nessas escalas, e contorno gerado
antes de 10/2026 não tem as quadras.

Como resolver: Correção cadastral → "Contorno dos bairros" → **Gerar todos**.
Ele calcula de novo o contorno e as quadras de cada bairro, um por vez. Bairro
que falhar aparece no aviso; o "Gerar" da linha mostra o motivo (em geral lote
isolado, com coordenada errada).

### 🟢 Aviso quando a amarração não casa

Ao salvar bairro com "nome no desenho" que não existe em `lotes`, dizer
"nenhum lote do desenho usa este nome" — mesmo tratamento que o sistema já dá
a "N lotes ficaram órfãos".

---

## 3 · Tirar operações do terminal

Hoje quem não tem a máquina de desenvolvimento não consegue.

### ✅ Importar bairro pela tela — feito em `bd34623` (02/10)

Rascunho → salva → publicada, com pré-curadoria e conferência com o cadastro.
A conversão DWG → GeoJSON continua fora do sistema, como planejado.

### ✅ Carregar cadastro (XLSX) pela tela — feito em 10/2026

Parâmetros → Cadastro municipal. Grava só a diferença, guarda o histórico
campo a campo e apaga o arquivo ao fim. Ver ARQUITETURA.md, "Cadastro municipal".

### 🟢 Cadastro tratado fora do sistema (app desktop → JSON de diferenças) — ideia registrada, adiada

Decisão de rumo, ainda sem data. A planilha do município deixa de entrar no
sistema: um **app desktop**, na prefeitura, lê o Excel e gera um **JSON só com
as diferenças em relação à importação anterior**. Esse JSON é **anexado dentro
da aplicação** (não há envio automático do app para o servidor), e a
integração parte dele.

Motivos (todos de segurança):
- a planilha bruta, com todas as colunas, não passa mais pelo servidor;
- CPF/CNPJ e colunas sem uso podem ser descartados antes, no próprio PC;
- o que entra é pequeno e verificável, não um arquivo de 12 MB;
- reduz o efeito de alguém anexar uma planilha errada ou adulterada.

A decidir quando for feito:
- **"Importação anterior" sem cópia da base no PC.** Guardar a planilha
  anterior no computador cria uma segunda cópia com dados pessoais e pode
  dessincronizar do servidor (JSON perdido, aplicado duas vezes ou fora de
  ordem). Alternativa: o sistema exporta um arquivo só com `inscrição → hash`
  (sem dado pessoal; o hash já existe em `cadastro_externo_imoveis.hash`),
  o app compara o Excel contra ele e gera o JSON.
- **Mesma regra de normalização dos dois lados**, senão o hash não bate. De
  preferência o app normaliza e calcula, e o servidor grava o hash recebido.
- **Conferência do JSON ao anexar:** versão do formato, a qual carga anterior
  ele se refere (recusar fora de ordem ou repetido) e, se possível, assinatura
  do app.
- **Onde fica o CPF/CNPJ**: hoje é mostrado a agentes e administradores e
  usado na lavratura.
- O servidor reaproveita `CargaDoCadastro` (diferença, ausência por bairro,
  trava de 20%, histórico), trocando só a leitura do Excel pela do JSON. O JSON
  precisa trazer a lista de inscrições presentes para marcar as ausentes.
- Linguagem e distribuição do app.

Até lá vale o envio do `.xlsx` por Parâmetros → Cadastro municipal.

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

## 5 · Sustentação — refatoração planejada

Levantada na revisão de 02/10/2026. Nada aqui muda comportamento: é para o
código continuar navegável e para um erro aparecer antes da produção. **Um PR
por item**, nessa ordem, e cada um conferido no navegador (Herd) antes do
merge. A suíte automatizada cobre pouco das telas.

### 🟢 1. Função única para falar com o servidor — risco baixo

Cada `fetch` monta o próprio cabeçalho `X-CSRF-TOKEN` (23 lugares em
`public/js`). Criar em `ui.js` uma função única (`api(url, {method, body})`)
que ponha CSRF, `Accept: application/json` e trate 419/422/500 de um jeito só,
e trocar as chamadas. Quando a sessão expira (419), hoje cada tela reage de
um jeito.

### 🟢 2. CI no GitHub Actions — risco baixo

Nada roda sozinho a cada push. Um workflow com PHP 8.4 rodando
`php artisan test` e `npm test` (Node, sem dependências). Os
`tests/*-backend.php` (MySQL real) e `tests/*-browser.cjs` (navegador local)
ficam de fora.

Junto: decidir o que fazer com `phpunit.xml` em SQLite, que não roda as
migrações espaciais — ou um MySQL de teste no CI, ou aceitar que a suíte PHP
cobre só regra pura. Prioridade para regras puras e caras de quebrar:
`InscricaoImobiliaria`, `GeometriaPlana` (já tem teste), os `impedimento()`
dos serviços de sucessão.

### 🟢 3. Quebrar `mapa.blade.php` — risco baixo a médio

3.592 linhas: a aplicação inteira numa view, com 37 blocos `<script>`.
Separar em `resources/views/mapa/*.blade.php` por tela/painel com `@include`.
Não muda o HTML gerado — conferir comparando a saída antes e depois.

### 🟢 4. Organizar `tests/` — risco baixo

Hoje convivem PHPUnit (`Feature/`, `Unit/`), testes Node (`*.test.cjs`),
testes de navegador (`*-browser.cjs`) e diagnósticos contra o banco
(`*-backend.php`). Separar em `tests/js/`, `tests/browser/` e
`scripts/diagnostico/`, ajustando `package.json` e `COMO-RODAR.md`.

### 🟢 5. Dividir `vistoria.js` e `cadastro.js` — risco médio

2.958 e 2.147 linhas. Dividir por responsabilidade (formulário, fotos,
relatório em itens, impressão / mesa, desfazer, curadoria). Sem módulos ES, a
ordem dos `<script>` em `mapa.blade.php` passa a importar: documentar a ordem
no topo de cada arquivo.

### ✅ 6. Consolidar os temas de CSS — feito em 10/2026 (parcial)

As três paletas (âmbar, institucional e cinza, que substituiu o azul) estão em
`temas.css`, só como tokens; saíram `tema-institucional.css` e `tema-azul.css`,
os seletores `html[data-tema=…]` das folhas de componente, a barra `.acoes`
(sem uso) e 127 declarações que outra regra, mais adiante, sempre vencia.
Conferido por captura de tela antes/depois: institucional e âmbar ficaram
idênticos pixel a pixel.

Falta, se valer o esforço:
- `tema-f.css` (3.200 linhas) continua sendo a folha de componentes com nome
  de tema; renomear para `componentes.css` toca as duas views e os testes.
- `app.css` ainda tem fragmentos de regras que `tema-f.css` completa (`.badge`,
  `#toast`, `.ctrl-btn`). Juntar cada componente num lugar só.
- `prancheta-cadastral.css` tem verdes fixos (`#009b3a`, `#f4f7f5`…) que não
  seguem o tema.

### 🟢 7. Emagrecer os controllers grandes — risco médio

`VistoriaController` (879 linhas), `CadastroLoteController` (793),
`BuscaController` (771). Levar regra de negócio para `app/Services` — o padrão
já existe e funciona (`LavraturaService`, `DesmembramentoDeLote`…) — e deixar
no controller só validação, autorização e resposta.

### ✅ README do projeto — feito em 02/10

---

## Fora de escopo por decisão

- **PostGIS** — ver ADR-001. Só se aparecer necessidade de correção topológica
  no banco, buffers reais ou vector tiles.
- **Framework de front** — sem build no servidor é o que permite `git pull`
  publicar. Trocar exigiria Node em produção.
- **Termo de Advertência** — saiu da lista de peças; o valor segue aceito pela
  coluna para não quebrar histórico.
