// Layout real e dados sintéticos: não consulta nem altera o cadastro.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http')
const { spawn } = require('node:child_process'), assert = require('node:assert/strict')
const root = path.resolve(__dirname, '..')
const output = process.env.TEMP || require('node:os').tmpdir()
const profile = fs.mkdtempSync(path.join(output, 'painel-browser-'))
const blade = fs.readFileSync(path.join(root, 'resources/views/mapa.blade.php'), 'utf8')
const sections=['busca','documentos','protocolos'].map(id=>blade.match(new RegExp('<section class="tela" id="t-'+id+'">[\\s\\S]*?</section>'))[0]).join('').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/@else[\s\S]*?@endif/g,'').replace(/@(?:if|elseif)[^\n]*|@else|@endif/g,'');
const mesa=blade.match(/<aside class="cad-mesa"[\s\S]*?<\/aside>/)[0];
const html='<!doctype html><html data-tema="institucional"><meta charset="utf-8">'+['app','tema-f','tema-institucional','painel-responsivo'].map(f=>'<link rel="stylesheet" href="/public/css/'+f+'.css">').join('')+sections+mesa;
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

  for(let i=0;i<50;i++){if(await evaluate("!!document.querySelector('#dm-tipo')"))break;await wait(100)}
  for(const width of [390,686,768,1024,1440,2560]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:false});
    await evaluate('new Promise(requestAnimationFrame)');
    for(const id of ['busca','documentos','protocolos']){
      await evaluate("document.querySelectorAll('.tela').forEach(e=>e.classList.remove('at'));document.querySelector('#t-"+id+"').classList.add('at')");
      const box=await evaluate("(()=>{const e=document.querySelector('.tela.at'),r=e.getBoundingClientRect();return {left:r.left,right:r.right,overflow:e.scrollWidth>e.clientWidth}})()");
      if(box.overflow)console.log(await evaluate("[...document.querySelectorAll('.tela.at *')].filter(e=>e.getBoundingClientRect().right>innerWidth).map(e=>[e.tagName,e.className,e.textContent.slice(0,80),e.getBoundingClientRect().width])"));
      assert.equal(box.overflow,false,JSON.stringify({width,id,box}));
      if(width>=1000)assert.equal(box.right,width);
    }
    assert.equal(await evaluate("getComputedStyle(document.querySelector('#dm-tipo')).fontWeight"),'400');
    await evaluate("document.querySelector('#cad-mesa').hidden=false;document.querySelector('#cad-mesa').classList.remove('so-regua')");
    assert.equal(await evaluate("getComputedStyle(document.querySelector('#cad-mesa')).display"),width>=600?'flex':'none');
    console.log('OK '+width+'px: três listas sem transbordamento, filtros e régua');
  }
}catch(e){console.error(e);process.exitCode=1}finally{if(ws){try{await send('Browser.close')}catch{}ws.close()}browser?.kill();server.close()}})()
