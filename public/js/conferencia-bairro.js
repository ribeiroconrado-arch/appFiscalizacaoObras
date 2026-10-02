/**
 * CONFERÊNCIA DO BAIRRO COM O CADASTRO — a que fica, no mapa.
 *
 * Três peças:
 *   - a JANELA (#m-conferencia): escolhe o bairro, confere (cadastro carregado
 *     ou planilha), lista as pendências e as justificadas;
 *   - as CORES no mapa: os lotes do bairro pintados pelo resultado (camada
 *     "Conferência com o cadastro", no painel Camadas), e um selo na quadra
 *     para os imóveis do cadastro que não têm lote;
 *   - a BARRA de pendências (#conf-barra, na faixa do alto): anterior/próxima,
 *     levando o mapa de pendência em pendência, com justificar à mão.
 *
 * O que não dá para resolver agora se JUSTIFICA: sai da lista com o motivo e
 * quem justificou, sem apagar nada (ConferenciaBairroController::justificar).
 */

const confState = {
  /** @type {string|null} nome de desenho do bairro aberto */
  bairro: null,
  oficial: null,
  /** @type {Object|null} resultado da última conferência (ConferenciaComCadastro) */
  resultado: null,
  /** @type {Object<string,{motivo:string,por:string,em:string}>} "tipo|chave" => justificativa */
  justificativas: {},
  /** As cores e a barra estão no mapa? */
  noMapa: false,
  /** @type {Map<number,string>} lote_id => tipo da pendência (ou 'justificado') */
  porLote: new Map(),
  /** @type {Array<Object>} pendências não justificadas, na ordem da barra */
  itens: [],
  idx: 0,
  /** @type {L.LayerGroup|null} selos "N sem lote" nas quadras */
  selos: null,
  /** @type {Array|null} lista de bairros para o seletor */
  bairros: null,
}

const CONF_TIPOS = {
  nao_encontrados: { rotulo: 'Não está no cadastro', curto: 'não está no cadastro', cor: '#E24B4A' },
  inativos:        { rotulo: 'Inativo no cadastro',  curto: 'inativo no cadastro',  cor: '#6B7280' },
  sem_lote:        { rotulo: 'No cadastro, sem lote no mapa', curto: 'sem lote no mapa', cor: '#E24B4A' },
  sem_inscricao:   { rotulo: 'Sem quadra ou número (inscrição não se monta)', curto: 'sem inscrição', cor: '#EF9F27' },
}
const CONF_COR_CASOU = '#1D9E75'

registrarCamada({
  id: 'conferencia', grupo: 'Curadoria', rotulo: 'Conferência com o cadastro',
  disponivel: () => !!window.USUARIO_CURADOR,
  aoMudar: () => repintarConferencia(),
})

/** A chave da pendência para justificar: inscrição, ou o lote quando não há inscrição. */
const _confChave = (tipo, l) => tipo === 'sem_inscricao' ? 'lote:' + l.lote_id : String(l.inscricao)
const _confJustificada = (tipo, l) => !!confState.justificativas[tipo + '|' + _confChave(tipo, l)]

async function _confPedir(url, opts = {}) {
  const r = await fetch(url, {
    ...opts,
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '', ...(opts.headers || {}) },
  })
  let d = {}
  try { d = await r.json() } catch { /* corpo vazio */ }
  if (r.status === 413) throw new Error('A planilha é maior que o limite de envio do servidor.')
  if (!r.ok) throw new Error((d.errors ? Object.values(d.errors)[0]?.[0] : null) || d.message || 'Falha ao falar com o servidor.')
  return d
}

// ── JANELA ───────────────────────────────────────────────────

async function abrirConferenciaBairro(bairro) {
  if (!document.getElementById('m-conferencia').classList.contains('open')) {
    await abrirJanelaDoMapa()   // fecha a ferramenta em uso, a mesa e as barras (ferramentas-mapa.js)
  }
  openModal('m-conferencia')
  if (bairro) confState.bairro = bairro
  _confCorpo('<div class="vazio-msg">Carregando…</div>')
  try {
    const d = await _confPedir('/api/conferencias')
    confState.bairros = d.bairros
    if (!confState.bairro) {
      // Sem escolha anterior: o bairro do lote mais perto do centro do mapa.
      const centro = mapaState.obj?.getCenter()
      let perto = null, menor = Infinity
      for (const c of (centro ? mapaState.porId?.values() || [] : [])) {
        const [la, lo] = centroLote(c.feature)
        const dist = (la - centro.lat) ** 2 + (lo - centro.lng) ** 2
        if (dist < menor) { menor = dist; perto = c.feature }
      }
      confState.bairro = perto?.properties?.bairro || d.bairros[0]?.bairro || null
    }
    if (confState.bairro) await _confCarregar(confState.bairro)
    else _confCorpo('<div class="lista-vazia">Nenhum bairro com lote no mapa.</div>')
  } catch (e) {
    _confCorpo(`<div class="cad-nota cad-erro">${esc(e.message)}</div>`)
  }
}

function _confCorpo(html) {
  document.getElementById('conf-corpo').innerHTML = html
}

async function _confCarregar(bairro, { janela = true } = {}) {
  let d = await _confPedir('/api/conferencias/bairro?bairro=' + encodeURIComponent(bairro))
  // Lote do bairro corrigido depois da conferência: ela se refaz sozinha, com
  // a MESMA fonte (planilha guardada ou cadastro carregado), sem anexar nada.
  if (d.resultado && d.em_dia === false) {
    try {
      const fd = new FormData()
      fd.append('bairro', bairro)
      fd.append('fonte', 'ultima')
      const antes = _confAbertas(d.resultado, d.justificativas || {})
      d = { ...d, ...(await _confPedir('/api/conferencias/bairro', { method: 'POST', body: fd })) }
      const depois = _confAbertas(d.resultado, d.justificativas || {})
      toast(antes - depois > 0
        ? `Conferência refeita: ${antes - depois} pendência(s) resolvida(s), ${depois} em aberto.`
        : `Conferência refeita: ${depois} pendência(s) em aberto.`)
    } catch { /* fica a última conferência guardada */ }
  }
  confState.bairro = bairro
  confState.oficial = d.oficial ?? confState.oficial
  confState.resultado = d.resultado
  confState.justificativas = d.justificativas || {}
  _confMontarItens()
  if (janela && document.getElementById('m-conferencia')?.classList.contains('open')) pintarJanelaConferencia()
  if (confState.noMapa) { repintarConferencia(); pintarBarraConferencia() }
}

/** Quantas pendências abertas (não justificadas) um resultado tem. */
function _confAbertas(r, justificativas) {
  let n = 0
  for (const tipo of Object.keys(CONF_TIPOS)) {
    for (const l of r?.[tipo] || []) if (!justificativas[tipo + '|' + _confChave(tipo, l)]) n++
  }
  return n
}

// Depois de cada correção no mapa (limparLotesDoMapa, app.js): com a
// conferência à vista no mapa, ela se confere de novo e a pendência resolvida
// sai da barra e das cores. Com espera: uma correção pode recarregar duas vezes.
let _confReconferirTimer = null
document.addEventListener('lotes-alterados', () => {
  if (!confState.noMapa || !confState.bairro) return
  clearTimeout(_confReconferirTimer)
  _confReconferirTimer = setTimeout(() => {
    _confCarregar(confState.bairro, { janela: false }).catch(() => { /* fica a anterior */ })
  }, 1500)
})

function _confMontarItens() {
  const r = confState.resultado
  confState.itens = []
  confState.porLote = new Map()
  if (!r) return
  for (const tipo of Object.keys(CONF_TIPOS)) {
    for (const l of r[tipo] || []) {
      const just = _confJustificada(tipo, l)
      if (l.lote_id) confState.porLote.set(Number(l.lote_id), just ? 'justificado' : tipo)
      if (!just) confState.itens.push({ tipo, ...l })
    }
  }
  // Por quadra e lote, dentro de cada tipo: "próxima" anda pela rua, e não
  // pula de um canto a outro do bairro na ordem em que o banco devolveu.
  const num = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0
  const ordemTipo = Object.keys(CONF_TIPOS)
  confState.itens.sort((a, b) => ordemTipo.indexOf(a.tipo) - ordemTipo.indexOf(b.tipo)
    || num(a.quadra) - num(b.quadra) || num(a.lote) - num(b.lote))
  if (confState.idx >= confState.itens.length) confState.idx = 0
}

function pintarJanelaConferencia() {
  const r = confState.resultado
  const lista = confState.bairros || []
  const sel = `<select id="conf-bairro" onchange="_confCarregar(this.value).catch(e => toast(e.message, 'err'))">
      ${lista.map(b => `<option value="${esc(b.bairro)}" ${b.bairro === confState.bairro ? 'selected' : ''}>
        ${esc(b.oficial || b.bairro)}${b.divergencias !== null ? ` · ${b.divergencias} pendência(s)` : ' · não conferido'}${b.ligado ? '' : ' · sem vínculo'}</option>`).join('')}
    </select>`

  const kpi = (rot, n, cls = '') => `<div class="imp-kpi ${cls}"><small>${rot}</small><b>${Number(n).toLocaleString('pt-BR')}</b></div>`
  const conta = t => confState.itens.filter(i => i.tipo === t).length
  const nJust = Object.keys(confState.justificativas).length

  const linha = (tipo, l) => {
    const just = _confJustificada(tipo, l)
    const ver = l.lote_id
      ? `<a href="#" onclick="event.preventDefault(); _confIrAoItem(${JSON.stringify({ tipo, ...l }).replace(/"/g, '&quot;')})">ver no mapa ›</a>`
      : `<a href="#" onclick="event.preventDefault(); _confIrAoItem(${JSON.stringify({ tipo, ...l }).replace(/"/g, '&quot;')})">na quadra ›</a>`
    const acao = just
      ? `<a href="#" onclick="event.preventDefault(); _confDesjustificar('${tipo}', '${esc(_confChave(tipo, l))}')">desfazer</a>`
      : `<a href="#" onclick="event.preventDefault(); _confJustificar('${tipo}', '${esc(_confChave(tipo, l))}')">justificar</a>`
    const j = confState.justificativas[tipo + '|' + _confChave(tipo, l)]
    return `<tr${just ? ' class="conf-justificada"' : ''}>
      <td class="mono">${esc(l.inscricao || '—')}</td>
      <td>Q ${esc(l.quadra ?? '—')} · L ${esc(l.lote ?? '—')}${l.isencao ? ` <span class="badge bd-in">Cadastro: ${esc(l.isencao)}</span>` : ''}
        ${j ? `<div class="imp-sub">Justificado por ${esc(j.por || '—')} em ${esc(j.em)}: ${esc(j.motivo)}</div>` : ''}</td>
      <td class="imp-abrir">${ver}</td><td class="imp-abrir">${acao}</td></tr>`
  }
  const grupo = tipo => {
    const itens = r?.[tipo] || []
    if (!itens.length) return ''
    const num = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0
    const ordem = (a, b) => num(a.quadra) - num(b.quadra) || num(a.lote) - num(b.lote)
    const abertas = itens.filter(l => !_confJustificada(tipo, l)).sort(ordem)
    const justificadas = itens.filter(l => _confJustificada(tipo, l)).sort(ordem)
    return `<div class="imp-grupo"><span class="conf-cor" style="background:${CONF_TIPOS[tipo].cor}"></span>
        ${esc(CONF_TIPOS[tipo].rotulo)} <span class="imp-sub">· ${abertas.length} pendente(s)${justificadas.length ? `, ${justificadas.length} justificada(s)` : ''}</span></div>
      <table class="imp-tabela"><tbody>${[...abertas, ...justificadas].slice(0, 300).map(l => linha(tipo, l)).join('')}</tbody></table>`
  }

  _confCorpo(`
    <div class="imp-fixo-topo">
      <div class="conf-topo">${sel}</div>
      <div class="imp-fonte">
        ${r && (r.retrato_id || r.fonte !== 'planilha') ? `<label class="imp-opcao" title="${esc(r.fonte_descricao)}"><input type="radio" name="conf-fonte" value="ultima" checked
          onchange="document.getElementById('conf-soltar').hidden = true"> Mesma fonte da última${r.retrato_id ? ' (planilha guardada)' : ''}</label>` : ''}
        <label class="imp-opcao"><input type="radio" name="conf-fonte" value="carregado" ${r && (r.retrato_id || r.fonte !== 'planilha') ? '' : 'checked'}
          onchange="document.getElementById('conf-soltar').hidden = true"> Cadastro carregado</label>
        <label class="imp-opcao"><input type="radio" name="conf-fonte" value="planilha"
          onchange="document.getElementById('conf-soltar').hidden = false"> Planilha .xlsx</label>
        <button class="btn primary sm" id="conf-btn" onclick="conferirBairroAgora()">${r ? 'Conferir de novo' : 'Conferir'}</button>
      </div>
      <label class="imp-soltar compacta" id="conf-soltar" for="conf-planilha" hidden>
        <input type="file" id="conf-planilha" accept=".xlsx"
          onchange="_mostrarEscolhido('conf-soltar', this.files[0], 'Solte a planilha .xlsx aqui', 'ou clique para escolher · exportação do cadastro imobiliário')">
        ${ICO_SOLTAR}<b>Solte a planilha .xlsx aqui</b><span>ou clique para escolher · exportação do cadastro imobiliário</span>
      </label>
      ${r ? `<div class="imp-kpis">
        ${kpi('Casaram', r.casaram, 'ok')}
        ${kpi('Não está no cadastro + inativos', conta('nao_encontrados') + conta('inativos'), conta('nao_encontrados') + conta('inativos') ? 'erro' : '')}
        ${kpi('Cadastro sem lote', conta('sem_lote'), conta('sem_lote') ? 'erro' : '')}
        ${kpi('Sem inscrição', conta('sem_inscricao'), conta('sem_inscricao') ? 'aviso' : '')}
      </div>` : ''}
    </div>
    ${r ? `
      ${r.sem_situacao ? '<div class="cad-nota cad-aviso">A planilha não tinha coluna de situação: os inativos não puderam ser conferidos.</div>' : ''}
      ${confState.itens.length === 0 ? '<div class="cad-nota imp-nota">Nenhuma pendência aberta neste bairro.</div>' : ''}
      ${Object.keys(CONF_TIPOS).map(grupo).join('')}`
      : '<p class="imp-expl">Este bairro ainda não foi conferido. Escolha a fonte e confira.</p>'}
    <div class="imp-fixo-rodape">
      ${r ? `<div class="imp-rodape-conf"><span class="imp-sub">${esc(r.fonte_descricao)} · conferido em ${esc(r.conferido_em)}
        por ${esc(r.conferido_por || '—')} · código do bairro ${esc(r.codigo_bairro)}${nJust ? ` · ${nJust} justificada(s)` : ''}</span></div>` : ''}
      <div class="btn-row imp-acoes">
        <button class="btn" onclick="fModalBtn('m-conferencia')">Fechar</button>
        ${r ? `<button class="btn primary" onclick="mostrarConferenciaNoMapa()">Ver pendências no mapa</button>` : ''}
      </div>
    </div>`)
  _prepararAreaDeSoltar('conf-soltar', 'conf-planilha', /\.xlsx$/i, 'A planilha precisa ser .xlsx.',
    () => _mostrarEscolhido('conf-soltar', document.getElementById('conf-planilha').files[0],
      'Solte a planilha .xlsx aqui', 'ou clique para escolher · exportação do cadastro imobiliário'))
}

async function conferirBairroAgora() {
  const fd = new FormData()
  fd.append('bairro', confState.bairro)
  if (document.querySelector('input[name="conf-fonte"]:checked')?.value === 'planilha') {
    const p = document.getElementById('conf-planilha').files[0]
    if (!p) { toast('Escolha a planilha .xlsx do cadastro.', 'err'); return }
    fd.append('planilha', p)
  }
  if (document.querySelector('input[name="conf-fonte"]:checked')?.value === 'ultima') fd.append('fonte', 'ultima')
  const btn = document.getElementById('conf-btn')
  btn.disabled = true
  btn.textContent = 'Conferindo…'
  try {
    const d = await _confPedir('/api/conferencias/bairro', { method: 'POST', body: fd })
    confState.resultado = d.resultado
    confState.justificativas = d.justificativas || {}
    _confMontarItens()
    // A lista de bairros traz a contagem: atualiza para o seletor.
    try { confState.bairros = (await _confPedir('/api/conferencias')).bairros } catch { /* fica a de antes */ }
    pintarJanelaConferencia()
    if (confState.noMapa) { repintarConferencia(); pintarBarraConferencia() }
    toast(`Conferido: ${confState.itens.length} pendência(s) em aberto.`)
  } catch (e) {
    toast(e.message, 'err')
    btn.disabled = false
    btn.textContent = 'Conferir'
  }
}

function _confJustificar(tipo, chave) {
  pedirTexto({
    titulo: 'Justificar pendência',
    rotulo: `${CONF_TIPOS[tipo].rotulo} · ${chave.startsWith('lote:') ? 'lote sem inscrição' : chave}`,
    dica: 'Ex.: inativo no cadastro, reativação pedida em 02/10; aguardando a prefeitura. A pendência sai da lista e o motivo fica guardado.',
    minimo: 10, textoBtn: 'Justificar',
    onOk: async motivo => {
      try {
        const d = await _confPedir('/api/conferencias/justificar', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ bairro: confState.bairro, chave, tipo, motivo }),
        })
        confState.justificativas = d.justificativas
        _depoisDeJustificar(d.message)
      } catch (e) { toast(e.message, 'err') }
    },
  })
}

async function _confDesjustificar(tipo, chave) {
  try {
    const d = await _confPedir('/api/conferencias/justificar', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ bairro: confState.bairro, chave, tipo, remover: true }),
    })
    confState.justificativas = d.justificativas
    _depoisDeJustificar(d.message)
  } catch (e) { toast(e.message, 'err') }
}

function _depoisDeJustificar(msg) {
  toast(msg)
  _confMontarItens()
  if (document.getElementById('m-conferencia').classList.contains('open')) pintarJanelaConferencia()
  if (confState.noMapa) { repintarConferencia(); pintarBarraConferencia() }
}

// ── NO MAPA ──────────────────────────────────────────────────

function mostrarConferenciaNoMapa() {
  fModalBtn('m-conferencia')
  confState.noMapa = true
  if (!camadaLigada('conferencia')) ligarCamada('conferencia', true)
  if (typeof irPara === 'function') irPara('mapa')
  // Enquadra o bairro pelo contorno, se houver.
  const f = (typeof contornoState !== 'undefined' ? contornoState.dados?.features : [])
    ?.find(x => x.properties.nome === confState.bairro)
  if (f) setTimeout(() => mapaState.obj?.fitBounds(L.geoJSON(f).getBounds(), { padding: [30, 30] }), 150)
  repintarConferencia()
  pintarBarraConferencia()
}

function fecharConferenciaNoMapa() {
  confState.noMapa = false
  repintarConferencia()
  pintarBarraConferencia()
}

/**
 * A cor de um lote pela conferência — chamada por estiloColorido (mapa-cores.js).
 * null = a conferência não fala deste lote (fora do bairro, ou desligada).
 */
function corDaConferencia(f) {
  if (!confState.noMapa || !confState.resultado || typeof camadaLigada !== 'function' || !camadaLigada('conferencia')) return null
  if (!(confState.resultado.nomes_do_desenho || [confState.bairro]).includes(f.properties.bairro)) return null
  const tipo = confState.porLote.get(Number(f.properties.id))
  if (tipo === 'justificado') return { color: '#9FE1CB', weight: 1.4, opacity: 1, dashArray: '4 3', fillColor: '#9FE1CB', fillOpacity: .15 }
  const cor = tipo ? CONF_TIPOS[tipo].cor : CONF_COR_CASOU
  return { color: tipo ? cor : '#FFFFFF', weight: tipo ? 2.2 : 1, opacity: 1, fillColor: cor, fillOpacity: tipo ? .55 : .3 }
}

function repintarConferencia() {
  mapaState.camadas?.forEach(c => c.setStyle(estiloColorido(c.feature)))
  confState.selos?.remove()
  confState.selos = null
  const r = confState.resultado
  if (!confState.noMapa || !r || !camadaLigada('conferencia') || !mapaState.obj) return

  // Selo na quadra: quantos imóveis do cadastro não têm lote desenhado nela.
  const porQuadra = {}
  for (const l of r.sem_lote || []) {
    if (_confJustificada('sem_lote', l)) continue
    const q = String(l.quadra ?? '').replace(/^0+/, '') || '0'
    porQuadra[q] = (porQuadra[q] || 0) + 1
  }
  confState.selos = L.layerGroup(Object.entries(porQuadra).map(([q, n]) => {
    const c = r.centros_quadras?.[q]
    if (!c) return null
    return L.marker(c, {
      interactive: false,
      icon: L.divIcon({ className: 'conf-selo', html: `<span>${n} sem lote · Q ${esc(q)}</span>`, iconSize: null }),
    })
  }).filter(Boolean)).addTo(mapaState.obj)
}

function pintarBarraConferencia() {
  const barra = document.getElementById('conf-barra')
  if (!barra) return
  barra.hidden = !confState.noMapa || !confState.resultado
  if (barra.hidden) return
  curadoriaApareceu()   // a conferência é curadoria: a pesquisa sai (ferramentas-mapa.js)
  const n = confState.itens.length
  const it = confState.itens[confState.idx]
  barra.innerHTML = `
    <b title="${esc(confState.oficial || '')}">Conferência · ${esc(confState.bairro || confState.oficial)}</b>
    <span class="badge ${n ? 'bd-er' : 'bd-ok'}">${n ? `${n} pendência(s)` : 'sem pendências'}</span>
    ${n ? `
      <button class="btn sm" onclick="_confPasso(-1)" title="Pendência anterior">‹</button>
      <span class="conf-atual"><span class="conf-cor" style="background:${CONF_TIPOS[it.tipo].cor}"></span>
        ${confState.idx + 1} de ${n}: Q ${esc(it.quadra ?? '—')} · L ${esc(it.lote ?? '—')} — ${esc(CONF_TIPOS[it.tipo].curto)}</span>
      <button class="btn sm" onclick="_confPasso(1)" title="Próxima pendência">›</button>
      <button class="btn sm" onclick="_confJustificar('${it.tipo}', '${esc(_confChave(it.tipo, it))}')">Justificar</button>` : ''}
    <button class="btn sm" onclick="abrirConferenciaBairro()">Lista</button>
    <button class="btn sm imp-barra-x" title="Tirar a conferência do mapa" onclick="fecharConferenciaNoMapa()">&#10005;</button>`
}

function _confPasso(d) {
  const n = confState.itens.length
  if (!n) return
  confState.idx = (confState.idx + d + n) % n
  pintarBarraConferencia()
  _confIrAoItem(confState.itens[confState.idx])
}

/** Leva o mapa até a pendência: o lote (destacado) ou, sem lote, a quadra. */
function _confIrAoItem(it) {
  if (document.getElementById('m-conferencia')?.classList.contains('open')) {
    fModalBtn('m-conferencia')
    if (!confState.noMapa) mostrarConferenciaNoMapa()
  }
  const i = confState.itens.findIndex(x => x.tipo === it.tipo && _confChave(x.tipo, x) === _confChave(it.tipo, it))
  if (i >= 0) { confState.idx = i; pintarBarraConferencia() }

  if (it.lote_id) { irAoLoteNoMapa(Number(it.lote_id)); return }
  const q = String(it.quadra ?? '').replace(/^0+/, '') || '0'
  const c = confState.resultado?.centros_quadras?.[q]
  if (!c) { toast(`A quadra ${it.quadra ?? '—'} não tem lote no mapa para servir de referência.`, 'aviso'); return }
  if (typeof irPara === 'function') irPara('mapa')
  mapaState.obj?.setView(c, 19)
  toast(`${it.inscricao}: o cadastro tem este imóvel na quadra ${it.quadra}, e o mapa não tem o lote.`)
}
