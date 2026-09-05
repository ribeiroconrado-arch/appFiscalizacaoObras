// ══════════════════════════════════════════════
// O HISTÓRICO DO CADASTRO — e o desfazer
//
// Vive DENTRO DA MESA, ao lado das ferramentas de curadoria, e não numa tela de
// administração. Quem desfaz um ato do cadastro é quem estava fazendo o ato: o
// curador, no mapa, com o desenho à frente. Levá-lo para Parâmetros obrigaria a
// sair do trabalho, procurar a linha numa lista do sistema inteiro, e voltar.
//
// O recorte é o MAPA: quadra corrigida, lote renumerado, unificação,
// desmembramento, exclusão e restauração. A auditoria do sistema — usuários,
// documentos, protocolos — é outro assunto, com outro público.
// ══════════════════════════════════════════════

const hcState = {
  /** @type {Array<Object>} */ linhas: [],
}

/**
 * Entra no modo histórico. É uma ferramenta da régua como as outras: ocupa o
 * lado direito da mesa e é largada pelo mesmo caminho.
 */
function abrirHistoricoCadastral() {
  sairModoCadastral(true)
  cadModo = 'historico'

  if (typeof fecharPaineisMapa === 'function') { fecharPaineisMapa() }
  if (ehMesaCadastral()) { abrirMesaCadastral() } else { abrirModalCad() }

  pintarPainelCadastro()
  carregarHistoricoCadastral()
}

async function carregarHistoricoCadastral() {
  const alvo = document.getElementById('hc-lista')
  if (!alvo) { return }

  const p = new URLSearchParams()
  p.set('dias', document.getElementById('hc-dias')?.value || '7')

  // "Só os lotes marcados" usa a marcação da MESA. Ela vive na tela, não no
  // servidor — é a tela que a manda, e não o servidor que a adivinha.
  if (document.getElementById('hc-escopo')?.value === 'marcados') {
    if (!selState.ids.size) {
      alvo.innerHTML = '<div class="lista-vazia">Nenhum lote marcado. '
        + 'Marque no mapa, ou volte para "Todo o cadastro".</div>'
      return
    }
    p.set('lotes', [...selState.ids].join(','))
  }

  alvo.innerHTML = '<div class="lista-vazia">Carregando…</div>'
  try {
    const r = await fetch('/api/cadastro/historico?' + p, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) { throw new Error(d.message || 'HTTP ' + r.status) }

    hcState.linhas = d.linhas ?? []
    renderHistoricoCadastral(d.truncou)
  } catch (e) {
    console.error(e)
    alvo.innerHTML = `<div class="lista-vazia">${esc(e.message || 'Não foi possível ler o histórico.')}</div>`
  }
}

/** @param {boolean} truncou */
function renderHistoricoCadastral(truncou) {
  const alvo = document.getElementById('hc-lista')

  if (!hcState.linhas.length) {
    alvo.innerHTML = '<div class="lista-vazia">Nada foi alterado no período.</div>'
    return
  }

  const aviso = truncou
    ? '<div class="cad-nota cad-aviso">Mostrando as 120 mais recentes.</div>'
    : ''

  alvo.innerHTML = aviso + hcState.linhas.map(l => {
    // O que mudou, de quê para quê. Numa coluna estreita cabe pouco, então só
    // o primeiro campo — que na prática é sempre o que interessa: a quadra que
    // mudou, o número que mudou.
    const m = (l.mudou ?? [])[0]
    const delta = m
      ? `<span class="hc-delta"><b>${esc(m.campo)}</b> ${esc(hcValor(m.de))}
           <span class="hc-seta">→</span> ${esc(hcValor(m.para))}</span>`
      : ''

    const acao = l.desfazer?.pode
      ? `<button class="btn out-cinza sm" onclick="desfazerNoCadastro(${l.id})">Desfazer</button>`
      : l.desfazer?.motivo
        ? `<span class="hc-sem-volta" title="${esc(l.desfazer.motivo)}">sem volta</span>`
        : ''

    // O lote é clicável: leva o mapa até ele. Ver um registro de alteração sem
    // poder olhar o desenho é metade da resposta.
    const alvoTxt = l.registro
      ? `<button type="button" class="hc-ir" onclick="irAoLoteDoHistorico(${l.registro})"
           title="Ver no mapa">${esc(l.alvo)}</button>`
      : `<span>${esc(l.alvo)}</span>`

    return `
      <div class="hc-linha">
        <div class="hc-l1">
          <span class="hc-acao ${l.acao === 'excluiu' ? 'perigosa' : ''}">${esc(l.acao)}</span>
          <span class="hc-quando">${esc(l.quando)}</span>
        </div>
        <div class="hc-l2">${alvoTxt}${delta}</div>
        <div class="hc-l3"><span class="hc-quem">${esc(l.quem)}</span>${acao}</div>
      </div>`
  }).join('')
}

function hcValor(v) {
  if (v === null || v === undefined || v === '') { return '—' }
  return String(v)
}

/** Leva o mapa até o lote da linha. @param {number} id */
function irAoLoteDoHistorico(id) {
  if (typeof destacarPorId === 'function' && mapaState.porId.has(id)) {
    destacarPorId(id)
    return
  }
  // Lote apagado não está na camada. O histórico é justamente o lugar onde ele
  // ainda aparece, então a resposta é dizer isso — e não um clique que não faz nada.
  toast('Este lote não está no desenho atual. Se foi apagado, use Desfazer.', 'aviso')
}

/**
 * Desfazer. Confirma sempre, e a confirmação diz que a reversão FICA
 * REGISTRADA: é ato sobre ato, e quem clica precisa saber que o próprio
 * desfazer entra na trilha com o nome dele.
 *
 * @param {number} id linha da auditoria
 */
function desfazerNoCadastro(id) {
  const l = hcState.linhas.find(x => x.id === id)
  if (!l) { return }

  confirmarAcao({
    titulo: 'Desfazer: ' + l.acao,
    mensagem: `${l.alvo} — ${l.quando}, por ${l.quem}.\n\n`
      + 'A reversão não apaga o registro original: ela grava uma linha nova no '
      + 'histórico, com o seu nome. Quem ler depois vê as duas.',
    textoBtn: 'Desfazer',
    perigo: true,
    onConfirm: async () => {
      const d = await postCadastro('/api/trilha/' + id + '/desfazer', {})
      if (!d) { return }
      toast(d.message)
      // O desenho mudou: a camada é refeita antes da lista, senão o mapa fica
      // mostrando o estado anterior ao lado de um histórico que já não bate.
      limparLotesDoMapa()
      await carregarLotesVisiveis()
      carregarHistoricoCadastral()
    },
  })
}
