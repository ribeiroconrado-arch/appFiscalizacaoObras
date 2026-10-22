/** Pesquisa livre e campos combinados; coordenadas continuam independentes. */
const pesqState = { opcoes: null, campos: {}, res: null, aviso: null, pedido: 0, ponto: null, coordenada: false, combinando: false, timer: null, abort: null }
const _pesqVal = id => document.getElementById(id)?.value.trim() ?? ''

/** Ancora à esquerda da lupa e limita o painel à área visível do mapa. */
function posicionarPesquisaMapa() {
  const painel = document.getElementById('pesq-barra')
  const mapa = document.getElementById('t-mapa')
  const botao = document.querySelector('#grupo-busca .ctrl-btn')
  if (!painel || painel.hidden || !mapa || !botao) return
  const r = mapa.getBoundingClientRect(), b = botao.getBoundingClientRect()
  const esquerda = Math.max(0, r.left) + 8
  const topo = Math.max(0, r.top) + 8
  const base = Math.min(window.innerHeight, r.bottom) - 8
  const largura = Math.max(0, Math.min(400, b.left - 8 - esquerda))
  painel.style.width = largura + 'px'
  painel.style.maxHeight = Math.max(0, base - topo) + 'px'
  painel.style.left = (b.left - 8 - largura - r.left) + 'px'
  painel.style.top = (Math.max(topo, Math.min(b.top, base - painel.offsetHeight)) - r.top) + 'px'
}
window.addEventListener('resize', posicionarPesquisaMapa)
document.addEventListener('DOMContentLoaded', () => {
  const painel = document.getElementById('pesq-barra')
  const mapa = document.getElementById('t-mapa')
  const observer = new ResizeObserver(posicionarPesquisaMapa)
  if (painel) observer.observe(painel)
  if (mapa) observer.observe(mapa)
})

function abrirPesquisaMapa() {
  pedirFerramenta('busca', () => {
    fecharPaineisMapa()
    const barra = document.getElementById('pesq-barra')
    if (!barra) return
    // Fora da faixa central: o painel tem posição própria dentro do mapa.
    document.getElementById('t-mapa').appendChild(barra)
    barra.hidden = false
    pintarBarraPesquisa()
    document.querySelector('#grupo-busca .ctrl-btn')?.classList.add('at')
    _pesqCarregarOpcoes()
    if (Object.values(pesqState.campos).some(Boolean)) _pesqBuscar()
  })
}
function fecharPesquisaMapa() {
  clearTimeout(pesqState.timer)
  pesqState.abort?.abort()
  pesqState.pedido++
  const barra = document.getElementById('pesq-barra')
  if (barra) barra.hidden = true
  document.querySelector('#grupo-busca .ctrl-btn')?.classList.remove('at')
  if (typeof destacarLotes === 'function') destacarLotes(null)
  pesqState.ponto?.remove()
  pesqState.ponto = null
}
function alternarPesquisaMapa() {
  const barra = document.getElementById('pesq-barra')
  barra && !barra.hidden ? fecharPesquisaMapa() : abrirPesquisaMapa()
}
async function _pesqCarregarOpcoes() {
  if (pesqState.opcoes) return
  try {
    const r = await fetch('/api/mapa/pesquisa/opcoes', { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('Não foi possível carregar as sugestões.')
    pesqState.opcoes = await r.json()
    if (!document.getElementById('pesq-barra')?.hidden) pintarBarraPesquisa()
  } catch { /* Busca livre permanece disponível; reabrir permite tentar novamente. */ }
}
function pintarBarraPesquisa() {
  const barra = document.getElementById('pesq-barra')
  if (!barra) return
  const anterior = document.activeElement
  const foco = barra.contains(anterior) ? anterior.id : null
  const inicio = anterior?.selectionStart, fim = anterior?.selectionEnd
  const rolagem = barra.querySelector('.pesq-conteudo')?.scrollTop || 0
  const detalhes = [...barra.querySelectorAll('details[open]')].map(d => d.id)
  const s = pesqState, c = s.campos, o = s.opcoes || {}
  const campo = (nome, rotulo, extra = '', dica = '') => `<label class="pesq-direto-campo"><span>${rotulo}</span><input id="pd-${nome}" name="${nome}" value="${esc(c[nome] || '')}" ${extra} placeholder="${dica}" autocomplete="off"></label>`
  const select = (nome, rotulo, opcoes) => `<label class="pesq-direto-campo"><span>${rotulo}</span><select id="pd-${nome}" name="${nome}">${Object.entries(opcoes).map(([v, label]) => `<option value="${esc(v)}" ${String(c[nome] || '') === v ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label>`
  barra.innerHTML = `<div class="pesq-cabecalho"><h2>Pesquisar no mapa</h2><button type="button" data-pesq="fechar" aria-label="Fechar pesquisa">&#10005;</button></div>
    <div class="pesq-conteudo"><form id="pesq-direta">
    <div class="pesq-livre"><input id="pd-termo" name="termo" value="${esc(c.termo || '')}" maxlength="120" placeholder="O que você procura?" aria-label="Pesquisa livre" autocomplete="off"><button type="submit">Buscar</button></div>
    <p class="pesq-dica">Digite um endereço, bairro ou inscrição.</p>
    <button type="button" class="pesq-combinar" data-pesq="combinar" aria-expanded="${s.combinando}" aria-controls="pesq-campos-diretos">Combinar campos <span>${s.combinando ? '−' : '+'}</span></button>
    <div id="pesq-campos-diretos" class="${s.combinando ? 'expandido' : ''}"><div class="pesq-direta-titulo">Pesquisar por campos <small>Preencha só o que souber</small></div>
    <div class="pesq-direta-grid">
    ${campo('bairro_texto', 'Bairro', 'list="pd-bairros" maxlength="160"', 'Todos os bairros')}
    ${campo('inscricao', 'Inscrição imobiliária', 'maxlength="40"', 'Completa ou início')}
    <div class="pesq-linha-inteira">${campo('rua_texto', 'Rua / logradouro', 'list="pd-ruas" maxlength="180"', 'Nome da rua')}</div>
    <div class="pesq-tres">${campo('numero_de', 'Número', 'type="number" min="0" inputmode="numeric"', 'Nº')}${campo('quadra_de', 'Quadra', 'type="number" min="0" inputmode="numeric"')}${campo('lote_de', 'Lote', 'type="number" min="0" inputmode="numeric"')}</div></div>
    <details id="pd-intervalos"><summary>Intervalos e lado da rua</summary><div class="pesq-direta-grid">
      ${campo('quadra_ate', 'Até a quadra', 'type="number" min="0" inputmode="numeric"')}${campo('lote_ate', 'Até o lote', 'type="number" min="0" inputmode="numeric"')}
      ${select('numero_modo', 'Número da rua', { '': 'Número exato', faixa: 'Intervalo', par: 'Lado par', impar: 'Lado ímpar' })}${campo('numero_ate', 'Até o número', 'type="number" min="0" inputmode="numeric"')}
    </div></details>
    ${Object.keys(o.pendencias || {}).length ? `<details id="pd-situacao"><summary>Situação e vistorias</summary><div class="pesq-direta-grid">${select('pendencia', 'Pendência', { '': 'Todas', ...o.pendencias, sem: 'Sem nenhuma pendência' })}${select('vistoria', 'Última vistoria', { '': 'Qualquer situação', ...o.vistorias })}</div></details>` : ''}
    </div>
    <div class="pesq-direta-pe"><span>Os campos preenchidos são combinados.</span><button type="button" data-pesq="limpar">Limpar</button></div>
    </form>
    <details id="pd-coordenada"><summary>Ir para coordenada</summary><div class="pesq-linha"><input id="pesq-coord" class="pesq-coord" aria-label="Coordenada" placeholder="Latitude, longitude ou UTM 21S"><button type="button" data-pesq="ir-coordenada">Ir</button></div><div id="pesq-coord-msg" hidden></div></details>
    <datalist id="pd-bairros">${(o.bairros || []).map(b => `<option value="${esc(b.nome)}">${esc(b.codigo || '')}</option>`).join('')}</datalist>
    <datalist id="pd-ruas">${(o.ruas || []).map(r => `<option value="${esc(r.rua)}"></option>`).join('')}</datalist>
    <div id="pesq-resultado" class="pesq-resultado" aria-live="polite">${_pesqResultadoHtml()}</div></div>`
  detalhes.forEach(id => { const el = document.getElementById(id); if (el) el.open = true })
  if (foco) {
    const el = document.getElementById(foco)
    el?.focus({ preventScroll: true })
    if (el?.type === 'text' && inicio != null) el.setSelectionRange(inicio, fim)
  }
  barra.querySelector('.pesq-conteudo').scrollTop = rolagem
  posicionarPesquisaMapa()
}
function _pesqParametros() {
  const c = pesqState.campos, p = new URLSearchParams()
  for (const k of ['termo', 'bairro_texto', 'rua_texto', 'inscricao', 'quadra_de', 'quadra_ate', 'lote_de', 'lote_ate']) if (c[k]?.trim()) p.set(k, c[k].trim())
  for (const k of ['quadra', 'lote']) if (c[k + '_de'] && !c[k + '_ate']) p.set(k + '_ate', c[k + '_de'])
  if (['par', 'impar'].includes(c.numero_modo)) p.set('numero_modo', c.numero_modo)
  else if (c.numero_de || (c.numero_modo === 'faixa' && c.numero_ate)) {
    p.set('numero_modo', c.numero_modo || 'exato')
    if (c.numero_de) p.set('numero_de', c.numero_de)
    if (c.numero_modo === 'faixa' && c.numero_ate) p.set('numero_ate', c.numero_ate)
  }
  if (c.pendencia === 'sem') p.set('sem_pendencia', '1')
  else if (c.pendencia) p.append('pendencias[]', c.pendencia)
  if (c.vistoria) p.append('vistorias[]', c.vistoria)
  return p
}
function _pesqResultadoHtml() {
  const s = pesqState
  if (s.aviso) return `<div class="pesq-msg${s.aviso.erro ? ' pesq-erro' : ''}">${esc(s.aviso.texto)}</div>`
  if (!s.res) return '<div class="pesq-msg">Digite na busca livre ou preencha os campos para pesquisar.</div>'
  const r = s.res
  if (!r.total) return '<div class="pesq-msg">Nenhum imóvel combina com os critérios informados.</div>'
  return `<div class="pesq-msg">${r.total}${r.truncado ? '+' : ''} imóveis encontrados${r.truncado ? ' · refine a pesquisa' : ''}</div>
    ${r.imoveis.map(i => `<button type="button" class="pesq-imovel" data-pesq="ir" data-id="${Number(i.id)}"><strong>${esc(i.endereco || i.inscricao || 'Imóvel')}</strong><span>${esc(i.bairro || '')} · Q ${esc(i.quadra ?? '—')} · Lote ${esc(i.lote ?? '—')}</span><small>${esc(i.inscricao || '')}</small>${(i.pendencias || []).map(p => `<span class="pesq-tag">${esc(p)}</span>`).join('')}</button>`).join('')}
    ${r.total > r.imoveis.length ? `<div class="pesq-msg">Mostrando ${r.imoveis.length} na lista; ${r.total} destacados no mapa.</div>` : ''}`
}
function _pesqAtualizarResultado() {
  const el = document.getElementById('pesq-resultado')
  if (el) el.innerHTML = _pesqResultadoHtml()
}
function _pesqLerCampos() {
  const form = document.getElementById('pesq-direta')
  if (form) pesqState.campos = Object.fromEntries(new FormData(form))
}
document.addEventListener('input', e => {
  if (!e.target.closest?.('#pesq-direta')) return
  _pesqLerCampos()
  // Invalida imediatamente qualquer resposta da consulta anterior.
  pesqState.pedido++
  pesqState.abort?.abort()
  clearTimeout(pesqState.timer)
  pesqState.timer = setTimeout(_pesqBuscar, 500)
})
document.addEventListener('submit', e => {
  if (e.target.id !== 'pesq-direta') return
  e.preventDefault(); _pesqLerCampos(); _pesqBuscar()
})
document.addEventListener('keydown', e => {
  if (!e.target.closest?.('#pesq-barra')) return
  if (e.key === 'Escape') fecharPesquisaMapa()
  if (e.key === 'Enter' && e.target.id === 'pesq-coord') { e.preventDefault(); _pesqIrACoordenada(e.target.value) }
})
document.addEventListener('click', e => {
  const btn = e.target.closest?.('#pesq-barra [data-pesq]')
  if (!btn) return
  const { pesq, id } = btn.dataset
  setTimeout(() => {
    if (pesq === 'fechar') fecharPesquisaMapa()
    if (pesq === 'ir') { if (window.innerWidth <= 620) fecharPesquisaMapa(); irAoLoteNoMapa(Number(id)) }
    if (pesq === 'ir-coordenada') _pesqIrACoordenada(_pesqVal('pesq-coord'))
    if (pesq === 'combinar') {
      pesqState.combinando = !pesqState.combinando
      document.getElementById('pesq-campos-diretos').classList.toggle('expandido', pesqState.combinando)
      btn.setAttribute('aria-expanded', String(pesqState.combinando))
      btn.querySelector('span').textContent = pesqState.combinando ? '−' : '+'
    }
    if (pesq === 'limpar') {
      pesqState.campos = {}; pesqState.res = null; pesqState.aviso = null
      pesqState.ponto?.remove(); pesqState.ponto = null
      pintarBarraPesquisa(); _pesqBuscar()
    }
  }, 0)
})
async function _pesqBuscar() {
  clearTimeout(pesqState.timer)
  const s = pesqState, pedido = ++s.pedido
  s.abort?.abort()
  s.res = null
  const p = _pesqParametros()
  if (!p.size) { s.aviso = null; destacarLotes(null); _pesqAtualizarResultado(); return }
  s.aviso = { texto: 'Pesquisando…' }; _pesqAtualizarResultado()
  s.abort = new AbortController()
  try {
    const r = await fetch('/api/mapa/pesquisa?' + p, { headers: { Accept: 'application/json' }, signal: s.abort.signal })
    const d = await r.json()
    if (pedido !== s.pedido) return
    if (!r.ok) throw new Error(d.message || 'Falha na pesquisa.')
    s.aviso = null; s.res = d
    destacarLotes(d.ids.length ? d.ids : null)
    if (d.caixa) {
      const [sul, oeste, norte, leste] = d.caixa
      const barra = document.getElementById('pesq-barra')
      const mapa = document.getElementById('map').getBoundingClientRect()
      const painel = barra.getBoundingClientRect()
      const espacoLateral = painel.left - mapa.left
      const lateral = espacoLateral >= 160
      const acima = painel.top - mapa.top > mapa.bottom - painel.bottom
      const inicio = lateral || acima ? [20, 20] : [20, Math.max(20, painel.bottom - mapa.top + 16)]
      const final = lateral ? [mapa.right - painel.left + 16, 20] : acima ? [20, mapa.bottom - painel.top + 16] : [20, 20]
      mapaState.obj?.fitBounds([[sul, oeste], [norte, leste]], { paddingTopLeft: inicio, paddingBottomRight: final, maxZoom: ZOOM_DO_LOTE, animate: false })
    }
  } catch (e) {
    if (pedido !== s.pedido || e.name === 'AbortError') return
    s.aviso = { texto: e.message, erro: true }; destacarLotes(null)
  }
  _pesqAtualizarResultado()
}

// ── COORDENADA ───────────────────────────────────────────────

/** Mensagem embaixo do campo de coordenada. */
function _pesqSaida(html) {
  const el = document.getElementById('pesq-coord-msg')
  if (!el) return
  el.hidden = !html
  el.innerHTML = html || ''
}

function _pesqIrACoordenada(termo) {
  const nums = (termo.match(/-?\d+(?:[.,]\d+)?/g) || []).map(n => Number(n.replace(',', '.')))
  if (nums.length < 2) { _pesqSaida('<div class="pesq-msg pesq-erro">Informe as duas coordenadas.</div>'); return }
  let [a, b] = nums
  let lat, lon, como
  if (Math.abs(a) <= 90 && Math.abs(b) <= 180) {
    // Graus decimais. Aceita na ordem que vier: aqui a latitude é ~-15 e a longitude ~-54.
    ;[lat, lon] = Math.abs(a) < Math.abs(b) ? [a, b] : [b, a]
    como = 'lat/long'
  } else {
    // UTM — E tem 6 dígitos, N tem 7. Fuso 21 sul (SIRGAS 2000), o do município.
    const [E, N] = a < b ? [a, b] : [b, a]
    ;[lat, lon] = utmParaLatLon(E, N, 21, true)
    como = 'UTM 21S'
  }
  if (!isFinite(lat) || !isFinite(lon)) { _pesqSaida('<div class="pesq-msg pesq-erro">Coordenada inválida.</div>'); return }

  pesqState.ponto?.remove()
  pesqState.ponto = L.circleMarker([lat, lon], { radius: 9, color: '#facc15', weight: 3, fillOpacity: .25 })
    .addTo(mapaState.obj)
  mapaState.obj.setView([lat, lon], ZOOM_DO_LOTE)
  _pesqSaida(`<div class="pesq-msg">${como}: ${lat.toFixed(6)}, ${lon.toFixed(6)}</div>`)
}

/**
 * UTM → graus (elipsoide GRS80, o do SIRGAS 2000; para esta finalidade igual
 * ao WGS84). Fórmulas de Snyder, "Map Projections — A Working Manual", p. 63.
 *
 * @returns {[number, number]} [lat, lon]
 */
function utmParaLatLon(E, N, zona, sul) {
  const a = 6378137, f = 1 / 298.257222101, k0 = 0.9996
  const e2 = f * (2 - f), ep2 = e2 / (1 - e2)
  const x = E - 500000, y = sul ? N - 10000000 : N
  const M = y / k0
  const mu = M / (a * (1 - e2 / 4 - 3 * e2 ** 2 / 64 - 5 * e2 ** 3 / 256))
  const e1 = (1 - Math.sqrt(1 - e2)) / (1 + Math.sqrt(1 - e2))
  const phi1 = mu + (3 * e1 / 2 - 27 * e1 ** 3 / 32) * Math.sin(2 * mu)
    + (21 * e1 ** 2 / 16 - 55 * e1 ** 4 / 32) * Math.sin(4 * mu)
    + (151 * e1 ** 3 / 96) * Math.sin(6 * mu)
  const C1 = ep2 * Math.cos(phi1) ** 2, T1 = Math.tan(phi1) ** 2
  const N1 = a / Math.sqrt(1 - e2 * Math.sin(phi1) ** 2)
  const R1 = a * (1 - e2) / (1 - e2 * Math.sin(phi1) ** 2) ** 1.5
  const D = x / (N1 * k0)
  const lat = phi1 - (N1 * Math.tan(phi1) / R1) * (D ** 2 / 2
    - (5 + 3 * T1 + 10 * C1 - 4 * C1 ** 2 - 9 * ep2) * D ** 4 / 24
    + (61 + 90 * T1 + 298 * C1 + 45 * T1 ** 2 - 252 * ep2 - 3 * C1 ** 2) * D ** 6 / 720)
  const lon0 = (zona * 6 - 183) * Math.PI / 180
  const lon = lon0 + (D - (1 + 2 * T1 + C1) * D ** 3 / 6
    + (5 - 2 * C1 + 28 * T1 - 3 * C1 ** 2 + 8 * ep2 + 24 * T1 ** 2) * D ** 5 / 120) / Math.cos(phi1)
  return [lat * 180 / Math.PI, lon * 180 / Math.PI]
}
