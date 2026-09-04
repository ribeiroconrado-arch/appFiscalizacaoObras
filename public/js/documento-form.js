// ══════════════════════════════════════════════
// MÓDULO: FORMULÁRIO DE DOCUMENTO
//
// Estrutura do formulário de Notificação do AppPOSTURAS, adaptada às quatro
// peças de obras (Vistoria, Notificação, Auto de Infração, Auto de Embargo):
// cabeçalho e rodapé fixos, corpo rolável, abas em sequência e um rodapé que
// muda conforme o estado do documento.
//
// A regra central, herdada de lá: o que já está GRAVADO só volta a ser
// editável clicando em "Editar". Formulário gravado que continua aberto para
// digitação convida à alteração acidental de peça de processo — e nada na tela
// distingue o que foi digitado agora do que já estava lá.
//
// Estados:
//   novo      — nada gravado ainda. Campos livres. Rodapé: Gravar.
//   rascunho  — gravado, sem número. Campos travados. Rodapé: Opções|Editar|Lavrar.
//   editando  — rascunho reaberto para alteração. Rodapé: Sair|Gravar.
//   lavrado   — número atribuído, prazo congelado. Só leitura. Rodapé: Opções.
// ══════════════════════════════════════════════

/** Ordem das abas. É ela que define o que «/‹/›/» percorrem. */
const ABAS_DOC = ['autuado', 'imovel', 'infracao', 'anexos', 'resumo']

const fdState = {
  /** @type {'novo'|'rascunho'|'lavrado'} */ estado: 'novo',
  /** @type {boolean} */ editando: false,
  /** @type {number|null} */ id: null,
  /** @type {string} */ aba: 'autuado',
  /** @type {Object|null} imóvel escolhido no mapa ou na busca */ lote: null,
  /** @type {number[]} */ artigos: [],
  /** @type {number|null} */ vistoriaId: null,
  /** @type {number} */ anexos: 0,
}

// ── ABERTURA ─────────────────────────────────────────────────

/**
 * "Novo documento": primeiro escolhe-se a PEÇA, depois se preenche.
 *
 * O tipo não é mais um campo perdido no meio do formulário porque ele decide
 * o formulário inteiro — uma vistoria não tem multa, um auto tem prazo de
 * defesa, uma notificação tem prazo de cumprimento. Perguntar primeiro é o
 * que evita preencher meia peça e descobrir que era outra.
 *
 * O imóvel NÃO é exigido aqui. Se houver lote selecionado no mapa ele já
 * entra; se não, o documento nasce sem imóvel e a inscrição é informada
 * depois, na aba Imóvel. A obrigatoriedade existe, mas na lavratura.
 */
async function novoDocumento(ev) {
  // O botão é guardado ANTES da espera: `ev.currentTarget` só vale enquanto o
  // evento está sendo despachado e já é `null` quando o await volta.
  const botao = ev.currentTarget

  // O MESMO botão existe na ficha do imóvel e na tela de Documentos. Quem
  // abriu de dentro da ficha volta para ela ao fechar; quem abriu da lista,
  // não. Ler o ancestral é mais barato — e mais difícil de esquecer — do que
  // um segundo parâmetro que cada chamada teria de passar certo.
  const daFicha = !!botao?.closest?.('#m-ficha')

  const o = await carregarOpcoes()

  abrirMenuNovo(botao, o.tipos.map(t => ({
    rotulo: t.rotulo,
    obs: OBS_TIPO_DOC[t.valor] || '',
    icone: ICO_TIPO_DOC[t.valor] || ICO_TIPO_DOC.padrao,
    // Traço antes do primeiro auto: acima ficam os atos que AVISAM, abaixo os
    // que SANCIONAM. Ver o comentário em OBS_TIPO_DOC.
    separar: t.valor === 'auto_embargo',
    // "Vistoria" nao e uma peca a redigir: e o ATO de campo, com checklist,
    // area aferida e fotos — e e dele que as outras quatro nascem. Mandar este
    // item para o formulario de documento, como os demais, deixou a tela de
    // vistoria inalcancavel a partir da ficha desde 7d9c0a3, quando o botao
    // "Nova vistoria" foi absorvido por este menu.
    acao: () => {
      if (daFicha) { lembrarFichaDeOrigem() }
      return t.valor === 'vistoria' ? novaVistoria() : escolherTipoDoc(t.valor)
    },
  })))
}

/**
 * Uma linha por peça, dizendo para que ela serve.
 *
 * Quem abre o menu raramente está em dúvida sobre onde clicar; está em dúvida
 * sobre QUAL peça lavrar. Notificação dá prazo, auto aplica sanção — escolher
 * errado não é um erro de tela, é vício no processo administrativo, e ele só
 * aparece meses depois, quando a defesa aponta.
 *
 * O texto é curto de propósito: o menu não é o lugar de ensinar direito
 * administrativo, é o lugar de impedir a troca grosseira.
 */
const OBS_TIPO_DOC = {
  vistoria: 'Registra o que foi visto no imóvel.',
  notificacao: 'Dá prazo para regularizar, antes da multa.',
  notificacao_embargo: 'Avisa que a obra deve parar.',
  auto_embargo: 'Para a obra de imediato.',
  auto_infracao: 'Aplica a multa, com memória de cálculo.',
}

/** Ícone de cada peça, no menu de criação. */
const ICO_TIPO_DOC = {
  vistoria: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
    stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>`,
  notificacao: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
    stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
    <path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/></svg>`,
  notificacao_embargo: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
    stroke-linecap="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 9h8M8 13h5"/>
    <path d="M4 4l16 16"/></svg>`,
  auto_infracao: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
    stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
    <path d="M12 9v4M12 17h.01"/></svg>`,
  auto_embargo: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
    stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>`,
  padrao: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
    stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
    <path d="M14 2v6h6"/></svg>`,
}

/** @param {string} tipo */
async function escolherTipoDoc(tipo) {
  await abrirFormDoc({ lote: state.selecionado?.properties || null, tipoInicial: tipo })
}

/**
 * Abre o formulário. Sem `documento`, é um documento novo; com ele, a ficha
 * carregada de /api/documentos/{id}.
 *
 * @param {{lote?:Object, documento?:Object}} opts
 */
/**
 * @param {Object}      [o.lote]         imóvel da peça
 * @param {Object}      [o.documento]    peça existente, para abrir em leitura
 * @param {string}      [o.tipoInicial]  tipo já escolhido no menu
 * @param {number|null} [o.vistoria]     A VISTORIA DE ORIGEM.
 *
 *   Com ela, a peça nasce presa àquela vistoria. Sem ela, vale o comportamento
 *   de sempre: a última vistoria do imóvel. A diferença importa numa obra
 *   visitada duas vezes no mês — o auto sairia amarrado à visita errada, e
 *   ninguém perceberia, porque a tela não dizia a qual vistoria se prendeu.
 */
async function abrirFormDoc({ lote = null, documento = null, tipoInicial = null, vistoria = null } = {}) {
  const o = await carregarOpcoes()

  fdState.editando = false
  fdState.aba = 'autuado'

  if (documento) {
    fdState.estado = documento.status.valor === 'rascunho' ? 'rascunho' : 'lavrado'
    fdState.id = documento.id
    fdState.artigos = []
    fdState.anexos = documento.anexos || 0
    fdState.lote = {
      id: null,
      inscricao: documento.imovel.inscricao,
      bairro: documento.imovel.bairro,
      quadra: documento.imovel.quadra,
      numero_lote: documento.imovel.lote,
      area_gis_m2: documento.imovel.terreno,
    }
  } else {
    fdState.estado = 'novo'
    fdState.id = null
    fdState.artigos = []
    fdState.anexos = 0
    fdState.vistoriaId = null
    fdState.lote = lote
  }

  // Tipos: os quatro de obras. A lista vem do servidor, não fixa aqui.
  document.getElementById('nd-tipo').innerHTML =
    o.tipos.map(t => `<option value="${t.valor}">${esc(t.rotulo)}</option>`).join('')
  document.getElementById('nd-lei').innerHTML =
    '<option value="">— selecione —</option>' +
    o.leis.map(l => `<option value="${l.id}">${esc(l.rotulo)}</option>`).join('')

  if (documento) {
    preencherFormDoc(documento)
  } else {
    if (tipoInicial) document.getElementById('nd-tipo').value = tipoInicial
    limparFormDoc()
  }

  irAbaDoc('autuado')
  renderCabecalhoDoc(documento)
  aplicarEstadoDoc()
  openModal('m-doc')

  // `?.` porque a peça PODE nascer sem imóvel: é o caminho de quem abre o
  // documento com o que tem em campo e amarra o lote depois, pela aba Imóvel
  // (ver DocumentoController::storeSemLote). Sem imóvel não há última vistoria
  // a consultar, e a função já trata o id ausente limpando a caixa.
  if (documento) { return }

  if (vistoria) {
    await sugerirDaVistoria(vistoria)
  } else {
    await sugerirDaUltimaVistoria(fdState.lote?.id ?? null)
  }
}

/** Campos em branco, com os padrões de um documento novo. */
function limparFormDoc() {
  document.getElementById('nd-autuado').value = ''
  document.getElementById('nd-autuado-doc').value = ''
  document.getElementById('nd-endereco').value = ''
  document.getElementById('nd-descricao').value = ''
  document.getElementById('nd-data').value = dataHojeLocal()
  document.getElementById('nd-hora').value = horaAgoraLocal()
  document.getElementById('nd-prazo').value = 10
  syncDataDoc()

  // Terreno vem do GIS e o fiscal só confere; construída não existe em
  // cadastro nenhum — só a medição em campo é confiável para basear multa.
  const p = fdState.lote
  document.getElementById('nd-area-terreno').value = p?.area_gis_m2 ? Number(p.area_gis_m2).toFixed(2) : ''
  document.getElementById('nd-area-construida').value = ''
  document.getElementById('nd-bloco-area').style.display = 'none'
  document.getElementById('nd-memoria-calculo').innerHTML = ''
  document.getElementById('nd-artigos').innerHTML =
    '<div class="lista-vazia">Escolha a lei para ver os artigos.</div>'

  renderImovelDoc()
  trocarTipoDoc()
}

/** @param {Object} d ficha vinda de /api/documentos/{id} */
function preencherFormDoc(d) {
  document.getElementById('nd-tipo').value = d.tipo
  document.getElementById('nd-autuado').value = d.autuado.nome || ''
  document.getElementById('nd-autuado-doc').value = d.autuado.documento || ''
  document.getElementById('nd-endereco').value = d.imovel.endereco || ''
  document.getElementById('nd-descricao').value = d.descricao || ''
  document.getElementById('nd-area-terreno').value = d.imovel.terreno ?? ''
  document.getElementById('nd-area-construida').value = d.imovel.construida ?? ''

  const [dia, hora] = (d.data_fato || ' ').split(' ')
  if (dia) {
    const [dd, mm, aa] = dia.split('/')
    document.getElementById('nd-data').value = `${aa}-${mm}-${dd}`
  }
  document.getElementById('nd-hora').value = hora || ''
  syncDataDoc()

  renderImovelDoc()
  trocarTipoDoc()
  renderAnexosDoc()
}

/**
 * Bloco do imóvel.
 *
 * Com imóvel: leitura, ninguém edita — trocá-lo faria o documento mudar de
 * objeto no meio da lavratura.
 *
 * Sem imóvel: um localizador. É o caminho de quem abriu a peça em campo antes
 * de identificar o lote. Ele fica visível até o vínculo existir, e o aviso diz
 * que a lavratura depende dele — melhor saber agora do que no botão Lavrar.
 */
function renderImovelDoc() {
  const alvo = document.getElementById('nd-imovel-dados')
  const p = fdState.lote

  if (!p) {
    alvo.innerHTML = `
      <div class="doc-sem-imovel">
        <b>Imóvel ainda não identificado.</b>
        O documento pode ser gravado assim, mas a lavratura exige o imóvel.
      </div>
      <div class="par-busca" style="margin-top:10px">
        <input type="text" id="nd-imovel-termo" placeholder="Inscrição imobiliária ou “quadra lote”"
               onkeydown="if(event.key==='Enter'){event.preventDefault();procurarImovelDoc()}">
        <button type="button" class="btn out-verde sm" onclick="procurarImovelDoc()">Localizar</button>
      </div>
      <div id="nd-imovel-resultado" class="leg" style="margin-top:8px"></div>`
    return
  }

  const linhas = [
    ['Inscrição imobiliária', p.inscricao || montarInscricao(p)],
    ['Bairro', p.bairro],
    ['Quadra / Lote', `${p.quadra ?? '—'} / ${p.numero_lote ?? '—'}`],
    ['Área do terreno', p.area_gis_m2 ? fmtNum(p.area_gis_m2) + ' m²' : null],
  ].filter(([, v]) => v)

  alvo.innerHTML = linhas.map(([r, v]) =>
    `<div><span class="df-rot">${esc(r)}</span><span class="df-val">${esc(v)}</span></div>`).join('')
}

/** Procura o imóvel pelo termo digitado e lista os acertos para escolha. */
async function procurarImovelDoc() {
  const termo = document.getElementById('nd-imovel-termo').value.trim()
  const saida = document.getElementById('nd-imovel-resultado')
  if (!termo) { saida.textContent = 'Digite a inscrição imobiliária ou “quadra lote”.'; return }

  saida.textContent = 'Procurando…'
  try {
    const r = await fetch('/api/imoveis/busca?' + new URLSearchParams({ termo }),
      { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)
    if (!d.imoveis.length) { saida.textContent = 'Nenhum imóvel encontrado.'; return }

    saida.innerHTML = d.imoveis.slice(0, 12).map(i => `
      <button type="button" class="doc-imovel-op" onclick="vincularImovelDoc(${i.id})">
        <b>${esc(i.inscricao || 'sem inscrição')}</b>
        ${esc(i.bairro || '')} · Q ${esc(i.quadra ?? '—')} · Lt ${esc(i.lote ?? '—')}
      </button>`).join('')
      + (d.total > 12 ? `<div class="leg">${d.total} acertos — refine o termo.</div>` : '')
  } catch (e) {
    console.error(e)
    saida.textContent = e.message || 'Falha na busca.'
  }
}

/** Amarra o imóvel escolhido ao documento. @param {number} id */
async function vincularImovelDoc(id) {
  try {
    const r = await fetch('/api/imoveis/' + id, { headers: { Accept: 'application/json' } })
    const f = await r.json()
    if (!r.ok) throw new Error(f.message || 'HTTP ' + r.status)

    fdState.lote = {
      id: f.id, inscricao: f.inscricao, bairro: f.bairro,
      quadra: f.quadra, numero_lote: f.lote, area_gis_m2: f.area,
    }
    renderImovelDoc()

    // A área do terreno acompanha o imóvel: ela é base de multa, e deixá-la
    // com o valor de outro lote produziria conta errada.
    const campoArea = document.getElementById('nd-area-terreno')
    if (f.area && !campoArea.value) campoArea.value = Number(f.area).toFixed(2)
    recalcularMultaDoc()

    toast('Imóvel vinculado ao documento')
  } catch (e) {
    console.error(e)
    toast(e.message || 'Não foi possível vincular o imóvel', 'err')
  }
}

function renderAnexosDoc() {
  document.getElementById('nd-anexos').innerHTML = fdState.anexos
    ? `<div class="df-val">${fdState.anexos} evidência(s) da vistoria vinculada entram na via impressa.</div>`
    : '<div class="lista-vazia">Nenhum anexo — a vistoria vinculada não tem evidência registrada.</div>'
}

// ── ABAS ─────────────────────────────────────────────────────

/** @param {string} nome */
function irAbaDoc(nome) {
  if (!ABAS_DOC.includes(nome)) return
  fdState.aba = nome

  document.querySelectorAll('#fd-tabs .doc-tab')
    .forEach(b => b.classList.toggle('ativa', b.dataset.aba === nome))
  document.querySelectorAll('#fd-body .doc-painel')
    .forEach(p => p.classList.toggle('ativa', p.id === 'fdp-' + nome))

  // O corpo volta ao topo: a aba nova começa onde a anterior tinha rolado.
  document.getElementById('fd-body').scrollTop = 0

  pintarSetasDeAba('fd-setas', ABAS_DOC, nome)

  if (nome === 'resumo') renderResumoDoc()
  renderRodapeDoc()
}

/** @param {number} passo -1 volta, +1 avança */
function passoAbaDoc(passo) {
  const i = ABAS_DOC.indexOf(fdState.aba)
  const alvo = ABAS_DOC[i + passo]
  if (alvo) irAbaDoc(alvo)
}

/** As setas do cabeçalho. @param {'primeira'|'anterior'|'proxima'|'ultima'} destino */
function irAbaDocPara(destino) {
  irAbaDoc(abaAlvo(ABAS_DOC, fdState.aba, destino))
}

// ── ESTADO E TRAVAMENTO ──────────────────────────────────────

/** Aplica o estado corrente ao cabeçalho, aos campos e ao rodapé. */
function aplicarEstadoDoc() {
  travarCamposDoc(fdState.estado !== 'novo' && !fdState.editando)
  renderRodapeDoc()
}

/**
 * Trava ou libera os campos marcados com data-lock.
 *
 * O imóvel nunca é liberado, mesmo em edição: trocá-lo faria o documento
 * mudar de objeto no meio da lavratura, e o número já emitido passaria a
 * apontar para outro lote.
 *
 * @param {boolean} travar
 */
function travarCamposDoc(travar) {
  document.querySelectorAll('#m-doc [data-lock]').forEach(el => { el.disabled = travar })
  // Os checkboxes de artigo são gerados a cada render, fora do data-lock.
  document.querySelectorAll('#nd-artigos input[type=checkbox]').forEach(el => { el.disabled = travar })
  document.getElementById('m-doc').classList.toggle('so-leitura', travar)
}

/** Cabeçalho: tipo, número, selo de estado, data de registro e agente. */
function renderCabecalhoDoc(d) {
  const tipoSel = document.getElementById('nd-tipo')
  const rotulo = tipoSel.options[tipoSel.selectedIndex]?.textContent || 'Documento'

  document.getElementById('fd-tipo-rotulo').textContent = rotulo
  // O ícone segue o tipo: quem abre um auto de embargo reconhece a peça pelo
  // símbolo antes de ler o nome dela.
  const ico = document.getElementById('fd-icone')
  if (ico) { ico.innerHTML = ICO_TIPO_DOC[tipoSel.value] || ICO_TIPO_DOC.padrao }
  document.getElementById('fd-numero').textContent = d?.numero || 'Sem número'
  document.getElementById('fd-registro').textContent = d?.criado_em || 'agora'
  document.getElementById('fd-agente').textContent =
    d?.agente ? d.agente + (d.matricula ? ' · ' + d.matricula : '') : (window.USUARIO_NOME || '—')

  const selo = document.getElementById('fd-status')
  const st = d?.status || { texto: 'Novo', classe: 'bd-in' }
  selo.className = 'badge ' + st.classe
  selo.textContent = fdState.editando ? 'Editando' : st.texto

  const prazoWrap = document.getElementById('fd-prazo-wrap')
  if (d?.prazo_badge) {
    prazoWrap.hidden = false
    document.getElementById('fd-prazo-badge').className = 'badge ' + d.prazo_badge.classe
    document.getElementById('fd-prazo-badge').textContent = d.prazo_badge.texto
  } else {
    prazoWrap.hidden = true
  }
}

/**
 * Quais botões o rodapé mostra agora.
 *
 * Espelha o _renderRodape do AppPOSTURAS: navegação sempre visível (só
 * desabilitada nas pontas, para dizer "aqui é o início/fim" em vez de o botão
 * simplesmente sumir), e as ações conforme o estado.
 */
function renderRodapeDoc() {
  const i = ABAS_DOC.indexOf(fdState.aba)
  document.getElementById('fd-primeira').disabled = i <= 0
  document.getElementById('fd-voltar').disabled = i <= 0
  document.getElementById('fd-avancar').disabled = i >= ABAS_DOC.length - 1
  document.getElementById('fd-ultima').disabled = i >= ABAS_DOC.length - 1

  const mostrar = (id, v) => { document.getElementById(id).hidden = !v }

  const novo = fdState.estado === 'novo'
  const rascunho = fdState.estado === 'rascunho'
  const lavrado = fdState.estado === 'lavrado'

  mostrar('fd-gravar', novo || fdState.editando)
  mostrar('fd-sair-edicao', fdState.editando)
  mostrar('fd-editar', rascunho && !fdState.editando)
  mostrar('fd-lavrar', rascunho && !fdState.editando)
  // Opções depende de haver documento gravado: antes disso não há nada para
  // imprimir, anular ou excluir.
  mostrar('fd-opcoes-wrap', (rascunho || lavrado) && !fdState.editando)
}

// ── AÇÕES ────────────────────────────────────────────────────

function editarDoc() {
  fdState.editando = true
  aplicarEstadoDoc()
  renderCabecalhoDoc(dFicha.doc)
  toast('Documento aberto para edição')
}

function sairEdicaoDoc() {
  fdState.editando = false
  aplicarEstadoDoc()
  // Recarrega do servidor: sair da edição tem de descartar o que foi digitado
  // e não gravado, senão a tela mostra alteração que o banco não tem.
  if (fdState.id) abrirDocumento(fdState.id)
}

/** Grava um novo documento ou atualiza o rascunho aberto. */
async function gravarDoc() {
  const tipo = document.getElementById('nd-tipo').value
  const t = dState.opcoes.tipos.find(x => x.valor === tipo)

  const corpo = {
    tipo,
    data_fato: document.getElementById('nd-datahora').value,
    autuado_nome: document.getElementById('nd-autuado').value,
    autuado_documento: document.getElementById('nd-autuado-doc').value,
    endereco: document.getElementById('nd-endereco').value,
    descricao: document.getElementById('nd-descricao').value,
    artigos: fdState.artigos,
  }
  const lei = document.getElementById('nd-lei').value
  if (lei) corpo.legislacao_id = Number(lei)
  if (fdState.vistoriaId) corpo.vistoria_id = fdState.vistoriaId
  if (t?.prazo === 'cumprimento') corpo.prazo_dias = Number(document.getElementById('nd-prazo').value || 0)

  const areaT = document.getElementById('nd-area-terreno').value
  const areaC = document.getElementById('nd-area-construida').value
  if (areaT !== '') corpo.area_terreno_m2 = Number(areaT)
  if (areaC !== '') corpo.area_construida_m2 = Number(areaC)

  // Num rascunho já gravado, o imóvel vinculado depois viaja no PATCH.
  if (fdState.id && fdState.lote?.id) corpo.lote_id = fdState.lote.id

  try {
    // Sem imóvel, o documento nasce pela rota que não o exige. A cobrança
    // continua existindo — na lavratura.
    const url = fdState.id
      ? `/api/documentos/${fdState.id}`
      : (fdState.lote?.id ? `/api/lotes/${fdState.lote.id}/documentos` : '/api/documentos')

    const r = await fetch(url, {
      method: fdState.id ? 'PATCH' : 'POST',
      headers: { ...cabecalhoDoc(), 'Content-Type': 'application/json' },
      body: JSON.stringify(corpo),
    })
    if (r.status === 419) { toast('Sessão expirada. Recarregando...', 'err'); setTimeout(() => location.reload(), 1500); return }
    const d = await r.json().catch(() => ({}))
    if (!r.ok) throw new Error(d.errors ? Object.values(d.errors)[0][0] : (d.message || 'HTTP ' + r.status))

    if (!fdState.id) fdState.id = d.documento.id
    fdState.estado = 'rascunho'
    fdState.editando = false
    aplicarEstadoDoc()
    toast(d.message)
    carregarDocumentos()
  } catch (e) {
    console.error(e)
    toast(e.message || 'Falha ao gravar o documento', 'err')
  }
}

/** Lavra: atribui número, congela o prazo e fecha para edição. */
function lavrarDocumento() {
  const tipo = document.getElementById('nd-tipo').value
  const t = dState.opcoes.tipos.find(x => x.valor === tipo)

  // As duas condições que o servidor também impõe (ver LavraturaService),
  // conferidas aqui só para não gastar uma ida ao servidor e voltar com erro.
  // A aba entra em cena ANTES do aviso: o campo que falta pode estar noutra
  // aba, e marcar um campo escondido não ajuda ninguém.
  if (!fdState.lote?.id) {
    irAbaDoc('imovel')
    exigirCampo('nd-imovel-termo', 'Informe o imóvel: a lavratura exige o lote identificado.')
    return
  }
  if (t?.exige_artigos && !fdState.artigos.length) {
    irAbaDoc('infracao')
    exigirCampo('nd-lei', 'Selecione ao menos um artigo — documento sem fundamentação não pode ser lavrado.')
    return
  }

  confirmarAcao({
    titulo: 'Lavrar documento',
    mensagem: 'A lavratura atribui número definitivo, congela o prazo e fecha o documento '
            + 'para edição. Esta ação não pode ser desfeita — só anulada.',
    textoBtn: 'Lavrar',
    onConfirm: async () => {
      const r = await fetch(`/api/documentos/${fdState.id}/lavrar`, { method: 'POST', headers: cabecalhoDoc() })
      const d = await r.json().catch(() => ({}))
      if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)
      toast(d.message)
      fModalBtn('m-doc')
      // Quem lavrou de dentro da ficha volta para o imóvel, com o documento
      // já na linha do tempo. Quem lavrou da lista continua na lista, que é
      // de onde veio.
      if (! voltarAFicha()) {
        irPara('documentos')
        carregarDocumentos()
      }
    },
  })
}

/**
 * Fecha, avisando se houver edição em curso — e volta para a ficha do imóvel
 * quando foi de lá que a peça nasceu.
 */
function fecharFormDoc() {
  const sair = () => { fModalBtn('m-doc'); voltarAFicha() }

  if (fdState.estado === 'novo' || fdState.editando) {
    confirmarAcao({
      titulo: 'Descartar alterações',
      mensagem: 'O que foi digitado e não gravado será perdido.',
      textoBtn: 'Descartar',
      perigo: true,
      onConfirm: sair,
    })
    return
  }
  sair()
}

// ── RESUMO ───────────────────────────────────────────────────

/**
 * A aba Resumo mostra o documento como ele sai no papel — mesmo cabeçalho,
 * mesmas seções numeradas, mesmas linhas de assinatura. É onde o fiscal
 * confere antes de lavrar, e conferir num layout diferente do impresso não
 * serve para nada.
 */
function renderResumoDoc() {
  const tipoSel = document.getElementById('nd-tipo')
  const rotulo = tipoSel.options[tipoSel.selectedIndex]?.textContent || 'Documento'
  const lei = dState.opcoes.leis.find(l => String(l.id) === document.getElementById('nd-lei').value)
  const artigos = (lei?.artigos || []).filter(a => fdState.artigos.includes(a.id))
  const p = fdState.lote || {}
  const t = dState.opcoes.tipos.find(x => x.valor === tipoSel.value)

  let n = 0
  const sec = titulo => `<div class="rs-sec"><span class="rs-num">${++n}</span>${esc(titulo)}</div>`
  const linha = (r, v) => v
    ? `<div class="rs-linha"><span>${esc(r)}</span><b>${esc(v)}</b></div>` : ''

  const dataFato = document.getElementById('nd-data').value
  const dataBr = dataFato ? dataFato.split('-').reverse().join('/') : '—'

  document.getElementById('nd-resumo').innerHTML = `
    <div class="rs-cab">
      <img class="rs-brasao" src="/img/brasao-prefeitura.png" alt=""
           onerror="this.style.display='none'">
      <div class="rs-cab-tit">
        <div class="rs-cab-doc">${esc(rotulo.toUpperCase())}</div>
        <div class="rs-cab-org">Prefeitura Municipal de Primavera do Leste – MT</div>
        <div class="rs-cab-meta">Nº ${esc(document.getElementById('fd-numero').textContent)}
          · Fato em ${esc(dataBr)} ${esc(document.getElementById('nd-hora').value || '')}</div>
      </div>
    </div>

    ${sec('Autuado')}
    ${linha('Nome', document.getElementById('nd-autuado').value) || '<div class="rs-linha"><span>Nome</span><b>—</b></div>'}
    ${linha('CPF/CNPJ', document.getElementById('nd-autuado-doc').value)}

    ${sec('Imóvel')}
    ${linha('Inscrição', p.inscricao || montarInscricao(p))}
    ${linha('Bairro', p.bairro)}
    ${linha('Quadra / Lote', `${p.quadra ?? '—'} / ${p.numero_lote ?? '—'}`)}
    ${linha('Endereço', document.getElementById('nd-endereco').value)}

    ${document.getElementById('nd-descricao').value
      ? sec('Constatação') + `<p class="rs-texto">${esc(document.getElementById('nd-descricao').value)}</p>` : ''}

    ${t?.exige_artigos ? `
      ${sec('Legislação infringida')}
      ${lei ? `<div class="rs-linha"><span>Lei</span><b>${esc(lei.rotulo)}</b></div>` : ''}
      <div class="rs-artigos">
        ${artigos.length
          ? artigos.map(a => `<div class="rs-artigo"><strong>Art. ${esc(String(a.numero).replace(/^Art\.?\s*/i, ''))}.</strong> ${esc(a.conduta || '')}</div>`).join('')
          : '<div class="rs-artigo">—</div>'}
      </div>
      ${resumoMulta(artigos)}
    ` : ''}

    ${t?.prazo === 'cumprimento'
      ? sec('Prazo para cumprimento') + `<div class="rs-linha"><span>Prazo</span><b>${esc(document.getElementById('nd-prazo').value)} dias</b></div>`
      : t?.prazo === 'defesa'
        ? sec('Prazo de defesa') + `<div class="rs-linha"><span>Prazo</span><b>${lei ? esc(String(lei.prazo_defesa_dias)) + ' dias úteis da lavratura' : 'definido pela lei'}</b></div>`
        : ''}

    <div class="rs-assinaturas">
      <div><div class="rs-assina-linha"></div>Fiscal</div>
      <div><div class="rs-assina-linha"></div>Autuado / Preposto</div>
    </div>`
}

/** Bloco de multa do resumo — a memória de cálculo, como sai impressa. */
function resumoMulta(artigos) {
  const comValor = artigos.filter(a => a.base_multa !== 'sem_multa')
  if (!comValor.length) return ''

  const areaT = parseFloat(document.getElementById('nd-area-terreno').value) || null
  const areaC = parseFloat(document.getElementById('nd-area-construida').value) || null

  let total = 0
  const linhas = comValor.map(a => {
    if (a.base_multa === 'fixa') {
      total += Number(a.multa_upf || 0)
      return `<div class="rs-linha"><span>${esc(a.numero)}</span><b>${fmtNum(a.multa_upf || 0)} UPF (fixo)</b></div>`
    }
    const area = a.base_multa === 'area_terreno' ? areaT : areaC
    if (area === null) {
      return `<div class="rs-linha"><span>${esc(a.numero)}</span><b style="color:var(--red)">informe a área</b></div>`
    }
    let v = Number(a.multa_upf_m2 || 0) * area
    let obs = ''
    if (a.multa_min_upf !== null && v < Number(a.multa_min_upf)) { v = Number(a.multa_min_upf); obs = ' (piso)' }
    else if (a.multa_max_upf !== null && v > Number(a.multa_max_upf)) { v = Number(a.multa_max_upf); obs = ' (teto)' }
    total += v
    return `<div class="rs-linha"><span>${esc(a.numero)}</span><b>${fmtNum(a.multa_upf_m2)} UPF/m² × ${fmtNum(area)} m² = ${fmtNum(v)} UPF${obs}</b></div>`
  }).join('')

  return `<div class="rs-sec"><span class="rs-num">•</span>Penalidade</div>${linhas}
    <div class="rs-linha rs-total"><span>Total estimado</span><b>${fmtNum(total)} UPF</b></div>
    <p class="rs-nota">O valor definitivo é calculado na lavratura, com a UPF do exercício.</p>`
}
