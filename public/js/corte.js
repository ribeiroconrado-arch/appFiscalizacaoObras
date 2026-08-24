// ══════════════════════════════════════════════
// CORTE DE LOTE POR LINHA
//
// O operador traça uma linha atravessando o lote em vez de desenhar as duas
// partes. É a forma natural de dividir um terreno, e é como o desenhista faz
// no DWG.
//
// ── Por que o corte acontece AQUI e não no banco ──
//
// O MySQL não tem `ST_Split`. Mas não é preciso implementar "corte": basta
// converter a linha no ANEL de uma das partes. A outra o servidor deriva com
// `pai menos a parte A`, e o encaixe fica exato por construção.
//
// ── Por que isso é seguro ──
//
// O que sai daqui é a MESMA lista de polígonos que o desenho livre produz, no
// mesmo contrato. O servidor não sabe — nem precisa saber — se o operador
// desenhou ou cortou. Consequência que vale registrar: um erro neste algoritmo
// é pego pelas provas de cobertura e sobreposição do desmembramento, em vez de
// virar base torta. O corte é conveniência sobre um caminho já provado, não um
// segundo caminho de escrita.
// ══════════════════════════════════════════════

/** Tolerância para considerar que um cruzamento caiu EM CIMA de um vértice. */
const CORTE_EPS = 1e-9

/**
 * Parte um anel de lote em dois, por uma linha.
 *
 * @param {Array<[number,number]>} anelPai  [[lon,lat],…], fechado ou não
 * @param {Array<[number,number]>} linha    traçado do operador
 * @returns {{erro:string}|{a:Object, b:Object}} GeoJSON Polygon de cada parte
 */
function cortarPorLinha(anelPai, linha) {
  const R = _semFechamento(anelPai)
  if (R.length < 3) { return { erro: 'O lote não tem contorno válido.' } }
  if (linha.length < 2) { return { erro: 'Trace a linha de corte.' } }

  // Orientação conhecida: daqui para baixo o anel é sempre anti-horário, o que
  // torna previsível de que lado fica cada metade.
  if (_areaAssinada(R) < 0) { R.reverse() }

  // ── cruzamentos entre a linha e o contorno ──
  const achados = []
  for (let i = 0; i < R.length; i++) {
    const p1 = R[i]
    const p2 = R[(i + 1) % R.length]
    for (let j = 0; j < linha.length - 1; j++) {
      const c = _cruzamento(p1, p2, linha[j], linha[j + 1])
      if (c) { achados.push({ aresta: i, t: c.t, s: j + c.s, ponto: c.ponto }) }
    }
  }

  // Cruzamento sobre um VÉRTICE do contorno é achado duas vezes, uma em cada
  // aresta que se encontra ali — mas geometricamente é um só. Sem juntar, uma
  // linha que corta o lote de canto a canto seria acusada de "cruzar 4 vezes",
  // escondendo o problema de verdade, que é passar pelo canto.
  const cruz = []
  for (const c of achados) {
    const igual = cruz.find(x =>
      Math.abs(x.ponto[0] - c.ponto[0]) < CORTE_EPS && Math.abs(x.ponto[1] - c.ponto[1]) < CORTE_EPS)
    if (igual) {
      igual.emVertice = true
    } else {
      cruz.push(c)
    }
  }

  // ── recusas, cada uma com o seu motivo ──
  //
  // A contagem cobre de uma vez três defeitos diferentes: linha que não
  // atravessa (0 cruzamentos), linha que sai e volta pelo mesmo lado (4), e
  // linha que raspa um canto (3+).
  if (cruz.length === 0) {
    return { erro: 'A linha não atravessa o lote. Comece fora dele e termine fora do outro lado.' }
  }
  if (cruz.length === 1) {
    return { erro: 'A linha entra no lote mas não sai. Estenda-a até passar do outro lado.' }
  }
  if (cruz.length > 2) {
    return { erro: `A linha cruza o contorno ${cruz.length} vezes. Ela precisa entrar por um lado e sair pelo outro, sem voltar.` }
  }

  for (const c of cruz) {
    if (c.emVertice || c.t < CORTE_EPS || c.t > 1 - CORTE_EPS) {
      return { erro: 'A linha passa exatamente por um canto do lote, e aí não dá para saber de que lado ele fica. Desloque a linha alguns centímetros.' }
    }
  }

  if (cruz[0].aresta === cruz[1].aresta) {
    return { erro: 'A linha entra e sai pelo mesmo lado do lote, então não separa nada.' }
  }

  // O caso que a contagem NÃO pega: a linha sai do lote e volta entre os dois
  // cruzamentos. Acontece de verdade quando o operador contorna uma edícula.
  const [pri, seg] = cruz[0].s <= cruz[1].s ? [cruz[0], cruz[1]] : [cruz[1], cruz[0]]
  const trecho = _trechoDaLinha(linha, pri, seg)
  for (let k = 1; k < trecho.length - 1; k++) {
    if (!_dentro(trecho[k], R)) {
      return { erro: 'Entre a entrada e a saída, a linha sai do lote e volta. Trace um corte que fique dentro dele.' }
    }
  }

  // ── monta as duas partes ──
  //
  // As duas usam OS MESMOS vértices do trecho da linha, um deles invertido.
  // É isso que faz a divisa ser idêntica nas duas partes: zero fresta, por
  // construção, e não por tolerância.
  const [x, y] = cruz[0].aresta <= cruz[1].aresta ? [cruz[0], cruz[1]] : [cruz[1], cruz[0]]
  const divisa = _trechoDaLinha(linha, ...(x.s <= y.s ? [x, y] : [y, x]))
  const divisaXY = x.s <= y.s ? divisa : [...divisa].reverse()

  const a = [x.ponto, ..._fatia(R, x.aresta + 1, y.aresta), y.ponto, ...[...divisaXY].reverse().slice(1, -1)]
  const b = [y.ponto, ..._fatia(R, y.aresta + 1, x.aresta), x.ponto, ...divisaXY.slice(1, -1)]

  if (a.length < 3 || b.length < 3) {
    return { erro: 'O corte não produziu duas partes com área.' }
  }

  // ── conferência local ──
  // Em graus², só para provar que o algoritmo não errou. Quem mede em metros
  // quadrados é o servidor.
  const soma = Math.abs(_areaAssinada(a)) + Math.abs(_areaAssinada(b))
  const pai = Math.abs(_areaAssinada(R))
  if (Math.abs(soma - pai) > pai * 1e-6) {
    return { erro: 'O corte não fechou: as duas partes não somam o lote inteiro. Tente uma linha mais simples.' }
  }

  return {
    a: { type: 'Polygon', coordinates: [[...a, a[0]].map(_arredondarPar)] },
    b: { type: 'Polygon', coordinates: [[...b, b[0]].map(_arredondarPar)] },
  }
}

// ── auxiliares ───────────────────────────────────────────────

/** @param {Array<[number,number]>} anel */
function _semFechamento(anel) {
  const a = anel.map(c => [c[0], c[1]])
  const n = a.length
  if (n > 1 && Math.abs(a[0][0] - a[n - 1][0]) < 1e-12 && Math.abs(a[0][1] - a[n - 1][1]) < 1e-12) {
    a.pop()
  }
  return a
}

/** Área assinada (positiva = anti-horário). Em graus², serve só de sinal e proporção. */
function _areaAssinada(anel) {
  let s = 0
  for (let i = 0; i < anel.length; i++) {
    const j = (i + 1) % anel.length
    s += anel[i][0] * anel[j][1] - anel[j][0] * anel[i][1]
  }
  return s / 2
}

/**
 * Onde o segmento p1→p2 cruza o segmento q1→q2.
 * @returns {{t:number, s:number, ponto:[number,number]}|null}
 */
function _cruzamento(p1, p2, q1, q2) {
  const rx = p2[0] - p1[0], ry = p2[1] - p1[1]
  const sx = q2[0] - q1[0], sy = q2[1] - q1[1]
  const den = rx * sy - ry * sx
  if (Math.abs(den) < 1e-15) { return null }   // paralelos

  const t = ((q1[0] - p1[0]) * sy - (q1[1] - p1[1]) * sx) / den
  const u = ((q1[0] - p1[0]) * ry - (q1[1] - p1[1]) * rx) / den
  if (t < 0 || t > 1 || u < 0 || u > 1) { return null }

  return { t, s: u, ponto: [p1[0] + t * rx, p1[1] + t * ry] }
}

/** Trecho da linha entre dois cruzamentos, com os pontos de cruzamento nas pontas. */
function _trechoDaLinha(linha, de, ate) {
  const pontos = [de.ponto]
  for (let k = Math.ceil(de.s); k <= Math.floor(ate.s); k++) {
    if (k >= 0 && k < linha.length) { pontos.push([linha[k][0], linha[k][1]]) }
  }
  pontos.push(ate.ponto)
  return pontos
}

/** Vértices do anel de `de` até `ate`, dando a volta pelo índice 0 se preciso. */
function _fatia(anel, de, ate) {
  const saida = []
  const n = anel.length
  for (let k = de % n, guarda = 0; guarda <= n; k = (k + 1) % n, guarda++) {
    if (k === (ate + 1) % n) { break }
    saida.push(anel[k])
  }
  return saida
}

/** Ponto dentro do anel? Lançamento de raio. */
function _dentro(p, anel) {
  let dentro = false
  for (let i = 0, j = anel.length - 1; i < anel.length; j = i++) {
    const [xi, yi] = anel[i]
    const [xj, yj] = anel[j]
    if ((yi > p[1]) !== (yj > p[1])
        && p[0] < (xj - xi) * (p[1] - yi) / (yj - yi) + xi) {
      dentro = !dentro
    }
  }
  return dentro
}

/** 7 casas ≈ 1 cm em EPSG:4326 — a mesma régua do desenho livre. */
function _arredondarPar(c) {
  return [Number(c[0].toFixed(7)), Number(c[1].toFixed(7))]
}
