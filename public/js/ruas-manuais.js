/**
 * Alteração cadastral → NOMES DE RUA.
 *
 * O nome automático (bairros-contorno.js) sai do voto dos lotes de cada lado
 * de quadra. Onde o voto não decide — lado só com lotes de esquina, lote sem
 * logradouro no cadastro — o trecho fica SEM NOME. Aqui o curador informa o
 * nome do TRECHO, e não do lote: o endereço do lote vem do cadastro e seria
 * sobrescrito na próxima carga.
 *
 * REFEITA EM 22/11/2026, a pedido dele:
 *  - A RUA, E NÃO O TRECHO. O servidor continua guardando um trecho por lado
 *    de quadra (é dele que vem o voto), mas a tela junta os trechos que se
 *    continuam com o mesmo nome numa rua só: um clique seleciona a rua inteira
 *    e "Salvar" grava todos os trechos num pedido (POST /api/ruas/manuais/lote).
 *  - SUGESTÃO DE CONTINUAÇÃO. O trecho sem nome que continua, alinhado, uma
 *    rua com nome recebe esse nome como sugestão; "Aceitar" corrige em bloco.
 *  - PAINEL NA LATERAL (#ruas-mesa, o molde da mesa de desmembramento), e não
 *    barra flutuante com legenda; e SEM BALÃO: clicar no vazio do mapa não
 *    perde a seleção. Esc limpa.
 *  - O NOME FICA POR CIMA DA LINHA: o painel das linhas (ruasEdicao) fica
 *    abaixo do dos marcadores, onde moram os rótulos de rua; a linha é fina e
 *    o clique tem tolerância (L.canvas tolerance).
 *  - Rua desenhada com VÁRIOS pontos, terminando no duplo clique, com as
 *    pontas encaixando nas ruas que já existem.
 *
 * O nome é escolhido da lista de logradouros do cadastro do bairro; só o
 * administrador digita fora dela (ver RuaManualController).
 */

const ruaState = {
  ativa: false,
  daCuradoria: false,
  /** @type {L.LayerGroup|null} as linhas clicáveis */ camada: null,
  /** @type {L.LayerGroup|null} o realce da seleção e do desenho */ realce: null,
  /** @type {L.Renderer|null} */ renderer: null,
  /** @type {Array<Object>} os trechos dos bairros carregados, já indexados */ trechos: [],
  /** @type {Map<string, Object>} */ porK: new Map(),
  /** @type {Set<string>} chaves dos trechos selecionados */ selecao: new Set(),
  /** @type {?string} o último trecho clicado ("Só o trecho clicado") */ ultimo: null,
  /** @type {'todas'|'sem_nome'|'sugestoes'|'manual'} */ filtro: 'todas',
  /** @type {?string} o logradouro escolhido no combo, preservado entre repinturas */ escolha: null,
  /** @type {{pontos: L.LatLng[]}|null} rua sendo desenhada */ desenhando: null,
  /** @type {Array<{bairro:string, de:number[], ate:number[]}>|null} desenhada, esperando nome */ novo: null,
  /** @type {Map<string, {logradouros:string[], livre:boolean, cadastro:boolean}>} por bairro */ listas: new Map(),
  /**
   * O que a busca no OpenStreetMap achou, por trecho: `nome` é a grafia da
   * lista do cadastro (vira sugestão); `osm`, o nome como está lá (só pista,
   * quando não bate com a lista); os dois nulos, procurado e nada achado.
   * Vive só na tela: nada disto é gravado até o curador aceitar.
   * @type {Map<string, {nome:?string, osm:?string}>}
   */
  osm: new Map(),
  buscandoOsm: false,
  gravando: false,
}

const COR_TRECHO = { cadastro: '#16a34a', manual: '#2563eb', sem_nome: '#dc2626', oculto: '#9ca3af', sugestao: '#d97706', novo: '#2563eb' }
const ROTULO_ORIGEM = { cadastro: 'cadastro', manual: 'à mão', sem_nome: 'sem nome', oculto: 'oculto' }

// Continuação de rua, em metros. Ver _continua.
const RUA_ANGULO_COS = Math.cos(20 * Math.PI / 180)
const RUA_FOLGA_PONTA_M = 35     // a largura da rua transversal, com sobra
const RUA_DESVIO_M = 10          // fora da reta, na emenda
const RUA_LADOS_M = 22           // os dois lados da mesma rua (divisa de bairros)
const RUA_LISTA_MAX = 150

FERRAMENTAS_MAPA.ruas = {
  rotulo: 'nomes de rua',
  aberta: () => ruaState.ativa,
  emCurso: () => ruaState.desenhando?.pontos.length || ruaState.novo ? 'uma rua desenhada sem nome' : null,
  fechar: () => fecharNomesDeRua(),
}

/** Abre a ferramenta (botão "Nomes de rua" da alteração cadastral e da base de lotes). */
function abrirNomesDeRua() {
  const daCuradoria = typeof _lancadorCadastralAVista === 'function' && _lancadorCadastralAVista()
  pedirFerramenta('ruas', () => {
    const mapa = mapaState.obj
    ruaState.ativa = true
    ruaState.daCuradoria = daCuradoria
    if (!mapa.getPane('ruasEdicao')) mapa.createPane('ruasEdicao')
    // ABAIXO DOS RÓTULOS (marcadores, 600) e acima dos lotes (400): o nome da
    // rua fica legível por cima da linha. Antes era 640, e a linha tapava o texto.
    mapa.getPane('ruasEdicao').style.zIndex = 590
    mapa.getPane('ruasEdicao').style.display = ''
    // A linha é fina, mas o clique pega a 8 px dela.
    ruaState.renderer ??= L.canvas({ pane: 'ruasEdicao', tolerance: 8 })
    ruaState.camada = L.layerGroup().addTo(mapa)
    ruaState.realce = L.layerGroup().addTo(mapa)
    mapa.on('click', _cliqueNoMapaRuas)
    mapa.on('dblclick', _duploCliqueRuas)
    mapa.on('moveend', _aoMoverRuas)
    document.addEventListener('keydown', _teclaRuas)
    window.addEventListener('resize', _posicionarMesaRuas)

    const mesa = document.getElementById('ruas-mesa')
    if (mesa) mesa.hidden = false
    _posicionarMesaRuas()
    if (!['quadras', 'lotes'].includes(nivelDoMapa(mapa))) {
      toast('Aproxime o mapa até ver as quadras: é nessa escala que as ruas aparecem.', 'aviso')
    }
    carregarQuadrasVisiveis()
    aoMudarRuas()
  }, { convive: ['base'] })   // a régua da base de lotes, de onde ela é lançada, fica
}

function fecharNomesDeRua() {
  const mapa = mapaState.obj
  ruaState.ativa = false
  _cancelarDesenhoRua(true)
  ruaState.novo = null
  ruaState.selecao.clear()
  ruaState.camada?.remove()
  ruaState.realce?.remove()
  ruaState.camada = ruaState.realce = null
  mapa?.off('click', _cliqueNoMapaRuas)
  mapa?.off('dblclick', _duploCliqueRuas)
  mapa?.off('moveend', _aoMoverRuas)
  document.removeEventListener('keydown', _teclaRuas)
  window.removeEventListener('resize', _posicionarMesaRuas)
  // A tela de desenho (canvas) do painel cobre o mapa inteiro e engoliria
  // todo clique nos lotes: escondida, não recebe nenhum.
  const pane = mapa?.getPane('ruasEdicao')
  if (pane) pane.style.display = 'none'
  const mesa = document.getElementById('ruas-mesa')
  if (mesa) mesa.hidden = true
  if (ruaState.daCuradoria && typeof pedirVoltaACuradoria === 'function') pedirVoltaACuradoria()
  ruaState.daCuradoria = false
}

/** Mapa movido: a contagem "na tela" da busca no OpenStreetMap muda. Desenhando, nada. */
function _aoMoverRuas() {
  if (!ruaState.desenhando && !ruaState.gravando) pintarMesaRuas()
}

/** Com a régua da base de lotes à vista, a mesa fica ao lado dela, e não por cima. */
function _posicionarMesaRuas() {
  const mesa = document.getElementById('ruas-mesa')
  if (!mesa) return
  const base = document.getElementById('base-mesa')
  mesa.style.left = base && !base.hidden && window.innerWidth >= 1000
    ? Math.round(base.getBoundingClientRect().right + 8) + 'px' : ''
}

// ══════ OS TRECHOS VIRAM RUAS ══════

/** Plano local em metros (a 15° S o grau de longitude é ~4 % menor que o de latitude). */
const _COS_LAT = Math.cos(-15.55 * Math.PI / 180)
const _m = p => [p[1] * 111320 * _COS_LAT, p[0] * 110574]
const _kDoTrecho = (bairro, t) => `${bairro}|${t.de.join(',')}|${t.ate.join(',')}`
const _comprimento = (de, ate) => { const a = _m(de), b = _m(ate); return Math.hypot(b[0] - a[0], b[1] - a[1]) }

/**
 * O trecho q continua o trecho p? Paralelos (até 20°) e:
 *  - EMENDA: uma ponta perto da outra (a rua transversal no meio) e fora da
 *    reta no máximo 10 m; ou
 *  - OS DOIS LADOS da mesma rua: sobrepostos ao longo dela e a até 22 m — a
 *    rua da divisa, que cada bairro gera a 7 m da própria quadra.
 */
function _continua(p, q) {
  if (Math.abs(p.ux * q.ux + p.uy * q.uy) < RUA_ANGULO_COS) return false
  const desvio = pt => Math.abs((pt[0] - p.a[0]) * p.uy - (pt[1] - p.a[1]) * p.ux)
  const ao = pt => (pt[0] - p.a[0]) * p.ux + (pt[1] - p.a[1]) * p.uy
  const d = (x, y) => Math.hypot(x[0] - y[0], x[1] - y[1])
  const folga = Math.min(d(p.a, q.a), d(p.a, q.b), d(p.b, q.a), d(p.b, q.b))
  if (folga <= RUA_FOLGA_PONTA_M && Math.min(desvio(q.a), desvio(q.b)) <= RUA_DESVIO_M) return true
  const [t1, t2] = [ao(q.a), ao(q.b)].sort((x, y) => x - y)
  const sobre = Math.min(p.len, t2) - Math.max(0, t1)
  return sobre > 0 && desvio([(q.a[0] + q.b[0]) / 2, (q.a[1] + q.b[1]) / 2]) <= RUA_LADOS_M
}

/** Os vizinhos que continuam cada trecho, procurados numa grade de 50 m. */
function _vizinhos(trechos) {
  const grade = new Map()
  const pontos = t => [t.a, t.b, [(t.a[0] + t.b[0]) / 2, (t.a[1] + t.b[1]) / 2]]
  trechos.forEach((t, i) => {
    for (const pt of pontos(t)) {
      const c = Math.floor(pt[0] / 50) + ':' + Math.floor(pt[1] / 50)
      ;(grade.get(c) || grade.set(c, []).get(c)).push(i)
    }
  })
  return trechos.map(t => {
    const perto = new Set()
    for (const pt of pontos(t)) {
      const cx = Math.floor(pt[0] / 50), cy = Math.floor(pt[1] / 50)
      for (let dx = -1; dx <= 1; dx++) for (let dy = -1; dy <= 1; dy++) {
        for (const j of grade.get((cx + dx) + ':' + (cy + dy)) || []) if (trechos[j] !== t) perto.add(j)
      }
    }
    return [...perto].filter(j => _continua(t, trechos[j]))
  })
}

/**
 * Refaz o índice: cada trecho com a chave, a geometria em metros, o grupo
 * (a rua contínua de mesmo nome) e a sugestão de nome, se for sem nome.
 */
function _indexarRuas() {
  const trechos = []
  for (const [bairro, b] of quadraState.porBairro) {
    if (b === 'carregando') continue
    for (const t of b.ruas || []) {
      const a = _m(t.de), bb = _m(t.ate)
      const len = Math.hypot(bb[0] - a[0], bb[1] - a[1])
      if (len < 0.5) continue
      trechos.push({
        k: _kDoTrecho(bairro, t), bairro, t, a, b: bb, len,
        ux: (bb[0] - a[0]) / len, uy: (bb[1] - a[1]) / len,
        chave: t.nome && t.origem !== 'oculto' ? _chaveDaRua(t.nome) : null,
        grupo: null, sugestao: null, fonte: null, pista: null,
      })
    }
  }
  const viz = _vizinhos(trechos)

  // Grupos: componentes de trechos com o mesmo nome que se continuam. Os sem
  // nome seguidos também são uma rua só (a avenida inteira sem nome, um clique);
  // o oculto fica no seu.
  const doGrupo = t => t.chave || (t.t.origem === 'sem_nome' ? '∅' : null)
  let g = 0
  trechos.forEach((t, i) => {
    if (t.grupo !== null) return
    t.grupo = g
    if (doGrupo(t)) {
      const fila = [i]
      while (fila.length) {
        for (const j of viz[fila.pop()]) {
          const u = trechos[j]
          if (u.grupo === null && doGrupo(u) === doGrupo(t)) { u.grupo = g; fila.push(j) }
        }
      }
    }
    g++
  })

  // Sugestões: o sem nome cujos vizinhos com nome concordam num nome só. Em
  // passadas, para atravessar dois ou três sem nome seguidos.
  const continuacao = () => {
    for (let passada = 0; passada < 4; passada++) {
      let mudou = false
      trechos.forEach((t, i) => {
        if (t.t.origem !== 'sem_nome' || t.sugestao) return
        const nomes = new Map()
        for (const j of viz[i]) {
          const u = trechos[j]
          const nome = u.chave ? u.t.nome : u.sugestao
          if (!nome) continue
          const ch = _chaveDaRua(nome)
          // O nome do mesmo bairro, se houver: é a grafia que a lista dele aceita.
          if (!nomes.has(ch) || u.bairro === t.bairro) nomes.set(ch, nome)
        }
        if (nomes.size === 1) { t.sugestao = [...nomes.values()][0]; t.fonte = 'continuacao'; mudou = true }
      })
      if (!mudou) break
    }
  }
  continuacao()
  // Depois, o que a busca no OpenStreetMap achou para os que sobraram — e a
  // continuação de novo, que agora tem mais nomes para propagar.
  if (ruaState.osm.size) {
    for (const t of trechos) {
      const o = t.t.origem === 'sem_nome' && !t.sugestao ? ruaState.osm.get(t.k) : null
      if (o?.nome) { t.sugestao = o.nome; t.fonte = 'osm' }
      else if (o?.osm) t.pista = o.osm
    }
    continuacao()
  }

  ruaState.trechos = trechos
  ruaState.porK = new Map(trechos.map(t => [t.k, t]))
  for (const k of [...ruaState.selecao]) if (!ruaState.porK.has(k)) ruaState.selecao.delete(k)
}

/**
 * Redesenha as linhas com o que está guardado de cada bairro. Chamada por
 * carregarQuadrasVisiveis (bairro novo na tela) e depois de cada gravação.
 */
function aoMudarRuas() {
  if (!ruaState.ativa || !ruaState.camada) return
  _indexarRuas()
  ruaState.camada.clearLayers()
  for (const x of ruaState.trechos) {
    const o = x.sugestao ? 'sugestao' : x.t.origem
    const linha = L.polyline([x.t.de, x.t.ate], {
      pane: 'ruasEdicao', renderer: ruaState.renderer,
      color: COR_TRECHO[o] || '#666', weight: o === 'cadastro' ? 3 : 3.5, opacity: o === 'cadastro' ? .55 : .9,
      dashArray: ['sem_nome', 'oculto', 'sugestao'].includes(o) ? '7 5' : null,
    })
    linha.on('click', e => {
      // Desenhando, o toque é ponto da rua nova: deixa passar para o mapa.
      if (ruaState.desenhando) return
      L.DomEvent.stopPropagation(e)
      _clicarTrecho(x.k)
    })
    linha.bindTooltip(x.t.nome
      || (x.sugestao ? `sem nome · sugestão${x.fonte === 'osm' ? ' do OpenStreetMap' : ''}: ${x.sugestao}`
        : x.pista ? `sem nome · OpenStreetMap: ${x.pista} (fora da lista do cadastro)` : ROTULO_ORIGEM[x.t.origem]),
      { sticky: true, direction: 'top' })
    ruaState.camada.addLayer(linha)
  }
  _pintarRealceRuas()
  pintarMesaRuas()
}

/** Um clique seleciona a RUA inteira (o grupo); clicar de novo nela tira. Soma às outras. */
function _clicarTrecho(k) {
  const x = ruaState.porK.get(k)
  if (!x) return
  ruaState.novo = null
  const grupo = ruaState.trechos.filter(t => t.grupo === x.grupo)
  const todos = grupo.every(t => ruaState.selecao.has(t.k))
  for (const t of grupo) todos ? ruaState.selecao.delete(t.k) : ruaState.selecao.add(t.k)
  ruaState.ultimo = todos ? null : k
  ruaState.escolha = null
  _pintarRealceRuas()
  pintarMesaRuas()
}

/** Seleciona trechos pela lista do painel, e leva o mapa até eles. @param {Object[]} lista */
function _selecionarELevar(lista) {
  if (!lista.length) return
  ruaState.novo = null
  ruaState.selecao = new Set(lista.map(t => t.k))
  ruaState.ultimo = lista[0].k
  ruaState.escolha = null
  const caixa = L.latLngBounds(lista.flatMap(t => [t.t.de, t.t.ate]))
  mapaState.obj.fitBounds(caixa, { padding: [80, 80], maxZoom: 19 })
  _pintarRealceRuas()
  pintarMesaRuas()
}

function limparSelecaoRuas() {
  ruaState.selecao.clear()
  ruaState.ultimo = null
  ruaState.escolha = null
  _pintarRealceRuas()
  pintarMesaRuas()
}

function soEsteTrechoRua() {
  if (!ruaState.ultimo) return
  ruaState.selecao = new Set([ruaState.ultimo])
  _pintarRealceRuas()
  pintarMesaRuas()
}

function _pintarRealceRuas() {
  const r = ruaState.realce
  if (!r) return
  r.clearLayers()
  const op = { pane: 'ruasEdicao', renderer: ruaState.renderer, interactive: false }
  const halo = (pts, cor) => {
    r.addLayer(L.polyline(pts, { ...op, color: cor, weight: 12, opacity: .22 }))
    r.addLayer(L.polyline(pts, { ...op, color: cor, weight: 3.5, opacity: 1 }))
  }
  for (const k of ruaState.selecao) {
    const x = ruaState.porK.get(k)
    if (x) halo([x.t.de, x.t.ate], COR_TRECHO.manual)
  }
  for (const s of ruaState.novo || []) halo([s.de, s.ate], COR_TRECHO.novo)
  const d = ruaState.desenhando
  if (d?.pontos.length) {
    if (d.pontos.length > 1) halo(d.pontos, COR_TRECHO.novo)
    for (const p of d.pontos) {
      r.addLayer(L.circleMarker(p, { ...op, radius: 5, color: COR_TRECHO.novo, weight: 2, fillColor: '#fff', fillOpacity: 1 }))
    }
  }
}

/** Esc: larga o desenho; sem desenho, limpa a seleção. */
function _teclaRuas(e) {
  if (e.key !== 'Escape' || !ruaState.ativa) return
  if (e.target.closest?.('input, select, textarea') || document.querySelector('.modal-bg.open')) return
  if (ruaState.desenhando) { _cancelarDesenhoRua(); return }
  if (ruaState.novo) { descartarRuaNova(); return }
  limparSelecaoRuas()
}

// ══════ O PAINEL ══════

/** A contagem de cada filtro. */
function _contagensRuas() {
  const ts = ruaState.trechos
  return {
    todas: new Set(ts.filter(t => t.chave).map(t => t.chave)).size,
    sem_nome: ts.filter(t => t.t.origem === 'sem_nome' && !t.sugestao).length,
    sugestoes: ts.filter(t => t.sugestao).length,
    manual: ts.filter(t => t.t.origem === 'manual' || t.t.origem === 'oculto').length,
  }
}

const _metros = n => fmtBR(Math.round(n), 0) + ' m'
const _kAttr = k => encodeURIComponent(k)

function pintarMesaRuas() {
  const corpo = document.getElementById('ruas-mesa-corpo')
  if (!corpo || !ruaState.ativa) return
  const n = _contagensRuas()
  const partes = []

  if (ruaState.desenhando) partes.push(_htmlDesenhando())
  else if (ruaState.novo) partes.push(_htmlRuaNova())
  else if (ruaState.selecao.size) partes.push(_htmlSelecao())
  else partes.push('<div class="ruas-nota">Clique numa rua no mapa: ela é selecionada inteira. Outro clique soma outra rua; Esc limpa.</div>')

  if (n.sugestoes && ruaState.filtro !== 'sugestoes' && !ruaState.desenhando && !ruaState.novo) {
    partes.push(`<div class="ruas-sugere">
      <b>${n.sugestoes} ${n.sugestoes === 1 ? 'trecho sem nome tem' : 'trechos sem nome têm'} sugestão de nome.</b>
      <div class="ruas-botoes">
        <button type="button" class="btn sm primary" onclick="aceitarSugestoesDeRua()" ${ruaState.gravando ? 'disabled' : ''}>Aceitar ${n.sugestoes === 1 ? 'a sugestão' : 'as ' + n.sugestoes}</button>
        <button type="button" class="btn sm" onclick="filtrarRuas('sugestoes')">Ver uma a uma</button>
      </div></div>`)
  }

  // A busca no OpenStreetMap: para os sem nome À VISTA que ainda não têm
  // sugestão nem foram procurados.
  if (!ruaState.desenhando && !ruaState.novo) {
    const faltam = _semNomeParaBuscar().length
    if (faltam || ruaState.buscandoOsm) {
      partes.push(`<div class="ruas-busca">
        <button type="button" class="btn sm" onclick="buscarNomesNoOsm()" ${ruaState.buscandoOsm ? 'disabled' : ''}>
          ${ruaState.buscandoOsm ? 'Buscando no OpenStreetMap…' : `Buscar nomes que faltam (${faltam} na tela)`}</button>
        <span class="ruas-nota">Consulta o OpenStreetMap; nada é gravado até você aceitar.</span></div>`)
    }
  }

  const filtros = [['todas', 'Ruas'], ['sem_nome', 'Sem nome'], ['sugestoes', 'Sugestões'], ['manual', 'À mão']]
  partes.push(`<div class="seg ruas-filtros">${filtros.map(([f, r]) =>
    `<button type="button" class="${ruaState.filtro === f ? 'at' : ''}" onclick="filtrarRuas('${f}')">${r}<span class="ruas-n">${n[f]}</span></button>`).join('')}</div>`)
  partes.push(`<div class="ruas-lista">${_htmlListaRuas()}</div>`)

  if (!ruaState.desenhando && !ruaState.novo) {
    partes.push(`<button type="button" class="btn sm ruas-desenhar" onclick="comecarDesenhoRua()"
      title="Rua sem lote de frente: rodovia, avenida ao lado de área verde">Desenhar rua</button>`)
  }
  // ODbL: o nome que veio de lá cita a fonte.
  if (ruaState.trechos.some(t => t.fonte === 'osm' || t.pista)) {
    partes.push('<div class="ruas-nota">Nomes sugeridos pelo OpenStreetMap: © colaboradores do OpenStreetMap.</div>')
  }
  corpo.innerHTML = partes.join('')
  _preencherComboRuas()
}

function filtrarRuas(f) {
  ruaState.filtro = f
  pintarMesaRuas()
}

/** O cartão da seleção: o nome, o tamanho e a escolha do logradouro. */
function _htmlSelecao() {
  const sel = _selecionados()
  const nomes = [...new Map(sel.filter(t => t.chave).map(t => [t.chave, t.t.nome])).values()]
  const sugestoes = [...new Set(sel.map(t => t.sugestao).filter(Boolean))]
  const titulo = nomes.length === 1 && sel.every(t => t.chave) ? esc(nomes[0])
    : nomes.length > 1 ? `${nomes.length} nomes diferentes`
      : nomes.length === 1 ? `${esc(nomes[0])} <span class="ruas-sem">e trechos sem nome</span>`
        : sel.every(t => t.t.origem === 'oculto') ? '<span class="ruas-sem">oculto</span>'
          : '<span class="ruas-sem">sem nome</span>'
  const bairros = [...new Set(sel.map(t => t.bairro))]
  const total = sel.reduce((s, t) => s + t.len, 0)
  const temManual = sel.some(t => t.t.id)
  const n = sel.length
  const pistas = [...new Set(sel.map(t => t.pista).filter(Boolean))]
  const doOsm = sel.some(t => t.fonte === 'osm')
  const dica = !nomes.length && sugestoes.length === 1
    ? `<div class="ruas-dica">${doOsm ? 'O OpenStreetMap chama de' : 'Continua a'} <b>${esc(sugestoes[0])}</b>.</div>`
    : !nomes.length && !sugestoes.length && pistas.length === 1
      ? `<div class="ruas-dica">O OpenStreetMap chama de <b>${esc(pistas[0])}</b>, que não está na lista do cadastro do bairro.</div>` : ''
  return `<div class="ruas-sel">
    <div class="ruas-sel-nome">${titulo}</div>
    <div class="ruas-sel-sub">${n} ${n === 1 ? 'trecho' : 'trechos'} · ${_metros(total)} · ${esc(bairros.length === 1 ? bairros[0] : bairros.length + ' bairros')}</div>
    ${dica}
    ${_htmlCampoNome()}
    <div class="ruas-botoes">
      <button type="button" class="btn sm primary" onclick="salvarNomeDeRua()" ${ruaState.gravando ? 'disabled' : ''}>${n === 1 ? 'Salvar' : 'Salvar nos ' + n}</button>
      <button type="button" class="btn sm" onclick="ocultarRuaSelecionada()" title="Rótulo errado e sem nome melhor: some do mapa">Ocultar</button>
      ${temManual ? '<button type="button" class="btn sm" onclick="voltarRuaAoCadastro()">Voltar ao cadastro</button>' : ''}
    </div>
    <div class="ruas-botoes ruas-leves">
      ${n > 1 && ruaState.ultimo ? '<button type="button" class="hist-voltar" onclick="soEsteTrechoRua()">Só o trecho clicado</button>' : ''}
      <button type="button" class="hist-voltar" onclick="limparSelecaoRuas()">Limpar</button>
    </div>
  </div>`
}

const _htmlCampoNome = () => `<div class="field"><label for="rua-nome">Logradouro</label>
  <span id="rua-nome-campo"><select disabled><option>Carregando…</option></select></span></div><div id="rua-nome-aviso"></div>`

function _htmlDesenhando() {
  const n = ruaState.desenhando.pontos.length
  const passo = !n ? 'Clique no INÍCIO da rua, sobre ela.'
    : n === 1 ? 'Clique no próximo ponto.' : `${n} pontos. Duplo clique, ou Concluir, termina.`
  return `<div class="ruas-sel">
    <div class="ruas-sel-nome">Desenhar rua</div>
    <div class="ruas-sel-sub">${passo} As pontas encaixam nas ruas que já existem.</div>
    <div class="ruas-botoes">
      <button type="button" class="btn sm primary" onclick="concluirDesenhoRua()" ${n < 2 ? 'disabled' : ''}>Concluir</button>
      ${n ? '<button type="button" class="btn sm" onclick="desfazerPontoRua()">Desfazer ponto</button>' : ''}
      <button type="button" class="btn sm" onclick="_cancelarDesenhoRua()">Cancelar</button>
    </div></div>`
}

function _htmlRuaNova() {
  const segs = ruaState.novo
  const total = segs.reduce((s, x) => s + _comprimento(x.de, x.ate), 0)
  return `<div class="ruas-sel">
    <div class="ruas-sel-nome">Rua nova</div>
    <div class="ruas-sel-sub">${segs.length} ${segs.length === 1 ? 'segmento' : 'segmentos'} · ${_metros(total)}</div>
    ${_htmlCampoNome()}
    <div class="ruas-botoes">
      <button type="button" class="btn sm primary" onclick="salvarNomeDeRua()" ${ruaState.gravando ? 'disabled' : ''}>Salvar</button>
      <button type="button" class="btn sm" onclick="descartarRuaNova()">Descartar</button>
    </div></div>`
}

/** A lista do filtro escolhido. */
function _htmlListaRuas() {
  const ts = ruaState.trechos
  if (!ts.length) return '<div class="ruas-nota">Nenhuma rua nos bairros à vista. Aproxime o mapa até ver as quadras.</div>'
  const linha = (cls, nome, sub, acao, extra = '') =>
    `<div class="ruas-item"><button type="button" class="ruas-item-ir" onclick="${acao}"><i class="ruas-cor ${cls}"></i>
      <span class="ruas-item-txt">${nome}<small>${sub}</small></span></button>${extra}</div>`
  const doTrecho = t => `_listaSeleciona('k', '${_kAttr(t.k)}')`
  let itens = []

  if (ruaState.filtro === 'todas') {
    const porChave = new Map()
    for (const t of ts) if (t.chave) (porChave.get(t.chave) || porChave.set(t.chave, []).get(t.chave)).push(t)
    itens = [...porChave.entries()].sort((a, b) => a[0].localeCompare(b[0], 'pt-BR')).map(([ch, lista]) => {
      const manual = lista.some(t => t.t.origem === 'manual')
      const comp = lista.reduce((s, t) => s + t.len, 0)
      return linha(manual ? 'o-manual' : 'o-cadastro', esc(lista[0].t.nome),
        `${lista.length} ${lista.length === 1 ? 'trecho' : 'trechos'} · ${_metros(comp)}${manual ? ' · à mão' : ''}`,
        `_listaSeleciona('chave', '${_kAttr(ch)}')`)
    })
  } else if (ruaState.filtro === 'sem_nome') {
    itens = ts.filter(t => t.t.origem === 'sem_nome' && !t.sugestao).map(t =>
      linha('o-sem_nome', 'Sem nome', `${esc(t.bairro)} · ${_metros(t.len)}`
        + (t.pista ? ` · OpenStreetMap: ${esc(t.pista)} (fora da lista)` : ruaState.osm.has(t.k) ? ' · nada no OpenStreetMap' : ''), doTrecho(t)))
  } else if (ruaState.filtro === 'sugestoes') {
    itens = ts.filter(t => t.sugestao).map(t =>
      linha('o-sugestao', esc(t.sugestao), `${t.fonte === 'osm' ? 'do OpenStreetMap' : 'continuação'} · ${esc(t.bairro)} · ${_metros(t.len)}`, doTrecho(t),
        `<button type="button" class="btn sm" onclick="aceitarSugestoesDeRua('${_kAttr(t.k)}')">Aceitar</button>`))
  } else {
    itens = ts.filter(t => t.t.origem === 'manual' || t.t.origem === 'oculto').map(t =>
      linha('o-' + t.t.origem, t.t.origem === 'oculto' ? 'Oculto' : esc(t.t.nome), `${ROTULO_ORIGEM[t.t.origem]} · ${esc(t.bairro)}`, doTrecho(t)))
  }
  if (!itens.length) {
    return `<div class="ruas-nota">${{ sem_nome: 'Nenhum trecho sem nome à vista.', sugestoes: 'Nenhuma sugestão à vista.', manual: 'Nada informado à mão à vista.' }[ruaState.filtro] || ''}</div>`
  }
  const mais = itens.length > RUA_LISTA_MAX ? `<div class="ruas-nota">Mostrando ${RUA_LISTA_MAX} de ${itens.length}. Aproxime o mapa para ver o resto.</div>` : ''
  return itens.slice(0, RUA_LISTA_MAX).join('') + mais
}

/** @param {'chave'|'k'} por @param {string} v codificado com _kAttr */
function _listaSeleciona(por, v) {
  const valor = decodeURIComponent(v)
  _selecionarELevar(por === 'k' ? [ruaState.porK.get(valor)].filter(Boolean) : ruaState.trechos.filter(t => t.chave === valor))
}

// ══════ O NOME ══════

/** A lista oficial de logradouros do bairro, guardada por bairro. @param {string} bairro */
async function _listaDoBairro(bairro) {
  if (ruaState.listas.has(bairro)) return ruaState.listas.get(bairro)
  const r = await fetch('/api/ruas/logradouros?bairro=' + encodeURIComponent(bairro), { headers: { Accept: 'application/json' } })
  const d = await r.json()
  if (!r.ok) throw new Error(d.message || 'Não foi possível ler os logradouros do bairro.')
  ruaState.listas.set(bairro, d)
  return d
}

/** Os bairros do que está selecionado (ou desenhado). */
function _bairrosEmJogo() {
  if (ruaState.novo) return [...new Set(ruaState.novo.map(s => s.bairro))]
  return [...new Set(_selecionados().map(t => t.bairro))]
}

/** Monta o combo com a lista dos bairros em jogo: a união delas. */
async function _preencherComboRuas() {
  const caixa = document.getElementById('rua-nome-campo')
  if (!caixa) return
  let listas
  try { listas = await Promise.all(_bairrosEmJogo().map(_listaDoBairro)) } catch (e) { toast(e.message, 'err'); return }
  if (document.getElementById('rua-nome-campo') !== caixa) return   // repintado enquanto esperava

  const sel = ruaState.novo ? [] : _selecionados()
  const nomes = [...new Set(sel.filter(t => t.chave).map(t => t.t.nome))]
  const sugs = [...new Set(sel.map(t => t.sugestao).filter(Boolean))]
  const atual = ruaState.escolha ?? (nomes.length === 1 ? nomes[0] : !nomes.length && sugs.length === 1 ? sugs[0] : '')

  const todos = [...new Set(listas.flatMap(l => l.logradouros))].sort((a, b) => a.localeCompare(b, 'pt-BR'))
  // Livre só se TODOS os bairros em jogo forem livres: o servidor confere a
  // lista de cada um, e um nome fora de uma delas recusaria tudo.
  const livre = listas.length > 0 && listas.every(l => l.livre)
  const opcoes = todos.map(n => `<option value="${esc(n)}"${n === atual ? ' selected' : ''}>${esc(n)}</option>`).join('')
  caixa.innerHTML = livre
    ? `<input id="rua-nome" list="rua-nomes" value="${esc(atual)}" placeholder="Nome do logradouro" maxlength="180" oninput="ruaState.escolha=this.value">
       <datalist id="rua-nomes">${opcoes}</datalist>`
    : `<select id="rua-nome" onchange="ruaState.escolha=this.value"><option value="">Escolha o logradouro…</option>${opcoes}</select>`
  const aviso = document.getElementById('rua-nome-aviso')
  if (aviso) aviso.innerHTML = listas.some(l => !l.cadastro) ? '<div class="rua-aviso">Bairro sem cadastro amarrado: o nome é livre.</div>' : ''
}

/** Grava o nome escolhido em toda a seleção (ou na rua desenhada). */
async function salvarNomeDeRua() {
  const nome = document.getElementById('rua-nome')?.value.trim()
  if (!nome) { toast('Escolha o logradouro.', 'err'); return }
  if (ruaState.novo) {
    await _enviarLoteRuas({ acao: 'nome', nome, itens: ruaState.novo })
    return
  }
  const ch = _chaveDaRua(nome)
  // O trecho que já tem esse nome não muda: nada a gravar nele.
  const itens = _selecionados().filter(t => t.chave !== ch).map(_itemDoTrecho)
  if (!itens.length) { toast('A seleção já tem esse nome.', 'aviso'); return }
  await _enviarLoteRuas({ acao: 'nome', nome, itens })
}

async function ocultarRuaSelecionada() {
  const itens = _selecionados().filter(t => t.t.origem !== 'oculto').map(_itemDoTrecho)
  if (!itens.length) { toast('A seleção já está oculta.', 'aviso'); return }
  await _enviarLoteRuas({ acao: 'ocultar', itens })
}

async function voltarRuaAoCadastro() {
  const itens = _selecionados().filter(t => t.t.id).map(_itemDoTrecho)
  if (!itens.length) return
  await _enviarLoteRuas({ acao: 'voltar', itens })
}

/**
 * Aceita as sugestões de continuação — todas, ou só a de um trecho. O nome
 * fora da lista do cadastro do bairro fica de fora (só o administrador
 * escolheria fora dela), e o aviso diz quantos.
 * @param {string} [kAttr] só este trecho
 */
async function aceitarSugestoesDeRua(kAttr) {
  const alvo = kAttr ? [ruaState.porK.get(decodeURIComponent(kAttr))].filter(t => t?.sugestao) : ruaState.trechos.filter(t => t.sugestao)
  if (!alvo.length) return
  let listas
  try {
    const bairros = [...new Set(alvo.map(t => t.bairro))]
    listas = new Map(await Promise.all(bairros.map(async b => [b, await _listaDoBairro(b)])))
  } catch (e) { toast(e.message, 'err'); return }
  const naLista = t => {
    const l = listas.get(t.bairro)
    return l.livre || l.logradouros.some(n => _chaveDaRua(n) === _chaveDaRua(t.sugestao))
  }
  const ok = alvo.filter(naLista)
  const fora = alvo.length - ok.length
  if (!ok.length) { toast('O nome sugerido não está na lista do cadastro do bairro. Escolha à mão.', 'aviso'); return }
  await _enviarLoteRuas({ acao: 'nome', itens: ok.map(t => ({ ..._itemDoTrecho(t), nome: t.sugestao })) },
    fora ? ` ${fora} ${fora === 1 ? 'ficou' : 'ficaram'} de fora: o nome não está na lista do bairro.` : '')
}

const _selecionados = () => [...ruaState.selecao].map(k => ruaState.porK.get(k)).filter(Boolean)
const _itemDoTrecho = t => ({ id: t.t.id ?? null, bairro: t.bairro, de: t.t.de, ate: t.t.ate })

async function _enviarLoteRuas(corpo, extra = '') {
  if (ruaState.gravando) return
  ruaState.gravando = true
  pintarMesaRuas()
  try {
    const r = await fetch('/api/ruas/manuais/lote', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: JSON.stringify(corpo),
    })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'Não foi possível gravar o nome de rua.')
    for (const [bairro, ruas] of Object.entries(d.ruas || {})) atualizarRuasDoBairro(bairro, ruas)
    ruaState.novo = null
    ruaState.selecao.clear()
    ruaState.ultimo = null
    ruaState.escolha = null
    toast(d.message + extra, 'ok')
  } catch (e) {
    toast(e.message, 'err')
  } finally {
    ruaState.gravando = false
    aoMudarRuas()
  }
}

// ══════ BUSCAR NO OPENSTREETMAP ══════

/** Os sem nome à vista, sem sugestão e ainda não procurados. */
function _semNomeParaBuscar() {
  const vista = mapaState.obj?.getBounds().pad(0.1)
  return ruaState.trechos.filter(t => t.t.origem === 'sem_nome' && !t.sugestao && !ruaState.osm.has(t.k)
    && vista?.contains(L.latLngBounds([t.t.de, t.t.ate]).getCenter()))
}

/** O nome sem o tipo ("R.", "AV."…): o cadastro às vezes não o traz, o OSM sempre traz. */
const _nucleoDaRua = nome => _chaveDaRua(nome).replace(/^(R|AV|TV|ALAM|ROD|EST|PÇ|PC)\.?\s+/, '')

/**
 * A via do OSM que corre junto do trecho: paralela (até 20°) e com o meio do
 * trecho a até 18 m dela. O trecho fica no meio da rua (7 m da quadra), como
 * a linha do OSM; 18 m cobre avenida larga e desenho um pouco deslocado.
 * @param {Object} t trecho indexado @param {Array} segs [{a,b,ux,uy,len,nome}] em metros
 */
function _viaDoOsmNoTrecho(t, segs) {
  const m = [(t.a[0] + t.b[0]) / 2, (t.a[1] + t.b[1]) / 2]
  let melhor = null, dist = 18
  for (const s of segs) {
    if (Math.abs(s.ux * t.ux + s.uy * t.uy) < RUA_ANGULO_COS) continue
    const k = Math.max(0, Math.min(s.len, (m[0] - s.a[0]) * s.ux + (m[1] - s.a[1]) * s.uy))
    const d = Math.hypot(m[0] - (s.a[0] + s.ux * k), m[1] - (s.a[1] + s.uy * k))
    if (d < dist) { dist = d; melhor = s.nome }
  }
  return melhor
}

/**
 * Procura no OpenStreetMap o nome dos trechos sem nome à vista. O nome achado
 * é conferido com a lista do cadastro do bairro: batendo, vira sugestão (com a
 * grafia do cadastro); não batendo, fica só como pista. Nada é gravado.
 */
async function buscarNomesNoOsm() {
  const alvo = _semNomeParaBuscar()
  if (!alvo.length || ruaState.buscandoOsm) return
  const lats = alvo.flatMap(t => [t.t.de[0], t.t.ate[0]]), lngs = alvo.flatMap(t => [t.t.de[1], t.t.ate[1]])
  const caixa = [Math.min(...lats) - 0.0005, Math.min(...lngs) - 0.0005, Math.max(...lats) + 0.0005, Math.max(...lngs) + 0.0005]
  if (caixa[2] - caixa[0] > 0.08 || caixa[3] - caixa[1] > 0.08) {
    toast('Área grande demais para buscar de uma vez. Aproxime o mapa.', 'aviso')
    return
  }
  ruaState.buscandoOsm = true
  pintarMesaRuas()
  try {
    const r = await fetch('/api/ruas/osm', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: JSON.stringify({ caixa }),
    })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'Não foi possível consultar o OpenStreetMap.')

    const segs = []
    for (const v of d.vias || []) {
      for (let i = 1; i < v.pontos.length; i++) {
        const a = _m(v.pontos[i - 1]), b = _m(v.pontos[i])
        const len = Math.hypot(b[0] - a[0], b[1] - a[1])
        if (len > 0.5) segs.push({ a, b, len, ux: (b[0] - a[0]) / len, uy: (b[1] - a[1]) / len, nome: v.nome })
      }
    }
    const listas = new Map(await Promise.all([...new Set(alvo.map(t => t.bairro))].map(async b => [b, await _listaDoBairro(b)])))

    let sugeridos = 0, pistas = 0, nada = 0
    for (const t of alvo) {
      const osm = _viaDoOsmNoTrecho(t, segs)
      if (!osm) { ruaState.osm.set(t.k, { nome: null, osm: null }); nada++; continue }
      const lista = listas.get(t.bairro)?.logradouros || []
      const doCadastro = lista.find(n => _chaveDaRua(n) === _chaveDaRua(osm))
        || lista.find(n => _nucleoDaRua(n) === _nucleoDaRua(osm))
      ruaState.osm.set(t.k, { nome: doCadastro || null, osm })
      doCadastro ? sugeridos++ : pistas++
    }
    const partes = [`${sugeridos} ${sugeridos === 1 ? 'sugestão' : 'sugestões'}`]
    if (pistas) partes.push(`${pistas} com nome fora da lista do cadastro`)
    if (nada) partes.push(`${nada} sem nome lá também`)
    toast('OpenStreetMap: ' + partes.join(', ') + '.', sugeridos ? 'ok' : 'aviso')
    if (sugeridos) ruaState.filtro = 'sugestoes'
  } catch (e) {
    toast(e.message, 'err')
  } finally {
    ruaState.buscandoOsm = false
    aoMudarRuas()
  }
}

// ══════ DESENHAR UMA RUA (sem lote de frente) ══════

function comecarDesenhoRua() {
  ruaState.selecao.clear()
  ruaState.novo = null
  ruaState.desenhando = { pontos: [] }
  mapaState.obj.doubleClickZoom.disable()
  document.getElementById('map')?.classList.add('ruas-desenhando')
  _pintarRealceRuas()
  pintarMesaRuas()
}

/** @param {boolean} [calado] ao fechar a ferramenta ou concluir, sem repintar */
function _cancelarDesenhoRua(calado) {
  if (ruaState.desenhando) {
    // Na volta do laço: o duplo clique que conclui ainda vai chegar ao mapa.
    setTimeout(() => mapaState.obj?.doubleClickZoom.enable(), 300)
    document.getElementById('map')?.classList.remove('ruas-desenhando')
  }
  ruaState.desenhando = null
  if (!calado && ruaState.ativa) { _pintarRealceRuas(); pintarMesaRuas() }
}

function desfazerPontoRua() {
  ruaState.desenhando?.pontos.pop()
  _pintarRealceRuas()
  pintarMesaRuas()
}

function descartarRuaNova() {
  ruaState.novo = null
  _pintarRealceRuas()
  pintarMesaRuas()
}

/** A ponta perto (12 px) de uma rua existente encaixa nela. @param {L.LatLng} p */
function _encaixarRua(p) {
  const mapa = mapaState.obj
  const tela = mapa.latLngToContainerPoint(p)
  let melhor = null, dist = 12
  for (const x of ruaState.trechos) {
    for (const q of [x.t.de, x.t.ate]) {
      const d = mapa.latLngToContainerPoint(q).distanceTo(tela)
      if (d < dist) { dist = d; melhor = L.latLng(q[0], q[1]) }
    }
  }
  return melhor || p
}

/** @param {L.LeafletMouseEvent} e */
function _cliqueNoMapaRuas(e) {
  const d = ruaState.desenhando
  if (!d) return   // fora do desenho, o clique no vazio não desfaz nada
  const p = _encaixarRua(e.latlng)
  const ultimo = d.pontos.at(-1)
  // O duplo clique chega antes como dois cliques: o segundo, no mesmo lugar, não é ponto.
  if (ultimo && mapaState.obj.latLngToContainerPoint(ultimo).distanceTo(mapaState.obj.latLngToContainerPoint(p)) < 4) return
  d.pontos.push(p)
  _pintarRealceRuas()
  pintarMesaRuas()
}

function _duploCliqueRuas() {
  if (ruaState.desenhando?.pontos.length >= 2) concluirDesenhoRua()
}

/** Cada par de pontos vira um trecho, no bairro em que está. */
function concluirDesenhoRua() {
  const d = ruaState.desenhando
  if (!d || d.pontos.length < 2) return
  const r = n => Number(n.toFixed(7))
  const segs = []
  for (let i = 1; i < d.pontos.length; i++) {
    const a = d.pontos[i - 1], b = d.pontos[i]
    const meio = L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2)
    const bairro = _bairroDoPonto(meio) || _bairroDoPonto(a) || _bairroDoPonto(b)
    if (!bairro) { toast(`O segmento ${i} está fora do contorno dos bairros. Desfaça o ponto e refaça.`, 'err'); return }
    segs.push({ bairro, de: [r(a.lat), r(a.lng)], ate: [r(b.lat), r(b.lng)] })
  }
  _cancelarDesenhoRua(true)
  ruaState.novo = segs
  ruaState.escolha = null
  _pintarRealceRuas()
  pintarMesaRuas()
}

/** O bairro cujo contorno contém o ponto (nome do desenho), ou null. @param {L.LatLng} p */
function _bairroDoPonto(p) {
  let achado = null
  contornoState.camada?.eachLayer(c => {
    if (achado || !c.getBounds().contains(p)) return
    // getLatLngs de multipolígono: [[anel externo, furos...], ...]; de polígono: [anel, furos...].
    const ll = c.getLatLngs()
    const poligonos = Array.isArray(ll[0]?.[0]) ? ll : [ll]
    if (poligonos.some(pol => _dentroDoAnel(p, pol[0]) && !pol.slice(1).some(furo => _dentroDoAnel(p, furo)))) {
      achado = c.feature?.properties?.nome ?? null
    }
  })
  return achado
}

/** Ponto dentro do anel (raio cruzando as arestas). */
function _dentroDoAnel(p, anel) {
  let dentro = false
  for (let i = 0, j = anel.length - 1; i < anel.length; j = i++) {
    const a = anel[i], b = anel[j]
    if ((a.lat > p.lat) !== (b.lat > p.lat)
        && p.lng < (b.lng - a.lng) * (p.lat - a.lat) / (b.lat - a.lat) + a.lng) dentro = !dentro
  }
  return dentro
}
