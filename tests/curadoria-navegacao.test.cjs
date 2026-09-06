const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm')
function carregar(arquivo,nome,contexto){const fonte=fs.readFileSync(arquivo,'utf8'),inicio=fonte.indexOf('function '+nome+'('),fim=fonte.indexOf('\n}',inicio)+2;vm.runInNewContext(fonte.slice(inicio,fim),contexto);return contexto[nome]}
test('abrir curadoria limpa seleção simples e múltipla antes de abrir a mesa',()=>{
  const chamadas=[],mesa={hidden:true};const ctx={document:{getElementById:()=>mesa},ehMesaCadastral:()=>true,fecharPaineisMapa:()=>{},limparSelecao:()=>chamadas.push('simples'),limparSelecaoCadastral:()=>chamadas.push('multipla'),abrirMesaCadastral:()=>chamadas.push('abrir'),fecharMesaCadastral:()=>chamadas.push('fechar')};
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
