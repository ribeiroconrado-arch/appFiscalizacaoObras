// ══════════════════════════════════════════════
// MÓDULO: VISTORIA (Etapa 5)
//
// Fecha o ciclo de campo: do lote identificado no mapa até a vistoria gravada
// com checklist, observações e fotos — e o histórico do imóvel logo abaixo,
// que é o que o fiscal consulta ANTES de decidir o que fazer na visita.
// ══════════════════════════════════════════════

/** Estado do formulário de vistoria. */
const vState = {
  /** @type {Object|null} lote sendo vistoriado */ lote: null,
  /** @type {Array<Object>} catálogo de irregularidades (cache da sessão) */ catalogo: [],
  /** @type {Array<{arquivo:File, titulo:string, descricao:string, url:string}>} */ anexos: [],
  /**
   * O relatório, na ordem em que o fiscal montou.
   *
   * Uma lista só para os quatro tipos de linha, e não quatro listas paralelas:
   * a ORDEM entre tipos diferentes é a informação — a foto logo depois do
   * artigo que ela ilustra conta algo que a mesma foto no fim de uma pilha
   * não conta. Quatro listas separadas não teriam onde guardar isso.
   *
   * @type {Array<{tipo:'foto'|'citacao'|'parecer'|'exigencia', texto:string,
   *               anexo?:number, artigo_id?:number, prazo?:number|null,
   *               fachada?:boolean, marcacoes?:Array<{n:number,x:number,y:number}>}>}
   */
  relatorio: [],
  /** @type {number|null} índice do item aberto na janela de edição */ itemAberto: null,
  /** @type {Array<Object>} artigos sugeridos pelas irregularidades marcadas */ artigos: [],
  /** @type {Set<number>} artigos confirmados pelo fiscal */ artigosMarcados: new Set(),
  /** @type {string} para que serve esta vistoria — decide os passos */
  finalidade: 'obras',
  /** @type {{alvara:string, fase:string, projeto:string, uso:string}} escolhas em botão */
  obra: { alvara: '', fase: '', projeto: '', uso: '' },
  /** @type {{lat:number, lon:number, prec:number}|null} posição da vistoria */ gps: null,
  /** @type {string} chave do passo visível */ passo: 'id',
  /** @type {number} índice do passo mais avançado — é o que marca ✓ na barra */ visitados: 0,
  /** @type {Array<string>|null} marcas do rascunho à espera do catálogo */ rascunhoIrreg: null,
  /** @type {boolean} a escolha de artigos veio do rascunho, não da sugestão */ artigosDoRascunho: false,
  /** @type {boolean} a tela está sendo montada — não gravar rascunho ainda */ abrindo: false,
  /**
   * Protocolo de desmembramento/unificação que esta vistoria vai atender.
   *
   * Preenchido quando o formulário é aberto A PARTIR do protocolo; nulo
   * quando o fiscal abriu pelo mapa, e aí o seletor pergunta.
   */
  /** @type {number|null} */ protocoloId: null,
  enviando: false,
}

// ── HISTÓRICO ────────────────────────────────────────────────

/**
 * Busca e renderiza o histórico do lote dentro da ficha.
 * @param {number} loteId
 */
async function carregarHistorico(loteId) {
  const alvo = document.getElementById('fi-historico')
  alvo.innerHTML = '<div class="vazio-msg">Carregando histórico…</div>'
  try {
    const r = await fetch(`/api/lotes/${loteId}/historico`, { headers: { 'Accept': 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    renderHistorico(d.eventos ?? [])
    renderResumo(d.resumo ?? null)
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="vazio-msg">Não foi possível carregar o histórico.</div>'
  }
}

/**
 * Preenche a aba Dados com o retrato do imóvel: status, vistorias, fachada.
 *
 * Vem junto do histórico de propósito — é a mesma consulta, e pedir duas vezes
 * ao servidor a mesma informação seria trabalho dobrado para o aparelho do
 * fiscal, que é o dispositivo mais fraco da cadeia.
 *
 * @param {Object|null} resumo
 */
function renderResumo(resumo) {
  const põe = (id, texto) => {
    const el = document.getElementById(id)
    if (el) { el.textContent = texto }
  }

  if (!resumo) {
    põe('fi-status', '—'); põe('fi-qt-vistorias', '—'); põe('fi-ultima-vistoria', '—')
    return
  }

  const st = document.getElementById('fi-status')
  if (st) {
    st.innerHTML = `<span class="badge ${esc(resumo.status.classe)}">${esc(resumo.status.texto)}</span>`
  }

  põe('fi-qt-vistorias', String(resumo.vistorias ?? 0))
  põe('fi-ultima-vistoria', resumo.ultima_vistoria || 'nenhuma')

  // A situação do cabeçalho segue o status quando ele é definitivo (lote
  // baixado), porque aí o imóvel não existe mais e isso vale mais do que
  // qualquer outra informação da ficha.
  const sit = document.getElementById('fi-situacao')
  if (sit && resumo.status.texto === 'Baixado') {
    sit.className = 'badge bd-cx'
    sit.textContent = 'Baixado'
  }

  renderFachada(resumo.fachada)
}

/** A foto mais recente do imóvel, com a data dela. @param {Object|null} f */
function renderFachada(f) {
  const fig = document.getElementById('fi-fachada')
  const data = document.getElementById('fi-fachada-data')
  if (!fig) { return }

  const vazio = fig.querySelector('.fi-vazio')
  const img = fig.querySelector('img')

  if (!f) {
    if (data) { data.textContent = '' }
    if (img) { img.remove() }
    if (vazio) { vazio.style.display = '' }
    return
  }

  if (data) { data.textContent = f.quando ? '· ' + f.quando : '' }
  if (vazio) { vazio.style.display = 'none' }

  const alvo = img || document.createElement('img')
  alvo.src = f.url
  alvo.alt = 'Fachada do imóvel'
  alvo.loading = 'lazy'
  if (!img) { fig.appendChild(alvo) }
}

/** Ícone de cada tipo de evento da linha do tempo. */
const ICO_EVENTO = {
  vistoria: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/>',
  documento: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/>',
  protocolo: '<path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/><rect x="9" y="2" width="6" height="4" rx="1"/>',
}

/**
 * Linha do tempo do imóvel, do mais recente para o mais antigo.
 *
 * Um evento por marco: vistoria, documento lavrado e requerimento. O traço
 * vertical ligando os marcos é o que transforma uma lista em sequência —
 * é a sequência que conta a história do processo.
 *
 * @param {Array<Object>} eventos
 */
function renderHistorico(eventos) {
  const alvo = document.getElementById('fi-historico')
  document.getElementById('fi-hist-total').textContent =
    eventos.length ? `${eventos.length} registro${eventos.length > 1 ? 's' : ''}` : ''

  if (!eventos.length) {
    alvo.innerHTML = '<div class="vazio-msg">Nada registrado neste imóvel ainda.</div>'
    return
  }

  alvo.innerHTML = eventos.map(e => {
    const itens = (e.itens || []).length
      ? `<div class="lt-itens">${e.itens.map(i => '• ' + esc(i)).join('<br>')}</div>` : ''
    const obs = e.obs ? `<div class="lt-obs">${esc(e.obs)}</div>` : ''
    const det = e.detalhe ? `<div class="lt-det">${esc(e.detalhe)}</div>` : ''
    return `
      <div class="lt-item lt-${esc(e.tipo)}">
        <div class="lt-marca">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">${ICO_EVENTO[e.tipo] || ''}</svg>
        </div>
        <div class="lt-corpo">
          <div class="lt-topo">
            <span class="lt-data">${esc(e.quando ?? '—')}</span>
            ${e.badge ? `<span class="badge ${esc(e.badge.classe)}">${esc(e.badge.texto)}</span>` : ''}
          </div>
          <div class="lt-tit">${esc(e.titulo)}</div>
          ${det}${itens}${obs}${_atoCadastral(e)}
        </div>
      </div>`
  }).join('')
}

/**
 * Bloco do ato cadastral dentro do evento de vistoria.
 *
 * Aparece em três estados, e o do meio é o que costuma faltar nos sistemas:
 *
 *   nada        vistoria comum, sem protocolo de desmembramento/unificação;
 *   explicação  há o protocolo, mas algo trava — e o texto diz o quê;
 *   botão       tudo no lugar: executa o ato a partir desta vistoria.
 *
 * Mostrar o motivo em vez de simplesmente esconder o botão é o que evita o
 * chamado "por que não aparece a opção de unificar?".
 *
 * @param {Object} e evento da linha do tempo
 */
function _atoCadastral(e) {
  const a = e.ato_cadastral
  if (!a || !a.tipo) { return '' }

  const rotulo = a.tipo === 'unificacao' ? 'Unificar lotes' : 'Desenhar desmembramento'
  const proto = a.protocolo ? `Protocolo ${esc(a.protocolo.numero)}` : ''

  if (!a.pode) {
    return `<div class="lt-ato lt-ato-travado">${proto}: ${esc(a.motivo || 'ato indisponível.')}</div>`
  }

  return `<div class="lt-ato">
    <span>${proto} — deferido e vistoriado.</span>
    <button class="btn sm primary" onclick="iniciarAtoCadastral(${a.protocolo.id}, '${esc(a.tipo)}', ${e.lote_id ?? 'null'})">
      ${rotulo}</button></div>`
}

// ── FORMULÁRIO ───────────────────────────────────────────────

/** Abre o formulário de nova vistoria para o lote selecionado. */
async function novaVistoria() {
  const f = state.selecionado
  if (!f) { toast('Selecione um lote no mapa', 'err'); return }

  vState.lote = f.properties
  // Trava a gravação do rascunho durante a montagem. Sem isto, o primeiro
  // irPasso() da abertura salvava a tela ainda VAZIA por cima do rascunho que
  // ele estava justamente indo buscar — o trabalho de campo era apagado pelo
  // ato de voltar para ele.
  vState.abrindo = true
  zerarVistoria()
  fModalBtn('m-ficha')

  document.getElementById('nv-lote').textContent =
    `${f.properties.bairro} · Quadra ${f.properties.quadra ?? '—'} · Lote ${f.properties.numero_lote ?? '—'}`

  // Data e hora já preenchidas com o momento da abertura — o fiscal está em
  // campo, e digitar data no celular é o que ele menos quer fazer.
  document.getElementById('nv-data').value = dataHojeLocal()
  document.getElementById('nv-hora').value = horaAgoraLocal()
  syncDataHora()
  // "Regular" e o estado de quem ainda nao constatou nada — e e o desfecho da
  // maioria das vistorias. Nascer "Irregular" fazia a tela pedir uma
  // irregularidade do catalogo para deixar avancar, mesmo numa atualizacao
  // cadastral ou num auto de constatacao, onde nem se procura irregularidade.
  document.getElementById('nv-situacao').value = 'regular'

  // A posição já capturada no mapa serve de ponto de partida; o botão do
  // passo 1 é o que a atualiza para o lugar onde o fiscal está agora.
  if (state.pos) { vState.gps = { ...state.pos } }
  pintarGps()

  irPasso('id')
  restaurarRascunho()
  vState.abrindo = false
  await carregarProtocolosCadastrais(f.properties.id)
  await carregarCatalogo()
  openModal('m-vistoria')
}

/** Devolve o formulário ao estado de folha em branco. */
function zerarVistoria() {
  vState.anexos.forEach(a => a.url && URL.revokeObjectURL(a.url))
  vState.anexos = []
  vState.relatorio = []
  vState.itemAberto = null
  vState.artigos = []
  vState.artigosMarcados = new Set()
  vState.finalidade = 'obras'
  vState.obra = { alvara: '', fase: '', projeto: '', uso: '' }
  vState.gps = null
  vState.passo = 'id'
  vState.visitados = 0

  const põe = (id, v) => { const e = document.getElementById(id); if (e) { e.value = v } }
  põe('nv-obs', ''); põe('nv-area', ''); põe('nv-area-metodo', '')
  põe('nv-acomp-nome', ''); põe('nv-acomp-qual', ''); põe('nv-alvara-numero', '')
  põe('nv-ano', '')
  põe('nv-exig-texto', ''); põe('nv-exig-prazo', '')
  document.getElementById('nv-alvara-num-campo').hidden = true
  document.getElementById('nv-rascunho').hidden = true
  // O checklist é DOM que sobrevive ao fechamento do modal: sem desmarcá-lo,
  // a vistoria seguinte nasceria com as irregularidades da anterior.
  document.querySelectorAll('#nv-checklist input:checked').forEach(c => {
    c.checked = false
    c.closest('.chk-item')?.classList.remove('marcado')
  })
  pintarOpcoes(); pintarFinalidade(); renderRelatorio()
  document.getElementById('nv-artigos').innerHTML =
    '<div class="leg">Marque as irregularidades para ver os artigos que as enquadram.</div>'
}

/** Fecha guardando o que foi digitado — ver salvarRascunho(). */
function fecharVistoria() {
  if (temConteudo()) { salvarRascunho() } else { limparRascunho() }
  fModalBtn('m-vistoria')
}

// ── PASSOS ───────────────────────────────────────────────────

/**
 * Move de passo. Cada painel é um assunto; a barra do topo diz onde se está.
 * @param {number} d -1 ou +1
 */
function passo(d) {
  const lista = passosDaVistoria()
  const i = lista.findIndex(x => x.k === vState.passo)
  const alvo = lista[i + d]
  if (!alvo) { return }
  // Só barra ao AVANÇAR: voltar para conferir nunca pode ser impedido.
  if (d > 0 && !passoCompleto(vState.passo)) { return }
  irPasso(alvo.k)
}

/**
 * O que impede de avançar. Deliberadamente pouco: o formulário não pode virar
 * interrogatório, e o que de fato não pode faltar é conferido na gravação.
 * @param {number} n
 */
/**
 * As finalidades e o que cada uma pergunta.
 *
 * Espelha Vistoria::FINALIDADES no servidor — e o espelho é conferido por
 * prova, porque duas listas que se separam em silêncio é como um campo passa
 * a ser oferecido aqui e ignorado lá.
 */
const FINALIDADES = {
  obras:         { passo: 'A obra',        campos: ['alvara', 'area', 'fase'] },
  cadastral:     { passo: 'O imóvel',      campos: ['area', 'uso', 'ano'] },
  habite_se:     { passo: 'A conclusão',   campos: ['alvara', 'area', 'projeto', 'fase'] },
  regularizacao: { passo: 'A construção',  campos: ['alvara', 'area', 'ano', 'uso', 'projeto'] },
  constatacao:   { passo: null,            campos: [] },
}

/**
 * Os passos da finalidade corrente, por CHAVE e não por número.
 *
 * O auto de constatação não tem passo de medição: são três passos, e o
 * "Relatório" é o segundo. Numerar os passos no código faria essa variação
 * virar aritmética espalhada por toda parte.
 *
 * @returns {Array<{k:string, rotulo:string}>}
 */
function passosDaVistoria() {
  const f = FINALIDADES[vState.finalidade] || FINALIDADES.obras
  const lista = [{ k: 'id', rotulo: 'Identificação' }]
  if (f.passo) { lista.push({ k: 'obra', rotulo: f.passo }) }
  lista.push({ k: 'rel', rotulo: 'Relatório' })
  lista.push({ k: 'rev', rotulo: 'Revisão' })
  return lista
}

/**
 * O que impede de avançar. Deliberadamente pouco: o formulário não pode virar
 * interrogatório, e o que de fato não pode faltar é conferido na gravação.
 *
 * @param {string} k chave do passo
 */
function passoCompleto(k) {
  if (k === 'id' && !document.getElementById('nv-datahora').value) {
    toast('Informe data e hora da vistoria', 'err'); return false
  }
  if (k === 'obra') {
    const area = document.getElementById('nv-area').value
    if (area && !document.getElementById('nv-area-metodo').value) {
      toast('Diga como a área foi obtida', 'err'); return false
    }
  }
  if (k === 'rel' && document.getElementById('nv-situacao').value === 'irregular'
      && !document.querySelectorAll('#nv-checklist input:checked').length) {
    toast('Marque ao menos uma irregularidade', 'err'); return false
  }
  return true
}

/** @param {string} k chave do passo: 'id', 'obra', 'rel' ou 'rev' */
function irPasso(k) {
  const lista = passosDaVistoria()
  if (!lista.some(x => x.k === k)) { k = lista[0].k }   // o passo sumiu com a finalidade

  vState.passo = k
  const i = lista.findIndex(x => x.k === k)
  vState.visitados = Math.max(vState.visitados, i)

  pintarBarraDePassos()
  document.querySelectorAll('.vs-painel').forEach(p => {
    p.classList.toggle('at', p.dataset.passo === k)
  })

  document.getElementById('nv-voltar').hidden = i === 0
  document.getElementById('nv-avancar').hidden = i === lista.length - 1
  document.getElementById('nv-gravar').hidden = i !== lista.length - 1
  const corpo = document.querySelector('.vs-corpo')
  if (corpo) { corpo.scrollTop = 0 }

  if (k === 'rel') { sugerirArtigos() }
  if (k === 'rev') { renderRevisao() }
  salvarRascunho()
}

/** A barra do topo — montada, e não fixa, porque os passos variam. */
function pintarBarraDePassos() {
  const barra = document.getElementById('nv-passos')
  if (!barra) { return }
  const lista = passosDaVistoria()

  barra.innerHTML = lista.map((p, i) => {
    const at = p.k === vState.passo
    const feito = i < vState.visitados && !at
    return `<button type="button" class="vs-passo${at ? ' at' : ''}${feito ? ' feito' : ''}"
              data-passo="${p.k}" onclick="irPasso('${p.k}')">
              <span class="n">${i + 1}</span>${esc(p.rotulo)}</button>`
  }).join('')

  // Em tela estreita a barra rola: o passo atual tem de se trazer para dentro,
  // ou o fiscal perde a única referência de onde está.
  barra.querySelector('.vs-passo.at')?.scrollIntoView({ block: 'nearest', inline: 'center' })
}

/**
 * A escolha que decide o resto da tela.
 *
 * Trocar a finalidade REFAZ os passos na hora — inclusive fazendo o segundo
 * desaparecer, no auto de constatação. O que já foi digitado nos campos que
 * somem continua na tela (só escondido) e é descartado na gravação pelo
 * servidor, que é quem tem a palavra final sobre o que pertence a quê.
 *
 * @param {string} valor
 */
function escolherFinalidade(valor) {
  if (!FINALIDADES[valor]) { return }
  vState.finalidade = valor
  pintarFinalidade()

  // Se o passo em que se está deixou de existir, cai no relatório — que é o
  // passo que toda finalidade tem.
  const lista = passosDaVistoria()
  irPasso(lista.some(x => x.k === vState.passo) ? vState.passo : 'rel')
  salvarRascunho()
}

/** Pinta a escolha e mostra só os blocos que a finalidade pede. */
function pintarFinalidade() {
  const f = FINALIDADES[vState.finalidade] || FINALIDADES.obras

  document.querySelectorAll('#nv-finalidade .vs-op').forEach(b =>
    b.classList.toggle('at', b.dataset.valor === vState.finalidade))

  document.querySelectorAll('#nv-p-obra [data-bloco]').forEach(bloco => {
    bloco.hidden = !f.campos.includes(bloco.dataset.bloco)
  })
  pintarBarraDePassos()
}

/**
 * Atalho da ronda de rotina: situação, foto, gravar.
 *
 * A vistoria de rotina é a esmagadora maioria, e obrigá-la a atravessar cinco
 * passos cobraria mais do que a informação que os passos coletam — o custo
 * disso não é um formulário chato, é o fiscal deixando de registrar.
 */
function vistoriaRapida() {
  if (!passoCompleto(1)) { return }
  vState.visitados = passosDaVistoria().length - 1
  irPasso('rel')
  // A foto entra pelo mesmo botão do relatório — o atalho só pula o que não
  // se preenche numa ronda de rotina, e não inventa um segundo caminho.
  setTimeout(() => escolherArquivoDeFoto(), 60)
  toast('Vistoria rápida: a foto e pronto')
}

// ── PASSO 1: A POSIÇÃO ───────────────────────────────────────

/** A coordenada de onde o fiscal está AGORA — a prova de que ele esteve lá. */
function capturarGpsVistoria() {
  const btn = document.getElementById('nv-gps-btn')
  if (!navigator.geolocation) { toast('Este aparelho não informa a posição', 'err'); return }

  btn.disabled = true
  btn.textContent = 'Capturando…'
  navigator.geolocation.getCurrentPosition(
    pos => {
      vState.gps = {
        lat: pos.coords.latitude,
        lon: pos.coords.longitude,
        prec: pos.coords.accuracy,
      }
      pintarGps()
      btn.disabled = false
      salvarRascunho()
    },
    err => {
      btn.disabled = false
      btn.textContent = vState.gps ? 'Atualizar' : 'Capturar'
      // O tratamento de permissão negada já existe e ensina a liberar —
      // "erro ao obter posição" não resolve nada para quem está em campo.
      if (err.code === err.PERMISSION_DENIED) { comoLiberarLocalizacao() }
      else { toast('Não foi possível obter a posição', 'err') }
    },
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 },
  )
}

function pintarGps() {
  const el = document.getElementById('nv-gps')
  const btn = document.getElementById('nv-gps-btn')
  if (!el) { return }
  if (!vState.gps) { el.textContent = 'não capturada'; return }
  el.textContent = `${vState.gps.lat.toFixed(6)}, ${vState.gps.lon.toFixed(6)}`
                 + ` (±${Math.round(vState.gps.prec)} m)`
  if (btn) { btn.textContent = 'Atualizar' }
}

// ── PASSO 2: A OBRA ──────────────────────────────────────────

/** @param {string} v */
function escolherAlvara(v) {
  vState.obra.alvara = vState.obra.alvara === v ? '' : v
  // O número só faz sentido quando há alvará: campo aberto para quem respondeu
  // "não possui" é convite a preencher o que não existe.
  document.getElementById('nv-alvara-num-campo').hidden = vState.obra.alvara !== 'possui'
  pintarOpcoes(); salvarRascunho()
}

/** @param {string} v */
function escolherFase(v) {
  vState.obra.fase = vState.obra.fase === v ? '' : v
  pintarOpcoes(); salvarRascunho()
}

/** @param {string} v */
function escolherProjeto(v) {
  vState.obra.projeto = vState.obra.projeto === v ? '' : v
  pintarOpcoes(); salvarRascunho()
}

/** @param {string} v */
function escolherUso(v) {
  vState.obra.uso = vState.obra.uso === v ? '' : v
  pintarOpcoes(); salvarRascunho()
}

function pintarOpcoes() {
  const marca = (id, valor) => document.querySelectorAll('#' + id + ' .vs-op')
    .forEach(b => b.classList.toggle('at', b.dataset.valor === valor))
  marca('nv-alvara', vState.obra.alvara)
  marca('nv-fase', vState.obra.fase)
  marca('nv-projeto', vState.obra.projeto)
  marca('nv-uso', vState.obra.uso)
}

/**
 * Protocolos de desmembramento/unificação deste imóvel à espera de vistoria.
 *
 * O seletor só aparece quando há algum: numa vistoria de rotina — que é a
 * esmagadora maioria — perguntar "atende a qual protocolo?" seria ruído.
 *
 * Este é o caminho de quem parte do MAPA, em campo. Quem parte da tela de
 * protocolos chega pelo botão "Registrar vistoria", que já traz o protocolo
 * escolhido.
 *
 * @param {number} loteId
 */
async function carregarProtocolosCadastrais(loteId) {
  const caixa = document.getElementById('nv-protocolo-caixa')
  const sel = document.getElementById('nv-protocolo')
  if (!caixa || !sel) { return }

  sel.innerHTML = '<option value="">— nenhum —</option>'
  caixa.hidden = true
  if (!loteId) { return }

  try {
    const r = await fetch('/api/lotes/' + loteId + '/protocolos-cadastrais',
      { headers: { Accept: 'application/json' } })
    if (!r.ok) { return }
    const d = await r.json()
    if (!d.protocolos.length) { return }

    d.protocolos.forEach(p => {
      const o = document.createElement('option')
      o.value = p.id
      o.textContent = p.rotulo
      sel.appendChild(o)
    })
    caixa.hidden = false

    // Quando a vistoria foi aberta A PARTIR de um protocolo, ele já vem
    // escolhido e o campo não é uma pergunta, é uma confirmação.
    if (vState.protocoloId) { sel.value = String(vState.protocoloId) }
  } catch (e) {
    console.error(e)   // sem protocolo o formulário funciona igual
  }
}

/** Busca o catálogo de irregularidades uma vez por sessão. */
async function carregarCatalogo() {
  if (vState.catalogo.length) { renderChecklist(); return }
  try {
    const r = await fetch('/api/irregularidades', { headers: { 'Accept': 'application/json' } })
    vState.catalogo = await r.json()
  } catch (e) {
    console.error(e)
    toast('Não foi possível carregar o checklist', 'err')
  }
  renderChecklist()
}

function renderChecklist() {
  const alvo = document.getElementById('nv-checklist')
  alvo.innerHTML = vState.catalogo.map(i => `
    <label class="chk-item" onclick="setTimeout(()=>{this.classList.toggle('marcado', this.querySelector('input').checked);sugerirArtigos()},0)">
      <input type="checkbox" name="irregularidades[]" value="${i.id}">
      <span class="desc">${esc(i.descricao)}<br><span class="cod">${esc(i.codigo)} · ${esc(i.gravidade)}</span></span>
    </label>`).join('')

  // O catálogo só chega do servidor DEPOIS de o rascunho ser lido, e por isso
  // as marcas são reaplicadas aqui — não em restaurarRascunho, onde ainda não
  // existiam caixas para marcar.
  if (vState.rascunhoIrreg?.length) {
    vState.rascunhoIrreg.forEach(id => {
      const c = alvo.querySelector(`input[value="${id}"]`)
      if (c) { c.checked = true; c.closest('.chk-item').classList.add('marcado') }
    })
    vState.rascunhoIrreg = null
    sugerirArtigos()
  }
}

// ── PASSO 3: ARTIGOS DE LEI ──────────────────────────────────

/**
 * Os artigos que enquadram o que foi marcado.
 *
 * Vem para a vistoria — e não só para a lavratura, semanas depois — porque é
 * aqui que os fatos estão à vista. Quem confere o enquadramento diante da obra
 * pode ainda medir, fotografar ou perguntar; na mesa, não pode mais.
 */
async function sugerirArtigos() {
  const alvo = document.getElementById('nv-artigos')
  if (!alvo) { return }

  // Enquanto o rascunho tem marcas à espera do catálogo, o checklist na tela
  // ainda está vazio — e concluir daí que "nada foi marcado" apagaria a
  // escolha de artigos que o rascunho acabou de trazer.
  if (vState.rascunhoIrreg) { return }

  const ids = [...document.querySelectorAll('#nv-checklist input:checked')].map(c => c.value)
  if (!ids.length) {
    vState.artigos = []
    vState.artigosMarcados = new Set()
    alvo.innerHTML = '<div class="leg">Marque as irregularidades para ver os artigos que as enquadram.</div>'
    return
  }

  try {
    const r = await fetch('/api/artigos-sugeridos?irregularidades=' + ids.join(','),
      { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const d = await r.json()

    // Sugerido é sugerido: artigo NOVO na lista já vem marcado, e o fiscal
    // desmarca o que não couber. Só os novos — remarcar o que ele acabou de
    // desmarcar (ou o que veio do rascunho) desfaria a decisão dele a cada
    // clique no checklist.
    const jaVistos = new Set(vState.artigos.map(x => x.id))
    vState.artigos = d.artigos ?? []
    if (vState.artigosDoRascunho) { vState.artigosDoRascunho = false }
    else { vState.artigos.forEach(x => { if (!jaVistos.has(x.id)) { vState.artigosMarcados.add(x.id) } }) }
    renderArtigos(d.sem_artigo ?? [])
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="leg">Não foi possível buscar os artigos agora. '
                   + 'A vistoria pode ser gravada assim mesmo.</div>'
  }
}

/** @param {Array<string>} semArtigo irregularidades que nenhum artigo enquadra */
function renderArtigos(semArtigo) {
  const alvo = document.getElementById('nv-artigos')

  // Dizer o que NÃO está fundamentado é o ponto: escondido, o fiscal veria
  // três artigos e concluiria que as cinco marcações estão cobertas.
  const aviso = semArtigo.length
    ? `<div class="cad-nota">Sem artigo cadastrado: ${semArtigo.map(esc).join('; ')}.
        A vistoria grava, mas a peça vai precisar do enquadramento.</div>`
    : ''

  if (!vState.artigos.length) {
    alvo.innerHTML = aviso || '<div class="leg">Nenhum artigo enquadra o que foi marcado.</div>'
    return
  }

  alvo.innerHTML = vState.artigos.map(a => {
    const m = vState.artigosMarcados.has(a.id)
    // "Por m² construído" avisa, ali mesmo, que a área do passo 2 é o que vai
    // fechar a conta da multa.
    const base = a.base ? `<div class="base">${esc(a.base)}${a.lei ? ' · ' + esc(a.lei) : ''}</div>` : ''
    return `<label class="vs-artigo${m ? ' marcado' : ''}">
      <input type="checkbox" ${m ? 'checked' : ''} onchange="alternarArtigo(${a.id}, this.checked)">
      <span style="flex:1;min-width:0">
        <span class="num">${esc(a.numero)}</span>
        <span class="cond">${esc(a.conduta ?? '')}</span>${base}
      </span></label>`
  }).join('') + aviso
}

/** @param {number} id @param {boolean} marcado */
function alternarArtigo(id, marcado) {
  marcado ? vState.artigosMarcados.add(id) : vState.artigosMarcados.delete(id)
  renderArtigos([])
  salvarRascunho()
}

/** Mantém o campo escondido com o valor combinado aaaa-mm-ddThh:mm. */
function syncDataHora() {
  const d = document.getElementById('nv-data').value
  const h = document.getElementById('nv-hora').value || '00:00'
  document.getElementById('nv-datahora').value = d ? `${d}T${h}` : ''
  atualizarDisplayData(document.getElementById('nv-data'))
}

// ── PASSO 3: O RELATÓRIO ─────────────────────────────────────
//
// Quatro tipos de linha, uma lista só, um botão só. A ordem entre elas é
// conteúdo: a foto logo depois do artigo que ela ilustra conta algo que a
// mesma foto no fim de uma pilha de fotos não conta.

/** O que o botão "Adicionar" oferece, e para que serve cada um. */
const TIPOS_ITEM = {
  foto: {
    rotulo: 'Foto',
    obs: 'Uma imagem com legenda — e marcas apontando o que ela mostra.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>`,
  },
  citacao: {
    rotulo: 'Artigo com observação',
    obs: 'O que se constatou em relação ao dispositivo. Vira FATO na peça.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>`,
  },
  parecer: {
    rotulo: 'Parecer sobre um artigo',
    obs: 'A sua conclusão sobre ele. Vira FUNDAMENTAÇÃO.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>`,
  },
  exigencia: {
    rotulo: 'Exigência',
    obs: 'O que o administrado deve fazer, com prazo. A notificação imprime.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>`,
  },
}

/** @param {Event} ev */
function menuItemRelatorio(ev) {
  abrirMenuNovo(ev.currentTarget, Object.entries(TIPOS_ITEM).map(([tipo, t]) => ({
    rotulo: t.rotulo,
    obs: t.obs,
    icone: t.icone,
    // Traço antes da exigência: acima está o que se CONSTATOU, abaixo o que se
    // COBRA. São naturezas diferentes dentro do mesmo relatório.
    separar: tipo === 'exigencia',
    acao: () => novoItemRelatorio(tipo),
  })))
}

/** @param {'foto'|'citacao'|'parecer'|'exigencia'} tipo */
function novoItemRelatorio(tipo) {
  if (tipo === 'foto') { escolherArquivoDeFoto(); return }

  if ((tipo === 'citacao' || tipo === 'parecer') && !vState.artigos.length) {
    // Sem artigo carregado não há o que citar. Buscar agora é melhor do que
    // abrir uma janela com um seletor vazio.
    sugerirArtigos().then(() => abrirItemRelatorio(criarItem(tipo)))
    return
  }
  abrirItemRelatorio(criarItem(tipo))
}

/** @param {string} tipo @returns {number} índice do item criado */
function criarItem(tipo) {
  vState.relatorio.push({ tipo, texto: '', artigo_id: null, prazo: null })
  return vState.relatorio.length - 1
}

/** Abre o seletor de arquivo do relatório — o mesmo para o atalho rápido. */
function escolherArquivoDeFoto() {
  document.getElementById('nv-arquivo')?.click()
}

/**
 * Handler do input de arquivo: cada arquivo entra como um ITEM do relatório,
 * no fim da lista, e a janela abre no primeiro para pedir a legenda.
 *
 * @param {HTMLInputElement} input
 */
function anexarArquivos(input) {
  const primeiro = vState.relatorio.length
  for (const arquivo of input.files) {
    vState.anexos.push({
      arquivo,
      titulo: arquivo.name.replace(/\.[^.]+$/, '').slice(0, 160),
      descricao: '',
      url: arquivo.type.startsWith('image/') ? URL.createObjectURL(arquivo) : null,
    })
    vState.relatorio.push({
      tipo: 'foto', texto: '', anexo: vState.anexos.length - 1,
      fachada: false, marcacoes: [],
    })
  }
  input.value = ''   // permite reanexar o mesmo arquivo depois de remover

  // A primeira foto de imagem vira a fachada por padrão: é quase sempre a que
  // o fiscal tira primeiro, e uma marca errada custa um toque para corrigir —
  // enquanto marca nenhuma faz a ficha voltar a mostrar foto qualquer.
  if (!vState.relatorio.some(i => i.fachada)) {
    const f = vState.relatorio.find(i => i.tipo === 'foto' && vState.anexos[i.anexo]?.url)
    if (f) { f.fachada = true }
  }

  renderRelatorio(); salvarRascunho()
  if (vState.relatorio[primeiro]) { abrirItemRelatorio(primeiro) }
}

/** A lista, na ordem montada. Cada linha é só o resumo; o texto mora na janela. */
function renderRelatorio() {
  const alvo = document.getElementById('nv-relatorio')
  if (!alvo) { return }

  if (!vState.relatorio.length) {
    alvo.innerHTML = '<div class="vazio-msg">Nada no relatório ainda. '
                   + 'Vistoria regular costuma ter uma foto e uma linha de parecer.</div>'
    return
  }

  alvo.innerHTML = vState.relatorio.map((item, i) => {
    const t = TIPOS_ITEM[item.tipo] || TIPOS_ITEM.citacao
    const anexo = item.tipo === 'foto' ? vState.anexos[item.anexo] : null
    const artigo = item.artigo_id ? vState.artigos.find(a => a.id === item.artigo_id) : null

    const capa = anexo
      ? `<div class="rel-thumb">${anexo.url ? `<img src="${anexo.url}" alt="">` : '<div class="pdf">PDF</div>'}
           ${(item.marcacoes || []).length ? `<span class="rel-marcas">${item.marcacoes.length}</span>` : ''}</div>`
      : `<div class="rel-ico rel-ico-${esc(item.tipo)}">${t.icone}</div>`

    const titulo = item.tipo === 'foto'
      ? esc(anexo?.titulo || 'Foto')
      : artigo ? esc(artigo.numero) : t.rotulo

    const falta = !item.texto.trim()
      || ((item.tipo === 'citacao' || item.tipo === 'parecer') && !item.artigo_id)

    return `
      <div class="rel-item${falta ? ' rel-falta' : ''}" onclick="abrirItemRelatorio(${i})">
        ${capa}
        <div class="rel-corpo">
          <div class="rel-topo">
            <span class="rel-tipo">${esc(t.rotulo)}</span>
            ${item.fachada ? '<span class="badge bd-ok">Fachada</span>' : ''}
            ${item.prazo ? `<span class="badge bd-al">${item.prazo} dias</span>` : ''}
          </div>
          <div class="rel-tit">${titulo}</div>
          <div class="rel-txt">${item.texto.trim() ? esc(item.texto) : '<i>sem texto — toque para escrever</i>'}</div>
        </div>
        <div class="rel-mover">
          <button type="button" class="rel-seta" title="Subir"
                  onclick="event.stopPropagation();moverItem(${i},-1)"${i === 0 ? ' disabled' : ''}>&#9650;</button>
          <button type="button" class="rel-seta" title="Descer"
                  onclick="event.stopPropagation();moverItem(${i},1)"${i === vState.relatorio.length - 1 ? ' disabled' : ''}>&#9660;</button>
        </div>
      </div>`
  }).join('')
}

/** Sobe ou desce um item — é assim que a sequência do relatório se ajusta. */
function moverItem(i, d) {
  const j = i + d
  if (j < 0 || j >= vState.relatorio.length) { return }
  const [item] = vState.relatorio.splice(i, 1)
  // `splice` fora do intervalo devolve lista vazia, e reinserir `undefined`
  // deixaria um buraco na lista que so estoura na proxima pintura — longe
  // daqui, com a mensagem errada. Melhor nao mexer.
  if (!item) { renderRelatorio(); return }
  vState.relatorio.splice(j, 0, item)
  renderRelatorio(); salvarRascunho()
}

// ── A JANELA DE UM ITEM ──────────────────────────────────────

/** @param {number} i */
function abrirItemRelatorio(i) {
  const item = vState.relatorio[i]
  if (!item) { return }
  vState.itemAberto = i

  const t = TIPOS_ITEM[item.tipo]
  document.getElementById('vsi-titulo').textContent = t.rotulo
  document.getElementById('vsi-foto').hidden = item.tipo !== 'foto'
  document.getElementById('vsi-artigo').hidden = item.tipo !== 'citacao' && item.tipo !== 'parecer'
  document.getElementById('vsi-exigencia').hidden = item.tipo !== 'exigencia'

  document.getElementById('vsi-texto').value = item.texto || ''
  document.getElementById('vsi-texto-rot').textContent =
    item.tipo === 'foto' ? 'O que a foto mostra'
      : item.tipo === 'parecer' ? 'Parecer'
      : item.tipo === 'exigencia' ? 'O que deve ser feito'
      : 'O que foi constatado'

  if (item.tipo === 'foto') {
    const anexo = vState.anexos[item.anexo]
    document.getElementById('vsi-titulo-foto').value = anexo?.titulo || ''
    document.getElementById('vsi-fachada').checked = !!item.fachada
    const img = document.getElementById('vsi-img')
    img.src = anexo?.url || ''
    img.hidden = !anexo?.url
    document.getElementById('vsi-dica').textContent = anexo?.url
      ? 'Toque na foto para apontar o que descreve.'
      : 'Arquivo PDF — sem marcação.'
    pintarPinos()
  }

  if (item.tipo === 'citacao' || item.tipo === 'parecer') {
    preencherSeletorDeArtigos(item.artigo_id)
  }

  if (item.tipo === 'exigencia') {
    document.getElementById('vsi-prazo').value = item.prazo ?? ''
  }

  openModal('m-vs-item')
}

/**
 * O seletor de artigos da janela.
 *
 * Lista os sugeridos pelas irregularidades marcadas — e, quando não há
 * nenhuma marcada, diz isso em vez de mostrar um seletor vazio, que pareceria
 * defeito.
 *
 * @param {number|null} escolhido
 */
function preencherSeletorDeArtigos(escolhido) {
  const sel = document.getElementById('vsi-artigo-id')
  const nota = document.getElementById('vsi-artigo-nota')

  sel.innerHTML = '<option value="">— escolha o artigo —</option>'
    + vState.artigos.map(a =>
        `<option value="${a.id}">${esc(a.numero)}${a.conduta ? ' — ' + esc(a.conduta.slice(0, 70)) : ''}</option>`).join('')
  if (escolhido) { sel.value = String(escolhido) }

  nota.hidden = vState.artigos.length > 0
  nota.textContent = 'Nenhum artigo sugerido ainda. Marque as irregularidades do '
                   + 'catálogo, no fim da tela, para o sistema oferecer os dispositivos.'
}

function salvarItemRelatorio() {
  const i = vState.itemAberto
  const item = vState.relatorio[i]
  if (!item) { return }

  item.texto = document.getElementById('vsi-texto').value.trim()

  if (item.tipo === 'foto') {
    const anexo = vState.anexos[item.anexo]
    if (anexo) { anexo.titulo = document.getElementById('vsi-titulo-foto').value.trim() || anexo.titulo }
    const fachada = document.getElementById('vsi-fachada').checked
    // Uma fachada por vistoria: ela responde "como está o imóvel hoje", e duas
    // respostas não respondem nada.
    if (fachada) { vState.relatorio.forEach(x => { x.fachada = false }) }
    item.fachada = fachada
  }

  if (item.tipo === 'citacao' || item.tipo === 'parecer') {
    const id = document.getElementById('vsi-artigo-id').value
    if (!id) { toast('Escolha o artigo', 'err'); return }
    item.artigo_id = Number(id)
  }

  if (item.tipo === 'exigencia') {
    if (!item.texto) { toast('Escreva a exigência', 'err'); return }
    const p = document.getElementById('vsi-prazo').value
    item.prazo = p ? Number(p) : null
  }

  fModalBtn('m-vs-item')
  renderRelatorio(); salvarRascunho()
}

/** Exclusão SEMPRE pergunta antes — regra sem exceção. */
function excluirItemRelatorio() {
  const i = vState.itemAberto
  const item = vState.relatorio[i]
  if (!item) { return }

  confirmarAcao({
    titulo: 'Remover do relatório',
    mensagem: 'Remover este item da vistoria?',
    textoBtn: 'Remover',
    perigo: true,
    onConfirm: () => {
      if (item.tipo === 'foto') {
        const anexo = vState.anexos[item.anexo]
        if (anexo?.url) { URL.revokeObjectURL(anexo.url) }
        // O anexo NÃO sai do array: os índices dos outros itens apontam para
        // posições dele. Marcar como removido e filtrar no envio custa um
        // campo; reindexar custaria um bug silencioso na foto errada.
        if (anexo) { anexo.removido = true }
      }
      vState.relatorio.splice(i, 1)
      fModalBtn('m-vs-item')
      renderRelatorio(); salvarRascunho()
    },
  })
}

// ── MARCAÇÕES SOBRE A FOTO ───────────────────────────────────
//
// Um toque crava um número na imagem. A legenda pode então dizer "1" e "2" em
// vez de "no canto superior direito, mais ou menos no meio" — que é o tipo de
// descrição que ninguém consegue conferir meses depois.
//
// As coordenadas são RELATIVAS (0 a 1): a mesma foto aparece em tamanhos
// diferentes na janela, na lista e na ficha, e pixel absoluto sairia do lugar.

/** @param {MouseEvent} ev */
function marcarNaFoto(ev) {
  const item = vState.relatorio[vState.itemAberto]
  if (!item || item.tipo !== 'foto') { return }
  const img = document.getElementById('vsi-img')
  if (img.hidden) { return }

  const r = img.getBoundingClientRect()
  const x = (ev.clientX - r.left) / r.width
  const y = (ev.clientY - r.top) / r.height
  if (x < 0 || x > 1 || y < 0 || y > 1) { return }

  item.marcacoes = item.marcacoes || []
  if (item.marcacoes.length >= 20) { toast('Limite de 20 marcas na foto', 'aviso'); return }
  item.marcacoes.push({ n: item.marcacoes.length + 1, x: +x.toFixed(4), y: +y.toFixed(4) })
  pintarPinos()
}

function limparMarcacoes() {
  const item = vState.relatorio[vState.itemAberto]
  if (!item) { return }
  item.marcacoes = []
  pintarPinos()
}

function pintarPinos() {
  const item = vState.relatorio[vState.itemAberto]
  const alvo = document.getElementById('vsi-pinos')
  if (!alvo || !item) { return }
  alvo.innerHTML = (item.marcacoes || []).map(m =>
    `<span class="vsi-pino" style="left:${(m.x * 100).toFixed(2)}%;top:${(m.y * 100).toFixed(2)}%">${m.n}</span>`
  ).join('')
}

// ── PASSO 5: REVISÃO ─────────────────────────────────────────

/**
 * O ato como ele será gravado.
 *
 * Última tela antes de gravar tem de ser a LEITURA do ato, não mais um
 * formulário: é daqui que saem notificação, auto de infração e embargo.
 */
function renderRevisao() {
  const marcadas = [...document.querySelectorAll('#nv-checklist input:checked')]
  const sit = document.getElementById('nv-situacao')
  const area = document.getElementById('nv-area').value
  const metodo = document.getElementById('nv-area-metodo')
  const obs = document.getElementById('nv-obs').value.trim()
  const acomp = document.getElementById('nv-acomp-nome').value.trim()
  const qual = document.getElementById('nv-acomp-qual')

  const falta = t => `<span class="falta">${t}</span>`
  const rotOp = (id, v) =>
    document.querySelector(`#${id} .vs-op[data-valor="${v}"]`)?.textContent.trim() ?? ''

  const rotuloFinalidade = document.querySelector('#nv-finalidade .vs-op.at .t')?.textContent.trim()
  const campos = (FINALIDADES[vState.finalidade] || FINALIDADES.obras).campos

  const linhas = [
    // A finalidade abre a leitura do ato: ela é o que explica por que as
    // linhas seguintes são estas e não outras.
    ['Finalidade', esc(rotuloFinalidade || 'Fiscalização de obras')],
    ['Imóvel', esc(document.getElementById('nv-lote').textContent)],
    ['Data e hora', esc((document.getElementById('nv-datahora').value || '').replace('T', ' às '))],
    ['Situação', esc(sit.options[sit.selectedIndex].text)],
    ['Coordenada', vState.gps
      ? `${vState.gps.lat.toFixed(6)}, ${vState.gps.lon.toFixed(6)}`
      : falta('não capturada')],
    ['Acompanhante', acomp
      ? esc(acomp) + (qual.value ? ' — ' + esc(qual.options[qual.selectedIndex].text) : '')
      : falta('ninguém identificado')],
    ...(campos.includes('alvara') ? [['Alvará', vState.obra.alvara
      ? esc(rotOp('nv-alvara', vState.obra.alvara))
        + (vState.obra.alvara === 'possui' && document.getElementById('nv-alvara-numero').value
           ? ' nº ' + esc(document.getElementById('nv-alvara-numero').value) : '')
      : falta('não informado')]] : []),
    // A área é a linha que mais importa nesta tela: sem ela, multa por metro
    // quadrado sai como "não calculada" — ver Artigo::calcularMulta().
    ...(campos.includes('area') ? [['Área aferida', area
      ? esc(area) + ' m²' + (metodo.value ? ' (' + esc(metodo.options[metodo.selectedIndex].text.toLowerCase()) + ')' : '')
      : falta('não medida — multa por m² não será calculada')]] : []),
    ...(campos.includes('fase') ? [['Fase da obra',
      vState.obra.fase ? esc(rotOp('nv-fase', vState.obra.fase)) : falta('não informada')]] : []),
    ...(campos.includes('projeto') ? [['Projeto aprovado',
      vState.obra.projeto ? esc(rotOp('nv-projeto', vState.obra.projeto)) : falta('não verificado')]] : []),
    ...(campos.includes('uso') ? [['Uso constatado',
      vState.obra.uso ? esc(rotOp('nv-uso', vState.obra.uso)) : falta('não informado')]] : []),
    ...(campos.includes('ano') ? [['Época da construção',
      document.getElementById('nv-ano').value
        ? 'por volta de ' + esc(document.getElementById('nv-ano').value)
        : falta('não estimada')]] : []),
    ['Relatório', vState.relatorio.length
      ? '<ol>' + vState.relatorio.map(it => {
          const t = TIPOS_ITEM[it.tipo]?.rotulo ?? it.tipo
          const art = it.artigo_id ? vState.artigos.find(a => a.id === it.artigo_id) : null
          const capa = it.tipo === 'foto' ? esc(vState.anexos[it.anexo]?.titulo || 'Foto') : esc(art?.numero || '')
          const marcas = (it.marcacoes || []).length
          return '<li><b>' + esc(t) + '</b>' + (capa ? ' · ' + capa : '')
               + (it.prazo ? ' <b>— ' + it.prazo + ' dias</b>' : '')
               + (marcas ? ' <i>(' + marcas + ' marca' + (marcas > 1 ? 's' : '') + ' na foto)</i>' : '')
               + (it.texto.trim() ? '<br>' + esc(it.texto) : ' ' + falta('sem texto'))
               + '</li>'
        }).join('') + '</ol>'
      : falta('vazio')],
    ['Irregularidades', marcadas.length
      ? '<ol>' + marcadas.map(c =>
          '<li>' + esc(c.closest('.chk-item').querySelector('.desc').firstChild.textContent.trim()) + '</li>').join('') + '</ol>'
      : falta('nenhuma')],
    ['Artigos citados', vState.artigosMarcados.size
      ? esc(vState.artigos.filter(a => vState.artigosMarcados.has(a.id))
            .map(a => a.numero).join(', '))
      : falta('nenhum')],
    ['Fotos', (() => {
      const fotos = vState.relatorio.filter(i => i.tipo === 'foto')
      if (!fotos.length) { return falta('nenhuma') }
      return `${fotos.length} no relatório`
        + (fotos.some(f => f.fachada) ? ', uma marcada como fachada' : ', ' + falta('sem fachada marcada'))
        + (fotos.some(f => !f.texto.trim()) ? '<br>' + falta('há foto sem descrição') : '')
    })()],
    ['Observações', obs ? esc(obs) : falta('nenhuma')],
  ]

  document.getElementById('nv-revisao').innerHTML = linhas.map(([r, v]) => `
    <div class="vs-rev-linha">
      <div class="vs-rev-rot">${r}</div>
      <div class="vs-rev-val">${v}</div>
    </div>`).join('')
}

// ── RASCUNHO NO APARELHO ─────────────────────────────────────
//
// A rede de segurança do trabalho de campo: bateria acabando, navegador
// fechado sem querer ou uma ligação que rouba o foco não podem custar uma
// vistoria inteira. Não é o modo offline — as FOTOS não cabem aqui, e por
// isso o aviso na retomada diz exatamente isso.

const RASCUNHO = 'vistoria-rascunho'

function temConteudo() {
  return !!(document.getElementById('nv-obs').value.trim()
    || document.getElementById('nv-area').value
    || vState.relatorio.length
    || document.querySelectorAll('#nv-checklist input:checked').length)
}

function salvarRascunho() {
  if (vState.abrindo || !vState.lote || !temConteudo()) { return }
  const v = id => document.getElementById(id)?.value ?? ''
  try {
    localStorage.setItem(RASCUNHO, JSON.stringify({
      lote: vState.lote.id,
      quando: Date.now(),
      passo: vState.passo,
      campos: {
        data: v('nv-data'), hora: v('nv-hora'), situacao: v('nv-situacao'),
        obs: v('nv-obs'), area: v('nv-area'), metodo: v('nv-area-metodo'),
        acompNome: v('nv-acomp-nome'), acompQual: v('nv-acomp-qual'),
        alvaraNumero: v('nv-alvara-numero'), ano: v('nv-ano'),
      },
      finalidade: vState.finalidade,
      obra: vState.obra,
      gps: vState.gps,
      // As FOTOS não cabem no armazenamento do navegador, então o rascunho
      // guarda os itens sem elas — e o aviso da retomada diz isso com todas as
      // letras, em vez de deixar o fiscal gravar achando que estão lá.
      relatorio: vState.relatorio.filter(i => i.tipo !== 'foto'),
      artigos: [...vState.artigosMarcados],
      irregularidades: [...document.querySelectorAll('#nv-checklist input:checked')].map(c => c.value),
    }))
  } catch (e) {
    console.error(e)   // cota cheia ou modo privado: o formulário segue igual
  }
}

/** Só retoma rascunho DO MESMO LOTE — o de outro imóvel seria contaminação. */
function restaurarRascunho() {
  let d
  try { d = JSON.parse(localStorage.getItem(RASCUNHO) || 'null') } catch (e) { return }
  if (!d || d.lote !== vState.lote.id) { return }

  const põe = (id, valor) => { const e = document.getElementById(id); if (e && valor) { e.value = valor } }
  const c = d.campos ?? {}
  põe('nv-data', c.data); põe('nv-hora', c.hora); põe('nv-situacao', c.situacao)
  põe('nv-obs', c.obs); põe('nv-area', c.area); põe('nv-area-metodo', c.metodo)
  põe('nv-acomp-nome', c.acompNome); põe('nv-acomp-qual', c.acompQual)
  põe('nv-alvara-numero', c.alvaraNumero); põe('nv-ano', c.ano)
  syncDataHora()

  vState.finalidade = FINALIDADES[d.finalidade] ? d.finalidade : 'obras'
  vState.obra = d.obra ?? { alvara: '', fase: '', projeto: '', uso: '' }
  vState.gps = d.gps ?? vState.gps
  vState.relatorio = d.relatorio ?? []
  vState.artigosMarcados = new Set(d.artigos ?? [])
  vState.artigosDoRascunho = vState.artigosMarcados.size > 0
  // null, e não lista vazia: é ele que destranca sugerirArtigos() acima.
  vState.rascunhoIrreg = d.irregularidades?.length ? d.irregularidades : null
  document.getElementById('nv-alvara-num-campo').hidden = vState.obra.alvara !== 'possui'

  pintarOpcoes(); pintarFinalidade(); pintarGps(); renderRelatorio()
  // O aviso é honesto sobre o que NÃO voltou: foto não cabe no armazenamento
  // do navegador, e deixar isso implícito faria o fiscal gravar sem as fotos.
  const av = document.getElementById('nv-rascunho')
  av.hidden = false
  av.textContent = 'Rascunho recuperado — as fotos precisam ser refeitas'
  irPasso(d.passo ?? 'id')
}

function limparRascunho() {
  try { localStorage.removeItem(RASCUNHO) } catch (e) { /* nada a fazer */ }
}

// ── GRAVAÇÃO ─────────────────────────────────────────────────

/** Grava a vistoria. Confirma antes: é registro que passa a valer como ato. */
function gravarVistoria() {
  if (vState.enviando) return

  const marcadas = [...document.querySelectorAll('#nv-checklist input:checked')]
  const situacao = document.getElementById('nv-situacao').value

  if (!document.getElementById('nv-datahora').value) {
    irPasso('id'); toast('Informe data e hora da vistoria', 'err'); return
  }
  if (situacao === 'irregular' && !marcadas.length) {
    irPasso('rel'); toast('Marque ao menos uma irregularidade', 'err'); return
  }
  // A mesma regra do servidor, dita antes de o fiscal perder o envio: área sem
  // método é número que não se sustenta em defesa.
  if (document.getElementById('nv-area').value && !document.getElementById('nv-area-metodo').value) {
    irPasso('obra'); toast('Diga como a área foi obtida', 'err'); return
  }

  const resumo = marcadas.length
    ? `${marcadas.length} irregularidade${marcadas.length > 1 ? 's' : ''}`
    : 'sem irregularidades'

  confirmarAcao({
    titulo: 'Gravar ' + (document.querySelector('#nv-finalidade .vs-op.at .t')?.textContent.trim().toLowerCase() || 'vistoria'),
    mensagem: `Registrar vistoria do lote ${vState.lote.numero_lote}, quadra `
            + `${vState.lote.quadra}, com ${resumo} e ${vState.relatorio.length} item(ns) no relatório?`,
    textoBtn: 'Gravar',
    onConfirm: () => enviarVistoria(marcadas),
  })
}

/** @param {Array<HTMLInputElement>} marcadas */
async function enviarVistoria(marcadas) {
  vState.enviando = true
  const campo = id => document.getElementById(id)?.value ?? ''
  const fd = new FormData()
  fd.append('data_hora', campo('nv-datahora'))
  // O servidor lê a finalidade ANTES de decidir o que gravar: o que não
  // pertence a ela é descartado lá, e não aqui — ver Vistoria::colunasForaDa.
  fd.append('finalidade', vState.finalidade)
  fd.append('situacao', campo('nv-situacao'))
  fd.append('observacoes', campo('nv-obs'))
  // O vínculo com o protocolo é o que, mais tarde, libera o ato cadastral.
  const proto = document.getElementById('nv-protocolo')?.value
  if (proto) { fd.append('protocolo_id', proto) }
  marcadas.forEach(c => fd.append('irregularidades[]', c.value))

  // ── quem acompanhou e o que se viu da obra ──
  const opcional = (nome, valor) => { if (valor) { fd.append(nome, valor) } }
  opcional('acompanhante_nome', campo('nv-acomp-nome').trim())
  opcional('acompanhante_qualificacao', campo('nv-acomp-qual'))
  opcional('alvara_situacao', vState.obra.alvara)
  if (vState.obra.alvara === 'possui') { opcional('alvara_numero', campo('nv-alvara-numero').trim()) }
  opcional('area_construida_aferida_m2', campo('nv-area'))
  opcional('area_metodo', campo('nv-area-metodo'))
  opcional('fase_obra', vState.obra.fase)
  opcional('conforme_projeto', vState.obra.projeto)
  opcional('uso_constatado', vState.obra.uso)
  opcional('ano_construcao_estimado', campo('nv-ano'))

  // ── o relatório, na ordem montada ──
  //
  // A posição de CADA item vai junto, e é a mesma sequência para fotos e
  // artigos: no servidor as duas coisas moram em tabelas diferentes, e sem a
  // ordem explícita elas voltariam agrupadas por tipo — perdendo justamente o
  // que o fiscal quis dizer ao intercalar.
  vState.artigosMarcados.forEach(id => fd.append('artigos[]', id))

  let nExig = 0, nArt = 0
  vState.relatorio.forEach((item, ordem) => {
    if (item.tipo === 'exigencia') {
      fd.append(`exigencias[${nExig}][texto]`, item.texto)
      if (item.prazo) { fd.append(`exigencias[${nExig}][prazo_dias]`, item.prazo) }
      nExig++
    } else if (item.tipo === 'citacao' || item.tipo === 'parecer') {
      fd.append(`itens_artigo[${nArt}][artigo_id]`, item.artigo_id)
      fd.append(`itens_artigo[${nArt}][tipo]`, item.tipo)
      fd.append(`itens_artigo[${nArt}][observacao]`, item.texto || '')
      fd.append(`itens_artigo[${nArt}][ordem]`, ordem)
      nArt++
    }
  })

  if (vState.gps) {
    fd.append('latitude', vState.gps.lat)
    fd.append('longitude', vState.gps.lon)
    fd.append('accuracy', vState.gps.prec)
  }
  // As fotos vão na ordem do relatório, e o índice enviado é o da REMESSA —
  // não o do array de anexos, que guarda buracos de itens removidos.
  let nFoto = 0
  vState.relatorio.forEach((item, ordem) => {
    if (item.tipo !== 'foto') { return }
    const anexo = vState.anexos[item.anexo]
    if (!anexo || anexo.removido) { return }

    fd.append('evidencias[]', anexo.arquivo)
    fd.append(`titulos[${nFoto}]`, anexo.titulo)
    fd.append(`descricoes[${nFoto}]`, item.texto ?? '')
    fd.append(`ordens[${nFoto}]`, ordem)
    if ((item.marcacoes || []).length) {
      fd.append(`marcacoes[${nFoto}]`, JSON.stringify(item.marcacoes))
    }
    if (item.fachada) { fd.append('fachada', nFoto) }
    nFoto++
  })

  try {
    const r = await fetch(`/api/lotes/${vState.lote.id}/vistorias`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: fd,   // sem Content-Type: o navegador põe o boundary do multipart
    })

    if (r.status === 419) { toast('Sessão expirada. Recarregando...', 'err'); setTimeout(() => location.reload(), 1500); return }
    const d = await r.json().catch(() => ({}))

    if (!r.ok) {
      // 422 traz os erros campo a campo; mostrar o primeiro é mais útil que
      // um "erro ao gravar" genérico.
      const primeiro = d.errors ? Object.values(d.errors)[0][0] : d.message
      throw new Error(primeiro || 'HTTP ' + r.status)
    }

    limparRascunho()
    zerarVistoria()
    // Zera o vinculo: sem isto ele vazaria para a proxima vistoria aberta
    // na mesma sessao, amarrando-a a um protocolo que ninguem escolheu.
    vState.protocoloId = null
    fModalBtn('m-vistoria')
    // A área volta no eco do servidor: é o número que a multa vai usar, e
    // confirmá-lo aqui evita descobrir semanas depois que ele ficou de fora.
    toast(d.vistoria?.area ? 'Vistoria registrada · ' + d.vistoria.area : 'Vistoria registrada')

    // Reabre a ficha já com o histórico atualizado — o fiscal confere o que
    // acabou de gravar sem ter que procurar o lote de novo.
    if (state.selecionado) abrirFicha(state.selecionado)
  } catch (e) {
    console.error(e)
    toast(e.message || 'Falha ao gravar a vistoria', 'err')
    throw e   // mantém o modal de confirmação aberto para nova tentativa
  } finally {
    vState.enviando = false
  }
}
