/**
 * A ORIGEM NA FICHA E A TELA DO ATO (20/11/2026).
 *
 * - Ficha › Dados: o cartão "Origem" (ou "Destino", na mãe) com o desenho
 *   pequeno do ato e o botão "Abrir o ato". Substitui a linha "Veio de".
 * - A tela do ato: a mãe tracejada e as partes coloridas, as listas, o
 *   histórico do ato e, para a curadoria, a troca de identidades.
 *   Abre pela ficha e pela tela Histórico (historico-atos.js).
 *
 * A troca continua a do servidor (TrocaDeIdentidades): a posição i recebe a
 * i-ésima identidade, ordenadas pelo sufixo da inscrição. Depois de trocar, o
 * mapa lê os lotes de novo e a ficha aberta é recarregada — antes nada disso
 * acontecia, e não dava para saber se a troca tinha dado certo.
 * Servidor: AtosDoCadastro (leitura), IdentidadesController (troca).
 */

/** @type {{dados:any, destino:?Object<number,number>, msg:?string}|null} */
let _ato = null

const COR_DAS_PARTES = ['#2563EB', '#0F766E', '#C2410C', '#7C3AED', '#BE185D', '#A16207', '#4D7C0F', '#0E7490']

/** Pedidos do ato por lote: a ficha não pergunta duas vezes. */
const _atoDoLote = new Map()

function _pedirAtoDoLote(loteId) {
  if (!_atoDoLote.has(loteId)) {
    _atoDoLote.set(loteId, fetch(`/api/lotes/${loteId}/ato`, { headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : null)).catch(() => null))
  }
  return _atoDoLote.get(loteId)
}

const _m2 = n => fmtBR(Number(n) || 0) + ' m²'
const _lista = l => l.length <= 1 ? (l[0] ?? '') : l.slice(0, -1).join(', ') + ' e ' + l[l.length - 1]

/* ── O desenho do ato (SVG) ─────────────────────────────────────────── */

/**
 * Desenha os lotes do ato num SVG: a mãe (e o terreno) tracejada, as partes
 * coloridas com o número. Projeção plana local: em um lote, a curvatura não
 * aparece.
 * @param {any} d o ato @param {{largura:number, altura:number, destaque?:number, rotulos?:boolean, rotuloDe?:(p:any)=>string}} o
 */
function _svgDoAto(d, o) {
  const todos = [...d.anteriores, ...d.terreno, ...d.posteriores].filter(l => l.geometria)
  const aneis = l => (l.geometria.type === 'Polygon' ? [l.geometria.coordinates] : l.geometria.coordinates).map(p => p[0])
  const pts = todos.flatMap(l => aneis(l).flat())
  if (!pts.length) return ''
  const lat0 = pts.reduce((s, p) => s + p[1], 0) / pts.length
  const k = Math.cos(lat0 * Math.PI / 180)
  const xs = pts.map(p => p[0] * k), ys = pts.map(p => -p[1])
  const [x0, x1, y0, y1] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)]
  const pad = o.largura < 120 ? 4 : 10
  const esc_ = Math.min((o.largura - 2 * pad) / (x1 - x0 || 1), (o.altura - 2 * pad) / (y1 - y0 || 1))
  const ox = (o.largura - (x1 - x0) * esc_) / 2, oy = (o.altura - (y1 - y0) * esc_) / 2
  const xy = p => [ox + (p[0] * k - x0) * esc_, oy + (-p[1] - y0) * esc_]
  const caminho = l => aneis(l).map(a => 'M' + a.map(p => xy(p).map(v => v.toFixed(1)).join(' ')).join('L') + 'Z').join('')
  const centro = l => {
    const a = aneis(l)[0].map(xy)
    return [a.reduce((s, p) => s + p[0], 0) / a.length, a.reduce((s, p) => s + p[1], 0) / a.length]
  }

  let svg = ''
  d.posteriores.forEach((l, i) => {
    if (!l.geometria) return
    const cor = COR_DAS_PARTES[i % COR_DAS_PARTES.length]
    const apagada = o.destaque != null && o.destaque !== l.id
    svg += `<path d="${caminho(l)}" fill="${apagada ? 'var(--sur2, #eee)' : cor}" fill-opacity="${apagada ? 1 : .3}"
      stroke="${apagada ? 'var(--bord, #ccc)' : cor}" stroke-width="${o.destaque === l.id ? 2 : 1.5}" fill-rule="evenodd"/>`
  })
  for (const l of [...d.anteriores, ...d.terreno]) {
    if (l.geometria) svg += `<path d="${caminho(l)}" fill="none" stroke="currentColor" stroke-opacity=".7" stroke-width="1.5" stroke-dasharray="5 3"/>`
  }
  if (o.rotulos !== false) {
    for (const l of d.posteriores) {
      if (!l.geometria) continue
      const [cx, cy] = centro(l)
      const pequeno = o.largura < 120
      // Na troca, o número NOVO, em destaque: "4A → 4B" não cabe num lote estreito.
      const novo = o.rotuloDe ? o.rotuloDe(l) : null
      svg += `<text x="${cx.toFixed(1)}" y="${(cy + (pequeno ? 4 : 0)).toFixed(1)}" text-anchor="middle" class="ato-svg-num${pequeno ? ' peq' : ''}${novo && novo !== l.numero ? ' muda' : ''}">${esc(novo ?? l.numero)}</text>`
      // A área só cabe em parte larga: em lote estreito ela invadiria o vizinho.
      const largura = (a => Math.max(...a.map(p => p[0])) - Math.min(...a.map(p => p[0])))(aneis(l)[0].map(xy))
      if (!pequeno && largura >= 64) svg += `<text x="${cx.toFixed(1)}" y="${(cy + 15).toFixed(1)}" text-anchor="middle" class="ato-svg-area">${esc(_m2(l.area))}</text>`
    }
  }
  return `<svg viewBox="0 0 ${o.largura} ${o.altura}" width="100%" class="ato-svg" role="img" aria-label="Desenho do ato">${svg}</svg>`
}

/* ── Ficha › Dados: o cartão Origem ─────────────────────────────────── */

/**
 * Preenche a procedência na aba Dados. Sem origem nem destino, some.
 * @param {{origens:any[], destinos:any[]}|null} p
 * @param {number} loteId
 */
function renderProcedencia(p, loteId) {
  const el = document.getElementById('fi-procedencia')
  if (!el) { return }
  const origens = p?.origens ?? []
  const destinos = p?.destinos ?? []
  if (!origens.length && !destinos.length) {
    el.hidden = true
    el.innerHTML = ''
    return
  }
  const lado = origens.length ? origens : destinos
  const x = lado[0]
  const comoParte = !!origens.length
  const rot = x.tipo === 'unificacao' ? 'Unificação' : x.tipo === 'fracao_ideal' ? 'Fração ideal' : 'Desmembramento'
  const lotes = _lista(lado.map(o => o.rotulo.replace(/^Quadra .* · Lote /, '')))
  const quadra = (x.rotulo.match(/^Quadra (\S+)/) || [])[1] || '—'
  const titulo = comoParte
    ? (x.tipo === 'unificacao' ? `Unificação dos lotes ${lotes} · quadra ${quadra}` : `Desmembrado do lote ${lotes} · quadra ${quadra}`)
    : (x.tipo === 'unificacao' ? `Unificado em ${lotes} · quadra ${quadra}` : `Desmembrado em ${lotes} · quadra ${quadra}`)
  const quando = [x.data, x.protocolo ? 'protocolo' : 'ato direto'].filter(Boolean).join(' · ')

  el.innerHTML = `
    <div class="ato-origem">
      <div class="ato-origem-mini" id="ato-origem-mini" aria-hidden="true"></div>
      <div class="ato-origem-txt">
        <span class="ato-k">${comoParte ? 'Origem' : 'Destino'} · ${esc(rot)}</span>
        <b>${esc(titulo)}</b>
        <small id="ato-origem-sub">${esc(quando)}</small>
      </div>
      <button type="button" class="btn sm" onclick="abrirAtoDoLote(${loteId})">Abrir o ato ›</button>
    </div>`
  el.hidden = false

  // O desenho e as partes chegam depois, pelo ato.
  _pedirAtoDoLote(loteId).then(d => {
    if (!d || !document.getElementById('ato-origem-mini')) return
    document.getElementById('ato-origem-mini').innerHTML = _svgDoAto(d, { largura: 78, altura: 60, destaque: comoParte ? loteId : null, rotulos: false })
    const partes = d.posteriores.map(q => q.id === loteId ? `${q.numero} (este)` : q.numero)
    document.getElementById('ato-origem-sub').textContent = [x.data, d.direto ? 'ato direto' : 'protocolo ' + (d.protocolo ?? '—'),
      d.posteriores.length > 1 ? `em ${d.posteriores.length} partes: ${_lista(partes)}` : null].filter(Boolean).join(' · ')
  })
}

/* ── A tela do ato ──────────────────────────────────────────────────── */

/** O modal mora na ficha; a tela Histórico abre sem ficha, então ele vai para o corpo da página. */
function _modalDoAto() {
  const m = document.getElementById('m-identidades')
  if (m && m.parentElement !== document.body) document.body.appendChild(m)
  return m
}

async function abrirAtoDoLote(loteId) {
  _atoDoLote.delete(loteId)
  return _abrirAto(() => _pedirAtoDoLote(loteId))
}

async function abrirAto(atoId) {
  return _abrirAto(() => fetch(`/api/atos/${atoId}`, { headers: { Accept: 'application/json' } }).then(r => r.json().then(j => (r.ok ? j : { erro: j.message }))))
}

async function _abrirAto(pedir) {
  const m = _modalDoAto()
  const corpo = document.getElementById('m-identidades-corpo')
  m.classList.add('open')
  corpo.innerHTML = '<div class="vazio-msg">Carregando o ato…</div>'
  try {
    const d = await pedir()
    if (!d || d.erro) {
      corpo.innerHTML = `<div class="ident-erro">${esc(d?.erro || 'Não foi possível abrir o ato.')}</div>`
      return
    }
    _ato = { dados: d, destino: null, msg: _ato?.dados?.id === d.id ? _ato.msg : null }
    pintarAto()
  } catch (e) {
    console.error(e)
    corpo.innerHTML = '<div class="ident-erro">Falha de rede ao abrir o ato.</div>'
  }
}

function fecharIdentidades() {
  _ato = null
  fModalBtn('m-identidades')
}

/** Partes ativas que podem trocar, ordenadas pelo sufixo (a ordem das identidades). */
function _partesDaTroca() {
  return _ato.dados.posteriores.filter(p => p.situacao === 'ativo').sort((a, b) => a.sufixo - b.sufixo || a.id - b.id)
}

/** O que a parte passa a ser no modo de troca: a identidade escolhida. */
function _identidadeNova(p) {
  if (!_ato.destino) return null
  const id = _ato.destino[p.id]
  return id != null ? _ato.dados.posteriores.find(x => x.id === id) : null
}

function pintarAto() {
  const d = _ato.dados
  const corpo = document.getElementById('m-identidades-corpo')
  const trocando = !!_ato.destino
  const anteriores = d.anteriores.map(l => l.numero)
  const cab = d.tipo === 'unificacao' ? `Unificação · lotes ${_lista(anteriores)}, quadra ${d.quadra ?? '—'}`
    : d.tipo === 'fracao_ideal' ? `Fração ideal · quadra ${d.quadra ?? '—'}`
    : `Desmembramento · lote ${_lista(anteriores)}, quadra ${d.quadra ?? '—'}`
  const sub = [d.bairro, d.direto ? 'ato direto' : 'protocolo ' + (d.protocolo ?? '—'), d.data, d.usuario ? 'por ' + d.usuario : null].filter(Boolean)

  const linhaLote = (l, i, papel) => {
    const v = l.vinculos
    const vinc = [v.documentos && `${v.documentos} documento${v.documentos > 1 ? 's' : ''}`,
      v.vistorias && `${v.vistorias} vistoria${v.vistorias > 1 ? 's' : ''}`, v.obras && `${v.obras} obra${v.obras > 1 ? 's' : ''}`].filter(Boolean)
    const nova = papel === 'parte' ? _identidadeNova(l) : null
    const mudou = nova && nova.id !== l.id
    const marca = papel === 'parte'
      ? `<i style="background:${COR_DAS_PARTES[i % COR_DAS_PARTES.length]}"></i>`
      : '<i class="ato-tracejado"></i>'
    let escolha = ''
    if (trocando && papel === 'parte' && _partesDaTroca().length > 2) {
      escolha = `<label class="ato-escolha">passa a ser
        <select onchange="escolherIdentidade(${l.id}, Number(this.value))">${_partesDaTroca().map(x =>
          `<option value="${x.id}" ${(_ato.destino[l.id] ?? l.id) === x.id ? 'selected' : ''}>${esc(x.numero)} · ${esc(x.inscricao ?? '—')}</option>`).join('')}
        </select></label>`
    }
    return `<div class="ato-lote${mudou ? ' muda' : ''}">${marca}
      <div class="ato-lote-txt">
        <b>Lote ${esc(l.numero)}</b>${mudou ? ` <span class="ato-seta">→ ${esc(nova.numero)}</span>` : ''} · ${esc((mudou ? nova : l).inscricao ?? 'sem inscrição')}
        <small>${papel === 'parte' ? _m2(l.area) : _m2(l.area_no_ato ?? l.area) + (l.situacao === 'ativo' ? ' · ativo' : ' · inativo desde o ato')}${vinc.length ? ' · ' + vinc.join(', ') : ''}</small>
        ${escolha}
      </div></div>`
  }

  const podeTrocar = d.pode_trocar && d.troca && !d.troca.bloqueio
  const partes = _partesDaTroca()
  let acoes = `<button type="button" class="btn sm" onclick="verAtoNoMapa()">Ver no mapa</button>`
  if (podeTrocar && !trocando) {
    acoes = (partes.length === 2
      ? `<button type="button" class="btn sm" onclick="comecarTroca()">Trocar ${esc(partes[0].numero)} ↔ ${esc(partes[1].numero)}</button>`
      : `<button type="button" class="btn sm" onclick="comecarTroca()">Trocar identidades</button>`) + acoes
  }

  let caixaTroca = ''
  if (trocando) {
    const muda = partes.filter(p => (_ato.destino[p.id] ?? p.id) !== p.id)
    caixaTroca = `
      <div class="ato-troca">
        <b>Como fica:</b> ${muda.length ? muda.map(p => `o desenho do <b>${esc(p.numero)}</b> passa a ser o <b>${esc(_identidadeNova(p).numero)}</b> (${esc(_identidadeNova(p).inscricao ?? '—')})`).join('; ') + '.' : 'nada muda ainda — escolha a identidade de cada parte.'}
        O desenho, a área e os vínculos de cada parte ficam onde estão.
        <textarea id="ato-motivo" rows="2" maxlength="500" placeholder="Motivo (obrigatório): placa e vistoria mostram a parte da esquerda como ${esc(partes[1]?.numero ?? '')}">${esc(_ato.motivo ?? '')}</textarea>
        <div class="ato-troca-acoes">
          <button type="button" class="btn sm" onclick="cancelarTroca()">Cancelar</button>
          <button type="button" class="btn sm primary" id="ato-btn-trocar" onclick="aplicarTroca()">Trocar e mostrar no mapa</button>
        </div>
        <div id="ato-troca-erro"></div>
      </div>`
  }

  corpo.innerHTML = `
    <div class="ato-cab">
      <div><h3 class="ident-titulo">${esc(cab)}</h3><p class="ident-explica">${esc(sub.join(' · '))}</p></div>
    </div>
    ${_ato.msg ? `<div class="ident-ok">${esc(_ato.msg)}</div>` : ''}
    <div class="ato-grade">
      <div class="ato-desenho">
        ${_svgDoAto(d, { largura: 220, altura: 180, rotuloDe: trocando ? (l => _identidadeNova(l)?.numero ?? l.numero) : null })}
        <small>Tracejado: ${d.tipo === 'unificacao' ? 'os lotes unificados' : 'o lote de origem (mãe)'}, hoje ${d.anteriores.every(l => l.situacao !== 'ativo') ? 'inativo' : 'ativo'}.</small>
      </div>
      <div class="ato-listas">
        <span class="ato-k">${d.tipo === 'unificacao' ? 'Lotes unificados' : 'Mãe'}</span>
        ${d.anteriores.map((l, i) => linhaLote(l, i, 'mae')).join('')}
        ${d.terreno.length ? '<span class="ato-k">Terreno</span>' + d.terreno.map((l, i) => linhaLote(l, i, 'mae')).join('') : ''}
        <span class="ato-k">${d.tipo === 'unificacao' ? 'Resultado' : 'Partes'}</span>
        ${d.posteriores.map((l, i) => linhaLote(l, i, 'parte')).join('')}
        ${caixaTroca}
        ${trocando ? '' : `<div class="ato-acoes">${acoes}</div>`}
        ${d.pode_trocar && d.troca?.bloqueio ? `<small class="ato-dica">${esc(d.troca.bloqueio)}</small>` : ''}
      </div>
    </div>
    ${d.observacao ? `<div class="ato-obs"><span class="ato-k">Justificativa</span>${esc(d.observacao)}</div>` : ''}
    <div class="ato-hist">
      <span class="ato-k">Histórico do ato</span>
      ${d.historico.map(h => `<div class="ato-hist-item${h.tipo === 'troca' ? ' troca' : ''}"><b>${esc(h.titulo)}</b>
        <small>${esc([h.quando, h.usuario].filter(Boolean).join(' · '))}${h.motivo && h.tipo === 'troca' ? ' · “' + esc(h.motivo) + '”' : ''}</small></div>`).join('')}
    </div>`
}

function comecarTroca() {
  const partes = _partesDaTroca()
  _ato.destino = Object.fromEntries(partes.map(p => [p.id, p.id]))
  _ato.msg = null
  // Duas partes: a troca é uma só, já proposta.
  if (partes.length === 2) { _ato.destino[partes[0].id] = partes[1].id; _ato.destino[partes[1].id] = partes[0].id }
  pintarAto()
  document.getElementById('ato-motivo')?.focus()
}

function cancelarTroca() { _ato.destino = null; _ato.motivo = null; pintarAto() }

/** Com 3 partes ou mais: a parte `id` passa a ser a identidade de `alvo`; quem a tinha fica com a dela. */
function escolherIdentidade(id, alvo) {
  _ato.motivo = document.getElementById('ato-motivo')?.value ?? ''
  const dest = _ato.destino
  const quemTinha = Object.keys(dest).find(k => dest[k] === alvo)
  if (quemTinha != null) dest[quemTinha] = dest[id]
  dest[id] = alvo
  pintarAto()
}

async function aplicarTroca() {
  const motivo = (document.getElementById('ato-motivo')?.value ?? '').trim()
  const erro = document.getElementById('ato-troca-erro')
  const partes = _partesDaTroca()
  if (partes.every(p => _ato.destino[p.id] === p.id)) { erro.innerHTML = '<div class="ident-erro">Nada muda: escolha a identidade de cada parte.</div>'; return }
  if (motivo.length < 10) { erro.innerHTML = '<div class="ident-erro">Escreva o motivo da troca (10 caracteres ou mais).</div>'; return }

  // A posição i recebe a i-ésima identidade (sufixo): quem fica com a identidade de partes[i].
  const ordem = partes.map(alvo => Number(Object.keys(_ato.destino).find(k => _ato.destino[k] === alvo.id)))
  const loteId = partes[0].id
  const bt = document.getElementById('ato-btn-trocar')
  bt.disabled = true
  bt.textContent = 'Trocando…'
  try {
    const r = await fetch(`/api/lotes/${loteId}/identidades`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: JSON.stringify({ ordem, motivo }),
    })
    const j = await r.json().catch(() => ({}))
    if (!r.ok) throw new Error(j.errors ? Object.values(j.errors).flat().join(' ') : (j.message || 'Não foi possível trocar.'))

    toast(j.message || 'Identidades trocadas.')
    _ato.msg = 'Identidades trocadas. O mapa foi atualizado.'
    _ato.motivo = null
    partes.forEach(p => _atoDoLote.delete(p.id))
    await abrirAto(_ato.dados.id)
    await _depoisDaTroca(partes.map(p => p.id))
  } catch (e) {
    erro.innerHTML = `<div class="ident-erro">${esc(e.message)}</div>`
    bt.disabled = false
    bt.textContent = 'Trocar e mostrar no mapa'
  }
}

/** O mapa lê os lotes de novo; a ficha aberta de uma das partes é refeita com o número novo. */
async function _depoisDaTroca(ids) {
  if (typeof recarregarLotesDoMapa === 'function' && mapaState?.obj) await recarregarLotesDoMapa()
  const aberta = state.selecionado?.properties?.id
  if (aberta && ids.includes(aberta) && document.getElementById('m-ficha')?.classList.contains('open')) {
    const f = state.lotes?.get(aberta)
    if (f) abrirFicha(f)
    else if (typeof carregarHistorico === 'function') carregarHistorico(aberta)
  }
  _destacarAtoNoMapa(false)
}

/* ── Ver no mapa ────────────────────────────────────────────────────── */

let _realceDoAto = null

/** Enquadra o ato e mostra as partes com o número por alguns segundos. */
function _destacarAtoNoMapa(enquadrar) {
  const mapa = mapaState?.obj
  const d = _ato?.dados
  if (!mapa || !d) return
  _realceDoAto?.remove()
  const feicoes = d.posteriores.filter(l => l.geometria).map((l, i) => ({ type: 'Feature', geometry: l.geometria, properties: { numero: l.numero, i } }))
  if (!feicoes.length) return
  _realceDoAto = L.geoJSON({ type: 'FeatureCollection', features: feicoes }, {
    interactive: false,
    style: f => ({ color: COR_DAS_PARTES[f.properties.i % COR_DAS_PARTES.length], weight: 3, fillOpacity: 0.25 }),
    onEachFeature: (f, l) => l.bindTooltip(esc(f.properties.numero), { permanent: true, direction: 'center', className: 'ato-realce-rot' }),
  }).addTo(mapa)
  if (enquadrar) mapa.fitBounds(_realceDoAto.getBounds(), { padding: [60, 60], maxZoom: 20 })
  const este = _realceDoAto
  setTimeout(() => { este.remove(); if (_realceDoAto === este) _realceDoAto = null }, 8000)
}

function verAtoNoMapa() {
  const d = _ato?.dados
  if (!d) return
  fModalBtn('m-identidades')
  if (document.getElementById('m-ficha')?.classList.contains('open')) fModalBtn('m-ficha')
  if (!document.getElementById('t-mapa')?.classList.contains('at')) irPara('mapa')
  setTimeout(() => _destacarAtoNoMapa(true), 150)
}
