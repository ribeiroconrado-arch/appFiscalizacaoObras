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
  /** @type {number|null} índice da foto marcada como fachada */ fachada: null,
  /** @type {Array<{texto:string, prazo:number|null}>} */ exigencias: [],
  /** @type {Array<Object>} artigos sugeridos pelas irregularidades marcadas */ artigos: [],
  /** @type {Set<number>} artigos confirmados pelo fiscal */ artigosMarcados: new Set(),
  /** @type {{alvara:string, fase:string}} escolhas em botão */
  obra: { alvara: '', fase: '' },
  /** @type {{lat:number, lon:number, prec:number}|null} posição da vistoria */ gps: null,
  /** @type {number} passo visível, de 1 a 5 */ passo: 1,
  /** @type {number} maior passo já alcançado — é o que marca ✓ na barra */ visitados: 1,
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
  document.getElementById('nv-situacao').value = 'irregular'

  // A posição já capturada no mapa serve de ponto de partida; o botão do
  // passo 1 é o que a atualiza para o lugar onde o fiscal está agora.
  if (state.pos) { vState.gps = { ...state.pos } }
  pintarGps()

  irPasso(1)
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
  vState.fachada = null
  vState.exigencias = []
  vState.artigos = []
  vState.artigosMarcados = new Set()
  vState.obra = { alvara: '', fase: '' }
  vState.gps = null
  vState.passo = 1
  vState.visitados = 1

  const põe = (id, v) => { const e = document.getElementById(id); if (e) { e.value = v } }
  põe('nv-obs', ''); põe('nv-area', ''); põe('nv-area-metodo', '')
  põe('nv-acomp-nome', ''); põe('nv-acomp-qual', ''); põe('nv-alvara-numero', '')
  põe('nv-exig-texto', ''); põe('nv-exig-prazo', '')
  document.getElementById('nv-alvara-num-campo').hidden = true
  document.getElementById('nv-rascunho').hidden = true
  // O checklist é DOM que sobrevive ao fechamento do modal: sem desmarcá-lo,
  // a vistoria seguinte nasceria com as irregularidades da anterior.
  document.querySelectorAll('#nv-checklist input:checked').forEach(c => {
    c.checked = false
    c.closest('.chk-item')?.classList.remove('marcado')
  })
  pintarOpcoes(); renderExigencias(); renderAnexos()
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
  const alvo = vState.passo + d
  if (alvo < 1 || alvo > 5) { return }
  // Só barra ao AVANÇAR: voltar para conferir nunca pode ser impedido.
  if (d > 0 && !passoCompleto(vState.passo)) { return }
  irPasso(alvo)
}

/**
 * O que impede de avançar. Deliberadamente pouco: o formulário não pode virar
 * interrogatório, e o que de fato não pode faltar é conferido na gravação.
 * @param {number} n
 */
function passoCompleto(n) {
  if (n === 1 && !document.getElementById('nv-datahora').value) {
    toast('Informe data e hora da vistoria', 'err'); return false
  }
  if (n === 2) {
    const area = document.getElementById('nv-area').value
    if (area && !document.getElementById('nv-area-metodo').value) {
      toast('Diga como a área foi obtida', 'err'); return false
    }
  }
  if (n === 3 && document.getElementById('nv-situacao').value === 'irregular'
      && !document.querySelectorAll('#nv-checklist input:checked').length) {
    toast('Marque ao menos uma irregularidade', 'err'); return false
  }
  return true
}

/** @param {number} n passo de 1 a 5 */
function irPasso(n) {
  vState.passo = n
  vState.visitados = Math.max(vState.visitados, n)

  document.querySelectorAll('#nv-passos .vs-passo').forEach(b => {
    const i = Number(b.dataset.passo)
    b.classList.toggle('at', i === n)
    b.classList.toggle('feito', i < vState.visitados && i !== n)
    // Em tela estreita a barra rola, e os passos 4 e 5 ficam fora de vista: o
    // passo atual tem de se trazer para dentro, ou o fiscal perde a única
    // referência de onde está.
    if (i === n) { b.scrollIntoView({ block: 'nearest', inline: 'center' }) }
  })
  document.querySelectorAll('.vs-painel').forEach(p => {
    p.classList.toggle('at', p.id === 'nv-p' + n)
  })

  document.getElementById('nv-voltar').hidden = n === 1
  document.getElementById('nv-avancar').hidden = n === 5
  document.getElementById('nv-gravar').hidden = n !== 5
  const corpo = document.querySelector('.vs-corpo')
  if (corpo) { corpo.scrollTop = 0 }

  if (n === 3) { sugerirArtigos() }
  if (n === 5) { renderRevisao() }
  salvarRascunho()
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
  vState.visitados = 5
  irPasso(4)
  toast('Vistoria rápida: anexe a foto e grave')
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

function pintarOpcoes() {
  const marca = (id, valor) => document.querySelectorAll('#' + id + ' .vs-op')
    .forEach(b => b.classList.toggle('at', b.dataset.valor === valor))
  marca('nv-alvara', vState.obra.alvara)
  marca('nv-fase', vState.obra.fase)
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

// ── PASSO 3: EXIGÊNCIAS ──────────────────────────────────────

/**
 * Acrescenta uma providência à lista.
 *
 * É lista, e não parágrafo, porque é assim que ela é usada depois: a
 * notificação imprime item a item, cada prazo conta da ciência, e o retorno da
 * fiscalização confere um por um.
 */
function addExigencia() {
  const cTexto = document.getElementById('nv-exig-texto')
  const cPrazo = document.getElementById('nv-exig-prazo')
  const texto = cTexto.value.trim()
  if (!texto) { toast('Escreva a exigência', 'err'); cTexto.focus(); return }
  if (vState.exigencias.length >= 30) { toast('Limite de 30 exigências', 'err'); return }

  vState.exigencias.push({ texto, prazo: cPrazo.value ? Number(cPrazo.value) : null })
  cTexto.value = ''; cPrazo.value = ''
  cTexto.focus()   // a próxima costuma vir logo atrás
  renderExigencias(); salvarRascunho()
}

/** @param {number} i */
function removerExigencia(i) {
  vState.exigencias.splice(i, 1)
  renderExigencias(); salvarRascunho()
}

function renderExigencias() {
  const alvo = document.getElementById('nv-exigencias')
  if (!alvo) { return }
  if (!vState.exigencias.length) {
    alvo.innerHTML = '<div class="leg">Nenhuma exigência. Vistoria regular costuma não ter.</div>'
    return
  }
  alvo.innerHTML = vState.exigencias.map((e, i) => `
    <div class="vs-exig">
      <span class="num">${i + 1}</span>
      <span class="txt">${esc(e.texto)}
        ${e.prazo ? `<span class="prazo">Prazo de ${e.prazo} dias</span>` : ''}</span>
      <button type="button" class="btn danger sm" onclick="removerExigencia(${i})">Excluir</button>
    </div>`).join('')
}

/** Mantém o campo escondido com o valor combinado aaaa-mm-ddThh:mm. */
function syncDataHora() {
  const d = document.getElementById('nv-data').value
  const h = document.getElementById('nv-hora').value || '00:00'
  document.getElementById('nv-datahora').value = d ? `${d}T${h}` : ''
  atualizarDisplayData(document.getElementById('nv-data'))
}

// ── PASSO 4: FOTOS ───────────────────────────────────────────

/** Handler do input de arquivo. @param {HTMLInputElement} input */
function anexarArquivos(input) {
  for (const arquivo of input.files) {
    vState.anexos.push({
      arquivo,
      titulo: arquivo.name.replace(/\.[^.]+$/, '').slice(0, 160),
      descricao: '',
      url: arquivo.type.startsWith('image/') ? URL.createObjectURL(arquivo) : null,
    })
  }
  // Primeira foto de imagem vira a fachada por padrão: é quase sempre a que o
  // fiscal tira primeiro, e uma marca errada custa um toque para corrigir —
  // enquanto marca nenhuma faz a ficha voltar a mostrar foto qualquer.
  if (vState.fachada === null) {
    const i = vState.anexos.findIndex(a => a.url)
    if (i >= 0) { vState.fachada = i }
  }
  input.value = ''   // permite reanexar o mesmo arquivo depois de remover
  renderAnexos(); salvarRascunho()
}

function renderAnexos() {
  const alvo = document.getElementById('nv-anexos')
  if (!alvo) { return }
  if (!vState.anexos.length) {
    alvo.innerHTML = '<div class="vazio-msg">Nenhuma foto anexada.</div>'
    return
  }
  alvo.innerHTML = vState.anexos.map((a, i) => `
    <div class="anexo-item com-desc${vState.fachada === i ? ' e-fachada' : ''}">
      <div class="anexo-thumb">
        ${a.url ? `<img src="${a.url}" alt="">` : '<div class="pdf">PDF</div>'}
      </div>
      <div class="anexo-info">
        <input class="t" style="width:100%;border:none;background:none;font-family:inherit"
               value="${esc(a.titulo)}" maxlength="160"
               oninput="vState.anexos[${i}].titulo = this.value"
               aria-label="Título da evidência">
        <input class="d" value="${esc(a.descricao)}" maxlength="1000"
               placeholder="Descreva o que a foto mostra"
               oninput="vState.anexos[${i}].descricao = this.value"
               aria-label="Descrição da evidência">
        <div class="s">${(a.arquivo.size / 1024 / 1024).toFixed(1)} MB</div>
        ${a.url ? `<label class="anexo-fach">
          <input type="checkbox" ${vState.fachada === i ? 'checked' : ''}
                 onchange="marcarFachada(${i}, this.checked)">Fachada</label>` : ''}
      </div>
      <button type="button" class="btn danger sm" onclick="removerAnexo(${i})">Excluir</button>
    </div>`).join('')
}

/**
 * Uma fachada por vistoria: ela responde "como está o imóvel hoje", e duas
 * respostas não respondem nada. É a foto que a ficha do imóvel passa a mostrar.
 *
 * @param {number} i @param {boolean} marcado
 */
function marcarFachada(i, marcado) {
  vState.fachada = marcado ? i : null
  renderAnexos(); salvarRascunho()
}

/** Exclusão SEMPRE pergunta antes — regra sem exceção. @param {number} i */
function removerAnexo(i) {
  const a = vState.anexos[i]
  confirmarAcao({
    titulo: 'Remover evidência',
    mensagem: `Remover "${a.titulo}" desta vistoria?`,
    textoBtn: 'Remover',
    perigo: true,
    onConfirm: () => {
      if (a.url) URL.revokeObjectURL(a.url)
      vState.anexos.splice(i, 1)
      // O índice da fachada é posicional: removida uma foto anterior a ela, a
      // marca escorregaria para a foto errada em silêncio.
      if (vState.fachada === i) { vState.fachada = null }
      else if (vState.fachada !== null && vState.fachada > i) { vState.fachada-- }
      renderAnexos(); salvarRascunho()
    },
  })
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

  const linhas = [
    ['Imóvel', esc(document.getElementById('nv-lote').textContent)],
    ['Data e hora', esc((document.getElementById('nv-datahora').value || '').replace('T', ' às '))],
    ['Situação', esc(sit.options[sit.selectedIndex].text)],
    ['Coordenada', vState.gps
      ? `${vState.gps.lat.toFixed(6)}, ${vState.gps.lon.toFixed(6)}`
      : falta('não capturada')],
    ['Acompanhante', acomp
      ? esc(acomp) + (qual.value ? ' — ' + esc(qual.options[qual.selectedIndex].text) : '')
      : falta('ninguém identificado')],
    ['Alvará', vState.obra.alvara
      ? esc(rotOp('nv-alvara', vState.obra.alvara))
        + (vState.obra.alvara === 'possui' && document.getElementById('nv-alvara-numero').value
           ? ' nº ' + esc(document.getElementById('nv-alvara-numero').value) : '')
      : falta('não informado')],
    // A área é a linha que mais importa nesta tela: sem ela, multa por metro
    // quadrado sai como "não calculada" — ver Artigo::calcularMulta().
    ['Área aferida', area
      ? esc(area) + ' m²' + (metodo.value ? ' (' + esc(metodo.options[metodo.selectedIndex].text.toLowerCase()) + ')' : '')
      : falta('não medida — multa por m² não será calculada')],
    ['Fase da obra', vState.obra.fase ? esc(rotOp('nv-fase', vState.obra.fase)) : falta('não informada')],
    ['Irregularidades', marcadas.length
      ? '<ol>' + marcadas.map(c =>
          '<li>' + esc(c.closest('.chk-item').querySelector('.desc').firstChild.textContent.trim()) + '</li>').join('') + '</ol>'
      : falta('nenhuma')],
    ['Artigos citados', vState.artigosMarcados.size
      ? esc(vState.artigos.filter(a => vState.artigosMarcados.has(a.id))
            .map(a => a.numero).join(', '))
      : falta('nenhum')],
    ['Exigências', vState.exigencias.length
      ? '<ol>' + vState.exigencias.map(e =>
          '<li>' + esc(e.texto) + (e.prazo ? ` <b>— ${e.prazo} dias</b>` : '') + '</li>').join('') + '</ol>'
      : falta('nenhuma')],
    ['Fotos', vState.anexos.length
      ? `${vState.anexos.length} anexada(s)`
        + (vState.fachada !== null ? ', uma marcada como fachada' : ', ' + falta('sem fachada marcada'))
        + (vState.anexos.some(a => !a.descricao.trim()) ? '<br>' + falta('há foto sem descrição') : '')
      : falta('nenhuma')],
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
    || vState.exigencias.length || vState.anexos.length
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
        alvaraNumero: v('nv-alvara-numero'),
      },
      obra: vState.obra,
      gps: vState.gps,
      exigencias: vState.exigencias,
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
  põe('nv-alvara-numero', c.alvaraNumero)
  syncDataHora()

  vState.obra = d.obra ?? { alvara: '', fase: '' }
  vState.gps = d.gps ?? vState.gps
  vState.exigencias = d.exigencias ?? []
  vState.artigosMarcados = new Set(d.artigos ?? [])
  vState.artigosDoRascunho = vState.artigosMarcados.size > 0
  // null, e não lista vazia: é ele que destranca sugerirArtigos() acima.
  vState.rascunhoIrreg = d.irregularidades?.length ? d.irregularidades : null
  document.getElementById('nv-alvara-num-campo').hidden = vState.obra.alvara !== 'possui'

  pintarOpcoes(); pintarGps(); renderExigencias()
  // O aviso é honesto sobre o que NÃO voltou: foto não cabe no armazenamento
  // do navegador, e deixar isso implícito faria o fiscal gravar sem as fotos.
  const av = document.getElementById('nv-rascunho')
  av.hidden = false
  av.textContent = vState.anexos.length ? 'Rascunho recuperado' : 'Rascunho recuperado — refaça as fotos'
  irPasso(d.passo ?? 1)
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
    irPasso(1); toast('Informe data e hora da vistoria', 'err'); return
  }
  if (situacao === 'irregular' && !marcadas.length) {
    irPasso(3); toast('Marque ao menos uma irregularidade', 'err'); return
  }
  // A mesma regra do servidor, dita antes de o fiscal perder o envio: área sem
  // método é número que não se sustenta em defesa.
  if (document.getElementById('nv-area').value && !document.getElementById('nv-area-metodo').value) {
    irPasso(2); toast('Diga como a área foi obtida', 'err'); return
  }

  const resumo = marcadas.length
    ? `${marcadas.length} irregularidade${marcadas.length > 1 ? 's' : ''}`
    : 'sem irregularidades'

  confirmarAcao({
    titulo: 'Gravar vistoria',
    mensagem: `Registrar vistoria do lote ${vState.lote.numero_lote}, quadra `
            + `${vState.lote.quadra}, com ${resumo} e ${vState.anexos.length} evidência(s)?`,
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

  // ── o enquadramento e as providências ──
  vState.artigosMarcados.forEach(id => fd.append('artigos[]', id))
  vState.exigencias.forEach((e, i) => {
    fd.append(`exigencias[${i}][texto]`, e.texto)
    if (e.prazo) { fd.append(`exigencias[${i}][prazo_dias]`, e.prazo) }
  })

  if (vState.gps) {
    fd.append('latitude', vState.gps.lat)
    fd.append('longitude', vState.gps.lon)
    fd.append('accuracy', vState.gps.prec)
  }
  vState.anexos.forEach((a, i) => {
    fd.append('evidencias[]', a.arquivo)
    fd.append(`titulos[${i}]`, a.titulo)
    fd.append(`descricoes[${i}]`, a.descricao ?? '')
  })
  if (vState.fachada !== null) { fd.append('fachada', vState.fachada) }

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
