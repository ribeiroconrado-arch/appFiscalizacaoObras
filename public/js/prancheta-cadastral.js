/* Prancheta CAD em plano métrico. A rotação pertence à vista, nunca ao cadastro. */
const PranchetaCad = (() => {
  const G=PranchetaGeo, cores=['#2563eb','#059669','#d97706','#7c3aed','#db2777','#0891b2']
  const copy=x=>structuredClone(x), $=id=>document.getElementById(id), e=x=>esc(String(x??''))
  let s=null, svg=null, modal=null, resize=null, saveTimer=null, fila=Promise.resolve(), geracao=0, reguaOrigem=null, fechamento=null
  const icones={esquadrejar:'M5 4v15h15M5 14h5v5M13 4l7 7',desfazer:'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',refazer:'M15 14l5-5-5-5M20 9H9a5 5 0 0 0 0 10h3',excluir:'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',encerrar:'M5 12.5l4.5 4.5L19 7',snap:'M6 3v8a6 6 0 0 0 12 0V3M6 3h4v4H6zM14 3h4v4h-4z',enquadrar:'M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3',norte:'M12 3l5 12-5-3-5 3zM12 15v6',ortho:'M5 4v15h15M5 12h7v7',selecionar:'M5 3l14 10-8 1-3 7z',linha:'M4 20L20 4M3 18v3h3M18 3h3v3',perpendicular:'M4 19h16M12 4v15M12 14h5v5',mover:'M12 2v20M2 12h20M8 6l4-4 4 4M8 18l4 4 4-4M6 8l-4 4 4 4M18 8l4 4-4 4',offset:'M4 19L16 7M8 21L20 9M8 7l6-6M8 1v6h6',alinhar:'M3 19h18M5 14l13-8M17 2l3 4-4 2',anotar:'M4 4h16M12 4v16M8 20h8',concordancia:'M4 21V13a9 9 0 0 1 9-9h8M4 4h3M4 4v3'}
  // "Livre": a prancheta desenha O CONTORNO do lote (lote novo, editar lote), em
  // vez de divisas dentro de um lote existente. Ver recalcular() e dados().
  // EDIFICAÇÃO: desenho livre do contorno de uma construção, com o lote por
  // referência (destacado), a imagem aérea por fundo e a frente do lote virada
  // para baixo — ver frenteParaBaixo.
  const edif=()=>!!s&&s.tipo==='edificacao'
  const livre=()=>!!s&&(s.tipo==='novo'||s.tipo==='edicao'||s.tipo==='edificacao')
  // FRAÇÃO IDEAL: o terreno fica; cada contorno FECHADO desenhado dentro dele é
  // a área exclusiva de uma unidade. Apartamento entra sem desenho, nos dados.
  const frac=()=>!!s&&s.tipo==='fracao_ideal'
  // ZOOM MÁXIMO da prancheta, em px por metro: 1 mm = 50 px. Era 500 (1 mm =
  // 0,5 px), pouco para encaixar diferenças de centímetro no desenho.
  const ESCALA_MAX=50000
  const pad3=v=>Number.isInteger(v)?String(v).padStart(3,'0'):''
  // MEDIDAS DA PRANCHETA: 4 casas no Editar lote (diferenças de 0,01 m² contra
  // o cadastro só se acham com elas), 2 nas demais ferramentas. O fmtNum da
  // tela (ui.js) continua com 2.
  const casas=()=>s?.tipo==='edicao'?4:2
  const fmtMed=n=>Number(n).toLocaleString('pt-BR',{minimumFractionDigits:casas(),maximumFractionDigits:casas()})
  // SÓ O ÍCONE na barra; o nome, o atalho e o que a ferramenta faz aparecem
  // ao passar o mouse (data-dica, ver prancheta-cadastral.css).
  function botao(k,t,atalho,desc='') {return `<button type="button" data-tool="${k}" data-dica="${t}${atalho?' ('+atalho+')':''}${desc?' — '+desc:''}" aria-label="${t}" onclick="PranchetaCad.ferramenta('${k}')"><svg viewBox="0 0 24 24"><path d="${icones[k]}"/></svg></button>`}
  function botaoAcao(ic,t,acao,desc='',id='') {return `<button type="button" ${id?`id="${id}"`:''} data-dica="${t}${desc?' — '+desc:''}" aria-label="${t}" onclick="${acao}"><svg viewBox="0 0 24 24"><path d="${icones[ic]}"/></svg></button>`}
  function opcoesSnap(abrir){
    const p=$('pc-snap-pop');if(!p)return
    p.hidden=abrir===undefined?!p.hidden:!abrir
    const botao=$('pc-snap')
    botao?.classList.toggle('ativo',!p.hidden)
    botao?.setAttribute('aria-expanded',String(!p.hidden))
    botao?.setAttribute('aria-controls','pc-snap-pop')
    if(!p.hidden){
      p.style.left='0px'
      const r=p.getBoundingClientRect()
      p.style.left=Math.min(0,window.innerWidth-8-r.right)+'px'
      p.style.maxHeight=Math.max(0,window.innerHeight-r.top-8)+'px'
    }
  }
  window.addEventListener('resize',()=>{if($('pc-snap-pop')?.hidden===false)opcoesSnap(true)})
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
${botaoAcao('esquadrejar','Esquadrejar','PranchetaCad.esquadrejar()','cantos a até 1° de 90° viram 90° exatos, mantendo a medida de cada lado')}${botao('anotar','Confronto','T','escreve o confrontante de um lado')}
      <i></i>${botaoAcao('desfazer','Desfazer (Ctrl+Z)','PranchetaCad.historico()')}${botaoAcao('refazer','Refazer (Ctrl+Y)','PranchetaCad.historico(true)')}${botaoAcao('excluir','Excluir linha (Delete)','PranchetaCad.excluir()','apaga a linha selecionada')}
      <i></i><span class="pc-snap-grupo">${botaoAcao('snap','Snap (OSNAP)','PranchetaCad.opcoesSnap()','onde o cursor encaixa: ponta, meio, na face, perpendicular','pc-snap')}
        <div class="pc-snap-pop" id="pc-snap-pop" hidden aria-label="Encaixe do cursor">
          <div class="pc-snap-heading">Encaixe do cursor <span>OSNAP</span></div>
          <div class="pc-snap-options">
          <label><span class="pc-snap-symbol" aria-hidden="true">□</span><span class="pc-snap-text">Extremidade <small>Endpoint · ponta da linha</small></span><input id="pc-end" type="checkbox" role="switch" checked></label>
          <label><span class="pc-snap-symbol" aria-hidden="true">△</span><span class="pc-snap-text">Ponto médio <small>Midpoint · centro da linha</small></span><input id="pc-mid" type="checkbox" role="switch" checked></label>
          <label><span class="pc-snap-symbol" aria-hidden="true">⊠</span><span class="pc-snap-text">Interseção <small>Cruzamento entre duas linhas</small></span><input id="pc-int" type="checkbox" role="switch" checked></label>
          <label><span class="pc-snap-symbol" aria-hidden="true">×</span><span class="pc-snap-text">Na face <small>Qualquer ponto sobre a linha</small></span><input id="pc-near" type="checkbox" role="switch" checked></label>
          <label><span class="pc-snap-symbol" aria-hidden="true">∟</span><span class="pc-snap-text">Perpendicular <small>Projeção a 90° sobre a linha</small></span><input id="pc-perp" type="checkbox" role="switch" checked></label>
          <div class="pc-snap-section">Exibição</div>
          <label><span class="pc-snap-text">Mapa cadastral <small>Referência de fundo</small></span><input id="pc-mapa" type="checkbox" role="switch" checked onchange="PranchetaCad.mapa(this.checked)"></label>
          </div><div class="pc-snap-alt"><kbd>Alt</kbd> suspenda o encaixe enquanto pressiona</div></div></span>${botaoAcao('ortho','Ortho (F8)','PranchetaCad.ortho()','linhas só na horizontal ou na vertical da tela, como no AutoCAD; Shift inverte enquanto pressionado','pc-ortho')}
      <i></i>${botao('alinhar','Alinhar face','R','gira a vista até a face clicada ficar na horizontal, embaixo do lote')}${botaoAcao('norte','Norte para cima','PranchetaCad.norte()','desfaz a rotação da vista')}${botaoAcao('enquadrar','Enquadrar','PranchetaCad.enquadrar()','mostra o desenho inteiro · arrastar a vista: roda do mouse pressionada; zoom: girar a roda')}
      </nav>
      <button type="button" class="pc-x" style="margin-left:auto" onclick="PranchetaCad.fechar()" aria-label="Fechar prancheta" data-dica="Fechar a prancheta (o rascunho fica guardado)">✕</button></header>
      <main class="pc-canvas"><span id="pc-ref" class="pc-ref"></span><svg id="pc-svg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Desenho dos imóveis e suas divisas"></svg>
      <div id="pc-entrada" hidden><label id="pc-entrada-label" for="pc-valor">Comprimento</label><input id="pc-valor" inputmode="decimal" autocomplete="off" aria-label="Medida em metros"><span>m · Enter</span></div>
      <div id="pc-cota-ed" hidden><label for="pc-cota-valor">Medida do lado</label><input id="pc-cota-valor" inputmode="decimal" autocomplete="off" aria-label="Nova medida do lado, em metros"><span>m</span>
        <button type="button" class="pc-cota-troca" title="Trocar a ponta que fica parada (Tab)" onclick="PranchetaCad.cotaTrocarPonta()">⇄ ponta</button>
        <label class="pc-cota-junto" title="O lado da ponta que anda desliza paralelo: os ângulos ficam como estão"><input type="checkbox" id="pc-cota-junto" onchange="PranchetaCad.cotaJunto(this.checked)"> levar o lado vizinho junto</label>
        <small><b class="pc-cota-fica">● verde fica</b> · <b class="pc-cota-anda">● laranja anda</b> · Enter aplica · Esc cancela</small></div>
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
    svg.addEventListener('dblclick',ev=>{ev.preventDefault();const c=ev.target.closest?.('[data-cota-line]');if(c){editarCota(c.dataset.cotaLine,Number(c.dataset.cotaI));return}terminar()})
    $('pc-cota-valor').addEventListener('keydown',ev=>{ev.stopPropagation();if(ev.key==='Enter'){ev.preventDefault();aplicarCota()}if(ev.key==='Escape'){ev.preventDefault();fecharCota()}if(ev.key==='Tab'){ev.preventDefault();trocarPontaCota()}})
    svg.addEventListener('contextmenu',ev=>{ev.preventDefault();terminar()})
    svg.addEventListener('wheel',wheel,{passive:false})
    modal.addEventListener('keydown',ev=>{ev.stopPropagation();tecla(ev)})
    $('pc-valor').addEventListener('keydown',ev=>{ev.stopPropagation();if(ev.key==='Enter'){ev.preventDefault();if(!$('pc-valor').value.trim()&&s?.traco.length&&s.ferramenta!=='concordancia')terminar();else medida()}if(ev.key==='Escape'){$('pc-entrada').hidden=true;svg.focus()}})
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
    // INTERSEÇÃO (como o OSNAP do AutoCAD): onde duas linhas se cruzam — a de
    // referência com o contorno em edição, por exemplo. Só as faces perto do
    // cursor entram no par a par.
    if($('pc-int')?.checked){const perto=faces(ignorar).filter(([a,b])=>G.len(G.sub(G.pe(p,a,b),p))<dist*2)
      for(let i=0;i<perto.length;i++)for(let j=i+1;j<perto.length;j++){const x=G.inter(...perto[i],...perto[j]);if(x)testar(x.p,'Interseção')}}
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
  /**
   * Tudo o que o OSNAP enxerga: o contorno de origem, os vizinhos, as linhas
   * desenhadas e os lados já traçados da linha em curso. `ignorar` é o id de
   * uma linha (ela inteira sai — mover/offset), ou {id, v, fechada} ao arrastar
   * um VÉRTICE: só os dois lados que encostam nele saem, e o resto do próprio
   * lote continua valendo como referência.
   */
  function faces(ignorar=null){
    const id=typeof ignorar==='string'?ignorar:null,vx=ignorar&&typeof ignorar==='object'?ignorar:null
    const lados=l=>l.pontos.slice(1).map((b,i)=>[l.pontos[i],b,i])
      .filter(([,,i])=>!vx||l.id!==vx.id||!(i===vx.v||i+1===vx.v||(vx.fechada&&vx.v===0&&i===l.pontos.length-2)||(vx.fechada&&vx.v===l.pontos.length-1&&i===0)))
      .map(([a,b])=>[a,b])
    const traco=s.traco.length>=2?s.traco.slice(1).map((b,i)=>[s.traco[i],b]):[]
    return [...facesFixas(),...s.linhas.filter(l=>l.id!==id).flatMap(lados),...traco]}
  // Faces que não se movem: o contorno de origem e, no desenho livre, os vizinhos
  // — é neles que o lote novo encosta.
  // Os VIZINHOS valem em toda ferramenta (antes, só no desenho livre).
  function facesFixas(){const fs=[...contornos(),...s.contexto.filter(f=>f.geometry?.coordinates?.[0])];return fs.flatMap(f=>{const r=ring(f);return r.slice(1).map((b,i)=>[r[i],b])})}
  // O lado sob o ponteiro, para a Concordância: primeiro os desenhados, depois os fixos.
  function segmentoPerto(p){let d=16/s.escala,m=null;const t=(a,b,sel)=>{const x=G.len(G.sub(G.pe(p,a,b),p));if(x<d){d=x;m=sel}}
    for(const l of s.linhas)for(let i=1;i<l.pontos.length;i++)t(l.pontos[i-1],l.pontos[i],{id:l.id,i:i-1})
    if(m)return m;for(const [a,b] of facesFixas())t(a,b,{id:null,a,b});return m}
  // O raio do Fillet mora no RODAPÉ, à vista enquanto o Fillet está ativo (ver
  // render): a caixa flutuante cobria o desenho bem onde se escolhe o lado.
  function entradaRaio(){$('pc-entrada').hidden=true;$('pc-fillet').hidden=false;$('pc-raio').value=String(s.raio).replace('.',',')}
  function raioDigitado(){const n=Number(String($('pc-raio').value).replace(',','.').trim());if(!Number.isFinite(n)||n<0){$('pc-fillet-passo').textContent='Raio inválido.';return}s.raio=n;s.fillet.erro=null;render();svg.focus()}
  /**
   * ALINHAR FACE: a face clicada fica na horizontal e EMBAIXO, com o lote
   * acima dela — como a testada na frente do lote (frenteParaBaixo). O lado
   * do lote é o que cai dentro de algum contorno, nesta ordem: o de origem, o
   * resultado ou uma linha fechada; depois os LOTES VIZINHOS. Sem os vizinhos,
   * a face de um vizinho não caía em contorno nenhum, valia o centro do
   * desenho (o lote em edição, em outro lugar) e a face subia (24/10/2026).
   * Sem nenhum contorno, vale o lado do centro do desenho.
   */
  function alinharFaceEmbaixo(f){
    const d=G.sub(f[1],f[0]);s.angulo=-Math.atan2(d[1],d[0])
    const meio=G.mul(G.add(f[0],f[1]),.5),n=G.normal(f[0],f[1])
    const aneis=[...contornos().map(ring),...(s.resultado?.partes||[]),...s.linhas.map(l=>l.pontos).filter(r=>r.length>=4&&G.len(G.sub(r[0],r.at(-1)))<1e-6)]
    const vizinhos=s.contexto.filter(c=>c.geometry?.coordinates?.[0]).map(ring)
    let dentro=null
    for(const grupo of [aneis,vizinhos]){
      for(const sinal of [1,-1]){const q=G.add(meio,G.mul(n,sinal*.3));if(grupo.some(r=>G.dentro(q,r))){dentro=q;break}}
      if(dentro)break
    }
    if(!dentro){const pts=aneis.flat();if(pts.length)dentro=G.mul(pts.reduce(G.add,[0,0]),1/pts.length)}
    // Lote abaixo da face na tela: meia-volta, para a face descer.
    if(dentro&&tela(dentro)[1]>tela(meio)[1])s.angulo+=Math.PI
  }
  /**
   * Os pontos em que um contorno cruza ou encosta em si mesmo: lados que não
   * são vizinhos e se tocam. É o que o servidor recusa com "O contorno se
   * cruza" — e a mensagem sozinha não dizia ONDE (24/10/2026). Pontos a menos
   * de 1 cm um do outro contam uma vez só.
   */
  function cruzamentosDe(r){
    const pts=r.length>1&&G.len(G.sub(r[0],r.at(-1)))<1e-9?r.slice(0,-1):r,n=pts.length,saida=[]
    if(n<4)return saida
    for(let i=0;i<n;i++)for(let j=i+2;j<n;j++){
      if(i===0&&j===n-1)continue   // o último lado encosta no primeiro: são vizinhos
      const x=G.inter(pts[i],pts[(i+1)%n],pts[j],pts[(j+1)%n])
      if(x&&!saida.some(p=>G.len(G.sub(p,x.p))<.01))saida.push(x.p)
    }
    return saida
  }
  /** A mensagem de contorno cruzado, apontando as marcas vermelhas do desenho. */
  function comCruzamentos(msg){
    const n=(s?.cruzamentos||[]).length
    return n&&/se cruza/i.test(msg)?`${msg} ${n===1?'O ponto marcado':`Os ${n} pontos marcados`} em vermelho no desenho ${n===1?'mostra':'mostram'} onde.`:msg
  }
  function facePerto(p){let d=16/s.escala,melhor=null;for(const f of faces()){const x=G.len(G.sub(G.pe(p,...f),p));if(x<d){d=x;melhor=f}}return melhor}
  function destino(p,ev){
    const a=s.traco.at(-1);if(!a)return snap(p,ev)
    if(s.ref){const n=G.normal(...s.ref),m=G.dot(G.sub(p,a),n);let q=G.add(a,G.mul(n,m));for(const f of faces()){const x=G.inter(a,G.add(a,n),...f,false);if(x&&x.s>=0&&x.s<=1&&G.len(G.sub(x.p,q))<12/s.escala&&G.len(G.sub(x.p,a))>.001)q=x.p}s.captura={p:q,t:'⊥ 90° com a face escolhida'};return q}
    // ORTHO (F8, como no AutoCAD): só horizontal ou vertical DA TELA. Shift
    // inverte enquanto pressionado — liga com o Ortho desligado, e vice-versa.
    // Com o Ortho ligado, o ponto CAPTURADO pelo OSNAP vale por cima (como no
    // AutoCAD); sem captura (ou só "Na face"), vale o Ortho.
    if(ortoAtivo(ev)){const q=snap(p,ev,a);if(s.captura&&s.captura.t!=='Na face')return q;s.captura=null;return orto(a,p)}
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
  /**
   * Ctrl+Z / Ctrl+Y. COM UMA LINHA EM CURSO (Line, antes de encerrar), desfaz
   * e refaz o ÚLTIMO SEGMENTO dela, como o "U" do LINE no AutoCAD — e não a
   * última alteração da prancheta. Sem linha em curso, o histórico de sempre.
   */
  function historico(redo=false){if(!s||s.leitura)return
    if(s.traco.length){s.tracoRedo||=[]
      if(!redo){s.tracoRedo.push(s.traco.pop());s.valor=''
        if(s.traco.length){entrada(s.traco.at(-1),'Comprimento');dica('Último segmento desfeito. Ctrl+Y refaz; continue clicando para seguir a linha.')}
        else{$('pc-entrada').hidden=true;dica('Linha em curso desfeita. Clique para começar de novo.')}
        render();return}
      if(s.tracoRedo.length){s.traco.push(s.tracoRedo.pop());entrada(s.traco.at(-1),'Comprimento');render();return}
    }
    // A linha em curso foi desfeita até o fim: Ctrl+Y ainda a traz de volta; Ctrl+Z segue para o histórico.
    if(redo&&!s.traco.length&&s.tracoRedo?.length){s.traco.push(s.tracoRedo.pop());entrada(s.traco.at(-1),'Comprimento');render();return}
    s.tracoRedo=[]
    const de=redo?s.redo:s.undo,para=redo?s.undo:s.redo;if(!de.length)return;para.push(estado());Object.assign(s,de.pop());s.traco=[];s.operacao=null;s.sel=null;mudou()}
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
    if(ev.target.closest?.('[data-cota-line]'))return
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
      if(s.ferramenta==='alinhar'){if(!s.leitura)antes();alinharFaceEmbaixo(f);s.ferramenta='selecionar';enquadrar();if(!s.leitura)mudou()}
      else{s.ref=copy(f);s.ferramenta='linha';s.traco=[];dica('Face realçada. Clique no início e trace a perpendicular; digite a medida se desejar.');render()}return
    }
    if(s.operacao){const o=s.operacao;if(o.basePendente){o.inicio=snap(p,ev);o.basePendente=false;s.mouse=o.inicio;entrada(s.mouse,'Mover');dica('Origem fixada. Capture o destino com snap ou aponte a direção e digite a distância.');render()}else{s.mouse=snap(p,ev,o.inicio,s.sel);if(o.tipo==='mover'&&ortoAtivo(ev))s.mouse=orto(o.inicio,s.mouse);aplicarOperacao()}return}
    if(s.ferramenta==='linha'&&!s.leitura){
      let q=destino(p,ev)
      if(!s.traco.length&&s.ref)q=G.pe(p,...s.ref)
      if(s.traco.length&&G.len(G.sub(q,s.traco.at(-1)))<.001)return
      // Clicar de novo no primeiro vértice fecha o contorno.
      if(s.traco.length>=3&&G.len(G.sub(q,s.traco[0]))<1e-6){s.traco.push([...s.traco[0]]);terminar();return}
      s.traco.push(q);s.tracoRedo=[];s.valor='';entrada(q,'Comprimento');render();return
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
      else{const alvo=snap(p,ev,d.inicio,d.vertex!==null?{id:s.sel,v:d.vertex,fechada:d.fechada}:s.sel),delta=G.sub(alvo,d.inicio);d.mudou ||= G.len(delta)>.001;const l=selecionada();if(l){l.pontos=copy(d.orig);if(d.vertex!==null){l.pontos[d.vertex]=alvo;const m=l.pontos.length-1;if(d.fechada&&(d.vertex===0||d.vertex===m))l.pontos[d.vertex===0?m:0]=[...alvo]}else l.pontos=l.pontos.map(q=>G.add(q,delta));recalcular()}}
    }else if(s.ferramenta==='linha'){s.mouse=destino(p,ev);if(s.traco.length)entrada(s.mouse,'Comprimento',true)}
    else if(s.operacao){const o=s.operacao;s.mouse=snap(p,ev,o.basePendente?null:o.inicio,o.basePendente?null:s.sel);if(!o.basePendente&&o.tipo==='mover'&&ortoAtivo(ev))s.mouse=orto(o.inicio,s.mouse);if(!o.basePendente)entrada(s.mouse,o.tipo==='offset'?'Offset':'Mover',true)}
    render()
  }
  function up(ev){if(!s?.drag)return;const d=s.drag;s.drag=null;if(svg.hasPointerCapture(ev.pointerId))svg.releasePointerCapture(ev.pointerId);if(!d.pan&&d.mudou){s.undo.push(d.antes);s.redo=[];mudou()}else render()}
  function wheel(ev){if(!s)return;ev.preventDefault();const b=svg.getBoundingClientRect(),p=[ev.clientX-b.left,ev.clientY-b.top],w=mundo(p);s.escala=Math.max(.01,Math.min(ESCALA_MAX,s.escala*Math.exp(-ev.deltaY*.001)));const q=G.rot(w,s.angulo);s.centro=[q[0]-(p[0]-larguraDesenho()/2)/s.escala,q[1]+(p[1]-s.h/2)/s.escala];render()}
  /**
   * EDITAR A MEDIDA DE UM LADO (duplo clique na cota). O lado passa a ter a
   * medida digitada, na MESMA DIREÇÃO: uma ponta fica parada (verde) e a outra
   * anda (laranja) — Tab troca. "Levar o lado vizinho junto" desliza também o
   * vértice seguinte, paralelo: os ângulos ficam (retângulo continua
   * retângulo). Sem ele, só a ponta anda e o lado vizinho gira um pouco.
   */
  function editarCota(id,i){if(!s||s.leitura)return;const l=s.linhas.find(x=>x.id===id);if(!l||!l.pontos[i]||!l.pontos[i-1])return
    s.sel=id;s.cotaEd={id,i,fixa:'a',junto:true}
    const el=$('pc-cota-ed'),q=tela(G.mul(G.add(l.pontos[i-1],l.pontos[i]),.5));el.hidden=false
    el.style.left=`${Math.max(8,Math.min(s.w-430,q[0]-120))}px`;el.style.top=`${Math.max(8,Math.min(s.h-45,q[1]+18))}px`
    $('pc-cota-valor').value=fmtMed(G.len(G.sub(l.pontos[i],l.pontos[i-1])));$('pc-cota-junto').checked=s.cotaEd.junto
    render();$('pc-cota-valor').focus();$('pc-cota-valor').select()
    dica('Digite a medida certa do lado. A ponta verde fica parada; Tab troca a ponta.')}
  function fecharCota(){if(!s)return;s.cotaEd=null;$('pc-cota-ed').hidden=true;render();svg.focus()}
  function trocarPontaCota(){if(!s?.cotaEd)return;s.cotaEd.fixa=s.cotaEd.fixa==='a'?'b':'a';render();$('pc-cota-valor').focus()}
  function aplicarCota(){const c=s?.cotaEd;if(!c)return;const l=s.linhas.find(x=>x.id===c.id);if(!l)return fecharCota()
    // 32,0039 ou 32.0039; com vírgula, o ponto é de milhar (1.234,5).
    const txt=String($('pc-cota-valor').value).trim(),n=Number(txt.includes(',')?txt.replace(/./g,'').replace(',','.'):txt)
    if(!Number.isFinite(n)||n<=0){dica('Digite uma medida maior que zero, em metros (ex.: 32,0000).');return}
    const A=l.pontos[c.i-1],B=l.pontos[c.i],atual=G.len(G.sub(B,A));if(atual<1e-9)return fecharCota()
    // Anda a ponta escolhida, ao longo do próprio lado; no contorno fechado o
    // primeiro e o último ponto são o mesmo vértice e andam juntos.
    const fech=G.fechada(l.pontos),nv=fech?l.pontos.length-1:l.pontos.length,u=G.unit(G.sub(B,A))
    const delta=G.mul(u,(n-atual)*(c.fixa==='a'?1:-1))
    const idx=k=>fech?((k%nv)+nv)%nv:k
    const mover=k=>{if(k<0||k>=l.pontos.length)return;const j=idx(k);l.pontos[j]=G.add(l.pontos[j],delta);if(fech&&j===0)l.pontos[l.pontos.length-1]=[...l.pontos[0]]}
    antes()
    const anda=c.fixa==='a'?c.i:c.i-1,vizinho=c.fixa==='a'?c.i+1:c.i-2,fixa=c.fixa==='a'?c.i-1:c.i
    mover(anda)
    if(c.junto&&idx(vizinho)!==idx(fixa)&&(fech||(vizinho>=0&&vizinho<l.pontos.length)))mover(vizinho)
    s.cotaEd=null;$('pc-cota-ed').hidden=true;mudou();svg.focus()
    dica(`Lado ajustado para ${fmtMed(n)} m.`)}
  /**
   * ESQUADREJAR o contorno (botão da barra): os cantos quase retos (até 1°)
   * viram 90° exatos e cada lado mantém a medida — 20,0000 × 50,0000 passa a
   * dar 1.000,0000 m², e não 999,999. Vale para a linha fechada selecionada
   * ou, sem seleção, para a única linha fechada do desenho (o contorno em edição).
   */
  function esquadrejar(){if(!s||s.leitura)return
    const fechadas=s.linhas.filter(l=>G.fechada(l.pontos)),l=selecionada()&&G.fechada(selecionada().pontos)?selecionada():fechadas.length===1?fechadas[0]:null
    if(!l){dica(fechadas.length?'Selecione o contorno que vai ser esquadrejado.':'Esquadrejar vale para um contorno fechado.');return}
    const r=G.esquadrejar(l.pontos);if(r.erro){dica(r.erro);return}
    if(!r.cantos){dica('Nenhum canto a até 1° de 90°: nada a esquadrejar.');return}
    antes();l.pontos=r.pontos;s.sel=l.id;mudou()
    dica(`${r.cantos} canto(s) acertado(s) em 90°. Área do contorno: ${fmtMed(Math.abs(G.area(r.pontos)))} m².`)}
  function terminar(){if(!s||s.leitura)return;s.tracoRedo=[];if(s.traco.length>=2){antes();s.linhas.push({id:crypto.randomUUID(),pontos:copy(s.traco)});s.sel=s.linhas.at(-1).id;s.traco=[];mudou()}else s.traco=[];s.valor='';$('pc-entrada').hidden=true;render();dica('Linha encerrada. Clique em outro ponto para começar uma nova; V para selecionar.')}
  function excluir(){if(!s||s.leitura||!s.sel)return;antes();s.linhas=s.linhas.filter(l=>l.id!==s.sel);s.sel=null;s.operacao=null;mudou()}
  function entrada(p,label,mover=false){const el=$('pc-entrada'),q=tela(p);el.hidden=false;el.style.left=`${Math.max(8,Math.min(s.w-220,q[0]+20))}px`;el.style.top=`${Math.max(8,Math.min(s.h-45,q[1]-45))}px`;$('pc-entrada-label').textContent=label;if(!mover)$('pc-valor').value=''}
  function medida(){const m=numeroDigitado();if(m===null){dica('Digite uma medida válida em metros.');return}
    if(s.ferramenta==='concordancia'){if(m<0){dica('O raio não pode ser negativo.');return}s.raio=m;s.valor='';render();svg.focus();return}
    if(s.operacao){aplicarOperacao();return}
    if(s.traco.length){if(m<=0){dica('O comprimento deve ser positivo.');return}const a=s.traco.at(-1),d=G.sub(s.mouse||a,a);if(G.len(d)<.0001){dica('Aponte a direção da linha antes de digitar a medida.');return}s.traco.push(G.add(a,G.mul(G.unit(d),m)));s.tracoRedo=[];s.valor='';$('pc-valor').value='';render();svg.focus()}
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
    if(frac()){s.resultado={partes:s.linhas.filter(l=>G.fechada(l.pontos)).map(l=>l.pontos),erro:null};return}
    // Desenho livre: o lote é a ÚNICA linha fechada. Linhas abertas ficam como
    // linhas de construção, e não entram no resultado.
    if(livre()){const fs=s.linhas.filter(l=>G.fechada(l.pontos));s.resultado=fs.length===1?{partes:[fs[0].pontos],erro:null}:{partes:[],erro:fs.length?'Há mais de um contorno fechado. Exclua o que sobra.':`Feche o contorno ${edif()?'da construção':'do lote'}: clique de novo no primeiro vértice, ou use o Fillet (F) com raio 0.`,orientacao:!fs.length};return}
    if(s.tipo==='desmembramento'){s.resultado=G.dividir(ring(s.originais[0]),s.linhas.map(l=>l.pontos));const ordem=s.ordem||[];s.resultado.partes.sort((a,b)=>{const ia=ordem.indexOf(parteKey(a)),ib=ordem.indexOf(parteKey(b));return (ia<0?Infinity:ia)-(ib<0?Infinity:ib)})}else s.resultado={partes:s.uniao?[ring({geometry:s.uniao})]:[],erro:s.uniao?null:s.erroUniao||(s.loading?'Calculando unificação…':'Não foi possível preparar a unificação.')}}
  function path(ps,close=false){return ps.map((p,i)=>{const q=tela(p);return `${i?'L':'M'}${q[0].toFixed(3)},${q[1].toFixed(3)}`}).join(' ')+(close?'Z':'')}
  function texto(p,t,cls='pc-label'){const q=tela(p);return `<text class="${cls}" x="${q[0]}" y="${q[1]}" text-anchor="middle">${e(t)}</text>`}
  function cota(a,b,t,lado=1,cls='pc-cota',attrs=''){const mid=G.mul(G.add(a,b),.5),q=tela(G.add(mid,G.mul(G.normal(a,b),lado*30/s.escala)));return `<text class="${cls}" ${attrs} x="${q[0]}" y="${q[1]}" text-anchor="middle" dominant-baseline="middle">${e(t)}</text>`}
  function centro(r){const a=r.slice(0,-1);const m=G.mul(a.reduce(G.add,[0,0]),1/a.length);if(G.dentro(m,r))return m;for(let i=1;i<r.length;i++){const mid=G.mul(G.add(r[i-1],r[i]),.5),n=G.normal(r[i-1],r[i]);for(const sign of [1,-1]){const q=G.add(mid,G.mul(n,sign*.1));if(G.dentro(q,r))return q}}return m}
  function render(){if(!s||!svg)return
    // A caixinha da medida só existe enquanto há uma medida em edição.
    if(!s.cotaEd&&$('pc-cota-ed'))$('pc-cota-ed').hidden=true
    let h='<defs><pattern id="pc-grid" width="25" height="25" patternUnits="userSpaceOnUse"><path d="M25 0H0V25" fill="none" stroke="#e8edf1" stroke-width=".6"/></pattern></defs><rect width="100%" height="100%" fill="#f8fafb"/><rect width="100%" height="100%" fill="url(#pc-grid)"/>'
    if(s.mapa)h+=edif()?tilesAereos():fundoCadastral()
    // Na edificação os vizinhos ficam quase transparentes: a imagem aérea tem de aparecer.
    for(const f of s.contexto){if(!f.geometry?.coordinates?.[0])continue;const r=ring(f),p=f.properties||{};h+=`<path d="${path(r,true)}" fill="#e7ebec" fill-opacity="${edif()?'.12':'.78'}" stroke="${edif()?'#ffffff':'#9ca9af'}" stroke-width="1"/>`
      const vis=r.map(tela),b=bounds(vis);if(b[2]<0||b[0]>s.w||b[3]<0||b[1]>s.h)continue
      // Identificação no fragmento visível, mesmo com centro do vizinho fora.
      const q=[Math.max(65,Math.min(s.w-65,(Math.max(0,b[0])+Math.min(s.w,b[2]))/2)),Math.max(28,Math.min(s.h-28,(Math.max(0,b[1])+Math.min(s.h,b[3]))/2))]
      const nome='Q '+(p.quadra||'—')+' · Lote '+(p.numero_lote||'—');h+=texto(posicaoConfronto(mundo(q),nome),nome,'pc-vizinho')
    }
    // No Editar lote o contorno de origem é só referência: tracejado, por baixo.
    // Na edificação o lote é a referência em destaque: contorno amarelo com
    // filete escuro, e nenhum preenchimento por cima da imagem.
    for(const f of contornos())h+=edif()?`<path d="${path(ring(f),true)}" fill="none" stroke="#1b2a27" stroke-width="5.5" stroke-opacity=".55"/><path d="${path(ring(f),true)}" fill="#facc15" fill-opacity=".07" stroke="#facc15" stroke-width="3"/>`:s.tipo==='edicao'?`<path d="${path(ring(f),true)}" fill="none" stroke="#6b7280" stroke-width="1.5" stroke-dasharray="6 4"/>`:`<path d="${path(ring(f),true)}" fill="white" fill-opacity=".93" stroke="#243b4b" stroke-width="2.5"/>`
    const partes=s.resultado?.partes||[]
    s.cruzamentos=partes.flatMap(cruzamentosDe)
    partes.forEach((r,i)=>{h+=`<path d="${path(r,true)}" fill="${cores[i%cores.length]}" fill-opacity=".11" stroke="${cores[i%cores.length]}" stroke-width="1.5"/>`;h+=texto(centro(r),`${frac()?'Unidade '+(pad3(s.dados[parteKey(r)]?.variacao)||i+1):s.tipo==='unificacao'?'Unificação':s.tipo==='novo'?'Lote novo':s.tipo==='edificacao'?'Edificação':s.tipo==='edicao'?'Contorno editado':s.leitura?'Lote '+(s.finalFeatures?.[i]?.properties?.numero_lote||i+1):'Parte '+(i+1)} · ${fmtMed(Math.abs(G.area(r)))} m²${s.tipo==='edicao'?alvoTxt(Math.abs(G.area(r))):''}`)})
    // Cotas das faces de origem, na mesma escala métrica usada nas ferramentas.
    // Na EDIÇÃO, o contorno de origem mostra a medida (em cinza) só dos lados
    // que mudaram: é ali que está a diferença contra o cadastro. Lado igual
    // ao do editado (as duas pontas a menos de 0,1 mm) não repete a cota.
    const editado=s.tipo==='edicao'?(partes[0]||[]):null
    const igualAoEditado=(a,b)=>editado.some((p,j)=>j>0&&((G.len(G.sub(p,a))<1e-4&&G.len(G.sub(editado[j-1],b))<1e-4)||(G.len(G.sub(p,b))<1e-4&&G.len(G.sub(editado[j-1],a))<1e-4)))
    for(const f of contornos()){const r=ring(f);for(let i=1;i<r.length;i++){const m=G.len(G.sub(r[i],r[i-1]));if(m*s.escala<70)continue
      if(editado){if(!igualAoEditado(r[i-1],r[i]))h+=cota(r[i-1],r[i],`antes ${fmtMed(m)} m`,G.area(r)>0?-1:1,'pc-cota pc-cota-antes');continue}
      h+=cota(r[i-1],r[i],`${fmtMed(m)} m`,G.area(r)>0?-1:1)}}
    // A linha SELECIONADA vai por último: as alças dos vértices dela ficam por
    // cima de toda outra linha. Desenhada na ordem, uma linha de referência
    // criada depois cobria o vértice do contorno em edição e roubava o clique.
    for(const l of [...s.linhas.filter(x=>x.id!==s.sel),...s.linhas.filter(x=>x.id===s.sel)]){const sel=l.id===s.sel;h+=`<path data-line="${e(l.id)}" class="pc-hit" d="${path(l.pontos)}"/><path data-line="${e(l.id)}" d="${path(l.pontos)}" fill="none" stroke="${sel?'#ea580c':'#2563eb'}" stroke-width="${sel?3:2}"/>`
      if(sel)l.pontos.forEach((p,i)=>{const q=tela(p);h+=`<circle data-line="${e(l.id)}" data-vertex="${i}" cx="${q[0]}" cy="${q[1]}" r="5" fill="white" stroke="#ea580c" stroke-width="2"/>`})
      // A medida de TODOS os lados do que está sendo desenhado (a selecionada
      // mostra mesmo os curtos). No contorno fechado, do lado oposto ao das
      // cotas do contorno de origem, para não encavalar.
      const fech=G.fechada(l.pontos),lado=fech&&G.area(l.pontos)>0?1:fech?-1:1
      for(let i=1;i<l.pontos.length;i++){const m=G.len(G.sub(l.pontos[i],l.pontos[i-1]));if(!sel&&m*s.escala<70)continue;const ed=s.cotaEd&&s.cotaEd.id===l.id&&s.cotaEd.i===i
        h+=cota(l.pontos[i-1],l.pontos[i],`${fmtMed(m)} m`,lado,s.leitura?'pc-cota':'pc-cota pc-cota-edit'+(ed?' pc-cota-ativa':''),s.leitura?'':`data-cota-line="${e(l.id)}" data-cota-i="${i}"`)}
      // MEDIDA EM EDIÇÃO: a ponta que fica parada em verde, a que anda em laranja.
      if(s.cotaEd&&s.cotaEd.id===l.id){const c=s.cotaEd,fixa=tela(l.pontos[c.fixa==='a'?c.i-1:c.i]),anda=tela(l.pontos[c.fixa==='a'?c.i:c.i-1])
        h+=`<circle cx="${fixa[0]}" cy="${fixa[1]}" r="7" fill="#16a34a" stroke="white" stroke-width="2"/><circle cx="${anda[0]}" cy="${anda[1]}" r="7" fill="#ea580c" stroke="white" stroke-width="2"/>`}
    }
    if(s.ref)h+=`<path d="${path(s.ref)}" stroke="#a855f7" stroke-width="5" opacity=".8"/>`
    // A FACE QUE SERÁ ESCOLHIDA, realçada sob o cursor antes do clique: halo
    // largo, traço forte, as duas pontas e um aviso do que vai acontecer.
    if((s.ferramenta==='alinhar'||s.ferramenta==='perpendicular')&&s.mouse&&!s.drag){const fc=facePerto(s.mouse);if(fc){
      const a=tela(fc[0]),b=tela(fc[1]),m=[(a[0]+b[0])/2,(a[1]+b[1])/2]
      const txt=s.ferramenta==='alinhar'?'Esta face vai para baixo · '+fmtNum(G.len(G.sub(fc[1],fc[0])))+' m':'Face de referência · '+fmtNum(G.len(G.sub(fc[1],fc[0])))+' m'
      h+=`<g class="pc-face-alvo" pointer-events="none"><path d="M${a[0]},${a[1]}L${b[0]},${b[1]}" stroke="#a855f7" stroke-width="14" stroke-opacity=".22" stroke-linecap="round"/><path d="M${a[0]},${a[1]}L${b[0]},${b[1]}" stroke="#7e22ce" stroke-width="3.5" stroke-linecap="round"/><circle cx="${a[0]}" cy="${a[1]}" r="5" fill="#fff" stroke="#7e22ce" stroke-width="2"/><circle cx="${b[0]}" cy="${b[1]}" r="5" fill="#fff" stroke="#7e22ce" stroke-width="2"/><text class="pc-face-rot" x="${m[0]}" y="${m[1]-14}" text-anchor="middle">${txt}</text></g>`}}
    // O primeiro lado escolhido na Concordância, à espera do segundo.
    const f1=s.fillet?.primeiro;if(f1){const l=f1.id!=null&&s.linhas.find(x=>x.id===f1.id),ab=f1.id==null?[f1.a,f1.b]:l?[l.pontos[f1.i],l.pontos[f1.i+1]]:null;if(ab?.[0]&&ab[1])h+=`<path d="${path(ab)}" stroke="#a855f7" stroke-width="6" opacity=".85"/>`}
    if(s.traco.length){let q=s.mouse||s.traco.at(-1);const n=numeroDigitado();if(n!==null&&n>0)q=G.add(s.traco.at(-1),G.mul(G.unit(G.sub(q,s.traco.at(-1))),n));h+=`<path d="${path([...s.traco,q])}" fill="none" stroke="#ea580c" stroke-width="2.5" stroke-dasharray="7 3"/>`;h+=cota(s.traco.at(-1),q,`${fmtNum(G.len(G.sub(q,s.traco.at(-1))))} m`);s.traco.forEach(p=>{const t=tela(p);h+=`<circle cx="${t[0]}" cy="${t[1]}" r="4" fill="#ea580c"/>`})}
    if(s.operacao&&!s.operacao.basePendente){try{h+=`<path d="${path(pontosOperacao())}" fill="none" stroke="#ea580c" stroke-width="3" stroke-dasharray="8 4"/>`;h+=texto(s.mouse,`${s.operacao.tipo==='offset'?'Offset':'Mover'} ${fmtNum(G.len(deltaOperacao()))} m`,'pc-cota')}catch{}}
    if(s.captura){const q=tela(s.captura.p),t=s.captura.t,k=t==='Endpoint'?'endpoint':t==='Midpoint'?'midpoint':t==='Na face'?'face':t==='Interseção'?'intersecao':'perpendicular';const shapes={intersecao:'<rect x="-6" y="-6" width="12" height="12"/><path d="M-6 -6L6 6M-6 6L6 -6"/>',endpoint:'<rect x="-6" y="-6" width="12" height="12"/>',midpoint:'<path d="M0 -8L8 6H-8Z"/>',face:'<path d="M-6 -6L6 6M-6 6L6 -6"/>',perpendicular:'<path d="M-7 -8V7H8M-7 1H-1V7"/>'};h+=`<g data-snap="${k}" transform="translate(${q[0]} ${q[1]})" fill="none" stroke="#16a34a" stroke-width="2" pointer-events="none">${shapes[k]}</g><text class="pc-snap" x="${q[0]+14}" y="${q[1]+23}">${e(t)}</text>`}
    // Um número por FACE: o arco da quina conta como uma só (G.agruparFaces), com o número no meio dele.
    if(s.medindo!=null){const r=partes[s.medindo];if(r)for(const [j0,fc] of G.agruparFaces(r).entries()){const j=j0+1,mid=Math.floor((fc.ini+fc.fim)/2),q=tela(fc.arco&&(fc.fim-fc.ini)%2===0?G.mul(G.add(r[mid],r[mid+1]),.5):fc.arco?r[mid+1]:G.mul(G.add(r[fc.ini],r[fc.ini+1]),.5));h+=`<g pointer-events="none"><circle cx="${q[0]}" cy="${q[1]}" r="12" fill="#006c16"/><text x="${q[0]}" y="${q[1]+4}" text-anchor="middle" fill="white" font-size="12">${j}</text></g>`}}
    for(const a of s.anotacoes)h+=texto(posicaoConfronto(a.p,a.texto),a.texto,'pc-confronto')
    // ONDE O CONTORNO SE CRUZA: círculo vermelho em cada ponto, por cima de tudo.
    for(const p of s.cruzamentos||[]){const q=tela(p);h+=`<g class="pc-cruzamento" pointer-events="none"><circle cx="${q[0]}" cy="${q[1]}" r="11" fill="#dc2626" fill-opacity=".15" stroke="#dc2626" stroke-width="2.5"/><circle cx="${q[0]}" cy="${q[1]}" r="2.5" fill="#dc2626"/></g>`}
    const north=G.rot([0,1],s.angulo);h+=`<g transform="translate(${s.w-38} 42)"><path d="M0 0L${north[0]*22} ${-north[1]*22}" stroke="#243b4b" stroke-width="2"/><text x="${north[0]*32}" y="${-north[1]*32}" text-anchor="middle" font-size="12">N</text></g>`
    const passo=10**Math.floor(Math.log10(120/s.escala)), px=passo*s.escala;h+=`<path d="M25 ${s.h-35}v7h${px}v-7" fill="none" stroke="#243b4b"/><text x="25" y="${s.h-40}" font-size="11">${passo>=1?fmtNum(passo)+' m':passo>=.01?Math.round(passo*100)+' cm':Math.round(passo*1000)+' mm'}</text>`
    svg.innerHTML=h
    for(const b of modal.querySelectorAll('[data-tool]')){b.classList.toggle('ativo',b.dataset.tool===s.ferramenta);b.setAttribute('aria-pressed',String(b.dataset.tool===s.ferramenta));b.disabled=(s.leitura&&!['selecionar','alinhar'].includes(b.dataset.tool))||(s.tipo==='unificacao'&&['linha','perpendicular','mover','offset','concordancia'].includes(b.dataset.tool))}
    $('pc-ref').textContent=s.ref?'⊥ Face de referência fixada · 90°':''
    // O rodapé não traz mais dicas; o resumo só aparece quando é ERRO (o
    // contorno que não fecha, a parte inválida) — esse a pessoa precisa ver.
    // "Feche o contorno" é orientação, não erro: fica fora do rodapé.
    $('pc-resumo').classList.toggle('erro',!!s.resultado?.erro&&!s.resultado?.orientacao)
    $('pc-resumo').textContent=s.resultado?.erro||(livre()?(partes[0]?`Contorno fechado · ${fmtNum(Math.abs(G.area(partes[0])))} m²${alvoTxt(Math.abs(G.area(partes[0])))}`:"Carregando…"):`${s.linhas.length} linha(s) · ${partes.length} ${s.tipo==='unificacao'?'resultado(s)':'parte(s)'}`)
    if(!s.operacao)dica(s.leitura?'Visualização salva do ato.':s.ferramenta==='concordancia'?(s.fillet?.erro||`Concordância · raio ${fmtNum(s.raio)} m · clique no ${s.fillet?.primeiro?"2º":"1º"} lado. Raio 0 estende até o encontro — com um arco entre os dois lados, fecha em ponta; digite outro raio e Enter.`):s.ferramenta==='linha'&&livre()?`Clique nos cantos ${edif()?'da construção':'do lote'} · digite a medida · clique no primeiro vértice para fechar`:s.ferramenta==='linha'?'Clique para desenhar · digite a medida · Enter encerra · novo clique inicia outra linha':s.ferramenta==='perpendicular'?'Clique na face à qual a nova linha será perpendicular.':s.ferramenta==='alinhar'?'Clique na face que deseja deixar horizontal.':s.ferramenta==='anotar'?'Clique na posição da identificação do confronto.':'Selecione e arraste uma linha ou um vértice · Shift+clique no lado insere vértice · M mover · O offset · F concordância')
    // Fillet ativo: o raio e o passo no rodapé; trocou de ferramenta, some.
    {const fl=s.ferramenta==='concordancia'&&!s.leitura;$('pc-fillet').hidden=!fl;if(fl){if(document.activeElement!==$('pc-raio'))$('pc-raio').value=String(s.raio).replace('.',',');$('pc-fillet-passo').textContent=s.fillet?.erro||(s.fillet?.primeiro?'clique no 2º lado':'clique no 1º lado · raio 0 = canto vivo')}}
  }
  // Na EDIFICAÇÃO o fundo é a IMAGEM AÉREA: é sobre o telhado que se contorna
  // a construção, e o mapa cadastral não o mostra. Vem da mesma sessão de
  // satélite do mapa principal; sem ela, a imagem gratuita da Esri (até o zoom 17).
  let fundoSat=null,fundoSatPedido=false
  function pedirFundoSat(){
    if(fundoSatPedido)return;fundoSatPedido=true
    fetch('/api/mapa/google-sessao',{headers:{Accept:'application/json'}})
      .then(r=>r.ok?r.json():null)
      .then(d=>{if(!d?.session)return;fundoSat=d;if(edif())render()})
      .catch(()=>{fundoSatPedido=false})
  }
  // O crédito no canto diz de quem é o fundo que está na tela.
  function creditar(t){const c=document.querySelector('#pc-modal .pc-credito');if(c&&c.textContent!==t)c.textContent=t}
  function tilesAereos(){
    pedirFundoSat();creditar(fundoSat?'Imagem aérea © Google':'Imagem aérea © Esri')
    const ps=[[0,0],[s.w,0],[s.w,s.h],[0,s.h]].map(p=>s.plano.de(mundo(p))),b=bounds(ps)
    const zmax=fundoSat?20:17
    const nmax=2**zmax,tx=lon=>(lon+180)/360*nmax,ty=lat=>(1-Math.asinh(Math.tan(lat*Math.PI/180))/Math.PI)/2*nmax
    const url=fundoSat
      ?(z,x,y)=>`https://tile.googleapis.com/v1/2dtiles/${z}/${x}/${y}?session=${encodeURIComponent(fundoSat.session)}&amp;key=${encodeURIComponent(fundoSat.key)}`
      :(z,x,y)=>`https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/${z}/${y}/${x}`
    let z=zmax
    while(z>10){const n=2**z,k=n/nmax;if((Math.ceil(tx(b[2])*k)-Math.floor(tx(b[0])*k)+1)*(Math.ceil(ty(b[1])*k)-Math.floor(ty(b[3])*k)+1)<=36)break;z--}
    const n=2**z,k=n/nmax;let h='<g>'
    const lon=x=>x/n*360-180,lat=y=>Math.atan(Math.sinh(Math.PI*(1-2*y/n)))*180/Math.PI
    for(let x=Math.floor(tx(b[0])*k);x<=Math.floor(tx(b[2])*k);x++)for(let y=Math.floor(ty(b[3])*k);y<=Math.floor(ty(b[1])*k);y++){
      const a=tela(s.plano.para([lon(x),lat(y)])),d=tela(s.plano.para([lon(x+1),lat(y)])),c=tela(s.plano.para([lon(x),lat(y+1)]))
      h+=`<image href="${url(z,x,y)}" width="256" height="256" transform="matrix(${(d[0]-a[0])/256} ${(d[1]-a[1])/256} ${(c[0]-a[0])/256} ${(c[1]-a[1])/256} ${a[0]} ${a[1]})"/>`
    }return h+'</g>'
  }
  /**
   * VIRA A FRENTE DO LOTE PARA BAIXO. A frente é a testada que dá para a rua: o
   * lado do lote que NÃO tem outro lote encostado (um ponto 2 m para fora dele
   * não cai dentro de nenhum vizinho). Em lote de esquina há mais de um lado
   * livre, e fica a menor testada com pelo menos 5 m — o chanfro da esquina,
   * mais curto, não conta. Sem nenhum lado livre (vizinhos por desenhar em
   * toda a volta), a prancheta fica com o norte para cima.
   * @returns {number|null} o comprimento da testada escolhida, em metros
   */
  function frenteParaBaixo(){
    const r=ring(s.originais[0]);if(r.length<4)return null
    const viz=s.contexto.filter(f=>f.geometry?.type==='Polygon').map(f=>f.geometry.coordinates[0].map(s.plano.para))
    const livres=[]
    for(let i=1;i<r.length;i++){const a=r[i-1],b=r[i],m=G.len(G.sub(b,a));if(m<1)continue
      const meio=G.mul(G.add(a,b),.5),n=G.normal(a,b)
      let fora=G.add(meio,G.mul(n,2));if(G.dentro(fora,r))fora=G.add(meio,G.mul(n,-2))
      if(!viz.some(v=>G.dentro(fora,v)))livres.push({a,b,m,meio})}
    if(!livres.length)return null
    const testadas=livres.filter(l=>l.m>=5).sort((x,y)=>x.m-y.m)
    const f=testadas[0]||livres.sort((x,y)=>y.m-x.m)[0]
    const d=G.sub(f.b,f.a);s.angulo=-Math.atan2(d[1],d[0])
    // A testada ficou horizontal; falta ela ficar EMBAIXO, com o lote acima.
    if(tela(centro(r))[1]>tela(f.meio)[1])s.angulo+=Math.PI
    return f.m
  }
  // FUNDO CADASTRAL: o mesmo do mapa, desenhado com os dados do sistema — o
  // contorno do bairro em branco (a rua), as quadras em cinza e o nome das
  // ruas. Substitui o mapa de ruas do Google: encaixa no lote ao centímetro e
  // não gera pedido nem custo fora. Só a edificação continua com foto aérea.
  function fundoCadastral(){
    creditar('Mapa cadastral · desenhado com os dados do sistema')
    const ps=[[0,0],[s.w,0],[s.w,s.h],[0,s.h]].map(p=>s.plano.de(mundo(p))),b=bounds(ps)
    const cabe=anel=>{let x0=1e9,y0=1e9,x1=-1e9,y1=-1e9;for(const [x,y] of anel){if(x<x0)x0=x;if(x>x1)x1=x;if(y<y0)y0=y;if(y>y1)y1=y}return !(x1<b[0]||x0>b[2]||y1<b[1]||y0>b[3])}
    const poligonos=g=>!g?[]:g.type==='Polygon'?[g.coordinates]:g.type==='MultiPolygon'?g.coordinates:[]
    const caminho=pol=>pol.map(a=>path(a.map(c=>s.plano.para(c)),true)).join(' ')
    let h='<rect width="100%" height="100%" fill="#EDEEE8"/>'
    for(const f of (typeof contornoState!=='undefined'&&contornoState.dados?.features)||[])for(const pol of poligonos(f.geometry))if(cabe(pol[0]))h+=`<path d="${caminho(pol)}" fill="#FFFFFF" fill-rule="evenodd" stroke="#C9CDC5" stroke-width="1"/>`
    const nomes=[]
    if(typeof quadraState!=='undefined')for(const q of quadraState.porBairro.values()){if(q==='carregando')continue
      for(const f of q.feicoes||[])for(const pol of poligonos(f.geometry))if(cabe(pol[0]))h+=`<path d="${caminho(pol)}" fill="#E6E8E3" fill-rule="evenodd" stroke="#CDD1CA" stroke-width="1"/>`
      // O nome da rua ao longo do trecho, um por rua no que se vê.
      for(const t of q.ruas||[]){if(!t.nome||t.origem==='oculto'||t.origem==='sem_nome')continue
        const a=tela(s.plano.para([t.de[1],t.de[0]])),c=tela(s.plano.para([t.ate[1],t.ate[0]]))
        if(Math.hypot(c[0]-a[0],c[1]-a[1])<t.nome.length*7+24)continue
        const mx=(a[0]+c[0])/2,my=(a[1]+c[1])/2;if(mx<0||my<0||mx>s.w||my>s.h)continue
        if(nomes.some(n=>n.nome===t.nome&&Math.hypot(n.x-mx,n.y-my)<300))continue;nomes.push({nome:t.nome,x:mx,y:my})
        let ang=Math.atan2(c[1]-a[1],c[0]-a[0])*180/Math.PI;if(ang>90)ang-=180;else if(ang<=-90)ang+=180
        h+=`<text class="pc-rua" x="${mx.toFixed(1)}" y="${my.toFixed(1)}" text-anchor="middle" dominant-baseline="middle" transform="rotate(${ang.toFixed(1)} ${mx.toFixed(1)} ${my.toFixed(1)})">${e(t.nome)}</text>`}
    }
    return h
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
    // O contorno de origem vem SEMPRE do servidor, com a precisão gravada: o do
    // mapa vem com 8 casas (~1 mm), e só esse arredondamento já muda a área em
    // ~0,01 m² — a diferença contra o cadastro que não se achava. As
    // propriedades (quadra, número) continuam as do mapa, quando ele tem o lote.
    const originais=salva?.originais||(tipo==='novo'?[]:await Promise.all(ids.map(async id=>{const f=state.lotes.get(id);const r=await fetch(`/api/imoveis/${id}/geometria`,{headers:{Accept:'application/json'}});if(!r.ok){if(f)return f;throw new Error('Não foi possível ler o contorno do imóvel.')}const d=await r.json();const g=d.type==='Feature'?d.geometry:(d.geometry||d);return {type:'Feature',geometry:g,properties:f?.properties||{id}}})))
    if(token!==geracao)return
    if(originais.some(f=>f.geometry?.type!=='Polygon'||f.geometry.coordinates.length!==1)){toast('A prancheta atende polígonos sem vazios internos.', 'err');return}
    if($('t-mapa')&&!$('t-mapa').classList.contains('at')&&typeof irPara==='function')irPara('mapa')
    const centroGeo=opts.centro||originais[0].geometry.coordinates[0][0]
    s={tipo,ids:ids.map(Number),protocoloId,originais,opts,plano:G.plano(centroGeo),linhas:[],traco:[],anotacoes:[],contexto:[],angulo:0,centro:[0,0],escala:1,undo:[],redo:[],dados:{},numero:'',justificativa:atoState.justificativa||'',ferramenta:tipo==='novo'||tipo==='edificacao'||tipo==='fracao_ideal'?'linha':'selecionar',sel:null,ref:null,operacao:null,drag:null,mouse:null,mapa:true,valor:'',leitura:!!salva,resultado:{partes:[]},uniao:null,revisado:null,raio:0,fillet:{primeiro:null,erro:null}}
    s.loading=!salva
    // Editar lote: o próprio contorno é a linha que se edita (fechada).
    if(tipo==='edicao'&&!salva){const r=ring(originais[0]);s.linhas=[{id:crypto.randomUUID(),pontos:r}];s.sel=s.linhas[0].id}
    // Lote novo reaberto para correção: o contorno que já estava pronto volta.
    if(tipo==='novo'&&opts.contorno){s.linhas=[{id:crypto.randomUUID(),pontos:opts.contorno.coordinates[0].map(s.plano.para)}];s.ferramenta='selecionar'}
    if(salva){restaurar(salva);s.contexto=salva.contexto||[];s.finalFeatures=salva.resultantes;if(tipo==='unificacao')s.uniao=salva.resultantes[0]?.geometry;s.resultado={partes:salva.resultantes.map(ring)}}
    const titulos={fracao_ideal:'Fração ideal',desmembramento:'Desmembramento',unificacao:'Unificação',novo:'Lote novo',edicao:'Editar lote',edificacao:'Desenhar edificação'}
    // O rótulo da opção de fundo acompanha o que ela mostra.
    {const rot=$('pc-mapa')?.nextSibling;if(rot?.nodeType===3)rot.textContent=tipo==='edificacao'?' Imagem aérea ':' Mapa cadastral '}
    $('pc-titulo').textContent=(salva?'Prancha finalizada · ':'')+(titulos[tipo]||tipo)
    $('pc-origem').textContent=tipo==='novo'?(opts.bairro||'Desenhe o contorno do lote'):originais.map(f=>`Q ${f.properties?.quadra||opts.quadra||'—'} · Lote ${f.properties?.numero_lote||opts.numero_lote||f.properties?.id}`).join(' + ')
    $('pc-continuar').textContent=tipo==='fracao_ideal'?'Unidades e finalizar →':tipo==='novo'?'Informar os dados →':tipo==='edificacao'?'Gravar edificação →':tipo==='edicao'?'Número, quadra e salvar →':'Dados e finalizar →'
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
      // (Nem a edificação: cada construção é um desenho novo sobre o mesmo lote.)
      if(tipo!=='novo'&&tipo!=='edificacao')try{const local=localStorage.getItem(chaveLocal());if(local){restaurar(JSON.parse(local));$('pc-salvo').textContent=livre()?'Cópia local recuperada':'Cópia local recuperada · salve novamente no servidor'}}catch{}
      if(tipo==='unificacao')s.numero=s.numeroUniao||'';s.loading=false
      // Edificação: com os vizinhos carregados, dá para saber qual lado é a rua.
      if(tipo==='edificacao'){const t=frenteParaBaixo();$('pc-salvo').textContent=t?`Frente do lote para baixo (testada de ${fmtNum(t)} m) · "Norte" desfaz`:'Não deu para identificar a frente do lote: norte para cima'}
      recalcular();enquadrar()
    }
  }
  function rota(previa=false){if(frac()&&!s.protocoloId)return '/api/lotes/fracao-ideal-direta'+(previa?'/previa':'');return s.protocoloId?`/api/protocolos/${s.protocoloId}/${s.tipo}${previa?'/previa':''}`:`/api/lotes/${s.tipo==='unificacao'?'unificacao-direta':'desmembramento-direto'}${previa?'/previa':''}`}
  function apelido(v){if(!s.identidade?.base||!Number.isInteger(v)||v<0||v>999)return '';const zero=s.resultado.partes.some(r=>s.dados[parteKey(r)]?.desmembramento===0);let letras='';for(let i=v+(zero?1:0);i>0;i=Math.floor(i/26)){i--;letras=String.fromCharCode(65+i%26)+letras}return s.identidade.base+letras}
  function identidadeTexto(d){const p=s.identidade;if(!p?.prefixo)return 'Inscrição de origem indisponível. Corrija o cadastro antes de finalizar.';const sufixo=Number.isInteger(d.desmembramento)&&d.desmembramento>=0&&d.desmembramento<=999?String(d.desmembramento).padStart(3,'0'):'???';return `<span class="lote-tag-origem">DESMEMBRADO</span><br>Inscrição: <b>${e(p.inscricao.slice(0,-3)+sufixo)}</b><br>Apelido automático: <b>${e(apelido(d.desmembramento)||'—')}</b>${d.desmembramento===0?'<br>O original será preservado como inativo. Apenas um lote ativo poderá usar esta inscrição.':''}`}
  function prepararIdentidades(){if(s.tipo!=='desmembramento')return;const usados=new Set(s.identidade?.usados||[]);for(const r of s.resultado.partes){const v=s.dados[parteKey(r)]?.desmembramento;if(v!=null)usados.add(v)}
    for(const r of s.resultado.partes){const k=parteKey(r),d=s.dados[k]||={};if(d.desmembramento==null){let v=1;while(usados.has(v)&&v<=999)v++;if(v<=999){d.desmembramento=v;usados.add(v)}}d.numero_lote=apelido(d.desmembramento);if(d.area_matricula_m2==null)d.area_matricula_m2=Number(Math.abs(G.area(r)).toFixed(2))}
  }
  function parteKey(r){return r.map(p=>p.map(n=>n.toFixed(5)).join(',')).sort().join(';')}
  function classificarFaces(i){
    const r=s.resultado.partes[i];if(!r||s.leitura)return;s.medindo=i;
    const faces=G.agruparFaces(r),d=s.dados[parteKey(r)]||{},nomes={frente_m:'Frente',fundos_m:'Fundos',lado_direito_m:'Lado direito',lado_esquerdo_m:'Lado esquerdo'};
    $('pc-form').innerHTML=`<h3>Faces da parte ${i+1}</h3><p>Classifique os trechos numerados no desenho. Os comprimentos de cada grupo serão somados. Direita e esquerda: olhando da rua para o lote.</p><p>As medidas calculadas substituem os quatro campos desta parte; a área é preenchida pelo desenho e pode ser conferida no formulário.</p><div class="pc-faces">${faces.map((fc,j)=>`<label>Face ${j+1}${fc.arco?' (arco)':''} · ${fmtNum(fc.comprimento)} m<select data-face="${j}"><option value="">Escolha…</option>${Object.entries(nomes).map(([k,t])=>`<option value="${k}" ${d._faces?.[fc.ini]===k?'selected':''}>${t}</option>`).join('')}</select></label>`).join('')}</div><p id="pc-face-erro" role="status"></p><div class="pc-acoes"><button class="btn out-cinza" type="button" onclick="PranchetaCad.dados(true)">Cancelar</button><button class="btn primary" type="button" id="pc-aplicar-faces">Calcular medidas</button></div>`;
    $('pc-aplicar-faces').onclick=()=>{
      const porFace=[...$('pc-form').querySelectorAll('[data-face]')].map(el=>el.value)
      const classes=r.slice(1).map((_,k)=>porFace[faces.findIndex(fc=>k>=fc.ini&&k<=fc.fim)])
      if(porFace.some(k=>!k)){$('pc-face-erro').textContent='Classifique todas as faces antes de aplicar.';return}
      const medidas={frente_m:0,fundos_m:0,lado_direito_m:0,lado_esquerdo_m:0};classes.forEach((k,j)=>medidas[k]+=G.len(G.sub(r[j+1],r[j])));
      antes();s.dados[parteKey(r)]={...d,...Object.fromEntries(Object.entries(medidas).map(([k,v])=>[k,classes.includes(k)?Number(v.toFixed(2)):null])),_faces:classes};s.medindo=null;mudou();dados(true)
    };render()
  }
  function corpo(){const v=visualizacao();if(frac())return {lote_id:s.ids[0],condominio:s.dados.condominio||null,justificativa:s.justificativa,visualizacao:v,
      unidades:unidadesFrac().map(u=>({variacao:u.d.variacao,fracao_ideal_m2:u.d.fracao_ideal_m2,geometry:u.r?{type:'Polygon',coordinates:[u.r.map(s.plano.de)]}:null}))};if(s.tipo==='unificacao')return {ids:s.ids,numero_lote:s.numero,justificativa:s.justificativa,visualizacao:v};return {lote_id:s.ids[0],modo:'prancheta',derivar_ultima:false,justificativa:s.justificativa,visualizacao:v,partes:s.resultado.partes.map((r,i)=>({numero_lote:'',...s.dados[parteKey(r)],geometry:{type:'Polygon',coordinates:[r.map(s.plano.de)]}}))}}
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
  function geometriaLivre(){const r=s.resultado.partes[0].map(p=>s.plano.de(p).map(n=>Number(n.toFixed(11))));r[r.length-1]=[...r[0]];return {type:'Polygon',coordinates:[r]}}
  // LOTE NOVO: a prancheta só desenha. Bairro, quadra, número e medidas são do
  // formulário que o Desenhar lote já tinha — ele recebe o contorno pronto.
  async function entregarLoteNovo(){const g=geometriaLivre(),cb=s.opts?.aoConcluir;s.entregue=true;await fechar(true);cb?.(g)}
  // O CARTÃO mostra por padrão só o essencial (apelido, área, ordem e a
  // inscrição); identidade completa e medidas abrem no "Mostrar mais".
  function botaoMais(){return `<button type="button" class="pc-mais" aria-expanded="false" onclick="PranchetaCad.mais(this)">Mostrar mais <span aria-hidden="true">▾</span></button>`}
  function mais(b,abrir){const c=b.closest('fieldset')?.querySelector('.pc-parte-mais');if(!c)return;const v=abrir??c.hidden;c.hidden=!v;b.setAttribute('aria-expanded',String(v));b.innerHTML=v?'Mostrar menos <span aria-hidden="true">▴</span>':'Mostrar mais <span aria-hidden="true">▾</span>'}
  // Campo com erro escondido no "mais": o cartão abre sozinho para mostrá-lo.
  function abrirCartoesComErro(){for(const c of document.querySelectorAll('#pc-form .pc-parte-mais'))if(c.querySelector('[aria-invalid=true],.pc-erro-campo')){const b=c.closest('fieldset')?.querySelector('.pc-mais');if(b)mais(b,true)}}
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
  // LOTE NOVO: a finalização acontece AQUI, no mesmo cartão das partes do
  // desmembramento — bairro, quadra e lote à vista; as medidas da matrícula no
  // "Mostrar mais". Antes a prancheta fechava e o formulário ia para a mesa, que
  // ficava com o lote pendente quando a pessoa largava a ferramenta.
  const MEDIDAS_NOVO=[['frente_m','Frente (m)'],['fundos_m','Fundos (m)'],['lado_direito_m','Lado direito (m)'],['lado_esquerdo_m','Lado esquerdo (m)'],['area_matricula_m2','Área da matrícula (m²)']]
  let bairrosNovo=null
  async function formLoteNovo(){
    s.dados.novo??={bairro:s.opts.bairroPadrao||'',quadra:'',numero_lote:''}
    const d=s.dados.novo,area=Math.abs(G.area(s.resultado.partes[0]))
    const titulo=()=>d.quadra||d.numero_lote?`Q ${d.quadra||'—'} · Lote ${d.numero_lote||'—'}`:'Lote novo'
    $('pc-dados').hidden=false
    $('pc-form').innerHTML=`<fieldset class="pc-parte-card" style="--parte-cor:${cores[0]}"><legend>Lote novo</legend>
      <header class="pc-parte-topo"><div><b id="pc-nv-tit">${e(titulo())}</b><small>${fmtNum(area)} m² · NOVO</small></div></header>
      <label>Bairro<select id="pc-nv-bairro"><option value="">— escolha —</option></select></label>
      <div class="pc-campos"><label>Quadra<input id="pc-nv-quadra" maxlength="20" value="${e(d.quadra)}"></label><label>Lote<input id="pc-nv-lote" maxlength="20" value="${e(d.numero_lote)}"></label></div>
      ${botaoMais()}<div class="pc-parte-mais" hidden><div class="pc-medidas"><b class="pc-medidas-tit">Medidas da matrícula</b>
        <small class="pc-inscricao-ajuda">Opcional. Preencha o que a matrícula trouxer; o desenho confere.</small>
        <div class="pc-campos">${MEDIDAS_NOVO.map(([k,t])=>`<label>${t}<input data-medida="${k}" type="number" step="0.01" min="0" value="${e(d[k])}"></label>`).join('')}</div></div></div>
      </fieldset>
      <div class="pc-form-acoes"><button type="button" class="btn primary" onclick="PranchetaCad.conferirLoteNovo()">Conferir e continuar</button></div>`
    $('pc-conferencia').replaceChildren()
    const muda=()=>{$('pc-nv-tit').textContent=titulo();$('pc-conferencia').replaceChildren();limparErros()}
    $('pc-nv-bairro').addEventListener('change',ev=>{d.bairro=ev.target.value;muda()})
    $('pc-nv-quadra').addEventListener('input',ev=>{d.quadra=ev.target.value.trim();muda()})
    $('pc-nv-lote').addEventListener('input',ev=>{d.numero_lote=ev.target.value.trim();muda()})
    for(const i of $('pc-form').querySelectorAll('[data-medida]'))i.addEventListener('input',()=>{d[i.dataset.medida]=i.value.trim()===''?null:Number(i.value.replace(',','.'));muda()})
    // Os bairros do cadastro, como no formulário da mesa (popularBairrosDoDesenho).
    try{if(!bairrosNovo){const r=await fetch('/api/bairros',{headers:{Accept:'application/json'}});bairrosNovo=(await r.json()).bairros||[]}}catch{bairrosNovo=[]}
    const sel=$('pc-nv-bairro');if(!sel)return
    sel.insertAdjacentHTML('beforeend',bairrosNovo.map(b=>`<option value="${e(b.valor)}">${b.codigo?e(b.codigo)+' · ':''}${e(b.nome)}</option>`).join(''))
    if(d.bairro&&bairrosNovo.some(b=>b.valor===d.bairro))sel.value=d.bairro;else d.bairro=''
    enquadrar()
  }
  function corpoLoteNovo(){const d=s.dados.novo;const m={};for(const [k] of MEDIDAS_NOVO)if(d[k]!=null&&Number.isFinite(d[k])&&d[k]>0)m[k]=d[k]
    return {bairro:d.bairro,quadra:d.quadra,numero_lote:d.numero_lote,geometry:geometriaLivre(),...m}}
  async function conferirLoteNovo(){
    if(!s||s.busy)return;const d=s.dados.novo||{},campos={}
    if(!d.bairro)campos.bairro='Escolha o bairro.';if(!d.quadra)campos.quadra='Informe a quadra.';if(!d.numero_lote)campos.numero_lote='Informe o número do lote.'
    if(Object.keys(campos).length){limparErros();for(const [k,msg] of Object.entries(campos)){const el=$({bairro:'pc-nv-bairro',quadra:'pc-nv-quadra',numero_lote:'pc-nv-lote'}[k]);if(el){el.setAttribute('aria-invalid','true');const a=document.createElement('small');a.className='pc-erro-campo';a.textContent=msg;el.closest('label').appendChild(a)}}$('pc-conferencia').innerHTML='<div class="pc-erro-resumo" role="alert"><b>Revise os campos indicados</b></div>';return}
    s.busy=true;$('pc-conferencia').innerHTML='<p>Conferindo…</p>'
    try{
      const r=await postCadastro('/api/lotes/previa',corpoLoteNovo());if(!r){$('pc-conferencia').replaceChildren();return}
      if(r.impedimento){$('pc-conferencia').innerHTML=`<div class="pc-erro-resumo" role="alert"><b>${e(comCruzamentos(r.impedimento))}</b></div>`;return}
      const t=r.retrato,dv=t.divergencia,encosta=(t.vizinhos||[]).filter(v=>v.area_comum===0).length
      $('pc-conferencia').innerHTML=`<div class="pc-identidade"><span class="lote-tag-origem">NOVO</span>
        <p>Lote de <b>${fmtNum(t.area_m2)} m²</b> com ${t.vertices} canto(s), em ${e(t.bairro)} · quadra ${e(t.quadra)} · lote ${e(t.lote)}.${encosta?` Encosta em ${encosta} lote(s) vizinho(s).`:''}</p>
        ${dv===null||dv===undefined?'':`<p>Matrícula × desenho: <b>${dv>0?'+':''}${String(dv).replace('.',',')}%</b>${Math.abs(dv)>5?' — diferença grande; o lote é gravado assim mesmo, com as duas medidas.':''}</p>`}
        ${(r.avisos||[]).map(a=>`<p>⚠ ${e(a)}</p>`).join('')}</div>
        <div class="pc-form-acoes"><button type="button" class="btn primary" onclick="PranchetaCad.criarLoteNovo()">Criar lote</button></div>`
      $('pc-conferencia').scrollIntoView({block:'nearest',behavior:'smooth'})
    }finally{s.busy=false}
  }
  function criarLoteNovo(){
    if(!s)return
    confirmarAcao({titulo:'Criar lote',mensagem:'Vai inserir um imóvel novo no cadastro, com a geometria desenhada. A criação fica registrada com o seu nome, e o lote passa a ser protegido contra sobrescrita por reimportação do DWG.',textoBtn:'Criar',
      onConfirm:async()=>{const d=await postCadastro('/api/lotes',corpoLoteNovo());if(!d)return;toast(d.message);const cb=s?.opts?.aoGravar;if(s)s.entregue=true;await fechar(true);cb?.()}})
  }
  // A área que o contorno tem de ter (a do cadastro), quando quem abriu a
  // edição foi a pendência de área da conferência: diz quanto falta ou sobra.
  function alvoTxt(area){
    const alvo=Number(s?.opts?.areaAlvo);if(!(alvo>0))return ''
    const d=Math.round((area-alvo)*10000)/10000
    return ` · cadastro ${fmtNum(alvo)} m² · ${d===0?'bate':d>0?'sobram '+fmtMed(d)+' m²':'faltam '+fmtMed(-d)+' m²'}`
  }
  function corpoEdicao(){return {quadra:s.dados.quadra,numero_lote:s.dados.numero_lote,geometry:geometriaLivre()}}
  async function conferirEdicao(){
    if(!s||s.busy)return;if(!s.dados.quadra||!s.dados.numero_lote){$('pc-conferencia').textContent='Informe a quadra e o número do lote.';return}
    s.busy=true;$('pc-conferencia').textContent='Conferindo…'
    try{const d=await request(`/api/lotes/${s.ids[0]}/edicao/previa`,corpoEdicao())
      if(d.impedimento){exibirErro({message:d.impedimento});return}
      const r=d.retrato,antes=r.area_anterior_exata??r.area_anterior_m2,agora=r.area_m2_exata??r.area_m2,dif=Math.round((agora-antes)*10000)/10000
      $('pc-conferencia').innerHTML=`<p>Área: <b>${fmtMed(antes)} m²</b> → <b>${fmtMed(agora)} m²</b>${alvoTxt(Number(agora))}.</p>
        ${dif!==0&&Math.abs(dif)<0.05?`<p class="pc-nota-pequena">Diferença de ${fmtMed(Math.abs(dif))} m²: é de centímetros no desenho. Os lados que mudaram aparecem com a medida de antes, em cinza.</p>`:''}
        <p class="pc-nota-pequena">Grava-se com 2 casas (${fmtNum(agora)} m²), como o cadastro.</p>${(r.vizinhos||[]).filter(v=>v.area_comum>0).map(v=>`<p>Encosta em Q${e(v.quadra)} Lt${e(v.lote)} (${fmtNum(v.area_comum)} m² em comum, dentro da tolerância).</p>`).join('')}<button type="button" class="btn primary" onclick="PranchetaCad.salvarEdicao()">Salvar lote</button>`
    }catch(err){exibirErro(err)}finally{s.busy=false}
  }
  async function salvarEdicao(){
    if(!s||s.busy)return;s.busy=true
    try{const d=await request(`/api/lotes/${s.ids[0]}/edicao`,corpoEdicao());try{localStorage.removeItem(chaveLocal())}catch{}
      const cb=s.opts?.aoSalvar;s.busy=false;await fechar(true);toast(d.message);limparLotesDoMapa();carregarLotesVisiveis();cb?.()
    }catch(err){exibirErro(err)}finally{if(s)s.busy=false}
  }
  // ── FRAÇÃO IDEAL: as unidades, a fração de cada uma e a soma ──
  function unidadesFrac(){const d=s.dados;d.semDesenho||=[];return [...s.resultado.partes.map(r=>({r,d:(d[parteKey(r)]||={})})),...d.semDesenho.map(u=>({r:null,d:u}))]}
  function areaTerrenoFrac(){return Math.abs(G.area(ring(s.originais[0])))}
  function prepararFrac(){
    const us=unidadesFrac(),usadas=new Set(s.identidade?.usados||[0])
    for(const u of us)if(Number.isInteger(u.d.variacao))usadas.add(u.d.variacao)
    for(const u of us)if(!Number.isInteger(u.d.variacao)){let v=1;while(usadas.has(v)&&v<=999)v++;u.d.variacao=v;usadas.add(v)}
    s.dados.condominio??=(s.resultado.partes.length?'casas':'predio')
    if(us.length&&us.every(u=>u.d.fracao_ideal_m2==null))distribuir(false,true)
  }
  // A fração sugerida: proporcional à área exclusiva (ou igual, quando há
  // unidade sem desenho). A última fecha a conta, para a soma bater no centavo.
  function distribuir(igual,quieto){
    if(!s)return;const us=unidadesFrac(),ref=areaTerrenoFrac();if(!us.length)return
    const ex=us.map(u=>u.r?Math.abs(G.area(u.r)):0),soma=ex.reduce((a,b)=>a+b,0);let acum=0
    us.forEach((u,i)=>{let v=igual||soma<=0||ex.some(x=>!x)?ref/us.length:ref*ex[i]/soma;v=Math.round(v*100)/100;if(i===us.length-1)v=Math.round((ref-acum)*100)/100;acum+=v;u.d.fracao_ideal_m2=v})
    if(!quieto){s.revisado=null;formFracao();agendar()}
  }
  function maisSemDesenho(){if(!s)return;s.dados.semDesenho||=[];s.dados.semDesenho.push({});prepararFrac();s.revisado=null;formFracao();agendar()}
  function tirarSemDesenho(j){if(!s)return;s.dados.semDesenho.splice(j,1);s.revisado=null;formFracao();agendar()}
  function somaFrac(){
    const el=$('pc-fr-soma');if(!el)return;const us=unidadesFrac(),ref=areaTerrenoFrac(),soma=us.reduce((a,u)=>a+(Number(u.d.fracao_ideal_m2)||0),0),dif=Math.round((ref-soma)*100)/100,tol=Math.max(.5,.1*Math.sqrt(ref))
    el.innerHTML=`<div class="pc-fr-soma-lin"><span>Soma das frações</span><b class="${Math.abs(dif)<=tol?'ok':'aviso'}">${fmtNum(soma)} de ${fmtNum(ref)} m²${Math.abs(dif)<0.005?' ✓':dif>0?' · faltam '+fmtNum(dif):' · sobram '+fmtNum(-dif)}</b></div>
      <div class="pc-fr-barra">${us.map((u,i)=>`<span style="width:${Math.max(0,(Number(u.d.fracao_ideal_m2)||0)/Math.max(ref,soma)*100)}%;background:${u.r?cores[i%cores.length]:'#6b7280'}"></span>`).join('')}</div>
      <small>Área do terreno pelo desenho; na conferência vale a da matrícula, quando houver.</small>`
  }
  function formFracao(){
    prepararFrac();$('pc-dados').hidden=false
    const us=unidadesFrac(),prefixo=s.identidade?.inscricao?.slice(0,-3)||'Inscrição indisponível',cond=s.dados.condominio,n=s.resultado.partes.length
    let h=`<p class="pc-fr-ajuda">O terreno continua ativo. Cada contorno fechado desenhado dentro dele é a área de uso exclusivo de uma unidade; o resto é área comum.</p>
      <div class="pc-fr-cond" role="radiogroup" aria-label="Tipo de condomínio">
        <label><input type="radio" name="pc-fr-cond" value="casas" ${cond==='casas'?'checked':''}><span><b>Unidades individuais (casas)</b><small>A multa por área usa a área individual: cada casa precisa da área desenhada.</small></span></label>
        <label><input type="radio" name="pc-fr-cond" value="predio" ${cond==='predio'?'checked':''}><span><b>Prédio (apartamentos)</b><small>A multa por área usa a obra toda. O desenho é opcional.</small></span></label></div>`
    if(us.length)h+=`<div class="pc-fr-acoes"><button type="button" class="btn sm out-verde" onclick="PranchetaCad.distribuir(false)">Fração pela área exclusiva</button><button type="button" class="btn sm out-cinza" onclick="PranchetaCad.distribuir(true)">Dividir igual</button></div>`
    else h+='<p class="pc-fr-ajuda">Nenhuma unidade ainda: desenhe a área de cada casa, ou acrescente os apartamentos abaixo.</p>'
    h+=us.map((u,i)=>{const cor=u.r?cores[i%cores.length]:'#6b7280',ex=u.r?fmtNum(Math.abs(G.area(u.r)))+' m² exclusiva':'sem desenho'
      return `<fieldset class="pc-parte-card" style="--parte-cor:${cor}"><legend>Unidade ${i+1}</legend>
        <header class="pc-parte-topo"><div><b data-titulo="${i}">Unidade ${pad3(u.d.variacao)}</b><small>${ex}</small></div>${u.r?'':`<button type="button" class="btn sm out-cinza" aria-label="Tirar a unidade ${i+1}" onclick="PranchetaCad.tirarSemDesenho(${i-n})">Tirar</button>`}</header>
        <div class="pc-campos"><label class="pc-inscricao-label">Inscrição<div class="pc-inscricao-editor"><span>${e(prefixo)}</span><input data-parte="${i}" data-campo="variacao" type="text" inputmode="numeric" maxlength="3" pattern="[0-9]{1,3}" value="${pad3(u.d.variacao)}" aria-label="Variação da unidade ${i+1}"></div></label>
        <label>Fração ideal (m²)<input data-parte="${i}" data-campo="fracao_ideal_m2" type="number" min="0" step="0.01" value="${u.d.fracao_ideal_m2??''}"></label></div></fieldset>`}).join('')
    h+='<button type="button" class="btn sm out-cinza pc-fr-mais" onclick="PranchetaCad.maisSemDesenho()">+ Unidade sem desenho (apartamento)</button><div class="pc-fr-soma" id="pc-fr-soma"></div>'
    if(!s.protocoloId)h+=`<label>Justificativa do ato direto<textarea id="pc-motivo" minlength="10" maxlength="500" placeholder="Descreva o motivo da alteração cadastral">${e(s.justificativa)}</textarea></label>`
    h+='<div class="pc-form-acoes"><button type="button" class="btn out-cinza" onclick="PranchetaCad.salvar().catch(()=>{})">Salvar rascunho</button><button type="button" class="btn primary" onclick="PranchetaCad.conferir()">Conferir e continuar</button></div>'
    $('pc-form').innerHTML=h;$('pc-conferencia').replaceChildren();somaFrac()
    const mudou2=()=>{s.revisado=null;limparErros();$('pc-conferencia').replaceChildren();agendar()}
    for(const r of $('pc-form').querySelectorAll('[name=pc-fr-cond]'))r.addEventListener('change',()=>{s.dados.condominio=r.value;mudou2()})
    for(const input of $('pc-form').querySelectorAll('[data-campo]'))input.addEventListener('input',()=>{
      const u=unidadesFrac()[Number(input.dataset.parte)];if(!u)return
      if(input.dataset.campo==='variacao'){u.d.variacao=/^[0-9]{1,3}$/.test(input.value)?Number(input.value):null;const t=$('pc-form').querySelector(`[data-titulo="${input.dataset.parte}"]`);if(t)t.textContent='Unidade '+(pad3(u.d.variacao)||'???');render()}
      else u.d.fracao_ideal_m2=input.value.trim()===''?null:Number(input.value)
      somaFrac();mudou2()})
    for(const input of $('pc-form').querySelectorAll('[data-campo="variacao"]'))input.addEventListener('blur',()=>{if(/^[0-9]{1,3}$/.test(input.value))input.value=input.value.padStart(3,'0')})
    $('pc-motivo')?.addEventListener('input',ev=>{s.justificativa=ev.target.value;mudou2()})
    render();enquadrar()
  }
  function dados(abrir){
    if(!s)return;s.medindo=null;render();if(!abrir){$('pc-dados').hidden=true;enquadrar();return}
    if(frac()){if(s.traco.length)terminar();if(s.operacao){dica('Conclua ou cancele o movimento primeiro.');return}formFracao();return}
    if(livre()){if(s.traco.length)terminar();if(s.resultado.erro){dica(s.resultado.erro);return}if(s.tipo==='novo'){formLoteNovo();return}if(s.tipo==='edificacao'){entregarLoteNovo();return}formEdicao();return}
    if(s.tipo==='unificacao'&&!s.uniao){dica(s.erroUniao||'Aguarde o cálculo da unificação.');return}if(s.traco.length)terminar();if(s.operacao){dica('Conclua ou cancele o movimento primeiro.');return}
    if(s.tipo==='desmembramento'&&(s.resultado.erro||s.resultado.partes.length<2)){dica(s.resultado.erro||'Trace uma divisão completa antes de preencher as partes.');return}
    prepararIdentidades();$('pc-dados').hidden=false;
    const campos=[['frente_m','Frente (m)'],['fundos_m','Fundos (m)'],['lado_direito_m','Lado direito (m)'],['lado_esquerdo_m','Lado esquerdo (m)'],['area_matricula_m2','Área (m²) · desenho']];
    let h=s.tipo==='unificacao'?`<div class="pc-identidade"><span class="lote-tag-origem">UNIFICADO</span><p>Inscrição: <b>${e(s.inscricaoUniao)}</b></p><p>Área: <b>${fmtNum(Math.abs(G.area(s.resultado.partes[0])))} m²</b></p></div><label>Lote resultante<input id="pc-numero" inputmode="numeric" maxlength="4" value="${e(s.numero)}"><small class="pc-dica-num">Sugerido: menor e maior com dois algarismos cada (0001 + 0002 = 0102). Pode trocar por outro livre na quadra, de 1 a 4 algarismos.</small></label><button class="btn out-verde" type="button" onclick="PranchetaCad.classificarFaces(0)">Conferir medidas pelas faces</button><div class="pc-campos">${campos.map(([k,t])=>`<label>${t}<input data-parte="0" data-campo="${k}" type="number" min="0" step="0.01" value="${e(s.dados[parteKey(s.resultado.partes[0])]?.[k]??(k==='area_matricula_m2'?Number(Math.abs(G.area(s.resultado.partes[0])).toFixed(2)):''))}"></label>`).join('')}</div>`:
      `<section class="pc-sequencia"><div class="pc-sequencia-acoes"><label for="pc-sequencia-inicio">Sequência</label><input id="pc-sequencia-inicio" type="number" min="0" max="999" step="1" value="1"><button type="button" class="btn out-verde" onclick="PranchetaCad.sequenciar()">Aplicar</button></div></section>`+
      s.resultado.partes.map((r,i)=>{
        const d=s.dados[parteKey(r)]||{},prefixo=s.identidade?.inscricao?.slice(0,-3)||'Inscrição indisponível';
        return `<fieldset class="pc-parte-card" style="--parte-cor:${cores[i%cores.length]}"><legend>Parte ${i+1}</legend>
          <header class="pc-parte-topo"><div><b>${e(d.numero_lote||'Sem apelido')}</b><small>${fmtNum(Math.abs(G.area(r)))} m² · DESMEMBRADO</small></div><div class="pc-ordem-botoes"><button class="btn sm out-cinza" type="button" aria-label="Mover parte ${i+1} para cima" ${i===0?'disabled':''} onclick="PranchetaCad.ordenarParte(${i},-1)">↑</button><button class="btn sm out-cinza" type="button" aria-label="Mover parte ${i+1} para baixo" ${i===s.resultado.partes.length-1?'disabled':''} onclick="PranchetaCad.ordenarParte(${i},1)">↓</button></div></header>
          <label class="pc-inscricao-label">Inscrição imobiliária<div class="pc-inscricao-editor"><span>${e(prefixo)}</span><input aria-label="Final da inscrição da parte ${i+1}" data-parte="${i}" data-campo="desmembramento" type="text" inputmode="numeric" maxlength="3" title="Os três últimos dígitos da inscrição (inclusive 000); o prefixo vem do lote original" pattern="[0-9]{1,3}" value="${d.desmembramento==null?'':String(d.desmembramento).padStart(3,'0')}" placeholder="000"></div></label>
          ${botaoMais()}<div class="pc-parte-mais" hidden>
          <div class="pc-identidade" data-identidade="${i}">${identidadeTexto(d)}</div>
          <div class="pc-medidas"><b class="pc-medidas-tit">Dimensões e área</b><button class="btn sm out-verde" type="button" onclick="PranchetaCad.classificarFaces(${i})">Calcular medidas pelas faces</button><div class="pc-campos">${campos.map(([k,t])=>`<label>${t}<input data-parte="${i}" data-campo="${k}" type="number" step="0.01" min="0" value="${e(d[k])}"></label>`).join('')}</div></div></div></fieldset>`
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
    abrirCartoesComErro()   // campo com erro no "Mostrar mais": o cartão abre
    $('pc-conferencia').innerHTML=`<div class="pc-erro-resumo" role="alert"><b>${e(mensagens.length?'Revise os campos indicados':comCruzamentos(err.message||'Não foi possível concluir.'))}</b>${mensagens.length?'<ul>'+mensagens.map(m=>'<li>'+e(m)+'</li>').join('')+'</ul>':''}</div>`;
    const alvo=primeiro||$('pc-conferencia');alvo.focus();alvo.scrollIntoView({block:'nearest',behavior:'smooth'})
  }
  function validarDados(){
    const campos={};if(!s.protocoloId){const m=s.justificativa.trim();if(m.length<10||m.length>500)campos.justificativa='Informe a justificativa com 10 a 500 caracteres.'}
    if(frac()){const us=unidadesFrac(),usados=new Set(s.identidade?.usados||[0]),vistos=new Set()
      if(us.length<2)campos._='Fração ideal pede ao menos duas unidades.'
      if(!s.dados.condominio)campos._c='Escolha se é de casas ou um prédio.'
      us.forEach((u,i)=>{const v=u.d.variacao,k='partes.'+i+'.variacao'
        if(!s.identidade?.prefixo)campos[k]='A inscrição do terreno está indisponível. Corrija o cadastro antes.'
        else if(!Number.isInteger(v)||v<1||v>999)campos[k]='Unidade '+(i+1)+': variação de 001 a 999 (a 000 é o terreno).'
        else if(usados.has(v)||vistos.has(v))campos[k]='Unidade '+(i+1)+': esta variação já está em uso.'
        vistos.add(v);if(!(Number(u.d.fracao_ideal_m2)>0))campos['partes.'+i+'.fracao_ideal_m2']='Unidade '+(i+1)+': informe a fração ideal em m².'})
      if(Object.keys(campos).length){exibirErro({campos});return false}limparErros();return true}
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
  async function conferir(){if(s.busy||!validarDados())return;const c=corpo();s.busy=true;$('pc-conferencia').textContent='Conferindo…';try{const d=await request(rota(true),c);if(JSON.stringify(c)!==JSON.stringify(corpo()))return;if(d.impedimento){exibirErro({message:d.impedimento});return}s.revisado=JSON.stringify(c);const rf=d.retrato;$('pc-conferencia').innerHTML=(frac()?`<p>Conferido: ${rf.unidades.length} unidade(s); as frações somam <b>${fmtNum(rf.soma_fracoes)}</b> de ${fmtNum(rf.referencia)} m²${rf.area_comum?' · área comum '+fmtNum(rf.area_comum)+' m²':''}. O terreno continua ativo e as unidades nascem dentro dele.</p>`:`<p>Geometria conferida. O ato preservará os imóveis de origem como inativos e criará os sucessores.</p>`)+`${(d.avisos||[]).map(a=>`<p>${e(a)}</p>`).join('')}<button type="button" class="btn primary" onclick="PranchetaCad.finalizar()">${frac()?'Confirmar e instituir as frações':'Confirmar e finalizar '+(s.tipo==='unificacao'?'unificação':'desmembramento')}</button>`}catch(err){exibirErro(err)}finally{s.busy=false}}
  async function finalizar(){if(!s||s.busy||!validarDados())return;const c=corpo();if(s.revisado!==JSON.stringify(c)){dica('Confira os dados novamente.');return}if(!s.protocoloId&&s.justificativa.trim().length<10){$('pc-conferencia').textContent='Informe a justificativa do ato direto (mínimo de 10 caracteres).';return}s.busy=true;clearTimeout(saveTimer);try{await fila.catch(()=>{});const d=await request(rota(),c);try{localStorage.removeItem(chaveLocal())}catch{}s.leitura=true;s.finalFeatures=(d.lotes||[{id:d.id,numero_lote:s.numero}]).map((p,i)=>({properties:p}));$('pc-dados').hidden=true;$('pc-save').hidden=true;$('pc-continuar').hidden=true;$('pc-titulo').textContent='Prancha finalizada · '+({fracao_ideal:'Fração ideal',desmembramento:'Desmembramento',unificacao:'Unificação'}[s.tipo]||s.tipo);$('pc-salvo').textContent='Desenho e orientação salvos com o ato';toast(d.message);atoState.tipo=null;limparLotesDoMapa();carregarLotesVisiveis();render()}catch(err){exibirErro(err)}finally{s.busy=false}}
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
    document.dispatchEvent(new CustomEvent('ferramenta-encerrada'))
    if(typeof pintarPainelCadastro==='function')pintarPainelCadastro()
    aoDesistir?.()
    return true
  }
  function exportar(){if(!s)return;const clone=svg.cloneNode(true);clone.querySelectorAll('image,[data-snap],.pc-snap').forEach(n=>n.remove());clone.removeAttribute('id');clone.setAttribute('width',s.w);clone.setAttribute('height',s.h);const style=document.createElementNS('http://www.w3.org/2000/svg','style');style.textContent='.pc-label,.pc-cota,.pc-confronto,.pc-vizinho{font:12px sans-serif;fill:#243b4b;paint-order:stroke;stroke:white;stroke-width:4px}.pc-hit{fill:none;stroke:transparent;stroke-width:14}.pc-confronto{font-weight:bold}.pc-snap{font:12px sans-serif;fill:#16803c}';clone.prepend(style);const url=URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(clone)],{type:'image/svg+xml'}));const a=document.createElement('a');a.href=url;a.download=`${s.tipo}-${s.ids.join('-')}.svg`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)}
  async function verSalvas(id){try{const r=await fetch(`/api/lotes/${id}/pranchas`,{headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok)throw new Error(d.message);if(!d.pranchas.length){toast('Este imóvel ainda não possui prancha finalizada.', 'aviso');return}const p=d.pranchas[0];await abrir(p.tipo,p.visualizacao.originais.map(f=>f.properties.id),null,p.visualizacao)}catch(err){toast(err.message,'err')}}
  return {esquadrejar,cotaTrocarPonta:trocarPontaCota,cotaJunto:v=>{if(s?.cotaEd){s.cotaEd.junto=!!v;$('pc-cota-valor').focus()}},distribuir,maisSemDesenho,tirarSemDesenho,ativa:()=>!!s,opcoesSnap,mais,conferirLoteNovo,criarLoteNovo,ortho:()=>{alternarOrtho();if(s)render()},conferirEdicao,salvarEdicao,ordenarParte,sequenciar,classificarFaces,abrir,fechar,ferramenta,terminar,excluir,historico,salvar,exportar,enquadrar,norte,mapa,dados,conferir,finalizar,verSalvas}
})()
