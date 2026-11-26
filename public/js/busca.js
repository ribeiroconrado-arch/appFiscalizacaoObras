// ══════════════════════════════════════════════
// MÓDULO: BUSCA DE IMÓVEIS
//
// Consulta de cadastro sem abrir o mapa. A camada de satélite é serviço pago
// por requisição, e conferir a situação de um lote — a maior parte das
// consultas de balcão — não deveria gerar faturamento de imagem aérea só para
// ler quatro campos de texto.
//
// Vários resultados viram tabela com o essencial. Escolhido um imóvel, a ficha
// abre NA PRÓPRIA TELA e não em modal: aqui não há mapa por baixo para
// preservar, então a sobreposição só atrapalharia.
// ══════════════════════════════════════════════

const bState = {
  /** @type {string[]|null} carregado uma vez por sessão */ bairros: null,
  /**
   * As ruas que a busca PODE achar — só as dos bairros cujo cadastro já foi
   * carregado e amarrado. Ver BuscaController::logradouros.
   * @type {string[]|null}
   */
  logradouros: null,
  /** @type {Array<Object>} a página aberta */ resultado: [],
  /** @type {URLSearchParams|null} os filtros do último Buscar (páginas e PDF) */ params: null,
  /** @type {Object|null} a última resposta, para "Voltar à lista" */ ultima: null,
}

/**
 * A tabela VAZIA, com o cabeçalho, que a tela mostra antes de qualquer busca
 * (26/11/2026, a pedido dele): o lugar do resultado existe desde a abertura,
 * e a primeira busca só o preenche.
 */
function tabelaInicialBusca() {
  renderTabelaBusca({ imoveis: [], total: 0, inicial: true })
}

/** Primeira entrada na aba: popula o filtro de bairros. */
async function prepararBusca() {
  // A cada entrada, e não só na primeira: sem resultado na tela, a tabela vazia.
  if (!document.getElementById('busca-resultado')?.children.length) tabelaInicialBusca()
  if (bState.bairros) return
  try {
    const r = await fetch('/api/imoveis/bairros', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    bState.bairros = d.bairros
    document.getElementById('bs-bairro').innerHTML =
      '<option value="">— todos —</option>' +
      d.bairros.map(b => `<option value="${esc(b)}">${esc(b)}</option>`).join('')
  } catch (e) {
    console.error(e)
  }
}

// ── COMBOBOX DE LOGRADOURO ───────────────────────────────────
//
// O logradouro NÃO VEM DO DESENHO: o DWG traz o polígono, a quadra e o lote,
// e nunca o nome da rua. Ele vem do cadastro da prefeitura, e chega ao lote
// pelo casamento bairro + quadra + lote.
//
// Por isso a lista é fechada — só oferece rua que pode achar alguma coisa — e
// por isso ela pode estar VAZIA: enquanto não houver bairro com cadastro
// carregado e amarrado, não há rua nenhuma a oferecer. Nesse caso o combo diz
// isso, em vez de abrir vazio e parecer defeito.

/** Carrega as ruas uma vez por sessão. */
async function carregarLogradouros() {
  if (bState.logradouros) { return bState.logradouros }
  try {
    const r = await fetch('/api/imoveis/logradouros', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    bState.logradouros = d.logradouros ?? []
  } catch (e) {
    console.error(e)
    bState.logradouros = []
  }
  return bState.logradouros
}

/** @param {string} texto */
async function buscarLogradouro(texto) {
  const alvo = document.getElementById('bs-logr-sugestoes')
  if (!alvo) { return }

  const ruas = await carregarLogradouros()
  const q = texto.trim().toLowerCase()

  if (!ruas.length) {
    alvo.classList.add('open')
    alvo.innerHTML = '<div class="ac-vazio">Nenhum logradouro disponível — '
      + 'depende de carregar o cadastro da prefeitura do bairro.</div>'
    return
  }

  const achados = (q ? ruas.filter(r => r.toLowerCase().includes(q)) : ruas).slice(0, 10)

  alvo.classList.toggle('open', achados.length > 0)
  alvo.innerHTML = achados.map(r =>
    `<div class="ac-item" onmousedown="event.preventDefault(); escolherLogradouro(${jsArg(r)})">${esc(r)}</div>`).join('')
}

/** @param {string} rua */
function escolherLogradouro(rua) {
  document.getElementById('bs-logradouro').value = rua
  fecharListaLogradouro()
  marcarPrecedencia()
}

function fecharListaLogradouro() {
  const alvo = document.getElementById('bs-logr-sugestoes')
  if (!alvo) { return }
  alvo.classList.remove('open')
  alvo.innerHTML = ''
}

// Clicar fora fecha — é o que se espera de um dropdown.
document.addEventListener('mousedown', ev => {
  if (!ev.target.closest('.bc-logr')) {
    fecharListaLogradouro()
  }
})

function limparBusca() {
  for (const id of ['bs-bairro', 'bs-quadra', 'bs-lote', 'bs-inscricao',
                    'bs-logradouro', 'bs-numero', 'bs-bci-de', 'bs-bci-ate', 'bs-vistoria']) {
    const e = document.getElementById(id)
    if (e) { e.value = '' }
  }
  fecharListaLogradouro()
  for (const id of ['bs-embargo', 'bs-pendente', 'bs-sem-vistoria', 'bs-inativos']) {
    const e = document.getElementById(id)
    if (e) { e.checked = false }
  }
  tabelaInicialBusca()
  bState.resultado = []
  bState.params = null
  bState.ultima = null
  marcarPrecedencia()
}

// Enter em qualquer campo da busca é o botão Buscar — menos quando a lista de
// ruas está aberta, onde o Enter é de quem escolhe uma rua.
document.addEventListener('keydown', ev => {
  if (ev.key !== 'Enter' || !ev.target.closest?.('#t-busca .busca-form')) return
  if (!ev.target.matches('input:not([type=checkbox])')) return
  if (document.getElementById('bs-logr-sugestoes')?.classList.contains('open') && ev.target.id === 'bs-logradouro') {
    fecharListaLogradouro()
  }
  ev.preventDefault()
  executarBusca()
})

/** Mostra e esconde o bloco de filtros avançados. */
function alternarFiltrosAvancados() {
  const bloco = document.getElementById('busca-avancado')
  const botao = document.getElementById('bs-mais')
  const abrindo = bloco.hasAttribute('hidden')
  bloco.toggleAttribute('hidden', !abrindo)
  botao.classList.toggle('aberto', abrindo)
  botao.setAttribute('aria-expanded', String(abrindo))
}

/**
 * Qual nível de filtro está mandando, segundo a precedência do domínio.
 *
 *   intervalo de BCI  >  inscrição unitária  >  demais filtros
 *
 * A inscrição imobiliária identifica UM imóvel; combiná-la com bairro ou
 * quadra só produz contradição, e a resposta vazia daí parece defeito do
 * sistema. Em vez de descartar em silêncio, a tela diz o que está valendo e
 * esmaece o que foi ignorado.
 *
 * @returns {'intervalo'|'inscricao'|'demais'}
 */
function nivelDeBusca() {
  const v = id => (document.getElementById(id)?.value ?? '').trim()
  if (v('bs-bci-de') || v('bs-bci-ate')) return 'intervalo'
  if (v('bs-inscricao')) return 'inscricao'
  return 'demais'
}

function marcarPrecedencia() {
  // Mexeu em QUALQUER filtro, o "informe ao menos um filtro" deixou de valer.
  // Antes ele só saía digitando na inscrição, e ficava na tela mesmo depois
  // de outra busca dar certo (26/11/2026).
  limparCampoInvalido('bs-inscricao')
  const nivel = nivelDeBusca()
  const aviso = document.getElementById('bs-precedencia')

  const demais = ['bs-bairro', 'bs-quadra', 'bs-lote', 'bs-vistoria',
                  'bs-embargo', 'bs-pendente', 'bs-sem-vistoria']

  const inativos = nivel === 'intervalo' ? [...demais, 'bs-inscricao']
                 : nivel === 'inscricao' ? demais
                 : []

  for (const id of [...demais, 'bs-inscricao']) {
    document.getElementById(id)?.closest('.field, .chk-item')
      ?.classList.toggle('bs-ignorado', inativos.includes(id))
  }

  if (nivel === 'demais') { aviso.hidden = true; return }
  aviso.hidden = false
  aviso.textContent = nivel === 'intervalo'
    ? 'Buscando pelo intervalo de BCI — os demais filtros, inclusive a inscrição unitária, estão sendo ignorados.'
    : 'Buscando pela inscrição imobiliária — os demais filtros estão sendo ignorados.'
}

async function executarBusca() {
  const p = new URLSearchParams()
  const nivel = nivelDeBusca()
  const v = id => (document.getElementById(id)?.value ?? '').trim()

  // A precedência é aplicada de novo no servidor; aqui ela existe para não
  // enviar o que já se sabe que será descartado.
  if (nivel === 'intervalo') {
    if (v('bs-bci-de'))  p.set('inscricao_de', v('bs-bci-de'))
    if (v('bs-bci-ate')) p.set('inscricao_ate', v('bs-bci-ate'))
  } else if (nivel === 'inscricao') {
    p.set('inscricao', v('bs-inscricao'))
  } else {
    for (const [campo, id] of [['bairro', 'bs-bairro'], ['quadra', 'bs-quadra'],
                               ['lote', 'bs-lote'], ['vistoria', 'bs-vistoria'],
                               ['logradouro', 'bs-logradouro'], ['numero', 'bs-numero']]) {
      if (v(id)) p.set(campo, v(id))
    }
    if (document.getElementById('bs-embargo').checked) p.set('embargo', '1')
    if (document.getElementById('bs-pendente').checked) p.set('doc_pendente', '1')
    if (document.getElementById('bs-sem-vistoria').checked) p.set('obra_sem_vistoria', '1')
  }

  // Fora do bloco acima de propósito: incluir inativos não é um filtro de
  // busca, é uma ampliação do universo consultado — vale inclusive quando se
  // procura por inscrição, que é justamente como se acha um lote extinto.
  if (document.getElementById('bs-inativos')?.checked) { p.set('incluir_inativos', '1') }

  limparCampoInvalido('bs-inscricao')
  // Só "incluir inativos" não é filtro: amplia o universo, não o recorta.
  if (![...p.keys()].filter(k => k !== 'incluir_inativos').length) {
    exigirCampo('bs-inscricao', 'Informe ao menos um filtro para buscar.')
    return
  }

  // Guardados para as outras páginas e para o PDF: a mesma pergunta, e não o
  // que estiver nos campos na hora (podem ter mudado depois do Buscar).
  bState.params = p
  // Busca nova começa na ordem natural (bairro, quadra, lote).
  bState.ordem = null
  bState.sentido = 'asc'
  await irParaPagina(1)
}

/**
 * Uma página do resultado (BuscaController::POR_PAGINA linhas).
 * @param {number} pagina
 */
async function irParaPagina(pagina) {
  if (!bState.params) return
  const p = new URLSearchParams(bState.params)
  p.set('pagina', String(pagina))

  const alvo = document.getElementById('busca-resultado')
  if (!alvo.querySelector('.bs-tabela')) alvo.innerHTML = '<div class="lista-vazia">Buscando…</div>'
  mostrarCarregandoTela('Buscando imóveis...')

  try {
    const r = await fetch('/api/imoveis/busca?' + p, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)

    bState.resultado = d.imoveis
    bState.ultima = d
    // Resultado único vai direto para a ficha: obrigar a clicar numa tabela
    // de uma linha só é um passo sem informação nova.
    // `await`: sem ele o `finally` daqui embaixo apagaria o overlay ENQUANTO a
    // ficha ainda estivesse carregando — a busca some da tela e a ficha demora,
    // que é justamente a espera que o overlay existe para cobrir.
    if (d.total === 1) { await abrirImovel(d.imoveis[0].id); return }
    renderTabelaBusca(d)
  } catch (e) {
    console.error(e)
    alvo.innerHTML = `<div class="lista-vazia">${esc(e.message || 'Falha na busca.')}</div>`
  } finally {
    // No `finally` e não no fim do `try`: o caminho de resultado único sai por
    // um `return` no meio, e ali o overlay ficaria preso na tela.
    esconderCarregandoTela()
  }
}

/**
 * Tabela só com o essencial — a ficha completa é um clique adiante.
 *
 * Sem resultado, a tabela aparece do mesmo jeito, com o cabeçalho e a linha
 * "nenhum imóvel": a resposta vazia fica no lugar onde o resultado estaria, e
 * não parece que a busca nem rodou.
 */
function renderTabelaBusca(d) {
  const n = d.total ?? d.imoveis.length
  const linhas = !d.imoveis.length
    ? `<tr class="bs-nenhum"><td colspan="10">${d.inicial
        ? 'Preencha um ou mais filtros acima e clique em Buscar.'
        : 'Nenhum imóvel com esses filtros.'}</td></tr>`
    : d.imoveis.map(i => `
    <tr onclick="abrirImovel(${i.id})">
      <td class="mono bs-col-insc">${esc(i.inscricao || '—')}</td>
      <td class="bs-col-logr">${esc(i.logradouro || '—')}${i.logradouro
        ? `<span class="bs-so-cel">, ${esc(i.numero || 's/nº')}</span>` : ''}</td>
      <td class="bs-c bs-col-num">${esc(i.numero || '—')}</td>
      <td class="bs-col-bairro">${esc(i.bairro || '—')}<span class="bs-so-cel"> · Q ${esc(i.quadra ?? '—')} · L ${esc(i.lote ?? '—')}</span></td>
      <td class="bs-c bs-col-q">${esc(i.quadra ?? '—')}</td>
      <td class="bs-c bs-col-l">${esc(i.lote ?? '—')}</td>
      <td class="bs-d bs-col-area">${i.area ? fmtNum(i.area) + ' m²' : '—'}</td>
      <td class="bs-c bs-col-sit">${seloSituacaoBusca(i)}</td>
      <td class="bs-c bs-col-doc" data-rot="Doc.">${i.documentos || '—'}</td>
      <td class="bs-c bs-col-vist" data-rot="Vist.">${i.vistorias || '—'}</td>
    </tr>`).join('')

  // Cabeçalho que ordena: as colunas que o banco sabe ordenar (ver
  // BuscaController::ORDENAVEIS). As outras vêm do cadastro e não ordenam.
  const th = (rot, cls, campo) => {
    if (!campo || d.inicial || !d.imoveis.length) return `<th class="${cls}">${rot}</th>`
    const at = bState.ordem === campo
    const seta = at ? (bState.sentido === 'desc' ? '▼' : '▲') : '↕'
    return `<th class="${cls} bs-ordena${at ? ' at' : ''}" aria-sort="${at ? (bState.sentido === 'desc' ? 'descending' : 'ascending') : 'none'}">
      <button type="button" onclick="ordenarBusca('${campo}')" title="Ordenar por ${rot}">${rot}<span class="bs-seta" aria-hidden="true">${seta}</span></button></th>`
  }

  // "1–50 de 711": de onde a onde vai esta página.
  const pag = d.pagina || 1, por = d.por_pagina || d.imoveis.length
  const de = d.imoveis.length ? (pag - 1) * por + 1 : 0
  const ate = (pag - 1) * por + d.imoveis.length

  document.getElementById('busca-resultado').innerHTML = `
    <div class="bs-cabecalho">
      <span>${d.inicial ? 'Nenhuma busca feita' : `${fmtNum(n)} ${n === 1 ? 'imóvel' : 'imóveis'}`}</span>
      ${d.truncado ? `<span class="bs-aviso">Mostrando os ${d.imoveis.length} primeiros — refine os filtros.</span>` : ''}
      ${n ? `<button type="button" class="btn sm bs-pdf" onclick="exportarBuscaPdf()" title="Todas as páginas, até 400 imóveis">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M12 12v6M9 15l3 3 3-3"/></svg>
        Exportar PDF</button>` : ''}
    </div>
    <div class="bs-tabela-rolagem">
      <table class="bs-tabela">
        <thead>
          <tr>
            ${th('Inscrição', 'bs-w-insc bs-col-insc', 'inscricao')}${th('Logradouro', 'bs-col-logr')}
            ${th('Número', 'bs-c bs-w-num bs-col-num')}${th('Bairro', 'bs-col-bairro', 'bairro')}
            ${th('Quadra', 'bs-c bs-w-n bs-col-q', 'quadra')}${th('Lote', 'bs-c bs-w-n bs-col-l', 'lote')}
            ${th('Área', 'bs-d bs-w-area bs-col-area')}${th('Situação', 'bs-c bs-w-sit bs-col-sit')}
            ${th('Doc.', 'bs-c bs-w-n bs-col-doc', 'documentos')}${th('Vist.', 'bs-c bs-w-n bs-col-vist', 'vistorias')}
          </tr>
        </thead>
        <tbody>${linhas}</tbody>
      </table>
    </div>
    ${(d.paginas || 1) > 1 ? `<nav class="bs-paginacao" aria-label="Páginas do resultado">
      <span class="bs-pag-faixa">${fmtNum(de)}–${fmtNum(ate)} de ${fmtNum(n)}</span>
      <button type="button" class="btn sm" ${pag <= 1 ? 'disabled' : ''} onclick="irParaPagina(1)" title="Primeira página">«</button>
      <button type="button" class="btn sm" ${pag <= 1 ? 'disabled' : ''} onclick="irParaPagina(${pag - 1})" aria-label="Página anterior">‹<span class="bs-pag-txt"> Anterior</span></button>
      <span class="bs-pag-atual">Página ${pag} de ${d.paginas}</span>
      <button type="button" class="btn sm" ${pag >= d.paginas ? 'disabled' : ''} onclick="irParaPagina(${pag + 1})" aria-label="Próxima página"><span class="bs-pag-txt">Próxima </span>›</button>
      <button type="button" class="btn sm" ${pag >= d.paginas ? 'disabled' : ''} onclick="irParaPagina(${d.paginas})" title="Última página">»</button>
    </nav>` : ''}`
  // Página nova começa do alto da tabela, e não onde a anterior parou.
  document.querySelector('#busca-resultado .bs-tabela-rolagem')?.scrollTo(0, 0)
}

/**
 * A situação do imóvel, na mesma regra da ficha e do balão do mapa: inativo
 * no SISTEMA (unificado/desmembrado) ou no CADASTRO é "Inativo", em vermelho
 * (decisão dele, 11/10/2026); ativo no cadastro, "Ativo"; sem o imóvel no
 * cadastro, "Sem info.".
 */
function seloSituacaoBusca(i) {
  if (i.situacao === 'inativo') {
    return `<span class="badge bd-cx" title="Unificado ou desmembrado${i.inativado_em ? ' em ' + esc(i.inativado_em) : ''}">Inativo</span>`
  }
  if (i.ativo_no_cadastro === false) return '<span class="badge bd-cx" title="Inativo no cadastro municipal">Inativo</span>'
  if (i.ativo_no_cadastro === true) return '<span class="badge bd-ok">Ativo</span>'
  return '<span class="badge bd-in" title="O imóvel não está no cadastro municipal carregado">Sem info.</span>'
}

/**
 * Clique no cabeçalho: ordena por aquela coluna; clicar de novo inverte.
 * A ordem vai junto da pergunta (bState.params): vale para as outras páginas
 * e para o PDF. Ordenar volta à página 1 — a 7 de outra ordem não é a mesma.
 * @param {string} campo
 */
function ordenarBusca(campo) {
  if (!bState.params) return
  bState.sentido = bState.ordem === campo && bState.sentido === 'asc' ? 'desc' : 'asc'
  bState.ordem = campo
  bState.params.set('ordem', campo)
  bState.params.set('sentido', bState.sentido)
  irParaPagina(1)
}

/** O PDF da busca: os mesmos filtros, todas as páginas (até 400 imóveis). */
function exportarBuscaPdf() {
  if (!bState.params) return
  window.open('/imoveis/busca.pdf?' + bState.params, '_blank')
}

/** Ficha técnica do imóvel, carregada na própria tela. @param {number} id */
async function abrirImovel(id) {
  const alvo = document.getElementById('busca-resultado')
  alvo.innerHTML = '<div class="lista-vazia">Carregando ficha…</div>'
  mostrarCarregandoTela('Abrindo o imóvel...')

  try {
    const r = await fetch('/api/imoveis/' + id, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)
    renderFichaImovel(d)
  } catch (e) {
    console.error(e)
    alvo.innerHTML = `<div class="lista-vazia">${esc(e.message || 'Não foi possível abrir o imóvel.')}</div>`
  } finally {
    esconderCarregandoTela()
  }
}

function renderFichaImovel(d) {
  // Volta à MESMA página da lista em que se estava.
  const voltar = (bState.ultima?.total ?? 0) > 1
    ? `<button class="btn" onclick="renderTabelaBusca(bState.ultima)">‹ Voltar à lista</button>`
    : ''
  // Endereço e situação no cadastro vêm da linha da busca (a ficha do lote não
  // os traz: é a chamada leve dos pinos do mapa).
  const linha = bState.resultado.find(x => x.id === d.id) || {}

  // Linha RESTRITA (externo): a lista mostra que o documento existe, sem id
  // para abrir — o servidor já não o manda. Ver BuscaController::ficha.
  const restrito = `<span class="bs-linha-data lt-restrito">${ICO_CADEADO} restrito</span>`

  const docs = d.documentos.length
    ? d.documentos.map(x => `
        <div class="bs-linha"${x.id ? ` onclick="abrirDocumento(${x.id})"` : ''}>
          <span class="proto-badge">${esc(x.numero)}</span>
          <span class="bs-linha-tit">${esc(x.tipo)}</span>
          <span class="badge ${esc(x.status.classe)}">${esc(x.status.texto)}</span>
          <span class="bs-linha-data">${esc(x.data || '')}</span>
          ${x.restrito ? restrito : ''}
        </div>`).join('')
    : '<div class="lista-vazia">Nenhum documento neste imóvel.</div>'

  const vist = d.vistorias.length
    ? d.vistorias.map(x => `
        <div class="bs-linha">
          <span class="bs-linha-tit">${esc(x.data || '—')}</span>
          <span class="bs-linha-sub">${esc(x.fiscal || (x.restrito ? '' : '—'))}</span>
          <span class="bs-linha-data">${esc(x.situacao || '')}</span>
          ${x.restrito ? restrito : ''}
        </div>`).join('')
    : '<div class="lista-vazia">Nenhuma vistoria neste imóvel.</div>'

  // O botão do mapa é o ÚNICO ponto desta tela que aciona o serviço pago —
  // por isso é uma escolha explícita do usuário, nunca um efeito da busca.
  // Um imóvel INATIVO não está mais na camada do mapa — ele foi unificado ou
  // desmembrado. Levar até a coordenada mostraria os sucessores desenhados por
  // cima e nada mais; por isso o botão dele é outro: desenha o contorno antigo
  // sozinho, tracejado, sobre o loteamento de hoje.
  const inativo = d.situacao === 'inativo'
  const noMapa = d.lat && d.lon
    ? (inativo
      ? `<button class="btn" onclick="verInativoNoMapa(${d.id}, ${d.lat}, ${d.lon})">
           Ver o contorno antigo no mapa</button>`
      // Leva E MARCA o lote (contorno piscante), como a pesquisa do mapa e a
      // conferência fazem — ver irAoLoteNoMapa, em mapa.js (26/11/2026).
      : `<button class="btn primary" onclick="irAoLoteNoMapa(${d.id})">Ver no mapa</button>`)
    : ''

  const selo = inativo
    ? `<div class="cad-nota cad-aviso" style="margin-bottom:10px">
         <b>Imóvel inativo</b>${d.inativado_em ? ' desde ' + esc(d.inativado_em) : ''}. Ele deixou de
         existir como lote — foi unificado ou desmembrado —, mas os documentos e
         vistorias abaixo continuam sendo dele.</div>`
    : ''

  document.getElementById('busca-resultado').innerHTML = `
    <div class="bs-ficha">
      ${selo}
      <div class="bs-ficha-cab">
        <div class="bs-ficha-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M10 20v-6h4v6"/></svg>
        </div>
        <div class="bs-ficha-id">
          <h3 class="bs-ficha-titulo${linha.logradouro ? '' : ' vazio'}">${linha.logradouro
            ? esc(linha.logradouro) + (linha.numero ? ', ' + esc(linha.numero) : ', s/nº')
            : 'Logradouro não cadastrado'}</h3>
          <div class="bs-ficha-l1">
            <span class="bs-ficha-insc mono" title="Inscrição imobiliária">${esc(d.inscricao || 'sem inscrição')}</span>
            ${seloSituacaoBusca({ ...linha, situacao: d.situacao, inativado_em: d.inativado_em })}
            <span class="lote-tag-origem">${esc(d.tag_origem || 'ORIGINAL')}</span>
          </div>
        </div>
        <div class="bs-ficha-acoes">${voltar}${noMapa}</div>
        <dl class="bs-ficha-dados">
          <div class="bs-ficha-bairro"><dt>Bairro</dt><dd>${esc(d.bairro || '—')}</dd></div>
          <div><dt>Quadra</dt><dd>${esc(d.quadra ?? '—')}</dd></div>
          <div><dt>Lote</dt><dd>${esc(d.lote ?? '—')}</dd></div>
          <div><dt>${d.area_oficial ? 'Área BCI' : 'Área mapa'}</dt><dd>${d.area ? fmtNum(d.area) + ' m²' : '—'}</dd></div>
        </dl>
      </div>

      <div class="sec-title">Cadastro</div>
      <div class="df-grade">
        <div><span class="df-rot">Chave de integração</span><span class="df-val">${esc(d.chave || '—')}</span></div>
        <div><span class="df-rot">Origem do dado</span><span class="df-val">${esc(d.fonte || '—')}</span></div>
        <div><span class="df-rot">Coordenadas</span><span class="df-val">${d.lat ? d.lat.toFixed(6) + ', ' + d.lon.toFixed(6) : '—'}</span></div>
      </div>

      ${d.quadra ? '' : '<div id="bs-quadra-pendente" class="qp-caixa">Verificando o quarteirao…</div>'}

      <div class="sec-title">Documentos (${d.documentos.length})</div>
      ${docs}

      <div class="sec-title">Vistorias (${d.vistorias.length})</div>
      ${vist}
    </div>`

  // Depois de a ficha estar na tela: a consulta do quarteirão é um segundo
  // acesso ao banco e só interessa a quem está sem quadra. Deixá-la fora da
  // ficha mantém leve a chamada usada em massa pelos pinos do mapa.
  if (!d.quadra) carregarQuarteiraoPendente(d.id)
}

/**
 * Lote sem quadra: oferece corrigir o QUARTEIRÃO INTEIRO de uma vez.
 *
 * Quadra vazia não é defeito do cadastro, é recusa do importador em chutar:
 * quando o desenho não separa as quadras pela rua, ou o rótulo não cai dentro
 * do quarteirão, ele prefere deixar em branco a gravar quadra errada — errada
 * passa despercebida e contamina a chave de integração.
 *
 * Mas quem está na ficha sabe qual é a quadra. Aqui ele informa uma vez, e os
 * lotes vizinhos que também estão sem quadra herdam. A herança nunca atravessa
 * lote que já tem quadra provada; o servidor confere de novo antes de gravar.
 *
 * @param {number} id
 */
async function carregarQuarteiraoPendente(id) {
  const alvo = document.getElementById('bs-quadra-pendente')
  if (!alvo) return

  try {
    const r = await fetch('/api/lotes/' + id + '/quarteirao', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok || !d.aplicavel) { alvo.remove(); return }

    const vizinhas = d.vizinhas.length
      ? `Faz divisa com a(s) quadra(s) <b>${d.vizinhas.map(esc).join(', ')}</b>.`
      : 'Não há quadra identificada fazendo divisa com ele.'
    const falta = d.sugestao
      ? ` Falta a quadra <b>${esc(d.sugestao)}</b> na sequência do bairro.`
      : ''

    // Quarteirão que repete número de lote por dentro são quadras coladas no
    // desenho: não há uma resposta certa a preencher, e insistir criaria
    // identificação duplicada. Aí a saída é o DWG, não esta tela.
    if (!d.coerente) {
      alvo.innerHTML = `
        <div class="qp-tit">Quadra não identificada</div>
        <p class="qp-txt">Este quarteirão repete número de lote por dentro — são duas ou mais
        quadras coladas no desenho, sem a rua entre elas. Preencher aqui criaria identificação
        repetida. A correção é no arquivo DWG do loteamento, com reimportação depois.</p>`
      return
    }

    if (!window.USUARIO_ADMIN) {
      alvo.innerHTML = `
        <div class="qp-tit">Quadra não identificada</div>
        <p class="qp-txt">O desenho do loteamento não permitiu identificar a quadra deste imóvel.
        ${vizinhas} Só o administrador pode preencher.</p>`
      return
    }

    alvo.innerHTML = `
      <div class="qp-tit">Quadra não identificada</div>
      <p class="qp-txt">O desenho do loteamento não permitiu identificar a quadra.
      ${vizinhas}${falta}</p>
      <p class="qp-txt">Informar aqui preenche os <b>${d.lotes} lote(s)</b> deste quarteirão
      (${esc(d.numeracao || 'sem numeração')}) de uma vez. Lote que já tem quadra não é tocado.</p>
      <div class="qp-linha">
        <input id="qp-quadra" class="inp" type="text" inputmode="numeric" maxlength="20"
               placeholder="Quadra" value="${esc(d.sugestao || '')}" style="max-width:8rem">
        <button class="btn btn-primario" onclick="aplicarQuadraQuarteirao(${id})">
          Aplicar ao quarteirão
        </button>
      </div>`
  } catch (e) {
    console.error(e)
    alvo.remove()
  }
}

/** @param {number} id */
async function aplicarQuadraQuarteirao(id) {
  const quadra = (document.getElementById('qp-quadra')?.value || '').trim()
  if (!quadra) { exigirCampo('qp-quadra', 'Informe o número da quadra deste quarteirão.'); return }

  try {
    const r = await fetch('/api/lotes/' + id + '/quadra', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({ quadra }),
    })
    const d = await r.json()
    if (r.status === 419) { return }   // sessão expirada: a janela de ui.js (sessaoExpirou) já avisa
    // A recusa do servidor é sempre sobre o VALOR digitado — quadra que já tem
    // esse número de lote, quarteirão que na verdade são duas quadras. Marcar o
    // campo poupa o usuário de procurar de onde veio o aviso.
    if (!r.ok) { toast(d.message || 'Não foi possível aplicar.', 'err', { campo: 'qp-quadra' }); return }

    toast(d.message)
    abrirImovel(id)   // recarrega a ficha já com a quadra
  } catch (e) {
    console.error(e)
    toast('Falha de rede ao aplicar a quadra.', 'err')
  }
}

/** Leva o mapa até o imóvel. @param {number} lat @param {number} lon */
function verImovelNoMapa(lat, lon) {
  irPara('mapa')
  setTimeout(() => mapaState.obj?.setView([lat, lon], ZOOM_DO_LOTE), 220)
}

/** @type {L.GeoJSON|null} o contorno do inativo que está sendo mostrado */
let inativoNoMapa = null

/**
 * Desenha SÓ o lote inativo, tracejado, por cima do loteamento atual.
 *
 * A camada geral continua sem inativos: religá-los ali traria o loteamento
 * antigo inteiro sobreposto ao novo, e não haveria como saber qual das duas
 * divisas é a que vale. Aqui é um contorno de cada vez, pedido, e que sai da
 * tela no próximo pedido ou no toque em "tirar do mapa".
 *
 * @param {number} id @param {number} lat @param {number} lon
 */
async function verInativoNoMapa(id, lat, lon) {
  irPara('mapa')
  await new Promise(r => setTimeout(r, 260))

  const mapa = mapaState.obj
  if (!mapa) { toast('Abra o mapa primeiro.', 'err'); return }

  try {
    const r = await fetch('/api/imoveis/' + id + '/geometria', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok || !d.geometry) { toast(d.message || 'Este imóvel não tem desenho.', 'err'); return }

    tirarInativoDoMapa()

    if (!mapa.getPane('inativos')) {
      const p = mapa.createPane('inativos')
      p.style.zIndex = 645
      p.style.pointerEvents = 'none'
    }

    inativoNoMapa = L.geoJSON(d.geometry, {
      pane: 'inativos',
      interactive: false,
      style: { color: '#6B7280', weight: 2.5, opacity: .95, dashArray: '7,6', fillOpacity: .07 },
    }).addTo(mapa)

    mapa.setView([lat, lon], ZOOM_DO_LOTE)
    toast('Contorno antigo em cinza tracejado. Toque em "tirar do mapa" para limpar.', 'aviso')

    const btn = document.getElementById('btn-tirar-inativo')
    if (btn) { btn.hidden = false }
  } catch (e) {
    console.error(e)
    toast('Falha ao buscar o desenho do imóvel inativo', 'err')
  }
}

function tirarInativoDoMapa() {
  if (inativoNoMapa) { mapaState.obj?.removeLayer(inativoNoMapa); inativoNoMapa = null }
  const btn = document.getElementById('btn-tirar-inativo')
  if (btn) { btn.hidden = true }
}
