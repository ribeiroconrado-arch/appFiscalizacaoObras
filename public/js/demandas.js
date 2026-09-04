// ══════════════════════════════════════════════
// MÓDULO: A FILA DE TRABALHO
//
// Protocolo e ordem de serviço numa lista só.
//
// Eram duas abas, e respondiam à mesma pergunta — "o que há para fazer?" —,
// obrigando a olhar duas telas para saber o que estava pendente. O TIPO virou
// coluna e filtro.
//
// O QUE CONTINUA SEPARADO: o formulário de cada uma, a ficha de cada uma, e as
// tabelas do banco. Só quatro colunas coincidem em significado — protocolo tem
// requerente, CPF, prazo legal e parecer; ordem tem designados, natureza,
// regime, jornadas e assinatura de quem emite. Ver DemandaController.
//
// Este módulo só LÊ. Abrir, criar e editar continuam em protocolos.js e os.js.
// ══════════════════════════════════════════════

const dmState = {
  /** @type {Object} filtros da fila */
  filtros: { tipo: '', agente: 'todos', situacao: '', busca: '' },
  /** @type {Array<Object>} a lista como veio do servidor */ lista: [],
  /** @type {boolean} as situações do seletor já foram montadas */ situacoesProntas: false,
}

/** @param {string} chave @param {string} valor */
function filtrarDemandas(chave, valor) {
  dmState.filtros[chave] = valor
  carregarDemandas()
}

function limparFiltrosDemandas() {
  // "Todos os responsáveis" é o PADRÃO da fila, e não a ausência de filtro:
  // protocolo chega sem dono, e limpar para "meus" esconderia justamente o que
  // ninguém assumiu.
  dmState.filtros = { tipo: '', agente: 'todos', situacao: '', busca: '' }

  const põe = (id, v) => { const e = document.getElementById(id); if (e) { e.value = v } }
  põe('dm-tipo', ''); põe('dm-agente', 'todos'); põe('dm-situacao', ''); põe('dm-busca', '')

  carregarDemandas()
}

async function carregarDemandas() {
  const alvo = document.getElementById('lista-demandas')
  if (!alvo) { return }

  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(dmState.filtros)) { if (v) { p.set(k, v) } }

  alvo.innerHTML = '<div class="lista-vazia">Carregando…</div>'
  try {
    const r = await fetch('/api/demandas?' + p, { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const d = await r.json()

    dmState.lista = d.demandas ?? []
    montarSituacoesDemanda(d.situacoes)
    document.getElementById('cont-demandas').textContent = d.total ?? 0
    renderDemandas()
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="lista-vazia">Não foi possível carregar a fila.</div>'
  }
}

/**
 * O seletor de situação, AGRUPADO POR TIPO.
 *
 * As situações não são as mesmas nos dois: "Deferido" é resposta a um pedido
 * do cidadão, "Concluída" é estado de um trabalho interno. Num seletor plano
 * elas pareceriam alternativas da mesma pergunta.
 *
 * @param {{protocolo:Array,os:Array}} sits
 */
function montarSituacoesDemanda(sits) {
  if (dmState.situacoesProntas || !sits) { return }
  const sel = document.getElementById('dm-situacao')
  if (!sel) { return }

  const grupo = (rot, itens) => `<optgroup label="${esc(rot)}">`
    + itens.map(s => `<option value="${esc(s.valor)}">${esc(s.rotulo)}</option>`).join('')
    + '</optgroup>'

  sel.innerHTML = '<option value="">Todas as situações</option>'
    + grupo('Protocolo', sits.protocolo ?? [])
    + grupo('Ordem de serviço', sits.os ?? [])

  dmState.situacoesProntas = true
}

function renderDemandas() {
  const alvo = document.getElementById('lista-demandas')

  if (!dmState.lista.length) {
    alvo.innerHTML = '<div class="lista-vazia">Nada na fila com esses filtros.</div>'
    return
  }

  alvo.innerHTML = ehTelaLarga() ? tabelaDemandas() : cartoesDemandas()
}

/** Abre a ficha certa para o tipo da linha. @param {string} tipo @param {number} id */
function abrirDemanda(tipo, id) {
  return tipo === 'os' ? abrirOs(id) : abrirProtocolo(id)
}

/** A coluna TIPO: é o que a lista única precisa dizer antes de tudo. */
function seloDeTipo(d) {
  return `<span class="dm-tipo dm-tipo-${d.tipo}">${esc(d.tipo === 'os' ? 'OS' : 'Protocolo')}</span>`
}

function tabelaDemandas() {
  const linhas = dmState.lista.map(d => `
    <tr class="${d.alerta ? 'st-alta' : ''}" onclick="abrirDemanda('${esc(d.tipo)}', ${d.id})">
      <td>${seloDeTipo(d)}</td>
      <td>
        <div class="tl-num"><span class="proto-badge">${esc(d.numero)}</span></div>
        <span class="tl-sub">${esc(d.assunto ?? '')}</span>
      </td>
      <td class="tl-fraco">${esc(d.quem ?? '—')}</td>
      <td class="tl-fraco">${esc(d.imovel ?? 'Não vinculado a lote')}</td>
      <td class="tl-fraco">${d.responsavel
        ? esc(d.responsavel) : '<span class="tl-falta">não distribuído</span>'}</td>
      <td class="tl-fraco">${esc(d.data ?? '—')}</td>
      <td>
        <div class="tl-tags">
          <span class="badge ${esc(d.situacao.classe)}">${esc(d.situacao.texto)}</span>
          ${d.alerta ? `<span class="badge ${esc(d.alerta.classe)}">${esc(d.alerta.texto)}</span>` : ''}
        </div>
      </td>
      <td class="tl-acao">
        <button type="button" class="card-opcoes-btn"
                onclick="event.stopPropagation();abrirDemanda('${esc(d.tipo)}', ${d.id})"
                title="Abrir">⋮</button>
      </td>
    </tr>`).join('')

  return `<div class="tabela-wrap">
      <table class="tabela-lista tl-dem">
        <thead><tr>
          <th>Tipo</th><th>Número</th><th>Quem</th><th>Imóvel</th>
          <th>Responsável</th><th>Data</th><th>Situação</th><th class="tl-acao"></th>
        </tr></thead>
        <tbody>${linhas}</tbody>
      </table>
    </div>`
}

/** O MESMO cartão do protocolo, que já era o padrão da tela — só com o
    selo de tipo à frente, que é o que a lista única acrescenta. */
function cartoesDemandas() {
  return dmState.lista.map(d => {
    const tags = [
      `<span class="badge ${esc(d.situacao.classe)}">${esc(d.situacao.texto)}</span>`,
      d.alerta ? `<span class="badge ${esc(d.alerta.classe)}">${esc(d.alerta.texto)}</span>` : '',
    ].join('')

    const linhas = [
      [esc(d.quem_rotulo), esc(d.quem ?? '—')],
      ['Imóvel', esc(d.imovel ?? 'Não vinculado a lote')],
      // Sem dono, o prazo do município corre e ninguém é cobrado. Marcar em
      // vermelho é o jeito mais barato de isso não passar batido na lista.
      ['Responsável', d.responsavel
        ? esc(d.responsavel)
        : '<span style="color:var(--red)">não distribuído</span>'],
    ]

    return `
      <div class="mob-card notif-card" onclick="abrirDemanda('${esc(d.tipo)}', ${d.id})">
        <div class="mc-top">
          <div class="notif-card-l1">
            ${seloDeTipo(d)}
            <span class="proto-badge">${esc(d.numero)}</span>
            <span class="notif-card-tipo">${esc(d.assunto ?? '')}</span>
            <span class="notif-card-data">${esc(d.data ?? '—')}</span>
          </div>
          <div class="mc-acoes">${tags}</div>
        </div>
        <div class="notif-card-linhas">
          ${linhas.map(([r, v]) => `<div><span class="notif-card-rot">${r}</span>${v}</div>`).join('')}
        </div>
      </div>`
  }).join('')
}
