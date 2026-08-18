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
  const satelite = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { attribution: '© Esri', maxZoom: 20 })

  mapaState.obj = L.map('map', { zoomControl: false, layers: [claro] })
  L.control.zoom({ position: 'topright' }).addTo(mapaState.obj)
  L.control.layers({ 'Mapa': claro, 'Satélite': satelite }, null,
                   { position: 'topright' }).addTo(mapaState.obj)

  mapaState.camadaLotes = L.geoJSON(null, { style: estiloLote }).addTo(mapaState.obj)

  // Painel dedicado aos rótulos de logradouro, acima dos lotes.
  mapaState.obj.createPane('rotulos')
  mapaState.obj.getPane('rotulos').style.zIndex = 650
  mapaState.obj.getPane('rotulos').style.pointerEvents = 'none'
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png',
    { subdomains: 'abcd', maxZoom: 20, pane: 'rotulos' }).addTo(mapaState.obj)

  mapaState.obj.on('zoomend', () => rotulosPorZoom())
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
      camada.on('click', () => { destacar(camada); aoClicar(feicao) })
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
