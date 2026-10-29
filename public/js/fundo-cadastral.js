/**
 * MAPA CADASTRAL — o fundo do mapa desenhado só com os dados do sistema.
 *
 * Alternativa à imagem de satélite, escolhida no painel Camadas (grupo
 * "Fundo"). Nada é baixado de fora: nem a imagem da Esri, nem a do Google
 * (que é cobrada por tile), nem a ortofoto. O desenho é feito pelo próprio
 * navegador, em camadas:
 *
 *   1. o fundo liso do mapa (fora dos bairros);
 *   2. o contorno de cada bairro, pintado de BRANCO — é o que se lê como rua;
 *   3. as quadras em cinza claro por cima (na escala das quadras);
 *   4. os lotes em cinza, com as divisas finas (na escala dos lotes).
 *
 * A rua não é desenhada: é o espaço que sobra entre as quadras dentro do
 * bairro. Como tudo sai dos mesmos dados dos lotes, o fundo encaixa no lote
 * ao centímetro e se atualiza sozinho quando o cadastro muda — não há imagem
 * pronta para regerar.
 */

const FUNDO_COR = { fora: '#EDEEE8', rua: '#FFFFFF', ruaBorda: '#C9CDC5', quadra: '#E6E8E3', quadraBorda: '#CDD1CA', lote: '#C3C8C0' }

const fundoState = {
  /** @type {L.GeoJSON|null} os bairros pintados de branco */
  bairros: null,
}

/** O mapa está no fundo cadastral (satélite desligado)? */
function fundoCadastral() {
  return typeof camadaLigada === 'function' && !camadaLigada('satelite')
}

/**
 * Aplica o fundo escolhido. Chamada ao criar o mapa e a cada troca no painel.
 *
 * No cadastral as camadas de imagem SAEM do mapa (não só ficam escondidas):
 * escondidas, o Leaflet continuaria pedindo os tiles — e os do Google custam.
 */
function aplicarFundoDoMapa() {
  const m = mapaState.obj
  if (!m) return
  const cad = fundoCadastral()
  // A ordem de empilhamento é a de entrada: satélite, Google por cima, ortofoto no topo.
  const imagens = [mapaState.satelite, mapaState.googleTiles, mapaState.ortofoto].filter(Boolean)
  imagens.forEach(t => m.removeLayer(t))
  if (!cad) {
    imagens.forEach(t => t.addTo(m))
    // O Google não chegou a ser pedido (o mapa abriu no cadastral): pede agora.
    if (!mapaState.googleTiles && mapaState.googlePendente && typeof montarGoogle === 'function') {
      mapaState.googlePendente = false
      montarGoogle(mapaState.satelite)
    }
  }
  desenharBairrosDoFundo()
  // As vias, o verde e a água do OpenStreetMap nos vazios (fundo-cidade.js).
  if (typeof desenharFundoDaCidade === 'function') desenharFundoDaCidade()
  // Lotes, quadras e a linha dos bairros trocam de estilo junto.
  mapaState.camadas?.forEach(c => c.setStyle(estiloColorido(c.feature)))
  if (typeof quadraState !== 'undefined') {
    for (const b of quadraState.porBairro.values()) if (b !== 'carregando') b.camada?.setStyle(estiloQuadraDoMapa())
    if (quadraState.silhueta && quadraState.silhueta !== 'carregando') quadraState.silhueta.setStyle(estiloSilhuetaDaQuadra())
  }
  if (typeof contornoState !== 'undefined') contornoState.camada?.setStyle(_estiloContorno())
}

/** Os bairros em branco, por baixo de tudo. Refeito quando os contornos chegam ou mudam. */
function desenharBairrosDoFundo() {
  const m = mapaState.obj
  fundoState.bairros?.remove()
  fundoState.bairros = null
  if (!m || !fundoCadastral() || typeof contornoState === 'undefined' || !contornoState.dados) return
  if (!m.getPane('fundoCadastral')) {
    // Acima de onde ficariam as imagens (200) e abaixo dos lotes (400): é chão.
    m.createPane('fundoCadastral').style.zIndex = 210
    m.getPane('fundoCadastral').style.pointerEvents = 'none'
  }
  fundoState.bairros = L.geoJSON(contornoState.dados, {
    pane: 'fundoCadastral', interactive: false,
    style: { stroke: true, color: FUNDO_COR.ruaBorda, weight: 1, fillColor: FUNDO_COR.rua, fillOpacity: 1 },
  }).addTo(m)
}

/** O estilo das quadras no mapa: só o contorno sobre a foto; cheias no cadastral. */
function estiloQuadraDoMapa() {
  return fundoCadastral()
    ? { color: FUNDO_COR.quadraBorda, weight: 1, opacity: 1, fill: true, fillColor: FUNDO_COR.quadra, fillOpacity: 1 }
    : { color: '#ffffff', weight: 1.4, opacity: .9, fill: false }
}

/**
 * A silhueta das quadras no mapa afastado: a mesma cor da quadra, SEM a borda
 * — de longe a borda fecha a rua, e é o branco entre as quadras que a mostra.
 * Sobre a foto, o contorno branco das quadras, mais fino.
 */
function estiloSilhuetaDaQuadra() {
  return fundoCadastral()
    ? { stroke: false, fill: true, fillColor: FUNDO_COR.quadra, fillOpacity: 1 }
    : { color: '#ffffff', weight: .8, opacity: .8, fill: false }
}

/** O lote comum no fundo cadastral: cinza da quadra, com a divisa fina. */
function estiloLoteCadastral(apagado = false) {
  return apagado
    ? { color: FUNDO_COR.lote, weight: .6, opacity: .5, fillColor: FUNDO_COR.quadra, fillOpacity: .5 }
    : { color: FUNDO_COR.lote, weight: .8, opacity: 1, fillColor: FUNDO_COR.quadra, fillOpacity: 1 }
}
