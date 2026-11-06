// O nome do bairro encurtado só onde não cabe — a mesma regra de tests/Unit/NomeDoBairroTest.php.
//   node tests/nome-do-bairro.test.cjs
const {test}=require('node:test'), assert=require('node:assert/strict'), fs=require('node:fs'), vm=require('node:vm')
const c={}; vm.createContext(c)
vm.runInContext(fs.readFileSync('public/js/nome-do-bairro.js','utf8')+';this.curto=bairroCurto',c)
const curto=c.curto

test('nome que cabe fica como está',()=>{
  assert.equal(curto('JARDIM EUROPA IV'),'JARDIM EUROPA IV')
  assert.equal(curto('PARQUE CASTELANDIA III'),'PARQUE CASTELANDIA III')
  assert.equal(curto(null),'')
})

test('abrevia a primeira palavra e só depois as do meio',()=>{
  assert.equal(curto('LOTEAMENTO JARDIM PARAISO'),'LOT. JARDIM PARAISO')
  assert.equal(curto('LOTEAMENTO JARDIM DOS IPES III'),'LOT. JD. DOS IPES III')
  assert.equal(curto('CONDOMINIO RESIDENCIAL VILLA PADOVA'),'COND. RESID. VILLA PADOVA')
})

test('acento, parêntese e palavras compostas',()=>{
  assert.equal(curto('CONDOMÍNIO RESIDENCIAL PORTO SEGURO'),'COND. RESID. PORTO SEGURO')
  assert.equal(curto('CHACARAS NOVA ESPERANCA'),'CHÁC. NOVA ESPERANCA')
  assert.equal(curto('JOSE DE ALENCAR G. DA SILVA (DISTRITO INDUSTRIAL II)'),'JOSE DE ALENCAR G. DA SILVA (DIST. IND. II)')
})

test('ampliação e expansão só no meio ou no fim',()=>{
  assert.equal(curto('LOTEAMENTO JARDIM LUCIANA 1ª AMPLIAÇÃO'),'LOT. JD. LUCIANA 1ª AMPL.')
  assert.equal(curto('EXPANSÃO NORTE DO BAIRRO DE TESTE'),'EXPANSÃO NORTE DO BAIRRO DE TESTE')
})

test('nome próprio e "Vila" não se abreviam',()=>{
  assert.equal(curto('CONDOMINIO CIDADE JARDIM',10),'COND. CIDADE JARDIM')
  assert.equal(curto('VILA POPULAR',5),'VILA POPULAR')
})

test('segue a caixa do nome',()=>{
  assert.equal(curto('Condomínio Residencial Villa Padova'),'Cond. Resid. Villa Padova')
})
