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
 *  - ENCOSTAR PONTAS (24/11/2026): a ponta de um trecho é levada até o outro
 *    trecho, sem fundir os dois (cada um fica com o seu nome). Ver
 *    comecarEncostar e RuaManualController::encostar.
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
  /** @type {boolean} administrador digitando um nome fora da lista */ nomeLivre: false,
  /** @type {{pontos: L.LatLng[]}|null} rua sendo desenhada */ desenhando: null,
  /** @type {Array<{bairro:string, de:number[], ate:number[]}>|null} desenhada, esperando nome */ novo: null,
  /**
   * Encostar pontas: a ponta escolhida (o trecho e qual ponta, 0 = de, 1 = até)
   * e o ponto onde ela encostaria agora, sob o cursor.
   * @type {{ponta: ?{x: Object, e: number}, alvo: ?{p: number[], tipo: string}}|null}
   */
  encostando: null,
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

const COR_TRECHO = { cadastro: '#16a34a', manual: '#2563eb', sem_nome: '#dc2626', oculto: '#9ca3af', excluido: '#6b7280', sugestao: '#d97706', novo: '#2563eb' }
const ROTULO_ORIGEM = { cadastro: 'cadastro', manual: 'à mão', sem_nome: 'sem nome', oculto: 'oculto', excluido: 'excluído' }

// Continuação de rua, em metros. Ver _continua.
const RUA_ANGULO_COS = Math.cos(20 * Math.PI / 180)
const RUA_FOLGA_PONTA_M = 35     // a largura da rua transversal, com sobra
const RUA_DESVIO_M = 10          // fora da reta, na emenda
const RUA_LADOS_M = 22           // os dois lados da mesma rua (divisa de bairros)
const RUA_LISTA_MAX = 150

FERRAMENTAS_MAPA.ruas = {
  rotulo: 'nomes de rua',
  aberta: () => ruaState.ativa,
  emCurso: () => ruaState.desenhando?.pontos.length || ruaState.novo ? 'uma rua desenhada sem nome'
    : ruaState.encostando?.ponta ? 'uma ponta escolhida para encostar' : null,
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
    mapa.on('mousemove', _moverEncostar)
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
  _sairEncostar(true)
  ruaState.novo = null
  ruaState.selecao.clear()
  ruaState.camada?.remove()
  ruaState.realce?.remove()
  ruaState.camada = ruaState.realce = null
  mapa?.off('click', _cliqueNoMapaRuas)
  mapa?.off('dblclick', _duploCliqueRuas)
  mapa?.off('moveend', _aoMoverRuas)
  mapa?.off('mousemove', _moverEncostar)
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

// ══════ COSTURA: AS LINHAS SE ENCONTRAM ══════
//
// O trecho gerado é um segmento reto por lado de quadra, cortado onde acabam
// os lotes: sobrava buraco em toda esquina, e a curva da avenida virava
// pedaços soltos (22/11/2026). A costura é só do DESENHO, feita na hora com o
// que está na tela — o gravado não muda, o "Gerar" não precisa ser refeito e
// vale também entre bairros vizinhos.
//  1. Ponta com ponta: as duas pontas mais próximas que se olham se encontram.
//     Em linha, no meio do caminho; dobrando pouco (curva da rua), por uma
//     curva suave que passa pelo cruzamento das duas retas, metade para cada
//     trecho; dobrando muito (esquina), no cruzamento das retas.
//  2. Ponta solta que chega numa rua transversal (o "T") vai até ela.

const COSTURA_ALCANCE_M = 40   // até onde uma ponta vai buscar a outra
const COSTURA_RECUO_M = 8      // a ponta que passou do cruzamento pode voltar até isto
const COSTURA_ALINHA_M = 6     // em linha: fora da reta no máximo isto
const COSTURA_RETA_COS = Math.cos(8 * Math.PI / 180)
const COSTURA_CURVA_GRAUS = 65 // dobra até isto é curva da rua; acima, esquina
const COSTURA_T_COS = Math.cos(30 * Math.PI / 180)
const COSTURA_EMENDA_M = 25    // reserva da emenda: pontas a até isto
const COSTURA_EMENDA_GRAUS = 30 // e dobrando até isto
const _llCostura = p => [+(p[1] / 110574).toFixed(7), +(p[0] / (111320 * _COS_LAT)).toFixed(7)]

/**
 * O segmento p→q (o esticão de uma ponta) atravessa algum trecho que não seja
 * os dois do par? Só conta o cruzamento por dentro dos dois segmentos: tocar
 * na ponta não é atravessar.
 */
function _cortaOutro(p, q, t1, t2, vivos) {
  const len = Math.hypot(q[0] - p[0], q[1] - p[1])
  if (len < 1) return false
  const minX = Math.min(p[0], q[0]), maxX = Math.max(p[0], q[0]), minY = Math.min(p[1], q[1]), maxY = Math.max(p[1], q[1])
  const lado = (a, b, c) => (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0])
  for (const u of vivos) {
    if (u === t1 || u === t2) continue
    if (Math.max(u.a[0], u.b[0]) < minX || Math.min(u.a[0], u.b[0]) > maxX
      || Math.max(u.a[1], u.b[1]) < minY || Math.min(u.a[1], u.b[1]) > maxY) continue
    const d1 = lado(p, q, u.a), d2 = lado(p, q, u.b), d3 = lado(u.a, u.b, p), d4 = lado(u.a, u.b, q)
    // Folga de ~0,5 m de cada lado: encostar não é cruzar.
    const fp = 0.5 * len, fu = 0.5 * u.len
    if (((d1 > fp && d2 < -fp) || (d1 < -fp && d2 > fp)) && ((d3 > fu && d4 < -fu) || (d3 < -fu && d4 > fu))) return true
  }
  return false
}

/** Preenche `t.linha` (lat,lng) de cada trecho, já com as pontas costuradas. */
function _costurar(trechos) {
  const vivos = trechos.filter(t => t.t.origem !== 'excluido')
  for (const t of trechos) t.linha = [t.t.de, t.t.ate]
  const pontas = []
  for (const t of vivos) {
    t.costura = [{ p: t.a, extra: [] }, { p: t.b, extra: [] }]
    pontas.push({ t, e: 0, p: t.a, d: [-t.ux, -t.uy] }, { t, e: 1, p: t.b, d: [t.ux, t.uy] })
  }
  const CEL = 50
  const grade = new Map()
  pontas.forEach((P, i) => {
    const c = Math.floor(P.p[0] / CEL) + ':' + Math.floor(P.p[1] / CEL)
    ;(grade.get(c) || grade.set(c, []).get(c)).push(i)
  })

  // 1. Pares de pontas, do mais barato ao mais caro.
  const pares = []
  const nomesCombinam = (a, b) => !a.chave || !b.chave || a.chave === b.chave
  pontas.forEach((E, i) => {
    const cx = Math.floor(E.p[0] / CEL), cy = Math.floor(E.p[1] / CEL)
    for (let dx = -1; dx <= 1; dx++) for (let dy = -1; dy <= 1; dy++) {
      for (const j of grade.get((cx + dx) + ':' + (cy + dy)) || []) {
        const F = pontas[j]
        if (j <= i || F.t === E.t) continue
        const v = [F.p[0] - E.p[0], F.p[1] - E.p[1]], dist = Math.hypot(v[0], v[1])
        if (dist > COSTURA_ALCANCE_M) continue
        const cos = E.d[0] * F.d[0] + E.d[1] * F.d[1]
        const giro = Math.acos(Math.max(-1, Math.min(1, -cos))) * 180 / Math.PI
        // Reserva: as duas pontas se olham, a rua quase não dobra e estão
        // perto, mas uma está deslocada para o lado — nem "em linha" (passa de
        // 6 m fora da reta) nem curva (o cruzamento cai para trás). Encontram-se
        // no meio; antes ficava o buraco (Av. Dom Aquino, 23/11/2026).
        const seOlham = v[0] * E.d[0] + v[1] * E.d[1] >= -COSTURA_RECUO_M && -(v[0] * F.d[0] + v[1] * F.d[1]) >= -COSTURA_RECUO_M
        const emenda = () => {
          if (giro <= COSTURA_EMENDA_GRAUS && seOlham && dist <= COSTURA_EMENDA_M && nomesCombinam(E.t, F.t)) {
            pares.push({ E, F, tipo: 'emenda', custo: dist + 6 })
          }
        }
        if (cos < -COSTURA_RETA_COS) {
          // De frente, em linha: a rua continua do outro lado do cruzamento.
          const ao = v[0] * E.d[0] + v[1] * E.d[1], fora = Math.abs(v[0] * E.d[1] - v[1] * E.d[0])
          if (ao >= -COSTURA_RECUO_M && fora <= COSTURA_ALINHA_M) pares.push({ E, F, tipo: 'reta', custo: dist })
          else emenda()
          continue
        }
        // O cruzamento das duas retas: E.p + s·E.d = F.p + r·F.d
        const det = -E.d[0] * F.d[1] + E.d[1] * F.d[0]
        if (Math.abs(det) < 1e-6) continue
        const s = (-v[0] * F.d[1] + v[1] * F.d[0]) / det
        const r = (E.d[0] * v[1] - E.d[1] * v[0]) / det
        if (s < -COSTURA_RECUO_M || r < -COSTURA_RECUO_M || s > COSTURA_ALCANCE_M || r > COSTURA_ALCANCE_M) { emenda(); continue }
        // Esticar até o cruzamento não pode atravessar outra linha: virava um
        // "X" com o outro lado da avenida (Q 85, 23/11/2026).
        const X = [E.p[0] + E.d[0] * s, E.p[1] + E.d[1] * s]
        if (_cortaOutro(E.p, X, E.t, F.t, vivos) || _cortaOutro(F.p, X, E.t, F.t, vivos)) { emenda(); continue }
        const curva = giro <= COSTURA_CURVA_GRAUS && s >= 0 && r >= 0 && nomesCombinam(E.t, F.t)
        // Num cruzamento, a rua que segue reta ganha da que dobra; e a que dobra
        // continuando o MESMO nome ganha da transversal — senão cada pedaço da
        // curva ia para a esquina com a travessa e a rua não se encontrava
        // (R. Maria Beer, 22/11/2026).
        const mesmoNome = E.t.chave && E.t.chave === F.t.chave
        const extra = mesmoNome ? 4 : curva ? 8 : 16
        pares.push({ E, F, s, tipo: curva ? 'curva' : 'esquina', custo: Math.max(s, 0) + Math.max(r, 0) + extra })
      }
    }
  })
  pares.sort((a, b) => a.custo - b.custo)
  const usada = new Set(), chaveP = P => P.t.k + '#' + P.e
  for (const { E, F, s, tipo } of pares) {
    if (usada.has(chaveP(E)) || usada.has(chaveP(F))) continue
    usada.add(chaveP(E)); usada.add(chaveP(F))
    if (tipo === 'emenda') {
      // Cada trecho fica reto; um pedacinho em diagonal liga os dois. Antes
      // as duas pontas RECUAM: na curva os pedaços se sobrepõem (o fim de um
      // passa do começo do outro, ao lado), e ligar direto fazia um "Z" que
      // voltava para trás (Av. Dom Aquino, 23/11/2026). O recuo deixa a
      // diagonal andar para a frente pelo menos o dobro do deslocamento.
      const v = [F.p[0] - E.p[0], F.p[1] - E.p[1]]
      const avanco = v[0] * E.d[0] + v[1] * E.d[1]
      const lado = Math.abs(v[0] * E.d[1] - v[1] * E.d[0])
      const recuo = Math.max(0, (Math.max(2 * lado, 4) - avanco) / 2)
      const a = Math.min(recuo, E.t.len * 0.3), b = Math.min(recuo, F.t.len * 0.3)
      const pe = [E.p[0] - E.d[0] * a, E.p[1] - E.d[1] * a]
      const pf = [F.p[0] - F.d[0] * b, F.p[1] - F.d[1] * b]
      const m = [(pe[0] + pf[0]) / 2, (pe[1] + pf[1]) / 2]
      E.t.costura[E.e].p = pe; F.t.costura[F.e].p = pf
      E.t.costura[E.e].extra.push(m); F.t.costura[F.e].extra.push(m)
      continue
    }
    if (tipo === 'reta') {
      const m = [(E.p[0] + F.p[0]) / 2, (E.p[1] + F.p[1]) / 2]
      E.t.costura[E.e].p = m; F.t.costura[F.e].p = m
      continue
    }
    const X = [E.p[0] + E.d[0] * s, E.p[1] + E.d[1] * s]
    if (tipo === 'esquina') { E.t.costura[E.e].p = X; F.t.costura[F.e].p = X; continue }
    // Curva: Bézier de E.p a F.p puxada pelo cruzamento X; cada trecho leva a sua metade.
    const n = Math.max(4, Math.ceil((Math.hypot(X[0] - E.p[0], X[1] - E.p[1]) + Math.hypot(X[0] - F.p[0], X[1] - F.p[1])) / 4))
    const B = u => [0, 1].map(c => (1 - u) * (1 - u) * E.p[c] + 2 * u * (1 - u) * X[c] + u * u * F.p[c])
    const meio = Math.floor(n / 2)
    for (let k = 1; k <= meio; k++) E.t.costura[E.e].extra.push(B(k / n))
    for (let k = n - 1; k >= meio; k--) F.t.costura[F.e].extra.push(B(k / n))
  }

  // 2. O "T": a ponta que sobrou vai até a rua transversal à frente (ou volta
  //    até ela, se passou um pouco).
  for (const E of pontas) {
    if (usada.has(chaveP(E))) continue
    let melhor = null
    for (const u of vivos) {
      if (u === E.t || Math.abs(E.d[0] * u.ux + E.d[1] * u.uy) > COSTURA_T_COS) continue
      // A transversal já costurada: o "T" que cai no encontro de dois pedaços
      // (a curva da avenida) também acha a rua.
      const ua = u.costura[0].p, ub = u.costura[1].p
      const ulen = Math.hypot(ub[0] - ua[0], ub[1] - ua[1])
      if (ulen < 0.5) continue
      const ux = (ub[0] - ua[0]) / ulen, uy = (ub[1] - ua[1]) / ulen
      const v = [ua[0] - E.p[0], ua[1] - E.p[1]]
      const det = -E.d[0] * uy + E.d[1] * ux
      if (Math.abs(det) < 1e-6) continue
      const s = (-v[0] * uy + v[1] * ux) / det
      const w = (E.d[0] * v[1] - E.d[1] * v[0]) / det
      if (s < -COSTURA_RECUO_M || s > COSTURA_ALCANCE_M || w < -3 || w > ulen + 3) continue
      if (melhor === null || Math.abs(s) < Math.abs(melhor)) melhor = s
    }
    if (melhor !== null) E.t.costura[E.e].p = [E.p[0] + E.d[0] * melhor, E.p[1] + E.d[1] * melhor]
  }

  for (const t of vivos) {
    const [c0, c1] = t.costura
    // A ponta recuada não pode atravessar a outra: aí fica o trecho como veio.
    const ok = (c1.p[0] - c0.p[0]) * t.ux + (c1.p[1] - c0.p[1]) * t.uy > 1
    t.linha = (ok ? [...[...c0.extra].reverse(), c0.p, c1.p, ...c1.extra] : [t.a, t.b]).map(_llCostura)
    delete t.costura
  }
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

  _costurar(trechos)
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
    // O excluído só aparece no filtro À mão, onde se desfaz.
    if (o === 'excluido' && ruaState.filtro !== 'manual') continue
    const linha = L.polyline(x.linha, {
      pane: 'ruasEdicao', renderer: ruaState.renderer,
      color: COR_TRECHO[o] || '#666', weight: o === 'cadastro' ? 3 : o === 'excluido' ? 2.5 : 3.5,
      opacity: o === 'cadastro' ? .55 : o === 'excluido' ? .7 : .9,
      dashArray: ['sem_nome', 'oculto', 'sugestao'].includes(o) ? '7 5' : o === 'excluido' ? '2 6' : null,
    })
    linha.on('click', e => {
      // Desenhando ou encostando, o toque é ponto: deixa passar para o mapa.
      if (ruaState.desenhando || ruaState.encostando) return
      L.DomEvent.stopPropagation(e)
      _clicarTrecho(x.k, !!e.originalEvent?.shiftKey)
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

/**
 * Um clique seleciona a RUA inteira (o grupo); clicar de novo nela tira. Soma às outras.
 * Com SHIFT, só o trecho clicado (24/11/2026): cada Shift+clique põe ou tira
 * um trecho, e a seleção vira uma composição de trechos.
 * @param {string} k @param {boolean} [soTrecho] Shift pressionado
 */
function _clicarTrecho(k, soTrecho = false) {
  const x = ruaState.porK.get(k)
  if (!x) return
  ruaState.novo = null
  if (soTrecho) {
    if (ruaState.selecao.has(k)) { ruaState.selecao.delete(k); ruaState.ultimo = null }
    else { ruaState.selecao.add(k); ruaState.ultimo = k }
    ruaState.escolha = null; ruaState.nomeLivre = false
    _pintarRealceRuas()
    pintarMesaRuas()
    return
  }
  const grupo = ruaState.trechos.filter(t => t.grupo === x.grupo)
  const todos = grupo.every(t => ruaState.selecao.has(t.k))
  for (const t of grupo) todos ? ruaState.selecao.delete(t.k) : ruaState.selecao.add(t.k)
  ruaState.ultimo = todos ? null : k
  ruaState.escolha = null; ruaState.nomeLivre = false
  _pintarRealceRuas()
  pintarMesaRuas()
}

/** Seleciona trechos pela lista do painel, e leva o mapa até eles. @param {Object[]} lista */
function _selecionarELevar(lista) {
  if (!lista.length) return
  ruaState.novo = null
  ruaState.selecao = new Set(lista.map(t => t.k))
  ruaState.ultimo = lista[0].k
  ruaState.escolha = null; ruaState.nomeLivre = false
  const caixa = L.latLngBounds(lista.flatMap(t => [t.t.de, t.t.ate]))
  mapaState.obj.fitBounds(caixa, { padding: [80, 80], maxZoom: 19 })
  _pintarRealceRuas()
  pintarMesaRuas()
}

function limparSelecaoRuas() {
  ruaState.selecao.clear()
  ruaState.ultimo = null
  ruaState.escolha = null; ruaState.nomeLivre = false
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
  // Selecionado: amarelo com borda preta, como marca-texto — o azul é do
  // editado à mão e confundia (22/11/2026).
  for (const k of ruaState.selecao) {
    const x = ruaState.porK.get(k)
    if (!x) continue
    r.addLayer(L.polyline(x.linha, { ...op, color: '#111827', weight: 9, opacity: .9, lineCap: 'round', lineJoin: 'round' }))
    r.addLayer(L.polyline(x.linha, { ...op, color: '#facc15', weight: 5, opacity: 1, lineCap: 'round', lineJoin: 'round' }))
  }
  for (const s of ruaState.novo || []) halo([s.de, s.ate], COR_TRECHO.novo)
  _realceEncostar(r, op)
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
  if (ruaState.encostando) {
    // Com a ponta escolhida, o Esc a larga; sem ela, sai do encostar.
    if (ruaState.encostando.ponta) { ruaState.encostando = { ponta: null, alvo: null }; _pintarRealceRuas(); pintarMesaRuas() } else _sairEncostar()
    return
  }
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
    manual: ts.filter(t => ['manual', 'oculto', 'excluido'].includes(t.t.origem)).length,
  }
}

const _metros = n => fmtBR(Math.round(n), 0) + ' m'
const _kAttr = k => encodeURIComponent(k)

function pintarMesaRuas() {
  const corpo = document.getElementById('ruas-mesa-corpo')
  if (!corpo || !ruaState.ativa) return
  const n = _contagensRuas()
  const partes = []

  if (ruaState.encostando) partes.push(_htmlEncostando())
  else if (ruaState.desenhando) partes.push(_htmlDesenhando())
  else if (ruaState.novo) partes.push(_htmlRuaNova())
  else if (ruaState.selecao.size) partes.push(_htmlSelecao())
  else partes.push('<div class="ruas-nota">Clique numa rua no mapa: ela é selecionada inteira. Outro clique soma outra rua. Com Shift, cada clique põe ou tira um trecho só. Esc limpa.</div>')

  // Também na aba Sugestões: lá o botão em bloco fazia falta (23/11/2026).
  if (n.sugestoes && !ruaState.desenhando && !ruaState.novo) {
    partes.push(`<div class="ruas-sugere">
      <b>${n.sugestoes} ${n.sugestoes === 1 ? 'trecho sem nome tem' : 'trechos sem nome têm'} sugestão de nome.</b>
      <div class="ruas-botoes">
        <button type="button" class="btn sm primary" onclick="aceitarSugestoesDeRua()" ${ruaState.gravando ? 'disabled' : ''}>Aceitar ${n.sugestoes === 1 ? 'a sugestão' : 'as ' + n.sugestoes}</button>
        ${ruaState.filtro !== 'sugestoes' ? `<button type="button" class="btn sm" onclick="filtrarRuas('sugestoes')">Ver uma a uma</button>` : ''}
      </div></div>`)
  }

  // A busca no OpenStreetMap: para os sem nome À VISTA que ainda não têm
  // sugestão nem foram procurados.
  if (!ruaState.desenhando && !ruaState.novo) {
    const faltam = _semNomeParaBuscar().length
    // Todos os da tela já procurados: o botão vira "Buscar de novo" (a cópia
    // do OpenStreetMap pode ter sido atualizada desde a última busca, 23/11/2026).
    const deNovo = !faltam && _semNomeParaBuscar(true).length
    if (faltam || deNovo || ruaState.buscandoOsm) {
      partes.push(`<div class="ruas-busca">
        <button type="button" class="btn sm" onclick="buscarNomesNoOsm(${!faltam})" ${ruaState.buscandoOsm ? 'disabled' : ''}>
          ${ruaState.buscandoOsm ? 'Buscando no OpenStreetMap…' : faltam ? `Buscar nomes que faltam (${faltam} na tela)` : `Buscar de novo no OpenStreetMap (${deNovo} na tela)`}</button>
        <span class="ruas-nota">Consulta o OpenStreetMap; nada é gravado até você aceitar.</span></div>`)
    }
  }

  const filtros = [['todas', 'Ruas'], ['sem_nome', 'Sem nome'], ['sugestoes', 'Sugestões'], ['manual', 'À mão']]
  partes.push(`<div class="seg ruas-filtros">${filtros.map(([f, r]) =>
    `<button type="button" class="${ruaState.filtro === f ? 'at' : ''}" onclick="filtrarRuas('${f}')">${r}<span class="ruas-n">${n[f]}</span></button>`).join('')}</div>`)
  partes.push(`<div class="ruas-lista">${_htmlListaRuas()}</div>`)

  if (!ruaState.desenhando && !ruaState.novo && !ruaState.encostando) {
    partes.push(`<div class="ruas-botoes ruas-ferramentas">
      <button type="button" class="btn sm ruas-desenhar" onclick="comecarDesenhoRua()"
        title="Rua sem lote de frente: rodovia, avenida ao lado de área verde">Desenhar rua</button>
      <button type="button" class="btn sm ruas-desenhar" onclick="comecarEncostar()"
        title="Leva a ponta de um trecho até o outro, sem juntar os dois">Encostar pontas</button></div>`)
  }
  // ODbL: o nome que veio de lá cita a fonte.
  if (ruaState.trechos.some(t => t.fonte === 'osm' || t.pista)) {
    partes.push('<div class="ruas-nota">Nomes sugeridos pelo OpenStreetMap: © colaboradores do OpenStreetMap.</div>')
  }
  corpo.innerHTML = partes.join('')
  _preencherComboRuas()
}

function filtrarRuas(f) {
  const redesenha = (f === 'manual') !== (ruaState.filtro === 'manual')
  ruaState.filtro = f
  // Entrar ou sair do À mão mostra ou esconde os excluídos no mapa.
  if (redesenha) aoMudarRuas()
  else pintarMesaRuas()
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
          : sel.every(t => t.t.origem === 'excluido') ? '<span class="ruas-sem">excluído</span>'
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
      <button type="button" class="btn sm" onclick="ocultarRuaSelecionada()" title="Rótulo errado e sem nome melhor: o nome some do mapa, a linha fica">Ocultar</button>
      ${sel.some(t => t.t.origem !== 'excluido') ? '<button type="button" class="btn sm danger" onclick="excluirRuaSelecionada()" title="Não é rua: a linha e o nome somem do mapa">Excluir</button>' : ''}
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
    itens = ts.filter(t => ['manual', 'oculto', 'excluido'].includes(t.t.origem)).map(t =>
      linha('o-' + t.t.origem, t.t.origem === 'oculto' ? 'Oculto' : t.t.origem === 'excluido' ? 'Excluído' : esc(t.t.nome),
        `${ROTULO_ORIGEM[t.t.origem]} · ${esc(t.bairro)}`, doTrecho(t)))
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
  // Para quem pode nome livre, o atual fora da lista (sugestão do OSM) entra
  // como opção, para o combo abrir com ele escolhido.
  const temLista = todos.length > 0
  if (livre && temLista && atual && !todos.includes(atual)) todos.unshift(atual)
  const opcoes = todos.map(n => `<option value="${esc(n)}"${n === atual ? ' selected' : ''}>${esc(n)}</option>`).join('')
  // Havendo lista, é sempre o combobox padrão do sistema (data-combo, ui.js).
  // Livre (administrador, ou bairro sem cadastro amarrado) pode ainda digitar
  // um nome fora dela pelo "Nome fora da lista"; sem lista nenhuma, só texto.
  // Em 56465ac o livre virou texto puro e o administrador perdeu o combo (23/11/2026).
  const texto = `<input type="text" id="rua-nome" value="${esc(atual)}" placeholder="Nome do logradouro" maxlength="180" autocomplete="off" oninput="ruaState.escolha=this.value">`
  caixa.innerHTML = !temLista || ruaState.nomeLivre
    ? texto + (temLista ? '<button type="button" class="hist-voltar" onclick="_nomeForaDaLista(false)">Escolher da lista</button>' : '')
    : `<select id="rua-nome" data-combo onchange="ruaState.escolha=this.value"><option value="">Escolha o logradouro…</option>${opcoes}</select>`
      + (livre ? '<button type="button" class="hist-voltar" onclick="_nomeForaDaLista(true)">Nome fora da lista</button>' : '')
  const combo = caixa.querySelector('select[data-combo]')
  if (combo && typeof comboDeSelect === 'function') comboDeSelect(combo)
  if (ruaState.nomeLivre) caixa.querySelector('input')?.focus()
  const aviso = document.getElementById('rua-nome-aviso')
  if (aviso) aviso.innerHTML = listas.some(l => !l.cadastro) ? '<div class="rua-aviso">Bairro sem cadastro amarrado: o nome é livre.</div>' : ''
}

/** Troca entre o combo da lista e o campo de texto livre. @param {boolean} sim */
function _nomeForaDaLista(sim) {
  ruaState.nomeLivre = sim
  _preencherComboRuas()
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

/**
 * Exclui a seleção: o trecho que não é rua some, linha e nome. O que veio do
 * cadastro fica guardado no filtro À mão, de onde volta; o desenhado à mão é
 * apagado.
 */
async function excluirRuaSelecionada() {
  const itens = _selecionados().filter(t => t.t.origem !== 'excluido').map(_itemDoTrecho)
  if (!itens.length) { toast('A seleção já está excluída.', 'aviso'); return }
  await _enviarLoteRuas({ acao: 'excluir', itens }, ' Para desfazer: filtro À mão, Voltar ao cadastro.')
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

const RUA_LOTE_MAX = 500   // o limite de itens por pedido no servidor (RuaManualController::lote)

async function _enviarLoteRuas(corpo, extra = '') {
  if (ruaState.gravando) return
  ruaState.gravando = true
  pintarMesaRuas()
  // O servidor aceita até 500 itens por pedido: "Aceitar as 618" vai em
  // partes (23/11/2026). Cada parte grava sozinha; se uma falhar, as
  // anteriores ficam e o aviso diz quantas.
  const blocos = []
  for (let i = 0; i < corpo.itens.length; i += RUA_LOTE_MAX) blocos.push(corpo.itens.slice(i, i + RUA_LOTE_MAX))
  let gravados = 0
  try {
    let d
    for (const itens of blocos) {
      const r = await fetch('/api/ruas/manuais/lote', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
        body: JSON.stringify({ ...corpo, itens }),
      })
      d = await r.json()
      if (!r.ok) {
        throw new Error((d.message || 'Não foi possível gravar o nome de rua.')
          + (gravados ? ` ${gravados} trechos já tinham sido gravados.` : ''))
      }
      gravados += d.n ?? itens.length
      for (const [bairro, ruas] of Object.entries(d.ruas || {})) atualizarRuasDoBairro(bairro, ruas)
    }
    if (blocos.length > 1) d.message = d.message.replace(/\d+/, gravados)
    ruaState.novo = null
    ruaState.selecao.clear()
    ruaState.ultimo = null
    ruaState.escolha = null; ruaState.nomeLivre = false
    toast(d.message + extra, 'ok')
  } catch (e) {
    toast(e.message, 'err')
  } finally {
    ruaState.gravando = false
    aoMudarRuas()
  }
}

// ══════ BUSCAR NO OPENSTREETMAP ══════

/** Os sem nome à vista, sem sugestão e ainda não procurados (ou todos, para buscar de novo). @param {boolean} [deNovo] */
function _semNomeParaBuscar(deNovo = false) {
  const vista = mapaState.obj?.getBounds().pad(0.1)
  return ruaState.trechos.filter(t => t.t.origem === 'sem_nome' && (deNovo ? !t.sugestao || t.fonte === 'osm' : !t.sugestao && !ruaState.osm.has(t.k))
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
/** @param {boolean} [deNovo] procura também os já procurados à vista */
async function buscarNomesNoOsm(deNovo = false) {
  const alvo = _semNomeParaBuscar(deNovo)
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

// ══════ ENCOSTAR PONTAS ══════
//
// O trecho que para antes do outro (a esquina que a costura do desenho não
// fechou): clique na PONTA dele e depois no trecho onde ela deve encostar. A
// ponta vai até lá — na reta do próprio trecho, se ela cruza o outro; senão,
// no ponto do outro mais perto do clique. Os dois continuam trechos
// separados, cada um com o seu nome. Grava na hora (acao "encostar") e fica
// no modo para a próxima ponta; Esc sai.

const ENCOSTAR_PX = 14          // o clique pega a ponta ou o trecho a até isto
const ENCOSTAR_MAX_M = 80       // a ponta não anda mais que isto (o servidor confere)

function comecarEncostar() {
  _cancelarDesenhoRua(true)
  ruaState.novo = null
  ruaState.selecao.clear()
  ruaState.ultimo = null
  ruaState.encostando = { ponta: null, alvo: null }
  document.getElementById('map')?.classList.add('ruas-desenhando')
  _pintarRealceRuas()
  pintarMesaRuas()
}

/** @param {boolean} [calado] ao fechar a ferramenta, sem repintar */
function _sairEncostar(calado) {
  if (!ruaState.encostando) return
  ruaState.encostando = null
  document.getElementById('map')?.classList.remove('ruas-desenhando')
  if (!calado && ruaState.ativa) { _pintarRealceRuas(); pintarMesaRuas() }
}

function _htmlEncostando() {
  const p = ruaState.encostando.ponta
  const passo = p
    ? `Ponta de <b>${esc(p.x.t.nome || 'trecho sem nome')}</b> escolhida. Clique no trecho onde ela deve encostar.`
    : 'Clique na PONTA do trecho que vai andar.'
  return `<div class="ruas-sel">
    <div class="ruas-sel-nome">Encostar pontas</div>
    <div class="ruas-sel-sub">${passo} Os dois trechos continuam separados, cada um com o seu nome. Esc ${p ? 'larga a ponta' : 'sai'}.</div>
    <div class="ruas-botoes">
      ${p ? '<button type="button" class="btn sm" onclick="ruaState.encostando = { ponta: null, alvo: null }; _pintarRealceRuas(); pintarMesaRuas()">Outra ponta</button>' : ''}
      <button type="button" class="btn sm" onclick="_sairEncostar()">Concluir</button>
    </div></div>`
}

/** Distância na tela, em px. */
function _pxEntre(a, b) {
  const m = mapaState.obj
  return m.latLngToContainerPoint(L.latLng(a[0], a[1])).distanceTo(m.latLngToContainerPoint(L.latLng(b[0], b[1])))
}

/** A ponta de trecho mais perto do clique (a desenhada ou a gravada). @param {L.LatLng} ll */
function _pontaPerto(ll) {
  const c = [ll.lat, ll.lng]
  let melhor = null, menor = ENCOSTAR_PX
  for (const x of ruaState.trechos) {
    if (x.t.origem === 'excluido') continue
    for (const e of [0, 1]) {
      for (const p of [e ? x.t.ate : x.t.de, e ? x.linha.at(-1) : x.linha[0]]) {
        const d = _pxEntre(c, p)
        if (d < menor) { menor = d; melhor = { x, e } }
      }
    }
  }
  return melhor
}

/** O pé da perpendicular de p no segmento a–b, em metros. */
function _peNoSegmento(p, a, b) {
  const vx = b[0] - a[0], vy = b[1] - a[1], l2 = vx * vx + vy * vy
  const t = l2 < 1e-9 ? 0 : Math.max(0, Math.min(1, ((p[0] - a[0]) * vx + (p[1] - a[1]) * vy) / l2))
  return [a[0] + vx * t, a[1] + vy * t]
}

/**
 * Onde a ponta escolhida encostaria, com o cursor em "ll":
 *  - numa ponta de outro trecho, a até ENCOSTAR_PX: nela;
 *  - sobre outro trecho: onde a reta do trecho que anda cruza o outro (se
 *    cruza perto); senão, no ponto do outro mais perto do cursor.
 * @param {L.LatLng} ll @returns {?{p: number[], tipo: string}} p em [lat, lon]
 */
function _alvoEncostar(ll) {
  const { x: anda, e } = ruaState.encostando.ponta
  const c = [ll.lat, ll.lng]
  let melhor = null, menor = ENCOSTAR_PX
  for (const x of ruaState.trechos) {
    if (x === anda || x.t.origem === 'excluido') continue
    for (const p of [x.t.de, x.t.ate, x.linha[0], x.linha.at(-1)]) {
      const d = _pxEntre(c, p)
      if (d < menor) { menor = d; melhor = { p, tipo: 'ponta' } }
    }
  }
  if (melhor) return melhor

  // Sobre a linha de outro trecho (o desenho com a costura, e o gravado).
  const cm = _m(c)
  let seg = null
  menor = ENCOSTAR_PX
  for (const x of ruaState.trechos) {
    if (x === anda || x.t.origem === 'excluido') continue
    const linhas = [x.linha.map(_m), [x.a, x.b]]
    for (const pts of linhas) {
      for (let i = 1; i < pts.length; i++) {
        const pe = _peNoSegmento(cm, pts[i - 1], pts[i])
        const d = _pxEntre(c, _llCostura(pe))
        if (d < menor) { menor = d; seg = [pts[i - 1], pts[i]] }
      }
    }
  }
  if (!seg) return null
  // A reta do trecho que anda, da ponta que fica para a que anda.
  const fica = e ? anda.a : anda.b, vai = e ? anda.b : anda.a
  const ux = vai[0] - fica[0], uy = vai[1] - fica[1]
  const sx = seg[1][0] - seg[0][0], sy = seg[1][1] - seg[0][1]
  const det = ux * sy - uy * sx
  const lu = Math.hypot(ux, uy), ls = Math.hypot(sx, sy)
  if (lu > 0.5 && ls > 0.5 && Math.abs(det) / (lu * ls) > Math.sin(15 * Math.PI / 180)) {
    const wx = seg[0][0] - fica[0], wy = seg[0][1] - fica[1]
    const t = (wx * sy - wy * sx) / det           // ao longo do trecho que anda
    const r = (wx * uy - wy * ux) / det           // ao longo do outro (0..1)
    const X = [fica[0] + ux * t, fica[1] + uy * t]
    if (r >= -2 / ls && r <= 1 + 2 / ls && t > 0 && Math.hypot(X[0] - vai[0], X[1] - vai[1]) <= ENCOSTAR_MAX_M) {
      return { p: _llCostura(_peNoSegmento(X, seg[0], seg[1])), tipo: 'em linha' }
    }
  }
  return { p: _llCostura(_peNoSegmento(cm, seg[0], seg[1])), tipo: 'no trecho' }
}

/** @param {L.LeafletMouseEvent} ev */
function _moverEncostar(ev) {
  const en = ruaState.encostando
  if (!en) return
  if (!en.ponta) {
    const p = _pontaPerto(ev.latlng)
    const k = p ? p.x.k + '#' + p.e : null
    if (k !== en.sobre) { en.sobre = k; en.dica = p; _pintarRealceRuas() }
    return
  }
  en.alvo = _alvoEncostar(ev.latlng)
  _pintarRealceRuas()
}

/** @param {L.LatLng} ll */
function _cliqueEncostar(ll) {
  const en = ruaState.encostando
  if (ruaState.gravando) return
  if (!en.ponta) {
    const p = _pontaPerto(ll)
    if (!p) { toast('Clique perto da ponta de um trecho.', 'aviso'); return }
    en.ponta = p
    en.alvo = null
    _pintarRealceRuas()
    pintarMesaRuas()
    return
  }
  const alvo = _alvoEncostar(ll)
  if (!alvo) { toast('Clique sobre o trecho onde a ponta deve encostar.', 'aviso'); return }
  const { x, e } = en.ponta
  const r = n => Number(n.toFixed(7))
  const p = [r(alvo.p[0]), r(alvo.p[1])]
  const novoDe = e ? x.t.de : p, novoAte = e ? p : x.t.ate
  ruaState.encostando = { ponta: null, alvo: null }
  _enviarLoteRuas({ acao: 'encostar', itens: [{ ..._itemDoTrecho(x), novo_de: novoDe, novo_ate: novoAte }] })
}

/** O realce do encostar: a ponta sob o cursor ou a escolhida, e a prévia do trecho novo. */
function _realceEncostar(r, op) {
  const en = ruaState.encostando
  if (!en) return
  const marca = (p, cor) => r.addLayer(L.circleMarker(p, { ...op, radius: 7, color: cor, weight: 2.5, fillColor: '#fff', fillOpacity: 1 }))
  const pontaDe = ({ x, e }) => e ? x.linha.at(-1) : x.linha[0]
  if (!en.ponta) {
    if (en.dica) marca(pontaDe(en.dica), '#7c3aed')
    return
  }
  const { x, e } = en.ponta
  r.addLayer(L.polyline(x.linha, { ...op, color: '#7c3aed', weight: 6, opacity: .35 }))
  marca(pontaDe(en.ponta), '#7c3aed')
  if (en.alvo) {
    const fica = e ? x.t.de : x.t.ate
    r.addLayer(L.polyline([fica, en.alvo.p], { ...op, color: '#7c3aed', weight: 3, dashArray: '6 5' }))
    marca(en.alvo.p, '#16a34a')
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
  if (ruaState.encostando) { _cliqueEncostar(e.latlng); return }
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
  ruaState.escolha = null; ruaState.nomeLivre = false
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
