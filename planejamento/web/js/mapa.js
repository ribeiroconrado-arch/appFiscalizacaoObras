// ══════════════════════════════════════════════
// MÓDULO: MAPA (Leaflet)
//
// Duas camadas de fundo: mapa claro do CartoDB (mesmo tile do AppPOSTURAS —
// discreto, para não competir com os polígonos) e satélite da Esri, que é o
// que o fiscal quer ver quando está conferindo obra em campo.
// ══════════════════════════════════════════════

/** Estado do mapa. Objeto mutável simples, como em core/state.js do AppPOSTURAS. */
const mapaState = {
  /** @type {L.Map|null} */        obj: null,
  /** @type {L.GeoJSON|null} */    camadaLotes: null,
  /** @type {L.Marker|null} */     marcadorEu: null,
  /** @type {L.Circle|null} */     precisaoEu: null,
  /** @type {L.Path|null} */       destacado: null,
}

/** Cor dos polígonos de lote. Hex puro: o Leaflet não lê variável CSS. */
const COR = {
  lote:      '#006C16',
  loteFundo: '#009B3A',
  destaque:  '#F5C400',
}

/** Estilo padrão de um lote no mapa. */
function estiloLote() {
  return { color: COR.lote, weight: 1, opacity: .85, fillColor: COR.loteFundo, fillOpacity: .18 }
}

/** Estilo do lote selecionado — dourado, o mesmo tom de "atenção" do sistema. */
function estiloDestaque() {
  return { color: COR.destaque, weight: 3, opacity: 1, fillColor: COR.destaque, fillOpacity: .38 }
}

/**
 * Cria o mapa. Idempotente: chamar duas vezes não recria.
 * O centro inicial é irrelevante — `ajustarAoConteudo()` enquadra os lotes
 * assim que eles carregam.
 */
function iniciarMapa() {
  if (mapaState.obj) return
  if (typeof L === 'undefined') { console.warn('Leaflet não carregado'); return }

  const claro = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap © CARTO', subdomains: 'abcd', maxZoom: 20,
  })
  const satelite = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { attribution: '© Esri', maxZoom: 20 })

  mapaState.obj = L.map('map', { zoomControl: false, layers: [claro] })
    .setView([-15.5165, -54.3105], 16)
  L.control.zoom({ position: 'topright' }).addTo(mapaState.obj)
  L.control.layers({ 'Mapa': claro, 'Satélite': satelite }, null,
                   { position: 'topright' }).addTo(mapaState.obj)
}

/**
 * Desenha os lotes no mapa.
 * @param {Object} geojson FeatureCollection dos lotes
 * @param {(feicao:Object)=>void} aoClicar callback do clique num lote
 */
function desenharLotes(geojson, aoClicar) {
  if (mapaState.camadaLotes) mapaState.camadaLotes.remove()
  mapaState.camadaLotes = L.geoJSON(geojson, {
    style: estiloLote,
    onEachFeature: (feicao, camada) => {
      camada.on('click', () => {
        destacar(camada)
        aoClicar(feicao)
      })
    },
  }).addTo(mapaState.obj)
}

/** Enquadra o mapa no conteúdo carregado. */
function ajustarAoConteudo() {
  if (mapaState.camadaLotes) {
    mapaState.obj.fitBounds(mapaState.camadaLotes.getBounds(), { padding: [24, 24] })
  }
}

/**
 * Destaca um lote, devolvendo o anterior ao estilo padrão.
 * @param {L.Path} camada
 */
function destacar(camada) {
  if (mapaState.destacado) mapaState.destacado.setStyle(estiloLote())
  mapaState.destacado = camada
  camada.setStyle(estiloDestaque())
  camada.bringToFront()
}

/**
 * Localiza e destaca um lote pela sua chave, sem depender do clique.
 * Usado pelo fluxo de GPS: identificado o lote, o mapa precisa mostrá-lo.
 * @param {string} chave
 */
function destacarPorChave(chave) {
  if (!mapaState.camadaLotes) return
  mapaState.camadaLotes.eachLayer(c => {
    if (c.feature?.properties?.chave === chave) {
      destacar(c)
      mapaState.obj.fitBounds(c.getBounds(), { padding: [80, 80], maxZoom: 19 })
    }
  })
}

/**
 * Marca a posição do fiscal, com o círculo de imprecisão do próprio GPS —
 * mostrar o raio é o que faz o fiscal entender por que o sistema às vezes
 * pergunta em vez de afirmar.
 *
 * @param {number} lat @param {number} lon @param {number} accuracy
 */
function marcarMinhaPosicao(lat, lon, accuracy) {
  const m = mapaState
  if (m.marcadorEu) m.marcadorEu.remove()
  if (m.precisaoEu) m.precisaoEu.remove()

  m.precisaoEu = L.circle([lat, lon], {
    radius: Math.max(accuracy || 0, 5),
    color: '#1565C0', weight: 1, opacity: .5, fillColor: '#1565C0', fillOpacity: .12,
  }).addTo(m.obj)

  m.marcadorEu = L.marker([lat, lon], {
    icon: L.divIcon({ className: '', html: '<div class="eu-pin"></div>', iconSize: [16, 16], iconAnchor: [8, 8] }),
    zIndexOffset: 1000,
  }).addTo(m.obj)
}

/** Remove o marcador de posição do fiscal. */
function limparMinhaPosicao() {
  if (mapaState.marcadorEu) { mapaState.marcadorEu.remove(); mapaState.marcadorEu = null }
  if (mapaState.precisaoEu) { mapaState.precisaoEu.remove(); mapaState.precisaoEu = null }
}
