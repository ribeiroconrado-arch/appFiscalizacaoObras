/* Geometria métrica da prancheta, independente da tela e do Leaflet. */
const PranchetaGeo = (() => {
  const EPS = 1e-7
  const add = (a,b) => [a[0]+b[0],a[1]+b[1]]
  const sub = (a,b) => [a[0]-b[0],a[1]-b[1]]
  const mul = (a,n) => [a[0]*n,a[1]*n]
  const dot = (a,b) => a[0]*b[0]+a[1]*b[1]
  const cross = (a,b) => a[0]*b[1]-a[1]*b[0]
  const len = a => Math.hypot(...a)
  const unit = a => mul(a,1/(len(a)||1))
  const normal = (a,b) => { const d=unit(sub(b,a)); return [-d[1],d[0]] }
  // Junções colineares, com tolerância de 1 mm, só para apresentar as faces.
  function juntarFaces(anel,tolerancia=.001,anguloMaximo=.25) {
    const pontos=anel.slice(0,-1).map(p=>[...p]);let mudou=true;
    while(mudou&&pontos.length>3){mudou=false;
      for(let i=0;i<pontos.length;i++){
        const a=pontos[(i+pontos.length-1)%pontos.length],b=pontos[i],c=pontos[(i+1)%pontos.length],ab=sub(b,a),bc=sub(c,b),ac=sub(c,a),comprimento=len(ac);
        const alinhadas=Math.abs(cross(ab,bc))<=Math.sin(anguloMaximo*Math.PI/180)*len(ab)*len(bc);
        if(comprimento>EPS&&dot(ab,bc)>=0&&alinhadas&&Math.abs(cross(ac,ab))/comprimento<=tolerancia){pontos.splice(i,1);mudou=true;break}
      }
    }
    return pontos.length?[...pontos,[...pontos[0]]]:[]
  }
  /**
   * As FACES de um anel para apresentar e classificar: os segmentos, com cada
   * ARCO (o canto chanfrado/arredondado do lote, que o desenho traz em vários
   * pedacinhos) juntado numa face só. Arco é uma sequência de 2+ segmentos de
   * tamanho parecido (até o dobro um do outro), cada um virando para o MESMO
   * lado em ângulo pequeno (0,3° a 40°). Lado reto continua sendo uma face.
   *
   * @returns {Array<{ini:number, fim:number, comprimento:number, arco:boolean}>}
   *   índices de SEGMENTO (o segmento k vai de anel[k] a anel[k+1]), inclusivos
   */
  function agruparFaces(anel) {
    const n=anel.length-1, comp=k=>len(sub(anel[k+1],anel[k]))
    // Segmento de comprimento zero (ponto repetido no desenho) não é face nem
    // interrompe o arco: entra na face vizinha.
    const validos=[];for(let k=0;k<n;k++)if(comp(k)>=1e-3)validos.push(k)
    const giro=(k1,k2)=>{const u=sub(anel[k1+1],anel[k1]),v=sub(anel[k2+1],anel[k2]);return Math.atan2(cross(u,v),dot(u,v))*180/Math.PI}
    const faces=[];let i=0
    while(i<validos.length){let j=i
      while(j+1<validos.length){const g=giro(validos[j],validos[j+1]),c0=comp(validos[i]),c1=comp(validos[j+1])
        const mesmoLado=j===i||Math.sign(g)===Math.sign(giro(validos[i],validos[i+1]))
        if(Math.abs(g)<.3||Math.abs(g)>40||!mesmoLado||c1>2*c0||c1<c0/2)break
        j++}
      faces.push({ini:validos[i],fim:validos[j],arco:j>i});i=j+1}
    if(!faces.length)return n?[{ini:0,fim:n-1,comprimento:0,arco:false}]:[]
    // Os de comprimento zero completam os intervalos: cada face vai até a seguinte.
    faces[0].ini=0
    for(let k=0;k<faces.length;k++)faces[k].fim=k+1<faces.length?faces[k+1].ini-1:n-1
    for(const fc of faces){let c=0;for(let j=fc.ini;j<=fc.fim;j++)c+=comp(j);fc.comprimento=c}
    return faces
  }
  /**
   * ESQUADREJAR um contorno fechado: todo canto a até `tol` graus de 90° vira
   * 90° exato, e CADA LADO MANTÉM O COMPRIMENTO. Os outros cantos (arco,
   * esquina torta de verdade) mantêm o ângulo que tinham. O lado mais comprido
   * fica parado (vértice inicial e direção); o resto é refeito a partir dele.
   *
   * Recusa quando, esquadrejado, o contorno não fecha (as medidas opostas não
   * batem — 50,0000 de um lado e 49,9952 do outro): fechar à força entortaria
   * outro canto, e é a medida que precisa ser acertada antes.
   *
   * @returns {{pontos:Array<[number,number]>, cantos:number}|{erro:string}}
   */
  function esquadrejar(anel,tol=1,fechaM=.001){
    if(!fechada(anel)||anel.length<5)return {erro:'Esquadrejar vale para contorno fechado.'}
    const v=anel.slice(0,-1),n=v.length,lado=k=>sub(v[(k+1)%n],v[k])
    // Começa pelo lado mais comprido.
    let k0=0;for(let k=1;k<n;k++)if(len(lado(k))>len(lado(k0)))k0=k
    const vs=[...v.slice(k0),...v.slice(0,k0)],L=k=>sub(vs[(k+1)%n],vs[k])
    const giro=k=>{const u=L(k-1<0?n-1:k-1),w=L(k);return Math.atan2(cross(u,w),dot(u,w))}
    const out=[[...vs[0]]];let dir=Math.atan2(L(0)[1],L(0)[0]),cantos=0
    for(let k=0;k<n-1;k++){
      if(k>0){const g=giro(k);if(Math.abs(Math.abs(g)-Math.PI/2)<=tol*Math.PI/180&&Math.abs(Math.abs(g)-Math.PI/2)>1e-12){dir+=Math.sign(g)*Math.PI/2;cantos++}else dir+=g}
      const c=len(L(k)),p=out[k];out.push([p[0]+c*Math.cos(dir),p[1]+c*Math.sin(dir)])}
    // O último lado tem de chegar ao vértice inicial com o mesmo comprimento,
    // e os cantos das duas pontas dele, quase retos, têm de ficar retos.
    const ult=out[n-1],fecha=sub(out[0],ult),cmp=len(L(n-1))
    const dUlt=Math.atan2(fecha[1],fecha[0]),gUlt=giro(n-1),g0=giro(0)
    const angDe=(a,b)=>{let d=b-a;while(d>Math.PI)d-=2*Math.PI;while(d<-Math.PI)d+=2*Math.PI;return d}
    const ehReto=g=>Math.abs(Math.abs(g)-Math.PI/2)<=tol*Math.PI/180
    const reto=(g,esperado)=>!ehReto(g)||Math.abs(Math.abs(esperado)-Math.PI/2)<1e-6
    if(Math.abs(len(fecha)-cmp)>fechaM||!reto(gUlt,angDe(dir,dUlt))||!reto(g0,angDe(dUlt,Math.atan2(L(0)[1],L(0)[0]))))
      return {erro:'As medidas não fecham em esquadro: os lados opostos não têm o mesmo comprimento. Acerte as medidas (duplo clique na cota) e esquadreje de novo.'}
    if(ehReto(gUlt)&&Math.abs(Math.abs(gUlt)-Math.PI/2)>1e-12)cantos++
    if(ehReto(g0)&&Math.abs(Math.abs(g0)-Math.PI/2)>1e-12)cantos++
    // Volta à ordem original dos vértices.
    const res=[...out.slice(n-k0),...out.slice(0,n-k0)]
    return {pontos:[...res,[...res[0]]],cantos}
  }
  /* Fator de escala do UTM — CÓPIA DELIBERADA de `fatorEscalaUTM` (geo.js),
     que é onde está a explicação de por que a régua do sistema é a GRADE e
     não o terreno. Este arquivo não pode depender de geo.js: ele é carregado
     isolado, sem globais, por tests/prancheta-geo.test.cjs (vm.runInNewContext),
     e é esse isolamento que deixa a geometria da prancheta ser testada sem
     navegador. Ao mexer numa das duas, mexa na outra — o teste
     'grade do UTM' compara as duas contra o mesmo valor esperado. */
  function escalaUTM(lat,lon) {
    const rad=Math.PI/180, zona=Math.floor((lon+180)/6)+1, mc=zona*6-183
    const f=lat*rad, t=Math.tan(f), e2=.00669438002290, le2=e2/(1-e2)
    const eta2=le2*Math.cos(f)**2, a=(lon-mc)*rad*Math.cos(f), a2=a*a
    return .9996*(1+(1+eta2)*a2/2+(5-4*t*t+42*eta2+13*eta2*eta2-28*le2)*a2*a2/24)
  }
  function plano([lon,lat]) {
    const s=Math.sin(lat*Math.PI/180), w=1-.00669437999014*s*s
    const k=escalaUTM(lat,lon)
    const ky=6378137*(1-.00669437999014)/w**1.5*Math.PI/180*k
    const kx=6378137/Math.sqrt(w)*Math.cos(lat*Math.PI/180)*Math.PI/180*k
    return { para: c=>[(c[0]-lon)*kx,(c[1]-lat)*ky], de: p=>[lon+p[0]/kx,lat+p[1]/ky] }
  }
  function rot(p,a) { return [p[0]*Math.cos(a)-p[1]*Math.sin(a),p[0]*Math.sin(a)+p[1]*Math.cos(a)] }
  function pe(p,a,b,segmento=true) {
    const d=sub(b,a), t=dot(sub(p,a),d)/(dot(d,d)||1)
    return add(a,mul(d,segmento?Math.max(0,Math.min(1,t)):t))
  }
  function area(r) { let s=0; const o=r[0]; for(let i=0;i<r.length;i++) s+=cross(sub(r[i],o),sub(r[(i+1)%r.length],o)); return s/2 }
  function dentro(p,r) {
    let c=false
    for(let i=0,j=r.length-1;i<r.length;j=i++) {
      if(len(sub(p,pe(p,r[j],r[i])))<EPS) return true
      if((r[i][1]>p[1])!==(r[j][1]>p[1]) && p[0]<(r[j][0]-r[i][0])*(p[1]-r[i][1])/(r[j][1]-r[i][1])+r[i][0]) c=!c
    }
    return c
  }
  function inter(a,b,c,d,segmentos=true) {
    const u=sub(b,a), v=sub(d,c), den=cross(u,v)
    if(Math.abs(den)<1e-12) return null
    const t=cross(sub(c,a),v)/den, s=cross(sub(c,a),u)/den
    if(segmentos && (t < -EPS || t>1+EPS || s < -EPS || s>1+EPS)) return null
    return {p:add(a,mul(u,t)),t,s}
  }
  function offset(linha,m) {
    if(linha.length<2) return linha
    const lados=linha.slice(1).map((b,i)=>{const n=mul(normal(linha[i],b),m);return [add(linha[i],n),add(b,n)]})
    const r=[lados[0][0]]
    for(let i=1;i<lados.length;i++) {
      const x=inter(...lados[i-1],...lados[i],false)?.p || lados[i][0]
      if(len(sub(x,linha[i]))>Math.max(1,Math.abs(m))*50) throw new Error('O offset cria uma quina muito longa. Ajuste o vértice primeiro.')
      r.push(x)
    }
    r.push(lados.at(-1)[1]); return r
  }
  // Grafo planar: quebra todos os segmentos nos cruzamentos e percorre as
  // faces à esquerda. Aceita linhas independentes, cruzes e encontros em T.
  function dividir(anel,linhas) {
    let r=anel.map(p=>[...p]); if(len(sub(r[0],r.at(-1)))<EPS) r.pop()
    if(r.length<3) return {erro:'O lote não tem contorno válido.',partes:[]}
    if(area(r)<0) r.reverse()
    const seg=[]
    for(let i=0;i<r.length;i++) seg.push({a:r[i],b:r[(i+1)%r.length],borda:true,cortes:[0,1]})
    for(const [li,l] of linhas.entries()) for(let j=1;j<l.length;j++) {
      if(len(sub(l[j],l[j-1]))<EPS) continue
      seg.push({a:l[j-1],b:l[j],li,borda:false,cortes:[0,1]})
    }
    if(seg.length>1200) return {erro:'Desenho muito complexo: limite de 1.200 segmentos.',partes:[]}
    for(let i=0;i<seg.length;i++) for(let j=i+1;j<seg.length;j++) {
      const a=seg[i], b=seg[j], x=inter(a.a,a.b,b.a,b.b)
      if(x) { a.cortes.push(Math.max(0,Math.min(1,x.t))); b.cortes.push(Math.max(0,Math.min(1,x.s))) }
      else { // Colinearidade e extremos coincidentes: evita arestas duplicadas.
        for(const p of [b.a,b.b]) if(len(sub(p,pe(p,a.a,a.b)))<EPS) a.cortes.push(dot(sub(p,a.a),sub(a.b,a.a))/dot(sub(a.b,a.a),sub(a.b,a.a)))
        for(const p of [a.a,a.b]) if(len(sub(p,pe(p,b.a,b.b)))<EPS) b.cortes.push(dot(sub(p,b.a),sub(b.b,b.a))/dot(sub(b.b,b.a),sub(b.b,b.a)))
      }
    }
    const nodes=[], buckets=new Map(), edges=new Map()
    const node=p=>{
      const gx=Math.round(p[0]/EPS),gy=Math.round(p[1]/EPS)
      for(let x=-1;x<=1;x++) for(let y=-1;y<=1;y++) for(const id of buckets.get(`${gx+x}:${gy+y}`)||[]) if(len(sub(nodes[id].p,p))<EPS) return id
      const id=nodes.length,key=`${gx}:${gy}`; nodes.push({p,adj:[]}); buckets.set(key,[...(buckets.get(key)||[]),id]); return id
    }
    for(const s of seg) {
      s.cortes.sort((a,b)=>a-b)
      for(let i=1;i<s.cortes.length;i++) {
        const a=add(s.a,mul(sub(s.b,s.a),s.cortes[i-1])), b=add(s.a,mul(sub(s.b,s.a),s.cortes[i]))
        if(len(sub(b,a))<EPS || (!s.borda&&!dentro(mul(add(a,b),.5),r))) continue
        const u=node(a),v=node(b);if(u===v)continue
        const key=u<v?`${u}:${v}`:`${v}:${u}`
        if(!edges.has(key)){ edges.set(key,{u,v,borda:s.borda});nodes[u].adj.push(v);nodes[v].adj.push(u) }
      }
    }
    for(const n of nodes) n.adj.sort((a,b)=>Math.atan2(nodes[a].p[1]-n.p[1],nodes[a].p[0]-n.p[0])-Math.atan2(nodes[b].p[1]-n.p[1],nodes[b].p[0]-n.p[0]))
    const visit=new Set(),faces=[],uses=new Map()
    for(const e of edges.values()) for(const [a,b] of [[e.u,e.v],[e.v,e.u]]) {
      if(visit.has(`${a}:${b}`))continue
      let u=a,v=b; const ids=[], path=[]
      for(let guard=0;guard<edges.size*2+1;guard++) {
        const key=`${u}:${v}`; if(visit.has(key))break
        visit.add(key);ids.push(u);path.push(u<v?`${u}:${v}`:`${v}:${u}`)
        const adj=nodes[v].adj, next=adj[(adj.indexOf(u)-1+adj.length)%adj.length]; u=v;v=next
        if(u===a&&v===b)break
      }
      const poly=ids.map(id=>nodes[id].p), ar=poly.length>2?area(poly):0
      if(ar>EPS) {
        faces.push(poly)
        for(const key of new Set(path)) uses.set(key,(uses.get(key)||0)+1)
      }
    }
    const soma=faces.reduce((s,p)=>s+area(p),0)
    if(Math.abs(soma-area(r))>Math.max(.0001,area(r)*1e-7)) return {erro:'A divisão não cobre o lote. Verifique linhas fechadas no interior.',partes:[]}
    const pendentes=[...edges.entries()].filter(([k,e])=>!e.borda&&(uses.get(k)||0)!==2).length
    // Um ramo solto pode percorrer a mesma aresta nos dois sentidos. Não é
    // polígono cadastral; a prévia fica indisponível até terminar a ligação.
    if(pendentes) return {erro:`Há ${pendentes} trecho(s) solto(s). Ligue-os a uma divisa ou exclua-os.`,partes:[],pendentes}
    if(faces.length>20) return {erro:'Limite de 20 partes.',partes:[]}
    return {partes:faces.map(p=>[...p,p[0]]),erro:null}
  }
  // ── CONCORDÂNCIA (o "fillet" do AutoCAD) ─────────────────────────
  // Linha fechada: primeiro e último ponto coincidem e há ao menos três cantos.
  function fechada(ps) { return ps.length>=4 && len(sub(ps[0],ps.at(-1)))<1e-6 }
  // O arco de raio `r` que substitui o canto `v` entre os lados v→a e v→b.
  // Devolve os pontos do arco, do ponto de tangência em va ao ponto em vb.
  function arcoNoCanto(a,v,b,r,n=8) {
    const u1=unit(sub(a,v)), u2=unit(sub(b,v)), c=Math.max(-1,Math.min(1,dot(u1,u2))), th=Math.acos(c)
    if(th<Math.PI/180||th>Math.PI*179/180) throw new Error('Os dois lados estão quase alinhados: não há canto para arredondar.')
    const d=r/Math.tan(th/2), max=Math.min(len(sub(a,v)),len(sub(b,v)))
    if(d>max-1e-6) throw new Error(`O raio não cabe nesses lados. O máximo aqui é ${(max*Math.tan(th/2)).toFixed(2).replace('.',',')} m.`)
    const t1=add(v,mul(u1,d)), t2=add(v,mul(u2,d)), centro=add(v,mul(unit(add(u1,u2)),r/Math.sin(th/2)))
    const a1=Math.atan2(t1[1]-centro[1],t1[0]-centro[0]), a2=Math.atan2(t2[1]-centro[1],t2[0]-centro[0])
    let delta=a2-a1; while(delta>Math.PI)delta-=2*Math.PI; while(delta<-Math.PI)delta+=2*Math.PI
    const pts=[]; for(let k=0;k<=n;k++){const ang=a1+delta*k/n;pts.push([centro[0]+r*Math.cos(ang),centro[1]+r*Math.sin(ang)])}
    pts[0]=t1; pts[n]=t2; return pts
  }
  // Arredonda o canto `k` de uma linha (aberta ou fechada) com raio r.
  function arredondar(ps,k,r,n=8) {
    const f=fechada(ps), m=f?ps.length-1:ps.length
    if(!f&&(k<=0||k>=m-1)) throw new Error('A ponta solta de uma linha não é canto.')
    const prev=ps[(k-1+m)%m], v=ps[k%m], next=ps[(k+1)%m], arco=arcoNoCanto(prev,v,next,r,n)
    if(!f) return [...ps.slice(0,k),...arco,...ps.slice(k+1)]
    const anel=ps.slice(0,m), resto=[...anel.slice(k+1),...anel.slice(0,k)]   // depois do canto, até antes dele
    return [...arco,...resto,[...arco[0]]]
  }
  /**
   * Concordância entre dois lados escolhidos. `s1`/`s2`: {id, i, a, b} — `id` é
   * a linha editável (null para face fixa: contorno do lote, vizinho) e `i` o
   * índice do lado nela. Raio 0 estende/apara até o encontro; raio > 0
   * arredonda o canto. Devolve {linhas} novas ou {erro}.
   */
  function concordancia(linhas,s1,s2,r,n=8) {
    try {
      linhas=linhas.map(l=>({...l,pontos:l.pontos.map(p=>[...p])}))
      const L=id=>linhas.find(l=>l.id===id)
      if(s1.id==null&&s2.id==null) throw new Error('Escolha ao menos um lado desenhado: faces do contorno e dos vizinhos não se movem.')
      if(s1.id==null){const t=s1;s1=s2;s2=t}
      // Mesmo canto de uma linha: lados vizinhos.
      if(s1.id===s2.id){
        const ps=L(s1.id).pontos,f=fechada(ps),m=ps.length-1,[i,j]=[s1.i,s2.i].sort((x,y)=>x-y)
        if(i===j) throw new Error('Escolha dois lados diferentes.')
        let k=null
        if(j===i+1)k=j; else if(f&&i===0&&j===m-1)k=0
        if(k!==null){
          if(!(r>0)) throw new Error('Esses lados já se encontram. Informe um raio maior que zero para arredondar o canto.')
          L(s1.id).pontos=arredondar(ps,k,r,n); return {linhas}
        }
      }
      const lado=sel=>{if(sel.id==null)return [sel.a,sel.b];const ps=L(sel.id).pontos;return [ps[sel.i],ps[sel.i+1]]}
      const [a1,b1]=lado(s1),[a2,b2]=lado(s2),x=inter(a1,b1,a2,b2,false)
      if(!x) throw new Error('Os lados são paralelos: eles nunca se encontram.')
      const X=x.p
      // A ponta solta do lado escolhido; numa linha de dois pontos, a mais perto do encontro.
      const ponta=sel=>{const ps=L(sel.id).pontos,m=ps.length-1;if(fechada(ps))return null
        if(m===1)return len(sub(ps[0],X))<len(sub(ps[1],X))?0:1
        return sel.i===0?0:sel.i===m-1?m:null}
      const p1=ponta(s1)
      if(p1==null) throw new Error('Escolha lados que terminam soltos, ou dois lados vizinhos de um mesmo canto.')
      if(s2.id==null){
        if(r>0) throw new Error('Com uma face fixa (contorno ou vizinho) a concordância é de raio 0: a linha é estendida ou aparada até ela.')
        L(s1.id).pontos[p1]=X; return {linhas}
      }
      const p2=ponta(s2)
      if(p2==null) throw new Error('Escolha lados que terminam soltos, ou dois lados vizinhos de um mesmo canto.')
      if(s1.id===s2.id){
        const l=L(s1.id); if(p1===p2) throw new Error('Escolha as duas pontas da linha para fechá-la.')
        l.pontos[0]=X; l.pontos[l.pontos.length-1]=[...X]
        if(r>0) l.pontos=arredondar(l.pontos,0,r,n)
        return {linhas}
      }
      // Duas linhas: a primeira termina no encontro, a segunda começa nele.
      const l1=L(s1.id), l2=L(s2.id)
      let q1=p1===0?[...l1.pontos].reverse():l1.pontos, q2=p2===0?l2.pontos:[...l2.pontos].reverse()
      q1=[...q1.slice(0,-1),X]; q2=[[...X],...q2.slice(1)]
      let unida=[...q1,...q2.slice(1)]
      if(r>0) unida=arredondar(unida,q1.length-1,r,n)
      l1.pontos=unida; linhas=linhas.filter(l=>l.id!==l2.id)
      return {linhas,unida:l1.id}
    } catch(e) { return {erro:e.message} }
  }
  return {EPS,add,sub,mul,dot,cross,len,unit,normal,plano,escalaUTM,rot,pe,area,dentro,inter,offset,dividir,juntarFaces,agruparFaces,esquadrejar,fechada,arcoNoCanto,arredondar,concordancia}
})()
