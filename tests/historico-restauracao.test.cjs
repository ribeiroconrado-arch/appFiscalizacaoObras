const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm')
function carregar(file,nome,ctx){const src=fs.readFileSync(file,'utf8'),start=src.indexOf('async function '+nome+'(')>=0?src.indexOf('async function '+nome+'('):src.indexOf('function '+nome+'(');vm.runInNewContext(src.slice(start,src.indexOf('\n}',start)+2),ctx);return ctx[nome]}
test('resposta anterior à restauração não repõe lotes antigos nem cancela a carga nova',async()=>{
  const responses=[],ctx={state:{lotes:new Map(),carregando:false,versaoLotes:0},mapaState:{obj:{getZoom:()=>19,getBounds:()=>({getWest:()=>1,getEast:()=>2,getSouth:()=>1,getNorth:()=>2}),removeLayer(){}},camadas:[],porId:new Map()},ZOOM_MINIMO:12,mapaVisivel:()=>true,desenhados:new Set(),atualizarChip(){},acrescentarLotes(){},toast(){},console,fetch:()=>new Promise(resolve=>responses.push(resolve))};
  const load=carregar('public/js/app.js','carregarLotesVisiveis',ctx),clear=carregar('public/js/app.js','limparLotesDoMapa',ctx);
  const old=load();clear();const fresh=load();assert.equal(responses.length,2);
  responses[0]({ok:true,json:async()=>({features:[{properties:{id:1}}]})});await old;
  assert.equal(ctx.state.lotes.size,0);assert.equal(ctx.state.carregando,true);
  responses[1]({ok:true,json:async()=>({features:[{properties:{id:2}}]})});await fresh;
  assert.equal(ctx.state.lotes.has(2),true);assert.equal(ctx.state.carregando,false)
})
test('restauração fora da área visível enquadra, recarrega e destaca o novo id',async()=>{
  const calls=[],ctx={mapaState:{porId:new Map(),obj:{fitBounds:()=>calls.push('enquadrar')}},L:{geoJSON:()=>({getBounds:()=>[]})},fetch:async()=>({ok:true,json:async()=>({geometry:{type:'Polygon'}})}),limparLotesDoMapa:()=>calls.push('limpar'),carregarLotesVisiveis:async()=>{calls.push('carregar');ctx.mapaState.porId.set(4516,{})},destacarPorId:id=>calls.push(id),console,toast:()=>calls.push('erro')};
  await carregar('public/js/historico-cadastro.js','mostrarLoteRestaurado',ctx)(4516);
  assert.deepEqual(calls,['enquadrar','limpar','carregar',4516]);calls.length=0;
  await ctx.mostrarLoteRestaurado(4516);assert.deepEqual(calls,[4516])
})
test('falha de visualização informa que a restauração já foi concluída',async()=>{
  const avisos=[],ctx={mapaState:{porId:new Map()},fetch:async()=>({ok:false,json:async()=>({})}),console:{error(){}},toast:t=>avisos.push(t)};
  await carregar('public/js/historico-cadastro.js','mostrarLoteRestaurado',ctx)(4516);
  assert.match(avisos[0],/restaurado no cadastro/)
})
