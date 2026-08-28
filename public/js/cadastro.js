// ══════════════════════════════════════════════
// CORREÇÃO CADASTRAL PELO MAPA
//
// Seleção de vários lotes com o dedo e correção da quadra deles.
//
// Existe porque o preenchimento por quarteirão (busca.js) só alcança lote com
// a quadra VAZIA. Quadra ERRADA é o defeito mais perigoso da base: não deixa
// buraco à vista, só faz dois imóveis responderem pela mesma identificação no
// cadastro imobiliário — e não havia como corrigi-la sem reimportar o bairro.
// ══════════════════════════════════════════════

/**
 * Seleção corrente. Estado PRÓPRIO, separado dos dois que já existem:
 *
 *   mapaState.destacado   um lote só, o do balão — seleção de consulta
 *   corState.destacados   resultado de FILTRO, alimentado por busca e pinos
 *   selState.ids          o que a pessoa marcou para corrigir
 *
 * Guardar a seleção em `corState.destacados` seria tentador (o Set já existe e
 * já é pintado), mas ele responde outra pergunta: aplicar um filtro apagaria a
 * seleção, e selecionar apagaria o filtro que a pessoa acabou de montar.
 */
const selState = {
  ativa: false,
  /** @type {'quadra'|null} */ modo: null,
  /** @type {Set<number>} */  ids: new Set(),
  /** Teto igual ao do servidor (QuadraDeLotesSelecionados::MAXIMO). */
  max: 300,
}

/** O mapa está em modo de seleção? Lido pelo handler de clique do polígono. */
function selecaoAtiva() {
  return selState.ativa
}

// ── LIGA E DESLIGA ───────────────────────────────────────────

/** @param {'quadra'} modo */
function ligarSelecao(modo) {
  selState.ativa = true
  selState.modo = modo
  // Balão aberto durante a seleção é ruído: cada toque abriria um.
  mapaState.obj?.closePopup()
  limparSelecao()
  pintarPainelCadastro()
}

function desligarSelecao() {
  selState.ativa = false
  selState.modo = null
  limparSelecaoCadastral()
  pintarPainelCadastro()
}

function alternarModoSelecao() {
  selState.ativa ? desligarSelecao() : ligarSelecao('quadra')
}

/** Larga tudo o que estava marcado, repintando só o que muda. */
function limparSelecaoCadastral() {
  const ids = [...selState.ids]
  selState.ids.clear()
  ids.forEach(repintarLote)
  marcarIndicadorControle('grupo-cadastro', null)
  pintarPainelCadastro()
}

// ── SELEÇÃO ──────────────────────────────────────────────────

/**
 * Marca ou desmarca um lote.
 *
 * Repinta SÓ a camada que mudou. A alternativa — `mapaState.camadas.forEach`,
 * como faz o filtro em mapa-cores.js — são até 3.000 mutações de DOM por
 * toque, e este mapa não usa `preferCanvas`: o renderizador é SVG.
 *
 * @param {Object} feicao @param {L.Path} camada
 */
function alternarSelecao(feicao, camada) {
  const id = feicao.properties.id

  if (selState.ids.has(id)) {
    selState.ids.delete(id)
  } else {
    if (selState.ids.size >= selState.max) {
      toast(`Máximo de ${selState.max} lotes por correção.`, 'aviso')
      return
    }
    selState.ids.add(id)
  }

  camada.setStyle(estiloColorido(feicao))
  if (selState.ids.has(id)) camada.bringToFront()

  marcarIndicadorControle('grupo-cadastro', selState.ids.size || null)
  pintarPainelCadastro()
}

/** @param {number} id */
function repintarLote(id) {
  const c = mapaState.porId.get(id)
  if (c) c.setStyle(estiloColorido(c.feature))
}

// ── PAINEL ───────────────────────────────────────────────────

// ── MODO DE TRABALHO ─────────────────────────────────────────
//
// A correção cadastral tem duas naturezas misturadas: um gesto no MAPA (marcar
// lotes, desenhar cantos) e um preenchimento de FORMULÁRIO (qual quadra, qual
// número, conferir a prévia). Antes as duas moravam na mesma coluna de 262px
// ao lado do mapa — e aí nenhuma ficava boa: o gesto disputava espaço com o
// mapa, e o formulário ficava espremido.
//
// Agora cada uma tem o seu lugar: o mapa inteiro para o gesto, com uma barra
// fina no topo dizendo o passo, e uma janela para os dados. A janela pode ser
// fechada sem perder nada — o que foi marcado ou desenhado continua no mapa, e
// a barra oferece reabrir.

/** @type {'quadra'|'desenho'|'coordenadas'|null} */
let cadModo = null

/**
 * Entra num modo de correção. Um de cada vez, sempre: marcar e desenhar
 * disputam o mesmo clique no mapa, e oferecer os dois ao mesmo tempo produz
 * exatamente o erro que a confirmação depois tenta consertar.
 *
 * @param {'quadra'|'desenho'|'coordenadas'} modo
 */
function modoCadastral(modo) {
  sairModoCadastral(true)
  cadModo = modo

  // O lancador recolhe assim que a escolha e feita. Ele e um menu: cumprida a
  // funcao, fica por cima do mapa — que e justamente onde o trabalho comeca a
  // acontecer —, e no caso das coordenadas ficaria por cima da propria janela
  // que ele acabou de abrir.
  if (typeof fecharPaineisMapa === 'function') { fecharPaineisMapa() }

  if (modo === 'quadra') {
    ligarSelecao('quadra')
  } else if (modo === 'desenho') {
    iniciarDesenhoDeLote()
  } else if (modo === 'coordenadas') {
    document.getElementById('coo-caixa').hidden = false
    abrirModalCad()
  }

  pintarPainelCadastro()
}

/** Sai do modo e limpa o que estava em curso. @param {boolean} [silencioso] */
function sairModoCadastral(silencioso) {
  if (selState.ativa) { desligarSelecao() }
  limparSelecaoCadastral()
  if (typeof cancelarDesenho === 'function') { cancelarDesenho() }
  desenhoPendente = null
  limparPreviaCoordenadas()

  const coo = document.getElementById('coo-caixa')
  if (coo) { coo.hidden = true }
  const txt = document.getElementById('coo-texto')
  if (txt && !silencioso) { txt.value = '' }
  const res = document.getElementById('coo-resultado')
  if (res) { res.innerHTML = '' }

  cadModo = null
  if (!silencioso) {
    fModalBtn('m-cad')
    pintarPainelCadastro()
  }
}

/** Abre a janela de dados no painel do modo corrente. */
function abrirModalCad() {
  const quadra = cadModo === 'quadra'
  document.getElementById('cadp-quadra').hidden = !quadra
  document.getElementById('cadp-desenho').hidden = quadra
  document.getElementById('cad-modal-titulo').textContent =
    quadra ? 'Quadra dos lotes marcados'
      : cadModo === 'coordenadas' ? 'Lote por coordenadas' : 'Dados do lote desenhado'
  // Tambem aqui, e nao so na escolha do modo: a janela pode ser aberta bem
  // depois, pela barra ("Informar a quadra", "Concluir desenho"), e ate la o
  // usuario pode ter reaberto o lancador.
  if (typeof fecharPaineisMapa === 'function') { fecharPaineisMapa() }
  openModal('m-cad')
  pintarPainelCadastro()
}

/** Fecha a janela SEM desfazer nada: a barra continua com o trabalho em curso. */
function fecharModalCad() {
  fModalBtn('m-cad')
  pintarPainelCadastro()
}

/**
 * A barra sobre o mapa: em que passo se está, e o que fazer a seguir.
 *
 * Ela é a única coisa que fica na frente do mapa durante o trabalho — por isso
 * diz o passo em uma linha, e não repete o formulário.
 */
function pintarBarraCadastral() {
  const barra = document.getElementById('cad-barra')
  if (!barra) { return }

  barra.hidden = !cadModo
  if (!cadModo) { return }

  const n = selState.ids.size
  const emAto = atoState.tipo === 'unificacao'
  const desenhando = typeof estaDesenhando === 'function' && estaDesenhando()

  const modo = document.getElementById('cad-barra-modo')
  const passo = document.getElementById('cad-barra-passo')
  const ok = document.getElementById('cad-barra-ok')
  const extra = document.getElementById('cad-barra-extra')

  ok.hidden = true
  extra.hidden = true

  if (cadModo === 'quadra') {
    modo.textContent = emAto ? 'Unificação' : 'Corrigir quadra'
    passo.textContent = n === 0
      ? 'Toque nos lotes do mapa para marcá-los.'
      : `${n} lote(s) marcado(s).`
    if (n > 0) {
      ok.hidden = false
      ok.textContent = emAto ? 'Conferir unificação' : 'Informar a quadra'
      ok.onclick = abrirModalCad
      extra.hidden = false
      extra.textContent = 'Limpar marcação'
      extra.onclick = limparSelecaoCadastral
    }
  } else if (cadModo === 'desenho') {
    modo.textContent = 'Desenhar lote'
    passo.textContent = desenhando
      ? 'Toque nos cantos do lote. Duplo toque fecha.'
      : desenhoPendente ? 'Desenho pronto.' : 'Toque no mapa para começar.'
    if (desenhoPendente) {
      ok.hidden = false
      ok.textContent = 'Informar os dados'
      ok.onclick = abrirModalCad
    }
  } else if (cadModo === 'coordenadas') {
    modo.textContent = 'Lote por coordenadas'
    passo.textContent = desenhoPendente
      ? 'Polígono lido do memorial, desenhado no mapa.'
      : 'Cole o memorial na janela.'
    ok.hidden = false
    ok.textContent = desenhoPendente ? 'Informar os dados' : 'Abrir a janela'
    ok.onclick = abrirModalCad
  }
}

/**
 * Mantém a janela e a barra coerentes com o estado.
 *
 * Continua se chamando `pintarPainelCadastro` porque é chamada de vários
 * pontos (seleção, desenho, atos cadastrais); o que mudou foi ONDE ela pinta.
 */
function pintarPainelCadastro() {
  pintarBarraCadastral()

  const cont = document.getElementById('cadp-quadra')
  if (!cont) { return }

  const n = selState.ids.size

  // Em ato cadastral o assunto é outro: não é corrigir quadra, é executar a
  // unificação daquele protocolo. Trocar o texto e o botão evita que a pessoa
  // preencha a quadra achando que é isso que vai acontecer.
  const emDesm = atoState.tipo === 'desmembramento'
  const grupo = document.getElementById('cad-geral')
  if (grupo) { grupo.hidden = emDesm }

  const emAto = atoState.tipo === 'unificacao'
  const cabecalho = document.getElementById('cad-ato')
  if (cabecalho) {
    cabecalho.hidden = !emAto
    if (emAto) {
      cabecalho.innerHTML = '<div class="cad-nota">Unificando pelo protocolo. '
        + 'Toque nos lotes; eles precisam se encostar e ser da mesma quadra.</div>'
    }
  }

  const contagem = document.getElementById('cad-contagem')
  if (contagem) {
    contagem.textContent = n === 0
      ? 'Nenhum lote marcado ainda — toque neles no mapa.'
      : `${n} lote(s) marcado(s).`
  }

  document.getElementById('cad-acoes').hidden = n === 0
  document.getElementById('cad-quadra-campo').hidden = emAto
  document.getElementById('cad-btn-conferir').setAttribute('onclick',
    emAto ? 'conferirUnificacao()' : 'conferirQuadraSelecao()')
  document.getElementById('cad-btn-limpar').setAttribute('onclick',
    emAto ? 'cancelarAtoCadastral()' : 'limparSelecaoCadastral()')
  document.getElementById('cad-btn-limpar').textContent = emAto ? 'Cancelar ato' : 'Limpar'

  // ── bloco de desenho ──
  const desenhando = typeof estaDesenhando === 'function' && estaDesenhando()
  document.getElementById('des-desenhando').hidden = !desenhando
  document.getElementById('des-dados').hidden = !desenhoPendente
  if (!desenhoPendente) { document.getElementById('des-previa').innerHTML = '' }
}

/**
 * Pede a prévia ao servidor e mostra o que vai acontecer ANTES de gravar.
 *
 * A prévia não é cortesia: esta operação sobrescreve quadra que já estava
 * preenchida, e a única defesa contra o clique errado é a pessoa ver a lista
 * de "de → para" antes de confirmar.
 */
async function conferirQuadraSelecao() {
  const quadra = (document.getElementById('cad-quadra')?.value || '').trim()
  if (!quadra) { exigirCampo('cad-quadra', 'Informe a quadra a gravar.'); return }

  const alvo = document.getElementById('cad-previa')
  alvo.innerHTML = '<div class="cad-nota">Conferindo…</div>'

  try {
    const d = await postCadastro('/api/lotes/quadra-em-massa/previa',
      { ids: [...selState.ids], quadra })
    if (!d) { alvo.innerHTML = ''; return }

    if (d.impedimento) {
      alvo.innerHTML = `<div class="cad-nota cad-erro">${esc(d.impedimento)}</div>`
      return
    }

    const r = d.retrato
    const avisos = d.avisos.map(a => `<div class="cad-nota cad-aviso">${esc(a)}</div>`).join('')
    const dePara = r.de_para.length
      ? `<div class="cad-lista">${r.de_para.map(x =>
          `<span>Lote ${esc(x.lote)} · Q${esc(x.de)} → <b>Q${esc(x.para)}</b></span>`).join('')}</div>`
      : ''
    const resto = r.sobrescreve > r.de_para.length
      ? `<div class="cad-nota">… e mais ${r.sobrescreve - r.de_para.length}.</div>` : ''

    alvo.innerHTML = `
      <div class="cad-nota"><b>${r.mudam}</b> lote(s) passam para a quadra <b>${esc(r.quadra)}</b>
        em ${esc(r.bairro || '—')}${r.ja_estao ? `; ${r.ja_estao} já estão lá` : ''}.</div>
      ${r.sobrescreve ? `<div class="cad-nota cad-aviso"><b>${r.sobrescreve}</b> lote(s) já tinham
        quadra e serão SOBRESCRITOS (vindos de ${r.origens.map(esc).join(', ')}).</div>` : ''}
      ${dePara}${resto}${avisos}
      <button class="btn primary" onclick="gravarQuadraSelecao()">Gravar quadra ${esc(r.quadra)}</button>`
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="cad-nota cad-erro">Não foi possível conferir.</div>'
  }
}

async function gravarQuadraSelecao() {
  const quadra = (document.getElementById('cad-quadra')?.value || '').trim()

  confirmarAcao({
    titulo: 'Gravar quadra',
    mensagem: `Vai alterar a identificação de ${selState.ids.size} imóvel(is). `
      + 'A identificação é a chave de integração com o cadastro imobiliário, '
      + 'e a alteração fica registrada na auditoria com o seu nome.',
    textoBtn: 'Gravar',
    onConfirm: async () => {
      const d = await postCadastro('/api/lotes/quadra-em-massa', { ids: [...selState.ids], quadra })
      if (!d) return

      toast(d.message)
      // Os lotes em memória seguram a quadra antiga; sem atualizá-los, o balão
      // e a ficha mostrariam o valor de antes até a próxima recarga por bbox.
      selState.ids.forEach(id => {
        const f = state.lotes.get(id)
        if (f) { f.properties.quadra = d.quadra; f.properties.chave = null }
      })
      limparSelecaoCadastral()
      document.getElementById('cad-quadra').value = ''
    },
  })
}

// ── ATO CADASTRAL (unificação e desmembramento) ──────────────
//
// Chamado do histórico do imóvel, a partir de uma vistoria REGULAR amarrada a
// um protocolo de desmembramento/unificação DEFERIDO. O portão é a vistoria, e
// não o protocolo direto, porque o ato altera o terreno: o deferimento diz que
// o pedido procede no papel, a vistoria diz que o papel bate com o chão.

/** Protocolo e tipo do ato em execução. */
const atoState = { protocoloId: null, tipo: null }

/**
 * @param {number} protocoloId
 * @param {'unificacao'|'desmembramento'} tipo
 * @param {number|null} loteId lote de origem, no desmembramento
 */
function iniciarAtoCadastral(protocoloId, tipo, loteId = null) {
  atoState.protocoloId = protocoloId
  atoState.tipo = tipo

  // Fecha a ficha e leva ao mapa: o ato é geométrico, e a escolha acontece
  // sobre o desenho, não numa lista.
  fModalBtn('m-ficha')
  irPara('mapa')

  setTimeout(() => {
    fecharPaineisMapa()
    alternarPainelMapa('grupo-cadastro')

    if (tipo === 'unificacao') {
      ligarSelecao('unificacao')
      toast('Toque nos lotes a unificar. Eles precisam se encostar.', 'aviso')
    } else {
      // O lote a desmembrar é o que a ficha estava mostrando: quem chegou aqui
      // veio da vistoria DELE, então perguntar de novo seria atrito.
      desmState.loteId = loteId || state.selecionado?.properties?.id || null
      desmState.partes = []
      desmState.derivar = true
  desmState.modo = 'poligonos'
      toast('Desenhe cada parte. A última sai do que sobrar.', 'aviso')
    }
    pintarPainelCadastro()
    pintarDesmembramento()
  }, 260)
}

/** Larga o ato em andamento sem executá-lo. */
function cancelarAtoCadastral() {
  atoState.protocoloId = null
  atoState.tipo = null
  if (selState.ativa) { desligarSelecao() }
  largarDesenho()
  pintarPainelCadastro()
}

/** Prévia e execução da unificação. */
async function conferirUnificacao() {
  const alvo = document.getElementById('cad-previa')
  alvo.innerHTML = '<div class="cad-nota">Conferindo…</div>'

  const d = await postCadastro(`/api/protocolos/${atoState.protocoloId}/unificacao/previa`,
    { ids: [...selState.ids] })
  if (!d) { alvo.innerHTML = ''; return }

  if (d.impedimento) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">${esc(d.impedimento)}</div>`
    return
  }

  const r = d.retrato
  const avisos = d.avisos.map(a => `<div class="cad-nota cad-aviso">${esc(a)}</div>`).join('')

  alvo.innerHTML = `
    <div class="cad-nota"><b>${r.lotes.length}</b> lotes da quadra ${esc(r.quadra)} viram um só,
      com <b>${fmtNum(r.area_uniao)} m²</b> (somavam ${fmtNum(r.soma_area)}).</div>
    <div class="cad-lista">${r.lotes.map(l =>
      `<span>Lote ${esc(l.lote)} — ${fmtNum(l.area)} m²</span>`).join('')}</div>
    ${avisos}
    <div class="field" style="margin:8px 0 6px">
      <label for="cad-lote-novo">Número do lote resultante</label>
      <input type="text" id="cad-lote-novo" class="mono" maxlength="20" value="${esc(r.sugestao_lote || '')}">
    </div>
    <button class="btn primary" onclick="gravarUnificacao()">Unificar</button>`
}

async function gravarUnificacao() {
  const numero = (document.getElementById('cad-lote-novo')?.value || '').trim()
  if (!numero) { exigirCampo('cad-lote-novo', 'Informe o número do lote resultante.'); return }

  confirmarAcao({
    titulo: 'Unificar lotes',
    mensagem: `${selState.ids.size} imóveis deixam de existir e um novo passa a existir. `
      + 'Os antigos não são apagados: ficam baixados, apontando para o sucessor, e '
      + 'vistorias, obras e documentos continuam neles.',
    textoBtn: 'Unificar',
    onConfirm: async () => {
      const d = await postCadastro(`/api/protocolos/${atoState.protocoloId}/unificacao`,
        { ids: [...selState.ids], numero_lote: numero })
      if (!d) { return }

      toast(d.message)
      cancelarAtoCadastral()
      desenhados.clear()
      carregarLotesVisiveis()
    },
  })
}

// ── DESMEMBRAMENTO ───────────────────────────────────────────
//
// O operador desenha as partes uma a uma. A ÚLTIMA não se desenha: ela é o que
// sobra do lote, derivada no servidor — assim a soma fecha exata, em vez de
// depender de encaixar N polígonos com precisão de centímetro.

/** @type {{loteId:number|null, partes:Array<Object>, derivar:boolean}} */
const desmState = { loteId: null, partes: [], derivar: true, modo: 'poligonos' }

/** Começa a desenhar mais uma parte. */
function desenharParte() {
  if (!desmState.loteId) {
    // O lote de origem é o que estiver selecionado no mapa quando o ato começa.
    const f = state.selecionado
    if (!f) { toast('Selecione no mapa o lote a desmembrar.', 'err'); return }
    desmState.loteId = f.properties?.id ?? f.id
  }

  iniciarDesenho({
    modo: 'poligono',
    snap: true,
    onConcluir: g => {
      desmState.partes.push({ geometry: g, numero_lote: '', desmembramento: null })
      pintarDesmembramento()
    },
    onCancelar: pintarDesmembramento,
  })
  pintarDesmembramento()
}

/**
 * Divide o lote tracando uma linha que o atravessa.
 *
 * E a forma natural de partir um terreno, e e como o desenhista faz no DWG. O
 * resultado entra na MESMA lista de partes do desenho livre — o servidor nao
 * sabe, nem precisa saber, por qual caminho elas vieram. Consequencia: um erro
 * no algoritmo de corte e pego pelas provas de cobertura e sobreposicao do
 * desmembramento, em vez de virar base torta.
 */
function cortarLote() {
  const id = desmState.loteId || state.selecionado?.properties?.id
  if (!id) { toast('Selecione no mapa o lote a cortar.', 'err'); return }

  const feicao = state.lotes.get(id)
  const anel = feicao?.geometry?.coordinates?.[0]
  if (!anel) {
    toast('O lote precisa estar visivel no mapa para ser cortado.', 'err')
    return
  }
  if ((feicao.geometry.coordinates.length ?? 1) > 1) {
    toast('Este lote tem um vazio interno; o corte por linha nao trata esse caso.', 'err')
    return
  }

  desmState.loteId = id

  iniciarDesenho({
    modo: 'linha',
    snap: true,
    onConcluir: g => {
      const r = cortarPorLinha(anel, g.coordinates)
      if (r.erro) { toast(r.erro, 'err'); pintarDesmembramento(); return }

      // O corte SUBSTITUI as partes: misturar com o que ja havia sido
      // desenhado produziria sobreposicao na certa.
      desmState.partes = [
        { geometry: r.a, numero_lote: '', desmembramento: null },
        { geometry: r.b, numero_lote: '', desmembramento: null },
      ]
      // As duas partes vem prontas: nao ha resto a derivar.
      desmState.derivar = false
      desmState.modo = 'corte'
      toast('Lote cortado em duas partes. Informe o numero de cada uma.')
      pintarDesmembramento()
    },
    onCancelar: pintarDesmembramento,
  })
  pintarDesmembramento()
}

/** @param {number} i */
function removerParte(i) {
  desmState.partes.splice(i, 1)
  pintarDesmembramento()
}

function largarDesmembramento() {
  desmState.loteId = null
  desmState.partes = []
  desmState.derivar = true
  desmState.modo = 'poligonos'
  cancelarDesenho()
  atoState.protocoloId = null
  atoState.tipo = null
  pintarPainelCadastro()
  // Repintar o proprio painel tambem: sem isto ele continuaria na tela depois
  // de o ato ser largado, oferecendo desenhar partes de um desmembramento que
  // ja nao existe.
  pintarDesmembramento()
}

function pintarDesmembramento() {
  const cx = document.getElementById('desm-caixa')
  if (!cx) { return }

  const emAto = atoState.tipo === 'desmembramento'
  cx.hidden = !emAto
  if (!emAto) { return }

  const n = desmState.partes.length
  // Com derivação, a última parte não é desenhada: N desenhos viram N+1 lotes.
  const total = desmState.derivar ? n + 1 : n

  const linhas = desmState.partes.map((p, i) => `
    <div class="desm-parte">
      <span class="desm-num">${i + 1}</span>
      <input type="text" class="mono" placeholder="Lote" maxlength="20"
             value="${esc(p.numero_lote)}" oninput="desmState.partes[${i}].numero_lote=this.value">
      <input type="text" class="mono" placeholder="Sufixo" inputmode="numeric" maxlength="3"
             value="${p.desmembramento ?? ''}" oninput="desmState.partes[${i}].desmembramento=this.value">
      <button type="button" class="desm-x" onclick="removerParte(${i})" title="Remover">&#10005;</button>
    </div>`).join('')

  const derivada = desmState.derivar ? `
    <div class="desm-parte desm-derivada">
      <span class="desm-num">${n + 1}</span>
      <input type="text" class="mono" placeholder="Lote" maxlength="20" id="desm-lote-resto">
      <input type="text" class="mono" placeholder="Sufixo" inputmode="numeric" maxlength="3" id="desm-suf-resto">
      <span class="desm-tag">resto</span>
    </div>` : ''

  cx.innerHTML = `
    <div class="cad-nota">Desmembrando pelo protocolo. Desenhe cada parte;
      ${desmState.derivar ? 'a última sai do que sobrar do lote.' : 'todas as partes são desenhadas.'}</div>
    <label class="ctrl-chk" style="margin:6px 0">
      <input type="checkbox" ${desmState.derivar ? 'checked' : ''}
             onchange="desmState.derivar=this.checked; pintarDesmembramento()">
      Derivar a última parte do que sobrar
    </label>
    <div class="leg">${n} parte(s) desenhada(s) · ${total} lote(s) no fim.</div>
    ${linhas}${derivada}
    <div class="seg" style="margin:8px 0 0">
      <button type="button" onclick="largarDesmembramento()">Cancelar</button>
      <button type="button" onclick="cortarLote()">Cortar por linha</button>
      <button type="button" onclick="desenharParte()">Desenhar parte</button>
      <button type="button" onclick="conferirDesmembramento()">Conferir</button>
    </div>
    <div id="desm-previa"></div>`
}

/** Monta o corpo que o servidor espera. */
function _corpoDesmembramento() {
  const partes = desmState.partes.map(p => ({
    geometry: p.geometry,
    numero_lote: (p.numero_lote || '').trim(),
    desmembramento: p.desmembramento ? Number(p.desmembramento) : null,
  }))

  // O número da parte derivada viaja junto da ÚLTIMA desenhada: ela é a única
  // que o operador não desenha, e criar um item sem geometria confundiria a
  // contagem de partes no servidor.
  if (desmState.derivar && partes.length) {
    partes[partes.length - 1].numero_lote_derivada =
      (document.getElementById('desm-lote-resto')?.value || '').trim()
    const suf = document.getElementById('desm-suf-resto')?.value
    partes[partes.length - 1].desmembramento_derivada = suf ? Number(suf) : null
  }

  return {
    lote_id: desmState.loteId,
    derivar_ultima: desmState.derivar,
    modo: desmState.modo || 'poligonos',
    partes,
  }
}

async function conferirDesmembramento() {
  if (!desmState.partes.length) { toast('Desenhe ao menos uma parte.', 'err'); return }

  const alvo = document.getElementById('desm-previa')
  alvo.innerHTML = '<div class="cad-nota">Conferindo…</div>'

  const d = await postCadastro(`/api/protocolos/${atoState.protocoloId}/desmembramento/previa`,
    _corpoDesmembramento())
  if (!d) { alvo.innerHTML = ''; return }

  if (d.impedimento) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">${esc(d.impedimento)}</div>`
    return
  }

  const r = d.retrato
  const avisos = d.avisos.map(a => `<div class="cad-nota cad-aviso">${esc(a)}</div>`).join('')
  const lista = r.partes.map((p, i) =>
    `<span>${i + 1}. Lote ${esc(p.numero_lote || '—')} — ${fmtNum(p.area)} m²${p.derivada ? ' (resto)' : ''}</span>`
  ).join('')

  alvo.innerHTML = `
    <div class="cad-nota">O lote de <b>${fmtNum(r.area_pai)} m²</b> vira
      <b>${r.partes.length}</b> lotes. Soma: ${fmtNum(r.soma)} m²
      ${Math.abs(r.sobra) < 0.005 ? '(fecha exato)' : `(diferença de ${fmtNum(Math.abs(r.sobra))} m²)`}.</div>
    <div class="cad-lista">${lista}</div>
    ${avisos}
    <button class="btn primary" onclick="gravarDesmembramento()">Desmembrar</button>`
}

async function gravarDesmembramento() {
  confirmarAcao({
    titulo: 'Desmembrar lote',
    mensagem: 'O lote de origem deixa de existir e as partes passam a existir. '
      + 'Ele não é apagado: fica baixado, apontando para as partes, e vistorias, '
      + 'obras e documentos continuam nele.',
    textoBtn: 'Desmembrar',
    onConfirm: async () => {
      const d = await postCadastro(`/api/protocolos/${atoState.protocoloId}/desmembramento`,
        _corpoDesmembramento())
      if (!d) { return }

      toast(d.message)
      largarDesmembramento()
      desenhados.clear()
      carregarLotesVisiveis()
    },
  })
}

// ── DESENHAR LOTE FALTANTE ───────────────────────────────────

/** Geometria do último desenho concluído, à espera dos dados do lote. */
let desenhoPendente = null

function iniciarDesenhoDeLote() {
  // Seleção e desenho disputariam o mesmo clique.
  if (selState.ativa) { desligarSelecao() }

  iniciarDesenho({
    modo: 'poligono',
    snap: true,
    onConcluir: g => {
      desenhoPendente = g
      pintarPainelCadastro()
      // O bairro sugerido é o do lote selecionado, quando há um: quem desenha
      // acabou de olhar a vizinhança, e redigitar o bairro é atrito puro.
      const f = state.selecionado
      if (f && !document.getElementById('des-bairro').value) {
        document.getElementById('des-bairro').value = f.bairro || f.properties?.bairro || ''
      }
      document.getElementById('des-quadra')?.focus()
    },
    onCancelar: () => { desenhoPendente = null; pintarPainelCadastro() },
  })

  pintarPainelCadastro()
}

// ── LOTE POR COORDENADAS ─────────────────────────────────────

/** Camada da prévia. Fica fora do mapaState porque é efêmera. */
let previaCoordenadas = null

/**
 * Abre a caixa do memorial. Chamada por modoCadastral('coordenadas'); mantida
 * separada porque o foco no campo só faz sentido depois de a janela aparecer.
 */
function abrirCoordenadas() {
  document.getElementById('coo-caixa').hidden = false
  document.getElementById('coo-texto').focus()
}

/** Limpa o memorial e a prévia, sem sair do modo. */
function largarCoordenadas() {
  document.getElementById('coo-resultado').innerHTML = ''
  document.getElementById('coo-texto').value = ''
  limparPreviaCoordenadas()
  desenhoPendente = null
  pintarPainelCadastro()
}

function limparPreviaCoordenadas() {
  if (previaCoordenadas) {
    mapaState.obj.removeLayer(previaCoordenadas)
    previaCoordenadas = null
  }
}

/**
 * Lê o texto, desenha a prévia e entrega o polígono ao MESMO fluxo do desenho
 * livre — conferir no servidor, ver a área, criar o lote.
 *
 * Reaproveitar o fluxo não é economia de código: é o que garante que o lote
 * criado por coordenada passe pelas mesmas provas de sobreposição e cobertura
 * que o desenhado à mão. Um segundo caminho de escrita seria um segundo
 * conjunto de regras para manter em pé.
 */
function lerCoordenadas() {
  const alvo = document.getElementById('coo-resultado')
  const { vertices, erros } = lerCoordenadasGMS(document.getElementById('coo-texto').value)

  if (erros.length) {
    alvo.innerHTML = erros.map(e => `<div class="cad-nota cad-erro">${esc(e)}</div>`).join('')
    limparPreviaCoordenadas()
    desenhoPendente = null
    pintarPainelCadastro()
    return
  }

  const p = poligonoDeCoordenadas(vertices)
  if (p.erro) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">${esc(p.erro)}</div>`
    return
  }

  desenhoPendente = p.geometry

  limparPreviaCoordenadas()
  const anel = p.geometry.coordinates[0].map(c => [c[1], c[0]])   // GeoJSON -> Leaflet
  previaCoordenadas = L.polygon(anel, {
    color: '#F97316', weight: 3, fillColor: '#F97316', fillOpacity: .25, dashArray: '6 4',
  }).addTo(mapaState.obj)
  mapaState.obj.fitBounds(previaCoordenadas.getBounds(), { padding: [40, 40] })

  alvo.innerHTML = `<div class="cad-nota">${vertices.length} vértice(s) lidos`
    + `${p.fechado ? ', perímetro já fechado no texto' : ''}. `
    + 'Confira o desenho no mapa e preencha os dados abaixo.</div>'

  // O bairro do lote selecionado poupa digitação, como no desenho livre.
  const f = state.selecionado
  if (f && !document.getElementById('des-bairro').value) {
    document.getElementById('des-bairro').value = f.bairro || f.properties?.bairro || ''
  }
  pintarPainelCadastro()
  document.getElementById('des-quadra')?.focus()
}

/** Chamado por desenho.js a cada vértice — mantém a contagem na tela. */
function aoDesenharVertice(n) {
  const el = document.getElementById('des-contagem')
  if (!el) { return }
  el.textContent = n === 0
    ? 'Toque nos cantos do lote. Duplo toque fecha.'
    : `${n} canto(s). Duplo toque fecha; Ctrl+Z desfaz.`
  const acoes = document.getElementById('des-desenhando')
  if (acoes) { acoes.hidden = !estaDesenhando() }
}

function largarDesenho() {
  desenhoPendente = null
  cancelarDesenho()
  limparPreviaCoordenadas()
  pintarPainelCadastro()
}

/** Dados do lote + prévia do servidor. */
async function conferirDesenho() {
  const corpo = _corpoDesenho()
  if (!corpo) { return }

  const alvo = document.getElementById('des-previa')
  alvo.innerHTML = '<div class="cad-nota">Conferindo…</div>'

  const d = await postCadastro('/api/lotes/previa', corpo)
  if (!d) { alvo.innerHTML = ''; return }

  if (d.impedimento) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">${esc(d.impedimento)}</div>`
    return
  }

  const r = d.retrato
  const avisos = d.avisos.map(a => `<div class="cad-nota cad-aviso">${esc(a)}</div>`).join('')
  const encosta = r.vizinhos.filter(v => v.area_comum === 0).length

  alvo.innerHTML = `
    <div class="cad-nota">Lote de <b>${fmtNum(r.area_m2)} m²</b> com ${r.vertices} canto(s),
      em ${esc(r.bairro)} · quadra ${esc(r.quadra)} · lote ${esc(r.lote)}.
      ${encosta ? `Encosta em ${encosta} lote(s) vizinho(s).` : ''}</div>
    ${avisos}
    <button class="btn primary" onclick="gravarDesenho()">Criar lote</button>`
}

async function gravarDesenho() {
  const corpo = _corpoDesenho()
  if (!corpo) { return }

  confirmarAcao({
    titulo: 'Criar lote',
    mensagem: 'Vai inserir um imóvel novo no cadastro, com a geometria desenhada. '
      + 'A criação fica registrada na auditoria com o seu nome, e o lote passa a '
      + 'ser protegido contra sobrescrita por reimportação do DWG.',
    textoBtn: 'Criar',
    onConfirm: async () => {
      const d = await postCadastro('/api/lotes', corpo)
      if (!d) { return }

      toast(d.message)
      largarDesenho()
      largarCoordenadas()
      document.getElementById('des-quadra').value = ''
      document.getElementById('des-lote').value = ''
      // O lote novo só existe no servidor: forçar a recarga do bbox é o que o
      // traz para o mapa sem o usuário ter de arrastar a tela.
      desenhados.clear()
      carregarLotesVisiveis()
    },
  })
}

/** @returns {Object|null} */
function _corpoDesenho() {
  if (!desenhoPendente) { toast('Desenhe o lote primeiro.', 'err'); return null }

  const bairro = (document.getElementById('des-bairro')?.value || '').trim()
  const quadra = (document.getElementById('des-quadra')?.value || '').trim()
  const lote   = (document.getElementById('des-lote')?.value || '').trim()

  if (!bairro) { exigirCampo('des-bairro', 'Informe o bairro do lote.'); return null }
  if (!quadra) { exigirCampo('des-quadra', 'Informe a quadra.'); return null }
  if (!lote)   { exigirCampo('des-lote', 'Informe o número do lote.'); return null }

  return { bairro, quadra, numero_lote: lote, geometry: desenhoPendente }
}

/**
 * POST com CSRF e tratamento de 419/422, no idioma já usado no resto do
 * sistema. Devolve null quando já avisou o usuário.
 *
 * @param {string} url @param {Object} corpo
 */
async function postCadastro(url, corpo) {
  const r = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    },
    body: JSON.stringify(corpo),
  })

  if (r.status === 419) {
    toast('Sessão expirada. Recarregando...', 'err')
    setTimeout(() => location.reload(), 1500)
    return null
  }

  const d = await r.json()
  if (!r.ok) {
    toast(d.message || 'Não foi possível concluir.', 'err', { campo: 'cad-quadra' })
    return null
  }
  return d
}
