// ══════════════════════════════════════════════
// EDIFICAÇÕES — o que está construído dentro do lote
//
// A multa de obras é por metro quadrado construído. Até aqui esse número só
// existia como o que a trena do fiscal anotou na vistoria: não havia como
// conferir, nem como mostrar no croqui que acompanha a peça.
//
// Aqui a construção é DESENHADA dentro do lote, com a mesma ferramenta do
// lote (desenho.js) e com as mesmas medidas na tela. O servidor recusa
// polígono que não caiba inteiro no lote — ver EdificacaoController.
// ══════════════════════════════════════════════

const edifState = {
  /** @type {number|null} lote cujas edificações estão desenhadas */ loteId: null,
  /** @type {L.Polygon[]} */ camadas: [],
  /** @type {Array<Object>} */ lista: [],
  areaConstruida: 0,
}

/** Traço mais fino que o do lote: é o que está DENTRO dele, não outra divisa. */
const COR_EDIFICACAO = '#7C3AED'

// Ícone próprio, e não o ICO_LIXO de parametros.js: aquele arquivo só é
// carregado para administrador, e a curadoria cadastral é um poder
// transversal — um curador que não fosse admin tomaria ReferenceError aqui.
const ICO_LIXO_EDIF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
  <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
  <path d="M10 11v6M14 11v6"/></svg>`

// ── CARREGAR E PINTAR ────────────────────────────────────────

/**
 * Busca as edificações do lote e as desenha.
 *
 * @param {number} loteId
 * @param {boolean} [pintar] desenhar no mapa (falso quando só se quer a soma)
 */
async function carregarEdificacoes(loteId, pintar = true) {
  if (!loteId) { return null }

  try {
    const r = await fetch(`/api/imoveis/${loteId}/edificacoes`, { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const d = await r.json()

    edifState.loteId = loteId
    edifState.lista = d.edificacoes
    edifState.areaConstruida = d.area_construida_m2

    if (pintar) { pintarEdificacoes() }
    return d
  } catch (e) {
    console.error(e)
    return null
  }
}

/** Painel próprio, acima dos lotes: a construção fica DENTRO do lote na tela. */
function _paneEdificacoes() {
  const mapa = mapaState.obj
  if (!mapa.getPane('edificacoes')) {
    const p = mapa.createPane('edificacoes')
    p.style.zIndex = 620
    // Surdo: o clique tem de continuar chegando ao lote embaixo, senão tocar
    // na casa deixaria de abrir a ficha do imóvel.
    p.style.pointerEvents = 'none'
    // Só na escala dos lotes: afastado, o croqui vira um risco que não se lê.
    mapa.on('zoomend', _edifNaEscala)
    _edifNaEscala()
  }
  return 'edificacoes'
}

function _edifNaEscala() {
  const mapa = mapaState.obj
  const p = mapa?.getPane('edificacoes')
  if (p) { p.style.display = typeof nivelDoMapa !== 'function' || nivelDoMapa(mapa) === 'lotes' ? '' : 'none' }
}

/**
 * DISCRETO (29/10/2026): contorno fino e tracejado, sem preenchimento. Cheio
 * de roxo, o croqui cobria a imagem e o número do lote — e ele é detalhe do
 * lote, não outra camada por cima dele.
 */
function pintarEdificacoes() {
  limparEdificacoes()
  const mapa = mapaState.obj
  if (!mapa) { return }

  edifState.camadas = edifState.lista.map(e => L.geoJSON(e.geometry, {
    pane: _paneEdificacoes(),
    interactive: false,
    style: { color: COR_EDIFICACAO, weight: 1, opacity: .75, dashArray: '4 3', fill: false },
  }).addTo(mapa))
}

function limparEdificacoes() {
  const mapa = mapaState.obj
  edifState.camadas.forEach(c => { if (mapa) { mapa.removeLayer(c) } })
  edifState.camadas = []
}

// ── DESENHAR ─────────────────────────────────────────────────

/**
 * Desenha uma edificação dentro do lote selecionado.
 *
 * Exige lote selecionado ANTES de começar, e não depois: o polígono só faz
 * sentido em relação a um lote, e descobrir no fim que não havia lote nenhum
 * custaria o desenho inteiro.
 */
function desenharEdificacao() {
  // Fecha busca, pinos e cores. A curadoria CONVIVE: a edificação também se
  // desenha de dentro da mesa, sobre o lote marcado nela (ferramentas-mapa.js).
  pedirFerramenta('desenho', _desenharEdificacao, { convive: ['curadoria'] })
}

function _desenharEdificacao() {
  // DUAS SELEÇÕES, e a edificação aceita as duas.
  //
  // Na mesa o lote chega MARCADO (selState.ids, um só) — lá o clique no mapa
  // marca em vez de abrir o balão, então `state.selecionado` fica vazio e
  // exigir o balão travaria a ferramenta. No celular não há marcação livre, e
  // o lote chega pelo balão, como sempre chegou.
  const marcado = typeof selState !== 'undefined' && selState.ids.size === 1
    ? mapaState.porId.get([...selState.ids][0])?.feature?.properties
    : null

  const p = marcado ?? state.selecionado?.properties
  if (!p?.id) {
    toast('Marque primeiro o lote onde está a construção.', 'err')
    return
  }

  // O que acontece com o contorno pronto: pede os dados e grava.
  //
  // Os dados são pedidos DEPOIS de fechar o contorno, e fechar a janela sem
  // gravar descarta a construção — a saída por desistência é dita nela.
  const aoConcluir = g => pedirDadosDaEdificacao(dados => gravarEdificacao(p.id, g, dados))

  // NA PRANCHETA: o lote destacado, a imagem aérea por fundo e a frente do
  // lote virada para baixo — é como a construção é vista da rua, e as
  // ferramentas de medida, esquadro e encaixe da prancheta valem para ela.
  if (typeof PranchetaCad !== 'undefined') {
    PranchetaCad.abrir('edificacao', [p.id], null, null, { quadra: p.quadra, numero_lote: p.numero_lote, aoConcluir })
      .catch(err => toast(err.message, 'err'))
    return
  }

  toast('Contorne a construção. Duplo toque fecha.', 'aviso')

  iniciarDesenho({
    modo: 'poligono',
    rotulo: 'Edificação',
    // O encaixe fica LIGADO: o muro da edícula costuma nascer na divisa, e
    // encostar nela de olho deixa a fresta de sempre.
    snap: true,
    onConcluir: aoConcluir,
  })
}

/**
 * De onde o croqui veio — as mesmas chaves de Edificacao::ORIGENS. O croqui
 * serve a várias demandas, e a ficha diz para qual ele foi feito.
 */
const EDIF_ORIGENS = {
  atualizacao_cadastral: 'Atualização cadastral',
  vistoria: 'Vistoria',
  desmembramento: 'Desmembramento',
  unificacao: 'Unificação',
}

let _medOk = null

/**
 * A janela dos dados da edificação (mapa.blade.php, #m-edificacao).
 * Quem chama de dentro de uma demanda passa a origem e o número já prontos.
 *
 * @param {(dados:{descricao:?string, origem:string, origem_ref:?string}) => any} onOk
 * @param {{origem?:string, origem_ref?:string}} [padrao]
 */
function pedirDadosDaEdificacao(onOk, padrao = {}) {
  document.getElementById('med-descricao').value = ''
  document.getElementById('med-origem').innerHTML = Object.entries(EDIF_ORIGENS)
    .map(([k, v]) => `<option value="${k}" ${k === (padrao.origem || 'atualizacao_cadastral') ? 'selected' : ''}>${esc(v)}</option>`).join('')
  document.getElementById('med-ref').value = padrao.origem_ref || ''
  _medOk = onOk
  openModal('m-edificacao')
  setTimeout(() => document.getElementById('med-descricao').focus(), 120)
}

async function _medConfirmar() {
  const dados = {
    descricao: document.getElementById('med-descricao').value.trim() || null,
    origem: document.getElementById('med-origem').value,
    origem_ref: document.getElementById('med-ref').value.trim() || null,
  }
  const acao = _medOk
  _medOk = null
  fModalBtn('m-edificacao')
  if (acao) { await acao(dados) }
}

/** @param {number} loteId @param {Object} geometry @param {{descricao:?string, origem:string, origem_ref:?string}} dados */
async function gravarEdificacao(loteId, geometry, dados) {
  const d = await postCadastro(`/api/imoveis/${loteId}/edificacoes`, { geometry, ...dados })
  if (!d) { return }

  toast(d.message)
  await carregarEdificacoes(loteId)
  renderCroquis()
  renderCroquiAtual()
}

/** @param {number} id */
function excluirEdificacao(id) {
  const e = edifState.lista.find(x => x.id === id)
  confirmarAcao({
    titulo: 'Remover edificação',
    mensagem: `Apaga do desenho ${e?.descricao ? '"' + e.descricao + '"' : 'esta construção'}`
      + ` (${fmtNum(e?.area_m2 || 0)} m²). A área construída do imóvel muda junto.`,
    perigo: true,
    onConfirm: async () => {
      const r = await fetch('/api/edificacoes/' + id, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      })
      const d = await r.json()
      if (!r.ok) { toast(d.message || 'Não foi possível remover', 'err'); return }
      toast(d.message)
      await carregarEdificacoes(edifState.loteId)
      renderCroquis()
      renderCroquiAtual()
    },
  })
}

// ── A ABA CROQUIS DA FICHA ───────────────────────────────────

/**
 * Lista as construções do imóvel, com a área de cada uma e a soma.
 *
 * A soma é o que a vistoria vai sugerir como área construída. Ela aparece
 * aqui, e não só lá, porque é nesta tela que se descobre que falta desenhar
 * a edícula — depois, na vistoria, o número já teria sido usado.
 */
function renderCroquis() {
  const alvo = document.getElementById('fi-lista-croquis')
  if (!alvo) { return }

  const pode = window.PODE_CURAR_CADASTRO
  const botao = pode
    ? `<button class="btn out-verde sm" onclick="desenharEdificacaoDaFicha()">
         + Desenhar edificação</button>`
    : ''

  if (!edifState.lista.length) {
    alvo.innerHTML = `<div class="vazio-msg">Nenhuma edificação desenhada neste imóvel.</div>${botao}`
    return
  }

  alvo.innerHTML = `
    <div class="sec-simples">Área construída
      <span class="cont">${fmtNum(edifState.areaConstruida)} m²</span></div>
    <p class="aviso-legal">
      É o que está <b>desenhado</b>. Na vistoria ele aparece ao lado da área
      <b>aferida em campo</b> — quando as duas discordam, a diferença é o assunto.
    </p>
    <div class="edif-croqui">${_svgDoCroqui(320)}</div>
    ${edifState.lista.map(e => `
      <div class="par-linha">
        <div class="principal">
          <b>${esc(e.descricao || 'Edificação')}</b>
          <span>${fmtNum(e.area_m2)} m² · ${esc(e.origem_rotulo || 'Atualização cadastral')}${e.origem_ref ? ' nº ' + esc(e.origem_ref) : ''}</span>
          <span class="edif-autor">${_autoriaDoCroqui(e)}</span>
        </div>
        ${pode ? `<button class="acao-x" onclick="excluirEdificacao(${e.id})"
                   title="Remover">${ICO_LIXO_EDIF}</button>` : ''}
      </div>`).join('')}
    ${botao}`
}

/** "Desenhado por Fulano em 29/10/2026" — sem autor gravado, só a data. */
function _autoriaDoCroqui(e) {
  return `Desenhado ${e.autor ? 'por ' + esc(e.autor) + ' ' : ''}${e.desenhado_em ? 'em ' + esc(e.desenhado_em) : ''}`.trim()
}

/**
 * O croqui em SVG: o lote (cinza) e as edificações (roxo), na escala, com o
 * norte para cima. Feito no navegador com o que já está carregado — o lote da
 * ficha e as edificações dele —, sem imagem nem pedido a mais.
 *
 * @param {number} lado tamanho máximo do desenho, em pixels
 */
function _svgDoCroqui(lado) {
  const lote = state.selecionado?.geometry
  const aneisDe = g => !g ? [] : g.type === 'MultiPolygon' ? g.coordinates.flat() : g.type === 'Polygon' ? g.coordinates : []
  const loteAneis = aneisDe(lote)
  const edifs = edifState.lista.map(e => aneisDe(e.geometry))
  const todos = [...loteAneis, ...edifs.flat()].flat()
  if (!todos.length) { return '' }

  // Metros num plano local: o croqui de um lote não sente a curvatura.
  const lat0 = todos[0][1] * Math.PI / 180
  const kx = 111320 * Math.cos(lat0), ky = 110540
  const xs = todos.map(p => p[0] * kx), ys = todos.map(p => p[1] * ky)
  const x0 = Math.min(...xs), x1 = Math.max(...xs), y0 = Math.min(...ys), y1 = Math.max(...ys)
  const folga = 12
  const k = (lado - 2 * folga) / Math.max(x1 - x0, y1 - y0, 1)
  const W = Math.round((x1 - x0) * k + 2 * folga), H = Math.round((y1 - y0) * k + 2 * folga)
  const P = ([lon, lat]) => `${(folga + (lon * kx - x0) * k).toFixed(1)},${(folga + (y1 - lat * ky) * k).toFixed(1)}`
  const caminho = aneis => aneis.map(a => 'M' + a.map(P).join('L') + 'Z').join('')

  return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Croqui do lote com as edificações">
    <path d="${caminho(loteAneis)}" fill="var(--blt)" stroke="var(--tx3)" stroke-width="1.2" fill-rule="evenodd"/>
    ${edifs.map(a => `<path d="${caminho(a)}" fill="${COR_EDIFICACAO}" fill-opacity=".14" stroke="${COR_EDIFICACAO}" stroke-width="1.2"/>`).join('')}
    <text x="${W - 6}" y="14" text-anchor="end" font-size="10" fill="var(--tx3)">N ↑</text>
  </svg>`
}

/**
 * "Croqui mais recente" na aba Dados da ficha: o desenho, a data e quem fez.
 * Pede as edificações sem pintar no mapa (a ficha cobre o mapa).
 */
async function renderCroquiAtual() {
  const fig = document.getElementById('fi-croqui-atual')
  const data = document.getElementById('fi-croqui-data')
  const id = state.selecionado?.properties?.id
  if (!fig || !id) { return }
  if (edifState.loteId !== id) { await carregarEdificacoes(id, false) }
  if (state.selecionado?.properties?.id !== id) { return }   // trocou de lote enquanto esperava

  fig.querySelector('.edif-croqui')?.remove()
  fig.querySelector('.edif-autor')?.remove()
  const vazio = fig.querySelector('.fi-vazio')
  if (!edifState.lista.length) {
    if (data) { data.textContent = '' }
    if (vazio) { vazio.style.display = '' }
    return
  }
  // A mais recente diz a data e o autor; o desenho mostra todas.
  const ultima = edifState.lista[edifState.lista.length - 1]
  if (data) { data.textContent = ultima.desenhado_em ? '· ' + ultima.desenhado_em : '' }
  if (vazio) { vazio.style.display = 'none' }
  fig.insertAdjacentHTML('beforeend', `<div class="edif-croqui" onclick="subFicha('croquis')" title="Ver as edificações">${_svgDoCroqui(300)}</div>
    <div class="edif-autor">${_autoriaDoCroqui(ultima)} · ${esc(ultima.origem_rotulo || '')}${ultima.origem_ref ? ' nº ' + esc(ultima.origem_ref) : ''}</div>`)
}

/**
 * Desenhar a partir da ficha: fecha a ficha antes.
 *
 * O desenho acontece no mapa, e a ficha é um modal que o cobre inteiro —
 * deixá-la aberta seria pedir para contornar uma construção que não se vê.
 */
function desenharEdificacaoDaFicha() {
  fModalBtn('m-ficha')
  desenharEdificacao()
}
