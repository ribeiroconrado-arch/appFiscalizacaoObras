// Concordância (o "fillet" do AutoCAD) — geometria pura de PranchetaGeo.
//   node tests/concordancia.test.cjs
const {test}=require('node:test'), assert=require('node:assert/strict'), fs=require('node:fs'), vm=require('node:vm')
const G=vm.runInNewContext(fs.readFileSync('public/js/prancheta-geo.js','utf8')+'\nPranchetaGeo')
const perto=(p,q,tol=1e-9)=>assert.ok(G.len(G.sub(p,q))<tol,`${JSON.stringify(p)} ≠ ${JSON.stringify(q)}`)

test('raio 0 entre duas linhas soltas estende as duas até o encontro e as une',()=>{
  const linhas=[{id:'a',pontos:[[0,0],[0,20]]},{id:'b',pontos:[[5,30],[40,30]]}]
  const r=G.concordancia(linhas,{id:'a',i:0},{id:'b',i:0},0)
  assert.equal(r.erro,undefined,r.erro)
  assert.equal(r.linhas.length,1)
  const ps=r.linhas[0].pontos
  perto(ps[0],[0,0]); perto(ps[1],[0,30]); perto(ps[2],[40,30])
})

test('raio 0 também APARA quem passou do encontro',()=>{
  const r=G.concordancia([{id:'a',pontos:[[0,0],[0,50]]},{id:'b',pontos:[[-10,30],[40,30]]}],{id:'a',i:0},{id:'b',i:0},0)
  perto(r.linhas[0].pontos[1],[0,30])
})

test('raio 0 com as duas pontas da mesma linha fecha o contorno',()=>{
  const r=G.concordancia([{id:'a',pontos:[[0,2],[0,30],[40,30],[40,0],[3,0]]}],{id:'a',i:0},{id:'a',i:3},0)
  const ps=r.linhas[0].pontos
  assert.ok(G.fechada(ps)); perto(ps[0],[0,0]); perto(ps.at(-1),[0,0])
  assert.ok(Math.abs(Math.abs(G.area(ps.slice(0,-1)))-1200)<1e-6)
})

test('raio > 0 no canto de uma linha troca o vértice por um arco tangente',()=>{
  const r=G.concordancia([{id:'a',pontos:[[0,0],[0,30],[40,30]]}],{id:'a',i:0},{id:'a',i:1},9,8)
  const ps=r.linhas[0].pontos
  assert.equal(ps.length,2+9)          // 2 pontas + 9 pontos do arco (8 segmentos)
  perto(ps[1],[0,21]); perto(ps.at(-2),[9,30])
  const centro=[9,21]                  // canto de 90°: centro a r das duas faces
  for(const p of ps.slice(1,-1)) assert.ok(Math.abs(G.len(G.sub(p,centro))-9)<1e-9)
})

test('raio > 0 no canto de um contorno fechado arredonda a esquina e reduz a área',()=>{
  const anel=[[0,0],[0,30],[40,30],[40,0],[0,0]]
  const r=G.concordancia([{id:'a',pontos:anel}],{id:'a',i:1},{id:'a',i:2},9,32)
  const ps=r.linhas[0].pontos
  assert.ok(G.fechada(ps))
  const perdida=81-Math.PI*81/4       // quadrado de 9×9 menos um quarto de círculo
  assert.ok(Math.abs(Math.abs(G.area(ps.slice(0,-1)))-(1200-perdida))<0.1)
})

test('raio que não cabe no lado é recusado com o máximo possível',()=>{
  const r=G.concordancia([{id:'a',pontos:[[0,0],[0,5],[40,5]]}],{id:'a',i:0},{id:'a',i:1},9)
  assert.match(r.erro,/máximo aqui é 5,00 m/)
})

test('linha contra face fixa: raio 0 estende até ela; raio > 0 é recusado',()=>{
  const face={id:null,a:[50,-100],b:[50,100]}
  const ok=G.concordancia([{id:'a',pontos:[[0,10],[20,10]]}],{id:'a',i:0},face,0)
  perto(ok.linhas[0].pontos[1],[50,10])
  assert.match(G.concordancia([{id:'a',pontos:[[0,10],[20,10]]}],{id:'a',i:0},face,3).erro,/raio 0/)
})

test('lados paralelos e lado do meio são recusados com explicação',()=>{
  assert.match(G.concordancia([{id:'a',pontos:[[0,0],[10,0]]},{id:'b',pontos:[[0,5],[10,5]]}],{id:'a',i:0},{id:'b',i:0},0).erro,/paralelos/)
  assert.match(G.concordancia([{id:'a',pontos:[[0,0],[10,0],[10,10],[20,10]]},{id:'b',pontos:[[2,5],[6,5]]}],{id:'a',i:1},{id:'b',i:0},0).erro,/terminam soltos/)
})

test('as linhas originais não são alteradas (a operação devolve cópia)',()=>{
  const linhas=[{id:'a',pontos:[[0,0],[0,20]]},{id:'b',pontos:[[5,30],[40,30]]}],antes=JSON.stringify(linhas)
  G.concordancia(linhas,{id:'a',i:0},{id:'b',i:0},0)
  assert.equal(JSON.stringify(linhas),antes)
})
