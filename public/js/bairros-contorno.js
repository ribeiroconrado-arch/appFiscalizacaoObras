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

/** Pedaço com até isso de lotes, longe do corpo do bairro, é lote ISOLADO (ex.: coordenada corrompida). */
const CONTORNO_ISOLADO_LOTES = 2
const CONTORNO_ISOLADO_M = 200

// ── DESENHO NO MAPA ──────────────────────────────────────────

/**
 * Traço conforme o zoom: forte com o mapa afastado, discreto entre os lotes.
 * Amarelo, e não branco: os lotes já são brancos, e com o mapa afastado eles
 * viram uma mancha branca que engolia um contorno da mesma cor.
 */
function _estiloContorno() {
  const z = mapaState.obj?.getZoom() ?? 12
  const longe = z < 16
  return { color: '#facc15', weight: longe ? 3 : 1.6, opacity: longe ? 1 : .85,
           dashArray: longe ? '10 6' : '6 6', fill: false, interactive: false, pane: 'contornos' }
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
  }
  try {
    const r = await fetch('/api/mapa/bairros', { headers: { Accept: 'application/json' } })
    if (!r.ok) return
    contornoState.dados = await r.json()
  } catch { return }

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
  // Os rótulos de bairro tirados dos lotes cedem lugar aos do contorno.
  if (typeof desenharRotulosDeGrupo === 'function') desenharRotulosDeGrupo()
  if (typeof aplicarNivelDoMapa === 'function') aplicarNivelDoMapa()
  carregarQuadrasVisiveis()
}

// ── QUADRAS NO MAPA ──────────────────────────────────────────

const quadraState = {
  /** @type {Map<string, {feicoes:Object[], camada:L.GeoJSON}|'carregando'>} por nome do bairro */
  porBairro: new Map(),
  /** @type {L.Marker[]} os números das quadras que estão na tela */
  rotulos: [],
  /** @type {L.Marker[]} os nomes de rua que estão na tela */
  rotulosRua: [],
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
  if (!['quadras', 'lotes'].includes(nivelDoMapa(mapa))) { _desenharRotulosDeQuadra(); _desenharRotulosDeRua(); return }

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
      style: { color: '#ffffff', weight: 1.4, opacity: .9, fill: false },
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
  [/^ALAMEDA\b/i, 'AL.'], [/^RODOVIA\b/i, 'ROD.'], [/^ESTRADA\b/i, 'EST.'], [/^PRA[CÇ]A\b/i, 'PÇ.']]

/** Largura média de uma letra do rótulo de rua, em pixels (ver .rot-rua). */
const RUA_PX_POR_LETRA = 6.4

/** @param {string} nome */
function _abreviarRua(nome) {
  for (const [re, ab] of RUA_ABREVIACOES) if (re.test(nome)) return nome.replace(re, ab)
  return nome
}


/** De quantos em quantos pixels o nome da MESMA rua se repete ao longo dela. */
const RUA_REPETE_PX = 560

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
 */
function _desenharRotulosDeRua() {
  quadraState.rotulosRua.forEach(m => m.remove())
  quadraState.rotulosRua = []
  const mapa = mapaState.obj
  if (!mapa || typeof nivelDoMapa !== 'function' || !['quadras', 'lotes'].includes(nivelDoMapa(mapa))) return

  const vista = mapa.getBounds().pad(0.05)
  const zoom = mapa.getZoom()

  for (const b of quadraState.porBairro.values()) {

    if (b === 'carregando') continue

    // 1. Os trechos com nome, agrupados por rua, com a posição absoluta do meio.
    const ruas = new Map()
    for (const t of b.ruas) {
      if (!t.nome || t.origem === 'oculto' || t.origem === 'sem_nome') continue
      const meio = L.latLng((t.de[0] + t.ate[0]) / 2, (t.de[1] + t.ate[1]) / 2)
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

      // 4. Só o que está na tela vira elemento.
      for (const c of porFaixa.values()) {
        if (!vista.contains(c.meio)) continue
        let ang = Math.atan2(c.p2.y - c.p1.y, c.p2.x - c.p1.x) * 180 / Math.PI
        if (ang > 90) ang -= 180
        else if (ang <= -90) ang += 180
        quadraState.rotulosRua.push(L.marker(c.meio, {
          interactive: false, keyboard: false,
          icon: L.divIcon({ className: 'rot-rua', iconSize: [0, 0],
            html: `<span style="transform:translate(-50%,-50%) rotate(${ang.toFixed(1)}deg)">${esc(c.texto)}</span>` }),
        }).addTo(mapa))
      }
    }
  }
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
        isolados: calc.isolados, quadras, ruas }),
    })
    const dg = await rg.json()
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
  const uniao = J.operation.union.UnaryUnionOp.union(fabrica.createGeometryCollection(emMetros.map(x => x.g)))

  // Fechamento com quina em esquadro: a borda do loteamento fica reta, não arredondada.
  const P = J.operation.buffer.BufferParameters
  const par = new P(); par.setJoinStyle(P.JOIN_MITRE); par.setMitreLimit(3)
  const buf = (g, d) => J.operation.buffer.BufferOp.bufferOp(g, d, par)
  const fechar = (g, r) => buf(buf(g, r), -r)
  const partesDe = g => { const ps = []; for (let i = 0; i < g.getNumGeometries(); i++) ps.push(g.getGeometryN(i)); return ps }

  // O que a fusão não pode engolir: os lotes dos outros bairros (com 2 m de folga).
  const outros = vizinhos.length
    ? J.operation.union.UnaryUnionOp.union(fabrica.createGeometryCollection(vizinhos.map(v =>
        leitor.read({ type: 'Polygon', coordinates: v.coordinates.map(anel => anel.map(c => plano.para(c))) })))).buffer(2)
    : null
  const livre = g => !outros || !g.intersects(outros)

  // 1 e 2: fechamento comum, e a fusão só onde não há vizinho.
  let fechado = fechar(uniao, raio)
  const acrescimos = partesDe(fechar(uniao, Math.max(raio, CONTORNO_RAIO_FUSAO)).difference(fechado)).filter(livre)
  if (acrescimos.length) {
    // A união sai em pedaços que só se ENCOSTAM (arredondamento nas bordas
    // comuns); 5 cm de dilata-contrai os costura num polígono só. Sem isto a
    // simplificação adiante colapsava o bairro num triângulo.
    fechado = fechar(J.operation.union.UnaryUnionOp.union(fabrica.createGeometryCollection([fechado, ...acrescimos])), 0.05)
  }

  // 3: furo sem vizinho dentro vira bairro.
  fechado = J.operation.union.UnaryUnionOp.union(fabrica.createGeometryCollection(partesDe(fechado).filter(p => p.getGeometryType() === 'Polygon').map(p => {
    const furos = []
    for (let i = 0; i < p.getNumInteriorRing(); i++) {
      const anel = p.getInteriorRingN(i)
      if (!livre(fabrica.createPolygon(fabrica.createLinearRing(anel.getCoordinates()), []))) {
        furos.push(fabrica.createLinearRing(anel.getCoordinates()))
      }
    }
    return fabrica.createPolygon(fabrica.createLinearRing(p.getExteriorRing().getCoordinates()), furos)
  })))
  // Simplificação que preserva a topologia: Douglas-Peucker puro pode cruzar
  // anéis e entregar um polígono inválido.
  fechado = J.simplify.TopologyPreservingSimplifier.simplify(fechado, 0.5)

  // Pedaços: o maior é o bairro; pedaço pequeno e longe é lote isolado.
  const pedacos = []
  for (let i = 0; i < fechado.getNumGeometries(); i++) pedacos.push(fechado.getGeometryN(i))
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

  const geometry = _multiPoligonoLonLat(ficam.map(p => escritor.write(p).coordinates), plano)
  return { geometry, isolados, pedacos: ficam.length }
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

/** Aresta de lote a até isto (m) da borda da quadra está SOBRE a borda. */
const RUA_TOLERANCIA_BORDA = 1.5
/** Arestas paralelas (graus) e alinhadas (m) são o mesmo LADO da quadra. */
const RUA_ANGULO = 15
const RUA_ALINHAMENTO = 3
/** Lado mais curto que isto (m) é chanfro de esquina, não frente de rua. */
const RUA_LADO_MINIMO = 8
/** Da borda da quadra ao meio da rua (m): calçada e meia pista. */
const RUA_AFASTAMENTO = 7
/** Dois lados (um de cada quadra) até esta distância (m) são a mesma rua. */
const RUA_FUSAO = 12

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
 * RUA: o DWG não traz eixo de rua, então o nome sai dos LADOS das quadras.
 *   1. As arestas de lote que estão sobre a borda da quadra são as FRENTES;
 *      as alinhadas formam um lado.
 *   2. Cada lote vota, no lado em que encosta, no logradouro do seu endereço
 *      (cadastro). Lote que encosta num lado só vale 1; o de esquina, que
 *      tem um endereço só para dois lados, vale 0,25 — senão o nome da rua
 *      dele iria para o lado errado. Vence o mais votado com soma ≥ 1; sem
 *      isso o lado fica SEM NOME (null), para o curador informar.
 *   3. O trecho vai RUA_AFASTAMENTO para fora da quadra, que é o meio da rua.
 *   4. Os dois lados da rua (quadras frente a frente) viram um trecho só, e
 *      um lado sem nome herda o do lado de lá.
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

  const quadras = [], lados = []
  for (const [numero, ls] of grupos) {
    const f = ls[0].g.getFactory()
    let g = J.operation.union.UnaryUnionOp.union(f.createGeometryCollection(ls.map(l => l.g)))
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
    quadras.push({
      numero,
      geometry: _multiPoligonoLonLat(partes.map(p => escritor.write(p).coordinates), plano),
      rotulo: [Number(lat.toFixed(7)), Number(lon.toFixed(7))],
      lotes: ls.length,
    })
    lados.push(..._ladosDaQuadra(partes, ls, J, f))
  }

  const ruas = _fundirLados(lados).map(t => {
    const [lon1, lat1] = plano.de(t.a), [lon2, lat2] = plano.de(t.b)
    return { nome: t.nome, de: [Number(lat1.toFixed(7)), Number(lon1.toFixed(7))], ate: [Number(lat2.toFixed(7)), Number(lon2.toFixed(7))] }
  })
  return { quadras, ruas }
}

/**
 * Os lados de UMA quadra, já com o nome votado e deslocados para o meio da
 * rua. Em metros. @returns {{nome:?string, a:number[], b:number[], ux:number, uy:number}[]}
 */
function _ladosDaQuadra(partes, lotes, J, f) {
  const borda = f.createMultiLineString(partes.map(p => f.createLineString(p.getExteriorRing().getCoordinates())))
  const sobreABorda = (x, y) => borda.distance(f.createPoint(new J.geom.Coordinate(x, y))) <= RUA_TOLERANCIA_BORDA
  const paralelo = Math.cos(RUA_ANGULO * Math.PI / 180)

  // 1. Frentes: aresta com as duas pontas E o meio sobre a borda. O meio
  //    importa: num lote que ocupa a quadra de lado a lado, a aresta lateral
  //    liga uma borda à outra sem estar em nenhuma.
  const lados = []
  for (const l of lotes) {
    const cs = l.g.getExteriorRing().getCoordinates()
    for (let i = 0; i + 1 < cs.length; i++) {
      const a = cs[i], b = cs[i + 1]
      const len = Math.hypot(b.x - a.x, b.y - a.y)
      if (len < 1 || !sobreABorda(a.x, a.y) || !sobreABorda(b.x, b.y) || !sobreABorda((a.x + b.x) / 2, (a.y + b.y) / 2)) continue
      const ux = (b.x - a.x) / len, uy = (b.y - a.y) / len
      const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2
      let lado = lados.find(L => Math.abs(L.ux * ux + L.uy * uy) >= paralelo
        && Math.abs((mx - L.px) * L.uy - (my - L.py) * L.ux) <= RUA_ALINHAMENTO)
      if (!lado) { lado = { ux, uy, px: a.x, py: a.y, pontos: [], lotes: new Set() }; lados.push(lado) }
      lado.pontos.push(a, b)
      lado.lotes.add(l)
    }
  }

  // 2. Voto. Quantos lados cada lote toca decide o peso dele.
  const nLados = new Map()
  for (const L of lados) for (const l of L.lotes) nLados.set(l, (nLados.get(l) || 0) + 1)

  const saida = []
  for (const L of lados) {
    const votos = new Map()
    for (const l of L.lotes) {
      if (!l.logradouro) continue
      votos.set(l.logradouro, (votos.get(l.logradouro) || 0) + (nLados.get(l) === 1 ? 1 : 0.25))
    }
    let nome = null, maior = 0
    for (const [n, v] of votos) if (v > maior) { maior = v; nome = n }
    if (maior < 1) nome = null

    // 3. Extensão do lado e deslocamento para fora da quadra.
    let t0 = Infinity, t1 = -Infinity
    for (const p of L.pontos) { const t = (p.x - L.px) * L.ux + (p.y - L.py) * L.uy; t0 = Math.min(t0, t); t1 = Math.max(t1, t) }
    if (t1 - t0 < RUA_LADO_MINIMO) continue
    const mx = L.px + L.ux * (t0 + t1) / 2, my = L.py + L.uy * (t0 + t1) / 2
    let nx = -L.uy, ny = L.ux
    if (partes.some(p => p.contains(f.createPoint(new J.geom.Coordinate(mx + nx * 2, my + ny * 2))))) { nx = -nx; ny = -ny }
    const ox = nx * RUA_AFASTAMENTO, oy = ny * RUA_AFASTAMENTO
    saida.push({
      nome, ux: L.ux, uy: L.uy,
      a: [L.px + L.ux * t0 + ox, L.py + L.uy * t0 + oy],
      b: [L.px + L.ux * t1 + ox, L.py + L.uy * t1 + oy],
    })
  }
  return saida
}

/**
 * 4. Os dois lados da mesma rua — um de cada quadra, frente a frente — viram
 * um trecho só, sobre a reta média. Nomes diferentes não se fundem; um lado
 * sem nome herda o do lado de lá (é a mesma rua).
 */
function _fundirLados(lados) {
  const paralelo = Math.cos(RUA_ANGULO * Math.PI / 180)
  const fundidos = []
  for (const t of lados) {
    const tmx = (t.a[0] + t.b[0]) / 2, tmy = (t.a[1] + t.b[1]) / 2
    const par = fundidos.find(o => {
      if (o.nome && t.nome && o.nome !== t.nome) return false
      if (Math.abs(o.ux * t.ux + o.uy * t.uy) < paralelo) return false
      if (Math.abs((tmx - o.a[0]) * o.uy - (tmy - o.a[1]) * o.ux) > RUA_FUSAO) return false
      // Sobreposição ao longo da rua: os dois lados de UM quarteirão, não o seguinte.
      const len = Math.hypot(o.b[0] - o.a[0], o.b[1] - o.a[1])
      const p1 = (t.a[0] - o.a[0]) * o.ux + (t.a[1] - o.a[1]) * o.uy
      const p2 = (t.b[0] - o.a[0]) * o.ux + (t.b[1] - o.a[1]) * o.uy
      const sentido = Math.sign((o.b[0] - o.a[0]) * o.ux + (o.b[1] - o.a[1]) * o.uy) || 1
      const [i0, i1] = sentido > 0 ? [0, len] : [-len, 0]
      return Math.min(i1, Math.max(p1, p2)) - Math.max(i0, Math.min(p1, p2)) > 0.3 * Math.min(len, Math.hypot(t.b[0] - t.a[0], t.b[1] - t.a[1]))
    })
    if (!par) { fundidos.push({ ...t }); continue }

    // Reta média: o lado de cá desloca metade da distância até o de lá.
    const d = (tmx - par.a[0]) * par.uy - (tmy - par.a[1]) * par.ux   // distância com sinal, normal (uy, -ux)
    const nx = par.uy * d / 2, ny = -par.ux * d / 2
    const ts = [par.a, par.b, t.a, t.b].map(p => (p[0] - par.a[0]) * par.ux + (p[1] - par.a[1]) * par.uy)
    const t0 = Math.min(...ts), t1 = Math.max(...ts)
    par.a = [par.a[0] + par.ux * t0 + nx, par.a[1] + par.uy * t0 + ny]
    par.b = [par.a[0] - par.ux * t0 + par.ux * t1, par.a[1] - par.uy * t0 + par.uy * t1]
    par.nome = par.nome ?? t.nome
  }
  return fundidos
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

/** "Contorno dos bairros" no painel de correção cadastral. */
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
    : p.desatualizado ? '<span class="badge bd-pe">desatualizado</span>' : '<span class="badge bd-ok">em dia</span>'

  _impCorpo(`
    <p class="imp-expl">O contorno é a união dos lotes do bairro com as ruas entre as quadras preenchidas
      (fechamento de <b>raio</b> metros). Ele fica tracejado no mapa e mostra o nome do bairro com o mapa afastado.
      Junto, sai o contorno de cada <b>quadra</b>, que o mapa mostra na escala do bairro no lugar das linhas de lote.
      Fica <b>desatualizado</b> quando a curadoria mexe nos lotes depois de gerado.</p>
    <div class="field" style="max-width:220px"><label for="ctn-raio">Raio do fechamento (m)</label>
      <input type="number" id="ctn-raio" min="5" max="200" step="1" value="${raioEscolhido}"></div>
    <table class="imp-tabela"><thead><tr><th>Bairro</th><th>Situação</th><th class="num">Área</th><th></th></tr></thead><tbody>
    ${linhas.map(l => `<tr>
      <td>${esc(l.oficial)}${l.oficial !== l.nome ? `<div class="imp-sub">${esc(l.nome)}</div>` : ''}
        ${l.p?.isolados?.length ? `<div class="imp-sub imp-diverge">${l.p.isolados.length} lote(s) isolado(s) fora do contorno:
          ${l.p.isolados.map(id => `<a href="#" onclick="event.preventDefault(); irAoLoteIsolado(${Number(id)})">nº ${Number(id)}</a>`).join(', ')}</div>` : ''}
        ${contornoState.fora[l.nome]?.length ? `<div class="imp-sub imp-diverge">Última tentativa recusada — ${contornoState.fora[l.nome].length} lote(s) fora do contorno (clique para ver no mapa):
          ${contornoState.fora[l.nome].map(x => `<a href="#" onclick="event.preventDefault(); irAoLoteIsolado(${Number(x.id)})">Q ${esc(x.quadra ?? '—')} · L ${esc(x.lote ?? '—')}</a>`).join(', ')}</div>` : ''}</td>
      <td>${situacao(l.p)}${l.p?.contorno_em ? `<div class="imp-sub">${esc(l.p.contorno_em)} · raio ${l.p.raio_m} m
        · ${l.p.quadras ? l.p.quadras + ' quadra(s)' : 'sem quadras — gere de novo'}</div>` : ''}</td>
      <td class="num">${l.p ? l.p.area_ha.toLocaleString('pt-BR') + ' ha' : '—'}</td>
      <td class="imp-abrir"><button class="btn sm" onclick="gerarDaLista(this, ${jsArg(l.nome)})">${l.p ? 'Gerar de novo' : 'Gerar'}</button></td>
    </tr>`).join('') || '<tr><td colspan="4">Nenhum bairro com lotes.</td></tr>'}
    </tbody></table>
    <div class="btn-row"><button class="btn" onclick="fecharImportacoes()">Fechar</button>
      ${linhas.length > 1 ? `<button class="btn primary" onclick="gerarTodosOsContornos(this)">Gerar todos (${linhas.length})</button>` : ''}</div>`,
    'Contorno dos bairros')
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
 * Gera de novo o contorno e as quadras de TODOS os bairros, um por vez. É o
 * caminho para os contornos feitos antes das quadras existirem, e para os
 * bairros que nunca tiveram contorno.
 * @param {HTMLButtonElement} btn
 */
function gerarTodosOsContornos(btn) {
  const d = contornoState.dados || { features: [], sem_contorno: [] }
  const nomes = [...d.features.map(f => f.properties.nome), ...(d.sem_contorno || [])]
  if (!nomes.length) return
  confirmarAcao({
    titulo: 'Gerar todos os contornos',
    mensagem: `Calcula de novo o contorno e as quadras dos ${nomes.length} bairros, um por vez. `
      + 'Pode levar alguns minutos; deixe esta janela aberta até terminar.',
    textoBtn: 'Gerar todos',
    // Sem await: a confirmação fecha na hora, e o andamento aparece no botão.
    onConfirm: () => { _gerarEmSequencia(btn, nomes) },
  })
}

/** @param {HTMLButtonElement} btn @param {string[]} nomes */
async function _gerarEmSequencia(btn, nomes) {
  const raio = Number(document.getElementById('ctn-raio')?.value) || CONTORNO_RAIO_PADRAO
  btn.disabled = true
  const falhas = []
  for (const [i, nome] of nomes.entries()) {
    btn.textContent = `Gerando ${i + 1} de ${nomes.length}…`
    if (!await gerarContornoDoBairro(nome, raio, { silencioso: true })) falhas.push(nome)
  }
  toast(falhas.length
    ? `${nomes.length - falhas.length} bairro(s) gerado(s). ${falhas.length} falharam — use "Gerar" na linha para ver o motivo: ${falhas.join(', ')}.`
    : `${nomes.length} bairros gerados, com as quadras.`, falhas.length ? 'aviso' : 'ok')
  // Só refaz a lista se ela ainda estiver aberta.
  if (document.getElementById('m-importacoes')?.classList.contains('open') && document.getElementById('ctn-raio')) {
    await abrirContornosDosBairros()
  }
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
