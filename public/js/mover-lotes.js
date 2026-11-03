/**
 * MOVER LOTES NO MAPA — como o MOVER do AutoCAD (01/11/2026).
 *
 * Com os lotes marcados na mesa de alteração cadastral:
 *   1. clique no PONTO DE BASE (encaixa em vértice e ponto médio de lote);
 *   2. os lotes acompanham o cursor, em linha tracejada;
 *   3. clique no DESTINO (com encaixe também) ou digite a MEDIDA e Enter —
 *      ela vale na direção em que o cursor está apontando;
 *   F8 trava a direção nos lados dos lotes marcados (frente, fundo e as
 *   perpendiculares); Esc sai.
 *
 * Só desloca: forma e área não mudam. Invadir um lote que não andou vira
 * aviso para confirmar, não recusa — é o passo do meio de um acerto (ver
 * App\Services\MoverLotes). Grava pelo /api/lotes/mover, com o modal de etapas.
 */

const MOV_SNAP_PX = 14

const movLoteState = {
  ativo: false,
  /** @type {Object[]} as feições dos lotes que andam */ feicoes: [],
  /** @type {'base'|'destino'} */ passo: 'base',
  /** @type {L.LatLng|null} */ base: null,
  /** O deslocamento do momento, em metros, e o último com direção (para a medida digitada). */
  v: null, dir: null,
  ortho: false,
  /** Direções dos lados dos lotes marcados (F8), em metros, unitárias. */
  dirs: [],
  plano: null,
  camada: null, marca: null, guia: null,
}

if (typeof FERRAMENTAS_MAPA !== 'undefined') {
  FERRAMENTAS_MAPA.moverLotes = {
    rotulo: 'o mover lotes',
    aberta: () => movLoteState.ativo,
    emCurso: () => movLoteState.base ? 'um movimento começado' : null,
    fechar: () => sairMoverLotes(),
  }
}

/** Abre o mover com os lotes dados (os marcados na mesa). @param {number[]} ids */
function moverLotesNoMapa(ids) {
  const feicoes = ids.map(id => mapaState.porId.get(id)?.feature).filter(f => f?.geometry?.type === 'Polygon')
  if (!feicoes.length) { toast('Marque no mapa os lotes que vão ser movidos.', 'err'); return }
  if (feicoes.length < ids.length) { toast('Só lotes com um contorno simples (sem partes separadas) podem ser movidos.', 'err'); return }
  pedirFerramenta('moverLotes', () => _abrirMover(feicoes), { convive: ['curadoria'] })
}

function _abrirMover(feicoes) {
  const m = mapaState.obj
  const [lon, lat] = feicoes[0].geometry.coordinates[0][0]
  Object.assign(movLoteState, { ativo: true, feicoes, passo: 'base', base: null, v: null, dir: null, ortho: false, plano: planoLocal(lat, lon) })
  // As direções de cada lado (e a perpendicular), para a trava F8.
  movLoteState.dirs = feicoes.flatMap(f => {
    const r = f.geometry.coordinates[0].map(([x, y]) => _movM(x, y))
    return r.slice(1).flatMap((p, i) => {
      const dx = p[0] - r[i][0], dy = p[1] - r[i][1], l = Math.hypot(dx, dy)
      return l < 0.5 ? [] : [[dx / l, dy / l], [-dy / l, dx / l]]
    })
  })
  if (!m.getPane('moverLotes')) { m.createPane('moverLotes').style.zIndex = 660; m.getPane('moverLotes').style.pointerEvents = 'none' }
  movLoteState.camada = L.layerGroup().addTo(m)
  m.getContainer().classList.add('mov-cursor')
  m.on('mousemove', _movMove).on('click', _movClique)
  document.addEventListener('keydown', _movTecla, true)
  _movBarra()
}

function sairMoverLotes() {
  const m = mapaState.obj
  if (!movLoteState.ativo) return
  movLoteState.ativo = false
  movLoteState.camada?.remove()
  movLoteState.camada = movLoteState.marca = movLoteState.guia = null
  m?.getContainer().classList.remove('mov-cursor')
  m?.off('mousemove', _movMove).off('click', _movClique)
  document.removeEventListener('keydown', _movTecla, true)
  document.getElementById('mov-barra')?.remove()
}

// ── METROS ↔ GRAUS (plano local, no primeiro vértice) ───────────

function _movM(lon, lat) { const p = movLoteState.plano; return [(lon - p.lonRef) * p.porGrauLon, (lat - p.latRef) * p.porGrauLat] }
function _movLL(x, y) { const p = movLoteState.plano; return [p.lonRef + x / p.porGrauLon, p.latRef + y / p.porGrauLat] }

// ── A BARRA ─────────────────────────────────────────────────────

function _movBarra() {
  let el = document.getElementById('mov-barra')
  if (!el) {
    el = document.createElement('div')
    el.id = 'mov-barra'
    el.className = 'mov-barra'
    el.innerHTML = `<b>Mover ${movLoteState.feicoes.length} lote(s)</b><span id="mov-passo"></span>
      <label id="mov-medida" hidden>Medida <input id="mov-dist" inputmode="decimal" autocomplete="off" placeholder="0,10"> m</label>
      <span id="mov-ortho" class="mov-ortho"></span>
      <button type="button" class="btn sm" onclick="sairMoverLotes()">Cancelar</button>`
    mapaState.obj.getContainer().parentElement.appendChild(el)
    const inp = el.querySelector('#mov-dist')
    inp.addEventListener('keydown', ev => {
      ev.stopPropagation()
      if (ev.key === 'Enter') { ev.preventDefault(); _movMedidaDigitada() }
      if (ev.key === 'Escape') { ev.preventDefault(); sairMoverLotes() }
      if (ev.key === 'F8') { ev.preventDefault(); _movOrtho() }
    })
  }
  const destino = movLoteState.passo === 'destino'
  el.querySelector('#mov-passo').textContent = destino
    ? 'Clique no destino (encaixa em vértice e ponto médio) ou digite a medida e Enter.'
    : 'Clique no ponto de base — encaixa em vértice e ponto médio dos lotes.'
  el.querySelector('#mov-medida').hidden = !destino
  el.querySelector('#mov-ortho').textContent = `F8 ${movLoteState.ortho ? 'trava ligada' : 'trava desligada'}`
  el.querySelector('#mov-ortho').classList.toggle('lig', movLoteState.ortho)
}

function _movOrtho() { movLoteState.ortho = !movLoteState.ortho; _movBarra(); if (movLoteState.ultimo) _movMove({ latlng: movLoteState.ultimo }) }

// ── ENCAIXE ─────────────────────────────────────────────────────

/** Vértice ou ponto médio de lote a menos de MOV_SNAP_PX do cursor. @param {L.LatLng} ll */
function _movEncaixe(ll) {
  const m = mapaState.obj, c = m.latLngToContainerPoint(ll)
  let melhor = null, menor = MOV_SNAP_PX
  const ver = (q, tipo) => { const p = m.latLngToContainerPoint(q), d = Math.hypot(p.x - c.x, p.y - c.y); if (d < menor) { menor = d; melhor = { ll: q, tipo } } }
  const vista = m.getBounds().pad(0.1)
  for (const camada of mapaState.camadas) {
    if (!camada.getLatLngs || !vista.intersects(camada.getBounds())) continue
    for (const anel of _aneisDeCamada(camada.getLatLngs())) {
      for (let i = 0; i < anel.length; i++) {
        const a = anel[i], b = anel[(i + 1) % anel.length]
        ver(a, 'Extremidade')
        ver(L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2), 'Ponto médio')
      }
    }
  }
  return melhor
}

// ── MOUSE E TECLADO ─────────────────────────────────────────────

function _movMove(ev) {
  const s = movLoteState
  if (!s.ativo) return
  s.ultimo = ev.latlng
  const enc = _movEncaixe(ev.latlng)
  let alvo = enc?.ll || ev.latlng
  if (!s.marca) s.marca = L.circleMarker(alvo, { pane: 'moverLotes', radius: 8, color: '#16a34a', weight: 2, fillOpacity: 0, interactive: false }).addTo(s.camada)
  s.marca.setLatLng(alvo).setStyle({ opacity: enc ? 1 : 0 })
  if (enc) s.marca.bindTooltip(enc.tipo, { permanent: true, direction: 'top', offset: [0, -8], className: 'mov-dica' }).openTooltip()
  else s.marca.unbindTooltip()
  if (s.passo !== 'destino') return

  const b = _movM(s.base.lng, s.base.lat)
  let p = _movM(alvo.lng, alvo.lat), v = [p[0] - b[0], p[1] - b[1]]
  // Trava F8: a direção do lado mais próximo; o encaixe vence a trava.
  if (s.ortho && !enc && s.dirs.length) {
    const l = Math.hypot(...v)
    if (l > 1e-9) {
      let melhor = s.dirs[0], prox = -Infinity
      for (const d of s.dirs) { const c = (v[0] * d[0] + v[1] * d[1]) / l; if (c > prox) { prox = c; melhor = d } }
      const t = v[0] * melhor[0] + v[1] * melhor[1]
      v = [melhor[0] * t, melhor[1] * t]
      const q = _movLL(b[0] + v[0], b[1] + v[1]); alvo = L.latLng(q[1], q[0])
    }
  }
  s.v = v
  if (Math.hypot(...v) > 1e-6) s.dir = [v[0] / Math.hypot(...v), v[1] / Math.hypot(...v)]
  _movFantasma(v, alvo)
}

/** Os lotes deslocados por `v` (metros), tracejados, e a linha da base ao cursor. */
function _movFantasma(v, alvo) {
  const s = movLoteState
  s.camada.eachLayer(l => { if (l !== s.marca) s.camada.removeLayer(l) })
  for (const f of s.feicoes) {
    const r = f.geometry.coordinates[0].map(([x, y]) => { const p = _movM(x, y), q = _movLL(p[0] + v[0], p[1] + v[1]); return [q[1], q[0]] })
    L.polygon(r, { pane: 'moverLotes', color: '#7c3aed', weight: 2, dashArray: '6 4', fill: true, fillOpacity: 0.08, interactive: false }).addTo(s.camada)
  }
  L.polyline([s.base, alvo], { pane: 'moverLotes', color: '#7c3aed', weight: 1.5, dashArray: '3 4', interactive: false })
    .bindTooltip(`${fmtMed(Math.hypot(...v))} m`, { permanent: true, direction: 'center', className: 'mov-dica' }).addTo(s.camada)
}

function _movClique(ev) {
  const s = movLoteState
  if (!s.ativo) return
  const enc = _movEncaixe(ev.latlng)
  if (s.passo === 'base') {
    s.base = enc?.ll || ev.latlng
    s.passo = 'destino'
    _movBarra()
    document.getElementById('mov-dist')?.focus()
    return
  }
  _movMove(ev)
  if (s.v && Math.hypot(...s.v) > 1e-6) _movConcluir(s.v)
}

function _movTecla(ev) {
  if (!movLoteState.ativo) return
  if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); sairMoverLotes(); return }
  if (ev.key === 'F8') { ev.preventDefault(); ev.stopPropagation(); _movOrtho(); return }
  // Começou a digitar a medida com o foco no mapa: vai para o campo.
  if (movLoteState.passo === 'destino' && /^[0-9.,]$/.test(ev.key) && !ev.target.closest?.('input,textarea')) {
    const inp = document.getElementById('mov-dist')
    if (inp) { ev.preventDefault(); inp.value = ev.key; inp.focus() }
  }
}

function _movMedidaDigitada() {
  const s = movLoteState, n = lerNumeroBR(document.getElementById('mov-dist').value)
  if (!Number.isFinite(n) || n <= 0) { toast('Digite a medida, em metros (ex.: 0,10).', 'err'); return }
  if (!s.dir) { toast('Aponte o cursor na direção do movimento antes de digitar a medida.', 'err'); return }
  _movConcluir([s.dir[0] * n, s.dir[1] * n])
}

// ── CONFERIR E GRAVAR ───────────────────────────────────────────

/** Os lotes deslocados por `v` (metros), em GeoJSON. @param {[number,number]} v */
function _movItens(v) {
  return movLoteState.feicoes.map(f => {
    const c = f.geometry.coordinates[0].map(([x, y]) => { const p = _movM(x, y); return _movLL(p[0] + v[0], p[1] + v[1]).map(n => Number(n.toFixed(11))) })
    c[c.length - 1] = [...c[0]]
    return { id: f.properties.id, geometry: { type: 'Polygon', coordinates: [c] } }
  })
}

async function _movConcluir(v) {
  const itens = _movItens(v), dist = fmtMed(Math.hypot(...v))
  let previa
  try { previa = await requisicaoCadastro('/api/lotes/mover/previa', 'POST', { lotes: itens }) } catch { return }
  if (!previa) return
  if (previa.impedimento) { toast(previa.impedimento, 'err'); return }
  const n = itens.length
  confirmarAcao({
    titulo: 'Mover lotes',
    mensagem: `Desloca ${n} lote(s) ${dist} m, sem mudar forma nem área.`
      + (previa.avisos?.length ? ` Atenção: ${previa.avisos.join(' ')} Acerte depois com Editar lote.` : ''),
    textoBtn: 'Mover',
    onConfirm: () => _movGravar(itens),
  })
}

async function _movGravar(itens) {
  const et = abrirEtapas(`Movendo ${itens.length} lote(s)`, ['Gravando os lotes', 'Atualizando o mapa'])
  et.vai(0)
  const d = await requisicaoCadastro('/api/lotes/mover', 'POST', { lotes: itens }).catch(() => null)
  if (!d) { et.erro(0, 'Não foi possível mover os lotes. Confira a mensagem e tente de novo.'); return }
  et.ok(0, d.message.replace(/\.$/, ''))
  sairMoverLotes()
  try { et.vai(1); limparLotesDoMapa(); await carregarLotesVisiveis(); et.ok(1, 'Mapa atualizado') }
  catch { et.erro(1, 'Os lotes foram movidos, mas o mapa não atualizou. Recarregue a página.'); return }
  await et.fechar()
  toast(d.message)
}
