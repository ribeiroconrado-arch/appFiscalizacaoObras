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

/**
 * AS CORES SÃO AS DO TEMA (escolha de 13/10/2026: "chumbo e verde do tema").
 * O ciano e o azul-escuro de antes não existiam em nenhum outro lugar do
 * sistema. Agora a linha é o tom claro do tema, o contorno o tom mais escuro,
 * e as etiquetas (tema-f.css) são chumbo — o escuro dos ícones e das dicas das
 * barras. Trocar o tema troca a medida junto.
 *
 * O Leaflet pinta os traços com cor escrita, e não com variável de CSS: por
 * isso as do tema são lidas da página a cada desenho.
 *
 * @returns {{linha:string, casca:string, miolo:string, borda:string}}
 */
function _medCores() {
  const css = getComputedStyle(document.documentElement)
  const v = (nome, reserva) => css.getPropertyValue(nome).trim() || reserva
  return { linha: v('--gm', '#A5D6A7'), casca: v('--gxd', '#00451A'), miolo: v('--g', '#009B3A'), borda: v('--gd', '#006B28') }
}
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
  /** @type {Object<string,boolean>} os encaixes ligados (OSNAP) — ver _medSnapsSalvos */
  snaps: {},
  /** @type {string|null} o tipo do encaixe em imaEm */
  imaTipo: null,
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
  medState.imaTipo = null
  opcoesSnapDaMedida(false)
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

// ── O OSNAP ──────────────────────────────────────────────────
//
// Como o da prancheta (13/11/2026): extremidade, ponto médio, interseção,
// na face e perpendicular, cada um ligado na janelinha do botão do ímã.
// A escolha fica guardada neste navegador. Segurar Alt solta num toque.

const MED_SNAP_TIPOS = {
  end:  { nome: 'Extremidade',   simbolo: '□' },
  mid:  { nome: 'Ponto médio',   simbolo: '△' },
  int:  { nome: 'Interseção',    simbolo: '⊠' },
  near: { nome: 'Na face',       simbolo: '×' },
  perp: { nome: 'Perpendicular', simbolo: '∟' },
}

/** @returns {{end:boolean, mid:boolean, int:boolean, near:boolean, perp:boolean}} */
function _medSnapsSalvos() {
  const padrao = { end: true, mid: true, int: true, near: false, perp: true }
  try { return { ...padrao, ...JSON.parse(localStorage.getItem('med-snaps') || '{}') } } catch { return padrao }
}
medState.snaps = _medSnapsSalvos()

/** Algum encaixe ligado? */
function _medSnapLigado() { return Object.values(medState.snaps).some(Boolean) }

/** Abre ou fecha a janelinha do OSNAP, debaixo do botão do ímã. @param {boolean} [abrir] */
function opcoesSnapDaMedida(abrir) {
  const pop = document.getElementById('med-snap-pop')
  const botao = document.getElementById('med-barra-ima')
  if (!pop || !botao) return
  pop.hidden = abrir === undefined ? !pop.hidden : !abrir
  botao.setAttribute('aria-expanded', String(!pop.hidden))
  botao.classList.toggle('aberto', !pop.hidden)
  if (pop.hidden) return
  Object.keys(MED_SNAP_TIPOS).forEach(k => {
    const c = document.getElementById('med-snap-' + k)
    if (c) c.checked = !!medState.snaps[k]
  })
  const r = botao.getBoundingClientRect()
  const w = pop.offsetWidth || 300
  pop.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) + 'px'
  pop.style.top = (r.bottom + 8) + 'px'
  pop.style.maxHeight = Math.max(120, window.innerHeight - r.bottom - 16) + 'px'
}

/** Liga ou desliga um tipo de encaixe. @param {string} k @param {boolean} ligado */
function marcarSnapDaMedida(k, ligado) {
  medState.snaps[k] = ligado
  try { localStorage.setItem('med-snaps', JSON.stringify(medState.snaps)) } catch {}
  medState.imaEm = null
  _medPintar()
}

// Fecha a janelinha ao tocar fora dela e com Esc.
document.addEventListener('pointerdown', ev => {
  const pop = document.getElementById('med-snap-pop')
  if (!pop || pop.hidden) return
  if (pop.contains(ev.target) || ev.target.closest?.('#med-barra-ima')) return
  opcoesSnapDaMedida(false)
}, true)
document.addEventListener('keydown', ev => {
  const pop = document.getElementById('med-snap-pop')
  if (ev.key === 'Escape' && pop && !pop.hidden) { opcoesSnapDaMedida(false); ev.stopPropagation() }
}, true)
window.addEventListener('resize', () => { if (document.getElementById('med-snap-pop')?.hidden === false) opcoesSnapDaMedida(true) })

/** Os trechos desenhados perto do toque, em pixels de tela, com os LatLng das pontas. */
function _medTrechosPerto(ll, folgaPx) {
  const mapa = mapaState.obj
  const alvo = mapa.latLngToContainerPoint(ll)
  const canto = mapa.containerPointToLatLng([alvo.x + folgaPx, alvo.y - folgaPx])
  const dLat = Math.abs(canto.lat - ll.lat), dLng = Math.abs(canto.lng - ll.lng)
  const trechos = []
  const anel = (lista, fechado) => {
    const n = lista.length
    for (let i = 0; i < (fechado ? n : n - 1); i++) {
      const a = lista[i], b = lista[(i + 1) % n]
      if (a.equals(b)) continue
      const pa = mapa.latLngToContainerPoint(a), pb = mapa.latLngToContainerPoint(b)
      if (_medDistAoTrecho(alvo, pa, pb) > folgaPx) continue
      trechos.push({ a, b, pa, pb })
    }
  }
  const varrer = (lista, fechado) => {
    if (!lista.length) return
    if (Array.isArray(lista[0])) lista.forEach(l => varrer(l, fechado))
    else anel(lista, fechado)
  }
  mapa.eachLayer(l => {
    if (!(l instanceof L.Polyline) || l === medState.captura || l.options.pane === 'medir') return
    if (medState.viva?.hasLayer(l)) return
    const b = l.getBounds?.()
    if (!b || !b.isValid()) return
    if (ll.lat < b.getSouth() - dLat || ll.lat > b.getNorth() + dLat
      || ll.lng < b.getWest() - dLng || ll.lng > b.getEast() + dLng) return
    varrer(l.getLatLngs(), l instanceof L.Polygon)
  })
  // A própria medida em curso, menos o trecho que vai até o cursor.
  const proprios = medState.pontos.map(c => L.latLng(c[1], c[0]))
  if (proprios.length >= 2) anel(proprios, false)
  return { alvo, trechos, proprios }
}

/** O ponto do trecho a t (0 = a, 1 = b). */
function _medNoTrecho(tr, t) {
  return L.latLng(tr.a.lat + (tr.b.lat - tr.a.lat) * t, tr.a.lng + (tr.b.lng - tr.a.lng) * t)
}

/** t da projeção do ponto p (tela) sobre o trecho. */
function _medT(p, tr) {
  const dx = tr.pb.x - tr.pa.x, dy = tr.pb.y - tr.pa.y
  return ((p.x - tr.pa.x) * dx + (p.y - tr.pa.y) * dy) / (dx * dx + dy * dy)
}

/**
 * O encaixe mais perto do toque, dentro do raio do ímã — em lote, edificação,
 * quadra, contorno ou outra medida. A extremidade ganha no empate; a "na face"
 * só vale quando nenhum outro pegou.
 *
 * @param {L.LatLng} ll @param {MouseEvent} [ev] @returns {L.LatLng}
 */
function _medIma(ll, ev) {
  medState.imaEm = null
  medState.imaTipo = null
  const s = medState.snaps
  if (!_medSnapLigado() || ev?.altKey) return ll
  const mapa = mapaState.obj
  const { alvo, trechos, proprios } = _medTrechosPerto(ll, MED_IMA_PX * 2)
  const ORDEM = { end: 0, int: 1, mid: 2, perp: 3, near: 4 }
  let melhor = null, dMelhor = MED_IMA_PX, tipo = null

  const testar = (c, k) => {
    const p = mapa.latLngToContainerPoint(c)
    const d = Math.hypot(p.x - alvo.x, p.y - alvo.y)
    if (d < dMelhor - 1e-6 || (Math.abs(d - dMelhor) <= 1e-6 && tipo && ORDEM[k] < ORDEM[tipo])) {
      dMelhor = d; melhor = c; tipo = k
    }
  }

  for (const tr of trechos) {
    if (s.end) { testar(tr.a, 'end'); testar(tr.b, 'end') }
    if (s.mid) testar(_medNoTrecho(tr, 0.5), 'mid')
  }
  // Fechar em cima do primeiro ponto da medida em curso.
  if (s.end) proprios.slice(0, -1).forEach(c => testar(c, 'end'))

  if (s.int) {
    for (let i = 0; i < trechos.length; i++) for (let j = i + 1; j < trechos.length; j++) {
      const A = trechos[i], B = trechos[j]
      const rx = A.pb.x - A.pa.x, ry = A.pb.y - A.pa.y, sx = B.pb.x - B.pa.x, sy = B.pb.y - B.pa.y
      const den = rx * sy - ry * sx
      if (Math.abs(den) < 1e-9) continue
      const qx = B.pa.x - A.pa.x, qy = B.pa.y - A.pa.y
      const t = (qx * sy - qy * sx) / den, u = (qx * ry - qy * rx) / den
      // Ponta com ponta já é extremidade: aqui só o cruzamento no meio.
      if (t <= 1e-6 || t >= 1 - 1e-6 || u < -1e-6 || u > 1 + 1e-6) continue
      testar(_medNoTrecho(A, t), 'int')
    }
  }

  const base = proprios.length ? proprios[proprios.length - 1] : null
  if (s.perp && base) {
    const pBase = mapa.latLngToContainerPoint(base)
    for (const tr of trechos) {
      const t = _medT(pBase, tr)
      if (t < 0 || t > 1) continue
      const q = _medNoTrecho(tr, t)
      if (mapa.latLngToContainerPoint(q).distanceTo(pBase) > 0.5) testar(q, 'perp')
    }
  }

  if (!melhor && s.near) {
    for (const tr of trechos) testar(_medNoTrecho(tr, Math.max(0, Math.min(1, _medT(alvo, tr)))), 'near')
  }

  if (melhor) { medState.imaEm = melhor; medState.imaTipo = tipo; return melhor }
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
 * O deslocamento que tira a etiqueta de cima do trecho a→b: ela anda na
 * perpendicular da linha (na tela) o bastante para a caixa inteira ficar de
 * um lado só, com uma folga. O lado é o de cima (ou a direita, na vertical);
 * com `longe` (o miolo do contorno), o lado de fora.
 *
 * O ângulo na tela não muda com o zoom (o mapa não gira), então o
 * deslocamento em pixels vale em qualquer zoom.
 *
 * @param {[number,number]} a @param {[number,number]} b @param {string} texto
 * @param {L.Point|null} [longe] ponto da tela do qual a etiqueta foge
 * @returns {string} o transform da etiqueta
 */
function _medAoLado(a, b, texto, longe = null) {
  const pa = mapaState.obj.latLngToContainerPoint(_ll(a))
  const pb = mapaState.obj.latLngToContainerPoint(_ll(b))
  const len = Math.hypot(pb.x - pa.x, pb.y - pa.y)
  if (len < 1) return 'translate(-50%,-50%)'
  let nx = -(pb.y - pa.y) / len, ny = (pb.x - pa.x) / len
  if (longe) {
    const mx = (pa.x + pb.x) / 2, my = (pa.y + pb.y) / 2
    if (nx * (longe.x - mx) + ny * (longe.y - my) > 0) { nx = -nx; ny = -ny }
  } else if (ny > 0.001 || (Math.abs(ny) <= 0.001 && nx < 0)) { nx = -nx; ny = -ny }
  // O tamanho da caixa (.med-rot: mono 11,5px, 6px de respiro e 1px de borda).
  const w = texto.length * 7 + 14, h = 20
  const d = (w / 2) * Math.abs(nx) + (h / 2) * Math.abs(ny) + 5
  return `translate(-50%,-50%) translate(${(nx * d).toFixed(1)}px,${(ny * d).toFixed(1)}px)`
}

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
  const tema = _medCores()
  const cor = sel ? MED_COR_SEL : tema.linha
  const cssSel = sel ? ' med-sel' : ''
  const traco = { pane: 'medidas', interactive: false, color: cor, weight: sel ? 3.5 : 2.5, opacity: 1 }
  const sombra = { ...traco, color: tema.casca, weight: 5, opacity: .6 }

  todos.forEach(c => L.circleMarker(_ll(c), { pane: 'medidas', interactive: false, radius: 3.5,
    color: sel ? tema.casca : tema.borda, weight: 1.5, fillColor: sel ? cor : '#FFFFFF', fillOpacity: 1 }).addTo(grupo))
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
    L.polygon(linha, { ...traco, fillColor: sel ? cor : tema.miolo, fillOpacity: .16, dashArray: viva ? '6 5' : null }).addTo(grupo)
  } else {
    L.polyline(linha, sombra).addTo(grupo)
    L.polyline(linha, { ...traco, dashArray: viva ? '6 5' : null }).addTo(grupo)
  }

  // A medida de cada lado, no meio dele — AO LADO da linha, e não em cima
  // dela: acima na régua; no contorno, do lado de fora.
  const centro = fechado ? mapaState.obj.latLngToContainerPoint(L.latLng(
    todos.reduce((s, c) => s + c[1], 0) / todos.length, todos.reduce((s, c) => s + c[0], 0) / todos.length)) : null
  m.lados.forEach((d, i) => {
    if (d < 0.00005) return
    const a = todos[i], b = todos[(i + 1) % todos.length], texto = fmtMed(d) + ' m'
    _medEtiqueta(grupo, _meio(a, b), texto, cssSel, _medAoLado(a, b, texto, centro))
  })

  if (fechado) {
    const cx = todos.reduce((s, c) => s + c[0], 0) / todos.length
    const cy = todos.reduce((s, c) => s + c[1], 0) / todos.length
    // O lote em que o miolo do contorno cai, antes da área: a etiqueta fica
    // em cima do número que o mapa escreve ali.
    const lote = _medLoteEm([cx, cy])
    _medEtiqueta(grupo, [cx, cy],
      `${lote ? `<i>${esc(lote)}</i>` : ''}<b>${fmtMed(m.area)} m²</b><small>perím. ${fmtMed(m.total)} m</small>`, 'med-total' + cssSel)
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
      // Onde o encaixe pegou, com o símbolo do tipo (□ △ ⊠ × ∟), antes de tocar.
      if (medState.imaEm) {
        const t = MED_SNAP_TIPOS[medState.imaTipo] || MED_SNAP_TIPOS.end
        L.marker(medState.imaEm, { pane: 'medidas', interactive: false, keyboard: false,
          icon: L.divIcon({ className: 'med-snap-marca', iconSize: [22, 22], iconAnchor: [11, 11],
            html: `<span title="${t.nome}">${t.simbolo}</span>` }) }).addTo(g)
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
    const estavaOculta = barra.hidden
    barra.hidden = !modo
    if (modo && estavaOculta) posicionarBarraMedicao()
    if (modo) {
      const pts = medState.pontos
      const n = pts.length
      const todos = n && medState.cursor ? [...pts, medState.cursor] : pts
      const m = medirPontos(todos, modo === 'area' && todos.length >= 3)
      document.getElementById('med-barra-passo').textContent =
        n === 0 ? (modo === 'cota' ? 'Toque nas duas pontas do trecho.' : modo === 'area' ? 'Toque nos cantos do contorno.' : 'Toque no ponto de partida.')
          : `${n} ${n === 1 ? 'ponto' : 'pontos'} · ${n >= (modo === 'area' ? 3 : 2) ? 'Concluir para finalizar' : 'Marque o próximo ponto'}`
      const resultado = document.getElementById('med-barra-resultado')
      resultado.textContent = modo === 'area' && todos.length >= 3 ? `${fmtMed(m.area)} m² · perím. ${fmtMed(m.total)} m`
        : `${fmtMed(m.total)} m`
      const concluir = document.getElementById('med-barra-concluir')
      concluir.hidden = modo === 'cota'
      concluir.disabled = n < (modo === 'area' ? 3 : 2)
      document.getElementById('med-barra-desfazer').disabled = n === 0
      const sel = medState.selecionada !== null
      document.getElementById('med-barra-apagar').hidden = !sel
      if (sel && n === 0) {
        document.getElementById('med-barra-passo').textContent = 'Medida selecionada'
        const selecionada = medState.medidas.find(item => item.id === medState.selecionada)
        if (selecionada) {
          const medida = medirPontos(selecionada.pts, selecionada.modo === 'area')
          resultado.textContent = selecionada.modo === 'area' ? `${fmtMed(medida.area)} m² · perím. ${fmtMed(medida.total)} m` : `${fmtMed(medida.total)} m`
        }
      }
      const ima = document.getElementById('med-barra-ima')
      ima.classList.toggle('at', _medSnapLigado())
      if (medState.imaEm && medState.imaTipo) {
        document.getElementById('med-barra-passo').textContent += ` · Snap: ${MED_SNAP_TIPOS[medState.imaTipo].nome}`
      }
    }
    barra.querySelectorAll('.med-limpar').forEach(b => { b.disabled = !medState.quantas && !medState.pontos.length })
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

/** No celular, encaixa a barra na borda inferior do mapa, acima da navegação. */
let medBarraPosicao = null
function posicionarBarraMedicao() {
  const barra = document.getElementById('med-barra')
  const mapa = document.getElementById('map')
  if (!barra || !mapa) return
  const mobile = window.innerWidth <= 620
  const r = mapa.getBoundingClientRect()
  const dockTop = window.innerWidth < 1280 ? document.querySelector('.abas')?.getBoundingClientRect().top : Infinity
  const limiteInferior = Math.min(r.bottom, dockTop ?? Infinity)
  if (medBarraPosicao) {
    // A área livre do mapa: sem os painéis altos encostados nas laterais (a
    // mesa do lote, a do desmembramento, a correção, a pesquisa) — a barra
    // não passa por cima deles.
    let esq = r.left, dir = r.right
    for (const p of document.querySelectorAll('#t-mapa .cad-mesa, #t-mapa .desm-mesa, .base-mesa, #t-mapa .corr-painel, #t-mapa > #pesq-barra')) {
      if (p.hidden || p.contains(barra)) continue
      const q = p.getBoundingClientRect()
      if (!q.width || q.height < r.height / 2 || q.bottom <= r.top || q.top >= r.bottom) continue
      if (q.left <= r.left + 24 && q.right < r.left + r.width / 2) esq = Math.max(esq, q.right)
      else if (q.right >= r.right - 24 && q.left > r.left + r.width / 2) dir = Math.min(dir, q.left)
    }
    if (dir - esq < 260) { esq = r.left; dir = r.right }   // sem espaço: vale o mapa inteiro
    barra.style.width = Math.min(570, Math.max(0, dir - esq - 16)) + 'px'
    const b = barra.getBoundingClientRect()
    medBarraPosicao.x = Math.max(esq + 8, Math.min(medBarraPosicao.x, dir - b.width - 8))
    medBarraPosicao.y = Math.max(r.top + 8, Math.min(medBarraPosicao.y, limiteInferior - b.height - 8))
    barra.style.left = medBarraPosicao.x + 'px'
    barra.style.top = medBarraPosicao.y + 'px'
    barra.style.bottom = 'auto'
    return
  }
  barra.style.left = mobile ? r.left + 'px' : ''
  barra.style.width = mobile ? r.width + 'px' : ''
  const nav = document.querySelector('.abas')?.getBoundingClientRect()
  barra.style.bottom = mobile ? Math.max(0, window.innerHeight - Math.min(r.bottom, nav ? nav.top - 8 : r.bottom)) + 'px' : ''
}
window.addEventListener('resize', posicionarBarraMedicao)
document.addEventListener('DOMContentLoaded', () => {
  const mapa = document.getElementById('map')
  if (mapa) new ResizeObserver(posicionarBarraMedicao).observe(mapa)
  // Mesa do lote abrindo ou fechando (classe no body): a barra solta sai de baixo dela.
  new MutationObserver(() => { if (medBarraPosicao && !document.getElementById('med-barra')?.hidden) posicionarBarraMedicao() })
    .observe(document.body, { attributes: true, attributeFilter: ['class'] })
  const barra = document.getElementById('med-barra')
  const cab = barra?.querySelector('.med-titulo')
  if (!cab) return
  cab.title = 'Arraste para mover a barra'
  let arrasto = null
  cab.addEventListener('pointerdown', e => {
    if (e.button !== 0) return
    e.preventDefault(); e.stopPropagation()
    const r = barra.getBoundingClientRect()
    arrasto = { x: e.clientX, y: e.clientY, left: r.left, top: r.top }
    medBarraPosicao = { x: r.left, y: r.top }
    document.getElementById('t-mapa').appendChild(barra)
    barra.classList.add('med-movel')
    posicionarBarraMedicao()
    cab.setPointerCapture(e.pointerId)
  })
  cab.addEventListener('pointermove', e => {
    if (!arrasto) return
    e.preventDefault(); e.stopPropagation()
    medBarraPosicao = { x: arrasto.left + e.clientX - arrasto.x, y: arrasto.top + e.clientY - arrasto.y }
    posicionarBarraMedicao()
  })
  const terminar = () => { arrasto = null }
  cab.addEventListener('pointerup', terminar)
  cab.addEventListener('pointercancel', terminar)
  cab.addEventListener('lostpointercapture', terminar)
})
