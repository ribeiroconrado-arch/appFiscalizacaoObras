// jsArg (public/js/ui.js): valor que vira argumento de função dentro de um
// atributo de evento (onclick="f(...)"). O navegador desfaz as entidades HTML
// do atributo ANTES de rodar o JS — este teste faz o mesmo e confere que o
// valor chega intacto e que nada fora dele executa.
const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')

const fonte = fs.readFileSync(path.join(__dirname, '../public/js/ui.js'), 'utf8')
const pegar = nome => fonte.match(new RegExp(`function ${nome}\\([\\s\\S]*?\\n}\\n`))[0]
const ctx = vm.createContext({})
vm.runInContext(pegar('esc') + pegar('jsArg'), ctx)

// O que o parser de HTML faz com o valor de um atributo entre aspas duplas.
const decodificarAtributo = s => s
  .replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<')
  .replace(/&gt;/g, '>').replace(/&amp;/g, '&')

function rodarOnclick(valor) {
  const html = `f(${ctx.jsArg(valor)})`
  assert.ok(!html.includes('"'), 'aspas duplas cruas fechariam o atributo')
  const recebidos = []
  const sandbox = vm.createContext({ f: v => recebidos.push(v), alert: () => { throw new Error('executou código injetado') } })
  vm.runInContext(decodificarAtributo(html), sandbox)
  return recebidos
}

const maliciosos = [
  'x&quot;);alert(1);//',
  "x&#39;);alert(1);//",
  `x");alert(1);//`,
  `x');alert(1);//`,
  'Jardim Europa IV',
  'D\'Ávila & Filhos <b>',
  '\\");alert(1);//',
]

for (const v of maliciosos) {
  test(`chega intacto e não executa: ${v}`, () => {
    assert.deepEqual(rodarOnclick(v), [v])
  })
}

test('objeto também atravessa intacto', () => {
  const o = { tipo: 'sem_lote', inscricao: `01.090&quot;);alert(1);//`, n: 3 }
  assert.equal(JSON.stringify(rodarOnclick(o)), JSON.stringify([o]))
})

test('o padrão antigo (JSON.stringify + &quot;) era explorável', () => {
  const antigo = v => JSON.stringify(v).replace(/"/g, '&quot;')
  const sandbox = vm.createContext({ f: () => {}, alert: () => { throw new Error('executou') } })
  assert.throws(() => vm.runInContext(decodificarAtributo(`f(${antigo('x&quot;);alert(1);//')})`), sandbox))
})
