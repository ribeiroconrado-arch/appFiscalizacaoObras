/**
 * PERÍMETRO URBANO — a camada do mapa e a atualização pela tela de Importações.
 *
 *   camada        o perímetro VIGENTE, tracejado roxo, sem preenchimento e sem
 *                 clique (não atrapalha o lote embaixo). Todos os usuários.
 *   Importações   (curador) o .geojson do conversor com `"tipo": "perimetro"`
 *                 é reconhecido ao ser solto e segue este fluxo, não o de
 *                 loteamento: conferência + lei → rascunho → publicação
 *                 (administrador). Ver App\Services\PerimetrosUrbanos.
 *
 * O que entra e o que sai em relação ao vigente aparece no mapa (verde e
 * vermelho) e na ficha, com os lotes que mudam de lado.
 */

const PER_COR = '#7C3AED'
const PER_BADGE = { rascunho: 'bd-tipo', vigente: 'bd-ok', anterior: 'bd-in' }

const perState = {
  /** A camada do vigente no mapa. */
  camada: null,
  /** O que "Ver no mapa" desenhou (rascunho/versão + vigente + entra/sai). */
  revisao: null,
  /** A ficha aberta. */
  atual: null,
}

const _perHa = m2 => (Number(m2 || 0) / 10000).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ha'
const _perData = iso => iso ? iso.split('-').reverse().join('/') : '—'

// ── CAMADA DO MAPA ──────────────────────────────────────────────

registrarCamada({
  id: 'perimetro', grupo: 'Outras', rotulo: 'Perímetro urbano',
  aoMudar: ligada => { ligada ? carregarPerimetroDoMapa() : perState.camada?.remove() },
})

function _perPane(mapa) {
  if (!mapa.getPane('perimetro')) {
    const p = mapa.createPane('perimetro')
    p.style.zIndex = 395            // abaixo dos lotes (overlayPane, 400)
    p.style.pointerEvents = 'none'
  }
  return 'perimetro'
}

/** Desenha (ou redesenha) o perímetro vigente. */
async function carregarPerimetroDoMapa() {
  const mapa = mapaState?.obj
  if (!mapa || !camadaLigada('perimetro')) return
  try {
    const r = await fetch('/api/mapa/perimetro', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    perState.camada?.remove()
    perState.camada = null
    if (!d.geometry || !camadaLigada('perimetro')) return
    perState.camada = L.geoJSON(d.geometry, {
      pane: _perPane(mapa), interactive: false,
      style: { color: PER_COR, weight: 2.5, opacity: 0.9, dashArray: '10 6', fill: false },
    }).addTo(mapa)
  } catch { /* sem perímetro: o mapa segue sem a camada */ }
}

// O mapa nasce depois deste arquivo: espera ele estar pronto.
document.addEventListener('DOMContentLoaded', () => {
  let tentativas = 0
  const t = setInterval(() => {
    if (mapaState?.obj?._loaded) { clearInterval(t); carregarPerimetroDoMapa() }
    else if (++tentativas > 60) clearInterval(t)
  }, 500)
})

// ── NA LISTA DE IMPORTAÇÕES ─────────────────────────────────────

/** O bloco do perímetro no alto da lista de Importações (chamado por importacoes.js). */
async function pintarPerimetrosNaLista() {
  const alvo = document.getElementById('imp-perimetros')
  if (!alvo) return
  try {
    const d = await _impPedir('/api/perimetros')
    const lista = d.perimetros || []
    const vig = lista.find(p => p.situacao === 'vigente')
    const rasc = lista.filter(p => p.situacao === 'rascunho')
    const ant = lista.filter(p => p.situacao === 'anterior')
    alvo.innerHTML = `
      <div class="per-bloco">
        <div class="per-cab">
          <span class="per-ico" aria-hidden="true"></span>
          <b>Perímetro urbano</b>
          <span class="imp-sub">${vig
            ? `${esc(vig.rotulo)} · vigente desde ${_perData(vig.vigente_desde)} · ${_perHa(vig.area_m2)}`
            : 'nenhum publicado — o mapa usa o retângulo de reserva'}</span>
          <span class="per-cab-acoes">
            ${vig ? `<button class="btn sm" onclick="abrirPerimetro(${vig.id})">Abrir</button>` : ''}
            ${ant.length ? `<button class="btn sm" onclick="const v=document.getElementById('per-versoes'); v.hidden=!v.hidden">Versões anteriores (${ant.length})</button>` : ''}
            ${vig ? `<button class="btn sm" onclick="abrirEditorMemorial({ base: ${vig.id} })" title="Os vértices da vigente já preenchidos: altere só o que a lei nova mudou">Nova versão a partir da vigente</button>` : ''}
            <button class="btn sm" onclick="abrirEditorMemorial({})" title="Colar o memorial descritivo da lei ou digitar os vértices">Pelo memorial</button>
          </span>
        </div>
        ${rasc.map(p => `
          <div class="per-linha" onclick="abrirPerimetro(${p.id})">
            <span class="badge ${PER_BADGE.rascunho}">Rascunho</span>
            <b>${esc(p.rotulo)}</b>
            <span class="imp-sub">${_perHa(p.area_m2)} · enviado por ${esc(p.enviado_por || '—')} em ${esc(p.enviado_em || '—')}</span>
            <button class="btn sm">Continuar</button>
          </div>`).join('')}
        <div id="per-versoes" hidden>
          ${ant.map(p => `
            <div class="per-linha" onclick="abrirPerimetro(${p.id})">
              <span class="badge ${PER_BADGE.anterior}">Anterior</span>
              <b>${esc(p.rotulo)}</b>
              <span class="imp-sub">${_perData(p.vigente_desde)} a ${_perData(p.vigente_ate)} · ${_perHa(p.area_m2)}</span>
              <button class="btn sm">Abrir</button>
            </div>`).join('')}
        </div>
      </div>`
  } catch (e) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">Perímetro urbano: ${esc(e.message)}</div>`
  }
}

/** O arquivo solto em "Nova importação" é do perímetro? (importacoes.js troca de fluxo) */
async function arquivoEhPerimetro(arq) {
  if (!arq || arq.size > 20 * 1024 * 1024) return false
  try {
    const gj = JSON.parse(await arq.text())
    return (gj.features || []).some(f => String(f?.properties?.tipo || '').trim().toLowerCase() === 'perimetro')
  } catch { return false }
}

// ── NOVO PERÍMETRO: arquivo → conferência + lei → rascunho ──────

const _perPassos = n => `<div class="imp-passos">${['1 Arquivo', '2 Conferência e lei', '3 Rascunho', '4 Publicação (administrador)']
  .map((p, i) => i + 1 === n ? `<b>${p}</b>` : p).join(' › ')}</div>`

function _perCamposDaLei(p = {}, editavel = true) {
  const dis = editavel ? '' : 'disabled'
  return `
    <div class="per-lei">
      <div class="field"><label for="per-lei-numero">Lei nº</label>
        <input id="per-lei-numero" maxlength="40" placeholder="ex.: 1.234/2026" value="${esc(p.lei_numero || '')}" ${dis}></div>
      <div class="field"><label for="per-lei-data">Data da lei</label>
        <input id="per-lei-data" type="date" value="${esc(p.lei_data || '')}" ${dis}></div>
      <div class="field"><label for="per-vigente-desde">Vigente desde</label>
        <input id="per-vigente-desde" type="date" value="${esc(p.vigente_desde || '')}" ${dis}></div>
      <div class="field per-obs"><label for="per-obs">Observação</label>
        <input id="per-obs" maxlength="2000" placeholder="opcional" value="${esc(p.observacao || '')}" ${dis}></div>
    </div>`
}

function _perLeiDoFormulario() {
  const v = id => document.getElementById(id)?.value.trim() || ''
  return { lei_numero: v('per-lei-numero'), lei_data: v('per-lei-data'), vigente_desde: v('per-vigente-desde'), observacao: v('per-obs') }
}

/** Chamado por importacoes.js quando o arquivo solto é do perímetro. */
async function abrirPerimetroNovo(arq) {
  perState.arquivo = arq
  _impCorpo(`
    <div class="jt">
      <div class="jt-topo">${_perPassos(2)}
        <p class="imp-expl">Arquivo do <b>perímetro urbano</b> reconhecido. Nada é gravado nesta etapa: confira a área e o
          que muda em relação ao perímetro vigente, informe a lei e carregue como rascunho.</p>
      </div>
      <div class="jt-rolagem jt-folga" id="per-corpo"><div class="vazio-msg">Lendo o arquivo…</div></div>
      <div class="jt-rodape">
        <span class="imp-sub">${esc(arq.name)}</span>
        <div class="btn-row">
          <button class="btn" onclick="carregarListaImportacoes()">Voltar à lista</button>
          <button class="btn primary" id="per-carregar" disabled onclick="carregarPerimetro()">Carregar como rascunho</button>
        </div>
      </div>
    </div>`, 'Novo perímetro urbano')
  const fd = new FormData()
  fd.append('arquivo', arq)
  const alvo = document.getElementById('per-corpo')
  try {
    const c = await _impPedir('/api/perimetros/conferir', { method: 'POST', body: fd })
    const difConversor = c.area_m2_arquivo && Math.abs(c.area_m2_arquivo - c.area_m2) > c.area_m2 * 0.001
    alvo.innerHTML = `
      <div class="per-kpis">
        <div class="imp-kpi"><small>Área</small><b>${_perHa(c.area_m2)}</b></div>
        <div class="imp-kpi"><small>Partes</small><b>${c.partes}</b></div>
        <div class="imp-kpi"><small>Furos (enclaves)</small><b>${c.furos}</b></div>
        <div class="imp-kpi"><small>Vértices</small><b>${Number(c.vertices).toLocaleString('pt-BR')}</b></div>
      </div>
      <p class="imp-sub">Origem: ${esc(c.fonte || c.arquivo)}${c.camada ? ` · camada ${esc(c.camada)}` : ''}</p>
      ${difConversor ? `<div class="cad-nota cad-aviso">O conversor mediu ${_perHa(c.area_m2_arquivo)}; o sistema mede ${_perHa(c.area_m2)}.
        Diferença acima de 0,1%: confira se o arquivo é o mesmo que saiu do conversor.</div>` : ''}
      <h4 class="per-tit">Lei</h4>
      ${_perCamposDaLei()}
      <p class="imp-expl">Lei e vigência podem ser completadas depois, no rascunho; para publicar são obrigatórias.</p>
      <h4 class="per-tit">O que muda</h4>
      ${_perHtmlComparacao(c.comparacao)}`
    document.getElementById('per-carregar').disabled = false
  } catch (e) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">${esc(e.message)}</div>`
  }
}

async function carregarPerimetro() {
  const fd = new FormData()
  fd.append('arquivo', perState.arquivo)
  for (const [k, v] of Object.entries(_perLeiDoFormulario())) if (v) fd.append(k, v)
  const b = document.getElementById('per-carregar')
  b.disabled = true
  try {
    const d = await _impPedir('/api/perimetros', { method: 'POST', body: fd })
    toast(d.message)
    await abrirPerimetro(d.id)
  } catch (e) {
    toast(e.message, 'err')
    b.disabled = false
  }
}

// ── A FICHA ────────────────────────────────────────────────────

async function abrirPerimetro(id) {
  if (!document.getElementById('m-importacoes')?.classList.contains('open') && typeof abrirImportacoes === 'function') {
    await abrirJanelaDoMapa()
    openModal('m-importacoes')
  }
  _impCorpo('<div class="vazio-msg">Carregando…</div>', 'Perímetro urbano')
  try {
    const p = perState.atual = await _impPedir('/api/perimetros/' + id)
    const rascunho = p.situacao === 'rascunho'
    _impCorpo(`
      <div class="jt">
        <div class="jt-topo">
          ${rascunho ? _perPassos(3) : ''}
          <div class="per-ficha-cab">
            <span class="badge ${PER_BADGE[p.situacao] || 'bd-in'}">${esc(p.situacao_rotulo)}</span>
            <b class="jt-forte">${esc(p.rotulo)}</b>
            <span class="imp-sub">${_perHa(p.area_m2)}</span>
          </div>
        </div>
        <div class="jt-rolagem jt-folga">
          <dl class="per-dados">
            <dt>Vigência</dt><dd>${p.situacao === 'rascunho' ? 'ainda não vale'
              : `${_perData(p.vigente_desde)} ${p.vigente_ate ? 'a ' + _perData(p.vigente_ate) : 'até hoje'}`}</dd>
            ${p.origem === 'memorial'
              ? `<dt>Origem</dt><dd>Memorial descritivo · ${Number(p.vertices_total || 0)} vértices (UTM 21S, SIRGAS 2000)</dd>`
              : `<dt>Arquivo</dt><dd>${esc(p.arquivo)}${p.fonte && p.fonte !== p.arquivo ? ` · DWG ${esc(p.fonte)}` : ''}${p.camada ? ` · camada ${esc(p.camada)}` : ''}</dd>`}
            <dt>Carregado</dt><dd>${esc(p.enviado_por || '—')} · ${esc(p.enviado_em || '—')}</dd>
            ${p.publicado_em ? `<dt>Publicado</dt><dd>${esc(p.publicado_em)}</dd>` : ''}
            ${!rascunho && p.observacao ? `<dt>Observação</dt><dd>${esc(p.observacao)}</dd>` : ''}
          </dl>
          ${p.conferencia_memorial ? `<h4 class="per-tit">Conferência do memorial</h4>${_perHtmlItens(p.conferencia_memorial.itens)}` : ''}
          ${rascunho ? `<h4 class="per-tit">Lei</h4>${_perCamposDaLei(p)}
            <div class="btn-row"><button class="btn sm" onclick="gravarLeiDoPerimetro()">Gravar dados da lei</button></div>` : ''}
          ${p.situacao !== 'anterior' ? `<h4 class="per-tit">O que muda${rascunho ? '' : ' (na publicação)'}</h4>${_perHtmlComparacao(p.comparacao)}` : ''}
          <div id="per-acao"></div>
        </div>
        <div class="jt-rodape">
          <span class="imp-sub">${rascunho ? (p.pode_publicar ? '' : 'Publicar: aguardando administrador') : ''}</span>
          <div class="btn-row">
            <button class="btn" onclick="carregarListaImportacoes()">Voltar à lista</button>
            <button class="btn" onclick="verPerimetroNoMapa(${p.id})">Ver no mapa</button>
            ${rascunho ? `<button class="btn" onclick="abrirEditorMemorial({ rascunho: ${p.id} })">${p.origem === 'memorial' ? 'Editar vértices' : 'Refazer pelo memorial'}</button>`
              : (p.vertices_total ? `<button class="btn" onclick="abrirEditorMemorial({ base: ${p.id} })">Nova versão a partir desta</button>` : '')}
            ${rascunho && (p.meu || p.pode_publicar) ? `<button class="btn" onclick="pedirDescartePerimetro()">Descartar</button>` : ''}
            ${rascunho && p.pode_publicar ? `<button class="btn primary" onclick="pedirPublicacaoPerimetro()">Publicar</button>` : ''}
          </div>
        </div>
      </div>`, 'Perímetro urbano')
  } catch (e) {
    _impCorpo(`<div class="cad-nota cad-erro">${esc(e.message)}</div>`, 'Perímetro urbano')
  }
}

async function gravarLeiDoPerimetro() {
  const p = perState.atual
  try {
    const d = await _impPedir('/api/perimetros/' + p.id, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(_perLeiDoFormulario()),
    })
    toast(d.message)
    await abrirPerimetro(p.id)
  } catch (e) { toast(e.message, 'err') }
}

/** Lotes e bairros: os primeiros à vista, o resto ao abrir. */
function _perListaDeLotes(titulo, l, classe) {
  if (!l?.total) return ''
  const linha = x => `<li>${esc(x.bairro)} · Q ${esc(x.quadra ?? '?')} · L ${esc(x.lote ?? '?')}</li>`
  const vistos = l.lista.slice(0, 8), resto = l.lista.slice(8)
  return `
    <div class="per-lotes ${classe}">
      <b>${Number(l.total).toLocaleString('pt-BR')} ${titulo}</b>
      <ul>${vistos.map(linha).join('')}</ul>
      ${resto.length ? `<details><summary>mais ${resto.length}${l.total > l.lista.length ? ` (de ${l.total - 8})` : ''}</summary>
        <ul>${resto.map(linha).join('')}</ul></details>` : ''}
    </div>`
}

function _perHtmlComparacao(c) {
  if (!c) return '<div class="vazio-msg">Sem comparação.</div>'
  const b = c.bairros || { cortados: [], fora: [] }
  return `
    ${c.vigente
      ? `<p class="imp-sub">Comparado com o vigente: ${esc(c.vigente.rotulo)} (${_perHa(c.vigente.area_m2)}) · ${esc(c.feita_em)}</p>
         <div class="per-kpis">
           <div class="imp-kpi" style="--kpi-cor:#1D9E75"><small><i></i>Entra</small><b>+ ${_perHa(c.area_entra_m2)}</b></div>
           <div class="imp-kpi" style="--kpi-cor:#E24B4A"><small><i></i>Sai</small><b>− ${_perHa(c.area_sai_m2)}</b></div>
           <div class="imp-kpi"><small>Lotes fora do novo</small><b>${Number(c.lotes_fora).toLocaleString('pt-BR')}</b></div>
         </div>`
      : `<p class="imp-sub">Primeira versão: não há perímetro vigente para comparar. Abaixo, os lotes do mapa que ficam fora dele.</p>`}
    ${_perListaDeLotes(c.vigente ? 'lote(s) passam a ficar FORA do perímetro' : 'lote(s) fora do perímetro', c.passam_fora, 'sai')}
    ${_perListaDeLotes('lote(s) passam a ficar DENTRO do perímetro', c.passam_dentro, 'entra')}
    ${!c.passam_fora?.total && !c.passam_dentro?.total ? '<div class="cad-nota imp-nota">Nenhum lote muda de lado.</div>' : ''}
    ${b.cortados.length ? `<div class="per-bairros"><b>Bairros cortados pela linha:</b> ${b.cortados.map(esc).join(', ')}</div>` : ''}
    ${b.fora.length ? `<div class="per-bairros"><b>Bairros inteiros fora:</b> ${b.fora.map(esc).join(', ')}</div>` : ''}`
}

// ── PUBLICAR / DESCARTAR ──────────────────────────────────────

function pedirPublicacaoPerimetro() {
  const p = perState.atual
  const alvo = document.getElementById('per-acao')
  const l = _perLeiDoFormulario()
  if (!l.lei_numero || !l.vigente_desde) {
    alvo.innerHTML = '<div class="cad-nota cad-aviso">Informe o número da lei e a data de início da vigência, e grave, antes de publicar.</div>'
    return
  }
  if (l.lei_numero !== (p.lei_numero || '') || l.vigente_desde !== (p.vigente_desde || '')) {
    alvo.innerHTML = '<div class="cad-nota cad-aviso">Os dados da lei foram alterados e não gravados. Clique em "Gravar dados da lei" primeiro.</div>'
    return
  }
  const vig = p.comparacao?.vigente
  alvo.innerHTML = `
    <div class="imp-publicar">
      <div class="cad-nota imp-nota">Ao publicar, o perímetro da <b>${esc(p.rotulo)}</b> passa a valer em ${_perData(p.vigente_desde)}${vig
        ? `, e o da <b>${esc(vig.rotulo)}</b> vai para o histórico, com vigência até a véspera` : ''}. Nenhuma versão é apagada.</div>
      <div class="btn-row">
        <button class="btn" onclick="document.getElementById('per-acao').innerHTML=''">Cancelar</button>
        <button class="btn primary" onclick="publicarPerimetro()">Publicar</button>
      </div>
    </div>`
  alvo.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

async function publicarPerimetro() {
  const p = perState.atual
  try {
    const d = await _impPedir(`/api/perimetros/${p.id}/publicar`, { method: 'POST' })
    toast(d.message)
    perState.camada?.remove(); perState.camada = null
    carregarPerimetroDoMapa()
    await abrirPerimetro(p.id)
  } catch (e) { toast(e.message, 'err') }
}

function pedirDescartePerimetro() {
  document.getElementById('per-acao').innerHTML = `
    <div class="imp-publicar">
      <div class="cad-nota cad-aviso">Descartar apaga este rascunho. O perímetro vigente não muda.</div>
      <div class="btn-row">
        <button class="btn" onclick="document.getElementById('per-acao').innerHTML=''">Cancelar</button>
        <button class="btn danger" onclick="descartarPerimetro()">Descartar rascunho</button>
      </div>
    </div>`
}

async function descartarPerimetro() {
  try {
    const d = await _impPedir(`/api/perimetros/${perState.atual.id}/descartar`, { method: 'POST' })
    toast(d.message)
    perState.atual = null
    carregarListaImportacoes()
  } catch (e) { toast(e.message, 'err') }
}

// ── VER NO MAPA ────────────────────────────────────────────────

async function verPerimetroNoMapa(id) {
  try {
    const d = await _impPedir(`/api/perimetros/${id}/mapa`)
    const p = perState.atual
    fecharImportacoes()
    if (typeof irPara === 'function') irPara('mapa')
    const mapa = mapaState.obj
    sairDoPerimetroNoMapa()
    const pane = _perPane(mapa)
    const g = L.featureGroup([], { pane })
    const desenhar = (geo, estilo) => geo && L.geoJSON(geo, { pane, interactive: false, style: estilo }).addTo(g)
    desenhar(d.entra, { stroke: false, fillColor: '#1D9E75', fillOpacity: 0.35 })
    desenhar(d.sai, { stroke: false, fillColor: '#E24B4A', fillOpacity: 0.35 })
    desenhar(d.vigente, { color: '#6B7280', weight: 2, dashArray: '6 6', fill: false })
    desenhar(d.perimetro, { color: PER_COR, weight: 3, fill: false })
    g.addTo(mapa)
    perState.revisao = g
    setTimeout(() => { try { mapa.fitBounds(g.getBounds(), { padding: [30, 30] }) } catch { /* vazio */ } }, 120)

    const ancora = document.getElementById('imp-barra')
    const barra = document.getElementById('per-barra') || Object.assign(document.createElement('div'), { id: 'per-barra', className: 'imp-barra' })
    if (!barra.parentNode) ancora?.parentNode?.insertBefore(barra, ancora.nextSibling)
    barra.hidden = false
    barra.innerHTML = `
      <button class="btn sm imp-barra-ficha" onclick="sairDoPerimetroNoMapa(); abrirPerimetro(${id})" title="Voltar à ficha do perímetro">&#8249; Ficha</button>
      <b>Perímetro · ${esc(p?.rotulo || '')} · ${esc(p?.situacao_rotulo || '')}</b>
      <span class="per-legenda"><i style="background:${PER_COR}"></i>${d.vigente ? 'novo' : 'perímetro'}</span>
      ${d.vigente ? `<span class="per-legenda"><i class="tracejado"></i>vigente</span>
        <span class="per-legenda"><i style="background:#1D9E75;opacity:.6"></i>entra</span>
        <span class="per-legenda"><i style="background:#E24B4A;opacity:.6"></i>sai</span>` : ''}
      <span class="imp-barra-acoes">
        <button class="btn sm imp-barra-x" title="Fechar" onclick="sairDoPerimetroNoMapa()">&#10005;</button>
      </span>`
  } catch (e) { toast(e.message, 'err') }
}

function sairDoPerimetroNoMapa() {
  perState.revisao?.remove()
  perState.revisao = null
  const b = document.getElementById('per-barra')
  if (b) b.hidden = true
}

// ── PELO MEMORIAL DESCRITIVO ──────────────────────────────────
//
// A lei define o perímetro pelos vértices (P-01 … P-n) em UTM 21S. Três jeitos
// de chegar à tabela: colar o texto da lei e "Ler"; partir dos vértices da
// versão vigente e alterar só o que a lei nova mudou; ou digitar. A conferência
// é do servidor (MemorialDoPerimetro): cada trecho contra o azimute e a
// distância da lei, área e perímetro declarados. Editou, confere de novo.

const PER_TRECHOS = { seca: 'linha seca', corrego: 'córrego', serra: 'serra', estrada: 'estrada' }

/** Número digitado: "8.287.874,960", "8287874,960" ou "8287874.960". */
const _perNum = s => {
  s = String(s ?? '').trim()
  if (!s) return null
  const n = s.includes(',') ? Number(s.replace(/\./g, '').replace(',', '.')) : Number(s)
  return Number.isFinite(n) ? n : null
}
const _perFmt = (n, casas = 3) => n == null ? '' : Number(n).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas, useGrouping: false })

/**
 * @param {{base?:number, rascunho?:number}} op  base: versão de onde copiar os
 *   vértices (nova versão); rascunho: o rascunho que será refeito.
 */
async function abrirEditorMemorial(op = {}) {
  const m = perState.memorial = { vertices: [], texto: '', area: null, perimetro: null, conferido: null, alvo: op.rascunho || null, base: null }
  let aviso = ''
  try {
    const id = op.base || op.rascunho
    if (id) {
      const d = await _impPedir(`/api/perimetros/${id}/memorial`)
      m.vertices = d.vertices || []
      m.texto = op.rascunho ? (d.texto || '') : ''
      m.area = d.conferencia?.area_declarada_m2 ?? null
      m.perimetro = d.conferencia?.perimetro_declarado_m ?? null
      if (op.base) {
        m.base = d.rotulo
        // A versão nova parte da área e do perímetro de AGORA: a lei nova declara os dela.
        m.area = m.perimetro = null
        aviso = m.vertices.length
          ? `Vértices copiados de <b>${esc(d.rotulo)}</b>. Altere só o que a lei nova mudou — ou cole o memorial novo inteiro acima.`
          : `<b>${esc(d.rotulo)}</b> veio de arquivo GeoJSON e não tem tabela de vértices: cole o memorial da lei nova.`
      }
    }
  } catch (e) { toast(e.message, 'err') }

  _impCorpo(`
    <div class="jt">
      <div class="jt-topo">
        <div class="imp-passos"><b>1 Memorial</b> › 2 Conferência › 3 Rascunho › 4 Publicação (administrador)</div>
        <p class="imp-expl">Vértices em <b>UTM 21S, SIRGAS 2000</b>, como na lei. As coordenadas definem o perímetro; azimute e
          distância servem para conferir. ${m.alvo ? 'Ao gravar, o rascunho é refeito com estes vértices.' : ''}</p>
      </div>
      <div class="jt-rolagem jt-folga">
        <details class="per-texto" ${m.vertices.length ? '' : 'open'}>
          <summary>Texto do memorial (colar da lei)</summary>
          <textarea id="per-mem-texto" rows="7" placeholder="Cole aqui o memorial descritivo: …vértice P-01 de coordenadas N=8287874,960 e E=793640,650… — ou uma tabela com vértice, N e E por linha.">${esc(m.texto)}</textarea>
          <div class="btn-row"><button class="btn sm primary" onclick="lerTextoDoMemorial()">Ler o texto</button>
            <span class="imp-sub">Substitui a tabela abaixo pelos vértices do texto.</span></div>
        </details>
        ${aviso ? `<div class="cad-nota imp-nota">${aviso}</div>` : ''}
        <div class="per-declarados">
          <div class="field"><label for="per-mem-area">Área declarada na lei (ha)</label>
            <input id="per-mem-area" inputmode="decimal" placeholder="ex.: 8699,382" value="${m.area != null ? _perFmt(m.area / 10000) : ''}"></div>
          <div class="field"><label for="per-mem-perim">Perímetro declarado (m)</label>
            <input id="per-mem-perim" inputmode="decimal" placeholder="ex.: 71550,5" value="${m.perimetro != null ? _perFmt(m.perimetro, 1) : ''}"></div>
        </div>
        <div id="per-mem-tabela"></div>
        <div id="per-mem-resultado"></div>
        ${m.alvo ? '' : `<h4 class="per-tit">Lei</h4>${_perCamposDaLei()}`}
      </div>
      <div class="jt-rodape">
        <span class="imp-sub" id="per-mem-status"></span>
        <div class="btn-row">
          <button class="btn" onclick="${m.alvo ? `abrirPerimetro(${m.alvo})` : 'carregarListaImportacoes()'}">Voltar</button>
          <button class="btn" onclick="conferirMemorial()">Conferir</button>
          <button class="btn primary" id="per-mem-gravar" disabled onclick="gravarMemorial()">${m.alvo ? 'Gravar no rascunho' : 'Carregar como rascunho'}</button>
        </div>
      </div>
    </div>`, m.alvo ? 'Perímetro urbano · vértices do rascunho' : 'Perímetro urbano pelo memorial')
  _perPintarTabela()
  for (const id of ['per-mem-area', 'per-mem-perim']) document.getElementById(id).addEventListener('input', _perMemorialMudou)
}

/** A tabela de vértices. As linhas com trecho que não bate ficam marcadas depois de conferir. */
function _perPintarTabela() {
  const m = perState.memorial
  const alvo = document.getElementById('per-mem-tabela')
  if (!alvo) return
  const ruins = new Map()   // linha → texto do problema
  const trechos = m.conferido?.conferencia?.trechos || []
  trechos.forEach((t, i) => {
    if (t.ok) return
    const linha = i + 1 < m.vertices.length ? i + 1 : 0
    const partes = []
    if (t.dif_distancia != null && Math.abs(t.dif_distancia) > 0.05) partes.push(`distância: lei ${_perFmt(t.distancia_lei)} m, coordenadas ${_perFmt(t.distancia_calc)} m`)
    if (t.dif_azimute_seg != null && Math.abs(t.dif_azimute_seg) > 5) partes.push(`azimute: lei ${t.azimute_lei}, coordenadas ${t.azimute_calc}`)
    ruins.set(linha, `${t.de} → ${t.para}: ${partes.join('; ')}`)
  })
  const fecha = m.vertices.length > 3 && m.vertices[0]?.p && m.vertices[m.vertices.length - 1]?.p === m.vertices[0].p
  alvo.innerHTML = `
    <div class="per-mem-cab"><b>${m.vertices.length} vértice(s)</b>
      <button class="btn sm" onclick="_perInserir(${m.vertices.length})">+ vértice no fim</button></div>
    ${m.vertices.length ? `
    <div class="per-mem-rolagem">
      <table class="per-mem">
        <thead><tr><th>#</th><th>Vértice</th><th>N</th><th>E</th><th>Azimute</th><th>Distância (m)</th><th>Trecho</th><th></th></tr></thead>
        <tbody>${m.vertices.map((v, i) => `
          <tr class="${ruins.has(i) ? 'ruim' : ''}" ${ruins.has(i) ? `title="${esc(ruins.get(i))}"` : ''}>
            <td class="num">${i + 1}</td>
            <td><input data-i="${i}" data-c="p" value="${esc(v.p ?? '')}" maxlength="20">${fecha && i === m.vertices.length - 1 ? '<small>fecha</small>' : ''}</td>
            <td><input data-i="${i}" data-c="n" inputmode="decimal" value="${_perFmt(v.n)}"></td>
            <td><input data-i="${i}" data-c="e" inputmode="decimal" value="${_perFmt(v.e)}"></td>
            <td><input data-i="${i}" data-c="azimute" value="${esc(v.azimute ?? '')}" placeholder="—" maxlength="20"></td>
            <td><input data-i="${i}" data-c="distancia" inputmode="decimal" value="${_perFmt(v.distancia)}" placeholder="—"></td>
            <td><select data-i="${i}" data-c="trecho">${Object.entries(PER_TRECHOS).map(([k, r]) => `<option value="${k}" ${v.trecho === k ? 'selected' : ''}>${r}</option>`).join('')}</select></td>
            <td class="per-mem-acoes"><button class="btn sm" title="Inserir vértice abaixo" onclick="_perInserir(${i + 1})">+</button><button class="btn sm" title="Remover" onclick="_perRemover(${i})">&#10005;</button></td>
          </tr>`).join('')}</tbody>
      </table>
    </div>
    <p class="imp-sub">Azimute e distância de cada linha são os do trecho que <b>chega</b> no vértice. A última linha igual à primeira é o fechamento.</p>`
    : '<div class="vazio-msg">Nenhum vértice ainda: cole o memorial acima e clique em "Ler o texto", ou acrescente vértice a vértice.</div>'}`
  alvo.querySelectorAll('[data-c]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', e => {
    const i = Number(e.target.dataset.i), c = e.target.dataset.c
    const v = m.vertices[i]
    v[c] = ['n', 'e', 'distancia'].includes(c) ? _perNum(e.target.value) : (e.target.value.trim() || null)
    _perMemorialMudou()
  }))
}

function _perInserir(i) {
  perState.memorial.vertices.splice(i, 0, { p: '', n: null, e: null, azimute: null, distancia: null, trecho: perState.memorial.vertices[i - 1]?.trecho || 'seca' })
  _perMemorialMudou()
  _perPintarTabela()
  document.querySelector(`#per-mem-tabela input[data-i="${i}"][data-c="p"]`)?.focus()
}

function _perRemover(i) {
  perState.memorial.vertices.splice(i, 1)
  _perMemorialMudou()
  _perPintarTabela()
}

/** Mexeu em alguma coisa: o que foi conferido não vale mais. */
function _perMemorialMudou() {
  const m = perState.memorial
  if (!m.conferido) return
  m.conferido = null
  document.getElementById('per-mem-gravar').disabled = true
  document.getElementById('per-mem-status').textContent = 'Alterado: confira de novo antes de gravar.'
}

function _perDeclarados() {
  const ha = _perNum(document.getElementById('per-mem-area')?.value)
  return { area_declarada_m2: ha != null ? ha * 10000 : null, perimetro_declarado_m: _perNum(document.getElementById('per-mem-perim')?.value) }
}

async function lerTextoDoMemorial() {
  const texto = document.getElementById('per-mem-texto').value
  if (!texto.trim()) { toast('Cole o texto do memorial.', 'err'); return }
  perState.memorial.texto = texto
  // Os declarados vêm do texto: os campos só valem se foram preenchidos à mão depois.
  document.getElementById('per-mem-area').value = ''
  document.getElementById('per-mem-perim').value = ''
  await conferirMemorial({ texto })
}

/** Confere no servidor: lê o texto (se veio) ou a tabela. */
async function conferirMemorial(op = {}) {
  const m = perState.memorial
  const corpo = op.texto != null ? { texto: op.texto } : { vertices: m.vertices }
  const res = document.getElementById('per-mem-resultado')
  res.innerHTML = '<div class="vazio-msg">Conferindo…</div>'
  try {
    const d = await _impPedir('/api/perimetros/memorial/conferir', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...corpo, ..._perDeclarados() }),
    })
    m.vertices = d.vertices || []
    m.conferido = d
    if (d.area_declarada_m2 != null) document.getElementById('per-mem-area').value = _perFmt(d.area_declarada_m2 / 10000)
    if (d.perimetro_declarado_m != null) document.getElementById('per-mem-perim').value = _perFmt(d.perimetro_declarado_m, 1)
    if (op.texto != null) document.querySelector('.per-texto')?.removeAttribute('open')
    _perPintarTabela()
    const c = d.conferencia
    res.innerHTML = `
      <h4 class="per-tit">Conferência</h4>
      ${_perHtmlItens(c.itens)}
      ${c.bloqueia ? '' : `<div class="per-kpis">
        <div class="imp-kpi"><small>Área (UTM)</small><b>${_perHa(c.area_m2)}</b></div>
        <div class="imp-kpi"><small>Perímetro</small><b>${Number(c.perimetro_m).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} m</b></div>
        <div class="imp-kpi"><small>Vértices</small><b>${m.vertices.length - (m.vertices.length > 3 && m.vertices[0].p === m.vertices[m.vertices.length - 1].p ? 1 : 0)}</b></div>
      </div>`}
      ${d.comparacao ? `<h4 class="per-tit">O que muda</h4>${_perHtmlComparacao(d.comparacao)}` : ''}`
    document.getElementById('per-mem-gravar').disabled = !!c.bloqueia
    document.getElementById('per-mem-status').textContent = c.bloqueia ? 'Corrija os erros para gravar.' : 'Conferido.'
  } catch (e) {
    res.innerHTML = `<div class="cad-nota cad-erro">${esc(e.message)}</div>`
  }
}

async function gravarMemorial() {
  const m = perState.memorial
  if (!m.conferido) { toast('Confira antes de gravar.', 'err'); return }
  const b = document.getElementById('per-mem-gravar')
  b.disabled = true
  const corpo = { vertices: m.vertices, texto: m.texto || null, ..._perDeclarados(), ...(m.alvo ? {} : _perLeiDoFormulario()) }
  for (const k of Object.keys(corpo)) if (corpo[k] === '') corpo[k] = null
  try {
    const d = await _impPedir(m.alvo ? `/api/perimetros/${m.alvo}/memorial` : '/api/perimetros/memorial', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo),
    })
    toast(d.message)
    await abrirPerimetro(m.alvo || d.id)
  } catch (e) {
    toast(e.message, 'err')
    b.disabled = false
  }
}

/** Os itens da conferência: ✓ ok, ! aviso, ✕ erro. */
function _perHtmlItens(itens = []) {
  const sinal = { ok: '✓', aviso: '!', erro: '✕' }
  return `<ul class="per-itens">${itens.map(i => `<li class="${esc(i.nivel)}"><span>${sinal[i.nivel] || '·'}</span>${esc(i.texto)}</li>`).join('')}</ul>`
}
