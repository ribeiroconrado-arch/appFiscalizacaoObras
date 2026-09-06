const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm')
function ambiente(quadras=['1','1']){
  const fonte=fs.readFileSync('public/js/cadastro.js','utf8'),trecho=fonte.slice(fonte.indexOf('const unificacaoDisponivel ='),fonte.indexOf('function acionarFerramenta('));
  const botoes=[{setAttribute(){},querySelector(){return null}},{setAttribute(){},querySelector(){return null}}],fila=[];
  const ctx={AbortController,Set,JSON,Number,Error,SyntaxError,selState:{ids:new Set([1,2])},state:{lotes:new Map(quadras.map((q,i)=>[i+1,{properties:{bairro:'A',quadra:q},geometry:{type:'Polygon',coordinates:[]}}]))},document:{querySelectorAll:()=>botoes,querySelector:()=>null},setTimeout:(fn,ms)=>{if(ms===300)fila.push(fn);return 1},clearTimeout(){}};
  vm.runInNewContext(trecho,ctx);return {ctx,botoes,fila}
}
test('unificação fica desabilitada até a conferência e continua bloqueada para lotes separados',async()=>{
  const {ctx,botoes,fila}=ambiente();ctx.fetch=async()=>({ok:true,json:async()=>({impedimento:'O lote 2 não encosta nos demais.',retrato:{geometry:{type:'MultiPolygon'}}})});
  ctx.conferirDisponibilidadeUnificacao();assert.ok(botoes.every(b=>b.disabled));await fila.shift()();assert.ok(botoes.every(b=>b.disabled));assert.match(botoes[0].title,/não encosta/)
})
test('só libera quando o servidor aprova o polígono único',async()=>{
  const {ctx,botoes,fila}=ambiente();ctx.fetch=async()=>({ok:true,json:async()=>({impedimento:null,retrato:{geometry:{type:'Polygon'}}})});
  ctx.conferirDisponibilidadeUnificacao();await fila.shift()();assert.ok(botoes.every(b=>!b.disabled));ctx.selState.ids.delete(2);ctx.conferirDisponibilidadeUnificacao();assert.ok(botoes.every(b=>b.disabled))
})
test('quadras diferentes bloqueiam sem consultar o servidor',()=>{
  const {ctx,botoes,fila}=ambiente(['1','2']);ctx.conferirDisponibilidadeUnificacao();assert.equal(fila.length,0);assert.ok(botoes.every(b=>b.disabled));assert.match(botoes[0].title,/mesma quadra/)
})
test('resposta atrasada de uma seleção anterior não libera a seleção atual',async()=>{
  const {ctx,botoes,fila}=ambiente();let resolver;ctx.fetch=()=>new Promise(r=>resolver=r);
  ctx.conferirDisponibilidadeUnificacao();const pendente=fila.shift()();ctx.selState.ids.delete(2);ctx.conferirDisponibilidadeUnificacao();resolver({ok:true,json:async()=>({retrato:{geometry:{type:'Polygon'}}})});await pendente;assert.ok(botoes.every(b=>b.disabled))
})
