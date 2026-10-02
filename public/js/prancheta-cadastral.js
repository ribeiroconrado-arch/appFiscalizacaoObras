/* Prancheta CAD em plano métrico. A rotação pertence à vista, nunca ao cadastro. */
const PranchetaCad = (() => {
  const G=PranchetaGeo, cores=['#2563eb','#059669','#d97706','#7c3aed','#db2777','#0891b2']
  const copy=x=>structuredClone(x), $=id=>document.getElementById(id), e=x=>esc(String(x??''))
  let s=null, svg=null, modal=null, resize=null, saveTimer=null, fila=Promise.resolve(), geracao=0, reguaOrigem=null, fechamento=null
  const icones={desfazer:'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',refazer:'M15 14l5-5-5-5M20 9H9a5 5 0 0 0 0 10h3',excluir:'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',encerrar:'M5 12.5l4.5 4.5L19 7',snap:'M6 3v8a6 6 0 0 0 12 0V3M6 3h4v4H6zM14 3h4v4h-4z',enquadrar:'M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3',norte:'M12 3l5 12-5-3-5 3zM12 15v6',ortho:'M5 4v15h15M5 12h7v7',selecionar:'M5 3l14 10-8 1-3 7z',linha:'M4 20L20 4M3 18v3h3M18 3h3v3',perpendicular:'M4 19h16M12 4v15M12 14h5v5',mover:'M12 2v20M2 12h20M8 6l4-4 4 4M8 18l4 4 4-4M6 8l-4 4 4 4M18 8l4 4-4 4',offset:'M4 19L16 7M8 21L20 9M8 7l6-6M8 1v6h6',alinhar:'M3 19h18M5 14l13-8M17 2l3 4-4 2',anotar:'M4 4h16M12 4v16M8 20h8',concordancia:'M4 21V13a9 9 0 0 1 9-9h8M4 4h3M4 4v3'}
  // "Livre": a prancheta desenha O CONTORNO do lote (lote novo, editar lote), em
  // vez de divisas dentro de um lote existente. Ver recalcular() e dados().
  const livre=()=>!!s&&(s.tipo==='novo'||s.tipo==='edicao')
  // SÓ O ÍCONE na barra; o nome, o atalho e o que a ferramenta faz aparecem
  // ao passar o mouse (data-dica, ver prancheta-cadastral.css).
  function botao(k,t,atalho,desc='') {return `<button type="button" data-tool="${k}" data-dica="${t}${atalho?' ('+atalho+')':''}${desc?' — '+desc:''}" aria-label="${t}" onclick="PranchetaCad.ferramenta('${k}')"><svg viewBox="0 0 24 24"><path d="${icones[k]}"/></svg></button>`}
  function botaoAcao(ic,t,acao,desc='',id='') {return `<button type="button" ${id?`id="${id}"`:''} data-dica="${t}${desc?' — '+desc:''}" aria-label="${t}" onclick="${acao}"><svg viewBox="0 0 24 24"><path d="${icones[ic]}"/></svg></button>`}
  function opcoesSnap(abrir){const p=$('pc-snap-pop');if(!p)return;p.hidden=abrir===undefined?!p.hidden:!abrir;$('pc-snap')?.classList.toggle('ativo',!p.hidden)}
  function montar() {
    if(modal) return
    modal=document.createElement('section');modal.id='pc-modal';modal.hidden=true;modal.setAttribute('aria-label','Prancheta cadastral')
    // UMA LINHA DE CABEÇALHO: título, ferramentas (só ícone), Snap e Ortho,
    // e Enquadrar/Norte empilhados. Salvar rascunho e Exportar SVG descem para
    // o rodapé, à esquerda; as dicas de texto do rodapé saíram — a descrição
    // de cada ferramenta está no próprio ícone, ao passar o mouse.
    modal.innerHTML=`<header class="pc-cab"><div class="pc-tit"><b id="pc-titulo">Prancheta cadastral</b><small id="pc-origem"></small></div>
      <nav class="pc-tools" aria-label="Ferramentas de desenho">
      ${botao('selecionar','Selecionar','V','clique numa linha para editar; arraste os vértices')}${botao('linha','Linha','L','clique nos cantos e digite a medida; clicar no 1º vértice fecha o contorno')}${botao('perpendicular','Perpendicular','P','escolha uma face e trace a 90° dela')}${botao('mover','Mover','M','move a linha selecionada; digite a distância')}${botao('offset','Offset','O','cópia paralela da linha, à distância digitada')}${botao('concordancia','Fillet (concordância)','F','clique em dois lados; arredonda o canto com o raio digitado — raio 0 une os lados num canto vivo')}
${botao('anotar','Confronto','T','escreve o confrontante de um lado')}
      <i></i>${botaoAcao('desfazer','Desfazer (Ctrl+Z)','PranchetaCad.historico()')}${botaoAcao('refazer','Refazer (Ctrl+Y)','PranchetaCad.historico(true)')}${botaoAcao('excluir','Excluir linha (Delete)','PranchetaCad.excluir()','apaga a linha selecionada')}${botaoAcao('encerrar','Encerrar linha (Enter)','PranchetaCad.terminar()','termina o traço em curso')}
      <i></i><span class="pc-snap-grupo">${botaoAcao('snap','Snap (OSNAP)','PranchetaCad.opcoesSnap()','onde o cursor encaixa: ponta, meio, na face, perpendicular','pc-snap')}
        <div class="pc-snap-pop" id="pc-snap-pop" hidden><b>Encaixe do cursor (OSNAP)</b>
          <label><input id="pc-end" type="checkbox" checked> □ Endpoint <small>ponta das linhas</small></label>
          <label><input id="pc-mid" type="checkbox" checked> △ Midpoint <small>meio das linhas</small></label>
          <label><input id="pc-near" type="checkbox" checked> × Na face <small>qualquer ponto da linha</small></label>
          <label><input id="pc-perp" type="checkbox" checked> ∟ Perpendicular <small>pé da perpendicular</small></label>
          <b>Exibição</b>
          <label><input id="pc-mapa" type="checkbox" checked onchange="PranchetaCad.mapa(this.checked)"> Mapa claro <small>referência de fundo</small></label>
          <small class="pc-snap-alt">Segure Alt para desenhar sem encaixe.</small></div></span>${botaoAcao('ortho','Ortho (F8)','PranchetaCad.ortho()','linhas só na horizontal ou na vertical da tela, como no AutoCAD; Shift inverte enquanto pressionado','pc-ortho')}
      <i></i>${botao('alinhar','Alinhar face','R','gira a vista até a face clicada ficar na horizontal')}${botaoAcao('norte','Norte para cima','PranchetaCad.norte()','desfaz a rotação da vista')}${botaoAcao('enquadrar','Enquadrar','PranchetaCad.enquadrar()','mostra o desenho inteiro · arrastar a vista: roda do mouse pressionada; zoom: girar a roda')}
      </nav>
      <button type="button" class="pc-x" style="margin-left:auto" onclick="PranchetaCad.fechar()" aria-label="Fechar prancheta" data-dica="Fechar a prancheta (o rascunho fica guardado)">✕</button></header>
      <main class="pc-canvas"><span id="pc-ref" class="pc-ref"></span><svg id="pc-svg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Desenho dos imóveis e suas divisas"></svg>
      <div id="pc-entrada" hidden><label id="pc-entrada-label" for="pc-valor">Comprimento</label><input id="pc-valor" inputmode="decimal" autocomplete="off" aria-label="Medida em metros"><span>m · Enter</span></div>
      <section id="pc-dados" hidden aria-label="Dados e conferência"><header><b>Dados dos imóveis</b><button type="button" class="modal-x pc-fechar-dados" onclick="PranchetaCad.dados(false)" aria-label="Fechar dados">✕</button></header><div id="pc-form"></div><div id="pc-conferencia" role="status" tabindex="-1"></div></section>
      <div class="pc-credito">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a> · Referência cartográfica</div></main>
      <footer class="pc-rodape"><button type="button" class="btn sm" onclick="PranchetaCad.salvar().catch(()=>{})" id="pc-save">Salvar rascunho</button>
      <button type="button" class="btn sm" onclick="PranchetaCad.exportar()">Exportar SVG</button><span id="pc-salvo" role="status"></span>
      <span class="pc-fillet" id="pc-fillet" hidden><b>Fillet</b><label>raio <input id="pc-raio" inputmode="decimal" autocomplete="off" aria-label="Raio do fillet, em metros"> m</label><small id="pc-fillet-passo"></small></span><span id="pc-dica" role="status"></span><span id="pc-resumo"></span><button type="button" class="btn primary" id="pc-continuar" onclick="PranchetaCad.dados(true)">Dados e finalizar →</button></footer>`
    // O painel do Snap fecha ao clicar fora dele.
    modal.addEventListener('pointerdown',ev=>{if(!ev.target.closest?.('.pc-snap-grupo'))opcoesSnap(false)})
    // A tela permanece inteira: apenas seu contêiner passa a ser a expansão da régua.
    const janela=document.createElement('div');janela.className='pc-janela'
    while(modal.firstChild)janela.appendChild(modal.firstChild)
    const reguaSlot=document.createElement('div');reguaSlot.className='pc-regua-slot'
    modal.append(reguaSlot,janela)
    reguaSlot.addEventListener('click',async ev=>{
      const botao=ev.target.closest('button');if(!botao||!s)return
      ev.preventDefault();ev.stopImmediatePropagation()
      if(await fechar())botao.click()
    },true)
    ;($('t-mapa')||document.body).appendChild(modal);svg=$('pc-svg')
    svg.addEventListener('pointerdown',down);svg.addEventListener('pointermove',move);svg.addEventListener('pointerup',up)
    // Sem isto o navegador abre a "rolagem automática" ao apertar a roda.
    svg.addEventListener('mousedown',ev=>{if(ev.button===1)ev.preventDefault()});svg.addEventListener('auxclick',ev=>{if(ev.button===1)ev.preventDefault()})
    svg.addEventListener('pointercancel',()=>{if(s?.drag){s.linhas=s.drag.antes.linhas;s.drag=null;recalcular();render()}})
    svg.addEventListener('dblclick',ev=>{ev.preventDefault();terminar()})
    svg.addEventListener('contextmenu',ev=>{ev.preventDefault();terminar()})
    svg.addEventListener('wheel',wheel,{passive:false})
    modal.addEventListener('keydown',ev=>{ev.stopPropagation();tecla(ev)})
    $('pc-valor').addEventListener('keydown',ev=>{ev.stopPropagation();if(ev.key==='Enter'){ev.preventDefault();medida()}if(ev.key==='Escape'){$('pc-entrada').hidden=true;svg.focus()}})
    $('pc-raio').addEventListener('keydown',ev=>{ev.stopPropagation();if(ev.key==='Enter'){ev.preventDefault();raioDigitado()}if(ev.key==='Escape'){ev.preventDefault();svg.focus()}})
    $('pc-raio').addEventListener('change',()=>{if(s)raioDigitado()})
    $('pc-valor').addEventListener('input',()=>{if(s){s.valor=$('pc-valor').value;render()}})
    svg.setAttribute('tabindex','0')
    resize=new ResizeObserver(()=>{if(s){dimensoes();render()}});resize.observe(svg)
  }
  function dimensoes(){const b=svg.getBoundingClientRect();s.w=Math.max(200,b.width);s.h=Math.max(180,b.height);svg.setAttribute('viewBox',`0 0 ${s.w} ${s.h}`)}
  function larguraDesenho(){return s.w>900&&!$('pc-dados').hidden?Math.max(300,s.w-$('pc-dados').offsetWidth-28):s.w}
  function tela(p){const q=G.rot(p,s.angulo);return [(q[0]-s.centro[0])*s.escala+larguraDesenho()/2,s.h/2-(q[1]-s.centro[1])*s.escala]}
  function mundo(p){return G.rot([(p[0]-larguraDesenho()/2)/s.escala+s.centro[0],(s.h/2-p[1])/s.escala+s.centro[1]],-s.angulo)}
  function ponto(ev){const b=svg.getBoundingClientRect();return mundo([ev.clientX-b.left,ev.clientY-b.top])}
  function ring(f){const r=f.geometry.coordinates[0].map(s.plano.para);return s.tipo==='unificacao'&&f.geometry===s.uniao?G.juntarFaces(r,.02):r}
  function bounds(ps){const xs=ps.map(p=>p[0]),ys=ps.map(p=>p[1]);return [Math.min(...xs),Math.min(...ys),Math.max(...xs),Math.max(...ys)]}
  function enquadrar(){if(!s)return;dimensoes()
    // Lote novo não tem contorno de origem: enquadra o que já foi desenhado, ou
    // abre com cerca de 60 m de largura em volta do ponto escolhido no mapa.
    let pts=s.originais.flatMap(f=>ring(f).map(p=>G.rot(p,s.angulo)))
    if(!pts.length)pts=s.linhas.flatMap(l=>l.pontos.map(p=>G.rot(p,s.angulo)))
    if(!pts.length){s.centro=[0,0];s.escala=Math.max(1,larguraDesenho()/60);render();return}
    const b=bounds(pts);s.centro=[(b[0]+b[2])/2,(b[1]+b[3])/2];s.escala=Math.min((larguraDesenho()-100)/Math.max(1,b[2]-b[0]),(s.h-150)/Math.max(1,b[3]-b[1]));render()}
  function snap(p,ev,base=null,ignorar=null){
    s.captura=null
    if(ev?.altKey)return p
    let dist=12/s.escala,melhor=null
    const testar=(q,t)=>{const d=G.len(G.sub(q,p));const empate=melhor&&Math.abs(d-dist)<=1e-6;if((d<dist&&!empate)||(empate&&(t==='Endpoint'||(t==='Perpendicular'&&s.captura.t!=='Endpoint')))){dist=d;melhor=q;s.captura={p:q,t}}}
    for(const [a,b] of faces(ignorar)) {
      if($('pc-end').checked){testar(a,'Endpoint');testar(b,'Endpoint')}
      if($('pc-mid').checked)testar(G.mul(G.add(a,b),.5),'Midpoint')
    }
    if(base&&$('pc-perp').checked)for(const [a,b] of faces(ignorar)){const q=G.pe(base,a,b,false),d=G.sub(b,a),t=G.dot(G.sub(q,a),d)/G.dot(d,d);if(t>=0&&t<=1&&G.len(G.sub(q,base))>.001)testar(q,'Perpendicular')}
    // O primeiro vértice do traço em curso: é clicando nele que o contorno fecha.
    if(s.traco.length>=2&&$('pc-end').checked)testar(s.traco[0],'Endpoint')
    if(!melhor&&$('pc-near').checked)for(const [a,b] of faces(ignorar))testar(G.pe(p,a,b),'Na face')
    return melhor||p
  }
  function contornos(){return s.tipo==='unificacao'&&s.uniao?[{geometry:s.uniao}]:s.originais}
  function faces(ignorar=null){return [...facesFixas(),...s.linhas.filter(l=>l.id!==ignorar).flatMap(l=>l.pontos.slice(1).map((b,i)=>[l.pontos[i],b]))]}
  // Faces que não se movem: o contorno de origem e, no desenho livre, os vizinhos
  // — é neles que o lote novo encosta.
  function facesFixas(){const fs=[...contornos(),...(livre()?s.contexto.filter(f=>f.geometry?.coordinates?.[0]):[])];return fs.flatMap(f=>{const r=ring(f);return r.slice(1).map((b,i)=>[r[i],b])})}
  // O lado sob o ponteiro, para a Concordância: primeiro os desenhados, depois os fixos.
  function segmentoPerto(p){let d=16/s.escala,m=null;const t=(a,b,sel)=>{const x=G.len(G.sub(G.pe(p,a,b),p));if(x<d){d=x;m=sel}}
    for(const l of s.linhas)for(let i=1;i<l.pontos.length;i++)t(l.pontos[i-1],l.pontos[i],{id:l.id,i:i-1})
    if(m)return m;for(const [a,b] of facesFixas())t(a,b,{id:null,a,b});return m}
  // O raio do Fillet mora no RODAPÉ, à vista enquanto o Fillet está ativo (ver
  // render): a caixa flutuante cobria o desenho bem onde se escolhe o lado.
  function entradaRaio(){$('pc-entrada').hidden=true;$('pc-fillet').hidden=false;$('pc-raio').value=String(s.raio).replace('.',',')}
  function raioDigitado(){const n=Number(String($('pc-raio').value).replace(',','.').trim());if(!Number.isFinite(n)||n<0){$('pc-fillet-passo').textContent='Raio inválido.';return}s.raio=n;s.fillet.erro=null;render();svg.focus()}
  function facePerto(p){let d=16/s.escala,melhor=null;for(const f of faces()){const x=G.len(G.sub(G.pe(p,...f),p));if(x<d){d=x;melhor=f}}return melhor}
  function destino(p,ev){
    const a=s.traco.at(-1);if(!a)return snap(p,ev)
    if(s.ref){const n=G.normal(...s.ref),m=G.dot(G.sub(p,a),n);let q=G.add(a,G.mul(n,m));for(const f of faces()){const x=G.inter(a,G.add(a,n),...f,false);if(x&&x.s>=0&&x.s<=1&&G.len(G.sub(x.p,q))<12/s.escala&&G.len(G.sub(x.p,a))>.001)q=x.p}s.captura={p:q,t:'⊥ 90° com a face escolhida'};return q}
    // ORTHO (F8, como no AutoCAD): só horizontal ou vertical DA TELA. Shift
    // inverte enquanto pressionado — liga com o Ortho desligado, e vice-versa.
    if(ortoAtivo(ev))return orto(a,p)
    return snap(p,ev,a)
  }
  // Ortho: a chave fica valendo entre aberturas da prancheta (deste navegador).
  let ortho=false;try{ortho=localStorage.getItem('pc-ortho')==='1'}catch{}
  function ortoAtivo(ev){return ortho!==!!ev?.shiftKey}
  function orto(a,p){const d=G.rot(G.sub(p,a),s.angulo);const q=Math.abs(d[0])>Math.abs(d[1])?[d[0],0]:[0,d[1]];return G.add(a,G.rot(q,-s.angulo))}
  function alternarOrtho(){ortho=!ortho;try{localStorage.setItem('pc-ortho',ortho?'1':'0')}catch{}$('pc-ortho')?.classList.toggle('ativo',ortho);$('pc-ortho')?.setAttribute('aria-pressed',String(ortho))}
  function ferramenta(t){
    if(!s||s.loading||s.busy||s.medindo!=null)return
    if(s.leitura&&!['selecionar','alinhar'].includes(t))return
    if(s.tipo==='unificacao'&&['linha','perpendicular','mover','offset','concordancia'].includes(t))return
    if(s.traco.length>1)terminar();else s.traco=[]
    s.ref=null;s.ferramenta=t;s.operacao=null;s.valor='';$('pc-entrada').hidden=true;s.fillet={primeiro:null,erro:null}
    if(t==='concordancia')entradaRaio()
    if(t==='mover'||t==='offset') { if(!selecionada())dica('Selecione uma linha. Depois use '+(t==='mover'?'Mover':'Offset')+'.');else iniciarOperacao(t) }
    render();svg.focus()
  }
  function dica(t){$('pc-dica').textContent=t}
  function selecionada(){return s.linhas.find(l=>l.id===s.sel)}
  function estado(){return {ordem:copy(s.ordem||[]),linhas:copy(s.linhas),anotacoes:copy(s.anotacoes),angulo:s.angulo,dados:copy(s.dados),numero:s.numero,justificativa:s.justificativa}}
  function antes(){s.undo.push(estado());if(s.undo.length>60)s.undo.shift();s.redo=[];s.revisado=null}
  function mudou(){s.revisado=null;$('pc-conferencia').replaceChildren();recalcular();render();agendar()}
  function historico(redo=false){if(!s||s.leitura)return;const de=redo?s.redo:s.undo,para=redo?s.undo:s.redo;if(!de.length)return;para.push(estado());Object.assign(s,de.pop());s.traco=[];s.operacao=null;s.sel=null;mudou()}
  function iniciarOperacao(t){const l=selecionada();if(!l)return;s.operacao={tipo:t,orig:copy(l.pontos),inicio:G.mul(G.add(l.pontos[0],l.pontos.at(-1)),.5),basePendente:t==='mover',antes:estado()};s.mouse=s.operacao.inicio;s.valor='';if(t==='offset')entrada(s.mouse,'Offset');else $('pc-entrada').hidden=true;dica(t==='mover'?'Clique no ponto de origem do movimento (com object snap). Depois indique o destino ou digite a distância.':'Afaste o cursor da linha; digite o offset com sinal ou clique para aplicar.')}
  function deltaOperacao(){const o=s.operacao;if(!o)return [0,0];const d=G.sub(s.mouse||o.inicio,o.inicio);const n=G.normal(o.orig[0],o.orig[1]);const numero=numeroDigitado();return o.tipo==='offset'?G.mul(n,numero??G.dot(d,n)):G.mul(G.unit(G.len(d)>1e-8?d:[1,0]),numero??G.len(d))}
  function numeroDigitado(){if(!s.valor?.trim())return null;const n=Number(s.valor.replace(',','.'));return Number.isFinite(n)?n:null}
  function pontosOperacao(){const o=s.operacao;if(!o)return null;const d=deltaOperacao();return o.tipo==='offset'?G.offset(o.orig,G.dot(d,G.normal(o.orig[0],o.orig[1]))):o.orig.map(p=>G.add(p,d))}
  function aplicarOperacao(){if(!s.operacao||s.operacao.basePendente)return;try{const p=pontosOperacao();antes();selecionada().pontos=p;s.operacao=null;s.valor='';s.captura=null;$('pc-entrada').hidden=true;mudou();svg.focus()}catch(err){dica(err.message)}}
  function down(ev){
    // PAN PELA RODA DO MOUSE (botão do meio), como no AutoCAD — e com QUALQUER
    // ferramenta ativa: a linha em curso continua; só a vista anda.
    if(s&&ev.button===1){ev.preventDefault();s.drag={pan:true,inicio:[ev.clientX,ev.clientY],centro:[...s.centro]};svg.setPointerCapture(ev.pointerId);return}
    if(!s||s.loading||s.busy||s.medindo!=null||ev.button!==0)return;ev.preventDefault();svg.focus();const p=ponto(ev);s.mouse=p
    const vertex=ev.target.closest('[data-vertex]'), hit=ev.target.closest('[data-line]')
    if(s.ferramenta==='concordancia'&&!s.leitura){
      const sg=segmentoPerto(p);s.fillet.erro=null
      if(!sg){s.fillet.erro='Clique sobre um lado.';render();return}
      if(!s.fillet.primeiro){s.fillet.primeiro=sg;render();return}
      const r=G.concordancia(s.linhas,s.fillet.primeiro,sg,s.raio),primeiro=s.fillet.primeiro;s.fillet.primeiro=null
      if(r.erro){s.fillet.erro=r.erro;render();return}
      antes();s.linhas=r.linhas;s.sel=r.unida||primeiro.id||sg.id;mudou();return
    }
    if(s.ferramenta==='alinhar'||s.ferramenta==='perpendicular'){
      const f=facePerto(p);if(!f){dica('Clique sobre a face que será a referência.');return}
      if(s.ferramenta==='alinhar'){if(!s.leitura)antes();const d=G.sub(f[1],f[0]);s.angulo=-Math.atan2(d[1],d[0]);s.ferramenta='selecionar';enquadrar();if(!s.leitura)mudou()}
      else{s.ref=copy(f);s.ferramenta='linha';s.traco=[];dica('Face realçada. Clique no início e trace a perpendicular; digite a medida se desejar.');render()}return
    }
    if(s.operacao){const o=s.operacao;if(o.basePendente){o.inicio=snap(p,ev);o.basePendente=false;s.mouse=o.inicio;entrada(s.mouse,'Mover');dica('Origem fixada. Capture o destino com snap ou aponte a direção e digite a distância.');render()}else{s.mouse=snap(p,ev,o.inicio,s.sel);if(o.tipo==='mover'&&ortoAtivo(ev))s.mouse=orto(o.inicio,s.mouse);aplicarOperacao()}return}
    if(s.ferramenta==='linha'&&!s.leitura){
      let q=destino(p,ev)
      if(!s.traco.length&&s.ref)q=G.pe(p,...s.ref)
      if(s.traco.length&&G.len(G.sub(q,s.traco.at(-1)))<.001)return
      // Clicar de novo no primeiro vértice fecha o contorno.
      if(s.traco.length>=3&&G.len(G.sub(q,s.traco[0]))<1e-6){s.traco.push([...s.traco[0]]);terminar();return}
      s.traco.push(q);s.valor='';entrada(q,'Comprimento');render();return
    }
    if(s.ferramenta==='anotar'&&!s.leitura){anotar(p);return}
    if(hit){
      const jaSelecionada=s.sel===hit.dataset.line
      s.sel=hit.dataset.line
      // Shift+clique num lado da linha selecionada insere um vértice ali.
      if(!s.leitura&&ev.shiftKey&&!vertex&&jaSelecionada){const l=selecionada();let melhor=null,dist=Infinity
        for(let i=1;i<l.pontos.length;i++){const q=G.pe(p,l.pontos[i-1],l.pontos[i]),d=G.len(G.sub(q,p));if(d<dist){dist=d;melhor={i,q}}}
        if(melhor){antes();l.pontos.splice(melhor.i,0,melhor.q);mudou()}return}
      if(!s.leitura){
        if(['mover','offset'].includes(s.ferramenta)){iniciarOperacao(s.ferramenta);render();return}
        s.drag={inicio:snap(p,ev),orig:copy(selecionada().pontos),antes:estado(),vertex:vertex?Number(vertex.dataset.vertex):null,mudou:false,fechada:G.fechada(selecionada().pontos)}
        svg.setPointerCapture(ev.pointerId)
      }render();return
    }
    // Clique no vazio: só desseleciona. Arrastar a vista é com a roda do mouse
    // (acima) — o Selecionar não é mais um pan. No toque, sem roda, um dedo no
    // vazio continua arrastando a vista.
    s.sel=null
    if(ev.pointerType==='touch'){s.drag={pan:true,inicio:[ev.clientX,ev.clientY],centro:[...s.centro]};svg.setPointerCapture(ev.pointerId)}
    render()
  }
  function move(ev){if(!s)return;const p=ponto(ev);s.mouse=p
    if(s.drag){const d=s.drag
      if(d.pan){s.centro=[d.centro[0]-(ev.clientX-d.inicio[0])/s.escala,d.centro[1]+(ev.clientY-d.inicio[1])/s.escala]}
      else{const alvo=snap(p,ev,d.inicio,s.sel),delta=G.sub(alvo,d.inicio);d.mudou ||= G.len(delta)>.001;const l=selecionada();if(l){l.pontos=copy(d.orig);if(d.vertex!==null){l.pontos[d.vertex]=alvo;const m=l.pontos.length-1;if(d.fechada&&(d.vertex===0||d.vertex===m))l.pontos[d.vertex===0?m:0]=[...alvo]}else l.pontos=l.pontos.map(q=>G.add(q,delta));recalcular()}}
    }else if(s.ferramenta==='linha'){s.mouse=destino(p,ev);if(s.traco.length)entrada(s.mouse,'Comprimento',true)}
    else if(s.operacao){const o=s.operacao;s.mouse=snap(p,ev,o.basePendente?null:o.inicio,o.basePendente?null:s.sel);if(!o.basePendente&&o.tipo==='mover'&&ortoAtivo(ev))s.mouse=orto(o.inicio,s.mouse);if(!o.basePendente)entrada(s.mouse,o.tipo==='offset'?'Offset':'Mover',true)}
    render()
  }
  function up(ev){if(!s?.drag)return;const d=s.drag;s.drag=null;if(svg.hasPointerCapture(ev.pointerId))svg.releasePointerCapture(ev.pointerId);if(!d.pan&&d.mudou){s.undo.push(d.antes);s.redo=[];mudou()}else render()}
  function wheel(ev){if(!s)return;ev.preventDefault();const b=svg.getBoundingClientRect(),p=[ev.clientX-b.left,ev.clientY-b.top],w=mundo(p);s.escala=Math.max(.01,Math.min(500,s.escala*Math.exp(-ev.deltaY*.001)));const q=G.rot(w,s.angulo);s.centro=[q[0]-(p[0]-larguraDesenho()/2)/s.escala,q[1]+(p[1]-s.h/2)/s.escala];render()}
  function terminar(){if(!s||s.leitura)return;if(s.traco.length>=2){antes();s.linhas.push({id:crypto.randomUUID(),pontos:copy(s.traco)});s.sel=s.linhas.at(-1).id;s.traco=[];mudou()}else s.traco=[];s.valor='';$('pc-entrada').hidden=true;render();dica('Linha encerrada. Clique em outro ponto para começar uma nova; V para selecionar.')}
  function excluir(){if(!s||s.leitura||!s.sel)return;antes();s.linhas=s.linhas.filter(l=>l.id!==s.sel);s.sel=null;s.operacao=null;mudou()}
  function entrada(p,label,mover=false){const el=$('pc-entrada'),q=tela(p);el.hidden=false;el.style.left=`${Math.max(8,Math.min(s.w-220,q[0]+20))}px`;el.style.top=`${Math.max(8,Math.min(s.h-45,q[1]-45))}px`;$('pc-entrada-label').textContent=label;if(!mover)$('pc-valor').value=''}
  function medida(){const m=numeroDigitado();if(m===null){dica('Digite uma medida válida em metros.');return}
    if(s.ferramenta==='concordancia'){if(m<0){dica('O raio não pode ser negativo.');return}s.raio=m;s.valor='';render();svg.focus();return}
    if(s.operacao){aplicarOperacao();return}
    if(s.traco.length){if(m<=0){dica('O comprimento deve ser positivo.');return}const a=s.traco.at(-1),d=G.sub(s.mouse||a,a);if(G.len(d)<.0001){dica('Aponte a direção da linha antes de digitar a medida.');return}s.traco.push(G.add(a,G.mul(G.unit(d),m)));s.valor='';$('pc-valor').value='';render();svg.focus()}
  }
  function tecla(ev){if(!s)return;ev.stopPropagation();if(ev.target.matches('input,textarea,select'))return
    if(s.medindo!=null){if(ev.key==='Escape'){ev.preventDefault();dados(true)}return}
    const k=ev.key.toLowerCase();if(ev.ctrlKey||ev.metaKey){if(k==='z'||k==='y'){ev.preventDefault();historico(k==='y'||ev.shiftKey)}return}
    if(k==='escape'){ev.preventDefault();s.traco=[];s.operacao=null;s.drag=null;s.ref=null;s.valor='';s.ferramenta='selecionar';$('pc-entrada').hidden=true;render();return}
    if(k==='enter'){ev.preventDefault();if(s.operacao)aplicarOperacao();else terminar();return}
    if(k==='delete'||k==='backspace'){ev.preventDefault();excluir();return}
    if(ev.key==='F8'){ev.preventDefault();alternarOrtho();render();return}
    const tools={v:'selecionar',l:'linha',p:'perpendicular',m:'mover',o:'offset',r:'alinhar',t:'anotar',f:'concordancia'}
    if(tools[k]){ev.preventDefault();ferramenta(tools[k]);return}
    if(/^[0-9.,]$/.test(k)&&s.ferramenta==='concordancia'){ev.preventDefault();entradaRaio();$('pc-raio').value=k;$('pc-raio').focus();return}
    if(/^[0-9.,-]$/.test(k)&&(s.traco.length||(s.operacao&&!s.operacao.basePendente))){ev.preventDefault();s.valor=k;entrada(s.mouse||s.traco.at(-1),s.operacao?.tipo==='offset'?'Offset':s.operacao?'Mover':'Comprimento');$('pc-valor').value=k;$('pc-valor').focus()}
  }
  function posicaoConfronto(p,t){
    let melhor=null,dist=Infinity;
    const rs=contornos().map(ring);
    for(const r of rs)for(let i=1;i<r.length;i++){const q=G.pe(p,r[i-1],r[i]),d=G.len(G.sub(p,q));if(d<dist){dist=d;melhor={q,n:G.mul(G.normal(r[i-1],r[i]),G.area(r)>0?-1:1)}}}
    if(!melhor)return p;
    const normalTela=G.rot(melhor.n,s.angulo),margem=(65+Math.abs(normalTela[0])*Math.min(String(t).length*3.5,180))/s.escala;
    if(dist>margem&&!rs.some(r=>G.dentro(p,r)))return p;
    return G.add(melhor.q,G.mul(melhor.n,margem))
  }
  function anotar(p){
    if(modal.querySelector('.pc-anotar'))return
    const n=document.createElement('form');n.className='pc-anotar';n.setAttribute('role','dialog');n.setAttribute('aria-label','Identificação do confronto');n.innerHTML='<div class="field"><label for="pc-confronto-texto">Identificação do confronto</label><input id="pc-confronto-texto" maxlength="160" placeholder="Ex.: Rua das Palmeiras ou Lote 2" required></div><div class="pc-acoes"><button class="btn out-cinza" type="button">Cancelar</button><button class="btn primary" type="submit">Aplicar</button></div>'
    $('pc-svg').parentElement.appendChild(n);n.querySelector('input').focus();n.querySelector('[type=button]').onclick=()=>{n.remove();svg.focus()};n.addEventListener('keydown',ev=>{ev.stopPropagation();if(ev.key==='Escape'){ev.preventDefault();n.remove();svg.focus()}});n.onsubmit=ev=>{ev.preventDefault();const texto=n.querySelector('input').value.trim();if(!texto)return;antes();s.anotacoes.push({p,texto});n.remove();mudou();svg.focus()}
  }
  function recalcular(){if(s.leitura)return
    // Desenho livre: o lote é a ÚNICA linha fechada. Linhas abertas ficam como
    // linhas de construção, e não entram no resultado.
    if(livre()){const fs=s.linhas.filter(l=>G.fechada(l.pontos));s.resultado=fs.length===1?{partes:[fs[0].pontos],erro:null}:{partes:[],erro:fs.length?'Há mais de um contorno fechado. Exclua o que sobra.':'Feche o contorno do lote: clique de novo no primeiro vértice, ou use o Fillet (F) com raio 0.',orientacao:!fs.length};return}
    if(s.tipo==='desmembramento'){s.resultado=G.dividir(ring(s.originais[0]),s.linhas.map(l=>l.pontos));const ordem=s.ordem||[];s.resultado.partes.sort((a,b)=>{const ia=ordem.indexOf(parteKey(a)),ib=ordem.indexOf(parteKey(b));return (ia<0?Infinity:ia)-(ib<0?Infinity:ib)})}else s.resultado={partes:s.uniao?[ring({geometry:s.uniao})]:[],erro:s.uniao?null:s.erroUniao||(s.loading?'Calculando unificação…':'Não foi possível preparar a unificação.')}}
  function path(ps,close=false){return ps.map((p,i)=>{const q=tela(p);return `${i?'L':'M'}${q[0].toFixed(3)},${q[1].toFixed(3)}`}).join(' ')+(close?'Z':'')}
  function texto(p,t,cls='pc-label'){const q=tela(p);return `<text class="${cls}" x="${q[0]}" y="${q[1]}" text-anchor="middle">${e(t)}</text>`}
  function cota(a,b,t,lado=1){const mid=G.mul(G.add(a,b),.5),q=tela(G.add(mid,G.mul(G.normal(a,b),lado*30/s.escala)));return `<text class="pc-cota" x="${q[0]}" y="${q[1]}" text-anchor="middle" dominant-baseline="middle">${e(t)}</text>`}
  function centro(r){const a=r.slice(0,-1);const m=G.mul(a.reduce(G.add,[0,0]),1/a.length);if(G.dentro(m,r))return m;for(let i=1;i<r.length;i++){const mid=G.mul(G.add(r[i-1],r[i]),.5),n=G.normal(r[i-1],r[i]);for(const sign of [1,-1]){const q=G.add(mid,G.mul(n,sign*.1));if(G.dentro(q,r))return q}}return m}
  function render(){if(!s||!svg)return
    let h='<defs><pattern id="pc-grid" width="25" height="25" patternUnits="userSpaceOnUse"><path d="M25 0H0V25" fill="none" stroke="#e8edf1" stroke-width=".6"/></pattern></defs><rect width="100%" height="100%" fill="#f8fafb"/><rect width="100%" height="100%" fill="url(#pc-grid)"/>'
    if(s.mapa)h+=tiles()
    for(const f of s.contexto){if(!f.geometry?.coordinates?.[0])continue;const r=ring(f),p=f.properties||{};h+=`<path d="${path(r,true)}" fill="#e7ebec" fill-opacity=".78" stroke="#9ca9af" stroke-width="1"/>`
      const vis=r.map(tela),b=bounds(vis);if(b[2]<0||b[0]>s.w||b[3]<0||b[1]>s.h)continue
      // Identificação no fragmento visível, mesmo com centro do vizinho fora.
      const q=[Math.max(65,Math.min(s.w-65,(Math.max(0,b[0])+Math.min(s.w,b[2]))/2)),Math.max(28,Math.min(s.h-28,(Math.max(0,b[1])+Math.min(s.h,b[3]))/2))]
      const nome='Q '+(p.quadra||'—')+' · Lote '+(p.numero_lote||'—');h+=texto(posicaoConfronto(mundo(q),nome),nome,'pc-vizinho')
    }
    // No Editar lote o contorno de origem é só referência: tracejado, por baixo.
    for(const f of contornos())h+=s.tipo==='edicao'?`<path d="${path(ring(f),true)}" fill="none" stroke="#6b7280" stroke-width="1.5" stroke-dasharray="6 4"/>`:`<path d="${path(ring(f),true)}" fill="white" fill-opacity=".93" stroke="#243b4b" stroke-width="2.5"/>`
    const partes=s.resultado?.partes||[]
    partes.forEach((r,i)=>{h+=`<path d="${path(r,true)}" fill="${cores[i%cores.length]}" fill-opacity=".11" stroke="${cores[i%cores.length]}" stroke-width="1.5"/>`;h+=texto(centro(r),`${s.tipo==='unificacao'?'Unificação':s.tipo==='novo'?'Lote novo':s.tipo==='edicao'?'Contorno editado':s.leitura?'Lote '+(s.finalFeatures?.[i]?.properties?.numero_lote||i+1):'Parte '+(i+1)} · ${fmtNum(Math.abs(G.area(r)))} m²`)})
    // Cotas das faces de origem, na mesma escala métrica usada nas ferramentas.
    for(const f of s.tipo==="edicao"?[]:contornos()){const r=ring(f);for(let i=1;i<r.length;i++){const m=G.len(G.sub(r[i],r[i-1]));if(m*s.escala<70)continue;h+=cota(r[i-1],r[i],`${fmtNum(m)} m`,G.area(r)>0?-1:1)}}
    for(const l of s.linhas){const sel=l.id===s.sel;h+=`<path data-line="${e(l.id)}" class="pc-hit" d="${path(l.pontos)}"/><path data-line="${e(l.id)}" d="${path(l.pontos)}" fill="none" stroke="${sel?'#ea580c':'#2563eb'}" stroke-width="${sel?3:2}"/>`
      if(sel){l.pontos.forEach((p,i)=>{const q=tela(p);h+=`<circle data-line="${e(l.id)}" data-vertex="${i}" cx="${q[0]}" cy="${q[1]}" r="5" fill="white" stroke="#ea580c" stroke-width="2"/>`});for(let i=1;i<l.pontos.length;i++)h+=cota(l.pontos[i-1],l.pontos[i],`${fmtNum(G.len(G.sub(l.pontos[i],l.pontos[i-1])))} m`)}
    }
    if(s.ref)h+=`<path d="${path(s.ref)}" stroke="#a855f7" stroke-width="5" opacity=".8"/>`
    // O primeiro lado escolhido na Concordância, à espera do segundo.
    const f1=s.fillet?.primeiro;if(f1){const l=f1.id!=null&&s.linhas.find(x=>x.id===f1.id),ab=f1.id==null?[f1.a,f1.b]:l?[l.pontos[f1.i],l.pontos[f1.i+1]]:null;if(ab?.[0]&&ab[1])h+=`<path d="${path(ab)}" stroke="#a855f7" stroke-width="6" opacity=".85"/>`}
    if(s.traco.length){let q=s.mouse||s.traco.at(-1);const n=numeroDigitado();if(n!==null&&n>0)q=G.add(s.traco.at(-1),G.mul(G.unit(G.sub(q,s.traco.at(-1))),n));h+=`<path d="${path([...s.traco,q])}" fill="none" stroke="#ea580c" stroke-width="2.5" stroke-dasharray="7 3"/>`;h+=cota(s.traco.at(-1),q,`${fmtNum(G.len(G.sub(q,s.traco.at(-1))))} m`);s.traco.forEach(p=>{const t=tela(p);h+=`<circle cx="${t[0]}" cy="${t[1]}" r="4" fill="#ea580c"/>`})}
    if(s.operacao&&!s.operacao.basePendente){try{h+=`<path d="${path(pontosOperacao())}" fill="none" stroke="#ea580c" stroke-width="3" stroke-dasharray="8 4"/>`;h+=texto(s.mouse,`${s.operacao.tipo==='offset'?'Offset':'Mover'} ${fmtNum(G.len(deltaOperacao()))} m`,'pc-cota')}catch{}}
    if(s.captura){const q=tela(s.captura.p),t=s.captura.t,k=t==='Endpoint'?'endpoint':t==='Midpoint'?'midpoint':t==='Na face'?'face':'perpendicular';const shapes={endpoint:'<rect x="-6" y="-6" width="12" height="12"/>',midpoint:'<path d="M0 -8L8 6H-8Z"/>',face:'<path d="M-6 -6L6 6M-6 6L6 -6"/>',perpendicular:'<path d="M-7 -8V7H8M-7 1H-1V7"/>'};h+=`<g data-snap="${k}" transform="translate(${q[0]} ${q[1]})" fill="none" stroke="#16a34a" stroke-width="2" pointer-events="none">${shapes[k]}</g><text class="pc-snap" x="${q[0]+14}" y="${q[1]+23}">${e(t)}</text>`}
    if(s.medindo!=null){const r=partes[s.medindo];if(r)for(let j=1;j<r.length;j++){const q=tela(G.mul(G.add(r[j-1],r[j]),.5));h+=`<g pointer-events="none"><circle cx="${q[0]}" cy="${q[1]}" r="12" fill="#006c16"/><text x="${q[0]}" y="${q[1]+4}" text-anchor="middle" fill="white" font-size="12">${j}</text></g>`}}
    for(const a of s.anotacoes)h+=texto(posicaoConfronto(a.p,a.texto),a.texto,'pc-confronto')
    const north=G.rot([0,1],s.angulo);h+=`<g transform="translate(${s.w-38} 42)"><path d="M0 0L${north[0]*22} ${-north[1]*22}" stroke="#243b4b" stroke-width="2"/><text x="${north[0]*32}" y="${-north[1]*32}" text-anchor="middle" font-size="12">N</text></g>`
    const passo=10**Math.floor(Math.log10(120/s.escala)), px=passo*s.escala;h+=`<path d="M25 ${s.h-35}v7h${px}v-7" fill="none" stroke="#243b4b"/><text x="25" y="${s.h-40}" font-size="11">${fmtNum(passo)} m</text>`
    svg.innerHTML=h
    for(const b of modal.querySelectorAll('[data-tool]')){b.classList.toggle('ativo',b.dataset.tool===s.ferramenta);b.setAttribute('aria-pressed',String(b.dataset.tool===s.ferramenta));b.disabled=(s.leitura&&!['selecionar','alinhar'].includes(b.dataset.tool))||(s.tipo==='unificacao'&&['linha','perpendicular','mover','offset','concordancia'].includes(b.dataset.tool))}
    $('pc-ref').textContent=s.ref?'⊥ Face de referência fixada · 90°':''
    // O rodapé não traz mais dicas; o resumo só aparece quando é ERRO (o
    // contorno que não fecha, a parte inválida) — esse a pessoa precisa ver.
    // "Feche o contorno" é orientação, não erro: fica fora do rodapé.
    $('pc-resumo').classList.toggle('erro',!!s.resultado?.erro&&!s.resultado?.orientacao)
    $('pc-resumo').textContent=s.resultado?.erro||(livre()?(partes[0]?`Contorno fechado · ${fmtNum(Math.abs(G.area(partes[0])))} m²`:"Carregando…"):`${s.linhas.length} linha(s) · ${partes.length} ${s.tipo==='unificacao'?'resultado(s)':'parte(s)'}`)
    if(!s.operacao)dica(s.leitura?'Visualização salva do ato.':s.ferramenta==='concordancia'?(s.fillet?.erro||`Concordância · raio ${fmtNum(s.raio)} m · clique no ${s.fillet?.primeiro?'2º':'1º'} lado. Raio 0 estende até o encontro; digite outro raio e Enter.`):s.ferramenta==='linha'&&livre()?'Clique nos cantos do lote · digite a medida · clique no primeiro vértice para fechar':s.ferramenta==='linha'?'Clique para desenhar · digite a medida · Enter encerra · novo clique inicia outra linha':s.ferramenta==='perpendicular'?'Clique na face à qual a nova linha será perpendicular.':s.ferramenta==='alinhar'?'Clique na face que deseja deixar horizontal.':s.ferramenta==='anotar'?'Clique na posição da identificação do confronto.':'Selecione e arraste uma linha ou um vértice · Shift+clique no lado insere vértice · M mover · O offset · F concordância')
    // Fillet ativo: o raio e o passo no rodapé; trocou de ferramenta, some.
    {const fl=s.ferramenta==='concordancia'&&!s.leitura;$('pc-fillet').hidden=!fl;if(fl){if(document.activeElement!==$('pc-raio'))$('pc-raio').value=String(s.raio).replace('.',',');$('pc-fillet-passo').textContent=s.fillet?.erro||(s.fillet?.primeiro?'clique no 2º lado':'clique no 1º lado · raio 0 = canto vivo')}}
  }
  function tiles(){
    const ps=[[0,0],[s.w,0],[s.w,s.h],[0,s.h]].map(p=>s.plano.de(mundo(p))),b=bounds(ps)
    const nmax=2**19,tx=lon=>(lon+180)/360*nmax,ty=lat=>(1-Math.asinh(Math.tan(lat*Math.PI/180))/Math.PI)/2*nmax
    let z=19
    while(z>10){const n=2**z,k=n/nmax;if((Math.ceil(tx(b[2])*k)-Math.floor(tx(b[0])*k)+1)*(Math.ceil(ty(b[1])*k)-Math.floor(ty(b[3])*k)+1)<=36)break;z--}
    const n=2**z,k=n/nmax;let h='<g opacity=".5">'
    const lon=x=>x/n*360-180,lat=y=>Math.atan(Math.sinh(Math.PI*(1-2*y/n)))*180/Math.PI
    for(let x=Math.floor(tx(b[0])*k);x<=Math.floor(tx(b[2])*k);x++)for(let y=Math.floor(ty(b[3])*k);y<=Math.floor(ty(b[1])*k);y++){
      const a=tela(s.plano.para([lon(x),lat(y)])),d=tela(s.plano.para([lon(x+1),lat(y)])),c=tela(s.plano.para([lon(x),lat(y+1)]))
      h+=`<image href="https://tile.openstreetmap.org/${z}/${x}/${y}.png" width="256" height="256" transform="matrix(${(d[0]-a[0])/256} ${(d[1]-a[1])/256} ${(c[0]-a[0])/256} ${(c[1]-a[1])/256} ${a[0]} ${a[1]})"/>`
    }return h+'</g>'
  }
  function norte(){if(!s)return;s.angulo=0;enquadrar();if(!s.leitura)mudou()}
  function mapa(v){s.mapa=v;render();if(!s.leitura)agendar()}
  function agendar(){clearTimeout(saveTimer);saveTimer=setTimeout(()=>salvar().catch(()=>{}),900)}
  function visualizacao(){return {versao:1,dados:copy(s.dados),ordem:s.ordem||[],angulo:s.angulo,mapa:s.mapa,linhas:s.linhas.map(l=>({id:l.id,pontos:l.pontos.map(s.plano.de)})),anotacoes:s.anotacoes.map(a=>({...a,p:s.plano.de(a.p)})),contexto:s.contexto}}
  function payload(){return {tipo:s.tipo,ids:s.ids,protocolo_id:s.protocoloId}}
  async function request(url,body){
    let r;try{r=await fetch(url,{method:'POST',signal:AbortSignal.timeout(15000),headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify(body)})}catch{throw new Error('Sem resposta do servidor. Verifique a conexão e tente novamente.')}
    let d;try{d=await r.json()}catch{throw new Error('Resposta inválida do servidor (HTTP '+r.status+'). Não foi possível confirmar a operação. Tente novamente; se persistir, informe este código ao suporte.')}
    if(!r.ok){const mensagens={401:'Sua sessão expirou. Salve o rascunho e entre novamente.',403:'Você não tem permissão para esta operação.',419:'Sua sessão expirou. Guarde o rascunho e recarregue a página.',429:'Muitas tentativas. Aguarde um momento antes de tentar novamente.'};const err=new Error(mensagens[r.status]||(r.status>=500?'Erro interno do servidor (HTTP '+r.status+'). Tente novamente; se persistir, contate o suporte.':d.message||'Não foi possível concluir.'));err.status=r.status;err.campos=r.status===422?d.errors:null;throw err}return d
  }
  function chaveLocal(){return 'prancheta-recuperacao:'+String(window.USUARIO_ID??'sessao')+':'+s.tipo+':'+[...s.ids].sort((a,b)=>a-b).join(',')+':'+(s.protocoloId||0)}
  function guardarLocal(corpo){try{localStorage.setItem(chaveLocal(),JSON.stringify(corpo.estado));return true}catch{return false}}

  async function salvar(){
    if(!s||s.leitura||s.loading)return;clearTimeout(saveTimer);
    // Lote novo e Editar lote não têm rascunho no servidor (a rota de
    // pranchetas é do ato cadastral); a cópia fica neste navegador.
    if(livre()){guardarLocal({estado:{...visualizacao(),traco:s.traco.map(s.plano.de)}});$('pc-salvo').textContent='Rascunho guardado neste navegador';return}
    const sessao=s,k=chaveLocal(),corpo=copy({...payload(),estado:{...visualizacao(),dados:s.dados,numero:s.numero,justificativa:s.justificativa,traco:s.traco.map(s.plano.de)}});
    const local=guardarLocal(corpo);$('pc-salvo').textContent='Salvando…';
    fila=fila.catch(()=>{}).then(()=>request('/api/pranchetas/salvar',corpo));
    try{await fila;if(s===sessao){$('pc-salvo').textContent='Rascunho salvo no servidor';try{if(localStorage.getItem(k)===JSON.stringify(corpo.estado))localStorage.removeItem(k)}catch{}}}
    catch(err){if(s===sessao){s.recuperacaoLocal=local;$('pc-salvo').textContent=local?'Rascunho apenas neste navegador · falha no servidor':err.message}throw err}
  }
  function restaurar(v){s.ordem=v.ordem||[];s.angulo=Number(v.angulo)||0;s.mapa=v.mapa!==false;s.linhas=(v.linhas||[]).map(l=>({...l,pontos:l.pontos.map(s.plano.para)}));s.anotacoes=(v.anotacoes||[]).map(a=>({...a,p:s.plano.para(a.p)}));s.dados=v.dados||{};s.numero=v.numero||'';s.justificativa=v.justificativa||s.justificativa;s.traco=(v.traco||[]).map(s.plano.para);$('pc-mapa').checked=s.mapa}
  /**
   * `opts` serve aos dois modos de desenho livre:
   *   novo    {centro:[lon,lat], bairro, aoConcluir(geometry)} — nada de origem;
   *   edicao  {quadra, numero_lote, aoSalvar()} — o lote de `ids[0]` vira linha editável.
   */
  async function abrir(tipo,ids,protocoloId=null,salva=null,opts={}){
    montar();if(s&&!s.leitura)await salvar();const token=++geracao
    const originais=salva?.originais||(tipo==='novo'?[]:await Promise.all(ids.map(async id=>{const f=state.lotes.get(id);if(f)return f;const r=await fetch(`/api/imoveis/${id}/geometria`,{headers:{Accept:'application/json'}});if(!r.ok)throw new Error('Não foi possível ler o contorno do imóvel.');const d=await r.json();return d.type==='Feature'?d:{type:'Feature',geometry:d.geometry||d,properties:{id}}})))
    if(token!==geracao)return
    if(originais.some(f=>f.geometry?.type!=='Polygon'||f.geometry.coordinates.length!==1)){toast('A prancheta atende polígonos sem vazios internos.', 'err');return}
    if($('t-mapa')&&!$('t-mapa').classList.contains('at')&&typeof irPara==='function')irPara('mapa')
    const centroGeo=opts.centro||originais[0].geometry.coordinates[0][0]
    s={tipo,ids:ids.map(Number),protocoloId,originais,opts,plano:G.plano(centroGeo),linhas:[],traco:[],anotacoes:[],contexto:[],angulo:0,centro:[0,0],escala:1,undo:[],redo:[],dados:{},numero:'',justificativa:atoState.justificativa||'',ferramenta:tipo==='novo'?'linha':'selecionar',sel:null,ref:null,operacao:null,drag:null,mouse:null,mapa:true,valor:'',leitura:!!salva,resultado:{partes:[]},uniao:null,revisado:null,raio:0,fillet:{primeiro:null,erro:null}}
    s.loading=!salva
    // Editar lote: o próprio contorno é a linha que se edita (fechada).
    if(tipo==='edicao'&&!salva){const r=ring(originais[0]);s.linhas=[{id:crypto.randomUUID(),pontos:r}];s.sel=s.linhas[0].id}
    // Lote novo reaberto para correção: o contorno que já estava pronto volta.
    if(tipo==='novo'&&opts.contorno){s.linhas=[{id:crypto.randomUUID(),pontos:opts.contorno.coordinates[0].map(s.plano.para)}];s.ferramenta='selecionar'}
    if(salva){restaurar(salva);s.contexto=salva.contexto||[];s.finalFeatures=salva.resultantes;if(tipo==='unificacao')s.uniao=salva.resultantes[0]?.geometry;s.resultado={partes:salva.resultantes.map(ring)}}
    const titulos={desmembramento:'Desmembramento',unificacao:'Unificação',novo:'Lote novo',edicao:'Editar lote'}
    $('pc-titulo').textContent=(salva?'Prancha finalizada · ':'')+(titulos[tipo]||tipo)
    $('pc-origem').textContent=tipo==='novo'?(opts.bairro||'Desenhe o contorno do lote'):originais.map(f=>`Q ${f.properties?.quadra||opts.quadra||'—'} · Lote ${f.properties?.numero_lote||opts.numero_lote||f.properties?.id}`).join(' + ')
    $('pc-continuar').textContent=tipo==='novo'?'Informar os dados →':tipo==='edicao'?'Número, quadra e salvar →':'Dados e finalizar →'
    $('pc-salvo').textContent=salva?'Visualização preservada':'';$('pc-save').hidden=!!salva;$('pc-continuar').hidden=!!salva;$('pc-dados').hidden=true;$('pc-entrada').hidden=true
    // A prancheta ocupa o mapa INTEIRO e fica sozinha: a régua da curadoria não
    // vem mais para dentro dela (trocar de ferramenta é sair da prancheta).
    modal.classList.remove('pc-com-regua')
    $('pc-ortho')?.classList.toggle('ativo',ortho);$('pc-snap-pop')&&($('pc-snap-pop').hidden=true)
    modal.hidden=false;document.body.classList.add('prancheta-aberta');dimensoes();enquadrar();svg.focus()
    if(!salva){
      if(!livre())try{const d=await request('/api/pranchetas/carregar',payload());if(token!==geracao)return;s.identidade=d.identidade;if(d.estado){restaurar(d.estado);$('pc-salvo').textContent='Rascunho recuperado'}}catch(err){$('pc-salvo').textContent=err.message}
      // Vizinhos em volta: do contorno de origem ou, no lote novo, do ponto do mapa.
      // `revisao=1` traz também os lotes em revisão (o servidor só atende curador).
      const pts=originais.length?originais.flatMap(f=>f.geometry.coordinates[0]):[opts.centro],b=bounds(pts),m=originais.length?.00035:.0007
      try{const r=await fetch(`/api/mapa/lotes?bbox=${[b[0]-m,b[1]-m,b[2]+m,b[3]+m].map(x=>x.toFixed(8)).join(',')}&revisao=1`,{headers:{Accept:'application/json'}});if(r.ok){const d=await r.json();if(token!==geracao)return;s.contexto=(d.features||[]).filter(f=>!s.ids.includes(Number(f.properties.id)))}}catch{}
      if(tipo==='unificacao')try{const d=await request(rota(true),{ids:s.ids});if(token!==geracao)return;s.erroUniao=d.impedimento||d.retrato?.erro_identidade;s.uniao=!s.erroUniao&&d.retrato?.geometry?.type==='Polygon'?d.retrato.geometry:null;s.numero=d.retrato?.sugestao_lote||'';s.numeroUniao=s.numero;s.inscricaoUniao=d.retrato?.inscricao||'';if(s.erroUniao)$('pc-salvo').textContent=s.erroUniao}catch(err){s.erroUniao=err.message;$('pc-salvo').textContent=err.message}
      if(token!==geracao)return;
      // Lote novo não recupera cópia local: sem lote de origem, a chave não diz
      // de qual desenho ela era, e traria o rascunho de outro lugar do mapa.
      if(tipo!=='novo')try{const local=localStorage.getItem(chaveLocal());if(local){restaurar(JSON.parse(local));$('pc-salvo').textContent=livre()?'Cópia local recuperada':'Cópia local recuperada · salve novamente no servidor'}}catch{}
      if(tipo==='unificacao')s.numero=s.numeroUniao||'';s.loading=false;recalcular();enquadrar()
    }
  }
  function rota(previa=false){return s.protocoloId?`/api/protocolos/${s.protocoloId}/${s.tipo}${previa?'/previa':''}`:`/api/lotes/${s.tipo==='unificacao'?'unificacao-direta':'desmembramento-direto'}${previa?'/previa':''}`}
  function apelido(v){if(!s.identidade?.base||!Number.isInteger(v)||v<0||v>999)return '';const zero=s.resultado.partes.some(r=>s.dados[parteKey(r)]?.desmembramento===0);let letras='';for(let i=v+(zero?1:0);i>0;i=Math.floor(i/26)){i--;letras=String.fromCharCode(65+i%26)+letras}return s.identidade.base+letras}
  function identidadeTexto(d){const p=s.identidade;if(!p?.prefixo)return 'Inscrição de origem indisponível. Corrija o cadastro antes de finalizar.';const sufixo=Number.isInteger(d.desmembramento)&&d.desmembramento>=0&&d.desmembramento<=999?String(d.desmembramento).padStart(3,'0'):'???';return `<span class="lote-tag-origem">DESMEMBRADO</span><br>Inscrição: <b>${e(p.inscricao.slice(0,-3)+sufixo)}</b><br>Apelido automático: <b>${e(apelido(d.desmembramento)||'—')}</b>${d.desmembramento===0?'<br>O original será preservado como inativo. Apenas um lote ativo poderá usar esta inscrição.':''}`}
  function prepararIdentidades(){if(s.tipo!=='desmembramento')return;const usados=new Set(s.identidade?.usados||[]);for(const r of s.resultado.partes){const v=s.dados[parteKey(r)]?.desmembramento;if(v!=null)usados.add(v)}
    for(const r of s.resultado.partes){const k=parteKey(r),d=s.dados[k]||={};if(d.desmembramento==null){let v=1;while(usados.has(v)&&v<=999)v++;if(v<=999){d.desmembramento=v;usados.add(v)}}d.numero_lote=apelido(d.desmembramento);if(d.area_matricula_m2==null)d.area_matricula_m2=Number(Math.abs(G.area(r)).toFixed(2))}
  }
  function parteKey(r){return r.map(p=>p.map(n=>n.toFixed(5)).join(',')).sort().join(';')}
  function classificarFaces(i){
    const r=s.resultado.partes[i];if(!r||s.leitura)return;s.medindo=i;
    const d=s.dados[parteKey(r)]||{},nomes={frente_m:'Frente',fundos_m:'Fundos',lado_direito_m:'Lado direito',lado_esquerdo_m:'Lado esquerdo'};
    $('pc-form').innerHTML=`<h3>Faces da parte ${i+1}</h3><p>Classifique os trechos numerados no desenho. Os comprimentos de cada grupo serão somados. Direita e esquerda: olhando da rua para o lote.</p><p>As medidas calculadas substituem os quatro campos desta parte; a área é preenchida pelo desenho e pode ser conferida no formulário.</p><div class="pc-faces">${r.slice(1).map((b,j)=>`<label>Face ${j+1} · ${fmtNum(G.len(G.sub(b,r[j])))} m<select data-face="${j}"><option value="">Escolha…</option>${Object.entries(nomes).map(([k,t])=>`<option value="${k}" ${d._faces?.[j]===k?'selected':''}>${t}</option>`).join('')}</select></label>`).join('')}</div><p id="pc-face-erro" role="status"></p><div class="pc-acoes"><button class="btn out-cinza" type="button" onclick="PranchetaCad.dados(true)">Cancelar</button><button class="btn primary" type="button" id="pc-aplicar-faces">Calcular medidas</button></div>`;
    $('pc-aplicar-faces').onclick=()=>{
      const classes=[...$('pc-form').querySelectorAll('[data-face]')].map(el=>el.value);
      if(classes.some(k=>!k)){$('pc-face-erro').textContent='Classifique todas as faces antes de aplicar.';return}
      const medidas={frente_m:0,fundos_m:0,lado_direito_m:0,lado_esquerdo_m:0};classes.forEach((k,j)=>medidas[k]+=G.len(G.sub(r[j+1],r[j])));
      antes();s.dados[parteKey(r)]={...d,...Object.fromEntries(Object.entries(medidas).map(([k,v])=>[k,classes.includes(k)?Number(v.toFixed(2)):null])),_faces:classes};s.medindo=null;mudou();dados(true)
    };render()
  }
  function corpo(){const v=visualizacao();if(s.tipo==='unificacao')return {ids:s.ids,numero_lote:s.numero,justificativa:s.justificativa,visualizacao:v};return {lote_id:s.ids[0],modo:'prancheta',derivar_ultima:false,justificativa:s.justificativa,visualizacao:v,partes:s.resultado.partes.map((r,i)=>({numero_lote:'',...s.dados[parteKey(r)],geometry:{type:'Polygon',coordinates:[r.map(s.plano.de)]}}))}}
  function ordenarParte(i,delta){
    if(!s||s.busy||s.leitura)return;const partes=s.resultado.partes,j=i+delta;if(j<0||j>=partes.length)return;
    antes();const ordem=partes.map(parteKey);[ordem[i],ordem[j]]=[ordem[j],ordem[i]];s.ordem=ordem;mudou();dados(true)
  }
  function sequenciar(){
    if(!s||s.busy||s.leitura)return;
    const inicio=Number($('pc-sequencia-inicio').value);
    if($('pc-sequencia-inicio').value===''||!Number.isInteger(inicio)||inicio<0||inicio>999){$('pc-conferencia').textContent='Informe o início da sequência entre 000 e 999.';return}
    const usados=new Set(s.identidade?.usados||[]),valores=[];let v=inicio;
    for(const r of s.resultado.partes){while(usados.has(v)&&v<=999)v++;if(v>999){$('pc-conferencia').textContent='Não há sufixos disponíveis para todas as partes a partir deste número.';return}valores.push(v);usados.add(v++)}
    antes();s.resultado.partes.forEach((r,i)=>{const d=s.dados[parteKey(r)]||={};d.desmembramento=valores[i];d.numero_lote=apelido(valores[i])});mudou();dados(true)
  }
  // O contorno fechado, em GeoJSON, com o fechamento exato que o servidor confere.
  function geometriaLivre(){const r=s.resultado.partes[0].map(p=>s.plano.de(p).map(n=>Number(n.toFixed(8))));r[r.length-1]=[...r[0]];return {type:'Polygon',coordinates:[r]}}
  // LOTE NOVO: a prancheta só desenha. Bairro, quadra, número e medidas são do
  // formulário que o Desenhar lote já tinha — ele recebe o contorno pronto.
  async function entregarLoteNovo(){const g=geometriaLivre(),cb=s.opts?.aoConcluir;s.entregue=true;await fechar(true);cb?.(g)}
  function formEdicao(){
    const o=s.originais[0]?.properties||{};s.dados.quadra??=o.quadra??s.opts.quadra??'';s.dados.numero_lote??=o.numero_lote??s.opts.numero_lote??''
    $('pc-dados').hidden=false
    $('pc-form').innerHTML=`<p>O lote está em revisão: a alteração fica no registro da importação, fora do Histórico do cadastro.</p>
      <div class="pc-campos"><label>Quadra<input id="pc-ed-quadra" maxlength="20" value="${e(s.dados.quadra)}"></label><label>Número do lote<input id="pc-ed-numero" maxlength="20" value="${e(s.dados.numero_lote)}"></label></div>
      <p>Área do desenho: <b>${fmtNum(Math.abs(G.area(s.resultado.partes[0])))} m²</b></p>
      <div class="pc-form-acoes"><button type="button" class="btn primary" onclick="PranchetaCad.conferirEdicao()">Conferir</button></div>`
    for(const [id,k] of [['pc-ed-quadra','quadra'],['pc-ed-numero','numero_lote']])$(id).addEventListener('input',ev=>{s.dados[k]=ev.target.value.trim();$('pc-conferencia').replaceChildren()})
    $('pc-conferencia').replaceChildren();enquadrar()
  }
  function corpoEdicao(){return {quadra:s.dados.quadra,numero_lote:s.dados.numero_lote,geometry:geometriaLivre()}}
  async function conferirEdicao(){
    if(!s||s.busy)return;if(!s.dados.quadra||!s.dados.numero_lote){$('pc-conferencia').textContent='Informe a quadra e o número do lote.';return}
    s.busy=true;$('pc-conferencia').textContent='Conferindo…'
    try{const d=await request(`/api/lotes/${s.ids[0]}/edicao/previa`,corpoEdicao())
      if(d.impedimento){exibirErro({message:d.impedimento});return}
      const r=d.retrato;$('pc-conferencia').innerHTML=`<p>Área: <b>${fmtNum(r.area_anterior_m2)} m²</b> → <b>${fmtNum(r.area_m2)} m²</b>.</p>${(r.vizinhos||[]).filter(v=>v.area_comum>0).map(v=>`<p>Encosta em Q${e(v.quadra)} Lt${e(v.lote)} (${fmtNum(v.area_comum)} m² em comum, dentro da tolerância).</p>`).join('')}<button type="button" class="btn primary" onclick="PranchetaCad.salvarEdicao()">Salvar lote</button>`
    }catch(err){exibirErro(err)}finally{s.busy=false}
  }
  async function salvarEdicao(){
    if(!s||s.busy)return;s.busy=true
    try{const d=await request(`/api/lotes/${s.ids[0]}/edicao`,corpoEdicao());try{localStorage.removeItem(chaveLocal())}catch{}
      const cb=s.opts?.aoSalvar;s.busy=false;await fechar(true);toast(d.message);limparLotesDoMapa();carregarLotesVisiveis();cb?.()
    }catch(err){exibirErro(err)}finally{if(s)s.busy=false}
  }
  function dados(abrir){
    if(!s)return;s.medindo=null;render();if(!abrir){$('pc-dados').hidden=true;enquadrar();return}
    if(livre()){if(s.traco.length)terminar();if(s.resultado.erro){dica(s.resultado.erro);return}if(s.tipo==='novo'){entregarLoteNovo();return}formEdicao();return}
    if(s.tipo==='unificacao'&&!s.uniao){dica(s.erroUniao||'Aguarde o cálculo da unificação.');return}if(s.traco.length)terminar();if(s.operacao){dica('Conclua ou cancele o movimento primeiro.');return}
    if(s.tipo==='desmembramento'&&(s.resultado.erro||s.resultado.partes.length<2)){dica(s.resultado.erro||'Trace uma divisão completa antes de preencher as partes.');return}
    prepararIdentidades();$('pc-dados').hidden=false;
    const campos=[['frente_m','Frente (m)'],['fundos_m','Fundos (m)'],['lado_direito_m','Lado direito (m)'],['lado_esquerdo_m','Lado esquerdo (m)'],['area_matricula_m2','Área (m²) · desenho']];
    let h=s.tipo==='unificacao'?`<div class="pc-identidade"><span class="lote-tag-origem">UNIFICADO</span><p>Inscrição: <b>${e(s.inscricaoUniao)}</b></p><p>Área: <b>${fmtNum(Math.abs(G.area(s.resultado.partes[0])))} m²</b></p></div><label>Lote resultante · menor + maior<input id="pc-numero" readonly maxlength="20" value="${e(s.numero)}"></label><button class="btn out-verde" type="button" onclick="PranchetaCad.classificarFaces(0)">Conferir medidas pelas faces</button><div class="pc-campos">${campos.map(([k,t])=>`<label>${t}<input data-parte="0" data-campo="${k}" type="number" min="0" step="0.01" value="${e(s.dados[parteKey(s.resultado.partes[0])]?.[k]??(k==='area_matricula_m2'?Number(Math.abs(G.area(s.resultado.partes[0])).toFixed(2)):''))}"></label>`).join('')}</div>`:
      `<section class="pc-sequencia"><div class="pc-sequencia-acoes"><label for="pc-sequencia-inicio">Sequência</label><input id="pc-sequencia-inicio" type="number" min="0" max="999" step="1" value="1"><button type="button" class="btn out-verde" onclick="PranchetaCad.sequenciar()">Aplicar</button></div></section>`+
      s.resultado.partes.map((r,i)=>{
        const d=s.dados[parteKey(r)]||{},prefixo=s.identidade?.inscricao?.slice(0,-3)||'Inscrição indisponível';
        return `<fieldset class="pc-parte-card" style="--parte-cor:${cores[i%cores.length]}"><legend>Parte ${i+1}</legend>
          <header class="pc-parte-topo"><div><b>${e(d.numero_lote||'Sem apelido')}</b><small>${fmtNum(Math.abs(G.area(r)))} m² · DESMEMBRADO</small></div><div class="pc-ordem-botoes"><button class="btn sm out-cinza" type="button" aria-label="Mover parte ${i+1} para cima" ${i===0?'disabled':''} onclick="PranchetaCad.ordenarParte(${i},-1)">↑</button><button class="btn sm out-cinza" type="button" aria-label="Mover parte ${i+1} para baixo" ${i===s.resultado.partes.length-1?'disabled':''} onclick="PranchetaCad.ordenarParte(${i},1)">↓</button></div></header>
          <label class="pc-inscricao-label">Inscrição imobiliária<div class="pc-inscricao-editor"><span>${e(prefixo)}</span><input aria-label="Final da inscrição da parte ${i+1}" data-parte="${i}" data-campo="desmembramento" type="text" inputmode="numeric" maxlength="3" pattern="[0-9]{1,3}" value="${d.desmembramento==null?'':String(d.desmembramento).padStart(3,'0')}" placeholder="000"></div></label>
          <small class="pc-inscricao-ajuda">Edite os três últimos dígitos, inclusive 000. O prefixo é herdado do original.</small>
          <div class="pc-identidade" data-identidade="${i}">${identidadeTexto(d)}</div>
          <details class="pc-medidas"><summary>Dimensões e área</summary><button class="btn sm out-verde" type="button" onclick="PranchetaCad.classificarFaces(${i})">Calcular medidas pelas faces</button><div class="pc-campos">${campos.map(([k,t])=>`<label>${t}<input data-parte="${i}" data-campo="${k}" type="number" step="0.01" min="0" value="${e(d[k])}"></label>`).join('')}</div></details></fieldset>`
      }).join('');
    if(!s.protocoloId)h+=`<label>Justificativa do ato direto<textarea id="pc-motivo" minlength="10" maxlength="500" placeholder="Descreva o motivo da alteração cadastral">${e(s.justificativa)}</textarea></label>`;
    h+='<div class="pc-form-acoes"><button type="button" class="btn out-cinza" onclick="PranchetaCad.salvar().catch(()=>{})">Salvar rascunho</button><button type="button" class="btn primary" onclick="PranchetaCad.conferir()">Conferir e continuar</button></div>';
    $('pc-form').innerHTML=h;$('pc-conferencia').replaceChildren();
    for(const input of $('pc-form').querySelectorAll('[data-campo],#pc-numero,#pc-motivo'))input.addEventListener('input',()=>{
      if(input.dataset.campo){const k=parteKey(s.resultado.partes[Number(input.dataset.parte)]);s.dados[k]||={};
        s.dados[k][input.dataset.campo]=input.value.trim()===''?null:Number(input.value);
        if(input.dataset.campo==='desmembramento'){s.resultado.partes.forEach((r,i)=>{const d=s.dados[parteKey(r)];d.numero_lote=apelido(d.desmembramento);const identidade=$('pc-form').querySelector(`[data-identidade="${i}"]`);if(identidade){identidade.innerHTML=identidadeTexto(d);identidade.closest('fieldset').querySelector('.pc-parte-topo b').textContent=d.numero_lote||'Sufixo inválido'}})}
      }else if(input.id==='pc-numero')s.numero=input.value;else s.justificativa=input.value;
      s.revisado=null;limparErros();$('pc-conferencia').replaceChildren();agendar()
    });
    for(const input of $('pc-form').querySelectorAll('[data-campo="desmembramento"]'))input.addEventListener('blur',()=>{if(/^[0-9]{1,3}$/.test(input.value))input.value=input.value.padStart(3,'0')});enquadrar()
  }
  function limparErros(){for(const el of $('pc-form').querySelectorAll('[aria-invalid]')){el.removeAttribute('aria-invalid');el.removeAttribute('aria-describedby')}$('pc-form').querySelectorAll('.pc-erro-campo').forEach(el=>el.remove())}
  function campoErro(k){const m=k.match(/^partes\.(\d+)\.([a-z_0-9]+)$/);return m?$('pc-form').querySelector(`[data-parte="${m[1]}"][data-campo="${m[2]}"]`):k==='justificativa'?$('pc-motivo'):k==='numero_lote'?$('pc-numero'):null}
  function exibirErro(err){
    limparErros();s.revisado=null;const campos=err.campos||{},mensagens=[];let primeiro=null;
    for(const [k,valor] of Object.entries(campos)){const msg=Array.isArray(valor)?valor.join(' '):String(valor),input=campoErro(k);mensagens.push(msg);
      if(input){const aviso=document.createElement('small');aviso.className='pc-erro-campo';aviso.id='pc-erro-'+mensagens.length;aviso.textContent=msg;input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby',aviso.id);input.closest('label').appendChild(aviso);const details=input.closest('details');if(details)details.open=true;primeiro||=input}
    }
    $('pc-conferencia').innerHTML=`<div class="pc-erro-resumo" role="alert"><b>${e(mensagens.length?'Revise os campos indicados':err.message||'Não foi possível concluir.')}</b>${mensagens.length?'<ul>'+mensagens.map(m=>'<li>'+e(m)+'</li>').join('')+'</ul>':''}</div>`;
    const alvo=primeiro||$('pc-conferencia');alvo.focus();alvo.scrollIntoView({block:'nearest',behavior:'smooth'})
  }
  function validarDados(){
    const campos={};if(!s.protocoloId){const m=s.justificativa.trim();if(m.length<10||m.length>500)campos.justificativa='Informe a justificativa com 10 a 500 caracteres.'}
    if(s.tipo==='unificacao'){if(!s.numero.trim()||s.numero.length>20)campos.numero_lote='Informe o número do imóvel resultante (até 20 caracteres).'}
    else{
      const usados=new Set(s.identidade?.usados||[]);
      for(const [i,r] of s.resultado.partes.entries()){const d=s.dados[parteKey(r)]||{},v=d.desmembramento,k='partes.'+i+'.desmembramento',input=campoErro(k);
        if(!s.identidade?.prefixo)campos[k]='A inscrição do lote de origem está indisponível. Corrija o cadastro antes de finalizar.';
        else if(!Number.isInteger(v)||v<0||v>999||!input?.checkValidity())campos[k]='Parte '+(i+1)+': informe um final de inscrição entre 000 e 999.';
        else if(usados.has(v))campos[k]='Parte '+(i+1)+': este final de inscrição está repetido ou indisponível.';
        usados.add(v);
        for(const nome of ['frente_m','fundos_m','lado_direito_m','lado_esquerdo_m','area_matricula_m2']){const val=d[nome],max=nome==='area_matricula_m2'?100000000:100000;if(val!=null&&(!Number.isFinite(val)||val<0||val>max))campos['partes.'+i+'.'+nome]='Parte '+(i+1)+': informe uma medida entre 0 e '+fmtNum(max)+'.'}
      }
    }
    if(Object.keys(campos).length){exibirErro({campos});return false}limparErros();return true
  }
  async function conferir(){if(s.busy||!validarDados())return;const c=corpo();s.busy=true;$('pc-conferencia').textContent='Conferindo…';try{const d=await request(rota(true),c);if(JSON.stringify(c)!==JSON.stringify(corpo()))return;if(d.impedimento){exibirErro({message:d.impedimento});return}s.revisado=JSON.stringify(c);$('pc-conferencia').innerHTML=`<p>Geometria conferida. O ato preservará os imóveis de origem como inativos e criará os sucessores.</p>${(d.avisos||[]).map(a=>`<p>${e(a)}</p>`).join('')}<button type="button" class="btn primary" onclick="PranchetaCad.finalizar()">Confirmar e finalizar ${s.tipo==='unificacao'?'unificação':'desmembramento'}</button>`}catch(err){exibirErro(err)}finally{s.busy=false}}
  async function finalizar(){if(!s||s.busy||!validarDados())return;const c=corpo();if(s.revisado!==JSON.stringify(c)){dica('Confira os dados novamente.');return}if(!s.protocoloId&&s.justificativa.trim().length<10){$('pc-conferencia').textContent='Informe a justificativa do ato direto (mínimo de 10 caracteres).';return}s.busy=true;clearTimeout(saveTimer);try{await fila.catch(()=>{});const d=await request(rota(),c);try{localStorage.removeItem(chaveLocal())}catch{}s.leitura=true;s.finalFeatures=(d.lotes||[{id:d.id,numero_lote:s.numero}]).map((p,i)=>({properties:p}));$('pc-dados').hidden=true;$('pc-save').hidden=true;$('pc-continuar').hidden=true;$('pc-titulo').textContent='Prancha finalizada · '+s.tipo;$('pc-salvo').textContent='Desenho e orientação salvos com o ato';toast(d.message);atoState.tipo=null;limparLotesDoMapa();carregarLotesVisiveis();render()}catch(err){exibirErro(err)}finally{s.busy=false}}
  function fechar(forcar=false){
    if(!fechamento)fechamento=fecharSessao(forcar).finally(()=>{fechamento=null})
    return fechamento
  }
  async function fecharSessao(forcar=false){
    if(!s)return true;if(s.busy)return false;
    if(!s.leitura&&!forcar){try{await salvar()}catch(err){
      if(!s.recuperacaoLocal){let aviso=modal.querySelector('.pc-falha');if(!aviso){aviso=document.createElement('section');aviso.className='pc-anotar pc-falha';aviso.innerHTML='<p>Não foi possível salvar no servidor nem neste navegador. Sair agora perde as alterações não salvas.</p><div class="pc-acoes"><button class="btn out-cinza" type="button" onclick="this.closest(\'.pc-falha\').remove()">Continuar editando</button><button class="btn out-vermelho" type="button" onclick="PranchetaCad.fechar(true)">Sair sem salvar</button></div>';svg.parentElement.appendChild(aviso)}return}
      toast('Prancheta fechada. Rascunho guardado apenas neste navegador; reabra os mesmos lotes para recuperá-lo.','aviso')
    }}
    // Fechada SEM entregar o contorno (lote novo / edição): é desistir da
    // ferramenta. Sem isto o modo "Desenhar lote" ficava ligado, com a barra
    // "Abrir a prancheta" sobrando no mapa ao lado da barra da importação.
    const aoDesistir=!s.entregue&&!s.leitura?s.opts?.aoDesistir:null
    clearTimeout(saveTimer);geracao++;modal.querySelectorAll('.pc-anotar').forEach(n=>n.remove());modal.hidden=true;s=null;atoState.tipo=null;atoState.protocoloId=null
    if(reguaOrigem){reguaOrigem.parent.insertBefore($('cad-regua'),reguaOrigem.next);reguaOrigem=null}
    document.body.classList.remove('prancheta-aberta')
    if(typeof abrirMesaCadastral==='function')abrirMesaCadastral()
    if(typeof pintarPainelCadastro==='function')pintarPainelCadastro()
    aoDesistir?.()
    return true
  }
  function exportar(){if(!s)return;const clone=svg.cloneNode(true);clone.querySelectorAll('image,[data-snap],.pc-snap').forEach(n=>n.remove());clone.removeAttribute('id');clone.setAttribute('width',s.w);clone.setAttribute('height',s.h);const style=document.createElementNS('http://www.w3.org/2000/svg','style');style.textContent='.pc-label,.pc-cota,.pc-confronto,.pc-vizinho{font:12px sans-serif;fill:#243b4b;paint-order:stroke;stroke:white;stroke-width:4px}.pc-hit{fill:none;stroke:transparent;stroke-width:14}.pc-confronto{font-weight:bold}.pc-snap{font:12px sans-serif;fill:#16803c}';clone.prepend(style);const url=URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(clone)],{type:'image/svg+xml'}));const a=document.createElement('a');a.href=url;a.download=`${s.tipo}-${s.ids.join('-')}.svg`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)}
  async function verSalvas(id){try{const r=await fetch(`/api/lotes/${id}/pranchas`,{headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok)throw new Error(d.message);if(!d.pranchas.length){toast('Este imóvel ainda não possui prancha finalizada.', 'aviso');return}const p=d.pranchas[0];await abrir(p.tipo,p.visualizacao.originais.map(f=>f.properties.id),null,p.visualizacao)}catch(err){toast(err.message,'err')}}
  return {ativa:()=>!!s,opcoesSnap,ortho:()=>{alternarOrtho();if(s)render()},conferirEdicao,salvarEdicao,ordenarParte,sequenciar,classificarFaces,abrir,fechar,ferramenta,terminar,excluir,historico,salvar,exportar,enquadrar,norte,mapa,dados,conferir,finalizar,verSalvas}
})()
