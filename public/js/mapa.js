// ══════════════════════════════════════════════
// MÓDULO: MAPA (Leaflet)
//
// Duas camadas de fundo: mapa claro do CartoDB (mesmo tile do AppPOSTURAS —
// discreto, para não competir com os polígonos) e satélite da Esri, que é o
// que o fiscal quer ver quando está conferindo obra em campo.
//
// A camada de lotes é ACUMULATIVA: os dados chegam por bbox conforme o mapa se
// move, então cada resposta acrescenta polígonos em vez de recriar a camada.
// Recriar apagaria o que já está desenhado e faria o mapa piscar a cada arrasto.
// ══════════════════════════════════════════════

/** Estado do mapa. Objeto mutável simples, como em core/state.js do AppPOSTURAS. */
const mapaState = {
  /** @type {L.Map|null} */     obj: null,
  /** @type {L.GeoJSON|null} */ camadaLotes: null,
  /** @type {L.Marker|null} */  marcadorEu: null,
  /** @type {L.Circle|null} */  precisaoEu: null,
  /** @type {L.Path|null} */    destacado: null,
  /** id do lote -> camada Leaflet, para destacar sem varrer a camada inteira */
  porId: new Map(),
  /** todas as camadas de lote, para repintar na coloração por adjacência */
  camadas: [],
  /** já enquadrado na base? só ocorre quando a aba Mapa fica visível */
  pronto: false,
}

/** Cores dos polígonos. Hex puro: o Leaflet não lê variável CSS. */
const COR = { lote: '#006C16', loteFundo: '#009B3A', destaque: '#F5C400' }

/**
 * Retângulo inicial de navegação, trocado pelo bbox real do município assim
 * que a malha do IBGE carrega (ver recortarMunicipio). Existe só para o mapa
 * já nascer travado, antes do fetch responder.
 */
let LIMITE_MUNICIPIO = [[-15.70, -54.75], [-14.58, -53.72]]

function estiloLote() {
  return { color: COR.lote, weight: 1, opacity: .85, fillColor: COR.loteFundo, fillOpacity: .18 }
}
function estiloDestaque() {
  return { color: COR.destaque, weight: 3, opacity: 1, fillColor: COR.destaque, fillOpacity: .38 }
}

/**
 * Até onde o mapa aproxima. Passa do último zoom do Google (20) de propósito:
 * a imagem não melhora — o Leaflet amplia o tile de 20 (`maxNativeZoom`) —,
 * mas o DESENHO melhora: vértice, divisa curta e medida de lado ficam legíveis.
 */
const ZOOM_MAXIMO = 22

/**
 * Aplica o nível de detalhe da área visível (nivelDoMapa, em app.js):
 *  - classe `nivel-*` no <body>, que decide os rótulos por CSS (nome da
 *    cidade, nomes dos bairros, números das quadras);
 *  - cada camada só é pintada no seu nível. Os lotes já carregados SAEM da
 *    pintura fora do nível deles: o canvas vive no `overlayPane`, e
 *    escondê-lo poupa o navegador de redesenhar milhares de polígonos a cada
 *    arrasto numa escala em que seriam só uma mancha. Continuam em memória:
 *    ao aproximar, voltam sem novo pedido ao servidor.
 */
function aplicarNivelDoMapa() {
  const m = mapaState.obj
  if (!m || typeof nivelDoMapa !== 'function') return
  const nivel = nivelDoMapa(m)
  for (const n of ['municipio', 'bairros', 'quadras', 'lotes']) {
    document.body.classList.toggle('nivel-' + n, n === nivel)
  }
  const mostrar = (pane, sim) => { const p = m.getPane(pane); if (p) p.style.display = sim ? '' : 'none' }
  mostrar('overlayPane', nivel === 'lotes')
  mostrar('contornos', nivel !== 'municipio')
  mostrar('quadras', nivel === 'quadras')
}

/** Cria o mapa. Idempotente. */
function iniciarMapa() {
  if (mapaState.obj) return
  if (typeof L === 'undefined') { console.warn('Leaflet não carregado'); return }

  // BASE ÚNICA: imagem de satélite.
  //
  // O mapa vetorial saiu, e com ele o seletor de camadas. É decisão de uso,
  // não de estilo: os polígonos de quadra e lote foram digitalizados sobre
  // foto aérea, então é sobre foto que eles coincidem com o que está no chão.
  // Sobre base vetorial o desenho e a rua não batem, e o fiscal passa a
  // duvidar do dado certo.
  //
  // maxNativeZoom 17: nesta região o acervo da Esri termina no zoom 17 —
  // z18, z19 e z20 devolvem o MESMO arquivo de 2.521 bytes, que é a placa
  // cinza "Map data not yet available". Verificado no centro, no Jardim
  // Europa, no Buritis e na entrada sul, e nas 196 capturas históricas do
  // acervo Wayback: nenhuma passa de 17. Declarando o limite real, o Leaflet
  // amplia o tile de 17 em vez de pedir um que não existe.
  const satelite = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    {
      attribution: '© Esri', maxZoom: ZOOM_MAXIMO, maxNativeZoom: 17, className: 'tile-satelite',
      // Sem isto, cada zoom intermediário do GESTO (não só o final) dispara
      // pedido e decodificação de tile — trabalho de GPU/rede bem no meio do
      // dedo ainda em movimento, que é onde um aparelho fraco engasga. Com
      // `false`, o Leaflet só atualiza os tiles quando o zoom PARA
      // (`zoomend`), e mostra a imagem antiga esticada durante o gesto — o
      // mesmo efeito que já existe acima do zoom nativo, só que também no
      // meio da animação.
      updateWhenZooming: false,
    })

  mapaState.obj = L.map('map', {
    zoomControl: false, layers: [satelite],
    /*
     * CANVAS, NÃO SVG — e é isto que faz o mapa funcionar no celular.
     *
     * Com o renderizador padrão, cada lote vira um elemento <path> no DOM.
     * São 2.239 lotes no município: 2.239 elementos que o navegador precisa
     * criar, posicionar e transformar a cada arrasto e a cada zoom. No
     * desktop passa; no iOS (onde todo navegador é WebKit por baixo, Chrome
     * inclusive) o mapa fica arrastado a ponto de não dar para usar.
     *
     * No canvas os polígonos são pintados num bitmap só — um elemento, não
     * milhares. Perde-se a possibilidade de estilizar lote por CSS, o que
     * aqui não custa nada: os estilos saem todos de estiloColorido(), em
     * opções do Leaflet (color, weight, fillColor), que o canvas suporta.
     */
    preferCanvas: true,
    // Prende a navegação ao município: viscosity 1 faz a borda não ceder,
    // então arrastar para fora simplesmente não sai do lugar.
    maxBounds: LIMITE_MUNICIPIO, maxBoundsViscosity: 1, minZoom: 11, maxZoom: ZOOM_MAXIMO,
  })
  L.control.zoom({ position: 'topright' }).addTo(mapaState.obj)

  // Sem o "Leaflet |" no rodapé: o espaço ali é curto e o que precisa aparecer
  // é o crédito da IMAGEM — exigido pelos termos de uso da API e útil ao
  // fiscal, que vê de quem e de que ano é a foto que está olhando.
  //
  // `setPrefix` depois de criar o mapa, e não `attributionControl: {prefix}`
  // nas opções: o Leaflet lê essa opção só como sim/não e cria o controle sem
  // repassar nada. Passar o objeto não dá erro — dá o prefixo lá, do mesmo
  // jeito, que foi o que aconteceu aqui antes de medir no navegador.
  mapaState.obj.attributionControl.setPrefix(false)

  montarOrtofoto(satelite)

  // estiloColorido, e não estiloLote: o lote nasce já na coloração corrente
  // (uniforme, por bairro ou destacado por filtro). Com estiloLote ele nascia
  // verde e só era repintado na chamada seguinte de aplicarCores.
  mapaState.camadaLotes = L.geoJSON(null, { style: f => estiloColorido(f) }).addTo(mapaState.obj)

  // Painel dedicado aos rótulos de logradouro, acima dos lotes.
  mapaState.obj.createPane('rotulos')
  mapaState.obj.getPane('rotulos').style.zIndex = 650
  mapaState.obj.getPane('rotulos').style.pointerEvents = 'none'
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png',
    { subdomains: 'abcd', maxZoom: ZOOM_MAXIMO, maxNativeZoom: 20, pane: 'rotulos', updateWhenZooming: false }).addTo(mapaState.obj)

  montarGoogle(satelite)
  ancorarControleCores()

  // Adiado um quadro com requestAnimationFrame: `zoomend` dispara no instante
  // em que o navegador ainda está assentando a transformação CSS do zoom, e
  // `sincronizarRotulos` (dentro de rotulosPorZoom) percorre TODO lote já
  // carregado na sessão para decidir rótulo — trabalho que cresce com a
  // navegação e que, feito na mesma volta do zoomend, competia pela mesma
  // thread num aparelho fraco (relatado: Tab A9+ engasgando no zoom, o mesmo
  // gesto liso num iPhone 14). Um quadro de folga custa ~16 ms, imperceptível,
  // e deixa o navegador terminar de pintar o zoom antes de recontar rótulo.
  mapaState.obj.on('zoomend', () => {
    requestAnimationFrame(() => { rotulosPorZoom(); ajustarNitidezSatelite() })
  })

  // Arrastar também mexe nos rótulos: no zoom em que eles aparecem, cada
  // deslocamento traz lotes novos para a tela e leva outros embora.
  mapaState.obj.on('moveend', sincronizarRotulos)
  // Rótulos de bairro/quadra existem só para o que está na tela: arrastar traz
  // grupos novos para a vista (agendado, para não competir com o arrasto).
  mapaState.obj.on('moveend', () => { if (typeof agendarRotulosDeGrupo === 'function') agendarRotulosDeGrupo() })
  mapaState.obj.on('baselayerchange', () => ajustarNitidezSatelite())
  mapaState.obj.on('zoomend', aplicarNivelDoMapa)
  // A área visível também muda ao girar o tablet ou recolher o menu lateral.
  mapaState.obj.on('resize', aplicarNivelDoMapa)
  // Contorno e número das quadras: pedidos por bairro, ao chegar à escala.
  mapaState.obj.on('moveend', () => { if (typeof carregarQuadrasVisiveis === 'function') carregarQuadrasVisiveis() })

  // Duplo toque FORA de um lote larga a seleção — o mesmo gesto do Esc, para
  // quem tem o dedo no mapa e não no teclado. Cada lote consome o próprio
  // duplo toque (ver onEachFeature) antes de ele chegar aqui, então isto só
  // dispara quando o toque caiu fora de qualquer lote.
  //
  // COM ALGO SELECIONADO, O DUPLO CLIQUE SÓ DESMARCA — não aproxima o mapa. O
  // zoom do duplo clique é do Leaflet, e ele ouve o mesmo `dblclick`: quem
  // queria só soltar os lotes ganhava de brinde um mapa um nível mais perto.
  // Sem nada selecionado, o zoom continua valendo.
  //
  // Para poder vetar o zoom, este ouvinte tem de rodar ANTES do dele — daí o
  // desliga/liga em volta do registro. Desligado dentro do nosso ouvinte, o do
  // Leaflet não chega a rodar neste mesmo evento; ele volta logo em seguida.
  const zoomDuplo = mapaState.obj.doubleClickZoom
  const zoomDuploLigado = zoomDuplo.enabled()
  if (zoomDuploLigado) zoomDuplo.disable()
  mapaState.obj.on('dblclick', () => {
    const haviaSelecao = !!state.selecionado || !!mapaState.destacado
      || (typeof selState !== 'undefined' && selState.ids.size > 0)
    // Só quando ele está ligado: durante o desenho de lote quem o desliga é o
    // desenho (desenho.js), e religá-lo aqui seria passar por cima.
    if (haviaSelecao && zoomDuplo.enabled()) {
      zoomDuplo.disable()
      setTimeout(() => zoomDuplo.enable(), 0)
    }

    // `escaparSelecao` e não `desligarSelecao`: na mesa, soltar as marcas não
    // pode desarmar a marcação — desarmada, ela devolveria o clique ao balão e
    // a régua ficaria sem como acender. Ver cadastro.js.
    if (typeof selecaoAtiva === 'function' && selecaoAtiva()) {
      escaparSelecao()
      return
    }
    limparSelecao()
  })
  if (zoomDuploLigado) zoomDuplo.enable()

  recortarMunicipio()
}

/**
 * Realce leve quando a imagem aérea está sendo ampliada além do zoom que ela
 * realmente tem.
 *
 * Não inventa detalhe — isso é impossível: o pixel que não foi fotografado
 * não existe. O que o filtro faz é recuperar contraste e definição de borda
 * que a interpolação do navegador achata, deixando telhado e muro um pouco
 * mais legíveis. É um ganho de leitura, não de resolução. A solução de fato
 * é a ortofoto municipal.
 */
function ajustarNitidezSatelite() {
  const m = mapaState.obj
  if (!m) return

  const z = m.getZoom()

  // Com o Google no ar entre o zoom 18 e o nativo dele (20), a imagem no topo
  // é nativa e não precisa de realce — aplicá-lo ali só degradaria uma foto
  // boa. Acima do 20 (até ZOOM_MAXIMO) ela também é ampliada, e o realce vale.
  const g = mapaState.googleTiles
  if (g && m.hasLayer(g) && z >= (g.options.minZoom ?? 18)) {
    document.getElementById('map')?.classList.toggle('sat-ampliado', z > (g.options.maxNativeZoom ?? 20))
    return
  }

  let ampliando = false
  m.eachLayer(l => {
    if (!l.options?.className?.includes('tile-satelite')) return
    const nativo = l.options.maxNativeZoom ?? l.options.maxZoom ?? 20
    if (z > nativo) ampliando = true
  })

  document.getElementById('map')?.classList.toggle('sat-ampliado', ampliando)
}

/**
 * Imagem aérea do Google (Map Tiles API), quando configurada.
 *
 * Entra como camada BASE ao lado do satélite da Esri, e não como
 * sobreposição: cobre o município inteiro, então não há área sem imagem
 * para o Esri complementar. O acervo do Google nesta região chega ao zoom
 * 20 — contra 17 da Esri —, que é o ganho de nitidez procurado.
 *
 * A sessão vem do servidor (ver MapaController::googleSessao). Se a API não
 * estiver habilitada ou a chave for recusada, a camada simplesmente não
 * aparece e o mapa segue com o que já tinha.
 *
 * @param {L.TileLayer} satelite camada da Esri, usada como referência de ordem
 */
async function montarGoogle(satelite) {
  try {
    const r = await fetch('/api/mapa/google-sessao', { headers: { Accept: 'application/json' } })
    if (!r.ok) {
      if (r.status !== 404) {
        console.warn('Google Tiles indisponível:', (await r.json()).message)
      }
      return
    }
    const { session, key, creditos } = await r.json()

    const google = L.tileLayer(
      `https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session=${session}&key=${key}`,
      {
        // O crédito vem do próprio Google (endpoint `viewport`) e traz quem
        // produziu a imagem e o ANO dela — "Imagens ©2026 Airbus, Maxar
        // Technologies". É a única informação de tempo que a API expõe: data
        // de captura, dia e mês, ela não devolve. Exibi-lo também é exigência
        // dos termos de uso, que o "© Google" fixo não cumpria.
        attribution: creditos || '© Google', maxZoom: ZOOM_MAXIMO, maxNativeZoom: 20,
        // ── O PONTO DE ECONOMIA ──
        // minZoom 18: abaixo disso o Leaflet nem pede o tile, e a Esri (que é
        // gratuita) cobre sozinha. Como o acervo da Esri aqui para no 17, do
        // 18 em diante ela só ampliaria o mesmo pixel — que é exatamente onde
        // o Google passa a acrescentar detalhe de verdade.
        //
        // Ou seja: paga-se pelo tile só no zoom em que ele melhora a imagem.
        // Navegar, procurar bairro e enquadrar quadra acontece abaixo de 18 e
        // não gera uma requisição sequer.
        minZoom: 18,
        className: 'tile-satelite',
        // Sem isto, cada zoom INTERMEDIÁRIO do gesto de pinça pede tile — e
        // aqui cada tile é uma requisição PAGA. Com `false`, só o zoom em que
        // o dedo para conta; o Leaflet mostra a imagem anterior esticada
        // durante o gesto, que é o mesmo efeito que já existe acima do zoom
        // nativo. Ganha o bolso e ganha o aparelho fraco, que para de
        // decodificar tile a cada frame do gesto ainda em movimento.
        updateWhenZooming: false,
        // O padrão do Leaflet é 2: pré-carrega um anel de tile DUAS fileiras
        // além do que está visível, para o arrasto não mostrar vazio na
        // borda. Isto é troca de rede/decodificação por suavidade — e aqui a
        // imagem de alta resolução do Google é o que confirmadamente pesa
        // (relatado: mesmo vindo do cache do navegador, sem nova rede, a
        // segunda visita à mesma área continua mais lenta que num iPhone ou
        // desktop — ou seja, o custo é DECODIFICAR a imagem, não buscá-la).
        // Menos tile pré-carregado é menos decodificação por vez, ao custo
        // de a borda aparecer em branco por um instante num arrasto rápido.
        keepBuffer: 1,
      }
    )

    // Sobreposição sempre ligada, e não base alternativa: sem seletor, quem
    // decide qual imagem aparece é o zoom. Acima do 17 o Google cobre a Esri;
    // abaixo, ele simplesmente não existe. Se a chave for recusada ou a API
    // falhar, a Esri continua embaixo e o mapa não fica em branco.
    google.addTo(mapaState.obj)
    mapaState.googleTiles = google
  } catch (e) {
    console.warn('Não foi possível preparar a camada do Google:', e)
  }
}

/**
 * Registra o painel de cores como CONTROLE do Leaflet, no mesmo canto do
 * zoom e do seletor de camadas.
 *
 * Medir a posição e aplicar `top` no CSS não funciona: quando o mapa é
 * criado ele ainda está escondido (o app abre no Painel), então a medição
 * sai zerada e o botão gruda no topo. Como controle, quem empilha é o
 * próprio Leaflet — e a ordem se ajusta sozinha se o seletor de camadas
 * mudar de altura ao ganhar a ortofoto.
 */
function ancorarControleCores() {
  const el = document.getElementById('ctrl-mapa')
  if (!el) return

  const Cores = L.Control.extend({
    onAdd() {
      // Sem isto, clicar nos botões arrasta o mapa e a roda dá zoom.
      L.DomEvent.disableClickPropagation(el)
      L.DomEvent.disableScrollPropagation(el)
      return el
    },
    onRemove() {},
  })

  new Cores({ position: 'topright' }).addTo(mapaState.obj)
  igualarAoControleDeCamadas()
}

/**
 * Copia as medidas do seletor de camadas do Leaflet para os nossos botões.
 *
 * Fixar 36px ou 40px no CSS não resolve: o tamanho do controle de camadas
 * muda com o modo de toque (36 no ponteiro, 44 no toque) e com a versão da
 * biblioteca, e qualquer número escrito à mão fica errado num dos casos — foi
 * assim que os botões saíram ~10% menores que o vizinho. Medindo o vizinho de
 * verdade, eles casam sempre.
 */
function igualarAoControleDeCamadas() {
  const vizinho = document.querySelector('.leaflet-control-layers')
  if (!vizinho) return

  const r = vizinho.getBoundingClientRect()
  if (!r.width || !r.height) return

  document.getElementById('ctrl-mapa')?.style.setProperty('--ctrl-lado-l', r.width + 'px')
  document.getElementById('ctrl-mapa')?.style.setProperty('--ctrl-lado-a', r.height + 'px')
}

/**
 * Abre e fecha um painel da coluna de controles.
 *
 * O grupo aberto troca o ícone pelo painel (o CSS cuida disso a partir da
 * classe `.aberto`), como faz o seletor de camadas do Leaflet logo acima.
 * Só um por vez: abertos juntos, os painéis empilhados cobririam o mapa, que
 * é justamente o que recolhê-los pretendia evitar.
 *
 * @param {string} idGrupo grupo a alternar
 */
function alternarPainelMapa(idGrupo) {
  // O grupo cadastral tem casa própria em tela grande: a mesa lateral. O ícone
  // continua sendo a porta, mas o que ele abre é a coluna, não o popover — que
  // ali seria um segundo menu com exatamente os mesmos botões.
  if (idGrupo === 'grupo-cadastro' && typeof ehMesaCadastral === 'function' && ehMesaCadastral()) {
    const mesa = document.getElementById('cad-mesa')
    if (mesa) {
      fecharPaineisMapa()
      if (mesa.hidden) {
        pedirFerramenta('curadoria', () => {
          limparSelecao()
          limparSelecaoCadastral()
          abrirMesaCadastral()
        })
      } else { fecharMesaPeloUsuario() }   // fechar pelo ícone é largar (cadastro.js)
      return
    }
  }

  const grupo = document.getElementById(idGrupo)
  if (!grupo) return
  const abrindo = !grupo.classList.contains('aberto')

  fecharPaineisMapa()
  if (!abrindo) return

  // Um painel é uma ferramenta como as outras: abrir a busca encerra a
  // curadoria em curso (perguntando, se houver lote marcado), e vice-versa.
  // Ver ferramentas-mapa.js.
  const ferramenta = { 'grupo-busca': 'busca',
    'grupo-cores': 'cores', 'grupo-cadastro': 'curadoria' }[idGrupo]

  // Painel que não é ferramenta (Camadas) só abre: não disputa o mapa.
  const pedir = ferramenta ? pedirFerramenta : (_n, abrir) => abrir()
  pedir(ferramenta, () => {
    if (idGrupo === 'grupo-cadastro') {
      limparSelecao()
      limparSelecaoCadastral()
    }

    grupo.classList.add('aberto')
    grupo.querySelector('.ctrl-btn')?.setAttribute('aria-expanded', 'true')

    // O painel abre no lugar do ícone e cresce para baixo: em tela de
    // notebook o de Camadas passava do rodapé. Cabe no que sobra, e rola.
    const corpo = grupo.querySelector('.ctrl-corpo')
    if (corpo) {
      corpo.style.maxHeight = Math.max(160, window.innerHeight - corpo.getBoundingClientRect().top - 14) + 'px'
      corpo.style.overflowY = 'auto'
    }

  })
}

/**
 * Escreve (ou apaga) o selo de estado no ícone recolhido de um controle.
 *
 * Recolhido, o botão não diz nada sobre o que está aplicado — e um mapa com
 * pinos filtrados ou pintado por quadra parece um mapa comum para quem chegou
 * depois. O selo é o único aviso de que há filtro em vigor.
 *
 * @param {string} idGrupo
 * @param {string|number|null} texto null apaga o selo
 */
function marcarIndicadorControle(idGrupo, texto) {
  const btn = document.querySelector('#' + idGrupo + ' .ctrl-btn')
  if (!btn) return

  let selo = btn.querySelector('.ctrl-selo')
  if (texto === null || texto === '' || texto === 0) {
    selo?.remove()
    btn.classList.remove('com-filtro')
    return
  }

  if (!selo) {
    selo = document.createElement('span')
    selo.className = 'ctrl-selo'
    btn.appendChild(selo)
  }
  selo.textContent = String(texto)
  btn.classList.add('com-filtro')
}

/** Recolhe todos os painéis. */
function fecharPaineisMapa() {
  for (const g of document.querySelectorAll('#ctrl-mapa .ctrl-grupo')) {
    g.classList.remove('aberto')
    g.querySelector('.ctrl-btn')?.setAttribute('aria-expanded', 'false')
  }
}

// Clique fora recolhe o painel, como no seletor de camadas.
//
// O teste de "fora" é explícito, e não pode ser delegado ao Leaflet: o
// disableClickPropagation aplicado em ancorarControleCores intercepta
// mousedown, touchstart, dblclick e contextmenu — mas NÃO o `click`. Sem esta
// verificação, o clique que abria o painel subia até aqui e o fechava no mesmo
// gesto (no desktop o painel nunca abria; no tablet abria no segundo toque e
// depois fechava ao tocar em qualquer campo de dentro).
document.addEventListener('click', ev => {
  if (ev.target.closest?.('#ctrl-mapa')) return
  fecharPaineisMapa()
})

/**
 * Leva o mapa até um lote e o DESTACA.
 *
 * Centralizar só não basta: no zoom 19 cabem dezenas de lotes iguais, e quem
 * veio de uma lista (pesquisa, conferência com o cadastro) precisa achar O
 * lote sem ler número por número. Usado pela pesquisa do mapa
 * (pesquisa-mapa.js) e pela conferência da importação (importacoes.js).
 *
 * @param {number} id
 */
async function irAoLoteNoMapa(id) {
  try {
    const r = await fetch('/api/imoveis/' + id, { headers: { Accept: 'application/json' } })
    const ficha = await r.json()
    if (!r.ok) throw new Error(ficha.message || 'Imóvel não encontrado.')
    if (!ficha.lat) { toast('Imóvel sem geometria cadastrada.', 'aviso'); return }
    if (typeof irPara === 'function') irPara('mapa')
    setTimeout(() => mapaState.obj?.setView([ficha.lat, ficha.lon], ZOOM_DO_LOTE), 120)
    destacarLoteQuandoCarregar(Number(id))
  } catch (e) {
    toast(e.message, 'err')
  }
}

/** Contorno piscante do lote procurado. @type {L.GeoJSON|null} */
let realceDoLote = null

/**
 * Destaca o lote assim que ele chegar ao mapa. Os lotes do novo enquadramento
 * chegam depois do movimento, por isso a espera; e o contorno piscante fica
 * numa camada própria, que sobrevive à recarga dos lotes (que redesenha as
 * camadas e levaria o destaque junto).
 *
 * @param {number} id
 */
async function destacarLoteQuandoCarregar(id) {
  for (let t = 0; t < 40; t++) {
    await new Promise(r => setTimeout(r, 200))
    const c = mapaState.porId?.get(id)
    if (!c) continue
    destacar(c)
    realceDoLote?.remove()
    realceDoLote = L.geoJSON(c.feature, {
      interactive: false,
      // SVG de propósito: o piscar é animação CSS, e caminho em canvas não tem classe.
      renderer: L.svg(),
      style: { color: '#facc15', weight: 5, fill: false, className: 'lote-realce' },
    }).addTo(mapaState.obj)
    setTimeout(() => { realceDoLote?.remove(); realceDoLote = null }, 6000)
    return
  }
  toast('O lote não apareceu no mapa. Confira as camadas ligadas.', 'aviso')
}

/**
 * Ortofoto em modo HÍBRIDO, sobreposta ao satélite.
 *
 * A ortofoto entra como camada de cima, não como base alternativa. Isso
 * resolve dois problemas de uma vez:
 *
 *   1. cobertura parcial — a imagem municipal costuma cobrir só a mancha
 *      urbana; com `bounds`, o Leaflet nem pede tile fora dela, e o satélite
 *      continua aparecendo no resto do município;
 *   2. zoom — com `minZoom`, ela só entra quando o satélite já esgotou o que
 *      tinha (zoom 17). Abaixo disso o Esri dá contexto melhor e a ortofoto
 *      seria download desperdiçado.
 *
 * O resultado é o que o mapa deve fazer: mostrar a melhor imagem disponível
 * para aquele ponto e aquela escala, sem o fiscal escolher camada.
 *
 * @param {L.TileLayer} satelite camada base que a ortofoto complementa
 */
function montarOrtofoto(satelite) {
  const alt = window.SATELITE_ALT
  if (!alt?.url) return

  const m = mapaState.obj

  const opcoes = {
    attribution: alt.atribuicao || '',
    maxZoom: ZOOM_MAXIMO,
    maxNativeZoom: Number(alt.maxNativeZoom) || 19,
    // Só pede tile a partir do zoom em que o satélite já não ajuda.
    minZoom: Number(alt.minZoom) || 17,
    // O Mapbox serve tile de 512 px (@2x). Declarar o tamanho evita que o
    // Leaflet trate como 256 e desloque a imagem meio tile.
    tileSize: Number(alt.tamanhoTile) || 256,
    zoomOffset: Number(alt.tamanhoTile) === 512 ? -1 : 0,
    className: 'tile-satelite',
    // Fora da área coberta o tile simplesmente não é requisitado.
    bounds: alt.bounds ? L.latLngBounds(alt.bounds) : undefined,
    // Mesmo motivo das outras camadas de imagem: só atualiza no zoom em que o
    // gesto PARA, não em cada zoom intermediário do dedo em movimento.
    updateWhenZooming: false,
  }

  const orto = L.tileLayer(alt.url, opcoes)
  mapaState.ortofoto = orto

  // Sempre ligada, sem entrada em seletor: o seletor de camadas saiu junto com
  // a base vetorial. Como a ortofoto só se manifesta dentro da área e do zoom
  // dela, ligá-la de vez não atrapalha o resto — e é a melhor imagem que
  // existe onde existe.
  //
  // Ela fica ACIMA do Google porque é imagem municipal, de voo próprio e mais
  // recente; onde houver ortofoto, é ela que vale — e cada tile dela é um
  // tile do Google que deixa de ser cobrado.
  orto.addTo(m)
  orto.bringToFront?.()
}

/**
 * Recorta o mapa no limite de Primavera do Leste.
 *
 * A máscara é UM polígono com dois anéis: o externo cobre o mundo, o interno
 * é o município. Pela regra even-odd, o interior do anel interno fica de
 * fora do preenchimento — ou seja, o município aparece limpo e todo o resto
 * some sob a cor de fundo. É mais barato que recortar cada tile e funciona
 * igual nas duas bases (mapa e satélite).
 *
 * O contorno vai numa camada própria, acima dos lotes, para a divisa
 * continuar visível quando o fiscal estiver com a malha toda desenhada.
 */
async function recortarMunicipio() {
  try {
    const r = await fetch('/geo/primavera-do-leste.geojson', { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const f = await r.json()

    // GeoJSON é [lon, lat]; o Leaflet quer [lat, lon].
    const paraLatLng = anel => anel.map(([lon, lat]) => [lat, lon])
    const aneis = f.geometry.type === 'MultiPolygon'
      ? f.geometry.coordinates.flat().map(paraLatLng)
      : f.geometry.coordinates.map(paraLatLng)

    const mundo = [[-90, -360], [-90, 360], [90, 360], [90, -360]]

    mapaState.obj.createPane('mascara')
    mapaState.obj.getPane('mascara').style.zIndex = 350
    mapaState.obj.getPane('mascara').style.pointerEvents = 'none'

    L.polygon([mundo, ...aneis], {
      pane: 'mascara', stroke: false, fillColor: '#FAF7F4', fillOpacity: .93,
      interactive: false,
    }).addTo(mapaState.obj)

    const contorno = L.polygon(aneis, {
      pane: 'rotulos', color: '#EA580C', weight: 1.6, opacity: .75,
      fill: false, interactive: false,
    }).addTo(mapaState.obj)

    // NOME DA CIDADE, no centro da malha — só no nível do município (CSS
    // `nivel-municipio`, ver aplicarNivelDoMapa). É o primeiro degrau do nível
    // de detalhe: município → bairros → quadras → lotes → medidas.
    // Sobre a CIDADE (o retângulo do perímetro urbano, config/gis.php), e não
    // no centro do município: este fica no meio da lavoura, longe de tudo o
    // que o fiscal procura.
    const centro = typeof PERIMETRO_URBANO !== 'undefined' && PERIMETRO_URBANO?.length === 4
      ? [(PERIMETRO_URBANO[1] + PERIMETRO_URBANO[3]) / 2, (PERIMETRO_URBANO[0] + PERIMETRO_URBANO[2]) / 2]
      : contorno.getBounds().getCenter()
    L.marker(centro, {
      interactive: false, keyboard: false,
      icon: L.divIcon({ className: '', html: '', iconSize: [0, 0] }),
    }).bindTooltip((f.properties?.nome || 'Primavera do Leste') + ' - MT', {
      permanent: true, direction: 'center', className: 'rot rot-cidade',
    }).addTo(mapaState.obj)

    // Limite de navegação = o próprio município, com uma folga pequena para
    // a divisa não colar na borda da tela.
    LIMITE_MUNICIPIO = contorno.getBounds().pad(0.04)
    mapaState.obj.setMaxBounds(LIMITE_MUNICIPIO)
  } catch (e) {
    // Sem a malha o mapa continua utilizável: perde o recorte, mantém o
    // retângulo de navegação declarado acima.
    console.warn('Não foi possível carregar o limite do município:', e)
  }
}

/**
 * Acrescenta lotes à camada existente, sem recriá-la.
 * @param {Object} geojson FeatureCollection
 * @param {(feicao:Object)=>void} aoClicar
 */
function adicionarAoMapa(geojson, aoClicar) {
  L.geoJSON(geojson, {
    // A coloração corrente vale já na criação — ver o comentário em
    // iniciarMapa(). Nascer verde e ser repintado depois fazia o lote piscar.
    style: f => estiloColorido(f),
    onEachFeature: (feicao, camada) => {
      mapaState.porId.set(feicao.properties.id, camada)
      mapaState.camadas.push(camada)
      // Clique abre o BALÃO, não a ficha: a maior parte das consultas em
      // campo é "que lote é este?", e para isso abrir um modal de tela cheia
      // é caro demais. A ficha completa fica a um toque de distância, dentro
      // do balão.
      //
      // Em modo de correção cadastral o clique MARCA o lote em vez de abrir o
      // balão — ver cadastro.js. Fora dele, nada muda.
      camada.on('click', () => {
        // Desenhando um trecho de rua (ruas-manuais.js), o toque é ponto do
        // trecho — quem o trata é o clique no mapa, não o lote embaixo.
        if (typeof ruaState !== 'undefined' && ruaState.desenhando) return
        if (typeof selecaoAtiva === 'function' && selecaoAtiva()) {
          alternarSelecao(feicao, camada)
          return
        }
        destacar(camada)
        abrirBalao(feicao, camada)
      })
      // Duplo toque NO lote é do lote — para de subir até o mapa. Sem isto,
      // o duplo toque para dar zoom no imóvel também disparava o "duplo toque
      // fora larga a seleção" do mapa, no mesmo gesto.
      camada.on('dblclick', ev => L.DomEvent.stopPropagation(ev))
      // O número do lote fica GUARDADO, não criado.
      //
      // Antes, cada lote recebia aqui um tooltip permanente, escondido por CSS
      // abaixo do zoom 18. Escondido por CSS continua existindo: eram 2.239
      // elementos que o Leaflet reposicionava a cada arrasto e a cada zoom,
      // invisíveis e caros — a maior fonte de lentidão do mapa no celular.
      //
      // Agora o rótulo nasce só quando vai ser visto, e só para os lotes que
      // estão na tela. Ver sincronizarRotulos().
      camada._numeroLote = feicao.properties.numero_lote
        ? String(feicao.properties.numero_lote) : null
      mapaState.camadaLotes.addLayer(camada)
    },
  })

  // Os lotes chegam por lote (a cada movimento do mapa). Sem esta chamada, os
  // que entram depois só ganhariam rótulo no próximo arrasto.
  sincronizarRotulos()
}

/** A partir deste zoom o número do lote é legível — abaixo, vira borrão. */
const ZOOM_ROTULO_LOTE = 18

/**
 * Refaz os rótulos que dependem do zoom e do enquadramento: o número de cada
 * lote e as medidas dos lados. Chamada a cada `moveend`, a cada `zoomend` e a
 * cada leva de lotes que chega.
 */
function sincronizarRotulos() {
  if (!mapaState.obj) { return }
  desenharNumerosDosLotes()
  sincronizarMedidas()
}

/** Quanto a tela dos números passa da área visível, de cada lado (fração). */
const NUMEROS_FOLGA = 0.25

/**
 * Escreve o número de cada lote visível — DESENHADO numa tela (canvas) só, e
 * não um elemento da página por lote.
 *
 * Era um tooltip permanente do Leaflet por lote. No zoom 18 cabem 700 lotes
 * numa tela grande, e cada tooltip criado faz o navegador recalcular o layout
 * da página inteira (o Leaflet lê a largura do rótulo logo depois de inseri-lo).
 * Medido em 03/10/2026, com 1.400 lotes carregados: ~400 ms de tela travada a
 * cada movimento do mapa, crescendo com o número de lotes. Desenhar 700 textos
 * numa tela custa poucos milissegundos e não cria elemento nenhum.
 *
 * A tela fica num pane próprio, cobre a área visível com folga (para o arrasto
 * não mostrar borda vazia) e é reposicionada e redesenhada a cada chamada.
 */
function desenharNumerosDosLotes() {
  const mapa = mapaState.obj
  // Antes de o mapa ter centro e zoom (a página abre no Painel) não há o que
  // desenhar — e pedir a área visível a um mapa sem posição lança erro.
  if (!mapa || !mapa._loaded) { return }
  if (!mapa.getPane('numeros')) {
    const pane = mapa.createPane('numeros')
    pane.style.zIndex = 640              // acima dos lotes, abaixo dos rótulos de grupo e dos balões
    pane.style.pointerEvents = 'none'
  }
  // `rot-lote` é a classe que a camada "Número do lote" esconde por CSS
  // (#map.cam-sem-rot-lote); `leaflet-zoom-hide` a esconde durante a animação
  // do zoom, quando a posição dela ainda é a do zoom anterior.
  const tela = mapaState.telaNumeros
    ||= L.DomUtil.create('canvas', 'rot-lote rot-lote-tela leaflet-zoom-hide', mapa.getPane('numeros'))

  const tam = mapa.getSize()
  const fx = Math.round(tam.x * NUMEROS_FOLGA), fy = Math.round(tam.y * NUMEROS_FOLGA)
  const larg = tam.x + 2 * fx, alt = tam.y + 2 * fy
  const dpr = window.devicePixelRatio || 1
  if (tela.width !== Math.round(larg * dpr) || tela.height !== Math.round(alt * dpr)) {
    tela.width = Math.round(larg * dpr)
    tela.height = Math.round(alt * dpr)
    tela.style.width = larg + 'px'
    tela.style.height = alt + 'px'
  }
  L.DomUtil.setPosition(tela, mapa.containerPointToLayerPoint([-fx, -fy]))

  const ctx = tela.getContext('2d')
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, larg, alt)

  // O número só é legível de perto, e só faz sentido com os lotes à vista.
  const noNivel = typeof nivelDoMapa !== 'function' || nivelDoMapa(mapa) === 'lotes'
  if (!(mapa.getZoom() >= ZOOM_ROTULO_LOTE) || !noNivel) { return }

  ctx.font = "500 10.5px 'JetBrains Mono','Courier New',monospace"
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.lineJoin = 'round'
  ctx.lineWidth = 3
  // Halo branco sob o texto escuro: o número cai sobre telhado, grama e asfalto.
  ctx.strokeStyle = 'rgba(255,255,255,.92)'
  ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--chumbo').trim() || '#37413F'

  const vista = mapa.getBounds().pad(NUMEROS_FOLGA)
  for (const camada of mapaState.camadas) {
    if (!camada._numeroLote) { continue }
    // `getBounds` do polígono é barato: o Leaflet já o mantém calculado.
    if (!vista.intersects(camada.getBounds())) { continue }
    // O centro do polígono não muda: calculado uma vez por lote.
    // O centroide, e não o meio da caixa: num lote em "L" o meio da caixa cai fora dele.
    const centro = camada._centroDoNumero ||= (camada._map ? camada.getCenter() : camada.getBounds().getCenter())
    const p = mapa.latLngToContainerPoint(centro)
    ctx.strokeText(camada._numeroLote, p.x + fx, p.y + fy)
    ctx.fillText(camada._numeroLote, p.x + fx, p.y + fy)
  }
}

// ── MEDIDAS DOS LADOS ────────────────────────────────────────

/** Lado mais curto que isso não leva rótulo: é quina de desenho, não divisa. */
const MEDIDA_MINIMA_M = 0.5

/** Comprimento mínimo do lado NA TELA para caber "00,00 m" sem invadir o vizinho. */
const MEDIDA_CABE_PX = 62

/** Quanto o rótulo entra no lote, a partir do lado, em pixels. */
const MEDIDA_RECUO_PX = 12

/**
 * O zoom em que as medidas aparecem: UM acima do último zoom de imagem do
 * Google (20 + 1 = 21), e daí até ZOOM_MAXIMO. No 20 os rótulos ainda se
 * apertavam nos lotes pequenos; um zoom a mais dá espaço ao texto maior.
 */
function zoomDasMedidas() {
  return (mapaState.googleTiles?.options?.maxNativeZoom ?? 20) + 1
}

/**
 * Escreve, DENTRO de cada lote visível, o comprimento de cada um dos seus lados.
 *
 * A régua é a da prancheta (PranchetaGeo.plano): a GRADE do UTM, a mesma das
 * medidas do DWG e da matrícula. Medir no terreno daria alguns centímetros a
 * mais, e o número na tela discordaria do documento.
 *
 * Por lote, e não por divisa: cada imóvel mostra as suas medidas do seu lado
 * da linha. Dois vizinhos que dividem um lado mostram o mesmo número, um de
 * cada lado — é o que se lê numa planta de loteamento.
 */
function sincronizarMedidas() {
  const mapa = mapaState.obj
  if (!mapa) { return }
  mapaState.camadaMedidas ||= L.layerGroup().addTo(mapa)
  mapaState.camadaMedidas.clearLayers()

  // `!(>=)`, e não `<`: antes de o mapa ser posicionado o zoom é indefinido, e
  // `undefined < 20` é falso — seguir adiante pediria getBounds() de um mapa
  // sem centro, que lança e interrompe o enquadramento inicial.
  if (!(mapa.getZoom() >= zoomDasMedidas()) || typeof PranchetaGeo === 'undefined') { return }

  const vista = mapa.getBounds().pad(0.1)

  for (const camada of mapaState.camadas) {
    const anel = camada.feature?.geometry?.coordinates?.[0]
    if (!anel || !vista.intersects(camada.getBounds())) { continue }

    const plano = PranchetaGeo.plano(anel[0])
    // O lote em pixels: é na tela que se decide para que lado fica o "dentro".
    const tela = anel.map(c => { const p = mapa.latLngToContainerPoint([c[1], c[0]]); return [p.x, p.y] })

    for (let i = 1; i < anel.length; i++) {
      const a = anel[i - 1], b = anel[i]
      const m = PranchetaGeo.len(PranchetaGeo.sub(plano.para(b), plano.para(a)))
      if (m < MEDIDA_MINIMA_M) { continue }

      const pa = tela[i - 1], pb = tela[i]
      const comp = Math.hypot(pb[0] - pa[0], pb[1] - pa[1])
      // Rótulo maior que o lado encavala com o do canto vizinho: só onde cabe.
      if (comp < MEDIDA_CABE_PX) { continue }

      // Recuo para DENTRO do lote: das duas normais, a que cai no polígono.
      const meio = [(pa[0] + pb[0]) / 2, (pa[1] + pb[1]) / 2]
      const n = [-(pb[1] - pa[1]) / comp, (pb[0] - pa[0]) / comp]
      const teste = [meio[0] + n[0] * 2, meio[1] + n[1] * 2]
      const sinal = PranchetaGeo.dentro(teste, tela) ? 1 : -1
      const alvo = [meio[0] + n[0] * MEDIDA_RECUO_PX * sinal, meio[1] + n[1] * MEDIDA_RECUO_PX * sinal]
      const pos = mapa.containerPointToLatLng(alvo)
      if (!vista.contains(pos)) { continue }

      // Ângulo do lado NA TELA, com o texto sempre de pé (entre -90° e 90°).
      let ang = Math.atan2(pb[1] - pa[1], pb[0] - pa[0]) * 180 / Math.PI
      if (ang > 90) ang -= 180
      if (ang < -90) ang += 180

      const texto = m.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' m'
      L.marker(pos, {
        pane: 'rotulos', interactive: false, keyboard: false,
        icon: L.divIcon({
          className: 'rot-medida',
          html: `<span style="transform:translate(-50%,-50%) rotate(${ang.toFixed(1)}deg)">${texto}</span>`,
          iconSize: [0, 0],
        }),
      }).addTo(mapaState.camadaMedidas)
    }
  }
}

/**
 * Balão com o essencial do lote e o caminho para a ficha completa.
 *
 * @param {Object} feicao feição GeoJSON
 * @param {L.Path} camada polígono clicado
 */
function abrirBalao(feicao, camada) {
  const p = feicao.properties
  state.selecionado = feicao

  const area = p.area_gis_m2
    ? Number(p.area_gis_m2).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' m²'
    : 'área não informada'

  // O chip só aparece quando existe inscrição imobiliária — o código pelo
  // qual a prefeitura conhece o imóvel. Cair para a chave interna aqui
  // repetiria bairro, quadra e lote, que já estão no título.
  //
  // O bairro sai pelo NOME OFICIAL (`bairroDe`), e não pelo do desenho: o
  // balão IDENTIFICA o imóvel — é dele que se abre a ficha e se lavra peça —,
  // e a inscrição logo abaixo é oficial. Os dois têm de concordar. O nome do
  // desenho fica para o rótulo escrito sobre o mapa.
  // MODELO D4: cabeçalho verde com quadra e lote; tabela de rótulo e valor;
  // três atalhos quadrados; "Abrir ficha". Frente × fundos só quando o lote
  // tem as medidas (os vindos do DWG não têm); Vistoria só para quem registra.
  const m = n => Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 1 })
  const medidas = p.frente_m && p.fundos_m ? `${m(p.frente_m)} × ${m(p.fundos_m)} m` : null
  const ico = d => `<svg viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`
  const atalhos = [
    `<button type="button" class="balao-q" onclick="sinalizarDoBalao()">${ico('<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>')}Sinalizar</button>`,
    `<button type="button" class="balao-q" onclick="historicoDoBalao()">${ico('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 4v4h4M12 8v4l3 2"/>')}Histórico</button>`,
    window.PODE_EDITAR
      ? `<button type="button" class="balao-q" onclick="vistoriaDoBalao()">${ico('<path d="M4 20h4L19 9l-4-4L4 16v4z"/>')}Vistoria</button>` : '',
  ].filter(Boolean)

  const html = `
    <div class="balao4">
      <div class="balao4-cab"><b>Q ${esc(p.quadra ?? '—')} · Lote ${esc(p.numero_lote ?? '—')}</b></div>
      <div class="balao4-corpo">
        ${p.em_revisao ? `<div class="balao-revisao">Em revisão · importação nº ${esc(p.importacao_id)}
           <a href="#" onclick="event.preventDefault(); abrirImportacao(${Number(p.importacao_id)})">abrir</a></div>` : ''}
        <table class="balao4-tab">
          <tr><td>Bairro</td><td>${esc(bairroDe(p))}</td></tr>
          <tr><td>Inscrição</td><td class="balao4-mono">${esc(p.inscricao || '—')}</td></tr>
          <tr><td>Área GIS</td><td><b>${area}</b></td></tr>
          ${medidas ? `<tr><td>Frente × fundos</td><td>${medidas}</td></tr>` : ''}
          <tr><td>Origem</td><td><span class="lote-tag-origem">${esc(p.tag_origem || 'ORIGINAL')}</span></td></tr>
        </table>
        <div class="balao4-acoes" style="grid-template-columns:repeat(${atalhos.length},1fr)">${atalhos.join('')}</div>
        <button class="btn primary sm balao-btn" onclick="abrirFichaDoBalao()">Abrir ficha</button>
      </div>
    </div>`

  camada.bindPopup(html, {
    className: 'popup-lote', closeButton: true, maxWidth: 300, minWidth: 270, autoPan: true,
  }).openPopup()
}

/** Atalhos do balão: o lote já está em `state.selecionado`. */
function sinalizarDoBalao() {
  mapaState.obj?.closePopup()
  if (state.selecionado && typeof abrirSinalizar === 'function') abrirSinalizar(state.selecionado.properties)
}
function historicoDoBalao() {
  abrirFichaDoBalao()
  if (typeof subFicha === 'function') subFicha('historico')
}
function vistoriaDoBalao() {
  mapaState.obj?.closePopup()
  if (typeof novaVistoria === 'function') novaVistoria()
}

/** Ponte do balão para a ficha: o lote já está em `state.selecionado`. */
function abrirFichaDoBalao() {
  mapaState.obj?.closePopup()
  if (state.selecionado) abrirFicha(state.selecionado)
}

// O "apagar" saiu do balão: resíduo raramente vem sozinho — a conversão do
// DWG deixa faixas em série —, e apagar de um em um, abrindo e fechando balão,
// é trabalho repetido. Ele virou modo de SELEÇÃO no painel de correção
// cadastral, onde já se marcam vários lotes: ver `modoCadastral('apagar')`.

/**
 * Destaca uma camada, devolvendo a anterior à coloração CORRENTE.
 *
 * Antes ela voltava com `estiloLote()`, o verde fixo do começo do projeto —
 * então cada lote que saía da seleção ficava verde, independentemente de o
 * mapa estar uniforme, colorido por bairro ou com filtro aplicado. É a mesma
 * razão de o estilo inicial da camada também ler `estiloColorido`: só existe
 * uma resposta para "de que cor este lote deveria estar", e é essa.
 *
 * @param {L.Path} camada
 */
function destacar(camada) {
  if (mapaState.destacado) {
    mapaState.destacado.setStyle(estiloColorido(mapaState.destacado.feature))
  }
  mapaState.destacado = camada
  camada.setStyle(estiloDestaque())
  camada.bringToFront()
}

/**
 * Tira a seleção do lote e fecha o que ela abriu.
 *
 * Sem isto o único jeito de largar um lote era selecionar outro — e o fiscal
 * que clicou por engano ficava com um lote preso em amarelo e com o "Novo
 * documento" apontando para o imóvel errado.
 */
function limparSelecao() {
  if (mapaState.destacado) {
    mapaState.destacado.setStyle(estiloColorido(mapaState.destacado.feature))
    mapaState.destacado = null
  }
  state.selecionado = null
  mapaState.obj?.closePopup()
}

// Esc larga a seleção. Só age quando não há modal aberto: dentro de um
// formulário, Esc é do formulário, e desfazer a seleção por baixo dele
// deixaria o documento sem imóvel sem que ninguém pedisse.
document.addEventListener('keydown', ev => {
  if (ev.key !== 'Escape') return
  if (document.querySelector('.modal-bg.open')) return

  // O modo de correção sai PRIMEIRO e consome o Esc. Sem o `return`, os dois
  // comportamentos disparariam no mesmo toque: a pessoa sairia do modo e ainda
  // perderia a seleção de consulta por baixo, sem ter pedido.
  if (typeof selecaoAtiva === 'function' && selecaoAtiva()) {
    escaparSelecao()
    return
  }

  limparSelecao()
})

/** Destaca e enquadra um lote pelo id. @param {number} id */
function destacarPorId(id) {
  const c = mapaState.porId.get(id)
  if (!c) return
  destacar(c)
  mapaState.obj.fitBounds(c.getBounds(), { padding: [80, 80], maxZoom: ZOOM_DO_LOTE })
}

/**
 * Marca a posição do fiscal com o círculo de imprecisão do próprio GPS —
 * mostrar o raio é o que faz o fiscal entender por que o sistema às vezes
 * pergunta em vez de afirmar.
 *
 * @param {number} lat @param {number} lon @param {number} accuracy
 */
function marcarMinhaPosicao(lat, lon, accuracy) {
  limparMinhaPosicao()
  mapaState.precisaoEu = L.circle([lat, lon], {
    radius: Math.max(accuracy || 0, 5),
    color: '#1565C0', weight: 1, opacity: .5, fillColor: '#1565C0', fillOpacity: .12,
  }).addTo(mapaState.obj)
  mapaState.marcadorEu = L.marker([lat, lon], {
    icon: L.divIcon({ className: '', html: '<div class="eu-pin"></div>',
                      iconSize: [16, 16], iconAnchor: [8, 8] }),
    zIndexOffset: 1000,
  }).addTo(mapaState.obj)
}

/** Remove o marcador de posição do fiscal. */
function limparMinhaPosicao() {
  if (mapaState.marcadorEu) { mapaState.marcadorEu.remove(); mapaState.marcadorEu = null }
  if (mapaState.precisaoEu) { mapaState.precisaoEu.remove(); mapaState.precisaoEu = null }
}
