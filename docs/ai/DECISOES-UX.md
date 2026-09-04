# DECISÕES DE UX

> As escolhas de interface e **o que as motivou**. Cada uma custou uma
> descoberta; desfazê-las sem saber o porquê refaz o problema.
> Atualizado em 04/09/2026.

## O princípio que governa o resto

**A tela mostra o que TEM, não o que PODE ter.**

A janela do item de vistoria já despejou de uma vez o catálogo inteiro de
irregularidades, mais formulário de artigo, mais de exigência, mais as fotos —
tudo aberto, para preencher talvez um deles. Hoje são cinco abas com contagem:
sem abrir nada dá para ver que o item tem duas irregularidades e nenhuma foto.

O mesmo princípio aparece em vários lugares: campo que não pertence à
finalidade escolhida **some** em vez de ficar vazio (um campo vazio faz parecer
que alguém olhou e não achou).

## Campo e escritório são dois contextos

| | Agente em campo | Curador no escritório |
|---|---|---|
| Aparelho | celular, de pé, ao sol | monitor grande |
| Gesto | dedo | mouse |
| Pressa | muita | nenhuma |
| Tela | painel flutuante sobre o mapa | mesa lateral fixa |

Acima de 1000px o lançador cadastral vira **mesa lateral fixa**; abaixo,
continua o painel flutuante que cabe no celular. Não é responsividade
cosmética: são dois desenhos para dois trabalhos.

**Alvos de dedo:** o `.btn.sm` do sistema tem 31px — cabe numa lista de
escritório, não numa mão só, de pé, ao sol. Só as ações que a vistoria executa
**em campo** crescem para 44px (GPS, atalhos, campos de exigência). Botão que
abre janela não entra nessa lista.

## O relatório de vistoria em itens

Um item é **um ponto da obra**, com tudo que se tem a dizer sobre ele:
irregularidade, relato, artigos, exigências e fotos.

Antes eram listas paralelas — marcava-se a irregularidade num lugar e
escrevia-se sobre ela em outro, e as duas podiam discordar. A ordem entre itens
é informação: a foto logo depois do artigo que ela ilustra conta algo que a
mesma foto no fim de uma pilha não conta.

**Os artigos da vistoria são a soma do que os itens citaram.** Uma verdade só,
derivada — não uma segunda lista para manter sincronizada.

### Escolher não é adicionar

Tocar numa sugestão **preenche o campo**; quem põe na lista é o `+add`. Antes o
toque já lançava, o que deixava o botão ao lado sem função e — pior — não dava
chance de escolher "como entra" (citação ou parecer) antes de o artigo já estar
dentro.

O `+add` fica **dentro da moldura do campo**. Solto ao lado, virava um terceiro
elemento disputando a largura da linha, e no campo de relato ficava pendurado
no canto de uma caixa três vezes mais alta que ele.

### O resumo é o mesmo em todas as abas

O que já está no item aparece embaixo, igual em qualquer aba — inclusive as
fotos, com miniatura. A foto já foi reduzida a "3 foto(s) anexada(s)" no
resumo: era escondê-la de quem não estivesse na aba Fotos, e a foto é a prova.

Cartões cinza sobre fundo branco, com **× vermelho sem moldura** que **pergunta
antes**. Remover é gesto de um toque, sem desfazer, a poucos pixels do texto do
cartão — num celular, em campo, o dedo erra.

## A foto tem três tempos

Escolher o arquivo → **descrever** (legenda, fachada, marcas) → anexar.

Antes a foto entrava na lista no instante da escolha, sem chance de dizer o que
mostra. Escolher cinco de uma vez continua valendo: viram fila, atendida uma a
uma.

**Câmera e Galeria são dois botões**, com ícone. Um input só, com
`capture="environment"`, forçava a câmera e impedia pegar da galeria ou anexar
PDF de projeto. Em cinza, não verde: os dois caminhos valem o mesmo, e cor de
ação principal em ambos não escolheria nada.

**Cada foto carrega a sua data/hora e a sua coordenada.** As colunas existiam
desde a primeira migração e vinham preenchidas com os dados *da vistoria*,
iguais para todas. Num processo é a foto que precisa dizer quando e de onde: a
vistoria pode ser lançada horas depois, e o fiscal anda pelo terreno entre uma
foto e outra.

**Ver e editar são coisas diferentes.** O olho abre o visualizador (foto
grande, setas que dão a volta, contador, pinos e legenda — o mesmo do
AppPOSTURAS); o lápis abre a ficha de correção. Já foram dois botões fazendo a
mesma coisa.

## Navegação entre abas

Todo formulário de várias abas tem as quatro setas **no rodapé**, à esquerda das
ações — que é onde o formulário de documento já as punha, e onde a mão vai
parar de qualquer jeito para gravar.

Não dão a volta nas pontas: num formulário de peça, saltar da última para a
primeira parece perda do que foi digitado. A seta que não leva a lugar nenhum
aparece **desligada**, não some — botão que desaparece faz os outros dançarem
de posição a cada troca de aba.

## Combobox de verdade

A lista de sugestões **flutua** ancorada no campo. Já foi um bloco no fluxo:
abrir o combo empurrava o formulário para baixo e a própria lista ganhava uma
barra de rolagem no meio da tela — o oposto do que um combo faz. Fecha ao
clicar fora.

## O que a tela recusa, e quando

**A obrigatoriedade fica onde o ato acontece, não onde a tela abre.**

- "Novo documento" abre as cinco peças **sem imóvel**. A inscrição é informada
  depois, na aba Imóvel; a exigência existe, mas na lavratura.
- A vistoria também abre sem lote selecionado, com localizador de imóvel no
  passo 1. Era a única das cinco que recusava, obrigando a fechar o menu, achar
  o lote no mapa e recomeçar. Quem manda **gravar** sem imóvel volta ao passo 1
  com o cursor no campo.
- Divergência entre área digitada e área do desenho **avisa, não bloqueia**.
  Campo obrigatório que atrapalha vira dado inventado.

## Confirmação e reversibilidade

Ação sem volta pergunta antes, com o nome do que vai sair — `confirmarAcao()`
substitui o `confirm()` nativo em todo módulo. O botão fica "Aguarde…" durante
o `await`, e o modal só fecha quando a ação resolve.

Exclusão que a história impede é **recusada com o motivo**: "excluir bairro em
uso" diz quantos lotes o usam; irregularidade já usada em vistoria sugere
desmarcar "Ativa" em vez de apagar — preserva a leitura das vistorias antigas.

## Mapa

- **Duplo toque fora de um lote** larga a seleção, como o Esc já fazia.
- Duplo toque **no** lote aproxima e mantém a seleção.
- Cursor de mira enquanto desenha: o mapa deixa de ser algo que se arrasta e
  passa a ser superfície de desenho, e o ponteiro tem de dizer isso.
- O balão identifica o imóvel pelo **nome oficial**, para concordar com a
  inscrição que mostra logo abaixo. O nome do desenho fica para o rótulo
  escrito sobre o mapa.

## Painel

Ordem da tela = ordem da pergunta. Quem abre o sistema de manhã não quer saber
quantos autos saíram nos últimos 30 dias: quer saber **o que precisa dele
hoje**. Por isso o trabalho vem primeiro e o número depois — e os filtros
descem junto com o que eles filtram (avisos e pendências não são filtrados por
período).

De 1240px para cima, avisos, pendências e atividade dividem a linha. Entre 900
e 1240 a atividade atravessa embaixo: é a mais larga das três e a primeira a
ficar ilegível espremida.

## O vazio DITO

"Não informado" e "não perguntado" são coisas diferentes num processo. Onde o
dado falta, a tela escreve o que sabe:

- inscrição que não dá para montar → **"sem inscrição"**, nunca `01.000...`
- irregularidade sem artigo cadastrado → avisa quais ficaram sem
  fundamentação, porque o fiscal veria três artigos sugeridos e concluiria que
  as cinco marcações estão cobertas

## Erros que esta tela já cometeu

Registrados para não voltarem:

| Erro | Sintoma | Lição |
|---|---|---|
| `montarInscricao` no navegador | escrevia `000` no bairro que não conhecia | número plausível e errado é pior que campo vazio |
| Otimizar trocando SQL por array PHP | comparação virou sensível a maiúsculas; a amarração parou de valer sem erro nenhum | otimização que muda semântica quebra em silêncio |
| Validação incompleta no `$request->validate()` | campo aninhado não declarado sumia do payload validado | perda de dado silenciosa; declarar todo campo que deve sobreviver |
| `toISOString()` para hora local | adiantaria a foto em 4h em Cuiabá | o banco guarda hora de parede |
