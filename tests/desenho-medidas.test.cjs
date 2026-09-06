// A RÉGUA DO DESENHO É A GRADE DO UTM.
//
// Este teste exercita as funções que o fiscal vê em ação: `distanciaNoPlano`,
// que rotula cada lado enquanto ele desenha, e `areaDoDesenho`, que mostra a
// área. O lote é real — Residencial Buritis V, quadra 47, lote 29, com as
// coordenadas exatamente como saíram da importação do DWG — e foi PROJETADO
// com 10,00 x 21,50 m e 215,00 m².
//
// Antes do fator de escala (fatorEscalaUTM, em geo.js) estes mesmos vértices
// eram medidos como 9,99 x 21,49 m: a diferença entre medir no chão e medir na
// grade, que é a régua do DWG e da matrícula. O fiscal digitava 10,00 vindo da
// matrícula e a tela devolvia outro número.
//
// Os valores esperados são os mesmos de tests/Unit/GeometriaPlanaTest.php (o
// servidor) e de tests/prancheta-geo.test.cjs (a prancheta). As três
// implementações do plano têm de concordar, senão o lote desenhado no mapa
// deixa de bater com o mesmo lote desenhado na prancheta.

const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')

// desenho.js registra atalhos de teclado ao carregar; fora do navegador basta
// que os dois existam. Nada aqui chama Leaflet nem toca no DOM.
const ouvinte = { addEventListener() {} }
const contexto = vm.createContext({ document: ouvinte, window: ouvinte, Math, JSON })

vm.runInContext(
  fs.readFileSync('public/js/geo.js', 'utf8') + '\n' +
  fs.readFileSync('public/js/desenho.js', 'utf8'),
  contexto
)

const rodar = expr => vm.runInContext(expr, contexto)

/** Quadra 47, lote 29 — anel fechado, em [lon, lat]. */
const ANEL = [
  [-54.342388840838254, -15.527585038628917],
  [-54.342480424355244, -15.527601561662465],
  [-54.342443783309136, -15.527792466496976],
  [-54.342352199715720, -15.527775943435666],
  [-54.342388840838254, -15.527585038628917],
]

/** Fixa o plano de trabalho no primeiro vértice, como o desenho faz. */
function prepara() {
  contexto.ANEL = ANEL
  rodar('desenhoState.plano = planoLocal(ANEL[0][1], ANEL[0][0])')
  // `areaDoDesenho` lê os vértices SEM o fechamento — é assim que o desenho
  // guarda enquanto o polígono está sendo traçado.
  rodar('desenhoState.vertices = ANEL.slice(0, -1)')
}

test('o fator de escala confere com o do servidor e o da prancheta', () => {
  assert.ok(Math.abs(rodar('fatorEscalaUTM(-15.5276, -54.3424)') - 1.0006053) < 1e-6)
  assert.ok(Math.abs(rodar('fatorEscalaUTM(-15.5, -57)') - 0.9996) < 1e-12)
})

test('cada lado é rotulado com a medida do projeto', () => {
  prepara()
  const esperado = [10, 21.5, 10, 21.5]
  const nomes = ['frente', 'lado direito', 'fundos', 'lado esquerdo']

  for (let i = 0; i < 4; i++) {
    contexto.i = i
    const m = rodar('distanciaNoPlano(ANEL[i], ANEL[i + 1])')
    assert.ok(Math.abs(m - esperado[i]) < 0.01, `${nomes[i]}: ${m} (esperado ${esperado[i]})`)
  }
})

test('a área mostrada é a área de grade da importação', () => {
  prepara()
  const area = rodar('areaDoDesenho()')
  assert.ok(Math.abs(area - 215) < 0.05, `área: ${area}`)
})

// Cravar uma medida e voltar ao mundo geográfico não pode mudar o número: é o
// que acontece quando o fiscal digita "10,00" em cima do lado.
test('ida e volta ao plano preserva a coordenada', () => {
  prepara()
  const d = rodar(`(() => {
    const p = aoPlano(ANEL[1][0], ANEL[1][1])
    const v = doPlano(p[0], p[1])
    return Math.max(Math.abs(v[0] - ANEL[1][0]), Math.abs(v[1] - ANEL[1][1]))
  })()`)
  assert.ok(d < 1e-12, `divergiu ${d} grau(s)`)
})
