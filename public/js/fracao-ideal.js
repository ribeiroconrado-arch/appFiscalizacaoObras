/**
 * FRAÇÃO IDEAL no mapa e na ficha — o terreno em condomínio e as unidades.
 *
 * O terreno continua sendo um lote ativo; as unidades são lotes ligados a ele
 * (`terreno_id`), cada uma com a sua inscrição (variação 001, 002…) e o SEU
 * proprietário, lido do cadastro municipal (App\Cadastro\CadastroCarregado).
 *
 *   mapa    a unidade com área desenhada aparece por cima do terreno, com a
 *           divisa tracejada: clicar dentro dela escolhe a unidade; clicar na
 *           área comum escolhe o terreno. O rótulo do terreno diz "20 · 2 un.".
 *           Apartamento não tem desenho, e só aparece na lista do terreno.
 *   balão   o do terreno lista as unidades e os donos; o da unidade mostra a
 *           fração, o dono e o caminho de volta ao terreno.
 *   ficha   aba "Unidades": no terreno, a tabela das unidades; na unidade, o
 *           terreno e as outras unidades.
 *
 * Tudo vem de GET /api/imoveis/{lote}/unidades (UnidadesDoTerrenoController).
 */

const fracaoState = {
  /** @type {Map<number, Promise<Object|null>>} resposta por terreno */
  cache: new Map(),
  /** Propriedades do terreno, para montar a ficha de uma unidade que não está no mapa. */
  terrenos: new Map(),
}

/** O lote é do assunto — terreno em condomínio ou unidade? */
function ehFracaoIdeal(p) {
  return !!(p && (p.condominio || p.terreno_id))
}

/** As unidades do terreno deste lote (o próprio, ou o da unidade). Cache por terreno. */
function carregarUnidades(p, forcar = false) {
  const terreno = Number(p.terreno_id || p.id)
  if (forcar) fracaoState.cache.delete(terreno)
  if (!fracaoState.cache.has(terreno)) {
    fracaoState.cache.set(terreno, fetch(`/api/imoveis/${Number(p.id)}/unidades`, { headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : null).catch(() => null))
  }
  return fracaoState.cache.get(terreno)
}

/** O que se escreve sobre o lote no mapa. */
function rotuloDoLoteNoMapa(p) {
  if (!p?.numero_lote) return null
  if (p.terreno_id) return p.unidade ? 'un. ' + p.unidade : String(p.numero_lote)
  if (p.condominio && p.unidades) return `${p.numero_lote} · ${p.unidades} un.`
  return String(p.numero_lote)
}

/** Os donos, numa linha: nomes, e o CPF mascarado quando é só isso que se vê. */
function donosEmTexto(lista) {
  if (lista === null || lista === undefined) return '—'
  if (!lista.length) return 'não informado no cadastro'
  return lista.map(d => d.nome + (d.documento_mascarado ? ` (${d.documento_mascarado})` : '')).join('; ')
}

const _m2 = n => n === null || n === undefined ? '—' : Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' m²'

// ── balão ───────────────────────────────────────────────────────

/** O pedaço do balão que é da fração ideal (vazio para o lote comum). */
function htmlFracaoNoBalao(p) {
  if (!ehFracaoIdeal(p)) return ''
  if (p.condominio) {
    fracaoState.terrenos.set(Number(p.id), p)
    return `<div class="fr-balao"><span class="fr-tag">Condomínio · ${Number(p.unidades || 0)} unidade(s)</span>
      <div class="fr-balao-lista" id="fr-balao-lista">Carregando unidades…</div></div>`
  }
  return `<div class="fr-balao"><span class="fr-tag fr-tag-un">Unidade ${esc(p.unidade || '')}</span>
    <table class="balao4-tab"><tr><td>Fração ideal</td><td><b>${_m2(p.fracao_ideal_m2)}</b></td></tr>
      <tr><td>Proprietário</td><td id="fr-balao-dono">…</td></tr></table>
    <button type="button" class="btn sm fr-btn-terreno" onclick="irAoTerrenoDaUnidade(${Number(p.terreno_id)})">↑ Terreno</button></div>`
}

/** Preenche o balão depois que as unidades chegam. */
async function preencherFracaoNoBalao(p) {
  if (!ehFracaoIdeal(p)) return
  const d = await carregarUnidades(p)
  if (!d) return
  if (p.condominio) {
    const alvo = document.getElementById('fr-balao-lista')
    if (!alvo) return
    alvo.innerHTML = d.unidades.length ? d.unidades.map(u => `
      <button type="button" class="fr-item" onclick="abrirUnidade(${u.id}, ${Number(p.id)})">
        <b>Unidade ${esc(u.unidade)}</b><small>${esc(donosEmTexto(u.proprietarios))}</small>
        <span>${_m2(u.fracao_ideal_m2)}</span></button>`).join('') : '<small>Nenhuma unidade ativa.</small>'
  } else {
    const u = d.unidades.find(x => x.id === Number(p.id))
    const el = document.getElementById('fr-balao-dono')
    if (el && u) el.textContent = donosEmTexto(u.proprietarios)
  }
}

// ── navegação ───────────────────────────────────────────────────

/** Abre a ficha de uma unidade — esteja ela no mapa ou não (apartamento). */
async function abrirUnidade(id, terrenoId) {
  mapaState.obj?.closePopup()
  const f = state.lotes?.get(id)
  if (f) { abrirFicha(f); subFicha('unidades'); return }
  const t = fracaoState.terrenos.get(Number(terrenoId)) || state.lotes?.get(Number(terrenoId))?.properties || {}
  const d = await carregarUnidades({ id: terrenoId })
  const u = d?.unidades.find(x => x.id === id)
  if (!u) { toast('A unidade não foi encontrada.', 'aviso'); return }
  abrirFicha({ geometry: null, properties: {
    ...t, id: u.id, numero_lote: u.numero_lote, inscricao: u.inscricao, terreno_id: Number(terrenoId),
    unidade: u.unidade, fracao_ideal_m2: u.fracao_ideal_m2, area_m2: u.fracao_ideal_m2, condominio: null, unidades: null,
    tag_origem: 'UNIDADE',
  } })
  subFicha('unidades')
}

/** Da unidade para o terreno: no mapa, se estiver carregado; senão, a ficha. */
function irAoTerrenoDaUnidade(terrenoId) {
  mapaState.obj?.closePopup()
  const f = state.lotes?.get(Number(terrenoId))
  if (f) { abrirFicha(f); subFicha('unidades'); return }
  fetch(`/api/imoveis/${Number(terrenoId)}`, { headers: { Accept: 'application/json' } })
    .then(r => r.ok ? r.json() : null)
    .then(l => { if (l) { abrirFichaPorLote({ ...l, numero_lote: l.lote, area_m2: l.area, condominio: 'casas' }); subFicha('unidades') } })
}

// ── ficha: aba Unidades ─────────────────────────────────────────

/** A aba só existe para terreno em condomínio e para unidade. */
function prepararAbaUnidades(p) {
  const b = document.querySelector('#m-ficha .sub-abas [data-fi="unidades"]')
  if (b) b.hidden = !ehFracaoIdeal(p)
  if (ehFracaoIdeal(p) && p.condominio) fracaoState.terrenos.set(Number(p.id), p)
}

async function carregarAbaUnidades(p) {
  const alvo = document.getElementById('fi-unidades-corpo')
  if (!alvo || !p) return
  alvo.innerHTML = '<div class="vazio-msg">Carregando unidades…</div>'
  const d = await carregarUnidades(p)
  if (!d || !d.condominio) { alvo.innerHTML = '<div class="vazio-msg">Este lote não tem frações instituídas.</div>'; return }
  const t = d.terreno
  const ehUnidade = !!p.terreno_id
  const inst = d.instituido ? `Instituído em ${esc(d.instituido.em)} · ${d.instituido.protocolo ? 'protocolo ' + esc(d.instituido.protocolo) : 'ato direto'}` : ''
  const cab = ehUnidade
    ? `<div class="fr-migalha"><a href="#" onclick="event.preventDefault();irAoTerrenoDaUnidade(${t.id})">Q ${esc(t.quadra)} · Lote ${esc(t.numero_lote)} (terreno)</a>
         › Unidade ${esc(p.unidade || '')} de ${d.unidades.length}</div>`
    : `<div class="fr-migalha"><span class="fr-tag">${esc(d.condominio_rotulo)}</span> ${inst}</div>`
  const resumo = `<div class="fr-resumo">
      <div><span>Terreno</span><b>${_m2(t.area_matricula ?? t.area_desenho)}</b></div>
      <div><span>Soma das frações</span><b>${_m2(t.soma_fracoes)}</b></div>
      <div><span>Área comum</span><b>${_m2(t.area_comum)}</b></div>
      <div><span>Multa por área</span><b>${d.condominio === 'predio' ? 'obra toda' : 'área individual'}</b></div></div>`
  const linhas = d.unidades.map(u => `<tr class="${u.id === Number(p.id) ? 'fr-atual' : ''}">
      <td><b>${esc(u.unidade)}</b></td><td class="mono">${esc(u.inscricao || '—')}</td>
      <td>${esc(donosEmTexto(u.proprietarios))}</td>
      <td class="n">${_m2(u.fracao_ideal_m2)}</td><td class="n">${_m2(u.area_exclusiva)}</td>
      <td class="n">${u.documentos ? u.documentos + ' doc.' : ''}${u.vistorias ? ' ' + u.vistorias + ' vist.' : ''}</td>
      <td>${u.id === Number(p.id) ? '<small>esta</small>' : `<button type="button" class="btn sm" onclick="abrirUnidade(${u.id}, ${t.id})">Abrir</button>`}</td></tr>`).join('')
  alvo.innerHTML = `${cab}${resumo}
    <div class="fr-tabela"><table><thead><tr><th>Unid.</th><th>Inscrição</th><th>Proprietário</th><th class="n">Fração</th><th class="n">Exclusiva</th><th class="n">Processos</th><th></th></tr></thead>
    <tbody>${linhas}</tbody></table></div>
    ${ehUnidade ? '' : '<p class="fr-nota">O terreno não tem proprietário próprio: cada unidade tem o seu, lido do cadastro pela inscrição dela.</p>'}`
}

// ── documento: qual área vale para a multa ─────────────────────

/**
 * MULTA POR ÁREA NA FRAÇÃO IDEAL — decisão do setor (15/10/2026):
 *   prédio      vale a área da obra TODA;
 *   casas       vale a área INDIVIDUAL da unidade.
 * O formulário diz isso junto do campo "Área obra", e a memória de cálculo da
 * tela leva a mesma frase (fdState.fracaoBase).
 */
async function notaDaAreaNoDocumento(p) {
  const nota = document.getElementById('nd-area-fracao')
  if (typeof fdState !== 'undefined') fdState.fracaoBase = null
  if (!nota) return
  nota.hidden = true
  if (!ehFracaoIdeal(p)) return
  const d = await carregarUnidades(p)
  if (!d?.condominio) return
  const u = p.terreno_id ? d.unidades.find(x => x.id === Number(p.id)) : null
  let txt
  if (d.condominio === 'predio') {
    txt = `Prédio em fração ideal: a multa por área usa a área da obra TODA do prédio${u ? ` (esta é a unidade ${u.unidade}, fração de ${_m2(u.fracao_ideal_m2)})` : ''}.`
  } else if (u) {
    txt = `Unidade ${u.unidade} de condomínio de casas: a multa por área usa a área INDIVIDUAL desta unidade${u.area_exclusiva ? ` (área exclusiva de ${_m2(u.area_exclusiva)})` : ''}.`
  } else {
    txt = 'Terreno em condomínio de casas: a multa por área usa a área individual de cada unidade. Se a obra é de uma casa, lavre na unidade dela.'
  }
  nota.textContent = txt
  nota.hidden = false
  if (typeof fdState !== 'undefined') fdState.fracaoBase = txt
}

// As frações mudaram (ato executado ou desfeito): as respostas guardadas caducam.
document.addEventListener('lotes-alterados', () => fracaoState.cache.clear())
