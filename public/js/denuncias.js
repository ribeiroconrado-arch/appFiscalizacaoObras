// ══════════════════════════════════════════════
// MÓDULO: DENÚNCIAS (Posturas)
//
// O módulo Perturbação do AppPOSTURAS dentro deste sistema: a lista do que o
// agente atendeu em campo, o painel com os números, a ficha, o formulário e a
// camada "Denúncias" no mapa. Da ficha sai a notificação já preenchida.
//
// A denúncia aqui é o REGISTRO do atendimento, como lá — não há fila de
// recebidas nem triagem. O servidor é DenunciaController.
// ══════════════════════════════════════════════

/** Estado da aba Denúncias. */
const dnState = {
  /** @type {Array<Object>} */ lista: [],
  total: 0,
  limite: 0,
  // "Todos os agentes" é o padrão, ao contrário de Documentos: no Posturas a
  // lista sempre mostrou as de todos, e é assim que a equipe procura a
  // denúncia que o colega atendeu ontem no mesmo endereço.
  filtros: { busca: '', tratativa: '', fonte: '', agente: 'todos', de: '', ate: '' },
  sub: 'lista',
  /** @type {Object|null} a denúncia aberta na ficha */ ficha: null,
  fonte: 'Residência',
  bairrosCarregados: false,
  /** @type {string[]} os bairros ligados à rua escolhida (vazio: rua sem ligação) */ bairrosDaRua: [],
  /** @type {SpeechRecognition|null} */ voz: null,
  /** @type {number|null} a denúncia a destacar quando o mapa carregar */ focoNoMapa: null,
}

// ── CORES DA TRATATIVA ───────────────────────────────────────
//
// Cada cor diz uma coisa só, o vocabulário dos selos do sistema (tema-f.css):
// âmbar aguardando, azul ato em vigor, vermelho sanção, verde resolvido,
// cinza neutro, cinza-escuro deixou de valer. O pino do mapa usa os mesmos
// tons, em hexadecimal porque o Leaflet desenha fora do CSS.

/** @param {string|null} t @returns {{classe:string, cor:string}} */
function corDaTratativa(t) {
  if (!t) return { classe: 'bd-pe', cor: '#B8860B' }
  if (t === 'Cancelado') return { classe: 'bd-fim', cor: '#4B5563' }
  if (t.startsWith('Auto de Infração')) return { classe: 'bd-er', cor: '#C62828' }
  if (t === 'Notificado' || t === 'Termo de Advertência') return { classe: 'bd-vig', cor: '#1D5FBF' }
  if (t === 'Orientado') return { classe: 'bd-ok', cor: '#2E7D32' }
  return { classe: 'bd-in', cor: '#6B7280' }
}

/** @param {string|null} t */
const seloDaTratativa = t =>
  `<span class="badge ${corDaTratativa(t).classe}">${esc(t || 'Pendente')}</span>`

/** Fala com o servidor. Erro de validação vira a mensagem do primeiro campo. */
async function _dnPedir(url, opts = {}) {
  const r = await fetch(url, { ...opts, headers: { Accept: 'application/json', 'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '', ...(opts.headers || {}) } })
  if (r.status === 419) throw new Error('Sessão expirada. Entre novamente.')
  let d = {}
  try { d = await r.json() } catch { /* sem corpo */ }
  if (!r.ok) throw new Error((d.errors && Object.values(d.errors)[0]?.[0]) || d.message || 'Falha ao falar com o servidor.')
  return d
}

// ── LISTA ────────────────────────────────────────────────────

/** Os filtros como vão na URL — os mesmos para a lista e para o CSV. */
function _dnParametros() {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(dnState.filtros)) { if (v) p.set(k, v) }
  return p
}

async function carregarDenuncias() {
  const alvo = document.getElementById('lista-denuncias')
  if (!alvo) return
  alvo.innerHTML = '<div class="lista-vazia">Carregando…</div>'
  mostrarCarregandoTela('Buscando denúncias...')
  try {
    const d = await _dnPedir('/api/denuncias?' + _dnParametros())
    dnState.lista = d.denuncias
    dnState.total = d.total
    dnState.limite = d.limite
    renderDenuncias()
    limparBuscaPendente('den-buscar')
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="lista-vazia">Não foi possível carregar as denúncias.</div>'
  } finally {
    esconderCarregandoTela()
  }
}

function renderDenuncias() {
  const alvo = document.getElementById('lista-denuncias')
  const cont = document.getElementById('cont-den')
  if (cont) cont.textContent = dnState.total
  pintarChipsDen()
  if (!dnState.lista.length) {
    alvo.innerHTML = '<div class="lista-vazia">Nenhuma denúncia com esses filtros.</div>'
    return
  }
  // A lista traz as mais recentes até o limite; o resto se alcança pela busca
  // e pelos filtros, e o rodapé avisa — lista cortada sem aviso parece completa.
  const corte = dnState.total > dnState.lista.length
    ? `Mostrando as ${dnState.lista.length} mais recentes de ${dnState.total}. Use a busca ou os filtros para chegar às outras.`
    : `Mostrando ${dnState.lista.length} denúncia(s)`
  alvo.innerHTML = ehTelaLarga() ? _dnTabela(corte) : _dnCartoes(corte)
}

/** No computador, o padrão M1 (css/tabelas.css): uma coluna por informação. */
function _dnTabela(rodape) {
  const linhas = dnState.lista.map(d => `
    <tr class="den-linha" style="--den-cor:${corDaTratativa(d.tratativa).cor}" onclick="abrirDenuncia(${Number(d.id)})">
      <td><span class="tl-cod">${esc(d.protocolo || '—')}</span></td>
      <td>${esc(d.data || '')}</td>
      <td title="${esc(d.endereco)}">${esc(d.endereco)}${d.lat ? ' <span class="den-gps-marca" title="Tem GPS">●</span>' : ''}</td>
      <td class="tl-cinza">${esc(d.bairro || '—')}</td>
      <td>${esc(d.fonte || '—')}</td>
      <td title="${esc(d.responsavel || '')}">${esc(d.responsavel || '—')}</td>
      <td class="tl-cinza" title="${esc(d.agente || '')}">${esc(d.agente || '—')}</td>
      <td>${seloDaTratativa(d.tratativa)}</td>
    </tr>`).join('')
  return `
    <div class="tabela-wrap">
      <table class="tabela-lista tl-den">
        <thead><tr>
          <th>Protocolo</th><th>Data</th><th>Endereço</th><th>Bairro</th><th>Fonte</th>
          <th>Responsável</th><th>Agente</th><th>Tratativa</th>
        </tr></thead>
        <tbody>${linhas}</tbody>
      </table>
      <div class="tl-rodape"><span>${esc(rodape)}</span></div>
    </div>`
}

/** No celular, o cartão: protocolo e tratativa no topo, endereço, e o rodapé de conferência. */
function _dnCartoes(rodape) {
  return dnState.lista.map(d => `
    <div class="mob-card den-card" style="border-left-color:${corDaTratativa(d.tratativa).cor}" onclick="abrirDenuncia(${Number(d.id)})">
      <div class="den-card-topo">
        <span class="proto-badge">${esc(d.protocolo || '—')}</span>
        ${seloDaTratativa(d.tratativa)}
      </div>
      <div class="den-card-end">${esc(d.endereco)}</div>
      <div class="den-card-sub">${esc(d.bairro || '')}${d.responsavel ? ' · ' + esc(d.responsavel) : ''}</div>
      <div class="den-card-rodape">
        <span>${esc(d.data || '')}</span>
        <span>${esc(d.fonte || '')}</span>
        <span>${esc((d.agente || '').split(' ')[0])}</span>
      </div>
    </div>`).join('') + `<div class="lista-vazia den-rodape">${esc(rodape)}</div>`
}

// ── FILTROS ──────────────────────────────────────────────────
// O mesmo desenho da lista de Documentos: escolher filtro só anota, quem
// busca é o botão; o que está valendo volta como etiqueta com ✕.

const ROTULOS_FILTRO_DEN = {
  tratativa: { nome: 'Tratativa', campo: 'den-f-tratativa' },
  fonte:     { nome: 'Fonte',     campo: 'den-f-fonte' },
  agente:    { nome: 'Agente',    campo: 'den-f-agente' },
  de:        { nome: 'De',        campo: 'den-f-de' },
  ate:       { nome: 'Até',       campo: 'den-f-ate' },
}

function abrirFiltrosDen() {
  for (const [chave, f] of Object.entries(ROTULOS_FILTRO_DEN)) {
    const el = document.getElementById(f.campo)
    if (el) el.value = dnState.filtros[chave] ?? ''
  }
  openModal('m-den-filtros')
}

function aplicarFiltrosDen() {
  for (const [chave, f] of Object.entries(ROTULOS_FILTRO_DEN)) {
    dnState.filtros[chave] = document.getElementById(f.campo)?.value ?? ''
  }
  fModalBtn('m-den-filtros')
  carregarDenuncias()
}

function limparFiltrosDen() {
  dnState.filtros = { busca: '', tratativa: '', fonte: '', agente: 'todos', de: '', ate: '' }
  const campo = document.getElementById('den-busca')
  if (campo) campo.value = ''
  fModalBtn('m-den-filtros')
  carregarDenuncias()
}

/** @param {string} chave */
function removerFiltroDen(chave) {
  dnState.filtros[chave] = chave === 'agente' ? 'todos' : ''
  carregarDenuncias()
}

/** @param {string} campo @param {string} valor */
function filtrarDenuncias(campo, valor) {
  dnState.filtros[campo] = valor
  marcarBuscaPendente('den-buscar')
}

/**
 * Os mesmos filtros, à vista no computador: clonados da janela (as opções de
 * tratativa vêm de Denuncia::TRATATIVAS no Blade, e não são escritas de novo
 * aqui). O que vale continua sendo `dnState.filtros`.
 */
function montarFiltroLargoDen() {
  const faixa = document.getElementById('den-filtro-larga')
  if (!faixa || faixa.dataset.pronta) return
  for (const [chave, f] of Object.entries(ROTULOS_FILTRO_DEN)) {
    const origem = document.getElementById(f.campo)
    if (!origem) continue
    const copia = origem.cloneNode(true)
    // O original já foi vestido de combo (ui.js), e o clone herdaria a marca de
    // pronto e a classe que o esconde — sem combo nenhum por cima. Sem elas, o
    // observador do ui.js veste o clone assim que ele entra na página.
    delete copia.dataset.comboPronto
    copia.classList.remove('combo-oculto')
    copia.id = 'den-w-' + chave
    copia.title = f.nome
    copia.onchange = () => filtrarDenuncias(chave, copia.value)
    const campo = document.createElement('div')
    campo.className = 'lista-campo'
    const label = document.createElement('label')
    label.htmlFor = copia.id
    label.textContent = f.nome
    campo.append(label, copia)
    faixa.appendChild(campo)
  }
  faixa.dataset.pronta = '1'
}

function pintarChipsDen() {
  montarFiltroLargoDen()
  for (const chave of Object.keys(ROTULOS_FILTRO_DEN)) {
    const el = document.getElementById('den-w-' + chave)
    if (el) el.value = dnState.filtros[chave] ?? ''
  }
  const alvo = document.getElementById('den-chips')
  const cont = document.getElementById('den-filtro-n')
  if (!alvo) return
  const ativos = Object.entries(ROTULOS_FILTRO_DEN).map(([chave, f]) => {
    const valor = dnState.filtros[chave]
    // O padrão da tela (todos os agentes) não é filtro, e não vira etiqueta.
    if (!valor || (chave === 'agente' && valor === 'todos')) return null
    const sel = document.getElementById(f.campo)
    const texto = sel?.tagName === 'SELECT'
      ? ([...sel.options].find(o => o.value === valor)?.text ?? valor)
      : (/^\d{4}-\d{2}-\d{2}$/.test(valor) ? formatarDataBR(valor) : valor)
    return { chave, rotulo: f.nome, texto }
  }).filter(Boolean)

  if (cont) { cont.hidden = !ativos.length; cont.textContent = ativos.length }
  alvo.innerHTML = ativos.map(a => `
      <button type="button" class="chip-filtro" onclick="removerFiltroDen('${a.chave}')">
        <span class="chip-rot">${esc(a.rotulo)}</span>${esc(a.texto)}<span class="chip-x">&#10005;</span>
      </button>`).join('')
    + (ativos.length > 1 ? '<button type="button" class="chip-limpar" onclick="limparFiltrosDen()">Limpar tudo</button>' : '')
}

/** A lista filtrada em planilha — o que está valendo nos filtros, até 5 mil linhas. */
function baixarCsvDenuncias() {
  window.location.href = '/api/denuncias/csv?' + _dnParametros()
}

// ── LISTA | PAINEL ───────────────────────────────────────────

/** @param {'lista'|'painel'} sub */
function subDenuncias(sub) {
  dnState.sub = sub
  document.querySelectorAll('.den-sub button').forEach(b => b.classList.toggle('at', b.dataset.sub === sub))
  document.getElementById('den-lista-painel').hidden = sub !== 'lista'
  document.getElementById('den-painel').hidden = sub !== 'painel'
  sub === 'painel' ? carregarPainelDen() : carregarDenuncias()
}

/** A aba foi aberta (irPara): recarrega o que estiver à vista. */
function prepararDenuncias() {
  dnState.sub === 'painel' ? carregarPainelDen() : carregarDenuncias()
}

// ── PAINEL ───────────────────────────────────────────────────

/**
 * O dashboard do AppPOSTURAS: quantas, quantas pendentes, como foram
 * tratadas, de onde vem o barulho e onde. O período padrão são os últimos 12
 * meses — a sazonalidade (festas de fim de ano, férias) só aparece no ano
 * inteiro.
 */
async function carregarPainelDen(de = '', ate = '') {
  const alvo = document.getElementById('den-painel')
  alvo.innerHTML = '<div class="lista-vazia">Carregando…</div>'
  try {
    const p = new URLSearchParams()
    if (de) p.set('de', de)
    if (ate) p.set('ate', ate)
    renderPainelDen(await _dnPedir('/api/denuncias/painel?' + p))
  } catch (e) {
    alvo.innerHTML = `<div class="lista-vazia">${esc(e.message)}</div>`
  }
}

const _MESES_CURTOS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']

function renderPainelDen(d) {
  const mc = (n, rotulo, detalhe = '') => `
    <div class="mc"><div class="n">${fmtNum(n)}</div>
      <div class="tx"><div class="l">${esc(rotulo)}</div>${detalhe ? `<div class="dt">${esc(detalhe)}</div>` : ''}</div></div>`
  const pct = (n, t) => t ? Math.round(n * 100 / t) + '%' : '—'

  // Barras horizontais: rótulo, barra proporcional ao maior, número.
  const barras = (itens, rotular, cor = () => 'var(--g)') => {
    const maior = Math.max(1, ...itens.map(i => i.n))
    return itens.length ? itens.map(i => `
      <div class="den-barra">
        <span class="den-barra-rot" title="${esc(rotular(i))}">${esc(rotular(i))}</span>
        <span class="den-barra-trilho"><span style="width:${(i.n * 100 / maior).toFixed(1)}%;background:${cor(i)}"></span></span>
        <span class="den-barra-n">${fmtNum(i.n)}</span>
      </div>`).join('') : '<div class="tl-fraco">Sem dados no período.</div>'
  }

  const maiorMes = Math.max(1, ...d.por_mes.map(m => m.n))
  const colunas = d.por_mes.map(m => {
    const [a, mes] = m.mes.split('-')
    return `<div class="den-col" title="${_MESES_CURTOS[Number(mes) - 1]}/${a}: ${m.n}">
      <span class="den-col-n">${m.n || ''}</span>
      <span class="den-col-barra" style="height:${(m.n * 100 / maiorMes).toFixed(1)}%"></span>
      <span class="den-col-rot">${_MESES_CURTOS[Number(mes) - 1]}</span></div>`
  }).join('')

  document.getElementById('den-painel').innerHTML = `
    <div class="den-periodo">
      <div class="field"><label for="dp-de">De</label><input type="date" id="dp-de" value="${esc(d.de)}"></div>
      <div class="field"><label for="dp-ate">Até</label><input type="date" id="dp-ate" value="${esc(d.ate)}"></div>
      <button type="button" class="btn primary" onclick="carregarPainelDen(document.getElementById('dp-de').value, document.getElementById('dp-ate').value)">Atualizar</button>
    </div>
    <div class="metricas den-metricas">
      ${mc(d.total, 'Denúncias', 'sem as canceladas')}
      ${mc(d.pendentes, 'Pendentes', pct(d.pendentes, d.total) + ' do total')}
      ${mc(d.com_gps, 'Com GPS', pct(d.com_gps, d.total) + ' aparecem no mapa')}
      ${mc(d.canceladas, 'Canceladas')}
    </div>
    <div class="den-graficos">
      <div class="bloco den-bloco-largo"><div class="sec-title">Por mês</div><div class="den-colunas">${colunas}</div></div>
      <div class="bloco"><div class="sec-title">Como foram tratadas</div>
        ${barras(d.por_tratativa, i => i.valor || 'Pendente', i => corDaTratativa(i.valor).cor)}</div>
      <div class="bloco"><div class="sec-title">Bairros com mais denúncias</div>${barras(d.por_bairro, i => i.valor || 'Sem bairro')}</div>
      <div class="bloco"><div class="sec-title">Fonte do barulho</div>${barras(d.por_fonte, i => i.valor || 'Não informada')}</div>
    </div>`
}

// ── FICHA ────────────────────────────────────────────────────

/** @param {number} id */
async function abrirDenuncia(id) {
  try {
    const { denuncia: d } = await comCarregando('Abrindo a denúncia…', () => _dnPedir('/api/denuncias/' + id))
    dnState.ficha = d
    renderFichaDen(d)
    openModal('m-den-ver')
  } catch (e) { toast(e.message, 'err') }
}

function renderFichaDen(d) {
  document.getElementById('dv-protocolo').textContent = d.protocolo || 'sem protocolo'
  const linha = (rot, valor) => valor ? `<tr><td>${esc(rot)}</td><td>${valor}</td></tr>` : ''
  const fonte = d.fonte === 'Empresa'
    ? linha('Empresa', esc(d.empresa_nome || '—')) + linha('CNPJ', esc(d.empresa_cnpj || ''))
    : d.fonte === 'Veículo'
      ? linha('Placa', `<span class="mono">${esc(d.placa || '—')}</span>`)
        + linha('Veículo', esc([d.veic_marca, d.veic_modelo, d.veic_cor].filter(Boolean).join(' · ')))
      : linha('Responsável', esc(d.resp_nome || '')) + linha('CPF', esc(d.resp_cpf || ''))

  const docs = d.documentos.length
    ? `<div class="sec-title">Peças geradas</div>${d.documentos.map(x => `
        <button type="button" class="den-doc" onclick="fModalBtn('m-den-ver');abrirDocumento(${Number(x.id)})">
          <span class="proto-badge">${esc(x.numero || 'rascunho')}</span><span>${esc(x.tipo)}</span></button>`).join('')}`
    : ''

  const logs = d.logs.length
    ? `<div class="sec-title">Histórico</div><div class="den-logs">${d.logs.map(l => `
        <div class="den-log"><b>${esc(l.acao)}</b> <span class="tl-cinza">${esc(l.em || '')} · ${esc(l.agente || '')}${l.mat ? ' · ' + esc(l.mat) : ''}</span>
          ${l.mudancas.length ? `<div>${l.mudancas.map(m => '• ' + esc(m)).join('<br>')}</div>` : ''}</div>`).join('')}</div>`
    : ''

  document.getElementById('dv-corpo').innerHTML = `
    <div class="den-ficha-topo">${seloDaTratativa(d.tratativa)}<span class="tl-cinza">${esc(d.data || '')}</span></div>
    <table class="balao4-tab den-ficha-tab">
      ${linha('Endereço', esc(d.endereco) + (d.local_tipo && d.local_tipo !== 'Local Exato' ? ` <span class="tl-cinza">(${esc(d.local_tipo)})</span>` : ''))}
      ${linha('Bairro', esc(d.bairro || ''))}
      ${linha('GPS', d.lat ? `${Number(d.lat).toFixed(6)}, ${Number(d.lng).toFixed(6)}${d.gps_precisao ? ` <span class="tl-cinza">(±${Math.round(d.gps_precisao)} m)</span>` : ''}` : '')}
      ${linha('Fonte sonora', esc(d.fonte || ''))}
      ${fonte}
      ${linha('Agente', esc(d.agente || '') + (d.matricula ? ` <span class="tl-cinza">· ${esc(d.matricula)}</span>` : ''))}
      ${linha('Observação', `<span class="balao4-quebra">${esc(d.obs || '')}</span>`)}
      ${d.cancelada ? linha('Cancelada', esc([d.cancelado_em, d.cancelado_por].filter(Boolean).join(' · '))) : ''}
    </table>
    ${docs}${logs}`

  // A ação principal fica à direita, como no resto do sistema; cancelar, que
  // não tem volta, fica separada à esquerda.
  document.getElementById('dv-acoes').innerHTML = `
    ${d.pode_editar ? '<button class="btn danger" onclick="cancelarDenuncia()">Cancelar denúncia</button>' : ''}
    <div style="flex:1"></div>
    ${d.lat ? '<button class="btn" onclick="verDenunciaNoMapa()">Ver no mapa</button>' : ''}
    ${d.pode_editar ? '<button class="btn" onclick="editarDenuncia()">Editar</button>' : ''}
    ${d.pode_notificar ? '<button class="btn primary" onclick="gerarNotificacaoDaDenuncia()">Gerar notificação</button>' : ''}
    ${!d.pode_editar && !d.pode_notificar ? '<button class="btn" onclick="fModalBtn(\'m-den-ver\')">Fechar</button>' : ''}`
}

/**
 * Cancela, com o motivo se o agente quiser dizer. Como lá, o cancelamento não
 * apaga: a denúncia fica na lista, cinza, e o motivo vai para a observação e
 * para o histórico.
 */
function cancelarDenuncia() {
  const d = dnState.ficha
  if (!d) return
  pedirTexto({
    titulo: 'Cancelar a denúncia ' + (d.protocolo || ''),
    rotulo: 'Motivo do cancelamento (opcional)',
    dica: 'A denúncia continua na lista como cancelada; não dá para desfazer.',
    textoBtn: 'Cancelar denúncia',
    onOk: async motivo => {
      try {
        const r = await _dnPedir(`/api/denuncias/${d.id}/cancelar`, { method: 'POST', body: JSON.stringify({ motivo }) })
        toast(r.message)
        fModalBtn('m-den-ver')
        carregarDenuncias()
        recarregarDenunciasMapa()
      } catch (e) { toast(e.message, 'err') }
    },
  })
}

/** Vai ao ponto da denúncia na aba Mapa, com a camada ligada e o balão aberto. */
function verDenunciaNoMapa() {
  const d = dnState.ficha
  if (!d?.lat) return
  fModalBtn('m-den-ver')
  ligarCamada('denuncias', true)
  dnState.focoNoMapa = d.id
  irPara('mapa')
  // irPara acerta o tamanho do mapa em 60 ms; o enquadramento vem depois.
  setTimeout(() => mapaState.obj?.setView([d.lat, d.lng], 18), 140)
}

// ── GERAR NOTIFICAÇÃO ────────────────────────────────────────

/**
 * Abre a notificação já preenchida com o que a denúncia sabe — o
 * anAbrirNotifDeDenuncia do AppPOSTURAS: o autuado é o responsável (pessoa ou
 * empresa, conforme a fonte), o imóvel é o endereço, a data do fato é a do
 * atendimento, a origem é a denúncia e a lei já vem a 723/2002 (o artigo
 * não: depende da medição).
 *
 * A notificação nasce sem imóvel do cadastro — o fiscal o vincula na aba
 * Imóvel, quando houver.
 */
async function gerarNotificacaoDaDenuncia() {
  const d = dnState.ficha
  if (!d) return
  fModalBtn('m-den-ver')
  await abrirFormDoc({ tipoInicial: 'notificacao' })

  const poe = (id, v) => { const el = document.getElementById(id); if (el && v != null && v !== '') el.value = v }
  const documento = d.resp_cpf || d.empresa_cnpj
  poe('nd-autuado', d.resp_nome || d.empresa_nome)
  // O mascarado (***) não serve de documento: fica em branco para digitar.
  if (documento && !documento.includes('*')) poe('nd-autuado-doc', documento)
  poe('nd-im-logradouro', d.logradouro)
  poe('nd-im-numero', d.numero)
  poe('nd-im-bairro', d.bairro)
  if (d.data_iso) {
    poe('nd-data', d.data_iso.slice(0, 10))
    poe('nd-hora', d.data_iso.slice(11, 16))
    syncDataDoc()
  }

  fdState.denunciaId = d.id
  fdState.denunciaProtocolo = d.protocolo
  poe('nd-origem-den', d.protocolo || 'sem protocolo')
  aplicarOrigemNotifDoc()   // libera a opção "Denúncia" antes de escolhê-la
  document.getElementById('nd-origem-motivo').value = 'denuncia'
  aplicarOrigemNotifDoc()

  const leis = dState.opcoes?.leis || []
  const lei = leis.find(l => l.formulario_tipo === 'lei_723_2002') || leis.find(l => /723/.test(l.rotulo || ''))
  if (lei) selLeiDoc(lei.id)

  // A fonte da denúncia vira a fonte causadora da medição — os combos têm
  // opções diferentes, daí o mapa (o mesmo do AppPOSTURAS) — e o veículo
  // anotado vem junto, para não redigitar a placa.
  const fonte = { 'Residência': 'Residência', 'Empresa': 'Comércio/Serviço', 'Veículo': 'Veículo' }[d.fonte]
  const campoFonte = document.getElementById('nd-ruido-fonte')
  if (campoFonte && fonte) campoFonte.value = fonte
  if (d.fonte === 'Veículo') {
    poe('nd-veic-placa', d.placa)
    poe('nd-veic-marca', d.veic_marca)
    poe('nd-veic-modelo', d.veic_modelo)
    poe('nd-veic-cor', d.veic_cor)
  }
  if (typeof aplicarPosturasDoc === 'function') aplicarPosturasDoc()
}

// ── FORMULÁRIO ───────────────────────────────────────────────

function novaDenuncia() {
  _dnLimparForm()
  document.getElementById('dn-titulo').textContent = 'Nova denúncia'
  document.getElementById('dn-protocolo').hidden = true
  document.getElementById('dn-salvar').textContent = 'Salvar'
  document.getElementById('dn-data').value = dataHojeLocal()
  document.getElementById('dn-hora').value = horaAgoraLocal()
  document.getElementById('dn-agente').textContent = document.querySelector('.perfil-btn .nome')?.textContent || '—'
  openModal('m-den')
  setTimeout(() => document.getElementById('dn-logradouro').focus(), 150)
}

function editarDenuncia() {
  const d = dnState.ficha
  if (!d) return
  fModalBtn('m-den-ver')
  _dnLimparForm()
  const g = id => document.getElementById(id)
  g('dn-titulo').textContent = 'Editar denúncia'
  g('dn-protocolo').textContent = d.protocolo || ''
  g('dn-protocolo').hidden = !d.protocolo
  g('dn-salvar').textContent = 'Atualizar'
  g('dn-id').value = d.id
  g('dn-data').value = (d.data_iso || '').slice(0, 10)
  g('dn-hora').value = (d.data_iso || '').slice(11, 16)
  g('dn-agente').textContent = d.agente || '—'
  g('dn-logradouro').value = d.logradouro || ''
  g('dn-numero').value = d.numero || ''
  g('dn-bairro').value = d.bairro || ''
  g('dn-local').value = d.local_tipo || 'Local Exato'
  if (d.lat) _dnMarcarGps({ lat: d.lat, lon: d.lng, prec: d.gps_precisao })
  fonteDen(d.fonte || 'Residência')
  g('dn-resp-nome').value = d.resp_nome || ''
  g('dn-resp-cpf').value = d.resp_cpf || ''
  g('dn-emp-nome').value = d.empresa_nome || ''
  g('dn-emp-cnpj').value = d.empresa_cnpj || ''
  g('dn-placa').value = d.placa || ''
  g('dn-vmarca').value = d.veic_marca || ''
  g('dn-vmodelo').value = d.veic_modelo || ''
  g('dn-vcor').value = d.veic_cor || ''
  g('dn-tratativa').value = d.tratativa || ''
  g('dn-obs').value = d.obs || ''
  openModal('m-den')
  verificarReincidenciaDen()
}

function _dnLimparForm() {
  for (const id of ['dn-id', 'dn-logradouro', 'dn-numero', 'dn-bairro', 'dn-resp-nome', 'dn-resp-cpf',
    'dn-emp-nome', 'dn-emp-cnpj', 'dn-placa', 'dn-vmarca', 'dn-vmodelo', 'dn-vcor', 'dn-obs']) {
    document.getElementById(id).value = ''
    limparCampoInvalido(id)
  }
  document.getElementById('dn-local').value = 'Local Exato'
  document.getElementById('dn-tratativa').value = ''
  document.getElementById('dn-sugestao').hidden = true
  document.getElementById('dn-aviso-bairro').hidden = true
  dnState.bairrosDaRua = []
  dnState.bairrosCarregados = false
  document.getElementById('dn-reincid').hidden = true
  _dnLimparGps()
  fonteDen('Residência')
}

function fecharFormDen() {
  dnState.voz?.stop()
  fModalBtn('m-den')
}

/**
 * A fonte do barulho decide quais dados do responsável se pedem: pessoa
 * (residência), empresa ou veículo. O servidor grava só os da fonte escolhida.
 * @param {string} fonte
 */
function fonteDen(fonte) {
  dnState.fonte = fonte
  document.querySelectorAll('#dn-fontes button').forEach(b => {
    const esta = b.dataset.fonte === fonte
    b.classList.toggle('at', esta)
    b.setAttribute('aria-checked', esta ? 'true' : 'false')
  })
  document.querySelectorAll('#m-den .den-fonte').forEach(bl => { bl.hidden = bl.dataset.fonte !== fonte })
}

/** O que o formulário manda ao servidor. */
function _dnCorpo() {
  const v = id => document.getElementById(id).value.trim()
  return {
    data_hora: `${v('dn-data')}T${v('dn-hora') || '00:00'}`,
    logradouro: v('dn-logradouro'), numero: v('dn-numero') || null, bairro: v('dn-bairro'),
    local_tipo: v('dn-local'),
    lat: v('dn-lat') ? Number(v('dn-lat')) : null,
    lng: v('dn-lng') ? Number(v('dn-lng')) : null,
    gps_precisao: v('dn-prec') ? Number(v('dn-prec')) : null,
    fonte_sonora: dnState.fonte,
    resp_nome: v('dn-resp-nome') || null, resp_cpf: v('dn-resp-cpf') || null,
    empresa_nome: v('dn-emp-nome') || null, empresa_cnpj: v('dn-emp-cnpj') || null,
    placa: v('dn-placa') || null, veic_marca: v('dn-vmarca') || null,
    veic_modelo: v('dn-vmodelo') || null, veic_cor: v('dn-vcor') || null,
    tratativa: v('dn-tratativa') || null,
    obs: v('dn-obs') || null,
  }
}

/** Confere o mínimo (data, rua e bairro, como lá) e pede confirmação. */
function salvarDenuncia() {
  const g = id => document.getElementById(id)
  if (!g('dn-data').value) { exigirCampo('dn-data', 'Informe a data do atendimento.'); return }
  if (!g('dn-logradouro').value.trim()) { exigirCampo('dn-logradouro', 'Informe o logradouro.'); return }
  if (!g('dn-bairro').value.trim()) { exigirCampo('dn-bairro', 'Informe o bairro.'); return }
  const doc = dnState.fonte === 'Empresa' ? g('dn-emp-cnpj') : g('dn-resp-cpf')
  if (dnState.fonte !== 'Veículo' && !conferirCpfCnpjCampo(doc)) { doc.focus(); return }

  const id = g('dn-id').value
  confirmarAcao({
    titulo: id ? 'Atualizar denúncia' : 'Registrar denúncia',
    mensagem: id ? 'Confirma a atualização deste registro?' : 'Confirma o registro desta denúncia?',
    textoBtn: id ? 'Atualizar' : 'Salvar',
    onConfirm: async () => {
      try {
        const r = await comCarregando(id ? 'Atualizando…' : 'Registrando…', () =>
          _dnPedir(id ? '/api/denuncias/' + id : '/api/denuncias', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(_dnCorpo()) }))
        toast(r.message)
        fecharFormDen()
        if (document.getElementById('t-denuncias')?.classList.contains('at')) prepararDenuncias()
        recarregarDenunciasMapa()
      } catch (e) { toast(e.message, 'err') }
    },
  })
}

// ── RUA → BAIRRO ─────────────────────────────────────────────
//
// A relação rua↔bairro do Posturas (Parâmetros → Ruas e bairros): escolhida
// a rua, os bairros por onde ela passa aparecem primeiro, para um toque; se
// for um só e o bairro estiver vazio, ele vem preenchido.

let _dnRuaTimer = null
/** Enquanto se digita: sugestões de rua e a reincidência. */
function ruaDigitadaDen() {
  clearTimeout(_dnRuaTimer)
  _dnRuaTimer = setTimeout(async () => {
    const q = document.getElementById('dn-logradouro').value.trim()
    if (q.length >= 2) {
      try {
        const d = await _dnPedir('/api/enderecos/logradouros?q=' + encodeURIComponent(q))
        document.getElementById('dn-lista-ruas').innerHTML =
          d.logradouros.map(l => `<option value="${esc(l.nome)}">`).join('')
      } catch { /* sem sugestão; o campo continua aceitando o que se digitar */ }
    }
    ruaEscolhidaDen()
  }, 250)
}

/**
 * Rua definida: os bairros ligados a ela, e a reincidência no endereço. Rua
 * com bairros ligados sugere SÓ eles; sem ligação, a lista é a da aba Bairros.
 */
async function ruaEscolhidaDen() {
  const rua = document.getElementById('dn-logradouro').value.trim()
  const caixa = document.getElementById('dn-sugestao')
  agendarReincidenciaDen()
  dnState.bairrosDaRua = []
  if (!rua) { caixa.hidden = true; dnState.bairrosCarregados = false; conferirBairroDen(); return }
  try {
    const d = await _dnPedir('/api/enderecos/bairros?rua=' + encodeURIComponent(rua))
    const ligados = d.da_rua ? d.bairros.map(b => b.nome) : []
    dnState.bairrosDaRua = ligados
    document.getElementById('dn-lista-bairros').innerHTML = d.bairros.map(b => `<option value="${esc(b.nome)}">`).join('')
    dnState.bairrosCarregados = true
    const campo = document.getElementById('dn-bairro')
    if (ligados.length === 1 && !campo.value.trim()) {
      campo.value = ligados[0]
      caixa.hidden = true
      conferirBairroDen()
      return
    }
    caixa.hidden = !ligados.length
    caixa.innerHTML = ligados.length ? '<span>Bairros desta rua:</span> ' + ligados.map(b =>
      `<button type="button" onclick="escolherBairroDen(${jsArg(b)})">${esc(b)}</button>`).join('') : ''
    conferirBairroDen()
  } catch { caixa.hidden = true }
}

/** @param {string} b */
function escolherBairroDen(b) {
  document.getElementById('dn-bairro').value = b
  document.getElementById('dn-sugestao').hidden = true
  conferirBairroDen()
}

/**
 * Bairro que a rua não tem ligado: pode ser a ponta de rua que ninguém ligou,
 * então grava — só avisa, e o administrador acerta a ligação depois. Bairro
 * fora da aba Bairros quem recusa é o servidor.
 */
function conferirBairroDen() {
  const aviso = document.getElementById('dn-aviso-bairro')
  const b = document.getElementById('dn-bairro').value.trim().toUpperCase()
  const ligados = dnState.bairrosDaRua || []
  const fora = !!b && ligados.length > 0 && !ligados.includes(b)
  aviso.hidden = !fora
  aviso.textContent = fora ? 'Este bairro não está ligado a esta rua em Ruas e bairros. Confira — dá para gravar assim mesmo.' : ''
}

/** A lista de bairros para o campo (a da aba Bairros), uma vez por abertura da tela. */
async function carregarBairrosDen() {
  if (dnState.bairrosCarregados) return
  dnState.bairrosCarregados = true
  try {
    const d = await _dnPedir('/api/enderecos/bairros')
    document.getElementById('dn-lista-bairros').innerHTML = d.bairros.map(b => `<option value="${esc(b.nome)}">`).join('')
  } catch { dnState.bairrosCarregados = false }
}

// ── REINCIDÊNCIA ─────────────────────────────────────────────
//
// O alerta do AppPOSTURAS: ao digitar o endereço, as últimas denúncias no
// mesmo lugar (mesma rua e, havendo, mesmo número). É o que diz ao agente,
// ainda na porta, que aquele som é de novo o mesmo.

let _dnReincTimer = null
function agendarReincidenciaDen() {
  clearTimeout(_dnReincTimer)
  _dnReincTimer = setTimeout(verificarReincidenciaDen, 350)
}

async function verificarReincidenciaDen() {
  const caixa = document.getElementById('dn-reincid')
  const rua = document.getElementById('dn-logradouro').value.trim()
  if (!rua) { caixa.hidden = true; return }
  const p = new URLSearchParams({ logradouro: rua })
  const numero = document.getElementById('dn-numero').value.trim()
  if (numero) p.set('numero', numero)
  const id = document.getElementById('dn-id').value
  if (id) p.set('exceto', id)
  try {
    const d = await _dnPedir('/api/denuncias/reincidencia?' + p)
    caixa.hidden = !d.denuncias.length
    caixa.innerHTML = d.denuncias.length ? `
      <div class="den-reincid-tit">Reincidência neste endereço${numero ? '' : ' (na rua toda)'}</div>
      ${d.denuncias.map(x => `<div class="den-reincid-item">
        <span class="proto-badge">${esc(x.protocolo || '—')}</span>${seloDaTratativa(x.tratativa)}<span class="tl-cinza">${esc(x.data || '')}</span>
      </div>`).join('')}` : ''
  } catch { caixa.hidden = true }
}

// ── GPS ──────────────────────────────────────────────────────

/**
 * Marca a posição pelo GPS (a melhor leitura em alguns segundos, como na
 * vistoria). Segundo toque com o GPS já marcado: tira — o agente que marcou
 * longe do local precisa poder desfazer.
 */
async function gpsDen() {
  if (document.getElementById('dn-lat').value) { _dnLimparGps(); return }
  if (!navigator.geolocation) { toast('Este aparelho não informa a localização.', 'err'); return }
  const txt = document.getElementById('dn-gps-txt')
  txt.textContent = 'Lendo…'
  try {
    const p = await melhorPosicaoGps({ aCadaMelhora: q => { txt.textContent = '±' + Math.round(q.prec) + ' m' } })
    _dnMarcarGps(p)
    if (p.prec > GPS_FRACO_M) toast(`GPS fraco (±${Math.round(p.prec)} m). Confira o endereço digitado.`, 'aviso')
  } catch (e) {
    _dnLimparGps()
    toast(e?.code === 1 ? 'Permita a localização para marcar o GPS.' : 'Não foi possível ler o GPS.', 'err')
  }
}

/** @param {{lat:number, lon:number, prec?:number}} p */
function _dnMarcarGps(p) {
  document.getElementById('dn-lat').value = p.lat
  document.getElementById('dn-lng').value = p.lon
  document.getElementById('dn-prec').value = p.prec ?? ''
  document.getElementById('dn-gps-txt').textContent = 'Tirar GPS'
  document.getElementById('dn-gps').classList.add('marcado')
  const info = document.getElementById('dn-gps-info')
  info.hidden = false
  info.textContent = `GPS: ${Number(p.lat).toFixed(6)}, ${Number(p.lon).toFixed(6)}${p.prec ? ` (±${Math.round(p.prec)} m)` : ''}`
}

function _dnLimparGps() {
  for (const id of ['dn-lat', 'dn-lng', 'dn-prec']) document.getElementById(id).value = ''
  document.getElementById('dn-gps-txt').textContent = 'GPS'
  document.getElementById('dn-gps').classList.remove('marcado')
  document.getElementById('dn-gps-info').hidden = true
}

// ── DITADO POR VOZ ───────────────────────────────────────────

/**
 * Dita a observação (o voz.js do AppPOSTURAS, só a parte da observação). Fala
 * na porta da casa, com a mão ocupada, é o caso de uso. O Safari do iPhone não
 * tem o reconhecimento: lá vale o microfone do próprio teclado.
 */
function ditarObsDen() {
  const Reconhecer = window.SpeechRecognition || window.webkitSpeechRecognition
  if (!Reconhecer) { toast('Este navegador não dita por voz. No iPhone, use o microfone do teclado.', 'aviso'); return }
  if (dnState.voz) { dnState.voz.stop(); return }

  const campo = document.getElementById('dn-obs')
  const marcar = ligado => {
    document.getElementById('dn-voz').classList.toggle('ouvindo', ligado)
    document.getElementById('dn-voz-txt').textContent = ligado ? 'Parar' : 'Falar'
  }
  const r = new Reconhecer()
  r.lang = 'pt-BR'
  r.continuous = true
  r.interimResults = false
  r.onresult = e => {
    for (let i = e.resultIndex; i < e.results.length; i++) {
      if (!e.results[i].isFinal) continue
      const frase = e.results[i][0].transcript.trim().toUpperCase()
      campo.value = (campo.value.trim() ? campo.value.trimEnd() + ' ' : '') + frase
    }
  }
  r.onerror = e => { if (e.error === 'not-allowed') toast('Permita o microfone para ditar.', 'err') }
  r.onend = () => { dnState.voz = null; marcar(false) }
  dnState.voz = r
  marcar(true)
  r.start()
}

// ── CAMADA NO MAPA ───────────────────────────────────────────
//
// Um mapa só para os dois módulos: em Posturas ele ganha a camada Denúncias,
// ligada por padrão, com um ponto por denúncia com GPS na cor da tratativa.
// Em Obras a camada nem é registrada — o usuário de Obras não tem acesso às
// denúncias (rota com `modulo:posturas`).

const dnMapa = { /** @type {L.LayerGroup|null} */ camada: null }

if (window.MODULO === 'posturas') {
  registrarCamada({
    id: 'denuncias', grupo: 'Posturas', rotulo: 'Denúncias de perturbação', padrao: true,
    aoMudar: ligada => { ligada ? recarregarDenunciasMapa() : dnMapa.camada?.clearLayers() },
  })
  // O mapa nasce no bootstrap (app.js); os pontos acompanham o movimento dele.
  window.addEventListener('load', () => {
    mapaState?.obj?.on('moveend', recarregarDenunciasMapa)
    recarregarDenunciasMapa()
  })
}

let _dnMapaTimer = null
function recarregarDenunciasMapa() {
  if (window.MODULO !== 'posturas') return
  clearTimeout(_dnMapaTimer)
  _dnMapaTimer = setTimeout(_dnCarregarMapa, 250)
}

async function _dnCarregarMapa() {
  const mapa = mapaState?.obj
  if (!mapa || !camadaLigada('denuncias')) return
  if (!dnMapa.camada) {
    if (!mapa.getPane('denuncias')) mapa.createPane('denuncias').style.zIndex = 625
    dnMapa.camada = L.layerGroup([], { pane: 'denuncias' }).addTo(mapa)
  }
  // Sem centro nem zoom (o mapa ainda não foi enquadrado) o Leaflet não sabe
  // os limites; os pontos vêm no primeiro moveend.
  if (!mapa._loaded) return
  if (mapa.getZoom() < 13) { dnMapa.camada.clearLayers(); return }
  const b = mapa.getBounds()
  const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].map(n => n.toFixed(6)).join(',')
  try {
    const d = await _dnPedir('/api/denuncias/mapa?bbox=' + bbox)
    dnMapa.camada.clearLayers()
    for (const x of d.denuncias) {
      const m = L.circleMarker([x.lat, x.lng], {
        pane: 'denuncias', radius: 7, weight: 2, color: '#fff',
        fillColor: corDaTratativa(x.tratativa).cor, fillOpacity: .95,
      }).bindPopup(() => _dnBalao(x), { className: 'popup-lote', maxWidth: 280 }).addTo(dnMapa.camada)
      if (dnState.focoNoMapa === x.id) { dnState.focoNoMapa = null; setTimeout(() => m.openPopup(), 50) }
    }
  } catch (e) { console.warn('denúncias no mapa:', e.message) }
}

/** O balão do ponto, no mesmo modelo do balão do lote (D4). */
function _dnBalao(x) {
  return `<div class="balao4">
    <div class="balao4-cab den-balao-cab" style="--den-cor:${corDaTratativa(x.tratativa).cor}">
      <b>${esc(x.protocolo || 'Denúncia')}</b><span>${esc(x.tratativa || 'Pendente')}</span></div>
    <div class="balao4-corpo">
      <table class="balao4-tab">
        <tr><td>Endereço</td><td>${esc(x.endereco)}</td></tr>
        <tr><td>Bairro</td><td>${esc(x.bairro || '—')}</td></tr>
        <tr><td>Data</td><td>${esc(x.data || '')}</td></tr>
        ${x.fonte ? `<tr><td>Fonte</td><td>${esc(x.fonte)}</td></tr>` : ''}
      </table>
      <div class="balao4-acoes" style="grid-template-columns:1fr">
        <button type="button" class="balao-q" onclick="abrirDenuncia(${Number(x.id)})">Abrir a denúncia</button>
      </div>
    </div></div>`
}
