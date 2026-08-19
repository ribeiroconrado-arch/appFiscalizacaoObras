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

/** Cria o mapa. Idempotente. */
function iniciarMapa() {
  if (mapaState.obj) return
  if (typeof L === 'undefined') { console.warn('Leaflet não carregado'); return }

  // Base SEM rótulos: os nomes de rua entram numa camada própria, desenhada
  // ACIMA dos polígonos. Com a base rotulada, os lotes cobrem os logradouros.
  const claro = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap © CARTO', subdomains: 'abcd', maxZoom: 20,
  })
  // maxNativeZoom 17: nesta região o acervo da Esri termina no zoom 17 —
  // z18, z19 e z20 devolvem o MESMO arquivo de 2.521 bytes, que é a placa
  // cinza "Map data not yet available". Verificado no centro, no Jardim
  // Europa, no Buritis e na entrada sul, e nas 196 capturas históricas do
  // acervo Wayback: nenhuma passa de 17. Declarando o limite real, o Leaflet
  // amplia o tile de 17 em vez de pedir um que não existe.
  const satelite = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { attribution: '© Esri', maxZoom: 20, maxNativeZoom: 17, className: 'tile-satelite' })

  const bases = { 'Mapa': claro, 'Satélite': satelite }

  mapaState.obj = L.map('map', {
    zoomControl: false, layers: [claro],
    // Prende a navegação ao município: viscosity 1 faz a borda não ceder,
    // então arrastar para fora simplesmente não sai do lugar.
    maxBounds: LIMITE_MUNICIPIO, maxBoundsViscosity: 1, minZoom: 11,
  })
  L.control.zoom({ position: 'topright' }).addTo(mapaState.obj)

  // Ortofoto por cima do satélite, em vez de no lugar dele — ver
  // montarOrtofoto(). O seletor recebe a camada depois, já como sobreposição.
  mapaState.controleCamadas = L.control.layers(bases, null, { position: 'topright' })
    .addTo(mapaState.obj)
  montarOrtofoto(satelite)

  mapaState.camadaLotes = L.geoJSON(null, { style: estiloLote }).addTo(mapaState.obj)

  // Painel dedicado aos rótulos de logradouro, acima dos lotes.
  mapaState.obj.createPane('rotulos')
  mapaState.obj.getPane('rotulos').style.zIndex = 650
  mapaState.obj.getPane('rotulos').style.pointerEvents = 'none'
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png',
    { subdomains: 'abcd', maxZoom: 20, pane: 'rotulos' }).addTo(mapaState.obj)

  ancorarControleCores()

  mapaState.obj.on('zoomend', () => { rotulosPorZoom(); ajustarNitidezSatelite() })
  mapaState.obj.on('baselayerchange', () => ajustarNitidezSatelite())

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

  let ampliando = false
  m.eachLayer(l => {
    if (!l.options?.className?.includes('tile-satelite')) return
    const nativo = l.options.maxNativeZoom ?? l.options.maxZoom ?? 20
    if (m.getZoom() > nativo) ampliando = true
  })

  document.getElementById('map')?.classList.toggle('sat-ampliado', ampliando)
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
}

/** Abre e fecha a legenda de cores. */
function alternarLegenda() {
  const corpo = document.getElementById('ctrl-corpo')
  const botao = document.querySelector('.ctrl-btn')
  const abrindo = corpo.hasAttribute('hidden')
  corpo.toggleAttribute('hidden', !abrindo)
  botao.classList.toggle('aberto', abrindo)
  botao.setAttribute('aria-expanded', String(abrindo))
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
    maxZoom: 20,
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
  }

  const orto = L.tileLayer(alt.url, opcoes)
  mapaState.ortofoto = orto

  // Sobreposição, e não base: pode ficar ligada junto com o satélite.
  mapaState.controleCamadas.addOverlay(orto, alt.rotulo || 'Ortofoto')

  // Ligada por padrão — é a melhor imagem que existe; e como só se manifesta
  // dentro da área e do zoom dela, não atrapalha o resto.
  orto.addTo(m)

  // Com a ortofoto ligada, entrar no zoom alto sem estar na base de satélite
  // mostraria a foto sobre o mapa de ruas, o que confunde. Então a base de
  // satélite acompanha quando a ortofoto começa a valer.
  m.on('zoomend', () => {
    if (!m.hasLayer(orto)) return
    const dentro = !opcoes.bounds || opcoes.bounds.intersects(m.getBounds())
    if (dentro && m.getZoom() >= opcoes.minZoom && !m.hasLayer(satelite)) {
      m.addLayer(satelite)
    }
  })
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
    style: estiloLote,
    onEachFeature: (feicao, camada) => {
      mapaState.porId.set(feicao.properties.id, camada)
      mapaState.camadas.push(camada)
      // Clique abre o BALÃO, não a ficha: a maior parte das consultas em
      // campo é "que lote é este?", e para isso abrir um modal de tela cheia
      // é caro demais. A ficha completa fica a um toque de distância, dentro
      // do balão.
      camada.on('click', () => { destacar(camada); abrirBalao(feicao, camada) })
      // Número do lote sobre o polígono, visível só a partir do zoom 18
      // (regra em mapa-cores.js) — antes disso vira borrão.
      if (feicao.properties.numero_lote) {
        camada.bindTooltip(String(feicao.properties.numero_lote),
          { permanent: true, direction: 'center', className: 'rot rot-lote' })
      }
      mapaState.camadaLotes.addLayer(camada)
    },
  })
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
  // repetiria bairro, quadra e lote, que já estão no título; enquanto o
  // cadastro não é integrado (Etapa 4), o balão fica sem o chip.
  const html = `
    <div class="balao">
      <div class="balao-tit">Quadra ${esc(p.quadra ?? '—')} · Lote ${esc(p.numero_lote ?? '—')}</div>
      <div class="balao-sub">${esc(p.bairro ?? '')}</div>
      ${p.inscricao ? `<div class="balao-chip">${esc(p.inscricao)}</div>` : ''}
      <div class="balao-area">${area}</div>
      <button class="btn primary sm balao-btn" onclick="abrirFichaDoBalao()">Ver ficha completa</button>
    </div>`

  camada.bindPopup(html, {
    className: 'popup-lote', closeButton: true, maxWidth: 260, autoPan: true,
  }).openPopup()
}

/** Ponte do balão para a ficha: o lote já está em `state.selecionado`. */
function abrirFichaDoBalao() {
  mapaState.obj?.closePopup()
  if (state.selecionado) abrirFicha(state.selecionado)
}

/** Destaca uma camada, devolvendo a anterior ao estilo padrão. @param {L.Path} camada */
function destacar(camada) {
  if (mapaState.destacado) mapaState.destacado.setStyle(estiloLote())
  mapaState.destacado = camada
  camada.setStyle(estiloDestaque())
  camada.bringToFront()
}

/** Destaca e enquadra um lote pelo id. @param {number} id */
function destacarPorId(id) {
  const c = mapaState.porId.get(id)
  if (!c) return
  destacar(c)
  mapaState.obj.fitBounds(c.getBounds(), { padding: [80, 80], maxZoom: 19 })
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
