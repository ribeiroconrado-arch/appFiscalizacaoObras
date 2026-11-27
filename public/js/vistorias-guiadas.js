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
// Os mesmos de VgItem (servidor).
const TIPOS_ITEM = { confere: 'Confere / Não confere', numero: 'Número', opcoes: 'Escolha', data: 'Data', texto: 'Texto' }
const METODOS = { trena: 'Medida com trena', estimativa: 'Estimativa visual', projeto: 'Conforme projeto apresentado', croqui: 'Calculada pelo croqui' }
const tipoI = it => TIPOS_ITEM[it.tipo] ? it.tipo : 'confere'
/** 1.234,56 — como o sistema escreve número. */
const fmtNum = n => n === null || n === undefined || n === '' ? '' : Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 })
function lerNum(s) {
  s = String(s ?? '').trim(); if (!s) return null
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.')
  const n = Number(s); return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) / 100 : NaN
}
const TIPOS = { imovel: 'Imóvel', pessoa: 'Pessoa', protocolo: 'Protocolo', denuncia: 'Denúncia' }
const MODULOS = { obras: 'Obras', posturas: 'Posturas' }
const EU = window.VG_EU

const VG = { modelos: [], vistorias: [], aba: 'vistorias', agentes: null }
// EMBUTIDA: a página está numa janela do mapa (vistoria-guiada-ponte.js).
// Fechar, atualizar a lista e gerar a peça são pedidos ao mapa.
const EMB = !!window.VG_EMBUTIDA && window.parent !== window
function avisarMapa(acao, dados = {}) {
  if (EMB) window.parent.postMessage({ vg: acao, ...dados }, location.origin)
}
/** Como a vistoria se chama numa linha: o local, senão o primeiro vínculo. */
const nomeV = v => v.local || v.vinculos?.[0]?.rotulo || 'Sem local informado'
async function carregarAgentes() {
  if (!VG.agentes) { try { VG.agentes = (await api('GET', '/agentes')).agentes } catch (e) { VG.agentes = []; toast(e.message, true) } }
  return VG.agentes
}

// ══════════════ busca de vínculo (usada na criação e na ficha) ══════════════
// O resultado é desenhado só na lista abaixo do campo: refazer o bloco inteiro
// a cada tecla tiraria o foco de quem está digitando.
const BV = {}
function buscaVinculoHtml(ctx) {
  const b = BV[ctx] || (BV[ctx] = { tipo: 'imovel', q: '', res: [] })
  const dica = { imovel: 'Inscrição ou bairro', pessoa: 'Nome ou CPF/CNPJ', protocolo: 'Número ou requerente', denuncia: 'Protocolo ou rua' }[b.tipo]
  return `<div class="bv">
    <div class="bv-linha">
      <select class="in" aria-label="Tipo de vínculo" onchange="BV['${ctx}'].tipo=this.value;BV['${ctx}'].res=[];refazerBusca('${ctx}')">
        ${Object.entries(TIPOS).map(([k, t]) => `<option value="${k}" ${b.tipo === k ? 'selected' : ''}>${t}</option>`).join('')}</select>
      <input class="in" id="bv-q-${ctx}" placeholder="${dica}" value="${esc(b.q)}" oninput="digitarBusca('${ctx}',this.value)" autocomplete="off">
    </div>
    <div class="bv-res" id="bv-res-${ctx}">${resultadosHtml(ctx)}</div></div>`
}
function resultadosHtml(ctx) {
  const b = BV[ctx]
  if (!b.q || b.q.trim().length < 2) return ''
  if (b.carregando) return '<div class="bv-nada">Procurando…</div>'
  if (!b.res.length) return '<div class="bv-nada">Nada encontrado.</div>'
  return b.res.map((r, k) => `<button onclick="escolherVinculo('${ctx}',${k})">${ic('mais')}<span>${esc(r.rotulo)}</span></button>`).join('')
}
function refazerBusca(ctx) {
  const el = $('#bv-q-' + ctx)?.closest('.bv'); if (!el) return
  el.outerHTML = buscaVinculoHtml(ctx); digitarBusca(ctx, BV[ctx].q)
}
let tBusca = null
function digitarBusca(ctx, q) {
  const b = BV[ctx]; b.q = q; clearTimeout(tBusca)
  if (q.trim().length < 2) { b.res = []; const r = $('#bv-res-' + ctx); if (r) r.innerHTML = ''; return }
  tBusca = setTimeout(async () => {
    b.carregando = true; const r = $('#bv-res-' + ctx); if (r) r.innerHTML = resultadosHtml(ctx)
    const vez = b.vez = (b.vez || 0) + 1
    try { const d = await api('GET', `/vinculos/busca?tipo=${b.tipo}&q=${encodeURIComponent(q.trim())}`); if (vez === b.vez) b.res = d.resultados }
    catch (e) { b.res = []; toast(e.message, true) }
    b.carregando = false
    const r2 = $('#bv-res-' + ctx); if (r2) r2.innerHTML = resultadosHtml(ctx)
  }, 300)
}
async function escolherVinculo(ctx, k) {
  const b = BV[ctx], r = b.res[k]; if (!r) return
  b.q = ''; b.res = []
  if (ctx === 'nv') {
    if (!NV.vinculos.some(x => x.tipo === b.tipo && x.id === r.id)) NV.vinculos.push({ tipo: b.tipo, id: r.id, rotulo: r.rotulo })
    renderNova()
  } else {
    try { R.v.vinculos.push(await api('POST', `/${R.v.id}/vinculos`, { tipo: b.tipo, id: r.id })); renderFicha(); renderCab() }
    catch (e) { toast(e.message, true) }
  }
}
const vinculoChip = (l, rem) => `<div class="vinc"><span class="vinc-t">${TIPOS[l.tipo]}</span><span class="vinc-r">${esc(l.rotulo)}</span>${rem ? `<button class="ib" title="Desligar" onclick="${rem}">${ic('x')}</button>` : ''}</div>`

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
          <span class="chip">${esc(v.modelo)} v${v.versao}</span><span class="chip">${MODULOS[v.modulo] || v.modulo}</span>${v.anexos ? `<span class="chip az">${ic('doc')}${v.anexos}</span>` : ''}${v.minha ? '<span class="chip vd">sua</span>' : ''}</span>
        <code class="num">${esc(v.numero)}</code>
        <b>${esc(nomeV(v))}</b>
        ${v.vinculos.length > (v.local ? 0 : 1) ? `<small>${esc(v.vinculos.slice(v.local ? 0 : 1).map(l => l.rotulo).join(' · '))}</small>` : ''}
        <small>${esc(v.fiscais.join(', ') || '—')} · criada em ${esc(v.criada_em)}${v.conclusao ? ' · ' + esc(v.conclusao) : ''}</small></button>`).join('')}</div>`
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
  if (!NV) NV = novaVazia()
  if (!usaveis.some(m => m.id === NV.modelo)) NV.modelo = modeloSugerido(usaveis)
  const m = usaveis.find(x => x.id === NV.modelo), def = m.vigente.definicao
  const agentes = VG.agentes || []
  if (!VG.agentes) carregarAgentes().then(() => { if (VG.aba === 'nova') renderNova() })
  const exige = def.vinculos || []
  const slot = (nome, arq, obrig, rem, add) => `<div class="anexo-slot ${arq ? 'tem' : obrig ? 'falta' : ''}">
      ${ic('doc')}<div class="info"><b>${esc(nome)}${obrig !== null ? ` <span class="tag-o ${obrig ? '' : 'opc'}">${obrig ? 'Obrigatório' : 'Opcional'}</span>` : ''}</b>
        <small>${arq ? `${esc(arq.name)} · ${kb(arq.size)}` : 'Nenhum arquivo'}</small></div>
      ${arq ? `<button class="btn sm" onclick="${rem}">${ic('lixo')}Remover</button>` : `<button class="btn sm pri" onclick="${add}">${ic('mais')}Anexar PDF</button>`}</div>`
  const faltam = def.anexos.filter(a => a.obrig && !NV.anexos[a.id])
  alvo.innerHTML = `<div class="caixa"><div class="nova">
    <h1>Nova vistoria</h1>
    <label class="campo-f"><span>Modelo</span><select class="in" id="nv-mod" onchange="NV.modelo=+this.value;NV.anexos={};renderNova()">
      ${usaveis.map(x => `<option value="${x.id}" ${x.id === NV.modelo ? 'selected' : ''}>${esc(x.nome)}</option>`).join('')}</select></label>
    ${NV.sinalizacao ? `<div class="instr">${ic('info')}<span>Esta vistoria nasce de uma <b>sinalização</b>: ao concluir, ela fica resolvida.</span></div>` : ''}
    <div class="campo-f"><span>Ligada a ${exige.length ? `<span class="tag-o">Exige: ${esc(exige.map(t => TIPOS[t].toLowerCase()).join(', '))}</span>` : '<span class="tag-o opc">Opcional</span>'}</span>
      ${NV.vinculos.map((l, k) => vinculoChip(l, `NV.vinculos.splice(${k},1);renderNova()`)).join('')}
      ${buscaVinculoHtml('nv')}</div>
    <label class="campo-f"><span>Local ${NV.vinculos.length ? '(opcional)' : ''}</span><input class="in" id="nv-local" maxlength="255" placeholder="Rua, número, ponto de referência" value="${esc(NV.local)}" oninput="NV.local=this.value;atualizarCriar()"></label>
    <label class="campo-f"><span>Descrição (opcional)</span><textarea class="in" id="nv-desc" rows="3" maxlength="4000" placeholder="Motivo da vistoria, o que foi pedido…" oninput="NV.descricao=this.value">${esc(NV.descricao)}</textarea></label>
    <div class="campo-f"><span>Fiscais</span>
      <div class="fisc-lista"><span class="fisc">${esc(EU.nome)} <small>você</small></span>
        ${NV.fiscais.map((id, k) => `<span class="fisc">${esc(agentes.find(a => a.id === id)?.nome || '…')}<button class="ib" title="Tirar" onclick="NV.fiscais.splice(${k},1);renderNova()">${ic('x')}</button></span>`).join('')}
        <select class="in fisc-add" aria-label="Acrescentar fiscal" onchange="if(this.value){NV.fiscais.push(+this.value);renderNova()}">
          <option value="">+ Acrescentar fiscal</option>
          ${agentes.filter(a => a.id !== EU.id && !NV.fiscais.includes(a.id)).map(a => `<option value="${a.id}">${esc(a.nome)}</option>`).join('')}</select></div></div>
    <div class="campo-f"><span>Anexos</span>
      <div style="display:flex;flex-direction:column;gap:8px">
        ${def.anexos.length ? def.anexos.map(a => slot(a.nome, NV.anexos[a.id], a.obrig, `delete NV.anexos['${a.id}'];renderNova()`, `escolherPdf('${a.id}')`)).join('')
          : '<p style="margin:0;color:var(--tx2)">Este modelo não pede anexos.</p>'}
        ${NV.extras.map((f, k) => slot(f.name.replace(/\.pdf$/i, ''), f, null, `NV.extras.splice(${k},1);renderNova()`, '')).join('')}
        <div><button class="btn sm" onclick="escolherPdf('extra')">${ic('mais')}Outro PDF</button></div>
      </div></div>
    <div style="display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap">
      <span class="falta-lista" id="nv-falta"></span>
      ${EMB ? `<button class="btn" onclick="avisarMapa('fechar')">Cancelar</button>` : ''}
      <button class="btn pri" id="nv-criar" onclick="criarVistoria()">Criar vistoria</button>
    </div>
    <p style="margin:0;font-size:12.5px;color:var(--tx3)">Os PDFs ficam com a vistoria e abrem durante o roteiro. Até 30 MB cada.</p>
  </div></div>`
  NV.faltam = faltam.map(a => a.nome)
  NV.exige = exige
  atualizarCriar()
}
/**
 * O modelo que já vem escolhido: o que tem os vínculos exigidos atendidos
 * pelo que veio do mapa, preferindo o que exige justamente esses (vindo da
 * denúncia, a averiguação de denúncia; vindo do imóvel, um de imóvel).
 */
function modeloSugerido(usaveis) {
  const tem = new Set(NV.vinculos.map(l => l.tipo))
  const nota = m => { const ex = m.vigente.definicao.vinculos || []
    return ex.every(t => tem.has(t)) ? 10 + ex.filter(t => tem.has(t)).length : 0 }
  return usaveis.reduce((a, m) => nota(m) > nota(a) ? m : a, usaveis[0]).id
}

/** A vistoria nova, no módulo em que o usuário está (o mesmo do mapa). */
function novaVazia() {
  return { modelo: null, modulo: window.VG_MODULO || 'obras', local: '', descricao: '', vinculos: [], fiscais: [], anexos: {}, extras: [], sinalizacao: null }
}
function atualizarCriar() {
  const f = [...(NV.faltam || [])]
  for (const t of NV.exige || []) if (!NV.vinculos.some(l => l.tipo === t)) f.unshift(TIPOS[t].toLowerCase())
  if (!NV.vinculos.length && !NV.local.trim()) f.unshift('local ou vínculo')
  $('#nv-falta').innerHTML = f.length ? `${ic('alerta')}Falta: ${esc(f.join(', '))}` : ''
  $('#nv-criar').disabled = f.length > 0
}
let alvoPdf = null
function escolherPdf(alvo) { alvoPdf = alvo; const c = $('#arq-pdf'); c.value = ''; c.click() }
$('#arq-pdf').addEventListener('change', ev => {
  const f = ev.target.files?.[0]; if (!f) return
  if (f.size > 30 * 1048576) { toast('O PDF passa de 30 MB.', true); return }
  if (alvoPdf.startsWith('ficha:')) { anexarNaFicha(alvoPdf.slice(6), f); return }
  if (alvoPdf === 'extra') NV.extras.push(f); else NV.anexos[alvoPdf] = f
  renderNova()
})
async function criarVistoria() {
  const fd = new FormData()
  fd.append('modelo_id', NV.modelo); fd.append('modulo', NV.modulo)
  if (NV.local.trim()) fd.append('local', NV.local.trim())
  if (NV.descricao.trim()) fd.append('descricao', NV.descricao.trim())
  fd.append('vinculos', JSON.stringify(NV.vinculos.map(l => ({ tipo: l.tipo, id: l.id }))))
  if (NV.sinalizacao) fd.append('sinalizacao_id', NV.sinalizacao)
  NV.fiscais.forEach(id => fd.append('fiscais[]', id))
  for (const [k, f] of Object.entries(NV.anexos)) fd.append(`anexos[${k}]`, f)
  NV.extras.forEach(f => fd.append('extras[]', f))
  const fim = cobrir('Enviando os anexos…')
  try {
    const d = await api('POST', '/', fd)
    NV = null; toast(d.message)
    avisarMapa('mudou')
    if (!EMB) irAba('vistorias')
    abrirVistoria(d.id)
  } catch (e) { toast(e.message, true) } finally { fim() }
}

// ══════════════ MODELOS ══════════════
// A TELA DOS MODELOS (refeita em 27/11/2026, "muito carregada"): duas
// colunas — a lista, só com nome e uma linha cinza; o editor, com duas abas,
// Roteiro (as etapas) e Regras (o resto). Sem selos coloridos nem textos
// explicativos longos: cada regra tem uma linha de dica, no máximo.

/** "v2 · 3 etapas" — o que a lista diz de cada modelo. */
function resumoModelo(m) {
  const d = m.rascunho, n = d?.etapas?.length || 0
  return [m.versao ? 'v' + m.versao : 'sem versão', n ? `${n} etapa${n > 1 ? 's' : ''}` : 'só itens livres'].join(' · ')
}
function renderMontador() {
  const lista = $('#lista-modelos')
  $('#mod-titulo').textContent = 'Modelos de ' + (MODULOS[window.VG_MODULO] || 'Obras')
  if (!VG.modelos.length) {
    lista.innerHTML = `<div class="mod-vazio">Nenhum modelo neste módulo.</div>`
    $('#editor').innerHTML = `<div class="vazio">Crie um modelo, ou carregue os exemplos deste módulo para começar.
      <button class="btn" onclick="carregarExemplos()">Carregar exemplos</button></div>`
    return
  }
  lista.innerHTML = VG.modelos.map(m => `<button class="${VG.M?.id === m.id ? 'at' : ''}" onclick="abrirModelo(${m.id})">
    <span class="mod-nome">${esc(m.nome)}${m.alterado ? '<i class="mod-sujo" title="Alterado e não publicado"></i>' : ''}</span>
    <small>${esc(resumoModelo(m))}${m.ativo ? '' : ' · inativo'}</small></button>`).join('')
  if (VG.M) renderEditor()
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
  const m = VG.M, d = D(), aba = VG.abaMod || 'roteiro'
  const nItens = d.etapas.reduce((s, e) => s + e.itens.length, 0)
  const nFotos = d.etapas.reduce((s, e) => s + (e.foto === 'obrigatoria' ? e.foto_min : 0), 0)
  const resumo = [`${d.etapas.length} etapa${d.etapas.length === 1 ? '' : 's'}`, `${nItens} ite${nItens === 1 ? 'm' : 'ns'}`,
    nFotos ? `${nFotos} foto${nFotos > 1 ? 's' : ''} mínima${nFotos > 1 ? 's' : ''}` : null].filter(Boolean).join(' · ')
  $('#editor').innerHTML = `
    <div class="cab-mod">
      <div class="cab-mod-txt">
        <input class="nome" id="nome-mod" maxlength="120" value="${esc(m.nome)}" oninput="VG.M.nome=this.value;mexeu()" aria-label="Nome do modelo">
        <small>${esc(resumo)}</small>
      </div>
      <span class="estado" id="estado-mod"></span>
      <button class="btn pri" id="bt-publicar" onclick="publicar()">Publicar</button>
    </div>
    <nav class="mod-abas" role="tablist">
      <button class="${aba === 'roteiro' ? 'at' : ''}" onclick="VG.abaMod='roteiro';renderEditor()">Roteiro</button>
      <button class="${aba === 'regras' ? 'at' : ''}" onclick="VG.abaMod='regras';renderEditor()">Regras</button>
    </nav>
    ${VG.erros ? `<div class="erros"><b>Não foi possível publicar:</b><ul>${VG.erros.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>` : ''}
    ${aba === 'roteiro' ? roteiroHtml() : regrasHtml()}`
  atualizarEstado()
}
function roteiroHtml() {
  const d = D()
  return `<div class="etapas">${d.etapas.length ? d.etapas.map(etapaHtml).join('')
      : `<p class="mod-dica">Sem etapas, o fiscal registra só itens livres (assunto, fotos e observação).</p>`}</div>
    <div class="mod-pe"><button class="btn" onclick="addEtapa()">${ic('mais')}Adicionar etapa</button></div>`
}
/** Uma regra: rótulo à esquerda, o controle à direita (empilha no celular). */
const regraL = (rot, dica, ctrl) => `<div class="regra-l"><div class="regra-rot"><b>${rot}</b>${dica ? `<small>${dica}</small>` : ''}</div><div class="regra-ctrl">${ctrl}</div></div>`
function regrasHtml() {
  const d = D(), vinc = d.vinculos || []
  return `<div class="regras-l">
    ${regraL('Ligar a', 'O que a vistoria precisa ter para ser criada. Nada marcado: basta o local.',
      `<div class="pilulas">${Object.entries(TIPOS).map(([k, t]) => `<button class="pil ${vinc.includes(k) ? 'on' : ''}" onclick="alternarVinculo('${k}')">${t}</button>`).join('')}</div>`)}
    ${regraL('Localização ao iniciar', LOCAL[d.local].d,
      `<select class="in" id="sel-local" onchange="D().local=this.value;mexeu();renderEditor()">
        ${Object.entries(LOCAL).map(([k, v]) => `<option value="${k}" ${d.local === k ? 'selected' : ''}>${v.r}</option>`).join('')}</select>`)}
    ${regraL('Foto no "não confere"', 'A descrição é sempre pedida; a foto, só se marcar.',
      `<label class="tog"><input type="checkbox" id="tg-nf" ${d.nao_foto ? 'checked' : ''} onchange="D().nao_foto=this.checked;mexeu()">Exigir foto</label>`)}
    ${regraL('Anexos pedidos', 'PDFs juntados ao criar a vistoria.',
      `<div class="anexos-l">${d.anexos.map(a => `<div class="anexo-esp">
          <input class="in" id="ae-${a.id}" maxlength="160" value="${esc(a.nome)}" oninput="D().anexos.find(x=>x.id==='${a.id}').nome=this.value;mexeu()" aria-label="Nome do anexo">
          <label><input type="checkbox" id="ao-${a.id}" ${a.obrig ? 'checked' : ''} onchange="D().anexos.find(x=>x.id==='${a.id}').obrig=this.checked;mexeu()">Obrigatório</label>
          <button class="ib" title="Remover" onclick="tirarAnexo('${a.id}')">${ic('lixo')}</button></div>`).join('')}
        <div><button class="btn sm" onclick="D().anexos.push({id:novoId('a'),nome:'Novo anexo',obrig:false});mexeu();renderEditor()">${ic('mais')}Anexo</button></div></div>`)}
    ${regraL('Conclusões', 'As respostas possíveis no fim da vistoria.',
      `<div class="pilulas">${d.conclusoes.map((c, k) => `<span class="pil on fixa">${esc(c)}<button title="Tirar" onclick="D().conclusoes.splice(${k},1);mexeu();renderEditor()">${ic('x')}</button></span>`).join('')}
        <input class="in pil-in" id="concl-nova" maxlength="120" placeholder="+ conclusão" onkeydown="if(event.key==='Enter'){novaConclusao(this.value)}" onblur="if(this.value.trim())novaConclusao(this.value)"></div>`)}
    ${regraL('Modelo ativo', 'Inativo não aparece para criar vistoria.',
      `<label class="tog"><input type="checkbox" id="tg-ativo" ${VG.M.ativo ? 'checked' : ''} onchange="VG.M.ativo=this.checked;mexeu()">Ativo</label>`)}
  </div>`
}
function alternarVinculo(k) {
  const v = new Set(D().vinculos || []); v.has(k) ? v.delete(k) : v.add(k)
  D().vinculos = Object.keys(TIPOS).filter(x => v.has(x)); mexeu(); renderEditor()
}
function novaConclusao(t) {
  t = t.trim(); if (!t) return
  if (!D().conclusoes.includes(t)) D().conclusoes.push(t)
  mexeu(); renderEditor(); $('#concl-nova')?.focus()
}
const nomeAnexo = id => D().anexos.find(a => a.id === id)?.nome
function chipsEtapa(e) {
  const n = e.itens.length
  return `<span class="et-resumo">${[`${n} ite${n === 1 ? 'm' : 'ns'}`,
    e.foto === 'obrigatoria' ? `${e.foto_min} foto${e.foto_min > 1 ? 's' : ''}` : e.foto === 'opcional' ? 'foto opcional' : null,
    nomeAnexo(e.anexo) ? 'consulta anexo' : null].filter(Boolean).join(' · ')}</span>`
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
      <div class="itens-s">${e.itens.map(it => itemEditorHtml(e, it)).join('')}</div>
      <div><button class="btn sm" onclick="addItem('${e.id}')">${ic('mais')}Item</button></div></div>
    <div class="et-acoes"><span style="display:flex;gap:6px">
        <button class="btn sm" ${n === 0 ? 'disabled' : ''} onclick="moverE('${e.id}',-1)">${ic('cima')}Subir</button>
        <button class="btn sm" ${n === D().etapas.length - 1 ? 'disabled' : ''} onclick="moverE('${e.id}',1)">${ic('baixo')}Descer</button></span>
      <button class="btn sm perigo" onclick="tirarE('${e.id}')">${ic('lixo')}Excluir etapa</button></div>
  </div>`
}
/** Um item no editor: texto, tipo e o que cada tipo pede. */
function itemEditorHtml(e, it) {
  const t = tipoI(it), q = `'${e.id}','${it.id}'`
  let extra = ''
  if (t === 'numero') extra = `<div class="it-s-extra">
      <label class="mini">Unidade <input class="in" id="iu-${it.id}" maxlength="12" style="width:70px" value="${esc(it.unidade || '')}" placeholder="m²" oninput="setI(${q},'unidade',this.value)"></label>
      <label><input type="checkbox" id="im-${it.id}" ${it.metodo ? 'checked' : ''} onchange="setI(${q},'metodo',this.checked)">Perguntar como foi medido</label>
      <label title="O valor deste item é a área usada no cálculo da multa por m²"><input type="checkbox" id="ia-${it.id}" ${it.papel === 'area' ? 'checked' : ''} onchange="marcarArea(${q},this.checked)">Área da multa</label></div>`
  if (t === 'opcoes') extra = `<div class="it-s-extra"><input class="in" id="iop-${it.id}" style="flex:1" maxlength="1200" placeholder="Opções separadas por ponto e vírgula"
      value="${esc((it.opcoes || []).join('; '))}" onchange="setI(${q},'opcoes',this.value.split(';').map(s=>s.trim()).filter(Boolean))"></div>`
  return `<div class="it-s-bloco"><div class="it-s">
      <input class="in" id="ir-${it.id}" maxlength="200" value="${esc(it.rotulo)}" placeholder="Ex.: Recuos" oninput="setI(${q},'rotulo',this.value)" aria-label="Item">
      <select class="in it-tipo" id="it-${it.id}" aria-label="Tipo do item" onchange="setI(${q},'tipo',this.value);renderEditor()">
        ${Object.entries(TIPOS_ITEM).map(([k, n]) => `<option value="${k}" ${t === k ? 'selected' : ''}>${n}</option>`).join('')}</select>
      <label><input type="checkbox" id="io-${it.id}" ${it.obrig ? 'checked' : ''} onchange="setI(${q},'obrig',this.checked)">Obrigatório</label>
      <button class="ib" title="Remover" onclick="tirarI(${q})">${ic('lixo')}</button></div>${extra}</div>`
}
/** Uma área da multa por modelo: marcar uma desmarca as outras. */
function marcarArea(eid, iid, on) {
  D().etapas.forEach(e => e.itens.forEach(i => { if (i.papel === 'area') i.papel = '' }))
  if (on) acharE(eid).itens.find(i => i.id === iid).papel = 'area'
  mexeu(); renderEditor()
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

function tirarAnexo(id) { D().anexos = D().anexos.filter(a => a.id !== id); D().etapas.forEach(e => { if (e.anexo === id) e.anexo = '' }); mexeu(); renderEditor() }

// ══════════════ EXECUÇÃO ══════════════
let R = null
async function abrirVistoria(id) {
  const fim = cobrir('Abrindo a vistoria…')
  try {
    const v = await api('GET', '/' + id)
    const def = v.definicao
    Object.assign(ART, v.artigos || {})
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
  $('#ficha')?.remove()
  R = null; mapaMarcar = null
  $('#exec').hidden = true; document.body.style.overflow = ''
  $('#pdf-slot').innerHTML = ''
  if (EMB) { avisarMapa('fechar'); return }
  carregarVistorias()
}
window.addEventListener('resize', () => { if (R && !$('#exec').hidden) { if (!largo() && R.pdf.modo === 'lado') R.pdf.modo = 'janela'; renderTrilho(); renderPdf() } })

function renderExec() { renderCab(); renderCorpo(); renderRodape(); renderTrilho(); renderPdf() }
const etapa = () => R.def.etapas[R.i]
const anexosV = () => R.v.arquivos.filter(a => a.papel === 'anexo')
const fotosEtapa = eid => R.v.arquivos.filter(a => a.papel === 'foto' && a.etapa === eid && !a.item && !a.livre_id)
const fotoItem = iid => R.v.arquivos.find(a => a.papel === 'foto' && a.item === iid)
const fotosLivre = lid => R.v.arquivos.filter(a => a.papel === 'foto' && a.livre_id === lid)
const podeEditar = () => EU.edita && R.v.relacionado
/** Só quem acrescentou remove; o que é anterior à conclusão não sai mais. */
const removivel = x => podeEditar() && x.meu && (R.v.estado !== 'concluida' || x.depois)

function pendencias(et) {
  const f = []
  et.itens.forEach(it => f.push(...pendItem(it)))
  const nf = fotosEtapa(et.id).length
  if (et.foto === 'obrigatoria' && nf < et.foto_min) f.push(`${et.foto_min - nf} foto${et.foto_min - nf > 1 ? 's' : ''} da etapa`)
  return f
}
/** O que falta num item — a mesma regra de VgItem::pendencias (servidor). */
function pendItem(it) {
  const r = R.resp[it.id] || {}, t = tipoI(it), f = []
  const tem = r.v !== null && r.v !== undefined && r.v !== ''
  if (it.obrig && !tem) f.push(it.rotulo)
  if (t === 'confere' && r.v === 'nao') {
    if (!r.obs?.trim()) f.push('descrição: ' + it.rotulo)
    if (R.def.nao_foto && !fotoItem(it.id)) f.push('foto: ' + it.rotulo)
    if (r.exig?.trim() && !r.prazo) f.push('prazo da exigência: ' + it.rotulo)
  }
  if (t === 'numero' && tem && it.metodo && !r.m) f.push('como foi medido: ' + it.rotulo)
  return f
}
const nNao = et => et.itens.filter(it => tipoI(it) === 'confere' && R.resp[it.id]?.v === 'nao').length
function estadoEtapa(k) {
  if (k === R.i && R.fase === 'etapa') return 'at'
  if (k >= R.max && !['final', 'fim', 'complemento'].includes(R.fase)) return ''
  const et = R.def.etapas[k]; if (pendencias(et).length) return ''
  return nNao(et) ? 'dv' : 'ok'
}

function renderCab() {
  const v = R.v, def = R.def
  const pdfBt = (anexosV().length && R.fase !== 'portao' ? `<button class="btn-pdf" onclick="abrirPdf()">${ic('doc')}Anexos</button>` : '')
    + `<button class="btn-pdf" onclick="abrirFicha()">${ic('nota')}Ficha</button>`
  let sub = `${v.numero} · ${v.modelo} v${v.versao}`, tit = nomeV(v)
  if (R.fase === 'etapa') { sub = `${v.numero} · etapa ${R.i + 1} de ${def.etapas.length}`; tit = etapa().titulo }
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
  return `<div class="cartao"><div class="rot">${esc(nomeV(R.v))}</div>
      ${R.v.vinculos.map(l => vinculoChip(l)).join('')}
      <div style="font-size:12.5px;color:var(--tx2)">${an.length ? `${an.length} anexo(s): ${esc(an.map(a => a.nome).join(', '))}` : 'Sem anexos'}</div>
      ${podeEditar() ? '' : `<div class="instr">${ic('info')}<span>Você não está entre os fiscais desta vistoria: pode ver e imprimir, mas não alterar.</span></div>`}</div>
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
    ${apagavel(f) && removivel(f) ? `<button title="Remover" onclick="apagarFoto(${f.id})">${ic('x')}</button>` : ''}</div>`).join('')
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
  const r = R.resp[it.id] || {}, f = fotoItem(it.id), t = tipoI(it), ed = podeEditar(), q = `'${it.id}'`
  const ro = ed ? '' : 'readonly', dis = ed ? '' : 'disabled'
  const tem = r.v !== null && r.v !== undefined && r.v !== ''
  let campo = '', extra = ''
  if (t === 'confere') {
    campo = `<div class="ops">${RESP.map(([v, n]) => `<button class="op ${r.v === v ? 'on ' + v : ''}" ${dis} onclick="responder(${q},'${v}')">${n}</button>`).join('')}</div>`
    if (r.v === 'nao') extra = `<textarea class="in" id="ob-${it.id}" rows="2" maxlength="2000" placeholder="O que não confere?" oninput="anotar(${q},'obs',this.value)" ${ro}>${esc(r.obs || '')}</textarea>`
      + (R.def.nao_foto ? `<div class="fotos">${f ? fotosHtml([f], () => true)
        : ed ? `<button class="add-foto falta" ${R.enviando ? 'disabled' : ''} onclick="fotografar({etapa:'${etapa().id}',item:${q}})">${ic('camera')}Foto do problema</button>` : ''}</div>` : '')
      + artigosHtml(it, r)
      + `<div class="exig"><div class="arts-cab">Exigência <small>(opcional)</small></div><textarea class="in" id="ex-${it.id}" rows="2" maxlength="2000" placeholder="O que o responsável deve fazer" oninput="anotar(${q},'exig',this.value)" ${ro}>${esc(r.exig || '')}</textarea>
          <label class="mini">Prazo <input class="in" id="pz-${it.id}" type="number" min="1" max="365" inputmode="numeric" style="width:76px" value="${r.prazo || ''}" ${ro}
            onchange="anotar(${q},'prazo',this.value?Math.min(365,Math.max(1,+this.value)):null);this.value=R.resp[${q}]?.prazo||''"> dias</label></div>`
  } else if (t === 'numero') {
    campo = `<div class="val-num"><input class="in" id="nv-${it.id}" inputmode="decimal" placeholder="0,00" value="${esc(fmtNum(r.v))}" ${ro}
        onchange="gravarNum(${q},this)">${it.unidade ? `<span class="un">${esc(it.unidade)}</span>` : ''}</div>`
      + (it.metodo ? `<div class="ops livre">${Object.entries(METODOS).map(([k, n]) => `<button class="op ${r.m === k ? 'on' : ''}" ${dis} onclick="anotar(${q},'m',R.resp[${q}]?.m==='${k}'?null:'${k}',true)">${n}</button>`).join('')}</div>` : '')
  } else if (t === 'opcoes') {
    campo = `<div class="ops livre">${(it.opcoes || []).map(o => `<button class="op ${r.v === o ? 'on' : ''}" data-v="${esc(o)}" ${dis} onclick="responder(${q},this.dataset.v)">${esc(o)}</button>`).join('')}</div>`
  } else if (t === 'data') {
    campo = `<input class="in" type="date" id="dt-${it.id}" value="${esc(r.v || '')}" ${ro} onchange="anotar(${q},'v',this.value||null,true)">`
  } else {
    campo = `<textarea class="in" id="tx-${it.id}" rows="2" maxlength="2000" ${ro} oninput="anotar(${q},'v',this.value)">${esc(r.v || '')}</textarea>`
  }
  const falta = (it.obrig && !tem) || (t === 'numero' && tem && it.metodo && !r.m)
  return `<div class="cartao ${falta ? 'falta' : ''}"><div class="rot"><span>${esc(it.rotulo)}</span>
      ${it.papel === 'area' ? '<span class="tag-o opc" title="Usada no cálculo da multa por m²">área da multa</span>' : ''}${it.obrig ? '' : '<span class="tag-o opc">Opcional</span>'}</div>
    ${campo}${extra}</div>`
}
function responder(id, v) { const r = R.resp[id] || {}; R.resp[id] = { ...r, v: r.v === v ? null : v }; agendarRespostas(); renderCorpo(); renderRodape(); renderTrilho() }
/** Muda um campo da resposta; `refazer` redesenha o item (botões que acendem). */
function anotar(id, k, val, refazer) {
  R.resp[id] = { ...(R.resp[id] || {}), [k]: val }; agendarRespostas()
  if (refazer) { renderCorpo(); renderTrilho() }
  renderRodape()
}
function gravarNum(id, el) {
  const n = lerNum(el.value)
  if (Number.isNaN(n)) { toast('Número inválido. Use, por exemplo, 88,50.', true); el.value = fmtNum(R.resp[id]?.v); return }
  anotar(id, 'v', n, true)
}

// ── artigos citados no "não confere" ──
// A busca é a mesma da vistoria antiga (/api/artigos/busca): procura o
// PROBLEMA, não o número, nas leis do módulo ativo.
const ART = {}            // id → { numero, rotulo, lei, conduta, por_m2 }
const BA = {}             // item → { q, res, carregando }
function artigosHtml(it, r) {
  const b = BA[it.id] || (BA[it.id] = { q: '', res: [] }), ed = podeEditar()
  const chips = (r.art || []).map(id => { const a = ART[id] || { numero: '#' + id }
    return `<span class="art-chip" title="${esc(a.conduta || '')}">${esc(a.numero)}${a.lei ? ` · ${esc(a.lei)}` : ''}${a.por_m2 ? ' · por m²' : ''}
      ${ed ? `<button class="ib" title="Tirar" onclick="tirarArtigo('${it.id}',${id})">${ic('x')}</button>` : ''}</span>` }).join('')
  return `<div class="arts"><div class="arts-cab">Enquadramento <small>(opcional)</small></div>${chips}
    ${ed && (r.art || []).length < 10 ? `<input class="in" id="ba-${it.id}" placeholder="Procurar artigo pelo problema (ex.: recuo, calçada)" value="${esc(b.q)}" autocomplete="off" oninput="buscarArtigo('${it.id}',this.value)">
      <div class="bv-res" id="ba-res-${it.id}">${artResHtml(it.id)}</div>` : ''}</div>`
}
function artResHtml(iid) {
  const b = BA[iid]; if (!b || b.q.trim().length < 2) return ''
  if (b.carregando) return '<div class="bv-nada">Procurando…</div>'
  if (!b.res.length) return '<div class="bv-nada">Nenhum artigo encontrado.</div>'
  return b.res.map((a, k) => `<button onclick="citarArtigo('${iid}',${k})">${ic('mais')}<span><b>${esc(a.numero)}</b>${a.lei ? ` · ${esc(a.lei)}` : ''}${a.conduta ? ` — ${esc(a.conduta)}` : ''}</span></button>`).join('')
}
let tArt = null
function buscarArtigo(iid, q) {
  const b = BA[iid]; b.q = q; clearTimeout(tArt)
  const el = () => $('#ba-res-' + iid)
  if (q.trim().length < 2) { b.res = []; if (el()) el().innerHTML = ''; return }
  tArt = setTimeout(async () => {
    b.carregando = true; if (el()) el().innerHTML = artResHtml(iid)
    const vez = b.vez = (b.vez || 0) + 1
    try {
      const r = await fetch('/api/artigos/busca?q=' + encodeURIComponent(q.trim()), { headers: { Accept: 'application/json' } })
      if (!r.ok) throw new Error('A busca de artigos falhou (' + r.status + ').')
      const d = await r.json(); if (vez === b.vez) b.res = d.artigos || []
    } catch (e) { b.res = []; toast(e.message, true) }
    b.carregando = false; if (el()) el().innerHTML = artResHtml(iid)
  }, 300)
}
function citarArtigo(iid, k) {
  const b = BA[iid], a = b.res[k]; if (!a) return
  ART[a.id] = a
  const r = R.resp[iid] || {}, lista = [...(r.art || [])]
  if (!lista.includes(a.id)) lista.push(a.id)
  b.q = ''; b.res = []
  anotar(iid, 'art', lista, true)
}
function tirarArtigo(iid, id) { anotar(iid, 'art', (R.resp[iid]?.art || []).filter(x => x !== id), true) }

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
  try { await api('DELETE', '/arquivos/' + id); R.v.arquivos = R.v.arquivos.filter(a => a.id !== id); renderCorpo(); renderRodape(); renderTrilho() } catch (e) { toast(e.message, true) }
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
      ${removivel(l) ? `<button class="ib" title="Remover item" onclick="apagarLivre(${l.id})">${ic('lixo')}</button>` : ''}</div>
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
    // Anexo ou vínculo exigido pode ter sido removido pela ficha depois de criada.
    const g = [...R.def.anexos.filter(a => a.obrig && !anexosV().some(x => x.anexo === a.id)).map(a => 'anexo ' + a.nome),
      ...(R.def.vinculos || []).filter(t => !R.v.vinculos.some(l => l.tipo === t)).map(t => 'vínculo com ' + TIPOS[t].toLowerCase())]
    if (!R.conclusao) g.push('escolher a conclusão')
    msg = !g.length ? `<div class="falta-lista ok">${ic('ok')}Pronta para concluir.</div>` : `<div class="falta-lista">${ic('alerta')}Falta: ${esc(g.join(' · '))}</div>`
    bts = `<button class="btn" ${R.def.etapas.length ? '' : 'disabled'} onclick="irEtapa(${R.def.etapas.length - 1})">${ic('esq')}Voltar</button>
      <button class="btn pri" ${!g.length && podeEditar() ? '' : 'disabled'} onclick="concluir()">Concluir vistoria</button>`
  } else if (R.fase === 'complemento') {
    bts = `<button class="btn pri" onclick="R.novoLivre=null;R.fase='fim';renderExec()">${ic('ok')}Terminar complemento</button>`
  } else {
    bts = `<button class="btn" onclick="fecharVistoria()">${ic(EMB ? 'x' : 'esq')}${EMB ? 'Fechar' : 'Lista'}</button>`
      + `<a class="btn" href="${R.v.relatorio}" target="_blank" rel="noopener">${ic('doc')}Relatório</a>`
      // A peça nasce no mapa, presa a esta vistoria: artigos citados, área e
      // exigências já vêm preenchidos (DocumentoController::sugestaoGuiada).
      + (EU.lavra ? (EMB ? `<button class="btn" onclick="avisarMapa('doc',{id:${R.v.id}})">${ic('nota')}Notificação ou auto</button>`
        : `<a class="btn" href="/?vg_doc=${R.v.id}">${ic('nota')}Notificação ou auto</a>`) : '')
      + (podeEditar() ?`<button class="btn pri" onclick="R.fase='complemento';renderExec()">${ic('mais')}Complementar</button>` : '')
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
    avisarMapa('mudou')
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
    ${assinaturasHtml()}
    ${def.etapas.length ? `<div class="cartao"><div class="cab-sec">Etapas</div><table class="tab-res"><tbody>${linhas}</tbody></table></div>` : ''}
    ${v.livres.length ? livresHtml('fim') : ''}`
}
function assinaturasHtml() {
  const v = R.v, eu = v.fiscais.find(f => f.eu)
  const falta = v.fiscais.filter(f => !f.assinada_em).length
  return `<div class="cartao"><div class="cab-sec"><span>Relatório e assinaturas</span><span class="tag-o ${falta ? '' : 'opc'}">${falta ? `${falta} sem assinar` : 'todos assinaram'}</span></div>
    ${v.fiscais.map(f => `<div class="assina-l">${ic(f.assinada_em ? 'ok' : 'nota')}<span><b>${esc(f.nome)}</b>${f.matricula ? ` · ${esc(f.matricula)}` : ''}<small>${f.assinada_em ? 'assinou em ' + esc(f.assinada_em) : 'ainda não assinou'}</small></span></div>`).join('')}
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn" href="${v.relatorio}" target="_blank" rel="noopener">${ic('doc')}Ver relatório</a>
      <a class="btn" href="${v.relatorio_pdf}" target="_blank" rel="noopener">${ic('doc')}PDF</a>
      ${eu && !eu.assinada_em && podeEditar() ? (EU.tem_assinatura ? `<button class="btn pri" onclick="assinar()">${ic('ok')}Assinar</button>`
        : `<span class="falta-lista">${ic('alerta')}Cadastre sua assinatura no perfil para assinar.</span>`) : ''}</div></div>`
}
async function assinar() {
  try { const d = await api('POST', `/${R.v.id}/assinar`); R.v.fiscais = d.fiscais; toast(d.message); renderCorpo() } catch (e) { toast(e.message, true) }
}

// ══════════════ FICHA: vínculos, fiscais, anexos e descrição ══════════════
// Abre por cima da execução em qualquer fase. Só os fiscais relacionados
// alteram; os outros veem.
function abrirFicha() {
  let f = $('#ficha')
  if (!f) { f = document.createElement('div'); f.id = 'ficha'; f.className = 'ficha-fundo'; f.onclick = ev => { if (ev.target === f) fecharFicha() }; document.body.appendChild(f) }
  carregarAgentes().then(renderFicha); renderFicha()
}
function fecharFicha() { $('#ficha')?.remove(); if (R) renderExec() }
function renderFicha() {
  const f = $('#ficha'); if (!f || !R) return
  const v = R.v, def = R.def, ed = podeEditar(), concl = v.estado === 'concluida'
  const agentes = (VG.agentes || []).filter(a => !v.fiscais.some(x => x.id === a.id))
  const slot = a => {
    const arq = anexosV().find(x => x.anexo === a.id)
    return `<div class="anexo-slot ${arq ? 'tem' : a.obrig ? 'falta' : ''}">${ic('doc')}<div class="info"><b>${esc(a.nome)} <span class="tag-o ${a.obrig ? '' : 'opc'}">${a.obrig ? 'Obrigatório' : 'Opcional'}</span></b>
      <small>${arq ? esc(arq.nome_original || '') + ' · ' + kb(arq.tamanho) : 'Nenhum arquivo'}</small></div>
      ${arq ? (removivel(arq) ? `<button class="btn sm" onclick="tirarAnexo2(${arq.id})">${ic('lixo')}Remover</button>` : '')
        : ed ? `<button class="btn sm pri" onclick="escolherPdf('ficha:${a.id}')">${ic('mais')}Anexar</button>` : ''}</div>`
  }
  const extras = anexosV().filter(x => !x.anexo)
  f.innerHTML = `<div class="ficha" role="dialog" aria-label="Ficha da vistoria">
    <div class="ficha-cab"><div><small>${esc(v.numero)} · ${esc(MODULOS[v.modulo] || v.modulo)}</small><b>${esc(v.modelo)}</b></div>
      <button class="ib" title="Fechar" onclick="fecharFicha()">${ic('x')}</button></div>
    <div class="ficha-corpo">
      <section><h3>Ligada a</h3>
        ${v.vinculos.length ? v.vinculos.map(l => vinculoChip(l, ed && (!concl || l.depois) ? `desligar(${l.id})` : '')).join('') : '<p class="nada">Nenhum vínculo.</p>'}
        ${(def.vinculos || []).filter(t => !v.vinculos.some(l => l.tipo === t)).map(t => `<div class="falta-lista">${ic('alerta')}O modelo exige: ${TIPOS[t].toLowerCase()}</div>`).join('')}
        ${ed ? buscaVinculoHtml('ficha') : ''}</section>
      <section><h3>Local</h3>
        ${ed && !concl ? `<input class="in" maxlength="255" value="${esc(v.local || '')}" placeholder="Rua, número, ponto de referência" onchange="salvarCampo('local',this.value)">` : `<p>${esc(v.local || '—')}</p>`}</section>
      <section><h3>Descrição</h3>
        ${ed && !concl ? `<textarea class="in" rows="4" maxlength="4000" placeholder="Motivo da vistoria, o que foi pedido…" onchange="salvarCampo('descricao',this.value)">${esc(v.descricao || '')}</textarea>`
          : `<p>${esc(v.descricao || '—')}</p>`}</section>
      <section><h3>Fiscais</h3>
        ${v.fiscais.map(x => `<div class="assina-l">${ic(x.assinada_em ? 'ok' : 'nota')}<span><b>${esc(x.nome)}</b>${x.eu ? ' <small style="display:inline">(você)</small>' : ''}<small>${x.assinada_em ? 'assinou em ' + esc(x.assinada_em) : 'sem assinatura'}</small></span>
          ${ed && !x.assinada_em && v.fiscais.length > 1 ? `<button class="ib" title="Tirar da vistoria" onclick="desrelacionar(${x.id})">${ic('x')}</button>` : ''}</div>`).join('')}
        ${ed && agentes.length ? `<select class="in" aria-label="Acrescentar fiscal" onchange="if(this.value)relacionar(+this.value)"><option value="">+ Acrescentar fiscal</option>
          ${agentes.map(a => `<option value="${a.id}">${esc(a.nome)}</option>`).join('')}</select>` : ''}</section>
      <section><h3>Anexos</h3>
        ${def.anexos.map(slot).join('')}
        ${extras.map(arq => `<div class="anexo-slot tem">${ic('doc')}<div class="info"><b>${esc(arq.nome)}</b><small>${kb(arq.tamanho)}${arq.depois ? ' · depois de concluída' : ''}</small></div>
          ${removivel(arq) ? `<button class="btn sm" onclick="tirarAnexo2(${arq.id})">${ic('lixo')}Remover</button>` : ''}</div>`).join('')}
        ${ed ? `<div><button class="btn sm" onclick="escolherPdf('ficha:')">${ic('mais')}Outro PDF</button></div>` : ''}
        <p class="nada">Só quem anexou pode remover.</p></section>
      <section><h3>Relatório</h3><div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn sm" href="${v.relatorio}" target="_blank" rel="noopener">${ic('doc')}Ver relatório</a>
        <a class="btn sm" href="${v.relatorio_pdf}" target="_blank" rel="noopener">${ic('doc')}PDF</a></div></section>
    </div></div>`
}
async function salvarCampo(k, val) {
  try { await api('PUT', `/${R.v.id}/andamento`, { [k]: val }); R.v[k] = val.trim() || null; renderCab() } catch (e) { toast(e.message, true) }
}
async function desligar(id) {
  try { await api('DELETE', '/vinculos/' + id); R.v.vinculos = R.v.vinculos.filter(l => l.id !== id); renderFicha() } catch (e) { toast(e.message, true) }
}
async function relacionar(uid) {
  try { R.v.fiscais = (await api('POST', `/${R.v.id}/fiscais`, { user_id: uid })).fiscais; renderFicha() } catch (e) { toast(e.message, true) }
}
async function desrelacionar(uid) {
  try {
    R.v.fiscais = (await api('DELETE', `/${R.v.id}/fiscais/${uid}`)).fiscais
    if (uid === EU.id) { R.v.relacionado = false; toast('Você saiu desta vistoria.') }
    renderFicha()
  } catch (e) { toast(e.message, true) }
}
async function anexarNaFicha(chave, arquivo) {
  const fd = new FormData(); fd.append('arquivo', arquivo); if (chave) fd.append('chave', chave)
  const fim = cobrir('Enviando o PDF…')
  try { R.v.arquivos.push(await api('POST', `/${R.v.id}/anexos`, fd)); renderFicha() } catch (e) { toast(e.message, true) } finally { fim() }
}
async function tirarAnexo2(id) {
  try { await api('DELETE', '/arquivos/' + id); R.v.arquivos = R.v.arquivos.filter(a => a.id !== id); PDF_DOCS.delete(id); if (R.pdf.anexo === id) R.pdf.anexo = anexosV()[0]?.id; renderFicha() }
  catch (e) { toast(e.message, true) }
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

// ══════════════ ABERTURA PELO ENDEREÇO ══════════════
// O mapa, a Documentos, o protocolo e a sinalização abrem esta página já
// dizendo o que fazer:
//   ?v=12                         abre a vistoria 12
//   ?nova=1&imovel=5&protocolo=3  nova vistoria, já ligada ao imóvel e ao protocolo
//   &denuncia=… &pessoa=…         idem, denúncia e pessoa
//   &sinalizacao=8&local=texto    a sinalização que ela resolve, e o local (a de rua não tem imóvel)
// O endereço é limpo em seguida, para o "recarregar" não criar de novo.
async function abrirPeloEndereco() {
  const p = new URLSearchParams(location.search)
  if ((p.has('v') || p.has('nova')) && !EMB) history.replaceState(null, '', location.pathname)
  // Parâmetros › Vistorias: só o montador de modelos.
  if (p.has('modelos') && EU.admin) return irAba('modelos')
  if (p.has('v')) { if (!EMB) irAba('vistorias'); return abrirVistoria(+p.get('v')) }
  if (p.has('nova') && EU.edita) {
    NV = novaVazia()
    NV.local = p.get('local') || ''
    NV.sinalizacao = +p.get('sinalizacao') || null
    for (const tipo of Object.keys(TIPOS)) {
      const id = +p.get(tipo); if (!id) continue
      try { const r = await api('GET', `/vinculos/rotulo?tipo=${tipo}&id=${id}`); NV.vinculos.push({ tipo, id, rotulo: r.rotulo }) }
      catch (e) { toast(e.message, true) }
    }
    return irAba('nova')
  }
  if (EMB) { avisarMapa('fechar'); return }
  irAba('vistorias')
}
abrirPeloEndereco()
