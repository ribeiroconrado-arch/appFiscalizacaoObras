// ══════════════════════════════════════════════
// O HISTÓRICO DO CADASTRO — e o desfazer
//
// Abre pelo relógio da Alteração cadastral, sem sair do mapa. Desde 22/11/2026
// é um MODAL (#m-historico) com duas abas: "Atos" (historico-atos.js, a mesma
// lista do "Ver tudo" do Painel) e "Edições do mapa" (esta), que tem o Desfazer.
// Antes era o lado direito da mesa, estreito para uma lista de leitura.
//
// O recorte é o MAPA: quadra corrigida, lote renumerado, unificação,
// desmembramento, exclusão e restauração. A auditoria do sistema — usuários,
// documentos, protocolos — é outro assunto, com outro público.
// ══════════════════════════════════════════════

const hcState = {
  /** @type {Array<Object>} */ linhas: [],
  /** @type {'atos'|'edicoes'} a aba aberta por último */ aba: 'atos',
}

/** O relógio da régua: abre o modal na última aba usada. Não larga a ferramenta em curso. */
function abrirHistoricoCadastral() {
  openModal('m-historico')
  abaDoHistorico(hcState.aba)
}

/** @param {'atos'|'edicoes'} aba */
function abaDoHistorico(aba) {
  hcState.aba = aba
  document.querySelectorAll('#m-historico .sub-abas button').forEach(b => b.classList.toggle('at', b.dataset.mh === aba))
  document.querySelectorAll('#m-historico .mh-painel').forEach(p => { p.hidden = p.dataset.mh !== aba })
  if (aba === 'atos') prepararHistorico('m-historico')
  else carregarHistoricoCadastral()
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
      ? `<button type="button" class="btn sm" onclick="fModalBtn('m-historico'); PranchetaCad.verSalvas(${Number(l.registro)})">Ver prancha</button>` : ''

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

function hcValor(v) {
  if (v === null || v === undefined || v === '') { return '—' }
  return String(v)
}

/** Leva o mapa até o lote da linha. @param {number} id */
function irAoLoteDoHistorico(id) {
  if (typeof destacarPorId === 'function' && mapaState.porId.has(id)) {
    fModalBtn('m-historico')   // o modal cobre o mapa: fecha para mostrar o lote
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
