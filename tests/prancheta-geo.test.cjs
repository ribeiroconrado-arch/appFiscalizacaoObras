const {test}=require('node:test'), assert=require('node:assert/strict'), fs=require('node:fs'),vm=require('node:vm')
const G=vm.runInNewContext(fs.readFileSync('public/js/prancheta-geo.js','utf8')+'\nPranchetaGeo')
const square=[[0,0],[100,0],[100,60],[0,60],[0,0]]
test('união junta duas faces de 10 m em uma de 20 m sem alterar a área',()=>{
  const r=[[0,0],[10,0],[20,0],[20,24],[10,24],[0,24],[0,0]],antes=JSON.stringify(r);
  for(const ang of [0,.75]){const p=r.map(q=>G.rot(q,ang)),u=G.juntarFaces(p);assert.equal(u.length,5);assert.ok(Math.abs(G.area(u)-480)<1e-8);const medidas=u.slice(1).map((p,i)=>G.len(G.sub(p,u[i]))).sort((a,b)=>a-b);assert.ok(Math.abs(medidas[0]-20)<1e-8);assert.ok(Math.abs(medidas[3]-24)<1e-8)}
  assert.equal(JSON.stringify(r),antes)
})
test('junção de faces preserva quinas reais e trata a junção do fechamento',()=>{
  assert.equal(G.juntarFaces([[10,0],[20,0],[20,24],[0,24],[0,0],[10,0]]).length,5);
  assert.equal(G.juntarFaces([[0,0],[10,.02],[20,0],[20,24],[0,24],[0,0]]).length,6)
})
const check=(linhas,n)=>{const r=G.dividir(square,linhas);assert.equal(r.erro,null,r.erro);assert.equal(r.partes.length,n);assert.ok(Math.abs(r.partes.reduce((s,p)=>s+G.area(p),0)-6000)<1e-5);return r}
test('sem linhas preserva o contorno original',()=>check([],1))
test('linha encerrada e outra começada no mesmo extremo formam uma divisa',()=>check([[[50,-5],[50,30]],[[50,30],[50,65]]],2))
test('linhas independentes cruzadas produzem quatro partes',()=>check([[[50,-5],[50,65]],[[-5,30],[105,30]]],4))
test('junção em T divide em três partes',()=>check([[[50,-5],[50,65]],[[50,30],[105,30]]],3))
test('diagonal de endpoint a endpoint',()=>check([[[0,0],[100,60]]],2))
test('linha solta continua editável mas não autoriza finalizar',()=>assert.ok(G.dividir(square,[[[50,0],[50,30]]]).erro))
test('contorno fechado interno não vira lote com buraco',()=>assert.ok(G.dividir(square,[[[20,20],[30,20],[30,30],[20,30],[20,20]]]).erro))
test('offset exato preserva direção; mover modifica a partição',()=>{const l=[[40,-10],[40,70]],o=G.offset(l,5);assert.ok(Math.abs(G.len(G.sub(l[0],o[0]))-5)<1e-10);check([o],2);const m=l.map(p=>G.add(p,[10,0]));const r=check([m],2);assert.ok(r.partes.every(p=>Math.abs(G.area(p)-3000)<1e-5))})
test('perpendicular a uma face oblíqua continua a 90 graus em qualquer rotação',()=>{const a=[4,7],b=[98,51],n=G.normal(a,b),d=G.sub(b,a);for(const ang of [0,.3,-1.2,Math.PI])assert.ok(Math.abs(G.dot(G.rot(d,ang),G.rot(n,ang)))<1e-10)})
test('projeção e rotação de ida e volta preservam coordenadas',()=>{const proj=G.plano([-54.3,-15.5]),p=[-54.30123456,-15.50123456],q=proj.de(G.rot(G.rot(proj.para(p),.83),-.83));assert.ok(G.len(G.sub(p,q))<1e-12)})
test('polilinha com quina pode receber offset',()=>{const o=G.offset([[0,0],[10,0],[10,10]],2);assert.deepEqual(JSON.parse(JSON.stringify(o)),[[0,2],[8,2],[8,10]])})

// A prancheta mede na GRADE do UTM, que é a régua do DWG e da matrícula — a
// mesma de App\Support\GeometriaPlana e de fatorEscalaUTM (public/js/geo.js).
// `escalaUTM` aqui é cópia deliberada: este arquivo roda isolado, sem globais.
// Os valores esperados são os mesmos de tests/Unit/GeometriaPlanaTest.php; se
// as duas implementações divergirem, o lote desenhado na prancheta deixa de
// bater com o lote desenhado no mapa.
test('fator de escala do UTM confere com o do servidor',()=>{
  assert.ok(Math.abs(G.escalaUTM(-15.5276,-54.3424)-1.0006053)<1e-6)
  assert.ok(Math.abs(G.escalaUTM(-15.5,-57)-0.9996)<1e-12)
})
test('lote real do Buritis mede o que o projeto diz',()=>{
  // Quadra 47, lote 29: projetado com 10,00 x 21,50 m. Sem o fator de escala
  // saía 9,99 x 21,49.
  const anel=[[-54.342388840838254,-15.527585038628917],[-54.342480424355244,-15.527601561662465],
              [-54.342443783309136,-15.527792466496976],[-54.342352199715720,-15.527775943435666],
              [-54.342388840838254,-15.527585038628917]]
  const proj=G.plano(anel[0]), p=anel.map(c=>proj.para(c))
  const lados=p.slice(0,-1).map((c,i)=>G.len(G.sub(p[i+1],c)))
  const esperado=[10,21.5,10,21.5]
  lados.forEach((m,i)=>assert.ok(Math.abs(m-esperado[i])<0.01,`lado ${i}: ${m}`))
  assert.ok(Math.abs(Math.abs(G.area(p))-215)<0.05,`area: ${G.area(p)}`)
})

test('faces da quadra 27 absorvem resíduos de até 2 cm com ângulo abaixo de 0,25 grau',()=>{
  const geo=[[-54.30676040437099,-15.518855004385603],[-54.30659151676048,-15.518707700002555],[-54.30670859865207,-15.51861964745017],[-54.30684561698051,-15.518790919402605],[-54.30684969453763,-15.518787852833562],[-54.30692032373973,-15.51873432823438],[-54.30705000108637,-15.518893969215364],[-54.3069781494044,-15.518948420251474],[-54.30691873672775,-15.518993102160254],[-54.30676040437099,-15.518855004385603]];
  const r=geo.map(G.plano(geo[0]).para),original=JSON.stringify(r);
  for(const ang of [0,.75]){const u=G.juntarFaces(r.map(p=>G.rot(p,ang)),.02);assert.equal(u.length,7)}
  assert.equal(JSON.stringify(r),original);
});
test('tolerância visual não apaga quinas curtas nem desvios acima de 2 cm',()=>{
  const quina=[[0,0],[.01,.01],[20,0],[20,24],[0,24],[0,0]];
  assert.equal(G.juntarFaces(quina,.02).length,6);
  assert.equal(G.juntarFaces([[0,0],[10,.03],[20,0],[20,24],[0,24],[0,0]],.02).length,6);
});
