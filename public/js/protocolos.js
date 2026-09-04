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

// ── LISTA ────────────────────────────────────────────────────

async function carregarProtocolos() {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(protoState.filtros)) { if (v) p.set(k, v) }

  const alvo = document.getElementById('lista-protocolos')
  alvo.innerHTML = '<div class="lista-vazia">Carregando…</div>'
  try {
    const r = await fetch('/api/protocolos?' + p, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    protoState.lista = d.protocolos
    renderProtocolos()
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="lista-vazia">Não foi possível carregar os protocolos.</div>'
  }
}

function renderProtocolos() {
  const alvo = document.getElementById('lista-protocolos')
  document.getElementById('cont-proto').textContent = protoState.lista.length

  if (!protoState.lista.length) {
    alvo.innerHTML = '<div class="lista-vazia">Nenhum protocolo com esses filtros.</div>'
    return
  }

  alvo.innerHTML = ehTelaLarga() ? tabelaProtocolos() : cartoesProtocolos()
}

/**
 * A FILA EM TABELA, no computador.
 *
 * A coluna que justifica a tabela aqui é "Responsável": lida de cima a baixo,
 * ela mostra de uma vez quantos protocolos estão sem dono — que é o risco do
 * módulo, porque o prazo corre contra a administração enquanto ninguém assume.
 *
 * @returns {string}
 */
function tabelaProtocolos() {
  const linhas = protoState.lista.map(p => {
    const semDono = p.responsavel === 'Não distribuído'

    return `
      <tr class="${semDono ? 'st-sem-dono' : ''}" onclick="abrirProtocolo(${p.id})">
        <td>
          <span class="tl-num"><span class="proto-badge">${esc(p.numero)}</span></span>
          <span class="tl-sub">${esc(p.tipo_rotulo)}</span>
        </td>
        <td class="tl-forte" title="${esc(p.requerente)}">${esc(p.requerente)}</td>
        <td class="tl-fraco" title="${esc(p.imovel)}">${esc(p.imovel)}</td>
        <td class="${semDono ? 'tl-falta' : 'tl-fraco'}">
          ${semDono ? 'não distribuído' : esc(p.responsavel)}
        </td>
        <td class="tl-fraco">${esc(p.data ?? '—')}</td>
        <td>
          <span class="tl-tags">
            <span class="badge ${esc(p.situacao.classe)}">${esc(p.situacao.texto)}</span>
            ${p.prazo ? `<span class="badge ${esc(p.prazo.classe)}">${esc(p.prazo.texto)}</span>` : ''}
          </span>
        </td>
        <td class="tl-acao">
          <button type="button" class="card-opcoes-btn" title="Opções"
                  onclick="abrirOpcoesProto(event, ${p.id})">${ICO_TRES_PONTOS}</button>
        </td>
      </tr>`
  }).join('')

  return `
    <div class="tabela-wrap">
      <table class="tabela-lista tl-prot">
        <thead><tr>
          <th>Protocolo</th><th>Requerente</th><th>Imóvel</th>
          <th>Responsável</th><th>Entrada</th><th>Situação</th><th class="tl-acao"></th>
        </tr></thead>
        <tbody>${linhas}</tbody>
      </table>
    </div>`
}

/**
 * O MENU DA LINHA.
 *
 * Só o que já existe e já é permitido: abrir a ficha, e assumir quando o
 * protocolo está sem dono. Distribuir para terceiros continua não existindo em
 * lugar nenhum do sistema, e um menu não é o lugar de inventar competência.
 *
 * @param {Event} ev @param {number} id
 */
function abrirOpcoesProto(ev, id) {
  const p = protoState.lista.find(x => x.id === id)
  if (!p) { return }

  const itens = [{
    rotulo: 'Abrir o protocolo',
    obs: 'A ficha, com histórico e parecer.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>`,
    acao: () => abrirProtocolo(id),
  }]

  if (p.responsavel === 'Não distribuído' && window.PODE_EDITAR) {
    itens.push({
      rotulo: 'Assumir o protocolo',
      obs: 'Passa a constar como seu, e o prazo passa a ser cobrado de você.',
      icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
        stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
      acao: () => confirmarAcao({
        titulo: 'Assumir o protocolo',
        mensagem: `O protocolo ${p.numero} passa a constar como seu.`,
        textoBtn: 'Assumir',
        onConfirm: () => salvarProtocolo({ responsavel_id: window.USUARIO_ID }, id),
      }),
    })
  }

  abrirMenuNovo(ev, itens)
}

/** @returns {string} a mesma fila em cartões, no celular */
function cartoesProtocolos() {
  return protoState.lista.map(p => {
    const tags = [
      `<span class="badge ${esc(p.situacao.classe)}">${esc(p.situacao.texto)}</span>`,
      p.prazo ? `<span class="badge ${esc(p.prazo.classe)}">${esc(p.prazo.texto)}</span>` : '',
    ].join('')

    const linhas = [
      ['Requerente', esc(p.requerente)],
      ['Imóvel', esc(p.imovel)],
      // Sem dono, o prazo do município corre e ninguém é cobrado. Marcar em
      // vermelho é o jeito mais barato de isso não passar batido na lista.
      ['Responsável', p.responsavel === 'Não distribuído'
        ? '<span style="color:var(--red)">não distribuído</span>'
        : esc(p.responsavel)],
    ]

    return `
      <div class="mob-card notif-card" onclick="abrirProtocolo(${p.id})">
        <div class="mc-top">
          <div class="notif-card-l1">
            <span class="proto-badge">${esc(p.numero)}</span>
            <span class="notif-card-tipo">${esc(p.tipo_rotulo)}</span>
            <span class="notif-card-data">${esc(p.data ?? '—')}</span>
          </div>
          <div class="mc-acoes">
            ${tags}
            <div class="card-opcoes">
              <button type="button" class="card-opcoes-btn" title="Opções"
                      onclick="abrirOpcoesProto(event, ${p.id})">${ICO_TRES_PONTOS}</button>
            </div>
          </div>
        </div>
        <div class="notif-card-linhas">
          ${linhas.map(([r, v]) => `<div><span class="notif-card-rot">${r}</span>${v}</div>`).join('')}
        </div>
      </div>`
  }).join('')
}

/** @param {string} campo @param {string} valor */
function filtrarProtocolos(campo, valor) {
  protoState.filtros[campo] = valor
  carregarProtocolos()
}

/**
 * Devolve os filtros ao PADRÃO da tela — que não é "tudo vazio".
 *
 * "Todos os agentes" é o padrão daqui: protocolo chega sem dono, e limpar
 * para "meus" esconderia justamente o que ninguém assumiu (ver o comentário
 * do seletor no Blade).
 */
function limparFiltrosProtocolos() {
  protoState.filtros = { tipo: '', situacao: '', agente: 'todos', busca: '' }

  document.querySelectorAll('#po-protocolos .lista-form select').forEach(s => {
    s.value = s.getAttribute('onchange')?.includes("'agente'") ? 'todos' : ''
  })
  document.querySelectorAll('#po-protocolos .lista-form input').forEach(i => { i.value = '' })

  carregarProtocolos()
}

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
