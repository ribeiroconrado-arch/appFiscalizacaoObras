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
}

/**
 * Contorno e número das quadras, no nível das quadras (nivelDoMapa). Cada
 * bairro é pedido UMA vez, quando entra na tela, e fica guardado: arrastar
 * pela cidade não repete pedido. Chamada a cada moveend (mapa.js).
 */
async function carregarQuadrasVisiveis() {
  const mapa = mapaState.obj
  if (!mapa || !contornoState.camada || typeof nivelDoMapa !== 'function') return
  if (nivelDoMapa(mapa) !== 'quadras') { _desenharRotulosDeQuadra(); return }

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
    quadraState.porBairro.set(nome, { feicoes: gj.features, camada })
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
    // As quadras saem dos MESMOS lotes, no mesmo envio: bairro e quadras
    // gravados juntos nunca ficam de idades diferentes.
    const quadras = calcularQuadras(dl.lotes)

    const rg = await fetch('/api/bairros/contorno', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ bairro, geometry: calc.geometry, raio_m: raio, lotes_contados: dl.lotes.length,
        isolados: calc.isolados, quadras }),
    })
    const dg = await rg.json()
    if (!rg.ok) throw new Error(dg.message || 'O servidor recusou o contorno.')

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
 *
 * União dos lotes da quadra e um fechamento de QUADRA_RAIO, que costura as
 * frestas entre lotes vizinhos. Os furos saem: o que interessa no mapa é a
 * borda da quadra, e um furo é quase sempre uma fresta do desenho. Quadra
 * com o mesmo número em pedaços separados (cortada por rua) fica em pedaços;
 * o número vai no ponto INTERNO do maior — o centro geométrico de uma quadra
 * em L cairia na rua. Lote sem quadra não entra.
 */
function calcularQuadras(lotes) {
  const J = window.jsts
  const comQuadra = lotes.filter(l => String(l.quadra ?? '').trim() !== '')
  if (!comQuadra.length) return []
  const plano = PranchetaGeo.plano(comQuadra[0].geometry.coordinates[0][0])
  const leitor = new J.io.GeoJSONReader(), escritor = new J.io.GeoJSONWriter()
  const P = J.operation.buffer.BufferParameters
  const par = new P(); par.setJoinStyle(P.JOIN_MITRE); par.setMitreLimit(3)
  const buf = (g, d) => J.operation.buffer.BufferOp.bufferOp(g, d, par)

  const grupos = new Map()
  for (const l of comQuadra) {
    const numero = String(l.quadra).trim()
    if (!grupos.has(numero)) grupos.set(numero, [])
    grupos.get(numero).push(leitor.read({ type: 'Polygon',
      coordinates: l.geometry.coordinates.map(anel => anel.map(c => plano.para(c))) }))
  }

  const quadras = []
  for (const [numero, gs] of grupos) {
    const f = gs[0].getFactory()
    let g = J.operation.union.UnaryUnionOp.union(f.createGeometryCollection(gs))
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
      lotes: gs.length,
    })
  }
  return quadras
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
      <input type="number" id="ctn-raio" min="5" max="200" step="1" value="${CONTORNO_RAIO_PADRAO}"></div>
    <table class="imp-tabela"><thead><tr><th>Bairro</th><th>Situação</th><th class="num">Área</th><th></th></tr></thead><tbody>
    ${linhas.map(l => `<tr>
      <td>${esc(l.oficial)}${l.oficial !== l.nome ? `<div class="imp-sub">${esc(l.nome)}</div>` : ''}
        ${l.p?.isolados?.length ? `<div class="imp-sub imp-diverge">${l.p.isolados.length} lote(s) isolado(s) fora do contorno:
          ${l.p.isolados.map(id => `<a href="#" onclick="event.preventDefault(); irAoLoteIsolado(${Number(id)})">nº ${Number(id)}</a>`).join(', ')}</div>` : ''}</td>
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
  if (r) await abrirContornosDosBairros()
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
