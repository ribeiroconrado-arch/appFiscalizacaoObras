const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const context = vm.createContext({ structuredClone })
vm.runInContext(fs.readFileSync('public/js/corte.js', 'utf8') + '\n' + fs.readFileSync('public/js/editor-cortes.js', 'utf8'), context)
const base = { type: 'Polygon', coordinates: [[[0,0],[10,0],[10,10],[0,10],[0,0]]] }
const area = g => Math.abs(context._areaAssinada(g.coordinates[0]))
const calc = cortes => context.calcularCortes(base, cortes)

test('midpoint: duas metades compartilham a divisa e cobrem o pai', () => {
  const r = calc([{ alvo: '0', linha: [[5,0],[5,10]] }])
  assert.equal(r.erro, undefined)
  assert.deepEqual(Array.from(r.partes, p => area(p.geometry)), [50,50])
  assert.equal(JSON.stringify(base.coordinates[0]), '[[0,0],[10,0],[10,10],[0,10],[0,0]]')
})
test('endpoint: diagonal entre cantos funciona sem vértices duplicados', () => {
  const r = calc([{ alvo: '0', linha: [[0,0],[10,10]] }])
  assert.equal(r.erro, undefined)
  assert.deepEqual(Array.from(r.partes, p => area(p.geometry)), [50,50])
  r.partes.forEach(p => assert.equal(p.geometry.coordinates[0].length, 4))
})
test('editar uma divisa recalcula três partes mantendo identificadores', () => {
  const cortes = [{ alvo: '0', linha: [[5,-1],[5,11]] }, { alvo: '0a', linha: [[-1,4],[11,4]] }]
  const antes = calc(cortes)
  assert.equal(antes.erro, undefined)
  cortes[0].linha = [[6,-1],[6,11]]
  const depois = calc(cortes)
  assert.equal(depois.erro, undefined)
  assert.deepEqual(Array.from(antes.partes, p => p.idParte), Array.from(depois.partes, p => p.idParte))
  assert.equal(depois.partes.reduce((s,p) => s+area(p.geometry),0), 100)
})
test('corte que não atravessa e linha que cruza a si mesma são recusados', () => {
  assert.ok(calc([{ alvo: '0', linha: [[2,2],[8,8]] }]).erro)
  assert.ok(calc([{ alvo: '0', linha: [[5,-1],[8,8],[2,8],[8,2],[5,11]] }]).erro)
})
test('coordenadas reais preservam exatamente o perímetro do pai', () => {
  const b = { type: 'Polygon', coordinates: [base.coordinates[0].map(([x,y]) => [-54.123456789+x*.00001,-15.123456789+y*.00001])] }
  const r = context.calcularCortes(b, [{ alvo: '0', linha: [[-54.123406789,-15.123466789],[-54.123406789,-15.123346789]] }])
  assert.equal(r.erro, undefined)
  for (const p of b.coordinates[0]) assert.ok(r.partes.some(g => g.geometry.coordinates[0].some(c => c[0]===p[0] && c[1]===p[1])))
  assert.ok(Math.abs(r.partes.reduce((s,p) => s+area(p.geometry),0)-area(b)) < 1e-16)
})

test('excluir corte pai remove dependentes, desfazer recupera geometria e dados', () => {
  vm.runInContext(`
    var desmState = { loteId: 1, partes: [] };
    var state = { lotes: new Map([[1, { geometry: ${JSON.stringify(base)} }]]) };
    function pintarPartesNoMapa() {} function pintarMesaDesmembramento() {} function agendarRascunhoDesmembramento() {}
  `, context)
  assert.equal(context.aplicarCortes([{ alvo: '0', linha: [[5,-1],[5,11]] }]), null)
  vm.runInContext(`desmState.partes[0].numero_lote = '12A'; desmState.partes[0].frente_m = 10`, context)
  assert.equal(context.aplicarCortes([{ alvo: '0', linha: [[6,-1],[6,11]] }]), null)
  assert.equal(vm.runInContext('desmState.partes[0].numero_lote', context), '12A')
  context.historicoCortes()
  assert.equal(vm.runInContext('desmState.partes[0].frente_m', context), 10)
  assert.equal(vm.runInContext('editorCortes.cortes[0].linha[0][0]', context), 5)
  context.historicoCortes(true)
  assert.equal(vm.runInContext('editorCortes.cortes[0].linha[0][0]', context), 6)
  context.aplicarCortes([])
  assert.equal(vm.runInContext('desmState.partes.length', context), 0)
  context.historicoCortes()
  assert.equal(vm.runInContext('desmState.partes[0].numero_lote', context), '12A')
})

test('snap perpendicular usa o plano métrico; toggles permitem desligar cada captura', () => {
  const c = vm.createContext({ document: { addEventListener() {} }, window: { addEventListener() {} },
    L: { latLng: (lat,lng) => ({ lat,lng }), circleMarker: () => ({ addTo() {return this}, setLatLng() {} }) },
    mapaState: { camadas: [], obj: { latLngToContainerPoint: ll => ({ x: ll.lng * 100000, y: ll.lat * 100000 }), removeLayer() {} } },
  })
  vm.runInContext(fs.readFileSync('public/js/desenho.js','utf8'), c)
  vm.runInContext(`
    desenhoState.plano = {lonRef:0,latRef:0,porGrauLon:80000,porGrauLat:110000};
    desenhoState.vertices = [[0, .001]];
    desenhoState.snapAneis = [[[0,0],[.001,.001],[.002,0],[0,0]]];
    desenhoState.snapTipos = {endpoint:false, midpoint:false, perpendicular:true};
  `, c)
  const t = 110000**2/(80000**2+110000**2)
  const r = c._encaixar({lng:t*.001,lat:t*.001})
  assert.ok(Math.abs(r.lng-t*.001)<1e-12)
  assert.equal(vm.runInContext('desenhoState.snapInfo',c),'Perpendicular')
  vm.runInContext('desenhoState.snapTipos.perpendicular=false',c)
  const alvo = {lng:t*.001,lat:t*.001}
  assert.equal(c._encaixar(alvo),alvo)
})
