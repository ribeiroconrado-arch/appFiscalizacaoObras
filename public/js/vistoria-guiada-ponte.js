// ══════════════════════════════════════════════
// PONTE DO MAPA PARA A VISTORIA (vistoria guiada) — 26/11/2026
//
// A vistoria por modelo substituiu a vistoria de obra. Todo caminho do mapa
// que abria a antiga — o "Novo › Vistoria" da Documentos, o balão do lote, o
// protocolo deferido, a sinalização, o painel — passa por aqui e abre a
// página /vistorias-guiadas já com o vínculo escolhido. A volta também passa
// por aqui: "Notificação ou auto" na vistoria concluída abre o mapa com
// ?vg_doc=<id>, e a peça nasce presa a ela.
//
// Carregado DEPOIS de vistoria.js: `novaVistoria` é redefinida aqui, e os
// lugares que a chamavam continuam chamando, sem saber da troca.
// ══════════════════════════════════════════════

/**
 * Abre a vistoria nova, já ligada.
 * @param {{imovel?:number, protocolo?:number, denuncia?:number, pessoa?:number, sinalizacao?:number, local?:string}} p
 */
function irParaVistoriaGuiada(p = {}) {
  const q = new URLSearchParams({ nova: '1' })
  for (const k of ['imovel', 'protocolo', 'denuncia', 'pessoa', 'sinalizacao', 'local']) {
    if (p[k]) q.set(k, String(p[k]))
  }
  location.href = '/vistorias-guiadas?' + q.toString()
}

/** Abre uma vistoria (guiada) já existente. @param {number} id */
function abrirVistoriaGuiada(id) {
  location.href = '/vistorias-guiadas?v=' + Number(id)
}

// A ANTIGA SAI: quem chamava novaVistoria() abre a guiada, com o imóvel
// selecionado e, vindo do protocolo, o protocolo (vState.protocoloId é o que
// vistoriaDoProtocolo deixa antes de chamar).
// eslint-disable-next-line no-global-assign
novaVistoria = function () {
  const imovel = state.selecionado?.properties?.id || null
  const protocolo = (typeof vState !== 'undefined' && vState.protocoloId) || null
  if (typeof vState !== 'undefined') vState.protocoloId = null
  irParaVistoriaGuiada({ imovel, protocolo })
}

// ── A volta: a peça a partir da vistoria concluída ──

/**
 * O menu das peças que podem nascer da vistoria (as mesmas do "Novo" da
 * Documentos, menos a própria vistoria), na janela "m-vist-ato" que a vistoria
 * antiga já usava.
 * @param {number} id a vistoria guiada
 */
async function documentoDaVistoriaGuiada(id) {
  let s, lote = null
  try {
    const r = await fetch(`/api/vistorias-guiadas/${id}/sugestao`, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    s = await r.json()
    if (s.vistoria.lote_id) {
      const ri = await fetch('/api/imoveis/' + s.vistoria.lote_id, { headers: { Accept: 'application/json' } })
      if (ri.ok) { const d = await ri.json(); lote = { id: d.id, bairro: d.bairro, quadra: d.quadra, numero_lote: d.lote } }
    }
  } catch (e) {
    console.error(e)
    toast('Não foi possível abrir a vistoria para gerar a peça.', 'err')
    return
  }

  const o = await carregarOpcoes()
  const opcoes = o.tipos.filter(t => t.valor !== 'vistoria').map(t => ({
    valor: t.valor, rotulo: t.rotulo,
    obs: OBS_TIPO_DOC[t.valor] || '',
    icone: ICO_TIPO_DOC[t.valor] || ICO_TIPO_DOC.padrao,
    separar: t.valor === 'auto_embargo',
    acao: () => {
      fModalBtn('m-vist-ato')
      return abrirFormDoc({ lote, tipoInicial: t.valor, vistoriaGuiada: id })
    },
  }))
  if (typeof vState !== 'undefined') vState.opcoesAto = opcoes

  const alvo = document.getElementById('vato-lista')
  if (!alvo) return
  document.getElementById('vato-titulo').textContent = s.vistoria.numero + ' — gerar peça'
  alvo.innerHTML = opcoes.map((op, i) => `
    <button type="button" class="vato-op${op.separar ? ' vato-sep' : ''}" onclick="escolherAto(${i})">
      <span class="vato-ico">${op.icone}</span>
      <span class="vato-txt"><span class="vato-nome">${esc(op.rotulo)}</span>
        ${op.obs ? `<span class="vato-obs">${esc(op.obs)}</span>` : ''}</span>
    </button>`).join('')
  openModal('m-vist-ato')
}

// ?vg_doc=<id>: chegou da vistoria concluída pedindo a peça.
window.addEventListener('load', () => {
  const p = new URLSearchParams(location.search)
  const id = Number(p.get('vg_doc'))
  if (!id) return
  history.replaceState(null, '', location.pathname)
  // Depois do resto do mapa montar (opções da Documentos, modais).
  setTimeout(() => documentoDaVistoriaGuiada(id), 400)
})
