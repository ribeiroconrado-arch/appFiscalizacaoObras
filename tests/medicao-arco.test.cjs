// Medição no mapa: o trecho em ARCO (24/11/2026) — comprimento do arco e o
// gomo na área. Roda as contas de medir-mapa.js fora do navegador.
const { test } = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm')

const H = __dirname + '/../public/js/'
const ctx = { console, document: { addEventListener() {}, getElementById: () => null }, window: { addEventListener() {} },
  localStorage: { getItem: () => null, setItem() {} }, fatorEscalaUTM: () => 1 }
vm.createContext(ctx)
const desenho = fs.readFileSync(H + 'desenho.js', 'utf8')
const ini = desenho.indexOf('const GEO_A')
vm.runInContext(desenho.slice(ini, desenho.indexOf('\n}\n', desenho.indexOf('function planoLocal(', ini)) + 3), ctx)
vm.runInContext(fs.readFileSync(H + 'medir-mapa.js', 'utf8').replace(/^const medState =/m, 'var medState =')
  + '\n;this.medirPontos = medirPontos', ctx)

const p = ctx.planoLocal(-15.55, -54.3)
const ll = (x, y) => [p.lonRef + x / p.porGrauLon, p.latRef + y / p.porGrauLat]
const perto = (a, b, msg) => assert.ok(Math.abs(a - b) < 1e-4, `${msg}: ${a} ≠ ${b}`)
const k = Math.SQRT1_2

test('quarto de círculo: o lado é o arco, e a área conta o gomo', () => {
  const m = ctx.medirPontos([ll(0, 0), ll(10, 0), ll(0, 10)], true, { 1: ll(10 * k, 10 * k) })
  perto(m.lados[1], Math.PI * 5, 'arco')
  perto(m.raios[1], 10, 'raio')
  perto(m.area, Math.PI * 25, 'área')
  perto(m.total, 20 + Math.PI * 5, 'perímetro')
})

test('o mesmo contorno ao contrário dá a mesma área', () => {
  const m = ctx.medirPontos([ll(0, 0), ll(0, 10), ll(10, 0)], true, { 1: ll(10 * k, 10 * k) })
  perto(m.area, Math.PI * 25, 'área')
})

test('lote 10 × 10 com a quina arredondada (raio 3)', () => {
  const m = ctx.medirPontos([ll(0, 0), ll(10, 0), ll(10, 7), ll(7, 10), ll(0, 10)], true, { 2: ll(7 + 3 * k, 7 + 3 * k) })
  perto(m.area, 100 - 9 + Math.PI * 9 / 4, 'área')
})

test('arco de mais de meia volta', () => {
  perto(ctx.medirPontos([ll(10, 0), ll(0, 10)], false, { 0: ll(0, -10) }).lados[0], Math.PI * 15, 'três quartos')
})

test('três pontos alinhados: é reta, sem raio', () => {
  const m = ctx.medirPontos([ll(0, 0), ll(10, 0)], false, { 0: ll(5, 0) })
  perto(m.lados[0], 10, 'lado')
  assert.equal(m.raios[0], null)
})
