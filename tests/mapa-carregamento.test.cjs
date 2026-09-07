const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const fonte=fs.readFileSync('public/js/app.js','utf8');
function carregar(nome,ctx){const inicio=fonte.indexOf('async function '+nome+'(');vm.runInNewContext(fonte.slice(inicio,fonte.indexOf('\n}',inicio)+2),ctx);return ctx[nome]}
test('overlay cobre enquadramento e carga, inclusive ao retornar ao mapa',async()=>{
  const eventos=[];let concluir;const carga=new Promise(r=>concluir=r);
  const ctx={mapaVisivel:()=>true,mapaState:{},mostrarCarregandoTela:()=>eventos.push('mostrar'),esconderCarregandoTela:()=>eventos.push('esconder'),enquadrarBase:async()=>eventos.push('enquadrar'),carregarLotesVisiveis:()=>carga};
  const preparar=carregar('prepararMapa',ctx),p=preparar();await Promise.resolve();
  assert.deepEqual(eventos,['mostrar','enquadrar']);await preparar();assert.equal(eventos.length,2);
  concluir();await p;assert.equal(eventos.at(-1),'esconder');
  await preparar();assert.deepEqual(eventos,['mostrar','enquadrar','esconder','mostrar','esconder']);
});
test('carga em andamento é aguardada sem duplicar a requisição',async()=>{
  let concluir,terminou=false;const pendente=new Promise(r=>concluir=r);
  const ctx={mapaState:{obj:{}},state:{carregando:true,cargaLotes:pendente}};
  const carregarLotes=carregar('carregarLotesVisiveis',ctx);
  const p=carregarLotes().then(()=>terminou=true);await Promise.resolve();assert.equal(terminou,false);
  concluir();await p;assert.equal(terminou,true);
});
test('falha libera o overlay e permite nova preparação',async()=>{
  let fechou=false;const ctx={mapaVisivel:()=>true,mapaState:{},mostrarCarregandoTela(){},esconderCarregandoTela:()=>fechou=true,enquadrarBase:async()=>{throw Error('rede')}};
  await assert.rejects(carregar('prepararMapa',ctx)(),/rede/);assert.equal(fechou,true);assert.equal(ctx.mapaState.preparando,false);
});
