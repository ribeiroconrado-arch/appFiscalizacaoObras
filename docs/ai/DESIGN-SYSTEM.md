# DESIGN SYSTEM

> Tokens, componentes e as regras de cor. Se for criar tela nova, use o que já
> existe — quase tudo já tem nome.
> Atualizado em 04/09/2026.

## Arquivos

| Arquivo | Papel |
|---|---|
| `public/css/app.css` | base: tokens, `.btn`, `.field`, `.badge`, `.sec-title`, modais |
| `public/css/tema-f.css` | os componentes do sistema (o grosso do CSS) |
| `public/css/tema-institucional.css` | **só redefine tokens**, sob `html[data-tema="institucional"]` |

Um tema é **um bloco de tokens**, nada mais. Sem o atributo no `<html>`, o
arquivo institucional é inerte — é por isso que os dois convivem sem custo.

`tema.js` é carregado no `<head>` **sem `defer`**, de propósito: aplicado
depois, o tema salvo entraria por cima de um quadro já pintado e a tela
piscaria a cada carregamento. Ele troca também a marca (favicon, logo).

## Tokens

```css
/* marca */
--g:#009B3A  --gd:#006B28  --gxd:#004D1C  --gl:#E8F5E9  --gm:#A5D6A7

/* superfícies neutras */
--bg:#F2F5F2  --sur:#FFF  --bord:#D8E4D8  --blt:#EDF3ED

/* texto */
--tx:#0F1F0F  --tx2:#4A5E4A  --tx3:#7A8E7A  --chumbo:#37474F

/* semântica */
--red:#C62828  --rlt:#FFEBEE     erro / exclusão
--warn:#E65100 --wlt:#FFF3E0     aviso
--gold:#F5C400 --gold-dk:#B8860B rascunho / prazo
--blue:#1565C0 --blt2:#E3F2FD    informação

/* forma */
--r:10px  --rl:14px  --sh / --shl (sombras)
```

## A regra de cor

**Verde é do sistema, não do conteúdo.**

| Fica verde | Fica cinza/preto/branco |
|---|---|
| menu e navegação | fundos e superfícies |
| cabeçalho e marca | campos e molduras |
| ícones | rótulos e títulos de seção |
| **botões** (ação) | abas — inclusive a ativa |
| avatar de usuário | crachás de contagem |
| status semântico (✓ concluído, pílula "Ativo", toast de sucesso) | tags e etiquetas em geral |

Isto foi corrigido em **três passagens**, e a terceira ainda achou coisa: o
`.sec-title` (título numerado de seção, presente em todo modal) e a aba ativa
liam a cor de marca direto. Eram os dois pontos que mais apareciam na tela.

**Ao criar componente novo:** pergunte se a cor *informa* alguma coisa. Se não
informa, é cinza.

## Componentes

### Campo — "Modelo E"

Moldura externa com borda, rótulo pequeno em maiúsculas dentro, e o campo real
sem borda própria.

```html
<div class="field">
  <label for="x">Rótulo</label>
  <input type="text" id="x">
</div>
```

Variações: `.g2` (dois lado a lado), `.campo-add` (com botão dentro),
`.vsi-campo-curto` (largura do rótulo).

### Botões

| Classe | Uso |
|---|---|
| `.btn` | neutro |
| `.btn.primary` | ação principal (verde cheio) |
| `.btn.danger` | exclusão (contorno vermelho) |
| `.btn.out-verde` | contorno verde — `+add`, "Consultar cadastro" |
| `.btn.out-cinza` | contorno neutro — Câmera / Galeria |
| `.btn.edit-verde` | Editar, com lápis |
| `.btn.atencao` | Lavrar (âmbar) |
| `.btn.sm` | 31px; em campo, 44px (ver `DECISOES-UX.md`) |

**O padrão de exclusão do sistema é botão com a palavra "Excluir"**, não ícone
de lixeira. (O `.acao-x` sem moldura existe em listas antigas de Feriados/UPF.)

### Combobox

```html
<div class="ac-wrap">
  <div class="field campo-add">
    <div class="campo-add-corpo"><label>…</label><input …></div>
    <button class="btn out-verde sm">+add</button>
  </div>
  <div class="ac-list" id="…"></div>   <!-- .open mostra -->
</div>
```

`.ac-list` flutua ancorada no campo, rolagem própria, fecha ao clicar fora.
Mesmo contrato do AppPOSTURAS, para os dois sistemas se lerem igual.

### Abas

- `.sub-abas` — trilho cinza, ativa em pílula branca **com texto preto**
- `.doc-tab` — o mesmo, no formulário de documento
- As quatro setas `« ‹ › »` vão no **rodapé**, agrupadas em `.foot-setas`

### Listas

| Classe | O que é |
|---|---|
| `.par-linha` | linha de lista com avatar/miniatura + texto + ações |
| `.par-av` | avatar quadrado arredondado (verde, um só para todos) |
| `.rel-capa` / `.rel-mini` | miniatura de arquivo |
| `.ico-circ` | botão de ícone circular (ver/editar/excluir) |
| `.vsi-cartao` | balão cinza sobre fundo branco, com × vermelho |
| `.par-fixo` | painel com topo fixo e lista rolando |

### Status

`.badge` para estado de processo (texto em `--chumbo`), `.pil` para
qualificação (`.pil-ok` verde). As duas não podem competir na mesma linha.

### Seções

`.sec-title` numera sozinho por contador CSS (`counter-reset:sec` no `.modal`).
**Cinza**, não verde.

## Tipografia

| Família | Uso |
|---|---|
| **Manrope** 600/700/800 | títulos, números, KPIs |
| **Inter** 400/500/600 | corpo |
| **JetBrains Mono** | inscrição, coordenada, medida — tudo que se compara dígito a dígito |

Números em coluna usam `font-variant-numeric: tabular-nums`.

## Impressão

`resources/views/impressao/` — A4, térmica (bobina 80mm) e OS. O cabeçalho
oficial do município mora em **um lugar só** (`App\Services\CabecalhoOficial` +
`_cabecalho.blade.php`); já esteve duplicado.

## Ao criar tela nova — o caminho curto

1. O componente já existe? Procure em `tema-f.css` antes de escrever CSS.
2. Campo é `.field`. Aba é `.sub-abas`. Lista é `.par-linha`. Cartão é
   `.vsi-cartao`.
3. Cor nova só se **informar** algo.
4. Exclusão pergunta antes (`confirmarAcao`) e diz o nome do que sai.
5. Teste em **375px**. A maior parte do uso é no celular.
6. O vazio se **diz** ("sem inscrição"), não se disfarça.
