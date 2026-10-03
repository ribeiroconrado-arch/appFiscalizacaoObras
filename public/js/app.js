// ══════════════════════════════════════════════
// APLICAÇÃO — Fiscalização de Obras
//
// Fluxo do MVP-1: abrir o mapa → ver os lotes → clicar num lote e ver a ficha
// → "usar minha localização" e o sistema dizer em qual lote o fiscal está.
//
// Diferença para o protótipo da Etapa 3: os dados agora vêm da API Laravel.
// Os lotes carregam por BBOX, à medida que o mapa se move — o município tem
// 23.662 lotes e mandar tudo de uma vez trava o aparelho do fiscal. E a
// identificação por GPS é resolvida no banco, porque o servidor tem a base
// inteira enquanto o navegador só tem o que está na tela.
// ══════════════════════════════════════════════

/** Estado da aplicação. Objeto mutável simples, como em core/state.js do AppPOSTURAS. */
const state = {
  /** Lotes já carregados, indexados por id — evita redesenhar ao voltar a uma área. */
  lotes: new Map(),
  /** @type {{lat:number, lon:number, prec:number}|null} */ pos: null,
  /** @type {Object|null} */ selecionado: null,
  versaoLotes: 0,
  truncado: false,
  /** Blocos já carregados: chave "x:y" → ids dos lotes que vieram nele. @type {Map<string, Set<number>>} */
  blocos: new Map(),
  /** Blocos com pedido em trânsito: chave → promessa. @type {Map<string, Promise>} */
  pendentes: new Map(),
  /** Blocos cuja resposta bateu no teto do servidor. @type {Set<string>} */
  blocosTruncados: new Set(),
}

/**
 * Abaixo deste zoom não se pedem lotes (e os carregados saem da pintura —
 * ver ocultarLotesAfastado, em mapa.js). Nos zooms 11 e 12 o lote é uma mancha
 * branca, e o contorno do bairro com o nome já diz onde cada um está.
 */
const ZOOM_MINIMO = 13

// ── CARGA EM BLOCOS ──────────────────────────────────────────
//
// O município vai a ~50 mil lotes. Pedir "o que está na tela" de uma vez só
// não escala: a cidade inteira são ~23 MB de GeoJSON e quase 1 s de banco
// (medido com 50 mil lotes sintéticos no MySQL 8.0.46). Então:
//
// 1. NÍVEL DE DETALHE PELA ÁREA. Lote só é pedido quando a área visível cabe
//    em AREA_MAX_GRAUS2 (~3,5 km²). Mais longe que isso o mapa mostra o
//    contorno e o nome dos bairros (bairros-contorno.js) e o nome da cidade.
//    É a área, e não o zoom, que decide: o mesmo zoom 16 cobre 0,8 km² no
//    celular e 18 km² num monitor largo.
// 2. BLOCOS FIXOS de BLOCO_GRAUS (~1,1 km). A tela vira uma lista de blocos;
//    só se pede o que falta, em paralelo, e um bloco já carregado nunca é
//    pedido de novo. Arrastar o mapa enquanto carrega não perde mais o pedido
//    (antes, uma carga em andamento descartava a nova área).
// 3. MEMÓRIA COM TETO. Passando de LIMITE_MEMORIA lotes, os blocos mais
//    distantes da tela saem — menos os lotes em uso (ver lotesProtegidos).

/** Lado do bloco, em graus (~1,1 km). Um bloco denso tem ~2.700 lotes. */
const BLOCO_GRAUS = 0.01

/** Maior área visível (graus²) em que os lotes são pedidos — ~3,5 km². */
const AREA_MAX_GRAUS2 = 0.0003

/**
 * Área maior (~14 km², um bairro inteiro numa tela larga) enquanto uma
 * ferramenta de CURADORIA está no mapa: a importação, a pré-curadoria, a
 * conferência com o cadastro e a mesa de correção enquadram o bairro inteiro
 * e precisam ver os lotes dele — é o trabalho que o curador está fazendo.
 */
const AREA_MAX_CURADORIA_GRAUS2 = 0.0012

/** Alguma ferramenta de curadoria está no mapa? */
function curadoriaNoMapa() {
  return (typeof impState !== 'undefined' && (impState.noMapa || !!impState.preCuradoria))
    || (typeof confState !== 'undefined' && confState.noMapa)
    || (typeof selState !== 'undefined' && selState.ativa)
}

/** Teto de lotes em memória antes de descartar os blocos distantes. */
const LIMITE_MEMORIA = 15000

/** Pedidos de bloco simultâneos. */
const BLOCOS_EM_PARALELO = 4

/** A área visível está na escala em que os lotes aparecem? @param {L.Map} mapa */
function lotesNaEscala(mapa) {
  if (!mapa || mapa.getZoom() < ZOOM_MINIMO) return false
  const b = mapa.getBounds()
  const teto = curadoriaNoMapa() ? AREA_MAX_CURADORIA_GRAUS2 : AREA_MAX_GRAUS2
  return (b.getEast() - b.getWest()) * (b.getNorth() - b.getSouth()) <= teto
}

/**
 * Chaves dos blocos que cobrem um retângulo.
 * @param {{oeste:number, sul:number, leste:number, norte:number}} r
 * @returns {string[]} "x:y", em ordem do centro para fora
 */
function blocosDoRetangulo(r) {
  const x0 = Math.floor(r.oeste / BLOCO_GRAUS), x1 = Math.floor(r.leste / BLOCO_GRAUS)
  const y0 = Math.floor(r.sul / BLOCO_GRAUS), y1 = Math.floor(r.norte / BLOCO_GRAUS)
  const cx = (x0 + x1) / 2, cy = (y0 + y1) / 2
  const chaves = []
  for (let x = x0; x <= x1; x++) for (let y = y0; y <= y1; y++) chaves.push([x, y])
  // O bloco do meio da tela chega primeiro: é para onde o fiscal está olhando.
  chaves.sort((a, b) => Math.hypot(a[0] - cx, a[1] - cy) - Math.hypot(b[0] - cx, b[1] - cy))
  return chaves.map(([x, y]) => x + ':' + y)
}

/** bbox "oeste,sul,leste,norte" de um bloco. @param {string} chave */
function bboxDoBloco(chave) {
  const [x, y] = chave.split(':').map(Number)
  return [x * BLOCO_GRAUS, y * BLOCO_GRAUS, (x + 1) * BLOCO_GRAUS, (y + 1) * BLOCO_GRAUS]
    .map(n => n.toFixed(6)).join(',')
}

/**
 * Pede ao servidor os lotes dos blocos que cobrem a tela e ainda não vieram.
 * Devolve uma promessa que resolve quando TODOS os blocos da tela chegaram —
 * inclusive os que outra chamada já tinha pedido (prepararMapa a aguarda).
 */
async function carregarLotesVisiveis() {
  const mapa = mapaState.obj
  if (!mapa) return

  // Mapa escondido (o app abre no Painel) tem contêiner de tamanho zero, e
  // aí getBounds() devolve os quatro cantos no mesmo ponto. Quem entra na aba
  // dispara a carga, em prepararMapa().
  if (!mapaVisivel()) return

  if (!lotesNaEscala(mapa)) {
    atualizarChip(`Aproxime o mapa para ver os lotes`)
    return
  }

  const b = mapa.getBounds().pad(0.1)
  const chaves = blocosDoRetangulo({ oeste: b.getWest(), sul: b.getSouth(), leste: b.getEast(), norte: b.getNorth() })
  const versao = state.versaoLotes

  const faltam = chaves.filter(k => !state.blocos.has(k) && !state.pendentes.has(k))
  const fila = [...faltam]
  const trabalhador = async () => {
    while (fila.length) {
      const k = fila.shift()
      const p = carregarBloco(k, versao)
      state.pendentes.set(k, p)
      try { await p } finally { if (state.pendentes.get(k) === p) state.pendentes.delete(k) }
    }
  }
  const trabalho = Promise.all(Array.from({ length: Math.min(BLOCOS_EM_PARALELO, fila.length) }, trabalhador))
  // Espera também os blocos desta tela que uma chamada anterior já pediu.
  const emTransito = chaves.filter(k => state.pendentes.has(k) && !faltam.includes(k)).map(k => state.pendentes.get(k))
  await Promise.all([trabalho, ...emTransito])

  if (versao !== state.versaoLotes) return
  descartarLotesDistantes(chaves)
  atualizarChip()
}

/**
 * Carrega um bloco e acrescenta ao mapa o que ainda não estava desenhado.
 * @param {string} chave @param {number} versao
 */
async function carregarBloco(chave, versao) {
  try {
    // Curador vê também os lotes de importação ainda não publicada, a menos
    // que desligue a camada "Lotes não publicados" (painel Camadas,
    // camadas-mapa.js). (`typeof document`: os testes rodam esta função fora
    // do navegador.)
    const revisao = typeof document !== 'undefined' && typeof window !== 'undefined' && window.USUARIO_CURADOR
      && (typeof camadaLigada !== 'function' || camadaLigada('nao-publicados')) ? '&revisao=1' : ''
    const r = await fetch(`/api/mapa/lotes?bbox=${bboxDoBloco(chave)}${revisao}`, {
      headers: { 'Accept': 'application/json' },
    })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const gj = await r.json()
    // Uma edição invalidou esta resposta enquanto a consulta estava em trânsito.
    if (versao !== state.versaoLotes) return

    const ids = new Set()
    for (const f of gj.features) {
      ids.add(f.properties.id)
      if (!state.lotes.has(f.properties.id)) state.lotes.set(f.properties.id, f)
    }
    state.blocos.set(chave, ids)
    if (gj.truncado) { state.blocosTruncados.add(chave) } else { state.blocosTruncados.delete(chave) }
    state.truncado = state.blocosTruncados.size > 0

    const novos = gj.features.filter(f => !desenhados.has(f.properties.id))
    if (novos.length) acrescentarLotes(novos)
  } catch (e) {
    if (versao !== state.versaoLotes) return
    console.error(e)
    toast('Falha ao carregar os lotes', 'err')
  }
}

/**
 * Lotes que não podem sair da memória: o aberto na ficha, os marcados na mesa
 * do cadastro, o do desmembramento e os destacados. Descartá-los quebraria a
 * ferramenta que está usando cada um.
 * @returns {Set<number>}
 */
function lotesProtegidos() {
  const ids = new Set()
  const sel = state.selecionado?.properties?.id
  if (sel != null) ids.add(sel)
  if (typeof selState !== 'undefined') selState.ids?.forEach(i => ids.add(i))
  if (typeof desmMesa !== 'undefined' && desmMesa.loteId != null) ids.add(desmMesa.loteId)
  if (typeof desmState !== 'undefined' && desmState.loteId != null) ids.add(desmState.loteId)
  if (typeof corState !== 'undefined') corState.destacados?.forEach(i => ids.add(i))
  return ids
}

/**
 * Passando do teto de memória, tira os blocos mais distantes da tela.
 *
 * Os blocos visíveis (`naTela`) ficam sempre. Um lote que está em dois
 * blocos (o da divisa) só sai quando os dois saem; um lote protegido não sai.
 *
 * @param {string[]} naTela chaves dos blocos visíveis
 */
function descartarLotesDistantes(naTela) {
  if (state.lotes.size <= LIMITE_MEMORIA) return

  const visiveis = new Set(naTela)
  const [cx, cy] = naTela.length
    ? naTela.map(k => k.split(':').map(Number)).reduce((a, p) => [a[0] + p[0], a[1] + p[1]], [0, 0]).map(v => v / naTela.length)
    : [0, 0]
  const distantes = [...state.blocos.keys()].filter(k => !visiveis.has(k))
    .sort((a, b) => {
      const [ax, ay] = a.split(':').map(Number), [bx, by] = b.split(':').map(Number)
      return Math.hypot(bx - cx, by - cy) - Math.hypot(ax - cx, ay - cy)
    })

  const protegidos = lotesProtegidos()
  const alvo = Math.floor(LIMITE_MEMORIA * 0.7)
  const sair = []
  for (const k of distantes) {
    if (state.lotes.size - sair.length <= alvo) break
    const ids = state.blocos.get(k)
    state.blocos.delete(k)
    state.blocosTruncados.delete(k)
    for (const id of ids) {
      if (protegidos.has(id)) continue
      let emOutro = false
      for (const outros of state.blocos.values()) { if (outros.has(id)) { emOutro = true; break } }
      if (!emOutro) sair.push(id)
    }
  }
  if (sair.length) removerLotesDoMapa(sair)
}

/**
 * Tira lotes do mapa e da memória (o descarte por distância).
 * @param {number[]} ids
 */
function removerLotesDoMapa(ids) {
  const fora = new Set(ids)
  for (const id of fora) {
    const camada = mapaState.porId.get(id)
    if (camada) mapaState.camadaLotes?.removeLayer(camada)
    mapaState.porId.delete(id)
    state.lotes.delete(id)
    desenhados.delete(id)
  }
  const restam = mapaState.camadas.filter(c => !fora.has(c.feature?.properties?.id))
  mapaState.camadas.length = 0
  mapaState.camadas.push(...restam)
  // Rótulos de bairro e quadra vêm dos lotes carregados: refaz sem os que saíram.
  if (typeof agendarRepintura === 'function') agendarRepintura()
}

/** Ids já desenhados no mapa, para não duplicar polígono ao arrastar de volta. */
const desenhados = new Set()

/**
 * Apaga do mapa TODOS os lotes desenhados, e não só a lista de ids.
 *
 * `desenhados.clear()` sozinho não removia camada nenhuma: ele só dizia "pode
 * desenhar de novo". Depois de um desmembramento ou de uma unificação, o lote
 * de ORIGEM continuava pintado por cima dos sucessores até alguém recarregar a
 * página — um imóvel que o sistema já tinha inativado seguia clicável no mapa, e
 * abria a ficha de um lote que não existe mais.
 *
 * Por isso limpar é remover as camadas, esvaziar os índices e recarregar do
 * servidor — que é quem sabe quais lotes ainda estão ativos.
 */
function limparLotesDoMapa() {
  state.versaoLotes++
  // Toda correção do cadastro passa por aqui. Quem acompanha os lotes — a
  // conferência com o cadastro, que se refaz sozinha — fica sabendo.
  if (typeof document !== 'undefined' && typeof document.dispatchEvent === 'function'
      && typeof CustomEvent !== 'undefined') {
    document.dispatchEvent(new CustomEvent('lotes-alterados'))
  }
  state.blocos.clear()
  state.pendentes.clear()
  state.blocosTruncados.clear()
  state.truncado = false
  const mapa = mapaState.obj
  if (mapa) {
    mapaState.camadas.forEach(c => mapa.removeLayer(c))
  }
  mapaState.camadas.length = 0
  mapaState.porId.clear()
  state.lotes.clear()
  desenhados.clear()
  // A seleção apontava para um lote que acabou de sair da tela.
  state.selecionado = null
}

/** @param {Array<Object>} feicoes */
function acrescentarLotes(feicoes) {
  const novas = feicoes.filter(f => !desenhados.has(f.properties.id))
  if (!novas.length) return
  novas.forEach(f => desenhados.add(f.properties.id))
  adicionarAoMapa({ type: 'FeatureCollection', features: novas }, abrirFicha)
  // A vizinhança muda conforme novos lotes entram, então a coloração e os
  // rótulos de grupo são refeitos — UMA vez por leva, e não por bloco: com a
  // carga em blocos chegavam até 12 respostas seguidas, e refazer tudo a cada
  // uma custava segundos de tela parada (medido no navegador com 50 mil lotes).
  agendarRepintura()
}

let _repinturaAgendada = null

/** Refaz cores e rótulos de grupo no próximo respiro do navegador, uma vez só. */
function agendarRepintura() {
  if (_repinturaAgendada) return
  _repinturaAgendada = setTimeout(() => {
    _repinturaAgendada = null
    if (typeof atualizarCoresAposCarga === 'function') atualizarCoresAposCarga()
  }, 60)
}

/** Atualiza o chip de estado no canto do mapa. @param {string} [texto] */
function atualizarChip(texto) {
  const el = document.getElementById('chip-txt')
  if (texto) { el.textContent = texto; return }
  el.textContent = state.truncado
    ? `${state.lotes.size} lotes — mostrando parte, aproxime mais`
    : `${state.lotes.size} lotes carregados`
}

/**
 * Pede ao servidor o retângulo de toda a base e enquadra o mapa nele.
 * Se falhar, cai no centro do município — melhor um mapa deslocado do que
 * uma tela em branco.
 */
async function enquadrarBase() {
  try {
    const r = await fetch('/api/mapa/extensao', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!d.extensao) throw new Error('base vazia')
    const e = d.extensao

    // Guardada para o botão "ver tudo" usar como segunda opção, quando o
    // perímetro urbano não estiver configurado: é a extensão REAL do que está
    // cadastrado, e cresce sozinha a cada bairro importado.
    mapaState.extensao = e
    mapaState.obj.fitBounds([[e.sul, e.oeste], [e.norte, e.leste]], { padding: [30, 30] })
  } catch (erro) {
    console.warn(erro)
    mapaState.obj.setView([-15.5556, -54.2961], 13)
  }
}

/** O contêiner do mapa só tem dimensão quando a aba Mapa está ativa. */
function mapaVisivel() {
  const el = document.getElementById('map')
  return !!el && el.offsetWidth > 0 && el.offsetHeight > 0
}

/**
 * Enquadra a base e carrega os lotes — uma vez só, no primeiro momento em
 * que a aba Mapa fica visível.
 *
 * Separado do bootstrap porque o app abre no Painel: enquadrar um mapa de
 * tamanho zero não posiciona nada, e era isso que deixava a base pela metade.
 */
async function prepararMapa() {
  if (!mapaVisivel() || mapaState.preparando) return
  mapaState.preparando = true
  mostrarCarregandoTela('Carregando mapa...')
  try {
    if (!mapaState.pronto) {
      await enquadrarBase()
      mapaState.pronto = true
      // O contorno dos bairros entra depois do enquadramento: os rótulos
      // permanentes precisam de um mapa já com centro e zoom. Sem await —
      // ele não segura a carga dos lotes.
      if (typeof carregarContornosDosBairros === 'function') { carregarContornosDosBairros() }
    }
    // Aguarda também a carga que o moveend iniciou durante o enquadramento.
    await carregarLotesVisiveis()
  } finally {
    mapaState.preparando = false
    esconderCarregandoTela()
  }
}

/** Ponto de entrada. */
async function bootstrap() {
  iniciarMapa()
  mapaState.obj.on('moveend', carregarLotesVisiveis)

  if (window.PODE_VER_DOCUMENTOS) {
    carregarNotificacoes()
    carregarPainel()
  } else {
    // Externo: não há Painel para ele; o sistema abre no mapa.
    irPara('mapa')
  }
  prepararMapa()   // sem efeito se a aba Mapa ainda não estiver visível

  // Enter = Buscar nos campos de texto dos três filtros. As listas não buscam
  // mais sozinhas (ver `filtrarDocumentos`), e sem isto escrever e apertar
  // Enter — que é o que a mão faz — não produziria nada.
  enterBusca(['doc-busca'], carregarDocumentos)
  enterBusca(['dm-busca'], carregarDemandas)
  enterBusca(['bs-inscricao', 'bs-quadra', 'bs-lote', 'bs-numero',
              'bs-bci-de', 'bs-bci-ate', 'bs-logradouro'], executarBusca)
}

// ── FICHA DO IMÓVEL ──────────────────────────────────────────

/**
 * Abre a ficha do lote — embrião da "Consulta de Imóvel" do §11 do projeto.
 * Hoje mostra o que a base GIS tem; proprietário, obra e histórico entram com
 * a integração do cadastro imobiliário (Etapa 4).
 *
 * @param {Object} feicao feição GeoJSON do lote
 */
function abrirFicha(feicao) {
  const p = feicao.properties
  state.selecionado = feicao

  // Chegar a uma ficha zera a memória de volta: ou se acabou de voltar para
  // ela, ou se abriu outra — e nos dois casos a origem anterior não vale
  // mais. Sem isto, um caminho de saída que eu não tenha previsto deixaria a
  // memória presa, e a PRÓXIMA janela aberta pela lista devolveria alguém a
  // uma ficha de onde nunca saiu.
  fichaDeOrigem = null

  // O título é fixo — "Ficha Imóvel". A inscrição saiu dele e desceu para a
  // faixa de campos: no cabeçalho ela competia com o nome da tela, e quem
  // abre a ficha já sabe qual imóvel abriu.
  document.getElementById('fi-inscricao').textContent = p.inscricao || 'sem inscrição'

  document.getElementById('fi-endereco').innerHTML = montarEndereco(p)

  // A situação nasce "Ativo" e é corrigida pelo resumo, que chega junto do
  // histórico. Só o mapa nunca traz lote inativo, então este é o estado certo
  // enquanto a resposta não volta — e não um travessão que pisca.
  const sit = document.getElementById('fi-situacao')
  sit.className = 'badge bd-ok'
  sit.textContent = 'Ativo'
  // Data da última carga do cadastro municipal em que este imóvel veio. Antes
  // vinha de `p.integrado_em`, que o servidor nunca mandou — o cabeçalho dizia
  // "—" para todos. Agora vem do BCI (a mesma ida ao servidor serve à aba).
  // Sem leitura, o travessão: inventar "hoje" faria o dado parecer conferido.
  preencherIntegracao(p.id)

  document.getElementById('fi-area').textContent = fmtNum(p.area_gis_m2) + ' m²'

  // SEM GEOMETRIA A FICHA AINDA ABRE.
  //
  // `abrirFichaPorLote` entrega `geometry: null` de propósito quando o lote
  // está fora do trecho de mapa já carregado, e é assim que chega o imóvel
  // resolvido pelo GPS. Chamar `centroide` nisso estourava, e a ficha não
  // abria — justamente no caminho de campo: o fiscal em pé no lote, tocando
  // "usar minha localização". Sem o desenho não há centro para calcular, mas
  // tudo o mais da ficha existe; o travessão diz que a coordenada não veio.
  const c = feicao.geometry ? centroide(feicao.geometry) : null
  document.getElementById('fi-coord').textContent =
    c ? `${c.lat.toFixed(6)}, ${c.lon.toFixed(6)}` : '—'

  subFicha('dados')

  const linhaDist = document.getElementById('fi-linha-dist')
  if (state.pos && c) {
    const d = distanciaM(state.pos.lat, state.pos.lon, c.lat, c.lon)
    document.getElementById('fi-dist').textContent = `${Math.round(d)} m de você`
    linhaDist.style.display = ''
  } else {
    linhaDist.style.display = 'none'
  }

  // O histórico é o motivo de a ficha existir antes da vistoria: saber que o
  // imóvel já foi notificado muda o que o fiscal faz na visita de hoje.
  if (p.id) carregarHistorico(p.id)

  openModal('m-ficha')
}

/** @param {string} nome aba da ficha do imóvel */
function subFicha(nome) {
  document.querySelectorAll('#m-ficha .sub-abas button')
    .forEach(b => b.classList.toggle('at', b.dataset.fi === nome))
  document.querySelectorAll('#m-ficha .fi-painel').forEach(p => {
    const alvo = nome === 'historico' ? 'fi-historico-painel' : 'fi-' + nome
    p.classList.toggle('at', p.id === alvo)
  })

  // O cadastro imobiliário é buscado ao ABRIR a aba, não junto da ficha: o
  // mapa carrega até 3.000 lotes de uma vez. As edificações seguem a mesma
  // regra e pelo mesmo motivo — são geometria, e geometria é cara.
  if (nome === 'cadastro') { carregarBci(state.selecionado?.properties?.id) }
  if (nome === 'croquis') {
    carregarEdificacoes(state.selecionado?.properties?.id).then(renderCroquis)
  }
}

// `montarInscricao` SAIU DAQUI.
//
// Ela montava 01.BBB.QQQ.LLLL.DDD no navegador e, quando não sabia o código do
// bairro — que a ficha nunca recebeu, e que a busca sequer passava —, escrevia
// 000 no lugar dele. A intenção estava dita no comentário ("melhor um campo
// visivelmente incompleto do que um número plausível e errado"), mas 000 não é
// visivelmente incompleto: é um número de bairro, com a mesma cara dos outros,
// numa tela de onde se copia inscrição para dentro de auto de infração.
//
// Agora quem monta é o servidor (App\Support\InscricaoImobiliaria), num lugar
// só, e ele devolve NULO quando o bairro do desenho ainda não foi amarrado a um
// bairro do cadastro. A tela mostra "sem inscrição", que é o que se sabe.

/**
 * Endereço no MESMO desenho da linha de baixo: rótulo e valor, aos pares.
 *
 * Antes era uma frase corrida com pontos no meio — "logradouro · Qd. 05 · Lt.
 * 1 · Jardim Europa IV" —, e ela obrigava a ler tudo para achar o lote. Com o
 * rótulo pequeno em cima do dado, cada informação se acha sozinha, e as duas
 * linhas passam a ler como um bloco só de identificação do imóvel.
 *
 * O ponto separador sai junto: quem separa agora é o rótulo seguinte.
 */
function montarEndereco(p) {
  const via = [p.logradouro, p.numero_predial].filter(Boolean).join(', ')

  const par = (rot, val, mono) =>
    `<span><span class="fi-rot">${rot}</span>`
    + `<span class="${mono ? 'mono ' : ''}fi-fixo-val">${val}</span></span>`

  return [
    // O logradouro vem SEM rótulo: "Rua Antônio João" já se anuncia como
    // logradouro pelo próprio tipo, e escrever "LOGRADOURO" antes seria
    // dizer duas vezes a mesma coisa. Sem cadastro, o texto muda para dizer
    // o que falta — é aí que um rótulo faria falta, e ele vira a própria
    // frase.
    via
      ? `<span class="fi-fixo-val fi-via">${esc(via)}</span>`
      : '<span class="fi-fixo-val fi-via fi-via-vazio">logradouro não cadastrado</span>',
    // Quadra e lote abreviados, e em monoespaçada como a inscrição da linha
    // de baixo: são códigos, e é assim que se comparam dois lotes um sob o
    // outro.
    par('Qd.', esc(p.quadra ?? '—'), true),
    par('Lt.', esc(p.numero_lote ?? '—'), true),
    bairroDe(p) ? par('Bairro', esc(bairroDe(p))) : '',
  ].filter(Boolean).join('')
}

/**
 * O nome do bairro que vale FORA DO MAPA — o oficial, do cadastro.
 *
 * O lote viaja com os dois: `bairro` é o que está escrito no desenho
 * ("Residencial Buritis V") e rotula o mapa; `bairro_oficial` é como o
 * registro do município chama o mesmo lugar ("RESIDENCIAL BURITIS PRIMAVERA V
 * - PRIME"), e é ele que vai para ficha, vistoria e peça — um auto que cite o
 * bairro pelo apelido da planta cita um bairro que não existe no cadastro.
 *
 * Cai no do desenho quando não há oficial: sem amarração, é o único nome que
 * se conhece, e a alternativa seria um campo vazio.
 *
 * @param {Object} p propriedades do lote
 * @returns {string}
 */
function bairroDe(p) {
  return p?.bairro_oficial || p?.bairro || ''
}

/**
 * Data e hora curtas — dd/mm/aa - hh:mm — para o cabeçalho da ficha.
 * Ano com dois dígitos porque ali o espaço é o que sobra ao lado do título.
 */
function formatarDataHoraCurta(iso) {
  const d = new Date(iso)
  if (isNaN(d)) { return '—' }
  const dd = n => String(n).padStart(2, '0')
  return `${dd(d.getDate())}/${dd(d.getMonth() + 1)}/${dd(d.getFullYear() % 100)}`
       + ` - ${dd(d.getHours())}:${dd(d.getMinutes())}`
}

// ── DE ONDE A JANELA FOI ABERTA ──────────────────────────────
//
// Vistoria e documento podem ser abertos de dois lugares: da lista do módulo
// (Documentos, Protocolo & OS) ou de dentro da ficha do imóvel. Fechados, os
// dois caíam no mesmo lugar — a lista —, e quem tinha vindo da ficha era
// devolvido a um lugar onde não estava. Pior: perdia o imóvel que estava
// examinando e tinha de procurá-lo de novo no mapa.
//
// Guardar a ficha de origem custa uma variável e resolve os dois casos: ao
// fechar, ao gravar, ao lavrar ou ao cancelar, volta-se para o imóvel — e a
// ficha se reabre buscando o histórico, então o que acabou de ser feito já
// aparece nele.

/** @type {Object|null} a feição do lote de cuja ficha a janela saiu */
let fichaDeOrigem = null

/** Marca que a próxima janela nasceu da ficha do imóvel aberta agora. */
function lembrarFichaDeOrigem() {
  fichaDeOrigem = state.selecionado ?? null
}

/**
 * Volta para a ficha de onde se veio, se houve uma.
 *
 * A memória é consumida: uma volta só. Sem isso, fechar a ficha e abrir um
 * documento pela lista devolveria à ficha antiga — que é o mesmo defeito, do
 * outro lado.
 *
 * @returns {boolean} se voltou
 */
function voltarAFicha() {
  if (!fichaDeOrigem) { return false }

  const f = fichaDeOrigem
  fichaDeOrigem = null
  // `abrirFicha` recarrega o histórico: a vistoria ou o documento que acabou
  // de ser gravado já aparece na linha do tempo do imóvel.
  abrirFicha(f)
  return true
}

/** Esquece a origem — para quem sai por um caminho que não volta à ficha. */
function esquecerFichaDeOrigem() {
  fichaDeOrigem = null
}

/** Abre a ficha a partir do que a API devolveu (sem geometria). @param {Object} lote */
function abrirFichaPorLote(lote) {
  const f = state.lotes.get(lote.id)
  if (f) { destacarPorId(lote.id); abrirFicha(f); return }
  // O lote pode estar fora do bbox já carregado — mostra o que a API deu.
  abrirFicha({ properties: lote, geometry: null })
}

// `novaVistoria()` vive em vistoria.js — é o formulário da Etapa 5.

// ── GPS → LOTE (resolvido no servidor) ───────────────────────

/** Handler do botão "Usar minha localização". Toggle, como em geolocalizacao.js. */
function usarMinhaLocalizacao() {
  const btn = document.getElementById('btn-gps')
  if (btn.dataset.gpsCapturado) { removerGPS(); return }

  if (!navigator.geolocation) { toast('GPS não disponível neste aparelho', 'err'); return }
  btn.disabled = true
  rotuloGps('Localizando…')

  navigator.geolocation.getCurrentPosition(
    async pos => {
      btn.disabled = false
      const { latitude: lat, longitude: lon, accuracy: prec } = pos.coords
      state.pos = { lat, lon, prec }
      marcarMinhaPosicao(lat, lon, prec)
      btn.classList.add('gps-ativo')
      btn.dataset.gpsCapturado = '1'
      rotuloGps('Remover GPS')
      mapaState.obj.setView([lat, lon], 18)
      await identificarNoServidor(lat, lon, prec)
    },
    err => {
      btn.disabled = false
      rotuloGps('Usar minha localização')

      // Permissão negada não é um aviso passageiro: é uma trava que só o
      // usuário destrava, e num lugar que ele não vai adivinhar. Por isso vai
      // para um modal com o caminho exato, e não para um toast que some em
      // dois segundos deixando o fiscal sem saber o que fazer.
      if (err.code === 1) {
        confirmarAcao({
          titulo: 'Localização bloqueada',
          mensagem: comoLiberarLocalizacao(),
          textoBtn: 'Entendi',
          onConfirm: () => {},
        })

        return
      }

      toast({ 2: 'Sinal de GPS fraco. Tente a céu aberto.',
              3: 'O GPS demorou demais para responder. Tente de novo.' }[err.code]
            || 'Erro ao obter GPS', 'err')
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  )
}

/** Quebra de linha das instruções — ver o `white-space` de #mcg-msg. */
const SALTO = '\n'

/**
 * O caminho para destravar a localização, no aparelho de quem está lendo.
 *
 * No iPhone são DUAS travas em lugares diferentes, e é por isso que a
 * instrução genérica ("permita nas configurações") não resolve: o app precisa
 * de permissão nos Ajustes do iOS E o site precisa de permissão dentro do
 * navegador. Quem só mexe numa continua sem GPS e conclui que o sistema está
 * quebrado.
 */
function comoLiberarLocalizacao() {
  const ua = navigator.userAgent
  const iOS = /iPad|iPhone|iPod/.test(ua)
    // iPad recente se apresenta como Mac; o toque no lugar do mouse denuncia.
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  const chromeNoIOS = iOS && /CriOS/.test(ua)

  if (chromeNoIOS) {
    return [
      'No iPhone, o Chrome precisa de duas permissões:',
      '1) Ajustes do iPhone > Privacidade e Segurança > Serviços de Localização'
        + ' > Chrome > "Ao Usar o App".',
      '2) No Chrome, três pontos > Configurações > Configurações do site >'
        + ' Localização, e permita para fiscobras.duckdns.org.',
      'Depois feche a aba e abra o sistema de novo.',
    ].join(SALTO + SALTO)
  }

  if (iOS) {
    return [
      'No iPhone, o Safari precisa de duas permissões:',
      '1) Ajustes do iPhone > Privacidade e Segurança > Serviços de Localização'
        + ' > Safari > "Ao Usar o App".',
      '2) Ajustes > Apps > Safari > Localização > Perguntar ou Permitir.',
      'Depois recarregue a página e toque no botão outra vez.',
    ].join(SALTO + SALTO)
  }

  return [
    'O navegador está bloqueando a localização deste site.',
    'Toque no cadeado ao lado do endereço, encontre "Localização" e mude para'
      + ' Permitir. Depois recarregue a página.',
  ].join(SALTO + SALTO)
}

/** Limpa a posição capturada. */
function removerGPS() {
  confirmarAcao({
    titulo: 'Remover localização',
    mensagem: 'Deseja limpar a sua posição do mapa?',
    textoBtn: 'Remover',
    perigo: true,
    onConfirm: () => {
      state.pos = null
      limparMinhaPosicao()
      const btn = document.getElementById('btn-gps')
      btn.classList.remove('gps-ativo')
      btn.dataset.gpsCapturado = ''
      rotuloGps('Usar minha localização')
      toast('Localização removida')
    },
  })
}

/**
 * Pergunta ao servidor em qual lote está a coordenada.
 * @param {number} lat @param {number} lon @param {number} prec
 */
async function identificarNoServidor(lat, lon, prec) {
  mostrarCarregandoTela('Identificando o imóvel...')
  try {
    const r = await fetch('/api/localizacao/identificar', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        // A rota vive no grupo `web` (ver routes/web.php), logo passa pelo
        // VerifyCsrfToken. Sem este cabeçalho, todo POST volta 419.
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({ lat, lon, accuracy: prec }),
    })
    // 419 = sessão expirada. Recarregar devolve o usuário ao login, que é o
    // comportamento certo: continuar tentando com token morto só gera erro.
    if (r.status === 419) { toast('Sessão expirada. Recarregando...', 'err'); setTimeout(() => location.reload(), 1500); return }
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()

    if (d.resultado === 'exato') {
      toast(`Você está no lote ${d.lote.numero_lote}, quadra ${d.lote.quadra}`)
      abrirFichaPorLote(d.lote)
      return
    }
    if (d.resultado === 'nenhum') {
      toast(`Nenhum lote cadastrado num raio de ${Math.round(d.tolerancia)} m.`, 'err')
      return
    }
    abrirConfirmacao(d.candidatos, prec)
  } catch (e) {
    console.error(e)
    toast('Falha ao identificar o imóvel', 'err')
  } finally {
    esconderCarregandoTela()
  }
}

/**
 * Tela de confirmação do §9 do projeto: o sistema não adivinha, oferece.
 * @param {Array<Object>} candidatos @param {number} prec
 */
function abrirConfirmacao(candidatos, prec) {
  document.getElementById('cf-precisao').textContent = `Precisão do GPS: ±${Math.round(prec)} m`
  document.getElementById('cf-lista').innerHTML = candidatos.map((c, i) => `
    <div class="opcao${i === 0 ? ' sel' : ''}" data-id="${c.id}" onclick="selecionarOpcao(this)">
      <div class="radio"></div>
      <div class="txt">
        <div class="t1">Lote ${esc(c.numero_lote)} · Quadra ${esc(c.quadra)}</div>
        <div class="t2">${esc(c.bairro)} · ${fmtNum(c.area_gis_m2)} m²</div>
      </div>
      <div class="dist">${Math.round(c.dist_m)} m</div>
    </div>`).join('')

  // Guarda os candidatos para o Confirmar não precisar de nova ida ao servidor.
  state._candidatos = candidatos
  openModal('m-confirmar-lote')
}

/** @param {HTMLElement} el */
function selecionarOpcao(el) {
  el.parentElement.querySelectorAll('.opcao').forEach(o => o.classList.remove('sel'))
  el.classList.add('sel')
}

/** Confirma a escolha do fiscal e abre a ficha. */
function confirmarLote() {
  const sel = document.querySelector('#cf-lista .opcao.sel')
  if (!sel) { toast('Selecione um lote', 'err'); return }
  const id = Number(sel.dataset.id)
  const lote = (state._candidatos || []).find(c => c.id === id)
  fModalBtn('m-confirmar-lote')
  if (lote) abrirFichaPorLote(lote)
}

/**
 * Enquadra o PERÍMETRO URBANO do município.
 *
 * Não é a extensão dos lotes cadastrados, e a diferença importa: a base cobre
 * hoje dois loteamentos no extremo noroeste, enquanto quem aperta "ver tudo"
 * quer ver a cidade inteira — inclusive o que ainda não foi levantado, que é
 * justamente a informação de quanto falta.
 *
 * O retângulo vem do servidor (config/gis.php), porque é configuração de
 * município. Sem ele, cai na extensão da base, e depois no limite do mapa:
 * nesta ordem, o botão nunca fica sem efeito.
 */
function verTudo() {
  const mapa = mapaState.obj

  if (typeof PERIMETRO_URBANO !== 'undefined' && PERIMETRO_URBANO?.length === 4) {
    const [oeste, sul, leste, norte] = PERIMETRO_URBANO
    mapa.fitBounds([[sul, oeste], [norte, leste]], { padding: [12, 12] })
    return
  }

  const e = mapaState.extensao
  if (e) {
    mapa.fitBounds([[e.sul, e.oeste], [e.norte, e.leste]], { padding: [24, 24] })
    return
  }

  mapa.fitBounds(LIMITE_MUNICIPIO)
}

document.addEventListener('DOMContentLoaded', bootstrap)

/**
 * Alterna entre as abas.
 *
 * O Leaflet calcula o tamanho do contêiner na criação; se o mapa estava
 * escondido (display:none) quando isso aconteceu, ele volta com dimensão
 * zerada. Daí o invalidateSize() ao reentrar na aba.
 *
 * `ordem` precisa espelhar a sequência dos botões da barra: o realce é feito
 * por índice, não por nome. Ao acrescentar uma aba, acrescente aqui.
 *
 * @param {'painel'|'mapa'|'documentos'|'protocolos'} destino
 */
function irPara(destino) {
  if (destino !== 'mapa' && typeof PranchetaCad !== 'undefined' && PranchetaCad.ativa()) {
    PranchetaCad.fechar().then(fechou => { if (fechou) irPara(destino) })
    return
  }
  // Trocar de módulo abandona o contexto do imóvel: não há para onde voltar.
  fichaDeOrigem = null

  document.querySelectorAll('.tela').forEach(t => t.classList.remove('at'))
  document.getElementById('t-' + destino).classList.add('at')

  // A aba marcada é a do DESTINO, e não a da posição: o externo não tem
  // Painel, Documentos nem Protocolo, e contar por índice marcaria a aba
  // errada assim que faltasse uma.
  document.querySelectorAll('.aba').forEach(a => {
    const esta = a.dataset.destino === destino
    a.classList.toggle('at', esta)
    if (esta) a.setAttribute('aria-current', 'page')
    else a.removeAttribute('aria-current')
  })

  marcarModuloNoSubcabecalho(destino)

  // Sobreposicoes do mapa que vivem fora de `#t-mapa` nao somem sozinhas ao
  // trocar de modulo — a barra do modo cadastral e a unica hoje, e ela sabe
  // se esconder quando o mapa nao esta a vista.
  if (typeof pintarBarraCadastral === 'function') { pintarBarraCadastral() }
  if (typeof fecharPaineisMapa === 'function' && destino !== 'mapa') { fecharPaineisMapa() }
  if (destino !== 'mapa' && typeof fecharMesaCadastral === 'function') { fecharMesaCadastral() }

  if (destino === 'busca') {
    prepararBusca()
  } else if (destino === 'mapa') {
    // O Leaflet mede o contêiner na criação; se o mapa estava escondido,
    // volta com dimensão zerada. Daí o invalidateSize ao reentrar.
    setTimeout(() => {
      mapaState.obj?.invalidateSize()
      rotulosPorZoom()
      prepararMapa()
    }, 60)
  } else if (destino === 'documentos') {
    carregarDocumentos()
  } else if (destino === 'protocolos') {
    // A tela virou UMA fila com protocolos e ordens de serviço juntos.
    carregarDemandas()
  } else if (destino === 'painel') {
    carregarPainel()
  }
}

/**
 * Texto do botão de GPS.
 *
 * O botão virou ícone quadrado na coluna de controles — não há mais rótulo
 * visível para trocar. O estado passa a viver no `title`, que é o que o
 * usuário lê ao pousar o ponteiro e o que o leitor de tela anuncia. A cor do
 * botão (.gps-ativo) continua sendo o aviso imediato de que há posição
 * capturada.
 *
 * @param {string} texto
 */
function rotuloGps(texto) {
  const btn = document.getElementById('btn-gps')
  if (btn) { btn.title = texto; btn.setAttribute('aria-label', texto) }
}

// ── SUB-CABEÇALHO ────────────────────────────────────────────

/**
 * Nome e ícone de cada módulo, no sub-cabeçalho.
 *
 * O rótulo é copiado do botão do rodapé em tempo de execução, e não escrito de
 * novo aqui: rótulo repetido em dois lugares vira rótulo divergente na
 * primeira vez que alguém renomear um só deles.
 */
function marcarModuloNoSubcabecalho(destino) {
  // Pelo destino, e não pela posição: o externo tem menos abas.
  const botao = document.querySelector(`.aba[data-destino="${destino}"]`)
  if (!botao) return

  const nome = document.getElementById('subcab-nome')
  const ico = document.getElementById('subcab-ico')
  if (nome) nome.textContent = botao.textContent.trim()
  if (ico) ico.innerHTML = botao.querySelector('svg')?.outerHTML || ''
}

/**
 * "Últ. Integração" do cabeçalho da ficha.
 * @param {number} loteId
 */
async function preencherIntegracao(loteId) {
  const el = document.getElementById('fi-integracao')
  el.textContent = '—'
  if (!loteId || typeof obterBci !== 'function') { return }
  try {
    const d = await obterBci(loteId)
    // Outro imóvel pode ter sido aberto enquanto a resposta vinha.
    if (state.selecionado?.properties?.id !== loteId) { return }
    // A data da CARGA em que o imóvel veio na planilha, mudando ou não.
    const g = d.integracao || {}
    if (g.ausente_desde) {
      el.textContent = 'fora do cadastro desde ' + formatarDataHoraCurta(g.ausente_desde).slice(0, 8)
    } else if (g.em) {
      el.textContent = formatarDataHoraCurta(g.em)
    }
  } catch { /* fica o travessão */ }
}
