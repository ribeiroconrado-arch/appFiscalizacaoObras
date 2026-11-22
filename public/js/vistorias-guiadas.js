// ══════════════════════════════════════════════
// VISTORIAS GUIADAS — página de teste (22/11/2026)
//
// Três abas: Vistorias (a lista), Nova vistoria (modelo + PDFs anexados) e
// Modelos (só administrador). Abrir uma vistoria cobre a página com a
// execução: abertura com localização, etapas, itens livres e conclusão.
//
// Tudo é gravado no servidor na hora — a internet é pressuposta. As
// pendências conferidas aqui (para travar o "Próxima") são as mesmas que o
// servidor confere de novo ao iniciar e ao concluir: ver
// VistoriaGuiadaController::pendencias.
// ══════════════════════════════════════════════

const $ = (s, r = document) => r.querySelector(s)
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
const ic = n => `<svg class="i"><use href="#i-${n}"/></svg>`
const novoId = p => p + Math.random().toString(36).slice(2, 10)
const kb = b => b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.round(b / 1024) + ' KB'
const largo = () => window.matchMedia('(min-width: 900px)').matches

function toast(t, erro) {
  const d = document.createElement('div'); d.className = 'toast' + (erro ? ' erro' : ''); d.textContent = t
  document.body.appendChild(d); setTimeout(() => d.remove(), erro ? 5000 : 2600)
}
function cobrir(txt) { const d = document.createElement('div'); d.className = 'cobre'; d.textContent = txt; document.body.appendChild(d); return () => d.remove() }

/** Chamada à API. Erro de validação vira mensagem legível. */
async function api(metodo, url, corpo) {
  const op = { method: metodo, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content } }
  if (corpo instanceof FormData) op.body = corpo
  else if (corpo !== undefined) { op.body = JSON.stringify(corpo); op.headers['Content-Type'] = 'application/json' }
  const r = await fetch('/api/vistorias-guiadas' + url, op)
  let d = null
  try { d = await r.json() } catch { /* resposta sem corpo */ }
  if (!r.ok) {
    const lista = d?.errors ? Object.values(d.errors).flat() : []
    const e = new Error(lista.length ? lista.join(' ') : (d?.message || 'Falha de comunicação (' + r.status + ').'))
    e.lista = lista
    throw e
  }
  return d
}

const LOCAL = {
  nao: { r: 'Não exige', d: 'A vistoria abre sem localização. Fotos saem sem coordenada.' },
  gps: { r: 'GPS do aparelho', d: 'Exige a posição do aparelho antes de começar.' },
  mapa: { r: 'Marcar no mapa', d: 'O fiscal marca o ponto no mapa. Útil onde o GPS falha.' },
  gps_ou_mapa: { r: 'GPS ou mapa', d: 'Qualquer um dos dois libera o início.' },
  gps_e_mapa: { r: 'GPS e mapa', d: 'Exige os dois: a posição do aparelho e o ponto no mapa.' },
}
const RESP = [['sim', 'Confere'], ['nao', 'Não confere'], ['na', 'Não se aplica']]
const EU = window.VG_EU

const VG = { modelos: [], vistorias: [], aba: 'vistorias' }

// ══════════════ abas ══════════════
function irAba(a) {
  VG.aba = a
  for (const k of ['vistorias', 'nova', 'modelos']) { $('#aba-' + k).classList.toggle('at', a === k); $('#v-' + k).hidden = a !== k }
  if (a === 'vistorias') carregarVistorias()
  if (a === 'nova') carregarModelos().then(renderNova)
  if (a === 'modelos') carregarModelos().then(() => { if (!VG.M && VG.modelos[0]) abrirModelo(VG.modelos[0].id); else renderMontador() })
}
async function carregarModelos() {
  try { VG.modelos = (await api('GET', '/modelos')).modelos } catch (e) { toast(e.message, true) }
}

// ══════════════ LISTA ══════════════
async function carregarVistorias() {
  const alvo = $('#v-vistorias')
  alvo.innerHTML = '<div class="vazio">Carregando…</div>'
  try { VG.vistorias = (await api('GET', '/')).vistorias } catch (e) { alvo.innerHTML = `<div class="vazio">${esc(e.message)}</div>`; return }
  const novaBt = EU.edita ? `<button class="btn pri" onclick="irAba('nova')">${ic('mais')}Nova vistoria</button>` : ''
  alvo.innerHTML = `<div class="lista-cab"><h1>Vistorias</h1>${novaBt}</div>` + (VG.vistorias.length
    ? `<div class="vist-grade">${VG.vistorias.map(v => `<button class="vist-card" onclick="abrirVistoria(${v.id})">
        <span class="linha"><span class="chip ${v.estado === 'concluida' ? 'vd' : v.estado === 'em_andamento' ? 'am' : 'lar'}">${{ pendente: 'A fazer', em_andamento: 'Em andamento', concluida: 'Concluída' }[v.estado]}</span>
          <span class="chip">${esc(v.modelo)} v${v.versao}</span>${v.anexos ? `<span class="chip az">${ic('doc')}${v.anexos}</span>` : ''}</span>
        <b>${esc(v.imovel)}</b>
        <small>${v.protocolo ? 'Protocolo ' + esc(v.protocolo) + ' · ' : ''}criada em ${esc(v.criada_em)} por ${esc(v.autor || '—')}${v.conclusao ? ' · ' + esc(v.conclusao) : ''}</small></button>`).join('')}</div>`
    : `<div class="vazio">Nenhuma vistoria ainda.${EU.edita ? ' Crie a primeira em <b>Nova vistoria</b>.' : ''}</div>`)
}

// ══════════════ NOVA VISTORIA ══════════════
let NV = null
function renderNova() {
  const alvo = $('#v-nova')
  const usaveis = VG.modelos.filter(m => m.ativo && m.vigente)
  if (!usaveis.length) {
    alvo.innerHTML = `<div class="caixa"><div class="vazio">Nenhum modelo publicado.${EU.admin ? ` <button class="btn" onclick="irAba('modelos')">Ir para Modelos</button>` : ' Peça ao administrador.'}</div></div>`
    return
  }
  if (!NV || !usaveis.some(m => m.id === NV.modelo)) NV = { modelo: usaveis[0].id, protocolo: '', imovel: '', anexos: {}, extras: [] }
  const m = usaveis.find(x => x.id === NV.modelo), def = m.vigente.definicao
  const slot = (nome, arq, obrig, rem, add) => `<div class="anexo-slot ${arq ? 'tem' : obrig ? 'falta' : ''}">
      ${ic('doc')}<div class="info"><b>${esc(nome)}${obrig !== null ? ` <span class="tag-o ${obrig ? '' : 'opc'}">${obrig ? 'Obrigatório' : 'Opcional'}</span>` : ''}</b>
        <small>${arq ? `${esc(arq.name)} · ${kb(arq.size)}` : 'Nenhum arquivo'}</small></div>
      ${arq ? `<button class="btn sm" onclick="${rem}">${ic('lixo')}Remover</button>` : `<button class="btn sm pri" onclick="${add}">${ic('mais')}Anexar PDF</button>`}</div>`
  const faltam = def.anexos.filter(a => a.obrig && !NV.anexos[a.id])
  alvo.innerHTML = `<div class="caixa"><div class="nova">
    <h1>Nova vistoria</h1>
    <div class="grade2">
      <label class="campo-f"><span>Modelo</span><select class="in" id="nv-mod" onchange="NV.modelo=+this.value;NV.anexos={};renderNova()">
        ${usaveis.map(x => `<option value="${x.id}" ${x.id === NV.modelo ? 'selected' : ''}>${esc(x.nome)} (v${x.versao})</option>`).join('')}</select></label>
      <label class="campo-f"><span>Protocolo (opcional)</span><input class="in" id="nv-prot" maxlength="40" value="${esc(NV.protocolo)}" oninput="NV.protocolo=this.value"></label>
    </div>
    <label class="campo-f"><span>Imóvel ou local</span><input class="in" id="nv-imovel" maxlength="255" placeholder="Rua, número, quadra e lote" value="${esc(NV.imovel)}" oninput="NV.imovel=this.value;atualizarCriar()"></label>
    <div class="campo-f"><span>Anexos</span>
      <div style="display:flex;flex-direction:column;gap:8px">
        ${def.anexos.length ? def.anexos.map(a => slot(a.nome, NV.anexos[a.id], a.obrig, `delete NV.anexos['${a.id}'];renderNova()`, `escolherPdf('${a.id}')`)).join('')
          : '<p style="margin:0;color:var(--tx2)">Este modelo não pede anexos.</p>'}
        ${NV.extras.map((f, k) => slot(f.name.replace(/\.pdf$/i, ''), f, null, `NV.extras.splice(${k},1);renderNova()`, '')).join('')}
        <div><button class="btn sm" onclick="escolherPdf('extra')">${ic('mais')}Outro PDF</button></div>
      </div></div>
    <div style="display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap">
      <span class="falta-lista" id="nv-falta"></span>
      <button class="btn pri" id="nv-criar" onclick="criarVistoria()">Criar vistoria</button>
    </div>
    <p style="margin:0;font-size:12.5px;color:var(--tx3)">Os PDFs ficam com a vistoria e abrem durante o roteiro. Até 30 MB cada.</p>
  </div></div>`
  NV.faltam = faltam.map(a => a.nome)
  atualizarCriar()
}
function atualizarCriar() {
  const f = [...(NV.faltam || [])]; if (!NV.imovel.trim()) f.unshift('imóvel')
  $('#nv-falta').innerHTML = f.length ? `${ic('alerta')}Falta: ${esc(f.join(', '))}` : ''
  $('#nv-criar').disabled = f.length > 0
}
let alvoPdf = null
function escolherPdf(alvo) { alvoPdf = alvo; const c = $('#arq-pdf'); c.value = ''; c.click() }
$('#arq-pdf').addEventListener('change', ev => {
  const f = ev.target.files?.[0]; if (!f) return
  if (f.size > 30 * 1048576) { toast('O PDF passa de 30 MB.', true); return }
  if (alvoPdf === 'extra') NV.extras.push(f); else NV.anexos[alvoPdf] = f
  renderNova()
})
async function criarVistoria() {
  const fd = new FormData()
  fd.append('modelo_id', NV.modelo); fd.append('imovel', NV.imovel.trim())
  if (NV.protocolo.trim()) fd.append('protocolo', NV.protocolo.trim())
  for (const [k, f] of Object.entries(NV.anexos)) fd.append(`anexos[${k}]`, f)
  NV.extras.forEach(f => fd.append('extras[]', f))
  const fim = cobrir('Enviando os anexos…')
  try {
    const d = await api('POST', '/', fd)
    NV = null; toast(d.message)
    irAba('vistorias')
    abrirVistoria(d.id)
  } catch (e) { toast(e.message, true) } finally { fim() }
}

// ══════════════ MODELOS ══════════════
function chipsModelo(def) {
  if (!def) return '<span class="chip">sem versão</span>'
  const fotos = def.etapas.filter(e => e.foto === 'obrigatoria').length
  return (def.etapas.length ? `<span class="chip">${def.etapas.length} etapas</span>` : `<span class="chip">Só itens livres</span>`)
    + (def.local !== 'nao' ? `<span class="chip lar">${ic(def.local === 'mapa' ? 'mapa' : 'gps')}${LOCAL[def.local].r}</span>` : '')
    + (def.anexos.length ? `<span class="chip az">${ic('doc')}${def.anexos.length}</span>` : '')
    + (fotos ? `<span class="chip">${ic('camera')}${fotos}</span>` : '')
}
function renderMontador() {
  const lista = $('#lista-modelos')
  if (!VG.modelos.length) {
    lista.innerHTML = `<div class="vazio" style="padding:12px">Nenhum modelo.<button class="btn sm" onclick="carregarExemplos()">Carregar exemplos</button></div>`
    $('#editor').innerHTML = `<div class="vazio">Crie um modelo ou carregue os exemplos (Habite-se, Início de obra e Averiguação de denúncia).</div>`
    $('#regras').innerHTML = ''
    return
  }
  lista.innerHTML = VG.modelos.map(m => `<button class="${VG.M?.id === m.id ? 'at' : ''}" onclick="abrirModelo(${m.id})">
    <b>${esc(m.nome)}</b><span class="linha">${chipsModelo(m.rascunho)}${m.ativo ? '' : '<span class="chip">inativo</span>'}</span></button>`).join('')
  if (VG.M) { renderEditor(); renderRegras() }
}
async function carregarExemplos() {
  try { toast((await api('POST', '/modelos/exemplos')).message); await carregarModelos(); abrirModelo(VG.modelos[0].id) } catch (e) { toast(e.message, true) }
}
function abrirModelo(id) {
  const m = VG.modelos.find(x => x.id === id)
  VG.M = JSON.parse(JSON.stringify(m)); VG.erros = null
  VG.etAberta = VG.M.rascunho.etapas[0]?.id
  renderMontador()
}
async function novoModelo() {
  try {
    const d = await api('POST', '/modelos', { nome: 'Novo modelo' })
    await carregarModelos(); abrirModelo(d.id)
  } catch (e) { toast(e.message, true) }
}
const D = () => VG.M.rascunho

// Grava o rascunho com espera curta: cada tecla não vira uma requisição.
let tSalvar = null, salvando = null
function mexeu() {
  VG.M.alterado = true; atualizarEstado('Salvando…')
  clearTimeout(tSalvar); tSalvar = setTimeout(salvarRascunho, 700)
  const l = VG.modelos.find(x => x.id === VG.M.id); if (l) { l.rascunho = D(); l.nome = VG.M.nome; l.ativo = VG.M.ativo }
}
async function salvarRascunho() {
  clearTimeout(tSalvar)
  const m = VG.M
  salvando = api('PUT', '/modelos/' + m.id, { nome: m.nome || 'Sem nome', ativo: m.ativo, rascunho: m.rascunho })
  try { await salvando; atualizarEstado() } catch (e) { atualizarEstado(); toast(e.message, true) } finally { salvando = null }
}
function atualizarEstado(txt) {
  const e = $('#estado-mod'); if (!e) return
  const s = VG.M.alterado
  e.className = 'estado' + (s ? ' sujo' : '')
  e.innerHTML = txt ? txt : s ? `${ic('alerta')}Rascunho salvo · não publicado` : `${ic('ok')}Publicado · versão ${VG.M.versao}`
  $('#bt-publicar').disabled = !s
}
async function publicar() {
  await salvarRascunho()
  try {
    const d = await api('POST', '/modelos/' + VG.M.id + '/publicar')
    toast(d.message); VG.erros = null
    await carregarModelos(); abrirModelo(VG.M.id)
  } catch (e) { VG.erros = e.lista?.length ? e.lista : [e.message]; renderEditor() }
}

function renderEditor() {
  const m = VG.M
  $('#editor').innerHTML = `
    <div class="cab-mod">
      <input class="nome" id="nome-mod" maxlength="120" value="${esc(m.nome)}" oninput="VG.M.nome=this.value;mexeu()" aria-label="Nome do modelo">
      <span class="estado" id="estado-mod"></span>
      <button class="btn pri" id="bt-publicar" onclick="publicar()">Publicar</button>
    </div>
    <p class="nota-v">Cada item vira uma pergunta com três respostas: <b>Confere</b>, <b>Não confere</b> ou <b>Não se aplica</b>. Publicar cria uma versão nova; a vistoria guarda a versão em que nasceu.</p>
    ${VG.erros ? `<div class="erros"><b>Não foi possível publicar:</b><ul>${VG.erros.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>` : ''}
    <div class="etapas">${D().etapas.length ? D().etapas.map(etapaHtml).join('')
      : `<div class="instr">${ic('info')}<span>Modelo sem etapas: o fiscal registra tudo como itens livres (assunto, fotos e observação). É o formato da averiguação de denúncia.</span></div>`}</div>
    <div style="padding:0 12px 14px"><button class="btn" onclick="addEtapa()">${ic('mais')}Adicionar etapa</button></div>`
  atualizarEstado()
}
const nomeAnexo = id => D().anexos.find(a => a.id === id)?.nome
function chipsEtapa(e) {
  const ob = e.itens.filter(i => i.obrig).length
  return `<span class="chip">${e.itens.length} itens${ob < e.itens.length ? ` · ${ob} obrig.` : ''}</span>`
    + (e.foto === 'obrigatoria' ? `<span class="chip lar">${ic('camera')}mín. ${e.foto_min}</span>` : e.foto === 'opcional' ? `<span class="chip">${ic('camera')}opcional</span>` : '')
    + (nomeAnexo(e.anexo) ? `<span class="chip az">${ic('doc')}${esc(nomeAnexo(e.anexo).split(' ').slice(0, 2).join(' '))}</span>` : '')
}
function etapaHtml(e, n) {
  const ab = e.id === VG.etAberta
  return `<div class="et ${ab ? 'aberta' : ''}">
    <div class="et-cab" onclick="VG.etAberta=VG.etAberta==='${e.id}'?null:'${e.id}';renderEditor()">
      <span class="et-num">${n + 1}</span>
      <span class="et-tit" id="tit-${e.id}">${esc(e.titulo) || '<i>Sem título</i>'}</span>
      <span class="et-chips" id="chips-${e.id}">${chipsEtapa(e)}</span>${ic(ab ? 'cima' : 'baixo')}
    </div>${ab ? etapaCorpo(e, n) : ''}</div>`
}
function etapaCorpo(e, n) {
  const an = D().anexos
  return `<div class="et-corpo">
    <div class="grade2">
      <label class="campo-f"><span>Título da etapa</span><input class="in" id="t-${e.id}" maxlength="160" value="${esc(e.titulo)}" oninput="setE('${e.id}','titulo',this.value)"></label>
      <label class="campo-f"><span>Anexo para consultar</span>
        <select class="in" id="an-${e.id}" onchange="setE('${e.id}','anexo',this.value,true)" ${an.length ? '' : 'disabled'}>
          <option value="">${an.length ? 'Nenhum' : 'O modelo não pede anexos'}</option>
          ${an.map(a => `<option value="${a.id}" ${e.anexo === a.id ? 'selected' : ''}>${esc(a.nome)}</option>`).join('')}</select></label>
    </div>
    <label class="campo-f"><span>Instrução para o fiscal (opcional)</span><input class="in" id="ins-${e.id}" maxlength="500" value="${esc(e.instrucao)}" oninput="setE('${e.id}','instrucao',this.value)"></label>
    <div class="campo-f"><span>Fotos da etapa</span>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <div class="seg">${[['nao', 'Sem foto'], ['opcional', 'Opcional'], ['obrigatoria', 'Obrigatória']].map(([v, t]) =>
          `<button class="${e.foto === v ? 'on' : ''}" onclick="setE('${e.id}','foto','${v}',true)">${t}</button>`).join('')}</div>
        ${e.foto === 'obrigatoria' ? `<label style="display:flex;gap:6px;align-items:center;font-size:13px">mínimo
          <input class="in" id="fm-${e.id}" type="number" min="1" max="20" value="${e.foto_min}" style="width:64px" onchange="setE('${e.id}','foto_min',Math.min(20,Math.max(1,+this.value||1)),true)"></label>` : ''}
        ${e.foto !== 'nao' ? `<input class="in" id="fd-${e.id}" maxlength="160" style="flex:1;min-width:160px" placeholder="O que fotografar" value="${esc(e.foto_dica)}" oninput="setE('${e.id}','foto_dica',this.value)">` : ''}
      </div></div>
    <div class="campo-f"><span>O que conferir</span>
      <div class="itens-s">${e.itens.map(it => `<div class="it-s">
        <input class="in" id="ir-${it.id}" maxlength="200" value="${esc(it.rotulo)}" placeholder="Ex.: Recuos" oninput="setI('${e.id}','${it.id}','rotulo',this.value)" aria-label="Item">
        <label><input type="checkbox" id="io-${it.id}" ${it.obrig ? 'checked' : ''} onchange="setI('${e.id}','${it.id}','obrig',this.checked)">Obrigatório</label>
        <button class="ib" title="Remover" onclick="tirarI('${e.id}','${it.id}')">${ic('lixo')}</button></div>`).join('')}</div>
      <div><button class="btn sm" onclick="addItem('${e.id}')">${ic('mais')}Item</button></div></div>
    <div class="et-acoes"><span style="display:flex;gap:6px">
        <button class="btn sm" ${n === 0 ? 'disabled' : ''} onclick="moverE('${e.id}',-1)">${ic('cima')}Subir</button>
        <button class="btn sm" ${n === D().etapas.length - 1 ? 'disabled' : ''} onclick="moverE('${e.id}',1)">${ic('baixo')}Descer</button></span>
      <button class="btn sm perigo" onclick="tirarE('${e.id}')">${ic('lixo')}Excluir etapa</button></div>
  </div>`
}
const acharE = id => D().etapas.find(e => e.id === id)
function setE(id, k, v, re) { const e = acharE(id); e[k] = v; mexeu(); if (re) renderEditor(); else { if (k === 'titulo') $('#tit-' + id).textContent = v; $('#chips-' + id).innerHTML = chipsEtapa(e) } }
function setI(eid, iid, k, v) { const e = acharE(eid); e.itens.find(i => i.id === iid)[k] = v; mexeu(); $('#chips-' + eid).innerHTML = chipsEtapa(e) }
function addItem(eid) { const it = { id: novoId('i'), rotulo: '', obrig: true }; acharE(eid).itens.push(it); mexeu(); renderEditor(); $('#ir-' + it.id)?.focus() }
function tirarI(eid, iid) { const e = acharE(eid); e.itens = e.itens.filter(i => i.id !== iid); mexeu(); renderEditor() }
function addEtapa() {
  const e = { id: novoId('e'), titulo: 'Nova etapa', instrucao: '', foto: 'opcional', foto_min: 1, foto_dica: '', anexo: '', itens: [{ id: novoId('i'), rotulo: '', obrig: true }] }
  D().etapas.push(e); VG.etAberta = e.id; mexeu(); renderEditor()
}
function moverE(id, d) { const a = D().etapas, i = a.findIndex(e => e.id === id), j = i + d; [a[i], a[j]] = [a[j], a[i]]; mexeu(); renderEditor() }
function tirarE(id) { D().etapas = D().etapas.filter(e => e.id !== id); VG.etAberta = D().etapas[0]?.id; mexeu(); renderEditor() }

function renderRegras() {
  const d = D()
  const nItens = d.etapas.reduce((s, e) => s + e.itens.length, 0)
  const nFotos = d.etapas.reduce((s, e) => s + (e.foto === 'obrigatoria' ? e.foto_min : 0), 0)
  $('#regras').innerHTML = `
    <div class="resumo-mod"><div><b>${d.etapas.length}</b><span>etapas</span></div><div><b>${nItens}</b><span>itens</span></div><div><b>${nFotos}</b><span>fotos mínimas</span></div></div>
    <div class="regra"><h3>${ic('doc')}Anexos da vistoria</h3>
      <p>O fiscal anexa estes PDFs ao criar a vistoria. Os obrigatórios travam a criação.</p>
      ${d.anexos.map(a => `<div class="anexo-esp">
        <input class="in" id="ae-${a.id}" maxlength="160" value="${esc(a.nome)}" oninput="D().anexos.find(x=>x.id==='${a.id}').nome=this.value;mexeu()">
        <label><input type="checkbox" id="ao-${a.id}" ${a.obrig ? 'checked' : ''} onchange="D().anexos.find(x=>x.id==='${a.id}').obrig=this.checked;mexeu()">Obrig.</label>
        <button class="ib" title="Remover" onclick="tirarAnexo('${a.id}')">${ic('lixo')}</button></div>`).join('')}
      <div><button class="btn sm" onclick="D().anexos.push({id:novoId('a'),nome:'Novo anexo',obrig:false});mexeu();renderMontador()">${ic('mais')}Anexo</button></div></div>
    <div class="regra"><h3>${ic('pin')}Localização antes de iniciar</h3>
      <select class="in" id="sel-local" onchange="D().local=this.value;mexeu();renderRegras()">
        ${Object.entries(LOCAL).map(([k, v]) => `<option value="${k}" ${d.local === k ? 'selected' : ''}>${v.r}</option>`).join('')}</select>
      <p>${LOCAL[d.local].d}</p></div>
    <div class="regra"><h3>${ic('alerta')}Quando não confere</h3>
      <p>A descrição é sempre obrigatória.</p>
      <label class="tog"><input type="checkbox" id="tg-nf" ${d.nao_foto ? 'checked' : ''} onchange="D().nao_foto=this.checked;mexeu()">Exigir também uma foto do problema</label></div>
    <div class="regra"><h3>${ic('nota')}Itens livres</h3>
      <p>Sempre disponíveis: o fiscal cria o item (assunto, fotos e observação) em qualquer etapa, na conclusão ou depois de concluída.</p></div>
    <div class="regra"><h3>${ic('ok')}Conclusão</h3>
      <label class="campo-f"><span>Conclusões possíveis (separe por ponto e vírgula)</span><input class="in" id="concl" value="${esc(d.conclusoes.join('; '))}" onchange="D().conclusoes=this.value.split(';').map(s=>s.trim()).filter(Boolean);mexeu()"></label>
      <label class="tog"><input type="checkbox" id="tg-ativo" ${VG.M.ativo ? 'checked' : ''} onchange="VG.M.ativo=this.checked;mexeu()">Modelo ativo (aparece em Nova vistoria)</label></div>`
}
function tirarAnexo(id) { D().anexos = D().anexos.filter(a => a.id !== id); D().etapas.forEach(e => { if (e.anexo === id) e.anexo = '' }); mexeu(); renderMontador() }

// ══════════════ EXECUÇÃO ══════════════
let R = null
async function abrirVistoria(id) {
  const fim = cobrir('Abrindo a vistoria…')
  try {
    const v = await api('GET', '/' + id)
    const def = v.definicao
    const anexos = v.arquivos.filter(a => a.papel === 'anexo')
    R = { v, def, i: 0, max: 0, resp: Array.isArray(v.respostas) ? {} : (v.respostas || {}), gps: v.gps, mapa: v.mapa,
      conclusao: v.conclusao || '', novoLivre: null, enviando: 0,
      pdf: { modo: 'mini', anexo: anexos[0]?.id, pag: {}, zoom: 1, x: 16, y: 90, sugerido: '' } }
    if (v.estado === 'concluida') R.fase = 'fim'
    else if (v.iniciada_em) { R.max = def.etapas.length; if (def.etapas.length) { R.fase = 'etapa'; entrarEtapa() } else R.fase = 'final' }
    else R.fase = 'portao'
    $('#exec').hidden = false; document.body.style.overflow = 'hidden'
    renderExec()
  } catch (e) { toast(e.message, true) } finally { fim() }
}
async function fecharVistoria() {
  await gravarRespostas()
  R = null; mapaMarcar = null
  $('#exec').hidden = true; document.body.style.overflow = ''
  $('#pdf-slot').innerHTML = ''
  carregarVistorias()
}
window.addEventListener('resize', () => { if (R && !$('#exec').hidden) { if (!largo() && R.pdf.modo === 'lado') R.pdf.modo = 'janela'; renderTrilho(); renderPdf() } })

function renderExec() { renderCab(); renderCorpo(); renderRodape(); renderTrilho(); renderPdf() }
const etapa = () => R.def.etapas[R.i]
const anexosV = () => R.v.arquivos.filter(a => a.papel === 'anexo')
const fotosEtapa = eid => R.v.arquivos.filter(a => a.papel === 'foto' && a.etapa === eid && !a.item && !a.livre_id)
const fotoItem = iid => R.v.arquivos.find(a => a.papel === 'foto' && a.item === iid)
const fotosLivre = lid => R.v.arquivos.filter(a => a.papel === 'foto' && a.livre_id === lid)
const podeEditar = () => EU.edita

function pendencias(et) {
  const f = []
  et.itens.forEach(it => {
    const r = R.resp[it.id] || {}
    if (it.obrig && !r.v) f.push(it.rotulo)
    if (r.v === 'nao') { if (!r.obs?.trim()) f.push('descrição: ' + it.rotulo); if (R.def.nao_foto && !fotoItem(it.id)) f.push('foto: ' + it.rotulo) }
  })
  const nf = fotosEtapa(et.id).length
  if (et.foto === 'obrigatoria' && nf < et.foto_min) f.push(`${et.foto_min - nf} foto${et.foto_min - nf > 1 ? 's' : ''} da etapa`)
  return f
}
const nNao = et => et.itens.filter(it => R.resp[it.id]?.v === 'nao').length
function estadoEtapa(k) {
  if (k === R.i && R.fase === 'etapa') return 'at'
  if (k >= R.max && !['final', 'fim', 'complemento'].includes(R.fase)) return ''
  const et = R.def.etapas[k]; if (pendencias(et).length) return ''
  return nNao(et) ? 'dv' : 'ok'
}

function renderCab() {
  const v = R.v, def = R.def
  const pdfBt = anexosV().length && R.fase !== 'portao' ? `<button class="btn-pdf" onclick="abrirPdf()">${ic('doc')}Anexos</button>` : ''
  let sub = `${v.modelo} v${v.versao}${v.protocolo ? ' · protocolo ' + v.protocolo : ''}`, tit = v.imovel
  if (R.fase === 'etapa') { sub = `${v.modelo} · etapa ${R.i + 1} de ${def.etapas.length}`; tit = etapa().titulo }
  if (R.fase === 'final') tit = def.etapas.length ? 'Conclusão' : 'Itens e conclusão'
  if (R.fase === 'fim') tit = 'Vistoria concluída'
  if (R.fase === 'complemento') tit = 'Complementar'
  const prog = ['portao', 'fim', 'complemento'].includes(R.fase) || !def.etapas.length ? '' :
    `<div class="prog">${def.etapas.map((_, k) => `<i class="${estadoEtapa(k)}"></i>`).join('')}<i class="${R.fase === 'final' ? 'at' : ''}"></i></div>`
  $('#cab').innerHTML = `<div class="l1"><button class="ib" title="Voltar à lista" onclick="fecharVistoria()">${ic('esq')}</button>
    <div class="tt"><small>${esc(sub)}</small><b>${esc(tit)}</b></div>${pdfBt}</div>${prog}`
}
function renderTrilho() {
  const t = $('#trilho'), def = R.def
  t.hidden = R.fase === 'portao' || !def.etapas.length
  if (t.hidden) return
  t.innerHTML = `<h4>Roteiro</h4>` + def.etapas.map((e, k) => { const s = estadoEtapa(k)
    return `<button class="${k === R.i && R.fase === 'etapa' ? 'at' : ''}" ${k > R.max || ['fim', 'complemento'].includes(R.fase) ? 'disabled' : ''} onclick="irEtapa(${k})">
      <span class="marca ${s}">${s === 'ok' ? ic('ok') : s === 'dv' ? '!' : k + 1}</span><span>${esc(e.titulo)}</span></button>` }).join('')
    + `<button class="${['final', 'fim', 'complemento'].includes(R.fase) ? 'at' : ''}" ${R.max < def.etapas.length || ['fim', 'complemento'].includes(R.fase) ? 'disabled' : ''} onclick="irFinal()"><span class="marca">${ic('ok')}</span><span>Conclusão</span></button>`
}
function renderCorpo() {
  const c = $('#corpo'), topo = c.scrollTop
  if (mapaMarcar) { mapaMarcar.remove(); mapaMarcar = null }
  c.innerHTML = R.fase === 'portao' ? portaoHtml() : R.fase === 'etapa' ? etapaHtmlRun(etapa())
    : R.fase === 'final' ? finalHtml() : R.fase === 'complemento' ? complementoHtml() : fimHtml()
  c.scrollTop = topo
  if (R.fase === 'portao') montarMapaMarcar()
}

// ── abertura: localização ──
const precisaGps = () => ['gps', 'gps_e_mapa'].includes(R.def.local)
const precisaMapa = () => ['mapa', 'gps_e_mapa'].includes(R.def.local)
function localOk() { return { nao: true, gps: !!R.gps, mapa: !!R.mapa, gps_ou_mapa: !!(R.gps || R.mapa), gps_e_mapa: !!(R.gps && R.mapa) }[R.def.local] }
function portaoHtml() {
  const L = R.def.local, temGps = L !== 'nao' && L !== 'mapa', temMapa = L !== 'nao' && L !== 'gps'
  const an = anexosV()
  return `<div class="cartao"><div class="rot">${esc(R.v.imovel)}</div>
      <div style="font-size:12.5px;color:var(--tx2)">${an.length ? `${an.length} anexo(s): ${esc(an.map(a => a.nome).join(', '))}` : 'Sem anexos'}</div></div>
    ${L === 'nao' ? `<div class="instr">${ic('info')}<span>Este modelo não exige localização.</span></div>` : `<div class="instr">${ic('pin')}<span><b>${LOCAL[L].r}.</b> ${LOCAL[L].d}</span></div>`}
    ${temGps ? `<div class="cartao"><div class="cab-sec"><span>Posição do aparelho</span>${precisaGps() ? '<span class="tag-o">Obrigatório</span>' : ''}</div>
      ${R.gps ? `<div class="loc-ok">${ic('gps')}<div>Posição capturada<small>${R.gps.lat.toFixed(6)}, ${R.gps.lng.toFixed(6)} · ±${R.gps.prec ?? '?'} m</small></div></div>` : ''}
      <button class="btn ${R.gps ? '' : 'pri'}" id="bt-gps" style="justify-content:center;padding:11px" onclick="pegarGps()">${ic('gps')}${R.gps ? 'Capturar de novo' : 'Capturar posição'}</button></div>` : ''}
    ${temMapa ? `<div class="cartao"><div class="cab-sec"><span>Toque no mapa onde fica o imóvel</span>${precisaMapa() ? '<span class="tag-o">Obrigatório</span>' : ''}</div>
      <div class="mapa-marcar" id="mapa-marcar"></div>
      ${R.mapa ? `<div class="loc-ok">${ic('pin')}<div>Ponto marcado<small>${R.mapa.lat.toFixed(6)}, ${R.mapa.lng.toFixed(6)}</small></div></div>` : ''}</div>` : ''}`
}
function pegarGps() {
  const b = $('#bt-gps'); b.disabled = true; b.innerHTML = ic('gps') + 'Procurando satélites…'
  if (!navigator.geolocation) { toast('Este aparelho não informa a posição.', true); renderCorpo(); return }
  navigator.geolocation.getCurrentPosition(async p => {
    R.gps = { lat: p.coords.latitude, lng: p.coords.longitude, prec: Math.round(p.coords.accuracy) }
    try { await api('PUT', `/${R.v.id}/andamento`, { gps_lat: R.gps.lat, gps_lng: R.gps.lng, gps_precisao: R.gps.prec }) } catch (e) { toast(e.message, true) }
    renderCorpo(); renderRodape()
  }, e => {
    toast(e.code === 1 ? 'A permissão de localização foi negada. Libere nas configurações do navegador.' : 'Não foi possível obter a posição. Tente de novo em área aberta.', true)
    renderCorpo()
  }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 })
}
let mapaMarcar = null
function montarMapaMarcar() {
  const el = $('#mapa-marcar'); if (!el || !window.L) return
  const centro = R.mapa || R.gps || { lat: -15.5598, lng: -54.2967 }
  mapaMarcar = L.map(el, { zoomControl: true, attributionControl: false }).setView([centro.lat, centro.lng], R.mapa || R.gps ? 18 : 14)
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 20, maxNativeZoom: 17 }).addTo(mapaMarcar)
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png', { subdomains: 'abcd', maxZoom: 20 }).addTo(mapaMarcar)
  let marca = R.mapa ? L.marker([R.mapa.lat, R.mapa.lng]).addTo(mapaMarcar) : null
  if (R.gps) L.circle([R.gps.lat, R.gps.lng], { radius: Math.max(R.gps.prec || 10, 5), color: '#1D4ED8', weight: 1, fillOpacity: .15 }).addTo(mapaMarcar)
  mapaMarcar.on('click', async ev => {
    R.mapa = { lat: ev.latlng.lat, lng: ev.latlng.lng }
    if (marca) marca.setLatLng(ev.latlng); else marca = L.marker(ev.latlng).addTo(mapaMarcar)
    try { await api('PUT', `/${R.v.id}/andamento`, { mapa_lat: R.mapa.lat, mapa_lng: R.mapa.lng }) } catch (e) { toast(e.message, true) }
    renderRodape()
    // Só o aviso abaixo do mapa muda: refazer o cartão recriaria o mapa.
    const ok = el.parentElement.querySelector('.loc-ok')
    const html = `<div class="loc-ok">${ic('pin')}<div>Ponto marcado<small>${R.mapa.lat.toFixed(6)}, ${R.mapa.lng.toFixed(6)}</small></div></div>`
    if (ok) ok.outerHTML = html; else el.insertAdjacentHTML('afterend', html)
  })
  setTimeout(() => mapaMarcar?.invalidateSize(), 50)
}
async function iniciar() {
  try {
    await api('PUT', `/${R.v.id}/andamento`, { iniciar: true })
    R.v.estado = 'em_andamento'
    if (R.def.etapas.length) irEtapa(0); else irFinal()
  } catch (e) { toast(e.message, true) }
}

// ── etapa ──
function fotosHtml(lista, apagavel) {
  return lista.map(f => `<div class="foto"><img src="${f.url}" alt="" loading="lazy" onclick="verFoto('${f.url}')">
    ${apagavel(f) && podeEditar() ? `<button title="Remover" onclick="apagarFoto(${f.id})">${ic('x')}</button>` : ''}</div>`).join('')
}
function etapaHtmlRun(et) {
  const fotos = fotosEtapa(et.id), faltaFoto = et.foto === 'obrigatoria' && fotos.length < et.foto_min
  const an = anexosV().find(a => a.anexo === et.anexo)
  const consultar = an ? `<div class="consultar">${ic('doc')}<span>Consulte: <b>${esc(an.nome)}</b></span><button class="btn sm" onclick="abrirPdf(${an.id})">Abrir</button></div>`
    : et.anexo ? `<div class="instr">${ic('info')}<span>O modelo sugere consultar um anexo que não foi juntado a esta vistoria.</span></div>` : ''
  return `${et.instrucao ? `<div class="instr">${ic('info')}<span>${esc(et.instrucao)}</span></div>` : ''}${consultar}
    ${et.itens.map(itemHtmlRun).join('')}
    ${et.foto !== 'nao' ? `<div class="cartao ${faltaFoto ? 'falta' : ''}">
      <div class="cab-sec"><span>Fotos${et.foto_dica ? ' · ' + esc(et.foto_dica) : ''}</span><span class="tag-o ${et.foto === 'opcional' ? 'opc' : ''}">${et.foto === 'obrigatoria' ? `${fotos.length}/${et.foto_min} obrigatórias` : 'Opcional'}</span></div>
      <div class="fotos">${fotosHtml(fotos, () => true)}
        ${podeEditar() ? `<button class="add-foto ${faltaFoto ? 'falta' : ''}" ${R.enviando ? 'disabled' : ''} onclick="fotografar({etapa:'${et.id}'})">${ic('camera')}${R.enviando ? 'Enviando…' : 'Fotografar'}</button>` : ''}</div></div>` : ''}
    ${livresHtml(et.id)}`
}
function itemHtmlRun(it) {
  const r = R.resp[it.id] || {}, f = fotoItem(it.id)
  let extra = ''
  if (r.v === 'nao') extra = `<textarea class="in" id="ob-${it.id}" rows="2" maxlength="2000" placeholder="O que não confere?" oninput="anotar('${it.id}',this.value)" ${podeEditar() ? '' : 'readonly'}>${esc(r.obs || '')}</textarea>`
    + (R.def.nao_foto ? `<div class="fotos">${f ? fotosHtml([f], () => true)
      : podeEditar() ? `<button class="add-foto falta" ${R.enviando ? 'disabled' : ''} onclick="fotografar({etapa:'${etapa().id}',item:'${it.id}'})">${ic('camera')}Foto do problema</button>` : ''}</div>` : '')
  return `<div class="cartao ${it.obrig && !r.v ? 'falta' : ''}"><div class="rot"><span>${esc(it.rotulo)}</span>${it.obrig ? '' : '<span class="tag-o opc">Opcional</span>'}</div>
    <div class="ops">${RESP.map(([v, t]) => `<button class="op ${r.v === v ? 'on ' + v : ''}" ${podeEditar() ? '' : 'disabled'} onclick="responder('${it.id}','${v}')">${t}</button>`).join('')}</div>${extra}</div>`
}
function responder(id, v) { const r = R.resp[id] || {}; R.resp[id] = { ...r, v: r.v === v ? null : v }; agendarRespostas(); renderCorpo(); renderRodape(); renderTrilho() }
function anotar(id, t) { R.resp[id] = { ...(R.resp[id] || {}), obs: t }; agendarRespostas(); renderRodape() }

let tResp = null
function agendarRespostas() { clearTimeout(tResp); tResp = setTimeout(gravarRespostas, 600) }
async function gravarRespostas() {
  if (!tResp || !R) return
  clearTimeout(tResp); tResp = null
  try { await api('PUT', `/${R.v.id}/andamento`, { respostas: R.resp }) } catch (e) { toast('Resposta não gravada: ' + e.message, true) }
}

// ── fotos ──
let alvoFoto = null
function fotografar(alvo) { alvoFoto = alvo; const c = $('#cam'); c.value = ''; c.click() }
$('#cam').addEventListener('change', async ev => {
  const f = ev.target.files?.[0]; if (!f || !alvoFoto || !R) return
  const alvo = alvoFoto; alvoFoto = null
  R.enviando++; renderCorpo()
  try {
    const blob = await carimbar(f)
    const fd = new FormData(); fd.append('arquivo', blob, 'foto.jpg')
    if (alvo.etapa) fd.append('etapa', alvo.etapa)
    if (alvo.item) fd.append('item', alvo.item)
    if (alvo.livre) fd.append('livre_id', alvo.livre)
    const a = await api('POST', `/${R.v.id}/fotos`, fd)
    if (alvo.item) R.v.arquivos = R.v.arquivos.filter(x => x.item !== alvo.item)
    R.v.arquivos.push(a)
  } catch (e) { toast(e.message, true) } finally { R.enviando--; renderCorpo(); renderRodape(); renderTrilho() }
})
async function apagarFoto(id) {
  try { await api('DELETE', '/fotos/' + id); R.v.arquivos = R.v.arquivos.filter(a => a.id !== id); renderCorpo(); renderRodape(); renderTrilho() } catch (e) { toast(e.message, true) }
}
function verFoto(url) {
  const d = document.createElement('div'); d.className = 'ver-foto'
  d.innerHTML = `<img src="${url}" alt=""><button title="Fechar">${ic('x')}</button>`
  d.onclick = () => d.remove(); document.body.appendChild(d)
}
/** Reduz para 1600 px e grava data, hora e posição na própria imagem. */
function carimbar(arquivo) {
  return new Promise((res, rej) => {
    const url = URL.createObjectURL(arquivo), img = new Image()
    img.onload = () => {
      const s = Math.min(1, 1600 / Math.max(img.width, img.height))
      const cv = document.createElement('canvas'); cv.width = Math.round(img.width * s); cv.height = Math.round(img.height * s)
      const x = cv.getContext('2d'); x.drawImage(img, 0, 0, cv.width, cv.height)
      const agora = new Date(), h = Math.max(30, cv.height * .055)
      const p = R.gps || R.mapa
      const pos = p ? `${p.lat.toFixed(6)}, ${p.lng.toFixed(6)}${R.gps?.prec ? ' ±' + R.gps.prec + ' m' : ' (mapa)'}` : 'sem localização'
      x.fillStyle = 'rgba(0,0,0,.6)'; x.fillRect(0, cv.height - h, cv.width, h)
      x.fillStyle = '#fff'; x.font = `600 ${Math.round(h * .38)}px Inter, sans-serif`; x.textBaseline = 'middle'
      x.fillText(`${agora.toLocaleDateString('pt-BR')} ${agora.toLocaleTimeString('pt-BR')} · ${pos}`, h * .3, cv.height - h / 2)
      URL.revokeObjectURL(url)
      cv.toBlob(b => b ? res(b) : rej(new Error('Não foi possível preparar a foto.')), 'image/jpeg', .85)
    }
    img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('Arquivo de imagem inválido.')) }
    img.src = url
  })
}

// ── itens livres ──
function livresHtml(ctx) {
  const todos = ctx === 'fim' || ctx === 'depois'
  const lista = todos ? R.v.livres : R.v.livres.filter(l => l.etapa === ctx)
  const aberto = R.novoLivre?.ctx === ctx
  if (!todos && !lista.length && !aberto)
    return podeEditar() ? `<button class="btn ghost" style="align-self:flex-start" onclick="novoLivre('${ctx}')">${ic('mais')}Item livre nesta etapa</button>` : ''
  const ondeFoi = e => R.def.etapas.find(x => x.id === e)?.titulo
  const travado = l => R.v.estado === 'concluida' && !l.depois
  const itens = lista.map(l => `<div class="livre"><div class="l1"><div><b>${esc(l.titulo)}</b>${l.observacao ? `<p>${esc(l.observacao)}</p>` : ''}
      <div class="meta">${esc(l.autor || '')} · ${esc(l.quando)}${todos && ondeFoi(l.etapa) ? ' · em ' + esc(ondeFoi(l.etapa)) : ''}${l.depois ? ' · acrescentado depois de concluída' : ''}</div></div>
      ${!travado(l) && podeEditar() ? `<button class="ib" title="Remover item" onclick="apagarLivre(${l.id})">${ic('lixo')}</button>` : ''}</div>
    <div class="fotos">${fotosHtml(fotosLivre(l.id), () => !travado(l))}
      ${!travado(l) && podeEditar() ? `<button class="add-foto" ${R.enviando ? 'disabled' : ''} onclick="fotografar({livre:${l.id}})">${ic('camera')}Foto</button>` : ''}</div></div>`).join('')
  const form = aberto ? `<div class="cartao">
      <input class="in" id="lv-tit" maxlength="160" placeholder="Assunto (ex.: muro sem reboco na divisa)" value="${esc(R.novoLivre.titulo)}" oninput="R.novoLivre.titulo=this.value">
      <textarea class="in" id="lv-obs" rows="3" maxlength="4000" placeholder="Observação" oninput="R.novoLivre.obs=this.value">${esc(R.novoLivre.obs)}</textarea>
      <div style="font-size:12px;color:var(--tx3)">As fotos entram depois de salvar o item.</div>
      <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn sm" onclick="R.novoLivre=null;renderCorpo()">Cancelar</button>
        <button class="btn sm pri" onclick="salvarLivre()">Salvar item</button></div></div>` : ''
  return `<div class="cartao"><div class="cab-sec"><span>Itens livres${todos ? '' : ' desta etapa'}</span><span class="tag-o opc">${lista.length}</span></div>
    ${todos && !lista.length && !aberto ? `<div style="font-size:12.5px;color:var(--tx2)">O que você viu e o roteiro não perguntou: assunto, fotos e observação.</div>` : ''}
    ${itens}${form}${aberto || !podeEditar() || R.fase === 'fim' ? '' : `<button class="btn" onclick="novoLivre('${ctx}')">${ic('mais')}Adicionar item livre</button>`}</div>`
}
function novoLivre(ctx) { R.novoLivre = { ctx, titulo: '', obs: '' }; renderCorpo(); $('#lv-tit')?.focus() }
async function salvarLivre() {
  const n = R.novoLivre
  if (!n.titulo.trim()) { toast('Dê um assunto ao item.', true); return }
  try {
    const l = await api('POST', `/${R.v.id}/livres`, { titulo: n.titulo.trim(), observacao: n.obs.trim() || null, etapa: ['fim', 'depois'].includes(n.ctx) ? null : n.ctx })
    R.v.livres.push(l); R.novoLivre = null; renderCorpo()
  } catch (e) { toast(e.message, true) }
}
async function apagarLivre(id) {
  try { await api('DELETE', '/livres/' + id); R.v.livres = R.v.livres.filter(l => l.id !== id); R.v.arquivos = R.v.arquivos.filter(a => a.livre_id !== id); renderCorpo() } catch (e) { toast(e.message, true) }
}

// ── rodapé e navegação ──
function renderRodape() {
  const r = $('#rodape'); let msg = '', bts = ''
  if (R.fase === 'portao') {
    const ok = localOk(), L = R.def.local
    msg = ok ? `<div class="falta-lista ok">${ic('ok')}Pronto. A hora de início fica gravada ao iniciar.</div>`
      : `<div class="falta-lista">${ic('pin')}Falta: ${L === 'gps_e_mapa' ? [!R.gps && 'posição do aparelho', !R.mapa && 'ponto no mapa'].filter(Boolean).join(' e ') : LOCAL[L].r.toLowerCase()}</div>`
    bts = `<button class="btn" onclick="fecharVistoria()">${ic('esq')}Voltar</button><button class="btn pri" ${ok && podeEditar() ? '' : 'disabled'} onclick="iniciar()">Iniciar${ic('dir')}</button>`
  } else if (R.fase === 'etapa') {
    const p = pendencias(etapa()), ult = R.i === R.def.etapas.length - 1
    msg = p.length ? `<div class="falta-lista">${ic('alerta')}<span>Falta: ${esc(p.slice(0, 3).join(' · '))}${p.length > 3 ? ` e mais ${p.length - 3}` : ''}</span></div>`
      : `<div class="falta-lista ok">${ic('ok')}Etapa completa${nNao(etapa()) ? ` · ${nNao(etapa())} não confere` : ''}</div>`
    bts = `<button class="btn" ${R.i === 0 ? 'disabled' : ''} onclick="irEtapa(${R.i - 1})">${ic('esq')}Voltar</button>
      <button class="btn pri" ${p.length ? 'disabled' : ''} onclick="${ult ? 'irFinal()' : `irEtapa(${R.i + 1})`}">${ult ? 'Conclusão' : 'Próxima'}${ic('dir')}</button>`
  } else if (R.fase === 'final') {
    msg = R.conclusao ? `<div class="falta-lista ok">${ic('ok')}Pronta para concluir.</div>` : `<div class="falta-lista">${ic('alerta')}Falta: escolher a conclusão</div>`
    bts = `<button class="btn" ${R.def.etapas.length ? '' : 'disabled'} onclick="irEtapa(${R.def.etapas.length - 1})">${ic('esq')}Voltar</button>
      <button class="btn pri" ${R.conclusao && podeEditar() ? '' : 'disabled'} onclick="concluir()">Concluir vistoria</button>`
  } else if (R.fase === 'complemento') {
    bts = `<button class="btn pri" onclick="R.novoLivre=null;R.fase='fim';renderExec()">${ic('ok')}Terminar complemento</button>`
  } else {
    bts = `<button class="btn" onclick="fecharVistoria()">${ic('esq')}Lista</button>`
      + (podeEditar() ? `<button class="btn pri" onclick="R.fase='complemento';renderExec()">${ic('mais')}Complementar</button>` : '')
  }
  r.innerHTML = msg + `<div class="bts">${bts}</div>`
  if (R.fase === 'etapa') renderCab()
}
function entrarEtapa() {
  const an = etapa().anexo && anexosV().find(a => a.anexo === etapa().anexo)
  if (an && R.pdf.anexo !== an.id) { R.pdf.anexo = an.id; R.pdf.sugerido = an.id }
}
async function irEtapa(k) { await gravarRespostas(); R.fase = 'etapa'; R.i = k; R.max = Math.max(R.max, k); R.novoLivre = null; entrarEtapa(); renderExec(); $('#corpo').scrollTop = 0 }
async function irFinal() { await gravarRespostas(); R.fase = 'final'; R.max = R.def.etapas.length; R.novoLivre = null; renderExec(); $('#corpo').scrollTop = 0 }
async function concluir() {
  await gravarRespostas()
  try {
    const d = await api('POST', `/${R.v.id}/concluir`, { conclusao: R.conclusao })
    toast(d.message)
    R.v = await api('GET', '/' + R.v.id)
    R.fase = 'fim'; R.pdf.modo = 'mini'; renderExec()
  } catch (e) { toast(e.message, true) }
}

function finalHtml() {
  const def = R.def
  const linhas = def.etapas.map(e => { const d = nNao(e), nf = fotosEtapa(e.id).length
    return `<tr><td>${esc(e.titulo)}<div style="font-size:11.5px;color:var(--tx3)">${nf} foto(s)</div></td><td>${d ? `<span class="chip am">${d} não confere</span>` : '<span class="chip vd">confere</span>'}</td></tr>` }).join('')
  return `${def.etapas.length ? `<div class="cartao"><div class="cab-sec">Resumo</div><table class="tab-res"><tbody>${linhas}</tbody></table></div>` : ''}
    ${livresHtml('fim')}
    <div class="cartao ${R.conclusao ? '' : 'falta'}"><div class="rot"><span>Conclusão da vistoria</span></div>
      <div class="ops livre">${def.conclusoes.map(c => `<button class="op ${R.conclusao === c ? 'on' : ''}" style="padding-inline:14px" data-v="${esc(c)}" ${podeEditar() ? '' : 'disabled'} onclick="R.conclusao=this.dataset.v;renderCorpo();renderRodape()">${esc(c)}</button>`).join('')}</div></div>`
}
function complementoHtml() {
  return `<div class="instr">${ic('info')}<span>Vistoria concluída como <b>${esc(R.v.conclusao)}</b> em ${esc(R.v.concluida_em)}. O que for acrescentado agora fica marcado; o que já estava não muda.</span></div>
    ${livresHtml('depois')}`
}
function fimHtml() {
  const def = R.def, v = R.v
  const nf = v.arquivos.filter(a => a.papel === 'foto').length, nd = def.etapas.reduce((s, e) => s + nNao(e), 0), nDep = v.livres.filter(l => l.depois).length
  const linhas = def.etapas.map(e => { const d = nNao(e); return `<tr><td>${esc(e.titulo)}</td><td>${d ? `<span class="chip am">${d} não confere</span>` : '<span class="chip vd">confere</span>'}</td></tr>` }).join('')
  return `<div class="recibo"><div class="selo">${ic('ok')}</div>
      <h2 style="font:800 20px var(--f-tit);margin:0">${esc(v.conclusao)}</h2>
      <div style="color:var(--tx2);font-size:13px">${def.etapas.length ? `${def.etapas.length} etapas · ${nd} não confere · ` : ''}${nf} foto(s) · ${v.livres.length} item(ns) livre(s)${nDep ? ` (${nDep} depois)` : ''}</div>
      <code>${esc(v.modelo)} v${v.versao} · iniciada ${esc(v.iniciada_em)} · concluída ${esc(v.concluida_em)}</code>
      <div style="font-size:12px;color:var(--tx3)">${v.gps ? `GPS ${v.gps.lat.toFixed(5)}, ${v.gps.lng.toFixed(5)} ±${v.gps.prec} m` : ''}${v.gps && v.mapa ? ' · ' : ''}${v.mapa ? `Mapa ${v.mapa.lat.toFixed(5)}, ${v.mapa.lng.toFixed(5)}` : ''}</div></div>
    ${def.etapas.length ? `<div class="cartao"><div class="cab-sec">Etapas</div><table class="tab-res"><tbody>${linhas}</tbody></table></div>` : ''}
    ${v.livres.length ? livresHtml('fim') : ''}`
}

// ══════════════ ANEXOS (PDF) ══════════════
const PDFJS = '/vendor/pdfjs-3.11.174/'
let pdfjsP = null
function carregarPdfjs() {
  if (!pdfjsP) {
    pdfjsP = new Promise((ok, falha) => {
      const s = document.createElement('script'); s.src = PDFJS + 'pdf.min.js'
      s.onload = () => { if (!window.pdfjsLib) return falha(new Error('pdfjs')); pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js'; ok(window.pdfjsLib) }
      s.onerror = () => falha(new Error('Leitor de PDF indisponível.'))
      document.head.appendChild(s)
    })
    pdfjsP.catch(() => { pdfjsP = null })
  }
  return pdfjsP
}
const PDF_DOCS = new Map()
function abrirPdf(id) {
  if (!anexosV().length) return
  if (id) R.pdf.anexo = id
  R.pdf.sugerido = ''
  if (R.pdf.modo === 'mini') R.pdf.modo = largo() ? 'lado' : 'janela'
  renderPdf()
}
function modoPdf(m) { R.pdf.modo = m; renderPdf() }
const anexoAtual = () => anexosV().find(a => a.id === R.pdf.anexo) || anexosV()[0]
function renderPdf() {
  const slot = $('#pdf-slot'), lado = $('#lado-slot')
  slot.innerHTML = ''; lado.hidden = true; lado.className = ''; lado.innerHTML = ''
  if (!R || !anexosV().length || R.fase === 'portao') return
  const p = R.pdf, an = anexoAtual()
  if (p.modo === 'mini') {
    slot.innerHTML = `<button class="pdf-mini ${p.sugerido ? 'pulso' : ''}" onclick="abrirPdf()">${ic('doc')}${esc(an.nome.split(' ').slice(0, 2).join(' '))} <small>${p.sugerido ? 'indicado nesta etapa' : 'pág. ' + (p.pag[an.id] || 1)}</small></button>`
    return
  }
  const html = janelaPdfHtml(an)
  let raiz
  if (p.modo === 'lado') { lado.hidden = false; lado.className = 'pdf-lado'; lado.innerHTML = html; raiz = lado }
  else {
    const w = Math.min(window.innerWidth - 16, largo() ? 520 : 360), h = Math.min(window.innerHeight - 120, largo() ? 440 : 340)
    p.x = Math.min(p.x, window.innerWidth - w); p.y = Math.min(p.y, window.innerHeight - h)
    slot.innerHTML = `<div class="pdfw ${p.modo}" id="pdfw" style="${p.modo === 'cheio' ? '' : `left:${p.x}px;top:${p.y}px;width:${w}px;height:${h}px`}">${html}</div>`
    raiz = $('#pdfw'); if (p.modo === 'janela') arrastavel(raiz)
  }
  desenharPagina(raiz, an)
}
function janelaPdfHtml(an) {
  const p = R.pdf, m = p.modo, lista = anexosV()
  return `<div class="pdf-barra">
      <span class="nm">${esc(an.nome)} <small>${esc(an.nome_original || '')}</small></span>
      <a class="ib" href="${an.url}" target="_blank" rel="noopener" title="Abrir em outra aba">${ic('jan')}</a>
      <button class="ib" title="Minimizar" onclick="modoPdf('mini')">${ic('min')}</button>
      ${m !== 'janela' ? `<button class="ib" title="Janela flutuante" onclick="modoPdf('janela')">${ic('jan')}</button>` : ''}
      ${largo() && m !== 'lado' ? `<button class="ib" title="Lado a lado" onclick="modoPdf('lado')">${ic('lado')}</button>` : ''}
      ${m !== 'cheio' ? `<button class="ib" title="Tela cheia" onclick="modoPdf('cheio')">${ic('max')}</button>` : `<button class="ib" title="Sair da tela cheia" onclick="modoPdf(largo()?'lado':'janela')">${ic('x')}</button>`}</div>
    <div class="pdf-folhas">
      ${lista.length > 1 ? `<select id="pdf-qual" onchange="R.pdf.anexo=+this.value;renderPdf()" aria-label="Anexo">${lista.map(a => `<option value="${a.id}" ${a.id === an.id ? 'selected' : ''}>${esc(a.nome)}</option>`).join('')}</select>` : ''}
      <span class="pdf-pag"><button class="ib" onclick="pagPdf(-1)" title="Página anterior">${ic('esq')}</button><span id="pdf-num">…</span><button class="ib" onclick="pagPdf(1)" title="Próxima página">${ic('dir')}</button></span>
      <span class="zm"><button class="ib" onclick="zoomPdf(-1)" ${p.zoom <= 1 ? 'disabled' : ''} title="Afastar">${ic('zmenos')}</button>${Math.round(p.zoom * 100)}%<button class="ib" onclick="zoomPdf(1)" ${p.zoom >= 4 ? 'disabled' : ''} title="Aproximar">${ic('zmais')}</button></span></div>
    <div class="pdf-view" id="pdf-view"><div class="pdf-carregando">Abrindo…</div></div>`
}
let totalPags = 1
function pagPdf(d) { const an = anexoAtual(), atual = R.pdf.pag[an.id] || 1, n = Math.min(totalPags, Math.max(1, atual + d)); if (n !== atual) { R.pdf.pag[an.id] = n; renderPdf() } }
function zoomPdf(d) { R.pdf.zoom = Math.min(4, Math.max(1, R.pdf.zoom + d * .5)); renderPdf() }
let vezPdf = 0
async function desenharPagina(raiz, an) {
  const vez = ++vezPdf
  const view = raiz.querySelector('#pdf-view'), num = raiz.querySelector('#pdf-num')
  const pag = R.pdf.pag[an.id] || 1
  try {
    const lib = await carregarPdfjs()
    if (!PDF_DOCS.has(an.id)) PDF_DOCS.set(an.id, lib.getDocument({ url: an.url, withCredentials: true }).promise)
    const doc = await PDF_DOCS.get(an.id)
    if (vez !== vezPdf) return
    totalPags = doc.numPages; num.textContent = `${pag} / ${totalPags}`
    const page = await doc.getPage(pag)
    const largura = Math.max(200, view.clientWidth - 20) * R.pdf.zoom
    const base = page.getViewport({ scale: 1 }), dpr = Math.min(window.devicePixelRatio || 1, 2)
    const vp = page.getViewport({ scale: (largura / base.width) * dpr })
    const cv = document.createElement('canvas'); cv.width = vp.width; cv.height = vp.height
    cv.style.width = largura + 'px'; cv.style.height = (vp.height / dpr) + 'px'
    await page.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise
    if (vez !== vezPdf || !view.isConnected) return
    view.innerHTML = ''; view.appendChild(cv)
  } catch (e) {
    console.warn('PDF', e); PDF_DOCS.delete(an.id)
    if (view.isConnected) view.innerHTML = `<div class="pdf-carregando">Não foi possível abrir este PDF aqui. Use o botão de abrir em outra aba.</div>`
  }
}
function arrastavel(w) {
  const barra = w.querySelector('.pdf-barra')
  barra.addEventListener('pointerdown', ev => {
    if (ev.target.closest('button,select,a')) return
    barra.setPointerCapture(ev.pointerId)
    const ox = ev.clientX - w.offsetLeft, oy = ev.clientY - w.offsetTop
    const mover = e => {
      R.pdf.x = Math.max(0, Math.min(window.innerWidth - w.offsetWidth, e.clientX - ox))
      R.pdf.y = Math.max(0, Math.min(window.innerHeight - w.offsetHeight, e.clientY - oy))
      w.style.left = R.pdf.x + 'px'; w.style.top = R.pdf.y + 'px'
    }
    const soltar = () => { barra.removeEventListener('pointermove', mover); barra.removeEventListener('pointerup', soltar) }
    barra.addEventListener('pointermove', mover); barra.addEventListener('pointerup', soltar)
  })
}

irAba('vistorias')
