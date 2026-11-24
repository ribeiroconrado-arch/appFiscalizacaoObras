/**
 * Contorno de cada bairro — a linha tracejada que o mapa mostra com o mapa
 * afastado, onde os lotes nem carregam — e, gerado junto, o de cada QUADRA,
 * que o mapa mostra na escala do bairro no lugar das linhas de lote.
 *
 * DESENHO (todos): lê /api/mapa/bairros uma vez e pinta; as quadras, por
 * bairro, quando a tela chega ao nível delas (carregarQuadrasVisiveis).
 * CÁLCULO (curador/admin): une os lotes do bairro e faz um FECHAMENTO de R
 * metros — dilata e contrai —, que cobre as ruas entre as quadras e mantém a
 * borda externa do loteamento; e une os lotes de cada quadra. Feito aqui com
 * JSTS (o mesmo motor do GEOS do QGIS) porque o ST_Buffer negativo do MySQL
 * corrompe multipolígono grande. O servidor só confere e grava
 * (BairroContornoController).
 */

const contornoState = {
  /** @type {L.GeoJSON|null} */ camada: null,
  /** @type {Object|null} resposta de /api/mapa/bairros */ dados: null,
  /**
   * Bairro → lotes que a última tentativa de contorno deixou de fora (a que o
   * servidor recusou). A lista da curadoria os mostra com link para o mapa.
   * @type {Object<string, {id:number, quadra:?string, lote:?string}[]>}
   */
  fora: {},
}

/** Raio padrão do fechamento: com 25 m os dois bairros da base fecham num contorno só. */
const CONTORNO_RAIO_PADRAO = 25

/**
 * Raio da FUSÃO: um segundo fechamento, bem maior, que tapa avenida larga e
 * entrada de loteamento — vãos que o de 25 m deixa abertos e que, no mapa,
 * faziam o bairro parecer várias linhas. O que ele acrescenta só entra se não
 * tocar lote de OUTRO bairro (ver calcularContorno).
 */
const CONTORNO_RAIO_FUSAO = 75

/**
 * Fechamento da QUADRA, em metros: só costura as frestas entre lotes vizinhos
 * que o desenho do DWG deixa (centímetros a poucos metros). Maior que isso
 * começaria a atravessar viela e juntar quadras.
 */
const QUADRA_RAIO = 1

/**
 * PONTE (08/11/2026): bairro que ainda sai em pedaços grandes — o Primavera I,
 * cortado pela rodovia — é ligado por fechamentos maiores, até este raio. Só
 * entra o trecho que liga dois pedaços e não toca lote de outro bairro.
 */
const CONTORNO_PONTE_RAIOS = [100, 150, 200]

/**
 * ENCOSTAR NO VIZINHO (08/11/2026): o vão entre o bairro e o vizinho, de até
 * 2 × isto, é repartido pela LINHA DO MEIO entre os lotes de um e os do outro
 * (Voronoi das bordas). Os dois lados calculam a mesma linha, então os
 * contornos se encostam sem sobra, em qualquer ordem de geração.
 */
const CONTORNO_ENCOSTO_M = 60
const CONTORNO_ENCOSTO_PASSO_M = 4
/** Tolerância da simplificação da linha do meio (m); o contorno final usa a metade. */
const CONTORNO_ENCOSTO_SIMPLIFICA_M = 1

/** Pedaço com até isso de lotes, longe do corpo do bairro, é lote ISOLADO (ex.: coordenada corrompida). */
const CONTORNO_ISOLADO_LOTES = 2
const CONTORNO_ISOLADO_M = 200

/**
 * ENDIREITAR as barrigas rasas da borda (_endireitarReentrancias): onde a
 * borda entra até isto (m) e até esta fração da reta que a cobre, vira reta.
 * A travessia de rua entre duas quadras de quina arredondada fica com uma
 * barriga em arco (o fechamento encosta nas duas quinas); uma esquina de
 * verdade do bairro, em L, entra muito mais que 20% da reta e fica.
 */
const CONTORNO_RETA_FUNDO_M = 8
const CONTORNO_RETA_FRACAO = 0.2
const CONTORNO_RETA_MAX_M = 80

/**
 * ESQUADREJAR a quina arredondada da quadra que o contorno acompanha: o arco
 * (lados de até CONTORNO_QUINA_LADO_CURTO_M) entre dois lados de pelo menos
 * CONTORNO_QUINA_LADO_LONGO_M vira o canto onde as duas retas se encontram —
 * a até CONTORNO_QUINA_MAX_M do arco e acrescentando no máximo
 * CONTORNO_QUINA_AREA_MAX_M2.
 */
const CONTORNO_QUINA_LADO_CURTO_M = 6
const CONTORNO_QUINA_LADO_LONGO_M = 6
const CONTORNO_QUINA_MAX_M = 20
const CONTORNO_QUINA_AREA_MAX_M2 = 150

// ── DESENHO NO MAPA ──────────────────────────────────────────

/**
 * Traço conforme o zoom: forte com o mapa afastado, discreto entre os lotes.
 * Amarelo, e não branco: os lotes já são brancos, e com o mapa afastado eles
 * viram uma mancha branca que engolia um contorno da mesma cor.
 */
/**
 * O contorno do bairro em cada nível do mapa (04/11/2026). Longe ele é o que
 * diz onde está cada loteamento; de perto quem importa é o lote, e ele perde
 * peso. No cadastral (claro), dourado fechado e, na cidade inteira, linha
 * contínua sobre o tom do bairro; no satélite, amarelo com sombra (CSS
 * `.fundo-sat`), e no nível dos lotes ele sai (aplicarNivelDoMapa).
 */
function _estiloContorno() {
  const nivel = typeof nivelDoMapa === 'function' && mapaState.obj ? nivelDoMapa(mapaState.obj) : 'municipio'
  const cad = typeof fundoCadastral === 'function' && fundoCadastral()
  const porNivel = cad
    ? { municipio: [2, 1, null], bairros: [1.6, .9, '6 5'], quadras: [1.2, .55, '6 5'], lotes: [1, .3, '4 4'] }
    : { municipio: [3, 1, '10 6'], bairros: [2, .95, '8 6'], quadras: [1.4, .85, '6 6'], lotes: [1, .6, '6 6'] }
  const [weight, opacity, dashArray] = porNivel[nivel] || porNivel.municipio
  return { color: cad ? '#C99A06' : '#facc15', weight, opacity, dashArray,
           fill: false, interactive: false, pane: 'contornos' }
}

async function carregarContornosDosBairros() {
  const mapa = mapaState.obj
  if (!mapa) return
  if (!mapa.getPane('contornos')) {
    // ACIMA dos lotes (overlayPane = 400) e sem clique: por baixo, a mancha
    // branca dos lotes escondia a linha justamente com o mapa afastado. Abaixo
    // dos rótulos (650) e dos balões.
    mapa.createPane('contornos').style.zIndex = 450
    mapa.getPane('contornos').style.pointerEvents = 'none'
    // As quadras logo abaixo do contorno do bairro, também sem clique.
    mapa.createPane('quadras').style.zIndex = 440
    mapa.getPane('quadras').style.pointerEvents = 'none'
    mapa.on('zoomend', () => contornoState.camada?.setStyle(_estiloContorno()))
    mapa.on('click', _cliqueNoBairro)
    mapa.on('mousemove', _cursorNoBairro)
    mapa.on('mouseout zoomstart', () => mapa.getContainer().classList.remove('bairro-sob-cursor'))
  }
  // Da pré-carga, na primeira vez (app.js, jsonDoFundo); depois, pedido novo.
  const dados = await jsonDoFundo('/api/mapa/bairros')
  if (!dados) return
  contornoState.dados = dados

  contornoState.camada?.remove()
  contornoState.camada = L.geoJSON(contornoState.dados, {
    style: _estiloContorno,
    onEachFeature: (f, camada) => {
      // O nome do bairro no centro, com o estilo `rot-bairro` (aparece com o mapa afastado).
      // NO MAPA, O APELIDO — o cadastrado em Parâmetros › Bairros, ou o nome
      // do desenho ("Buritis I"), que é o que está escrito na planta e cabe no
      // mapa. O oficial ("RESIDENCIAL BURITIS PRIMAVERA") é de ficha, vistoria
      // e documento (BairrosDoDesenho::oficial).
      camada.bindTooltip(f.properties.apelido || f.properties.nome || f.properties.nome_oficial,
        { permanent: true, direction: 'center', className: 'rot rot-bairro', interactive: false })
    },
  }).addTo(mapa)
  // No mapa cadastral, os bairros são o chão branco: refeito com os contornos novos.
  if (typeof desenharBairrosDoFundo === 'function') desenharBairrosDoFundo()
  // Os rótulos de bairro tirados dos lotes cedem lugar aos do contorno.
  if (typeof desenharRotulosDeGrupo === 'function') desenharRotulosDeGrupo()
  if (typeof aplicarNivelDoMapa === 'function') aplicarNivelDoMapa()
  carregarQuadrasVisiveis()
}

/**
 * Refaz no mapa tudo o que vem dos BAIRROS — contorno, nome, quadras e nomes
 * de rua — depois que os lotes de um bairro saíram (importação excluída ou
 * descartada). Recarregar só os lotes deixava a linha do loteamento, o nome
 * dele e o número das quadras desenhados em volta do vazio.
 */
async function recarregarContornosDoMapa() {
  await carregarContornosDosBairros()
  // As quadras guardadas de bairro que deixou de vir: fora.
  const vivos = new Set((contornoState.dados?.features || []).map(f => f.properties?.nome))
  for (const nome of [...quadraState.porBairro.keys()]) {
    if (!vivos.has(nome)) _esquecerQuadras(nome)
  }
  _desenharRotulosDeQuadra()
  _desenharRotulosDeRua()
  if (typeof desenharRotulosDeGrupo === 'function') desenharRotulosDeGrupo()
}

// ── QUADRAS NO MAPA ──────────────────────────────────────────

const quadraState = {
  /** @type {Map<string, {feicoes:Object[], camada:L.GeoJSON}|'carregando'>} por nome do bairro */
  porBairro: new Map(),
  /** @type {L.Marker[]} os números das quadras que estão na tela */
  rotulos: [],
  /** @type {L.Marker[]} os nomes de rua que estão na tela */
  rotulosRua: [],
  /** @type {L.GeoJSON|'carregando'|null} todas as quadras da cidade, só o desenho (mapa afastado) */
  silhueta: null,
}

/**
 * Contorno e número das quadras, no nível das quadras (nivelDoMapa). Cada
 * bairro é pedido UMA vez, quando entra na tela, e fica guardado: arrastar
 * pela cidade não repete pedido. Chamada a cada moveend (mapa.js).
 */
async function carregarQuadrasVisiveis() {
  const mapa = mapaState.obj
  if (!mapa || !contornoState.camada || typeof nivelDoMapa !== 'function') return
  // Os dados do bairro (quadras e ruas) servem a dois níveis: as quadras só
  // aparecem no delas, os nomes de rua também no dos lotes.
  if (!['quadras', 'lotes'].includes(nivelDoMapa(mapa))) {
    _carregarSilhuetaDasQuadras()
    _desenharRotulosDeQuadra(); _desenharRotulosDeRua(); return
  }

  const vista = mapa.getBounds().pad(0.2)
  const pedidos = []
  contornoState.camada.eachLayer(c => {
    const p = c.feature?.properties
    if (!p?.quadras || quadraState.porBairro.has(p.nome) || !vista.intersects(c.getBounds())) return
    quadraState.porBairro.set(p.nome, 'carregando')
    pedidos.push(_carregarQuadrasDoBairro(p.nome))
  })
  await Promise.all(pedidos)
  _desenharRotulosDeQuadra()
  _desenharRotulosDeRua()
  if (typeof aoMudarRuas === 'function') aoMudarRuas()   // ferramenta Nomes de rua (ruas-manuais.js)
}

/** @param {string} nome nome do desenho do bairro */
async function _carregarQuadrasDoBairro(nome) {
  try {
    const r = await fetch('/api/mapa/quadras?bairro=' + encodeURIComponent(nome), { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const gj = await r.json()
    const camada = L.geoJSON(gj, {
      pane: 'quadras', interactive: false,
      // Contorno sobre a foto; cheia no mapa cadastral (fundo-cadastral.js).
      style: typeof estiloQuadraDoMapa === 'function' ? estiloQuadraDoMapa() : { color: '#ffffff', weight: 1.4, opacity: .9, fill: false },
    }).addTo(mapaState.obj)
    quadraState.porBairro.set(nome, { feicoes: gj.features, camada, ruas: gj.ruas || [] })
  } catch (e) {
    // Sem as quadras o mapa continua: o bairro só fica sem elas nesta escala.
    quadraState.porBairro.delete(nome)
    console.warn('Quadras de ' + nome + ':', e)
  }
}

/**
 * Números das quadras que estão na tela. Refeitos a cada parada do mapa: com
 * vários bairros, os de fora da vista seriam centenas de elementos que o
 * Leaflet reposiciona a cada arrasto sem ninguém ver.
 */
function _desenharRotulosDeQuadra() {
  quadraState.rotulos.forEach(m => m.remove())
  quadraState.rotulos = []
  const mapa = mapaState.obj
  if (!mapa || nivelDoMapa(mapa) !== 'quadras') return
  const vista = mapa.getBounds().pad(0.1)
  for (const b of quadraState.porBairro.values()) {
    if (b === 'carregando') continue
    for (const f of b.feicoes) {
      const [lat, lon] = f.properties.rotulo
      if (!vista.contains([lat, lon])) continue
      quadraState.rotulos.push(L.marker([lat, lon], {
        interactive: false, keyboard: false,
        icon: L.divIcon({ className: '', html: '', iconSize: [0, 0] }),
      }).bindTooltip('Q ' + f.properties.numero, {
        permanent: true, direction: 'center', className: 'rot rot-quadra rot-quadra-contorno',
      }).addTo(mapa))
    }
  }
}

// ── NOMES DE RUA NO MAPA ─────────────────────────────────────

/** Tipo de logradouro abreviado, para quando o nome inteiro não cabe no trecho. */
const RUA_ABREVIACOES = [[/^AVENIDA\b/i, 'AV.'], [/^RUA\b/i, 'R.'], [/^TRAVESSA\b/i, 'TV.'],
  [/^ALAMEDA\b/i, 'ALAM.'], [/^RODOVIA\b/i, 'ROD.'], [/^ESTRADA\b/i, 'EST.'], [/^PRA[CÇ]A\b/i, 'PÇ.']]

/** Largura média de uma letra do rótulo de rua, em pixels (ver .rot-rua). */
const RUA_PX_POR_LETRA = 6.4

/** @param {string} nome */
function _abreviarRua(nome) {
  for (const [re, ab] of RUA_ABREVIACOES) if (re.test(nome)) return nome.replace(re, ab)
  return nome
}


/**
 * De quantos em quantos pixels o nome da MESMA rua se repete ao longo dela.
 * No celular, 450 (31/10/2026): com 1.100 px numa tela de ~400, a rua
 * atravessava a tela sem nome nenhum.
 */
const RUA_REPETE_PX = typeof window !== 'undefined' && window.innerWidth <= 620 ? 450 : 1100

/** Dois nomes iguais mais perto do que isto, em pixels, são a mesma rua dita duas vezes. */
const RUA_MESMA_PX = RUA_REPETE_PX * 0.5

/** Dois nomes (quaisquer) com o centro mais perto do que isto se atropelam. */
const RUA_COLIDE_PX = 16

/** O nome da rua sem o que varia entre bairros: caixa, acento e tipo abreviado. */
function _chaveDaRua(nome) {
  return _abreviarRua(String(nome)).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/\s+/g, ' ').trim()
}

/**
 * Nomes de rua que estão na tela, nos níveis das quadras e dos lotes.
 *
 * O nome só aparece se CABE no trecho: o comprimento do trecho na tela, em
 * pixels, contra a largura estimada do texto. Afastado, só os trechos longos
 * têm nome; aproximando, os curtos vão ganhando. Não cabendo inteiro, tenta o
 * tipo abreviado ("R.", "AV.").
 *
 * E NÃO SE REPETE A CADA QUADRA. Cada trecho (o pedaço entre duas esquinas)
 * que comportava o nome ganhava o seu, e uma rua de dez quadras saía com o
 * nome escrito dez vezes, um a cada 80 metros. Agora a rua é dividida em
 * faixas de RUA_REPETE_PX ao longo do comprimento, e cada faixa leva UM nome —
 * no trecho mais próximo do meio dela. As faixas são contadas em coordenada
 * absoluta do mapa, e não a partir da borda da tela: arrastar não faz os nomes
 * pularem de quadra. Menos rótulos também é menos peso (cada um é um elemento
 * da página, refeito a cada movimento).
 *
 * O texto gira com a rua e nunca fica de cabeça para baixo (-90° a 90°).
 *
 * UMA RUA, UM NOME — MESMO ENTRE DOIS BAIRROS. A rua da divisa é gerada pelos
 * dois bairros que ela separa, cada um com o seu trecho a 7 m da própria
 * quadra: saíam dois nomes iguais, lado a lado, e de longe um por cima do
 * outro. Os candidatos de TODOS os bairros são reunidos antes de virar
 * elemento, e o nome igual a outro já posto a menos de RUA_MESMA_PX é
 * descartado — o que fica vai para o meio dos dois, que é o meio da rua. Dois
 * nomes diferentes com o centro quase no mesmo ponto também não saem juntos.
 */
function _desenharRotulosDeRua() {
  quadraState.rotulosRua.forEach(m => m.remove())
  quadraState.rotulosRua = []
  const mapa = mapaState.obj
  if (!mapa || typeof nivelDoMapa !== 'function' || !['quadras', 'lotes'].includes(nivelDoMapa(mapa))) return

  const vista = mapa.getBounds().pad(0.05)
  const zoom = mapa.getZoom()
  /** Os candidatos de todos os bairros, antes da regra de "uma rua, um nome". */
  const candidatos = []

  // Os trechos de cada bairro e, no fundo cadastral, os do OpenStreetMap nos
  // vazios entre bairros (fundo-cidade.js) — que passam pela mesma regra.
  const fontes = [...quadraState.porBairro.values()].filter(b => b !== 'carregando').map(b => b.ruas || [])
  if (typeof trechosDeRuaDoFundo === 'function') fontes.push(trechosDeRuaDoFundo())

  for (const trechosDaFonte of fontes) {

    // 1. Os trechos com nome, agrupados por rua, com a posição absoluta do meio.
    const ruas = new Map()
    for (const t of trechosDaFonte) {
      if (!t.nome || t.origem === 'oculto' || t.origem === 'sem_nome') continue
      const meio = L.latLng((t.de[0] + t.ate[0]) / 2, (t.de[1] + t.ate[1]) / 2)
      // RUA NÃO PASSA POR DENTRO DE QUADRA. O trecho gerado fica 7 m para
      // fora do lado da quadra (o meio da rua); em duas quadras encostadas
      // pelos fundos, sem rua entre elas, esse "fora" cai dentro da vizinha, e
      // o nome saía no meio dos lotes, por cima do número da quadra. O que o
      // curador desenhou à mão (manual) fica: ele sabe onde pôs.
      if (t.origem !== 'manual' && _pontoDentroDeQuadra(meio.lat, meio.lng)) continue
      const lista = ruas.get(t.nome) || ruas.set(t.nome, []).get(t.nome)
      lista.push({ t, meio, abs: mapa.project(meio, zoom) })
    }

    for (const [nome, trechos] of ruas) {
      // 2. O eixo da rua: o lado em que ela mais se estende. É ao longo dele
      //    que as faixas são contadas.
      const xs = trechos.map(x => x.abs.x), ys = trechos.map(x => x.abs.y)
      const porX = Math.max(...xs) - Math.min(...xs) >= Math.max(...ys) - Math.min(...ys)

      // 3. Um candidato por faixa: o trecho que comporta o nome e está mais
      //    perto do meio da faixa.
      const porFaixa = new Map()
      for (const x of trechos) {
        const p1 = mapa.latLngToLayerPoint(x.t.de), p2 = mapa.latLngToLayerPoint(x.t.ate)
        const cabe = p1.distanceTo(p2) - 16
        const texto = [nome, _abreviarRua(nome)].find(s => s.length * RUA_PX_POR_LETRA <= cabe)
        if (!texto) continue
        const s = porX ? x.abs.x : x.abs.y
        const faixa = Math.floor(s / RUA_REPETE_PX)
        const desvio = Math.abs(s - (faixa + 0.5) * RUA_REPETE_PX)
        const atual = porFaixa.get(faixa)
        if (!atual || desvio < atual.desvio) porFaixa.set(faixa, { ...x, p1, p2, texto, desvio })
      }

      // 4. Só o que está na tela (com folga) é candidato.
      for (const c of porFaixa.values()) {
        if (!vista.contains(c.meio)) continue
        candidatos.push({ ...c, chave: _chaveDaRua(nome), pt: mapa.latLngToLayerPoint(c.meio) })
      }
    }
  }

  // 5. Uma rua, um nome. Os trechos mais longos primeiro: são os que melhor
  //    comportam o texto, e é o deles que fica.
  candidatos.sort((a, b) => b.p1.distanceTo(b.p2) - a.p1.distanceTo(a.p2))
  const postos = []
  for (const c of candidatos) {
    const igual = postos.find(p => p.chave === c.chave && p.pt.distanceTo(c.pt) < RUA_MESMA_PX)
    if (igual) {
      // O gêmeo do outro lado da rua: o nome que ficou vai para o meio dos dois.
      if (!igual.centrado && igual.pt.distanceTo(c.pt) < 60) {
        igual.meio = L.latLng((igual.meio.lat + c.meio.lat) / 2, (igual.meio.lng + c.meio.lng) / 2)
        igual.pt = mapa.latLngToLayerPoint(igual.meio)
        igual.centrado = true
      }
      continue
    }
    if (postos.some(p => p.pt.distanceTo(c.pt) < RUA_COLIDE_PX)) continue
    postos.push(c)
  }

  for (const c of postos) {
    let ang = Math.atan2(c.p2.y - c.p1.y, c.p2.x - c.p1.x) * 180 / Math.PI
    if (ang > 90) ang -= 180
    else if (ang <= -90) ang += 180
    quadraState.rotulosRua.push(L.marker(c.meio, {
      interactive: false, keyboard: false,
      icon: L.divIcon({ className: c.t.origem === 'osm' ? 'rot-rua rot-rua-osm' : 'rot-rua', iconSize: [0, 0],
        html: `<span style="transform:translate(-50%,-50%) rotate(${ang.toFixed(1)}deg)">${esc(c.texto)}</span>` }),
    }).addTo(mapa))
  }
}

/**
 * O ponto está dentro de alguma quadra carregada (de qualquer bairro)?
 * Raio para a direita contra cada anel externo; a caixa de cada quadra é
 * calculada uma vez e descarta quase todas sem percorrer os vértices.
 * @param {number} lat @param {number} lon
 */
function _pontoDentroDeQuadra(lat, lon) {
  for (const b of quadraState.porBairro.values()) {
    if (b === 'carregando') continue
    for (const feicao of b.feicoes || []) {
      const g = feicao.geometry
      if (!g) continue
      const poligonos = g.type === 'MultiPolygon' ? g.coordinates : [g.coordinates]
      const caixa = feicao._caixa || (feicao._caixa = _caixaDosAneis(poligonos.map(p => p[0])))
      if (lon < caixa[0] || lon > caixa[2] || lat < caixa[1] || lat > caixa[3]) continue
      if (poligonos.some(p => _pontoNoAnel(lon, lat, p[0]))) return true
    }
  }
  return false
}

/** [oeste, sul, leste, norte] de um conjunto de anéis em [lon, lat]. */
function _caixaDosAneis(aneis) {
  const c = [Infinity, Infinity, -Infinity, -Infinity]
  for (const anel of aneis) for (const [x, y] of anel) {
    if (x < c[0]) c[0] = x
    if (y < c[1]) c[1] = y
    if (x > c[2]) c[2] = x
    if (y > c[3]) c[3] = y
  }
  return c
}

// ── BALÃO DO BAIRRO (15/11/2026) ─────────────────────────────
//
// Com o mapa afastado (bairros e quadras, sem lote na tela), tocar dentro do
// contorno abre o balão do bairro: nome oficial, código do cadastro e o ato
// que o aprovou (Parâmetros › Bairros). De perto, o toque é do lote. O
// contorno continua sem clique (pane `contornos`): o balão sai do clique no
// mapa, achando o bairro pelo ponto.

/** O bairro (feição do contorno) que contém o ponto, ou null. @param {L.LatLng} ll */
function bairroNoPonto(ll) {
  for (const f of contornoState.dados?.features || []) {
    const g = f.geometry
    if (!g) continue
    const poligonos = g.type === 'Polygon' ? [g.coordinates] : g.type === 'MultiPolygon' ? g.coordinates : []
    for (const p of poligonos) {
      if (!p[0] || !_pontoNoAnel(ll.lng, ll.lat, p[0])) continue
      // Dentro do furo não é do bairro.
      if (p.slice(1).some(furo => _pontoNoAnel(ll.lng, ll.lat, furo))) continue
      return f
    }
  }
  return null
}

/** Distância, em pixels de tela, do ponto à LINHA do bairro mais perto: [feição, px]. */
function _bairroPertoDaLinha(ll) {
  const mapa = mapaState.obj
  const p = mapa.latLngToContainerPoint(ll)
  let melhor = null, dMin = Infinity
  for (const f of contornoState.dados?.features || []) {
    const g = f.geometry
    const poligonos = g?.type === 'Polygon' ? [g.coordinates] : g?.type === 'MultiPolygon' ? g.coordinates : []
    for (const anel of poligonos.flat()) {
      let a = mapa.latLngToContainerPoint([anel[0][1], anel[0][0]])
      for (let i = 1; i < anel.length; i++) {
        const b = mapa.latLngToContainerPoint([anel[i][1], anel[i][0]])
        const dx = b.x - a.x, dy = b.y - a.y
        const t = dx || dy ? Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / (dx * dx + dy * dy))) : 0
        const d = Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy))
        if (d < dMin) { dMin = d; melhor = f }
        a = b
      }
    }
  }
  return [melhor, dMin]
}

/** Folga, em pixels, para o mouse "estar na linha" do bairro por fora dela. */
const BAIRRO_LINHA_PX = 8

/**
 * O bairro sob o cursor quando o toque é do bairro — mapa afastado e nenhuma
 * ferramenta aberta —, ou null. Dentro do contorno (o nome fica ali) ou a até
 * BAIRRO_LINHA_PX da linha.
 * @param {L.LatLng} ll
 */
function bairroSobOCursor(ll) {
  const mapa = mapaState.obj
  if (!mapa || typeof nivelDoMapa !== 'function' || nivelDoMapa(mapa) === 'lotes') return null
  // Ferramenta aberta (medir, desenhar, pesquisa, curadoria) é dona do clique.
  if (typeof FERRAMENTAS_MAPA !== 'undefined' && Object.values(FERRAMENTAS_MAPA).some(f => f.aberta())) return null
  const dentro = bairroNoPonto(ll)
  if (dentro) return dentro
  const [perto, d] = _bairroPertoDaLinha(ll)
  return d <= BAIRRO_LINHA_PX ? perto : null
}

// O MESMO AVISO DO LOTE ao passar o mouse: a mãozinha de "clicável" (o lote,
// em canvas, ganha a classe leaflet-interactive do Leaflet). Um quadro por vez.
let _bairroCursorQuadro = 0
function _cursorNoBairro(ev) {
  if (_bairroCursorQuadro) return
  _bairroCursorQuadro = requestAnimationFrame(() => {
    _bairroCursorQuadro = 0
    const el = mapaState.obj?.getContainer()
    el?.classList.toggle('bairro-sob-cursor', !!bairroSobOCursor(ev.latlng))
  })
}

/** @param {L.LeafletMouseEvent} ev */
function _cliqueNoBairro(ev) {
  const mapa = mapaState.obj
  // Com uma ferramenta dona do clique (quadras por decreto, medir…) o bairro não abre balão.
  if (typeof _ferramentaComOClique === 'function' && _ferramentaComOClique()) return
  // Com a camada "Etapas dos loteamentos" ligada, a quadra sob o cursor abre o balão dela.
  if (typeof cliqueNaEtapa === 'function' && cliqueNaEtapa(ev)) return
  const f = bairroSobOCursor(ev.latlng)
  if (!f) return
  const p = f.properties || {}
  // SÓ TRÊS LINHAS (15/11/2026): nome oficial; o ato; a data e, ao lado, o código.
  const nada = '<span class="balao4-sub">não informado</span>'
  L.popup({ className: 'popup-lote', closeButton: true, maxWidth: 300, minWidth: 250, autoPan: true })
    .setLatLng(ev.latlng)
    .setContent(`
      <div class="balao4">
        <div class="balao4-cab"><b>Informações do bairro</b></div>
        <div class="balao4-corpo">
          <table class="balao4-tab">
            <tr><td>Nome oficial</td><td>${esc(p.nome_oficial || p.nome || '—')}</td></tr>
            <tr><td>Aprovação</td><td>${p.ato_aprovacao ? esc(p.ato_aprovacao) : nada}</td></tr>
          </table>
          <div class="balao4-grade balao-bairro-grade">
            <div class="balao4-cel"><span>Data</span><b>${p.data_aprovacao ? esc(p.data_aprovacao) : '—'}</b></div>
            <div class="balao4-cel"><span>Código</span><b>${p.codigo ? esc(p.codigo) : '—'}</b></div>
          </div>
          ${htmlOutrosAtosNoBalao(p.outros_atos || [])}
          ${typeof htmlQuadrasPorDecretoNoBalao === 'function' ? htmlQuadrasPorDecretoNoBalao(p) : ''}
        </div>
      </div>`)
    .openOn(mapa)
}

// OS OUTROS ATOS (18/11/2026): as três linhas continuam; só quando o bairro
// tem mais atos aparece um link discreto ("+ 3 ampliações") que abre a lista.
const CLASSE_ATO_BALAO = { aprovacao: 'bd-vig', revogacao: 'bd-fim' }

/** O nome do link pelo que a lista tem: ampliações, etapas ou atos. */
function _nomeDosOutrosAtos(outros) {
  const n = outros.length
  if (outros.every(a => a.tipo === 'ampliacao')) return n === 1 ? 'ampliação' : 'ampliações'
  if (outros.every(a => a.tipo === 'etapa')) return n === 1 ? 'etapa' : 'etapas'
  return n === 1 ? 'ato' : 'atos'
}

function htmlOutrosAtosNoBalao(outros) {
  if (!outros.length) return ''
  const nome = `${outros.length} ${_nomeDosOutrosAtos(outros)}`
  return `<button type="button" class="balao-atos-lk" data-nome="${esc(nome)}" onclick="alternarAtosDoBalao(this)">+ ${esc(nome)}</button>
    <div class="balao-atos" hidden>${outros.map(a => `
      <div class="balao-ato"><span class="badge ${CLASSE_ATO_BALAO[a.tipo] || 'bd-in'}" title="${esc(a.rotulo)}">${esc(a.observacao || a.rotulo)}</span>
        <span class="balao-ato-nome">${esc(a.ato)}</span><small>${a.data ? esc(a.data) : 'sem data'}</small></div>`).join('')}</div>`
}

/** Abre e fecha a lista de atos no balão do bairro. @param {HTMLButtonElement} bt */
function alternarAtosDoBalao(bt) {
  const lista = bt.nextElementSibling
  lista.hidden = !lista.hidden
  bt.textContent = lista.hidden ? '+ ' + bt.dataset.nome : '− ocultar'
  // Sem popup.update(): ele refaz o conteúdo a partir do HTML de origem e
  // fecharia a lista. O balão é ancorado embaixo e cresce para cima sozinho.
}

/** Ponto dentro do anel (regra par-ímpar). @param {number} x @param {number} y @param {number[][]} anel */
function _pontoNoAnel(x, y, anel) {
  let dentro = false
  for (let i = 0, j = anel.length - 1; i < anel.length; j = i++) {
    const [xi, yi] = anel[i], [xj, yj] = anel[j]
    if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) dentro = !dentro
  }
  return dentro
}

/**
 * Troca os trechos de rua de um bairro já guardado (resposta da ferramenta
 * Nomes de rua) e redesenha, sem novo pedido.
 * @param {string} nome @param {Object[]} ruas
 */
function atualizarRuasDoBairro(nome, ruas) {
  const b = quadraState.porBairro.get(nome)
  if (b && b !== 'carregando') b.ruas = ruas
  _desenharRotulosDeRua()
}

/** Larga as quadras guardadas de um bairro — depois de gerá-las de novo. @param {string} nome */
function _esquecerQuadras(nome) {
  const b = quadraState.porBairro.get(nome)
  if (b && b !== 'carregando') b.camada.remove()
  quadraState.porBairro.delete(nome)
  // A silhueta da cidade também muda: pedida de novo no próximo mapa afastado.
  if (quadraState.silhueta && quadraState.silhueta !== 'carregando') quadraState.silhueta.remove()
  quadraState.silhueta = null
}

/**
 * A SILHUETA DAS QUADRAS no mapa afastado (níveis dos bairros e do
 * município): todas as quadras da cidade num pedido só, só o desenho, sem
 * número. A quadra cheia e a rua é o branco que sobra entre elas. Pedida uma
 * vez, na primeira vez que o mapa afasta; no nível das quadras quem desenha
 * é a camada de cada bairro (aplicarNivelDoMapa troca uma pela outra).
 */
async function _carregarSilhuetaDasQuadras() {
  const mapa = mapaState.obj
  if (!mapa || quadraState.silhueta) return
  quadraState.silhueta = 'carregando'
  if (!mapa.getPane('silhuetas')) {
    // Sobre o chão branco dos bairros (210) e abaixo do contorno deles (450). Sem clique.
    mapa.createPane('silhuetas').style.zIndex = 435
    mapa.getPane('silhuetas').style.pointerEvents = 'none'
  }
  try {
    const gj = await jsonDoFundo('/api/mapa/quadras-silhueta')
    if (!gj) throw new Error('silhueta indisponível')
    if (quadraState.silhueta !== 'carregando') return   // largada enquanto chegava
    quadraState.silhueta = L.geoJSON(gj, { pane: 'silhuetas', interactive: false, style: estiloSilhuetaDaQuadra() }).addTo(mapa)
    if (typeof aplicarNivelDoMapa === 'function') aplicarNivelDoMapa()
  } catch (e) {
    // Sem a silhueta o mapa afastado continua como era: só o contorno dos bairros.
    quadraState.silhueta = null
    console.warn('Silhueta das quadras:', e)
  }
}

// ── CÁLCULO (curador) ────────────────────────────────────────

let _jstsCarregando = null
/** JSTS só para quem gera — o mapa de todos não paga esse download. */
function _carregarJsts() {
  if (window.jsts) return Promise.resolve()
  _jstsCarregando ||= new Promise((ok, falha) => {
    const s = document.createElement('script')
    s.src = '/vendor/jsts-2.12.1/jsts.min.js'   // local, não da CDN — ver mapa.blade.php (Leaflet)
    s.onload = ok
    s.onerror = () => { _jstsCarregando = null; falha(new Error('Não foi possível carregar a biblioteca de geometria (JSTS).')) }
    document.head.appendChild(s)
  })
  return _jstsCarregando
}

/**
 * UNIÃO QUE NÃO CAI NO ARREDONDAMENTO. Dois lados de lotes vizinhos que quase
 * coincidem (diferença na 14ª casa) fazem a união do JSTS desistir com "found
 * non-noded intersection" (JD das Américas II, 24/10/2026). Tenta a união
 * normal; se ela cai, repete com as coordenadas presas a uma grade de 1 mm e
 * depois de 1 cm — nada visível no contorno — e, por último, o buffer(0), que
 * costura o que restar.
 *
 * @param {Object} fabrica GeometryFactory do JSTS
 * @param {Object[]} geoms
 */
function _unirComSeguranca(fabrica, geoms) {
  const J = window.jsts
  const unir = gs => J.operation.union.UnaryUnionOp.union(fabrica.createGeometryCollection(gs))
  try { return unir(geoms) } catch (e) { console.warn('União do JSTS caiu; refazendo com precisão reduzida:', e?.message || e) }
  for (const escala of [1000, 100]) {
    const pm = new J.geom.PrecisionModel(escala)
    try { return unir(geoms.map(g => J.precision.GeometryPrecisionReducer.reduce(g, pm))) } catch { /* tenta a grade seguinte */ }
  }
  return fabrica.createGeometryCollection(geoms).buffer(0)
}

/**
 * Diferença ou interseção com o mesmo socorro da união: refaz com as
 * coordenadas presas a 1 mm e a 1 cm. Se ainda cair, devolve null — quem
 * chama trata como "não dá para conferir" e deixa aquele trecho como estava,
 * em vez de derrubar o contorno inteiro.
 *
 * @param {Object} a @param {Object} b @param {'difference'|'intersection'} op
 * @returns {Object|null}
 */
function _sobreporComSeguranca(a, b, op) {
  const J = window.jsts
  try { return a[op](b) } catch { /* refaz com precisão reduzida */ }
  for (const escala of [1000, 100]) {
    const pm = new J.geom.PrecisionModel(escala), r = g => J.precision.GeometryPrecisionReducer.reduce(g, pm)
    try { return r(a)[op](r(b)) } catch { /* tenta a grade seguinte */ }
  }
  return null
}

/**
 * Calcula e grava o contorno de um bairro.
 *
 * @param {string} bairro nome do desenho (lotes.bairro)
 * @param {number} [raio] metros do fechamento
 * @param {{silencioso?:boolean}} [opts]
 * @returns {Promise<Object|null>} resumo, ou null se falhou (já avisado)
 */
async function gerarContornoDoBairro(bairro, raio = CONTORNO_RAIO_PADRAO, opts = {}) {
  try {
    await _carregarJsts()
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? ''
    const rl = await fetch('/api/bairros/lotes?bairro=' + encodeURIComponent(bairro), { headers: { Accept: 'application/json' } })
    const dl = await rl.json()
    if (!rl.ok) throw new Error(dl.message || 'Não foi possível ler os lotes do bairro.')
    if (!dl.lotes.length) throw new Error('O bairro não tem lotes ativos.')

    const calc = calcularContorno(dl.lotes, raio, dl.vizinhos || [])
    // As quadras e os nomes de rua saem dos MESMOS lotes, no mesmo envio:
    // gravados juntos, nunca ficam de idades diferentes.
    const { quadras, ruas } = calcularQuadrasERuas(dl.lotes)

    const rg = await fetch('/api/bairros/contorno', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ bairro, geometry: calc.geometry, raio_m: raio, lotes_contados: dl.lotes.length,
        isolados: calc.isolados, quadras, ruas, sobrescrever_edicao: !!opts.sobrescrever }),
    })
    const dg = await rg.json()
    // EDITADO À MÃO (contorno-editor.js): gerar pelo raio substituiria a
    // edição. Em lote (silencioso) fica o editado; na linha, pergunta.
    if (rg.status === 409 && dg.codigo === 'editado_manual') {
      if (opts.silencioso) return { mantido: true, message: `Contorno de ${bairro} editado à mão: mantido.` }
      confirmarAcao({ titulo: 'Substituir o contorno editado?', textoBtn: 'Gerar de novo', perigo: true,
        mensagem: dg.message + ' O editado fica guardado: "Desfazer edição", na lista, o devolve.',
        onConfirm: async () => {
          const r = await gerarContornoDoBairro(bairro, raio, { ...opts, sobrescrever: true })
          if (r && typeof abrirContornosDosBairros === 'function' && document.getElementById('ctn-raio')) abrirContornosDosBairros()
        } })
      return null
    }
    if (!rg.ok) {
      // Recusado por deixar lotes de fora: guarda QUAIS, para a lista mostrar.
      if (Array.isArray(dg.fora)) contornoState.fora[bairro] = dg.fora
      throw new Error(dg.message || 'O servidor recusou o contorno.')
    }

    delete contornoState.fora[bairro]
    _esquecerQuadras(bairro)
    await carregarContornosDosBairros()
    const resumo = { ...dg, pedacos: calc.pedacos, isolados: calc.isolados }
    if (!opts.silencioso) {
      toast(dg.message + (calc.isolados.length ? ` ${calc.isolados.length} lote(s) isolado(s) ficaram de fora — veja na lista.` : ''),
        calc.isolados.length ? 'aviso' : 'ok')
    }
    return resumo
  } catch (e) {
    if (!opts.silencioso) toast(e.message, 'err')
    console.warn('Contorno do bairro:', e)
    return null
  }
}

/**
 * DEIXA A BORDA RETILÍNEA (em metros), em dois passos sobre cada polígono:
 *   1. quina arredondada de quadra → canto vivo, por fora (CONTORNO_QUINA_*);
 *   2. trecho torto → reta: o trecho entre os vértices i e j vira a reta i–j
 *      quando se afasta dela no máximo CONTORNO_RETA_FUNDO_M e
 *      CONTORNO_RETA_FRACAO do comprimento (dobra de rua, não esquina do
 *      bairro), o que a reta CORTA não tem lote do bairro e o que ela
 *      ACRESCENTA não tem lote de outro bairro (`livre`).
 * Guloso: de cada vértice, a reta mais longa que serve. Furos ficam como estão.
 * Qualquer resultado inválido devolve o polígono como estava.
 */
function _endireitarReentrancias(geom, livre, fabrica, lotesEmMetros) {
  const J = window.jsts
  // Índice dos lotes do bairro: o que a reta corta é conferido só contra os de perto.
  const lotes = new J.index.strtree.STRtree()
  for (const l of lotesEmMetros) lotes.insert(l.getEnvelopeInternal(), l)
  const partes = []
  for (let k = 0; k < geom.getNumGeometries(); k++) {
    const p = geom.getGeometryN(k)
    partes.push(p.getGeometryType() === 'Polygon' ? _endireitarPoligono(p, livre, fabrica, lotes) : p)
  }
  return _unirComSeguranca(fabrica, partes)
}

function _endireitarPoligono(p, livre, fabrica, lotes) {
  const J = window.jsts
  const livreOuVazio = g => g.getArea() < 0.05 || livre(g)
  let pts = p.getExteriorRing().getCoordinates().slice(0, -1).map(c => [c.x, c.y])
  const n = pts.length
  if (n < 4) return p
  // Sentido do anel: anti-horário tem o bairro à esquerda de cada lado.
  let s = 0
  for (let i = 0; i < n; i++) { const a = pts[i], b = pts[(i + 1) % n]; s += a[0] * b[1] - b[0] * a[1] }
  const dentro = s > 0 ? 1 : -1
  // Começa no vértice mais longe do centro: ele está no casco, fora de qualquer barriga.
  let cx = 0, cy = 0
  for (const q of pts) { cx += q[0]; cy += q[1] }
  cx /= n; cy /= n
  let k0 = 0, dm = -1
  pts.forEach((q, i) => { const d = (q[0] - cx) ** 2 + (q[1] - cy) ** 2; if (d > dm) { dm = d; k0 = i } })
  pts = [...pts.slice(k0), ...pts.slice(0, k0)]

  const anel = cs => fabrica.createLinearRing(cs.map(c => new J.geom.Coordinate(c[0], c[1])))
  const poligono = cs => {
    try { const g = fabrica.createPolygon(anel([...cs, cs[0]]), []); return g.isValid() ? g : g.buffer(0) } catch { return null }
  }

  // 1º QUINA ARREDONDADA → CANTO VIVO. Uma sequência de lados curtos (o arco
  // da quina da quadra) entre dois lados longos é trocada pelo encontro das
  // duas retas longas, por FORA do arco: cobre a quina, não corta lote.
  let mudou = false
  for (let i = 1; i < pts.length - 2; i++) {
    const lado = (a, b) => Math.hypot(b[0] - a[0], b[1] - a[1])
    if (lado(pts[i - 1], pts[i]) < CONTORNO_QUINA_LADO_LONGO_M) continue
    let melhor = null
    for (let j = i + 2; j < pts.length - 1 && j - i <= 12; j++) {
      if (lado(pts[j - 1], pts[j]) > CONTORNO_QUINA_LADO_CURTO_M) break   // o arco acabou
      if (lado(pts[j], pts[j + 1]) < CONTORNO_QUINA_LADO_LONGO_M) continue
      const x = _encontroDasRetas(pts[i - 1], pts[i], pts[j], pts[j + 1])
      if (!x || lado(x, pts[i]) > CONTORNO_QUINA_MAX_M || lado(x, pts[j]) > CONTORNO_QUINA_MAX_M) continue
      const g = poligono([...pts.slice(i, j + 1), x])
      if (!g || g.getArea() < 0.05 || g.getArea() > CONTORNO_QUINA_AREA_MAX_M2) continue
      const sobre = _sobreporComSeguranca(g, p, 'intersection')
      if (!sobre || sobre.getArea() > 0.05 || !livre(g)) continue   // só por fora, e sem vizinho
      melhor = { j, x }
    }
    if (melhor) { pts.splice(i, melhor.j - i + 1, melhor.x); mudou = true }
  }
  // 2º TRECHO TORTO → RETA. De cada vértice, a reta mais longa até um vértice
  // adiante cujo trecho entre os dois se afasta dela no máximo
  // CONTORNO_RETA_FUNDO_M e CONTORNO_RETA_FRACAO do comprimento — para
  // qualquer lado. O que a reta CORTA não pode ter lote do bairro; o que ela
  // ACRESCENTA não pode ter lote de outro bairro.
  const lado = (a, b) => Math.hypot(b[0] - a[0], b[1] - a[1])
  const furos = []
  for (let h = 0; h < p.getNumInteriorRing(); h++) furos.push(fabrica.createLinearRing(p.getInteriorRingN(h).getCoordinates()))
  const comFuros = cs => { try { return fabrica.createPolygon(anel([...cs, cs[0]]), furos) } catch { return null } }
  const semLote = g => g.getArea() < 0.05 || !lotes.query(g.getEnvelopeInternal()).toArray()
    .some(l => {
      if (!l.intersects(g)) return false
      const x = _sobreporComSeguranca(l, g, 'intersection')
      return !x || x.getArea() >= 0.05   // sem conferir, conta como lote: o trecho não muda
    })
  const saida = []
  let i = 0
  while (i < pts.length) {
    saida.push(pts[i])
    const atual = comFuros([...saida, ...pts.slice(i + 1)])
    const candidatos = []
    let caminho = 0
    for (let j = i + 2; j <= pts.length; j++) {
      const b = pts[j % pts.length]
      caminho += lado(pts[j - 1], pts[j - 2])
      if (caminho > CONTORNO_RETA_MAX_M * 1.5) break
      const a = pts[i], corda = lado(a, b)
      if (corda < 1 || corda > CONTORNO_RETA_MAX_M) continue
      let fundo = 0
      for (let t = i + 1; t < j; t++) {
        fundo = Math.max(fundo, Math.abs((b[0] - a[0]) * (pts[t][1] - a[1]) - (b[1] - a[1]) * (pts[t][0] - a[0])) / corda)
      }
      if (fundo <= Math.min(CONTORNO_RETA_FUNDO_M, CONTORNO_RETA_FRACAO * corda)) candidatos.push(j)
    }
    let alvo = -1
    for (let c = candidatos.length - 1; c >= 0 && alvo < 0 && atual; c--) {
      const j = candidatos[c]
      const novo = comFuros([...saida, ...pts.slice(j)])
      if (!novo || !novo.isValid()) continue
      const corta = _sobreporComSeguranca(atual, novo, 'difference'), poe = _sobreporComSeguranca(novo, atual, 'difference')
      if (corta && poe && semLote(corta) && livreOuVazio(poe)) alvo = j
    }
    if (alvo > i + 1) { mudou = true; i = alvo } else i++
  }
  if (!mudou) return p
  const novo = comFuros(saida)
  // Conferência final: válido, e nenhum lote do bairro de fora.
  const cortado = novo && novo.isValid() ? _sobreporComSeguranca(p, novo, 'difference') : null
  return cortado && semLote(cortado) ? novo : p
}

/**
 * Onde a reta a→b, seguindo adiante de b, encontra a reta c→d vindo de trás
 * de c — o canto vivo de uma quina arredondada entre b e c. Null se as retas
 * são paralelas ou se o encontro fica do lado errado (atrás de b ou além de c).
 */
function _encontroDasRetas(a, b, c, d) {
  const r = [b[0] - a[0], b[1] - a[1]], s = [d[0] - c[0], d[1] - c[1]]
  const den = r[0] * s[1] - r[1] * s[0]
  if (Math.abs(den) < 1e-9 * Math.hypot(...r) * Math.hypot(...s)) return null
  const w = [c[0] - b[0], c[1] - b[1]]
  const t = (w[0] * s[1] - w[1] * s[0]) / den   // adiante de b, em unidades de r
  const u = (w[0] * r[1] - w[1] * r[0]) / den   // em unidades de s, a partir de c
  if (t < -0.01 || u > 0.01) return null
  return [b[0] + t * r[0], b[1] + t * r[1]]
}

/**
 * PONTE entre pedaços do mesmo bairro (CONTORNO_PONTE_RAIOS). Pedaço é o que
 * tem lote do bairro dentro; o trecho que um fechamento maior acrescenta só
 * entra se encosta em DOIS pedaços (liga, não engorda) e não toca lote de
 * outro bairro. Para no primeiro raio que deixa um pedaço só.
 */
function _ligarPedacos(geom, fechar, livre, fabrica, lotes) {
  const partes = g => { const ps = []; for (let i = 0; i < g.getNumGeometries(); i++) ps.push(g.getGeometryN(i)); return ps }
  const comLote = g => partes(g).filter(p => lotes.some(l => p.intersects(l)))
  let atual = geom
  for (const raio of CONTORNO_PONTE_RAIOS) {
    const pedacos = comLote(atual)
    if (pedacos.length < 2) break
    const maior = fechar(atual, raio)
    const novo = maior && _sobreporComSeguranca(maior, atual, 'difference')
    if (!novo) continue
    const pontes = partes(novo).filter(t => {
      if (t.getArea() < 1 || !livre(t)) return false
      const perto = t.buffer(0.1)
      return pedacos.filter(p => p.intersects(perto)).length >= 2
    })
    if (!pontes.length) continue
    const unido = _unirComSeguranca(fabrica, [atual, ...pontes])
    if (unido && unido.isValid()) atual = unido
  }
  return atual
}

/**
 * ENCOSTAR NO VIZINHO (CONTORNO_ENCOSTO_M): o vão entre os lotes do bairro e
 * os de outro bairro vai até a LINHA DO MEIO entre eles — o diagrama de
 * Voronoi dos pontos das duas bordas, a cada CONTORNO_ENCOSTO_PASSO_M.
 *
 * O vão é o que o fechamento de lotes daqui + lotes de lá (raio D) preenche e
 * nenhum dos dois ocupa: só o espaço ENTRE eles — rua, canteiro, avenida de
 * até 2D —, nunca uma orelha para fora. Do vão, fica com este bairro a parte
 * mais perto dos lotes dele; e o contorno que já avançava além da linha do
 * meio recua até ela. Lote do bairro nunca é cortado.
 *
 * Os dois bairros calculam com os mesmos lotes (os vizinhos de um são os
 * lotes do outro), então a linha sai a mesma dos dois lados.
 * Devolve null se algo falhar — o contorno fica como estava.
 */
function _encostarNosVizinhos(geom, proprios, outros, fabrica, lotes = []) {
  const J = window.jsts
  const D = CONTORNO_ENCOSTO_M, passo = CONTORNO_ENCOSTO_PASSO_M
  try {
    const P = J.operation.buffer.BufferParameters
    const par = new P(); par.setJoinStyle(P.JOIN_MITRE); par.setMitreLimit(3)
    const buf = (g, d) => J.operation.buffer.BufferOp.bufferOp(g, d, par)
    // Os vizinhos em BLOCOS simplificados (quadras de lá, 1 m): as contas de
    // área (vão, faixa perto) ficam leves. A linha do meio usa os lotes exatos.
    const blocosLa = J.simplify.TopologyPreservingSimplifier.simplify(buf(buf(outros, 3), -3), 1)
    // "Perto" por índice espacial, sem buffer de milhares de lotes.
    const indice = g => {
      const t = new J.index.strtree.STRtree()
      for (let k = 0; k < g.getNumGeometries(); k++) { const p = g.getGeometryN(k); t.insert(p.getEnvelopeInternal(), p) }
      return t
    }
    const iLa = indice(blocosLa), iAqui = indice(geom)
    const ponto = c => fabrica.createPoint(c)
    const pertoDe = (arvore, c, d) => {
      const e = new J.geom.Envelope(c); e.expandBy(d)
      return arvore.query(e).toArray().some(p => p.isWithinDistance(ponto(c), d))
    }
    const lOutros = { locate: c => pertoDe(iLa, c, 2 * D) }, lAqui = { locate: c => pertoDe(iAqui, c, 2 * D) }
    const EXT = false
    const sitios = [], deQuem = new Map()
    const amostrar = (g, quem, filtro) => {
      for (let k = 0; k < g.getNumGeometries(); k++) {
        const p = g.getGeometryN(k)
        const aneis = [p.getExteriorRing()]
        for (let h = 0; h < p.getNumInteriorRing(); h++) aneis.push(p.getInteriorRingN(h))
        for (const anel of aneis) {
          const cs = anel.getCoordinates()
          for (let i = 1; i < cs.length; i++) {
            const a = cs[i - 1], b = cs[i], L = Math.hypot(b.x - a.x, b.y - a.y), n = Math.max(1, Math.ceil(L / passo))
            for (let t = 0; t < n; t++) {
              const c = new J.geom.Coordinate(a.x + (b.x - a.x) * t / n, a.y + (b.y - a.y) * t / n)
              if (filtro.locate(c) === EXT) continue
              const chave = c.x.toFixed(3) + ',' + c.y.toFixed(3)
              if (deQuem.has(chave)) continue
              deQuem.set(chave, quem); sitios.push(c)
            }
          }
        }
      }
    }
    amostrar(proprios, 'aqui', lOutros)
    amostrar(outros, 'la', lAqui)
    if (!sitios.length || ![...deQuem.values()].includes('la') || ![...deQuem.values()].includes('aqui')) return geom

    // O vão: só o espaço entre os dois (fechamento do conjunto, menos os dois).
    // Feito com o contorno daqui e os blocos de lá — os lotes daqui estão
    // dentro do contorno, e o vão de lá até a linha do meio é o do vizinho.
    const juntos = _unirComSeguranca(fabrica, [geom, blocosLa])
    const vaoTodo = _sobreporComSeguranca(buf(buf(juntos, D), -D), juntos, 'difference')
    // Do vão, só o que está a até D do contorno daqui: a linha do meio de um
    // vão de até 2D nunca fica mais longe que isso. Área cercada e grande
    // (centenas de metros de tudo) não é rua entre bairros.
    const vao = vaoTodo && _sobreporComSeguranca(vaoTodo, buf(geom, D + 1), 'intersection')
    if (!vao) return geom

    // Zona dos vizinhos: as células de Voronoi dos pontos da borda de lá.
    const vb = new J.triangulate.VoronoiDiagramBuilder()
    vb.setSites(fabrica.createMultiPointFromCoords(sitios))
    const env = geom.getEnvelopeInternal(); env.expandBy(3 * D)
    vb.setClipEnvelope(env)
    const celulas = vb.getDiagram(fabrica)
    // Faixa onde a linha do meio importa: o vão e o contorno a até D dos
    // lotes de lá. Só as células de lá que tocam essa faixa entram na união.
    // A LINHA DO MEIO: os lados que uma célula daqui divide com uma de lá
    // (células vizinhas do Voronoi têm o lado comum com as mesmas coordenadas).
    // Unir milhares de células, ou recortar cada uma, levava mais de um minuto;
    // cortar as áreas por esta linha uma vez só leva segundos.
    const ladoDe = new Map()
    const ch = (a, b) => { const p = a.x.toFixed(4) + ',' + a.y.toFixed(4), q = b.x.toFixed(4) + ',' + b.y.toFixed(4); return p < q ? p + '|' + q : q + '|' + p }
    const sitiosDe = { aqui: new J.index.strtree.STRtree(), la: new J.index.strtree.STRtree() }
    for (let k = 0; k < celulas.getNumGeometries(); k++) {
      const c = celulas.getGeometryN(k), s = c.getUserData()
      const dono = s && deQuem.get(s.x.toFixed(3) + ',' + s.y.toFixed(3))
      if (!dono) continue
      sitiosDe[dono].insert(new J.geom.Envelope(s), s)
      const cs = c.getExteriorRing().getCoordinates()
      for (let i = 1; i < cs.length; i++) {
        const k2 = ch(cs[i - 1], cs[i]), v = ladoDe.get(k2)
        if (!v) ladoDe.set(k2, { a: cs[i - 1], b: cs[i], donos: new Set([dono]) })
        else v.donos.add(dono)
      }
    }
    const meio = [...ladoDe.values()].filter(v => v.donos.size === 2)
      .map(v => fabrica.createLineString([new J.geom.Coordinate(v.a.x, v.a.y), new J.geom.Coordinate(v.b.x, v.b.y)]))
    if (!meio.length) return geom
    // A linha do Voronoi sai em zigue-zague (um vértice a cada passo): emendada
    // e simplificada em 1 m. Com milhares de vértices o servidor estourava o
    // tempo ao conferir o contorno (504). A simplificação é a mesma dos dois
    // lados, porque a linha de entrada é a mesma.
    const juntar = new J.operation.linemerge.LineMerger()
    juntar.add(J.operation.union.UnaryUnionOp.union(fabrica.createMultiLineString(meio)))
    const linhaDoMeio = fabrica.createMultiLineString(juntar.getMergedLineStrings().toArray()
      .map(l => J.simplify.DouglasPeuckerSimplifier.simplify(l, CONTORNO_ENCOSTO_SIMPLIFICA_M)))
    // O sítio mais perto de um ponto: busca em caixas crescentes no índice.
    const maisPerto = (arvore, c) => {
      for (let r = passo * 2; r < 8 * D; r *= 2) {
        const e = new J.geom.Envelope(c); e.expandBy(r)
        const ss = arvore.query(e).toArray()
        if (ss.length) return Math.min(...ss.map(s => s.distance(c)))
      }
      return Infinity
    }
    const deQuemE = g => {
      const c = g.getInteriorPoint().getCoordinate()
      const a = maisPerto(sitiosDe.aqui, c), l = maisPerto(sitiosDe.la, c)
      return a < Infinity && a <= l ? 'aqui' : 'la'   // sem ponto perto: não é daqui
    }
    // Corta a área pela linha do meio e devolve as faces de um dono.
    // O corte é uma fresta de 2 cm ao longo da linha (a costura de 5 cm, no
    // fim, a fecha): mais robusto que poligonizar, que deixava o vão inteiro.
    const fresta = linhaDoMeio.buffer(0.01, 1)
    const facesDe = (area, dono) => {
      if (!area || area.isEmpty()) return []
      const cortada = _sobreporComSeguranca(area, fresta, 'difference')
      if (!cortada) return []
      const fs = []
      for (let k = 0; k < cortada.getNumGeometries(); k++) {
        const f = cortada.getGeometryN(k)
        if (f.getArea() > 0.01 && deQuemE(f) === dono) fs.push(f)
      }
      return fs
    }
    const pertoLa = _sobreporComSeguranca(geom, buf(blocosLa, D), 'intersection')
    const ganhos = facesDe(vao, 'aqui'), passou = facesDe(pertoLa, 'la')

    // Ganha a parte do vão do lado de cá que encosta no contorno (sem ilhas).
    let novo = geom
    if (ganhos.length) {
      const ganho = _unirComSeguranca(fabrica, ganhos)
      const encostados = []
      for (let k = 0; k < ganho.getNumGeometries(); k++) {
        const p = ganho.getGeometryN(k)
        if (p.getArea() > 0.5 && p.isWithinDistance(geom, 0.5)) encostados.push(p)
      }
      if (encostados.length) novo = _unirComSeguranca(fabrica, [geom, ...encostados])
    }
    // Recua o que passava da linha do meio — nunca sobre lote daqui.
    // Os lotes daqui por índice: contas contra a união de milhares de lotes
    // levavam dezenas de segundos; só os de perto do trecho importam.
    const iLotes = new J.index.strtree.STRtree()
    for (const l of lotes) iLotes.insert(l.getEnvelopeInternal(), l)
    const lotesEm = g => iLotes.query(g.getEnvelopeInternal()).toArray().filter(l => l.intersects(g))
    const semLotes = g => { const ls = lotesEm(g); return ls.length ? _sobreporComSeguranca(g, _unirComSeguranca(fabrica, ls), 'difference') : g }
    const passouU = passou.length ? _unirComSeguranca(fabrica, passou) : null
    const alemDaLinha = passouU ? semLotes(passouU) : null
    if (alemDaLinha && alemDaLinha.getArea() > 0.01) novo = _sobreporComSeguranca(novo, alemDaLinha, 'difference') || novo
    // Costura de 5 cm: os recortes encostam no contorno por arredondamento.
    novo = buf(buf(novo, 0.05), -0.05)
    // Sem os vértices dos arcos do buffer e das frestas (meio metro não aparece).
    const simples = J.simplify.TopologyPreservingSimplifier.simplify(novo, CONTORNO_ENCOSTO_SIMPLIFICA_M / 2)
    // A simplificação não pode cortar lote: a ponta que ela tirou volta.
    const tirou = _sobreporComSeguranca(novo, simples, 'difference')
    const pontas = tirou ? lotesEm(tirou).map(l => _sobreporComSeguranca(l, tirou, 'intersection')).filter(g => g && g.getArea() > 0.001) : []
    novo = pontas.length ? _unirComSeguranca(fabrica, [simples, ...pontas]) : simples
    // Grade de 1 cm e sem farelo: a fresta e as pontas deixam vértices a
    // milímetros um do outro, que no arredondamento para lon/lat se cruzavam
    // ("linhas se cruzando" no servidor, Primavera I, 08/11/2026).
    novo = _limparContorno(novo, fabrica)
    if (!novo || !novo.isValid() || novo.isEmpty()) return null
    // Conferência: o encosto não deixa MAIS lote daqui de fora do que o
    // contorno já deixava (a simplificação de 0,5 m já tira pontas de lote).
    // Recorte de menos de 5 cm de largura é a grade de 1 cm e a abertura de
    // 2 cm (_limparContorno), não lote perdido. Lote cortado de verdade volta
    // (a ponta é devolvida e o contorno limpo de novo); só recusa se, ainda
    // assim, faltar lote.
    const perdas = () => {
      const bruto = _sobreporComSeguranca(geom, novo, 'difference')
      if (!bruto) return null
      const cortado = bruto.buffer(-0.025).buffer(0.025)
      if (cortado.getArea() <= 0.01) return []
      return lotesEm(cortado).map(l => _sobreporComSeguranca(l, cortado, 'intersection')).filter(x => x && x.getArea() > 0.001)
    }
    let perdidas = perdas()
    if (!perdidas) return null
    if (perdidas.reduce((s, x) => s + x.getArea(), 0) > 0.5) {
      novo = _limparContorno(_unirComSeguranca(fabrica, [novo, ...perdidas]), fabrica)
      if (!novo || !novo.isValid()) return null
      perdidas = perdas()
      if (!perdidas || perdidas.reduce((s, x) => s + x.getArea(), 0) > 0.5) return null
    }
    return novo
  } catch (e) {
    console.warn('Encostar no vizinho:', e)
    return null
  }
}

/**
 * A geometria em si. Função pura: lotes [{id, geometry(Polygon, lon/lat)}] e
 * os lotes de outros bairros em volta (Polygons) →
 * {geometry: MultiPolygon lon/lat, isolados: [ids], pedacos}.
 *
 * Três passos:
 *   1. fechamento de `raio` (25 m): cobre as ruas comuns entre as quadras;
 *   2. FUSÃO: fechamento de 75 m; cada trecho que ele acrescenta entra só se
 *      não tocar lote de outro bairro — avenida e entrada se fundem, o
 *      vizinho não é engolido;
 *   3. furos internos sem lote de outro bairro são preenchidos.
 */
function calcularContorno(lotes, raio, vizinhos = []) {
  const J = window.jsts
  const plano = PranchetaGeo.plano(lotes[0].geometry.coordinates[0][0])
  const leitor = new J.io.GeoJSONReader(), escritor = new J.io.GeoJSONWriter()

  // Em metros: buffer em grau seria um raio diferente em cada direção.
  const emMetros = lotes.map(l => ({
    id: l.id,
    g: leitor.read({ type: 'Polygon', coordinates: l.geometry.coordinates.map(anel => anel.map(c => plano.para(c))) }),
  }))
  const fabrica = emMetros[0].g.getFactory()
  const uniao = _unirComSeguranca(fabrica, emMetros.map(x => x.g))

  // Fechamento com quina em esquadro: a borda do loteamento fica reta, não arredondada.
  const P = J.operation.buffer.BufferParameters
  const par = new P(); par.setJoinStyle(P.JOIN_MITRE); par.setMitreLimit(3)
  const buf = (g, d) => J.operation.buffer.BufferOp.bufferOp(g, d, par)
  const fechar = (g, r) => buf(buf(g, r), -r)
  const partesDe = g => { const ps = []; for (let i = 0; i < g.getNumGeometries(); i++) ps.push(g.getGeometryN(i)); return ps }

  // O que a fusão não pode engolir: os lotes dos outros bairros (com 2 m de folga).
  const outrosCru = vizinhos.length
    ? _unirComSeguranca(fabrica, vizinhos.map(v =>
        leitor.read({ type: 'Polygon', coordinates: v.coordinates.map(anel => anel.map(c => plano.para(c))) })))
    : null
  const outros = outrosCru ? outrosCru.buffer(2) : null
  const livre = g => !outros || !g.intersects(outros)

  // 1 e 2: fechamento comum, e a fusão só onde não há vizinho.
  let fechado = fechar(uniao, raio)
  // Sem conseguir a diferença, não há fusão: fica o fechamento comum.
  const fundido = _sobreporComSeguranca(fechar(uniao, Math.max(raio, CONTORNO_RAIO_FUSAO)), fechado, 'difference')
  const acrescimos = fundido ? partesDe(fundido).filter(livre) : []
  if (acrescimos.length) {
    // A união sai em pedaços que só se ENCOSTAM (arredondamento nas bordas
    // comuns); 5 cm de dilata-contrai os costura num polígono só. Sem isto a
    // simplificação adiante colapsava o bairro num triângulo.
    fechado = fechar(_unirComSeguranca(fabrica, [fechado, ...acrescimos]), 0.05)
  }

  // 3: furo sem vizinho dentro vira bairro.
  fechado = _unirComSeguranca(fabrica, partesDe(fechado).filter(p => p.getGeometryType() === 'Polygon').map(p => {
    const furos = []
    for (let i = 0; i < p.getNumInteriorRing(); i++) {
      const anel = p.getInteriorRingN(i)
      if (!livre(fabrica.createPolygon(fabrica.createLinearRing(anel.getCoordinates()), []))) {
        furos.push(fabrica.createLinearRing(anel.getCoordinates()))
      }
    }
    return fabrica.createPolygon(fabrica.createLinearRing(p.getExteriorRing().getCoordinates()), furos)
  }))
  // 3b: PONTE — pedaços grandes separados por via larga (rodovia) viram um só.
  fechado = _ligarPedacos(fechado, fechar, livre, fabrica, emMetros.map(x => x.g))
  // Simplificação que preserva a topologia: Douglas-Peucker puro pode cruzar
  // anéis e entregar um polígono inválido.
  fechado = J.simplify.TopologyPreservingSimplifier.simplify(fechado, 0.5)
  // 4: borda retilínea — quina arredondada vira canto vivo e dobra de rua
  // vira reta, sem deixar lote de fora nem encostar em lote de outro bairro.
  fechado = _endireitarReentrancias(fechado, livre, fabrica, emMetros.map(x => x.g))
  // 5: ENCOSTAR — o vão até o vizinho, repartido pela linha do meio. Por
  // último e sem simplificar depois: a linha tem de sair igual nos dois lados.
  if (outrosCru) fechado = _encostarNosVizinhos(fechado, uniao, outrosCru, fabrica, emMetros.map(x => x.g)) || fechado

  // Pedaços: o maior é o bairro; pedaço pequeno e longe é lote isolado.
  // Pedaço SEM ÁREA fica de fora: a simplificação de 0,5 m achata lasca
  // estreita numa linha (2 pontos, 0 m²), e um anel assim torna o contorno
  // inteiro inválido — o servidor recusava "linhas se cruzando" (JD. Florença,
  // 18/10/2026). Lasca dessas não contém lote nenhum.
  const pedacos = []
  for (let i = 0; i < fechado.getNumGeometries(); i++) {
    const p = fechado.getGeometryN(i)
    if (p.getArea() >= 1 && p.isValid()) pedacos.push(p)
  }
  pedacos.sort((a, b) => b.getArea() - a.getArea())
  const corpo = pedacos[0], ficam = [corpo], isolados = []
  for (const p of pedacos.slice(1)) {
    const dentro = emMetros.filter(x => p.intersects(x.g))
    if (dentro.length <= CONTORNO_ISOLADO_LOTES && p.distance(corpo) > CONTORNO_ISOLADO_M) {
      isolados.push(...dentro.map(x => x.id))
    } else {
      ficam.push(p)
    }
  }

  _juntarQuaseEncostados(ficam, fabrica)

  let geometry = _multiPoligonoLonLat(ficam.map(p => escritor.write(p).coordinates), plano)
  // Conferência JÁ EM LON/LAT, como o servidor confere: o arredondamento da
  // conversão pode cruzar vértices que em metros não se cruzavam. Inválido,
  // passa pela grade de 1 cm (e, se preciso, de 10 cm) e converte de novo.
  for (const escala of [100, 10]) {
    if (new J.operation.valid.IsValidOp(leitor.read(geometry)).isValid()) break
    const limpos = ficam.map(p => _limparContorno(p, fabrica, escala)).filter(Boolean)
    const partes = []
    for (const g of limpos) for (let i = 0; i < g.getNumGeometries(); i++) partes.push(escritor.write(g.getGeometryN(i)).coordinates)
    geometry = _multiPoligonoLonLat(partes, plano)
  }
  return { geometry, isolados, pedacos: ficam.length }
}

/**
 * PEDAÇOS QUASE ENCOSTADOS viram um só. A ponta de lote devolvida pelo encosto
 * pode ficar a 2 cm do corpo do contorno: no plano, em metros, os dois não se
 * tocam e o JSTS dá válido; o MySQL confere sobre o elipsoide (SRID 4326) e os
 * vê se tocando — "linhas se cruzando" (Jd. Esperança e Lot. Três Américas,
 * 09/11/2026). O MENOR de dois pedaços a menos de 10 cm um do outro cresce a
 * fresta e mais 2 cm (em esquadro) e é unido ao maior. Não se encolhe depois:
 * o fechamento (cresce e encolhe) refazia o gargalo e separava os dois de novo.
 * Mexe na lista no lugar.
 *
 * @param {Object[]} ficam  polígonos JSTS em metros
 */
function _juntarQuaseEncostados(ficam, fabrica) {
  const J = window.jsts
  const P = J.operation.buffer.BufferParameters, par = new P()
  par.setJoinStyle(P.JOIN_MITRE); par.setMitreLimit(3)
  for (let volta = 0; volta < 50; volta++) {
    let achou = null
    for (let i = 0; i < ficam.length && !achou; i++) {
      for (let j = i + 1; j < ficam.length; j++) {
        const d = ficam[i].distance(ficam[j])
        if (d < 0.10) { achou = [i, j, d]; break }
      }
    }
    if (!achou) return
    let [i, j, d] = achou
    if (ficam[j].getArea() > ficam[i].getArea()) [i, j] = [j, i]   // i é o maior
    let junto
    try {
      const crescido = J.operation.buffer.BufferOp.bufferOp(ficam[j], d + 0.02, par)
      junto = _unirComSeguranca(fabrica, [ficam[i], crescido])
    } catch { return }
    if (!junto || junto.isEmpty() || !junto.isValid() || junto.getNumGeometries() !== 1) return   // não juntou: fica como estava
    ficam[i] = junto.getGeometryN(0)
    ficam.splice(j, 1)
  }
}

/**
 * Prende o contorno a uma grade (1 cm por padrão), o que desfaz cruzamento
 * de vértices quase coincidentes, e tira o farelo: furo e pedaço com menos de
 * 1 m². Devolve null se não sobrar nada válido.
 */
function _limparContorno(g, fabrica, escala = 100) {
  const J = window.jsts
  let r
  // Abertura de 2 cm (encolhe e devolve, quina em esquadro): some gargalo e
  // espinho mais finos que isso — bordas quase encostadas que no plano são
  // válidas e que o MySQL, conferindo sobre o elipsoide (SRID 4326), dá como
  // cruzadas (São Cristóvão, 08/11/2026).
  const P = J.operation.buffer.BufferParameters, par = new P()
  par.setJoinStyle(P.JOIN_MITRE); par.setMitreLimit(3)
  const buf = (x, d) => J.operation.buffer.BufferOp.bufferOp(x, d, par)
  // Antes, o FECHAMENTO de 2 cm: junta pedaço que só encosta no corpo (ponta
  // de lote devolvida tocando num ponto — multipolígono inválido).
  let aberto = g
  try { aberto = buf(buf(buf(buf(g, 0.02), -0.02), -0.02), 0.02) } catch { /* fica como estava */ }
  if (aberto.isEmpty()) aberto = g
  // Zigue-zague de lados de 1 a 4 cm (pontas devolvidas + grade): sai com a
  // simplificação de 5 cm, que preserva a topologia (Primavera II, 08/11/2026).
  try { aberto = J.simplify.TopologyPreservingSimplifier.simplify(aberto, 0.05) } catch { /* fica */ }
  try { r = J.precision.GeometryPrecisionReducer.reduce(aberto, new J.geom.PrecisionModel(escala)) } catch { r = aberto.buffer(0) }
  if (!r.isValid()) r = r.buffer(0)
  const polis = []
  for (let k = 0; k < r.getNumGeometries(); k++) {
    const p = r.getGeometryN(k)
    if (p.getGeometryType() !== 'Polygon' || p.getArea() < 1) continue
    const furos = []
    for (let h = 0; h < p.getNumInteriorRing(); h++) {
      const anel = p.getInteriorRingN(h)
      if (fabrica.createPolygon(fabrica.createLinearRing(anel.getCoordinates()), []).getArea() >= 1) furos.push(fabrica.createLinearRing(anel.getCoordinates()))
    }
    polis.push(fabrica.createPolygon(fabrica.createLinearRing(p.getExteriorRing().getCoordinates()), furos))
  }
  if (!polis.length) return null
  const out = polis.length === 1 ? polis[0] : fabrica.createMultiPolygon(polis)
  return out.isValid() ? out : out.buffer(0)
}

/**
 * O contorno de cada quadra do bairro. Função pura: lotes
 * [{id, quadra, geometry(Polygon, lon/lat)}] →
 * [{numero, geometry: MultiPolygon lon/lat, rotulo: [lat, lon], lotes}].
 * (As ruas saem do mesmo cálculo — ver calcularQuadrasERuas.)
 */
function calcularQuadras(lotes) {
  return calcularQuadrasERuas(lotes).quadras
}

// ── NOMES DE RUA ─────────────────────────────────────────────
//
// Refeito em 23/11/2026. Antes, o trecho saía de cada LADO RETO de quadra,
// empurrado 7 m para fora e fundido com o lado de lá: em bairro de rua curva
// (COND. SPLENDORE) a curva virava pedaços retos tortos, cruzando lotes, e a
// fusão falhava. Agora o eixo é o MEIO DO ESPAÇO ENTRE AS QUADRAS, calculado
// ponto a ponto (Voronoi), e acompanha a curva; e é uma linha só por rua.

/** Aresta de lote a até isto (m) da borda da quadra está SOBRE a borda (é frente). */
const RUA_TOLERANCIA_BORDA = 1.5

const VAZIO_PASSO = 2.5        // pontos na borda das quadras a cada isto (m)
const VAZIO_MEIA_MIN = 2.5     // meio da rua a menos disto da quadra: quadras encostadas, não há rua
const VAZIO_MEIA_MAX = 20      // a mais disto: não é rua entre as duas (campo aberto)
const VAZIO_LADO_UNICO = 7     // rua com quadra de um lado só: o meio fica a isto da quadra
const VAZIO_SIMPLIFICA = 0.6   // tolerância do desenho final (m)
const VAZIO_PONTA_MIN = 8      // ponta solta mais curta que isto é rebarba
const VAZIO_LADO_UNICO_MIN = 15
const VAZIO_ESQUINA_GRAUS = 55 // a linha dobra mais que isto em VAZIO_ESQUINA_M para cada lado: é esquina
const VAZIO_ESQUINA_M = 8
const VAZIO_JA_TEM_EIXO = 10   // borda afastada a até isto de um eixo entre quadras: a rua já está desenhada
const VAZIO_ENCOSTADAS = 1.4   // aresta cujas duas quadras distam menos que isto × a distância a elas: divisa, não rua
const VAZIO_DEFRONTE_M = 80    // lado único com outra quadra do bairro até isto à frente: é rua, mesmo sem nome
const VAZIO_COSTURA_M = 22     // ponta solta que, seguindo em frente, dá em outra linha a até isto: vai até ela
const VAZIO_COSTURA_PAR_M = 50 // duas pontas soltas uma de frente para a outra a até isto: se juntam
const VAZIO_COSTURA_GRAUS = 30 // folga do "de frente"
const VAZIO_COSTURA_NO_M = 8   // três ou mais pontas soltas a até isto uma da outra: viram um nó
const VAZIO_COSTURA_PERTO_M = 5 // ponta solta a até isto de outra linha: liga nela
const VAZIO_NO_CURTO_M = 3     // pedaço mais curto que isto entre dois cruzamentos: é um cruzamento só
const VAZIO_CRUZ_RETA_DE_M = 12   // no cruzamento, a reta da rua é medida daqui…
const VAZIO_CRUZ_RETA_ATE_M = 45  // …até aqui do nó; mais perto, a linha entorta para a quina
const VAZIO_CRUZ_RETA_FOLGA_M = 0.8 // a rua é reta nesse pedaço se nenhum vértice sai mais que isto
const VAZIO_CRUZ_MOVE_MAX_M = 12  // o nó não anda mais que isto
const VAZIO_GALHO_M = 25       // ponta solta mais curta que isto saindo de um cruzamento: sobra

/**
 * Quadras E nomes de rua, num cálculo só (a união dos lotes de cada quadra
 * serve aos dois). Função pura: lotes
 * [{id, quadra, logradouro?, geometry(Polygon, lon/lat)}] →
 * {quadras: [...como calcularQuadras], ruas: [{nome|null, de:[lat,lon], ate:[lat,lon]}]}.
 *
 * QUADRA: união dos lotes com o mesmo número e um fechamento de QUADRA_RAIO,
 * que costura as frestas entre lotes vizinhos. Os furos saem; quadra com o
 * mesmo número cortada por rua fica em pedaços, e o número vai no ponto
 * INTERNO do maior (o centro de uma quadra em L cairia na rua).
 *
 * RUA: o DWG não traz eixo de rua. O eixo é o meio entre as quadras
 * (_eixosPeloVazio), partido nos cruzamentos; cada eixo leva o nome que os
 * lotes de frente para ele votam (_nomearEixos). Uma rua curva sai como uma
 * sequência de trechos curtos.
 */
function calcularQuadrasERuas(lotes) {
  const J = window.jsts
  const comQuadra = lotes.filter(l => String(l.quadra ?? '').trim() !== '')
  if (!comQuadra.length) return { quadras: [], ruas: [] }
  const plano = PranchetaGeo.plano(comQuadra[0].geometry.coordinates[0][0])
  const leitor = new J.io.GeoJSONReader(), escritor = new J.io.GeoJSONWriter()
  const P = J.operation.buffer.BufferParameters
  const par = new P(); par.setJoinStyle(P.JOIN_MITRE); par.setMitreLimit(3)
  const buf = (g, d) => J.operation.buffer.BufferOp.bufferOp(g, d, par)

  const grupos = new Map()
  for (const l of comQuadra) {
    const numero = String(l.quadra).trim()
    if (!grupos.has(numero)) grupos.set(numero, [])
    grupos.get(numero).push({
      logradouro: String(l.logradouro ?? '').trim() || null,
      g: leitor.read({ type: 'Polygon', coordinates: l.geometry.coordinates.map(anel => anel.map(c => plano.para(c))) }),
    })
  }

  const quadras = [], partesComLotes = []
  for (const [numero, ls] of grupos) {
    const f = ls[0].g.getFactory()
    let g = _unirComSeguranca(f, ls.map(l => l.g))
    g = buf(buf(g, QUADRA_RAIO), -QUADRA_RAIO)
    g = J.simplify.TopologyPreservingSimplifier.simplify(g, 0.3)
    const partes = []
    for (let i = 0; i < g.getNumGeometries(); i++) {
      const p = g.getGeometryN(i)
      if (p.getGeometryType() !== 'Polygon' || p.isEmpty()) continue
      partes.push(f.createPolygon(f.createLinearRing(p.getExteriorRing().getCoordinates()), []))
    }
    if (!partes.length) continue
    partes.sort((a, b) => b.getArea() - a.getArea())
    const c = partes[0].getInteriorPoint().getCoordinate()
    const [lon, lat] = plano.de([c.x, c.y])
    quadras.push({ numero, geometry: _multiPoligonoLonLat(partes.map(p => escritor.write(p).coordinates), plano),
      rotulo: [Number(lat.toFixed(7)), Number(lon.toFixed(7))], lotes: ls.length })
    // Cada pedaço com os lotes que estão nele.
    for (const p of partes) partesComLotes.push({ p, lotes: ls.filter(l => p.intersects(l.g.getInteriorPoint())) })
  }

  const linhas = _eixosPeloVazio(partesComLotes.map(x => x.p), J)
  const ruas = []
  for (const { pts, nome } of _nomearEixos(linhas, partesComLotes, J)) {
    for (let i = 1; i < pts.length; i++) {
      const [lon1, lat1] = plano.de(pts[i - 1]), [lon2, lat2] = plano.de(pts[i])
      ruas.push({ nome, de: [Number(lat1.toFixed(7)), Number(lon1.toFixed(7))], ate: [Number(lat2.toFixed(7)), Number(lon2.toFixed(7))] })
    }
  }
  return { quadras, ruas }
}

/**
 * Os eixos das ruas, em metros: o meio do caminho entre as quadras.
 * Pontos a cada VAZIO_PASSO na borda de cada quadra; no diagrama de Voronoi
 * desses pontos, a aresta entre a célula de um ponto de uma quadra e a de um
 * ponto de OUTRA quadra está a igual distância das duas — é o meio da rua.
 * Ficam as que estão a uma distância de rua (VAZIO_MEIA_MIN a _MAX). Onde
 * não há quadra do outro lado, o eixo é a borda afastada VAZIO_LADO_UNICO.
 * @returns {{pts:number[][], ladoUnico:boolean}[]} polilinhas, e se são de um lado só
 */
function _eixosPeloVazio(partes, J) {
  const f = partes[0].getFactory()
  const sites = [], dono = new Map()
  const chave = c => c.x.toFixed(3) + ',' + c.y.toFixed(3)
  partes.forEach((p, i) => {
    const anel = J.densify.Densifier.densify(p.getExteriorRing(), VAZIO_PASSO)
    for (const c of anel.getCoordinates()) {
      const k = chave(c)
      if (!dono.has(k)) { dono.set(k, i); sites.push(c) }
    }
  })
  const vb = new J.triangulate.VoronoiDiagramBuilder()
  vb.setSites(f.createMultiPointFromCoords(sites))
  // As células direto da subdivisão, SEM o recorte do getDiagram: em quadra
  // regular o recorte cai em erro de precisão. As de fora ficam grandes, mas
  // as arestas longe das quadras saem pelo filtro de distância.
  const celulas = vb.getSubdivision().getVoronoiCellPolygons(f).toArray()

  // Arestas: cada uma aparece em duas células; fica a que separa quadras diferentes.
  const arestas = new Map()
  const ck = c => c.x.toFixed(4) + ',' + c.y.toFixed(4)
  for (const cel of celulas) {
    const site = cel.getUserData()
    const q = dono.get(chave(site))
    const cs = cel.getExteriorRing().getCoordinates()
    for (let j = 0; j + 1 < cs.length; j++) {
      const a = cs[j], b = cs[j + 1]
      const k = ck(a) < ck(b) ? ck(a) + '|' + ck(b) : ck(b) + '|' + ck(a)
      const e = arestas.get(k)
      if (!e) arestas.set(k, { a, b, q, site })
      else { e.q2 = q; e.site2 = site }
    }
  }
  const indice = new J.index.strtree.STRtree()
  partes.forEach(p => indice.insert(p.getEnvelopeInternal(), p))
  const dentroDeQuadra = (x, y) => {
    const pt = f.createPoint(new J.geom.Coordinate(x, y))
    return indice.query(pt.getEnvelopeInternal()).toArray().some(p => p.contains(pt))
  }
  const segs = []
  for (const e of arestas.values()) {
    if (e.q2 === undefined || e.q2 === e.q) continue
    const mx = (e.a.x + e.b.x) / 2, my = (e.a.y + e.b.y) / 2
    const d = Math.hypot(mx - e.site.x, my - e.site.y)
    if (d < VAZIO_MEIA_MIN || d > VAZIO_MEIA_MAX || dentroDeQuadra(mx, my)) continue
    // As duas quadras se encostam ali perto (lotes grandes lado a lado): a
    // aresta sai da divisa e atravessa a rua da frente — não é rua. No meio
    // de rua de verdade, os dois pontos ficam um de cada lado (≈ 2 × d).
    if (Math.hypot(e.site.x - e.site2.x, e.site.y - e.site2.y) < VAZIO_ENCOSTADAS * d) continue
    // Presas a 1 cm: em quadra regular as arestas saem quase iguais, e a
    // união de linhas quase iguais cai em erro de precisão.
    const r = c => new J.geom.Coordinate(Math.round(c.x * 100) / 100, Math.round(c.y * 100) / 100)
    const a = r(e.a), b = r(e.b)
    if (!a.equals2D(b)) segs.push(f.createLineString([a, b]))
  }

  // Rua com quadra de um lado só: a borda afastada, onde não há quadra do outro lado.
  const P = J.operation.buffer.BufferParameters
  // Quina viva (mitre): a esquina da borda afastada fica num ponto só, onde a linha é partida.
  const pr = new P(); pr.setJoinStyle(P.JOIN_MITRE); pr.setMitreLimit(3)
  const bufR = (g, d) => J.operation.buffer.BufferOp.bufferOp(g, d, pr)
  // Sai da borda afastada o que já tem eixo entre quadras perto (até
  // VAZIO_JA_TEM_EIXO) e o que cai dentro de outra quadra (encostadas pelos
  // fundos). Perto de um cruzamento, a borda termina a essa distância do eixo
  // transversal, e a costura do desenho a leva até ele.
  const ladoUnico = []
  const juntar = ls => { const m = new J.operation.linemerge.LineMerger(); m.add(_unirLinhas(f, ls)); return m.getMergedLineStrings().toArray() }
  const eixosEntre = segs.length ? juntar(segs).map(l => J.simplify.DouglasPeuckerSimplifier.simplify(l, VAZIO_SIMPLIFICA)) : []
  segs.length = 0
  segs.push(...eixosEntre)
  const pr2 = new P(); pr2.setQuadrantSegments(2)
  const jaTem = eixosEntre.length ? J.operation.buffer.BufferOp.bufferOp(f.createMultiLineString(eixosEntre), VAZIO_JA_TEM_EIXO, pr2) : null
  partes.forEach((p, i) => {
    const caixa = p.getEnvelopeInternal()
    const outras = partes.filter((o, j) => j !== i && o.getEnvelopeInternal().distance(caixa) <= VAZIO_LADO_UNICO + 2)
    let anel = f.createLineString(bufR(p, VAZIO_LADO_UNICO).getExteriorRing().getCoordinates())
    if (jaTem) anel = _sobreporComSeguranca(anel, jaTem, 'difference')
    if (anel && outras.length) anel = _sobreporComSeguranca(anel, bufR(_unirComSeguranca(f, outras), 1), 'difference')
    if (!anel) return   // a conta não fechou: sem rua de um lado só nesta quadra
    for (let k = 0; k < anel.getNumGeometries(); k++) {
      const l = anel.getGeometryN(k)
      if (l.getLength() >= VAZIO_LADO_UNICO_MIN) { segs.push(l); ladoUnico.push(l) }
    }
  })

  // Junta em polilinhas entre cruzamentos.
  if (!segs.length) return []
  let linhas = juntar(segs)

  // Rebarbas: ponta solta curta (VAZIO_PONTA_MIN), e o galho — ponta solta
  // mais curta que VAZIO_GALHO_M saindo de um cruzamento — é sobra do vazio.
  const limpar = ls => {
    for (let volta = 0; volta < 4; volta++) {
      const grau = new Map()
      const kk = c => c.x.toFixed(2) + ',' + c.y.toFixed(2)
      for (const l of ls) for (const c of [l.getCoordinateN(0), l.getCoordinateN(l.getNumPoints() - 1)]) grau.set(kk(c), (grau.get(kk(c)) || 0) + 1)
      const g0 = l => grau.get(kk(l.getCoordinateN(0))), g1 = l => grau.get(kk(l.getCoordinateN(l.getNumPoints() - 1)))
      const antes = ls.length
      // A mesma ligação duas vezes (a união deixou duas linhas quase iguais
      // entre os mesmos nós): fica uma.
      const entre = new Map()
      ls = ls.filter(l => {
        const k = [kk(l.getCoordinateN(0)), kk(l.getCoordinateN(l.getNumPoints() - 1))].sort().join('|')
        const outras = entre.get(k) || entre.set(k, []).get(k)
        if (outras.some(o => J.algorithm.distance.DiscreteHausdorffDistance.distance(o, l) < 2)) return false
        outras.push(l)
        return true
      })
      ls = ls.filter(l => {
        const L = l.getLength(), solta = g0(l) === 1 || g1(l) === 1
        if (solta && L < VAZIO_PONTA_MIN) return false
        // Laço curto (sai e volta ao mesmo nó): sobra da costura, não é rua.
        if (l.isClosed() && L < 2 * VAZIO_GALHO_M) return false
        return !(L < VAZIO_GALHO_M && ((g0(l) === 1 && g1(l) >= 3) || (g1(l) === 1 && g0(l) >= 3)))
      })
      if (ls.length === antes) break
      const lm = new J.operation.linemerge.LineMerger()
      ls.forEach(l => lm.add(l))
      ls = lm.getMergedLineStrings().toArray()
    }
    return ls
  }
  linhas = limpar(linhas)
  // Costura: a ponta que parou perto de outra linha vai até ela.
  const costurado = _costurarPontas(linhas, partes, indice, f, J)
  if (costurado.novos.length) linhas = limpar(juntar([...costurado.linhas, ...costurado.novos]))
  linhas = _contrairNosCurtos(linhas, J)
  linhas = _endireitarCruzamentos(linhas, J)
  // De um lado só: a linha que cai quase toda sobre a borda afastada.
  const faixaLado = ladoUnico.length ? bufR(f.createMultiLineString(ladoUnico), 0.5) : null
  const pedacos = linhas.flatMap(l => {
    const ladoUnico = !!faixaLado && (_sobreporComSeguranca(l, faixaLado, 'intersection')?.getLength() ?? 0) > 0.8 * l.getLength()
    const pts = J.simplify.DouglasPeuckerSimplifier.simplify(l, VAZIO_SIMPLIFICA).getCoordinates().map(c => [c.x, c.y])
    return _partirNasEsquinas(pts).map(p => ({ pts: p, ladoUnico }))
  })
  // Rebarba que só aparece depois de partir nas esquinas (ou que a
  // simplificação encurtou): curta e com uma ponta solta.
  const kp = c => c[0].toFixed(1) + ',' + c[1].toFixed(1)
  const grauP = new Map()
  for (const { pts } of pedacos) for (const c of [pts[0], pts[pts.length - 1]]) grauP.set(kp(c), (grauP.get(kp(c)) || 0) + 1)
  const compr = pts => pts.reduce((s, c, i) => i ? s + Math.hypot(c[0] - pts[i - 1][0], c[1] - pts[i - 1][1]) : 0, 0)
  return pedacos
    .filter(({ pts }) => !(compr(pts) < VAZIO_PONTA_MIN && (grauP.get(kp(pts[0])) === 1 || grauP.get(kp(pts[pts.length - 1])) === 1)))
    .map(e => ({ ...e, defronte: e.ladoUnico && _temQuadraDefronte(e.pts, partes, indice, eixosEntre, f, J) }))
}

/**
 * Ruas retas até o cruzamento (24/11/2026). Perto do cruzamento, o meio entre
 * as quadras é puxado pelas quinas (chanfradas ou arredondadas): as linhas
 * chegavam ao nó entortando, em diagonal — no mapa, pedacinhos tortos em toda
 * esquina. A rua de verdade segue reta até o encontro.
 *
 * Em cada nó com 3 ou mais linhas: a reta de cada uma é medida longe da
 * quina, de VAZIO_CRUZ_RETA_DE_M a _ATE_M do nó (vale se a linha é reta ali);
 * o nó vai para o ponto que melhor encontra essas retas (mínimos quadrados —
 * no "T", o encontro da rua com a transversal); e cada linha reta passa a ir
 * direto do nó novo ao seu trecho reto, sem o pedaço torto. A linha curva, ou
 * curta, só troca a ponta pelo nó novo. O nó que andaria mais de
 * VAZIO_CRUZ_MOVE_MAX_M fica onde estava.
 */
function _endireitarCruzamentos(linhas, J) {
  const kk = c => c[0].toFixed(2) + ',' + c[1].toFixed(2)
  const cs = linhas.map(l => l.getCoordinates().map(c => [c.x, c.y]))
  const nos = new Map()
  cs.forEach((c, i) => {
    if (c.length < 2 || kk(c[0]) === kk(c[c.length - 1])) return
    for (const fim of [false, true]) {
      const k = kk(fim ? c[c.length - 1] : c[0])
      ;(nos.get(k) || nos.set(k, []).get(k)).push({ i, fim })
    }
  })
  const A = VAZIO_CRUZ_RETA_DE_M, B = VAZIO_CRUZ_RETA_ATE_M
  // O ponto a s metros do início de pts.
  const em = (pts, s) => {
    let acc = 0
    for (let i = 1; i < pts.length; i++) {
      const d = Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1])
      if (acc + d >= s) { const t = d ? (s - acc) / d : 0; return [pts[i - 1][0] + (pts[i][0] - pts[i - 1][0]) * t, pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * t] }
      acc += d
    }
    return pts[pts.length - 1]
  }
  const comprimento = pts => pts.reduce((t, p, i) => i ? t + Math.hypot(p[0] - pts[i - 1][0], p[1] - pts[i - 1][1]) : 0, 0)

  for (const [, ps] of nos) {
    if (ps.length < 3) continue
    // Cada linha, do nó para fora.
    const ramos = ps.map(({ i, fim }) => {
      const pts = fim ? [...cs[i]].reverse() : cs[i]
      const L = comprimento(pts)
      // A reta é procurada recuando: a quina pode entortar a linha por mais
      // de A metros (a travessa que chega à avenida em curva, ~20 m). A outra
      // ponta também pode ser cruzamento: a janela não passa do meio dela.
      let reta = null
      for (const de of [A, A + 8, A + 16]) {
        const ate = Math.min(de + (B - A), L - de)
        if (ate - de < 8) break
        const p = em(pts, de), q = em(pts, ate)
        const d = Math.hypot(q[0] - p[0], q[1] - p[1])
        if (d <= 1) continue
        const u = [(q[0] - p[0]) / d, (q[1] - p[1]) / d]
        let acc = 0, reto = true
        for (let k = 1; k < pts.length && reto; k++) {
          acc += Math.hypot(pts[k][0] - pts[k - 1][0], pts[k][1] - pts[k - 1][1])
          if (acc <= de || acc >= ate) continue
          if (Math.abs((pts[k][0] - p[0]) * u[1] - (pts[k][1] - p[1]) * u[0]) > VAZIO_CRUZ_RETA_FOLGA_M) reto = false
        }
        if (reto) { reta = { p, u, de }; break }
      }
      return { i, fim, pts, reta }
    })
    const retas = ramos.filter(r => r.reta)
    // Uma reta só chegando numa rua curva (a travessa na avenida em curva): a
    // reta vai até a curva, e o pedaço da curva entre o nó velho e o encontro
    // passa para o outro lado da curva.
    if (retas.length === 1) { _retaAteACurva(retas[0], ramos, cs, A); continue }
    if (retas.length < 2) continue
    // O ponto que melhor encontra as retas: Σ (I − u uᵀ) X = Σ (I − u uᵀ) p.
    let a = 0, b = 0, c = 0, bx = 0, by = 0
    for (const { reta: { p, u } } of retas) {
      const m11 = 1 - u[0] * u[0], m12 = -u[0] * u[1], m22 = 1 - u[1] * u[1]
      a += m11; b += m12; c += m22
      bx += m11 * p[0] + m12 * p[1]; by += m12 * p[0] + m22 * p[1]
    }
    const det = a * c - b * b
    if (Math.abs(det) < 0.05 * retas.length) continue   // todas paralelas: não há encontro definido
    const X = [(c * bx - b * by) / det, (a * by - b * bx) / det]
    const no = ramos[0].pts[0]
    if (Math.hypot(X[0] - no[0], X[1] - no[1]) > VAZIO_CRUZ_MOVE_MAX_M) continue
    for (const r of ramos) {
      let novo
      if (r.reta) {
        // Do nó novo direto ao trecho reto: o pedaço torto perto da quina sai.
        let acc = 0, k = 1
        for (; k < r.pts.length; k++) {
          acc += Math.hypot(r.pts[k][0] - r.pts[k - 1][0], r.pts[k][1] - r.pts[k - 1][1])
          if (acc > r.reta.de) break
        }
        novo = [X, r.reta.p, ...r.pts.slice(k)]
      } else {
        novo = [X, ...r.pts.slice(1)]
      }
      cs[r.i] = r.fim ? novo.reverse() : novo
    }
  }
  const f = linhas[0]?.getFactory()
  if (!f) return linhas
  return cs.map(c => f.createLineString(c.map(([x, y]) => new J.geom.Coordinate(x, y))))
}

/**
 * O "T" com a rua de cima em curva: a reta `r` (o ramo, do nó para fora) é
 * prolongada até cruzar um dos outros ramos, a até VAZIO_CRUZ_MOVE_MAX_M do
 * nó. Ali fica o nó novo: o ramo cruzado é cortado nele, e o pedaço que sobra
 * (entre o nó velho e o novo) vai para um dos outros ramos, para a curva
 * continuar inteira. Muda `cs` no lugar.
 */
function _retaAteACurva(r, ramos, cs, A) {
  const { p, u } = r.reta
  const no = r.pts[0]
  // O lado da reta: do trecho reto para o nó (para trás em u).
  let melhor = null
  for (const o of ramos) {
    if (o === r) continue
    let acc = 0
    for (let k = 1; k < o.pts.length && acc <= VAZIO_CRUZ_MOVE_MAX_M * 2; k++) {
      const a = o.pts[k - 1], b = o.pts[k]
      acc += Math.hypot(b[0] - a[0], b[1] - a[1])
      // p − t·u cruza a–b?
      const ex = b[0] - a[0], ey = b[1] - a[1]
      const den = -u[0] * ey + u[1] * ex
      if (Math.abs(den) < 1e-9) continue
      const wx = a[0] - p[0], wy = a[1] - p[1]
      const t = (-wx * ey + wy * ex) / den           // ao longo de −u, a partir de p
      const w = (-u[0] * wy + u[1] * wx) / den       // ao longo de a–b (0..1)
      if (w < -1e-9 || w > 1 + 1e-9 || t <= 0) continue
      const X = [p[0] - u[0] * t, p[1] - u[1] * t]
      if (Math.hypot(X[0] - no[0], X[1] - no[1]) > VAZIO_CRUZ_MOVE_MAX_M) continue
      if (!melhor || t < melhor.t) melhor = { o, k, X, t }
    }
  }
  if (!melhor) return
  const { o, k, X } = melhor
  // O ramo cruzado: do nó novo para fora.
  const resto = [X, ...o.pts.slice(k)]
  // O pedaço do nó velho até o novo, que passa para um dos outros ramos.
  const pedaco = [...o.pts.slice(0, k), X]
  const outro = ramos.find(x => x !== r && x !== o)
  if (!outro) return
  const outroNovo = [...pedaco.reverse(), ...outro.pts.slice(1)]
  // A reta: do nó novo direto ao trecho reto.
  let acc = 0, j = 1
  for (; j < r.pts.length; j++) {
    acc += Math.hypot(r.pts[j][0] - r.pts[j - 1][0], r.pts[j][1] - r.pts[j - 1][1])
    if (acc > r.reta.de) break
  }
  const retaNova = [X, p, ...r.pts.slice(j)]
  // Os demais ramos (cruzamento de 4 ou mais) também passam pelo nó novo.
  for (const x of ramos) {
    let novo
    if (x === r) novo = retaNova
    else if (x === o) novo = resto
    else if (x === outro) novo = outroNovo
    else novo = [X, ...x.pts.slice(1)]
    cs[x.i] = x.fim ? [...novo].reverse() : novo
  }
}

/**
 * Cruzamento em dois nós: no Voronoi, o cruzamento de quatro ruas sai muitas
 * vezes como dois nós a 1–2 m, ligados por um pedacinho que virava um trecho
 * sem nome. O pedaço mais curto que VAZIO_NO_CURTO_M entre dois cruzamentos
 * some, e os dois nós viram um só, no meio dele.
 */
function _contrairNosCurtos(linhas, J) {
  const kk = c => c.x.toFixed(2) + ',' + c.y.toFixed(2)
  const ponta = (l, fim) => l.getCoordinateN(fim ? l.getNumPoints() - 1 : 0)
  const grau = new Map()
  for (const l of linhas) for (const fim of [false, true]) grau.set(kk(ponta(l, fim)), (grau.get(kk(ponta(l, fim))) || 0) + 1)
  // União dos nós ligados por pedaço curto (uma cadeia vira um nó só).
  const pai = new Map()
  const raiz = k => { while (pai.has(k) && pai.get(k) !== k) k = pai.get(k); return k }
  const soma = new Map()   // raiz → [Σx, Σy, n]
  const curtos = new Set()
  for (const l of linhas) {
    const a = ponta(l, false), b = ponta(l, true)
    if (l.getLength() >= VAZIO_NO_CURTO_M || kk(a) === kk(b) || grau.get(kk(a)) < 3 || grau.get(kk(b)) < 3) continue
    curtos.add(l)
    const ra = raiz(kk(a)), rb = raiz(kk(b))
    if (!soma.has(ra)) soma.set(ra, [a.x, a.y, 1])
    if (!soma.has(rb)) soma.set(rb, [b.x, b.y, 1])
    if (ra === rb) continue
    pai.set(rb, ra)
    const sa = soma.get(ra), sb = soma.get(rb)
    soma.set(ra, [sa[0] + sb[0], sa[1] + sb[1], sa[2] + sb[2]])
  }
  if (!curtos.size) return linhas
  const novoNo = k => { const s = soma.get(raiz(k)); return s ? new J.geom.Coordinate(s[0] / s[2], s[1] / s[2]) : null }
  const f = linhas[0].getFactory()
  const ficam = []
  for (const l of linhas) {
    if (curtos.has(l)) continue
    const cs = l.getCoordinates().map(c => new J.geom.Coordinate(c.x, c.y))
    const a = novoNo(kk(cs[0])), b = novoNo(kk(cs[cs.length - 1]))
    if (a) cs[0] = a
    if (b) cs[cs.length - 1] = b
    if (cs.length === 2 && cs[0].equals2D(cs[1])) continue
    ficam.push(f.createLineString(cs))
  }
  const lm = new J.operation.linemerge.LineMerger()
  ficam.forEach(l => lm.add(l))
  return lm.getMergedLineStrings().toArray()
}

/**
 * Costura das pontas soltas. O eixo entre quadras para onde o vão fica largo
 * demais, e a borda afastada para a VAZIO_JA_TEM_EIXO do eixo transversal:
 * nos cruzamentos da borda do bairro as linhas ficavam a poucos metros umas
 * das outras, sem se tocar. Em ordem:
 *  0. três ou mais pontas soltas juntas (VAZIO_COSTURA_NO_M) viram um nó;
 *  1. duas pontas soltas uma de frente para a outra (até VAZIO_COSTURA_PAR_M,
 *     cada uma apontando para a outra com folga de VAZIO_COSTURA_GRAUS): se juntam;
 *  2. a ponta que, seguindo em frente até VAZIO_COSTURA_M, dá em outra linha: vai até ela;
 *  3. a ponta a até VAZIO_COSTURA_PERTO_M de outra linha: liga no ponto mais perto.
 * A costura que atravessaria uma quadra não é feita.
 * @returns {{linhas: object[], novos: object[]}} as linhas (sem os ganchos) e os segmentos novos, para juntar
 */
function _costurarPontas(linhas, partes, indice, f, J) {
  const C = (x, y) => new J.geom.Coordinate(x, y)
  const kk = c => c.x.toFixed(2) + ',' + c.y.toFixed(2)
  const grau = new Map()
  for (const l of linhas) for (const c of [l.getCoordinateN(0), l.getCoordinateN(l.getNumPoints() - 1)]) grau.set(kk(c), (grau.get(kk(c)) || 0) + 1)
  // Gancho: a borda afastada dobra a esquina da quadra e para logo depois
  // (VAZIO_ESQUINA_M). Na ponta solta, o gancho sai antes de costurar — com
  // ele, a ponta aponta para o lado e não vê a outra metade da rua.
  const semGancho = cs => {
    let s = 0, corte = -1
    for (let i = cs.length - 2; i >= 1; i--) {
      s += Math.hypot(cs[i + 1].x - cs[i].x, cs[i + 1].y - cs[i].y)
      if (s > VAZIO_ESQUINA_M + 2) break
      const ux = cs[i].x - cs[i - 1].x, uy = cs[i].y - cs[i - 1].y, vx = cs[i + 1].x - cs[i].x, vy = cs[i + 1].y - cs[i].y
      const nu = Math.hypot(ux, uy), nv = Math.hypot(vx, vy)
      if (nu < 0.01 || nv < 0.01) continue
      if ((ux * vx + uy * vy) / (nu * nv) < Math.cos(VAZIO_ESQUINA_GRAUS * Math.PI / 180)) corte = i
    }
    return corte > 0 ? cs.slice(0, corte + 1) : cs
  }
  // Quais pontas são soltas, antes de aparar (a ponta aparada não está no grau).
  const solta = linhas.map(l => [grau.get(kk(l.getCoordinateN(0))) === 1, grau.get(kk(l.getCoordinateN(l.getNumPoints() - 1))) === 1])
  linhas = linhas.map((l, il) => {
    let cs = l.getCoordinates()
    if (cs.length < 3 || l.isClosed()) return l
    if (solta[il][1]) cs = semGancho(cs)
    if (solta[il][0]) cs = semGancho([...cs].reverse()).reverse()
    return cs.length === l.getNumPoints() ? l : f.createLineString(cs)
  })
  // Cada ponta solta, com a direção em que a linha chega nela (dos últimos 5 m).
  const pontas = []
  linhas.forEach((l, il) => {
    const L = l.getLength()
    if (L < 1) return
    const li = new J.linearref.LengthIndexedLine(l)
    for (const ini of [true, false]) {
      const c = l.getCoordinateN(ini ? 0 : l.getNumPoints() - 1)
      if (!solta[il][ini ? 0 : 1]) continue
      const t = li.extractPoint(ini ? Math.min(5, L) : L - Math.min(5, L))
      const d = Math.hypot(c.x - t.x, c.y - t.y)
      if (d < 0.5) continue
      pontas.push({ c, ux: (c.x - t.x) / d, uy: (c.y - t.y) / d, il, feita: false })
    }
  })
  if (!pontas.length) return { linhas, novos: [] }
  const cruzaQuadra = seg => indice.query(seg.getEnvelopeInternal()).toArray().some(p => p.intersects(seg))
  const novos = []
  // `passa`: a costura que termina SOBRE outra linha passa 10 cm dela — em
  // ponto flutuante a ponta "em cima" da linha não é cruzamento, e a união
  // não fazia o nó. A pontinha que sobra sai com as rebarbas.
  const costura = (a, b, passa = false) => {
    const d = Math.hypot(b.x - a.x, b.y - a.y)
    if (d < 0.01) return false
    const e = passa ? 0.1 / d : 0
    const seg = f.createLineString([C(a.x, a.y), C(b.x + (b.x - a.x) * e, b.y + (b.y - a.y) * e)])
    if (cruzaQuadra(seg)) return false
    novos.push(seg)
    return true
  }
  // Ponto de chegada perto (2 m) de um vértice da linha: vai no vértice, que
  // já é nó — sem o pedacinho de poucos centímetros entre dois nós.
  const ligar = (a, b, linha) => {
    let v = null, dv = 2
    for (const c of linha.getCoordinates()) { const d = Math.hypot(c.x - b.x, c.y - b.y); if (d < dv) { dv = d; v = c } }
    return v ? costura(a, v) : costura(a, b, true)
  }

  // 0. Três ou mais pontas soltas juntas (a até VAZIO_COSTURA_NO_M uma da
  // outra): é um cruzamento que o vazio não fechou; viram um nó, no meio delas.
  const grupoDe = new Map()
  pontas.forEach((p, i) => grupoDe.set(p, i))
  const raizP = p => { let g = grupoDe.get(p); while (grupoDe.get(pontas[g]) !== g) g = grupoDe.get(pontas[g]); return g }
  for (let i = 0; i < pontas.length; i++) for (let j = i + 1; j < pontas.length; j++) {
    if (Math.hypot(pontas[i].c.x - pontas[j].c.x, pontas[i].c.y - pontas[j].c.y) > VAZIO_COSTURA_NO_M) continue
    const a = raizP(pontas[i]), b = raizP(pontas[j])
    if (a !== b) grupoDe.set(pontas[b], a)
  }
  const grupos = new Map()
  for (const p of pontas) { const g = raizP(p); (grupos.get(g) || grupos.set(g, []).get(g)).push(p) }
  for (const g of grupos.values()) {
    if (g.length < 3) continue
    const meio = C(g.reduce((s, p) => s + p.c.x, 0) / g.length, g.reduce((s, p) => s + p.c.y, 0) / g.length)
    for (const p of g) if (costura(p.c, meio)) p.feita = true
  }

  // 1. Pares de frente.
  const cosMax = Math.cos(VAZIO_COSTURA_GRAUS * Math.PI / 180)
  const pares = []
  for (let i = 0; i < pontas.length; i++) for (let j = i + 1; j < pontas.length; j++) {
    const a = pontas[i], b = pontas[j]
    if (a.il === b.il && linhas[a.il].getLength() < 4 * VAZIO_COSTURA_PAR_M) continue   // fecharia um laço
    const dx = b.c.x - a.c.x, dy = b.c.y - a.c.y, d = Math.hypot(dx, dy)
    if (d < 0.01 || d > VAZIO_COSTURA_PAR_M) continue
    if ((a.ux * dx + a.uy * dy) / d < cosMax || -(b.ux * dx + b.uy * dy) / d < cosMax) continue
    pares.push({ a, b, d })
  }
  pares.sort((x, y) => x.d - y.d)
  for (const { a, b } of pares) {
    if (a.feita || b.feita) continue
    if (costura(a.c, b.c)) a.feita = b.feita = true
  }

  // A ponta que passou um pouco de outra linha (já a cruza a até 10 m da
  // ponta) não é costurada: é pontinha que sobra e sai com as rebarbas.
  // Voltar dela até a linha fazia um laço.
  const jaCruza = p => {
    const propria = linhas[p.il]
    for (const l of [...linhas.filter((_, k) => k !== p.il), ...novos]) {
      if (!l.getEnvelopeInternal().intersects(propria.getEnvelopeInternal())) continue
      let x
      try { x = propria.intersection(l) } catch { continue }
      if (x.getCoordinates().some(c => Math.hypot(c.x - p.c.x, c.y - p.c.y) < 10)) return true
    }
    return false
  }
  for (const p of pontas) if (!p.feita && jaCruza(p)) p.feita = true

  // 2. Em frente, até outra linha (as costuras já feitas contam).
  for (const p of pontas) {
    if (p.feita) continue
    const raio = f.createLineString([C(p.c.x, p.c.y), C(p.c.x + p.ux * VAZIO_COSTURA_M, p.c.y + p.uy * VAZIO_COSTURA_M)])
    let melhor = null, dela = null, dMin = Infinity
    const alvos = [...linhas.filter((_, k) => k !== p.il), ...novos]
    for (const l of alvos) {
      if (!l.getEnvelopeInternal().intersects(raio.getEnvelopeInternal())) continue
      let x
      try { x = l.intersection(raio) } catch { continue }
      for (const c of x.getCoordinates()) {
        const d = Math.hypot(c.x - p.c.x, c.y - p.c.y)
        if (d > 0.01 && d < dMin) { dMin = d; melhor = c; dela = l }
      }
    }
    if (melhor && ligar(p.c, melhor, dela)) p.feita = true
  }

  // 3. Bem perto de outra linha.
  for (const p of pontas) {
    if (p.feita) continue
    const pt = f.createPoint(C(p.c.x, p.c.y))
    let melhor = null, dela = null, dMin = VAZIO_COSTURA_PERTO_M
    for (const l of [...linhas.filter((_, k) => k !== p.il), ...novos]) {
      const d = l.distance(pt)
      if (d > 0.01 && d < dMin) { dMin = d; melhor = J.operation.distance.DistanceOp.nearestPoints(l, pt)[0]; dela = l }
    }
    if (melhor && ligar(p.c, melhor, dela)) p.feita = true
  }
  return { linhas, novos }
}

/**
 * Rua de um lado só com OUTRA quadra do bairro à frente (até VAZIO_DEFRONTE_M):
 * é rua num vão largo (praça, área verde, gleba do outro lado), e não muro.
 * De pontos a cada 10 m, olha para longe da quadra mais perto; vale se a
 * metade ou mais dá em outra quadra SEM passar antes por um eixo entre
 * quadras (avenida larga: o meio dela já é a rua, e a borda afastada não é).
 */
function _temQuadraDefronte(pts, partes, indice, eixosEntre, f, J) {
  const eixos = eixosEntre.length ? f.createMultiLineString(eixosEntre) : null
  const linha = f.createLineString(pts.map(([x, y]) => new J.geom.Coordinate(x, y)))
  const n = Math.max(1, Math.round(linha.getLength() / 10))
  const li = new J.linearref.LengthIndexedLine(linha)
  let viu = 0
  for (let k = 0; k < n; k++) {
    const c = li.extractPoint((k + 0.5) * linha.getLength() / n)
    const pt = f.createPoint(c)
    let dona = null, dMin = Infinity
    for (const p of partes) { const d = p.distance(pt); if (d < dMin) { dMin = d; dona = p } }
    if (!dona || dMin < 0.01) continue
    const perto = J.operation.distance.DistanceOp.nearestPoints(dona, pt)[0]
    const ux = (c.x - perto.x) / dMin, uy = (c.y - perto.y) / dMin
    const raio = f.createLineString([new J.geom.Coordinate(c.x + ux, c.y + uy), new J.geom.Coordinate(c.x + ux * VAZIO_DEFRONTE_M, c.y + uy * VAZIO_DEFRONTE_M)])
    if (eixos && eixos.intersects(raio)) continue
    if (indice.query(raio.getEnvelopeInternal()).toArray().some(p => p !== dona && p.intersects(raio))) viu++
  }
  return viu * 2 >= n
}

/**
 * Parte a polilinha nas ESQUINAS: onde a direção muda mais de VAZIO_ESQUINA_GRAUS
 * entre VAZIO_ESQUINA_M antes e depois do vértice. A volta em torno de uma
 * quadra sozinha (sem cruzamento que a parta) eram quatro ruas com um nome só.
 * Curva de rua (Splendore) dobra pouco nesses metros e não é partida.
 * @param {number[][]} pts @returns {number[][][]}
 */
function _partirNasEsquinas(pts) {
  if (pts.length < 3) return [pts]
  // Linha fechada (a volta da quadra): passa a começar no meio do lado mais
  // longo, que não é esquina; no fim, o primeiro e o último pedaço se juntam.
  const fechada = pts.length > 3 && Math.hypot(pts[0][0] - pts[pts.length - 1][0], pts[0][1] - pts[pts.length - 1][1]) < 0.01
  if (fechada) {
    let k = 0, maior = -1
    for (let i = 0; i + 1 < pts.length; i++) {
      const d = Math.hypot(pts[i + 1][0] - pts[i][0], pts[i + 1][1] - pts[i][1])
      if (d > maior) { maior = d; k = i }
    }
    const meio = [(pts[k][0] + pts[k + 1][0]) / 2, (pts[k][1] + pts[k + 1][1]) / 2]
    pts = [meio, ...pts.slice(k + 1, -1), ...pts.slice(0, k + 1), meio]
  }
  const acum = [0]
  for (let i = 1; i < pts.length; i++) acum.push(acum[i - 1] + Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]))
  const total = acum[acum.length - 1]
  const em = s => {   // o ponto a s metros do início
    s = Math.max(0, Math.min(total, s))
    let i = 1
    while (i < pts.length - 1 && acum[i] < s) i++
    const t = (s - acum[i - 1]) / ((acum[i] - acum[i - 1]) || 1)
    return [pts[i - 1][0] + (pts[i][0] - pts[i - 1][0]) * t, pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * t]
  }
  const giro = i => {
    const a = em(acum[i] - VAZIO_ESQUINA_M), b = pts[i], c = em(acum[i] + VAZIO_ESQUINA_M)
    const u = [b[0] - a[0], b[1] - a[1]], v = [c[0] - b[0], c[1] - b[1]]
    const nu = Math.hypot(...u), nv = Math.hypot(...v)
    if (nu < 0.5 || nv < 0.5) return 0
    return Math.acos(Math.max(-1, Math.min(1, (u[0] * v[0] + u[1] * v[1]) / (nu * nv)))) * 180 / Math.PI
  }
  const giros = pts.map((_, i) => (i === 0 || i === pts.length - 1) ? 0 : giro(i))
  const cortes = []
  for (let i = 1; i < pts.length - 1; i++) {
    if (giros[i] < VAZIO_ESQUINA_GRAUS) continue
    // Da mesma esquina (vértices a menos de VAZIO_ESQUINA_M), só o que mais dobra.
    const perto = j => Math.abs(acum[j] - acum[i]) < VAZIO_ESQUINA_M
    if (perto(i - 1) && giros[i - 1] >= VAZIO_ESQUINA_GRAUS && giros[i - 1] >= giros[i]) continue
    if (perto(i + 1) && giros[i + 1] >= VAZIO_ESQUINA_GRAUS && giros[i + 1] > giros[i]) continue
    cortes.push(i)
  }
  if (!cortes.length) return [pts]
  const partes = []
  let ini = 0
  for (const c of cortes) { partes.push(pts.slice(ini, c + 1)); ini = c }
  partes.push(pts.slice(ini))
  // O ponto do meio (o início artificial) sai: está na reta entre os vizinhos.
  if (fechada && partes.length > 1) partes[0] = [...partes.pop().slice(0, -1), ...partes[0].slice(1)]
  return partes.filter(p => p.length >= 2)
}

/**
 * União (e nó nos cruzamentos) de linhas, com o socorro de precisão de
 * _sobreporComSeguranca: 1 mm, depois 1 cm, depois 10 cm.
 */
function _unirLinhas(f, linhas) {
  const J = window.jsts
  const todas = f.createMultiLineString(linhas)
  try { return J.operation.union.UnaryUnionOp.union(todas) } catch { /* refaz com precisão reduzida */ }
  for (const escala of [1000, 100, 10]) {
    try { return J.operation.union.UnaryUnionOp.union(J.precision.GeometryPrecisionReducer.reduce(todas, new J.geom.PrecisionModel(escala))) } catch { /* próxima grade */ }
  }
  return todas
}

/**
 * O nome de cada eixo: os lotes votam pelo endereço, cada frente (aresta do
 * lote sobre a borda da quadra) no eixo à sua frente. Lote que dá para um eixo
 * só vale 1; o de esquina, 0,25. Vence o mais votado com soma ≥ 1.
 */
function _nomearEixos(linhas, partesComLotes, J) {
  const eixos = linhas.map(l => ({ ...l, votos: new Map() }))
  // Segmentos de todos os eixos, numa grade de 25 m.
  const grade = new Map(), CEL = 25
  eixos.forEach((e, ie) => {
    for (let i = 1; i < e.pts.length; i++) {
      const a = e.pts[i - 1], b = e.pts[i]
      const n = Math.ceil(Math.hypot(b[0] - a[0], b[1] - a[1]) / CEL) + 1
      const vistos = new Set()
      for (let k = 0; k <= n; k++) {
        const x = a[0] + (b[0] - a[0]) * k / n, y = a[1] + (b[1] - a[1]) * k / n
        const c = Math.floor(x / CEL) + ':' + Math.floor(y / CEL)
        if (vistos.has(c)) continue
        vistos.add(c)
        ;(grade.get(c) || grade.set(c, []).get(c)).push([ie, a, b])
      }
    }
  })
  const paralelo = Math.cos(30 * Math.PI / 180)
  const eixoEmFrente = (mx, my, ux, uy) => {
    let melhor = null, dmin = VAZIO_MEIA_MAX + 3
    const cx = Math.floor(mx / CEL), cy = Math.floor(my / CEL)
    for (let dx = -1; dx <= 1; dx++) for (let dy = -1; dy <= 1; dy++) {
      for (const [ie, a, b] of grade.get((cx + dx) + ':' + (cy + dy)) || []) {
        const L = Math.hypot(b[0] - a[0], b[1] - a[1])
        if (L < 0.1) continue
        const sx = (b[0] - a[0]) / L, sy = (b[1] - a[1]) / L
        if (Math.abs(sx * ux + sy * uy) < paralelo) continue
        const t = Math.max(0, Math.min(L, (mx - a[0]) * sx + (my - a[1]) * sy))
        const d = Math.hypot(mx - a[0] - sx * t, my - a[1] - sy * t)
        if (d < dmin) { dmin = d; melhor = ie }
      }
    }
    return melhor
  }
  for (const { p, lotes } of partesComLotes) {
    const f = p.getFactory()
    const borda = f.createLineString(p.getExteriorRing().getCoordinates())
    const sobre = (x, y) => borda.distance(f.createPoint(new J.geom.Coordinate(x, y))) <= RUA_TOLERANCIA_BORDA
    for (const l of lotes) {
      const toca = new Set()
      const cs = l.g.getExteriorRing().getCoordinates()
      for (let i = 0; i + 1 < cs.length; i++) {
        const a = cs[i], b = cs[i + 1]
        const len = Math.hypot(b.x - a.x, b.y - a.y)
        if (len < 1) continue
        const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2
        if (!sobre(a.x, a.y) || !sobre(b.x, b.y) || !sobre(mx, my)) continue
        const ie = eixoEmFrente(mx, my, (b.x - a.x) / len, (b.y - a.y) / len)
        if (ie !== null) toca.add(ie)
      }
      if (!l.logradouro) continue
      const peso = toca.size === 1 ? 1 : 0.25
      // Lote que dá para um eixo entre quadras E para uma borda de lado único
      // (o chacarão com frente na avenida e fundo no campo): o fundo não leva
      // o nome da frente. O voto fica só com o eixo entre quadras.
      const entre = [...toca].filter(ie => !eixos[ie].ladoUnico)
      for (const ie of entre.length ? entre : toca) eixos[ie].votos.set(l.logradouro, (eixos[ie].votos.get(l.logradouro) || 0) + peso)
    }
  }
  const nomeados = eixos.map(e => {
    let nome = null, maior = 0
    for (const [n, v] of e.votos) if (v > maior) { maior = v; nome = n }
    return { pts: e.pts, ladoUnico: e.ladoUnico, defronte: e.defronte, nome: maior >= 1 ? nome : null }
  })
  // Lado único sem nome é, quase sempre, muro ou fundo de lote (a borda do
  // condomínio): sai, se o bairro tem nomes. Sem cadastro, fica tudo. Fica
  // o que tem outra quadra do bairro à frente: rua num vão largo.
  const temNomes = nomeados.some(e => e.nome)
  return nomeados.filter(e => !(e.ladoUnico && !e.nome && temNomes && !e.defronte))
}

/**
 * Polígonos em metros (anéis do JSTS) → MultiPolygon lon/lat em 8 casas.
 *
 * SENTIDO DOS ANÉIS, no padrão do GeoJSON (RFC 7946): externo anti-horário,
 * furos horários. Em coordenada GEOGRÁFICA o MySQL lê anel externo horário
 * como "o resto do planeta" — o contorno passava a deixar o bairro de fora.
 */
function _multiPoligonoLonLat(poligonos, plano) {
  const area2 = anel => anel.reduce((s, p, i) => { const q = anel[(i + 1) % anel.length]; return s + p[0] * q[1] - q[0] * p[1] }, 0)
  const orientar = (anel, externo) => ((area2(anel) > 0) === externo ? anel : [...anel].reverse())
  return {
    type: 'MultiPolygon',
    coordinates: poligonos.map(aneis => aneis.map((anel, i) =>
      orientar(anel.map(c => plano.de(c).map(n => Number(n.toFixed(8)))), i === 0))),
  }
}

// ── LISTA DA CURADORIA ───────────────────────────────────────

/** "Contorno dos bairros" no painel de alteração cadastral. */
async function abrirContornosDosBairros() {
  // O raio digitado sobrevive à lista ser refeita (depois de gerar, ou de uma
  // recusa): voltar a 25 obrigaria a digitá-lo de novo a cada tentativa.
  const raioEscolhido = Number(document.getElementById('ctn-raio')?.value) || CONTORNO_RAIO_PADRAO
  await abrirJanelaDoMapa()   // fecha a ferramenta em uso (ferramentas-mapa.js)
  openModal('m-importacoes')
  _impCorpo('<div class="vazio-msg">Carregando…</div>', 'Contorno dos bairros')
  await carregarContornosDosBairros()
  const d = contornoState.dados || { features: [], sem_contorno: [] }
  const linhas = [
    ...d.features.map(f => ({ nome: f.properties.nome, oficial: f.properties.nome_oficial, p: f.properties })),
    ...(d.sem_contorno || []).map(nome => ({ nome, oficial: nome, p: null })),
  ].sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'))

  const situacao = p => !p ? '<span class="badge bd-in">sem contorno</span>'
    : (p.desatualizado ? '<span class="badge bd-pe">desatualizado</span>' : '<span class="badge bd-ok">em dia</span>')
      + (p.editado_manual ? ` <span class="badge bd-num" title="Editado à mão em ${esc(p.editado_em || '')}">editado à mão</span>` : '')

  const nDesat = linhas.filter(l => l.p?.desatualizado).length
  const nSem = linhas.filter(l => !l.p).length
  _impCorpo(`
    <div class="jt">
      <div class="jt-topo">
        <div class="jt-linha">
          ${jtBusca('ctn-busca', 'Pesquisar por bairro ou situação')}
          <label class="jt-campo" for="ctn-raio" title="Distância, em metros, usada para fechar as ruas entre as quadras ao gerar o contorno.">Raio do fechamento
            <input type="number" id="ctn-raio" min="5" max="200" step="1" value="${raioEscolhido}"> m</label>
        </div>
        <p class="imp-expl">O contorno é a união dos lotes do bairro com as ruas entre as quadras preenchidas
          (fechamento de <b>raio</b> metros). Fica tracejado no mapa e mostra o nome do bairro com o mapa afastado; junto sai
          o contorno de cada <b>quadra</b>. Fica <b>desatualizado</b> quando a curadoria mexe nos lotes depois de gerado.</p>
      </div>
      <div class="jt-rolagem">
        <table class="imp-tabela jt-tabela"><thead><tr><th>Bairro</th><th>Situação</th><th>Gerado em</th>
          <th class="num">Raio</th><th class="num">Quadras</th><th class="num">Área</th><th></th></tr></thead><tbody>
        ${linhas.map(l => `<tr data-bairro="${esc(l.nome)}">
          <td class="jt-quebra"><b class="jt-forte">${esc(l.oficial)}</b>${l.oficial !== l.nome ? `<div class="imp-sub">${esc(l.nome)}</div>` : ''}
            ${l.p?.isolados?.length ? `<div class="imp-sub imp-diverge">${l.p.isolados.length} lote(s) isolado(s) fora do contorno:
              ${l.p.isolados.map(id => `<a href="#" onclick="event.preventDefault(); irAoLoteIsolado(${Number(id)})">nº ${Number(id)}</a>`).join(', ')}</div>` : ''}
            ${contornoState.fora[l.nome]?.length ? `<div class="imp-sub imp-diverge">Última tentativa recusada — ${contornoState.fora[l.nome].length} lote(s) fora do contorno (clique para ver no mapa):
              ${contornoState.fora[l.nome].map(x => `<a href="#" onclick="event.preventDefault(); irAoLoteIsolado(${Number(x.id)})">Q ${esc(x.quadra ?? '—')} · L ${esc(x.lote ?? '—')}</a>`).join(', ')}</div>` : ''}</td>
          <td>${situacao(l.p)}</td>
          <td>${l.p?.contorno_em ? esc(l.p.contorno_em) : '—'}</td>
          <td class="num">${l.p?.contorno_em ? l.p.raio_m + ' m' : '—'}</td>
          <td class="num">${l.p?.contorno_em ? (l.p.quadras ? l.p.quadras : '<span class="imp-diverge" title="Gere de novo para criar as quadras">sem quadras</span>') : '—'}</td>
          <td class="num">${l.p ? l.p.area_ha.toLocaleString('pt-BR') + ' ha' : '—'}</td>
          <td class="jt-acoes">${l.p ? `<button class="btn sm" onclick="editarContornoDoBairro(${jsArg(l.nome)})" title="Puxar vértices, encostar no vizinho">Editar</button>` : ''}
            ${l.p?.editado_manual ? `<button class="btn sm" onclick="desfazerEdicaoDoContorno(${jsArg(l.nome)})">Desfazer edição</button>` : ''}
            <button class="btn sm" onclick="gerarDaLista(this, ${jsArg(l.nome)})">${l.p ? 'Gerar de novo' : 'Gerar'}</button></td>
        </tr>`).join('') || '<tr class="jt-sem-linhas"><td colspan="7">Nenhum bairro com lotes.</td></tr>'}
        </tbody></table>
        <div class="jt-nada" hidden>Nenhum bairro com o que foi pesquisado.</div>
      </div>
      <div class="jt-rodape">
        <span class="imp-sub">${linhas.length} bairro(s)${nDesat ? ` · ${nDesat} desatualizado(s)` : ''}${nSem ? ` · ${nSem} sem contorno` : ''}${!nDesat && !nSem ? ' · todos em dia' : ''}</span>
        <div class="btn-row"><button class="btn" onclick="fecharImportacoes()">Fechar</button>
          ${d.features.length ? `<button class="btn" onclick="refazerRuasDeTodos(this)" title="Refaz só os nomes de rua, sem mexer no contorno nem nas quadras">Refazer nomes de rua (${d.features.length})</button>` : ''}
          ${nDesat ? `<button class="btn primary" onclick="atualizarContornosDesatualizados(this)">Atualizar desatualizados (${nDesat})</button>` : ''}</div>
      </div>
    </div>`,
    'Contorno dos bairros')
  jtAplicar('ctn-busca')
}

/**
 * Gera de novo o contorno dos bairros que ENCOSTAM neste (ou ficam a até
 * ~30 m dele). Um contorno gerado antes de o vizinho existir não conhecia a
 * divisa: o passo de fusão (CONTORNO_RAIO_FUSAO) só respeita os lotes de
 * outros bairros que já estavam lá. Em silêncio; o editado à mão é mantido.
 *
 * @param {string} bairro
 * @returns {Promise<string[]>} os vizinhos gerados de novo
 */
async function gerarContornosVizinhos(bairro) {
  const fs = contornoState.dados?.features || []
  const caixa = f => { let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity
    const g = f.geometry, ps = g.type === 'Polygon' ? [g.coordinates] : g.coordinates
    for (const p of ps) for (const c of p[0]) { x0 = Math.min(x0, c[0]); x1 = Math.max(x1, c[0]); y0 = Math.min(y0, c[1]); y1 = Math.max(y1, c[1]) }
    return [x0, y0, x1, y1] }
  const este = fs.find(f => f.properties?.nome === bairro)
  if (!este) return []
  const [a0, b0, a1, b1] = caixa(este), folga = 0.0003   // ~30 m
  const vizinhos = fs.filter(f => f !== este && !f.properties?.editado_manual).filter(f => {
    const [c0, d0, c1, d1] = caixa(f)
    return !(c1 < a0 - folga || c0 > a1 + folga || d1 < b0 - folga || d0 > b1 + folga)
  }).map(f => f.properties.nome)
  const feitos = []
  for (const nome of vizinhos) {
    const r = await gerarContornoDoBairro(nome, Number(fs.find(f => f.properties?.nome === nome)?.properties?.raio_m) || CONTORNO_RAIO_PADRAO, { silencioso: true })
    if (r && !r.mantido) feitos.push(nome)
  }
  if (feitos.length) toast(`Contorno dos vizinhos ajustado à divisa: ${feitos.join(', ')}.`)
  return feitos
}

async function gerarDaLista(btn, bairro) {
  const raio = Number(document.getElementById('ctn-raio')?.value) || CONTORNO_RAIO_PADRAO
  btn.disabled = true; btn.textContent = 'Calculando…'
  const r = await gerarContornoDoBairro(bairro, raio)
  // Refaz a lista também na recusa com lotes de fora: é nela que eles aparecem.
  if (r || contornoState.fora[bairro]?.length) await abrirContornosDosBairros()
  else { btn.disabled = false; btn.textContent = 'Gerar' }
}

/**
 * Atualiza, um por vez, só os contornos DESATUALIZADOS (a curadoria mexeu nos
 * lotes depois de gerados). No geral não há "Gerar todos": o que está em dia
 * fica como está, e bairro sem contorno se gera pela própria linha. Cada um
 * mantém o raio com que foi gerado; o editado à mão fica como estava.
 * @param {HTMLButtonElement} btn
 */
function atualizarContornosDesatualizados(btn) {
  const fs = (contornoState.dados?.features || []).filter(f => f.properties?.desatualizado)
  if (!fs.length) return
  const raios = Object.fromEntries(fs.map(f => [f.properties.nome, Number(f.properties.raio_m) || CONTORNO_RAIO_PADRAO]))
  confirmarAcao({
    titulo: 'Atualizar contornos desatualizados',
    mensagem: `Calcula de novo o contorno e as quadras dos ${fs.length} bairro(s) desatualizado(s), um por vez, `
      + 'cada um com o raio com que foi gerado. Pode levar alguns minutos; deixe esta janela aberta até terminar.',
    textoBtn: 'Atualizar',
    // Sem await: a confirmação fecha na hora, e o andamento aparece no botão.
    onConfirm: () => { _gerarEmSequencia(btn, Object.keys(raios), raios) },
  })
}

/** @param {HTMLButtonElement} btn @param {string[]} nomes @param {Record<string, number>} raios */
async function _gerarEmSequencia(btn, nomes, raios = {}) {
  const raioCampo = Number(document.getElementById('ctn-raio')?.value) || CONTORNO_RAIO_PADRAO
  btn.disabled = true
  const falhas = [], mantidos = []
  for (const [i, nome] of nomes.entries()) {
    btn.textContent = `Atualizando ${i + 1} de ${nomes.length}…`
    const r = await gerarContornoDoBairro(nome, raios[nome] || raioCampo, { silencioso: true })
    if (!r) falhas.push(nome)
    else if (r.mantido) mantidos.push(nome)
  }
  toast(falhas.length
    ? `${nomes.length - falhas.length} bairro(s) atualizado(s). ${falhas.length} falharam — use "Gerar de novo" na linha para ver o motivo: ${falhas.join(', ')}.`
    : `${nomes.length - mantidos.length} bairro(s) atualizado(s), com as quadras.`
      + (mantidos.length ? ` ${mantidos.length} editado(s) à mão ficaram como estavam: ${mantidos.join(', ')}.` : ''), falhas.length ? 'aviso' : 'ok')
  // Só refaz a lista se ela ainda estiver aberta.
  if (document.getElementById('m-importacoes')?.classList.contains('open') && document.getElementById('ctn-raio')) {
    await abrirContornosDosBairros()
  }
}

/**
 * Refaz SÓ os trechos de rua de um bairro, pela regra do meio entre as
 * quadras: o contorno e as quadras ficam como estão (o editado à mão também).
 * Os informados à mão passam para o desenho novo no servidor.
 *
 * @param {string} bairro
 * @returns {Promise<Object>} a resposta do servidor
 */
async function gerarRuasDoBairro(bairro) {
  await _carregarJsts()
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? ''
  const rl = await fetch('/api/bairros/lotes?bairro=' + encodeURIComponent(bairro), { headers: { Accept: 'application/json' } })
  const dl = await rl.json()
  if (!rl.ok) throw new Error(dl.message || 'Não foi possível ler os lotes do bairro.')
  if (!dl.lotes.length) throw new Error('O bairro não tem lotes ativos.')
  const { ruas } = calcularQuadrasERuas(dl.lotes)
  const rg = await fetch('/api/bairros/contorno', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ bairro, so_ruas: true, ruas }),
  })
  const dg = await rg.json()
  if (!rg.ok) throw new Error(dg.message || 'O servidor recusou os nomes de rua.')
  return dg
}

/** "Refazer nomes de rua": todos os bairros com contorno, um por vez. @param {HTMLButtonElement} btn */
function refazerRuasDeTodos(btn) {
  const nomes = (contornoState.dados?.features || []).map(f => f.properties.nome)
  if (!nomes.length) return
  confirmarAcao({
    titulo: 'Refazer nomes de rua',
    mensagem: `Refaz os trechos de rua dos ${nomes.length} bairro(s) com contorno, um por vez, pelo meio do espaço entre as quadras. `
      + 'O contorno e as quadras não mudam. Os nomes informados à mão passam para o desenho novo; as exclusões feitas '
      + 'na linha antiga, que não existe mais, saem. Pode levar alguns minutos; deixe esta janela aberta até terminar.',
    textoBtn: 'Refazer',
    onConfirm: () => { _refazerRuasEmSequencia(btn, nomes) },
  })
}

/** @param {HTMLButtonElement} btn @param {string[]} nomes */
async function _refazerRuasEmSequencia(btn, nomes) {
  const rotulo = btn.textContent
  btn.disabled = true
  const falhas = []
  let semNome = 0, passados = 0, descartados = 0
  for (const [i, nome] of nomes.entries()) {
    btn.textContent = `Ruas ${i + 1} de ${nomes.length}…`
    try {
      const r = await gerarRuasDoBairro(nome)
      semNome += r.sem_nome || 0; passados += r.manuais_passados || 0; descartados += r.manuais_descartados || 0
    } catch (e) {
      falhas.push(nome)
      console.warn('Nomes de rua de ' + nome + ':', e)
    }
  }
  const feitos = nomes.length - falhas.length
  toast(`Nomes de rua refeitos em ${feitos} bairro(s)`
    + (semNome ? `; ${semNome} trecho(s) sem nome — veja em Nomes de rua` : '')
    + (passados ? `; ${passados} nome(s) informado(s) à mão passado(s) para o desenho novo` : '')
    + (descartados ? `; ${descartados} exclusão(ões) da linha antiga removida(s)` : '')
    + (falhas.length ? `. ${falhas.length} falharam: ${falhas.join(', ')}` : '') + '.',
  falhas.length ? 'aviso' : 'ok')
  btn.disabled = false; btn.textContent = rotulo
}

/** Lote isolado: vai até ele, onde quer que a coordenada o tenha posto. */
async function irAoLoteIsolado(id) {
  try {
    const r = await fetch('/api/imoveis/' + id, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'Lote não encontrado.')
    fecharImportacoes()
    if (d.lat && d.lon) verImovelNoMapa(d.lat, d.lon)
    toast(`Lote isolado: Q ${d.quadra ?? '—'} · L ${d.lote ?? '—'} — confira a coordenada dele.`, 'aviso')
  } catch (e) { toast(e.message, 'err') }
}
