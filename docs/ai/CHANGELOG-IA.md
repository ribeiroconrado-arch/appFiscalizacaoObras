# CHANGELOG

> O que foi construído, em ordem, e **o que cada bloco resolveu**. Serve para
> entender por que o código está como está — a mensagem de commit costuma
> guardar a razão inteira.
> 72 commits · 18/08/2026 → 04/09/2026 · atualizado em 04/09/2026.

## Como ler

Os commits estão agrupados por bloco de trabalho, não um a um. Quando uma
decisão de projeto está registrada, ela aparece como **↳ decisão**.

---

## 18–19/08 · Fundação

`6835dd3` `9532cb8` `635a1f2` `00b5229` `83bbb81`

Primeira versão: mapa, lotes, ficha, perfil de usuário, parâmetros em modal.
Integração com o mapa do Google.

↳ **decisão** (ADR-001): banco espacial é **MySQL 8 Spatial**, não PostGIS —
os lotes chegam do DWG já fechados e válidos, e não há correção topológica a
fazer no banco.

---

## 21–24/08 · Cadastro, publicação e o iOS

`618f3f1` `25ca672` `5b420bb` `76eb8f0` `ee92e09` `3d5d991` `516affe`
`30e44b2` `363809a`

Correção cadastral pelo mapa (quadra, desenho, unificação, desmembramento).
Ficha do imóvel em faixas, com a aba do cadastro imobiliário. Carga do cadastro
da prefeitura por planilha, **atrás de uma interface** (`FonteDoCadastro`), para
que trocar a fonte um dia não espalhe mudança pelo sistema.

Login por matrícula ou e-mail, e o que a publicação na internet exigiu.

↳ **decisão**: o mapa passa a renderizar em **canvas**. 2.239 lotes como
`<path>` travavam o iOS. Consequência: não há mais elemento DOM por lote — o
que muda como se faz hit-test e rótulo.

↳ **decisão**: **duas chaves do Google**, não uma — a do servidor travada por
IP, a do navegador por referrer. Uma só obrigaria a deixá-la sem restrição.

---

## 27–28/08 · A vistoria nasce

`7d9c0a3` … `37c2ac6` (ajustes de mapa e botões)
`1337027` `e2b694a` `8a7e3d0` `9b6e9ed` `e139296` `1ee317a` `a08ec79` `19cf7db`

**A vistoria de obra em cinco passos.** Depois: a vistoria vira um **relatório
— uma lista só, montada na ordem em que se conta**, em vez de listas paralelas
que podiam discordar.

**A finalidade decide o que se pergunta**: numa atualização cadastral não se
pergunta fase de obra, e o campo some em vez de ficar vazio.

**Ordem de serviço**: a coordenação determina, o fiscal cumpre — com via em
papel, ciência do fiscal e assinatura de quem determina.

---

## 29/08 · O ciclo fecha, e a interface se organiza

`3745e52` … `b46d845` (14 commits)

Sessão expirada leva ao login em vez de falhar tela a tela. O painel abre pelo
**trabalho**, não pelos números. As três listas viram tabela no computador. Uma
largura só para as janelas de trabalho.

`c5a08f4` — **a vistoria fecha o ciclo**: numerada, impressa, e gerando o ato
preso a ela. A diferença importa numa obra visitada duas vezes no mês: sem
isso, o auto sairia amarrado à visita errada.

`b46d845` — o cabeçalho A4 passa a ser o do AppPOSTURAS e **mora num lugar só**;
estava duplicado.

---

## 31/08 · Curadoria da base

`0c06a96` `3de29cb` `be1e6fa` `a72f55d` `d3f2f54`

Curador do cadastro pode apagar resíduo e executar ato direto, sem protocolo.
Apagar lote sai da ficha e vai para o mapa, com seleção múltipla.

`be1e6fa` — **o item do relatório vira um GRUPO**: irregularidades, texto,
artigos, exigências e fotos, tudo sobre o mesmo ponto da obra.

---

## 02–03/09 · A mesa de edição de lotes

`8b8b0c3` `aa8b5b8` `e5db14a` `8f251ad`

Desenho com **medida do lado, trava em 90°/45° e medida digitada**; bairros em
Parâmetros; edificações dentro do lote; desmembramento por **corte de linha**,
que preserva o contorno externo.

↳ **decisão**: a conta é feita num **plano local em metros**, espelhando
`GeometriaPlana::projetar`. Ângulo reto calculado em graus fica certo na tela e
errado no terreno; haversine dava ~0,4% de erro, que não serve para medida
cadastral.

↳ **decisão**: o motor de desenho continua **próprio**. O encaixe em vértice do
vizinho elimina a fresta na origem em vez de mascará-la com tolerância —
nenhuma biblioteca genérica sabe que os polígonos ao lado são lotes que
precisam compartilhar divisa.

Depois: fechar o contorno vira **ajuste** (os cantos se arrastam), e o corte
divide em várias partes.

---

## 03/09 · O item da vistoria, refeito em camadas

`ae1a834` `369bbce` `28711ed` `61a1cb7` `ceb2d0b` `4578d09` `ad1a57e` `e85dce8`

Sete iterações sobre a mesma janela, cada uma respondendo a uma crítica de uso:

1. **cinco botões** com contagem, no lugar do despejo de tudo
2. **combo + resumo fixo**, igual em todas as abas
3. **escolher deixa de adicionar** — quem põe na lista é o `+add`
4. a foto ganha **ficha própria** (legenda, fachada, marcas) antes de entrar
5. **combobox de verdade** (flutuante), relato vira lista, `×` pergunta antes
6. as setas de aba vão para o **rodapé**, onde o sistema já as punha
7. o `+add` passa para **dentro do campo**

↳ **decisão**: cada foto grava **a sua** data/hora e coordenada. As colunas
existiam desde a primeira migração e vinham preenchidas com os dados da
vistoria, iguais para todas.

↳ **erro corrigido no caminho**: `toISOString()` adiantaria a foto em 4h em
Cuiabá. O próprio `ui.js` já advertia contra isso.

`1304c07` — a vistoria passa a abrir **sem lote selecionado**, com localizador
de imóvel no passo 1. Era a única das cinco peças que recusava.

---

## 03/09 · Cor: três passagens

`369bbce` `28711ed` `889be76`

A regra: verde só em menu, cabeçalho, ícones e botões; o resto em
branco/preto/cinza.

Custou três passagens porque as duas primeiras foram feitas por busca textual
no CSS, e a terceira — feita olhando a tela — ainda achou os dois pontos que
mais apareciam: o `.sec-title` (título numerado, presente em todo modal) e a
**aba ativa**.

↳ **lição**: auditoria visual por `grep` não pega gradiente, `box-shadow` nem
regra herdada. Olhar a tela achou o que três buscas não acharam.

---

## 03–04/09 · A inscrição imobiliária

`5adc833` `6fb0f94` `e4c96e2` `6e7d6b8` `fea0041`

O bloco mais denso, e o que mais desenterrou defeito.

**`5adc833`** — a inscrição vira regra do sistema, num lugar só
(`InscricaoImobiliaria`), **derivada** de bairro + quadra + lote + variação em
vez de lida de uma coluna vazia. Provada contra as 990 inscrições reais da
prefeitura: 984 batem; as 6 restantes são divergência da exportação.

No mesmo commit, **dois defeitos que ninguém tinha visto**:

- o casamento com o cadastro comparava o código do bairro **sem tirar o zero à
  esquerda** (`124` contra `000124`). A consulta antiga achava **0** linhas; a
  corrigida acha **990**. A aba BCI nunca funcionou para lote nenhum — e
  falhava com a mesma mensagem de quem não tem cadastro carregado, o que
  escondia o defeito atrás de uma explicação plausível.
- `lotes.bairro` e `cadastro_bairros.nome_gis` têm **colações diferentes**; o
  MySQL recusa compará-las.

**`6fb0f94`** — havia um **segundo montador de inscrição, em JavaScript**, que
escrevia `000` no lugar do bairro que não conhecia — inclusive no resumo da
peça. O comentário dele dizia "melhor um campo visivelmente incompleto do que
um número plausível e errado"; a intenção estava certa e o efeito era o oposto.

**`e4c96e2`** — regressão introduzida no commit anterior: ao trazer o mapa de
bairros para a memória (para não consultar o banco por lote), a comparação
virou índice de array PHP, **byte a byte**. O MySQL comparava sem diferenciar
maiúsculas. A amarração feita em produção parou de valer, sem erro nenhum.

↳ **lição**: otimização que muda a **semântica** junto com o desempenho quebra
em silêncio. O mesmo lote devolvia o número certo por um caminho e nulo por
outro.

**`6e7d6b8`** — o filtro de **intervalo** ficara de fora: continuava lendo a
coluna vazia. Derivar em PHP resolve exibir, não resolve filtrar — a fórmula
passa a ser escrita **também em SQL**.

**`fea0041`** — a regra dos dois nomes: **fora do mapa, o bairro é o do
cadastro**; o do desenho fica só no rótulo do mapa. Um auto de infração que
cite bairro pelo apelido da planta cita bairro que não existe no registro.

---

## Padrões que este histórico revela

**Interface se acerta iterando com o usuário.** A janela do item de vistoria
foi refeita sete vezes em um dia. Nenhuma das versões estava "errada" — cada
crítica revelou um uso que a anterior não tinha previsto.

**Defeito velho aparece quando o dado começa a ser usado.** O BCI nunca
funcionou, e ninguém sabia, porque a mensagem de erro era plausível. Só quando
a inscrição passou a ser derivada é que a comparação foi olhada de perto.

**Verificar contra o dado, não contra o enunciado.** A fórmula da inscrição foi
dita de boca e conferida contra 990 registros reais. As 6 divergências que
apareceram são da exportação da prefeitura — e não teriam aparecido numa
conferência "no papel".

**Produção difere do ambiente local.** Dois defeitos deste histórico só
existiam lá, porque o dado era diferente.
