/**
 * PAINEL DE CORREÇÃO — corrigir as pendências sem perder o lugar.
 *
 * Antes, cada "ver no mapa" da conferência fechava a janela; depois de
 * unificar, desmembrar ou editar, voltar era reabrir a ficha do topo e
 * procurar de novo a pendência. Agora a lista fica num painel no mapa, à
 * direita, enquanto se corrige:
 *
 *   1. escolhe-se a pendência (o lote vem marcado e enquadrado);
 *   2. escolhe-se a correção — o painel oferece as que servem àquele problema;
 *   3. corrige-se com a ferramenta de sempre da alteração cadastral;
 *   4. gravou, a conferência se refaz sozinha: a pendência sai com ✓ e o
 *      painel vai para a próxima. Desistiu, o painel volta na mesma.
 *
 * Serve às duas conferências — a da IMPORTAÇÃO (ficha, importacoes.js) e a do
 * BAIRRO (conferencia-bairro.js) —, que têm o mesmo formato de resultado.
 */

const CORR_TIPOS_DESENHO = {
  invalido: { rotulo: 'Contorno inválido', curto: 'contorno inválido', cor: '#EF9F27' },
  repetido: { rotulo: 'Número repetido no arquivo', curto: 'número repetido', cor: '#EF9F27' },
}

/** As correções que o painel sabe lançar, com o nome do botão. */
const CORR_ACOES = {
  editar: 'Editar lote', desmembramento: 'Desmembrar', unificacao: 'Unificar', quadra: 'Corrigir quadra',
  numero: 'Informar número', excluir: 'Excluir lote', desenho: 'Desenhar lote', justificar: 'Justificar',
}

const correcaoState = {
  aberto: false,
  /** @type {'importacao'|'bairro'|null} */
  origem: null,
  /** @type {Array<Object>} as pendências abertas, na ordem do painel */
  itens: [],
  /** chave da pendência em que se está */
  atual: null,
  /** @type {Array<Object>} resolvidas nesta sessão do painel (para o ✓ e a contagem) */
  resolvidas: [],
  filtro: null,
  busca: '',
  /** Uma ferramenta lançada daqui está em uso: o painel fica recolhido. */
  emFerramenta: false,
  iniciouEm: 0,
  /** Gravou alguma coisa desde que a ferramenta foi lançada. */
  gravou: false,
  /** A conferência está sendo refeita depois de uma gravação. */
  conferindo: false,
  /** Unificar pede o vizinho marcado antes. */
  esperaUnificar: false,
  /** @type {Map<number, {lat:number, lon:number}>} */
  posicoes: new Map(),
}

const correcaoAberta = () => correcaoState.aberto

/** A chave estável de uma pendência — a mesma vinda da janela ou montada aqui. */
function _corrChave(tipo, l) {
  return tipo + '|' + (l.lote_id ? 'l' + l.lote_id : 'i' + (l.inscricao || '') + '|' + (l.quadra ?? '') + '|' + (l.lote ?? ''))
}

const _corrTipo = t => (typeof CONF_TIPOS !== 'undefined' && CONF_TIPOS[t]) || CORR_TIPOS_DESENHO[t] || { rotulo: t, curto: t, cor: '#888' }

// ── AS PENDÊNCIAS, DE ONDE ELAS VÊM ──────────────────────────

function _corrMontar() {
  const num = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0
  const porLote = (a, b) => num(a.quadra) - num(b.quadra) || num(a.lote) - num(b.lote)
  const porArea = (a, b) => Math.abs(b.diferenca ?? 0) - Math.abs(a.diferenca ?? 0) || porLote(a, b)
  const lista = []
  const junta = (tipo, itens, ordem = porLote) => [...(itens || [])].sort(ordem)
    .forEach(l => lista.push({ ...l, tipo, chave: _corrChave(tipo, l) }))

  if (correcaoState.origem === 'importacao') {
    // A cópia do painel: outra ferramenta abrindo larga a importação (sairDaCuradoria).
    const i = correcaoState.imp || impState.atual
    const c = i?.conferencia_cadastro || {}
    const p = i?.pendencias_desenho || { invalidos: [], repetidos: [] }
    junta('invalido', p.invalidos)
    junta('repetido', p.repetidos)
    junta('area_diferente', c.area_diferente, porArea)
    junta('nao_encontrados', c.nao_encontrados)
    junta('inativos', c.inativos)
    junta('sem_inscricao', c.sem_inscricao)
    junta('sem_lote', c.sem_lote)
  } else {
    const r = confState.resultado || {}
    const aberta = (tipo, l) => !_confJustificada(tipo, l)
    junta('area_diferente', (r.area_diferente || []).filter(l => aberta('area_diferente', l)), porArea)
    for (const t of ['nao_encontrados', 'inativos', 'sem_inscricao', 'sem_lote']) junta(t, (r[t] || []).filter(l => aberta(t, l)))
  }
  return lista
}

/** As pendências que o filtro e a pesquisa deixam à vista. */
function _corrVisiveis() {
  const termos = _jtNorm(correcaoState.busca).split(/\s+/).filter(Boolean)
  return correcaoState.itens.filter(it => {
    if (correcaoState.filtro && it.tipo !== correcaoState.filtro) return false
    if (!termos.length) return true
    const texto = _jtNorm(`${it.inscricao || ''} q ${it.quadra ?? ''} l ${it.lote ?? ''} ${_corrTipo(it.tipo).curto}`)
    return termos.every(t => texto.includes(t))
  })
}

const _corrItem = () => correcaoState.itens.find(it => it.chave === correcaoState.atual) || null

// ── O QUE FAZER COM CADA PENDÊNCIA ───────────────────────────

/** A frase do problema e, logo depois, o que fazer. */
function _corrFrase(it) {
  if (it.tipo === 'invalido') return 'O contorno deste lote se cruza. Corrija os cantos com Editar lote, ou exclua e desenhe de novo.'
  if (it.tipo === 'repetido') return `O arquivo trazia dois lotes ${esc(it.lote)} na quadra ${esc(it.quadra)}: este entrou sem número. Informe o número certo, ou exclua o que sobrou.`
  if (it.tipo === 'area_diferente') {
    const cad = Number(it.area_cadastro) || 0, dif = Number(it.diferenca) || 0
    const rel = cad ? Math.abs(dif) / cad : 0
    const dica = rel > 0.3 && dif > 0 ? 'O desenho está bem maior que o cadastro: pode haver um lote vizinho junto. Desmembre.'
      : rel > 0.3 && dif < 0 ? 'O desenho está bem menor que o cadastro: pode faltar uma parte. Unifique com o vizinho ou edite os cantos.'
        : 'A diferença é pequena: ajuste os cantos com Editar lote.'
    return `${_confAreas(it)}. ${dica}`
  }
  return esc(_confProblema(it))
}

/** As correções que servem ao problema — a primeira é a recomendada. */
function _corrSugestoes(it) {
  const imp = correcaoState.origem === 'importacao'
  const justificar = imp ? [] : ['justificar']
  switch (it.tipo) {
    case 'area_diferente': {
      const cad = Number(it.area_cadastro) || 0, dif = Number(it.diferenca) || 0
      const rel = cad ? Math.abs(dif) / cad : 0
      return rel > 0.3 && dif > 0 ? ['desmembramento', 'editar', 'unificacao']
        : rel > 0.3 && dif < 0 ? ['unificacao', 'editar', 'desmembramento']
          : ['editar', 'desmembramento', 'unificacao']
    }
    case 'nao_encontrados':
    case 'sem_inscricao': return [...(imp ? ['numero'] : []), 'editar', 'quadra', ...justificar]
    case 'inativos': return ['desmembramento', 'unificacao', 'editar', ...justificar]
    case 'sem_lote': return ['desenho', ...justificar]
    case 'invalido': return ['editar', 'excluir']
    case 'repetido': return ['numero', 'excluir']
  }
  return ['editar']
}

// ── ABRIR, IR, FECHAR ────────────────────────────────────────

/**
 * Abre o painel. Chamado da ficha da importação ou da conferência do bairro,
 * com a pendência escolhida (ou a primeira).
 *
 * @param {'importacao'|'bairro'} origem
 * @param {string} [chave]
 */
async function abrirCorrecao(origem, chave) {
  const mudou = correcaoState.origem !== origem
    || (origem === 'importacao' && correcaoState.impId !== impState.atual?.id)
    || (origem === 'bairro' && correcaoState.bairro !== confState.bairro)
  correcaoState.origem = origem
  correcaoState.impId = origem === 'importacao' ? impState.atual?.id : null
  correcaoState.imp = origem === 'importacao' ? impState.atual : null
  correcaoState.bairro = origem === 'bairro' ? confState.bairro : null
  if (mudou) { correcaoState.resolvidas = []; correcaoState.filtro = null; correcaoState.busca = ''; correcaoState.posicoes.clear() }
  correcaoState.itens = _corrMontar()
  correcaoState.atual = chave && correcaoState.itens.some(it => it.chave === chave) ? chave
    : (correcaoState.itens.some(it => it.chave === correcaoState.atual) ? correcaoState.atual : correcaoState.itens[0]?.chave ?? null)
  correcaoState.aberto = true
  correcaoState.emFerramenta = false
  correcaoState.conferindo = false
  correcaoState.esperaUnificar = false

  const rolavel = document.querySelector(origem === 'importacao' ? '#m-importacoes .jt-rolagem' : '#m-conferencia .jt-rolagem')
  if (rolavel) correcaoState.rolagemFicha = rolavel.scrollTop

  if (origem === 'importacao') {
    fecharImportacoes()
    // Os lotes da importação só existem no mapa com a camada de revisão ligada.
    if (!camadaLigada('nao-publicados')) ligarCamada('nao-publicados', true)
    // A barra da importação sai: quem fala da importação agora é o painel.
    impState.noMapa = false
  } else {
    fModalBtn('m-conferencia')
    confState.noMapa = true
    confState.soFoco = false
    confState.filtro = null
    if (!camadaLigada('conferencia')) ligarCamada('conferencia', true)
  }
  if (typeof irPara === 'function') irPara('mapa')
  document.body.classList.add('com-correcao')
  if (origem === 'importacao') pintarBarraImportacao()
  else pintarBarraConferencia()
  _corrPintar()
  const it = _corrItem()
  if (it) await _corrIr(it)
}

/** Fecha o painel. A pendência em que se estava fica guardada para a volta. */
function fecharCorrecao(semBarra = false) {
  if (!correcaoState.aberto) return
  correcaoState.aberto = false
  correcaoState.emFerramenta = false
  document.body.classList.remove('com-correcao')
  const p = document.getElementById('corr-painel')
  if (p) p.hidden = true
  if (correcaoState.origem === 'bairro') {
    confState.noMapa = false
    repintarConferencia()
    pintarBarraConferencia()
  } else {
    if (!impState.atual && correcaoState.imp) impState.atual = correcaoState.imp
    if (semBarra || !_impEmAndamento(impState.atual)) return
    // De volta, a barra da importação (com o "‹ Ficha") reaparece.
    impState.noMapa = true
    pintarBarraImportacao()
  }
}

/**
 * FECHAR O PAINEL É VOLTAR À JANELA de onde se veio — a ficha da importação
 * ou a conferência do bairro —, no mesmo ponto da lista, com o mesmo filtro e
 * a mesma pesquisa, e sem barra nenhuma sobrando no mapa.
 */
async function voltarAFichaDaCorrecao() {
  const origem = correcaoState.origem
  fecharCorrecao(true)
  if (origem === 'importacao') {
    if (impState.preCuradoria) sairPreCuradoria()
    impState.noMapa = false
    pintarBarraImportacao()
    if (correcaoState.impId) await abrirImportacao(correcaoState.impId)
  } else if (origem === 'bairro') {
    await abrirConferenciaBairro(correcaoState.bairro)
  }
  const rolagem = correcaoState.rolagemFicha || 0
  setTimeout(() => {
    const q = document.querySelector(origem === 'importacao' ? '#m-importacoes .jt-rolagem' : '#m-conferencia .jt-rolagem')
    if (q) q.scrollTop = rolagem
  }, 60)
}

/** Leva o mapa até a pendência e deixa o lote marcado, pronto para a ferramenta. */
async function _corrIr(it) {
  correcaoState.atual = it.chave
  correcaoState.esperaUnificar = false
  _corrPintar()
  const imp = correcaoState.origem === 'importacao'
  // A alteração cadastral aberta: é dela que saem as ferramentas.
  if (imp) {
    // Outra ferramenta pode ter largado a importação no caminho: o painel a devolve.
    if (!impState.atual && correcaoState.imp) impState.atual = correcaoState.imp
    _ligarPreCuradoria(impState.atual)
    _abrirMesaDaCuradoria()
  } else if (typeof garantirPainelCadastroAberto === 'function') {
    garantirPainelCadastroAberto()
  }
  if (!imp) {
    // As cores da conferência acendem a pendência em que se está.
    const i = confState.itens.findIndex(x => _corrChave(x.tipo, x) === it.chave)
    if (i >= 0) confState.idx = i
    repintarConferencia()
  }

  if (!it.lote_id) {
    // Sem lote (o imóvel do cadastro que falta desenhar): vai à quadra.
    const q = String(it.quadra ?? '').replace(/^0+/, '') || '0'
    const c = confState.resultado?.centros_quadras?.[q]
    if (!imp && c) mapaState.obj?.setView(c, ZOOM_DO_LOTE)
    else if (imp && impState.atual?.extensao) {
      const e = impState.atual.extensao
      mapaState.obj?.fitBounds([[e.sul, e.oeste], [e.norte, e.leste]], { padding: [30, 30] })
    }
    if (typeof limparSelecaoCadastral === 'function') limparSelecaoCadastral()
    return
  }

  try {
    const id = Number(it.lote_id)
    let pos = correcaoState.posicoes.get(id)
    if (!pos) {
      const r = await fetch('/api/imoveis/' + id, { headers: { Accept: 'application/json' } })
      const d = await r.json()
      if (!r.ok) throw new Error(d.message || 'Lote não encontrado.')
      pos = { lat: d.lat, lon: d.lon }
      correcaoState.posicoes.set(id, pos)
    }
    if (pos.lat && pos.lon) mapaState.obj?.setView([pos.lat, pos.lon], ZOOM_DO_LOTE)
    await destacarLoteQuandoCarregar(id)
    if (correcaoState.atual !== it.chave) return   // já se foi para outra
    const camada = mapaState.porId?.get(id)
    if (camada && typeof alternarSelecao === 'function') {
      limparSelecaoCadastral()
      alternarSelecao(camada.feature, camada)
    }
  } catch (e) {
    toast(e.message, 'err')
  }
}

function _corrPasso(d) {
  const vis = _corrVisiveis()
  if (!vis.length) return
  const i = vis.findIndex(it => it.chave === correcaoState.atual)
  _corrIr(vis[(i + d + vis.length) % vis.length])
}

function _corrIrPara(chave) {
  const it = correcaoState.itens.find(x => x.chave === chave)
  if (it) _corrIr(it)
}

function _corrFiltrar(tipo) {
  correcaoState.filtro = tipo && correcaoState.filtro !== tipo ? tipo : null
  const vis = _corrVisiveis()
  if (vis.length && !vis.some(it => it.chave === correcaoState.atual)) _corrIr(vis[0])
  else _corrPintar()
}

function _corrBuscar(campo) {
  correcaoState.busca = campo.value
  _corrPintarLista()
}

// ── CORRIGIR ─────────────────────────────────────────────────

/** Lança a ferramenta da correção escolhida, com o lote da pendência marcado. */
function corrigirCom(acao) {
  const it = _corrItem()
  if (!it) return
  const imp = correcaoState.origem === 'importacao'
  const lancar = () => {
    correcaoState.emFerramenta = true
    correcaoState.gravou = false
    correcaoState.iniciouEm = Date.now()
    _corrPintar()
  }
  switch (acao) {
    case 'editar':
      lancar()
      PranchetaCad.abrir('edicao', [Number(it.lote_id)], null, null, {
        quadra: it.quadra, numero_lote: it.lote,
        // Área diferente: a prancheta mostra quanto falta ou sobra para a do cadastro.
        areaAlvo: it.tipo === 'area_diferente' ? Number(it.area_cadastro) : undefined,
      }).catch(e => { correcaoState.emFerramenta = false; _corrPintar(); toast(e.message, 'err') })
      return
    case 'desenho':
      lancar()
      modoCadastral('desenho')
      toast('Desenhe o lote que falta. Ao gravar, a conferência se refaz sozinha.', 'aviso')
      return
    case 'numero':
      // Uma pergunta só, numa janela: o painel não precisa recolher.
      numerarLoteDaPreCuradoria()
      return
    case 'excluir':
      if (imp) { excluirLotesDaPreCuradoria(); return }
      lancar(); acionarFerramenta('apagar')
      return
    case 'justificar':
      _confJustificar(it.tipo, _confChave(it.tipo, it))
      return
    case 'unificacao':
      // Unificar pede o vizinho: com um lote só, o painel ensina o passo.
      if (typeof selState === 'undefined' || selState.ids.size < 2) {
        correcaoState.esperaUnificar = true
        _corrPintar()
        return
      }
      lancar(); acionarFerramenta('unificacao')
      return
    default:
      lancar(); acionarFerramenta(acao)
  }
}

// ── A VOLTA DAS FERRAMENTAS ──────────────────────────────────

/** Alguma ferramenta da alteração cadastral ainda está em uso? */
function _corrFerramentaEmUso() {
  return (typeof cadModo !== 'undefined' && !!cadModo)
    || (typeof atoState !== 'undefined' && !!atoState.tipo)
    || (typeof PranchetaCad !== 'undefined' && PranchetaCad.ativa?.())
    || (typeof desmMesa !== 'undefined' && !!desmMesa.ativa)
}

let _corrEncerrarTimer = null
document.addEventListener('ferramenta-encerrada', () => {
  if (!correcaoState.aberto || !correcaoState.emFerramenta) return
  // O próprio lançamento às vezes larga o modo anterior: isso não é a volta.
  if (Date.now() - correcaoState.iniciouEm < 700) return
  clearTimeout(_corrEncerrarTimer)
  _corrEncerrarTimer = setTimeout(() => {
    if (_corrFerramentaEmUso()) return
    correcaoState.emFerramenta = false
    if (correcaoState.gravou) {
      // Gravou: a conferência se refaz (abaixo) e o painel decide para onde ir.
      correcaoState.conferindo = true
      _corrPintar()
      return
    }
    // Desistiu: volta na mesma pendência, com o lote marcado de novo.
    const it = _corrItem()
    _corrPintar()
    if (it) _corrIr(it)
  }, 500)
})

let _corrRecarregarTimer = null
document.addEventListener('lotes-alterados', () => {
  if (!correcaoState.aberto) return
  // Só conta como correção o que veio de uma ferramenta lançada daqui.
  if (correcaoState.emFerramenta || Date.now() - correcaoState.iniciouEm < 15000) correcaoState.gravou = true
  if (correcaoState.origem !== 'importacao') return   // o bairro se reconfere sozinho (conferencia-bairro.js)
  clearTimeout(_corrRecarregarTimer)
  _corrRecarregarTimer = setTimeout(async () => {
    try {
      const id = correcaoState.impId
      if (!id) return
      let i = await _impPedir('/api/importacoes/' + id)
      if (await _impReconferirSePreciso(i)) i = await _impPedir('/api/importacoes/' + id)
      impState.atual = i
      correcaoState.imp = i
      _corrAtualizar()
    } catch { /* fica a lista de antes */ }
  }, 1600)
})

// A conferência do bairro foi refeita (ou uma pendência justificada).
document.addEventListener('conferencia-atualizada', () => {
  if (correcaoState.aberto && correcaoState.origem === 'bairro') _corrAtualizar()
})

/**
 * Depois de a conferência mudar: o que saiu da lista foi resolvido. Se a
 * pendência em que se estava saiu, o painel vai para a próxima.
 */
function _corrAtualizar() {
  const antes = correcaoState.itens
  const atual = _corrItem()
  const posicao = Math.max(0, _corrVisiveis().findIndex(it => it.chave === correcaoState.atual))
  correcaoState.itens = _corrMontar()
  const ficaram = new Set(correcaoState.itens.map(it => it.chave))
  const sairam = antes.filter(it => !ficaram.has(it.chave))
  correcaoState.resolvidas.push(...sairam.filter(it => !correcaoState.resolvidas.some(r => r.chave === it.chave)))
  correcaoState.conferindo = false

  if (atual && !ficaram.has(atual.chave)) {
    toast(`Q ${atual.quadra ?? '—'} · L ${atual.lote ?? '—'}: resolvida.`)
    const vis = _corrVisiveis()
    const prox = vis[Math.min(posicao, vis.length - 1)]
    if (prox) { _corrIr(prox); return }
    correcaoState.atual = null
    toast(correcaoState.itens.length ? 'Não há mais pendências neste filtro.' : 'Nenhuma pendência em aberto. Tudo conferido.')
  } else if (atual && correcaoState.gravou) {
    toast('Gravado, mas a pendência continua: confira a medida ou tente outra correção.', 'aviso')
  }
  correcaoState.gravou = false
  _corrPintar()
}

// ── O PAINEL ─────────────────────────────────────────────────

function _corrPainel() {
  let p = document.getElementById('corr-painel')
  if (!p) {
    p = document.createElement('aside')
    p.id = 'corr-painel'
    p.className = 'corr-painel'
    p.setAttribute('aria-label', 'Painel de correção')
    document.getElementById('t-mapa')?.appendChild(p)
  }
  return p
}

/** Em que passo se está — é o que a faixa do topo acende. */
function _corrPassoAtual() {
  if (correcaoState.conferindo) return 4
  if (correcaoState.emFerramenta) return 3
  return _corrItem() ? 2 : 1
}

function _corrPintar() {
  const p = _corrPainel()
  p.hidden = !correcaoState.aberto
  if (!correcaoState.aberto) return
  const it = _corrItem()
  const passo = _corrPassoAtual()
  const total = correcaoState.itens.length + correcaoState.resolvidas.length
  const feitas = correcaoState.resolvidas.length
  const nome = correcaoState.origem === 'importacao'
    ? (correcaoState.imp?.bairro || 'Importação') : (confState.oficial || confState.bairro || 'Bairro')
  p.classList.toggle('recolhido', correcaoState.emFerramenta)

  const passos = ['Escolha a pendência', 'Escolha a correção', 'Corrija no mapa', 'Conferido sozinho']
    .map((t, i) => `<li class="${i + 1 < passo ? 'feito' : i + 1 === passo ? 'agora' : ''}"><b>${i + 1}</b>${t}</li>`).join('')

  const cabeca = `
    <div class="corr-cab">
      <div class="corr-tit"><b>Correção · ${esc(nome)}</b>
        <button type="button" class="corr-x" onclick="voltarAFichaDaCorrecao()" title="Fechar e voltar à lista" aria-label="Fechar e voltar à lista">&#10005;</button></div>
      <ol class="corr-passos">${passos}</ol>
    </div>`

  if (correcaoState.emFerramenta) {
    p.innerHTML = `${cabeca}
      <div class="corr-recolhido">
        <div>Corrigindo <b>Q ${esc(it?.quadra ?? '—')} · L ${esc(it?.lote ?? '—')}</b>. Termine na ferramenta, ou cancele: o painel volta sozinho.</div>
      </div>`
    return
  }

  const contaTipo = {}
  for (const x of correcaoState.itens) contaTipo[x.tipo] = (contaTipo[x.tipo] || 0) + 1
  // OS CARTÕES DA CONFERÊNCIA, aqui também: um por tipo, e clicar filtra a
  // lista (outro clique no mesmo cartão volta a mostrar todas).
  const cartao = (t, n) => {
    const at = correcaoState.filtro === t
    return `<button type="button" class="imp-kpi conf-kpi${at ? ' ativo' : ''}" style="--kpi-cor:${_corrTipo(t).cor}" aria-pressed="${at}"
      title="${at ? 'Mostrar todas' : 'Mostrar só estas'}" onclick="_corrFiltrar(${jsArg(t)})"><small><i></i>${esc(_corrTipo(t).curto)}</small><b>${n}</b></button>`
  }
  const cartoes = `<div class="imp-kpi kpi-ok" style="--kpi-cor:#1D9E75"><small><i></i>resolvidas</small><b>${feitas}</b></div>`
    + Object.entries(contaTipo).map(([t, n]) => cartao(t, n)).join('')

  p.innerHTML = `${cabeca}
    <div class="corr-progresso">
      <span>${correcaoState.conferindo ? 'Conferindo de novo…' : `<b>${feitas}</b> de ${total} resolvida${total === 1 ? '' : 's'}`}</span>
      <div class="corr-barra"><i style="width:${total ? Math.round(feitas * 100 / total) : 0}%"></i></div>
    </div>
    <div class="imp-kpis corr-kpis">${cartoes}</div>
    ${correcaoState.filtro ? `<div class="corr-filtro">Só <b>${esc(_corrTipo(correcaoState.filtro).rotulo)}</b> · <a href="#" onclick="event.preventDefault(); _corrFiltrar(null)">mostrar todas</a></div>` : ''}
    <label class="jt-busca corr-busca" for="corr-busca">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" id="corr-busca" placeholder="Pesquisar quadra, lote ou inscrição" autocomplete="off" value="${esc(correcaoState.busca)}" oninput="_corrBuscar(this)">
    </label>
    <div class="corr-lista" id="corr-lista"></div>
    <div class="corr-pe">
      <span class="corr-nav">
        <button type="button" class="btn sm" onclick="_corrPasso(-1)" title="Pendência anterior">&#8249; Anterior</button>
        <button type="button" class="btn sm" onclick="_corrPasso(1)" title="Próxima pendência">Próxima &#8250;</button>
      </span>
      <button type="button" class="btn sm" onclick="voltarAFichaDaCorrecao()">Voltar à lista</button>
    </div>`
  _corrPintarLista()
}

function _corrPintarLista() {
  const lista = document.getElementById('corr-lista')
  if (!lista) return
  const vis = _corrVisiveis()
  const linha = it => {
    const t = _corrTipo(it.tipo)
    const medida = it.tipo === 'area_diferente' && it.diferenca != null
      ? `${it.diferenca > 0 ? '+' : '−'}${fmtNum(Math.abs(it.diferenca))} m²` : esc(t.curto)
    if (it.chave !== correcaoState.atual) {
      return `<button type="button" class="corr-item" onclick="_corrIrPara(${jsArg(it.chave)})">
        <i style="background:${t.cor}"></i><span>Q ${esc(it.quadra ?? '—')} · L ${esc(it.lote ?? '—')}</span><small>${medida}</small></button>`
    }
    const sug = _corrSugestoes(it)
    return `<div class="corr-item atual" id="corr-atual">
      <div class="corr-item-topo"><i style="background:${t.cor}"></i><b>Q ${esc(it.quadra ?? '—')} · L ${esc(it.lote ?? '—')}</b><small>${medida}</small></div>
      <div class="corr-tipo">${esc(t.rotulo)}${it.inscricao ? ` · <span class="mono">${esc(it.inscricao)}</span>` : ''}</div>
      <p class="corr-frase">${_corrFrase(it)}</p>
      ${correcaoState.esperaUnificar ? '<p class="corr-dica">Toque no lote vizinho para marcá-lo junto (aparece em laranja) e toque em <b>Unificar</b> de novo.</p>' : ''}
      <div class="corr-acoes">${sug.map((a, i) => `<button type="button" class="btn sm${i === 0 ? ' primary' : ''}" onclick="corrigirCom(${jsArg(a)})">${CORR_ACOES[a]}</button>`).join('')}</div>
    </div>`
  }
  const feitas = correcaoState.resolvidas.slice(-3).reverse().map(r =>
    `<div class="corr-item feita"><span>&#10003;</span><span>Q ${esc(r.quadra ?? '—')} · L ${esc(r.lote ?? '—')}</span><small>resolvida</small></div>`).join('')
  lista.innerHTML = (vis.length ? vis.map(linha).join('')
    : `<div class="corr-vazio">${correcaoState.itens.length ? 'Nenhuma pendência com este filtro ou pesquisa.' : 'Nenhuma pendência em aberto.'}</div>`) + feitas
  document.getElementById('corr-atual')?.scrollIntoView({ block: 'nearest' })
}
