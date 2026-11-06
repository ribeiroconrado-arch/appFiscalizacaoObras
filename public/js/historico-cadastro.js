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
    // O QUE O ATO FEZ, numa frase do servidor ("Lotes 12 + 13 → 1213 ·
    // 600,00 m²"). Sem ela, o primeiro campo que mudou, de quê para quê.
    const m = (l.mudou ?? [])[0]
    const oQue = l.resumo
      ? `<div class="hc-resumo">${esc(l.resumo)}</div>`
      : m ? `<div class="hc-resumo"><span class="hc-delta"><b>${esc(m.campo)}</b> ${esc(hcValor(m.de))}
           <span class="hc-seta">→</span> ${esc(hcValor(m.para))}</span></div>` : ''

    const desfazer = l.desfazer?.pode
      ? `<button type="button" class="btn out-vermelho sm" onclick="desfazerNoCadastro(${l.id})">Desfazer</button>`
      : l.desfazer?.motivo
        ? `<span class="hc-sem-volta" title="${esc(l.desfazer.motivo)}">sem volta</span>`
        : ''
    const prancha = ['desmembrou', 'unificou', 'instituiu frações'].includes(l.acao) && l.registro
      ? `<button type="button" class="btn sm" onclick="PranchetaCad.verSalvas(${Number(l.registro)})">Ver prancha</button>` : ''

    // O lote é clicável: leva o mapa até ele. Ver um registro de alteração sem
    // poder olhar o desenho é metade da resposta.
    const alvoTxt = l.registro
      ? `<button type="button" class="hc-ir" onclick="irAoLoteDoHistorico(${l.registro})"
           title="Ver no mapa">${esc(l.alvo)}</button>`
      : `<span class="hc-alvo">${esc(l.alvo)}</span>`
    const tipo = HC_ACOES[l.acao] || { rotulo: l.acao, cls: '' }

    return `
      <div class="hc-linha">
        <div class="hc-l1">
          <span class="hc-acao ${tipo.cls}">${esc(tipo.rotulo)}</span>
          <span class="hc-quando">${esc(l.quando)}</span>
        </div>
        <div class="hc-l2">${alvoTxt}${l.bairro ? `<span class="hc-bairro">${esc(l.bairro)}</span>` : ''}</div>
        ${oQue}
        <div class="hc-l3"><span class="hc-quem">${esc(l.quem)}</span>
          <span class="hc-botoes">${prancha}${desfazer}</span></div>
      </div>`
  }).join('')
}

/** O nome de cada ato na lista, e a cor do selo. */
const HC_ACOES = {
  unificou:            { rotulo: 'Unificação',      cls: 'hc-azul' },
  desmembrou:          { rotulo: 'Desmembramento',  cls: 'hc-verde' },
  'instituiu frações': { rotulo: 'Fração ideal',    cls: 'hc-verde' },
  'corrigiu quadra':   { rotulo: 'Quadra corrigida', cls: '' },
  renumerou:           { rotulo: 'Renumeração',     cls: '' },
  excluiu:             { rotulo: 'Exclusão',        cls: 'perigosa' },
}

// ── A LARGURA DA MESA NO HISTÓRICO ──
// Por padrão ela cresce com a tela (CSS: body.mesa-historico); a alça na borda
// direita estica até 70% da janela, e a largura escolhida fica guardada neste
// navegador. Duplo clique na alça volta ao padrão.
const HC_LARGURA_CHAVE = 'hc-largura-mesa'

function _hcAplicarLargura(px) {
  if (px) document.body.style.setProperty('--mesa-hist-w', px + 'px')
  else document.body.style.removeProperty('--mesa-hist-w')
}
try { const v = Number(localStorage.getItem(HC_LARGURA_CHAVE)); if (v) _hcAplicarLargura(v) } catch { /* sem armazenamento */ }

/** Põe a alça na mesa, uma vez só. @param {HTMLElement} mesa */
function montarAlcaDaMesa(mesa) {
  if (!mesa || mesa.querySelector('.mesa-alca')) return
  const alca = document.createElement('div')
  alca.className = 'mesa-alca'
  alca.title = 'Arraste para alargar o histórico · duplo clique volta ao padrão'
  alca.setAttribute('role', 'separator')
  alca.setAttribute('aria-orientation', 'vertical')
  mesa.appendChild(alca)

  alca.addEventListener('pointerdown', e => {
    e.preventDefault()
    alca.setPointerCapture(e.pointerId)
    const esquerda = mesa.getBoundingClientRect().left
    const mover = ev => {
      const w = Math.round(Math.min(Math.max(ev.clientX - esquerda, 348), window.innerWidth * 0.7))
      _hcAplicarLargura(w)
    }
    const soltar = () => {
      alca.removeEventListener('pointermove', mover)
      alca.removeEventListener('pointerup', soltar)
      alca.removeEventListener('pointercancel', soltar)
      const w = parseInt(document.body.style.getPropertyValue('--mesa-hist-w'), 10)
      try { if (w) localStorage.setItem(HC_LARGURA_CHAVE, String(w)) } catch { /* sem armazenamento */ }
    }
    alca.addEventListener('pointermove', mover)
    alca.addEventListener('pointerup', soltar)
    alca.addEventListener('pointercancel', soltar)
  })
  alca.addEventListener('dblclick', () => {
    _hcAplicarLargura(null)
    try { localStorage.removeItem(HC_LARGURA_CHAVE) } catch { /* sem armazenamento */ }
  })
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
      if (d.lote_restaurado_id) await mostrarLoteRestaurado(Number(d.lote_restaurado_id))
      await carregarHistoricoCadastral()
    },
  })
}

/** A restauração cria outro id; o registro apagado nunca é usado para localizar a volta. */
async function mostrarLoteRestaurado(id) {
  try {
    if (!mapaState.porId.has(id)) {
      const r = await fetch('/api/imoveis/' + id + '/geometria', { headers: { Accept: 'application/json' } })
      const d = await r.json()
      if (!r.ok || !d.geometry) throw new Error('Geometria indisponível')
      mapaState.obj.fitBounds(L.geoJSON(d.geometry).getBounds(), { padding: [80, 80], maxZoom: ZOOM_DO_LOTE, animate: false })
      // Recarrega a feição completa, com os atributos usados nas cores e no balão.
      limparLotesDoMapa()
      await carregarLotesVisiveis()
    }
    if (!mapaState.porId.has(id)) throw new Error('Lote ausente após a recarga')
    destacarPorId(id)
  } catch (e) {
    console.error(e)
    toast('Lote restaurado no cadastro, mas não foi possível destacá-lo no mapa. Atualize a página.', 'aviso')
  }
}
