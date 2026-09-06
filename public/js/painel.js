// ══════════════════════════════════════════════
// MÓDULO: PAINEL (variante F)
//
// Responde "o que mudou e o que precisa de mim?". Todo número vem da API —
// nada aqui é simulado. Bloco sem dado não aparece, em vez de mostrar zero
// disfarçado de informação.
// ══════════════════════════════════════════════

/** Estado do painel. */
const pState = {
  filtros: { dias: 30, bairro: '', agente: 'todos' },
  carregado: false,
  atencao: [],
  notificacoes: [],
  recentes: [],
  recentesExpandidos: false,
}

/** Busca e desenha o painel inteiro. */
async function carregarPainel() {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(pState.filtros)) if (v) p.set(k, v)

  try {
    const r = await fetch('/api/painel?' + p, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    renderPainel(d)
    // Os avisos vêm de outra rota (a do sino) e por isso são pedidos aqui:
    // o painel não os recalcula, só os mostra num segundo lugar.
    carregarNotificacoes()
    pState.carregado = true
  } catch (e) {
    console.error(e)
    document.getElementById('pn-metricas').innerHTML =
      '<div class="lista-vazia">Não foi possível carregar o painel.</div>'
  }
}

/** @param {Object} d resposta de /api/painel */
function renderPainel(d) {
  pState.atencao = d.atencao
  pState.recentes = d.recentes
  // ── métricas ──
  // A grade mantém a altura dos indicadores, inclusive com detalhe extra.
  document.getElementById('pn-metricas').innerHTML = Object.values(d.metricas).map(m => `
    <div class="mc">
      <div class="n">${m.n}</div>
      <div class="tx">
        <div class="l">${esc(m.rotulo)}</div>
        ${m.detalhe ? `<div class="dt">${esc(m.detalhe)}</div>` : ''}
      </div>
    </div>`).join('')

  // ── precisa de atenção ──
  const at = document.getElementById('pn-atencao')
  document.getElementById('pn-atencao-n').textContent = d.atencao.length
  at.innerHTML = d.atencao.length
    ? d.atencao.map(a => `
        <${a.aba ? 'button type="button"' : 'div'} class="item" ${a.aba ? `onclick="irPara('${a.aba}')"` : ''}>
          <div class="ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          </div>
          <div class="tx">
            <div class="t">${esc(a.titulo)}</div>
            <div class="s">${esc(a.detalhe)}</div>
            ${a.tag ? `<div class="m"><span class="badge ${esc(a.tag.classe)}">${esc(a.tag.texto)}</span></div>` : ''}
          </div>
        </${a.aba ? 'button' : 'div'}>`).join('')
    : '<div class="lista-vazia">Nada pendente no momento.</div>'

  // ── alterações recentes (vêm da auditoria) ──
  // Avatar + título + frase + data. O autor entra como "por você" quando é o
  // próprio usuário: repetir o nome dele em toda linha da lista não informa
  // nada, e é o caso mais comum de quem está olhando o painel.
  //
  // O avatar usa `.par-av`, a MESMA classe da lista de usuários — não uma
  // cópia com os mesmos valores, que voltaria a divergir na primeira vez que
  // alguém mexesse numa das duas. Antes a mesma servidora aparecia como
  // quadrado verde numa tela e círculo cinza na outra.
  renderRecentesPainel()
  renderAvisosPainel()

  // ── barras ──
  document.getElementById('pn-por-tipo').innerHTML = barras(d.por_tipo)
  document.getElementById('pn-irregs').innerHTML = barras(d.irregularidades)

  const sel = document.getElementById('pn-bairro')
  if (sel && sel.options.length <= 1) {
    sel.innerHTML = '<option value="">Todos os bairros</option>' +
      d.bairros.map(b => `<option value="${esc(b)}">${esc(b)}</option>`).join('')
  }
}

function renderRecentesPainel() {
  const recentes = pState.recentesExpandidos ? pState.recentes : pState.recentes.slice(0, 3)
  document.getElementById('pn-recentes').innerHTML = recentes.length
    ? recentes.map(r => `
        <div class="fd" title="${esc(r.hora)}">
          <div class="par-av">${esc(inicialDe(r.usuario))}</div>
          <div class="c">
            <div class="a">${esc(r.titulo)}</div>
            <div class="b">${esc(r.detalhe)} ${r.eu
              ? '<b>por você</b>'
              : 'por ' + esc(r.usuario)}</div>
          </div>
          <div class="h">${esc(r.quando)}</div>
        </div>`).join('')
    : '<div class="lista-vazia">Sem movimentação registrada.</div>'

  const botao = document.getElementById('pn-recentes-mais')
  botao.hidden = pState.recentes.length <= 3
  botao.setAttribute('aria-expanded', String(pState.recentesExpandidos))
  botao.textContent = pState.recentesExpandidos ? 'Mostrar menos atividades' : 'Mostrar mais atividades'
}

function alternarRecentesPainel() {
  pState.recentesExpandidos = !pState.recentesExpandidos
  renderRecentesPainel()
}

function alternarFiltrosPainel(botao) {
  const filtros = document.getElementById('pn-filtros')
  filtros.hidden = !filtros.hidden
  botao.setAttribute('aria-expanded', String(!filtros.hidden))
}

/**
 * A inicial do nome, como na lista de usuários (parametros.js).
 *
 * Uma letra, e não duas: é o que a tela de usuários mostra, e o pedido era que
 * os dois avatares fossem iguais.
 *
 * @param {string} nome
 */
function inicialDe(nome) {
  return (nome || '?').trim().charAt(0).toUpperCase()
}

/**
 * Barras proporcionais ao maior valor da série — não ao total.
 * Proporção sobre o total achataria tudo quando há muitas categorias.
 *
 * @param {Array<{rotulo:string,n:number}>} itens
 */
function barras(itens) {
  if (!itens.length) return '<div class="lista-vazia">Sem dados no período.</div>'
  const max = Math.max(...itens.map(i => i.n))
  return itens.map(i => `
    <div class="barra">
      <div class="lin"><span>${esc(i.rotulo)}</span><b>${i.n}</b></div>
      <div class="tr"><div class="pr" style="width:${Math.round(i.n / max * 100)}%"></div></div>
    </div>`).join('')
}

/** @param {string} campo @param {string|number} valor */
function filtrarPainel(campo, valor) {
  pState.filtros[campo] = valor
  document.getElementById('pn-filtro-resumo').textContent = Array.from(
    document.querySelectorAll('#pn-filtros select'), sel => sel.selectedOptions[0].textContent
  ).join(' · ')
  carregarPainel()
}

// ── CENTRAL DE NOTIFICAÇÕES ──────────────────────────────────

/**
 * Avisos ligados aos atos do próprio usuário.
 *
 * Não confundir com a aba Documentos, que é o módulo de notificações e autos
 * fiscais. São coisas diferentes com nome parecido — o vocabulário da
 * fiscalização usa "notificação" nos dois sentidos.
 */
async function carregarNotificacoes() {
  try {
    const r = await fetch('/api/notificacoes', { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    pState.notificacoes = d.notificacoes

    const chip = document.getElementById('sino-n')
    chip.textContent = d.total
    chip.style.display = d.total ? '' : 'none'

    // O sino mantém todos os avisos. O painel agrupa os já presentes nas pendências.
    const html = d.total
      ? d.notificacoes.map(n => `
          <div class="notif"${n.aba ? ` onclick="fModalBtn('m-notif');irPara('${n.aba}')"` : ''}>
            <div class="ic">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                   stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            </div>
            <div class="c">
              <div class="t">${esc(n.titulo)}</div>
              <div class="b">${esc(n.texto)}</div>
              <div class="h">${esc(n.quando)}</div>
            </div>
          </div>`).join('')
      : '<div class="lista-vazia">Nenhum aviso no momento.</div>'

    document.getElementById('lista-notificacoes').innerHTML = html

    renderAvisosPainel()
  } catch (e) {
    console.error(e)
  }
}

/** Só agrupa avisos com a mesma identidade, nunca por semelhança do texto. */
function renderAvisosPainel() {
  const alvo = document.getElementById('pn-avisos')
  if (!alvo) return
  const chaves = new Set(pState.atencao.map(a => a.chave).filter(Boolean))
  const outros = pState.notificacoes.filter(n => !n.chave || !chaves.has(n.chave))
  const agrupados = pState.notificacoes.length - outros.length
  document.getElementById('pn-avisos-bloco').hidden = !outros.length
  document.getElementById('pn-avisos-n').textContent = outros.length
  alvo.innerHTML = outros.map(n => `
    <${n.aba ? 'button type="button"' : 'div'} class="notif" ${n.aba ? `onclick="irPara('${n.aba}')"` : ''}>
      <div class="c"><div class="t">${esc(n.titulo)}</div>
      <div class="b">${esc(n.texto)}</div><div class="h">${esc(n.quando)}</div></div>
    </${n.aba ? 'button' : 'div'}>`).join('')
  const resumo = document.getElementById('pn-avisos-agrupados')
  resumo.hidden = !agrupados
  resumo.textContent = agrupados === 1
    ? '1 aviso já está na lista “Precisa de você”. Todos os avisos continuam disponíveis no sino.'
    : `${agrupados} avisos já estão na lista “Precisa de você”. Todos os avisos continuam disponíveis no sino.`
}

function abrirNotificacoes() {
  carregarNotificacoes()
  openModal('m-notif')
}
