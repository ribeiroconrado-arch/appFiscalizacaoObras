// ══════════════════════════════════════════════
// DESENHO DE POLÍGONO SOBRE O MAPA
//
// Clique a clique, sem biblioteca. As duas alternativas foram avaliadas e
// descartadas: o Leaflet.draw está sem manutenção desde 2019 e briga com o
// Leaflet 1.9; o Leaflet-Geoman seria a terceira dependência de runtime num
// front sem empacotador — 70 KB que o fiscal em 4G ruim paga antes de ver o
// mapa — e mesmo assim não entrega as duas peças difíceis daqui: o encaixe em
// vértice com a COORDENADA EXATA do vizinho, e o corte que devolve dois
// polígonos compartilhando a divisa vértice a vértice.
// ══════════════════════════════════════════════

const desenhoState = {
  ativo: false,
  /** @type {'poligono'|'linha'|null} */ modo: null,
  /** @type {Array<[number,number]>} vértices em [lon, lat], ordem do GeoJSON */ vertices: [],
  /** @type {L.Polyline|null} */  rascunho: null,
  /** @type {L.Polygon|null} */   previa: null,
  /** @type {L.Polyline|null} */  elastico: null,
  /** @type {L.Rectangle|null} */ captura: null,
  /** @type {L.CircleMarker[]} */ marcadores: [],
  snap: true,
  /** Travar cada lado em múltiplo de 45° do lado anterior. Shift solta. */
  travaAngulo: true,
  /** Shift pressionado agora: solta a trava sem desligá-la. */
  shiftSolto: false,
  /** Plano local em metros, fixado no primeiro vértice — ver `_plano`. */
  plano: null,
  /** @type {L.Marker[]} rótulos de medida dos lados */ rotulos: [],
  /** @type {L.Marker|null} */ rotuloArea: null,
  /** @type {L.Marker|null} medida do lado em traçado */ rotuloElastico: null,
  onConcluir: null,
  onCancelar: null,
  /** Instante e ponto do último clique — a guarda contra o duplo clique. */
  ultimoClique: { t: 0, x: 0, y: 0 },
}

/** Casas decimais na saída. 7 ≈ 1 cm em EPSG:4326. */
const DESENHO_CASAS = 7

/** Raio de encaixe em vértice vizinho, em pixels de tela. */
const DESENHO_SNAP_PX = 12

/** Um clique a menos disto do anterior, em ms e px, é a metade de um duplo clique. */
const DESENHO_DUPLO_MS = 320
const DESENHO_DUPLO_PX = 8

function estaDesenhando() {
  return desenhoState.ativo
}

// ── ABERTURA E FECHAMENTO ────────────────────────────────────

/**
 * @param {{modo?:'poligono'|'linha', snap?:boolean,
 *          onConcluir:(g:Object)=>void, onCancelar?:()=>void}} opcoes
 */
function iniciarDesenho(opcoes) {
  const mapa = mapaState.obj
  if (!mapa) { toast('Abra o mapa antes de desenhar.', 'aviso'); return }

  cancelarDesenho()

  Object.assign(desenhoState, {
    ativo: true,
    modo: opcoes.modo || 'poligono',
    snap: opcoes.snap !== false,
    vertices: [],
    // O plano nasce nulo e é fixado no primeiro vértice — ver `_plano`.
    plano: null,
    // A trava vem LIGADA no polígono e desligada na linha: lote de loteamento
    // é retangular, e a linha de corte do desmembramento quase nunca é
    // perpendicular a coisa nenhuma. `travaAngulo` na chamada manda em ambos.
    travaAngulo: opcoes.travaAngulo ?? (opcoes.modo !== 'linha'),
    shiftSolto: false,
    onConcluir: opcoes.onConcluir,
    onCancelar: opcoes.onCancelar || null,
    ultimoClique: { t: 0, x: 0, y: 0 },
  })
  _pintarTrava()

  // O duplo clique fecharia o desenho E aproximaria o mapa. Desligar aqui e
  // religar no fim é mais previsível do que tentar cancelar o zoom depois.
  mapa.doubleClickZoom.disable()

  // ── a folha de captura ──
  //
  // Sem ela o clique cairia no polígono do lote (mapa.js) e abriria o balão.
  // A alternativa seria desligar `interactive` em até 3.000 camadas e ter de
  // religar exatamente as mesmas depois — um retângulo transparente por cima
  // custa uma camada e não deixa estado para trás.
  if (!mapa.getPane('desenho')) {
    mapa.createPane('desenho').style.zIndex = 650
  }
  // O painel volta a RECEBER cliques enquanto se desenha. Ele fica surdo o
  // resto do tempo — ver _limpar() e o porque disso.
  mapa.getPane('desenho').style.pointerEvents = ''
  desenhoState.captura = L.rectangle(
    [[-90, -180], [90, 180]],
    { pane: 'desenho', interactive: true, stroke: false, fillOpacity: 0, className: 'desenho-captura' }
  ).addTo(mapa)

  desenhoState.captura.on('click', _aoClicar)
  desenhoState.captura.on('dblclick', _aoDuploClique)
  mapa.on('mousemove', _aoMover)

  document.getElementById('map').classList.add('desenhando')
  toast('Toque nos cantos do lote. Duplo toque fecha.', 'aviso')
}

function cancelarDesenho() {
  if (!desenhoState.ativo && !desenhoState.captura) { return }
  const cb = desenhoState.onCancelar
  _limpar()
  if (cb) cb()
}

/** Desfaz o último vértice. Ctrl+Z e botão. */
function desfazerVertice() {
  if (!desenhoState.ativo || !desenhoState.vertices.length) { return }
  desenhoState.vertices.pop()
  _pintar()
}

/**
 * Fecha o desenho e devolve a geometria a quem pediu.
 *
 * O polígono é fechado AQUI, repetindo o primeiro vértice no fim, porque é o
 * que a RFC 7946 exige e o que `ST_GeomFromGeoJSON` espera. Deixar isso para o
 * servidor faria a mesma regra viver em dois lugares.
 */
function concluirDesenho() {
  if (!desenhoState.ativo) { return }

  const minimo = desenhoState.modo === 'linha' ? 2 : 3
  if (desenhoState.vertices.length < minimo) {
    toast(desenhoState.modo === 'linha'
      ? 'A linha precisa de ao menos dois pontos.'
      : 'O lote precisa de ao menos três cantos.', 'err')
    return
  }

  const g = geometriaDoDesenho()
  const cb = desenhoState.onConcluir
  _limpar()
  if (cb) cb(g)
}

/** @returns {Object|null} GeoJSON Polygon ou LineString, em (lon, lat) */
function geometriaDoDesenho() {
  const v = desenhoState.vertices.map(c => [_arredondar(c[0]), _arredondar(c[1])])
  if (!v.length) { return null }

  if (desenhoState.modo === 'linha') {
    return { type: 'LineString', coordinates: v }
  }
  return { type: 'Polygon', coordinates: [[...v, v[0]]] }
}

// ── EVENTOS ──────────────────────────────────────────────────

/** @param {L.LeafletMouseEvent} ev */
function _aoClicar(ev) {
  L.DomEvent.stop(ev)

  // Metade de um duplo clique não vira vértice.
  //
  // O Leaflet dispara os DOIS `click` antes do `dblclick`, então fechar por
  // duplo toque acrescentaria dois vértices em cima do último — e o polígono
  // sairia com dois cantos coincidentes, que o MySQL recusa como inválido.
  const p = mapaState.obj.latLngToContainerPoint(ev.latlng)
  const agora = Date.now()
  const anterior = desenhoState.ultimoClique
  const perto = Math.abs(p.x - anterior.x) < DESENHO_DUPLO_PX
             && Math.abs(p.y - anterior.y) < DESENHO_DUPLO_PX

  if (agora - anterior.t < DESENHO_DUPLO_MS && perto) {
    return
  }
  desenhoState.ultimoClique = { t: agora, x: p.x, y: p.y }

  // A ORDEM IMPORTA: o encaixe no vizinho vence a trava de ângulo.
  //
  // Os dois querem mover o mesmo ponto. Se a trava viesse depois, ela empurraria
  // o canto para fora do vértice do vizinho e devolveria a fresta que o encaixe
  // existe para eliminar — uma divisa com 4 cm de sobra vale menos do que um
  // ângulo com 0,3° de erro. Havendo vizinho no raio, ele manda.
  const encaixado = desenhoState.snap ? _encaixar(ev.latlng) : ev.latlng
  const ll = encaixado === ev.latlng ? _travar(ev.latlng) : encaixado

  desenhoState.vertices.push([ll.lng, ll.lat])
  _pintar()
}

function _aoDuploClique(ev) {
  L.DomEvent.stop(ev)
  concluirDesenho()
}

/** @param {L.LeafletMouseEvent} ev */
function _aoMover(ev) {
  if (!desenhoState.ativo || !desenhoState.vertices.length) { return }

  const ultimo = desenhoState.vertices[desenhoState.vertices.length - 1]
  const encaixado = desenhoState.snap ? _encaixar(ev.latlng) : ev.latlng
  const destino = encaixado === ev.latlng ? _travar(ev.latlng) : encaixado
  const traco = [[ultimo[1], ultimo[0]], [destino.lat, destino.lng]]

  if (desenhoState.elastico) {
    desenhoState.elastico.setLatLngs(traco)
  } else {
    desenhoState.elastico = L.polyline(traco, {
      pane: 'desenho', color: COR_DESENHO, weight: 2, dashArray: '5,6', opacity: .8, interactive: false,
    }).addTo(mapaState.obj)
  }

  // A MEDIDA ACOMPANHA O CURSOR.
  //
  // É o que torna a trava utilizável: o ângulo já sai certo, e o número
  // dizendo quantos metros o lado tem enquanto ele cresce é o que permite
  // parar em 12,00 em vez de parar onde parecia certo.
  const meio = [(ultimo[1] + destino.lat) / 2, (ultimo[0] + destino.lng) / 2]
  const texto = fmtMedida(distanciaNoPlano(ultimo, [destino.lng, destino.lat]))
  if (desenhoState.rotuloElastico) {
    desenhoState.rotuloElastico.setLatLng(meio)
    const alvo = desenhoState.rotuloElastico.getElement()?.querySelector('.des-rot')
    if (alvo) { alvo.textContent = texto }
  } else {
    desenhoState.rotuloElastico = _rotulo(meio[0], meio[1], texto, 'des-medida des-medida-viva')
  }
}

// ── O PLANO LOCAL EM METROS ──────────────────────────────────
//
// Ângulo reto e medida de lado só fazem sentido num plano projetado. Em graus,
// um lado "de 90°" na tela sai torto no terreno, porque um grau de longitude
// não vale os mesmos metros que um grau de latitude — em Primavera do Leste, a
// 15°S, vale 3,5% menos. Errar isso não dá erro nenhum: dá lote torto, que só
// aparece na conferência da matrícula.
//
// É a mesma projeção do servidor (App\Support\GeometriaPlana::projetar), com o
// raio meridional e o raio da grande normal do WGS84 — e não um raio médio de
// esfera, que introduz viés sistemático de −0,25% em toda medida.

const GEO_A = 6378137.0
const GEO_E2 = 0.00669437999014

/** @param {number} latRef @param {number} lonRef */
function planoLocal(latRef, lonRef) {
  const sen = Math.sin(latRef * Math.PI / 180)
  const w = 1 - GEO_E2 * sen * sen
  const m = GEO_A * (1 - GEO_E2) / (w * Math.sqrt(w))
  const n = GEO_A / Math.sqrt(w)
  return {
    latRef, lonRef,
    porGrauLat: m * Math.PI / 180,
    porGrauLon: n * Math.PI / 180 * Math.cos(latRef * Math.PI / 180),
  }
}

/**
 * O plano de trabalho, fixado no PRIMEIRO vértice.
 *
 * Não é recalculado a cada ponto de propósito: um plano que se move faria o
 * mesmo lado medir coisas diferentes conforme a ordem em que foi desenhado. Num
 * lote de 50 m a diferença é de milímetros; a incoerência não é.
 */
function _plano() {
  if (!desenhoState.plano) {
    const v = desenhoState.vertices[0]
    const c = mapaState.obj.getCenter()
    desenhoState.plano = v ? planoLocal(v[1], v[0]) : planoLocal(c.lat, c.lng)
  }
  return desenhoState.plano
}

/** @param {number} lon @param {number} lat @returns {[number,number]} metros */
function aoPlano(lon, lat) {
  const p = _plano()
  return [(lon - p.lonRef) * p.porGrauLon, (lat - p.latRef) * p.porGrauLat]
}

/** @param {number} x @param {number} y @returns {[number,number]} [lon, lat] */
function doPlano(x, y) {
  const p = _plano()
  return [p.lonRef + x / p.porGrauLon, p.latRef + y / p.porGrauLat]
}

// ── TRAVA DE ÂNGULO ──────────────────────────────────────────

/**
 * Prende o próximo canto num múltiplo de 45° em relação ao lado anterior.
 *
 * Quase todo lote de loteamento é retangular, e acertar 90° com o dedo é
 * impossível: sai 89,4°, e a divisa do fundo fica trinta centímetros fora do
 * lugar. Com a trava o ângulo sai exato, e a medida continua sendo a que o
 * operador escolhe.
 *
 * GIRA em vez de PROJETAR: o canto acompanha a distância do cursor e só o
 * ângulo é corrigido. Projetando, afastar o cursor para o lado ENCURTARIA o
 * lado — o ponto recuaria enquanto a mão avança, que é o contrário do que se
 * espera de um traço.
 *
 * Sem lado anterior (segundo vértice), a referência é o leste: os múltiplos de
 * 45° passam a ser os rumos cardeais, que é o que serve para começar.
 *
 * @param {L.LatLng} alvo @returns {L.LatLng}
 */
function _travar(alvo) {
  const v = desenhoState.vertices
  if (!desenhoState.travaAngulo || desenhoState.shiftSolto || !v.length) { return alvo }

  const a = aoPlano(v[v.length - 1][0], v[v.length - 1][1])
  const c = aoPlano(alvo.lng, alvo.lat)
  const dx = c[0] - a[0]
  const dy = c[1] - a[1]
  const dist = Math.hypot(dx, dy)
  if (dist < 0.05) { return alvo }   // 5 cm: ainda não há direção nenhuma

  let base = 0
  if (v.length >= 2) {
    const ant = aoPlano(v[v.length - 2][0], v[v.length - 2][1])
    base = Math.atan2(a[1] - ant[1], a[0] - ant[0])
  }

  const passo = Math.PI / 4
  const travado = base + Math.round((Math.atan2(dy, dx) - base) / passo) * passo
  const [lon, lat] = doPlano(a[0] + Math.cos(travado) * dist, a[1] + Math.sin(travado) * dist)
  return L.latLng(lat, lon)
}

/** Liga e desliga a trava pelo botão. */
function alternarTravaAngulo() {
  desenhoState.travaAngulo = !desenhoState.travaAngulo
  _pintarTrava()
  toast(desenhoState.travaAngulo
    ? 'Ângulo travado em 90°/45°. Segure Shift para soltar num canto.'
    : 'Ângulo livre.', 'aviso')
}

function _pintarTrava() {
  const b = document.getElementById('des-trava')
  if (!b) { return }
  b.classList.toggle('at', desenhoState.travaAngulo)
  b.setAttribute('aria-pressed', String(desenhoState.travaAngulo))
}

// ── LADO POR MEDIDA DIGITADA ─────────────────────────────────

/**
 * Crava o próximo canto a uma distância exata, na direção pedida.
 *
 * É por aqui que a matrícula entra no desenho: "frente 12,00 m, lado direito
 * 30,00 m" vira duas linhas digitadas, e o polígono sai com a medida do
 * registro — não com a que o dedo alcançou.
 *
 * @param {number} metros
 * @param {'reta'|'esq'|'dir'|'azimute'} direcao
 * @param {number} [azimute] graus, 0 = norte, sentido horário
 * @returns {boolean} se o canto entrou
 */
function cravarLado(metros, direcao, azimute) {
  if (!desenhoState.ativo) { toast('Comece o desenho antes.', 'err'); return false }
  if (!(metros > 0)) { toast('Informe a medida do lado, em metros.', 'err'); return false }

  const v = desenhoState.vertices
  if (!v.length) {
    toast('Toque no primeiro canto; a partir dele a medida tem de onde sair.', 'err')
    return false
  }

  const a = aoPlano(v[v.length - 1][0], v[v.length - 1][1])

  // A direção do último lado. Com um único canto marcado não existe "seguir
  // reto" nem "virar à esquerda" — só o azimute diz para onde ir, e é o que se
  // exige em vez de escolher um rumo por conta.
  let base = null
  if (v.length >= 2) {
    const ant = aoPlano(v[v.length - 2][0], v[v.length - 2][1])
    base = Math.atan2(a[1] - ant[1], a[0] - ant[0])
  }

  let ang
  if (direcao === 'azimute' || base === null) {
    if (!Number.isFinite(azimute)) {
      toast('No primeiro lado não há direção anterior: informe o azimute.', 'err')
      return false
    }
    // Azimute é geográfico (0 = norte, horário); o plano mede do leste, no
    // sentido anti-horário. Trocar os dois é o erro clássico daqui.
    ang = (90 - azimute) * Math.PI / 180
  } else if (direcao === 'reta') {
    ang = base
  } else {
    ang = base + (direcao === 'esq' ? Math.PI / 2 : -Math.PI / 2)
  }

  const [lon, lat] = doPlano(a[0] + Math.cos(ang) * metros, a[1] + Math.sin(ang) * metros)
  desenhoState.vertices.push([lon, lat])
  _pintar()
  return true
}

// ── MEDIDAS NA TELA ──────────────────────────────────────────

/**
 * Distância entre dois cantos, NO PLANO DO DESENHO.
 *
 * Não usa `distanciaM` (geo.js) de propósito, e isto foi medido: o haversine
 * de lá trabalha com raio médio de ESFERA, enquanto o desenho vive no
 * elipsoide WGS84. Um lado cravado com exatos 25,00 m aparecia rotulado como
 * "25,12 m" — o operador digitava a medida da matrícula e a tela devolvia
 * outra, que é o pior defeito possível numa ferramenta de medir.
 *
 * `distanciaM` continua certo para o que ele faz (a que distância o fiscal
 * está do lote). O que não se pode é medir o mesmo lote com duas réguas.
 *
 * @param {[number,number]} a @param {[number,number]} b em [lon, lat]
 */
function distanciaNoPlano(a, b) {
  const pa = aoPlano(a[0], a[1])
  const pb = aoPlano(b[0], b[1])
  return Math.hypot(pb[0] - pa[0], pb[1] - pa[1])
}

/** @param {number} m @returns {string} */
function fmtMedida(m) {
  return m.toFixed(2).replace('.', ',') + ' m'
}

/** Área do anel em desenho, em m². Fórmula do cadarço sobre o plano local. */
function areaDoDesenho() {
  const v = desenhoState.vertices
  if (v.length < 3) { return 0 }
  const p = v.map(c => aoPlano(c[0], c[1]))
  let s = 0
  for (let i = 0, n = p.length; i < n; i++) {
    const j = (i + 1) % n
    s += p[i][0] * p[j][1] - p[j][0] * p[i][1]
  }
  return Math.abs(s) / 2
}

/**
 * @param {number} lat @param {number} lon @param {string} texto @param {string} css
 * @param {[number,number]} [fora] deslocamento em pixels de tela
 */
function _rotulo(lat, lon, texto, css, fora) {
  // ANCORADO PELA BORDA, não pelo centro.
  //
  // Deslocar o centro do rótulo alguns pixels para fora não bastava: metade da
  // etiqueta continuava entrando no lote, e num lote de 12 m de frente os dois
  // rótulos de lado invadiam o da área no meio. Empurrando 50% da própria
  // largura MAIS uma folga, o que fica a 8px da divisa é a borda da etiqueta —
  // e aí ela está inteira do lado de fora, seja qual for o texto.
  const estilo = fora
    ? ` style="transform:translate(-50%,-50%) translate(${_empurrar(fora[0])},${_empurrar(fora[1])})"`
    : ''
  return L.marker([lat, lon], {
    pane: 'desenho', interactive: false, keyboard: false,
    icon: L.divIcon({
      className: css, iconSize: null,
      html: `<span class="des-rot"${estilo}>${texto}</span>`,
    }),
  }).addTo(mapaState.obj)
}

/**
 * Empurra o rótulo do lado para FORA do polígono, em pixels de tela.
 *
 * Num lote de 12 m de frente, os rótulos dos dois lados de 25 m e o da área
 * caíam todos na mesma faixa e saíam sobrepostos — "25,00 300,00 m²5,00 m",
 * ilegível justamente no formato de lote mais comum do município.
 *
 * O deslocamento é em PIXELS e não em metros: em metros, ele encolheria junto
 * com o lote ao afastar o zoom e as etiquetas voltariam a se encontrar. A
 * direção na tela não muda com o zoom (o mapa não gira), então basta calculá-la
 * uma vez, ao pintar.
 *
 * @param {[number,number]} a @param {[number,number]} b extremos do lado
 * @param {[number,number]} centro centroide do polígono, em [lon, lat]
 * @returns {[number,number]} deslocamento em px
 */
function _foraDoLado(a, b, centro) {
  const pa = aoPlano(a[0], a[1])
  const pb = aoPlano(b[0], b[1])
  const pc = aoPlano(centro[0], centro[1])

  // Normal ao lado, no plano.
  let nx = -(pb[1] - pa[1])
  let ny = pb[0] - pa[0]
  const n = Math.hypot(nx, ny) || 1
  nx /= n; ny /= n

  // Apontando para longe do miolo.
  const mx = (pa[0] + pb[0]) / 2 - pc[0]
  const my = (pa[1] + pb[1]) / 2 - pc[1]
  if (nx * mx + ny * my < 0) { nx = -nx; ny = -ny }

  // Na tela o eixo Y cresce para BAIXO, ao contrário do plano.
  return [+nx.toFixed(4), +(-ny).toFixed(4)]
}

/**
 * Uma componente do empurrão, em CSS.
 *
 * `50%` aqui é metade da LARGURA (ou altura) do próprio rótulo — é o que faz a
 * etiqueta sair inteira do polígono em vez de ficar meio dentro. Componente
 * nula devolve zero: `calc(0% + 0px)` funcionaria, mas escrever zero é mais
 * fácil de ler no inspetor quando algo sair do lugar.
 *
 * @param {number} c componente do vetor normal, entre −1 e 1
 */
function _empurrar(c) {
  if (Math.abs(c) < 0.02) { return '0px' }
  const s = c > 0 ? 1 : -1
  return `calc(${(c * 50).toFixed(1)}% + ${(s * 8).toFixed(0)}px)`
}

/**
 * Rótulo em cada lado e a área no meio.
 *
 * Sem isto, o único jeito de saber o que se desenhou era gravar e ler a prévia
 * do servidor — ou seja, descobrir o erro depois de ter feito o lote.
 */
function _pintarMedidas() {
  const mapa = mapaState.obj
  desenhoState.rotulos.forEach(r => mapa.removeLayer(r))
  desenhoState.rotulos = []
  if (desenhoState.rotuloArea) {
    mapa.removeLayer(desenhoState.rotuloArea)
    desenhoState.rotuloArea = null
  }

  const v = desenhoState.vertices
  if (v.length < 2) { return }

  // O lado de fechamento (do último ao primeiro) só ganha rótulo quando o
  // polígono de fato fecha: numa linha ele não existe, e com dois pontos seria
  // o mesmo lado medido duas vezes.
  const fecha = desenhoState.modo === 'poligono' && v.length >= 3
  const lados = fecha ? v.length : v.length - 1

  const cx = v.reduce((s, c) => s + c[0], 0) / v.length
  const cy = v.reduce((s, c) => s + c[1], 0) / v.length

  for (let i = 0; i < lados; i++) {
    const a = v[i]
    const b = v[(i + 1) % v.length]
    desenhoState.rotulos.push(_rotulo(
      (a[1] + b[1]) / 2, (a[0] + b[0]) / 2,
      fmtMedida(distanciaNoPlano(a, b)), 'des-medida',
      _foraDoLado(a, b, [cx, cy])))
  }

  if (fecha) {
    desenhoState.rotuloArea = _rotulo(cy, cx,
      areaDoDesenho().toFixed(2).replace('.', ',') + ' m²', 'des-area')
  }
}

// ── ENCAIXE EM VÉRTICE VIZINHO ───────────────────────────────

/**
 * Se houver vértice de lote a menos de DESENHO_SNAP_PX do cursor, usa a
 * COORDENADA EXATA dele.
 *
 * É o que elimina a fresta NA ORIGEM. Sem isso, o lote desenhado encosta no
 * vizinho com alguns centímetros de sobra ou de falta, e o sistema fica
 * dependendo de tolerância para fingir que a divisa é a mesma — tolerância
 * mascara, o encaixe resolve.
 *
 * O custo é O(vértices na tela): com 300 lotes visíveis de 6 cantos são ~1.800
 * comparações por movimento do cursor, imperceptível.
 *
 * @param {L.LatLng} alvo @returns {L.LatLng}
 */
function _encaixar(alvo) {
  const mapa = mapaState.obj
  const pAlvo = mapa.latLngToContainerPoint(alvo)
  let melhor = null
  let menor = DESENHO_SNAP_PX

  for (const camada of mapaState.camadas) {
    if (!camada.getLatLngs) { continue }
    const aneis = camada.getLatLngs()
    for (const anel of aneis) {
      const pontos = Array.isArray(anel) ? anel : [anel]
      for (const ll of pontos) {
        if (!ll.lat) { continue }
        const p = mapa.latLngToContainerPoint(ll)
        const d = Math.hypot(p.x - pAlvo.x, p.y - pAlvo.y)
        if (d < menor) { menor = d; melhor = ll }
      }
    }
  }

  return melhor || alvo
}

// ── PINTURA ──────────────────────────────────────────────────

/** Laranja do cadastro, o mesmo da seleção — é a mesma família de ação. */
const COR_DESENHO = '#EA580C'

function _pintar() {
  const mapa = mapaState.obj
  const latlngs = desenhoState.vertices.map(c => [c[1], c[0]])

  if (desenhoState.rascunho) {
    desenhoState.rascunho.setLatLngs(latlngs)
  } else if (latlngs.length) {
    desenhoState.rascunho = L.polyline(latlngs, {
      pane: 'desenho', color: COR_DESENHO, weight: 3, interactive: false,
    }).addTo(mapa)
  }

  // Prévia do fechamento, só a partir do terceiro canto.
  if (desenhoState.modo === 'poligono' && latlngs.length >= 3) {
    if (desenhoState.previa) {
      desenhoState.previa.setLatLngs(latlngs)
    } else {
      desenhoState.previa = L.polygon(latlngs, {
        pane: 'desenho', color: COR_DESENHO, weight: 1, opacity: .6,
        fillColor: COR_DESENHO, fillOpacity: .22, dashArray: '4,5', interactive: false,
      }).addTo(mapa)
    }
  } else if (desenhoState.previa) {
    mapa.removeLayer(desenhoState.previa)
    desenhoState.previa = null
  }

  desenhoState.marcadores.forEach(m => mapa.removeLayer(m))
  desenhoState.marcadores = latlngs.map((ll, i) => L.circleMarker(ll, {
    pane: 'desenho', radius: i === 0 ? 6 : 4, weight: 2,
    color: '#fff', fillColor: COR_DESENHO, fillOpacity: 1, interactive: false,
  }).addTo(mapa))

  _pintarMedidas()

  if (typeof aoDesenharVertice === 'function') {
    aoDesenharVertice(desenhoState.vertices.length)
  }
}

function _limpar() {
  const mapa = mapaState.obj
  if (mapa) {
    ;[desenhoState.rascunho, desenhoState.previa, desenhoState.elastico,
      desenhoState.captura, desenhoState.rotuloArea, desenhoState.rotuloElastico]
      .forEach(c => { if (c) mapa.removeLayer(c) })
    desenhoState.marcadores.forEach(m => mapa.removeLayer(m))
    desenhoState.rotulos.forEach(r => mapa.removeLayer(r))
    mapa.off('mousemove', _aoMover)
    mapa.doubleClickZoom.enable()

    // O PAINEL FICA SURDO ao sair do desenho.
    //
    // Remover as camadas nao basta: para desenhar dentro do painel proprio, o
    // Leaflet cria ali um <canvas> que cobre o mapa inteiro e vive acima dos
    // lotes (z-index 650 contra 400). Ele nao e removido junto com as camadas
    // — e, vazio, continuava interceptando todo clique e devolvendo nada.
    //
    // Era isto que travava a correcao cadastral: depois de desenhar um lote,
    // voltar para "corrigir quadra" nao marcava mais nada, porque o toque
    // nunca chegava ao lote. Nao ha efeito visual: o painel segue pintando o
    // que precisa pintar; so deixa de disputar o clique quando nao ha desenho.
    const painel = mapa.getPane('desenho')
    if (painel) { painel.style.pointerEvents = 'none' }
  }

  Object.assign(desenhoState, {
    ativo: false, modo: null, vertices: [], rascunho: null, previa: null,
    elastico: null, captura: null, marcadores: [], onConcluir: null, onCancelar: null,
    plano: null, rotulos: [], rotuloArea: null, rotuloElastico: null, shiftSolto: false,
  })

  document.getElementById('map')?.classList.remove('desenhando')
  if (typeof aoDesenharVertice === 'function') { aoDesenharVertice(0) }
}

/** @param {number} v */
function _arredondar(v) {
  return Number(v.toFixed(DESENHO_CASAS))
}

// Esc cancela; Ctrl+Z desfaz; Enter fecha. O handler roda na fase de CAPTURA
// para chegar antes dos de mapa.js — lá o Esc larga a seleção de consulta, e
// os dois disparando no mesmo toque desfariam duas coisas de uma vez.
document.addEventListener('keydown', ev => {
  if (!desenhoState.ativo) { return }
  if (document.querySelector('.modal-bg.open')) { return }

  if (ev.key === 'Escape') { ev.stopPropagation(); cancelarDesenho(); return }
  if (ev.key === 'Enter') { ev.stopPropagation(); concluirDesenho(); return }
  if (ev.key === 'z' && (ev.ctrlKey || ev.metaKey)) { ev.stopPropagation(); desfazerVertice() }

  // Shift SOLTA a trava enquanto está pressionado, e não a desliga.
  //
  // O canto fora de esquadro é a exceção — a quina chanfrada, o lote de
  // esquina —, e exceção não deve custar dois cliques num botão e a lembrança
  // de religar depois. Quem esquece de religar desenha o resto do lote torto.
  if (ev.key === 'Shift' && !desenhoState.shiftSolto) {
    desenhoState.shiftSolto = true
  }
}, true)

document.addEventListener('keyup', ev => {
  if (ev.key === 'Shift') { desenhoState.shiftSolto = false }
}, true)

// A tecla presa quando a janela perde o foco ficaria presa para sempre: o
// `keyup` acontece fora da página e nunca chega aqui.
window.addEventListener('blur', () => { desenhoState.shiftSolto = false })
