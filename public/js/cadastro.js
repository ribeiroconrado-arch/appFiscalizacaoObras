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
  /**
   * `livre` é a marcação SEM ferramenta escolhida, e é o que inverte a ordem
   * do trabalho na mesa: primeiro se marca no mapa, e a régua acende só as
   * ferramentas que aquela marcação comporta. Antes era o contrário —
   * escolher a ferramenta ligava a marcação —, e a exigência de cada uma só
   * aparecia na recusa depois do trabalho todo feito.
   *
   * @type {'livre'|'quadra'|'apagar'|null}
   */
  modo: null,
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

/**
 * Garante o painel de cadastro ABERTO, seja qual for a largura da tela.
 *
 * Existe porque `alternarPainelMapa` alterna, e quem começa um ato precisa que
 * ele esteja aberto — não do contrário do que estava.
 */
function garantirPainelCadastroAberto() {
  if (ehMesaCadastral()) {
    const mesa = document.getElementById('cad-mesa')
    if (mesa?.hidden) { abrirMesaCadastral() }
    return
  }
  const g = document.getElementById('grupo-cadastro')
  if (g && !g.classList.contains('aberto')) { alternarPainelMapa('grupo-cadastro') }
}

/**
 * NA MESA, A MARCAÇÃO É DO OPERADOR — NÃO DA FERRAMENTA.
 *
 * Ela existe ANTES de a ferramenta ser escolhida: foi ela que decidiu quais
 * ferramentas estavam disponíveis. Limpá-la ao entrar obrigaria a marcar tudo
 * de novo, que é o mesmo trabalho duas vezes com a chance de a segunda sair
 * diferente da primeira.
 *
 * Por isso a limpeza automática vale só FORA da mesa — no celular, onde não há
 * marcação livre e o conjunto está sempre vazio na entrada, de modo que
 * preservá-lo não mudaria nada de qualquer jeito. Na mesa, a marcação sai por
 * ato explícito: Limpar, Esc, duplo clique fora, ou o fim do trabalho.
 */
function marcacaoEhDoOperador() {
  const mesa = document.getElementById('cad-mesa')
  return ehMesaCadastral() && !!mesa && !mesa.hidden
}

function desligarSelecao() {
  selState.ativa = false
  selState.modo = null
  limparSelecaoCadastral()
  pintarPainelCadastro()
}

/**
 * Esc e duplo clique fora: soltam as MARCAS, e não a marcação.
 *
 * Na mesa, desarmar devolveria o clique ao balão do lote — e a régua ficaria
 * sem como acender, porque não haveria mais o que marcar. Fora da mesa não há
 * modo livre, e o comportamento antigo continua valendo inteiro.
 */
function escaparSelecao() {
  if (selState.modo === 'livre') { limparSelecaoCadastral(); return }
  desligarSelecao()
}

/**
 * Volta ao estado de "mesa aberta, nenhuma ferramenta": marcação ligada e
 * livre. É para onde se cai ao largar uma ferramenta sem fechar a mesa.
 */
function selecaoLivreNaMesa() {
  if (!ehMesaCadastral()) { return }
  const mesa = document.getElementById('cad-mesa')
  if (!mesa || mesa.hidden) { return }
  selState.ativa = true
  selState.modo = 'livre'
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
  } else if (modo === 'apagar') {
    ligarSelecao('apagar')
  } else if (modo === 'desenho') {
    iniciarDesenhoDeLote()
  } else if (modo === 'coordenadas') {
    document.getElementById('coo-caixa').hidden = false
    abrirModalCad()
  }

  // EM TELA GRANDE A MESA ABRE JUNTO, e não ao fim do trabalho.
  //
  // Em tela pequena a janela de dados só aparece no fim, porque enquanto se
  // desenha ela cobriria o mapa. Na mesa não cobre nada — e é justamente
  // durante o desenho que os controles importam: a trava de esquadro e o lado
  // digitado não servem para nada depois que o polígono já está fechado.
  if (ehMesaCadastral()) { abrirModalCad() }

  pintarPainelCadastro()
}

/** Sai do modo e limpa o que estava em curso. @param {boolean} [silencioso] */
function sairModoCadastral(silencioso) {
  if (!marcacaoEhDoOperador()) {
    if (selState.ativa) { desligarSelecao() }
    limparSelecaoCadastral()
  }
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

  // Largou a ferramenta e a mesa continua aberta: volta para a marcação livre,
  // senão o clique no mapa passaria a abrir o balão e a régua nunca mais
  // acenderia sem reabrir a mesa.
  selecaoLivreNaMesa()

  if (!silencioso) {
    fModalBtn('m-cad')
    // A MESA CONTINUA ABERTA, mostrando o menu.
    //
    // Fechá-la aqui fazia o "← Ferramentas" apagar a coluna inteira em vez de
    // voltar um passo — quem largava uma ferramenta tinha de reabrir a mesa
    // pelo ícone para escolher a próxima, que é o caminho mais longo possível
    // entre duas ferramentas vizinhas.
    pintarPainelCadastro()
    pintarMesaCadastral()
  }
}

// ── A MESA DE EDIÇÃO (tela grande) ───────────────────────────
//
// Acima de 1000px o lançador e o formulário SAEM de cima do mapa e vão para uma
// coluna fixa à esquerda. Não há segunda cópia do formulário: os mesmos
// elementos são MOVIDOS de um lugar para o outro. Duplicar a marcação criaria
// dois campos com o mesmo id, e `getElementById` passaria a devolver um dos
// dois sem aviso — o que a tela lê deixaria de ser o que o operador digitou,
// e o erro só apareceria no lote gravado errado.

/** A mesa é para tela de mesa. No celular, nada disto existe. */
function ehMesaCadastral() {
  return typeof ehTelaLarga === 'function' && ehTelaLarga()
}

/**
 * Põe cada peça no lugar que a largura da tela pede.
 *
 * Idempotente de propósito: `appendChild` de um nó que já está ali não faz
 * nada, então pode ser chamada em toda troca de modo, em todo redimensionamento
 * e na abertura — sem precisar saber onde as peças estavam antes.
 *
 * @param {boolean} [abrir] abre ou fecha a mesa; omitido, mantém como está
 */
function montarMesaCadastral(abrir) {
  const mesa = document.getElementById('cad-mesa')
  const geral = document.getElementById('cad-geral')
  const corpo = document.getElementById('cad-corpo')
  if (!mesa || !corpo) { return }

  if (ehMesaCadastral()) {
    // O LANÇADOR NÃO VIAJA MAIS PARA A MESA. Quem mostra as ferramentas aqui é
    // a régua, montada a partir dele — então ele fica onde sempre esteve, no
    // painel flutuante, que a mesa esconde por CSS. Movê-lo para cá deixava o
    // painel flutuante VAZIO quando alguém fechava a mesa: o ícone passava a
    // abrir uma caixa com título e nada dentro.
    montarReguaCadastral()
    document.getElementById('mesa-props').appendChild(corpo)
    if (abrir !== undefined) { mesa.hidden = !abrir }
    // A janela não tem mais corpo nenhum: se ficasse aberta, seria um retângulo
    // vazio por cima da mesa onde o trabalho está acontecendo.
    if (!mesa.hidden) { fModalBtn('m-cad') }
  } else {
    // De volta ao painel flutuante, na ordem original: `#desm-caixa` vem antes
    // do lançador, e o corpo volta para dentro da janela.
    const flutuante = document.querySelector('#grupo-cadastro .ctrl-corpo')
    if (geral && flutuante) { flutuante.appendChild(geral) }
    document.getElementById('cad-modal-corpo')?.appendChild(corpo)
    // Esvazia a régua E o carimbo de "já montada" JUNTO. Sem apagar o carimbo,
    // voltar do celular para a tela grande encontrava a contagem batendo com a
    // do lançador, concluía que não havia o que fazer, e deixava a mesa com uma
    // régua vazia — sem nenhuma ferramenta e sem erro nenhum no console.
    const r = document.getElementById('cad-regua')
    if (r) { r.replaceChildren(); delete r.dataset.pronta }
    mesa.hidden = true
  }

  document.body.classList.toggle('com-mesa', ehMesaCadastral() && !mesa.hidden)
  pintarMesaCadastral()
}

/**
 * A mesa mostra UMA coisa de cada vez: o menu OU a ferramenta.
 *
 * Antes mostrava as duas empilhadas, e era o defeito principal: escolhida a
 * ferramenta, os sete botões de lançamento continuavam ocupando a coluna
 * inteira e o trabalho de verdade — marcar lotes, digitar a quadra, conferir —
 * ficava espremido no rodapé, fora da vista em qualquer notebook de 768px.
 *
 * Escolher uma ferramenta recolhe o menu; o "← Ferramentas" no topo traz ele
 * de volta. É a mesma navegação que Parâmetros já usa em lei → artigos e em
 * ano → feriados.
 */
function pintarMesaCadastral() {
  const mesa = document.getElementById('cad-mesa')
  if (!mesa) { return }

  // O DESMEMBRAMENTO TEM MESA PRÓPRIA, e as duas ocupam o mesmo canto da tela.
  //
  // Com o ato em curso, esta aqui abria junto e VAZIA — título "Desmembrar
  // lote" e nada embaixo, porque o formulário do lote não é o formulário do
  // desmembramento. Quem começava o ato ficava com uma caixa branca inútil por
  // cima da mesa onde o trabalho de fato acontece, sem passo seguinte à vista:
  // a ferramenta parecia travada, e estava.
  if (atoState.tipo === 'desmembramento') {
    mesa.hidden = true
    document.body.classList.remove('com-mesa')
    return
  }

  if (mesa.hidden) { return }

  // A RÉGUA FICA; o que troca é o lado direito.
  //
  // Antes o menu e a ferramenta eram duas TELAS da mesma coluna: escolher uma
  // ferramenta apagava a lista, e trocar de ferramenta era voltar e escolher de
  // novo. Agora a lista está encostada na borda o tempo todo, e o lado direito
  // só carrega o que a ferramenta ativa pede — sem ferramenta, ele some e a
  // mesa encolhe para a largura da régua, devolvendo 270px ao mapa.
  const emFerramenta = !!(cadModo || atoState.tipo)
  const lanca = document.getElementById('mesa-lanca')
  const props = document.getElementById('mesa-props')
  if (lanca) { lanca.hidden = true }
  if (props) { props.hidden = !emFerramenta }
  pintarRegua()

  // ENQUANTO SE TRAÇA, O LADO DIREITO SAI DE CENA — MAS A RÉGUA FICA.
  //
  // Durante o desenho o formulário não tem o que dizer: quem conduz o passo é a
  // barra sobre o mapa, e os dados só fazem sentido com o contorno fechado.
  // Antes a mesa INTEIRA sumia, e junto com ela ia embora o único lugar de onde
  // se troca de ferramenta ou se larga o traçado sem procurar. Agora ela
  // encolhe para os 56px da régua: o mapa fica quase todo livre do mesmo jeito,
  // e a saída continua na tela.
  const tracando = typeof estaDesenhando === 'function' && estaDesenhando()
  mesa.classList.toggle('so-regua', !emFerramenta || tracando)

  // `com-mesa` diz que a mesa está na tela — é o que esconde o painel
  // flutuante. `mesa-larga` diz que o LADO DIREITO está aberto, e é só isso
  // que a barra de desenho precisa saber para se deslocar: com a mesa
  // encolhida, deslocar 170px a jogaria para fora do centro sem motivo.
  document.body.classList.add('com-mesa')
  document.body.classList.toggle('mesa-larga', emFerramenta && !tracando)

  // O painel do modo corrente, e só ele. Sem isto, sair de "corrigir quadra"
  // para "desenhar lote" deixava os dois formulários na tela.
  const quadra = document.getElementById('cadp-quadra')
  const desenho = document.getElementById('cadp-desenho')
  if (quadra && desenho && emFerramenta) {
    const ehQuadra = cadModo === 'quadra' || cadModo === 'apagar' || atoState.tipo === 'unificacao'
    quadra.hidden = !ehQuadra
    desenho.hidden = ehQuadra
  }

  const voltar = document.getElementById('cad-mesa-voltar')
  if (voltar) { voltar.hidden = !emFerramenta }

  const t = document.getElementById('cad-mesa-titulo')
  if (t) { t.textContent = emFerramenta ? tituloDaFerramenta() : 'Ferramentas do cadastro' }
}

// ── A RÉGUA ──────────────────────────────────────────────────
//
// Ícone só, encostado na borda. O texto de cada lançador não sumiu: virou a
// dica do ponteiro, com o atalho e a exigência de seleção junto — e é isso que
// paga os 270px que a coluna devolve ao mapa.
//
// Ela é MONTADA A PARTIR DE #cad-geral, e não de uma lista escrita aqui. Um
// catálogo em JavaScript seria uma segunda verdade: ferramenta nova precisaria
// ser lembrada em dois lugares, e a permissão de curador — que é @if no Blade,
// e por isso simplesmente não existe no DOM de quem não a tem — nunca chegaria
// a este arquivo. Lendo do lançador, quem não pode curar não vê os três botões
// de curadoria na régua pelo mesmo motivo que não os vê no painel.

/** Escapa para atributo/HTML. Local: `esc` de ui.js já faz isso, e é ele que uso. */
function montarReguaCadastral() {
  const regua = document.getElementById('cad-regua')
  const geral = document.getElementById('cad-geral')
  if (!regua || !geral) { return }

  // Idempotente: chamada em toda troca de modo e em todo redimensionamento.
  if (regua.dataset.pronta === String(geral.querySelectorAll('.cad-lanca').length)) { return }

  const grupos = []
  let atual = null
  for (const el of geral.children) {
    if (el.classList.contains('cad-sep')) { atual = { nome: el.textContent.trim(), itens: [] }; grupos.push(atual) }
    else if (el.classList.contains('cad-lanca') && atual) { atual.itens.push(el) }
  }

  let h = ''
  for (const g of grupos) {
    if (!g.itens.length) { continue }
    h += '<div class="cad-regua-gp">'
    for (const b of g.itens) {
      const nome = b.querySelector('.cad-lanca-txt')?.childNodes[0]?.textContent.trim() ?? ''
      const obs = b.querySelector('.cad-lanca-obs')?.textContent.trim() ?? ''
      const ico = b.querySelector('.cad-ico')?.outerHTML ?? ''
      h += `<button type="button" class="cad-fer${b.classList.contains('cad-lanca-perigo') ? ' perigo' : ''}"
              data-fer="${b.dataset.fer}" data-min="${b.dataset.min ?? 0}"
              data-max="${b.dataset.max ?? ''}" aria-label="${esc(nome)}">
          ${ico}
          <span class="cad-dica-fer"><b>${esc(nome)}</b><span>${esc(obs)}</span>
            <span class="exige">Precisa de: ${esc(b.dataset.exige || '—')}</span>
            <kbd>${esc(b.dataset.tecla || '')}</kbd></span>
        </button>`
    }
    h += '</div>'
  }
  // O contador e o fechar ficam NA RÉGUA porque ela é a única parte da mesa
  // que está sempre na tela: sem ferramenta ativa o lado direito some, e um
  // "fechar" que some junto deixa a mesa sem saída.
  h += '<div class="cad-regua-pe">'
    + '<button type="button" class="cad-selo-sel" id="cad-selo-sel"'
    + ' onclick="limparSelecaoCadastral()">0'
    + '<span class="cad-dica-fer"><b>Lotes marcados</b>'
    + '<span>Clique para desmarcar todos. Esc faz o mesmo.</span></span>'
    + '</button>'
    + '<button type="button" class="cad-fer cad-fechar" onclick="fecharMesaCadastral()"'
    + ' aria-label="Fechar a mesa">'
    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
    + ' stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'
    + '<span class="cad-dica-fer"><b>Fechar a mesa</b>'
    + '<span>O que estiver marcado ou desenhado continua no mapa.</span></span>'
    + '</button></div>'

  regua.innerHTML = h
  regua.dataset.pronta = String(geral.querySelectorAll('.cad-lanca').length)

  // `[data-fer]`, e não `.cad-fer` inteiro.
  //
  // O botão de fechar TAMBÉM é um `.cad-fer` — é o mesmo quadrado, com a mesma
  // aparência — e o seletor sem qualificação sobrescrevia o `onclick` dele por
  // `acionarFerramenta(undefined)`, que não acha lançador nenhum e retorna em
  // silêncio. O X ficava na tela, clicável, bonito, e não fechava a mesa. Sem
  // erro no console: só um botão que não faz nada.
  regua.querySelectorAll('.cad-fer[data-fer]').forEach(f => {
    f.onclick = () => acionarFerramenta(f.dataset.fer)
  })
  pintarRegua()
}

/**
 * Aciona a ferramenta pelo botão original.
 *
 * Passa pela confirmação de `voltarAsFerramentas` quando há trabalho em curso:
 * com o menu sempre à vista, trocar de ferramenta virou um clique — e um clique
 * que descarta 40 lotes marcados sem perguntar seria pior do que o menu que
 * obrigava a voltar.
 *
 * @param {string} id valor de data-fer
 */
function acionarFerramenta(id) {
  const alvo = document.querySelector(`#cad-geral .cad-lanca[data-fer="${id}"]`)
  if (!alvo) { return }

  // Clicar na ferramenta que já está ativa a larga — é o que o botão aceso
  // sugere, e evita ter de procurar a seta de sair.
  if (ferramentaAtiva() === id) { voltarAsFerramentas(); return }

  // Apagada não age. O botão já diz isso pela cor; o ATALHO não tem como
  // dizer, e sem esta linha "U" com um lote só entraria na unificação para ser
  // recusado três passos adiante.
  if (!cabeNaMarcacao(alvo)) {
    toast('Precisa de ' + (alvo.dataset.exige || 'outra marcação') + '.', 'aviso')
    return
  }

  // Troca LEVANDO a marcação: foi ela que acendeu esta ferramenta, e descartá-la
  // agora seria pedir o mesmo trabalho de novo — com a chance de a segunda
  // marcação sair diferente da primeira. Só o desenho em curso é perguntado,
  // porque esse sim se perde.
  const desenhando = desenhoPendente
    || (typeof estaDesenhando === 'function' && estaDesenhando())

  const entrar = () => alvo.click()

  if (!desenhando) { entrar(); return }

  confirmarAcao({
    titulo: 'Trocar de ferramenta',
    mensagem: 'O desenho em curso será descartado.',
    perigo: true,
    onConfirm: () => { sairModoCadastral(true); entrar() },
  })
}

/**
 * A marcação corrente comporta esta ferramenta?
 *
 * Lê `data-min`/`data-max` do próprio lançador — a mesma exigência que a dica
 * escreve em português. Escrita em dois lugares, ela vira duas exigências
 * assim que alguém corrigir só uma.
 *
 * @param {HTMLElement} lanca @returns {boolean}
 */
function cabeNaMarcacao(lanca) {
  const n = selState.ids.size
  const min = Number(lanca.dataset.min ?? 0)
  const max = lanca.dataset.max ? Number(lanca.dataset.max) : Infinity
  return n >= min && n <= max
}

/**
 * Qual ferramenta está acesa.
 *
 * A edificação NÃO aparece aqui: ela não é um modo cadastral — não liga
 * seleção, não pede quadra, e termina numa pergunta só (ver
 * edificacoes.js). Enquanto ela não guardar estado, a régua não tem o que
 * acender, e inventar um estado só para a régua seria mentir sobre o sistema.
 *
 * @returns {string|null}
 */
function ferramentaAtiva() {
  if (atoState.tipo === 'unificacao') { return 'unificacao' }
  if (atoState.tipo === 'desmembramento') { return 'desmembramento' }
  return cadModo
}

/** Acende a ferramenta corrente e atualiza o contador de seleção. */
function pintarRegua() {
  const regua = document.getElementById('cad-regua')
  if (!regua || !regua.dataset.pronta) { return }

  const at = ferramentaAtiva()
  const n = selState.ids.size

  regua.querySelectorAll('.cad-fer[data-fer]').forEach(f => {
    f.classList.toggle('at', f.dataset.fer === at)

    // A EXIGÊNCIA APARECE ANTES DO CLIQUE, e não na recusa.
    //
    // Unificar nasce apagado e acende quando o segundo lote é marcado;
    // desmembrar apaga no mesmo instante, porque ele divide UM. A regra deixa
    // de ser um texto que alguém precisa lembrar e passa a ser o estado do
    // botão.
    //
    // A ferramenta ATIVA nunca apaga: ela precisa continuar clicável para ser
    // largada, e uma marcação ainda incompleta é o estado normal do trabalho
    // em curso, não um impedimento.
    const min = Number(f.dataset.min || 0)
    const max = f.dataset.max ? Number(f.dataset.max) : Infinity
    f.toggleAttribute('disabled', f.dataset.fer !== at && (n < min || n > max))
  })

  const selo = document.getElementById('cad-selo-sel')
  if (selo) {
    const n = selState.ids.size
    // `firstChild` e não `textContent`: a dica do ponteiro mora dentro do
    // selo, e reescrever o conteúdo inteiro a apagaria no primeiro lote marcado.
    selo.firstChild.nodeValue = String(n)
    selo.classList.toggle('tem', n > 0)
    selo.disabled = n === 0
  }
}

// ── ATALHOS ──────────────────────────────────────────────────
//
// Só valem com a MESA ABERTA, e nunca dentro de um campo: "D" digitado no nome
// de um bairro não pode largar a ferramenta e começar a desenhar um lote.
// Também não valem com modal aberto — lá o teclado é do formulário.
document.addEventListener('keydown', ev => {
  if (ev.ctrlKey || ev.metaKey || ev.altKey) { return }

  const mesa = document.getElementById('cad-mesa')
  if (!mesa || mesa.hidden || !ehMesaCadastral()) { return }

  // `instanceof Element`: o alvo de um keydown nem sempre é elemento — quando
  // nada está focado ele é o próprio `document`, que não tem `matches`, e
  // chamá-lo derruba o ouvinte inteiro em silêncio.
  const alvo = ev.target
  if (alvo instanceof Element && (alvo.matches('input, textarea, select') || alvo.isContentEditable)) { return }

  // Janela aberta manda no teclado: ali "D" é texto sendo digitado, ou atalho
  // do próprio formulário. O elemento que `openModal` marca com `.open` é o
  // FUNDO da janela, de classe `modal-bg` — `.modal` é a caixa de dentro, e
  // procurar por ela devolvia "nenhuma janela aberta" com uma janela na tela.
  if (document.querySelector('.modal-bg.open')) { return }

  const tecla = ev.key === 'Delete' ? 'Del' : ev.key.toUpperCase()
  const botao = document.querySelector(`#cad-geral .cad-lanca[data-tecla="${tecla}"]`)
  if (!botao) { return }

  ev.preventDefault()
  acionarFerramenta(botao.dataset.fer)
})

/** O nome da ferramenta ativa, para o topo da mesa. */
function tituloDaFerramenta() {
  if (atoState.tipo === 'unificacao') { return 'Unificar lotes' }
  if (atoState.tipo === 'desmembramento') { return 'Desmembrar lote' }
  return {
    quadra: 'Corrigir quadra',
    apagar: 'Apagar lote residual',
    desenho: 'Desenhar lote',
    coordenadas: 'Lote por coordenadas',
  }[cadModo] ?? 'Edição cadastral'
}

/**
 * Volta ao menu, largando a ferramenta.
 *
 * Confirma quando há trabalho em curso — lotes marcados ou desenho pendente.
 * Sair é a mesma coisa que o "Sair" da barra flutuante sempre fez; a diferença
 * é que ali ele era um botão isolado, e aqui fica ao lado do título, onde a
 * mão passa sem querer.
 */
function voltarAsFerramentas() {
  // NA MESA, LARGAR A FERRAMENTA NÃO DESMARCA NADA — a marcação é do operador
  // (ver `marcacaoEhDoOperador`), e continua valendo para a próxima. Só o
  // desenho em curso se perde, e é só por ele que vale perguntar. Perguntar
  // sobre lotes que não vão sumir seria ensinar a ignorar a pergunta.
  const perdeDesenho = !!desenhoPendente
    || (typeof estaDesenhando === 'function' && estaDesenhando())
  const perdeMarcas = selState.ids.size > 0 && !marcacaoEhDoOperador()

  if (!perdeDesenho && !perdeMarcas) { sairModoCadastral(); return }

  confirmarAcao({
    titulo: 'Largar o que está em curso',
    mensagem: perdeDesenho
      ? 'O desenho em curso será descartado.'
      : `${selState.ids.size} lote(s) marcado(s) serão desmarcados.`,
    perigo: true,
    onConfirm: () => sairModoCadastral(),
  })
}

/**
 * Abre a mesa. O título é decidido por `pintarMesaCadastral`, e não aqui.
 *
 * O parâmetro existia e escrevia por cima: abrir pelo ícone deixava
 * "Edição cadastral" fixo no topo mesmo com uma ferramenta ativa, e o título
 * deixava de dizer onde a pessoa estava.
 */
function abrirMesaCadastral() {
  montarMesaCadastral(true)
  // Abrir a mesa JÁ ARMA a marcação. É isto que inverte a ordem do trabalho:
  // chega-se marcando os lotes no mapa, e a régua responde com o que aquela
  // marcação comporta. Sem isto o clique abriria o balão do lote e não haveria
  // como acender ferramenta nenhuma sem antes escolher uma — que é a ordem
  // antiga, e a que faz a exigência só aparecer na recusa.
  if (!cadModo && !atoState.tipo) { selecaoLivreNaMesa(); pintarPainelCadastro() }
}

/**
 * Fecha a mesa SEM desfazer nada.
 *
 * O que foi marcado ou desenhado continua no mapa, e a barra de estado no topo
 * oferece reabrir — mesma regra da janela em tela pequena. Fechar a mesa é
 * querer ver o mapa inteiro, não desistir do trabalho.
 */
function fecharMesaCadastral() {
  const mesa = document.getElementById('cad-mesa')
  if (!mesa) { return }
  mesa.hidden = true
  document.body.classList.remove('com-mesa')
  document.body.classList.remove('mesa-larga')

  // Fechada a mesa, o clique no lote volta a ser o balão. Deixar a marcação
  // armada por baixo faria o mapa continuar marcando lotes sem nada na tela
  // dizendo por quê.
  if (selState.modo === 'livre') { desligarSelecao() }
  pintarPainelCadastro()
}

// A tela pode mudar de tamanho com trabalho em curso — janela redimensionada,
// tablet girado. Sem isto, o formulário ficaria numa mesa invisível (ou numa
// janela que a largura já não usa), e o operador perderia de vista o que
// estava preenchendo.
// `TELA_LARGA` vem de ui.js, que é o primeiro script da página — não há guarda
// de `typeof` aqui de propósito: `typeof` sobre um `const` ainda não avaliado
// LANÇA, em vez de devolver "undefined", e a guarda seria um conforto falso
// que esconderia uma troca de ordem dos scripts em vez de denunciá-la.
TELA_LARGA.addEventListener('change', () => {
  montarMesaCadastral((cadModo || atoState.tipo) ? ehMesaCadastral() : false)
})
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

  // EM TELA GRANDE NÃO HÁ JANELA. O mesmo formulário aparece na mesa lateral,
  // ao lado do mapa — que é onde o trabalho está acontecendo. Abrir um modal
  // por cima do mapa para digitar a quadra de lotes que estão no mapa é
  // esconder a resposta atrás da pergunta.
  if (ehMesaCadastral()) {
    abrirMesaCadastral()
  } else {
    openModal('m-cad')
  }
  pintarPainelCadastro()
}

/** Fecha a janela SEM desfazer nada: a barra continua com o trabalho em curso. */
function fecharModalCad() {
  fModalBtn('m-cad')
  fecharMesaCadastral()
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

  // A barra e `position:fixed` e mora FORA de `#t-mapa` — precisa estar fora
  // para flutuar sobre o mapa inteiro. O preco e que ela nao some junto com a
  // tela: quem trocava de modulo com um modo cadastral em curso levava a barra
  // para o Painel, para os Documentos e para o Protocolo & OS.
  //
  // Esconder, e nao sair do modo: o trabalho em curso (lotes marcados, desenho
  // pendente) continua de pe, e volta a aparecer ao voltar para o mapa.
  const noMapa = typeof mapaVisivel !== 'function' || mapaVisivel()

  // A MESA E A BARRA NÃO FALAM JUNTAS.
  //
  // As duas dizem o mesmo: em que passo se está e qual é o próximo botão. Com
  // a mesa aberta ao lado do mapa, a barra virava um segundo painel repetindo
  // "2 lote(s) marcados · Limpar marcação · Informar a quadra" logo acima do
  // painel que já oferecia exatamente isso — dois controles para a mesma ação,
  // que é como se perde a confiança no que a tela está dizendo.
  //
  // A barra continua existindo para o celular, onde não há mesa, e para quando
  // a mesa é fechada de propósito para ver o mapa inteiro.
  const mesaAberta = ehMesaCadastral() && document.getElementById('cad-mesa')?.hidden === false

  barra.hidden = !cadModo || !noMapa || mesaAberta
  if (barra.hidden) { return }

  const n = selState.ids.size
  const emAto = atoState.tipo === 'unificacao'
  const desenhando = typeof estaDesenhando === 'function' && estaDesenhando()

  const modo = document.getElementById('cad-barra-modo')
  const passo = document.getElementById('cad-barra-passo')
  const ok = document.getElementById('cad-barra-ok')
  const extra = document.getElementById('cad-barra-extra')

  ok.hidden = true
  extra.hidden = true

  if (cadModo === 'apagar') {
    modo.textContent = 'Apagar resíduo'
    passo.textContent = n === 0
      ? 'Marque os lotes a apagar. Vários de uma vez, se for o caso.'
      : `${n} lote(s) marcado(s) para apagar.`
    if (n > 0) {
      ok.hidden = false
      ok.textContent = n === 1 ? 'Apagar 1 lote' : `Apagar ${n} lotes`
      ok.onclick = abrirExclusaoSelecionados
      extra.hidden = false
      extra.textContent = 'Limpar marcação'
      extra.onclick = limparSelecaoCadastral
    }
  } else if (cadModo === 'quadra') {
    modo.textContent = emAto ? 'Unificação' : 'Corrigir quadra'
    passo.textContent = n === 0
      ? 'Marque os lotes no mapa.'
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
  pintarMesaCadastral()

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
      // A frase muda com a ORIGEM do ato: falar em protocolo num ato direto
      // mandaria o fiscal procurar um processo que não existe.
      cabecalho.innerHTML = atoState.direto
        ? '<div class="cad-nota"><b>Unificação direta</b>, sem protocolo. '
          + 'Marque DOIS ou mais lotes; eles precisam se encostar e ser da mesma quadra.</div>'
        : '<div class="cad-nota">Unificando pelo protocolo. '
          + 'Marque os lotes; eles precisam se encostar e ser da mesma quadra.</div>'
    }
  }

  // APAGAR usa a MESMA lista de lotes marcados, e por isso o mesmo painel —
  // mas não é a mesma pergunta. Enquanto o painel só era aberto para corrigir
  // quadra, isso não aparecia; com ele na mesa lateral, o modo "apagar
  // resíduo" passou a exibir o campo "Quadra a gravar" depois de marcar os
  // lotes a apagar. Perguntar a quadra de um lote que vai deixar de existir é
  // pior do que inútil: é a pergunta de outra ferramenta, no meio desta.
  const emApagar = cadModo === 'apagar'

  // O QUE FALTA PARA PODER SEGUIR, dito em número: a unificação precisa de dois,
  // e "1 lote marcado" sem mais nada deixava o fiscal esperando um botão que
  // não ia aparecer.
  const contagem = document.getElementById('cad-contagem')
  if (contagem) {
    if (n === 0) {
      contagem.textContent = emApagar
        ? 'Nenhum lote marcado — marque os resíduos a apagar. Vários, se for o caso.'
        : emAto
          ? 'Nenhum lote marcado — marque dois ou mais no mapa.'
          : 'Nenhum lote marcado ainda — marque-os no mapa.'
    } else if (emAto && n === 1) {
      contagem.textContent = '1 lote marcado — falta ao menos mais um para unificar.'
    } else {
      contagem.textContent = `${n} lote(s) marcado(s).`
    }
  }

  // Em unificação o mínimo é DOIS: com um só, o botão de conferir levaria a uma
  // recusa do servidor dizendo exatamente isto.
  document.getElementById('cad-acoes').hidden = emAto ? n < 2 : n === 0
  document.getElementById('cad-quadra-campo').hidden = emAto || emApagar

  const conferir = document.getElementById('cad-btn-conferir')
  conferir.setAttribute('onclick',
    emApagar ? 'abrirExclusaoSelecionados()'
      : emAto ? 'conferirUnificacao()' : 'conferirQuadraSelecao()')
  conferir.textContent = emApagar
    ? (n === 1 ? 'Apagar 1 lote' : `Apagar ${n} lotes`)
    : 'Conferir'
  conferir.classList.toggle('perigo', emApagar)

  document.getElementById('cad-btn-limpar').setAttribute('onclick',
    emAto ? 'cancelarAtoCadastral()' : 'limparSelecaoCadastral()')
  document.getElementById('cad-btn-limpar').textContent = emAto ? 'Cancelar ato' : 'Limpar'

  // ── bloco de desenho ──
  // O formulário só aparece com o contorno FECHADO: pedir bairro e quadra no
  // meio do traçado seria disputar a atenção com o mapa.
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
/**
 * A ROTA DO ATO — com protocolo ou direta.
 *
 * O caminho normal executa a decisão de um protocolo deferido. O DIRETO existe
 * porque o mapa vem de um DWG que nem sempre acompanha o cartório: há lote já
 * unificado ou desmembrado no mundo real e ainda inteiro no desenho. Aí não há
 * protocolo a esperar — o ato não decide nada, só põe o cadastro em dia.
 *
 * Uma função e não quatro URLs escritas à mão: eram quatro pontos montando o
 * mesmo endereço, e o direto precisaria ser lembrado em todos os quatro.
 *
 * @param {'unificacao'|'desmembramento'} ato
 * @param {boolean} previa
 * @returns {string}
 */
function rotaDoAto(ato, previa = false) {
  const fim = previa ? '/previa' : ''

  if (atoState.direto) {
    return ato === 'unificacao'
      ? `/api/lotes/unificacao-direta${fim}`
      : `/api/lotes/desmembramento-direto${fim}`
  }

  return `/api/protocolos/${atoState.protocoloId}/${ato}${fim}`
}

/**
 * A justificativa do ato direto, no corpo do pedido.
 *
 * Sem protocolo não há decisão a apontar, então a responsabilidade é de quem
 * executou — e ela fica escrita em `lote_atos.observacao`, ao lado do usuário.
 *
 * @returns {Object}
 */
function corpoDoAto(extra) {
  return atoState.direto
    ? { ...extra, justificativa: atoState.justificativa }
    : extra
}

function iniciarAtoCadastral(protocoloId, tipo, loteId = null) {
  atoState.protocoloId = protocoloId
  atoState.tipo = tipo
  // Protocolo nulo = ato direto. Quem chama pela ficha sempre traz um.
  atoState.direto = !protocoloId

  // Fecha a ficha e leva ao mapa: o ato é geométrico, e a escolha acontece
  // sobre o desenho, não numa lista.
  fModalBtn('m-ficha')
  irPara('mapa')

  setTimeout(() => {
    fecharPaineisMapa()
    // ABRIR, e não alternar. `alternarPainelMapa` foi escrito para o painel
    // flutuante, onde o ato sempre chegava com ele fechado. Na mesa o mesmo
    // chamado encontra a coluna ABERTA — e a fecha, no exato momento em que o
    // ato começa: quem iniciava uma unificação pela régua via a mesa
    // desaparecer e o trabalho continuar sem lugar nenhum na tela.
    garantirPainelCadastroAberto()

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

/**
 * COMEÇA UM ATO DIRETO — sem protocolo.
 *
 * A justificativa é pedida AQUI, no começo, e não no fim: ela é o motivo de o
 * ato existir sem protocolo, e quem não consegue escrevê-la provavelmente
 * deveria estar abrindo um. Guardá-la desde já também evita perder o texto se
 * a conferência apontar impedimento e o operador tiver de refazer o desenho.
 *
 * @param {'unificacao'|'desmembramento'} tipo
 */
function atoDiretoCadastral(tipo) {
  const rotulo = tipo === 'unificacao' ? 'Unificação direta' : 'Desmembramento direto'

  // CADA ATO PEDE UMA COISA DIFERENTE, e é melhor dizer isso antes de o fiscal
  // escrever a justificativa do que depois:
  //
  //   unificação      DOIS ou mais lotes, que se encostam — eles viram um
  //   desmembramento  UM lote, que vai ser dividido em partes desenhadas
  //
  // O desmembramento parava aqui em silêncio: sem lote selecionado, começava
  // com `loteId: null` e só falhava lá na frente, depois de desenhar tudo.
  // O ALVO PODE VIR DE DUAS SELEÇÕES.
  //
  // Na mesa o lote chega MARCADO: lá o clique no mapa marca em vez de abrir o
  // balão, e `state.selecionado` fica vazio. Exigir o balão travaria o
  // desmembramento justamente na tela feita para ele. No celular nada muda —
  // não há marcação livre, e o lote continua chegando pelo balão.
  const alvoMarcado = selState.ids.size === 1 ? [...selState.ids][0] : null
  const loteAlvo = alvoMarcado ?? state.selecionado?.properties?.id ?? null

  if (tipo === 'desmembramento' && !loteAlvo) {
    toast('Marque primeiro o lote que será desmembrado.', 'err')
    return
  }

  pedirTexto({
    titulo: rotulo,
    rotulo: 'Por que este ato não tem protocolo?',
    dica: 'Ex.: lote já unificado na matrícula 12.345 do CRI; o desenho do DWG '
      + 'não foi atualizado. Fica registrado com o seu nome.',
    minimo: 10,
    onOk: texto => {
      atoState.justificativa = texto
      // Protocolo nulo é o que marca o ato como direto — ver `rotaDoAto`.
      iniciarAtoCadastral(null, tipo, loteAlvo)
      // O desmembramento tem tela própria: o lote alvo ocupa o mapa e os
      // vizinhos viram referência. A unificação não precisa — ali o trabalho é
      // tocar em lotes espalhados, que é justamente o que a mesa esconderia.
      if (tipo === 'desmembramento') { abrirMesaDesmembramento() }
    },
  })
}

/**
 * Apagar resíduo: marcar no mapa e apagar o conjunto.
 *
 * Resíduo raramente vem sozinho — a conversão do DWG deixa faixas em série, ao
 * longo de uma divisa inteira. Apagar de um em um seria repetir senha e motivo
 * a cada faixa. Aqui se marca à vontade e se apaga o conjunto, com uma senha e
 * um motivo para todos.
 */
function apagarLoteDoPainel() {
  modoCadastral('apagar')
  toast('Toque nos lotes a apagar.', 'aviso')
}

/** Abre a janela da exclusão com o que está marcado no mapa. */
function abrirExclusaoSelecionados() {
  const ids = [...selState.ids]
  if (!ids.length) { toast('Nenhum lote marcado', 'err'); return }

  const rotulo = ids.length === 1
    ? rotuloDoLote(ids[0])
    : `${ids.length} lotes marcados`

  excluirLote(ids, rotulo)
}

/** @param {number} id @returns {string} */
function rotuloDoLote(id) {
  const p = state.lotes.get(id)?.properties
  return p
    ? `Quadra ${p.quadra ?? '—'} · Lote ${p.numero_lote ?? '—'} — ${p.bairro ?? ''}`.trim()
    : 'o lote marcado'
}

/** Larga o ato em andamento sem executá-lo. */
function cancelarAtoCadastral() {
  atoState.protocoloId = null
  atoState.tipo = null
  if (selState.ativa) { desligarSelecao() }
  largarDesenho()

  // A MESA CONTINUA ABERTA — E A MARCAÇÃO TEM DE VOLTAR A FICAR ARMADA.
  //
  // `desligarSelecao` acima desarma, e é o que se quer no celular. Na mesa, sem
  // isto, o fim de uma unificação deixava a régua na tela viva e inútil: clicar
  // num lote voltava a abrir o balão, nada mais acendia, e a única saída era
  // fechar e reabrir a mesa. É o estado em que o operador cai logo depois de
  // gravar o ato — ou seja, sempre.
  selecaoLivreNaMesa()
  pintarPainelCadastro()
}

/** Prévia e execução da unificação. */
async function conferirUnificacao() {
  const alvo = document.getElementById('cad-previa')
  alvo.innerHTML = '<div class="cad-nota">Conferindo…</div>'

  const d = await postCadastro(rotaDoAto('unificacao', true), { ids: [...selState.ids] })
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
      + 'Os antigos não são apagados: ficam inativos, apontando para o sucessor, e '
      + 'vistorias, obras e documentos continuam neles.',
    textoBtn: 'Unificar',
    onConfirm: async () => {
      const d = await postCadastro(rotaDoAto('unificacao'),
        corpoDoAto({ ids: [...selState.ids], numero_lote: numero }))
      if (!d) { return }

      toast(d.message)
      cancelarAtoCadastral()
      limparLotesDoMapa()
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
    rotulo: 'Parte do desmembramento',
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
    rotulo: 'Divisa do desmembramento',
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
      pintarMesaDesmembramento()
      if (typeof pintarPartesNoMapa === 'function') { pintarPartesNoMapa() }
    },
    onCancelar: () => { pintarDesmembramento(); pintarMesaDesmembramento() },
  })
  pintarDesmembramento()
  pintarMesaDesmembramento()
}

/** @param {number} i */
function removerParte(i) {
  desmState.partes.splice(i, 1)
  pintarDesmembramento()
}

function largarDesmembramento() {
  if (typeof sairMesaDesmembramento === 'function') { sairMesaDesmembramento() }
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

  // A MESA TOMA O LUGAR DESTE PAINEL.
  //
  // Os dois falam do mesmo desmembramento, e mostrá-los juntos daria dois
  // lugares para digitar o número da mesma parte — com dois valores possíveis
  // para o que o servidor recebe.
  const emAto = atoState.tipo === 'desmembramento' && !(typeof desmMesa !== 'undefined' && desmMesa.ativa)
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
    // As medidas da matrícula, quando a mesa de desmembramento as pediu.
    // Vazio vira null e não zero: zero é uma medida, ausência não é.
    frente_m: _num(p.frente_m),
    fundos_m: _num(p.fundos_m),
    lado_direito_m: _num(p.lado_direito_m),
    lado_esquerdo_m: _num(p.lado_esquerdo_m),
    area_matricula_m2: _num(p.area_matricula_m2),
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

/** @param {string|number|null|undefined} v @returns {number|null} */
function _num(v) {
  if (v === null || v === undefined || String(v).trim() === '') { return null }
  const n = Number(String(v).replace(',', '.'))
  return Number.isFinite(n) && n > 0 ? n : null
}

async function conferirDesmembramento() {
  if (!desmState.partes.length) { toast('Desenhe ao menos uma parte.', 'err'); return }

  const alvo = document.getElementById('desm-previa')
  alvo.innerHTML = '<div class="cad-nota">Conferindo…</div>'

  const d = await postCadastro(rotaDoAto('desmembramento', true), _corpoDesmembramento())
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
      + 'Ele não é apagado: fica inativo, apontando para as partes, e vistorias, '
      + 'obras e documentos continuam nele.',
    textoBtn: 'Desmembrar',
    onConfirm: async () => {
      const d = await postCadastro(rotaDoAto('desmembramento'),
        corpoDoAto(_corpoDesmembramento()))
      if (!d) { return }

      toast(d.message)
      largarDesmembramento()
      limparLotesDoMapa()
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
    rotulo: 'Lote novo',
    snap: true,
    onConcluir: g => {
      desenhoPendente = g
      pintarPainelCadastro()
      const f = state.selecionado
      popularBairrosDoDesenho(f?.bairro || f?.properties?.bairro || '')
      conferirMedidas()
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
/**
 * O motor de desenho avisa a cada vértice.
 *
 * O texto do passo saiu daqui: quem o escreve agora é a barra sobre o mapa
 * (`_pintarBarraDesenho`, em desenho.js), que vale para todo desenho e não só
 * para o lote. O que sobra é repintar o painel, porque é ele que decide quando
 * o formulário de dados aparece.
 */
function aoDesenharVertice() {
  pintarPainelCadastro()
}

// ── BAIRROS E MEDIDAS DO LOTE ────────────────────────────────

/** @type {Array<{codigo:string|null,nome:string,valor:string}>|null} */
let bairrosDoCadastro = null

/**
 * Enche o combobox de bairro com os bairros CADASTRADOS.
 *
 * Não é o mesmo endpoint do filtro de busca: lá só interessam bairros que já
 * têm lote, porque oferecer no filtro um bairro sem resultado é oferecer
 * trabalho perdido. Aqui o lote ainda vai existir — e pode ser o primeiro do
 * bairro dele.
 *
 * @param {string} [selecionado] valor a deixar escolhido
 */
async function popularBairrosDoDesenho(selecionado) {
  const sel = document.getElementById('des-bairro')
  if (!sel) { return }

  if (!bairrosDoCadastro) {
    try {
      const r = await fetch('/api/bairros', { headers: { Accept: 'application/json' } })
      bairrosDoCadastro = (await r.json()).bairros
    } catch (e) {
      console.error(e)
      toast('Não foi possível carregar os bairros', 'err')
      return
    }
  }

  sel.innerHTML = '<option value="">— escolha —</option>'
    + bairrosDoCadastro.map(b => `<option value="${esc(b.valor)}">`
      + `${b.codigo ? esc(b.codigo) + ' · ' : ''}${esc(b.nome)}</option>`).join('')

  // O bairro do lote vizinho vem escolhido: quem desenha acabou de olhar a
  // quadra ao lado, e reescolher na lista de 125 é atrito puro. Só entra se o
  // nome existir na lista — senão o campo ficaria com um valor que o servidor
  // recusa, sem dizer por quê.
  if (selecionado && bairrosDoCadastro.some(b => b.valor === selecionado)) {
    sel.value = selecionado
  }
}

/** @param {string} id @returns {number|null} */
function numeroDoCampo(id) {
  const v = String(document.getElementById(id)?.value ?? '').replace(',', '.').trim()
  if (v === '') { return null }
  const n = Number(v)
  return Number.isFinite(n) && n > 0 ? n : null
}

/** As medidas digitadas, prontas para ir ao servidor. */
function medidasDigitadas() {
  return {
    frente_m: numeroDoCampo('des-frente'),
    fundos_m: numeroDoCampo('des-fundos'),
    lado_direito_m: numeroDoCampo('des-lado-dir'),
    lado_esquerdo_m: numeroDoCampo('des-lado-esq'),
    area_matricula_m2: numeroDoCampo('des-area-mat'),
  }
}

/** Acima disto a diferença entre matrícula e desenho é apontada. */
const DIVERGENCIA_LIMITE = 5

/**
 * Confronta o que foi digitado com o que o desenho mede.
 *
 * AVISA E NÃO IMPEDE, de propósito. A divergência é informação — pode ser
 * avanço sobre a via, erro do DWG, desmembramento não averbado — e é o fiscal
 * quem sabe qual. Bloquear a gravação faria ele arredondar o número digitado
 * até a tela parar de reclamar, que é como um campo obrigatório vira dado
 * inventado.
 */
function conferirMedidas() {
  const alvo = document.getElementById('des-conferencia')
  const selo = document.getElementById('des-conf-selo')
  if (!alvo) { return }

  const g = desenhoPendente
  const m = medidasDigitadas()
  const linhas = []
  let divergiu = false

  // A área do desenho: se o polígono já está fechado, mede-se ele; senão não
  // há com o que comparar ainda.
  const areaDesenho = g ? areaDoAnel(g) : null

  if (m.area_matricula_m2 && areaDesenho) {
    const dif = (areaDesenho / m.area_matricula_m2 - 1) * 100
    const fora = Math.abs(dif) > DIVERGENCIA_LIMITE
    divergiu = divergiu || fora
    linhas.push(`<div class="cad-nota${fora ? ' cad-aviso' : ''}">`
      + `Matrícula <b>${fmtNum(m.area_matricula_m2)} m²</b> · `
      + `desenho <b>${fmtNum(areaDesenho)} m²</b> · `
      + `<b>${dif > 0 ? '+' : ''}${dif.toFixed(1).replace('.', ',')}%</b>`
      + (fora ? ' — confira se o desenho não pegou a calçada ou faltou pedaço.' : '')
      + '</div>')
  }

  // O perímetro digitado dá uma área aproximada (fórmula do trapézio: média
  // das frentes vezes média dos lados). Não é a área do lote — só serve para
  // pegar o erro grosseiro de digitação, um "3" que virou "30".
  if (m.frente_m && m.fundos_m && m.lado_direito_m && m.lado_esquerdo_m) {
    const aprox = ((m.frente_m + m.fundos_m) / 2) * ((m.lado_direito_m + m.lado_esquerdo_m) / 2)
    const base = m.area_matricula_m2 || areaDesenho
    if (base) {
      const dif = (aprox / base - 1) * 100
      const fora = Math.abs(dif) > 15
      divergiu = divergiu || fora
      if (fora) {
        linhas.push('<div class="cad-nota cad-aviso">Os quatro lados dariam cerca de '
          + `<b>${fmtNum(aprox)} m²</b>, contra ${fmtNum(base)} m². Confira as medidas.</div>`)
      }
    }
  }

  alvo.innerHTML = linhas.join('')
  if (selo) {
    const preenchidos = Object.values(m).filter(v => v !== null).length
    selo.hidden = preenchidos === 0
    selo.textContent = divergiu ? 'confira' : `${preenchidos}/5`
    selo.classList.toggle('selo-aviso', divergiu)
  }
}

/**
 * Área de um polígono GeoJSON, em m².
 *
 * Usa o mesmo plano local do desenho (desenho.js), e não o haversine: medir o
 * mesmo lote com duas réguas produz divergência de meio por cento que ninguém
 * consegue explicar depois.
 *
 * @param {{coordinates:Array}} g
 */
function areaDoAnel(g) {
  const anel = g?.coordinates?.[0]
  if (!anel || anel.length < 4) { return null }

  const p = planoLocal(anel[0][1], anel[0][0])
  const xy = anel.slice(0, -1).map(c => [
    (c[0] - p.lonRef) * p.porGrauLon,
    (c[1] - p.latRef) * p.porGrauLat,
  ])
  let s = 0
  for (let i = 0, n = xy.length; i < n; i++) {
    const j = (i + 1) % n
    s += xy[i][0] * xy[j][1] - xy[j][0] * xy[i][1]
  }
  return Math.abs(s) / 2
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

  // A divergência vem do SERVIDOR, e não da conta que a tela já fez enquanto o
  // operador digitava: é este número que fica ao lado do botão de gravar, e
  // conferência que só existe no navegador não é conferência.
  const dv = r.divergencia
  const divergencia = dv === null || dv === undefined ? '' :
    `<div class="cad-nota${Math.abs(dv) > 5 ? ' cad-aviso' : ''}">
      Matrícula × desenho: <b>${dv > 0 ? '+' : ''}${String(dv).replace('.', ',')}%</b>.
      ${Math.abs(dv) > 5
        ? 'A diferença é grande — o lote é gravado assim mesmo, e as duas medidas ficam registradas.'
        : 'As duas medidas batem.'}</div>`

  alvo.innerHTML = `
    <div class="cad-nota">Lote de <b>${fmtNum(r.area_m2)} m²</b> com ${r.vertices} canto(s),
      em ${esc(r.bairro)} · quadra ${esc(r.quadra)} · lote ${esc(r.lote)}.
      ${encosta ? `Encosta em ${encosta} lote(s) vizinho(s).` : ''}</div>
    ${divergencia}
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
      limparLotesDoMapa()
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

  return {
    bairro, quadra, numero_lote: lote, geometry: desenhoPendente,
    ...medidasDigitadas(),
  }
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

// ── APAGAR LOTE RESIDUAL ─────────────────────────────────────
//
// A conversão do DWG deixa sobras: faixas de terra sem quadra, sem número e sem
// dono, que existem no desenho e não no mundo. Elas poluem a busca, entram em
// contagem e um dia alguém vai vistoriar uma.
//
// NÃO é baixa. Baixa é o que acontece com um lote que existiu e deixou de
// existir — fica na sucessão, apontando para o sucessor. Resíduo nunca existiu:
// guardá-lo como "inativo" inventaria um ato que não houve.
//
// A senha é pedida porque isto é irreversível e o sistema é usado no celular,
// em campo, com o dedo. Quem confere a senha é o servidor.

/**
 * @param {number|Array<number>} ids um lote ou o conjunto marcado no mapa
 * @param {string} rotulo como o conjunto se identifica na mensagem
 */
function excluirLote(ids, rotulo) {
  const lista = Array.isArray(ids) ? ids : [ids]

  document.getElementById('mex-lote').textContent = rotulo || 'este lote'
  document.getElementById('mex-motivo').value = ''
  document.getElementById('mex-senha').value = ''
  document.getElementById('m-excluir-lote').dataset.lotes = JSON.stringify(lista)
  openModal('m-excluir-lote')
}

async function confirmarExclusaoLote() {
  const caixa = document.getElementById('m-excluir-lote')
  const ids = JSON.parse(caixa.dataset.lotes || '[]')
  const motivo = document.getElementById('mex-motivo').value.trim()
  const senha = document.getElementById('mex-senha').value

  if (!ids.length) { toast('Nenhum lote marcado', 'err'); return }
  if (motivo.length < 10) { toast('Escreva o motivo — ao menos 10 caracteres.', 'err'); return }
  if (!senha) { toast('Informe sua senha para confirmar.', 'err'); return }

  const btn = document.getElementById('mex-btn')
  btn.disabled = true
  try {
    // POST e não DELETE: o pedido leva senha e motivo no corpo, e corpo em
    // DELETE é aceito pela norma mas mal suportado por proxies e clientes.
    const r = await fetch('/api/lotes/excluir', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({ ids, senha, motivo }),
    })
    const d = await r.json().catch(() => ({}))

    if (!r.ok) { toast(d.message || 'Não foi possível apagar.', 'err'); return }

    toast(d.message)
    fModalBtn('m-excluir-lote')
    // A ficha de um lote apagado não pode continuar aberta atrás da janela.
    fModalBtn('m-ficha')
    state.selecionado = null
    sairModoCadastral(true)
    limparLotesDoMapa()
    carregarLotesVisiveis()
  } catch (e) {
    console.error(e)
    toast('Falha de rede ao apagar', 'err')
  } finally {
    btn.disabled = false
    // A senha não sobrevive à janela: some da tela assim que o pedido termina.
    document.getElementById('mex-senha').value = ''
  }
}

