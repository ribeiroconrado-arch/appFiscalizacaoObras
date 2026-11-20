/**
 * ETAPAS DOS LOTEAMENTOS (20/11/2026) — camada do mapa: cada quadra pintada
 * pelo decreto que a criou (original, ampliações, etapas).
 *
 * Lê o que "Quadras por decreto" marcou (decretos-quadras.js): quadra sem
 * marcação é da aprovação e aparece tracejada e mais clara, para se ver o que
 * falta conferir. Bairro de decreto único não entra: não há etapa a separar.
 * O selo "R" marca a quadra alterada depois (regularização, retificação,
 * alteração) e tem chave própria no painel.
 *
 * A camada não recebe clique (como o contorno do bairro): o clique no mapa
 * procura a quadra sob o cursor (cliqueNaEtapa, chamado por _cliqueNoBairro).
 * Assim ela não rouba o clique dos lotes nem das ferramentas.
 * Servidor: GET /api/mapa/etapas (DecretosDasQuadras::etapasDaCidade).
 */

const etState = {
  /** resposta do servidor: bairros{nome:{padrao,atos}}, features */ dados: null,
  /** @type {?Promise} */ pedido: null,
  /** @type {L.GeoJSON|null} */ camada: null,
  /** @type {L.LayerGroup|null} */ selos: null,
  /** @type {?string} bairro da legenda */ bairro: null,
  /** @type {?number} etapa destacada na legenda */ foco: null,
}

registrarCamada({
  id: 'etapas', grupo: 'Bairros', rotulo: 'Etapas dos loteamentos', padrao: false,
  aoMudar: ligada => { ligada ? carregarEtapasDoMapa() : _apagarEtapas() },
})
registrarCamada({
  id: 'etapas-alteradas', grupo: 'Bairros', rotulo: 'Etapas: marcar quadras alteradas',
  disponivel: () => camadaLigada('etapas'),
  aoMudar: () => _desenharSelos(),
})

/** Pane próprio, sem clique, logo abaixo das quadras do nível dos bairros. */
function _etPane(mapa, nome, z) {
  if (!mapa.getPane(nome)) {
    const p = mapa.createPane(nome)
    p.style.zIndex = z
    p.style.pointerEvents = 'none'
  }
  return nome
}

async function carregarEtapasDoMapa() {
  const mapa = mapaState?.obj
  if (!mapa || !camadaLigada('etapas')) return
  if (!etState.dados) {
    etState.pedido ??= fetch('/api/mapa/etapas', { headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : null)).catch(() => null)
    const d = await etState.pedido
    etState.pedido = null
    if (!d) { toast('Não foi possível ler as etapas dos loteamentos.', 'erro'); return }
    etState.dados = d
  }
  if (!camadaLigada('etapas')) return
  etState.camada?.remove()
  etState.camada = L.geoJSON(etState.dados, {
    pane: _etPane(mapa, 'etapas', 438), interactive: false,
    style: f => _estiloEtapa(f.properties),
  }).addTo(mapa)
  _desenharSelos()
  _legendaDasEtapas()
  if (!etState.dados.features.length) toast('Nenhum bairro tem ampliação ou etapa cadastrada ainda.', 'aviso')
}

/** Depois de gravar em "Quadras por decreto": a camada lê de novo, se estiver ligada. */
function recarregarEtapas() {
  etState.dados = null
  if (camadaLigada('etapas')) carregarEtapasDoMapa()
}

function _apagarEtapas() {
  etState.camada?.remove(); etState.camada = null
  etState.selos?.remove(); etState.selos = null
  etState.foco = null
  const leg = document.getElementById('et-legenda')
  if (leg) leg.hidden = true
}

/* ── Cores e nomes ──────────────────────────────────────────────────── */

/** Os atos que criam quadra do bairro, a aprovação padrão primeiro (a mesma ordem do painel). */
function _etapasDoBairro(bairro) {
  const b = etState.dados?.bairros?.[bairro]
  if (!b) return []
  const criam = b.atos.filter(a => a.papel === 'criou')
  return [...criam.filter(a => a.id === b.padrao), ...criam.filter(a => a.id !== b.padrao)]
}

/** A cor do ato no bairro: as mesmas de "Quadras por decreto" (COR_DOS_ATOS). */
function _corDaEtapa(bairro, id) {
  const i = Math.max(0, _etapasDoBairro(bairro).findIndex(a => a.id === id))
  return COR_DOS_ATOS[i % COR_DOS_ATOS.length]
}

/** "Original", "1ª ampliação", "2ª etapa"… */
function _nomeDaEtapa(bairro, id) {
  const lista = _etapasDoBairro(bairro)
  const a = lista.find(x => x.id === id)
  if (!a) return '—'
  if (a.tipo === 'aprovacao') return 'Original'
  const n = lista.filter(x => x.tipo === a.tipo).findIndex(x => x.id === id) + 1
  return `${n}ª ${a.tipo === 'etapa' ? 'etapa' : 'ampliação'}`
}

const _anoDoAto = a => (a?.data ? a.data.slice(-4) : '')

function _estiloEtapa(p) {
  const cor = _corDaEtapa(p.bairro, p.criou)
  const apagada = etState.foco != null && (p.bairro !== etState.bairro || p.criou !== etState.foco)
  return {
    color: cor, weight: p.presumido ? 1.2 : 1.6, opacity: apagada ? 0.25 : 0.9,
    fillColor: cor, fillOpacity: apagada ? 0.04 : p.presumido ? 0.14 : 0.38,
    dashArray: p.presumido ? '5 4' : null,
  }
}

/* ── O selo "R" das quadras alteradas ───────────────────────────────── */

function _desenharSelos() {
  const mapa = mapaState?.obj
  etState.selos?.remove(); etState.selos = null
  if (!mapa || !etState.dados || !camadaLigada('etapas') || !camadaLigada('etapas-alteradas')) return
  const pane = _etPane(mapa, 'etapasSelos', 439)
  etState.selos = L.layerGroup(etState.dados.features
    .filter(f => f.properties.alterou.length)
    .map(f => L.marker(f.properties.rotulo, {
      pane, interactive: false, keyboard: false,
      icon: L.divIcon({ className: 'et-selo', html: 'R', iconSize: [18, 18], iconAnchor: [-6, 22] }),
    }))).addTo(mapa)
}

/* ── A legenda: o bairro do centro do mapa ──────────────────────────── */

function _quadraNoPonto(latlng) {
  const x = latlng.lng, y = latlng.lat
  return (etState.dados?.features || []).find(f => {
    const g = f.geometry
    const polis = g.type === 'Polygon' ? [g.coordinates] : g.coordinates
    return polis.some(p => _pontoNoAnel(x, y, p[0]) && !p.slice(1).some(h => _pontoNoAnel(x, y, h)))
  }) || null
}

/** O bairro da legenda: o do centro; senão, o que tem mais quadras à vista. */
function _bairroDaLegenda() {
  const m = mapaState.obj
  const doCentro = _quadraNoPonto(m.getCenter())
  if (doCentro) return doCentro.properties.bairro
  const b = m.getBounds()
  const conta = {}
  for (const f of etState.dados?.features || []) {
    if (b.contains(f.properties.rotulo)) conta[f.properties.bairro] = (conta[f.properties.bairro] || 0) + 1
  }
  return Object.entries(conta).sort((a, c) => c[1] - a[1])[0]?.[0] || null
}

function _legendaDasEtapas() {
  const leg = document.getElementById('et-legenda')
  if (!leg) return
  const bairro = etState.dados && camadaLigada('etapas') && nivelDoMapa(mapaState.obj) !== 'municipio' ? _bairroDaLegenda() : null
  if (bairro !== etState.bairro && etState.foco != null) { etState.foco = null; etState.camada?.setStyle(f => _estiloEtapa(f.properties)) }
  etState.bairro = bairro
  if (!bairro) { leg.hidden = true; return }

  const qs = etState.dados.features.filter(f => f.properties.bairro === bairro).map(f => f.properties)
  const linhas = _etapasDoBairro(bairro).map(a => {
    const minhas = qs.filter(q => q.criou === a.id)
    const area = minhas.reduce((s, q) => s + q.area, 0)
    let conf = ''
    if (a.area_m2) {
      const dif = area - a.area_m2
      conf = Math.abs(dif) <= a.area_m2 * 0.01 ? '<span class="qd-ok">≈ confere</span>'
        : `<span class="qd-dif">${dif < 0 ? 'faltam' : 'sobram'} ${fmtBR(Math.abs(dif))} m²</span>`
    }
    return `<button type="button" class="et-item${etState.foco === a.id ? ' sel' : ''}${etState.foco != null && etState.foco !== a.id ? ' apagado' : ''}"
        onclick="destacarEtapa(${a.id})" title="${esc(a.ato)}">
      <i style="background:${_corDaEtapa(bairro, a.id)}"></i>
      <span><b>${esc(_nomeDaEtapa(bairro, a.id))}</b><small>${minhas.length} quadra${minhas.length === 1 ? '' : 's'}${_anoDoAto(a) ? ' · ' + _anoDoAto(a) : ''}</small>
        ${conf ? `<small class="et-conf">${conf}</small>` : ''}</span>
    </button>`
  }).join('')
  const semMarca = qs.filter(q => q.presumido).length
  const alteradas = qs.filter(q => q.alterou.length).length
  leg.innerHTML = `<div class="et-topo"><b>Etapas · ${esc(bairro)}</b></div>${linhas}
    ${semMarca ? `<div class="et-item et-fixo"><i class="et-tracejado"></i><span><b>Sem marcação</b><small>${semMarca} quadra${semMarca === 1 ? '' : 's'} · fica com a original</small></span></div>` : ''}
    ${alteradas && camadaLigada('etapas-alteradas') ? `<div class="et-item et-fixo"><i class="et-selo-leg">R</i><span><b>Alterada depois</b><small>${alteradas} quadra${alteradas === 1 ? '' : 's'}</small></span></div>` : ''}`
  leg.hidden = false
}

/** Clique numa etapa da legenda: destaca as quadras dela; de novo, desfaz. */
function destacarEtapa(id) {
  etState.foco = etState.foco === id ? null : id
  etState.camada?.setStyle(f => _estiloEtapa(f.properties))
  _legendaDasEtapas()
}

/* ── O balão da quadra ──────────────────────────────────────────────── */

/**
 * Chamado pelo clique no mapa (_cliqueNoBairro). Com a camada ligada e o
 * mapa afastado dos lotes, a quadra sob o cursor abre o balão dela.
 * @returns {boolean} se abriu (e o bairro não deve abrir o seu)
 */
function cliqueNaEtapa(ev) {
  const mapa = mapaState.obj
  if (!etState.dados || !camadaLigada('etapas') || nivelDoMapa(mapa) === 'lotes') return false
  const f = _quadraNoPonto(ev.latlng)
  if (!f) return false
  const p = f.properties
  const b = etState.dados.bairros[p.bairro]
  const porId = new Map(b.atos.map(a => [a.id, a]))
  const criou = porId.get(p.criou)
  const cor = _corDaEtapa(p.bairro, p.criou)
  const ato = a => esc(a.ato)
  L.popup({ className: 'popup-lote', closeButton: true, maxWidth: 300, minWidth: 250, autoPan: true })
    .setLatLng(ev.latlng)
    .setContent(`
      <div class="balao4">
        <div class="balao4-cab"><b>Quadra ${esc(p.quadra)} · ${esc(p.bairro)}</b></div>
        <div class="balao4-corpo">
          <table class="balao4-tab">
            <tr><td>Criada por</td><td>${criou ? ato(criou) : '—'}${p.presumido ? ' <span class="badge bd-al" title="A quadra não foi marcada em nenhuma ampliação: vale a aprovação do bairro">padrão do bairro</span>' : ''}</td></tr>
            <tr><td>Etapa</td><td><b style="color:${cor}">${esc(_nomeDaEtapa(p.bairro, p.criou))}</b></td></tr>
            ${p.alterou.map(id => porId.get(id)).filter(Boolean).map(a => `<tr><td>Alterada por</td><td>${ato(a)} <span class="badge bd-in">${esc(a.rotulo)}</span></td></tr>`).join('')}
            <tr><td>Área</td><td>${fmtBR(p.area)} m²</td></tr>
            <tr><td>Lotes</td><td>${p.lotes}</td></tr>
          </table>
          <div class="et-botoes">
            <button type="button" class="btn sm balao-btn" data-bairro="${esc(p.bairro)}" data-quadra="${esc(p.quadra)}"
              onclick="aproximarDaQuadraDaEtapa(this.dataset.bairro, this.dataset.quadra)">Ver lotes</button>
            ${typeof htmlQuadrasPorDecretoNoBalao === 'function' ? htmlQuadrasPorDecretoNoBalao({ nome: p.bairro, outros_atos: b.atos }) : ''}
          </div>
        </div>
      </div>`)
    .openOn(mapa)
  return true
}

function aproximarDaQuadraDaEtapa(bairro, quadra) {
  const f = etState.dados?.features.find(x => x.properties.bairro === bairro && x.properties.quadra === quadra)
  if (!f) return
  mapaState.obj.closePopup()
  mapaState.obj.fitBounds(L.geoJSON(f).getBounds(), { padding: [40, 40], maxZoom: ZOOM_MAXIMO })
}

/* ── Início ─────────────────────────────────────────────────────────── */

// Mesmo gancho do perímetro: o mapa só fica pronto quando a aba Mapa abre.
document.addEventListener('DOMContentLoaded', () => {
  const t = setInterval(() => {
    if (!mapaState?.obj) return
    clearInterval(t)
    mapaState.obj.whenReady(() => {
      carregarEtapasDoMapa()
      mapaState.obj.on('moveend', () => { if (etState.camada) _legendaDasEtapas() })
    })
  }, 200)
})
