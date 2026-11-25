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
  // Obras
  obra_iniciada:      '<path d="M4 21V9l8-5 8 5v12M9 21v-6h6v6"/>',
  invasao_recuo:      '<path d="M3 20h18M6 20V9h8v11M14 13h6M17 10l3 3-3 3"/>',
  entulho:            '<path d="M3 20h18M5 20l2-6h4l1 6M12 20l2-8h4l2 8M8 14l1-3h2"/>',
  asseio_via:         '<path d="M3 20h18M7 20c0-3 2-5 5-5s5 2 5 5M9 11c0-1.5 1-3 3-3M15 7l2-3M19 9l2-1"/>',
  // Posturas
  obstrucao_calcada:  '<path d="M3 20h18M3 16h18M7 16V9h4v7M15 16v-4h3v4"/>',
  veiculo_abandonado: '<path d="M4 16V12l2-5h12l2 5v4z"/><circle cx="8" cy="17" r="1.8"/><circle cx="16" cy="17" r="1.8"/><path d="M4 12h16"/>',
  aguas_servidas:     '<path d="M12 3s-5 6-5 9.5a5 5 0 0 0 10 0C17 9 12 3 12 3z"/><path d="M3 21c2-1 4-1 6 0s4 1 6 0 4-1 6 0"/>',
  publicidade:        '<rect x="3" y="4" width="18" height="10" rx="1"/><path d="M12 14v7M8 21h8M7 8h10M7 11h6"/>',
  // Os dois
  risco:              '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17v.5"/>',
  outro:              '<path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/>',
  lembrete:           '<circle cx="12" cy="13" r="7"/><path d="M12 10v3l2 2M5 4 2 7M19 4l3 3"/>',
  bandeira:           '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
  ok:                 '<path d="M5 12l5 5L20 7"/>',
  lapis:              '<path d="M4 20h4L19 9l-4-4L4 16v4z"/>',
}
/**
 * SEIS por módulo — o que cabe no balão (decisão de 24/11/2026). A mesma lista
 * está em Sinalizacao::POR_MODULO, e é o servidor quem recusa o tipo errado.
 * O lembrete não entra: é a vistoria que o deixa.
 */
const SIN_TIPOS_MODULO = {
  obras: [['obra_iniciada', 'Obra iniciada'], ['invasao_recuo', 'Invasão de recuo'], ['entulho', 'Entulho na calçada'],
    ['asseio_via', 'Asseio da via'], ['risco', 'Risco'], ['outro', 'Outro']],
  posturas: [['obstrucao_calcada', 'Obstrução de calçada'], ['veiculo_abandonado', 'Veículo abandonado'], ['aguas_servidas', 'Águas servidas'],
    ['publicidade', 'Publicidade irregular'], ['risco', 'Risco'], ['outro', 'Outro']],
}
const _sinTipos = () => SIN_TIPOS_MODULO[window.MODULO] || SIN_TIPOS_MODULO.obras

const sinState = {
  /** Lote do formulário aberto: {id, quadra, numero_lote}; null = ponto na via. */ lote: null,
  /** O ponto tocado: {lat, lng}. Com lote, onde ele tocou o lote; sem, o ponto na via. */ ponto: null,
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

/**
 * @param {{id:number, quadra?:string, numero_lote?:string}} lote
 * @param {{lat:number, lng:number}=} ponto onde o lote foi tocado (a bandeira vai ali)
 */
async function abrirSinalizar(lote, ponto) {
  if (!lote?.id) { toast('Escolha o imóvel primeiro.', 'err'); return }
  sinState.lote = lote; sinState.ponto = ponto || null; sinState.tipo = null; sinState.dias = 30; sinState.jaExistem = []
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

/**
 * Sinalizar num PONTO do mapa (toque longo, ou clique direito). Se o ponto
 * cair dentro de um lote, o servidor liga ao imóvel; na via, fica só o ponto,
 * com o lote mais próximo como referência em texto — veículo abandonado na
 * frente de uma casa não é da casa.
 * @param {{lat:number, lng:number}} ponto
 */
function abrirSinalizarNoPonto(ponto) {
  sinState.lote = null; sinState.ponto = ponto; sinState.tipo = null; sinState.dias = 30; sinState.jaExistem = []
  _sinModal()
  const c = document.getElementById('sin-comentario'); if (c) c.value = ''
  document.getElementById('sin-titulo').textContent = 'Sinalizar neste ponto'
  _sinPintarForm()
  openModal('m-sinalizar')
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
  // O QUE JÁ ESTÁ PENDENTE NO IMÓVEL aparece antes de qualquer escolha, seja
  // qual for o tipo: quem ia avisar de novo fica sabendo que já foi avisado.
  const pendentes = (sinState.jaExistem || []).length
    ? `<div class="sin-aviso">${_sinSvg('bandeira')}<span><b>Este imóvel já tem sinalização pendente:</b>
        ${sinState.jaExistem.map(x => `${esc(x.rotulo)} (desde ${esc(x.desde)})`).join(' · ')}.
        Se for a mesma coisa, o seu aviso é somado ao que existe.</span></div>` : ''
  // No ponto: diz o que vai acontecer — o servidor decide pelo lugar.
  const noPonto = !sinState.lote
    ? `<p class="sin-soma">Se o ponto cair <b>dentro de um lote</b>, a sinalização fica no imóvel. Na <b>via</b>, fica só o ponto, com o lote mais próximo como referência.</p>` : ''
  document.getElementById('sin-corpo').innerHTML = `
    ${pendentes}${noPonto}
    <div class="sin-sub">O que você viu?</div>
    <div class="sin-grade">${_sinTipos().map(([k, t]) => `
      <button type="button" class="sin-op${sinState.tipo === k ? ' on' : ''}${jaTipos.has(k) ? ' ja' : ''}"
              onclick="sinState.tipo='${k}';sinState.duplicada=false;_sinPintarForm()">
        ${_sinSvg(k)}<span>${esc(t)}</span>${jaTipos.has(k) ? '<i class="sin-ja-marca" title="Já sinalizado">•</i>' : ''}</button>`).join('')}</div>
    ${ja ? `<p class="sin-soma">"${esc(ja.rotulo)}" já está sinalizado desde ${esc(ja.desde)}. O seu aviso será
      <b>somado</b> a ele: a bandeira continua uma só e passa a mostrar quantas pessoas avisaram.</p>` : ''}
    ${lembrete ? `<div class="sin-sub">Lembrar em</div>${_sinChipsLembrete('sin')}` : ''}
    <div class="field"><label for="sin-comentario">${_sinExigeDescricao() ? `Descreva o que você viu <b>(obrigatório)</b>` : 'Comentário (opcional)'}</label>
      <input type="text" id="sin-comentario" maxlength="500" oninput="_sinConferirDescricao()"
        placeholder="${lembrete ? 'Conferir retirada do entulho' : _sinExigeDescricao() ? `Pelo menos ${SIN_DESCRICAO_MIN} caracteres` : 'O que você viu, em poucas palavras'}">
      <small class="sin-falta" id="sin-falta" hidden></small></div>
    <div class="btn-row"><button class="btn" onclick="fModalBtn('m-sinalizar')">Cancelar</button>
      <button class="btn primary" id="sin-enviar" disabled
        onclick="enviarSinalizacao()">${ja ? 'Somar meu aviso' : 'Enviar'}</button></div>`
  const c = document.getElementById('sin-comentario'); if (c) c.value = comentario
  _sinConferirDescricao()
}

/**
 * "Risco" e "Outro" não dizem o que é: exigem descrição de pelo menos
 * SIN_DESCRICAO_MIN caracteres, nos dois módulos (a mesma regra está em
 * SinalizacaoController::EXIGEM_DESCRICAO — o servidor recusa sem ela).
 */
const SIN_EXIGEM_DESCRICAO = ['risco', 'outro']
const SIN_DESCRICAO_MIN = 10
const _sinExigeDescricao = () => SIN_EXIGEM_DESCRICAO.includes(sinState.tipo)
function _sinConferirDescricao() {
  const btn = document.getElementById('sin-enviar'), aviso = document.getElementById('sin-falta')
  if (!btn) return
  const n = (document.getElementById('sin-comentario')?.value || '').trim().length
  const falta = _sinExigeDescricao() ? Math.max(0, SIN_DESCRICAO_MIN - n) : 0
  btn.disabled = !sinState.tipo || falta > 0
  if (aviso) {
    aviso.hidden = !falta
    aviso.textContent = falta === 1 ? 'Falta 1 caractere na descrição.' : falta ? `Faltam ${falta} caracteres na descrição.` : ''
  }
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

/**
 * Envia a sinalização. Se o imóvel já tem o mesmo tipo pendente, o servidor
 * SOMA o aviso ao que existe (em vez de abrir outra bandeira) e diz isso na
 * resposta — não há mais "enviar mesmo assim".
 */
async function enviarSinalizacao() {
  if (!sinState.tipo || (!sinState.lote && !sinState.ponto)) return
  const btn = document.getElementById('sin-enviar'); btn.disabled = true
  const p = sinState.ponto
  try {
    // Com lote: a rota do imóvel (o ponto, se veio, é onde a bandeira fica).
    // Só o ponto: o servidor procura o lote em que ele cai.
    const d = await _sinPedir(sinState.lote ? `/api/lotes/${sinState.lote.id}/sinalizacoes` : '/api/sinalizacoes', {
      method: 'POST',
      body: JSON.stringify({
        tipo: sinState.tipo,
        comentario: document.getElementById('sin-comentario').value.trim() || null,
        lembrar_em: sinState.tipo === 'lembrete' ? _sinDataEscolhida('sin') : null,
        lat: p ? +p.lat.toFixed(7) : null,
        lng: p ? +p.lng.toFixed(7) : null,
      }),
    })
    fModalBtn('m-sinalizar'); toast(d.message)
    recarregarSinalizacoes()
    if (typeof carregarParaHoje === 'function') carregarParaHoje()
    if (sinState.lote && document.getElementById('m-ficha')?.classList.contains('open') && typeof carregarHistorico === 'function') {
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
  // O mapa existe mas ainda não foi enquadrado (a tela abriu no Painel, por
  // exemplo): sem centro nem zoom o Leaflet não sabe os limites e lança erro.
  // As bandeiras são carregadas quando ele for enquadrado (moveend).
  if (!mapa._loaded) return
  if (mapa.getZoom() < 14) { sinState.camada.clearLayers(); return }
  const b = mapa.getBounds()
  const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].map(n => n.toFixed(6)).join(',')
  try {
    const d = await _sinPedir('/api/sinalizacoes?bbox=' + bbox)
    sinState.camada.clearLayers()
    // UMA BANDEIRA POR IMÓVEL. Todas as sinalizações do lote ficam no mesmo
    // ponto (o meio dele): desenhadas uma a uma, caíam uma em cima da outra, e
    // só a de cima abria — o imóvel com dois avisos de tipos diferentes parecia
    // ter um só. Agora a bandeira reúne as do lote, e o balão lista todas.
    // Na via cada sinalização é a sua bandeira, no ponto marcado.
    const porLote = new Map()
    for (const s of d.sinalizacoes) {
      const chave = s.lote_id ? 'l' + s.lote_id : 'p' + s.id
      if (!porLote.has(chave)) porLote.set(chave, [])
      porLote.get(chave).push(s)
    }
    for (const lista of porLote.values()) {
      const p = lista[0]
      // Azul só quando tudo ali é lembrete; havendo sinalização, a bandeira é dela.
      const lembrete = lista.every(s => s.tipo === 'lembrete')
      // O número: quantos avisos o imóvel tem ao todo (sinalizações e pessoas).
      const total = lista.reduce((n, s) => n + (s.avisos || 1), 0)
      const dica = lista.map(s => s.rotulo).join(' · ') + (total > 1 ? ' · ' + total + ' avisos' : '')
      L.marker([p.lat, p.lon], {
        pane: 'sinalizacoes',
        icon: L.divIcon({ className: '', iconSize: [26, 26], iconAnchor: [4, 26], popupAnchor: [9, -24],
          html: `<div class="sin-pino${lembrete ? ' lembrete' : ''}" title="${esc(dica)}">${_sinSvg(lembrete ? 'lembrete' : 'bandeira')}</div>${total > 1 ? `<span class="sin-pino-n">${total}</span>` : ''}` }),
      }).bindPopup(() => _sinBalaoDoLote(lista), { className: 'popup-lote', maxWidth: 280 }).addTo(sinState.camada)
    }
  } catch (e) { console.warn('sinalizações:', e.message) }
}

/**
 * O balão de um imóvel com sinalização: uma só, é o balão dela; mais de uma,
 * todas empilhadas, cada qual com o próprio cabeçalho e as próprias ações.
 * @param {Object[]} lista as sinalizações pendentes do lote
 */
function _sinBalaoDoLote(lista) {
  if (lista.length === 1) return _sinBalao(lista[0])
  return `<div class="sin-balao-grupo">${lista.map(_sinBalao).join('')}</div>`
}

/** O balão da bandeira, no mesmo modelo do balão do lote (D4). */
function _sinBalao(s) {
  const lembrete = s.tipo === 'lembrete'
  // A vistoria de obra precisa de imóvel: na via, só o "Resolvido".
  const vistoriar = window.PODE_EDITAR && s.lote_id
    ? `<button type="button" class="balao-q" onclick="vistoriarDaSinalizacao(${Number(s.lote_id)})">${_sinSvg('lapis')}Vistoriar</button>` : ''
  return `<div class="balao4">
    <div class="balao4-cab ${lembrete ? 'lembrete' : 'sinal'}">${_sinSvg(s.tipo)}<b>${esc(s.rotulo)}</b></div>
    <div class="balao4-corpo">
      <table class="balao4-tab">
        <tr><td>${s.na_rua ? 'Local' : 'Imóvel'}</td><td class="balao4-quebra">${esc(s.imovel)}</td></tr>
        <tr><td>${lembrete ? 'Lembrete de' : 'Aberta'}</td><td>${esc(lembrete ? (s.lembrar_em || s.criada_em) : s.ha + ' · ' + s.criada_em)}</td></tr>
        <tr><td>Por</td><td>${esc(s.autor)}</td></tr>
        ${s.comentario ? `<tr><td>Comentário</td><td class="balao4-quebra">${esc(s.comentario)}</td></tr>` : ''}
        ${s.avisos > 1 ? `<tr><td>Avisos</td><td><b>${s.avisos} pessoas</b> avisaram</td></tr>` : ''}
        ${(s.reforcos || []).map(r => `<tr><td>${esc(r.em || '')}</td><td class="balao4-quebra">${esc(r.autor)}${r.comentario ? ' — ' + esc(r.comentario) : ''}</td></tr>`).join('')}
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

/**
 * "Fazer vistoria" a partir do painel — o mesmo atalho do balão da bandeira.
 * No painel o lote pode ainda não estar carregado no mapa: vai até ele, espera
 * chegar e só então abre a vistoria (que resolve as sinalizações do imóvel).
 * @param {number} loteId
 */
async function fazerVistoriaDoPainel(loteId) {
  if (!state.lotes?.get(loteId)) {
    await irAoLoteNoMapa(loteId)
    for (let t = 0; t < 40 && !state.lotes?.get(loteId); t++) await new Promise(r => setTimeout(r, 250))
  }
  if (!state.lotes?.get(loteId)) { toast('Não foi possível carregar o imóvel para a vistoria.', 'err'); return }
  vistoriarDaSinalizacao(loteId)
}

// ── 3 · É RESOLVIDA: o "Resolvido" de um toque ───────────────

/** Troca os botões por um campo de uma palavra + confirmar. */
function _sinPedirResolucao(id, alvoId) {
  // A TROCA ESPERA O CLIQUE TERMINAR. Trocar o innerHTML dentro do próprio
  // clique tira o botão clicado do DOM antes de o evento acabar de subir — e
  // quem escuta "clique fora do balão" (o mapa) vê um alvo que não está mais
  // dentro de balão nenhum, e fecha o balão: o botão parecia não fazer nada.
  setTimeout(() => {
    const alvo = document.getElementById(alvoId); if (!alvo) return
    alvo.style.gridTemplateColumns = '1fr'
    alvo.innerHTML = `<div class="sin-resolver">
        <input type="text" id="sin-res-${id}" maxlength="300" placeholder="Como foi resolvida? Ex.: placa instalada"
               onkeydown="if(event.key==='Enter')resolverSinalizacao(${id})">
        <button type="button" class="btn primary sm" onclick="resolverSinalizacao(${id})">Confirmar</button></div>`
    document.getElementById('sin-res-' + id)?.focus()
  }, 0)
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

/** Atendimento no painel mantém o contexto da sinalização e exige o relato. */
function abrirAtendimentoPainel(id) {
  const alvo = document.getElementById('pn-atendimento-' + id)
  if (!alvo) return
  alvo.hidden = false
  alvo.innerHTML = `<label for="sin-res-${id}">O que foi realizado?</label>
    <textarea id="sin-res-${id}" maxlength="300" required placeholder="Descreva as providências adotadas."></textarea>
    <div class="sin-atendimento-acoes">
      <button type="button" class="btn sm" onclick="fecharAtendimentoPainel(${id})">Cancelar</button>
      <button type="button" class="btn sm" onclick="resolverSinalizacao(${id})">Salvar atendimento</button>
    </div>`
  document.getElementById('pn-registrar-' + id)?.setAttribute('aria-expanded', 'true')
  document.getElementById('sin-res-' + id)?.focus()
}

function fecharAtendimentoPainel(id) {
  const alvo = document.getElementById('pn-atendimento-' + id)
  if (alvo) { alvo.hidden = true; alvo.innerHTML = '' }
  const botao = document.getElementById('pn-registrar-' + id)
  botao?.setAttribute('aria-expanded', 'false')
  botao?.focus()
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
        <div class="sin-linha-txt" onclick="${s.lote_id ? `irAoLoteNoMapa(${Number(s.lote_id)})` : `irAoPontoDaSinalizacao(${Number(s.lat)}, ${Number(s.lon)})`}" title="Ver no mapa">
          <b>${esc(s.tipo === 'lembrete' ? 'Voltar' : s.rotulo)}</b> · ${esc(s.imovel)}
          <div class="sin-linha-sub">${esc(s.comentario || '')}${s.comentario ? ' · ' : ''}${esc(s.ha || '')} · ${esc(s.autor)}${s.avisos > 1 ? ` · <b class="sin-n">${s.avisos} avisos</b>` : ''}</div>
        </div>
        ${window.PODE_EDITAR && s.lote_id ? `<button type="button" class="btn sm sin-registrar sin-vistoriar" onclick="fazerVistoriaDoPainel(${Number(s.lote_id)})">${_sinSvg('lapis')}Fazer vistoria</button>` : ''}
        <button type="button" class="btn sm sin-registrar" id="pn-registrar-${s.id}" aria-expanded="false" aria-controls="pn-atendimento-${s.id}" onclick="abrirAtendimentoPainel(${s.id})">Registrar atendimento</button>
        <div class="sin-atendimento" id="pn-atendimento-${s.id}" hidden></div>
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
    · ${esc(p.rotulo)}${p.comentario ? ' — "' + esc(p.comentario) + '"' : ''} · ${esc(p.autor || '—')}, ${esc(p.criada_em || '')}${p.avisos > 1 ? ' · ' + p.avisos + ' pessoas avisaram' : ''}</span></div>`
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
          Resolver "${esc(s.rotulo)}"${s.comentario ? ' — ' + esc(s.comentario) : ''} <span class="sin-linha-sub">(${esc(s.criada_em)}${s.avisos > 1 ? ' · ' + s.avisos + ' avisos' : ''})</span></label>`).join('')}` : ''}
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

/** Vai até uma sinalização da via (o Painel não tem lote para abrir). */
function irAoPontoDaSinalizacao(lat, lon) {
  const mapa = mapaState?.obj
  if (!mapa || !isFinite(lat) || !isFinite(lon)) return
  if (typeof irPara === 'function') irPara('mapa')
  if (!camadaLigada('sinalizacoes') && typeof ligarCamada === 'function') ligarCamada('sinalizacoes', true)
  // A mesma espera do irAoLoteNoMapa: a aba do mapa precisa estar à vista.
  setTimeout(() => mapa.setView([lat, lon], Math.max(mapa.getZoom() || 0, 18)), 120)
}

/**
 * TOQUE LONGO (clique direito no computador) num ponto do mapa: oferece
 * "Sinalizar aqui". Fica calado com qualquer ferramenta aberta — medir,
 * desenhar rua, curadoria —, que é dona do mapa enquanto trabalha.
 */
function _sinOfertarNoPonto(ev) {
  const ferramentaAberta = typeof FERRAMENTAS_MAPA !== 'undefined'
    && Object.values(FERRAMENTAS_MAPA).some(f => { try { return f.aberta() } catch { return false } })
  if (ferramentaAberta) return
  ev.originalEvent?.preventDefault?.()
  const p = { lat: ev.latlng.lat, lng: ev.latlng.lng }
  L.popup({ className: 'popup-lote', closeButton: false, offset: [0, -2] })
    .setLatLng(ev.latlng)
    .setContent(`<div class="sin-aqui"><button type="button" class="balao-q" onclick="mapaState.obj.closePopup();abrirSinalizarNoPonto({lat:${p.lat},lng:${p.lng}})">${_sinSvg('bandeira')}Sinalizar aqui</button></div>`)
    .openOn(mapaState.obj)
}

// O mapa nasce no bootstrap (app.js); as bandeiras acompanham o movimento dele.
window.addEventListener('load', () => {
  const mapa = mapaState?.obj
  if (!mapa) return
  mapa.on('moveend', recarregarSinalizacoes)
  mapa.on('contextmenu', _sinOfertarNoPonto)
  recarregarSinalizacoes()
})
