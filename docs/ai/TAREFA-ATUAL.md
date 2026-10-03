# TAREFA ATUAL

> O que está em curso **agora**. Este arquivo é curto de propósito: ele responde
> "onde eu parei?" para quem volta amanhã.
> Atualizado em 02/10/2026.

## Em curso

**Nada em curso.** A última entrega de funcionalidade foi a importação de
bairro pela tela, a conferência com o cadastro, o painel Camadas e a prancheta
em tela cheia (commit `bd34623`, 02/10/2026). Depois dela veio só limpeza de
repositório (protótipos, esqueleto do Vite, README próprio, docs revisados).

## Estado da árvore

| | |
|---|---|
| Branch principal | `main` |
| Último commit de funcionalidade | `bd34623` |
| Publicado | **conferir** — produção estava em `fea0041` em 04/09; o deploy automático (`f479da8`) publica cada push na `main` |
| Migrações novas | 27/09 a 02/10 (importações, pré-curadoria, contorno, conferência, retratos, e-mail opcional) — o `deploy.sh` migra; conferir com `php artisan migrate:status` |

## O que fazer a seguir

Pela ordem do `ROADMAP.md`, o próximo item de código com melhor
custo/benefício é:

> **Combo no lugar do campo "Nome no desenho"** (Parâmetros → Bairros)
>
> Hoje é texto livre que precisa bater exato com um nome do DWG. Errar não dá
> aviso nenhum: salva quieto e o bairro não amarra. Já custou 711 lotes sem
> inscrição em produção por um `VI` no lugar de `IV`.
>
> Trocar o `<input>` por `<select>` alimentado pelos bairros distintos de
> `lotes` elimina a classe inteira de erro. São 2 nomes hoje.
>
> Arquivos: `resources/views/mapa.blade.php` (painel `#par-bairros`),
> `public/js/parametros.js`, `app/Http/Controllers/ParametroController.php`
> (`index()` já pode devolver a lista).

Mas os itens que **realmente destravam o sistema** não são de código — são de
dado, e dependem do usuário:

1. alimentar a legislação, com os termos de busca de cada artigo (sem artigo citado não há auto)
2. carregar a exportação do cadastro dos bairros 105 e 90 (a aba BCI está
   vazia para todos os imóveis)
3. ~~conferir os 101 lotes sem quadra~~ — **feito em 04–05/09**, pela tela

## Antes de começar qualquer coisa

Leia, nesta ordem:

1. `CONTEXTO.md` — o que o sistema é e as regras que não se negociam
2. `ESTADO-ATUAL.md` — o que está quebrado agora
3. `DECISOES-UX.md` — se for mexer em tela
4. `DESIGN-SYSTEM.md` — se for criar componente

## Como trabalhar aqui

**Verificação é no sistema real.** Não há suíte automatizada que rode. O
combinado é: criar usuário temporário, provar no navegador contra o banco de
verdade, e **remover o usuário e os rastros ao fim** — inclusive as linhas de
auditoria que a prova gerou.

**Produção difere do ambiente local.** Já houve defeito que só aparecia lá,
porque o dado era diferente (grafia da amarração de bairro). Quando a diferença
for de dado, confira nos dois.

**Commit e deploy só quando pedido.** O repositório é público, e o envio é
decisão do usuário. Migração (`migrate --force`) quem roda é ele.

**Deploy:** `ssh … "sudo /usr/local/bin/deploy-fiscobras.sh"` — puxa do
GitHub, migra, refaz caches e testa o site.
