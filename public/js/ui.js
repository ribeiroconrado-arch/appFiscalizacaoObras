// ══════════════════════════════════════════════
// COMPONENTES DE UI
// Portados do AppPOSTURAS (components/toast.js, components/modals.js,
// components/ui.js). Mantidos com os mesmos nomes de função para que a
// migração para o Laravel seja recorte-e-cola, e para que quem já mexeu no
// outro sistema reconheça o código.
// ══════════════════════════════════════════════

/**
 * Aviso curto e efêmero. Substitui `alert()` em todo o sistema.
 *
 * @param {string} msg
 * @param {'ok'|'err'|'aviso'} [tipo='ok']
 * @param {{campo?: string}} [opts] campo: id do campo que impediu a ação
 */
function toast(msg, tipo = 'ok', opts = {}) {
  const el = document.getElementById('toast')

  // Aviso em SUPERFÍCIE, não em bloco colorido.
  //
  // O formato anterior era uma pílula inteiramente vermelha ou verde ocupando
  // quase a largura da tela. Duas consequências: mensagem longa virava um
  // paredão sobre a interface, e a cor gritava com a mesma intensidade para
  // "salvo" e para "campo obrigatório" — avisos de peso muito diferente.
  // Agora a cor vive na barra lateral e no ícone, o texto fica escuro e
  // legível, e a caixa tem o tamanho do que precisa dizer.
  const t = TIPOS_AVISO[tipo] ? tipo : 'ok'
  el.className = 'toast toast-' + t + ' show'
  el.innerHTML = `<span class="toast-ico">${TIPOS_AVISO[t]}</span>`
    + `<span class="toast-txt">${esc(msg)}</span>`

  // Clicar dispensa: aviso que só sai sozinho obriga a esperar.
  el.onclick = () => { el.className = 'toast'; clearTimeout(el._t) }

  clearTimeout(el._t)
  // Erro fica mais tempo: costuma pedir uma ação, não só informar.
  el._t = setTimeout(() => { el.className = 'toast' }, t === 'err' ? 5000 : 3200)

  if (opts.campo) marcarCampoInvalido(opts.campo)
}

/** Ícone de cada tipo de aviso. */
const TIPOS_AVISO = {
  ok: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
    stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>`,
  err: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
    stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5h.01"/></svg>`,
  aviso: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
    stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>`,
}

/**
 * Marca o campo que impediu a ação e leva o usuário até ele.
 *
 * Um aviso de "campo obrigatório" que não diz QUAL campo obriga a varrer o
 * formulário inteiro — e num formulário com abas o campo pode nem estar na
 * aba visível. A marca sai sozinha assim que o usuário mexe no campo: exigir
 * que ele a apague seria cobrar duas ações por um erro.
 *
 * @param {string} id
 */
function marcarCampoInvalido(id) {
  const el = document.getElementById(id)
  if (!el) return

  el.classList.add('campo-invalido')
  el.scrollIntoView({ block: 'center', behavior: 'smooth' })
  setTimeout(() => el.focus?.(), 220)

  const limpar = () => {
    el.classList.remove('campo-invalido')
    el.removeEventListener('input', limpar)
    el.removeEventListener('change', limpar)
  }
  el.addEventListener('input', limpar)
  el.addEventListener('change', limpar)
}

/**
 * Exigência de preenchimento: avisa e aponta o campo, numa chamada só.
 *
 * @param {string} id  campo que falta
 * @param {string} msg o que se espera dele
 */
function exigirCampo(id, msg) {
  toast(msg, 'err', { campo: id })
}

/** Abre o modal e trava o scroll do body. @param {string} id */
function openModal(id) {
  document.getElementById(id).classList.add('open')
  document.body.style.overflow = 'hidden'
}

/** Fecha o modal e devolve o scroll. @param {string} id */
function fModalBtn(id) {
  const el = document.getElementById(id)
  if (!el) return
  el.classList.remove('open')
  document.body.style.overflow = ''
}

/**
 * Handler do clique no fundo do modal — intencionalmente vazio.
 * Clicar fora NÃO fecha, de propósito: evita perder dados já digitados.
 * Os modais só fecham pelo × ou por um botão "Fechar"/"Cancelar" explícito.
 */
function fModal() { /* vazio de propósito — ver comentário acima */ }

/** Ação pendente do modal genérico de confirmação. @type {Function|null} */
let _confirmAcao = null

/**
 * Confirmação antes de uma ação — substitui `confirm()` nativo em qualquer
 * módulo. O botão fica "Aguarde…" durante o await, e o modal só fecha depois
 * que a ação resolve.
 *
 * @param {{titulo?:string, mensagem?:string, textoBtn?:string, perigo?:boolean,
 *          onConfirm:() => (void|Promise<void>)}} opts
 */
function confirmarAcao({ titulo = 'Confirmar ação', mensagem = 'Tem certeza?',
                         textoBtn = 'Confirmar', perigo = false, onConfirm }) {
  document.getElementById('mcg-titulo').textContent = titulo
  document.getElementById('mcg-msg').textContent = mensagem
  const btn = document.getElementById('mcg-btn-ok')
  btn.textContent = textoBtn
  btn.className = 'btn ' + (perigo ? 'danger' : 'primary')
  btn.disabled = false
  btn.dataset.textoOriginal = textoBtn
  _confirmAcao = onConfirm
  openModal('m-confirm')
}

/** Handler do OK do modal genérico de confirmação. */
async function _mcgConfirmar() {
  if (!_confirmAcao) return
  const btn = document.getElementById('mcg-btn-ok')
  const acao = _confirmAcao
  btn.disabled = true
  btn.textContent = 'Aguarde...'
  try {
    await acao()
    fModalBtn('m-confirm')
  } finally {
    btn.disabled = false
    btn.textContent = btn.dataset.textoOriginal || 'Confirmar'
  }
}

/* ── TEMPOS DO OVERLAY DE CARREGAMENTO ──
   Duas medidas, e cada uma resolve um defeito oposto.

   ATRASO: abaixo disso o overlay não chega a aparecer. Em rede boa a carga
   dos lotes termina em 100 ms, e um overlay que pisca por 100 ms é lido como
   falha de renderização, não como "estou trabalhando".

   MÍNIMO: uma vez aparecido, fica no mínimo esse tempo. Sem ele, uma carga de
   250 ms produz um lampejo — o overlay entra e sai antes de o olho concluir o
   que viu, e o usuário fica com a sensação de que a tela deu um salto.
   520 ms é uma volta inteira da marca mais o tempo de esmaecer. */
const CARREGANDO_ATRASO = 180
const CARREGANDO_MINIMO = 520

let _carregandoTimer = null
let _carregandoDesde = 0

/**
 * Overlay de tela cheia para transições que dependem de carga de dados.
 * Sempre usar em try/finally, para não deixar o overlay preso se a carga falhar.
 * @param {string} [txt]
 */
function mostrarCarregandoTela(txt = 'Carregando...') {
  document.getElementById('tela-carregando-txt').textContent = txt

  // Já visível (ou já agendado): só troca o texto. Reagendar reiniciaria a
  // contagem e o overlay nunca sairia numa sequência de cargas curtas.
  if (_carregandoTimer || _carregandoDesde) { return }

  _carregandoTimer = setTimeout(() => {
    _carregandoTimer = null
    _carregandoDesde = Date.now()
    document.getElementById('tela-carregando').classList.add('show')
  }, CARREGANDO_ATRASO)
}

/** Esconde o overlay de carregamento — respeitando o tempo mínimo em tela. */
function esconderCarregandoTela() {
  // Terminou antes de o overlay aparecer: cancela e ninguém viu nada.
  if (_carregandoTimer) {
    clearTimeout(_carregandoTimer)
    _carregandoTimer = null
    return
  }
  if (!_carregandoDesde) { return }

  const falta = CARREGANDO_MINIMO - (Date.now() - _carregandoDesde)
  const fechar = () => {
    _carregandoDesde = 0
    document.getElementById('tela-carregando').classList.remove('show')
  }
  falta > 0 ? setTimeout(fechar, falta) : fechar()
}

/* ── FILTRO ALTERADO E AINDA NÃO BUSCADO ──
   Tirar a busca instantânea resolve um problema e cria outro: a pessoa troca o
   seletor, NADA acontece, e ela não sabe se o filtro não pegou ou se a lista já
   é aquela. O ponto no botão responde exatamente isso — "há filtro escolhido
   que esta lista ainda não reflete" — e some sozinho quando a busca volta.
   É por isso que ele é aceso pelo filtro e apagado pelo SUCESSO da consulta, e
   não pelo clique: busca que falhou continua sendo filtro não aplicado. */

/** @param {string} idBotao */
function marcarBuscaPendente(idBotao) {
  document.getElementById(idBotao)?.classList.add('pendente')
}

/** @param {string} idBotao */
function limparBuscaPendente(idBotao) {
  document.getElementById(idBotao)?.classList.remove('pendente')
}

/**
 * Enter num campo de filtro vale como clicar em Buscar.
 *
 * Não é volta da busca instantânea: continua sendo um ato deliberado, com hora
 * marcada por quem digita. É o gesto que todo mundo já tem no dedo depois de
 * escrever num campo de busca, e negá-lo faria o campo parecer travado.
 *
 * @param {string[]} ids @param {Function} buscar
 */
function enterBusca(ids, buscar) {
  for (const id of ids) {
    const e = document.getElementById(id)
    if (!e) { continue }
    e.addEventListener('keydown', ev => {
      if (ev.key !== 'Enter') { return }
      ev.preventDefault()
      // Uma sugestão aberta manda no Enter: ali ele escolhe a linha da lista,
      // e não dispara a busca da tela.
      if (document.querySelector('.ac-list.open')) { return }
      buscar()
    })
  }
}

/** Escapa texto antes de injetar em innerHTML. @param {*} s @returns {string} */
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ))
}

/** Formata número com separador de milhar pt-BR. @param {number} n */
function fmtNum(n) {
  return Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 })
}

// ── SETAS DE ABA ─────────────────────────────────────────────
//
// Todo formulário de várias abas ganha os mesmos quatro controles NO RODAPÉ,
// à esquerda das ações: primeira, anterior, próxima e última. É onde o
// formulário de documento já os punha, e onde a mão vai parar de qualquer
// jeito para gravar. A barra de abas continua sendo o caminho de quem sabe
// onde quer chegar; as setas são para quem preenche em ordem — e para o
// celular, onde o trilho rola de lado e a última aba fica fora da vista.

/**
 * Para onde uma seta leva, dentro de uma lista de abas.
 *
 * Não dá a volta nas pontas de propósito: aqui a ordem é a do preenchimento,
 * e saltar da última para a primeira num formulário de peça parece perda do
 * que foi digitado. Quem está na ponta simplesmente fica (o botão aparece
 * desligado — ver `pintarSetasDeAba`).
 *
 * @param {Array<string>} lista abas em ordem
 * @param {string} atual
 * @param {'primeira'|'anterior'|'proxima'|'ultima'} destino
 * @returns {string}
 */
function abaAlvo(lista, atual, destino) {
  const i = Math.max(0, lista.indexOf(atual))
  if (destino === 'primeira') { return lista[0] }
  if (destino === 'ultima')   { return lista[lista.length - 1] }
  if (destino === 'anterior') { return lista[Math.max(0, i - 1)] }
  return lista[Math.min(lista.length - 1, i + 1)]
}

/**
 * Desliga as setas que não levam a lugar nenhum.
 *
 * Desabilita em vez de esconder, como o rodapé do formulário de documento já
 * fazia: o botão desligado diz "aqui é o início/fim", e o que some faz os
 * outros três dançarem de posição a cada troca de aba.
 *
 * @param {string} idBarra id do rodapé que contém as setas
 * @param {Array<string>} lista @param {string} atual
 */
function pintarSetasDeAba(idBarra, lista, atual) {
  const barra = document.getElementById(idBarra)
  if (!barra) { return }

  const i = lista.indexOf(atual)
  const noComeco = i <= 0
  const noFim = i >= lista.length - 1

  barra.querySelectorAll('[data-ir]').forEach(b => {
    const ir = b.dataset.ir
    b.disabled = (ir === 'primeira' || ir === 'anterior') ? noComeco : noFim
  })
}

// ── DATA E HORA ──────────────────────────────────────────────
// Nunca usar `new Date().toISOString()` para "hoje"/"agora": o ISO converte
// para UTC e, no fuso de Cuiabá, das 20h em diante já devolve o dia seguinte.
// Uma vistoria feita às 21h ficaria registrada como do dia seguinte — bug que
// o AppPOSTURAS já pagou para aprender.

/** Data de hoje em aaaa-mm-dd, no fuso do aparelho. @returns {string} */
function dataHojeLocal() {
  const d = new Date()
  const p = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/** Hora atual em hh:mm, no fuso do aparelho. @returns {string} */
function horaAgoraLocal() {
  const d = new Date()
  const p = n => String(n).padStart(2, '0')
  return `${p(d.getHours())}:${p(d.getMinutes())}`
}

/**
 * Um instante qualquer em aaaa-mm-ddThh:mm:ss, NO FUSO DO APARELHO.
 *
 * É o mesmo formato que o campo de data/hora da vistoria manda, e pela mesma
 * razão: o banco guarda hora de parede (a aplicação roda em UTC, e o que se
 * grava é o que o relógio de quem estava lá marcava). `toISOString()` aqui
 * adiantaria a foto em 4 horas em Cuiabá — ver o aviso no alto desta seção.
 *
 * @param {Date} d @returns {string}
 */
function instanteLocal(d) {
  const p = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
       + `T${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

/**
 * Converte aaaa-mm-dd em dd/mm/aaaa sem passar por `new Date()`, que
 * interpretaria a string como UTC e poderia devolver o dia anterior.
 * @param {string} iso @returns {string}
 */
function formatarDataBR(iso) {
  if (!iso) return ''
  const [a, m, d] = iso.split('-')
  return `${d}/${m}/${a}`
}

/**
 * Atualiza o texto sobreposto ao <input type=date>. É esse span que mostra
 * dd/mm/aaaa; o input nativo fica com o texto transparente, preservando o
 * calendário do navegador sem exibir o formato do sistema operacional.
 * @param {HTMLInputElement} input
 */
function atualizarDisplayData(input) {
  const span = input.parentElement?.querySelector('.date-ov-txt')
  if (!span) return
  if (input.value) {
    span.textContent = formatarDataBR(input.value)
    span.classList.remove('vazio')
  } else {
    span.textContent = 'dd/mm/aaaa'
    span.classList.add('vazio')
  }
}

/** Pré-preenche com hoje ao focar, se vazio. @param {HTMLInputElement} i */
function preencherDataHojeSeVazio(i) {
  if (!i.value) { i.value = dataHojeLocal(); atualizarDisplayData(i) }
}

/** Pré-preenche com agora ao focar, se vazio. @param {HTMLInputElement} i */
function preencherHoraAgoraSeVazio(i) {
  if (!i.value) i.value = horaAgoraLocal()
}

// ── MENU "NOVO" ANCORADO ─────────────────────────────────────
// Componente único para qualquer botão de criar que tenha MAIS DE UMA opção.
// Antes cada botão desses resolvia do seu jeito — um abria modal, outro já
// criava direto —, e o usuário precisava descobrir o comportamento de cada um.
//
// O menu nasce do próprio botão (speed-dial), como no AppPOSTURAS: a lista
// aparece colada nele, não no centro da tela, para não perder a relação entre
// o que foi clicado e o que abriu.

/**
 * Abre o menu de opções ancorado a um botão.
 *
 * Aceita o EVENTO ou o próprio elemento. A distinção importa: `currentTarget`
 * só existe enquanto o evento está sendo despachado, e vira `null` assim que o
 * manipulador devolve o controle. Quem abre o menu depois de um `await` —
 * carregar os tipos de documento, por exemplo — recebe `null` ali e o menu
 * ficava sem posição nenhuma, encalhado no canto superior esquerdo da tela.
 * Acontecia só na PRIMEIRA vez, porque na segunda a resposta vinha do cache e
 * não havia espera.
 *
 * Cada opção pode trazer `obs` — uma linha dizendo PARA QUE serve. Não é
 * enfeite: a dúvida real de quem abre este menu não é onde clicar, é qual peça
 * lavrar (notificação ou auto? embargo ou infração?), e a resposta custa uma
 * linha aqui contra um processo anulado depois.
 *
 * `separar: true` desenha um traço ANTES da opção, para agrupar o que é da
 * mesma natureza — aviso de um lado, sanção do outro.
 *
 * @param {MouseEvent|HTMLElement} origem  o clique, ou o botão que ancora
 * @param {Array<{rotulo:string, obs?:string, icone?:string, separar?:boolean,
 *                acao:Function}>} opcoes
 */
function abrirMenuNovo(origem, opcoes) {
  const ev = origem instanceof Event ? origem : null
  ev?.stopPropagation()
  fecharMenuNovo()

  const botao = ev ? (ev.currentTarget || ev.target) : origem
  if (!botao?.getBoundingClientRect) {
    console.error('abrirMenuNovo: sem elemento para ancorar o menu.')
    return
  }

  const fundo = document.createElement('div')
  fundo.className = 'menu-novo-fundo'
  fundo.id = 'menu-novo-fundo'
  fundo.onclick = fecharMenuNovo

  const menu = document.createElement('div')
  menu.className = 'menu-novo'
  menu.id = 'menu-novo'

  opcoes.forEach((o, i) => {
    if (o.separar) {
      const traco = document.createElement('div')
      traco.className = 'menu-novo-sep'
      menu.appendChild(traco)
    }

    const b = document.createElement('button')
    b.type = 'button'
    // `perigo` marca o item que desfaz alguma coisa. Ele fica no mesmo menu,
    // e não num canto à parte, porque é ali que a pessoa procura — o que muda
    // é a cor, que faz o dedo hesitar meio segundo antes de acertar o alvo.
    b.className = 'menu-novo-op' + (o.perigo ? ' perigo' : '')
    b.innerHTML = (o.icone ? `<span class="menu-novo-ico">${o.icone}</span>` : '')
      + `<span class="menu-novo-txt"><span class="menu-novo-nome">${esc(o.rotulo)}</span>`
      + (o.obs ? `<span class="menu-novo-obs">${esc(o.obs)}</span>` : '')
      + '</span>'
    b.onclick = () => { fecharMenuNovo(); o.acao() }
    // Entrada escalonada: as opções descem uma após a outra, o que mostra de
    // onde a lista saiu. Sem isso ela apenas aparece, e o vínculo com o botão
    // se perde.
    b.style.animationDelay = (i * 35) + 'ms'
    menu.appendChild(b)
  })

  document.body.append(fundo, menu)
  posicionarMenuNovo(menu, botao)
  botao.classList.add('menu-aberto')
}

/**
 * Coloca o menu junto ao botão, dentro da tela.
 *
 * Abre para baixo quando há espaço e para cima quando não há — um menu que
 * nasce fora da viewport é um menu que não existe. O alinhamento acompanha a
 * borda direita do botão, que é onde esses botões costumam ficar.
 */
function posicionarMenuNovo(menu, botao) {
  const r = botao.getBoundingClientRect()
  const alt = menu.offsetHeight
  const larg = menu.offsetWidth

  const cabeAbaixo = r.bottom + 8 + alt <= window.innerHeight - 8
  menu.style.top = cabeAbaixo ? (r.bottom + 8) + 'px' : Math.max(8, r.top - 8 - alt) + 'px'

  const esquerda = Math.min(
    Math.max(8, r.right - larg),
    window.innerWidth - larg - 8
  )
  menu.style.left = esquerda + 'px'
}

function fecharMenuNovo() {
  document.getElementById('menu-novo')?.remove()
  document.getElementById('menu-novo-fundo')?.remove()
  document.querySelectorAll('.menu-aberto').forEach(b => b.classList.remove('menu-aberto'))
}

// Esc fecha, como qualquer menu.
document.addEventListener('keydown', ev => {
  if (ev.key === 'Escape') fecharMenuNovo()
})

// ── SESSÃO EXPIRADA ──────────────────────────────────────────
//
// A sessão dura duas horas. Quando ela cai, o servidor passa a responder 401
// a tudo, e cada tela falhava do seu jeito: o histórico do imóvel mostrava
// "Error: HTTP 401" no console, a lista de documentos escrevia "não foi
// possível carregar", e o mapa simplesmente não trazia lote nenhum. Nenhuma
// dizia a única coisa que importa — que é preciso entrar de novo.
//
// Sete lugares já tratavam 419 (token vencido) copiando as mesmas três linhas.
// Nenhum tratava 401. Em vez de escrever a oitava cópia, o tratamento passa a
// morar aqui, uma vez, valendo para toda chamada que qualquer módulo fizer —
// inclusive as que ainda serão escritas.

/** Para não disparar dez vezes quando dez chamadas caem juntas. */
let _sessaoCaiu = false

/**
 * Leva de volta ao login, dizendo por quê.
 *
 * `/entrar` e não `location.reload()`: recarregar a página do mapa devolve o
 * redirecionamento do servidor e chega ao mesmo lugar, mas passa antes por
 * uma tela que pisca. Ir direto é mais honesto com o que está acontecendo.
 */
function sessaoExpirou() {
  if (_sessaoCaiu) { return }
  _sessaoCaiu = true

  toast('Sessão expirada. Entre novamente.', 'err')
  setTimeout(() => { location.href = '/entrar' }, 1600)
}

/**
 * Envelope em volta do `fetch` do navegador.
 *
 * Não engole a resposta: devolve-a como veio, para o tratamento de erro de
 * cada chamada continuar valendo. Só acrescenta o que faltava — perceber que
 * a sessão caiu e agir uma vez só.
 */
;(() => {
  const original = window.fetch

  window.fetch = async (entrada, opcoes) => {
    const resposta = await original(entrada, opcoes)

    // Só o que é DESTE servidor: uma chamada a serviço de terceiro que
    // responda 401 não diz nada sobre a nossa sessão.
    const url = new URL(
      typeof entrada === 'string' ? entrada : (entrada?.url ?? ''),
      location.origin
    )
    const nossa = url.origin === location.origin

    if (nossa && (resposta.status === 401 || resposta.status === 419)
        && ! location.pathname.startsWith('/entrar')) {
      sessaoExpirou()
    }

    return resposta
  }
})()

// ── TABELA OU CARTÃO ─────────────────────────────────────────
//
// As três listas do sistema — documentos, protocolos e ordens de serviço —
// mudam de FORMA conforme a tela: cartão no celular, tabela no computador.
// A escolha mora aqui, num lugar só, porque o ponto de quebra tem de ser o
// mesmo nas três: duas listas trocando de forma em larguras diferentes é o
// tipo de incoerência que ninguém reporta e todo mundo sente.
//
// 1000px é onde sete colunas ainda leem sem cortar palavra no meio — medido
// no navegador, não escolhido no escuro.

const TELA_LARGA = window.matchMedia('(min-width: 1000px)')

/** @returns {boolean} se a lista cabe em tabela */
function ehTelaLarga() { return TELA_LARGA.matches }

// Girar o tablet ou arrastar a borda da janela atravessa o ponto de quebra
// com a lista já na tela. Sem isto ela ficaria na forma antiga até a próxima
// busca — e o filtro do computador ficaria escondido numa tela larga.
TELA_LARGA.addEventListener('change', () => {
  if (typeof dState !== 'undefined' && dState.lista?.length) { renderDocumentos() }
  // Protocolo e ordem de serviço viraram UMA fila (demandas.js): antes eram
  // dois redesenhos, um por aba.
  if (typeof dmState !== 'undefined' && dmState.lista?.length) { renderDemandas() }
})

// ── PEDIR UM TEXTO ───────────────────────────────────────────
//
// O primo do `confirmarAcao` para quando a confirmação exige MOTIVO escrito, e
// não só um "sim". Nasceu do ato cadastral direto: sem protocolo a apontar, a
// responsabilidade é de quem executou, e ela precisa estar escrita.
//
// Modal genérico e não um por caso: já são três lugares que pedem justificativa
// (unificação direta, desmembramento direto, exclusão de lote residual), e três
// janelas quase iguais divergem na primeira vez que alguém mexer numa delas.

/** @type {Function|null} */
let _textoOk = null

/**
 * @param {Object} o
 * @param {string} o.titulo
 * @param {string} o.rotulo    o que se pede, acima do campo
 * @param {string} [o.dica]    exemplo ou consequência, abaixo do campo
 * @param {number} [o.minimo]  tamanho mínimo aceito
 * @param {string} [o.textoBtn]
 * @param {Function} o.onOk    recebe o texto digitado
 */
function pedirTexto({ titulo, rotulo, dica = '', minimo = 0, textoBtn = 'Confirmar', onOk }) {
  document.getElementById('mtx-titulo').textContent = titulo
  document.getElementById('mtx-rotulo').textContent = rotulo
  document.getElementById('mtx-dica').textContent = dica
  document.getElementById('mtx-dica').hidden = !dica

  const campo = document.getElementById('mtx-campo')
  campo.value = ''
  campo.dataset.minimo = String(minimo)

  document.getElementById('mtx-btn').textContent = textoBtn
  _textoOk = onOk
  openModal('m-texto')
  // O foco vai para o campo: quem abriu esta janela veio escrever.
  setTimeout(() => campo.focus(), 120)
}

/** Handler do OK do modal de texto. */
async function _mtxConfirmar() {
  const campo = document.getElementById('mtx-campo')
  const texto = campo.value.trim()
  const minimo = Number(campo.dataset.minimo || 0)

  // A checagem é aqui E no servidor. Aqui, para o operador não perder o
  // trabalho descobrindo o limite depois de um pedido recusado.
  if (texto.length < minimo) {
    toast(`Escreva ao menos ${minimo} caracteres.`, 'err')
    campo.focus()
    return
  }

  const acao = _textoOk
  _textoOk = null
  fModalBtn('m-texto')
  if (acao) { await acao(texto) }
}
