const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm')
function carregar(arquivo,nome,contexto){const fonte=fs.readFileSync(arquivo,'utf8'),inicio=fonte.indexOf('function '+nome+'('),fim=fonte.indexOf('\n}',inicio)+2;vm.runInNewContext(fonte.slice(inicio,fim),contexto);return contexto[nome]}
test('abrir curadoria limpa seleção simples e múltipla antes de abrir a mesa',()=>{
  const chamadas=[],mesa={hidden:true};const ctx={document:{getElementById:()=>mesa},ehMesaCadastral:()=>true,fecharPaineisMapa:()=>{},limparSelecao:()=>chamadas.push('simples'),limparSelecaoCadastral:()=>chamadas.push('multipla'),abrirMesaCadastral:()=>chamadas.push('abrir'),fecharMesaCadastral:()=>chamadas.push('fechar'),fecharMesaPeloUsuario:()=>chamadas.push('fechar'),pedirFerramenta:(n,f)=>f()};
  const abrir=carregar('public/js/mapa.js','alternarPainelMapa',ctx);abrir('grupo-cadastro');assert.deepEqual(chamadas,['simples','multipla','abrir']);chamadas.length=0;mesa.hidden=false;abrir('grupo-cadastro');assert.deepEqual(chamadas,['fechar'])
})
test('trocar para outra aba fecha a mesa, voltar ao mapa não a reabre',()=>{
  const chamadas=[],cl={add(){},remove(){}};
  const ctx={document:{querySelectorAll:()=>[],getElementById:()=>({classList:cl})},marcarModuloNoSubcabecalho(){},pintarBarraCadastral(){},fecharPaineisMapa(){},fecharMesaCadastral:()=>chamadas.push('fechar'),prepararBusca(){},carregarPainel(){},carregarDocumentos(){},carregarDemandas(){},setTimeout(){}};
  const ir=carregar('public/js/app.js','irPara',ctx);for(const destino of ['busca','painel','documentos','protocolos'])ir(destino);assert.equal(chamadas.length,4);ir('mapa');assert.equal(chamadas.length,4)
})

test('a navegação aguarda o rascunho e permanece na prancheta quando a saída falha',async()=>{
  let ativa=true,autorizar=false,navegou=0;
  const ctx={PranchetaCad:{ativa:()=>ativa,fechar:async()=>{if(autorizar)ativa=false;return autorizar}},document:{querySelectorAll:()=>[],getElementById:()=>({classList:{add(){},remove(){}}})},marcarModuloNoSubcabecalho(){},carregarDocumentos(){navegou++}};
  const ir=carregar('public/js/app.js','irPara',ctx);
  ir('documentos');await Promise.resolve();assert.equal(navegou,0);
  autorizar=true;ir('documentos');await Promise.resolve();assert.equal(navegou,1)
})

// ── Uma ferramenta do mapa por vez (public/js/ferramentas-mapa.js) ──
function porteiro(estado){
  const grupos={'grupo-busca':{aberto:estado.busca},'grupo-pins':{aberto:false},'grupo-cores':{aberto:false},'grupo-cadastro':{aberto:false}}
  const ctx={estado,chamadas:[],
    document:{getElementById:id=>id==='cad-mesa'?{hidden:!estado.mesa}:id==='pesq-barra'?{hidden:!estado.busca}:grupos[id]?{classList:{contains:()=>grupos[id].aberto}}:null},
    selState:{ids:new Set(estado.marcados||[]),ativa:false},cadModo:null,atoState:{tipo:null},
    fecharPaineisMapa(){Object.values(grupos).forEach(g=>g.aberto=false)},
    fecharPesquisaMapa(){estado.busca=false},
    fecharMesaCadastral(){estado.mesa=false},limparSelecaoCadastral(){ctx.selState.ids.clear()},
    confirmarAcao(o){ctx.confirmado=o},setTimeout:f=>f(),console}
  vm.runInNewContext(fs.readFileSync('public/js/ferramentas-mapa.js','utf8'),ctx)
  return ctx
}
test('abrir a busca fecha a curadoria aberta sem trabalho em curso',()=>{
  const c=porteiro({mesa:true});let abriu=false
  c.pedirFerramenta('busca',()=>abriu=true)
  assert.equal(abriu,true);assert.equal(c.estado.mesa,false);assert.equal(c.confirmado,undefined)
})
test('com lote marcado, pergunta antes — e só encerra se confirmar',()=>{
  const c=porteiro({mesa:true,marcados:[7,8]});let abriu=false
  c.pedirFerramenta('busca',()=>abriu=true)
  assert.equal(abriu,false);assert.equal(c.estado.mesa,true);assert.match(c.confirmado.mensagem,/2 lote\(s\) marcados/)
  c.confirmado.onConfirm()
  assert.equal(abriu,true);assert.equal(c.estado.mesa,false);assert.equal(c.selState.ids.size,0)
})
test('a curadoria fecha a busca ao abrir, e o que convive não é fechado',()=>{
  const c=porteiro({busca:true});let abriu=false
  c.pedirFerramenta('curadoria',()=>abriu=true)
  assert.equal(abriu,true);assert.equal(c.estado.busca,false)
  const d=porteiro({mesa:true});d.pedirFerramenta('desenho',()=>{},{convive:['curadoria']})
  assert.equal(d.estado.mesa,true)
})
