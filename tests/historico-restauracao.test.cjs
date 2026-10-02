const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm')
function carregar(file,nome,ctx){const src=fs.readFileSync(file,'utf8'),start=src.indexOf('async function '+nome+'(')>=0?src.indexOf('async function '+nome+'('):src.indexOf('function '+nome+'(');vm.runInNewContext(src.slice(start,src.indexOf('\n}',start)+2),ctx);return ctx[nome]}
test('resposta anterior à restauração não repõe lotes antigos nem cancela a carga nova',async()=>{
  const fonte=fs.readFileSync('public/js/app.js','utf8'),responses=[],ret={getWest:()=>-54.309,getEast:()=>-54.301,getSouth:()=>-15.569,getNorth:()=>-15.561,pad(){return ret}};
  const ctx={state:{lotes:new Map(),versaoLotes:0,blocos:new Map(),pendentes:new Map(),blocosTruncados:new Set()},mapaState:{obj:{getZoom:()=>19,getBounds:()=>ret,removeLayer(){}},camadas:[],porId:new Map()},mapaVisivel:()=>true,desenhados:new Set(),atualizarChip(){},acrescentarLotes(){},toast(){},console,Math,fetch:()=>new Promise(resolve=>responses.push(resolve))};
  const ini=fonte.indexOf('const ZOOM_MINIMO'),fim=fonte.indexOf('/** Ids já desenhados'),lim=fonte.indexOf('function limparLotesDoMapa(');
  vm.runInNewContext(fonte.slice(ini,fim)+'\n'+fonte.slice(lim,fonte.indexOf('\n}',lim)+2)+';Object.assign(globalThis,{carregarLotesVisiveis,limparLotesDoMapa})',ctx);
  const old=ctx.carregarLotesVisiveis();await new Promise(r=>setImmediate(r));ctx.limparLotesDoMapa();const fresh=ctx.carregarLotesVisiveis();await new Promise(r=>setImmediate(r));
  assert.equal(responses.length,2);
  responses[0]({ok:true,json:async()=>({features:[{properties:{id:1}}]})});await old;
  assert.equal(ctx.state.lotes.size,0,'a resposta velha não repôs o lote antigo');
  responses[1]({ok:true,json:async()=>({features:[{properties:{id:2}}]})});await fresh;
  assert.equal(ctx.state.lotes.has(2),true,'a carga nova chegou')
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
