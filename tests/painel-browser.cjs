// Layout real e dados sintéticos: não consulta nem altera o cadastro.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http')
const { spawn } = require('node:child_process'), assert = require('node:assert/strict')
const root = path.resolve(__dirname, '..')
const output = process.env.TEMP || require('node:os').tmpdir()
const profile = fs.mkdtempSync(path.join(output, 'painel-browser-'))
const blade = fs.readFileSync(path.join(root, 'resources/views/mapa.blade.php'), 'utf8')
const painel = blade.match(/<section class="tela at" id="t-painel">[\s\S]*?<\/section>/)[0].replace(/\{\{--[\s\S]*?--\}\}/g, '')
const nav = blade.match(/<nav class="abas"[\s\S]*?<\/nav>/)[0].replace(/\{\{--[\s\S]*?--\}\}/g, '')
const data = {
  metricas: [{n:12,rotulo:'Vistorias no período'},{n:3,rotulo:'Vistorias irregulares'},{n:4,rotulo:'Documentos emitidos'},{n:1,rotulo:'Rascunhos',detalhe:'não lavrados'}],
  atencao: [{titulo:'AI 2026/0002',chave:'documento-prazo-2',detalhe:'Auto de Infração · Quadra 24 · Lote 9',tag:{texto:'Defesa venceu há 12 dias',classe:'bd-er'},aba:'documentos'},
    {titulo:'2 vistorias irregulares sem documento',detalhe:'Constatação registrada, ato administrativo não emitido',tag:{texto:'Sem documento',classe:'bd-er'},aba:'documentos'},
    {titulo:'OS 2026/0002',detalhe:'Plantão para vistoria de habite-se · 2 dias marcados',tag:{texto:'Prazo vencido',classe:'bd-er'},aba:'protocolos'},
    {titulo:'Protocolo 2026/0377',detalhe:'Construtora Bandeirantes · Quadra 03 · Lote 10',tag:{texto:'Resposta atrasada 7d',classe:'bd-er'},aba:'protocolos'},
    {titulo:'18 irregularidades sem artigo vinculado',detalhe:'Sem fundamentação legal o sistema bloqueia a lavratura',tag:{texto:'Bloqueia auto',classe:'bd-al'},aba:null}],
  recentes:Array.from({length:8},(_,i)=>({titulo:'Lotes Residencial Buritis V|21|'+(17+i),detalhe:'Lote reativado',usuario:'Administrador',eu:true,quando:'05/09/2026',hora:'05/09/2026 10:30'})),
  bairros:['Centro'],por_tipo:[{rotulo:'Auto de Infração',n:3}],irregularidades:[{rotulo:'Construção sem alvará de licença',n:2}]
}
const app = fs.readFileSync(path.join(root,'public/js/app.js'),'utf8')
const start = app.indexOf('function irPara('), end = app.indexOf('\n}', start)+2
const html = `<!doctype html><html data-tema="institucional"><meta charset="utf-8">
${['app','tema-f','tema-institucional','painel-responsivo'].map(f=>`<link rel="stylesheet" href="/public/css/${f}.css">`).join('')}
<header style="height:68px;background:#005b24;color:white;padding:16px;font:700 16px Arial">Fiscalização de Obras</header><div class="subcab">Prefeitura Municipal de Primavera do Leste</div>
${painel}${['busca','documentos','protocolos','mapa'].map(id=>`<section class="tela" id="t-${id}"></section>`).join('')}${nav}
<script>function esc(s){const el=document.createElement('span');el.textContent=s??'';return el.innerHTML.replaceAll('"','&quot;')}
function marcarModuloNoSubcabecalho(){} function prepararBusca(){} function carregarDocumentos(){} function carregarDemandas(){}
const mapaState={obj:null};function rotulosPorZoom(){} function prepararMapa(){}
${app.slice(start,end)}
</script><script src="/public/js/painel.js"></script><script>
const testData=${JSON.stringify(data)};renderPainel(testData);
pState.notificacoes=[{chave:'documento-prazo-2',titulo:'Defesa vencida',texto:'Auto 2',quando:'há 12 dias',aba:'documentos'}];renderAvisosPainel();
carregarPainel=()=>renderPainel(testData);
</script></html>`
const server = http.createServer((req,res)=>{
  if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html)}
  if(!/^\/public\/(css|js)\/[a-z-]+\.(css|js)$/.test(req.url)){res.writeHead(404);return res.end()}
  res.setHeader('Content-Type',req.url.endsWith('.css')?'text/css':'text/javascript');res.end(fs.readFileSync(path.join(root,req.url)))
})
let browser, ws, serial=0;const pending=new Map(), errors=[]
const wait=ms=>new Promise(r=>setTimeout(r,ms))
function send(method,params={}){return new Promise((resolve,reject)=>{const id=++serial;const timer=setTimeout(()=>{pending.delete(id);reject(Error('Timeout: '+method))},10000);pending.set(id,{resolve:r=>{clearTimeout(timer);resolve(r)},reject:e=>{clearTimeout(timer);reject(e)}});ws.send(JSON.stringify({id,method,params}))})}
async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value}
;(async()=>{try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r))
  browser=spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',['--headless=new','--disable-gpu','--no-first-run','--disable-extensions','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'})
  let port;for(let i=0;i<100;i++){try{port=fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];break}catch{}await wait(100)}
  assert.ok(port,'Navegador não iniciou')
  const targets=await(await fetch(`http://127.0.0.1:${port}/json`)).json()
  ws=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl)
  await new Promise(r=>ws.addEventListener('open',r,{once:true}))
  ws.addEventListener('message',ev=>{const m=JSON.parse(ev.data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);if(p)m.error?p.reject(m.error):p.resolve(m.result)}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails)})
  await send('Runtime.enable');await send('Page.enable');await send('Page.navigate',{url:`http://127.0.0.1:${server.address().port}/`})
  for(let i=0;i<50;i++){if(await evaluate("document.querySelectorAll('#pn-recentes .fd').length===3"))break;await wait(100)}
  assert.equal(await evaluate("document.querySelector('#pn-avisos-bloco').hidden"),true)
  assert.equal(await evaluate("document.querySelector('#pn-avisos-agrupados').hidden"),false)
  await evaluate("document.querySelector('#pn-recentes-mais').click()")
  assert.equal(await evaluate("document.querySelectorAll('#pn-recentes .fd').length"),8)
  await evaluate("document.querySelector('#pn-recentes-mais').click();document.querySelector('.painel-filtros-btn').click()")
  assert.equal(await evaluate("document.querySelector('#pn-filtros').hidden"),false)
  await evaluate("document.querySelector('#pn-bairro').value='Centro';document.querySelector('#pn-bairro').dispatchEvent(new Event('change'))")
  assert.match(await evaluate("document.querySelector('#pn-filtro-resumo').textContent"),/Centro/)
  await evaluate("document.querySelector('.painel-filtros-btn').click()")
  // Um aviso diferente (inclusive sem chave) precisa continuar visível.
  await evaluate("pState.notificacoes.push({titulo:'Rascunho',texto:'Pendente',quando:'hoje'});renderAvisosPainel()")
  assert.equal(await evaluate("document.querySelector('#pn-avisos-n').textContent"),'1')
  await evaluate('pState.notificacoes.pop();renderAvisosPainel()')
  for(const width of [360,768,1024,1279,1280,1440]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false})
    await evaluate('new Promise(requestAnimationFrame)')
    const boxes=await evaluate(`(()=>{const p=document.querySelector('#t-painel'),n=document.querySelector('.abas'),b=n.getBoundingClientRect(),r=p.getBoundingClientRect();return {direction:getComputedStyle(n).flexDirection,left:r.left,bottom:r.bottom,navTop:b.top,overflow:p.scrollWidth>p.clientWidth,targets:[...n.children].every(a=>a.getBoundingClientRect().height>=44)}})()`)
    assert.equal(boxes.direction,width>=1280?'column':'row',JSON.stringify({width,boxes}))
    assert.equal(boxes.overflow,false,`Conteúdo transbordando em ${width}px`)
    assert.ok(boxes.targets)
    if(width<1280)assert.ok(boxes.bottom<=boxes.navTop+1)
    else assert.equal(boxes.left,184)
    console.log(`OK ${width}px: menu ${boxes.direction==='column'?'lateral':'inferior'}, conteúdo e alvos de toque`)
    if(process.env.PAINEL_SCREENSHOTS&&[360,1024,1440].includes(width)){
      const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(process.env.PAINEL_SCREENSHOTS,`painel-${width}.png`),Buffer.from(shot.data,'base64'))
    }
  }
  await evaluate("document.querySelectorAll('.aba')[1].click()")
  assert.equal(await evaluate("document.querySelector('.aba[aria-current=page]').textContent.trim()"),'Consulta')
  await send('Emulation.setDeviceMetricsOverride',{width:768,height:1000,deviceScaleFactor:1,mobile:false})
  assert.equal(await evaluate("document.querySelector('.tela.at').id"),'t-busca')
  assert.deepEqual(errors,[])
  console.log('OK agrupamento, histórico, filtros e navegação preservada após redimensionar')
}catch(e){console.error(e);process.exitCode=1}finally{if(ws){try{await send('Browser.close')}catch{}ws.close()}browser?.kill();server.close()}})()
