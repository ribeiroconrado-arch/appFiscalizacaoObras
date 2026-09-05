// ══════════════════════════════════════════════
// A TRILHA DE ALTERAÇÕES DO CADASTRO
//
// Quem mexeu no quê, e o que mudou de quê para quê. O dado sempre esteve na
// tabela `auditoria` — o trait de eventos do Eloquent grava tudo —, mas só as
// 12 linhas mais recentes apareciam, no feed do Painel. Uma unificação empurra
// cinco de uma vez.
// ══════════════════════════════════════════════

const trState = {
  /** @type {Array<Object>} */ linhas: [],
  filtros: { busca: '', acao: '', user_id: '', dias: '30', lote_id: '' },
  opcoesMontadas: false,
}

/**
 * Anota o filtro, mas não busca — mesma regra das outras listas do sistema.
 * Ver `filtrarDocumentos` para o porquê.
 *
 * @param {string} chave @param {string} valor
 */
function filtrarTrilha(chave, valor) {
  trState.filtros[chave] = valor
  marcarBuscaPendente('tr-buscar')
}

function limparTrilha() {
  trState.filtros = { busca: '', acao: '', user_id: '', dias: '30', lote_id: '' }
  const põe = (id, v) => { const e = document.getElementById(id); if (e) { e.value = v } }
  põe('tr-busca', ''); põe('tr-acao', ''); põe('tr-pessoa', ''); põe('tr-dias', '30'); põe('tr-lote', '')
  carregarTrilha()
}

async function carregarTrilha() {
  const alvo = document.getElementById('lista-trilha')
  if (!alvo) { return }

  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(trState.filtros)) { if (v) { p.set(k, v) } }

  alvo.innerHTML = '<div class="lista-vazia">Carregando…</div>'
  mostrarCarregandoTela('Lendo a trilha...')
  try {
    const r = await fetch('/api/trilha?' + p, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) { throw new Error(d.message || 'HTTP ' + r.status) }

    trState.linhas = d.linhas ?? []
    montarOpcoesTrilha(d.opcoes)
    document.getElementById('cont-trilha').textContent = trState.linhas.length
    renderTrilha(d.truncou)
    limparBuscaPendente('tr-buscar')
  } catch (e) {
    console.error(e)
    alvo.innerHTML = `<div class="lista-vazia">${esc(e.message || 'Não foi possível ler a trilha.')}</div>`
  } finally {
    esconderCarregandoTela()
  }
}

/**
 * As opções vêm do que EXISTE na trilha, e não de uma lista escrita aqui.
 *
 * Ação que nunca aconteceu não precisa aparecer no filtro, e ação nova passa a
 * aparecer sozinha — sem ninguém lembrar de acrescentá-la em dois lugares.
 *
 * Montadas uma vez: remontar a cada busca perderia a escolha corrente.
 */
function montarOpcoesTrilha(op) {
  if (trState.opcoesMontadas || !op) { return }

  const acoes = document.getElementById('tr-acao')
  for (const a of op.acoes ?? []) {
    acoes.insertAdjacentHTML('beforeend', `<option value="${esc(a)}">${esc(a)}</option>`)
  }
  const pessoas = document.getElementById('tr-pessoa')
  for (const p of op.pessoas ?? []) {
    pessoas.insertAdjacentHTML('beforeend',
      `<option value="${p.user_id}">${esc(p.usuario_nome ?? '—')}</option>`)
  }
  trState.opcoesMontadas = true
}

/** @param {boolean} truncou */
function renderTrilha(truncou) {
  const alvo = document.getElementById('lista-trilha')

  if (!trState.linhas.length) {
    alvo.innerHTML = '<div class="lista-vazia">Nenhuma alteração com esses filtros.</div>'
    return
  }

  const aviso = truncou
    ? '<div class="cad-nota cad-aviso">Mostrando as 300 mais recentes. Estreite o período ou o filtro para ver o resto.</div>'
    : ''

  alvo.innerHTML = aviso + trState.linhas.map(l => {
    // O que mudou, de quê para quê. É isto que a "Atividade recente" não diz —
    // e é a resposta que quem abre esta tela veio buscar.
    const delta = (l.mudou ?? []).map(m =>
      `<span class="tr-delta"><b>${esc(m.campo)}</b>
         ${esc(valorTrilha(m.de))} <span class="tr-seta">→</span> ${esc(valorTrilha(m.para))}</span>`
    ).join('')

    const botao = l.desfazer?.pode
      ? `<button class="btn out-cinza sm" onclick="desfazerTrilha(${l.id})">Desfazer</button>`
      : l.desfazer?.motivo
        ? `<span class="tr-sem-volta" title="${esc(l.desfazer.motivo)}">Sem volta</span>`
        : ''

    return `
      <div class="tr-linha">
        <span class="tr-quando">${esc(l.quando)}</span>
        <span class="tr-acao ${l.acao === 'excluiu' ? 'perigosa' : ''}">${esc(l.acao)}</span>
        <span class="tr-alvo">
          ${esc(l.alvo)}${l.bairro ? ` <span class="tr-bairro">${esc(l.bairro)}</span>` : ''}
          <span class="tr-quem">${esc(l.quem)}${l.matricula ? ' · mat. ' + esc(l.matricula) : ''}</span>
        </span>
        <span class="tr-mudou">${delta}</span>
        ${botao}
      </div>`
  }).join('')
}

/** Nulo é "vazio", e não a palavra "null" na tela. */
function valorTrilha(v) {
  if (v === null || v === undefined || v === '') { return '—' }
  return String(v)
}

/**
 * Desfazer. Confirma sempre: é ato sobre ato, e a pessoa precisa ver o que vai
 * acontecer antes — inclusive que a reversão FICA REGISTRADA.
 *
 * @param {number} id linha da auditoria
 */
function desfazerTrilha(id) {
  const l = trState.linhas.find(x => x.id === id)
  if (!l) { return }

  confirmarAcao({
    titulo: 'Desfazer: ' + l.acao,
    mensagem: `${l.alvo} — ${l.quando}, por ${l.quem}.\n\n`
      + 'A reversão não apaga o registro original: ela grava uma linha nova na '
      + 'trilha, com o seu nome. Quem ler depois vê as duas.',
    textoBtn: 'Desfazer',
    perigo: true,
    onConfirm: async () => {
      const d = await postCadastro('/api/trilha/' + id + '/desfazer', {})
      if (!d) { return }
      toast(d.message)
      carregarTrilha()
      if (typeof limparLotesDoMapa === 'function') { limparLotesDoMapa(); carregarLotesVisiveis() }
    },
  })
}
