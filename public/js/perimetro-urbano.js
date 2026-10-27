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
        ${!vig && !rasc.length ? `<p class="imp-expl">Para carregar: <b>+ Nova importação</b> e solte o .geojson do perímetro
          (gerado no conversor com a camada no papel "Perímetro urbano"). O sistema reconhece o arquivo.</p>` : ''}
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
            <dt>Arquivo</dt><dd>${esc(p.arquivo)}${p.fonte && p.fonte !== p.arquivo ? ` · DWG ${esc(p.fonte)}` : ''}${p.camada ? ` · camada ${esc(p.camada)}` : ''}</dd>
            <dt>Carregado</dt><dd>${esc(p.enviado_por || '—')} · ${esc(p.enviado_em || '—')}</dd>
            ${p.publicado_em ? `<dt>Publicado</dt><dd>${esc(p.publicado_em)}</dd>` : ''}
            ${!rascunho && p.observacao ? `<dt>Observação</dt><dd>${esc(p.observacao)}</dd>` : ''}
          </dl>
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
