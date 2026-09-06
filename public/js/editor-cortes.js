// Editor de divisas. Cada corte referencia uma parte estável da árvore;
// editar um corte recalcula seus descendentes, preservando os dados das folhas.
const editorCortes = { cortes: [], base: null, undo: [], redo: [], ativo: null,
  snaps: { endpoint: true, midpoint: true, perpendicular: true }, camadas: [] }

function calcularCortes(base, cortes) {
  const partes = new Map([['0', base]])
  for (const [i, corte] of cortes.entries()) {
    const origem = partes.get(corte.alvo)
    if (!origem) return { erro: `Corte ${i + 1}: a parte de origem não existe mais.` }
    for (let a = 0; a < corte.linha.length - 1; a++) {
      for (let b = a + 2; b < corte.linha.length - 1; b++) {
        if (_cruzamento(corte.linha[a], corte.linha[a + 1], corte.linha[b], corte.linha[b + 1])) {
          return { erro: `Corte ${i + 1}: a linha cruza a si mesma.` }
        }
      }
    }
    const r = cortarPorLinha(origem.coordinates[0], corte.linha)
    if (r.erro) return { erro: `Corte ${i + 1}: ${r.erro}` }
    partes.delete(corte.alvo)
    partes.set(corte.alvo + 'a', r.a)
    partes.set(corte.alvo + 'b', r.b)
  }
  return { partes: [...partes].map(([idParte, geometry]) => ({ idParte, geometry })) }
}

function estadoEditorCortes() {
  return structuredClone({ cortes: editorCortes.cortes, base: editorCortes.base, partes: desmState.partes })
}

function restaurarEditorCortes(e) {
  editorCortes.cortes = structuredClone(e?.cortes || [])
  editorCortes.base = e?.base || state.lotes.get(desmState.loteId)?.geometry
  editorCortes.undo = []; editorCortes.redo = []
}

function aplicarCortes(cortes, registrar = true) {
  const base = editorCortes.base || state.lotes.get(desmState.loteId)?.geometry
  const r = calcularCortes(base, cortes)
  if (r.erro) return r.erro
  if (registrar) { editorCortes.undo.push(estadoEditorCortes()); editorCortes.redo = [] }
  const dados = new Map(desmState.partes.map(p => [p.idParte, p]))
  editorCortes.base = base
  editorCortes.cortes = structuredClone(cortes)
  desmState.partes = cortes.length ? r.partes.map(p => ({ numero_lote: '', ...dados.get(p.idParte), ...p })) : []
  desmState.derivar = false; desmState.modo = 'corte'
  pintarPartesNoMapa(); pintarMesaDesmembramento(); agendarRascunhoDesmembramento()
  return null
}

function historicoCortes(refazer = false) {
  if (editorCortes.ativo) return
  const origem = refazer ? editorCortes.redo : editorCortes.undo
  const destino = refazer ? editorCortes.undo : editorCortes.redo
  if (!origem.length) return
  destino.push(estadoEditorCortes())
  const e = origem.pop()
  editorCortes.cortes = e.cortes; editorCortes.base = e.base; desmState.partes = e.partes
  pintarPartesNoMapa(); pintarMesaDesmembramento(); agendarRascunhoDesmembramento()
}

function ferramentasCortes() {
  return `<section class="editor-cortes">
    <b>Editor de divisas</b>
    <p>1. Crie o corte · 2. Ajuste e confira as áreas · 3. Preencha os imóveis</p>
    <div class="editor-snaps">${[['endpoint','□ Endpoint'],['midpoint','△ Midpoint'],['perpendicular','⊥ Perpendicular']].map(([k,t]) =>
      `<label><input type="checkbox" ${editorCortes.snaps[k] ? 'checked' : ''} onchange="editorCortes.snaps.${k}=this.checked"> ${t}</label>`).join('')}</div>
    <p>Encaixes em cantos, meios de lados e projeções perpendiculares. Aproxime o cursor da referência.</p>
    <div class="editor-acoes"><button class="btn sm" onclick="historicoCortes()" ${!editorCortes.undo.length ? 'disabled' : ''}>↶ Desfazer</button>
    <button class="btn sm" onclick="historicoCortes(true)" ${!editorCortes.redo.length ? 'disabled' : ''}>↷ Refazer</button></div>
    ${editorCortes.cortes.map((c,i) => `<div class="editor-acoes"><span>Divisa ${i+1}</span>
      <button class="btn sm" onclick="editarCorte(${i})">Editar divisa</button>
      <button class="btn sm" onclick="excluirCorte(${i})">Excluir</button></div>`).join('')}
    <div id="editor-corte-ajuste" hidden></div>
  </section>`
}

function excluirCorte(i) {
  if (editorCortes.ativo) { toast('Aplique ou cancele o ajuste atual.', 'aviso'); return }
  const alvo = editorCortes.cortes[i].alvo
  const dependentes = editorCortes.cortes.filter((c,j) => j > i && c.alvo.startsWith(alvo)).length
  confirmarAcao({ titulo: 'Excluir divisa', mensagem: `As partes deste corte voltam a formar uma só. ${dependentes ? `${dependentes} corte(s) dependente(s) também serão removidos.` : ''} Você pode desfazer esta alteração.`, textoBtn: 'Excluir divisa',
    onConfirm: () => aplicarCortes(editorCortes.cortes.filter((c,j) => j < i || (j !== i && !c.alvo.startsWith(alvo)))) })
}

function editarCorte(i = null, parte = null) {
  if (editorCortes.ativo) { toast('Aplique ou cancele o ajuste atual.', 'aviso'); return }
  if (i === null && desmState.partes.length >= 20) { toast('Limite de 20 partes.', 'err'); return }
  if (desmState.partes.length && !editorCortes.cortes.length) {
    toast('Este rascunho antigo contém apenas polígonos. Use Refazer o corte para criar divisas editáveis.', 'aviso'); return
  }
  if (i === null && editorCortes.cortes.length && !parte) { toast('Clique em Dividir na parte que deseja cortar.', 'aviso'); return }
  const base = editorCortes.base || state.lotes.get(desmState.loteId)?.geometry
  if (!base) return
  const cortes = structuredClone(editorCortes.cortes)
  const alvo = i === null ? (parte?.idParte || '0') : cortes[i].alvo
  const anteriores = calcularCortes(base, i === null ? cortes : cortes.slice(0, i))
  const origem = anteriores.partes?.find(p => p.idParte === alvo)?.geometry
  if (!origem) { toast('Não foi possível localizar a parte do corte.', 'err'); return }
  const indice = i ?? cortes.length
  const montar = g => {
    const lista = structuredClone(cortes)
    lista[indice] = { alvo, linha: g?.coordinates || [] }
    return lista
  }
  const limpar = () => {
    editorCortes.camadas.forEach(c => mapaState.obj.removeLayer(c)); editorCortes.camadas = []
  }
  const terminar = () => { limpar(); editorCortes.ativo = null; pintarMesaDesmembramento(); pintarPartesNoMapa() }
  iniciarDesenho({ modo: 'linha', rotulo: `Editar divisa ${indice + 1}`, snapAneis: [origem.coordinates[0]], snapTipos: editorCortes.snaps,
    validar: g => calcularCortes(base, montar(g)).erro,
    onAlterar: g => {
      if (!editorCortes.ativo) return
      const a = editorCortes.ativo, atual = JSON.stringify(g?.coordinates || [])
      if (a.ultimo !== atual) {
        if (a.ultimo !== null && !a.restaurando) { a.historico.push(a.ultimo); a.futuro = []; if (a.historico.length > 100) a.historico.shift() }
        a.ultimo = atual
      }
      limpar()
      const r = calcularCortes(base, montar(g))
      editorCortes.ativo.valido = !r.erro
      const msg = document.getElementById('editor-corte-previa')
      if (msg) msg.textContent = r.erro || r.partes.map((p,j) => `Parte ${j+1}: ${fmtNum(areaDoAnel(p.geometry))} m²`).join(' · ')
      if (!r.erro) r.partes.forEach((p,j) => editorCortes.camadas.push(L.geoJSON(p.geometry, { pane: _paneMesa(), interactive: false,
        style: { color: CORES_PARTE[j % CORES_PARTE.length], weight: 2, fillOpacity: .35 } }).addTo(mapaState.obj)))
      const btn = document.getElementById('editor-corte-aplicar'); if (btn) btn.disabled = !!r.erro
    },
    onConcluir: g => { const lista = montar(g); terminar(); const erro = aplicarCortes(lista); if (erro) toast(erro, 'err') },
    onCancelar: terminar,
  })
  editorCortes.ativo = { indice, valido: false, historico: [], futuro: [], ultimo: null }
  desmMesa.camadasPartes.forEach(c => mapaState.obj.removeLayer(c)); desmMesa.camadasPartes = []
  const painel = document.getElementById('editor-corte-ajuste')
  painel.hidden = false
  painel.innerHTML = `<b>Criação e modificação</b>
    <p>Marque dois extremos ou vários pontos. Entre em Ajustar para arrastar vértices, inserir pelo meio do segmento e remover com duplo clique.</p>
    <div class="editor-acoes"><button class="btn sm" onclick="concluirDesenho()">Ajustar vértices</button><button class="btn sm" onclick="voltarATracar()">Adicionar pontos</button></div>
    <div class="editor-acoes"><button class="btn sm" onclick="historicoAjusteCorte()">↶ Desfazer ajuste</button><button class="btn sm" onclick="historicoAjusteCorte(true)">↷ Refazer ajuste</button></div>
    <div class="field"><label>Distância (m)</label><input id="ec-dist" type="number" step="0.01" value="10"></div>
    <div class="field"><label>Azimute (° — 0 norte, 90 leste)</label><input id="ec-az" type="number" step="0.01" value="90"></div>
    <button class="btn sm" onclick="pontoExatoCorte()">Adicionar ponto por distância e azimute</button>
    <div class="field"><label>Deslocamento paralelo da divisa (m, com sinal)</label><input id="ec-offset" type="number" step="0.01" value="1"></div>
    <button class="btn sm" onclick="deslocarDivisa()">Deslocar divisa reta</button>
    <p id="editor-corte-previa" role="status">Trace a divisa para visualizar as partes.</p>
    <div class="editor-acoes"><button class="btn primary" id="editor-corte-aplicar" disabled onclick="concluirDesenho();confirmarDesenho()">Aplicar corte</button>
    <button class="btn" onclick="cancelarDesenho()">Cancelar ajuste</button></div>`
  if (i !== null) {
    desenhoState.vertices = structuredClone(cortes[i].linha); desenhoState.fechado = true; _pintar()
  } else _pintar()
}

function historicoAjusteCorte(refazer = false) {
  const a = editorCortes.ativo
  if (!a) return
  const de = refazer ? a.futuro : a.historico, para = refazer ? a.historico : a.futuro
  if (!de.length) return
  para.push(JSON.stringify(desenhoState.vertices))
  desenhoState.vertices = JSON.parse(de.pop())
  if (desenhoState.vertices.length < 2) desenhoState.fechado = false
  a.restaurando = true; _pintar(); a.restaurando = false
}

function pontoExatoCorte() {
  const m = Number(document.getElementById('ec-dist').value), az = Number(document.getElementById('ec-az').value)
  if (!Number.isFinite(m) || !Number.isFinite(az) || m <= 0) { toast('Informe distância positiva e azimute.', 'err'); return }
  cravarLado(m, 'azimute', az)
}

function deslocarDivisa() {
  const v = desenhoState.vertices, m = Number(document.getElementById('ec-offset').value)
  if (v.length !== 2 || !Number.isFinite(m)) { toast('O deslocamento paralelo exige uma divisa reta com dois pontos.', 'err'); return }
  const a = aoPlano(...v[0]), b = aoPlano(...v[1]), dx = b[0]-a[0], dy = b[1]-a[1], d = Math.hypot(dx,dy)
  if (d < .001) return
  // Estende os extremos para continuar cruzando o contorno depois do offset.
  const margem = 1000
  desenhoState.vertices = [doPlano(a[0]-dy/d*m-dx/d*margem,a[1]+dx/d*m-dy/d*margem),
    doPlano(b[0]-dy/d*m+dx/d*margem,b[1]+dx/d*m+dy/d*margem)]
  desenhoState.fechado = true; _pintar()
}
