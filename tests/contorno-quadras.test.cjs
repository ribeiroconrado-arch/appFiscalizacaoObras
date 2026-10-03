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
vm.runInContext(fs.readFileSync('public/js/bairros-contorno.js', 'utf8') + '\n;globalThis.calcularQuadras = calcularQuadras', ctx)

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
