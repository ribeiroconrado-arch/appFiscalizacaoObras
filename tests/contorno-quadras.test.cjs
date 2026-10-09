// Contorno das quadras (calcularQuadras, public/js/bairros-contorno.js), com o
// mesmo JSTS que o navegador usa (public/vendor). Lotes montados em metros num
// plano local e levados a lon/lat pela PranchetaGeo — o caminho de volta é o
// mesmo do cálculo.
const { test } = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm')

const ctx = { console }
ctx.window = ctx; ctx.self = ctx
vm.createContext(ctx)
vm.runInContext(fs.readFileSync('public/vendor/jsts-2.12.1/jsts.min.js', 'utf8'), ctx)
vm.runInContext(fs.readFileSync('public/js/prancheta-geo.js', 'utf8') + '\n;globalThis.PranchetaGeo = PranchetaGeo', ctx)
vm.runInContext(fs.readFileSync('public/js/bairros-contorno.js', 'utf8') + '\n;globalThis.calcularQuadras = calcularQuadras; globalThis.calcularQuadrasERuas = calcularQuadrasERuas', ctx)

const ORIGEM = [-54.3, -15.55]
const plano = ctx.PranchetaGeo.plano(ORIGEM)
let id = 0
/** Lote retangular em metros (x, y = canto sudoeste), com folga `f` de cada lado. */
const lote = (quadra, x, y, l = 12, p = 25, f = 0) => ({
  id: ++id, quadra,
  geometry: { type: 'Polygon', coordinates: [[[x + f, y + f], [x + l - f, y + f], [x + l - f, y + p - f], [x + f, y + p - f], [x + f, y + f]].map(c => plano.de(c))] },
})
/** Quadra de 2 filas de `n` lotes de 12 x 25 m, a partir de (x, y). */
const quadra = (numero, x, y, n, folga = 0) => {
  const ls = []
  for (let fila = 0; fila < 2; fila++) for (let k = 0; k < n; k++) ls.push(lote(numero, x + k * 12, y + fila * 25, 12, 25, folga))
  return ls
}
const area = anel => Math.abs(anel.reduce((s, p, i) => { const q = anel[(i + 1) % anel.length]; return s + p[0] * q[1] - q[0] * p[1] }, 0) / 2)
const anelEmMetros = anel => anel.map(c => plano.para(c))
const anti = anel => anel.reduce((s, p, i) => { const q = anel[(i + 1) % anel.length]; return s + p[0] * q[1] - q[0] * p[1] }, 0) > 0

test('lotes da mesma quadra viram um contorno só, mesmo com fresta entre eles', () => {
  // 10 cm de folga em cada lado do lote: 20 cm de fresta entre vizinhos, como no DWG.
  const [q] = ctx.calcularQuadras(quadra('01', 0, 0, 5, 0.1))
  assert.equal(q.numero, '01')
  assert.equal(q.lotes, 10)
  assert.equal(q.geometry.type, 'MultiPolygon')
  assert.equal(q.geometry.coordinates.length, 1, 'um pedaço só')
  assert.equal(q.geometry.coordinates[0].length, 1, 'sem furos')
  const a = area(anelEmMetros(q.geometry.coordinates[0][0]))
  assert.ok(Math.abs(a - 60 * 50) < 60 * 50 * 0.02, `área ${a.toFixed(1)} m² perto de 3000`)
})

test('ruas continuam separando as quadras; lote sem quadra não entra', () => {
  const lotes = [...quadra('01', 0, 0, 5), ...quadra('02', 72, 0, 5), lote(null, 200, 0), lote('  ', 220, 0)]
  const qs = ctx.calcularQuadras(lotes)
  assert.deepEqual([...qs.map(q => q.numero)].sort(), ['01', '02'])
  for (const q of qs) assert.equal(q.geometry.coordinates.length, 1)
})

test('quadra com o mesmo número cortada por rua fica em pedaços, e o número vai no maior', () => {
  const lotes = [...quadra('51', 0, 0, 6), ...quadra('51', 0, 70, 2)]
  const [q] = ctx.calcularQuadras(lotes)
  assert.equal(q.geometry.coordinates.length, 2)
  const [lat, lon] = q.rotulo
  const [x, y] = plano.para([lon, lat])
  assert.ok(x > 0 && x < 72 && y > 0 && y < 50, `rótulo (${x.toFixed(1)}, ${y.toFixed(1)}) dentro do pedaço maior`)
})

test('quadra em L: o número fica DENTRO dela, não no centro da caixa', () => {
  // Braço horizontal 0..120 x 0..50 e braço vertical 0..24 x 50..200: o centro
  // da caixa (60, 100) cai fora da quadra.
  const lotes = [...quadra('07', 0, 0, 10)]
  for (let k = 0; k < 6; k++) { lotes.push(lote('07', 0, 50 + k * 25), lote('07', 12, 50 + k * 25)) }
  const [q] = ctx.calcularQuadras(lotes)
  const [lat, lon] = q.rotulo
  const [x, y] = plano.para([lon, lat])
  assert.ok((y <= 50 && x <= 120) || (x <= 24 && y <= 200), `rótulo (${x.toFixed(1)}, ${y.toFixed(1)}) dentro do L`)
})

test('anel externo anti-horário (RFC 7946), como o MySQL espera em coordenada geográfica', () => {
  const [q] = ctx.calcularQuadras(quadra('03', 0, 0, 3))
  assert.ok(anti(q.geometry.coordinates[0][0]))
})

test('sem nenhum lote com quadra, nenhuma quadra', () => {
  assert.deepEqual(JSON.parse(JSON.stringify(ctx.calcularQuadras([lote(null, 0, 0)]))), [])
})

// ── NOMES DE RUA ─────────────────────────────────────────────

/**
 * Quadra de 2 filas de `n` lotes 12 x 25 m: a fila de baixo tem endereço na
 * rua `sul`, a de cima na `norte` — inclusive os lotes de esquina, que é o
 * caso real (o endereço da esquina é numa rua só).
 */
const quadraComRuas = (numero, x, y, n, sul, norte) => {
  const ls = quadra(numero, x, y, n)
  ls.forEach((l, i) => { l.logradouro = i < n ? sul : norte })
  return ls
}
/** Trecho → metros: {nome, y médio, ângulo em graus (0 = leste-oeste), comprimento}. */
const emMetros = t => {
  const [x1, y1] = plano.para([t.de[1], t.de[0]]), [x2, y2] = plano.para([t.ate[1], t.ate[0]])
  let ang = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI
  if (ang > 90) ang -= 180; else if (ang <= -90) ang += 180
  return { nome: t.nome, x: (x1 + x2) / 2, y: (y1 + y2) / 2, ang, len: Math.hypot(x2 - x1, y2 - y1) }
}
const ruas = lotes => [...ctx.calcularQuadrasERuas(lotes).ruas].map(emMetros)

test('ruas: cada frente leva o nome da rua dos seus lotes, no meio da rua', () => {
  const rs = ruas(quadraComRuas('01', 0, 0, 5, 'RUA SUL', 'RUA NORTE'))
  const sul = rs.find(r => r.nome === 'RUA SUL'), norte = rs.find(r => r.nome === 'RUA NORTE')
  assert.ok(sul && norte, JSON.stringify(rs))
  assert.ok(Math.abs(sul.y + 7) < 0.5, `RUA SUL 7 m abaixo da quadra (y=${sul.y.toFixed(2)})`)
  assert.ok(Math.abs(norte.y - 57) < 0.5, `RUA NORTE 7 m acima (y=${norte.y.toFixed(2)})`)
  assert.ok(Math.abs(sul.ang) < 1 && Math.abs(sul.len - 60) < 1, 'paralelo e do tamanho da frente')
})

test('ruas: o lado só com lotes de esquina fica SEM NOME, e não com o nome da esquina', () => {
  const rs = ruas(quadraComRuas('01', 0, 0, 5, 'RUA SUL', 'RUA NORTE'))
  const lados = rs.filter(r => Math.abs(Math.abs(r.ang) - 90) < 1)
  assert.equal(lados.length, 2, 'os dois lados de 50 m aparecem')
  assert.ok(lados.every(r => r.nome === null), JSON.stringify(lados))
})

test('ruas: as duas quadras de frente para a mesma rua dão UM nome só, no meio dela', () => {
  // Rua de 14 m entre y=50 e y=64; o meio é y=57.
  const rs = ruas([...quadraComRuas('01', 0, 0, 5, 'RUA SUL', 'RUA DO MEIO'), ...quadraComRuas('02', 0, 64, 5, 'RUA DO MEIO', 'RUA NORTE')])
  const meio = rs.filter(r => r.nome === 'RUA DO MEIO')
  assert.equal(meio.length, 1, JSON.stringify(meio))
  assert.ok(Math.abs(meio[0].y - 57) < 0.5, `no meio da rua (y=${meio[0].y.toFixed(2)})`)
})

test('ruas: o lado sem endereço herda o nome do lado de lá', () => {
  const rs = ruas([...quadraComRuas('01', 0, 0, 5, 'RUA SUL', 'RUA DO MEIO'), ...quadraComRuas('02', 0, 64, 5, null, 'RUA NORTE')])
  const noMeio = rs.filter(r => Math.abs(r.y - 57) < 2 && Math.abs(r.ang) < 1)
  assert.deepEqual(noMeio.map(r => r.nome), ['RUA DO MEIO'])
})

test('ruas: nomes diferentes frente a frente não se fundem', () => {
  const rs = ruas([...quadraComRuas('01', 0, 0, 5, 'RUA SUL', 'RUA A'), ...quadraComRuas('02', 0, 64, 5, 'RUA B', 'RUA NORTE')])
  assert.ok(rs.some(r => r.nome === 'RUA A') && rs.some(r => r.nome === 'RUA B'))
})

test('ruas: sem cadastro (nenhum logradouro), os lados saem todos sem nome', () => {
  const rs = ruas(quadra('01', 0, 0, 5))
  assert.ok(rs.length >= 4 && rs.every(r => r.nome === null))
})

test('ruas: as quadras continuam iguais com o cálculo das ruas junto', () => {
  const ls = quadraComRuas('01', 0, 0, 5, 'RUA SUL', 'RUA NORTE')
  assert.equal(JSON.stringify(ctx.calcularQuadras(ls)), JSON.stringify(ctx.calcularQuadrasERuas(ls).quadras))
})

test('ruas: quadras encostadas pelos fundos, sem rua entre elas, não ganham nome ali', () => {
  // 20 cm de fresta entre as duas, como no DWG: não é rua.
  const rs = ruas([...quadraComRuas('01', 0, 0, 5, 'RUA SUL', 'RUA FANTASMA'), ...quadraComRuas('02', 0, 50.2, 5, 'RUA FANTASMA', 'RUA NORTE')])
  const noMeio = rs.filter(r => Math.abs(r.ang) < 1 && r.y > 30 && r.y < 70)
  assert.equal(noMeio.length, 0, 'nenhum trecho dentro das quadras: ' + JSON.stringify(noMeio))
  assert.ok(rs.some(r => r.nome === 'RUA SUL') && rs.some(r => r.nome === 'RUA NORTE'), 'as ruas de verdade continuam')
})

test('exibição: nome de rua gravado que cai dentro de uma quadra não é desenhado', () => {
  // As quadras carregadas no mapa, como /api/mapa/quadras as devolve.
  const { quadras } = ctx.calcularQuadrasERuas([...quadra('01', 0, 0, 5), ...quadra('02', 0, 64, 5)])
  ctx.feicoesDeTeste = quadras.map(q => ({ type: 'Feature', geometry: q.geometry, properties: { numero: q.numero } }))
  vm.runInContext("quadraState.porBairro.set('TESTE', { feicoes: feicoesDeTeste, camada: null, ruas: [] })", ctx)
  const dentro = (x, y) => { const [lon, lat] = plano.de([x, y]); ctx.ponto = [lat, lon]; return vm.runInContext('_pontoDentroDeQuadra(ponto[0], ponto[1])', ctx) }
  assert.equal(dentro(30, 25), true, 'meio da quadra 01')
  assert.equal(dentro(30, 43), true, '7 m para dentro do lado norte da 01 (o caso das quadras encostadas)')
  assert.equal(dentro(30, 57), false, 'meio da rua entre as duas')
  assert.equal(dentro(30, -7), false, 'meio da rua ao sul')
  assert.equal(dentro(500, 500), false, 'longe de tudo')
  vm.runInContext("quadraState.porBairro.delete('TESTE')", ctx)
})
