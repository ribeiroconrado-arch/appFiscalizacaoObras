/**
 * Contorno de cada bairro: a linha tracejada que o mapa mostra sempre, e que
 * é o desenho principal com o mapa afastado (onde os lotes nem carregam).
 *
 * DESENHO (todos): lê /api/mapa/bairros uma vez e pinta.
 * CÁLCULO (curador/admin): une os lotes do bairro e faz um FECHAMENTO de R
 * metros — dilata e contrai —, que cobre as ruas entre as quadras e mantém a
 * borda externa do loteamento. Feito aqui com JSTS (o mesmo motor do GEOS do
 * QGIS) porque o ST_Buffer negativo do MySQL corrompe multipolígono grande.
 * O servidor só confere e grava (BairroContornoController).
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

    const rg = await fetch('/api/bairros/contorno', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ bairro, geometry: calc.geometry, raio_m: raio, lotes_contados: dl.lotes.length, isolados: calc.isolados }),
    })
    const dg = await rg.json()
    if (!rg.ok) throw new Error(dg.message || 'O servidor recusou o contorno.')

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

  const poligonos = ficam.map(p => escritor.write(p).coordinates)

  // SENTIDO DOS ANÉIS, no padrão do GeoJSON (RFC 7946): externo anti-horário,
  // furos horários. Em coordenada GEOGRÁFICA o MySQL lê anel externo horário
  // como "o resto do planeta" — o contorno passava a deixar o bairro de fora.
  const area2 = anel => anel.reduce((s, p, i) => { const q = anel[(i + 1) % anel.length]; return s + p[0] * q[1] - q[0] * p[1] }, 0)
  const orientar = (anel, externo) => ((area2(anel) > 0) === externo ? anel : [...anel].reverse())

  const geometry = {
    type: 'MultiPolygon',
    coordinates: poligonos.map(aneis => aneis.map((anel, i) =>
      orientar(anel.map(c => plano.de(c).map(n => Number(n.toFixed(8)))), i === 0))),
  }
  return { geometry, isolados, pedacos: ficam.length }
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
      Fica <b>desatualizado</b> quando a curadoria mexe nos lotes depois de gerado.</p>
    <div class="field" style="max-width:220px"><label for="ctn-raio">Raio do fechamento (m)</label>
      <input type="number" id="ctn-raio" min="5" max="200" step="1" value="${CONTORNO_RAIO_PADRAO}"></div>
    <table class="imp-tabela"><thead><tr><th>Bairro</th><th>Situação</th><th class="num">Área</th><th></th></tr></thead><tbody>
    ${linhas.map(l => `<tr>
      <td>${esc(l.oficial)}${l.oficial !== l.nome ? `<div class="imp-sub">${esc(l.nome)}</div>` : ''}
        ${l.p?.isolados?.length ? `<div class="imp-sub imp-diverge">${l.p.isolados.length} lote(s) isolado(s) fora do contorno:
          ${l.p.isolados.map(id => `<a href="#" onclick="event.preventDefault(); irAoLoteIsolado(${Number(id)})">nº ${Number(id)}</a>`).join(', ')}</div>` : ''}</td>
      <td>${situacao(l.p)}${l.p?.contorno_em ? `<div class="imp-sub">${esc(l.p.contorno_em)} · raio ${l.p.raio_m} m</div>` : ''}</td>
      <td class="num">${l.p ? l.p.area_ha.toLocaleString('pt-BR') + ' ha' : '—'}</td>
      <td class="imp-abrir"><button class="btn sm" onclick="gerarDaLista(this, ${jsArg(l.nome)})">${l.p ? 'Gerar de novo' : 'Gerar'}</button></td>
    </tr>`).join('') || '<tr><td colspan="4">Nenhum bairro com lotes.</td></tr>'}
    </tbody></table>
    <div class="btn-row"><button class="btn" onclick="fecharImportacoes()">Fechar</button></div>`, 'Contorno dos bairros')
}

async function gerarDaLista(btn, bairro) {
  const raio = Number(document.getElementById('ctn-raio')?.value) || CONTORNO_RAIO_PADRAO
  btn.disabled = true; btn.textContent = 'Calculando…'
  const r = await gerarContornoDoBairro(bairro, raio)
  if (r) await abrirContornosDosBairros()
  else { btn.disabled = false; btn.textContent = 'Gerar' }
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
