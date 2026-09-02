// ══════════════════════════════════════════════
// EDIFICAÇÕES — o que está construído dentro do lote
//
// A multa de obras é por metro quadrado construído. Até aqui esse número só
// existia como o que a trena do fiscal anotou na vistoria: não havia como
// conferir, nem como mostrar no croqui que acompanha a peça.
//
// Aqui a construção é DESENHADA dentro do lote, com a mesma ferramenta do
// lote (desenho.js) e com as mesmas medidas na tela. O servidor recusa
// polígono que não caiba inteiro no lote — ver EdificacaoController.
// ══════════════════════════════════════════════

const edifState = {
  /** @type {number|null} lote cujas edificações estão desenhadas */ loteId: null,
  /** @type {L.Polygon[]} */ camadas: [],
  /** @type {Array<Object>} */ lista: [],
  areaConstruida: 0,
}

/** Traço mais fino que o do lote: é o que está DENTRO dele, não outra divisa. */
const COR_EDIFICACAO = '#7C3AED'

// Ícone próprio, e não o ICO_LIXO de parametros.js: aquele arquivo só é
// carregado para administrador, e a curadoria cadastral é um poder
// transversal — um curador que não fosse admin tomaria ReferenceError aqui.
const ICO_LIXO_EDIF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
  <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
  <path d="M10 11v6M14 11v6"/></svg>`

// ── CARREGAR E PINTAR ────────────────────────────────────────

/**
 * Busca as edificações do lote e as desenha.
 *
 * @param {number} loteId
 * @param {boolean} [pintar] desenhar no mapa (falso quando só se quer a soma)
 */
async function carregarEdificacoes(loteId, pintar = true) {
  if (!loteId) { return null }

  try {
    const r = await fetch(`/api/imoveis/${loteId}/edificacoes`, { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const d = await r.json()

    edifState.loteId = loteId
    edifState.lista = d.edificacoes
    edifState.areaConstruida = d.area_construida_m2

    if (pintar) { pintarEdificacoes() }
    return d
  } catch (e) {
    console.error(e)
    return null
  }
}

/** Painel próprio, acima dos lotes: a construção fica DENTRO do lote na tela. */
function _paneEdificacoes() {
  const mapa = mapaState.obj
  if (!mapa.getPane('edificacoes')) {
    const p = mapa.createPane('edificacoes')
    p.style.zIndex = 620
    // Surdo: o clique tem de continuar chegando ao lote embaixo, senão tocar
    // na casa deixaria de abrir a ficha do imóvel.
    p.style.pointerEvents = 'none'
  }
  return 'edificacoes'
}

function pintarEdificacoes() {
  limparEdificacoes()
  const mapa = mapaState.obj
  if (!mapa) { return }

  edifState.camadas = edifState.lista.map(e => L.geoJSON(e.geometry, {
    pane: _paneEdificacoes(),
    interactive: false,
    style: {
      color: COR_EDIFICACAO, weight: 1.6, opacity: .95,
      fillColor: COR_EDIFICACAO, fillOpacity: .28,
    },
  }).addTo(mapa))
}

function limparEdificacoes() {
  const mapa = mapaState.obj
  edifState.camadas.forEach(c => { if (mapa) { mapa.removeLayer(c) } })
  edifState.camadas = []
}

// ── DESENHAR ─────────────────────────────────────────────────

/**
 * Desenha uma edificação dentro do lote selecionado.
 *
 * Exige lote selecionado ANTES de começar, e não depois: o polígono só faz
 * sentido em relação a um lote, e descobrir no fim que não havia lote nenhum
 * custaria o desenho inteiro.
 */
function desenharEdificacao() {
  const p = state.selecionado?.properties
  if (!p?.id) {
    toast('Toque primeiro no lote onde está a construção.', 'err')
    return
  }

  toast('Contorne a construção. Duplo toque fecha.', 'aviso')

  iniciarDesenho({
    modo: 'poligono',
    // O encaixe fica LIGADO: o muro da edícula costuma nascer na divisa, e
    // encostar nela de olho deixa a fresta de sempre.
    snap: true,
    onConcluir: g => {
      // O nome é pedido DEPOIS de fechar o contorno, e fechar a caixinha sem
      // confirmar descarta a construção. `pedirTexto` (ui.js) não avisa quando
      // é cancelada — então a saída por desistência é dita na própria dica, em
      // vez de fingir que existe um caminho de volta.
      pedirTexto({
        titulo: 'Descrição da construção',
        rotulo: 'Casa, edícula, barracão… (opcional)',
        dica: 'Fechar esta caixa sem confirmar descarta o contorno.',
        minimo: 0,
        textoBtn: 'Gravar edificação',
        onOk: nome => gravarEdificacao(p.id, g, nome || null),
      })
    },
  })
}

/** @param {number} loteId @param {Object} geometry @param {string|null} descricao */
async function gravarEdificacao(loteId, geometry, descricao) {
  const d = await postCadastro(`/api/imoveis/${loteId}/edificacoes`, { geometry, descricao })
  if (!d) { return }

  toast(d.message)
  await carregarEdificacoes(loteId)
  renderCroquis()
}

/** @param {number} id */
function excluirEdificacao(id) {
  const e = edifState.lista.find(x => x.id === id)
  confirmarAcao({
    titulo: 'Remover edificação',
    mensagem: `Apaga do desenho ${e?.descricao ? '"' + e.descricao + '"' : 'esta construção'}`
      + ` (${fmtNum(e?.area_m2 || 0)} m²). A área construída do imóvel muda junto.`,
    perigo: true,
    onConfirm: async () => {
      const r = await fetch('/api/edificacoes/' + id, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      })
      const d = await r.json()
      if (!r.ok) { toast(d.message || 'Não foi possível remover', 'err'); return }
      toast(d.message)
      await carregarEdificacoes(edifState.loteId)
      renderCroquis()
    },
  })
}

// ── A ABA CROQUIS DA FICHA ───────────────────────────────────

/**
 * Lista as construções do imóvel, com a área de cada uma e a soma.
 *
 * A soma é o que a vistoria vai sugerir como área construída. Ela aparece
 * aqui, e não só lá, porque é nesta tela que se descobre que falta desenhar
 * a edícula — depois, na vistoria, o número já teria sido usado.
 */
function renderCroquis() {
  const alvo = document.getElementById('fi-lista-croquis')
  if (!alvo) { return }

  const pode = window.PODE_CURAR_CADASTRO
  const botao = pode
    ? `<button class="btn out-verde sm" onclick="desenharEdificacaoDaFicha()">
         + Desenhar edificação</button>`
    : ''

  if (!edifState.lista.length) {
    alvo.innerHTML = `<div class="vazio-msg">Nenhuma edificação desenhada neste imóvel.</div>${botao}`
    return
  }

  alvo.innerHTML = `
    <div class="sec-simples">Área construída
      <span class="cont">${fmtNum(edifState.areaConstruida)} m²</span></div>
    <p class="aviso-legal">
      É o que está <b>desenhado</b>. Na vistoria ele aparece ao lado da área
      <b>aferida em campo</b> — quando as duas discordam, a diferença é o assunto.
    </p>
    ${edifState.lista.map(e => `
      <div class="par-linha">
        <div class="principal">
          <b>${esc(e.descricao || 'Edificação')}</b>
          <span>${fmtNum(e.area_m2)} m²</span>
        </div>
        ${pode ? `<button class="acao-x" onclick="excluirEdificacao(${e.id})"
                   title="Remover">${ICO_LIXO_EDIF}</button>` : ''}
      </div>`).join('')}
    ${botao}`
}

/**
 * Desenhar a partir da ficha: fecha a ficha antes.
 *
 * O desenho acontece no mapa, e a ficha é um modal que o cobre inteiro —
 * deixá-la aberta seria pedir para contornar uma construção que não se vê.
 */
function desenharEdificacaoDaFicha() {
  fModalBtn('m-ficha')
  desenharEdificacao()
}
