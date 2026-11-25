// ══════════════════════════════════════════════
// MÓDULO: SERVIÇOS — a fila de trabalho
//
// Tudo o que há para fazer numa lista só (acertado com ele em 25/11/2026):
//   Obras    — protocolos, ordens de serviço e sinalizações (era
//              "Protocolos e ordens de serviço");
//   Posturas — protocolos e denúncias gerais (o mesmo formulário), ordens de
//              serviço e sinalizações.
// Nos dois, vistorias, autos e notificações ficam na tela Documentos; o
// sossego, na tela Denúncias. O que entra em cada módulo quem decide é o
// servidor (DemandaController::TIPOS_DO_MODULO); esta tela monta o filtro
// com o que ele devolve.
//
// O QUE CONTINUA SEPARADO: o formulário de cada um, a ficha de cada um, e as
// tabelas do banco. Este módulo só LÊ — abrir e criar continuam em
// protocolos.js, os.js e sinalizacoes.js.
// ══════════════════════════════════════════════

const dmState = {
  /** @type {Object} filtros da fila */
  filtros: { tipo: '', agente: 'todos', situacao: '', busca: '' },
  /** @type {Array<Object>} a lista como veio do servidor */ lista: [],
  /** @type {boolean} tipos e situações do filtro já foram montados */ filtrosProntos: false,
  /** Permissões que o servidor mandou com a lista. */ pode: null,
}

/** O selo de cada tipo, curto: é o que a lista única precisa dizer antes de tudo. */
const DM_SELO = { protocolo: 'Protocolo', os: 'OS', vistoria: 'Vistoria', sinalizacao: 'Sinalização', documento: 'Documento' }
const DM_GRUPO = { protocolo: 'Protocolo', os: 'Ordem de serviço', vistoria: 'Vistoria', documento: 'Documento' }

/**
 * ANOTA O FILTRO, MAS NÃO BUSCA — mesma regra da lista de documentos e da
 * Consulta: quem dispara a consulta é o botão Buscar. Ver `filtrarDocumentos`
 * para o porquê.
 */
function filtrarDemandas(chave, valor) {
  dmState.filtros[chave] = valor
  marcarBuscaPendente('dm-buscar')
}

function limparFiltrosDemandas() {
  // "Todos os responsáveis" é o PADRÃO da fila, e não a ausência de filtro:
  // protocolo e sinalização chegam sem dono, e limpar para "meus" esconderia
  // justamente o que ninguém assumiu.
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
  mostrarCarregandoTela('Buscando os serviços...')
  try {
    const r = await fetch('/api/demandas?' + p, { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const d = await r.json()

    dmState.lista = d.demandas ?? []
    dmState.pode = { protocolar: !!d.pode_protocolar, os: !!d.pode_emitir_os }
    montarFiltrosDemanda(d.tipos, d.situacoes)
    document.getElementById('cont-demandas').textContent = d.total ?? 0
    renderDemandas()
    limparBuscaPendente('dm-buscar')
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="lista-vazia">Não foi possível carregar os serviços.</div>'
  } finally {
    esconderCarregandoTela()
  }
}

/**
 * O seletor de tipo e o de situação, com o que existe NESTE módulo.
 *
 * A situação é AGRUPADA POR TIPO: "Deferido" é resposta a um pedido do
 * cidadão, "Concluída" é estado de um trabalho interno, "Irregular" é o que a
 * vistoria constatou. Num seletor plano pareceriam alternativas da mesma
 * pergunta.
 *
 * @param {Array<{valor:string, rotulo:string}>} tipos
 * @param {Object<string, Array>} sits
 */
function montarFiltrosDemanda(tipos, sits) {
  if (dmState.filtrosProntos) { return }
  const selTipo = document.getElementById('dm-tipo')
  if (selTipo && tipos) {
    selTipo.innerHTML = '<option value="">Todos os serviços</option>'
      + tipos.map(t => `<option value="${esc(t.valor)}">Só ${esc(t.rotulo.toLowerCase())}</option>`).join('')
    selTipo.value = dmState.filtros.tipo || ''
  }
  const sel = document.getElementById('dm-situacao')
  if (sel && sits) {
    const grupo = (rot, itens) => `<optgroup label="${esc(rot)}">`
      + itens.map(s => `<option value="${esc(s.valor)}">${esc(s.rotulo)}</option>`).join('')
      + '</optgroup>'
    sel.innerHTML = '<option value="">Todas as situações</option>'
      + Object.entries(sits).map(([t, itens]) => grupo(DM_GRUPO[t] || t, itens ?? [])).join('')
  }
  dmState.filtrosProntos = true
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
  if (tipo === 'os') return abrirOs(id)
  if (tipo === 'sinalizacao') {
    // A sinalização mora no mapa: vai até ela (o lote, ou o ponto na via).
    const s = dmState.lista.find(d => d.tipo === 'sinalizacao' && d.id === id)
    if (!s) return
    return s.lote_id ? irAoLoteNoMapa(s.lote_id) : irAoPontoDaSinalizacao(Number(s.lat), Number(s.lon))
  }
  return abrirProtocolo(id)
}

/** A coluna TIPO: é o que a lista única precisa dizer antes de tudo. */
function seloDeTipo(d) {
  // `selo` vem do servidor quando a linha diz mais que o tipo (a denúncia
  // geral de Posturas é um protocolo, mas se lê "Denúncia").
  return `<span class="dm-tipo dm-tipo-${esc(d.tipo)}">${esc(d.selo || DM_SELO[d.tipo] || d.tipo)}</span>`
}

/** A linha sem responsável: em vermelho, para não passar batida. */
const dmResponsavel = d => d.responsavel ? esc(d.responsavel) : '<span class="tl-falta">não distribuído</span>'

function tabelaDemandas() {
  const linhas = dmState.lista.map(d => `
    <tr class="${d.alerta ? 'st-alta' : ''}" onclick="abrirDemanda('${esc(d.tipo)}', ${d.id})">
      <td><span class="tl-cod">${esc(d.numero)}</span></td>
      <td>${seloDeTipo(d)}</td>
      <td title="${esc(d.assunto ?? '')}">${esc(d.assunto ?? '—')}</td>
      <td title="${esc(d.imovel ?? '')}">${esc(d.imovel ?? 'Sem lote')}</td>
      <td class="tl-cinza" title="${esc(d.quem ?? '')}">${esc(d.quem ?? '—')}</td>
      <td class="tl-cinza">${dmResponsavel(d)}</td>
      <td>${esc((d.data ?? '—').slice(0, 5))}</td>
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
          <th>Nº</th><th>Tipo</th><th>Assunto</th><th>Imóvel / local</th><th>Quem</th>
          <th>Responsável</th><th>Data</th><th>Situação</th><th class="tl-acao"></th>
        </tr></thead>
        <tbody>${linhas}</tbody>
      </table>
      <div class="tl-rodape"><span>Mostrando ${dmState.lista.length} serviço(s)</span></div>
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
      [d.tipo === 'sinalizacao' && !d.lote_id ? 'Local' : 'Imóvel', esc(d.imovel ?? 'Não vinculado a lote')],
      ['Responsável', d.responsavel ? esc(d.responsavel) : '<span style="color:var(--red)">não distribuído</span>'],
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

// ── NOVO ─────────────────────────────────────────────────────

/** Ícones de linha do menu Novo (o mesmo traço dos demais menus). */
const DM_ICO = {
  protocolo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4M9 13h6M9 17h4"/></svg>',
  os: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 11l2 2 4-4M9 17h6"/></svg>',
  denuncia: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H9l-5 4z"/><path d="M12 8v3M12 13.5h0"/></svg>',
  sinalizacao: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg>',
}

/**
 * O "Novo" de Serviços: o mesmo menu dos outros "Novo" do sistema
 * (abrirMenuNovo, em ui.js). Só oferece o que a pessoa pode criar — as
 * permissões vêm da tela (data-* do botão, gravados pelo servidor) e quem
 * autoriza de verdade é o servidor de cada um.
 * @param {MouseEvent} ev
 */
async function novoServico(ev) {
  const botao = ev.currentTarget
  const obras = (window.MODULO || 'obras') === 'obras'
  const pode = { protocolar: botao.dataset.protocolar === '1', os: botao.dataset.os === '1' }
  const ops = []

  // Vistoria não entra: ela é da tela Documentos (o "Novo documento"), nos
  // dois módulos — acertado com ele em 25/11/2026.
  if (pode.protocolar) {
    ops.push({ rotulo: 'Protocolo', obs: obras ? 'O que chega de fora: requerimento do contribuinte.' : 'Requerimento registrado no protocolo geral.',
      icone: DM_ICO.protocolo, acao: () => novoProtocolo(obras ? null : 'requerimento') })
    // Em Posturas a denúncia geral usa o mesmo formulário. A de perturbação
    // do sossego continua na tela Denúncias.
    if (!obras) {
      ops.push({ rotulo: 'Denúncia', obs: 'Reclamação recebida. Sossego continua na tela Denúncias.',
        icone: DM_ICO.denuncia, acao: () => novoProtocolo('denuncia') })
    }
  }
  if (pode.os) {
    ops.push({ rotulo: 'Ordem de serviço', obs: 'O que a coordenação manda fazer.', icone: DM_ICO.os, acao: () => novaOs() })
  }
  ops.push({ rotulo: 'Sinalização', obs: 'No mapa: toque e segure no ponto (clique direito no computador).', icone: DM_ICO.sinalizacao,
    acao: () => { irPara('mapa'); toast('Toque e segure no ponto do mapa para sinalizar (no computador, clique direito).') } })

  // Autos e notificações NÃO entram aqui: têm o "Novo documento" da tela
  // Documentos, nos dois módulos (correção dele, 25/11/2026).
  abrirMenuNovo(botao, ops)
}
