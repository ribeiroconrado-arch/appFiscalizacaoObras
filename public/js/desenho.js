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
    onConcluir: opcoes.onConcluir,
    onCancelar: opcoes.onCancelar || null,
    ultimoClique: { t: 0, x: 0, y: 0 },
  })

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

  const ll = desenhoState.snap ? _encaixar(ev.latlng) : ev.latlng
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
  const destino = desenhoState.snap ? _encaixar(ev.latlng) : ev.latlng
  const traco = [[ultimo[1], ultimo[0]], [destino.lat, destino.lng]]

  if (desenhoState.elastico) {
    desenhoState.elastico.setLatLngs(traco)
  } else {
    desenhoState.elastico = L.polyline(traco, {
      pane: 'desenho', color: COR_DESENHO, weight: 2, dashArray: '5,6', opacity: .8, interactive: false,
    }).addTo(mapaState.obj)
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

  if (typeof aoDesenharVertice === 'function') {
    aoDesenharVertice(desenhoState.vertices.length)
  }
}

function _limpar() {
  const mapa = mapaState.obj
  if (mapa) {
    ;[desenhoState.rascunho, desenhoState.previa, desenhoState.elastico, desenhoState.captura]
      .forEach(c => { if (c) mapa.removeLayer(c) })
    desenhoState.marcadores.forEach(m => mapa.removeLayer(m))
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
}, true)
