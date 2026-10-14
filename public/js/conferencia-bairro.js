/**
 * CONFERÊNCIA DO BAIRRO COM O CADASTRO — a que fica, no mapa.
 *
 * Três peças:
 *   - a JANELA (#m-conferencia): escolhe o bairro, confere (cadastro carregado
 *     ou planilha), lista as pendências e as justificadas;
 *   - as CORES no mapa: SÓ os lotes com pendência são pintados (o resto do
 *     bairro fica como estava — pintar tudo poluía e não dizia o que olhar), e
 *     um selo na quadra para os imóveis do cadastro que não têm lote;
 *   - o CARTÃO da pendência (#conf-barra, no alto do mapa): diz qual é o
 *     problema, oferece como resolver, e o ✕ VOLTA PARA A LISTA, no ponto em
 *     que ela estava. Vindo de "ver no mapa" de uma linha, mostra só aquele
 *     lote; vindo de "Ver pendências no mapa", anda por todas (‹ ›).
 *
 * Os cartões do alto da janela FILTRAM a lista pelo tipo de divergência (um
 * clique liga, outro desliga), e o filtro vale também para o mapa.
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
  /** @type {string|null} tipo de divergência escolhido nos cartões (null = todas) */
  filtro: null,
  /** No mapa está UMA pendência só (veio de "ver no mapa" de uma linha)? */
  soFoco: false,
  /** @type {number} rolagem da lista, para o ✕ do cartão voltar ao mesmo ponto */
  rolagem: 0,
}

const CONF_TIPOS = {
  // NÃO É A MESMA COISA: "não está no cadastro" é inscrição que a prefeitura
  // não tem (em geral quadra ou lote errado no desenho); "inativo" é inscrição
  // que EXISTE e foi desativada — o imóvel mudou por desmembramento,
  // unificação ou retificação do BCI, e o desenho ficou para trás.
  nao_encontrados: { rotulo: 'Não está no cadastro', curto: 'não está no cadastro', cor: '#E24B4A',
    nota: 'a prefeitura não tem esta inscrição — confira a quadra e o número do lote' },
  inativos:        { rotulo: 'Inativo no cadastro',  curto: 'inativo no cadastro',  cor: '#6B7280',
    nota: 'a inscrição existe, mas foi desativada: o imóvel mudou por desmembramento, unificação ou retificação do BCI' },
  sem_lote:        { rotulo: 'No cadastro, sem lote no mapa', curto: 'sem lote no mapa', cor: '#E24B4A' },
  sem_inscricao:   { rotulo: 'Sem quadra ou número (inscrição não se monta)', curto: 'sem inscrição', cor: '#EF9F27' },
  // A inscrição casou, mas a metragem do desenho não é a do cadastro. Tem de
  // ser EXATA (ao centésimo de m²): não impede publicar, pede correção.
  area_diferente:  { rotulo: 'Área do desenho diferente da do cadastro', curto: 'área diferente', cor: '#7C3AED' },
}

/** "desenho 359,87 m² · cadastro 360,00 m² · −0,13 m²" — as duas áreas de uma divergência de área. */
function _confAreas(l) {
  if (l.area_cadastro == null) return ''
  const m2 = v => v == null ? '—' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' m²'
  const dif = l.diferenca == null ? '' : ' · ' + (l.diferenca > 0 ? '+' : '−') + m2(Math.abs(l.diferenca)).replace(' m²', '') + ' m²'
  return `desenho <b>${m2(l.area_desenho)}</b> · cadastro <b>${m2(l.area_cadastro)}</b>${dif}`
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
  document.dispatchEvent(new CustomEvent('conferencia-atualizada'))   // o painel de correção (correcao-mapa.js)
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
  const sel = `<select id="conf-bairro" data-combo onchange="_confCarregar(this.value).catch(e => toast(e.message, 'err'))">
      ${lista.map(b => `<option value="${esc(b.bairro)}" ${b.bairro === confState.bairro ? 'selected' : ''}>
        ${esc(b.oficial || b.bairro)}${b.divergencias !== null ? ` · ${b.divergencias} pendência(s)` : ' · não conferido'}${b.ligado ? '' : ' · sem vínculo'}</option>`).join('')}
    </select>`

  const kpi = (rot, n, cls = '') => `<div class="imp-kpi ${cls}"><small>${rot}</small><b>${Number(n).toLocaleString('pt-BR')}</b></div>`
  const conta = t => confState.itens.filter(i => i.tipo === t).length
  const nJust = Object.keys(confState.justificativas).length
  // CARTÃO QUE FILTRA: um clique mostra só aquele tipo de divergência; outro
  // clique no mesmo cartão volta a mostrar todas.
  const cartao = (tipo, rot, cls) => {
    const n = conta(tipo), ativo = confState.filtro === tipo
    return `<button type="button" class="imp-kpi conf-kpi ${n ? cls : ''}${ativo ? ' ativo' : ''}" aria-pressed="${ativo}" ${n || ativo ? '' : 'disabled'}
      title="${ativo ? 'Mostrar todas as divergências' : 'Mostrar só estas'}" onclick="_confFiltrar('${tipo}')"><small>${rot}</small><b>${Number(n).toLocaleString('pt-BR')}</b></button>`
  }

  const linha = (tipo, l) => {
    const just = _confJustificada(tipo, l)
    const ver = just ? `<button type="button" class="btn sm" onclick="_confIrAoItem(${jsArg({ tipo, ...l })})">${l.lote_id ? 'Ver no mapa' : 'Ver a quadra'}</button>`
      : `<button type="button" class="btn sm primary" onclick="_confIrAoItem(${jsArg({ tipo, ...l })})">Corrigir</button>`
    const acao = just
      ? `<button type="button" class="btn sm" onclick="_confDesjustificar(${jsArg(tipo)}, ${jsArg(_confChave(tipo, l))})">Desfazer</button>`
      // Diferença de área não se justifica: vai direto para a correção do desenho.
      : tipo === 'area_diferente'
        ? ''
      : `<button type="button" class="btn sm" onclick="_confJustificar(${jsArg(tipo)}, ${jsArg(_confChave(tipo, l))})">Justificar</button>`
    const j = confState.justificativas[tipo + '|' + _confChave(tipo, l)]
    const detalhe = [
      l.area_cadastro != null ? _confAreas(l) : '',
      tipo === 'sem_lote' ? [l.area_m2 ? fmtNum(l.area_m2) + ' m²' : '', esc(l.endereco || '')].filter(Boolean).join(' · ') : '',
      l.isencao ? `<span class="badge bd-in">Cadastro: ${esc(l.isencao)}</span>` : '',
      l.alterado ? '<span class="badge bd-pe" title="O lote mudou depois da conferência com a planilha: confirme com ela">alterado</span>' : '',
      j ? `<div class="imp-sub">Justificado por ${esc(j.por || '—')} em ${esc(j.em)}: ${esc(j.motivo)}</div>` : '',
    ].filter(Boolean).join(' ')
    return `<tr${just ? ' class="conf-justificada"' : ''}>
      <td class="mono">${esc(l.inscricao || '—')}</td>
      <td>Q ${esc(l.quadra ?? '—')} · L ${esc(l.lote ?? '—')}</td>
      <td class="jt-quebra">${detalhe || '<span class="imp-sub">—</span>'}</td>
      <td>${just ? '<span class="badge bd-in">Justificada</span>' : '<span class="badge bd-pe">Pendente</span>'}</td>
      <td class="jt-acoes">${ver}${acao}</td></tr>`
  }
  const grupo = tipo => {
    const itens = r?.[tipo] || []
    if (!itens.length) return ''
    const num = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0
    const porLote = (a, b) => num(a.quadra) - num(b.quadra) || num(a.lote) - num(b.lote)
    // Área: a maior diferença primeiro — é por ela que se começa a corrigir.
    const ordem = tipo === 'area_diferente'
      ? (a, b) => Math.abs(b.diferenca ?? 0) - Math.abs(a.diferenca ?? 0) || porLote(a, b)
      : porLote
    const abertas = itens.filter(l => !_confJustificada(tipo, l)).sort(ordem)
    const justificadas = itens.filter(l => _confJustificada(tipo, l)).sort(ordem)
    return `<section class="jt-grupo">
        <div class="jt-grupo-tit"><span class="conf-cor" style="background:${CONF_TIPOS[tipo].cor}"></span>
          <b>${esc(CONF_TIPOS[tipo].rotulo)}</b>
          <span class="imp-sub">${abertas.length} pendente(s)${justificadas.length ? `, ${justificadas.length} justificada(s)` : ''}</span>
          ${CONF_TIPOS[tipo].nota ? `<span class="imp-sub conf-grupo-nota">${esc(CONF_TIPOS[tipo].nota)}</span>` : ''}</div>
        <table class="imp-tabela jt-tabela">
          <thead><tr><th>Inscrição</th><th>Quadra · Lote</th><th>Detalhe</th><th class="jt-c-sit">Situação</th><th></th></tr></thead>
          <tbody>${[...abertas, ...justificadas].slice(0, 300).map(l => linha(tipo, l)).join('')}</tbody></table>
        ${itens.length > 300 ? `<div class="imp-sub">+ ${itens.length - 300} — veja todas no PDF.</div>` : ''}
      </section>`
  }

  _confCorpo(`
   <div class="jt">
    <div class="jt-topo">
      <div class="jt-linha conf-linha-topo">
        <div class="conf-topo">${sel}</div>
        ${r ? jtBusca('conf-busca', 'Pesquisar por inscrição, quadra, lote ou detalhe') : ''}
      </div>
      <div class="imp-fonte">
        ${r && (r.fonte === 'planilha' || r.imoveis_no_cadastro > 0) ? `<label class="imp-opcao" title="${esc(r.fonte_descricao)}"><input type="radio" name="conf-fonte" value="ultima" checked
          onchange="document.getElementById('conf-soltar').hidden = true"> ${r.fonte === 'planilha' ? 'Revisar as divergências (sem planilha)' : 'Mesma fonte da última (cadastro carregado)'}</label>` : ''}
        <label class="imp-opcao"><input type="radio" name="conf-fonte" value="carregado" ${r ? '' : 'checked'}
          onchange="document.getElementById('conf-soltar').hidden = true"> Cadastro carregado</label>
        <label class="imp-opcao"><input type="radio" name="conf-fonte" value="planilha" ${r && !(r.fonte === 'planilha' || r.imoveis_no_cadastro > 0) ? 'checked' : ''}
          onchange="document.getElementById('conf-soltar').hidden = false"> Planilha .xlsx</label>
        <label class="conf-tol" title="Diferença entre a área do desenho e a do cadastro que NÃO conta como divergência. Zero = as áreas têm de ser iguais.">Variação de área aceita
          <input type="number" id="conf-tol" min="0" step="0.01" inputmode="decimal" value="${Number(r?.tolerancia_area?.valor ?? 0)}">
          <select id="conf-tol-un" aria-label="Unidade da variação">
            <option value="m2" ${(r?.tolerancia_area?.unidade ?? 'm2') === 'm2' ? 'selected' : ''}>m²</option>
            <option value="pct" ${r?.tolerancia_area?.unidade === 'pct' ? 'selected' : ''}>%</option>
          </select></label>
        <button class="btn primary sm" id="conf-btn" onclick="conferirBairroAgora()">${r ? 'Conferir de novo' : 'Conferir'}</button>
      </div>
      <label class="imp-soltar compacta" id="conf-soltar" for="conf-planilha" ${r && !(r.fonte === 'planilha' || r.imoveis_no_cadastro > 0) ? '' : 'hidden'}>
        <input type="file" id="conf-planilha" accept=".xlsx"
          onchange="_mostrarEscolhido('conf-soltar', this.files[0], 'Solte a planilha .xlsx aqui', 'ou clique para escolher · exportação do cadastro imobiliário')">
        ${ICO_SOLTAR}<b>Solte a planilha .xlsx aqui</b><span>ou clique para escolher · exportação do cadastro imobiliário</span>
      </label>
      ${r ? `<div class="imp-kpis conf-kpis">
        ${kpi('Casaram', r.casaram, 'ok')}
        ${cartao('nao_encontrados', 'Não está no cadastro', 'erro')}
        ${cartao('inativos', 'Inativo no cadastro', 'erro')}
        ${cartao('sem_lote', 'Cadastro sem lote', 'erro')}
        ${cartao('sem_inscricao', 'Sem inscrição', 'aviso')}
        ${cartao('area_diferente', 'Área diferente', 'erro')}
      </div>` : ''}
    </div>
    <div class="jt-rolagem">
    ${r ? `
      ${r.sem_situacao ? '<div class="cad-nota cad-aviso">A planilha não tinha coluna de situação: os inativos não puderam ser conferidos.</div>' : ''}
      ${r.area_diferente === undefined ? '<div class="cad-nota cad-aviso">Esta conferência é de antes da comparação de áreas. Confira de novo para apontar os lotes com metragem diferente da do cadastro.</div>' : ''}
      ${r.sem_area_no_cadastro ? `<div class="cad-nota cad-aviso">${r.sem_area_no_cadastro} imóvel(is) casaram pela inscrição, mas o cadastro não traz a área do terreno deles: a metragem não pôde ser comparada.</div>` : ''}
      ${confState.itens.length === 0 ? '<div class="cad-nota imp-nota">Nenhuma pendência aberta neste bairro.</div>' : ''}
      ${r.tolerancia_area?.valor ? `<div class="cad-nota imp-nota">Diferença de área de até ${Number(r.tolerancia_area.valor).toLocaleString('pt-BR')} ${r.tolerancia_area.unidade === 'pct' ? '%' : 'm²'} não foi contada como divergência.</div>` : ''}
      ${confState.filtro ? `<div class="conf-filtro-aviso">Mostrando só <b>${esc(CONF_TIPOS[confState.filtro].rotulo)}</b>. <a href="#" onclick="event.preventDefault(); _confFiltrar(null)">Mostrar todas</a></div>` : ''}
      ${Object.keys(CONF_TIPOS).filter(t => !confState.filtro || t === confState.filtro).map(grupo).join('')}`
      : '<p class="imp-expl">Este bairro ainda não foi conferido. Escolha a fonte e confira.</p>'}
      <div class="jt-nada" hidden>Nenhuma pendência com o que foi pesquisado.</div>
    </div>
    <div class="jt-rodape">
      ${r ? `<div class="imp-rodape-conf"><span class="imp-sub">${esc(r.fonte_descricao)} · conferido em ${esc(r.conferido_em)}${r.revisao ? ` · revisado sem planilha em ${esc(r.revisao.em)} (${r.revisao.resolvidas} resolvida(s))` : ''}
        por ${esc(r.conferido_por || '—')} · código do bairro ${esc(r.codigo_bairro)}${nJust ? ` · ${nJust} justificada(s)` : ''}</span></div>` : ''}
      <div class="btn-row imp-acoes">
        <button class="btn" onclick="fModalBtn('m-conferencia')">Fechar</button>
        ${r ? `<button class="btn" onclick="baixarPdfDaConferencia()" title="A listagem que está na tela, em PDF">${confState.filtro ? 'PDF desta listagem' : 'Gerar PDF'}</button>` : ''}
        ${r && _confVisiveis().length ? `<button class="btn primary" onclick="mostrarConferenciaNoMapa()">Corrigir no mapa</button>` : ''}
      </div>
    </div>
   </div>`)
  jtAplicar('conf-busca')
  _prepararAreaDeSoltar('conf-soltar', 'conf-planilha', /\.xlsx$/i, 'A planilha precisa ser .xlsx.',
    () => _mostrarEscolhido('conf-soltar', document.getElementById('conf-planilha').files[0],
      'Solte a planilha .xlsx aqui', 'ou clique para escolher · exportação do cadastro imobiliário'))
}

/**
 * A listagem da tela em PDF: todas as divergências ou, com um cartão ligado,
 * só as daquele tipo. Abre em outra aba — é arquivo, não JSON.
 */
function baixarPdfDaConferencia() {
  if (!confState.bairro || !confState.resultado) return
  const q = new URLSearchParams({ bairro: confState.bairro })
  if (confState.filtro) q.set('tipo', confState.filtro)
  window.open('/conferencias/bairro/pdf?' + q, '_blank')
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
  // A variação de área aceita (zero = exata), em m² ou em % da área do cadastro.
  const tol = Number(String(document.getElementById('conf-tol')?.value ?? '0').replace(',', '.'))
  fd.append('tolerancia_area', Number.isFinite(tol) && tol > 0 ? String(tol) : '0')
  fd.append('tolerancia_unidade', document.getElementById('conf-tol-un')?.value || 'm2')
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
  document.dispatchEvent(new CustomEvent('conferencia-atualizada'))
  if (typeof correcaoAberta === 'function' && correcaoAberta()) return
  if (document.getElementById('m-conferencia').classList.contains('open')) pintarJanelaConferencia()
  if (!confState.noMapa) return
  // Vendo UMA pendência e ela foi justificada: não há mais o que mostrar ali.
  if (confState.soFoco || !_confVisiveis().length) { voltarAListaDaConferencia(); return }
  repintarConferencia(); pintarBarraConferencia()
}

/** As pendências abertas que o filtro dos cartões deixa passar. */
function _confVisiveis() {
  return confState.filtro ? confState.itens.filter(i => i.tipo === confState.filtro) : confState.itens
}

/** Cartão clicado: liga o filtro daquele tipo, ou desliga se já era ele. @param {string|null} tipo */
function _confFiltrar(tipo) {
  confState.filtro = tipo && confState.filtro !== tipo ? tipo : null
  confState.idx = 0
  if (document.getElementById('m-conferencia')?.classList.contains('open')) pintarJanelaConferencia()
  if (confState.noMapa) { repintarConferencia(); pintarBarraConferencia() }
}

/** O quadro que rola dentro da janela da conferência. */
const _confRolavel = () => document.querySelector('#m-conferencia .jt-rolagem')

// ── NO MAPA ──────────────────────────────────────────────────

/**
 * "Ver pendências no mapa": leva ao mapa as pendências (as do filtro, se
 * houver), pintando SÓ elas, e abre o cartão para andar de uma em uma.
 */
function mostrarConferenciaNoMapa() {
  // As pendências vão para o PAINEL DE CORREÇÃO (correcao-mapa.js): a lista
  // fica à vista enquanto se corrige, e a ferramenta devolve a vez a ele.
  if (typeof abrirCorrecao === 'function') { abrirCorrecao('bairro'); return }
  confState.rolagem = _confRolavel()?.scrollTop ?? 0
  fModalBtn('m-conferencia')
  confState.noMapa = true
  confState.soFoco = false
  confState.idx = 0
  if (!camadaLigada('conferencia')) ligarCamada('conferencia', true)
  if (typeof irPara === 'function') irPara('mapa')
  repintarConferencia()
  pintarBarraConferencia()
  // Já começa na primeira pendência: o cartão fala dela, o mapa mostra ela.
  const primeira = _confVisiveis()[0]
  if (primeira) _confLevarMapa(primeira)
}

/** Tira a conferência do mapa, sem voltar à lista (outra ferramenta tomou a vez). */
function fecharConferenciaNoMapa() {
  confState.noMapa = false
  confState.soFoco = false
  repintarConferencia()
  pintarBarraConferencia()
}

/**
 * O ✕ do cartão: tira a conferência do mapa e VOLTA PARA A LISTA, no ponto em
 * que ela estava — fechar o cartão é voltar um passo, não encerrar a conferência.
 */
function voltarAListaDaConferencia() {
  fecharConferenciaNoMapa()
  if (typeof realceDoLote !== 'undefined' && realceDoLote) { realceDoLote.remove(); realceDoLote = null }
  openModal('m-conferencia')
  pintarJanelaConferencia()
  const rolagem = confState.rolagem
  setTimeout(() => { const q = _confRolavel(); if (q) q.scrollTop = rolagem }, 30)
}

/**
 * A cor de um lote pela conferência — chamada por estiloColorido (mapa-cores.js).
 * null = a conferência não fala deste lote, e ele fica como o mapa o pinta.
 *
 * SÓ AS PENDÊNCIAS SÃO PINTADAS. Antes o bairro inteiro ganhava cor (verde
 * para o que casou, tracejado para o justificado), e o que precisava de
 * atenção se perdia no meio. Vendo UMA pendência, só o lote dela.
 */
function corDaConferencia(f) {
  if (!confState.noMapa || !confState.resultado || typeof camadaLigada !== 'function' || !camadaLigada('conferencia')) return null
  const id = Number(f.properties.id)
  const tipo = confState.porLote.get(id)
  if (!tipo || tipo === 'justificado') return null
  if (confState.filtro && tipo !== confState.filtro) return null
  if (confState.soFoco && Number(_confVisiveis()[confState.idx]?.lote_id) !== id) return null
  const cor = CONF_TIPOS[tipo].cor
  const atual = Number(_confVisiveis()[confState.idx]?.lote_id) === id
  return { color: atual ? '#FFFFFF' : cor, weight: atual ? 3.2 : 2, opacity: 1, fillColor: cor, fillOpacity: atual ? .7 : .45 }
}

function repintarConferencia() {
  mapaState.camadas?.forEach(c => c.setStyle(estiloColorido(c.feature)))
  confState.selos?.remove()
  confState.selos = null
  const r = confState.resultado
  if (!confState.noMapa || !r || !camadaLigada('conferencia') || !mapaState.obj) return
  // O selo "N sem lote" é das pendências de cadastro sem lote: some com outro
  // filtro, e quando se está vendo uma pendência só.
  if (confState.soFoco || (confState.filtro && confState.filtro !== 'sem_lote')) return

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

/** O problema de uma pendência, em uma frase — é o que o cartão do mapa diz. */
function _confProblema(it) {
  const insc = it.inscricao ? `a inscrição ${it.inscricao}` : 'a inscrição'
  switch (it.tipo) {
    case 'nao_encontrados':
      return `O cadastro da prefeitura não tem ${insc}. Em geral é quadra ou número de lote errado no desenho.`
    case 'inativos':
      return `No cadastro, ${insc} está inativa${it.isencao ? ' (' + it.isencao + ')' : ''}: o imóvel foi alterado por desmembramento, unificação ou retificação do BCI, e o desenho não acompanhou.`
    case 'sem_lote':
      return `O cadastro tem o imóvel ${it.inscricao || ''} nesta quadra${it.area_m2 ? ', com ' + fmtNum(it.area_m2) + ' m²' : ''}${it.endereco ? ' (' + it.endereco + ')' : ''}, e o mapa não tem o lote desenhado.`
    case 'sem_inscricao':
      return 'Este lote está sem quadra ou sem número, e por isso a inscrição dele não se monta.'
    case 'area_diferente':
      return `${_confAreas(it).replace(/<\/?b>/g, '')}. A diferença passa da variação aceitável: o desenho do lote precisa ser corrigido.`
  }
  return ''
}

/**
 * O cartão da pendência, no alto do mapa: qual é, o que há de errado, como
 * resolver — e o ✕, que volta para a lista.
 */
function pintarBarraConferencia() {
  const barra = document.getElementById('conf-barra')
  if (!barra) return
  // Com o painel de correção aberto, é ele que mostra a pendência.
  if (typeof correcaoAberta === 'function' && correcaoAberta()) { barra.hidden = true; return }
  barra.hidden = !confState.noMapa || !confState.resultado
  if (barra.hidden) return
  curadoriaApareceu()   // a conferência é curadoria: a pesquisa sai (ferramentas-mapa.js)
  const itens = _confVisiveis()
  const n = itens.length
  const it = itens[confState.idx]
  if (!it) {
    barra.innerHTML = `<div class="conf-b-topo"><b>Conferência · ${esc(confState.bairro || confState.oficial || '')}</b>
        <span class="badge bd-ok">sem pendências</span>
        <button class="btn sm imp-barra-x" title="Voltar à lista" aria-label="Voltar à lista" onclick="voltarAListaDaConferencia()">&#10005;</button></div>`
    return
  }
  const t = CONF_TIPOS[it.tipo]
  // COMO RESOLVER, conforme o problema. Justificar vale para todos.
  // ÁREA DIFERENTE não tem "Justificar": o que é aceitável se diz na variação
  // da conferência; o que passou dela se resolve corrigindo o desenho.
  const area = it.tipo === 'area_diferente'
  const resolver = [
    area && it.lote_id ? `<button class="btn sm primary" onclick="_confCorrigirDesenho(${Number(it.lote_id)})">Corrigir o desenho</button>` : '',
    it.lote_id ? `<button class="btn sm" onclick="_confAbrirFicha(${Number(it.lote_id)})">Abrir a ficha do lote</button>` : '',
    it.tipo === 'sem_lote' ? `<button class="btn sm" onclick="modoCadastral('desenho')">Desenhar o lote</button>` : '',
    area ? '' : `<button class="btn sm" onclick="_confJustificar(${jsArg(it.tipo)}, ${jsArg(_confChave(it.tipo, it))})">Justificar</button>`,
  ].join('')
  barra.innerHTML = `
    <div class="conf-b-topo">
      <span class="conf-cor" style="background:${t.cor}"></span>
      <b>Q ${esc(it.quadra ?? '—')} · L ${esc(it.lote ?? '—')}</b>
      <span class="conf-b-tipo">${esc(t.rotulo)}</span>
      ${!confState.soFoco && n > 1 ? `<span class="conf-b-nav">
        <button class="btn sm" onclick="_confPasso(-1)" title="Pendência anterior" aria-label="Pendência anterior">‹</button>
        <span>${confState.idx + 1} de ${n}</span>
        <button class="btn sm" onclick="_confPasso(1)" title="Próxima pendência" aria-label="Próxima pendência">›</button></span>` : ''}
      <button class="btn sm imp-barra-x" title="Voltar à lista" aria-label="Voltar à lista" onclick="voltarAListaDaConferencia()">&#10005;</button>
    </div>
    <div class="conf-b-txt">${esc(_confProblema(it))}</div>
    <div class="conf-b-acoes">${resolver}</div>`
}

/**
 * "Corrigir o desenho" da pendência de área: o lote abre na prancheta com o
 * contorno editável e a área do cadastro como ALVO — o rodapé diz quanto falta
 * ou sobra enquanto se ajustam os cantos. Salvo, a conferência se refaz
 * sozinha: batendo, a pendência sai.
 */
function _confCorrigirDesenho(loteId) {
  const it = _confVisiveis().find(x => Number(x.lote_id) === Number(loteId))
  if (!it) return
  PranchetaCad.abrir('edicao', [Number(loteId)], null, null, {
    quadra: it.quadra, numero_lote: it.lote, areaAlvo: Number(it.area_cadastro),
    aoSalvar: async () => {
      try { await _confCarregar(confState.bairro, { janela: false }) } catch (e) { toast(e.message, 'err'); return }
      const resolvida = !confState.itens.some(x => x.tipo === 'area_diferente' && Number(x.lote_id) === Number(loteId))
      if (!resolvida) { toast('O lote foi salvo, mas a área ainda não é a do cadastro.', 'aviso'); return }
      // Vendo só esta pendência (ou era a última): volta para a lista.
      if (confState.soFoco || !_confVisiveis().length) { voltarAListaDaConferencia(); return }
      confState.idx = Math.min(confState.idx, _confVisiveis().length - 1)
      repintarConferencia(); pintarBarraConferencia()
      _confLevarMapa(_confVisiveis()[confState.idx])
    },
  }).catch(e => toast(e.message, 'err'))
}

/** Abre a ficha do lote da pendência (o lote já está no mapa, destacado). */
function _confAbrirFicha(loteId) {
  const f = state.lotes?.get(loteId)
  if (f) { abrirFicha(f); return }
  toast('O lote ainda está carregando no mapa. Tente de novo em instantes.', 'aviso')
}

function _confPasso(d) {
  const itens = _confVisiveis()
  if (!itens.length) return
  confState.idx = (confState.idx + d + itens.length) % itens.length
  repintarConferencia()
  pintarBarraConferencia()
  _confLevarMapa(itens[confState.idx])
}

/**
 * "ver no mapa" de uma linha da lista: fecha a janela (guardando onde ela
 * estava), mostra SÓ aquele lote e abre o cartão dele. O ✕ volta à lista.
 */
function _confIrAoItem(it) {
  if (typeof abrirCorrecao === 'function') { abrirCorrecao('bairro', _corrChave(it.tipo, it)); return }
  const aberta = document.getElementById('m-conferencia')?.classList.contains('open')
  if (aberta) {
    confState.rolagem = _confRolavel()?.scrollTop ?? 0
    fModalBtn('m-conferencia')
    confState.soFoco = true
  }
  confState.noMapa = true
  if (!camadaLigada('conferencia')) ligarCamada('conferencia', true)
  // A pendência escolhida pode estar fora do filtro ligado: o filtro cede.
  if (confState.filtro && confState.filtro !== it.tipo) confState.filtro = null
  const i = _confVisiveis().findIndex(x => x.tipo === it.tipo && _confChave(x.tipo, x) === _confChave(it.tipo, it))
  if (i >= 0) confState.idx = i
  if (typeof irPara === 'function') irPara('mapa')
  repintarConferencia()
  pintarBarraConferencia()
  _confLevarMapa(it)
}

/** Leva o mapa até a pendência: o lote (destacado) ou, sem lote, a quadra. */
function _confLevarMapa(it) {
  if (it.lote_id) { irAoLoteNoMapa(Number(it.lote_id)); return }
  const q = String(it.quadra ?? '').replace(/^0+/, '') || '0'
  const c = confState.resultado?.centros_quadras?.[q]
  if (!c) { toast(`A quadra ${it.quadra ?? '—'} não tem lote no mapa para servir de referência.`, 'aviso'); return }
  mapaState.obj?.setView(c, ZOOM_DO_LOTE)
}
