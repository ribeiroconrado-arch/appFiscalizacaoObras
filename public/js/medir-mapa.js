/**
 * MEDIR DIRETO NO MAPA — régua, área/perímetro e cota.
 *
 * Três jeitos de perguntar "quanto mede?", sem gravar nada:
 *
 *   régua  distância ponto a ponto, com o total do caminho;
 *   área   contorno fechado, com a área, o perímetro e cada lado;
 *   cota   a medida de UM trecho, anotada ao longo dele como numa planta.
 *
 * Tudo é TEMPORÁRIO: as medidas ficam no mapa enquanto a página está aberta,
 * para se comparar uma com a outra, e saem com "Limpar tudo". Nenhuma vai ao
 * servidor.
 *
 * Com a barra aberta e nenhuma medida começada, tocar numa medida pronta (no
 * traço ou na etiqueta) a SELECIONA; Delete a apaga. Segurar Alt ignora a
 * seleção, para começar uma medida nova em cima de outra.
 *
 * ── A régua é a mesma do resto do sistema ──
 *
 * As contas são feitas no plano local de `planoLocal` (desenho.js): elipsoide
 * WGS84 e fator de escala do UTM, a mesma grade do DWG e da matrícula. Medir o
 * lote aqui e na prancheta tem de dar o mesmo número.
 *
 * ── Quatro casas, e o que elas valem ──
 *
 * O número sai com quatro casas depois da vírgula. Ele é exato para os PONTOS
 * marcados; o que decide se a medida é a do terreno é onde o ponto caiu. Por
 * isso o ímã: perto de um canto já desenhado (lote, edificação, quadra, outra
 * medida) o ponto gruda no canto, e a medida passa a ser a do desenho, não a
 * do dedo.
 *
 * É uma FERRAMENTA do mapa (ferramentas-mapa.js): abrir a medição encerra a
 * pesquisa ou a alteração cadastral, e vice-versa.
 */

const MED_COR = '#22D3EE'
const MED_COR_SEL = '#FBBF24'   // a medida selecionada
const MED_PEGA_PX = 8          // a que distância do traço o toque seleciona
const MED_IMA_PX = 14          // raio do ímã, em pixels de tela
const MED_DUPLO_MS = 320
const MED_DUPLO_PX = 6

const medState = {
  /** @type {'regua'|'area'|'cota'|null} a ferramenta em uso; null = barra fechada */
  modo: null,
  /** @type {'regua'|'area'|'cota'} a que o ícone abre */
  ultimoModo: 'regua',
  /** @type {Array<[number,number]>} pontos da medida em curso, em [lon, lat] */
  pontos: [],
  /** @type {[number,number]|null} onde o cursor está (já com ímã) */
  cursor: null,
  ima: true,
  /** @type {L.LatLng|null} canto em que o ímã pegou */
  imaEm: null,
  /** @type {L.LayerGroup|null} medidas prontas */
  prontas: null,
  /** @type {L.LayerGroup|null} a medida em curso */
  viva: null,
  captura: null,
  /** @type {Array<{id:number, modo:string, pts:Array<[number,number]>, grupo:L.LayerGroup}>} as medidas prontas */
  medidas: [],
  /** @type {number|null} id da medida selecionada */
  selecionada: null,
  _seq: 0,
  quantas: 0,
  ultimoClique: { t: 0, x: -99, y: -99 },
  _quadro: 0,
}

// ── NÚMEROS ──────────────────────────────────────────────────

/** @param {number} n @returns {string} quatro casas, com vírgula */
function fmtMed(n) {
  return n.toLocaleString('pt-BR', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
}

/** Os pontos no plano métrico, fixado no primeiro deles. @param {Array<[number,number]>} pts */
function _medNoPlano(pts) {
  const p = planoLocal(pts[0][1], pts[0][0])
  return pts.map(c => [(c[0] - p.lonRef) * p.porGrauLon, (c[1] - p.latRef) * p.porGrauLat])
}

/**
 * @param {Array<[number,number]>} pts em [lon, lat]
 * @param {boolean} fechado conta o lado que volta ao primeiro ponto
 * @returns {{lados:number[], total:number, area:number}}
 */
function medirPontos(pts, fechado) {
  if (pts.length < 2) return { lados: [], total: 0, area: 0 }
  const m = _medNoPlano(pts)
  const lados = []
  const n = m.length
  for (let i = 0; i < (fechado ? n : n - 1); i++) {
    const j = (i + 1) % n
    lados.push(Math.hypot(m[j][0] - m[i][0], m[j][1] - m[i][1]))
  }
  let s = 0
  if (fechado && n >= 3) {
    for (let i = 0; i < n; i++) {
      const j = (i + 1) % n
      s += m[i][0] * m[j][1] - m[j][0] * m[i][1]
    }
  }
  return { lados, total: lados.reduce((a, b) => a + b, 0), area: Math.abs(s) / 2 }
}

// ── ENTRAR E SAIR ────────────────────────────────────────────

/**
 * O ícone da coluna de controles: abre a barra de medição (ou a fecha).
 *
 * Abre já na última ferramenta usada — a régua, da primeira vez. Uma barra
 * sem ferramenta escolhida seria um passo a mais para o caso de sempre.
 */
function alternarMedir() {
  if (medState.modo) { sairDeMedir(); return }
  medirNoMapa(medState.ultimoModo)
}

/** Escolhe a ferramenta de medir, na barra. @param {'regua'|'area'|'cota'} modo */
function medirNoMapa(modo) {
  if (medState.modo === modo) return
  const trocar = () => {
    fecharPaineisMapa()
    _medPreparar()
    medState.modo = medState.ultimoModo = modo
    medState.pontos = []
    medState.cursor = null
    _medPintar()
  }
  // Já medindo, trocar de ferramenta não é abrir outra: só muda o gesto.
  if (medState.modo) trocar()
  else pedirFerramenta('medir', trocar)
}

function _medPreparar() {
  const mapa = mapaState.obj
  if (!mapa.getPane('medidas')) {
    // As medidas prontas ficam acima dos lotes e não recebem clique.
    const p = mapa.createPane('medidas')
    p.style.zIndex = 645
    p.style.pointerEvents = 'none'
    mapa.createPane('medir').style.zIndex = 655
  }
  medState.prontas ??= L.layerGroup().addTo(mapa)
  medState.viva ??= L.layerGroup().addTo(mapa)

  if (medState.captura) return
  // A folha de captura, como no desenho (desenho.js): sem ela o toque cairia
  // no lote e abriria o balão.
  mapa.getPane('medir').style.pointerEvents = ''
  medState.captura = L.rectangle([[-90, -180], [90, 180]],
    { pane: 'medir', interactive: true, stroke: false, fillOpacity: 0, className: 'desenho-captura' }).addTo(mapa)
  medState.captura.on('click', _medAoClicar)
  medState.captura.on('dblclick', _medAoDuploClique)
  mapa.on('mousemove', _medAoMover)
  mapa.doubleClickZoom.disable()
  document.getElementById('map').classList.add('desenhando')
}

/** Sai da ferramenta. As medidas PRONTAS continuam no mapa até o "Limpar tudo". */
function sairDeMedir() {
  const mapa = mapaState.obj
  if (medState.captura) {
    mapa.removeLayer(medState.captura)
    medState.captura = null
    mapa.off('mousemove', _medAoMover)
    mapa.doubleClickZoom.enable()
    mapa.getPane('medir').style.pointerEvents = 'none'
    document.getElementById('map').classList.remove('desenhando')
  }
  medState.modo = null
  medState.pontos = []
  medState.cursor = null
  medState.imaEm = null
  medState.viva?.clearLayers()
  _medSelecionar(null)
  document.getElementById('map').classList.remove('med-sobre')
  _medPintarBarra()
}

/** Apaga todas as medidas do mapa — as prontas e a que estiver em curso. */
function limparMedidas() {
  medState.prontas?.clearLayers()
  medState.viva?.clearLayers()
  medState.pontos = []
  medState.medidas = []
  medState.selecionada = null
  medState.quantas = 0
  if (medState.modo) _medPintar()
  else _medPintarBarra()
}

/** Tira o último ponto da medida em curso. */
function desfazerPontoDaMedida() {
  if (!medState.pontos.length) return
  medState.pontos.pop()
  _medPintar()
}

function alternarImaDaMedida() {
  medState.ima = !medState.ima
  medState.imaEm = null
  _medPintar()
}

// ── O ÍMÃ ────────────────────────────────────────────────────

/**
 * O canto desenhado mais perto do toque, dentro do raio do ímã — de lote,
 * edificação, quadra, contorno ou de outra medida. Segurar Alt solta.
 *
 * @param {L.LatLng} ll @param {MouseEvent} [ev] @returns {L.LatLng}
 */
function _medIma(ll, ev) {
  medState.imaEm = null
  if (!medState.ima || ev?.altKey) return ll
  const mapa = mapaState.obj
  const alvo = mapa.latLngToContainerPoint(ll)
  let melhor = null, dMelhor = MED_IMA_PX

  const testar = c => {
    const p = mapa.latLngToContainerPoint(c)
    const d = Math.hypot(p.x - alvo.x, p.y - alvo.y)
    if (d < dMelhor) { dMelhor = d; melhor = c }
  }
  const varrer = lista => {
    for (const c of lista) { if (Array.isArray(c)) varrer(c); else testar(c) }
  }
  // Só o que está perto: a caixa do traçado, com uma folga do tamanho do ímã.
  const folga = mapa.containerPointToLatLng([alvo.x + MED_IMA_PX, alvo.y - MED_IMA_PX])
  const dLat = Math.abs(folga.lat - ll.lat), dLng = Math.abs(folga.lng - ll.lng)

  mapa.eachLayer(l => {
    if (!(l instanceof L.Polyline) || l === medState.captura || l.options.pane === 'medir') return
    if (medState.viva?.hasLayer(l)) return
    const b = l.getBounds?.()
    if (!b || !b.isValid()) return
    if (ll.lat < b.getSouth() - dLat || ll.lat > b.getNorth() + dLat
      || ll.lng < b.getWest() - dLng || ll.lng > b.getEast() + dLng) return
    varrer(l.getLatLngs())
  })
  // Os pontos da própria medida em curso: fechar em cima do primeiro, por exemplo.
  medState.pontos.slice(0, -1).forEach(c => testar(L.latLng(c[1], c[0])))

  if (melhor) { medState.imaEm = melhor; return melhor }
  return ll
}

// ── SELECIONAR E APAGAR UMA MEDIDA ───────────────────────────

/** Distância, em pixels, do ponto p ao trecho a–b. */
function _medDistAoTrecho(p, a, b) {
  const dx = b.x - a.x, dy = b.y - a.y
  const t = dx || dy ? Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / (dx * dx + dy * dy))) : 0
  return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy))
}

/**
 * A medida pronta que está debaixo do toque: no traço ou numa etiqueta dela.
 * A de cima (a mais nova) ganha.
 *
 * @param {L.LatLng} ll @returns {Object|null}
 */
function _medSobToque(ll) {
  const mapa = mapaState.obj
  const p = mapa.latLngToContainerPoint(ll)
  const caixa = mapa.getContainer().getBoundingClientRect()
  const cx = caixa.left + p.x, cy = caixa.top + p.y

  for (let k = medState.medidas.length - 1; k >= 0; k--) {
    const m = medState.medidas[k]
    const px = m.pts.map(c => mapa.latLngToContainerPoint(_ll(c)))
    const ate = m.modo === 'area' ? px.length : px.length - 1
    for (let i = 0; i < ate; i++) {
      if (_medDistAoTrecho(p, px[i], px[(i + 1) % px.length]) <= MED_PEGA_PX) return m
    }
    let naEtiqueta = false
    m.grupo.eachLayer(l => {
      const r = l.getElement?.()?.querySelector('.med-rot:not(.med-tique)')?.getBoundingClientRect()
      if (r && cx >= r.left && cx <= r.right && cy >= r.top && cy <= r.bottom) naEtiqueta = true
    })
    if (naEtiqueta) return m
  }
  return null
}

/** Redesenha uma medida pronta — em destaque, se for a selecionada. */
function _medRedesenhar(m) {
  m.grupo.clearLayers()
  _medDesenhar(m.grupo, m.modo, m.pts, null, m.id === medState.selecionada)
}

/** Seleciona uma medida pronta (ou nenhuma). @param {number|null} id */
function _medSelecionar(id) {
  if (medState.selecionada === id) return
  const antes = medState.medidas.find(m => m.id === medState.selecionada)
  medState.selecionada = id
  if (antes) _medRedesenhar(antes)
  const agora = medState.medidas.find(m => m.id === id)
  if (agora) _medRedesenhar(agora)
  _medPintarBarra()
}

/** Apaga a medida selecionada. Delete e botão da barra. */
function apagarMedidaSelecionada() {
  const i = medState.medidas.findIndex(m => m.id === medState.selecionada)
  if (i < 0) return
  medState.prontas.removeLayer(medState.medidas[i].grupo)
  medState.medidas.splice(i, 1)
  medState.quantas = medState.medidas.length
  medState.selecionada = null
  document.getElementById('map').classList.remove('med-sobre')
  _medPintarBarra()
}

// ── EVENTOS ──────────────────────────────────────────────────

function _medAoClicar(ev) {
  L.DomEvent.stop(ev)
  if (!medState.modo) return

  // Metade de um duplo clique não vira ponto (o Leaflet manda os dois `click`
  // antes do `dblclick`).
  const p = mapaState.obj.latLngToContainerPoint(ev.latlng)
  const agora = Date.now(), ant = medState.ultimoClique
  if (agora - ant.t < MED_DUPLO_MS && Math.abs(p.x - ant.x) < MED_DUPLO_PX && Math.abs(p.y - ant.y) < MED_DUPLO_PX) return
  medState.ultimoClique = { t: agora, x: p.x, y: p.y }

  // Sem medida começada, o toque numa medida pronta a seleciona (Alt ignora).
  if (!medState.pontos.length && !ev.originalEvent?.altKey) {
    const m = _medSobToque(ev.latlng)
    if (m) { _medSelecionar(m.id); return }
  }
  _medSelecionar(null)

  const ll = _medIma(ev.latlng, ev.originalEvent)
  const pts = medState.pontos

  // Área: tocar de novo no primeiro ponto fecha o contorno.
  if (medState.modo === 'area' && pts.length >= 3 && ll.lat === pts[0][1] && ll.lng === pts[0][0]) {
    concluirMedida(); return
  }
  pts.push([ll.lng, ll.lat])
  medState.cursor = null

  // A cota é um trecho só: o segundo ponto já a conclui.
  if (medState.modo === 'cota' && pts.length === 2) { concluirMedida(); return }
  _medPintar()
}

function _medAoDuploClique(ev) {
  L.DomEvent.stop(ev)
  concluirMedida()
}

function _medAoMover(ev) {
  if (!medState.modo) return
  // A mãozinha diz que o toque vai selecionar, e não começar uma medida.
  document.getElementById('map').classList.toggle('med-sobre',
    !medState.pontos.length && !ev.originalEvent?.altKey && !!_medSobToque(ev.latlng))
  const ll = _medIma(ev.latlng, ev.originalEvent)
  medState.cursor = [ll.lng, ll.lat]
  // Um redesenho por quadro: o mouse dispara mais rápido do que a tela pinta.
  if (medState._quadro) return
  medState._quadro = setTimeout(() => { medState._quadro = 0; _medPintar() }, 16)
}

document.addEventListener('keydown', ev => {
  if (!medState.modo) return
  if (ev.target.closest?.('input,textarea,select,[contenteditable="true"]')) return
  if (document.querySelector('.modal-bg.open')) return
  if (ev.key === 'Delete' || (ev.key === 'Backspace' && !medState.pontos.length)) {
    if (medState.selecionada !== null) { apagarMedidaSelecionada(); ev.preventDefault() }
    return
  }
  if (ev.key === 'Escape') {
    // Esc larga, nesta ordem: a medida começada, a seleção, a ferramenta.
    if (medState.pontos.length) { medState.pontos = []; _medPintar() }
    else if (medState.selecionada !== null) _medSelecionar(null)
    else sairDeMedir()
    ev.preventDefault()
  } else if (ev.key === 'Enter') {
    concluirMedida(); ev.preventDefault()
  } else if (ev.key === 'Backspace' || (ev.key.toLowerCase() === 'z' && (ev.ctrlKey || ev.metaKey))) {
    desfazerPontoDaMedida(); ev.preventDefault()
  }
})

// ── CONCLUIR ─────────────────────────────────────────────────

/** Fecha a medida em curso e a deixa no mapa. A ferramenta continua pronta para a próxima. */
function concluirMedida() {
  const modo = medState.modo
  if (!modo) return
  const pts = medState.pontos
  const minimo = modo === 'area' ? 3 : 2
  if (pts.length < minimo) {
    toast(modo === 'area' ? 'A área precisa de ao menos três pontos.' : 'Marque ao menos dois pontos.', 'err')
    return
  }
  const m = { id: ++medState._seq, modo, pts: pts.map(c => [...c]), grupo: L.layerGroup().addTo(medState.prontas) }
  medState.medidas.push(m)
  _medDesenhar(m.grupo, modo, m.pts, null)
  medState.quantas = medState.medidas.length
  medState.pontos = []
  medState.cursor = null
  _medPintar()
}

// ── DESENHAR ─────────────────────────────────────────────────

const _ll = c => [c[1], c[0]]

/** Etiqueta presa a um ponto do mapa. @param {string} [estilo] transform extra do miolo */
function _medEtiqueta(grupo, c, html, css = '', estilo = '') {
  L.marker(_ll(c), {
    pane: 'medidas', interactive: false, keyboard: false,
    icon: L.divIcon({ className: 'med-marca', iconSize: null,
      html: `<span class="med-rot ${css}"${estilo ? ` style="transform:${estilo}"` : ''}>${html}</span>` }),
  }).addTo(grupo)
}

/** O ângulo do trecho NA TELA, em graus (o mapa não gira: vale em qualquer zoom). */
function _medAngulo(a, b) {
  const pa = mapaState.obj.latLngToContainerPoint(_ll(a))
  const pb = mapaState.obj.latLngToContainerPoint(_ll(b))
  return Math.atan2(pb.y - pa.y, pb.x - pa.x) * 180 / Math.PI
}

const _meio = (a, b) => [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2]

/**
 * O lote que contém um ponto do mapa — "Q 17 · Lote 12" —, ou '' se nenhum.
 *
 * A etiqueta da área fica no miolo do contorno, que é onde o mapa escreve o
 * número do lote: ela o cobria. Em vez de disputar o lugar, a etiqueta passa a
 * dizer qual é o lote.
 *
 * @param {[number,number]} c em [lon, lat] @returns {string}
 */
function _medLoteEm(c) {
  const ponto = L.latLng(c[1], c[0])
  for (const camada of mapaState.camadas || []) {
    const g = camada.feature?.geometry
    if (!g || !camada.getBounds?.().contains(ponto)) continue
    const poligonos = g.type === 'Polygon' ? [g.coordinates] : g.type === 'MultiPolygon' ? g.coordinates : []
    for (const aneis of poligonos) {
      // Dentro do anel de fora e fora dos buracos.
      if (!_medNoAnel(c, aneis[0]) || aneis.slice(1).some(b => _medNoAnel(c, b))) continue
      const p = camada.feature.properties || {}
      if (p.numero_lote == null || p.numero_lote === '') return ''
      return (p.quadra != null && p.quadra !== '' ? `Q ${p.quadra} · ` : '') + `Lote ${p.numero_lote}`
    }
  }
  return ''
}

/** O ponto está dentro do anel? (cruzamentos de um raio horizontal) */
function _medNoAnel(c, anel) {
  let dentro = false
  for (let i = 0, j = anel.length - 1; i < anel.length; j = i++) {
    const [xi, yi] = anel[i], [xj, yj] = anel[j]
    if ((yi > c[1]) !== (yj > c[1]) && c[0] < (xj - xi) * (c[1] - yi) / (yj - yi) + xi) dentro = !dentro
  }
  return dentro
}

/**
 * Desenha uma medida num grupo — a pronta e a em curso usam o mesmo traço.
 *
 * @param {L.LayerGroup} grupo
 * @param {'regua'|'area'|'cota'} modo
 * @param {Array<[number,number]>} pts
 * @param {[number,number]|null} cursor o ponto elástico, na medida em curso
 * @param {boolean} [sel] a medida está selecionada
 */
function _medDesenhar(grupo, modo, pts, cursor, sel = false) {
  const todos = cursor ? [...pts, cursor] : pts
  if (!todos.length) return
  const viva = !!cursor || grupo === medState.viva
  const cor = sel ? MED_COR_SEL : MED_COR
  const cssSel = sel ? ' med-sel' : ''
  const traco = { pane: 'medidas', interactive: false, color: cor, weight: sel ? 3.5 : 2.5, opacity: 1 }
  const sombra = { ...traco, color: '#0F172A', weight: 5, opacity: .55 }

  todos.forEach(c => L.circleMarker(_ll(c), { pane: 'medidas', interactive: false, radius: 3.5,
    color: '#0F172A', weight: 1.5, fillColor: cor, fillOpacity: 1 }).addTo(grupo))
  if (todos.length < 2) return

  if (modo === 'cota') {
    const [a, b] = todos
    const ang = _medAngulo(a, b)
    const de_pe = ang > 90 || ang <= -90 ? ang + 180 : ang   // o texto nunca fica de cabeça para baixo
    L.polyline([_ll(a), _ll(b)], sombra).addTo(grupo)
    L.polyline([_ll(a), _ll(b)], traco).addTo(grupo)
    // Os dois tiques das pontas, atravessados no trecho, como numa planta.
    for (const c of [a, b]) _medEtiqueta(grupo, c, '', 'med-tique' + cssSel, `translate(-50%,-50%) rotate(${(ang + 90).toFixed(1)}deg)`)
    _medEtiqueta(grupo, _meio(a, b), fmtMed(medirPontos([a, b], false).total) + ' m', 'med-cota' + cssSel,
      `translate(-50%,-50%) rotate(${de_pe.toFixed(1)}deg) translateY(-13px)`)
    return
  }

  const fechado = modo === 'area' && todos.length >= 3
  const m = medirPontos(todos, fechado)
  const linha = todos.map(_ll)
  if (fechado) {
    L.polygon(linha, { ...sombra, fill: false }).addTo(grupo)
    L.polygon(linha, { ...traco, fillColor: cor, fillOpacity: .16, dashArray: viva ? '6 5' : null }).addTo(grupo)
  } else {
    L.polyline(linha, sombra).addTo(grupo)
    L.polyline(linha, { ...traco, dashArray: viva ? '6 5' : null }).addTo(grupo)
  }

  // A medida de cada lado, no meio dele.
  m.lados.forEach((d, i) => {
    if (d < 0.00005) return
    _medEtiqueta(grupo, _meio(todos[i], todos[(i + 1) % todos.length]), fmtMed(d) + ' m', cssSel)
  })

  if (fechado) {
    const cx = todos.reduce((s, c) => s + c[0], 0) / todos.length
    const cy = todos.reduce((s, c) => s + c[1], 0) / todos.length
    // O lote em que o miolo do contorno cai, antes da área: a etiqueta fica
    // em cima do número que o mapa escreve ali.
    const lote = _medLoteEm([cx, cy])
    _medEtiqueta(grupo, [cx, cy],
      `${lote ? `<i>${esc(lote)}</i>` : ''}<b>${fmtMed(m.area)} m²</b><small>perímetro ${fmtMed(m.total)} m</small>`, 'med-total' + cssSel)
  } else if (modo === 'regua' && m.lados.length > 1) {
    _medEtiqueta(grupo, todos[todos.length - 1], `<b>total ${fmtMed(m.total)} m</b>`, 'med-total med-ponta' + cssSel)
  }
}

/** Redesenha a medida em curso e a barra. */
function _medPintar() {
  const g = medState.viva
  if (g) {
    g.clearLayers()
    if (medState.modo) {
      _medDesenhar(g, medState.modo, medState.pontos, medState.pontos.length ? medState.cursor : null)
      // Onde o ímã pegou: um anel no canto, antes de tocar.
      if (medState.imaEm) {
        L.circleMarker(medState.imaEm, { pane: 'medidas', interactive: false, radius: 8,
          color: MED_COR, weight: 2.5, fill: false }).addTo(g)
      }
    }
  }
  _medPintarBarra()
}

/** A barra no alto do mapa: o que se está medindo, quanto já deu e o que fazer. */
function _medPintarBarra() {
  const modo = medState.modo
  const barra = document.getElementById('med-barra')
  if (barra) {
    barra.hidden = !modo
    if (modo) {
      const pts = medState.pontos
      const n = pts.length
      const todos = n && medState.cursor ? [...pts, medState.cursor] : pts
      const m = medirPontos(todos, modo === 'area' && todos.length >= 3)
      document.getElementById('med-barra-passo').textContent =
        n === 0 ? (modo === 'cota' ? 'Toque nas duas pontas do trecho.' : modo === 'area' ? 'Toque nos cantos do contorno.' : 'Toque no ponto de partida.')
          : modo === 'area' && todos.length >= 3 ? `${fmtMed(m.area)} m² · perímetro ${fmtMed(m.total)} m`
            : todos.length >= 2 ? `${fmtMed(m.total)} m` + (modo === 'regua' && n >= 2 ? ' · duplo toque conclui' : '')
              : 'Toque no ponto seguinte.'
      document.getElementById('med-barra-concluir').hidden = modo === 'cota' || n < (modo === 'area' ? 3 : 2)
      document.getElementById('med-barra-desfazer').hidden = n === 0
      const sel = medState.selecionada !== null
      document.getElementById('med-barra-apagar').hidden = !sel
      if (sel && n === 0) document.getElementById('med-barra-passo').textContent = 'Medida selecionada · Delete apaga.'
      const ima = document.getElementById('med-barra-ima')
      ima.classList.toggle('at', medState.ima)
      ima.setAttribute('aria-pressed', String(medState.ima))
    }
    barra.querySelectorAll('.med-limpar').forEach(b => { b.hidden = !medState.quantas && !medState.pontos.length })
  }

  // A ferramenta em uso, na barra; e o ícone aceso enquanto ela está aberta.
  document.querySelectorAll('#med-barra .med-modos button').forEach(b => {
    b.classList.toggle('at', b.dataset.modo === modo)
    b.setAttribute('aria-pressed', String(b.dataset.modo === modo))
  })
  const icone = document.querySelector('#grupo-medir .ctrl-btn')
  if (icone) { icone.classList.toggle('med-ativo', !!modo); icone.setAttribute('aria-pressed', String(!!modo)) }
  // O ícone recolhido avisa que há medida no mapa.
  if (typeof marcarIndicadorControle === 'function') marcarIndicadorControle('grupo-medir', medState.quantas || null)
}
