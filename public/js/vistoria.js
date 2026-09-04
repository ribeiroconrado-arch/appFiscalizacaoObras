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
  /**
   * A foto ESCOLHIDA E AINDA NÃO ANEXADA — a que está na ficha da aba Fotos,
   * esperando legenda, fachada e marcas. Ela só entra no item no "+ add".
   *
   * `editando` guarda o índice quando a ficha foi aberta para CORRIGIR uma
   * foto que já está no item: é o mesmo formulário, e por isso o mesmo estado.
   *
   * @type {{arquivo:File|null, url:string|null, titulo:string,
   *         marcacoes:Array<{n:number,x:number,y:number}>,
   *         quando:string|null, lat:number|null, lon:number|null,
   *         editando:number|null}|null}
   */
  fotoPendente: null,
  /** @type {Array<File>} escolhidas de uma vez, atendidas uma a uma */ filaFotos: [],
  /** @type {Array<Object>} artigos sugeridos pelas irregularidades marcadas */ artigos: [],
  /** @type {Array<string>} irregularidades que nenhum artigo enquadra */ semArtigo: [],
  /** @type {string} para que serve esta vistoria — decide os passos */
  finalidade: 'obras',
  /** @type {{alvara:string, fase:string, projeto:string, uso:string}} escolhas em botão */
  obra: { alvara: '', fase: '', projeto: '', uso: '' },
  /** @type {{lat:number, lon:number, prec:number}|null} posição da vistoria */ gps: null,
  /** @type {string} chave do passo visível */ passo: 'id',
  /** @type {number} índice do passo mais avançado — é o que marca ✓ na barra */ visitados: 0,
  /** @type {boolean} a tela está sendo montada — não gravar rascunho ainda */ abrindo: false,
  /** @type {Object|null} rascunho oferecido e ainda não aceito nem recusado */ rascunhoPendente: null,
  /** @type {Object|null} a vistoria aberta na janela de leitura */ lendo: null,
  /** @type {Array<Object>} as peças oferecidas depois de gravar */ opcoesAto: [],
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

/**
 * Que janela cada marco abre.
 *
 * As três já existiam para documento e protocolo; a da vistoria nasceu com
 * isto — ver `verVistoria`. O nome da função vai para o HTML, então o mapa é
 * também a lista do que é clicável: tipo sem entrada aqui fica sem clique, em
 * vez de abrir um erro no console.
 */
const ABRE_EVENTO = {
  vistoria:  'verVistoria',
  documento: 'abrirDocumento',
  protocolo: 'abrirProtocolo',
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
    // CADA MARCO LEVA AO ATO QUE O PRODUZIU.
    //
    // A linha do tempo dizia o que aconteceu e parava aí: para ver o auto
    // citado era preciso fechar a ficha, ir à aba Documentos e procurar o
    // número na lista — três passos para chegar a algo que já estava na tela.
    const abre = ABRE_EVENTO[e.tipo]
    const clique = (abre && e.id)
      ? ` onclick="${abre}(${e.id})" role="button" tabindex="0"`
      : ''

    return `
      <div class="lt-item lt-${esc(e.tipo)}${clique ? ' lt-abre' : ''}"${clique}>
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
    <!-- O marco inteiro abre a vistoria; este botão faz outra coisa, e o
         clique não pode subir até ele. -->
    <button class="btn sm primary" onclick="event.stopPropagation();iniciarAtoCadastral(${a.protocolo.id}, '${esc(a.tipo)}', ${e.lote_id ?? 'null'})">
      ${rotulo}</button></div>`
}

// ── FORMULÁRIO ───────────────────────────────────────────────

/** Abre o formulário de nova vistoria para o lote selecionado. */
/**
 * Oferece a área construída que está DESENHADA no lote.
 *
 * As duas medidas convivem de propósito: o desenho é o que o cadastro sabe, e
 * a aferição é o que o fiscal mediu hoje. Quando divergem, a divergência é o
 * assunto da vistoria — e é por isso que o número desenhado nunca entra
 * sozinho no campo que vai virar multa.
 *
 * @param {number} loteId
 */
async function oferecerAreaDesenhada(loteId) {
  const alvo = document.getElementById('nv-area-desenhada')
  if (!alvo) { return }
  alvo.hidden = true
  alvo.innerHTML = ''
  if (!loteId || typeof carregarEdificacoes !== 'function') { return }

  // Sem pintar: a vistoria é um modal por cima do mapa, e desenhar as
  // edificações embaixo dele seria trabalho que ninguém vê.
  const d = await carregarEdificacoes(loteId, false)
  if (!d || !d.area_construida_m2) { return }

  const n = d.edificacoes.length
  alvo.hidden = false
  alvo.innerHTML = `Desenhado no cadastro: <b>${fmtNum(d.area_construida_m2)} m²</b>`
    + ` em ${n} construção(ões). `
    + `<button type="button" class="btn sm" onclick="usarAreaDesenhada(${d.area_construida_m2})">`
    + 'Usar este valor</button>'
}

/** @param {number} area */
function usarAreaDesenhada(area) {
  document.getElementById('nv-area').value = area
  // O método vai junto, e diz de onde o número veio: perito que contesta multa
  // por metro quadrado contesta a medição, e "do desenho do cadastro" precisa
  // aparecer como o que é.
  // "croqui" é exatamente isto: área calculada pelo desenho, e não medida em
  // campo (ver Vistoria::METODOS_AREA). Escolhido pelo VALOR e não pelo texto
  // da opção — rótulo é o que muda quando alguém reescreve a tela.
  const metodo = document.getElementById('nv-area-metodo')
  if (metodo && !metodo.value && [...metodo.options].some(o => o.value === 'croqui')) {
    metodo.value = 'croqui'
  }
  toast('Área do desenho copiada. Confira o método de obtenção.', 'aviso')
}
/**
 * Abre a vistoria.
 *
 * SEM LOTE SELECIONADO ELA ABRE ASSIM MESMO, com o localizador de imóvel no
 * passo 1. As outras quatro peças do menu "Novo documento" já nasciam sem
 * imóvel; a vistoria era a única que recusava, e quem escolhia por engano
 * tinha de fechar o menu, achar o lote no mapa e recomeçar. O imóvel continua
 * obrigatório para GRAVAR — é vistoria de alguma coisa —, mas isso se cobra
 * na hora de gravar, não na de abrir.
 */
async function novaVistoria() {
  const f = state.selecionado

  vState.lote = f ? f.properties : null
  // Trava a gravação do rascunho durante a montagem. Sem isto, o primeiro
  // irPasso() da abertura salvava a tela ainda VAZIA por cima do rascunho que
  // ele estava justamente indo buscar — o trabalho de campo era apagado pelo
  // ato de voltar para ele.
  vState.abrindo = true
  zerarVistoria()
  fModalBtn('m-ficha')

  pintarImovelDaVistoria()

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
  oferecerRascunho()
  vState.abrindo = false
  // O catálogo não depende do imóvel e é o que a janela precisa para trabalhar.
  await carregarCatalogo()
  openModal('m-vistoria')

  // Só o que depende do imóvel, e só quando há um.
  if (vState.lote) { await carregarDadosDoImovel() }
}

/**
 * O que a tela busca DEPOIS de saber qual é o imóvel — área desenhada e
 * protocolos cadastrais pendentes.
 *
 * Separado da abertura porque o imóvel pode chegar depois, pelo localizador do
 * passo 1. Sem `await` na área: é conveniência, e a vistoria não pode esperar
 * por ela — o fiscal está em campo, muitas vezes com rede ruim.
 */
async function carregarDadosDoImovel() {
  const id = vState.lote?.id
  if (!id) { return }
  oferecerAreaDesenhada(id)
  await carregarProtocolosCadastrais(id)
}

/**
 * Mostra o imóvel no cabeçalho — ou abre o localizador, quando não há um.
 */
function pintarImovelDaVistoria() {
  const l = vState.lote
  const escolha = document.getElementById('nv-imovel-escolha')

  document.getElementById('nv-lote').textContent = l
    ? `${bairroDe(l)} · Quadra ${l.quadra ?? '—'} · Lote ${l.numero_lote ?? '—'}`
    : 'Imóvel não identificado'

  if (escolha) { escolha.hidden = !!l }
}

/** Busca o imóvel pela inscrição ou por "quadra lote" — a mesma rota do documento. */
async function procurarImovelVistoria() {
  const termo = document.getElementById('nv-imovel-termo').value.trim()
  const saida = document.getElementById('nv-imovel-resultado')
  if (!termo) { saida.textContent = 'Digite a inscrição imobiliária ou “quadra lote”.'; return }

  saida.textContent = 'Procurando…'
  try {
    const r = await fetch('/api/imoveis/busca?' + new URLSearchParams({ termo }),
      { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) { throw new Error(d.message || 'HTTP ' + r.status) }
    if (!d.imoveis.length) { saida.textContent = 'Nenhum imóvel encontrado.'; return }

    // Guardados para o clique: é daqui que sai o imóvel, sem nova consulta.
    vState.imoveisBuscados = d.imoveis

    saida.innerHTML = d.imoveis.slice(0, 12).map(i => `
      <button type="button" class="doc-imovel-op" onclick="vincularImovelVistoria(${i.id})">
        <b>${esc(i.inscricao || 'sem inscrição')}</b>
        ${esc(i.bairro || '')} · Q ${esc(i.quadra ?? '—')} · Lt ${esc(i.lote ?? '—')}
      </button>`).join('')
      + (d.total > 12 ? '<div class="leg">' + d.total + ' acertos — refine o termo.</div>' : '')
  } catch (e) {
    console.error(e)
    saida.textContent = e.message || 'Falha na busca.'
  }
}

/**
 * Amarra a vistoria ao imóvel escolhido na busca.
 *
 * Sem segunda consulta: o resumo da busca já traz tudo que o formulário lê
 * daqui — o id (que vai na URL de gravação), o bairro e a quadra/lote (que
 * aparecem no cabeçalho e na confirmação). O resto a vistoria não usa.
 *
 * @param {number} id
 */
async function vincularImovelVistoria(id) {
  const i = (vState.imoveisBuscados || []).find(x => x.id === id)
  if (!i) { return }

  vState.lote = { id, bairro: i.bairro, quadra: i.quadra, numero_lote: i.lote }

  document.getElementById('nv-imovel-resultado').innerHTML = ''
  document.getElementById('nv-imovel-termo').value = ''
  pintarImovelDaVistoria()
  await carregarDadosDoImovel()
}

/** Devolve o formulário ao estado de folha em branco. */
function zerarVistoria() {
  vState.anexos.forEach(a => a.url && URL.revokeObjectURL(a.url))
  vState.anexos = []
  vState.relatorio = []
  vState.itemAberto = null
  vState.imoveisBuscados = []
  // A busca de imóvel não pode sobreviver à vistoria anterior: os acertos da
  // outra ficariam à vista, prontos para serem clicados por engano.
  const termo = document.getElementById('nv-imovel-termo')
  if (termo) { termo.value = '' }
  const achados = document.getElementById('nv-imovel-resultado')
  if (achados) { achados.innerHTML = '' }
  vState.artigos = []
  vState.semArtigo = []
  vState.finalidade = 'obras'
  vState.obra = { alvara: '', fase: '', projeto: '', uso: '' }
  vState.gps = null
  vState.passo = 'id'
  vState.visitados = 0
  vState.rascunhoPendente = null

  const põe = (id, v) => { const e = document.getElementById(id); if (e) { e.value = v } }
  põe('nv-area', ''); põe('nv-area-metodo', '')
  põe('nv-acomp-nome', ''); põe('nv-acomp-qual', ''); põe('nv-alvara-numero', '')
  põe('nv-ano', '')
  põe('nv-exig-texto', ''); põe('nv-exig-prazo', '')
  document.getElementById('nv-alvara-num-campo').hidden = true
  document.getElementById('nv-rascunho').hidden = true
  // As irregularidades vivem DENTRO dos itens agora: zerar `relatorio` já as
  // leva junto, e não sobra checklist de tela para desmarcar à mão.
  pintarOpcoes(); pintarFinalidade(); renderRelatorio()
}

/**
 * Fecha a vistoria. NÃO grava rascunho por conta.
 *
 * Guardava sozinho ao sair, e o efeito era o oposto do prometido: o fiscal
 * fechava a tela achando que descartava, e a visita seguinte no mesmo imóvel
 * nascia com o texto da anterior. Gravar é decisão de quem escreve — o botão
 * "Salvar rascunho" está no rodapé, ao lado de onde se sai.
 *
 * O aviso é o mesmo do formulário de documento: sair com trabalho na tela
 * pergunta antes, e diz onde está o botão que guarda.
 */
function fecharVistoria() {
  if (temConteudo()) {
    confirmarAcao({
      titulo: 'Sair da vistoria',
      mensagem: 'O que está na tela não foi gravado e será perdido. '
        + 'Para guardar e continuar depois, use "Salvar rascunho" antes de sair.',
      textoBtn: 'Sair sem gravar',
      perigo: true,
      onConfirm: () => sairDaVistoria(),
    })
    return
  }
  sairDaVistoria()
}

/** O fechamento em si, depois de resolvido o que fazer com o que está na tela. */
function sairDaVistoria() {
  fModalBtn('m-vistoria')
  // Quem abriu a vistoria de dentro da ficha volta para o imóvel — e não para
  // o mapa, onde teria de procurar o lote de novo.
  voltarAFicha()
}

// ── VISTORIA GRAVADA: LEITURA ────────────────────────────────

/**
 * Abre uma vistoria já gravada, só para ler.
 *
 * A ficha do imóvel volta depois de fechar — é de lá que se chega aqui, e
 * devolver o fiscal ao mapa o obrigaria a procurar o lote de novo.
 *
 * @param {number} id
 */
async function verVistoria(id) {
  try {
    const r = await fetch('/api/vistorias/' + id, { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const { vistoria: v } = await r.json()

    // Guardada para o rodapé: gerar documento e imprimir agem sobre ela.
    vState.lendo = v

    document.getElementById('vv-numero').textContent = v.numero ?? '—'
    document.getElementById('vv-finalidade').textContent = v.finalidade
    document.getElementById('vv-quando').textContent = v.quando ?? '—'
    document.getElementById('vv-fiscal').textContent = v.fiscal ?? '—'
    document.getElementById('vv-imovel').textContent = v.imovel ?? '—'
    const sit = document.getElementById('vv-situacao')
    sit.className = 'badge ' + (v.situacao.classe ?? 'bd-in')
    sit.textContent = v.situacao.texto

    document.getElementById('vv-corpo').innerHTML = corpoDaVistoria(v)
    // A ficha fica ABERTA por baixo: fechar esta janela devolve o imóvel sem
    // uma segunda ida ao servidor.
    openModal('m-vistoria-ver')
  } catch (e) {
    console.error(e)
    toast('Não foi possível abrir a vistoria', 'err')
  }
}

function fecharVistoriaVer() { fModalBtn('m-vistoria-ver') }

/** Abre o relatório em A4 na janela de impressão. @see VistoriaImpressao */
function imprimirVistoria() {
  if (!vState.lendo) { return }
  // Aba nova, e não fetch: é uma página que se manda para a impressora.
  window.open('/vistorias/' + vState.lendo.id + '/impressao', '_blank')
}

/** O botão do rodapé da janela de leitura. @param {Event} ev */
function documentoDaVistoria(ev) {
  if (!vState.lendo) { return }
  menuDocumentoDaVistoria(ev, vState.lendo)
}

/**
 * AS PEÇAS QUE PODEM NASCER DESTA VISTORIA.
 *
 * Sempre as mesmas do botão "Novo documento" — mesmos ícones, mesmas
 * descrições, mesma ordem (os que AVISAM acima, os que SANCIONAM abaixo).
 * Montar a lista num lugar só é o que impede as duas telas de divergirem no
 * dia em que um tipo novo entrar.
 *
 * @param {Object} v a vistoria de origem
 * @returns {Promise<Array<Object>>}
 */
async function opcoesDeAto(v) {
  const o = await carregarOpcoes()

  return o.tipos.filter(t => t.valor !== 'vistoria').map(t => ({
    valor: t.valor,
    rotulo: t.rotulo,
    obs: OBS_TIPO_DOC[t.valor] || '',
    icone: ICO_TIPO_DOC[t.valor] || ICO_TIPO_DOC.padrao,
    separar: t.valor === 'auto_embargo',
    acao: () => {
      fModalBtn('m-vist-ato')
      fModalBtn('m-vistoria-ver')
      // A PEÇA NASCE PRESA A ESTA VISTORIA — e não à última do imóvel, que é
      // o que o formulário adivinha quando ninguém diz de onde veio.
      return abrirFormDoc({
        lote: state.selecionado?.properties || null,
        tipoInicial: t.valor,
        vistoria: v.id,
      })
    },
  }))
}

/**
 * A pergunta que fecha o ciclo, logo depois de gravar uma vistoria irregular.
 *
 * Janela própria, e não menu suspenso: a tela da vistoria acabou de fechar e
 * não há botão para ancorar um menu. Também não é a caixa de confirmação
 * genérica, que tem uma ação só — aqui são quatro peças diferentes, e escolher
 * qual É a decisão.
 *
 * @param {Object} v a vistoria recém-gravada (id, numero)
 */
async function oferecerDocumentoDaVistoria(v) {
  const alvo = document.getElementById('vato-lista')
  if (!alvo) { return }

  document.getElementById('vato-titulo').textContent = (v.numero || 'Vistoria') + ' registrada'

  const opcoes = await opcoesDeAto(v)
  vState.opcoesAto = opcoes

  alvo.innerHTML = opcoes.map((o, i) => `
    <button type="button" class="vato-op${o.separar ? ' vato-sep' : ''}" onclick="escolherAto(${i})">
      <span class="vato-ico">${o.icone}</span>
      <span class="vato-txt">
        <span class="vato-nome">${esc(o.rotulo)}</span>
        ${o.obs ? `<span class="vato-obs">${esc(o.obs)}</span>` : ''}
      </span>
    </button>`).join('')

  openModal('m-vist-ato')
}

/** @param {number} i o índice da peça escolhida na janela da oferta */
function escolherAto(i) {
  vState.opcoesAto?.[i]?.acao()
}

/**
 * O mesmo caminho, a partir da janela da vistoria gravada — aqui há botão para
 * ancorar, então é o menu suspenso de sempre.
 *
 * @param {Event|Element} origem @param {Object} v
 */
async function menuDocumentoDaVistoria(origem, v) {
  abrirMenuNovo(origem, await opcoesDeAto(v))
}

/**
 * O RELATÓRIO DA VISTORIA na tela — o mesmo conteúdo do papel.
 *
 * O VAZIO É DITO, e não escondido. Antes só apareciam as seções preenchidas, e
 * uma vistoria regular abria quase em branco, parecendo falha de carregamento.
 * Pior: num processo, "não informado" e "não perguntado" são coisas
 * diferentes — só entram os campos que ESTA finalidade pergunta, e os que
 * ficaram em branco dizem a consequência (a área é o caso que importa: sem
 * ela, a multa por metro quadrado não é calculada).
 *
 * @param {Object} v @returns {string}
 */
function corpoDaVistoria(v) {
  const secao = (titulo, conteudo) =>
    conteudo ? `<div class="vv-secao"><h4>${esc(titulo)}</h4>${conteudo}</div>` : ''

  // Par com valor OU com a frase do vazio — em itálico e cinza, para sair do
  // peso do dado apurado sem sair da página.
  const pares = linhas => linhas.length
    ? `<div class="vv-pares">${linhas.map(([rot, val, falta]) =>
        `<div><span class="vv-rot">${esc(rot)}</span>` +
        (val ? `<span>${esc(val)}</span>` : `<span class="vv-vazio">${esc(falta)}</span>`) +
        '</div>').join('')}</div>`
    : ''

  // ── 1 · o que foi constatado ──
  const acomp = v.acompanhante
    ? v.acompanhante.nome + (v.acompanhante.qual ? ` — ${v.acompanhante.qual}` : '')
    : null

  const constatado = pares([
    ['Situação', v.situacao.texto, 'não informada'],
    ['Acompanhante', acomp, 'ninguém identificado no local'],
    ['Coordenada', v.gps ? `${v.gps.lat.toFixed(6)}, ${v.gps.lon.toFixed(6)}` : null, 'não capturada'],
    // `obra` já vem do servidor filtrado pela finalidade, com null no que
    // ficou em branco — a tela não repete essa regra, só a exibe.
    ...Object.entries(v.obra ?? {}).map(([rot, val]) => [rot, val, FALTA_VISTORIA[rot] ?? 'não informado']),
  ])

  // ── O RELATÓRIO EM GRUPOS ──
  //
  // Cada item é um bloco de raciocínio, e dentro dele a ordem é fixa:
  // irregularidades, texto, artigos, exigências, fotos — o fato, a narrativa, a
  // lei, a providência e a prova. Só a ordem ENTRE itens foi escolhida, e é a
  // sequência em que a obra foi percorrida.
  const relatorio = v.relatorio.length
    ? v.relatorio.map((it, n) => {
        const partes = []

        if (it.irregularidades.length) {
          partes.push(`<ul class="vv-lista">${it.irregularidades.map(i =>
            `<li><b>${esc(i.codigo)}</b> ${esc(i.descricao)}
             <span class="vv-grav vv-${esc(i.gravidade)}">${esc(i.gravidade)}</span></li>`).join('')}</ul>`)
        }

        if (it.texto) { partes.push(`<p class="vv-obs">${esc(it.texto)}</p>`) }

        it.artigos.forEach(a => partes.push(`
          <div class="vv-artigo">
            <span class="vv-artigo-tipo">${a.tipo === 'parecer' ? 'Parecer do fiscal' : 'Dispositivo citado'}</span>
            <b>${esc(a.numero ?? '—')}</b>
            ${a.texto ? `<p>${esc(a.texto)}</p>` : ''}
          </div>`))

        if (it.exigencias.length) {
          partes.push(`<ul class="vv-lista">${it.exigencias.map(e =>
            `<li>${esc(e.texto)}${e.prazo ? ` <span class="vv-prazo">prazo de ${e.prazo} dias</span>` : ''}</li>`)
            .join('')}</ul>`)
        }

        it.fotos.forEach(f => partes.push(itemAnexo(f)))

        return `<div class="vv-item">
          <div class="vv-item-num">Item ${n + 1}</div>
          ${partes.join('')}
        </div>`
      }).join('')
    : '<div class="vv-vazio">nada registrado no relatório</div>'

  // ── 6 · o que a constatação virou ──
  const docs = (v.documentos ?? []).length
    ? `<div class="vv-docs">${v.documentos.map(d => `
        <button type="button" class="vv-doc" onclick="abrirDocumento(${d.id})">
          <span class="proto-badge">${esc(d.numero)}</span>
          <span class="vv-doc-tipo">${esc(d.tipo)}</span>
          <span class="vv-doc-meta">${esc(d.data ?? '')} · ${esc(d.status)}</span>
        </button>`).join('')}</div>`
    : `<div class="vv-vazio">nenhum documento emitido a partir desta vistoria${
        v.situacao.valor === 'irregular'
          ? ' — a constatação é de irregularidade e o ato ainda não foi lavrado' : ''}</div>`

  const obs = v.observacoes ? `<p class="vv-obs">${esc(v.observacoes)}</p>` : ''

  return secao('O que foi constatado', constatado)
    + secao('Relatório', relatorio)
    + secao('Observações', obs)
    + secao('Documentos emitidos', docs)
}

/** A frase do vazio de cada campo de obra — a mesma da aba Revisão. */
const FALTA_VISTORIA = {
  'Alvará': 'não verificado',
  'Área aferida': 'não medida — multa por m² não será calculada',
  'Fase da obra': 'não informada',
  'Conforme o projeto': 'não verificado',
  'Uso constatado': 'não informado',
  'Época da construção': 'não estimada',
}

/**
 * O anexo do relatório: foto se dá para exibir, cartão de arquivo se não dá.
 *
 * O sistema aceita PDF de propósito — laudo, projeto, alvará —, e a tela
 * mandava todos para dentro de um `<img>`: o PDF virava um retângulo quebrado
 * com o título ao lado. Quem decide é `imagem`, que vem do mime real do
 * arquivo, e não o tipo do item de relatório (onde "foto" quer dizer "anexo").
 *
 * @param {Object} i @returns {string}
 */
function itemAnexo(i) {
  if (i.imagem) {
    return `
      <figure class="vv-foto">
        <img src="${esc(i.url ?? '')}" alt="${esc(i.titulo || 'Foto da vistoria')}" loading="lazy">
        ${i.texto ? `<figcaption>${esc(i.texto)}</figcaption>` : ''}
      </figure>`
  }

  return `
    <a class="vv-arquivo" href="${esc(i.url ?? '#')}" target="_blank" rel="noopener">
      <span class="vv-arquivo-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
      </span>
      <span class="vv-arquivo-txt">
        <b>${esc(i.titulo || i.arquivo_nome || 'Documento anexado')}</b>
        ${i.texto ? `<span class="vv-arquivo-obs">${esc(i.texto)}</span>` : ''}
        <span class="vv-arquivo-abrir">abrir em outra aba</span>
      </span>
    </a>`
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
      && !irregularidadesDaVistoria().length) {
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
  pintarOpcoes()
}

/** @param {string} v */
function escolherFase(v) {
  vState.obra.fase = vState.obra.fase === v ? '' : v
  pintarOpcoes()
}

/** @param {string} v */
function escolherProjeto(v) {
  vState.obra.projeto = vState.obra.projeto === v ? '' : v
  pintarOpcoes()
}

/** @param {string} v */
function escolherUso(v) {
  vState.obra.uso = vState.obra.uso === v ? '' : v
  pintarOpcoes()
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

/**
 * O catálogo de irregularidades vive DENTRO da janela do item.
 *
 * Era uma lista única da vistoria, num passo à parte. Com o item virando grupo,
 * a irregularidade passou a pertencer ao ponto da obra onde foi constatada —
 * então esta função só repinta a janela aberta, se houver uma.
 */
function renderChecklist() {
  if (vState.itemAberto !== null) { buscarIrregularidade(document.getElementById('vsi-irreg-busca')?.value ?? '') }

  // O catálogo só chega do servidor DEPOIS de o rascunho ser lido, e por isso
  // as marcas são reaplicadas aqui — não em restaurarRascunho, onde ainda não
  // existiam caixas para marcar.
  // O CHECKLIST DEIXOU DE SER DOM DA TELA, e com isso caiu todo o mecanismo
  // do `rascunhoIrreg`: ele existia porque as marcas do rascunho chegavam
  // antes das caixas e precisavam esperar o catálogo. Agora a irregularidade
  // é DADO dentro do item — voltar do rascunho é devolver a lista, e a janela
  // do item pinta o que estiver nela.
  sugerirArtigos()
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
  // O bloco `#nv-artigos` saiu da tela: quem mostra os artigos agora é o
  // seletor da janela do item, e a sugestão só alimenta a lista dele.


  // A SOMA DOS ITENS, e não um checklist de tela: cada irregularidade pertence
  // a um item, e o enquadramento é da vistoria inteira.
  const ids = irregularidadesDaVistoria()
  if (!ids.length) {
    vState.artigos = []
    if (vState.itemAberto !== null) { buscarArtigo(document.getElementById('vsi-artigo-busca')?.value ?? '') }
    return
  }

  try {
    const r = await fetch('/api/artigos-sugeridos?irregularidades=' + ids.join(','),
      { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const d = await r.json()

    // A sugestão OFERECE, e não escolhe: os artigos entram no seletor da
    // janela do item, e é o fiscal quem cita o que couber. Antes ela marcava
    // sozinha uma lista paralela, que podia discordar do que ele escreveu.
    vState.artigos = d.artigos ?? []
    renderArtigos(d.sem_artigo ?? [])
  } catch (e) {
    console.error(e)
    toast('Não foi possível buscar os artigos agora. A vistoria grava assim mesmo.', 'aviso')
  }
}

/**
 * O que a sugestão devolveu, guardado para o seletor da janela do item.
 *
 * A LISTA DE MARCAR ARTIGOS SAIU. Ela existia quando o relatório era plano: o
 * fiscal marcava os dispositivos da vistoria num lugar e escrevia sobre eles em
 * outro, e as duas listas podiam discordar. Agora o artigo é citado DENTRO do
 * item, com o texto ao lado — e os artigos da vistoria são a soma do que os
 * itens citaram. Uma verdade só.
 *
 * @param {Array<string>} semArtigo irregularidades que nenhum artigo enquadra
 */
function renderArtigos(semArtigo) {
  // Dizer o que NÃO está fundamentado é o ponto: em silêncio, o fiscal veria
  // três artigos sugeridos e concluiria que as cinco marcações estão cobertas.
  vState.semArtigo = semArtigo ?? []

  if (vState.semArtigo.length) {
    toast('Sem artigo cadastrado para: ' + vState.semArtigo.join('; ')
      + '. A vistoria grava, mas a peça vai precisar do enquadramento.', 'aviso')
  }

  // O seletor do item se refaz com a lista nova, se a janela estiver aberta.
  if (vState.itemAberto !== null) {
    pintarLeisDoItem()
    buscarArtigo(document.getElementById('vsi-artigo-busca')?.value ?? '')
  }
}

/** Os artigos citados na vistoria — a soma do que os itens citaram. */
function artigosDaVistoria() {
  return [...new Set(vState.relatorio.flatMap(i => i.artigos.map(a => a.artigo_id)))]
}

/** Mantém o campo escondido com o valor combinado aaaa-mm-ddThh:mm. */
function syncDataHora() {
  const d = document.getElementById('nv-data').value
  const h = document.getElementById('nv-hora').value || '00:00'
  document.getElementById('nv-datahora').value = d ? `${d}T${h}` : ''
  atualizarDisplayData(document.getElementById('nv-data'))
}

// ══════════════════════════════════════════════
// O RELATÓRIO EM ITENS
//
// Cada item é um GRUPO, e não uma linha. Em campo o que se constata não vem
// separado: "muro sem recuo" é uma irregularidade, mais o que o fiscal escreveu
// sobre ela, mais os artigos que a enquadram, mais as fotos que a provam. Eram
// quatro linhas soltas, que precisavam ser lidas juntas e podiam ser
// reordenadas em separado — desmontando o raciocínio.
//
// A ordem ENTRE itens é escolhida (é a sequência em que a obra foi percorrida).
// A ordem DENTRO do item é fixa e não se escolhe:
//
//   1 irregularidades   o que a lei chama de infração
//   2 texto livre       o que se viu, com as palavras do fiscal
//   3 artigos           o enquadramento
//   4 exigências        o que se cobra, com prazo
//   5 fotos             a prova
//
// É a ordem do raciocínio de uma peça. Deixá-la à escolha faria cada relatório
// sair diferente, e quem lê vinte por semana perde o hábito de leitura.
// ══════════════════════════════════════════════

/** Um item vazio — a folha em branco de um grupo. */
function itemVazio() {
  // `relatos` é a lista que a tela edita; `texto` é ela junta, e continua
  // sendo o que o servidor recebe e o que o resto do sistema lê. Uma verdade
  // só, derivada num lugar só — ver `sincronizarRelatos`.
  return { texto: '', relatos: [], irregularidades: [], artigos: [], exigencias: [], fotos: [] }
}

/**
 * Junta os relatos no `texto` do item — a forma que o servidor recebe.
 *
 * Um parágrafo por relato, na ordem em que foram escritos: é assim que eles
 * saem impressos na peça.
 *
 * @param {Object} item
 */
function sincronizarRelatos(item) {
  item.relatos = item.relatos ?? []
  item.texto = item.relatos.join('\n\n')
}

/**
 * Garante a lista de relatos num item que ainda não a tinha.
 *
 * Rascunho gravado antes desta mudança (e item vindo de qualquer caminho mais
 * antigo) traz só o `texto` corrido: ele vira a lista, quebrado nos parágrafos
 * em que foi escrito. Sem isto, abrir um rascunho antigo mostraria o relato
 * como vazio e ele se perderia no primeiro "Guardar".
 *
 * @param {Object} item
 */
function garantirRelatos(item) {
  if (Array.isArray(item.relatos)) { return }
  const t = (item.texto ?? '').trim()
  item.relatos = t ? t.split(/\n{2,}/).map(s => s.trim()).filter(Boolean) : []
}

/** Acrescenta um item e abre a janela dele: ninguém quer um grupo vazio na lista. */
function novoItemRelatorio() {
  vState.relatorio.push(itemVazio())
  renderRelatorio()
  abrirItemRelatorio(vState.relatorio.length - 1)
}

/** @param {Object} item @returns {boolean} */
function itemVazioDeConteudo(item) {
  return !item.texto?.trim()
    && !item.irregularidades.length && !item.artigos.length
    && !item.exigencias.length && !item.fotos.length
}

// ── A LISTA ──────────────────────────────────────────────────

/**
 * Cada item é um cartão com o resumo dos seus blocos.
 *
 * O resumo diz o que TEM dentro, e não o conteúdo: o texto inteiro mora na
 * janela do item, porque é texto de peça e merece o espaço de um formulário.
 * Aqui o que importa é reconhecer o grupo e poder movê-lo.
 */
function renderRelatorio() {
  const alvo = document.getElementById('nv-relatorio')
  if (!alvo) { return }

  if (!vState.relatorio.length) {
    alvo.innerHTML = '<div class="leg">Nenhum item ainda. Cada item é um ponto da obra: '
      + 'a irregularidade, o que você viu, os artigos, o que exige e as fotos.</div>'
    return
  }

  // Excluir e mover moram AQUI, na lista — não dentro da janela do item. Só
  // depois de Guardado o item existe como algo que se possa excluir; enquanto
  // se preenche, "Cancelar" já é a saída.
  alvo.innerHTML = vState.relatorio.map((item, i) => `
      <div class="rel-item${itemVazioDeConteudo(item) ? ' rel-falta' : ''}"
           onclick="abrirItemRelatorio(${i})">
        <div class="rel-capa">${capaDoItem(item, i)}</div>
        <div class="rel-corpo">
          <div class="rel-tit">Item ${i + 1}</div>
          ${conteudoDoItem(item)}
        </div>
        <div class="rel-acoes" onclick="event.stopPropagation()">
          <button type="button" class="btn sm danger" onclick="excluirItemDaLista(${i})">Excluir</button>
          <span class="rel-setas">
            <button type="button" onclick="moverItem(${i}, -1)" ${i === 0 ? 'disabled' : ''}
                    title="Subir">&#9650;</button>
            <button type="button" onclick="moverItem(${i}, 1)"
                    ${i === vState.relatorio.length - 1 ? 'disabled' : ''} title="Descer">&#9660;</button>
          </span>
        </div>
      </div>`).join('')
}

/** @param {number} i */
function excluirItemDaLista(i) {
  const item = vState.relatorio[i]
  if (!item) { return }

  confirmarAcao({
    titulo: 'Excluir item',
    mensagem: `O item ${i + 1} sai do relatório com tudo que está nele — `
      + 'irregularidades, texto, artigos, exigências e fotos.',
    textoBtn: 'Excluir',
    perigo: true,
    onConfirm: () => {
      // As fotos do item somem junto: elas eram a prova DELE.
      item.fotos.forEach(f => {
        const anexo = vState.anexos[f.anexo]
        if (anexo) { anexo.removido = true }
      })
      vState.relatorio.splice(i, 1)
      if (vState.itemAberto === i) { fModalBtn('m-vs-item'); vState.itemAberto = null }
      renderRelatorio()
    },
  })
}

/**
 * A primeira foto do item vira a miniatura.
 *
 * É o que faz reconhecer o ponto da obra de relance, sem abrir. Sem foto,
 * fica o número do item — que é como ele aparece no relatório impresso.
 */
function capaDoItem(item, i) {
  const capa = item.fotos.find(f => vState.anexos[f.anexo]?.url && !vState.anexos[f.anexo]?.removido)
  return capa
    ? `<img class="rel-mini" src="${esc(vState.anexos[capa.anexo].url)}" alt="">`
    : `<span class="rel-num">${i + 1}</span>`
}

/**
 * O QUE ESTÁ NO ITEM, dito por extenso — e só o que está.
 *
 * A versão anterior mostrava o texto livre e, embaixo, selos contando o resto:
 * "2 irregularidade(s)", "1 artigo(s)". Contar não é dizer: dois itens com
 * duas irregularidades cada ficavam idênticos na lista, e para saber QUAL era
 * a irregularidade — que é o que decide o enquadramento — só abrindo os dois.
 *
 * Agora cada bloco preenchido aparece nomeado, na mesma ordem em que sai no
 * relatório, e o bloco vazio não aparece: um item que é só uma foto se lê como
 * uma foto, não como quatro categorias em branco.
 *
 * @param {Object} item
 */
function conteudoDoItem(item) {
  const linhas = []

  if (item.irregularidades.length) {
    const nomes = item.irregularidades
      .map(id => vState.catalogo.find(c => c.id === id)?.descricao)
      .filter(Boolean)
    linhas.push(linhaDoItem('Irregularidade', nomes, item.irregularidades.length))
  }

  if (item.texto?.trim()) {
    const t = item.texto.trim()
    linhas.push(`<div class="rel-linha"><span class="rel-rot">Relato</span>
      <span class="rel-val">${esc(t.slice(0, 140))}${t.length > 140 ? '…' : ''}</span></div>`)
  }

  if (item.artigos.length) {
    const nomes = item.artigos
      .map(a => vState.artigos.find(x => x.id === a.artigo_id)?.rotulo
        || vState.artigos.find(x => x.id === a.artigo_id)?.numero)
      .filter(Boolean)
    linhas.push(linhaDoItem('Artigo', nomes, item.artigos.length))
  }

  if (item.exigencias.length) {
    const textos = item.exigencias.map(e =>
      e.texto + (e.prazo ? ` (${e.prazo} dias)` : ''))
    linhas.push(linhaDoItem('Exigência', textos, item.exigencias.length))
  }

  if (item.fotos.length) {
    linhas.push(`<div class="rel-linha"><span class="rel-rot">Fotos</span>
      <span class="rel-val">${item.fotos.length}${
        item.fotos.some(f => f.fachada) ? ' · uma delas é a fachada' : ''}</span></div>`)
  }

  return linhas.length
    ? linhas.join('')
    : '<div class="rel-linha rel-sem">Item vazio — toque para preencher.</div>'
}

/**
 * Uma linha do cartão: rótulo, os primeiros nomes, e quantos ficaram de fora.
 *
 * Corta em dois porque o cartão é para reconhecer o item, não para lê-lo
 * inteiro — mas dizer "e mais 3" é diferente de esconder três.
 *
 * @param {string} rotulo @param {string[]} nomes @param {number} total
 */
function linhaDoItem(rotulo, nomes, total) {
  const mostra = nomes.slice(0, 2).map(n => esc(String(n)))
  const resto = total - mostra.length
  return `<div class="rel-linha">
    <span class="rel-rot">${esc(rotulo)}${total > 1 ? 's' : ''}</span>
    <span class="rel-val">${mostra.join(' · ')}${resto > 0 ? ` <i>e mais ${resto}</i>` : ''}</span>
  </div>`
}

/** @param {number} i @param {number} d -1 sobe, 1 desce */
function moverItem(i, d) {
  const j = i + d
  if (j < 0 || j >= vState.relatorio.length) { return }

  const [item] = vState.relatorio.splice(i, 1)
  vState.relatorio.splice(j, 0, item)
  renderRelatorio()
}

// ── A JANELA DO ITEM ─────────────────────────────────────────
//
// Os cinco blocos numa janela só, na mesma ordem em que sairão no relatório.
// Editar em cinco telas separadas quebraria justamente o que o item existe para
// juntar.

/** @param {number} i */
function abrirItemRelatorio(i) {
  const item = vState.relatorio[i]
  if (!item) { return }

  vState.itemAberto = i
  irregEscolhida = null
  artigoEscolhido = null
  document.getElementById('vsi-titulo').textContent = `Item ${i + 1} do relatório`
  // O campo de relato abre VAZIO: o que já foi escrito está na lista do
  // resumo, e trazer de volta para o campo faria o "+add" duplicá-lo.
  garantirRelatos(item)
  document.getElementById('vsi-texto').value = ''
  document.getElementById('vsi-irreg-busca').value = ''
  document.getElementById('vsi-artigo-busca').value = ''
  fecharSugestoes('vsi-irreg-sugestoes')
  fecharSugestoes('vsi-artigo-sugestoes')
  descartarFotoPendente()
  pintarLeisDoItem()

  pintarContasDoItem()

  // Abre no bloco que JÁ TEM alguma coisa: reabrir um item para conferir a
  // foto não deveria começar pelo catálogo de irregularidades. Item novo abre
  // nas irregularidades, que é por onde o enquadramento começa.
  abaDoItem(primeiroBlocoComConteudo(item))

  openModal('m-vs-item')
}

/** Os cinco blocos do item, na ordem em que saem no relatório. */
// Três ícones em círculo, do padrão já usado para anexo (ver/editar/excluir):
// verde para as duas ações que preservam a foto, vermelho para a que apaga.
const ICO_OLHO = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/>
  <circle cx="12" cy="12" r="3"/></svg>`
const ICO_LAPIS = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
  <path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>`
const ICO_X = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>`

const BLOCOS_DO_ITEM = ['irreg', 'texto', 'artigos', 'exigencias', 'fotos']

/** @param {Object} item @returns {string} */
function primeiroBlocoComConteudo(item) {
  if (item.irregularidades.length) { return 'irreg' }
  if (item.texto?.trim())          { return 'texto' }
  if (item.artigos.length)         { return 'artigos' }
  if (item.exigencias.length)      { return 'exigencias' }
  if (item.fotos.length)           { return 'fotos' }
  return 'irreg'
}

/**
 * Mostra um bloco de cada vez.
 *
 * @param {string} nome
 */
function abaDoItem(nome) {
  document.querySelectorAll('#vsi-abas button[data-bloco]').forEach(b => {
    b.classList.toggle('at', b.dataset.bloco === nome)
  })
  document.querySelectorAll('#m-vs-item .vsi-bloco[data-bloco]').forEach(d => {
    d.hidden = d.dataset.bloco !== nome
  })
  // Trocar de aba fecha qualquer combo aberto: a lista flutua sobre o
  // conteúdo, e ficaria pairando sobre a aba nova.
  fecharSugestoes('vsi-irreg-sugestoes')
  fecharSugestoes('vsi-artigo-sugestoes')
  pintarSetasDeAba('vsi-setas', BLOCOS_DO_ITEM, nome)
  pintarContasDoItem()
}

/** As setas da janela do item. @param {'primeira'|'anterior'|'proxima'|'ultima'} destino */
function irAbaItem(destino) {
  const atual = document.querySelector('#vsi-abas button.at')?.dataset.bloco ?? BLOCOS_DO_ITEM[0]
  abaDoItem(abaAlvo(BLOCOS_DO_ITEM, atual, destino))
}

/**
 * A contagem em cada botão.
 *
 * É ela que substitui o empilhamento: sem abrir bloco nenhum dá para ver que o
 * item tem duas irregularidades e nenhuma foto, que era justamente o que a
 * janela cheia não deixava enxergar.
 */
function pintarContasDoItem() {
  const item = itemAtual()
  if (!item) { return }

  const põe = (id, n) => {
    const e = document.getElementById(id)
    if (!e) { return }
    e.textContent = n || ''
    e.parentElement.classList.toggle('vsi-tem', !!n)
  }

  põe('vsi-n-irreg', item.irregularidades.length)
  // O relato passou a ser uma LISTA, então conta como as outras: antes era um
  // campo só, e a aba mostrava um ponto porque "1" não dizia nada.
  põe('vsi-n-texto', item.relatos?.length ?? 0)
  põe('vsi-n-artigos', item.artigos.length)
  põe('vsi-n-exigencias', item.exigencias.length)
  põe('vsi-n-fotos', item.fotos.length)

  pintarResumoDoItem()
}

/**
 * O RESUMO É FIXO, e é o MESMO em qualquer aba.
 *
 * Cada aba do item tem, agora, só o controle de acrescentar — um combo, um
 * texto, um formulário curto. O que já foi posto no item mora aqui, fora das
 * abas, sempre visível, para não ser preciso visitar as cinco só para saber o
 * que já está preenchido. Cada linha tem seu próprio × — remover não exige
 * trocar de aba.
 */
function pintarResumoDoItem() {
  const alvo = document.getElementById('vsi-resumo')
  const item = itemAtual()
  if (!alvo || !item) { return }

  const cartoes = []

  // O resumo é redesenhado inteiro a cada mudança: as remoções guardadas do
  // desenho anterior apontariam para índices que já mudaram de dono.
  removedores.length = 0

  item.irregularidades.forEach(id => {
    const irr = vState.catalogo.find(c => c.id === id)
    const nome = irr?.descricao ?? `Irregularidade #${id}`
    cartoes.push(cartaoDoResumo({
      titulo: nome,
      sub: irr ? `${irr.codigo} · ${irr.gravidade}` : null,
      desc: irr?.base_legal,
      onclick: () => removerIrregularidadeDoItem(id),
      oQue: 'a irregularidade', qual: nome,
    }))
  })

  garantirRelatos(item)
  item.relatos.forEach((t, k) => cartoes.push(cartaoDoResumo({
    titulo: `Relato ${k + 1}`,
    desc: t,
    onclick: () => removerRelatoDoItem(k),
    oQue: 'o relato', qual: t,
  })))

  item.artigos.forEach((a, j) => {
    const nome = nomeDoArtigo(a.artigo_id)
    cartoes.push(cartaoDoResumo({
      titulo: nome,
      sub: a.tipo === 'parecer' ? 'Parecer — sua conclusão' : 'Citação — o que se constatou',
      desc: a.texto,
      onclick: () => removerArtigoDoItem(j),
      oQue: 'o artigo', qual: nome,
    }))
  })

  item.exigencias.forEach((e, j) => cartoes.push(cartaoDoResumo({
    titulo: e.texto,
    sub: e.prazo ? `Prazo de ${e.prazo} dia(s)` : null,
    onclick: () => removerExigenciaDoItem(j),
    oQue: 'a exigência', qual: e.texto,
  })))

  // AS FOTOS ENTRAM INTEIRAS, e não como "3 foto(s) anexada(s)". O resumo é o
  // que aparece em TODAS as abas: reduzir a foto a um número aqui era o mesmo
  // que escondê-la de quem não estivesse na aba Fotos — e a foto é a prova.
  // A linha é a MESMA de qualquer lista de arquivo do sistema (`.par-linha`,
  // miniatura + três ícones), agora com os três botões fazendo três coisas:
  // ver no visualizador, corrigir na ficha, remover.
  fotosVivasDoItem().forEach(({ f, j }) => {
    const anexo = vState.anexos[f.anexo] ?? {}
    const legenda = f.texto?.trim()
    cartoes.push(`
      <div class="par-linha vsi-foto-linha">
        <div class="rel-capa">${anexo.url
          ? `<img class="rel-mini" src="${esc(anexo.url)}" alt="">`
          : `<span class="rel-num">${j + 1}</span>`}</div>
        <div class="principal">
          <b>${esc(legenda || anexo.titulo || `Foto ${j + 1}`)}</b>
          <span>${esc(metaDaFoto(f))}${f.fachada ? ' · fachada' : ''}${
            legenda ? '' : ' · sem legenda'}</span>
        </div>
        <div class="vsi-foto-icones">
          <button type="button" class="ico-circ" onclick="verFotoDoItem(${j})"
                  title="Ver a foto">${ICO_OLHO}</button>
          <button type="button" class="ico-circ" onclick="editarFotoDoItem(${j})"
                  title="Corrigir legenda e marcas">${ICO_LAPIS}</button>
          <button type="button" class="ico-circ ico-circ-perigo" onclick="removerFotoDoItem(${j})"
                  title="Remover">${ICO_X}</button>
        </div>
      </div>`)
  })

  alvo.innerHTML = cartoes.length
    ? cartoes.join('')
    : '<div class="leg vsi-resumo-vazio">Nada adicionado ainda — use os campos acima.</div>'
}

/**
 * Um cartão do resumo: título, subtítulo, descrição e o × que remove — o
 * mesmo formato de cartão usado para citar artigo no resto do sistema, não
 * uma etiqueta inventada só para esta janela.
 *
 * @param {{titulo:string, sub?:string|null, desc?:string|null,
 *          onclick?:string, semRemover?:boolean}} p
 */
function cartaoDoResumo({ titulo, sub, desc, onclick, semRemover, oQue, qual }) {
  // O × PERGUNTA ANTES. Tirar da lista é gesto de um toque e sem desfazer, e o
  // botão fica a poucos pixels do texto do próprio cartão — num celular, em
  // campo, o dedo erra. A remoção fica guardada como FUNÇÃO em `removedores`,
  // e o HTML chama pelo índice: montar a chamada como texto obrigaria a
  // escapar aspas de descrição de irregularidade dentro de um `onclick`, que é
  // o tipo de coisa que quebra no primeiro registro com apóstrofo.
  let acao = ''
  if (!semRemover) {
    removedores.push(() => confirmarRemocaoDoResumo(oQue ?? 'o registro', qual ?? '', onclick))
    acao = `removedores[${removedores.length - 1}]()`
  }

  return `
    <div class="vsi-cartao">
      <div class="vsi-cartao-corpo">
        <b>${esc(titulo)}</b>${sub ? ` <span class="vsi-cartao-sub">${esc(sub)}</span>` : ''}
        ${desc ? `<p>${esc(desc)}</p>` : ''}
      </div>
      ${semRemover ? '' : `<button type="button" class="cartao-x" onclick="${acao}"
        title="Remover">&times;</button>`}
    </div>`
}

/**
 * Pergunta antes de tirar uma linha do resumo.
 *
 * O modal genérico já se fecha sozinho depois que a ação resolve — ver
 * `confirmarAcao` em ui.js.
 *
 * @param {string} oQue "a irregularidade", "o artigo"…
 * @param {string} qual o nome, para a pessoa reconhecer o que vai sair
 * @param {Function} acao o que remove de fato
 */
function confirmarRemocaoDoResumo(oQue, qual, acao) {
  const nome = qual.length > 80 ? qual.slice(0, 80) + '…' : qual
  confirmarAcao({
    titulo: 'Remover do item',
    mensagem: nome ? `Tirar ${oQue} "${nome}" deste item?` : `Tirar ${oQue} deste item?`,
    textoBtn: 'Remover',
    perigo: true,
    onConfirm: acao,
  })
}

/** O item aberto agora, ou null. */
function itemAtual() {
  return vState.relatorio[vState.itemAberto] ?? null
}

// ── bloco 1: irregularidades ──

/**
 * O catálogo, com o que já foi usado em OUTRO item bloqueado.
 *
 * O banco tem índice único (vistoria, irregularidade): a mesma não pode ser
 * constatada em dois itens. Dizer isso aqui, com o número do item que a usa,
 * evita o pedido recusado depois de todo o trabalho — o que se repete em vários
 * pontos da obra é o texto e a foto, não o enquadramento.
 */
// ── BUSCA-E-ADICIONA, o padrão dos dois combos deste item ──
//
// Um `<select>` obriga a rolar um catálogo de 20 ou 30 entradas para achar
// uma. Aqui digita-se parte do nome, aparecem as que batem, toca-se numa — ou
// aperta Enter/"+ add" para a primeira da lista. O que já está no item, ou
// preso a OUTRO item pelo índice único do banco, nem aparece: ele não é uma
// opção neste momento, então oferecê-la desabilitada só ocupa espaço.

/** @type {Array<Object>} a busca de irregularidade mostrada agora */
let sugestoesIrreg = []
/** @type {Array<Object>} a busca de artigo mostrada agora */
let sugestoesArtigo = []

// ESCOLHER NÃO É ADICIONAR. Tocar numa sugestão apenas PREENDE o campo e
// guarda a escolha aqui; quem põe no item é o "+ add" (ou o Enter). Antes o
// toque já jogava na lista — o que deixava o botão ao lado sem função e, pior,
// não dava chance de escolher "como entra" antes de o artigo estar dentro.
/**
 * As remoções do resumo, uma por cartão desenhado.
 *
 * O HTML chama `removedores[i]()` em vez de trazer a chamada escrita: nome de
 * irregularidade tem apóstrofo, e apóstrofo dentro de um `onclick` montado
 * como texto quebra o atributo.
 *
 * @type {Array<Function>}
 */
const removedores = []

/** @type {Object|null} a irregularidade escolhida e ainda não adicionada */
let irregEscolhida = null
/** @type {Object|null} o artigo escolhido e ainda não adicionado */
let artigoEscolhido = null

/** @param {string} texto */
function buscarIrregularidade(texto) {
  const item = itemAtual()
  if (!item) { return }

  const dono = new Map()
  vState.relatorio.forEach((it, n) => {
    if (n === vState.itemAberto) { return }
    it.irregularidades.forEach(id => dono.set(id, n + 1))
  })

  const q = texto.trim().toLowerCase()
  const disponiveis = vState.catalogo.filter(irr =>
    !item.irregularidades.includes(irr.id) && !dono.has(irr.id))

  // Campo vazio (ou recém-focado) mostra o começo do catálogo: combo que só
  // responde a quem já sabe o que digitar não é combo, é adivinhação.
  sugestoesIrreg = q
    ? disponiveis.filter(irr => irr.descricao.toLowerCase().includes(q)
        || irr.codigo.toLowerCase().includes(q)).slice(0, 8)
    : disponiveis.slice(0, 8)

  // Some com a escolha que o texto não descreve mais: digitar por cima do
  // nome escolhido é desistir dela.
  if (irregEscolhida && irregEscolhida.descricao !== texto) { irregEscolhida = null }

  pintarSugestoes('vsi-irreg-sugestoes', sugestoesIrreg,
    irr => ({ titulo: irr.descricao, sub: `${irr.codigo} · ${irr.gravidade}` }),
    'selecionarIrregularidade')

  document.getElementById('vsi-irreg-nota').textContent = disponiveis.length
    ? 'O que a lei chama de infração. É daqui que saem os artigos sugeridos.'
    : 'Todas as irregularidades do catálogo já estão marcadas em algum item.'
}

/**
 * Escolhe (não adiciona): põe o nome no campo e guarda a escolha.
 * @param {number} i índice em `sugestoesIrreg`
 */
function selecionarIrregularidade(i) {
  const irr = sugestoesIrreg[i]
  if (!irr) { return }

  irregEscolhida = irr
  document.getElementById('vsi-irreg-busca').value = irr.descricao
  fecharSugestoes('vsi-irreg-sugestoes')
  document.getElementById('vsi-irreg-nota').textContent =
    `${irr.codigo} · ${irr.gravidade}. Toque em "+ add" para pôr no item.`
}

/**
 * O "+ add" e o Enter: põem no item a irregularidade ESCOLHIDA — ou, se
 * ninguém escolheu ainda, a primeira da busca em curso.
 */
function adicionarIrregularidadeAoItem() {
  const item = itemAtual()
  if (!item) { return }

  const irr = irregEscolhida ?? sugestoesIrreg[0]
  if (!irr) { toast('Escolha a irregularidade na lista', 'err'); return }

  item.irregularidades = [...new Set([...item.irregularidades, irr.id])]

  irregEscolhida = null
  document.getElementById('vsi-irreg-busca').value = ''
  buscarIrregularidade('')
  fecharSugestoes('vsi-irreg-sugestoes')
  pintarContasDoItem()
  // A sugestão de artigos lê a SOMA dos itens: marcar aqui muda o que ela
  // oferece no item inteiro.
  sugerirArtigos()
}

/** @param {number} id */
function removerIrregularidadeDoItem(id) {
  const item = itemAtual()
  if (!item) { return }
  item.irregularidades = item.irregularidades.filter(x => x !== id)
  pintarContasDoItem()
  sugerirArtigos()
}

/**
 * A lista de sugestões, compartilhada pelos dois combos.
 *
 * @param {string} idAlvo id do container da lista
 * @param {Array<Object>} itens
 * @param {(item:Object)=>{titulo:string,sub?:string}} formatar
 * @param {string} aoEscolherFn nome da função global que recebe o índice
 */
function pintarSugestoes(idAlvo, itens, formatar, aoEscolherFn) {
  const alvo = document.getElementById(idAlvo)
  if (!alvo) { return }

  // `.open` e não `hidden`: a lista é um dropdown ancorado no campo, e quem
  // decide se ela aparece é a classe — o mesmo contrato do `.ac-list` do
  // AppPOSTURAS, para os dois sistemas se lerem igual.
  alvo.classList.toggle('open', itens.length > 0)
  alvo.innerHTML = itens.map((it, i) => {
    const f = formatar(it)
    return `<button type="button" class="ac-item" onclick="${aoEscolherFn}(${i})">
      <b>${esc(f.titulo)}</b>${f.sub ? `<span>${esc(f.sub)}</span>` : ''}
    </button>`
  }).join('')
}

/** Esconde e esvazia uma lista de sugestões. @param {string} idAlvo */
function fecharSugestoes(idAlvo) {
  const alvo = document.getElementById(idAlvo)
  if (!alvo) { return }
  alvo.classList.remove('open')
  alvo.innerHTML = ''
}

// Clicar fora fecha o combo — é o que se espera de um dropdown, e sem isto ele
// ficaria aberto sobre o resumo até alguém escolher alguma coisa. O clique na
// própria lista não conta: é ele que escolhe.
document.addEventListener('mousedown', ev => {
  if (ev.target.closest('.ac-wrap')) { return }
  fecharSugestoes('vsi-irreg-sugestoes')
  fecharSugestoes('vsi-artigo-sugestoes')
})

// ── bloco 2: o que você viu ──
//
// Uma LISTA de relatos, e não um campo corrido. Um item da obra costuma
// render mais de uma constatação, e tudo num bloco só obrigava a reescrever o
// parágrafo inteiro para tirar uma frase. Cada relato entra pelo "+add" e sai
// sozinho do resumo, como irregularidade e artigo.

/** O "+add" da aba: leva o que está escrito para a lista. */
function adicionarRelatoAoItem() {
  const item = itemAtual()
  if (!item) { return }

  const campo = document.getElementById('vsi-texto')
  const texto = campo.value.trim()
  if (!texto) { toast('Escreva o que você viu', 'err'); return }

  garantirRelatos(item)
  item.relatos.push(texto)
  sincronizarRelatos(item)

  campo.value = ''
  campo.focus()
  pintarContasDoItem()
}

/** @param {number} k posição do relato na lista */
function removerRelatoDoItem(k) {
  const item = itemAtual()
  if (!item?.relatos?.[k]) { return }

  item.relatos.splice(k, 1)
  sincronizarRelatos(item)
  pintarContasDoItem()
}

// ── bloco 3: artigos ──

/** @param {number} id @returns {string} */
function nomeDoArtigo(id) {
  const a = vState.artigos.find(x => x.id === id)
  return a ? (a.rotulo || a.numero) : 'Artigo'
}

/**
 * O select "Lei infringida" — as leis DOS ARTIGOS SUGERIDOS, e não o catálogo
 * inteiro de legislação: oferecer uma lei que não enquadra nenhuma das
 * irregularidades marcadas é oferecer um filtro que só sabe esvaziar a lista.
 */
function pintarLeisDoItem() {
  const sel = document.getElementById('vsi-artigo-lei')
  if (!sel) { return }

  const leis = [...new Set(vState.artigos.map(a => a.lei).filter(Boolean))].sort()
  const antes = sel.value

  sel.innerHTML = '<option value="">— todas as leis —</option>'
    + leis.map(l => `<option value="${esc(l)}">${esc(l)}</option>`).join('')

  // Mantém a lei escolhida se ela ainda existe na lista nova.
  sel.value = leis.includes(antes) ? antes : ''
}

/** @param {string} texto */
function buscarArtigo(texto) {
  const item = itemAtual()
  if (!item) { return }

  const q = texto.trim().toLowerCase()
  const lei = document.getElementById('vsi-artigo-lei')?.value ?? ''

  const disponiveis = vState.artigos
    .filter(a => !item.artigos.some(x => x.artigo_id === a.id))
    .filter(a => !lei || a.lei === lei)

  sugestoesArtigo = q
    ? disponiveis.filter(a => (a.rotulo || a.numero || '').toLowerCase().includes(q)
        || (a.conduta || '').toLowerCase().includes(q)).slice(0, 8)
    : disponiveis.slice(0, 8)

  if (artigoEscolhido && (artigoEscolhido.rotulo || artigoEscolhido.numero) !== texto) {
    artigoEscolhido = null
  }

  // `a.lei` já vem com o nome inteiro ("Lei Complementar 1/2023"): prefixar
  // "Lei" aqui escrevia "Lei Lei Complementar".
  pintarSugestoes('vsi-artigo-sugestoes', sugestoesArtigo,
    a => ({ titulo: a.rotulo || a.numero, sub: a.lei || null }),
    'selecionarArtigo')

  const nota = document.getElementById('vsi-artigo-nota')
  const texto2 = vState.artigos.length
    ? '' : 'Marque irregularidades para ver os artigos que as enquadram.'
  nota.textContent = texto2
  nota.hidden = !texto2
}

/**
 * Escolhe (não adiciona): põe o artigo no campo e guarda a escolha, para que
 * "como entra" e a observação ainda possam mudar antes do "+ add".
 * @param {number} i índice em `sugestoesArtigo`
 */
function selecionarArtigo(i) {
  const a = sugestoesArtigo[i]
  if (!a) { return }

  artigoEscolhido = a
  document.getElementById('vsi-artigo-busca').value = a.rotulo || a.numero
  fecharSugestoes('vsi-artigo-sugestoes')

  const nota = document.getElementById('vsi-artigo-nota')
  nota.textContent = a.conduta
    ? `${a.conduta} — toque em "+ add" para citar no item.`
    : 'Toque em "+ add" para citar no item.'
  nota.hidden = false
}

/** O "+ add" e o Enter: citam no item o artigo escolhido (ou o primeiro da busca). */
function adicionarArtigoAoItem() {
  const item = itemAtual()
  if (!item) { return }

  const a = artigoEscolhido ?? sugestoesArtigo[0]
  if (!a) { toast('Escolha o artigo na lista', 'err'); return }

  item.artigos.push({
    artigo_id: a.id,
    tipo: document.getElementById('vsi-artigo-tipo').value,
    // A observação por artigo saiu da tela: o que se tem a dizer é o relato do
    // item. O campo segue no envio (o servidor o aceita nulo) para não mexer
    // no contrato por causa de uma mudança de tela.
    texto: null,
  })

  artigoEscolhido = null
  document.getElementById('vsi-artigo-busca').value = ''
  buscarArtigo('')
  fecharSugestoes('vsi-artigo-sugestoes')
  pintarContasDoItem()
}

/** @param {number} j */
function removerArtigoDoItem(j) {
  const item = itemAtual()
  if (!item) { return }
  item.artigos.splice(j, 1)
  pintarContasDoItem()
}

// ── bloco 4: exigências ──

function adicionarExigenciaAoItem() {
  const item = itemAtual()
  const texto = document.getElementById('vsi-exig-texto').value.trim()
  if (!item) { return }
  if (!texto) { toast('Escreva a providência exigida', 'err'); return }

  const prazo = Number(document.getElementById('vsi-exig-prazo').value) || null
  item.exigencias.push({ texto, prazo })

  document.getElementById('vsi-exig-texto').value = ''
  document.getElementById('vsi-exig-prazo').value = ''
  pintarContasDoItem()
}

/** @param {number} j */
function removerExigenciaDoItem(j) {
  const item = itemAtual()
  if (!item) { return }
  item.exigencias.splice(j, 1)
  pintarContasDoItem()
}

// ── bloco 5: fotos ──
//
// A ABA SÓ ADICIONA, como as outras quatro. A LISTA do que já está no item
// mora no resumo (`pintarResumoDoItem`), o mesmo em todas as abas — a foto era
// a única com lista própria, e por isso só existia para quem estivesse
// justamente na aba Fotos.
//
// E o caminho da foto tem TRÊS tempos: escolher o arquivo, DESCREVER (legenda,
// fachada, marcas) e só então anexar. Antes ela entrava na lista no instante
// da escolha — sem chance de dizer o que mostra, e com o botão de anexar ao
// lado sem serventia nenhuma.

/** Abre a galeria/explorador (aceita PDF; não força a câmera). */
function escolherFotoDaGaleria() {
  prepararItemParaFoto()
  document.getElementById('nv-arquivo-galeria').click()
}

/** Abre a câmera traseira no celular. */
function tirarFotoDaCamera() {
  prepararItemParaFoto()
  document.getElementById('nv-arquivo').click()
}

/**
 * Garante um item aberto para receber a foto.
 *
 * A foto entra SEMPRE num item: se nenhum está aberto — é o caso da vistoria
 * rápida, que pula direto para a foto —, um item nasce para recebê-la.
 */
function prepararItemParaFoto() {
  if (vState.itemAberto === null) {
    vState.relatorio.push(itemVazio())
    vState.itemAberto = vState.relatorio.length - 1
    renderRelatorio()
  }
}

/** O atalho da vistoria rápida entra por aqui: uma foto, direto da câmera. */
function escolherArquivoDeFoto() { tirarFotoDaCamera() }

/**
 * Recebe os arquivos escolhidos e ENFILEIRA: um de cada vez ganha a ficha.
 *
 * Escolher cinco fotos de uma vez continua valendo — o que não vale é as cinco
 * entrarem sem legenda. Elas viram fila, e a ficha atende uma a uma.
 *
 * @param {HTMLInputElement} input
 */
function anexarArquivos(input) {
  prepararItemParaFoto()

  vState.filaFotos.push(...input.files)
  input.value = ''   // permite reescolher o mesmo arquivo depois de descartar

  if (!document.getElementById('m-vs-item').classList.contains('open')) {
    abrirItemRelatorio(vState.itemAberto)
  }
  abaDoItem('fotos')

  if (!vState.fotoPendente) { proximaFotoDaFila() }
}

/** Puxa o próximo arquivo da fila para a ficha; sem fila, fecha a ficha. */
function proximaFotoDaFila() {
  const arquivo = vState.filaFotos.shift()
  if (!arquivo) { renderFotoPendente(); return }

  const ehImagem = arquivo.type.startsWith('image/')

  vState.fotoPendente = {
    arquivo,
    url: ehImagem ? URL.createObjectURL(arquivo) : null,
    titulo: arquivo.name,
    marcacoes: [],
    // DATA E HORA DA FOTO, e não da vistoria: `lastModified` é o instante em
    // que a câmera gravou o arquivo. Quem lê o processo depois precisa saber
    // quando a prova foi feita — a vistoria pode ser lançada horas depois, e
    // até então as duas datas eram a mesma por construção.
    //
    // Em hora de parede (`instanteLocal`), e NÃO em ISO: o banco guarda o que
    // o relógio de quem estava lá marcava, como já faz a data da vistoria.
    quando: instanteLocal(new Date(arquivo.lastModified || Date.now())),
    lat: vState.gps?.lat ?? null,
    lon: vState.gps?.lon ?? null,
    editando: null,
  }

  renderFotoPendente()
  posicaoDaFoto()
}

/**
 * A posição NO MOMENTO DA FOTO, pedida ao aparelho em segundo plano.
 *
 * A da vistoria (capturada no passo 1) serve de piso e já entrou acima; esta a
 * substitui quando chega, porque o fiscal anda pelo terreno entre uma foto e
 * outra. Falhar é aceitável e silencioso: foto sem coordenada continua sendo
 * prova, e um alerta a cada foto seria ruído para quem está trabalhando.
 */
function posicaoDaFoto() {
  if (!navigator.geolocation) { return }

  const alvo = vState.fotoPendente
  navigator.geolocation.getCurrentPosition(
    pos => {
      // A ficha pode ter sido fechada ou trocada enquanto o GPS respondia.
      if (vState.fotoPendente !== alvo) { return }
      alvo.lat = pos.coords.latitude
      alvo.lon = pos.coords.longitude
      renderFotoPendente()
    },
    () => {},
    { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 },
  )
}

/** Desenha a ficha da foto pendente (ou some com ela). */
function renderFotoPendente() {
  const caixa = document.getElementById('vsi-foto-nova')
  const dica = document.getElementById('vsi-foto-dica')
  if (!caixa) { return }

  const p = vState.fotoPendente
  caixa.hidden = !p
  if (dica) { dica.hidden = !!p }
  if (!p) { return }

  const img = document.getElementById('vsi-foto-nova-img')
  const palco = document.getElementById('vsi-foto-nova-palco')
  // Anexo que não é imagem (PDF de projeto) não tem palco nem marcação.
  palco.hidden = !p.url
  if (p.url) { img.src = p.url }

  document.getElementById('vsi-foto-nova-pinos').innerHTML =
    p.marcacoes.map((m, k) =>
      `<span class="vsi-pino" style="left:${m.x * 100}%;top:${m.y * 100}%">${k + 1}</span>`).join('')

  document.getElementById('vsi-foto-nova-meta').textContent = metaDaFoto(p)

  const naFila = vState.filaFotos.length
  document.getElementById('vsi-foto-nova-titulo').textContent = p.editando !== null
    ? 'Corrigindo a foto'
    : (naFila ? `${p.titulo} — mais ${naFila} na fila` : p.titulo)

  document.getElementById('vsi-foto-nova-add').textContent =
    p.editando !== null ? 'Guardar' : '+ add'
}

/**
 * Data/hora e coordenada da foto, em uma linha.
 * @param {Object} p a foto (pendente ou já anexada)
 * @returns {string}
 */
function metaDaFoto(p) {
  const partes = []
  if (p.quando) {
    const d = new Date(p.quando)
    if (!isNaN(d.getTime())) {
      partes.push(d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }))
    }
  }
  partes.push(p.lat != null && p.lon != null
    ? `${Number(p.lat).toFixed(6)}, ${Number(p.lon).toFixed(6)}`
    : 'sem coordenada')
  return partes.join(' · ')
}

/** Um toque crava um número na foto da ficha. @param {MouseEvent} ev */
function marcarNaFotoPendente(ev) {
  const p = vState.fotoPendente
  if (!p || !p.url) { return }

  const img = ev.currentTarget.querySelector('img')
  if (!img) { return }

  const r = img.getBoundingClientRect()
  const x = (ev.clientX - r.left) / r.width
  const y = (ev.clientY - r.top) / r.height
  if (x < 0 || x > 1 || y < 0 || y > 1) { return }

  if (p.marcacoes.length >= 20) { toast('Limite de 20 marcas na foto', 'aviso'); return }
  p.marcacoes.push({ n: p.marcacoes.length + 1, x: +x.toFixed(4), y: +y.toFixed(4) })
  renderFotoPendente()
}

function limparMarcacoesPendente() {
  if (!vState.fotoPendente) { return }
  vState.fotoPendente.marcacoes = []
  renderFotoPendente()
}

/** Larga a foto da ficha sem anexar — e chama a próxima da fila, se houver. */
function descartarFotoPendente() {
  const p = vState.fotoPendente

  // A URL local só existe para exibir aqui; soltá-la evita segurar o arquivo
  // inteiro na memória. Numa CORREÇÃO ela não é nossa: pertence ao anexo, que
  // continua no item — revogá-la apagaria a miniatura da lista.
  if (p && p.url && p.editando === null) { URL.revokeObjectURL(p.url) }
  vState.fotoPendente = null

  const campo = document.getElementById('vsi-foto-nova-legenda')
  if (campo) { campo.value = '' }
  const fach = document.getElementById('vsi-foto-nova-fachada')
  if (fach) { fach.checked = false }

  if (vState.filaFotos.length) { proximaFotoDaFila() } else { renderFotoPendente() }
}

/** O "+ add" da ficha: agora sim a foto entra no item. */
function adicionarFotoAoItem() {
  const item = itemAtual()
  const p = vState.fotoPendente
  if (!item || !p) { return }

  const legenda = document.getElementById('vsi-foto-nova-legenda').value.trim()
  const fachada = document.getElementById('vsi-foto-nova-fachada').checked

  // A fachada é UMA na vistoria inteira: é a foto que responde "como está o
  // imóvel hoje", e duas respostas para isso não respondem nada.
  if (fachada) { vState.relatorio.forEach(it => it.fotos.forEach(f => { f.fachada = false })) }

  if (p.editando !== null) {
    Object.assign(item.fotos[p.editando], {
      texto: legenda, fachada, marcacoes: p.marcacoes,
      quando: p.quando, lat: p.lat, lon: p.lon,
    })
  } else {
    vState.anexos.push({
      arquivo: p.arquivo, titulo: p.titulo, url: p.url, removido: false,
    })
    item.fotos.push({
      anexo: vState.anexos.length - 1,
      texto: legenda,
      fachada,
      marcacoes: p.marcacoes,
      quando: p.quando, lat: p.lat, lon: p.lon,
    })
    // A URL passou a ser do anexo, que a usa na miniatura: não revogar aqui.
  }

  vState.fotoPendente = null
  document.getElementById('vsi-foto-nova-legenda').value = ''
  document.getElementById('vsi-foto-nova-fachada').checked = false

  if (vState.filaFotos.length) { proximaFotoDaFila() } else { renderFotoPendente() }
  pintarContasDoItem()
  renderRelatorio()
}

/**
 * O LÁPIS da lista: traz a foto de volta para a MESMA ficha, agora corrigindo.
 *
 * Um formulário só para pôr e para corrigir. O olho, que antes abria esta
 * mesma edição, passou a abrir o VISUALIZADOR — ver e editar eram dois botões
 * fazendo a mesma coisa.
 *
 * @param {number} j índice da foto dentro do item
 */
function editarFotoDoItem(j) {
  const item = itemAtual()
  const foto = item?.fotos?.[j]
  if (!foto) { return }

  const anexo = vState.anexos[foto.anexo] ?? {}
  vState.fotoPendente = {
    arquivo: anexo.arquivo ?? null,
    url: anexo.url ?? null,
    titulo: anexo.titulo ?? `Foto ${j + 1}`,
    marcacoes: [...(foto.marcacoes ?? [])],
    quando: foto.quando ?? null,
    lat: foto.lat ?? null,
    lon: foto.lon ?? null,
    editando: j,
  }

  abaDoItem('fotos')
  renderFotoPendente()
  document.getElementById('vsi-foto-nova-legenda').value = foto.texto ?? ''
  document.getElementById('vsi-foto-nova-fachada').checked = !!foto.fachada
}

/** @param {number} j */
function removerFotoDoItem(j) {
  const item = itemAtual()
  if (!item?.fotos?.[j]) { return }

  // Corrigindo justamente esta? A ficha ficaria apontando para um índice que
  // mudou de dono depois do splice — fecha antes.
  if (vState.fotoPendente?.editando === j) {
    vState.fotoPendente = null
    renderFotoPendente()
  }

  const anexo = vState.anexos[item.fotos[j].anexo]
  if (anexo) { anexo.removido = true }
  item.fotos.splice(j, 1)
  pintarContasDoItem()
  renderRelatorio()
}

// ── O VISUALIZADOR (#m-foto-view) ────────────────────────────
//
// Mesmo visualizador do AppPOSTURAS: a foto grande, as setas andando pelas
// fotos do MESMO item (dando a volta nas pontas) e o contador "N de M". Aqui
// ele mostra também os pinos e a legenda — ver a marca "2" sem saber o que ela
// aponta é meio caminho.

/** @type {number} índice, dentro do item, da foto aberta no visualizador */
let fotoVista = -1

/** @param {number} j índice da foto dentro do item */
function verFotoDoItem(j) {
  const item = itemAtual()
  if (!item?.fotos?.[j]) { return }
  fotoVista = j
  pintarVisualizadorDeFoto()
  openModal('m-foto-view')
}

/** @param {number} d -1 anterior, 1 próxima — dá a volta nas pontas. */
function navegarFoto(d) {
  const vivas = fotosVivasDoItem()
  if (vivas.length < 2) { return }

  const atual = vivas.findIndex(({ j }) => j === fotoVista)
  const proxima = (atual + d + vivas.length) % vivas.length
  fotoVista = vivas[proxima].j
  pintarVisualizadorDeFoto()
}

/** As fotos do item que não foram removidas, com o índice original. */
function fotosVivasDoItem() {
  const item = itemAtual()
  if (!item) { return [] }
  return item.fotos
    .map((f, j) => ({ f, j }))
    .filter(({ f }) => !vState.anexos[f.anexo]?.removido)
}

function pintarVisualizadorDeFoto() {
  const item = itemAtual()
  const foto = item?.fotos?.[fotoVista]
  if (!foto) { return }

  const anexo = vState.anexos[foto.anexo] ?? {}
  const vivas = fotosVivasDoItem()
  const pos = vivas.findIndex(({ j }) => j === fotoVista)

  document.getElementById('foto-view-titulo').textContent =
    foto.texto?.trim() || anexo.titulo || `Foto ${fotoVista + 1}`

  const img = document.getElementById('foto-view-img')
  img.src = anexo.url ?? ''
  img.hidden = !anexo.url

  document.getElementById('foto-view-pinos').innerHTML =
    (foto.marcacoes ?? []).map((m, k) =>
      `<span class="vsi-pino" style="left:${m.x * 100}%;top:${m.y * 100}%">${k + 1}</span>`).join('')

  const legenda = document.getElementById('foto-view-legenda')
  legenda.textContent = foto.texto?.trim() ?? ''
  legenda.hidden = !foto.texto?.trim()

  document.getElementById('foto-view-meta').textContent =
    metaDaFoto(foto) + (foto.fachada ? ' · fachada do imóvel' : '')

  // Setas e contador só quando há para onde ir.
  const multi = vivas.length > 1
  document.getElementById('foto-view-prev').hidden = !multi
  document.getElementById('foto-view-next').hidden = !multi
  document.getElementById('foto-view-contador').textContent =
    multi ? `${pos + 1} de ${vivas.length}` : ''
}

function fecharVisualizadorDeFoto() {
  fotoVista = -1
  fModalBtn('m-foto-view')
}
// ── fechar a janela ──

function salvarItemRelatorio() {
  const item = itemAtual()

  // Relato digitado e ainda não adicionado entra assim mesmo: quem escreveu e
  // foi direto em "Guardar" quis guardar aquilo, e não perdê-lo por não ter
  // tocado no "+add".
  if (item) {
    const sobrou = document.getElementById('vsi-texto').value.trim()
    if (sobrou) {
      garantirRelatos(item)
      item.relatos.push(sobrou)
      sincronizarRelatos(item)
      document.getElementById('vsi-texto').value = ''
    }
  }

  if (item && itemVazioDeConteudo(item)) {
    toast('O item está vazio — escreva algo, marque uma irregularidade ou anexe uma foto.', 'err')
    return
  }

  fModalBtn('m-vs-item')
  vState.itemAberto = null
  renderRelatorio()
}

/**
 * Fecha sem levar o que estava só digitado.
 *
 * Tudo que passou pelo "+add" já está no item — botão "adicionar" é gravação.
 * O que fica no campo de relato sem ter sido adicionado é o que se perde aqui,
 * e é justamente o que "Guardar" salva.
 */
function fecharItemRelatorio() {
  const item = itemAtual()

  if (item && itemVazioDeConteudo(item) && !document.getElementById('vsi-texto').value.trim()) {
    // Item que nasceu agora e não recebeu nada não deve ficar na lista.
    vState.relatorio.splice(vState.itemAberto, 1)
  }

  fModalBtn('m-vs-item')
  vState.itemAberto = null
  renderRelatorio()
}

// `excluirItemRelatorio` (excluir de dentro da janela) saiu daqui: quem
// exclui agora é `excluirItemDaLista(i)`, a partir do cartão na lista — ver
// o comentário em `renderRelatorio`.

/** Todas as irregularidades marcadas na vistoria, de todos os itens. */
function irregularidadesDaVistoria() {
  return [...new Set(vState.relatorio.flatMap(i => i.irregularidades))]
}


// ── PASSO 5: REVISÃO ─────────────────────────────────────────

/**
 * O ato como ele será gravado.
 *
 * Última tela antes de gravar tem de ser a LEITURA do ato, não mais um
 * formulário: é daqui que saem notificação, auto de infração e embargo.
 */
function renderRevisao() {
  const marcadas = irregularidadesDaVistoria()
    .map(id => vState.catalogo.find(c => c.id === id))
    .filter(Boolean)
  const sit = document.getElementById('nv-situacao')
  const area = document.getElementById('nv-area').value
  const metodo = document.getElementById('nv-area-metodo')
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
    // O relatório sai como sairá no papel: item a item, e dentro de cada um a
    // ordem fixa — irregularidades, texto, artigos, exigências, fotos.
    ['Relatório', vState.relatorio.length
      ? '<ol>' + vState.relatorio.map(it => {
          const partes = []
          if (it.irregularidades.length) {
            partes.push(it.irregularidades
              .map(id => esc(vState.catalogo.find(c => c.id === id)?.descricao ?? '—')).join('; '))
          }
          if (it.texto?.trim()) { partes.push(esc(it.texto.trim())) }
          if (it.artigos.length) {
            partes.push('<b>' + it.artigos.map(a => esc(nomeDoArtigo(a.artigo_id))).join(', ') + '</b>')
          }
          it.exigencias.forEach(e => {
            partes.push(esc(e.texto) + (e.prazo ? ' <b>— ' + e.prazo + ' dias</b>' : ''))
          })
          const fotos = it.fotos.filter(f => !vState.anexos[f.anexo]?.removido)
          if (fotos.length) {
            const marcas = fotos.reduce((s, f) => s + (f.marcacoes?.length ?? 0), 0)
            partes.push(fotos.length + ' foto(s)' + (marcas ? ` <i>(${marcas} marca(s))</i>` : ''))
          }

          return '<li>' + (partes.length ? partes.join('<br>') : falta('item vazio')) + '</li>'
        }).join('') + '</ol>'
      : falta('vazio')],
    ['Irregularidades', marcadas.length
      ? '<ol>' + marcadas.map(c => '<li>' + esc(c.descricao) + '</li>').join('') + '</ol>'
      : falta('nenhuma')],
    ['Artigos citados', artigosDaVistoria().length
      ? esc(artigosDaVistoria().map(id => nomeDoArtigo(id)).join(', '))
      : falta('nenhum')],
    ['Fotos', (() => {
      const fotos = vState.relatorio.flatMap(i => i.fotos)
        .filter(f => !vState.anexos[f.anexo]?.removido)
      if (!fotos.length) { return falta('nenhuma') }
      return `${fotos.length} no relatório`
        + (fotos.some(f => f.fachada) ? ', uma marcada como fachada' : ', ' + falta('sem fachada marcada'))
        + (fotos.some(f => !f.texto?.trim()) ? '<br>' + falta('há foto sem legenda') : '')
    })()],
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
  return !!(document.getElementById('nv-area').value
    || vState.relatorio.length)
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
        area: v('nv-area'), metodo: v('nv-area-metodo'),
        acompNome: v('nv-acomp-nome'), acompQual: v('nv-acomp-qual'),
        alvaraNumero: v('nv-alvara-numero'), ano: v('nv-ano'),
      },
      finalidade: vState.finalidade,
      obra: vState.obra,
      gps: vState.gps,
      // As FOTOS não cabem no armazenamento do navegador. O rascunho guarda os
      // itens com tudo menos elas — e o aviso da retomada diz isso com todas as
      // letras, em vez de deixar o fiscal gravar achando que estão lá.
      relatorio: vState.relatorio.map(i => ({ ...i, fotos: [] })),
    }))
  } catch (e) {
    console.error(e)   // cota cheia ou modo privado: o formulário segue igual
  }
}

/**
 * O rascunho guardado deste imóvel, se houver.
 * Só o DO MESMO LOTE — o de outro imóvel seria contaminação.
 *
 * @returns {Object|null}
 */
function lerRascunho() {
  let d
  try { d = JSON.parse(localStorage.getItem(RASCUNHO) || 'null') } catch (e) { return null }
  return (d && d.lote === vState.lote.id) ? d : null
}

/**
 * OFERECE o rascunho — não o aplica.
 *
 * Antes ele voltava sozinho, e uma vistoria nova nascia com o texto da
 * anterior dentro. Quem abre "nova vistoria" está pedindo uma folha em branco;
 * receber o conteúdo de outra visita é, na melhor hipótese, um susto, e na
 * pior um ato assinado com o que se viu em outro dia.
 *
 * A rede de segurança continua inteira — bateria acabando, navegador fechado
 * sem querer —, só que agora ela é um botão em vez de um efeito. E a cópia
 * fica em memória: a partir do primeiro toque a tela já grava rascunho por
 * cima do armazenamento, e sem isto a oferta morreria antes de ser aceita.
 */
function oferecerRascunho() {
  const av = document.getElementById('nv-rascunho')
  const d = lerRascunho()
  vState.rascunhoPendente = d

  if (!d) { av.hidden = true; return }

  const quando = d.quando ? new Date(d.quando) : null
  const rotulo = quando
    ? `${String(quando.getDate()).padStart(2, '0')}/${String(quando.getMonth() + 1).padStart(2, '0')}`
      + ` às ${String(quando.getHours()).padStart(2, '0')}:${String(quando.getMinutes()).padStart(2, '0')}`
    : 'anterior'

  av.hidden = false
  av.innerHTML = `
    <span>Há um rascunho deste imóvel de ${esc(rotulo)} — as fotos não voltam.</span>
    <span class="vs-aviso-acoes">
      <button type="button" class="btn sm" onclick="retomarRascunho()">Retomar</button>
      <button type="button" class="btn sm" onclick="descartarRascunho()">Começar em branco</button>
    </span>`
}

/** Aceita a oferta: o rascunho entra na tela. */
function retomarRascunho() {
  const d = vState.rascunhoPendente
  if (!d) { return }

  vState.rascunhoPendente = null
  aplicarRascunho(d)
  // O catálogo já chegou (a oferta só existe com a tela montada), então as
  // marcas do checklist se aplicam agora — em `renderChecklist`, que é quem
  // sabe fazê-lo e é o mesmo caminho de quando o catálogo chega depois.
  renderChecklist()

  const av = document.getElementById('nv-rascunho')
  av.hidden = false
  av.textContent = 'Rascunho recuperado — as fotos precisam ser refeitas'
}

/**
 * Guarda o rascunho AGORA, porque alguém pediu.
 *
 * É o único caminho que grava: nenhuma tecla, nenhuma troca de passo e nenhum
 * fechamento de janela guarda nada sozinho. O aviso na tela confirma que
 * guardou — sem ele o botão seria um clique no escuro.
 */
function guardarRascunho() {
  if (!temConteudo()) { toast('Não há nada para guardar ainda', 'aviso'); return }

  salvarRascunho()
  vState.rascunhoPendente = null

  const av = document.getElementById('nv-rascunho')
  av.hidden = false
  av.textContent = 'Rascunho guardado neste aparelho — as fotos não vão junto'
  toast('Rascunho guardado')
}

/** Recusa a oferta: some da tela e do aparelho. */
function descartarRascunho() {
  vState.rascunhoPendente = null
  limparRascunho()
  document.getElementById('nv-rascunho').hidden = true
}

/** Põe na tela o que o rascunho guardou. @param {Object} d */
function aplicarRascunho(d) {
  const põe = (id, valor) => { const e = document.getElementById(id); if (e && valor) { e.value = valor } }
  const c = d.campos ?? {}
  põe('nv-data', c.data); põe('nv-hora', c.hora); põe('nv-situacao', c.situacao)
  põe('nv-area', c.area); põe('nv-area-metodo', c.metodo)
  põe('nv-acomp-nome', c.acompNome); põe('nv-acomp-qual', c.acompQual)
  põe('nv-alvara-numero', c.alvaraNumero); põe('nv-ano', c.ano)
  syncDataHora()

  vState.finalidade = FINALIDADES[d.finalidade] ? d.finalidade : 'obras'
  vState.obra = d.obra ?? { alvara: '', fase: '', projeto: '', uso: '' }
  vState.gps = d.gps ?? vState.gps
  // Os itens voltam inteiros, menos as FOTOS — que não cabem no armazenamento
  // do navegador. `fotos: []` é garantido aqui e não confiado ao que foi
  // gravado: rascunho de uma versão antiga não pode trazer índices de anexo
  // que não existem mais nesta sessão.
  vState.relatorio = (d.relatorio ?? []).map(i => ({ ...itemVazio(), ...i, fotos: [] }))
  document.getElementById('nv-alvara-num-campo').hidden = vState.obra.alvara !== 'possui'

  pintarOpcoes(); pintarFinalidade(); pintarGps(); renderRelatorio()
  irPasso(d.passo ?? 'id')
}

function limparRascunho() {
  try { localStorage.removeItem(RASCUNHO) } catch (e) { /* nada a fazer */ }
}

// ── GRAVAÇÃO ─────────────────────────────────────────────────

/** Grava a vistoria. Confirma antes: é registro que passa a valer como ato. */
function gravarVistoria() {
  if (vState.enviando) return

  const marcadas = irregularidadesDaVistoria()
  const situacao = document.getElementById('nv-situacao').value

  // O IMÓVEL É COBRADO AQUI, e não na abertura: a janela abre sem ele para que
  // se possa começar a escrever, mas vistoria é vistoria DE alguma coisa, e a
  // gravação vai para /api/lotes/{id}/vistorias.
  if (!vState.lote?.id) {
    irPasso('id')
    document.getElementById('nv-imovel-termo')?.focus()
    toast('Escolha o imóvel desta vistoria', 'err')
    return
  }
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
    onConfirm: () => enviarVistoria(),
  })
}

/** @param {Array<HTMLInputElement>} marcadas */
async function enviarVistoria() {
  vState.enviando = true
  const campo = id => document.getElementById(id)?.value ?? ''
  const fd = new FormData()
  fd.append('data_hora', campo('nv-datahora'))
  // O servidor lê a finalidade ANTES de decidir o que gravar: o que não
  // pertence a ela é descartado lá, e não aqui — ver Vistoria::colunasForaDa.
  fd.append('finalidade', vState.finalidade)
  fd.append('situacao', campo('nv-situacao'))
  // `observacoes` não é mais preenchido pela tela — o campo saiu do passo do
  // relatório (todo relato pertence a um item). A coluna segue no banco pelas
  // vistorias antigas que a usaram, e o servidor a aceita nula.
  // O vínculo com o protocolo é o que, mais tarde, libera o ato cadastral.
  const proto = document.getElementById('nv-protocolo')?.value
  if (proto) { fd.append('protocolo_id', proto) }
  // `irregularidades[]` NÃO vai mais no topo: cada uma pertence ao item onde
  // foi constatada, e o servidor deriva a lista da vistoria somando os itens.
  // Mandar as duas coisas abriria espaço para elas discordarem.

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

  // ── O RELATÓRIO EM ITENS ──
  //
  // Cada item vai como um grupo, na ordem em que o fiscal os montou. As FOTOS
  // não vão aninhadas: arquivo sobe na remessa achatada `evidencias[]`, que é
  // como upload funciona, e o item as reivindica pelo ÍNDICE DA REMESSA —
  // não pelo índice em `vState.anexos`, que guarda buracos de fotos removidas.
  //
  // Os artigos da vistoria são a SOMA do que os itens citaram: é a relação que
  // a lavratura lê, e derivá-la evita duas listas que podem discordar.
  artigosDaVistoria().forEach(id => fd.append('artigos[]', id))

  let nFoto = 0
  vState.relatorio.forEach((item, n) => {
    if (item.texto?.trim()) { fd.append(`itens[${n}][texto]`, item.texto.trim()) }

    item.irregularidades.forEach(id => fd.append(`itens[${n}][irregularidades][]`, id))

    item.artigos.forEach((a, j) => {
      fd.append(`itens[${n}][artigos][${j}][artigo_id]`, a.artigo_id)
      fd.append(`itens[${n}][artigos][${j}][tipo]`, a.tipo)
      fd.append(`itens[${n}][artigos][${j}][observacao]`, a.texto ?? '')
    })

    item.exigencias.forEach((e, j) => {
      fd.append(`itens[${n}][exigencias][${j}][texto]`, e.texto)
      if (e.prazo) { fd.append(`itens[${n}][exigencias][${j}][prazo_dias]`, e.prazo) }
    })

    item.fotos.forEach(f => {
      const anexo = vState.anexos[f.anexo]
      if (!anexo || anexo.removido) { return }

      fd.append('evidencias[]', anexo.arquivo)
      fd.append(`titulos[${nFoto}]`, anexo.titulo)
      fd.append(`descricoes[${nFoto}]`, f.texto ?? '')
      fd.append(`ordens[${nFoto}]`, nFoto)
      // QUANDO E ONDE A FOTO FOI FEITA. Até aqui toda evidência era gravada
      // com a data e a coordenada DA VISTORIA — iguais para todas, e da hora
      // do lançamento, não da captura. Num processo, é a foto que precisa
      // dizer quando e de onde: a vistoria pode ser digitada horas depois, e
      // o fiscal anda pelo terreno entre uma foto e outra.
      if (f.quando) { fd.append(`fotos_quando[${nFoto}]`, f.quando) }
      if (f.lat != null && f.lon != null) {
        fd.append(`fotos_lat[${nFoto}]`, f.lat)
        fd.append(`fotos_lon[${nFoto}]`, f.lon)
      }
      if ((f.marcacoes || []).length) {
        fd.append(`marcacoes[${nFoto}]`, JSON.stringify(f.marcacoes))
      }
      if (f.fachada) { fd.append('fachada', nFoto) }

      fd.append(`itens[${n}][fotos][]`, nFoto)
      nFoto++
    })
  })

  if (vState.gps) {
    fd.append('latitude', vState.gps.lat)
    fd.append('longitude', vState.gps.lon)
    fd.append('accuracy', vState.gps.prec)
  }

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
    // acabou de gravar sem ter que procurar o lote de novo. `voltarAFicha`
    // usa a ficha de ORIGEM quando há uma; sem ela, o lote selecionado serve,
    // que é o caso de quem chegou pela tela de protocolos.
    if (! voltarAFicha() && state.selecionado) { abrirFicha(state.selecionado) }

    // CONSTATOU IRREGULARIDADE? O ATO VEM AGORA.
    //
    // Era aqui que o caminho do fiscal terminava: gravava a constatação, a
    // tela voltava para a ficha e o assunto morria. O painel até cobrava
    // ("vistorias irregulares sem documento"), mas não havia por onde fechar.
    // A pergunta aparece no único momento em que o fiscal ainda está com a
    // obra na cabeça — e a peça nasce presa A ESTA vistoria, não à última do
    // imóvel. Vistoria regular não pergunta nada: nada aconteceu, e está certo.
    if (d.vistoria?.situacao === 'irregular' && d.vistoria?.id) {
      oferecerDocumentoDaVistoria(d.vistoria)
    }
  } catch (e) {
    console.error(e)
    toast(e.message || 'Falha ao gravar a vistoria', 'err')
    throw e   // mantém o modal de confirmação aberto para nova tentativa
  } finally {
    vState.enviando = false
  }
}
