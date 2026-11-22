/**
 * QUADRAS POR DECRETO (19/11/2026) — que ato criou cada quadra, e quais a
 * alteraram.
 *
 * A regra dele: toda quadra é da APROVAÇÃO do loteamento até um decreto
 * posterior (ampliação, etapa) marcá-la como sua. Loteamento de um decreto só
 * já está pronto. A ferramenta só marca as exceções; clicar de novo numa
 * quadra marcada a devolve à aprovação. A aba "Alterou" marca as quadras de
 * regularização, retificação e alteração, sem trocar quem as criou.
 *
 * O lote não guarda nada: o decreto é da quadra. Saiu do balão do lote em
 * 21/11/2026, a pedido dele; fica no balão da quadra (etapas-loteamentos.js).
 * Servidor: App\Cadastro\DecretosDasQuadras.
 */

const qdState = {
  ativo: false,
  /** @type {?string} nome do desenho */ bairro: null,
  /** resposta do servidor: padrao, atos, areas */ dados: null,
  /** @type {Object<string, number>} quadra => ato */ criou: {},
  /** @type {Object<string, number[]>} quadra => atos */ alterou: {},
  /** @type {?number} ato escolhido na lista */ sel: null,
  /** @type {'criou'|'alterou'} */ modo: 'criou',
  /** @type {L.GeoJSON|null} */ camada: null,
  mudou: false,
  gravando: false,
}

/** As cores dos atos, na ordem da lista. A primeira é a da aprovação (padrão). */
const COR_DOS_ATOS = ['#2563EB', '#0F766E', '#C2410C', '#7C3AED', '#BE185D', '#A16207', '#4D7C0F', '#0E7490']

FERRAMENTAS_MAPA.decretos = {
  rotulo: 'quadras por decreto',
  aberta: () => qdState.ativo,
  emCurso: () => (qdState.mudou ? 'marcações de quadra não gravadas' : null),
  fechar: () => fecharQuadrasPorDecreto(true),
}

const _qdNorm = q => String(q ?? '').trim().replace(/^0+(?=\d)/, '')

/* ── Leitura (ferramenta) ───────────────────────────── */

/** Pedido por bairro, guardado: o balão não pergunta duas vezes. */
const _decretosDoBairro = new Map()

/** @param {string} bairro @param {boolean} [comAreas] */
function pedirDecretosDoBairro(bairro, comAreas = false) {
  const chave = bairro + (comAreas ? '|areas' : '')
  if (comAreas) _decretosDoBairro.delete(chave)
  if (!_decretosDoBairro.has(chave)) {
    _decretosDoBairro.set(chave, fetch('/api/bairros/decretos-das-quadras?bairro=' + encodeURIComponent(bairro)
        + (comAreas ? '&areas=1' : ''), { headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : null))
      .catch(() => null)
      .then(d => { if (!d) _decretosDoBairro.delete(chave); return d }))
  }
  return _decretosDoBairro.get(chave)
}

/**
 * O decreto de uma quadra: quem criou (marcado ou o padrão), se é presumido
 * (padrão num bairro com mais de um decreto que cria quadra) e quem alterou.
 * @param {Object} d resposta do servidor @param {string} quadra
 */
function decretoDaQuadra(d, quadra) {
  const q = _qdNorm(quadra)
  const porId = new Map((d?.atos || []).map(a => [a.id, a]))
  const marcado = d?.criou?.[q]
  const criou = porId.get(marcado ?? d?.padrao) || null
  const criam = (d?.atos || []).filter(a => a.papel === 'criou').length
  return {
    criou,
    presumido: !!criou && marcado == null && criam > 1,
    alterou: (d?.alterou?.[q] || []).map(id => porId.get(id)).filter(Boolean),
  }
}

/* ── A ferramenta ───────────────────────────────────────────────────── */

/** O botão do balão do bairro: só para a curadoria, e só quando há o que marcar. */
function htmlQuadrasPorDecretoNoBalao(p) {
  if (!(window.USUARIO_CURADOR || window.USUARIO_ADMIN) || !p?.nome) return ''
  const marca = (p.outros_atos || []).some(a => ['ampliacao', 'etapa', 'aprovacao', 'regularizacao', 'retificacao', 'alteracao'].includes(a.tipo))
  if (!marca) return ''
  return `<button type="button" class="btn sm balao-btn" data-bairro="${esc(p.nome)}"
    onclick="abrirQuadrasPorDecreto(this.dataset.bairro)">Quadras por decreto</button>`
}

/** @param {string} bairro nome do desenho */
function abrirQuadrasPorDecreto(bairro) {
  pedirFerramenta('decretos', async () => {
    const mapa = mapaState.obj
    mapa.closePopup()
    Object.assign(qdState, { ativo: true, bairro, dados: null, criou: {}, alterou: {}, sel: null, modo: 'criou', mudou: false })
    if (!mapa.getPane('decretosQuadras')) mapa.createPane('decretosQuadras').style.zIndex = 640
    // Fechada, a camada some da frente: a tela de desenho vazia engoliria o clique (ver ruas-manuais.js).
    mapa.getPane('decretosQuadras').style.display = ''
    _pintarPainelDecretos()
    try {
      const [d, q] = await Promise.all([
        pedirDecretosDoBairro(bairro, true),
        fetch('/api/mapa/quadras?bairro=' + encodeURIComponent(bairro), { headers: { Accept: 'application/json' } }).then(r => r.json()),
      ])
      if (!qdState.ativo || qdState.bairro !== bairro) return
      if (!d) throw new Error('sem resposta')
      qdState.dados = d
      qdState.criou = { ...d.criou }
      qdState.alterou = Object.fromEntries(Object.entries(d.alterou || {}).map(([k, v]) => [k, [...v]]))
      qdState.sel = _atosDoModo()[0]?.id ?? null
      _desenharQuadrasDecretos(q)
      _pintarPainelDecretos()
    } catch (e) {
      toast('Não foi possível ler os decretos das quadras.', 'erro')
      fecharQuadrasPorDecreto(true)
    }
  })
}

/**
 * Fecha. Com marcação não gravada, pergunta — a não ser que quem fecha seja
 * outra ferramenta, que já perguntou (pedirFerramenta).
 * @param {boolean} [semPerguntar]
 */
function fecharQuadrasPorDecreto(semPerguntar = false) {
  if (!semPerguntar && qdState.mudou) {
    confirmarAcao({
      titulo: 'Sair sem gravar?',
      mensagem: 'As quadras marcadas desde a última gravação se perdem.',
      textoBtn: 'Sair sem gravar', perigo: true,
      onConfirm: () => fecharQuadrasPorDecreto(true),
    })
    return
  }
  qdState.ativo = false
  qdState.mudou = false
  qdState.camada?.remove()
  qdState.camada = null
  const pane = mapaState.obj?.getPane('decretosQuadras')
  if (pane) pane.style.display = 'none'
  const painel = document.getElementById('qd-painel')
  if (painel) painel.hidden = true
}

/** Os atos da aba atual: os que criam quadra (aprovação primeiro) ou os que alteram. */
function _atosDoModo() {
  const atos = (qdState.dados?.atos || []).filter(a => a.papel === qdState.modo)
  return qdState.modo === 'criou' ? atos.filter(a => a.id !== qdState.dados.padrao) : atos
}

function _corDoAto(id) {
  const lista = (qdState.dados?.atos || []).filter(a => a.papel === 'criou')
  const i = id === qdState.dados?.padrao ? 0 : lista.filter(a => a.id !== qdState.dados?.padrao).findIndex(a => a.id === id) + 1
  if (i > 0 || id === qdState.dados?.padrao) return COR_DOS_ATOS[i % COR_DOS_ATOS.length]
  const alt = (qdState.dados?.atos || []).filter(a => a.papel === 'alterou').findIndex(a => a.id === id)
  return COR_DOS_ATOS[(alt + 1) % COR_DOS_ATOS.length]
}

/** As quadras desenhadas do bairro e a área de cada uma. */
function _quadrasDoDesenho() {
  return Object.entries(qdState.dados?.areas || {}).map(([n, a]) => [_qdNorm(n), Number(a) || 0])
}

/** Quantas quadras e quanta área cada ato tem agora (com o padrão calculado). */
function _contasDosDecretos() {
  const contas = new Map()
  const soma = (id, a) => { const c = contas.get(id) || { n: 0, area: 0 }; c.n++; c.area += a; contas.set(id, c) }
  for (const [q, a] of _quadrasDoDesenho()) {
    soma(qdState.criou[q] ?? qdState.dados?.padrao, a)
    for (const id of qdState.alterou[q] || []) soma(id, a)
  }
  return contas
}

function _pintarPainelDecretos() {
  const painel = document.getElementById('qd-painel')
  if (!painel) return
  const d = qdState.dados
  const topo = `<div class="qd-topo"><div><b>Quadras por decreto</b><small>${esc(qdState.bairro || '')}</small></div>
    <button class="btn sm imp-barra-x" title="Fechar" onclick="fecharQuadrasPorDecreto()">&#10005;</button></div>`
  if (!d) { painel.innerHTML = topo + '<div class="qd-vazio">Carregando…</div>'; painel.hidden = false; return }

  const contas = _contasDosDecretos()
  const padrao = d.atos.find(a => a.id === d.padrao)
  const linha = (a, ehPadrao) => {
    const c = contas.get(a.id) || { n: 0, area: 0 }
    let conf = ''
    if (a.area_m2) {
      const dif = c.area - a.area_m2
      conf = Math.abs(dif) <= a.area_m2 * 0.01
        ? '<span class="qd-ok">≈ confere</span>'
        : `<span class="qd-dif">${dif < 0 ? 'faltam' : 'sobram'} ${fmtBR(Math.abs(dif))} m²</span>`
    }
    return `<button type="button" class="qd-ato${ehPadrao ? ' padrao' : ''}${qdState.sel === a.id && !ehPadrao ? ' sel' : ''}"
        ${ehPadrao ? 'disabled' : `onclick="escolherDecretoDaQuadra(${a.id})"`}>
      <i style="background:${_corDoAto(a.id)}"></i>
      <span class="qd-ato-txt"><b>${esc(a.ato)}</b>
        <span class="badge bd-in">${esc(a.rotulo)}</span>${ehPadrao ? '<span class="badge bd-in">padrão</span>' : ''}
        <small>${c.n} quadra${c.n === 1 ? '' : 's'}${ehPadrao ? ' (as não marcadas)' : ''} · ${fmtBR(c.area)}${a.area_m2 ? ' de ' + fmtBR(a.area_m2) : ''} m²</small>
        ${conf ? `<small>${conf}</small>` : ''}</span>
    </button>`
  }

  const criam = d.atos.filter(a => a.papel === 'criou')
  const alteram = d.atos.filter(a => a.papel === 'alterou')
  let corpo
  if (qdState.modo === 'criou') {
    corpo = (padrao ? linha(padrao, true) : '<div class="qd-vazio">O bairro não tem ato de aprovação em Parâmetros › Bairros.</div>')
      + _atosDoModo().map(a => linha(a, false)).join('')
    corpo += `<p class="qd-dica">${criam.length <= 1
      ? 'Decreto único: todas as quadras já são dele. Nada a marcar.'
      : 'Escolha uma ampliação e clique nas quadras dela. Clicar de novo devolve a quadra à aprovação.'}</p>`
  } else {
    corpo = alteram.length
      ? alteram.map(a => linha(a, false)).join('') + '<p class="qd-dica">Clique nas quadras que o ato regularizou, retificou ou alterou. Quem criou a quadra não muda.</p>'
      : '<p class="qd-dica">O bairro não tem regularização, retificação nem alteração. Elas entram em Parâmetros › Bairros.</p>'
  }
  painel.innerHTML = topo + `
    <div class="qd-abas seg">
      <button type="button" class="${qdState.modo === 'criou' ? 'on' : ''}" onclick="modoDecretosDaQuadra('criou')">Criou a quadra</button>
      <button type="button" class="${qdState.modo === 'alterou' ? 'on' : ''}" onclick="modoDecretosDaQuadra('alterou')">Alterou${alteram.length ? ` (${alteram.length})` : ''}</button>
    </div>
    <div class="qd-lista">${corpo}</div>
    <div class="qd-acoes">
      <button class="btn sm" onclick="fecharQuadrasPorDecreto()">Fechar</button>
      <button class="btn sm primary" onclick="gravarQuadrasPorDecreto()" ${qdState.mudou && !qdState.gravando ? '' : 'disabled'}>${qdState.gravando ? 'Gravando…' : 'Gravar'}</button>
    </div>`
  painel.hidden = false
}

function escolherDecretoDaQuadra(id) { qdState.sel = id; _pintarPainelDecretos(); _restilizarQuadrasDecretos() }

function modoDecretosDaQuadra(modo) {
  qdState.modo = modo
  qdState.sel = _atosDoModo()[0]?.id ?? null
  _pintarPainelDecretos()
  _restilizarQuadrasDecretos()
}

/** @param {Object} fc FeatureCollection das quadras do bairro */
function _desenharQuadrasDecretos(fc) {
  qdState.camada?.remove()
  qdState.camada = L.geoJSON({ type: 'FeatureCollection', features: fc.features || [] }, {
    pane: 'decretosQuadras',
    style: f => _estiloQuadraDecreto(_qdNorm(f.properties.numero)),
    onEachFeature: (f, l) => {
      const q = _qdNorm(f.properties.numero)
      l.on('click', e => { L.DomEvent.stopPropagation(e); _cliqueNaQuadraDecreto(q) })
      l.bindTooltip(() => _dicaDaQuadra(q), { sticky: true, direction: 'top' })
    },
  }).addTo(mapaState.obj)
}

function _estiloQuadraDecreto(q) {
  if (qdState.modo === 'alterou') {
    const marcada = qdState.sel != null && (qdState.alterou[q] || []).includes(qdState.sel)
    const outra = !marcada && (qdState.alterou[q] || []).length > 0
    const cor = marcada ? _corDoAto(qdState.sel) : '#6B7280'
    return { color: cor, weight: marcada ? 2.5 : 1, fillColor: cor, fillOpacity: marcada ? 0.5 : outra ? 0.25 : 0.08, dashArray: marcada ? null : '4 3' }
  }
  const marcada = qdState.criou[q] != null
  const cor = _corDoAto(qdState.criou[q] ?? qdState.dados?.padrao)
  return { color: cor, weight: marcada ? 2.5 : 1.2, fillColor: cor, fillOpacity: marcada ? 0.5 : 0.22, dashArray: marcada ? null : '4 3' }
}

function _restilizarQuadrasDecretos() {
  qdState.camada?.eachLayer(l => l.setStyle(_estiloQuadraDecreto(_qdNorm(l.feature.properties.numero))))
}

function _dicaDaQuadra(q) {
  const { criou, presumido, alterou } = decretoDaQuadra({ ...qdState.dados, criou: qdState.criou, alterou: qdState.alterou }, q)
  return `<b>Quadra ${esc(q)}</b><br>${criou ? esc(criou.ato) : 'sem decreto'}${presumido ? ' · padrão' : ''}`
    + alterou.map(a => `<br>alterada: ${esc(a.ato)}`).join('')
}

function _cliqueNaQuadraDecreto(q) {
  if (qdState.sel == null) {
    toast(qdState.modo === 'criou' ? 'Escolha na lista a ampliação ou etapa dona da quadra.' : 'Escolha na lista o ato que alterou a quadra.', 'aviso')
    return
  }
  if (qdState.modo === 'criou') {
    if (qdState.criou[q] === qdState.sel) delete qdState.criou[q]
    else qdState.criou[q] = qdState.sel
  } else {
    const l = qdState.alterou[q] || []
    qdState.alterou[q] = l.includes(qdState.sel) ? l.filter(id => id !== qdState.sel) : [...l, qdState.sel]
    if (!qdState.alterou[q].length) delete qdState.alterou[q]
  }
  qdState.mudou = true
  _restilizarQuadrasDecretos()
  _pintarPainelDecretos()
}

async function gravarQuadrasPorDecreto() {
  if (!qdState.mudou || qdState.gravando) return
  qdState.gravando = true
  _pintarPainelDecretos()
  try {
    const r = await fetch('/api/bairros/decretos-das-quadras', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: JSON.stringify({ bairro: qdState.bairro, criou: qdState.criou, alterou: qdState.alterou }),
    })
    const d = await r.json().catch(() => ({}))
    if (!r.ok) throw new Error(d.message || 'Não foi possível gravar.')
    qdState.dados = { ...qdState.dados, ...d }
    qdState.criou = { ...d.criou }
    qdState.alterou = Object.fromEntries(Object.entries(d.alterou || {}).map(([k, v]) => [k, [...v]]))
    qdState.mudou = false
    // O balão dos lotes passa a ler o que acabou de ser gravado.
    _decretosDoBairro.delete(qdState.bairro)
    if (typeof recarregarEtapas === 'function') recarregarEtapas()
    toast('Quadras dos decretos gravadas.')
  } catch (e) {
    toast(e.message, 'erro')
  } finally {
    qdState.gravando = false
    _pintarPainelDecretos()
    _restilizarQuadrasDecretos()
  }
}
