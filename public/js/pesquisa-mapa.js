/**
 * PESQUISA DO MAPA — barra horizontal no alto do mapa, na mesma faixa das
 * barras da curadoria (#mapa-barras).
 *
 * Antes era um campo único num painel da coluna de controles, que adivinhava
 * o tipo do que se digitava. Agora quem pesquisa ESCOLHE o tipo — inscrição,
 * quadra e lote, endereço, bairro ou coordenada — e pode limitar a um bairro.
 * O servidor é o mesmo da Consulta (GET /api/imoveis/busca); a coordenada é
 * resolvida aqui, sem ida ao servidor.
 *
 * É uma FERRAMENTA (ferramentas-mapa.js): abrir a pesquisa fecha a curadoria,
 * e abrir a curadoria fecha a pesquisa.
 */

const pesqState = {
  /** @type {'inscricao'|'quadra'|'endereco'|'bairro'|'coordenada'} */
  tipo: 'inscricao',
  /** @type {string[]|null} bairros com lote, para o "em:" e o tipo Bairro */
  bairros: null,
  /** @type {L.Marker|null} o ponto da coordenada procurada */
  ponto: null,
}

const PESQ_TIPOS = {
  inscricao:  { rotulo: 'Inscrição',     dica: '01.060.002.0009.000 — pode ser só o começo' },
  quadra:     { rotulo: 'Quadra e lote', dica: 'quadra e lote: 14 5, Q14 L5 — ou só a quadra' },
  endereco:   { rotulo: 'Endereço',      dica: 'rua e número: Carnaúba 266' },
  bairro:     { rotulo: 'Bairro',        dica: 'nome do bairro: Buritis' },
  coordenada: { rotulo: 'Coordenada',    dica: 'lat, long (-15.5601, -54.3065) ou UTM 21S (E N: 788900 8277900)' },
}

function abrirPesquisaMapa() {
  pedirFerramenta('busca', () => {
    fecharPaineisMapa()
    const barra = document.getElementById('pesq-barra')
    if (!barra) return
    if (barra.hidden) { barra.hidden = false; pintarBarraPesquisa() }
    document.getElementById('grupo-busca')?.querySelector('.ctrl-btn')?.classList.add('at')
    setTimeout(() => document.getElementById('pesq-termo')?.focus(), 30)
    _pesqCarregarBairros()
  })
}

function fecharPesquisaMapa() {
  const barra = document.getElementById('pesq-barra')
  if (barra) barra.hidden = true
  document.getElementById('grupo-busca')?.querySelector('.ctrl-btn')?.classList.remove('at')
  if (typeof destacarLotes === 'function') destacarLotes(null)
  pesqState.ponto?.remove()
  pesqState.ponto = null
}

/** A lupa abre e fecha. */
function alternarPesquisaMapa() {
  const barra = document.getElementById('pesq-barra')
  barra && !barra.hidden ? fecharPesquisaMapa() : abrirPesquisaMapa()
}

function pintarBarraPesquisa() {
  const barra = document.getElementById('pesq-barra')
  const t = PESQ_TIPOS[pesqState.tipo]
  const usaEscopo = pesqState.tipo !== 'bairro' && pesqState.tipo !== 'coordenada'
  barra.innerHTML = `
    <div class="pesq-linha">
      <svg class="pesq-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
      <input type="text" id="pesq-termo" class="pesq-termo" placeholder="${esc(t.dica)}"
             aria-label="Pesquisar no mapa: ${esc(t.rotulo)}" autocomplete="off"
             ${pesqState.tipo === 'bairro' ? 'list="pesq-bairros"' : ''}
             onkeydown="if(event.key==='Enter')pesquisarNoMapa(); if(event.key==='Escape')fecharPesquisaMapa()">
      <button type="button" class="btn primary sm" onclick="pesquisarNoMapa()">Pesquisar</button>
      <button type="button" class="btn sm pesq-x" title="Fechar (Esc)" onclick="fecharPesquisaMapa()">&#10005;</button>
    </div>
    <div class="pesq-linha pesq-opcoes">
      ${Object.entries(PESQ_TIPOS).map(([k, v]) => `
        <button type="button" class="pesq-chip${k === pesqState.tipo ? ' at' : ''}" onclick="trocarTipoPesquisa('${k}')">${esc(v.rotulo)}</button>`).join('')}
      ${usaEscopo ? `
        <label class="pesq-escopo">em
          <select id="pesq-escopo"><option value="">todos os bairros</option>
            ${(pesqState.bairros || []).map(b => `<option>${esc(b)}</option>`).join('')}</select>
        </label>` : ''}
    </div>
    <datalist id="pesq-bairros">${(pesqState.bairros || []).map(b => `<option value="${esc(b)}">`).join('')}</datalist>
    <div id="pesq-resultado" class="pesq-resultado" hidden></div>`
}

function trocarTipoPesquisa(tipo) {
  const termo = document.getElementById('pesq-termo')?.value ?? ''
  const escopo = document.getElementById('pesq-escopo')?.value ?? ''
  pesqState.tipo = tipo
  pintarBarraPesquisa()
  const campo = document.getElementById('pesq-termo')
  campo.value = termo
  const sel = document.getElementById('pesq-escopo')
  if (sel) sel.value = escopo
  campo.focus()
}

async function _pesqCarregarBairros() {
  if (pesqState.bairros) return
  try {
    const r = await fetch('/api/imoveis/bairros', { headers: { Accept: 'application/json' } })
    pesqState.bairros = (await r.json()).bairros || []
    const termo = document.getElementById('pesq-termo')?.value ?? ''
    pintarBarraPesquisa()
    const campo = document.getElementById('pesq-termo')
    if (campo) { campo.value = termo; campo.focus() }
  } catch { pesqState.bairros = [] }
}

/** Mostra uma mensagem (ou lista) embaixo da barra. */
function _pesqSaida(html) {
  const el = document.getElementById('pesq-resultado')
  if (!el) return
  el.hidden = !html
  el.innerHTML = html || ''
}

async function pesquisarNoMapa() {
  const termo = document.getElementById('pesq-termo')?.value.trim() ?? ''
  if (!termo) { _pesqSaida(`<div class="pesq-msg">${esc(PESQ_TIPOS[pesqState.tipo].dica)}</div>`); return }

  if (pesqState.tipo === 'coordenada') { _pesqIrACoordenada(termo); return }
  if (pesqState.tipo === 'bairro' && _pesqIrAoContornoDoBairro(termo)) return

  const p = new URLSearchParams()
  const escopo = document.getElementById('pesq-escopo')?.value
  if (escopo) p.set('bairro', escopo)
  if (pesqState.tipo === 'inscricao') {
    // Inteira (15 dígitos) é exata; só o começo vira faixa — "01.105.014" é
    // a quadra 14 do bairro 105 inteira, de 01105014000000 a 01105014999999.
    const dig = termo.replace(/\D/g, '')
    if (dig.length >= 15 || dig.length < 2) p.set('inscricao', termo)
    else { p.set('inscricao_de', dig.padEnd(15, '0')); p.set('inscricao_ate', dig.padEnd(15, '9')) }
  }
  if (pesqState.tipo === 'bairro') p.set('bairro', termo)
  if (pesqState.tipo === 'quadra') {
    const partes = termo.toUpperCase().replace(/[QL]/g, ' ').trim().split(/[\s/.,-]+/).filter(Boolean)
    if (!partes.length) { _pesqSaida('<div class="pesq-msg">Informe a quadra (e o lote).</div>'); return }
    p.set('quadra', partes[0])
    if (partes[1]) p.set('lote', partes[1])
  }
  if (pesqState.tipo === 'endereco') {
    const m = termo.match(/^(.*?)[,\s]+(\d+[A-Za-z]?)$/)
    p.set('logradouro', (m ? m[1] : termo).trim())
    if (m) p.set('numero', m[2])
  }

  _pesqSaida('<div class="pesq-msg">Pesquisando…</div>')
  try {
    const r = await fetch('/api/imoveis/busca?' + p, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'Falha na pesquisa.')
    if (!d.imoveis.length) { _pesqSaida('<div class="pesq-msg">Nenhum imóvel encontrado.</div>'); destacarLotes(null); return }

    if (d.imoveis.length === 1) {
      _pesqSaida(_pesqLinhas(d.imoveis, false))
      irAoLoteNoMapa(d.imoveis[0].id)
      return
    }
    // Vários: pintados no mapa (destaque por filtro) e listados para escolher.
    destacarLotes(d.imoveis.map(i => i.id))
    _pesqSaida(`<div class="pesq-msg">${d.total}${d.truncado ? '+' : ''} imóveis — destacados no mapa.
        <a href="#" onclick="event.preventDefault(); destacarLotes(null); _pesqSaida('')">limpar</a></div>`
      + _pesqLinhas(d.imoveis.slice(0, 30), true)
      + (d.imoveis.length > 30 ? `<div class="pesq-msg">Mostrando 30. Refine a pesquisa ou limite a um bairro.</div>` : ''))
  } catch (e) {
    _pesqSaida(`<div class="pesq-msg pesq-erro">${esc(e.message)}</div>`)
  }
}

function _pesqLinhas(imoveis, comVer) {
  return `<table class="pesq-tabela"><tbody>${imoveis.map(i => `
    <tr onclick="irAoLoteNoMapa(${Number(i.id)})">
      <td><b>Q ${esc(i.quadra ?? '—')} · L ${esc(i.lote ?? '—')}</b></td>
      <td>${esc(i.bairro || '')}</td>
      <td class="mono">${esc(i.inscricao || '')}</td>
      <td class="pesq-ver">${comVer ? 'ver ›' : ''}</td>
    </tr>`).join('')}</tbody></table>`
}

/** Bairro com contorno: o mapa enquadra o bairro inteiro. */
function _pesqIrAoContornoDoBairro(termo) {
  const alvo = BairrosDoNomeChave(termo)
  const f = (typeof contornoState !== 'undefined' ? contornoState.dados?.features : [])
    ?.find(x => [x.properties.nome, x.properties.nome_oficial].some(n => BairrosDoNomeChave(n) === alvo))
    ?? (typeof contornoState !== 'undefined' ? contornoState.dados?.features : [])
      ?.find(x => [x.properties.nome, x.properties.nome_oficial].some(n => BairrosDoNomeChave(n).includes(alvo)))
  if (!f) return false
  mapaState.obj.fitBounds(L.geoJSON(f).getBounds(), { padding: [30, 30] })
  _pesqSaida(`<div class="pesq-msg"><b>${esc(f.properties.nome || f.properties.nome_oficial)}</b>${
    f.properties.nome_oficial && f.properties.nome_oficial !== f.properties.nome ? ` · ${esc(f.properties.nome_oficial)}` : ''}</div>`)
  return true
}

/** Sem acento e sem caixa, para comparar nome de bairro digitado. */
function BairrosDoNomeChave(n) {
  return String(n || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim()
}

// ── COORDENADA ───────────────────────────────────────────────

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
  mapaState.obj.setView([lat, lon], 19)
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
