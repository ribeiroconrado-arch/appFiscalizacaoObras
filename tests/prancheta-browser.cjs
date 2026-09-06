// Teste de interação isolado: navegador real, imóveis sintéticos, sem gravar no cadastro.
const fs=require('node:fs'),http=require('node:http'),path=require('node:path'),os=require('node:os'),{spawn}=require('node:child_process'),assert=require('node:assert/strict')
const root=path.resolve(__dirname,'..'),profile=fs.mkdtempSync(path.join(os.tmpdir(),'prancheta-browser-'))
const fixture=`<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="teste"><link rel="stylesheet" href="/public/css/prancheta-cadastral.css"><style>body{font-family:Arial}.btn{padding:8px;border:1px solid #ccd6df;border-radius:5px;background:white}.primary{background:#155a91;color:white}#t-mapa{position:fixed;inset:110px 0 68px}#cad-regua button{width:42px;height:44px}@media(min-width:1280px){#t-mapa{left:184px;bottom:0}}</style></head><body><section id="t-mapa" class="tela at"></section><aside id="cad-mesa"><nav id="cad-regua" class="cad-regua"><button id="test-historico" onclick="window.historicoAberto=true">H</button></nav></aside>
<script>
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function fmtNum(n){return Number(n).toLocaleString('pt-BR',{maximumFractionDigits:2})}
function toast(m){console.log(m)} function limparLotesDoMapa(){} function carregarLotesVisiveis(){} function pintarPainelCadastro(){}
const atoState={justificativa:''};let __saved=null;const __posts=[];
</script><script src="/public/js/prancheta-geo.js"></script><script>
const proj=PranchetaGeo.plano([-54.3,-15.5]);const coords=[[0,0],[100,0],[100,60],[0,60],[0,0]].map(p=>proj.de(PranchetaGeo.rot(p,.35)));
const fixture={type:'Feature',properties:{id:1,quadra:'35',numero_lote:'1'},geometry:{type:'Polygon',coordinates:[coords]}};
const vizinho={type:'Feature',properties:{id:2,quadra:'35',numero_lote:'2'},geometry:{type:'Polygon',coordinates:[[[100,0],[150,0],[150,60],[100,60],[100,0]].map(p=>proj.de(PranchetaGeo.rot(p,.35)))]}};
const state={lotes:new Map([[1,fixture],[2,vizinho]])};const realFetch=window.fetch;
window.fetch=async (url,opts)=>{const b=opts?.body?JSON.parse(opts.body):null;if(url.includes('/api/')){__posts.push({url,b});if(url.includes('/carregar'))return Response.json({estado:__saved,identidade:{inscricao:'01.105.035.0001.000',prefixo:'011050350001',base:'0001',usados:[]}});if(url.includes('/salvar')){__saved=b.estado;return Response.json({message:'salvo'})}if(url.includes('/mapa/'))return Response.json({features:[fixture,vizinho]});if(url.includes('previa'))return Response.json({impedimento:null,avisos:[],retrato:{geometry:fixture.geometry,sugestao_lote:'1'}});return Response.json({lotes:[{id:3,numero_lote:'1A'},{id:4,numero_lote:'1B'}],message:'Finalizado'})}return realFetch(url,opts)};
</script><script src="/public/js/prancheta-cadastral.js"></script></body></html>`
const server=http.createServer((req,res)=>{if(req.url==='/'){res.setHeader('Content-Type','text/html');res.end(fixture);return}const names=['/public/css/prancheta-cadastral.css','/public/js/prancheta-geo.js','/public/js/prancheta-cadastral.js'];if(!names.includes(req.url)){res.writeHead(404).end();return}res.setHeader('Content-Type',req.url.endsWith('.js')?'text/javascript':'text/css');res.end(fs.readFileSync(path.join(root,req.url)))})
let browser,ws;const pending=new Map();let serial=0;const errors=[]
const wait=ms=>new Promise(r=>setTimeout(r,ms))
function send(method,params={}){return new Promise((resolve,reject)=>{const id=++serial;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params}))})}
async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw new Error(JSON.stringify(r.exceptionDetails));return r.result.value}
async function mouse(type,x,y){await send('Input.dispatchMouseEvent',{type,x,y,button:type==='mouseMoved'?'none':'left',clickCount:type==='mouseMoved'?0:1})}
async function click(x,y){await mouse('mouseMoved',x,y);await mouse('mousePressed',x,y);await mouse('mouseReleased',x,y)}
async function key(k){await send('Input.dispatchKeyEvent',{type:'keyDown',key:k,...(k.length===1?{text:k}:{})});await send('Input.dispatchKeyEvent',{type:'keyUp',key:k})}
(async()=>{
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));const port=server.address().port
  const executable='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'
  browser=spawn(executable,['--headless=new','--disable-gpu','--no-first-run','--disable-extensions','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'})
  let debugPort;for(let i=0;i<100;i++){try{debugPort=fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];break}catch{}await wait(100)}
  assert.ok(debugPort,'Navegador não iniciou');const targets=await(await fetch(`http://127.0.0.1:${debugPort}/json`)).json()
  ws=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r,{once:true}));ws.addEventListener('message',ev=>{const m=JSON.parse(ev.data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(m.error):p.resolve(m.result)}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails)})
  await send('Runtime.enable');await send('Page.enable');await send('Emulation.setDeviceMetricsOverride',{width:1280,height:860,deviceScaleFactor:1,mobile:false})
  await send('Network.enable');await send('Network.setBlockedURLs',{urls:['https://tile.openstreetmap.org/*','https://*.basemaps.cartocdn.com/*']});
  await send('Page.navigate',{url:`http://127.0.0.1:${port}/`});for(let i=0;i<50;i++){if(await evaluate("typeof PranchetaCad !== 'undefined'"))break;await wait(100)}
  await evaluate("PranchetaCad.abrir('desmembramento',[1]);");await evaluate('PranchetaCad.mapa(false)')
  assert.equal(await evaluate("document.querySelector('#pc-modal').tagName"),'SECTION');
  assert.equal(await evaluate("document.querySelector('#pc-modal').parentElement.id"),'t-mapa');
  assert.equal(await evaluate("!!document.querySelector(':modal')"),false);
  // Coordenadas de tela derivadas do contorno renderizado, não de estado privado.
  const frame=await evaluate(`(()=>{const svg=document.querySelector('#pc-svg'),b=svg.getBoundingClientRect();const p=[...svg.querySelectorAll('path')].find(p=>p.getAttribute('stroke')==='#243b4b');return {x:b.x,y:b.y,d:p.getAttribute('d')}})()`)
  const pts=[...frame.d.matchAll(/[ML](-?[\d.]+),(-?[\d.]+)/g)].map(m=>[+m[1]+frame.x,+m[2]+frame.y])
  const mid=(a,b)=>[(a[0]+b[0])/2,(a[1]+b[1])/2],a=mid(pts[0],pts[1]),b=mid(pts[2],pts[3]),c=mid(a,b)
  await key('l');await mouse('mouseMoved',...pts[0]);assert.equal(await evaluate("document.querySelector('[data-snap]')?.dataset.snap"),'endpoint');
  await mouse('mouseMoved',...a);assert.equal(await evaluate("document.querySelector('[data-snap]')?.dataset.snap"),'midpoint');
  await mouse('mouseMoved',...mid(pts[0],a));assert.equal(await evaluate("document.querySelector('[data-snap]')?.dataset.snap"),'face');
  await click(...c);await mouse('mouseMoved',...a);assert.equal(await evaluate("document.querySelector('[data-snap]')?.dataset.snap"),'perpendicular');await key('Escape');await key('l');
  await click(...a);await click(...c);await key('Enter');await click(...c);await click(...b);await key('Enter')
  assert.match(await evaluate("document.querySelector('#pc-resumo').textContent"),/2 linha.*2 parte/)
  await evaluate('PranchetaCad.salvar()');const saved=await evaluate('__saved');assert.equal(saved.linhas.length,2)
  // Seleção e movimento por distância: o cursor define a direção; 3 m é digitado.
  await key('v');await click(...mid(a,c));await key('m');await click(...a);await mouse('mouseMoved',c[0]+30,c[1]+20)
  await key('3');await key('Enter');await evaluate('PranchetaCad.salvar()')
  const moved=await evaluate('__saved');const g=require('node:vm').runInNewContext(fs.readFileSync(path.join(root,'public/js/prancheta-geo.js'),'utf8')+'\nPranchetaGeo');const p=g.plano(saved.linhas[0].pontos[0]);assert.ok(Math.abs(g.len(p.para(moved.linhas[0].pontos[0]))-3)<1e-6)
  await evaluate('PranchetaCad.historico()');
  // Mover com base no midpoint e destino no endpoint da face.
  await key('v');await click(...mid(a,c));await key('m');await mouse('mouseMoved',...a);
  assert.equal(await evaluate("document.querySelector('[data-snap]')?.dataset.snap"),'endpoint');await click(...a);await mouse('mouseMoved',...pts[0]);
  assert.equal(await evaluate("document.querySelector('[data-snap]')?.dataset.snap"),'endpoint');await click(...pts[0]);await evaluate('PranchetaCad.salvar()');
  const snapped=await evaluate('__saved.linhas[0].pontos[0]'),corner=await evaluate('fixture.geometry.coordinates[0][0]');assert.ok(Math.hypot(snapped[0]-corner[0],snapped[1]-corner[1])<1e-10);
  await evaluate('PranchetaCad.historico()');await evaluate('PranchetaCad.historico()');await evaluate('PranchetaCad.historico()')
  // Perpendicular: escolher face oblíqua, marcar início e digitar 60 metros.
  await key('p');await click(...a);await click(...a);await mouse('mouseMoved',b[0],b[1]);await key('6');await key('0');await key('Enter');await key('Enter')
  await evaluate('PranchetaCad.salvar()');const perpendicular=await evaluate('__saved')
  assert.equal(perpendicular.linhas.length,1)
  const metric=g.plano(await evaluate('fixture.geometry.coordinates[0][0]'));const vec=g.sub(metric.para(perpendicular.linhas[0].pontos[1]),metric.para(perpendicular.linhas[0].pontos[0]));const face=g.sub(metric.para(await evaluate('fixture.geometry.coordinates[0][1]')),metric.para(await evaluate('fixture.geometry.coordinates[0][0]')))
  assert.ok(Math.abs(g.dot(g.unit(vec),g.unit(face)))<1e-8,'Perpendicular perdeu o ângulo de 90°');assert.ok(Math.abs(g.len(vec)-60)<1e-5)
  // Alinhar face e recuperar a orientação do rascunho.
  await key('r');await click(...a);await evaluate('PranchetaCad.salvar()');const angle=await evaluate('__saved.angulo');assert.ok(Math.abs(angle)>.1)
  await evaluate('PranchetaCad.fechar()');await evaluate("PranchetaCad.abrir('desmembramento',[1])");await evaluate('PranchetaCad.mapa(false)')
  assert.equal(await evaluate('__saved.angulo'),angle)
  await evaluate('PranchetaCad.dados(true);PranchetaCad.classificarFaces(0)');
  await evaluate(`(()=>{const classes=['frente_m','lado_direito_m','fundos_m','lado_esquerdo_m'];document.querySelectorAll('[data-face]').forEach((el,i)=>el.value=classes[i%4]);document.querySelector('#pc-aplicar-faces').click()})()`);
  await evaluate('PranchetaCad.salvar()');const measured=await evaluate('Object.values(__saved.dados)[0]');assert.ok(measured.frente_m>0);assert.ok(measured.fundos_m>0);assert.ok(Math.abs(measured.area_matricula_m2-3000)<.01);assert.equal(measured.numero_lote,'0001A');assert.equal(measured.desmembramento,1);
  // Ordem da quadra e sequência iniciando em .000 preservam os dados de cada forma.
  const keysBefore=await evaluate('Object.keys(__saved.dados)');
  await evaluate('PranchetaCad.ordenarParte(1,-1);PranchetaCad.salvar()');
  const order=await evaluate('__saved.ordem');assert.equal(order.length,2);assert.equal(order[1],keysBefore[0]);
  await evaluate("document.querySelector('#pc-sequencia-inicio').value='0';PranchetaCad.sequenciar();PranchetaCad.salvar()");
  assert.equal(await evaluate('__saved.dados[__saved.ordem[0]].desmembramento'),0);
  assert.equal(await evaluate('__saved.dados[__saved.ordem[0]].numero_lote'),'0001A');
  assert.equal(await evaluate('__saved.dados[__saved.ordem[1]].numero_lote'),'0001B');
  assert.equal(await evaluate('__saved.dados[__saved.ordem[1]].frente_m'),measured.frente_m);
  await evaluate(`(()=>{const el=document.querySelector('[data-campo="desmembramento"]');el.value='005';el.dispatchEvent(new Event('input',{bubbles:true}))})()`);
  assert.match(await evaluate("document.querySelector('[data-identidade]').textContent"),/01.105.035.0001.005/);
  assert.match(await evaluate("document.querySelector('.pc-parte-topo b').textContent"),/0001E/);
  await evaluate('PranchetaCad.salvar()');
  const layoutShot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(profile,'dados-partes.png'),Buffer.from(layoutShot.data,'base64'));console.log('Layout: '+path.join(profile,'dados-partes.png'));
  // Campos obrigatórios, validação do servidor e erros internos sem detalhes técnicos.
  await evaluate('PranchetaCad.conferir()');assert.equal(await evaluate("document.querySelector('#pc-motivo').getAttribute('aria-invalid')"),'true');
  await evaluate(`(()=>{const el=document.querySelector('#pc-motivo');el.value='Correção cadastral para teste';el.dispatchEvent(new Event('input',{bubbles:true}))})()`);
  await evaluate(`window.previewFetch=window.fetch;window.fetch=async(url,opts)=>url.includes('/previa')?Response.json({message:'Inválido',errors:{'partes.0.area_matricula_m2':['Confira a área da parte.']}},{status:422}):window.previewFetch(url,opts)`);
  await evaluate('PranchetaCad.conferir()');assert.equal(await evaluate(`document.querySelector('[data-parte="0"][data-campo="area_matricula_m2"]').getAttribute('aria-invalid')`),'true');
  assert.equal(await evaluate("document.querySelector('.pc-medidas').open"),true);
  await evaluate(`window.fetch=async(url,opts)=>url.includes('/previa')?Response.json({message:'SQLSTATE detalhe privado'},{status:500}):window.previewFetch(url,opts)`);
  await evaluate('PranchetaCad.conferir()');const internal=await evaluate("document.querySelector('#pc-conferencia').textContent");assert.match(internal,/Erro interno/);assert.doesNotMatch(internal,/SQLSTATE/);
  await evaluate(`window.fetch=async(url,opts)=>url.includes('/previa')?Response.json({message:'CSRF'},{status:419}):window.previewFetch(url,opts)`);
  await evaluate('PranchetaCad.conferir()');assert.match(await evaluate("document.querySelector('#pc-conferencia').textContent"),/sessão expirou/);
  await evaluate('window.fetch=window.previewFetch');
  // Servidor devolvendo HTML: não prender o usuário e recuperar a cópia local.
  await evaluate(`window.goodFetch=window.fetch;window.fetch=async(url,opts)=>url.includes('/salvar')?new Response('<br><b>Falha PHP</b>',{status:500}):window.goodFetch(url,opts);`);
  await evaluate('PranchetaCad.fechar()');assert.equal(await evaluate("document.querySelector('#pc-modal').hidden"),true);
  await evaluate("PranchetaCad.abrir('desmembramento',[1])");assert.match(await evaluate("document.querySelector('#pc-salvo').textContent"),/local recuperada/);await evaluate('PranchetaCad.dados(true)');assert.equal(await evaluate("document.querySelector('.pc-parte-topo b').textContent"),'0001E');
  await evaluate('window.fetch=window.goodFetch;PranchetaCad.salvar()');
  // Sem espaço local e sem servidor: oferecer saída explícita, sem travar.
  await evaluate(`window.oldSetItem=Storage.prototype.setItem;Storage.prototype.setItem=function(){throw new Error('Quota')};window.fetch=async(url,opts)=>url.includes('/salvar')?new Response('<br>Falha',{status:500}):window.goodFetch(url,opts);`);
  await evaluate('PranchetaCad.fechar()');assert.equal(await evaluate("!!document.querySelector('.pc-falha')"),true);
  await evaluate("document.querySelector('.pc-falha .out-vermelho').click()");assert.equal(await evaluate("document.querySelector('#pc-modal').hidden"),true);
  await evaluate("Storage.prototype.setItem=window.oldSetItem;window.fetch=window.goodFetch;PranchetaCad.abrir('desmembramento',[1])");
  const shot=await send('Page.captureScreenshot',{format:'png'});const output=path.join(profile,'prancheta-desmembramento.png');fs.writeFileSync(output,Buffer.from(shot.data,'base64'));console.log('Screenshot: '+output)
  assert.equal(errors.length,0,JSON.stringify(errors))
  for(const width of [768,1024,1440]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:false});await wait(100);
    const box=await evaluate(`(()=>{const p=document.querySelector('#pc-modal').getBoundingClientRect(),m=document.querySelector('#t-mapa').getBoundingClientRect();return {inside:p.left>=m.left&&p.top>=m.top&&p.right<=m.right&&p.bottom<=m.bottom,overflow:document.querySelector('.pc-janela').scrollWidth>document.querySelector('.pc-janela').clientWidth}})()`);
    assert.ok(box.inside);assert.equal(box.overflow,false);
  }
  await evaluate("document.querySelector('#test-historico').click()");
  for(let i=0;i<30&&!await evaluate('!!window.historicoAberto');i++)await wait(50);
  assert.equal(await evaluate('!!window.historicoAberto'),true);
  assert.equal(await evaluate("document.querySelector('#cad-regua').parentElement.id"),'cad-mesa');
  await evaluate("__saved=null;PranchetaCad.abrir('unificacao',[1,2])");
  assert.equal(await evaluate("document.querySelector('#pc-titulo').textContent"),'Unificação');
  assert.equal(await evaluate("document.querySelector('#cad-regua').parentElement.className"),'pc-regua-slot');
  assert.equal(await evaluate("!!document.querySelector(':modal')"),false);
  await evaluate('PranchetaCad.fechar()');
  console.log('PASS: expansão dentro do mapa, régua restaurada, linhas, mover 3 m, perpendicular 60 m, rotação e rascunho; nenhum erro JavaScript.')
 } finally {try{if(ws){await send('Browser.close');ws.close()}}catch{}if(browser)browser.kill();server.close()}
})().catch(err=>{console.error(err);process.exitCode=1})
