// ══════════════════════════════════════════════
// MÓDULO: PROTOCOLOS
//
// Vistorias SOLICITADAS pelo contribuinte (habite-se, calçada, contestação de
// área, renovação de alvará, desmembramento). O cartão repete o padrão da aba
// Documentos de propósito: é o mesmo servidor lendo as duas listas, e duas
// gramáticas visuais diferentes só aumentariam o custo de leitura.
//
// A diferença de fundo em relação a Documentos: aqui o prazo corre CONTRA a
// administração. Por isso o padrão do filtro de agente é "todos" e existe o
// recorte "não distribuídos" — protocolo parado sem dono é o risco do módulo.
// ══════════════════════════════════════════════

/** Estado da aba Protocolos. */
const protoState = {
  /** @type {Array<Object>} */ lista: [],
  filtros: { tipo: '', situacao: '', agente: 'todos', busca: '' },
  /** @type {Object|null} */   atual: null,
}

// ── A LISTA SAIU DAQUI ───────────────────────────────────────
//
// Protocolo e ordem de serviço passaram a dividir UMA fila, em demandas.js:
// eram duas abas respondendo à mesma pergunta, e obrigavam a olhar duas telas
// para saber o que estava pendente.
//
// O que ficou neste arquivo é o que NÃO se unificou — a ficha, o formulário e
// as regras do protocolo, que não são as da ordem de serviço.
//
// `carregarProtocolos` continua existindo porque vários pontos a chamam para
// atualizar a tela depois de gravar; hoje ela recarrega a fila inteira.
function carregarProtocolos() { return carregarDemandas() }
// ── FICHA ────────────────────────────────────────────────────

/** @param {number} id */
/**
 * Abre a ficha do protocolo.
 *
 * A lista da aba é o caminho comum, mas não é o único: o protocolo também
 * aparece na linha do tempo do imóvel, e de lá `protoState.lista` está vazia.
 * Antes a função saía calada nesse caso — o clique não fazia nada e não havia
 * o que investigar. Agora, quando o protocolo não está em mãos, busca-se no
 * servidor.
 *
 * @param {number} id
 */
async function abrirProtocolo(id) {
  let p = protoState.lista.find(x => x.id === id)

  if (!p) {
    try {
      const r = await fetch('/api/protocolos/' + id, { headers: { Accept: 'application/json' } })
      if (!r.ok) { throw new Error('HTTP ' + r.status) }
      p = (await r.json()).protocolo
    } catch (e) {
      console.error(e)
      toast('Não foi possível abrir o protocolo', 'err')
      return
    }
  }

  protoState.atual = p

  document.getElementById('pf-numero').textContent = p.numero
  document.getElementById('pf-tipo').textContent   = p.tipo_rotulo
  document.getElementById('pf-corpo').innerHTML = [
    ['Protocolado em', esc(p.data ?? '—')],
    ['Requerente', esc(p.requerente)],
    ['Imóvel', esc(p.imovel)],
    ['Responsável', esc(p.responsavel)],
    ['Situação', `<span class="badge ${esc(p.situacao.classe)}">${esc(p.situacao.texto)}</span>`],
    ['Prazo', p.prazo ? `<span class="badge ${esc(p.prazo.classe)}">${esc(p.prazo.texto)}</span>` : 'sem prazo fixado'],
    ['Objeto', esc(p.objeto || '—')],
  ].map(([r, v]) => `<div class="ficha-linha"><span class="ficha-rot">${r}</span><span>${v}</span></div>`).join('')

  document.getElementById('pf-situacao').value = ''
  document.getElementById('pf-parecer').value  = ''

  // Protocolo de desmembramento/unificação já deferido e ainda sem vistoria:
  // oferece registrar a vistoria que vai fundamentar o ato. É o caminho de
  // quem parte do processo, e não do mapa.
  const caixa = document.getElementById('pf-vistoria-cadastral')
  if (caixa) {
    caixa.hidden = ! p.espera_vistoria
    if (p.espera_vistoria) {
      caixa.innerHTML = `<div class="cad-nota">Deferido. O ato cadastral depende de uma
        vistoria do imóvel — é ela que confirma em campo o que o processo autorizou.</div>
        <button class="btn primary sm" style="width:100%"
                onclick="vistoriaDoProtocolo(${p.id}, ${p.lote_id})">Registrar vistoria</button>`
    }
  }

  openModal('m-proto')
}

/**
 * Abre o formulário de vistoria já amarrado a este protocolo.
 *
 * O imóvel vem do próprio protocolo, então o fiscal não precisa achá-lo no
 * mapa — que é justamente o atrito de quem começou pelo processo.
 *
 * @param {number} protocoloId @param {number} loteId
 */
async function vistoriaDoProtocolo(protocoloId, loteId) {
  fModalBtn('m-proto')

  try {
    const r = await fetch('/api/imoveis/' + loteId, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()

    // `novaVistoria()` lê o lote de `state.selecionado`; o formato é o mesmo
    // da feição do mapa, com as propriedades dentro de `properties`.
    state.selecionado = { properties: {
      id: d.id, bairro: d.bairro, quadra: d.quadra, numero_lote: d.lote,
    } }
    vState.protocoloId = protocoloId
    await novaVistoria()
  } catch (e) {
    console.error(e)
    toast('Não foi possível abrir o imóvel do protocolo.', 'err')
  }
}

/**
 * Assume o protocolo para o usuário corrente.
 *
 * Distribuir para terceiros exigiria uma lista de servidores e uma regra de
 * quem pode distribuir para quem; enquanto isso não existe, "assumir" já tira
 * o protocolo do limbo, que é o problema real.
 */
async function assumirProtocolo() {
  await salvarProtocolo({ responsavel_id: window.USUARIO_ID })
}

/** Grava situação e parecer da ficha aberta. */
async function concluirProtocolo() {
  const situacao = document.getElementById('pf-situacao').value
  const parecer  = document.getElementById('pf-parecer').value.trim()
  if (!situacao) { toast('Escolha a nova situação', 'err'); return }
  await salvarProtocolo({ situacao, parecer: parecer || null })
}

/**
 * @param {Object} dados
 * @param {number|undefined} id o protocolo a gravar; por omissão, o da ficha
 *   aberta — o menu da lista grava sem abrir ficha nenhuma.
 */
async function salvarProtocolo(dados, id = protoState.atual?.id) {
  if (!id) return
  try {
    const r = await fetch('/api/protocolos/' + id, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
      },
      body: JSON.stringify(dados),
    })
    const d = await r.json()
    if (!r.ok) { toast(d.message || primeiroErroProto(d), 'err'); return }
    toast(d.message)
    fModalBtn('m-proto')
    carregarProtocolos()
  } catch (e) {
    console.error(e)
    toast('Falha de rede ao salvar o protocolo', 'err')
  }
}

// ── NOVO PROTOCOLO ───────────────────────────────────────────

/**
 * Abre o cadastro. O lote é opcional de propósito: muito requerimento chega
 * antes de alguém saber a que lote ele se refere, e travar o cadastro nisso
 * empurraria o registro para fora do sistema.
 */
function novoProtocolo() {
  const sel = state.selecionado?.properties
  document.getElementById('np-lote').value = sel?.id ?? ''
  document.getElementById('np-imovel').textContent = sel
    ? `${sel.bairro} · Quadra ${sel.quadra ?? '—'} · Lote ${sel.numero_lote ?? '—'}`
    : 'Nenhum lote selecionado no mapa (opcional)'

  document.getElementById('np-numero').value = ''
  document.getElementById('np-requerente').value = ''
  document.getElementById('np-documento').value = ''
  document.getElementById('np-contato').value = ''
  document.getElementById('np-objeto').value = ''
  document.getElementById('np-data').value = dataHojeLocal()
  document.getElementById('np-prazo').value = ''
  atualizarDisplayData(document.getElementById('np-data'))
  openModal('m-novo-proto')
}

async function salvarNovoProtocolo() {
  const corpo = {
    numero:               document.getElementById('np-numero').value.trim(),
    tipo:                 document.getElementById('np-tipo').value,
    lote_id:              document.getElementById('np-lote').value || null,
    requerente_nome:      document.getElementById('np-requerente').value.trim(),
    requerente_documento: document.getElementById('np-documento').value.trim() || null,
    requerente_contato:   document.getElementById('np-contato').value.trim() || null,
    protocolado_em:       document.getElementById('np-data').value,
    prazo_resposta:       document.getElementById('np-prazo').value || null,
    objeto:               document.getElementById('np-objeto').value.trim() || null,
  }
  if (!corpo.numero || !corpo.requerente_nome || !corpo.protocolado_em) {
    toast('Número, requerente e data são obrigatórios', 'err')
    return
  }

  try {
    const r = await fetch('/api/protocolos', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
      },
      body: JSON.stringify(corpo),
    })
    const d = await r.json()
    if (!r.ok) { toast(d.message || primeiroErroProto(d), 'err'); return }
    toast(d.message)
    fModalBtn('m-novo-proto')
    carregarProtocolos()
  } catch (e) {
    console.error(e)
    toast('Falha de rede ao registrar o protocolo', 'err')
  }
}

/** Primeira mensagem de erro de validação do Laravel. */
function primeiroErroProto(d) {
  const e = d?.errors && Object.values(d.errors)[0]
  return Array.isArray(e) ? e[0] : 'Não foi possível concluir a operação'
}
