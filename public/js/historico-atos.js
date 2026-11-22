/**
 * A TELA "HISTÓRICO" (20/11/2026): todos os atos do cadastro — desmembramentos,
 * unificações, frações ideais e as trocas de identidade —, do mais recente ao
 * mais antigo. Cada linha abre a tela do ato (identidades.js).
 * Ato desfeito sai da sucessão e não aparece aqui; a trilha do lote conta.
 * Servidor: GET /api/atos (AtosDoCadastro::lista).
 */

const histState = { tipo: '', busca: '', pagina: 1, itens: [], mais: false, carregando: false }
let _histEspera = null

const HIST_FILTROS = [
  ['', 'Todos'], ['desmembramento', 'Desmembramentos'], ['unificacao', 'Unificações'],
  ['fracao_ideal', 'Frações ideais'], ['troca', 'Trocas de identidade'],
]

function prepararHistorico() {
  const f = document.getElementById('hist-filtros')
  if (f && !f.children.length) {
    f.innerHTML = HIST_FILTROS.map(([v, r]) =>
      `<button type="button" data-tipo="${v}" aria-pressed="${v === histState.tipo}" onclick="filtrarHistorico('${v}')">${r}</button>`).join('')
  }
  carregarHistoricoDeAtos(true)
}

function filtrarHistorico(tipo) {
  histState.tipo = tipo
  document.querySelectorAll('#hist-filtros button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.tipo === tipo)))
  carregarHistoricoDeAtos(true)
}

function buscarNoHistorico(v) {
  clearTimeout(_histEspera)
  _histEspera = setTimeout(() => { histState.busca = v.trim(); carregarHistoricoDeAtos(true) }, 300)
}

async function carregarHistoricoDeAtos(doZero) {
  const alvo = document.getElementById('hist-lista')
  if (!alvo) return
  if (doZero) { histState.pagina = 1; histState.itens = []; alvo.innerHTML = '<div class="vazio-msg">Carregando…</div>' }
  histState.carregando = true
  const q = new URLSearchParams({ pagina: histState.pagina })
  if (histState.tipo) q.set('tipo', histState.tipo)
  if (histState.busca) q.set('busca', histState.busca)
  const pedido = q.toString()
  histState.pedido = pedido
  try {
    const r = await fetch('/api/atos?' + pedido, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (histState.pedido !== pedido) return   // outro filtro chegou antes
    if (!r.ok) throw new Error(d.message || 'Não foi possível ler o histórico.')
    histState.itens.push(...d.itens)
    histState.mais = d.mais
    pintarHistoricoDeAtos()
  } catch (e) {
    alvo.innerHTML = `<div class="ident-erro">${esc(e.message)}</div>`
  } finally {
    histState.carregando = false
  }
}

function maisHistorico() {
  if (histState.carregando) return
  histState.pagina++
  carregarHistoricoDeAtos(false)
}

function _linhaDoHistorico(i) {
  const ant = _lista(i.anteriores)
  const pos = _lista(i.posteriores)
  const onde = [i.tipo === 'unificacao' ? `lotes ${ant}` : `lote ${ant || pos}`, i.quadra ? 'Q' + i.quadra : null, i.bairro].filter(Boolean).join(' · ')
  let detalhe
  if (i.tipo === 'troca') detalhe = AtosTexto.trocas(i.trocas) + (i.observacao ? ` · “${i.observacao}”` : '')
  else if (i.tipo === 'unificacao') detalhe = `em ${pos}`
  else if (i.tipo === 'fracao_ideal') detalhe = `${i.posteriores.length} unidade${i.posteriores.length === 1 ? '' : 's'}`
  else detalhe = `em ${pos}`
  if (i.tipo !== 'troca') detalhe += i.protocolo ? ` · protocolo ${i.protocolo}` : ' · ato direto'
  return `<button type="button" class="hist-linha" onclick="abrirAto(${i.ato})">
    <span class="hist-data">${esc(String(i.quando || '').split(' ')[0])}<small>${esc(String(i.quando || '').split(' ')[1] || '')}</small></span>
    <span class="hist-ato"><b>${esc(i.rotulo)}</b> · ${esc(onde)}<small>${esc(detalhe)}</small></span>
    <span class="hist-quem">${esc(i.usuario || '—')}</span>
  </button>`
}

/**
 * O bloco do Painel (21/11/2026): os 5 atos mais recentes, sem filtro. A lista
 * inteira, com filtros e busca, abre pelo "Ver tudo" (#t-historico).
 */
async function carregarHistoricoNoPainel() {
  const alvo = document.getElementById('pn-historico')
  if (!alvo) return
  if (!alvo.children.length) alvo.innerHTML = '<div class="vazio-msg">Carregando…</div>'
  try {
    const r = await fetch('/api/atos?pagina=1', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'Não foi possível ler o histórico.')
    const itens = (d.itens || []).slice(0, 5)
    alvo.innerHTML = itens.length
      ? `<div class="hist-lista hist-painel">${itens.map(_linhaDoHistorico).join('')}</div>`
      : '<div class="vazio-msg">Nenhum ato no cadastro ainda.</div>'
  } catch (e) {
    alvo.innerHTML = `<div class="ident-erro">${esc(e.message)}</div>`
  }
}

/** O texto das trocas, igual ao do servidor (AtosDoCadastro::textoDasTrocas). */
const AtosTexto = {
  trocas: t => (t || []).map(x => x.de + (x.mutua ? ' ↔ ' : ' → ') + x.para).join(', '),
}

function pintarHistoricoDeAtos() {
  const alvo = document.getElementById('hist-lista')
  if (!histState.itens.length) {
    alvo.innerHTML = `<div class="vazio-msg">${histState.busca || histState.tipo ? 'Nenhum ato com esse filtro.' : 'Nenhum ato no cadastro ainda.'}</div>`
    return
  }
  alvo.innerHTML = `<div class="hist-cab"><span>Data</span><span>Ato</span><span>Quem</span></div>`
    + histState.itens.map(_linhaDoHistorico).join('')
    + (histState.mais ? '<button type="button" class="btn sm hist-mais" onclick="maisHistorico()">Ver mais</button>' : '')
}
