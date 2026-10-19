/**
 * EDITAR O CONTORNO DO BAIRRO — vértice a vértice, como no AutoCAD.
 *
 * O contorno gerado pelo raio deixa lacunas entre bairros vizinhos. Aqui o
 * curador as fecha direto no mapa:
 *
 *   vértice (quadrado)   arrastar move; clicar seleciona; Delete apaga
 *   meio do lado (▬)     arrastar leva o LADO INTEIRO na perpendicular, paralelo
 *                        a ele mesmo (o "stretch" do AutoCAD): os lados vizinhos
 *                        esticam. Shift + arrastar cria um vértice novo ali
 *   ímã                  o vértice gruda no vértice ou na linha do bairro
 *                        vizinho (Alt segurado desliga) — a divisa fica comum
 *   Encostar no vizinho  escolha dois vértices: o trecho entre eles passa a
 *                        seguir a divisa do vizinho mais próximo, de uma vez
 *   Simplificar          tira vértices que não mudam a forma (0,5 m)
 *   Ctrl+Z / Ctrl+Y      desfazer / refazer
 *
 * A cada passo o servidor confere as três regras (EdicaoDeContorno): polígono
 * válido, nenhum lote nem quadra do bairro de fora, sem invadir outro bairro.
 * O que falha aparece no mapa (invasão em vermelho, lotes de fora em
 * vermelho) e "Gravar" só libera quando tudo fecha.
 *
 * Coordenadas em [lon, lat], como o GeoJSON; o Leaflet recebe [lat, lon].
 */

const ceState = {
  ativo: false, bairro: null,
  /** @type {Array<Array<Array<[number,number]>>>} polígonos → anéis → vértices (sem repetir o primeiro) */
  polis: [],
  undo: [], redo: [], sel: null, modo: null, encostarA: null,
  camada: null, alcas: null, ima: null, invasoes: null,
  vizinhos: [], conf: null, timer: null, pedido: 0, gravando: false, alterado: false,
  lotesFora: new Set(),
  /** Shift e Alt seguros agora: o arrasto do Leaflet não traz o teclado. */
  teclas: { shift: false, alt: false },
}

for (const t of ['keydown', 'keyup']) document.addEventListener(t, ev => { ceState.teclas.shift = ev.shiftKey; ceState.teclas.alt = ev.altKey }, true)
window.addEventListener('blur', () => { ceState.teclas.shift = ceState.teclas.alt = false })

/** Abaixo deste zoom os vértices não aparecem; acima, aparecem se couberem (CE_MAX_ALCAS). */
const CE_ZOOM_ALCAS = 13
/** Mais que isso na tela, só os vértices (sem os meios dos lados). */
const CE_MAX_ALCAS = 500
const CE_IMA_VERTICE_PX = 12
const CE_IMA_LADO_PX = 9
const CE_SIMPLIFICAR_M = 0.5

FERRAMENTAS_MAPA.contorno = {
  rotulo: 'a edição do contorno',
  aberta: () => ceState.ativo,
  emCurso: () => ceState.alterado ? 'um contorno alterado e não gravado' : null,
  // Outra ferramenta pediu a vez: sai sem voltar à lista.
  fechar: () => sairDaEdicaoDoContorno(true, false),
}

// ── geometria ───────────────────────────────────────────────────

const _ceCopia = v => JSON.parse(JSON.stringify(v))
/** Metros num plano local em volta de `o` [lon, lat]. */
function _ceM(o) {
  const kx = 111320 * Math.cos(o[1] * Math.PI / 180), ky = 111320
  return { para: p => [(p[0] - o[0]) * kx, (p[1] - o[1]) * ky], de: q => [o[0] + q[0] / kx, o[1] + q[1] / ky] }
}
function _ceSegDist(p, a, b) {
  const dx = b[0] - a[0], dy = b[1] - a[1], l2 = dx * dx + dy * dy
  const t = l2 ? Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / l2)) : 0
  const q = [a[0] + t * dx, a[1] + t * dy]
  return { d: Math.hypot(p[0] - q[0], p[1] - q[1]), q, t }
}
/** O ponto do anel mais perto de p (num plano em metros). */
function _cePertoNoAnel(p, anel) {
  let melhor = { d: Infinity }
  for (let k = 0; k < anel.length; k++) {
    const r = _ceSegDist(p, anel[k], anel[(k + 1) % anel.length])
    if (r.d < melhor.d) melhor = { ...r, k }
  }
  return melhor
}
function _ceComprimento(pts) { let s = 0; for (let i = 1; i < pts.length; i++) s += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]); return s }
/** Douglas-Peucker num anel fechado (metros). */
function _ceSimplificarAnel(anel, tol) {
  if (anel.length <= 4) return anel
  const dp = (pts) => {
    if (pts.length < 3) return pts
    let max = 0, idx = 0
    for (let i = 1; i < pts.length - 1; i++) { const d = _ceSegDist(pts[i], pts[0], pts.at(-1)).d; if (d > max) { max = d; idx = i } }
    if (max <= tol) return [pts[0], pts.at(-1)]
    return [...dp(pts.slice(0, idx + 1)).slice(0, -1), ...dp(pts.slice(idx))]
  }
  // O anel é partido no vértice mais distante do primeiro, para o fecho não sumir.
  let far = 0, fd = 0
  anel.forEach((p, i) => { const d = Math.hypot(p[0] - anel[0][0], p[1] - anel[0][1]); if (d > fd) { fd = d; far = i } })
  const a = dp(anel.slice(0, far + 1)), b = dp([...anel.slice(far), anel[0]])
  const r = [...a.slice(0, -1), ...b.slice(0, -1)]
  return r.length >= 3 ? r : anel
}

/** O que vai ao servidor: MultiPolygon com os anéis fechados. */
function _ceGeometria() {
  return { type: 'MultiPolygon', coordinates: ceState.polis.map(p => p.map(r => [...r, r[0]].map(c => [Number(c[0].toFixed(10)), Number(c[1].toFixed(10))]))) }
}

// ── abrir e sair ────────────────────────────────────────────────

/** Da lista "Contorno dos bairros": edita o contorno gravado de um bairro. */
function editarContornoDoBairro(nome) {
  const f = contornoState.dados?.features?.find(x => x.properties?.nome === nome)
  if (!f) { toast('Este bairro ainda não tem contorno: gere-o antes de editar.', 'aviso'); return }
  pedirFerramenta('contorno', () => { _ceAbrir(nome, f); ceState.daLista = true })
}

function _ceAbrir(nome, f) {
  if (typeof fecharImportacoes === 'function') fecharImportacoes()
  const m = mapaState.obj
  const g = f.geometry
  const polis = (g.type === 'Polygon' ? [g.coordinates] : g.coordinates)
    .map(p => p.map(r => { const a = r.map(c => [c[0], c[1]]); if (a.length > 1 && a[0][0] === a.at(-1)[0] && a[0][1] === a.at(-1)[1]) a.pop(); return a }))
  Object.assign(ceState, { ativo: true, bairro: nome, polis, undo: [], redo: [], sel: null, modo: null, encostarA: null,
    conf: null, alterado: false, gravando: false })
  ceState.vizinhos = (contornoState.dados.features || []).filter(x => x.properties?.nome !== nome).flatMap(x => {
    const gg = x.geometry, ps = gg.type === 'Polygon' ? [gg.coordinates] : gg.coordinates
    return ps.flatMap(p => p.map(r => {
      const a = r.map(c => [c[0], c[1]]); if (a.length > 1 && a[0][0] === a.at(-1)[0] && a[0][1] === a.at(-1)[1]) a.pop()
      let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity
      for (const c of a) { x0 = Math.min(x0, c[0]); x1 = Math.max(x1, c[0]); y0 = Math.min(y0, c[1]); y1 = Math.max(y1, c[1]) }
      return { nome: x.properties.nome, anel: a, caixa: [x0, y0, x1, y1] }
    }))
  })
  if (!m.getPane('ceContorno')) {
    m.createPane('ceContorno').style.zIndex = 455
    m.getPane('ceContorno').style.pointerEvents = 'none'
    m.createPane('ceAlcas').style.zIndex = 660
  }
  ceState.camada = L.polygon([], { pane: 'ceContorno', interactive: false, color: '#2563EB', weight: 2.4, opacity: 1,
    fillColor: '#2563EB', fillOpacity: .05 }).addTo(m)
  ceState.alcas = L.layerGroup().addTo(m)
  ceState.invasoes = L.layerGroup().addTo(m)
  _ceDesenhar()
  m.fitBounds(ceState.camada.getBounds(), { padding: [40, 40] })
  m.on('moveend', _ceDesenharAlcas)
  document.addEventListener('keydown', _ceTeclas, true)
  _cePintarBarra()
  _ceConferir()
}

/** Sai da edição. `semPerguntar` vem de quem já confirmou (outra ferramenta pediu a vez). */
/**
 * Sai da edição. `semPerguntar` vem de quem já confirmou; `voltar` reabre a
 * lista "Contorno dos bairros" na linha do bairro, como as outras ferramentas
 * que se abrem de uma janela (a não ser quando outra ferramenta pediu a vez).
 */
function sairDaEdicaoDoContorno(semPerguntar = false, voltar = true) {
  if (!ceState.ativo) return
  if (ceState.alterado && !semPerguntar) {
    confirmarAcao({ titulo: 'Sair sem gravar?', mensagem: 'As alterações do contorno se perdem.', textoBtn: 'Sair sem gravar', perigo: true,
      onConfirm: () => sairDaEdicaoDoContorno(true, voltar) })
    return
  }
  const m = mapaState.obj, bairro = ceState.bairro, daLista = ceState.daLista
  clearTimeout(ceState.timer)
  _ceLimparPrevia()
  ceState.camada?.remove(); ceState.alcas?.remove(); ceState.invasoes?.remove(); ceState.ima?.remove()
  ceState.camada = ceState.alcas = ceState.invasoes = ceState.ima = null
  m.off('moveend', _ceDesenharAlcas)
  document.removeEventListener('keydown', _ceTeclas, true)
  _ceMarcarLotesFora([])
  ceState.ativo = false; ceState.daLista = false; ceState.modo = null; ceState.encostarA = null
  const barra = document.getElementById('ce-barra'); if (barra) { barra.hidden = true; barra.innerHTML = '' }
  document.dispatchEvent(new CustomEvent('ferramenta-encerrada'))
  if (voltar && daLista && typeof abrirContornosDosBairros === 'function') {
    abrirContornosDosBairros().then(() => {
      const tr = document.querySelector(`#m-importacoes tr[data-bairro="${CSS.escape(bairro)}"]`)
      if (tr) { tr.scrollIntoView({ block: 'center' }); tr.classList.add('jt-realce'); setTimeout(() => tr.classList.remove('jt-realce'), 2200) }
    })
  }
}

// ── desenho ─────────────────────────────────────────────────────

function _ceDesenhar() {
  ceState.camada?.setLatLngs(ceState.polis.map(p => p.map(r => r.map(c => [c[1], c[0]]))))
  _ceDesenharAlcas()
}

function _ceDesenharAlcas() {
  const m = mapaState.obj, g = ceState.alcas
  if (!g) return
  g.clearLayers()
  _cePintarBarra()
  if (m.getZoom() < CE_ZOOM_ALCAS) return
  const vista = m.getBounds().pad(0.1)
  const vs = [], ms = []
  ceState.polis.forEach((p, pi) => p.forEach((r, ri) => r.forEach((c, i) => {
    if (vista.contains([c[1], c[0]])) vs.push([pi, ri, i])
    const d = r[(i + 1) % r.length], meio = [(c[0] + d[0]) / 2, (c[1] + d[1]) / 2]
    if (vista.contains([meio[1], meio[0]])) ms.push([pi, ri, i, meio])
  })))
  const comMeios = vs.length + ms.length <= CE_MAX_ALCAS
  for (const [pi, ri, i] of vs.slice(0, CE_MAX_ALCAS)) _ceAlcaVertice(pi, ri, i)
  if (comMeios) for (const [pi, ri, i, meio] of ms) _ceAlcaMeio(pi, ri, i, meio)
}

function _ceIcone(cls, angulo = 0) {
  if (cls.includes('ce-m')) return L.divIcon({ className: 'ce-alca ' + cls, iconSize: [18, 18], html: `<span style="transform:rotate(${angulo.toFixed(1)}deg)"></span>` })
  return L.divIcon({ className: 'ce-alca ' + cls, iconSize: [11, 11] })
}

function _ceAlcaVertice(pi, ri, i) {
  const c = ceState.polis[pi][ri][i]
  const s = ceState.sel, selecionado = s && s[0] === pi && s[1] === ri && s[2] === i
  const ea = ceState.encostarA, escolhido = ea && ea[0] === pi && ea[1] === ri && ea[2] === i
  const mk = L.marker([c[1], c[0]], { pane: 'ceAlcas', draggable: !ceState.modo, keyboard: false,
    icon: _ceIcone('ce-v' + (selecionado ? ' ce-sel' : '') + (escolhido ? ' ce-escolhido' : '')) })
  let antes = null
  mk.on('click', () => _ceClicouVertice(pi, ri, i))
  mk.on('dragstart', () => { antes = _ceCopia(ceState.polis); ceState.sel = [pi, ri, i] })
  mk.on('drag', ev => { const p = _ceImantar(ev.latlng, ev.originalEvent); ceState.polis[pi][ri][i] = p; mk.setLatLng([p[1], p[0]]); ceState.camada.setLatLngs(ceState.polis.map(pp => pp.map(r => r.map(cc => [cc[1], cc[0]])))) })
  mk.on('dragend', () => { _ceMarcaIma(null); _ceEmpilhar(antes) })
  mk.addTo(ceState.alcas)
}

/**
 * A alça do MEIO DO LADO (retângulo deitado no lado), como no AutoCAD:
 * arrastar leva o lado inteiro na perpendicular, paralelo a ele mesmo — só a
 * componente do arrasto na direção normal ao lado conta. Os dois vértices do
 * lado andam juntos e os lados vizinhos esticam. Com Shift, cria um vértice.
 */
function _ceAlcaMeio(pi, ri, i, meio) {
  const anel = ceState.polis[pi][ri], j = (i + 1) % anel.length, m = mapaState.obj
  const pa = m.latLngToContainerPoint([anel[i][1], anel[i][0]]), pb = m.latLngToContainerPoint([anel[j][1], anel[j][0]])
  const angulo = Math.atan2(pb.y - pa.y, pb.x - pa.x) * 180 / Math.PI
  const mk = L.marker([meio[1], meio[0]], { pane: 'ceAlcas', draggable: !ceState.modo, keyboard: false,
    icon: _ceIcone('ce-m', angulo) })
  let antes = null, criar = false, base = null
  const redesenhar = () => ceState.camada.setLatLngs(ceState.polis.map(pp => pp.map(r => r.map(cc => [cc[1], cc[0]]))))
  mk.on('dragstart', () => {
    antes = _ceCopia(ceState.polis)
    criar = ceState.teclas.shift
    if (criar) { anel.splice(i + 1, 0, meio); ceState.sel = [pi, ri, i + 1]; return }
    // O lado de partida, num plano em metros: a normal dele é a direção do arrasto.
    const M = _ceM(meio), a = M.para(anel[i]), b = M.para(anel[j])
    const dx = b[0] - a[0], dy = b[1] - a[1], l = Math.hypot(dx, dy) || 1
    base = { M, a, b, n: [-dy / l, dx / l] }
    ceState.sel = null
  })
  mk.on('drag', ev => {
    if (criar) { const p = _ceImantar(ev.latlng, ev.originalEvent); anel[i + 1] = p; mk.setLatLng([p[1], p[0]]); redesenhar(); return }
    // O ímã vale aqui também: a linha do lado encosta na divisa do vizinho.
    const q = base.M.para(_ceImantar(ev.latlng, ev.originalEvent))
    const d = q[0] * base.n[0] + q[1] * base.n[1]
    anel[i] = base.M.de([base.a[0] + base.n[0] * d, base.a[1] + base.n[1] * d])
    anel[j] = base.M.de([base.b[0] + base.n[0] * d, base.b[1] + base.n[1] * d])
    const c = base.M.de([base.n[0] * d, base.n[1] * d])
    mk.setLatLng([c[1], c[0]])
    redesenhar()
  })
  mk.on('dragend', () => { _ceMarcaIma(null); _ceEmpilhar(antes) })
  mk.addTo(ceState.alcas)
}

/** O ímã: vértice do vizinho a até 12 px, senão a linha dele a até 9 px. Alt desliga. */
function _ceImantar(ll, ev) {
  const p = [ll.lng, ll.lat]
  if (ev?.altKey || ceState.teclas.alt) { _ceMarcaIma(null); return p }
  const m = mapaState.obj, cp = m.latLngToContainerPoint(ll)
  const grau = Math.abs(m.containerPointToLatLng([cp.x + CE_IMA_VERTICE_PX, cp.y]).lng - ll.lng)
  let melhor = null
  for (const v of ceState.vizinhos) {
    const [x0, y0, x1, y1] = v.caixa
    if (p[0] < x0 - grau || p[0] > x1 + grau || p[1] < y0 - grau || p[1] > y1 + grau) continue
    for (const c of v.anel) {
      const q = m.latLngToContainerPoint([c[1], c[0]]), d = Math.hypot(q.x - cp.x, q.y - cp.y)
      if (d <= CE_IMA_VERTICE_PX && (!melhor || melhor.tipo !== 'v' || d < melhor.d)) melhor = { d, c, tipo: 'v' }
    }
  }
  if (!melhor) {
    for (const v of ceState.vizinhos) {
      const [x0, y0, x1, y1] = v.caixa
      if (p[0] < x0 - grau || p[0] > x1 + grau || p[1] < y0 - grau || p[1] > y1 + grau) continue
      const pts = v.anel.map(c => { const q = m.latLngToContainerPoint([c[1], c[0]]); return [q.x, q.y] })
      const r = _cePertoNoAnel([cp.x, cp.y], pts)
      if (r.d <= CE_IMA_LADO_PX && (!melhor || r.d < melhor.d)) {
        const a = v.anel[r.k], b = v.anel[(r.k + 1) % v.anel.length]
        melhor = { d: r.d, c: [a[0] + (b[0] - a[0]) * r.t, a[1] + (b[1] - a[1]) * r.t], tipo: 'l' }
      }
    }
  }
  _ceMarcaIma(melhor ? melhor.c : null)
  return melhor ? [melhor.c[0], melhor.c[1]] : p
}

function _ceMarcaIma(c) {
  ceState.ima?.remove(); ceState.ima = null
  if (c) ceState.ima = L.circleMarker([c[1], c[0]], { pane: 'ceAlcas', radius: 9, color: '#16A34A', weight: 2, fill: false, interactive: false }).addTo(mapaState.obj)
}

// ── ações ───────────────────────────────────────────────────────

function _ceEmpilhar(antes) {
  ceState.undo.push(antes); ceState.redo = []; ceState.alterado = true
  _ceDesenhar(); _ceConferir()
}

function _ceClicouVertice(pi, ri, i) {
  if (ceState.modo === 'encostar') { _ceEscolherParaEncostar(pi, ri, i); return }
  ceState.sel = [pi, ri, i]
  _ceDesenharAlcas()
}

function _ceTeclas(ev) {
  if (!ceState.ativo) return
  const alvo = ev.target
  if (alvo instanceof Element && (alvo.matches('input, textarea, select') || alvo.isContentEditable)) return
  if (document.querySelector('.modal-bg.open')) return
  const k = ev.key.toLowerCase()
  if ((ev.ctrlKey || ev.metaKey) && k === 'z') { ev.preventDefault(); ev.stopPropagation(); desfazerPassoContorno(); return }
  if ((ev.ctrlKey || ev.metaKey) && k === 'y') { ev.preventDefault(); ev.stopPropagation(); refazerPassoContorno(); return }
  if (k === 'delete' || k === 'backspace') { ev.preventDefault(); ev.stopPropagation(); apagarVerticeDoContorno(); return }
  if (ceState.modo === 'previa' && k === 'enter') { ev.preventDefault(); ev.stopPropagation(); aplicarEncostar(); return }
  if (ceState.modo === 'previa' && k === 'tab') { ev.preventDefault(); ev.stopPropagation(); outroLadoEncostar(); return }
  if (k === 'escape') {
    ev.preventDefault(); ev.stopPropagation()
    if (ceState.modo) cancelarEncostar(); else { ceState.sel = null; _ceDesenharAlcas() }
  }
}

function apagarVerticeDoContorno() {
  const s = ceState.sel
  if (!s) { toast('Clique num vértice para selecioná-lo, e então Delete.', 'aviso'); return }
  const anel = ceState.polis[s[0]]?.[s[1]]
  if (!anel || anel.length <= 3) { toast('O contorno precisa de ao menos três vértices.', 'aviso'); return }
  const antes = _ceCopia(ceState.polis)
  anel.splice(s[2], 1)
  ceState.sel = null
  _ceEmpilhar(antes)
}

function desfazerPassoContorno() {
  if (!ceState.undo.length) return
  ceState.redo.push(_ceCopia(ceState.polis)); ceState.polis = ceState.undo.pop()
  ceState.sel = null; ceState.alterado = ceState.undo.length > 0
  _ceDesenhar(); _ceConferir()
}
function refazerPassoContorno() {
  if (!ceState.redo.length) return
  ceState.undo.push(_ceCopia(ceState.polis)); ceState.polis = ceState.redo.pop()
  ceState.sel = null; ceState.alterado = true
  _ceDesenhar(); _ceConferir()
}

/** Tira os vértices que não mudam a forma (0,5 m): lado curvo com dezenas deles fica editável. */
function simplificarContorno() {
  const antes = _ceCopia(ceState.polis)
  const o = ceState.polis[0][0][0], M = _ceM(o)
  const n0 = ceState.polis.flat(2).length
  ceState.polis = ceState.polis.map(p => p.map(r => _ceSimplificarAnel(r.map(M.para), CE_SIMPLIFICAR_M).map(M.de)))
  const n1 = ceState.polis.flat(2).length
  if (n1 === n0) { ceState.polis = antes; toast('Nada a simplificar: todos os vértices mudam a forma.', 'aviso'); return }
  toast(`${n0 - n1} vértice(s) a menos (${n0} → ${n1}). A conferência confirma que nenhum lote ficou de fora.`)
  _ceEmpilhar(antes)
}

/** "Encostar no vizinho": escolha dois vértices; o trecho entre eles segue a divisa do vizinho. */
/**
 * "Encostar no vizinho": escolha o vértice do começo e o do fim do trecho. O
 * trecho passa a seguir a divisa do bairro vizinho mais perto — primeiro como
 * PRÉVIA (verde tracejado): Enter aplica, Tab mostra o outro lado, Esc cancela.
 */
function encostarNoVizinho() {
  if (!ceState.vizinhos.length) { toast('Não há outro bairro com contorno para encostar.', 'aviso'); return }
  if (ceState.modo) { _ceLimparPrevia(); ceState.modo = null; ceState.encostarA = null }
  else { ceState.modo = 'encostar'; ceState.encostarA = null; ceState.sel = null }
  if (mapaState.obj.getZoom() < CE_ZOOM_ALCAS) toast('Aproxime o mapa até ver os vértices do contorno.', 'aviso')
  _ceDesenharAlcas()
}

function _ceEscolherParaEncostar(pi, ri, i) {
  if (ceState.modo === 'previa') return
  const a = ceState.encostarA
  if (!a) { ceState.encostarA = [pi, ri, i]; _ceDesenharAlcas(); return }
  if (a[0] !== pi || a[1] !== ri) { toast('Os dois vértices têm de ser do mesmo contorno.', 'aviso'); return }
  if (a[2] === i) { ceState.encostarA = null; _ceDesenharAlcas(); return }
  const r = _ceCandidatosEncostar(pi, ri, a[2], i)
  if (!r) return
  ceState.previa = { pi, ri, nome: r.nome, cands: r.cands, idx: 0 }
  ceState.modo = 'previa'
  _ceDesenharPrevia()
  _ceDesenharAlcas()
}

function _ceDesenharPrevia() {
  const pv = ceState.previa
  ceState.previaCamada?.remove()
  if (!pv) return
  const anel = pv.cands[pv.idx]
  ceState.previaCamada = L.polygon(anel.map(c => [c[1], c[0]]), { pane: 'ceContorno', interactive: false,
    color: '#16A34A', weight: 3, dashArray: '8 5', fillColor: '#16A34A', fillOpacity: .12 }).addTo(mapaState.obj)
}

function _ceLimparPrevia() { ceState.previaCamada?.remove(); ceState.previaCamada = null; ceState.previa = null }

function aplicarEncostar() {
  const pv = ceState.previa
  if (!pv) return
  const antes = _ceCopia(ceState.polis)
  ceState.polis[pv.pi][pv.ri] = pv.cands[pv.idx]
  _ceLimparPrevia(); ceState.modo = null; ceState.encostarA = null
  toast(`Trecho encostado na divisa de ${pv.nome}.`)
  _ceEmpilhar(antes)
}

function outroLadoEncostar() {
  const pv = ceState.previa
  if (!pv) return
  pv.idx = (pv.idx + 1) % pv.cands.length
  _ceDesenharPrevia(); _cePintarBarra()
}

function cancelarEncostar() { _ceLimparPrevia(); ceState.modo = null; ceState.encostarA = null; _ceDesenharAlcas() }

/**
 * Troca o trecho entre os vértices i e j pela divisa do vizinho mais perto.
 * Dos dois trechos do anel entre i e j, troca o que está do lado do vizinho
 * (o mais perto dele); dos dois caminhos pela divisa do vizinho, segue o mais curto.
 */
/**
 * As formas possíveis de encostar o trecho entre os vértices i e j na divisa
 * do vizinho mais perto, da mais provável para a menos: dos dois trechos do
 * nosso anel entre i e j, o mais curto (é o lado que encara o vizinho) com o
 * caminho mais curto pela divisa dele; depois as outras combinações — o "Outro
 * lado" da prévia. Conferido com os contornos reais de produção (19/10/2026).
 *
 * @returns {{nome:string, cands:Array<Array<[number,number]>>}|null}
 */
function _ceCandidatosEncostar(pi, ri, i, j) {
  const anel = ceState.polis[pi][ri], A = anel[i], B = anel[j]
  const M = _ceM(A), a = M.para(A), b = M.para(B)
  let viz = null
  for (const v of ceState.vizinhos) {
    const pts = v.anel.map(M.para)
    const ra = _cePertoNoAnel(a, pts), rb = _cePertoNoAnel(b, pts), d = Math.max(ra.d, rb.d)
    if (!viz || d < viz.d) viz = { d, pts, ra, rb, nome: v.nome }
  }
  if (!viz || viz.d > 150) { toast('Nenhum bairro vizinho a menos de 150 m desses dois vértices.', 'aviso'); return null }
  const n = viz.pts.length
  const frente = [viz.ra.q]
  for (let k = (viz.ra.k + 1) % n, g = 0; g < n; k = (k + 1) % n, g++) { if (k === (viz.rb.k + 1) % n) break; frente.push(viz.pts[k]) }
  frente.push(viz.rb.q)
  const tras = [viz.ra.q]
  for (let k = viz.ra.k, g = 0; g < n; k = (k - 1 + n) % n, g++) { if (k === viz.rb.k) break; tras.push(viz.pts[k]) }
  tras.push(viz.rb.q)
  const caminhos = _ceComprimento(frente) <= _ceComprimento(tras) ? [frente, tras] : [tras, frente]
  const trecho = (s0, e) => { const r = []; for (let k = s0; ; k = (k + 1) % anel.length) { r.push(k); if (k === e) break } return r }
  const t1 = trecho(i, j), t2 = trecho(j, i)
  const comp = t => _ceComprimento(t.map(k => M.para(anel[k])))
  // trocar i→j (fica j→i) ou trocar j→i (fica i→j); o trecho mais curto primeiro.
  const opcoes = comp(t1) <= comp(t2) ? ['ij', 'ji'] : ['ji', 'ij']
  const cands = []
  for (const cam of caminhos) for (const o of opcoes) {
    const nova = cam.map(M.de)
    const r = o === 'ij' ? [...nova, ...t2.slice(1, -1).map(k => anel[k])] : [...nova.slice().reverse(), ...t1.slice(1, -1).map(k => anel[k])]
    if (r.length >= 3) cands.push(r)
  }
  // A ordem: menor trecho + menor caminho, menor trecho + caminho longo vem por último.
  const ordem = cands.length === 4 ? [cands[0], cands[1], cands[2], cands[3]] : cands
  if (!ordem.length) { toast('O trecho escolhido não deixa contorno suficiente.', 'aviso'); return null }
  return { nome: viz.nome, cands: ordem }
}

// ── conferência e gravação ──────────────────────────────────────

function _ceConferir() {
  clearTimeout(ceState.timer)
  ceState.conf = null
  _cePintarBarra()
  ceState.timer = setTimeout(async () => {
    const pedido = ++ceState.pedido
    try {
      const r = await fetch('/api/bairros/contorno/conferir-edicao', { method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
        body: JSON.stringify({ bairro: ceState.bairro, geometry: _ceGeometria() }) })
      const d = await r.json()
      if (pedido !== ceState.pedido || !ceState.ativo) return
      ceState.conf = r.ok ? d : { pode: false, motivo: d.message || 'Não foi possível conferir.', lotes_fora: [], invasoes: [], quadras_fora: [] }
    } catch { if (pedido === ceState.pedido) ceState.conf = { pode: false, motivo: 'Sem resposta do servidor.', lotes_fora: [], invasoes: [], quadras_fora: [] } }
    _ceMostrarConferencia()
  }, 450)
}

function _ceMostrarConferencia() {
  const c = ceState.conf
  ceState.invasoes?.clearLayers()
  for (const i of c?.invasoes || []) {
    L.geoJSON(i.geometry, { pane: 'ceContorno', interactive: false, style: { color: '#DC2626', weight: 1.5, dashArray: '4 3', fillColor: '#DC2626', fillOpacity: .3 } }).addTo(ceState.invasoes)
  }
  _ceMarcarLotesFora((c?.lotes_fora || []).map(l => l.id))
  ceState.camada?.setStyle({ color: c && !c.pode ? '#DC2626' : '#2563EB' })
  _cePintarBarra()
}

/** Os lotes que ficariam de fora, em vermelho no mapa (os carregados). */
function _ceMarcarLotesFora(ids) {
  for (const id of ceState.lotesFora) { const cm = mapaState.porId.get(id); if (cm) cm.setStyle(estiloColorido(cm.feature)) }
  ceState.lotesFora = new Set(ids)
  for (const id of ids) mapaState.porId.get(id)?.setStyle({ color: '#DC2626', weight: 2.4, fillColor: '#DC2626', fillOpacity: .35 })
}

async function gravarContornoEditado() {
  if (!ceState.conf?.pode || ceState.gravando) return
  ceState.gravando = true; _cePintarBarra()
  try {
    const r = await fetch('/api/bairros/contorno/edicao', { method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: JSON.stringify({ bairro: ceState.bairro, geometry: _ceGeometria() }) })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'O servidor recusou o contorno.')
    toast(d.message)
    ceState.alterado = false
    if (typeof recarregarContornosDoMapa === 'function') await recarregarContornosDoMapa()
    sairDaEdicaoDoContorno(true)
  } catch (e) {
    toast(e.message, 'err')
  } finally { ceState.gravando = false; _cePintarBarra() }
}

/** Da lista: volta o contorno de antes da última edição. */
function desfazerEdicaoDoContorno(nome) {
  confirmarAcao({ titulo: 'Desfazer a edição do contorno', textoBtn: 'Desfazer',
    mensagem: `O contorno de ${nome} volta a ser o de antes da última edição (ou da última geração por cima dela).`,
    onConfirm: async () => {
      const r = await fetch('/api/bairros/contorno/desfazer-edicao', { method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
        body: JSON.stringify({ bairro: nome }) })
      const d = await r.json()
      toast(d.message, r.ok ? 'ok' : 'err')
      if (r.ok) { await carregarContornosDosBairros(); if (typeof abrirContornosDosBairros === 'function') abrirContornosDosBairros() }
    } })
}

// ── a barra ─────────────────────────────────────────────────────

const _CE_ICO = {
  simplificar: '<path d="M3 16l4-5 3 3 4-6 3 4 4-3"/><path d="M3 20h18" opacity=".45"/>',
  encostar: '<rect x="3" y="5" width="7" height="14" rx="1"/><rect x="15" y="5" width="6" height="14" rx="1"/><path d="M10 12h4M12.5 10.5 14 12l-1.5 1.5"/>',
  desfazer: '<path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3"/>',
  refazer: '<path d="M15 14l5-5-5-5M20 9H9a5 5 0 0 0 0 10h3"/>',
  ajuda: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5V14M12 17.2v.1"/>',
  fechar: '<path d="M6 6l12 12M18 6L6 18"/>',
}
const _ceIco = k => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${_CE_ICO[k]}</svg>`

/** A barra: compacta, uma linha. As dicas ficam no "?" e nos títulos dos botões. */
function _cePintarBarra() {
  const barra = document.getElementById('ce-barra')
  if (!barra) return
  if (!ceState.ativo) { barra.hidden = true; return }
  const c = ceState.conf, n = ceState.polis.flat(2).length
  const curto = !c ? null : !c.valido ? 'lados se cruzam'
    : c.lotes_fora?.length ? `${c.lotes_fora.length} lote(s) de fora`
      : c.quadras_fora?.length ? `${c.quadras_fora.length} quadra(s) de fora`
        : c.invasoes?.length ? `invade ${c.invasoes[0].bairro}` : null
  const estado = !c ? '<span class="ce-chip">conferindo…</span>'
    : c.pode ? '<span class="ce-chip ce-ok">ok</span>'
      : `<span class="ce-chip ce-er" title="${esc(c.motivo || '')}">${esc(curto || 'não fecha')}</span>`
      + (c.lotes_fora?.length ? ` <a href="#" class="ce-ver" onclick="event.preventDefault(); irAoLoteIsolado(${Number(c.lotes_fora[0].id)})">ver</a>` : '')
  const ajuda = 'Vértice (quadrado): arraste; clique e Delete apaga. Meio do lado (retângulo): arraste o lado inteiro na perpendicular; Shift + arrastar cria vértice. Ímã no vizinho; Alt desliga. Ctrl+Z / Ctrl+Y desfazem e refazem.'
  const b = (ico, rot, acao, extra = '') => `<button class="ce-ib" type="button" title="${rot}" aria-label="${rot}" onclick="${acao}" ${extra}>${_ceIco(ico)}</button>`
  barra.className = 'imp-barra ce-barra'
  barra.hidden = false
  if (ceState.modo === 'encostar' || ceState.modo === 'previa') {
    const pv = ceState.previa
    barra.innerHTML = pv
      ? `<b class="ce-tit">Encostar em ${esc(pv.nome)}</b><span class="ce-sub">prévia ${pv.idx + 1}/${pv.cands.length}</span>
         <button class="btn sm" type="button" onclick="outroLadoEncostar()" title="Tab">Outro lado</button>
         <button class="btn sm" type="button" onclick="cancelarEncostar()" title="Esc">Cancelar</button>
         <button class="btn sm primary" type="button" onclick="aplicarEncostar()" title="Enter">Aplicar</button>`
      : `<b class="ce-tit">Encostar no vizinho</b><span class="ce-sub">${ceState.encostarA ? 'agora o vértice do fim do trecho' : 'clique no vértice do começo do trecho'}</span>
         <button class="btn sm" type="button" onclick="cancelarEncostar()" title="Esc">Cancelar</button>`
    return
  }
  barra.innerHTML = `<b class="ce-tit" title="${esc(ceState.bairro)}">${esc(ceState.bairro)}</b><span class="ce-sub">${n} vért.</span>${estado}
    <span class="ce-sep"></span>
    ${b('simplificar', 'Simplificar: tira vértices que não mudam a forma (0,5 m)', 'simplificarContorno()')}
    ${b('encostar', 'Encostar no vizinho: o trecho entre dois vértices segue a divisa do bairro ao lado', 'encostarNoVizinho()')}
    ${b('desfazer', 'Desfazer (Ctrl+Z)', 'desfazerPassoContorno()', ceState.undo.length ? '' : 'disabled')}
    ${b('refazer', 'Refazer (Ctrl+Y)', 'refazerPassoContorno()', ceState.redo.length ? '' : 'disabled')}
    <span class="ce-ib ce-ajuda" title="${ajuda}" tabindex="0" aria-label="${ajuda}">${_ceIco('ajuda')}</span>
    <span class="ce-sep"></span>
    ${b('fechar', 'Cancelar e voltar à lista', 'sairDaEdicaoDoContorno()')}
    <button class="btn sm primary ce-gravar" type="button" onclick="gravarContornoEditado()" ${c?.pode && ceState.alterado && !ceState.gravando ? '' : 'disabled'}>${ceState.gravando ? 'Gravando…' : 'Gravar'}</button>`
}
