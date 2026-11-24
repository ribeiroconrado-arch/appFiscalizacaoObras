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
 * LOTES OU QUADRAS (24/11/2026): a barra tem a escolha. Em "Quadra inteira",
 * andam todos os lotes das quadras dos marcados (basta marcar um lote de
 * cada) — como o mover quadras do conversor GeoJSON. Os lotes da quadra que
 * não estão na tela são buscados antes (_feicoesDasQuadras).
 *
 * Só desloca: forma e área não mudam. Invadir um lote que não andou vira
 * aviso para confirmar, não recusa — é o passo do meio de um acerto (ver
 * App\Services\MoverLotes). Grava pelo /api/lotes/mover, com o modal de etapas.
 */

const MOV_SNAP_PX = 14
/** Teto de lotes num movimento (o mesmo do servidor, CadastroLoteController::validarMover). */
const MOV_MAX_LOTES = 150

const movLoteState = {
  ativo: false,
  /** @type {Object[]} as feições dos lotes que andam */ feicoes: [],
  /** @type {'lotes'|'quadras'} */ escopo: 'lotes',
  /** @type {Object[]} os lotes marcados na mesa */ marcados: [],
  /** @type {Object[]|null} todos os lotes das quadras dos marcados, quando já buscados */ daQuadra: null,
  buscandoQuadras: false,
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
  pedirFerramenta('moverLotes', () => {
    Object.assign(movLoteState, { marcados: feicoes, escopo: 'lotes', daQuadra: null, buscandoQuadras: false })
    _abrirMover(feicoes)
  }, { convive: ['curadoria'] })
}

/** bairro|quadra de um lote. */
const _movChaveQuadra = f => String(f.properties.bairro ?? '') + '|' + String(f.properties.quadra ?? '')

/** As quadras dos lotes marcados, como "Q 12, Q 13 (Bairro)". */
function _movNomeQuadras() {
  const qs = [...new Set(movLoteState.marcados.map(_movChaveQuadra))].map(k => k.split('|'))
  const bairros = [...new Set(qs.map(q => q[0]))]
  return qs.map(q => 'Q ' + q[1]).join(', ') + (bairros.length === 1 ? ' (' + bairros[0] + ')' : '')
}

/**
 * Todos os lotes das quadras dos marcados. A quadra pode passar da tela: os
 * blocos em volta dos lotes já conhecidos dela (300 m) são carregados, e de
 * novo enquanto aparecer lote novo da quadra (até 3 voltas).
 * @returns {Promise<{feicoes: Object[], partes: number, truncado: boolean}>}
 */
async function _feicoesDasQuadras() {
  const chaves = new Set(movLoteState.marcados.map(_movChaveQuadra))
  const pre = typeof impState !== 'undefined' ? impState.preCuradoria : null
  const daQuadra = () => [...state.lotes.values()].filter(f => chaves.has(_movChaveQuadra(f))
    && (!f.properties.situacao || f.properties.situacao === 'ativo')
    && (!pre || Number(f.properties.importacao_id) === pre.id))
  let achados = daQuadra(), antes = -1
  for (let volta = 0; volta < 3 && achados.length !== antes; volta++) {
    antes = achados.length
    let oeste = Infinity, sul = Infinity, leste = -Infinity, norte = -Infinity
    for (const f of achados) {
      const aneis = f.geometry.type === 'Polygon' ? [f.geometry.coordinates[0]] : f.geometry.coordinates.map(p => p[0])
      for (const anel of aneis) for (const [x, y] of anel) { oeste = Math.min(oeste, x); leste = Math.max(leste, x); sul = Math.min(sul, y); norte = Math.max(norte, y) }
    }
    const folga = 300 / 107000
    const blocos = blocosDoRetangulo({ oeste: oeste - folga, sul: sul - folga, leste: leste + folga, norte: norte + folga }, tamanhoDoBloco())
    await Promise.all(blocos.filter(k => !state.blocos.has(k)).map(k => state.pendentes.get(k) || carregarBloco(k, state.versaoLotes)))
    achados = daQuadra()
  }
  return {
    feicoes: achados.filter(f => f.geometry?.type === 'Polygon'),
    partes: achados.filter(f => f.geometry?.type !== 'Polygon').length,
    truncado: !!state.truncado,
  }
}

/** A escolha da barra: só os lotes marcados ou as quadras inteiras deles. @param {'lotes'|'quadras'} escopo */
async function escopoDoMover(escopo) {
  const s = movLoteState
  if (!s.ativo || s.travado || s.buscandoQuadras || s.escopo === escopo) return
  if (escopo === 'lotes') {
    s.escopo = 'lotes'
    _movTrocarFeicoes(s.marcados)
    return
  }
  if (!s.daQuadra) {
    s.buscandoQuadras = true
    _movBarra()
    let r
    try { r = await _feicoesDasQuadras() } catch { r = null }
    s.buscandoQuadras = false
    if (!s.ativo) return
    if (!r?.feicoes.length) { toast('Não foi possível juntar os lotes da quadra. Tente de novo.', 'err'); _movBarra(); return }
    if (r.feicoes.length > MOV_MAX_LOTES) {
      toast(`As quadras escolhidas têm ${r.feicoes.length} lotes; o mover leva até ${MOV_MAX_LOTES} de cada vez. Mova uma quadra por vez.`, 'err')
      _movBarra()
      return
    }
    if (r.partes) toast(`${r.partes} lote(s) da quadra têm partes separadas e ficam onde estão.`, 'aviso')
    if (r.truncado) toast('O mapa não trouxe todos os lotes desta região. Aproxime o mapa da quadra e tente de novo.', 'aviso')
    s.daQuadra = r.feicoes
  }
  s.escopo = 'quadras'
  _movTrocarFeicoes(s.daQuadra)
}

/** Troca os lotes que andam, sem perder o ponto de base já escolhido. */
function _movTrocarFeicoes(feicoes) {
  const s = movLoteState
  s.feicoes = feicoes
  s.dirs = _movDirecoes(feicoes)
  _movBarra()
  if (s.passo === 'destino' && s.ultimo) _movMove({ latlng: s.ultimo })
}

/** Direções de cada lado (e a perpendicular), para a trava F8. */
function _movDirecoes(feicoes) {
  return feicoes.flatMap(f => {
    const r = f.geometry.coordinates[0].map(([x, y]) => _movM(x, y))
    return r.slice(1).flatMap((p, i) => {
      const dx = p[0] - r[i][0], dy = p[1] - r[i][1], l = Math.hypot(dx, dy)
      return l < 0.5 ? [] : [[dx / l, dy / l], [-dy / l, dx / l]]
    })
  })
}

function _abrirMover(feicoes) {
  const m = mapaState.obj
  const [lon, lat] = feicoes[0].geometry.coordinates[0][0]
  Object.assign(movLoteState, { ativo: true, feicoes, passo: 'base', base: null, v: null, dir: null, ortho: false, travado: false, conferindo: false, gravando: false, plano: planoLocal(lat, lon) })
  movLoteState.dirs = _movDirecoes(feicoes)
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
  movLoteState.travado = movLoteState.conferindo = movLoteState.gravando = false
  m?.getContainer().classList.remove('mov-espera')
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
    el.innerHTML = `<b>Mover</b><span class="seg mov-escopo" role="group" aria-label="O que mover"><button type="button" data-esc="lotes" onclick="escopoDoMover('lotes')"></button><button type="button" data-esc="quadras" onclick="escopoDoMover('quadras')"></button></span><span id="mov-passo"></span>
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
  const s = movLoteState
  const nl = s.marcados.length
  const bLotes = el.querySelector('[data-esc="lotes"]'), bQuadras = el.querySelector('[data-esc="quadras"]')
  bLotes.textContent = nl === 1 ? 'Lote marcado' : `${nl} lotes marcados`
  bQuadras.textContent = s.buscandoQuadras ? 'Juntando a quadra…'
    : s.escopo === 'quadras' ? `Quadra inteira · ${s.feicoes.length} lotes` : 'Quadra inteira'
  bQuadras.title = 'Todos os lotes de ' + _movNomeQuadras()
  bLotes.classList.toggle('at', s.escopo === 'lotes')
  bQuadras.classList.toggle('at', s.escopo === 'quadras')
  bLotes.disabled = bQuadras.disabled = !!s.travado || s.buscandoQuadras
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

/**
 * TRAVADO depois do destino (10/11/2026): da conferência no servidor até a
 * resposta da confirmação, o mover não aceita mais cursor, clique nem tecla —
 * com vários lotes a prévia demora um pouco, e nesse meio tempo dava para
 * mexer o fantasma e clicar num segundo destino. Fechada a confirmação sem
 * mover, a trava sai e o destino pode ser escolhido de novo.
 */
function _movTravado() {
  const s = movLoteState
  if (!s.travado) return false
  if (s.gravando || document.getElementById('m-confirm')?.classList.contains('open') || s.conferindo) return true
  s.travado = false
  mapaState.obj?.getContainer().classList.remove('mov-espera')
  _movBarra()
  return false
}

function _movTravar(sim) {
  const s = movLoteState
  s.travado = sim
  mapaState.obj?.getContainer().classList.toggle('mov-espera', sim)
  const passo = document.getElementById('mov-passo')
  if (sim && passo) passo.textContent = 'Conferindo o movimento…'
  if (!sim) _movBarra()
}

function _movMove(ev) {
  const s = movLoteState
  if (!s.ativo || _movTravado()) return
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
  if (!s.ativo || _movTravado()) return
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
  // Travado: só o Esc (sair) vale, e só enquanto nada está sendo gravado.
  if (_movTravado()) {
    if (ev.key === 'Escape' && !movLoteState.gravando && !document.getElementById('m-confirm')?.classList.contains('open')) { ev.preventDefault(); sairMoverLotes() }
    return
  }
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
  const s = movLoteState
  if (s.travado) return
  const itens = _movItens(v), dist = fmtMed(Math.hypot(...v))
  s.conferindo = true
  _movTravar(true)
  let previa
  try { previa = await requisicaoCadastro('/api/lotes/mover/previa', 'POST', { lotes: itens }) } catch { previa = null }
  s.conferindo = false
  if (!previa) { _movTravar(false); return }
  if (previa.impedimento) { toast(previa.impedimento, 'err'); _movTravar(false); return }
  const n = itens.length
  const quadras = movLoteState.escopo === 'quadras'
  // A trava segue até a confirmação ser respondida (ver _movTravado).
  confirmarAcao({
    titulo: quadras ? 'Mover quadras' : 'Mover lotes',
    mensagem: (quadras ? `Desloca ${_movNomeQuadras()}, ${n} lote(s), ${dist} m, sem mudar forma nem área. Depois gere o contorno do bairro de novo, para as quadras e as ruas acompanharem.`
      : `Desloca ${n} lote(s) ${dist} m, sem mudar forma nem área.`)
      + (previa.avisos?.length ? ` Atenção: ${previa.avisos.join(' ')} Acerte depois com Editar lote.` : ''),
    textoBtn: 'Mover',
    onConfirm: () => _movGravar(itens),
  })
}

async function _movGravar(itens) {
  movLoteState.gravando = true
  const et = abrirEtapas(`Movendo ${itens.length} lote(s)`, ['Gravando os lotes', 'Atualizando o mapa'])
  et.vai(0)
  const d = await requisicaoCadastro('/api/lotes/mover', 'POST', { lotes: itens }).catch(() => null)
  movLoteState.gravando = false
  if (!d) { et.erro(0, 'Não foi possível mover os lotes. Confira a mensagem e tente de novo.'); _movTravar(false); return }
  et.ok(0, d.message.replace(/\.$/, ''))
  sairMoverLotes()
  try { et.vai(1); limparLotesDoMapa(); await carregarLotesVisiveis(); et.ok(1, 'Mapa atualizado') }
  catch { et.erro(1, 'Os lotes foram movidos, mas o mapa não atualizou. Recarregue a página.'); return }
  et.concluir(d.message)
}
