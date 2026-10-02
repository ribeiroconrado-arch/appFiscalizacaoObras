/**
 * SINALIZAÇÃO — o aviso rápido sobre um imóvel, e o lembrete de revistoria.
 *
 * Nasce em dois toques (balão do lote ou ficha → "Sinalizar" → o que se viu),
 * fica PENDENTE como bandeira no mapa, aviso na ficha e item do "Para hoje" do
 * Painel, e se RESOLVE de dois jeitos: sozinha, quando alguém registra uma
 * vistoria no lote (o formulário traz as pendências marcadas), ou com um toque
 * em "Resolvido" e uma palavra. Resolvida, vai para o Histórico do imóvel.
 *
 * O servidor decide quem vê o quê (SinalizacaoController): a fiscalização vê
 * todas; quem é de fora, só as que criou.
 */

/** Ícones de LINHA, no traço do resto do sistema — um por tipo. */
const SIN_ICONES = {
  obra_sem_placa: '<rect x="4" y="4" width="16" height="10" rx="1"/><path d="M8 14v6M16 14v6M7 8h10M7 11h6"/>',
  entulho:        '<path d="M3 20h18M5 20l2-6h4l1 6M12 20l2-8h4l2 8M8 14l1-3h2"/>',
  avanco:         '<path d="M3 12h18M7 8l-4 4 4 4M17 8l4 4-4 4"/>',
  risco:          '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17v.5"/>',
  obra_iniciada:  '<path d="M4 21V9l8-5 8 5v12M9 21v-6h6v6"/>',
  pedir_vistoria: '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>',
  lembrete:       '<circle cx="12" cy="13" r="7"/><path d="M12 10v3l2 2M5 4 2 7M19 4l3 3"/>',
  outro:          '<path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/>',
  bandeira:       '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
  ok:             '<path d="M5 12l5 5L20 7"/>',
  lapis:          '<path d="M4 20h4L19 9l-4-4L4 16v4z"/>',
}
const SIN_TIPOS = [
  ['obra_sem_placa', 'Obra sem placa'], ['entulho', 'Entulho na calçada'], ['avanco', 'Avanço / muro'],
  ['risco', 'Risco'], ['obra_iniciada', 'Obra iniciada'], ['pedir_vistoria', 'Pedir vistoria'],
  ['lembrete', 'Lembrete'], ['outro', 'Outro'],
]

const sinState = {
  /** Lote do formulário aberto: {id, quadra, numero_lote}. */ lote: null,
  tipo: null,
  /** Dias do lembrete (null = data escolhida no campo). */ dias: 30,
  camada: null,
  /** Vistoria em curso: o bloco de pendências e lembrete foi montado? */ naVistoria: false,
}

const _sinSvg = (k, cls = '') => `<svg class="sin-ico ${cls}" viewBox="0 0 24 24" aria-hidden="true">${SIN_ICONES[k] || SIN_ICONES.bandeira}</svg>`
const _sinCsrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? ''

async function _sinPedir(url, opts = {}) {
  const r = await fetch(url, { ...opts, headers: { Accept: 'application/json', 'Content-Type': 'application/json',
    'X-CSRF-TOKEN': _sinCsrf(), ...(opts.headers || {}) } })
  let d = {}
  try { d = await r.json() } catch { /* sem corpo */ }
  if (!r.ok) throw new Error((d.errors && Object.values(d.errors)[0]?.[0]) || d.message || 'Falha ao falar com o servidor.')
  return d
}

/** Data local aaaa-mm-dd daqui a `dias`. */
function _sinDataDaqui(dias) {
  const d = new Date(); d.setDate(d.getDate() + dias)
  return d.toISOString().slice(0, 10)
}

// ── 1 · NASCE: o formulário "O que você viu?" ───────────────

function _sinModal() {
  let m = document.getElementById('m-sinalizar')
  if (m) return m
  m = document.createElement('div')
  m.className = 'modal-bg'; m.id = 'm-sinalizar'
  m.innerHTML = `<div class="modal sin-modal" onclick="event.stopPropagation()">
      <button class="modal-x" onclick="fModalBtn('m-sinalizar')">&#10005;</button>
      <h3>${_sinSvg('bandeira')}<span id="sin-titulo">Sinalizar</span></h3>
      <div id="sin-corpo"></div>
    </div>`
  document.body.appendChild(m)
  return m
}

/** @param {{id:number, quadra?:string, numero_lote?:string}} lote */
async function abrirSinalizar(lote) {
  if (!lote?.id) { toast('Escolha o imóvel primeiro.', 'err'); return }
  sinState.lote = lote; sinState.tipo = null; sinState.dias = 30; sinState.jaExistem = []
  _sinModal()
  // O formulário guarda o comentário entre repinturas; uma sinalização nova começa vazia.
  const c = document.getElementById('sin-comentario'); if (c) c.value = ''
  document.getElementById('sin-titulo').textContent = `Sinalizar · Q ${lote.quadra ?? '—'} · Lote ${lote.numero_lote ?? '—'}`
  _sinPintarForm()
  openModal('m-sinalizar')
  // O que JÁ está sinalizado no lote (de qualquer pessoa, só tipo e data):
  // é o que permite avisar antes de repetir.
  try {
    sinState.jaExistem = (await _sinPedir(`/api/lotes/${lote.id}/sinalizacoes`)).ja_existem || []
    _sinPintarForm()
  } catch { /* sem a lista, o servidor avisa ao enviar */ }
}

function sinalizarDaFicha() {
  const p = state.selecionado?.properties
  if (!p?.id) { toast('Abra a ficha de um imóvel.', 'err'); return }
  abrirSinalizar(p)
}

function _sinPintarForm() {
  const lembrete = sinState.tipo === 'lembrete'
  const comentario = document.getElementById('sin-comentario')?.value ?? ''
  const ja = (sinState.jaExistem || []).find(x => x.tipo === sinState.tipo)
  const jaTipos = new Set((sinState.jaExistem || []).map(x => x.tipo))
  document.getElementById('sin-corpo').innerHTML = `
    <div class="sin-sub">O que você viu?</div>
    <div class="sin-grade">${SIN_TIPOS.map(([k, t]) => `
      <button type="button" class="sin-op${sinState.tipo === k ? ' on' : ''}${jaTipos.has(k) ? ' ja' : ''}"
              onclick="sinState.tipo='${k}';sinState.duplicada=false;_sinPintarForm()">
        ${_sinSvg(k)}<span>${esc(t)}</span>${jaTipos.has(k) ? '<i class="sin-ja-marca" title="Já sinalizado">•</i>' : ''}</button>`).join('')}</div>
    ${ja ? `<div class="sin-aviso">${_sinSvg('bandeira')}<span><b>Já sinalizado:</b> este imóvel tem "${esc(ja.rotulo)}"
      pendente desde ${esc(ja.desde)}. Não é preciso enviar de novo — a fiscalização já foi avisada.</span></div>` : ''}
    ${lembrete ? `<div class="sin-sub">Lembrar em</div>${_sinChipsLembrete('sin')}` : ''}
    <div class="field"><label for="sin-comentario">Comentário (opcional)</label>
      <input type="text" id="sin-comentario" maxlength="500" placeholder="${lembrete ? 'Conferir retirada do entulho' : 'Fundação começando, sem placa'}"></div>
    <div class="btn-row"><button class="btn" onclick="fModalBtn('m-sinalizar')">Cancelar</button>
      <button class="btn ${ja ? '' : 'primary'}" id="sin-enviar" ${sinState.tipo ? '' : 'disabled'}
        onclick="enviarSinalizacao(${ja ? 'true' : 'false'})">${ja ? 'Enviar mesmo assim' : 'Enviar'}</button></div>`
  const c = document.getElementById('sin-comentario'); if (c) c.value = comentario
}

/** Os atalhos do lembrete — os mesmos no formulário e no fim da vistoria. */
function _sinChipsLembrete(prefixo, comNao = false) {
  const op = [...(comNao ? [[0, 'Não']] : []), [15, '15 dias'], [30, '30 dias'], [60, '60 dias'], [90, '90 dias']]
  return `<div class="sin-chips">${op.map(([d, t]) =>
    `<button type="button" class="sin-chip${sinState.dias === d ? ' on' : ''}" onclick="_sinEscolherDias('${prefixo}', ${d})">${t}</button>`).join('')}
    <input type="date" class="sin-data" id="${prefixo}-data" min="${_sinDataDaqui(1)}"
      ${sinState.dias === null ? '' : ''} onchange="sinState.dias=null;_sinRealcarChips('${prefixo}')"></div>`
}
function _sinEscolherDias(prefixo, d) {
  sinState.dias = d
  const campo = document.getElementById(prefixo + '-data'); if (campo) campo.value = ''
  _sinRealcarChips(prefixo)
}
function _sinRealcarChips(prefixo) {
  const caixa = document.getElementById(prefixo + '-data')?.closest('.sin-chips')
  caixa?.querySelectorAll('.sin-chip').forEach(b => {
    const d = Number(b.getAttribute('onclick').match(/, (\d+)\)/)[1])
    b.classList.toggle('on', sinState.dias === d)
  })
}
/** A data do lembrete escolhida, ou null ("Não"). */
function _sinDataEscolhida(prefixo) {
  if (sinState.dias === 0) return null
  if (sinState.dias === null) return document.getElementById(prefixo + '-data')?.value || null
  return _sinDataDaqui(sinState.dias)
}

/** @param {boolean} confirmada o usuário já viu o aviso de "já sinalizado" */
async function enviarSinalizacao(confirmada = false) {
  if (!sinState.tipo || !sinState.lote) return
  const btn = document.getElementById('sin-enviar'); btn.disabled = true
  try {
    const r = await fetch(`/api/lotes/${sinState.lote.id}/sinalizacoes`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _sinCsrf() },
      body: JSON.stringify({
        tipo: sinState.tipo,
        comentario: document.getElementById('sin-comentario').value.trim() || null,
        lembrar_em: sinState.tipo === 'lembrete' ? _sinDataEscolhida('sin') : null,
        confirmar_duplicada: confirmada,
      }),
    })
    const d = await r.json().catch(() => ({}))
    // O servidor achou a mesma sinalização pendente (a lista da tela pode
    // estar velha): mostra o aviso e deixa a decisão com quem sinaliza.
    if (r.status === 409 && d.duplicada) {
      sinState.jaExistem = [...(sinState.jaExistem || []), { tipo: sinState.tipo,
        rotulo: SIN_TIPOS.find(t => t[0] === sinState.tipo)?.[1], desde: (d.message.match(/desde (\S+),/) || [])[1] || '—' }]
      _sinPintarForm(); return
    }
    if (!r.ok) throw new Error((d.errors && Object.values(d.errors)[0]?.[0]) || d.message || 'Falha ao enviar.')
    fModalBtn('m-sinalizar'); toast(d.message)
    recarregarSinalizacoes()
    if (document.getElementById('m-ficha')?.classList.contains('open') && typeof carregarHistorico === 'function') {
      carregarHistorico(sinState.lote.id)
    }
  } catch (e) { toast(e.message, 'err'); btn.disabled = false }
}

// ── 2 · FICA PENDENTE: bandeiras no mapa ─────────────────────

registrarCamada({
  id: 'sinalizacoes', grupo: 'Outras', rotulo: 'Sinalizações e lembretes',
  aoMudar: ligada => { ligada ? recarregarSinalizacoes() : sinState.camada?.clearLayers() },
})

let _sinTimer = null
function recarregarSinalizacoes() {
  clearTimeout(_sinTimer)
  _sinTimer = setTimeout(_sinCarregar, 250)
}

async function _sinCarregar() {
  const mapa = mapaState?.obj
  if (!mapa || !camadaLigada('sinalizacoes')) return
  if (!sinState.camada) {
    if (!mapa.getPane('sinalizacoes')) mapa.createPane('sinalizacoes').style.zIndex = 630
    sinState.camada = L.layerGroup([], { pane: 'sinalizacoes' }).addTo(mapa)
  }
  if (mapa.getZoom() < 14) { sinState.camada.clearLayers(); return }
  const b = mapa.getBounds()
  const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].map(n => n.toFixed(6)).join(',')
  try {
    const d = await _sinPedir('/api/sinalizacoes?bbox=' + bbox)
    sinState.camada.clearLayers()
    for (const s of d.sinalizacoes) {
      const lembrete = s.tipo === 'lembrete'
      L.marker([s.lat, s.lon], {
        pane: 'sinalizacoes',
        icon: L.divIcon({ className: '', iconSize: [26, 26], iconAnchor: [4, 26], popupAnchor: [9, -24],
          html: `<div class="sin-pino${lembrete ? ' lembrete' : ''}" title="${esc(s.rotulo)}">${_sinSvg(lembrete ? 'lembrete' : 'bandeira')}</div>` }),
      }).bindPopup(() => _sinBalao(s), { className: 'popup-lote', maxWidth: 280 }).addTo(sinState.camada)
    }
  } catch (e) { console.warn('sinalizações:', e.message) }
}

/** O balão da bandeira, no mesmo modelo do balão do lote (D4). */
function _sinBalao(s) {
  const lembrete = s.tipo === 'lembrete'
  const vistoriar = window.PODE_EDITAR
    ? `<button type="button" class="balao-q" onclick="vistoriarDaSinalizacao(${Number(s.lote_id)})">${_sinSvg('lapis')}Vistoriar</button>` : ''
  return `<div class="balao4">
    <div class="balao4-cab ${lembrete ? 'lembrete' : 'sinal'}">${_sinSvg(s.tipo)}<b>${esc(s.rotulo)}</b></div>
    <div class="balao4-corpo">
      <table class="balao4-tab">
        <tr><td>Imóvel</td><td>${esc(s.imovel)}</td></tr>
        <tr><td>${lembrete ? 'Lembrete de' : 'Aberta'}</td><td>${esc(lembrete ? (s.lembrar_em || s.criada_em) : s.ha + ' · ' + s.criada_em)}</td></tr>
        <tr><td>Por</td><td>${esc(s.autor)}</td></tr>
        ${s.comentario ? `<tr><td>Comentário</td><td class="balao4-quebra">${esc(s.comentario)}</td></tr>` : ''}
      </table>
      <div class="balao4-acoes" id="sin-acoes-${s.id}" style="grid-template-columns:repeat(${vistoriar ? 2 : 1},1fr)">
        ${vistoriar}
        <button type="button" class="balao-q" onclick="_sinPedirResolucao(${s.id}, 'sin-acoes-${s.id}')">${_sinSvg('ok')}Resolvido</button>
      </div>
    </div></div>`
}

function vistoriarDaSinalizacao(loteId) {
  const f = state.lotes?.get(loteId)
  if (f) state.selecionado = f
  mapaState.obj?.closePopup()
  if (typeof novaVistoria === 'function') novaVistoria()
}

// ── 3 · É RESOLVIDA: o "Resolvido" de um toque ───────────────

/** Troca os botões por um campo de uma palavra + confirmar. */
function _sinPedirResolucao(id, alvoId) {
  const alvo = document.getElementById(alvoId); if (!alvo) return
  alvo.style.gridTemplateColumns = '1fr'
  alvo.innerHTML = `<div class="sin-resolver">
      <input type="text" id="sin-res-${id}" maxlength="300" placeholder="Como foi resolvida? Ex.: placa instalada">
      <button type="button" class="btn primary sm" onclick="resolverSinalizacao(${id})">Confirmar</button></div>`
  document.getElementById('sin-res-' + id)?.focus()
}

async function resolverSinalizacao(id) {
  const resolucao = document.getElementById('sin-res-' + id)?.value.trim()
  if (!resolucao) { exigirCampo('sin-res-' + id, 'Diga em uma palavra como foi resolvida.'); return }
  try {
    const d = await _sinPedir(`/api/sinalizacoes/${id}/resolver`, { method: 'POST', body: JSON.stringify({ resolucao }) })
    toast(d.message)
    mapaState.obj?.closePopup()
    recarregarSinalizacoes()
    carregarParaHoje()
    const lote = state.selecionado?.properties?.id
    if (lote && document.getElementById('m-ficha')?.classList.contains('open')) carregarHistorico(lote)
  } catch (e) { toast(e.message, 'err') }
}

// ── O "PARA HOJE" DO PAINEL ──────────────────────────────────

async function carregarParaHoje() {
  const bloco = document.getElementById('pn-hoje-bloco')
  if (!bloco) return
  try {
    const d = await _sinPedir('/api/sinalizacoes/hoje')
    document.getElementById('pn-hoje-n').textContent = d.itens.length
    bloco.hidden = !d.itens.length
    document.getElementById('pn-hoje').innerHTML = d.itens.map(s => `
      <div class="sin-linha" id="pn-sin-${s.id}">
        <span class="sin-linha-ico ${s.tipo === 'lembrete' ? 'lembrete' : ''}">${_sinSvg(s.tipo === 'lembrete' ? 'lembrete' : 'bandeira')}</span>
        <div class="sin-linha-txt" onclick="irAoLoteNoMapa(${Number(s.lote_id)})" title="Ver no mapa">
          <b>${esc(s.tipo === 'lembrete' ? 'Voltar' : s.rotulo)}</b> · ${esc(s.imovel)}
          <div class="sin-linha-sub">${esc(s.comentario || '')}${s.comentario ? ' · ' : ''}${esc(s.ha || '')} · ${esc(s.autor)}</div>
        </div>
        <button type="button" class="sin-linha-ok" title="Resolvido" onclick="_sinPedirResolucao(${s.id}, 'pn-sin-${s.id}')">${_sinSvg('ok')}</button>
      </div>`).join('')
  } catch { bloco.hidden = true }
}

// ── A FICHA: aviso das pendentes ─────────────────────────────

function pintarAvisoSinalizacao(lista) {
  const el = document.getElementById('fi-sinal-aviso')
  if (!el) return
  el.hidden = !lista.length
  if (!lista.length) { el.innerHTML = ''; return }
  const p = lista[0]
  el.innerHTML = `<div class="sin-aviso">${_sinSvg('bandeira')}<span><b>${lista.length} sinalização(ões) pendente(s)</b>
    · ${esc(p.rotulo)}${p.comentario ? ' — "' + esc(p.comentario) + '"' : ''} · ${esc(p.autor || '—')}, ${esc(p.criada_em || '')}</span></div>`
}

// ── NA VISTORIA: o que ela atende e o lembrete de voltar ─────

async function pintarSinalNaVistoria(loteId) {
  const alvo = document.getElementById('nv-sinal')
  if (!alvo) return
  sinState.naVistoria = false
  if (!loteId) { alvo.innerHTML = ''; return }
  sinState.dias = 0
  let pendentes = []
  try { pendentes = (await _sinPedir(`/api/lotes/${loteId}/sinalizacoes`)).pendentes } catch { /* sem a lista, o servidor resolve todas */ }
  alvo.innerHTML = `
    ${pendentes.length ? `<div class="sec-title">Sinalizações deste imóvel</div>
      ${pendentes.filter(s => s.tipo !== 'lembrete' || !s.lembrar_em || s.lembrar_em <= new Date().toLocaleDateString('pt-BR')).map(s => `
        <label class="sin-check"><input type="checkbox" class="nv-sinal-res" value="${s.id}" checked>
          Resolver "${esc(s.rotulo)}"${s.comentario ? ' — ' + esc(s.comentario) : ''} <span class="sin-linha-sub">(${esc(s.criada_em)})</span></label>`).join('')}` : ''}
    <div class="sec-title">Lembrar de voltar neste imóvel?</div>
    ${_sinChipsLembrete('nv-lembrete', true)}
    <div class="field"><input type="text" id="nv-lembrete-motivo" maxlength="300" placeholder="Motivo (opcional) — ex.: conferir retirada do entulho"></div>`
  sinState.naVistoria = true
}

/** @param {FormData} fd */
function anexarSinalNaVistoria(fd) {
  if (!sinState.naVistoria) return   // bloco não montado: o servidor resolve todas
  fd.append('sinalizacoes_enviadas', '1')
  document.querySelectorAll('.nv-sinal-res:checked').forEach(c => fd.append('resolver_sinalizacoes[]', c.value))
  const data = _sinDataEscolhida('nv-lembrete')
  if (data) {
    fd.append('lembrar_em', data)
    const motivo = document.getElementById('nv-lembrete-motivo')?.value.trim()
    if (motivo) fd.append('lembrete_motivo', motivo)
  }
  sinState.naVistoria = false
}

// O mapa nasce no bootstrap (app.js); as bandeiras acompanham o movimento dele.
window.addEventListener('load', () => {
  const mapa = mapaState?.obj
  if (!mapa) return
  mapa.on('moveend', recarregarSinalizacoes)
  recarregarSinalizacoes()
})
