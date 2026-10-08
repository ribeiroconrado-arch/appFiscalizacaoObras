const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const fonte=fs.readFileSync('public/js/app.js','utf8');
function carregar(nome,ctx){const inicio=fonte.indexOf('async function '+nome+'(');vm.runInNewContext(fonte.slice(inicio,fonte.indexOf('\n}',inicio)+2),ctx);return ctx[nome]}

// O bloco da carga em blocos inteiro (constantes + funções), num contexto falso.
function cargaEmBlocos(ctx){
  const ini=fonte.indexOf('const ZOOM_MINIMO'),fim=fonte.indexOf('/** Ids já desenhados');
  const lim=fonte.indexOf('function limparLotesDoMapa('),limFim=fonte.indexOf('\n}',lim)+2;
  vm.runInNewContext(fonte.slice(ini,fim)+'\n'+fonte.slice(lim,limFim)+
    '\n;Object.assign(globalThis,{ZOOM_MINIMO,BLOCO_GRAUS,AREA_MAX_GRAUS2,curadoriaNoMapa,nivelDoMapa,LIMITE_MEMORIA,lotesNaEscala,blocosDoRetangulo,bboxDoBloco,carregarLotesVisiveis,carregarBloco,lotesProtegidos,descartarLotesDistantes,removerLotesDoMapa,limparLotesDoMapa})',ctx);
  return ctx;
}
function retangulo(o,s,l,n){const r={getWest:()=>o,getSouth:()=>s,getEast:()=>l,getNorth:()=>n,pad:()=>r};return r}
function contexto(extra={}){
  const pedidos=[];
  const ctx={state:{lotes:new Map(),selecionado:null,versaoLotes:0,truncado:false,blocos:new Map(),pendentes:new Map(),blocosTruncados:new Set()},
    mapaState:{obj:{getZoom:()=>18,getBounds:()=>retangulo(-54.301,-15.561,-54.299,-15.559),removeLayer(){}},camadas:[],porId:new Map(),camadaLotes:{removeLayer(){}}},
    mapaVisivel:()=>true,desenhados:new Set(),atualizarChip(){},acrescentarLotes(fs){fs.forEach(f=>ctx.desenhados.add(f.properties.id))},toast(){},console,Math,
    fetch:url=>new Promise(r=>pedidos.push({url,responder:ids=>r({ok:true,json:async()=>({features:ids.map(id=>({properties:{id}})),truncado:false})})})),
    ...extra};
  cargaEmBlocos(ctx);return {ctx,pedidos};
}
const tick=()=>new Promise(r=>setImmediate(r));

test('overlay cobre enquadramento e carga, inclusive ao retornar ao mapa',async()=>{
  const eventos=[];let concluir;const carga=new Promise(r=>concluir=r);
  const ctx={mapaVisivel:()=>true,mapaState:{},mostrarCarregandoTela:()=>eventos.push('mostrar'),esconderCarregandoTela:()=>eventos.push('esconder'),enquadrarBase:async()=>eventos.push('enquadrar'),carregarLotesVisiveis:()=>carga};
  const preparar=carregar('prepararMapa',ctx),p=preparar();await Promise.resolve();
  assert.deepEqual(eventos,['mostrar','enquadrar']);await preparar();assert.equal(eventos.length,2);
  concluir();await p;assert.equal(eventos.at(-1),'esconder');
  await preparar();assert.deepEqual(eventos,['mostrar','enquadrar','esconder','mostrar','esconder']);
});

test('falha libera o overlay e permite nova preparação',async()=>{
  let fechou=false;const ctx={mapaVisivel:()=>true,mapaState:{},mostrarCarregandoTela(){},esconderCarregandoTela:()=>fechou=true,enquadrarBase:async()=>{throw Error('rede')}};
  await assert.rejects(carregar('prepararMapa',ctx)(),/rede/);assert.equal(fechou,true);assert.equal(ctx.mapaState.preparando,false);
});

test('a tela vira blocos de 0,01°, do centro para fora',()=>{
  const {ctx}=contexto();
  const k=ctx.blocosDoRetangulo({oeste:-54.305,sul:-15.565,leste:-54.285,norte:-15.555});
  assert.equal(k.length,6);
  assert.equal(ctx.bboxDoBloco('-5430:-1556'),'-54.300000,-15.560000,-54.290000,-15.550000');
});

test('bloco em trânsito é aguardado sem repetir o pedido',async()=>{
  const {ctx,pedidos}=contexto();
  const a=ctx.carregarLotesVisiveis();await tick();const n=pedidos.length;assert.ok(n>0);
  let terminou=false;const b=ctx.carregarLotesVisiveis().then(()=>terminou=true);await tick();
  assert.equal(pedidos.length,n,'a segunda chamada não pede de novo');assert.equal(terminou,false);
  for(let i=0;i<20&&pedidos.some(p=>!p.feito);i++){for(const p of pedidos)if(!p.feito){p.feito=1;p.responder([pedidos.indexOf(p)+1])}await tick();}
  await a;await b;assert.equal(terminou,true);
  assert.equal(ctx.state.blocos.size,pedidos.length);
});

test('arrastar durante a carga pede a área nova (antes o pedido se perdia)',async()=>{
  const {ctx,pedidos}=contexto();
  ctx.carregarLotesVisiveis();await tick();const antes=pedidos.length;
  ctx.mapaState.obj.getBounds=()=>retangulo(-54.251,-15.561,-54.249,-15.559);
  ctx.carregarLotesVisiveis();await tick();
  assert.ok(pedidos.length>antes,'os blocos da área nova foram pedidos');
});

test('nível de detalhe pela área visível: município → bairros → quadras → lotes',()=>{
  const {ctx}=contexto();
  // Lado do quadrado visível, em graus, e o nível esperado.
  // Bairros e quadras aparecem dois zooms mais longe do que apareciam; nos dois
  // zooms novos o bairro mostra só o CÓDIGO (terceira coluna), depois o nome.
  for(const [lado,nivel,soCodigo] of [[0.8,'municipio',false],[0.3,'bairros',true],[0.2,'bairros',true],[0.1,'bairros',false],[0.06,'bairros',false],
    [0.05,'quadras',false],[0.012,'quadras',false],[0.004,'quadras',false],[0.002,'lotes',false]]){
    ctx.mapaState.obj.getBounds=()=>retangulo(-54.3,-15.56,-54.3+lado,-15.56+lado);
    assert.equal(ctx.nivelDoMapa(ctx.mapaState.obj),nivel,`lado ${lado}°`);
    assert.equal(ctx.bairroSoComCodigo(ctx.mapaState.obj),soCodigo,`só código, lado ${lado}°`);
  }
  // Abaixo do zoom mínimo não há lote, por menor que seja a área.
  ctx.mapaState.obj.getZoom=()=>12;
  assert.equal(ctx.nivelDoMapa(ctx.mapaState.obj),'quadras');
});

test('longe demais não pede lote — o mapa mostra os bairros',async()=>{
  const {ctx,pedidos}=contexto();
  ctx.mapaState.obj.getBounds=()=>retangulo(-54.40,-15.65,-54.20,-15.50);
  await ctx.carregarLotesVisiveis();assert.equal(pedidos.length,0);
});

test('acima do teto de memória saem os blocos distantes, nunca o lote em uso',()=>{
  const {ctx}=contexto();
  vm.runInContext('LIMITE_MEMORIA_TESTE=10',ctx);
  // 3 blocos de 6 lotes; o distante tem o lote 99, que está aberto na ficha.
  const lotes=[['0:0',[1,2,3,4,5,6]],['1:0',[7,8,9,10,11,12]],['9:9',[13,14,15,16,17,99]]];
  for(const [k,ids] of lotes){ctx.state.blocos.set(k,new Set(ids));ids.forEach(id=>{ctx.state.lotes.set(id,{});ctx.mapaState.porId.set(id,{});ctx.mapaState.camadas.push({feature:{properties:{id}}})})}
  ctx.state.selecionado={properties:{id:99}};
  // O teto real é 15 mil; aqui a regra é a mesma com números pequenos.
  const fonteDescarte=fonte.slice(fonte.indexOf('function descartarLotesDistantes('),fonte.indexOf('\n}',fonte.indexOf('function descartarLotesDistantes('))+2).replace(/LIMITE_MEMORIA/g,'LIMITE_MEMORIA_TESTE');
  vm.runInContext(fonteDescarte,ctx);
  ctx.descartarLotesDistantes(['0:0','1:0']);
  assert.equal(ctx.state.blocos.has('9:9'),false,'o bloco distante saiu');
  assert.equal(ctx.state.lotes.has(13),false);
  assert.equal(ctx.state.lotes.has(99),true,'o lote aberto na ficha ficou');
  assert.equal(ctx.state.lotes.has(1),true,'os blocos da tela ficaram');
  assert.equal(ctx.mapaState.camadas.length,13);
});

test('com a curadoria no mapa, o bairro inteiro mostra os lotes',async()=>{
  const {ctx,pedidos}=contexto();
  // ~3,3 x 1,7 km: grande demais para a consulta, cabe na curadoria.
  ctx.mapaState.obj.getBounds=()=>retangulo(-54.33,-15.575,-54.30,-15.56);
  await ctx.carregarLotesVisiveis();assert.equal(pedidos.length,0,'consulta: só bairros');
  vm.runInContext('var impState={noMapa:true,preCuradoria:null}',ctx);
  ctx.carregarLotesVisiveis();await tick();assert.ok(pedidos.length>0,'curadoria: pede os lotes');
});

