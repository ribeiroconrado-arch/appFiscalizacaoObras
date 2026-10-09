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
const ABAS_DOC = ['autuado', 'infracao', 'anexos', 'resumo']

const fdState = {
  /** @type {'novo'|'rascunho'|'lavrado'} */ estado: 'novo',
  /** @type {boolean} */ editando: false,
  /** @type {number|null} */ id: null,
  /** @type {string} */ aba: 'autuado',
  /** @type {Object|null} imóvel escolhido no mapa ou na busca */ lote: null,
  /** @type {number[]} */ artigos: [],
  /** @type {number|null} */ vistoriaId: null,
  /** @type {number} */ anexos: 0,
  /** @type {Object<number, number|null>} multiplicador do alvará, por artigo */ multiplicadores: {},
  /** @type {Object|null} a última conta da multa (de /api/multas/simular) */ multa: null,
  /** @type {Object|null} a conta que a peça reaberta guardou */ multaGravada: null,
  /** @type {boolean} a área de lavratura (assinaturas) está aberta no resumo */ lavrando: false,
  /** @type {Object|null} as assinaturas da peça lavrada */ assinaturas: null,
  /** @type {number|null} a ordem de serviço que originou a notificação */ origemOsId: null,
  /** @type {string|null} */ origemOsRotulo: null,
  /** @type {boolean} este usuário pode corrigir a origem da peça lavrada */ podeEditarOrigem: false,
  /** @type {number|null} a peça de que esta nasceu */ origemId: null,
  /** @type {string|null} */ origemRotulo: null,
  /** @type {number|null} o auto de que este é reincidência */ reincidenciaId: null,
  /** @type {string|null} */ reincidenciaNumero: null,
  /** @type {number|null} */ reincidenciaFator: null,
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
  fdState.lavrando = false
  fdState.assinaturas = documento?.assinaturas ?? null
  if (typeof fecharAreaLavratura === 'function') fecharAreaLavratura()
  fdState.aba = 'autuado'
  // A cópia do cadastro municipal guardada na lavratura (nula em rascunho).
  fdState.cadastro = documento?.cadastro ?? null

  if (documento) {
    fdState.estado = documento.status.valor === 'rascunho' ? 'rascunho' : 'lavrado'
    fdState.id = documento.id
    // Os artigos e a lei da peça voltam ao formulário pelos ids: sem isto,
    // gravar um rascunho reaberto apagava o enquadramento dele.
    fdState.artigos = (documento.artigos || []).map(a => a.artigo_id).filter(Boolean)
    fdState.multiplicadores = Object.fromEntries((documento.artigos || [])
      .filter(a => a.artigo_id && a.multiplicador !== null).map(a => [a.artigo_id, a.multiplicador]))
    // A conta como a peça a guardou: é o que se mostra enquanto ela está só
    // aberta para leitura. Ao editar, a prévia volta a vir do servidor.
    fdState.origemOsId = documento.origem_os_id ?? null
    fdState.origemOsRotulo = documento.origem_os_rotulo ?? null
    fdState.podeEditarOrigem = !!documento.pode_editar_origem
    fdState.origemId = documento.origem_id ?? null
    fdState.origemRotulo = documento.origem_rotulo ?? null
    fdState.reincidenciaId = documento.reincidencia?.id ?? null
    fdState.reincidenciaNumero = documento.reincidencia?.numero ?? null
    fdState.reincidenciaFator = documento.reincidencia?.fator ?? null
    fdState.multaGravada = {
      gravada: true,
      fator_reincidencia: documento.reincidencia?.fator ?? 1,
      linhas: (documento.artigos || []).map(a => ({ numero: a.numero, base: a.base, memoria: a.calculo, valor: a.valor, pendencia: null })),
      total_upf: documento.valor_upf, total_reais: documento.valor_reais,
    }
    fdState.anexos = documento.anexos || 0
    fdState.lote = {
      id: documento.imovel.lote_id ?? null,
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
    fdState.multiplicadores = {}
    fdState.multaGravada = null
    fdState.reincidenciaId = fdState.reincidenciaNumero = fdState.reincidenciaFator = null
    fdState.origemId = fdState.origemRotulo = null
    fdState.origemOsId = fdState.origemOsRotulo = null
    fdState.podeEditarOrigem = false
    fdState.anexos = 0
    fdState.vistoriaId = null
    fdState.lote = lote
  }

  // Tipos: os quatro de obras. A lista vem do servidor, não fixa aqui.
  document.getElementById('nd-tipo').innerHTML =
    o.tipos.map(t => `<option value="${t.valor}">${esc(t.rotulo)}</option>`).join('')
  document.getElementById('nd-lei').value = documento?.legislacao_id ?? ''
  artigoEscolhidoDoc = null
  document.getElementById('nd-artigo-busca').value = ''

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
  // O cadastro municipal do imóvel: sempre mostrado; em peça nova, também
  // sugere o autuado, os endereços e a área do terreno.
  renderBciDoc({ sugerir: !documento })
  // A origem da notificação (direta, ordem de serviço, ouvidoria).
  {
    const os = document.getElementById('nd-origem-os')
    os.dataset.carregado = ''
    os.innerHTML = '<option value="">Escolha a ordem de serviço…</option>'
      + (fdState.origemOsId ? `<option value="${fdState.origemOsId}">${esc(fdState.origemOsRotulo || 'Ordem de serviço')}</option>` : '')
    os.value = fdState.origemOsId ? String(fdState.origemOsId) : ''
    document.getElementById('nd-origem-motivo').value = documento?.origem_motivo || 'direta'
    document.getElementById('nd-origem-ref').value = documento?.origem_referencia || ''
  }
  // As peças de que este auto pode nascer, para o campo de origem.
  document.getElementById('nd-origem').innerHTML = '<option value="">Direta — sem documento de origem</option>'
  carregarOrigensDoc()
  // Os autos anteriores do imóvel, para o campo de reincidência.
  document.getElementById('nd-reincidencia').innerHTML = '<option value="">Não é reincidência</option>'
  carregarAutosAnterioresDoc().then(() => { if (fdState.reincidenciaId) recalcularMultaDoc() })

  if (documento) { return }

  // Só quem abre a peça A PARTIR de uma vistoria a recebe vinculada, com os
  // artigos citados nela. Peça nova, aberta do imóvel ou da lista, nasce sem
  // vínculo e sem artigo: antes ela se prendia sozinha à última vistoria do
  // imóvel e já vinha com a infração preenchida.
  document.getElementById('nd-sugestao').innerHTML = ''
  if (vistoria) {
    await sugerirDaVistoria(vistoria)
  }
}

/** Rótulo e formato de cada dado do BCI, na ordem em que aparecem. */
const BCI_CAMPOS_DOC = [
  ['inscricao_alternativa', 'Inscrição alternativa'],
  ['area_edificada_m2', 'Área edificada', 'm²'],
  ['fracao_ideal', 'Fração ideal'],
  ['testada_m', 'Testada', 'm'],
  ['medida_lado_direito', 'Lado direito', 'm'],
  ['medida_lado_esquerdo', 'Lado esquerdo', 'm'],
  ['medida_fundo', 'Fundo', 'm'],
  ['regiao_fiscal', 'Região fiscal'],
]

/**
 * O que o cadastro municipal (BCI) diz do imóvel da peça, dentro do
 * formulário — e, em peça NOVA, a sugestão dos campos que saem dele.
 *
 * IMÓVEL NO CADASTRO: inscrição, bairro, quadra, lote, área do terreno e
 * endereço (logradouro e número) vêm de lá e ficam só para leitura; o resto
 * do BCI — medidas, características, unidades, proprietários — aparece
 * embaixo, também para leitura. IMÓVEL FORA DO CADASTRO: o bloco do BCI não
 * aparece, e os campos do imóvel ficam abertos para o fiscal informar à mão.
 * O autuado (nome, CPF/CNPJ, endereço domiciliar) é sempre editável, e nasce
 * sugerido do proprietário.
 *
 * Só preenche campo vazio — o que o fiscal digitou é decisão dele. O CPF e o
 * endereço do proprietário só chegam a quem pode vê-los
 * (App\Cadastro\ProprietariosVisiveis).
 *
 * @param {{sugerir?: boolean}} [o] sugerir: preencher os campos vazios da peça
 */
async function renderBciDoc({ sugerir = false } = {}) {
  const bloco = document.getElementById('nd-bci-bloco')
  const alvo = document.getElementById('nd-bci')
  const loteId = fdState.lote?.id ?? null
  bloco.hidden = true
  alvo.innerHTML = ''
  // Até o cadastro responder (ou se ele não tiver o imóvel), os campos do
  // imóvel ficam abertos à digitação.
  travarImovelDoc([])

  // PEÇA LAVRADA: mostra a CÓPIA que ela guardou na lavratura, e não o
  // cadastro de hoje — que pode ter mudado numa carga posterior. A data em
  // que a cópia foi tirada vai escrita no topo do bloco.
  if (fdState.estado === 'lavrado') {
    const c = fdState.cadastro
    if (c?.retrato) {
      bloco.hidden = false
      alvo.innerHTML = htmlBciDoc(c.retrato.imovel, c.retrato.caracteristicas, c.retrato.unidades, [],
        `Dados copiados do cadastro municipal na lavratura, em <b>${esc(c.copiado_em || '—')}</b>`
        + (c.consultado_em ? ` — cadastro integrado em <b>${esc(c.consultado_em)}</b>.` : '.'))
    }
    return
  }

  if (!loteId || typeof obterBci !== 'function') return

  let bci
  try { bci = await obterBci(loteId) } catch { return }
  if (fdState.lote?.id !== loteId) return      // o imóvel mudou enquanto se lia

  const donos = bci.proprietarios || []
  const por = (id, valor) => { const el = document.getElementById(id); if (valor && !el.value.trim()) el.value = valor }
  if (sugerir) {
    por('nd-autuado', donos[0]?.nome)
    por('nd-autuado-doc', donos[0]?.documento)
    // O endereço de correspondência do cadastro é um texto só: entra no
    // logradouro, e o fiscal separa o que precisar.
    por('nd-aut-logradouro', donos[0]?.endereco)
  }

  // IMÓVEL FORA DO CADASTRO: nada a mostrar, e os campos ficam para o fiscal.
  if (!bci.tem) return

  const im = bci.imovel
  // O endereço da obra é só logradouro e número — o complemento fica no BCI.
  const numero = im.numero_predial ? String(im.numero_predial).replace(/^0+/, '') : ''
  const doCadastro = ['nd-im-inscricao', 'nd-im-bairro', 'nd-im-quadra', 'nd-im-lote']

  // Em peça nova, o que o cadastro diz substitui o que estava no campo; em
  // peça já gravada, vale o que ela guardou.
  const g = id => document.getElementById(id)
  if (im.area_terreno_m2) {
    if (sugerir || !g('nd-area-terreno').value) g('nd-area-terreno').value = Number(im.area_terreno_m2).toFixed(2)
    doCadastro.push('nd-area-terreno')
  }
  if (im.logradouro) {
    if (sugerir || !g('nd-im-logradouro').value.trim()) g('nd-im-logradouro').value = im.logradouro
    doCadastro.push('nd-im-logradouro')
  }
  if (numero) {
    if (sugerir || !g('nd-im-numero').value.trim()) g('nd-im-numero').value = numero
    doCadastro.push('nd-im-numero')
  }
  travarImovelDoc(doCadastro)
  recalcularMultaDoc()

  bloco.hidden = false
  const carga = bci.integracao?.em && typeof dataHoraCurta === 'function' ? dataHoraCurta(bci.integracao.em) : null
  alvo.innerHTML = htmlBciDoc(im, bci.caracteristicas, bci.unidades, donos,
    (carga ? `Cadastro municipal integrado em <b>${esc(carga)}</b>. ` : '')
    + 'Ao lavrar, estes dados são copiados para a peça, com a data.')
}

/**
 * O bloco "Cadastro municipal (BCI)" do formulário: o mesmo desenho para o
 * cadastro AO VIVO (rascunho) e para a CÓPIA guardada na peça (lavrada).
 *
 * Linhas fixas no topo — código, situação e área; logradouro, número e bairro;
 * complemento na linha inteira — e o resto do que vier, em grade. O setor não
 * aparece: é o nome interno do bairro no cadastro, e o bairro já está na linha.
 *
 * @param {Object} im campos do imóvel
 * @param {{chave:string, valor:?string}[]} [caracteristicas]
 * @param {Object[]} [unidades]
 * @param {Object[]} [donos]
 * @param {string} [nota] de quando é o dado (HTML já escapado por quem chama)
 */
function htmlBciDoc(im, caracteristicas = [], unidades = [], donos = [], nota = '') {
  const par = (r, v) => `<div><span class="df-rot">${esc(r)}</span><span class="df-val">${esc(v ?? '—')}</span></div>`
  const tem = v => v !== null && v !== undefined && v !== ''
  const numero = tem(im.numero_predial) ? String(im.numero_predial).replace(/^0+/, '') || '0' : null
  const outros = BCI_CAMPOS_DOC
    .filter(([k]) => tem(im[k]))
    .map(([k, rot, un]) => par(rot, typeof im[k] === 'number' ? fmtNum(im[k]) + (un ? ' ' + un : '') : im[k]))
  const carac = (caracteristicas || []).filter(c => tem(c.valor))

  return `
    ${nota ? `<p class="bci-doc-nota">${nota}</p>` : ''}
    <div class="bci-doc-lin bci-doc-l3">
      ${par('Código no cadastro', im.codigo_cadastro)}
      ${par('Situação / isenção', im.isencao)}
      ${par('Área do terreno', tem(im.area_terreno_m2) ? fmtNum(im.area_terreno_m2) + ' m²' : null)}
    </div>
    <div class="bci-doc-lin bci-doc-rua">
      ${par('Logradouro', im.logradouro)}
      ${par('Número', numero)}
      ${par('Bairro', im.nome_bairro)}
    </div>
    ${tem(im.complemento) ? `<div class="bci-doc-lin">${par('Complemento', im.complemento)}</div>` : ''}
    ${outros.length ? `<div class="df-grade">${outros.join('')}</div>` : ''}
    ${donos.length ? `<div class="bci-sub">Proprietário(s)</div><div class="df-grade">${donos.map(d =>
      par(d.nome || '—', [d.documento, d.endereco].filter(Boolean).join(' · ') || '—')).join('')}</div>` : ''}
    ${carac.length ? `<div class="bci-sub">Características</div><div class="df-grade">${carac.map(c =>
      par(String(c.chave).replace(/_/g, ' '), c.valor)).join('')}</div>` : ''}
    ${(unidades || []).length ? `<div class="bci-sub">Unidades edificadas</div><div class="df-grade">${unidades.map(u =>
      par('Unidade ' + (u.numero ?? '—'), [u.area ? fmtNum(u.area) + ' m²' : null, u.ano, u.padrao].filter(Boolean).join(' · ') || '—')).join('')}</div>` : ''}`
}

/** Os campos que identificam o imóvel na peça. */
const CAMPOS_IMOVEL_DOC = ['nd-im-inscricao', 'nd-im-bairro', 'nd-im-quadra', 'nd-im-lote', 'nd-area-terreno',
  'nd-im-logradouro', 'nd-im-numero']

/** O domicílio do autuado, em partes: id do campo → nome da parte. */
const CAMPOS_ENDERECO_AUTUADO_DOC = { 'nd-aut-logradouro': 'logradouro', 'nd-aut-numero': 'numero',
  'nd-aut-bairro': 'bairro', 'nd-aut-cidade': 'cidade', 'nd-aut-uf': 'uf' }

/** "Rua X, 10 — Centro — Cidade/UF": o domicílio do autuado numa linha, para o resumo. */
function enderecoAutuadoDoc() {
  const v = id => document.getElementById(id).value.trim()
  return [[v('nd-aut-logradouro'), v('nd-aut-numero')].filter(Boolean).join(', '), v('nd-aut-bairro'),
    [v('nd-aut-cidade'), v('nd-aut-uf')].filter(Boolean).join('/')].filter(Boolean).join(' — ')
}

/** "Rua X, 10": o endereço da obra numa linha. */
function enderecoObraDoc() {
  return ['nd-im-logradouro', 'nd-im-numero'].map(id => document.getElementById(id).value.trim()).filter(Boolean).join(', ')
}

/**
 * Deixa só para leitura os campos do imóvel que vieram do cadastro municipal,
 * e abertos os demais. Imóvel que não está no cadastro: lista vazia, tudo
 * aberto para o fiscal informar à mão.
 *
 * @param {string[]} doCadastro ids dos campos preenchidos pelo cadastro
 */
function travarImovelDoc(doCadastro) {
  for (const id of CAMPOS_IMOVEL_DOC) {
    const el = document.getElementById(id)
    const trava = doCadastro.includes(id)
    el.readOnly = trava
    el.classList.toggle('so-leitura-campo', trava)
    el.title = trava ? 'Vem do cadastro municipal' : ''
  }
}

/** Campos em branco, com os padrões de um documento novo. */
function limparFormDoc() {
  document.getElementById('nd-autuado').value = ''
  document.getElementById('nd-autuado-doc').value = ''
  Object.keys(CAMPOS_ENDERECO_AUTUADO_DOC).forEach(id => { document.getElementById(id).value = '' })
  // Peça nova: o CNPJ da anterior não pode impedir a consulta desta.
  if (typeof _cnpjConsultadoDoc !== 'undefined') _cnpjConsultadoDoc = ''
  if (typeof fecharBalaoDataHora === 'function') fecharBalaoDataHora()
  CAMPOS_IMOVEL_DOC.forEach(id => { document.getElementById(id).value = '' })
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
  document.getElementById('nd-alvara-valor').value = ''
  document.getElementById('nd-bloco-area').style.display = 'none'
  document.getElementById('nd-bloco-alvara').style.display = 'none'
  document.getElementById('nd-multiplicadores').dataset.chave = '?'
  document.getElementById('nd-memoria-calculo').innerHTML = ''
  fdState.multa = null

  renderImovelDoc()
  trocarLeiDoc()
}

/** @param {Object} d ficha vinda de /api/documentos/{id} */
function preencherFormDoc(d) {
  document.getElementById('nd-tipo').value = d.tipo
  document.getElementById('nd-autuado').value = d.autuado.nome || ''
  document.getElementById('nd-autuado-doc').value = d.autuado.documento || ''
  for (const [id, parte] of Object.entries(CAMPOS_ENDERECO_AUTUADO_DOC)) {
    document.getElementById(id).value = d.autuado[parte] || ''
  }

  // De quando é o dado cadastral desta peça. `d.cadastro` vem nulo no
  // rascunho — lá o carimbo ainda não existe, porque o conteúdo ainda pode
  // mudar até a lavratura.
  const carimbo = document.getElementById('nd-carimbo')
  if (carimbo) {
    carimbo.hidden = !d.cadastro
    if (d.cadastro) {
      carimbo.textContent = d.cadastro.consultado_em
        ? 'Dados do imóvel conforme o cadastro municipal integrado em ' + d.cadastro.consultado_em
          + (d.cadastro.fonte === 'exportacao' ? ' (exportação da prefeitura).' : '.')
        : 'Lavrado sem dado do cadastro municipal.'
    }
  }
  document.getElementById('nd-im-logradouro').value = d.imovel.logradouro || ''
  document.getElementById('nd-im-numero').value = d.imovel.numero || ''
  document.getElementById('nd-im-inscricao').value = d.imovel.inscricao || ''
  document.getElementById('nd-im-bairro').value = d.imovel.bairro || ''
  document.getElementById('nd-im-quadra').value = d.imovel.quadra ?? ''
  document.getElementById('nd-im-lote').value = d.imovel.lote ?? ''
  document.getElementById('nd-descricao').value = d.descricao || ''
  document.getElementById('nd-area-terreno').value = d.imovel.terreno ?? ''
  document.getElementById('nd-area-construida').value = d.imovel.construida ?? ''
  document.getElementById('nd-alvara-valor').value = d.imovel.alvara_valor ?? ''

  const [dia, hora] = (d.data_fato || ' ').split(' ')
  if (dia) {
    const [dd, mm, aa] = dia.split('/')
    document.getElementById('nd-data').value = `${aa}-${mm}-${dd}`
  }
  document.getElementById('nd-hora').value = hora || ''
  syncDataDoc()

  renderImovelDoc()
  trocarLeiDoc()
  renderAnexosDoc()
}

/**
 * O imóvel da peça.
 *
 * Sem imóvel: o localizador. É o caminho de quem abriu a peça em campo antes
 * de identificar o lote; o aviso diz que a lavratura depende dele — melhor
 * saber agora do que no botão Lavrar.
 *
 * Com imóvel: os campos (inscrição, bairro, quadra, lote) recebem o que o
 * lote diz, onde ainda estiverem vazios. Se eles ficam abertos ou só para
 * leitura é o cadastro municipal que decide — ver renderBciDoc.
 */
function renderImovelDoc() {
  const alvo = document.getElementById('nd-imovel-dados')
  const p = fdState.lote

  if (!p?.id) {
    alvo.innerHTML = `
      <div class="doc-sem-imovel">
        <b>Imóvel ainda não identificado.</b>
        O documento pode ser gravado assim, mas a lavratura exige o imóvel.
      </div>
      <div class="par-busca" style="margin:10px 0">
        <input type="text" id="nd-imovel-termo" placeholder="Inscrição imobiliária ou “quadra lote”"
               onkeydown="if(event.key==='Enter'){event.preventDefault();procurarImovelDoc()}">
        <button type="button" class="btn out-verde sm" onclick="procurarImovelDoc()">Localizar</button>
      </div>
      <div id="nd-imovel-resultado" class="leg" style="margin-bottom:8px"></div>`
  } else {
    alvo.innerHTML = ''
  }
  if (!p) return

  const por = (id, valor) => { const el = document.getElementById(id); if (valor != null && valor !== '' && !el.value.trim()) el.value = valor }
  por('nd-im-inscricao', p.inscricao)
  por('nd-im-bairro', bairroDe(p))
  por('nd-im-quadra', p.quadra)
  por('nd-im-lote', p.numero_lote)
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
    // Os campos do imóvel acompanham o imóvel escolhido: o que estava
    // digitado sai, entra o que o lote diz — e o cadastro municipal
    // (renderBciDoc) completa e trava, se tiver o imóvel. A área é base de
    // multa: deixá-la com o valor de outro lote produziria conta errada.
    CAMPOS_IMOVEL_DOC.forEach(id => { document.getElementById(id).value = '' })
    renderImovelDoc()
    document.getElementById('nd-area-terreno').value = f.area ? Number(f.area).toFixed(2) : ''
    recalcularMultaDoc()
    renderBciDoc({ sugerir: true })

    toast('Imóvel vinculado ao documento')
  } catch (e) {
    console.error(e)
    toast(e.message || 'Não foi possível vincular o imóvel', 'err')
  }
}

/** A aba Anexos é de documento-anexos.js: os anexos próprios da peça. */
function renderAnexosDoc() {
  if (typeof carregarAnexosDoc === 'function') carregarAnexosDoc()
}

// ── ABAS ─────────────────────────────────────────────────────

/** @param {string} nome */
function irAbaDoc(nome) {
  if (!ABAS_DOC.includes(nome)) return
  if (typeof fecharBalaoDataHora === 'function') fecharBalaoDataHora()   // o calendário não segue para outra aba
  // A lavratura acontece no Resumo: sair dele é desistir dela.
  if (fdState.lavrando && nome !== 'resumo') cancelarLavraturaDoc()
  fdState.aba = nome

  document.querySelectorAll('#fd-tabs .doc-tab')
    .forEach(b => b.classList.toggle('ativa', b.dataset.aba === nome))
  document.querySelectorAll('#fd-body .doc-painel')
    .forEach(p => p.classList.toggle('ativa', p.id === 'fdp-' + nome))

  // O corpo volta ao topo: a aba nova começa onde a anterior tinha rolado.
  document.getElementById('fd-body').scrollTop = 0

  if (nome === 'resumo') renderResumoDoc()
  // Sempre do servidor: gravar a peça, lavrar ou trocar a origem muda o que a aba mostra.
  if (nome === 'anexos') renderAnexosDoc()
  // O rodapé já liga e desliga as quatro setas — ver `renderRodapeDoc`.
  renderRodapeDoc()
}

/** @param {number} passo -1 volta, +1 avança */
function passoAbaDoc(passo) {
  const i = ABAS_DOC.indexOf(fdState.aba)
  const alvo = ABAS_DOC[i + passo]
  if (alvo) irAbaDoc(alvo)
}

// ── ESTADO E TRAVAMENTO ──────────────────────────────────────

/** Aplica o estado corrente ao cabeçalho, aos campos e ao rodapé. */
function aplicarEstadoDoc() {
  travarCamposDoc(fdState.estado !== 'novo' && !fdState.editando)
  renderRodapeDoc()
  // A origem da notificação tem trava própria: continua editável com a peça lavrada.
  if (typeof aplicarOrigemNotifDoc === 'function') aplicarOrigemNotifDoc()
  // O resumo depende do estado: gravada, a peça passa a mostrar a via A4.
  if (fdState.aba === 'resumo') renderResumoDoc()
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
  document.getElementById('m-doc').classList.toggle('so-leitura', travar)
  // Os quadros dos artigos são redesenhados: o X de cada um só existe
  // quando a peça pode ser alterada.
  trocarLeiDoc()
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

  // LAVRANDO: só os dois botões do ato — o resto sai de cena até decidir.
  const lavrando = fdState.lavrando
  mostrar('fd-gravar', (novo || fdState.editando) && !lavrando)
  mostrar('fd-sair-edicao', fdState.editando && !lavrando)
  mostrar('fd-editar', rascunho && !fdState.editando && !lavrando)
  mostrar('fd-lavrar', rascunho && !fdState.editando && !lavrando)
  mostrar('fd-lavrar-cancelar', lavrando)
  mostrar('fd-lavrar-ok', lavrando)
  // Opções depende de haver documento gravado: antes disso não há nada para
  // imprimir, anular ou excluir.
  mostrar('fd-opcoes-wrap', (rascunho || lavrado) && !fdState.editando && !lavrando)
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
/**
 * O que o formulário manda ao servidor. Uma função só, porque dois pedidos
 * mandam EXATAMENTE isto: o Gravar e a prévia do resumo (a via A4 do que está
 * na tela) — se divergissem, o resumo mostraria uma peça e o Gravar faria outra.
 */
function corpoDoDoc() {
  const tipo = document.getElementById('nd-tipo').value
  const t = dState.opcoes.tipos.find(x => x.valor === tipo)

  // O que está digitado em data e hora vale mesmo sem ter saído do campo.
  lerDataHoraDoc()

  const corpo = {
    tipo,
    data_fato: document.getElementById('nd-datahora').value,
    autuado_nome: document.getElementById('nd-autuado').value,
    autuado_documento: document.getElementById('nd-autuado-doc').value,
    // O endereço vai EM PARTES; o servidor monta o texto único da peça.
    ...Object.fromEntries(Object.entries(CAMPOS_ENDERECO_AUTUADO_DOC)
      .map(([id, parte]) => ['autuado_' + parte, document.getElementById(id).value.trim() || null])),
    imovel_logradouro: document.getElementById('nd-im-logradouro').value.trim() || null,
    imovel_numero: document.getElementById('nd-im-numero').value.trim() || null,
    imovel_inscricao: document.getElementById('nd-im-inscricao').value.trim() || null,
    imovel_bairro: document.getElementById('nd-im-bairro').value.trim() || null,
    imovel_quadra: document.getElementById('nd-im-quadra').value.trim() || null,
    imovel_lote: document.getElementById('nd-im-lote').value.trim() || null,
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
  // Multa por múltiplo do alvará: o valor do alvará e o multiplicador de cada artigo.
  const alvara = document.getElementById('nd-alvara-valor').value
  corpo.alvara_valor = alvara === '' ? null : Number(alvara)
  corpo.multiplicadores = multiplicadoresDoDoc()
  corpo.reincidencia_de_id = reincidenciaDoDoc()
  corpo.origem_id = origemDoDoc()
  Object.assign(corpo, motivoDeOrigemDoDoc())

  // Num rascunho já gravado, o imóvel vinculado depois viaja no PATCH.
  if (fdState.id && fdState.lote?.id) corpo.lote_id = fdState.lote.id

  return corpo
}

async function gravarDoc() {
  const tipo = document.getElementById('nd-tipo').value
  const t = dState.opcoes.tipos.find(x => x.valor === tipo)

  const corpo = corpoDoDoc()

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
    // O menu de Opções (imprimir, lavrar, excluir) lê a ficha do servidor.
    // Sem buscá-la aqui, a peça recém-gravada ficava sem opção nenhuma até
    // ser fechada e aberta de novo.
    await atualizarFichaDoc()
    // Avisos do servidor (Auto de Embargo por artigo que pede prazo, sem
    // Notificação de Embargo vencida): não impedem gravar nem lavrar.
    ;(d.avisos || []).forEach(a => toast(a, 'aviso'))
    carregarDocumentos()
  } catch (e) {
    console.error(e)
    toast(e.message || 'Falha ao gravar o documento', 'err')
  }
}

/**
 * Busca a ficha da peça aberta e a entrega ao menu de Opções (dFicha, em
 * documentos.js). Chamada depois de gravar: é a ficha que diz o que este
 * usuário pode fazer com a peça AGORA.
 * @returns {Promise<Object|null>} a ficha, ou null se não deu para buscar
 */
async function atualizarFichaDoc() {
  if (!fdState.id) return null
  try {
    const r = await fetch('/api/documentos/' + fdState.id, { headers: { Accept: 'application/json' } })
    if (!r.ok) return null
    const doc = await r.json()
    dFicha.doc = doc
    dFicha.opcoes = doc.opcoes || []
    return doc
  } catch (_) {
    return null
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
    irAbaDoc('autuado')
    exigirCampo('nd-imovel-termo', 'Informe o imóvel: a lavratura exige o lote identificado.')
    return
  }
  if (t?.exige_artigos && !fdState.artigos.length) {
    irAbaDoc('infracao')
    exigirCampo('nd-artigo-busca', 'Adicione ao menos um artigo — documento sem fundamentação não pode ser lavrado.')
    return
  }

  // As assinaturas são colhidas no próprio Resumo (documento-lavratura.js):
  // o fiscal confere a peça, o autuado assina — ou se registra a recusa — e
  // só então a lavratura é confirmada.
  abrirAreaLavratura()
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
  const caixa = document.getElementById('nd-resumo')
  // O RESUMO É SEMPRE A VIA A4 — a mesma página que sai impressa, e é nela
  // que o autuado lê o que está assinando.
  //   · peça gravada e sem edição em curso: a via do que está no servidor;
  //   · peça nova ou em edição: a via do que está NA TELA, montada pelo
  //     servidor sem gravar nada (POST /api/documentos/previa).
  if (fdState.id && fdState.estado !== 'novo' && !fdState.editando) {
    mostrarViaA4NoResumo(caixa, { url: `/documentos/${fdState.id}/impressao?formato=a4&previa=1&t=${Date.now()}` })
    return
  }
  previaA4DoFormulario(caixa)
}

/**
 * A via A4 do que está digitado, sem gravar. Se o servidor recusar a peça
 * como está (artigo que não serve ao documento, por exemplo), o resumo diz
 * o motivo em vez de mostrar uma folha — é o mesmo motivo que o Gravar daria.
 * @param {HTMLElement} caixa
 */
async function previaA4DoFormulario(caixa) {
  caixa.classList.add('rs-a4')
  caixa.innerHTML = '<div class="rs-a4-aviso">Montando a via do documento…</div>'
  const pedido = previaA4DoFormulario.ultimo = (previaA4DoFormulario.ultimo || 0) + 1
  try {
    const r = await fetch('/api/documentos/previa', {
      method: 'POST',
      headers: { ...cabecalhoDoc(), 'Content-Type': 'application/json', Accept: 'text/html, application/json' },
      body: JSON.stringify({ ...corpoDoDoc(), documento_id: fdState.id || null, lote_id: fdState.lote?.id || null }),
    })
    // Resposta de um pedido antigo (o fiscal já mudou de aba e voltou).
    if (pedido !== previaA4DoFormulario.ultimo || fdState.aba !== 'resumo') return
    if (!r.ok) {
      const d = await r.json().catch(() => ({}))
      throw new Error(d.errors ? Object.values(d.errors)[0][0] : (d.message || 'HTTP ' + r.status))
    }
    mostrarViaA4NoResumo(caixa, { html: await r.text() })
  } catch (e) {
    if (pedido !== previaA4DoFormulario.ultimo) return
    caixa.innerHTML = `<div class="rs-a4-aviso rs-a4-erro">Não foi possível montar a via do documento: ${esc(e.message || 'falha de comunicação')}</div>`
  }
}

// ── O RESUMO COMO A VIA A4 ───────────────────────────────────

/**
 * Largura em que a via é desenhada antes de ser ajustada à tela: a ÁREA ÚTIL
 * da folha A4 (190 mm, sem as margens de impressão), e não a folha inteira.
 * Sem as margens em branco, o mesmo desenho ocupa a tela toda e a letra sai
 * maior.
 *
 * 600, e não os 720 da área útil: desenhada mais estreita e ajustada à mesma
 * tela, a folha sai com a letra DOIS PONTOS maior (10,5 → 12,5), em todos os
 * textos na mesma proporção. Só a tela muda; o PDF segue no tamanho do papel.
 */
const LARGURA_A4_PX = 600

/**
 * Põe no resumo a via A4 do documento — a mesma página do PDF, pedida ao
 * servidor em modo de prévia — e a ENCOLHE até caber na largura da tela.
 *
 * Encolhida, e não reorganizada: o que o autuado vê é o desenho exato do
 * papel, com todas as seções, só que menor. Num tablet a folha cabe inteira
 * na largura; ampliar é o gesto de pinça do próprio aparelho.
 *
 * @param {HTMLElement} caixa
 * @param {{url?: string|null, html?: string|null}} origem a via gravada (endereço) ou a prévia (HTML)
 */
function mostrarViaA4NoResumo(caixa, { url = null, html = null }) {
  caixa.classList.add('rs-a4')
  caixa.innerHTML = `<div class="rs-a4-aviso">Carregando a via do documento…</div>
    <div class="rs-a4-folha"><iframe class="rs-a4-quadro" title="Via A4 do documento" scrolling="no"></iframe></div>`

  const quadro = caixa.querySelector('iframe')
  quadro.addEventListener('load', () => {
    caixa.querySelector('.rs-a4-aviso')?.remove()
    ajustarViaA4NoResumo()
  })
  // A peça gravada vem pelo endereço; a prévia, pelo HTML já em mãos.
  if (html !== null) quadro.srcdoc = html
  else quadro.src = url
}

/** Recalcula a escala da via A4 para a largura atual do resumo. */
function ajustarViaA4NoResumo() {
  const caixa = document.getElementById('nd-resumo')
  const folha = caixa?.querySelector('.rs-a4-folha')
  const quadro = caixa?.querySelector('.rs-a4-quadro')
  if (!folha || !quadro || !folha.clientWidth) return
  let altura = 1123   // uma folha A4, se a página ainda não puder ser medida
  try { altura = Math.max(quadro.contentDocument.documentElement.scrollHeight, 400) } catch (_) { /* fica a folha padrão */ }

  // Ocupa a largura toda: encolhe em tela estreita e AMPLIA em tela larga (até
  // 1,5×, para a folha não virar um cartaz num monitor grande).
  const escala = Math.min(1.5, folha.clientWidth / LARGURA_A4_PX)
  quadro.style.width = LARGURA_A4_PX + 'px'
  quadro.style.height = altura + 'px'
  quadro.style.transform = `scale(${escala})`
  // O transform não muda o espaço que o elemento ocupa: a altura da moldura
  // é que acompanha a folha encolhida.
  folha.style.height = Math.ceil(altura * escala) + 'px'
}

// Girar o tablet ou redimensionar a janela muda a largura disponível.
window.addEventListener('resize', () => { if (fdState.aba === 'resumo') ajustarViaA4NoResumo() })
