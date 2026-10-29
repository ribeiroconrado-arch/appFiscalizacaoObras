/**
 * Importação de bairro pela tela: carregar o GeoJSON como RASCUNHO, fazer a
 * pré-curadoria e a conferência com o cadastro, e só então SALVAR — é aí que a
 * importação passa a valer. Salva, ela segue para conferência, publicação ou
 * exclusão; o rascunho, se não servir, é descartado sem deixar registro.
 *
 * O servidor decide tudo (ImportacaoController): aqui só se mostra o que ele
 * devolve e se oferece o que ele aceitaria. Um modal só (#m-importacoes), com
 * três vistas — lista, nova importação e ficha da importação —, e a barra
 * #imp-barra sobre o mapa para a importação que se está revisando.
 */

const impState = {
  /** Importação aberta na ficha / na barra do mapa. @type {Object|null} */
  atual: null,
  /** Arquivo escolhido na "Nova importação", até gravar. @type {File|null} */
  arquivo: null,
  podePublicar: !!window.USUARIO_ADMIN,
  /** Importação em PRÉ-CURADORIA: as ferramentas só alcançam os lotes dela. @type {Object|null} */
  preCuradoria: null,
  /** Quantas importações não publicadas existem (rascunho ou em revisão). */
  pendentes: 0,
  /** A importação foi levada ao MAPA (Ver no mapa, Pré-curadoria, ver lote)? Só então a barra aparece. */
  noMapa: false,
}

const IMP_BADGE = { rascunho: 'bd-tipo', revisao: 'bd-pe', publicada: 'bd-ok', excluida: 'bd-in' }

/** Rascunho ou em revisão: dá para fazer pré-curadoria e conferir. */
const _impEmAndamento = i => !!i && (i.status === 'rascunho' || i.status === 'revisao')

/** Como chamar a importação nas mensagens: rascunho ainda não tem número que valha. */
const _impNome = i => i.status === 'rascunho' ? `rascunho de ${i.bairro}` : `importação nº ${i.id}`

function _impCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

/** fetch com JSON de volta e a mensagem do servidor no erro. */
async function _impPedir(url, opts = {}) {
  const r = await fetch(url, {
    ...opts,
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': _impCsrf(), ...(opts.headers || {}) },
  })
  let d = {}
  try { d = await r.json() } catch { /* corpo vazio */ }
  // 413 vem do PHP/servidor web, antes da aplicação: o arquivo passou do limite
  // de envio configurado NO SERVIDOR (post_max_size / client_max_body_size).
  if (r.status === 413) {
    throw new Error('O arquivo é maior que o limite de envio do servidor. Peça para aumentar o '
      + 'limite (post_max_size e upload_max_filesize do PHP) ou envie um bairro por arquivo, que fica menor.')
  }
  if (!r.ok) {
    const primeiro = d.errors ? Object.values(d.errors)[0]?.[0] : null
    throw new Error(primeiro || d.message || 'Falha ao falar com o servidor.')
  }
  return d
}

function _impCorpo(html, titulo = 'Importações') {
  document.getElementById('imp-titulo').textContent = titulo
  document.getElementById('imp-corpo').innerHTML = html
}

// ── A JANELA DE LISTAGEM ─────────────────────────────────────
//
// Importações, Contorno dos bairros e Conferência com o cadastro têm a mesma
// forma (`.jt`, importacoes.css): em cima, parado, a pesquisa e os comandos;
// no meio, a tabela, que é a única coisa que rola (com o cabeçalho das colunas
// preso); embaixo, parado, o resumo e os botões.

/** O que foi digitado em cada pesquisa: a lista é refeita a cada ação, e o filtro fica. */
const _jtBuscas = {}

/** Sem acento e sem caixa, para "sao" achar "SÃO". */
const _jtNorm = s => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()

/**
 * O campo de pesquisa livre do cabeçalho.
 * @param {string} id @param {string} dica o que dá para procurar
 */
function jtBusca(id, dica) {
  return `<label class="jt-busca" for="${id}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.500-3.500"/></svg>
      <input type="search" id="${id}" placeholder="${esc(dica)}" autocomplete="off" value="${esc(_jtBuscas[id] || '')}" oninput="jtFiltrar(this)">
    </label><span class="jt-conta" data-jt-conta></span>`.split('3.500').join('3.5')
}

/**
 * Filtra as linhas da janela pelo que foi digitado: todas as palavras têm de
 * aparecer na linha, em qualquer coluna. Grupo sem linha à vista some junto.
 * @param {HTMLInputElement} campo
 */
function jtFiltrar(campo) {
  const jt = campo?.closest('.jt')
  if (!jt) return
  _jtBuscas[campo.id] = campo.value
  const termos = _jtNorm(campo.value).split(/\s+/).filter(Boolean)
  const linhas = [...jt.querySelectorAll('.jt-rolagem tbody tr:not(.jt-sem-linhas)')]
  let visiveis = 0
  for (const tr of linhas) {
    tr._jtTexto ??= _jtNorm(tr.textContent)
    tr.hidden = termos.some(t => !tr._jtTexto.includes(t))
    if (!tr.hidden) visiveis++
  }
  jt.querySelectorAll('.jt-grupo').forEach(g => { g.hidden = !g.querySelector('tbody tr:not([hidden])') })
  const conta = jt.querySelector('[data-jt-conta]')
  if (conta) conta.textContent = termos.length ? `${visiveis} de ${linhas.length}` : `${linhas.length} linha${linhas.length === 1 ? '' : 's'}`
  const vazio = jt.querySelector('.jt-nada')
  if (vazio) vazio.hidden = !(termos.length && visiveis === 0)
}

/**
 * OS CARTÕES DA CONFERÊNCIA — a mesma montagem na ficha da importação e na
 * conferência do bairro. "Casaram" é só número; cada tipo de divergência é um
 * botão que filtra a lista (outro clique no mesmo volta a mostrar todas).
 *
 * @param {number} casaram
 * @param {(tipo:string) => number} conta pendências abertas de cada tipo
 * @param {string|null} filtro o tipo ligado
 * @param {string} acao a função que filtra (recebe o tipo)
 */
function htmlCartoesConferencia(casaram, conta, filtro, acao) {
  const tipos = [['nao_encontrados', 'Não está no cadastro'], ['inativos', 'Inativo no cadastro'],
    ['sem_lote', 'Cadastro sem lote'], ['sem_inscricao', 'Sem inscrição'], ['area_diferente', 'Área diferente']]
  const cor = t => (typeof CONF_TIPOS !== 'undefined' && CONF_TIPOS[t]?.cor) || '#888'
  return `<div class="imp-kpis conf-kpis">
    <div class="imp-kpi kpi-ok" style="--kpi-cor:#1D9E75"><small><i></i>Casaram</small><b>${Number(casaram || 0).toLocaleString('pt-BR')}</b></div>
    ${tipos.map(([t, rot]) => {
      const n = conta(t), at = filtro === t
      return `<button type="button" class="imp-kpi conf-kpi${n ? '' : ' zero'}${at ? ' ativo' : ''}" style="--kpi-cor:${cor(t)}" aria-pressed="${at}" ${n || at ? '' : 'disabled'}
        title="${at ? 'Mostrar todas as divergências' : 'Mostrar só estas'}" onclick="${acao}('${t}')"><small><i></i>${rot}</small><b>${Number(n).toLocaleString('pt-BR')}</b></button>`
    }).join('')}
  </div>`
}

/** Depois de montar a janela: reaplica a pesquisa que estava digitada. @param {string} id */
function jtAplicar(id) {
  const campo = document.getElementById(id)
  if (campo) jtFiltrar(campo)
}

// ── LISTA ────────────────────────────────────────────────────

async function abrirImportacoes() {
  await abrirJanelaDoMapa()   // fecha a ferramenta em uso, a mesa e as barras (ferramentas-mapa.js)
  openModal('m-importacoes')
  await carregarListaImportacoes()
}

function fecharImportacoes() {
  fModalBtn('m-importacoes')
  // Fechar a janela é largar a importação: a barra sai junto. Quem leva a
  // importação ao mapa (verImportacaoNoMapa) liga a barra DEPOIS de fechar.
  // A pré-curadoria sai com ela: sem a barra à vista, as ferramentas não podem
  // continuar presas aos lotes de uma importação que ninguém está vendo.
  if (impState.preCuradoria) sairPreCuradoria()
  impState.noMapa = false
  pintarBarraImportacao()
}

/**
 * As abas da janela: Loteamentos, Perímetro urbano (perimetro-urbano.js) e
 * Mapa — o fundo da cidade (fundo-cidade.js).
 * A aba escolhida fica em `impState.aba` — "Voltar à lista" volta para ela.
 */
function _impAbas() {
  const aba = impState.aba || 'loteamentos'
  const b = (id, rotulo) => `<button type="button" class="${aba === id ? 'at' : ''}" onclick="trocarAbaImportacoes('${id}')">${rotulo}</button>`
  return `<div class="sub-abas imp-abas">${b('loteamentos', 'Loteamentos')}${typeof pintarAbaPerimetro === 'function' ? b('perimetro', 'Perímetro urbano') : ''}${typeof pintarAbaMapa === 'function' ? b('mapa', 'Mapa') : ''}</div>`
}

function trocarAbaImportacoes(aba) {
  impState.aba = aba
  carregarListaImportacoes()
}

async function carregarListaImportacoes() {
  if (impState.aba === 'perimetro' && typeof pintarAbaPerimetro === 'function') return pintarAbaPerimetro()
  if (impState.aba === 'mapa' && typeof pintarAbaMapa === 'function') return pintarAbaMapa()
  _impCorpo('<div class="vazio-msg">Carregando…</div>')
  try {
    const d = await _impPedir('/api/importacoes')
    impState.podePublicar = d.pode_publicar
    atualizarContadorImportacoes(d.em_revisao, _impPendentes(d))

    const linhas = d.importacoes.map(i => `
      <tr onclick="abrirImportacao(${i.id})">
        <td class="mono">${i.status === 'rascunho' ? '—' : i.id}</td>
        <td><b class="jt-forte">${esc(i.bairro)}</b><div class="imp-sub">${esc(i.arquivo)}</div></td>
        <td class="num">${Number(i.lotes).toLocaleString('pt-BR')}</td>
        <td>${esc(i.enviado_por || '—')}</td>
        <td>${esc(i.enviado_em || '—')}</td>
        <td><span class="badge ${IMP_BADGE[i.status] || 'bd-in'}">${esc(i.status_rotulo)}</span></td>
        <td class="num">${i.status === 'revisao' && i.divergencias ? `<span class="imp-diverge">${Number(i.divergencias).toLocaleString('pt-BR')}</span>` : '—'}</td>
        <td class="jt-acoes"><button type="button" class="btn sm">${i.status === 'rascunho' ? 'Continuar' : 'Abrir'}</button></td>
      </tr>`).join('')

    const nPend = _impPendentes(d)
    _impCorpo(`
      <div class="jt">
        <div class="jt-topo">
          ${_impAbas()}
          <div class="jt-linha">
            ${jtBusca('imp-busca', 'Pesquisar por bairro, arquivo, situação ou quem enviou')}
            <button class="btn primary sm" onclick="novaImportacao()">+ Nova importação</button>
          </div>
          <p class="imp-expl">O arquivo entra como <b>rascunho</b>: só você o vê, e dá para fazer a pré-curadoria
            e conferir com o cadastro antes de decidir. Ao <b>salvar</b>, fica <b>salva, não publicada</b>, na lista
            dos curadores. Ao <b>publicar</b> (administrador), o bairro passa a valer para todos.</p>
        </div>
        <div class="jt-rolagem">
          ${d.importacoes.length ? `
            <table class="imp-tabela jt-tabela">
              <thead><tr><th>Nº</th><th>Bairro</th><th class="num">Lotes</th><th>Enviado por</th><th>Em</th>
                <th>Situação</th><th class="num">Divergências</th><th></th></tr></thead>
              <tbody>${linhas}</tbody>
            </table>
            <div class="jt-nada" hidden>Nenhuma importação com o que foi pesquisado.</div>`
            : '<div class="lista-vazia">Nenhuma importação feita pela tela ainda.</div>'}
        </div>
        <div class="jt-rodape">
          <span class="imp-sub">${d.importacoes.length} importação(ões)${nPend ? ` · ${nPend} não publicada(s)` : ''}</span>
          <div class="btn-row"><button class="btn" onclick="fecharImportacoes()">Fechar</button></div>
        </div>
      </div>`)
    jtAplicar('imp-busca')
  } catch (e) {
    _impCorpo(`<div class="cad-nota cad-erro">${esc(e.message)}</div>`)
  }
}

/** O número de importações em revisão, ao lado do botão do painel. */
/** Rascunhos (os meus) e importações em revisão: tudo o que ainda não foi publicado. */
function _impPendentes(d) {
  return (d.importacoes || []).filter(i => i.status === "rascunho" || i.status === "revisao").length
}

function atualizarContadorImportacoes(n, pendentes = n) {
  // É também o que acende as ferramentas de lote não publicado na mesa.
  impState.pendentes = Number(pendentes) || 0
  _ferramentasDaPreCuradoria()
  const el = document.getElementById('imp-contador')
  if (!el) return
  el.hidden = !n
  el.textContent = n ? `${n} não publicada${n > 1 ? 's' : ''}` : ''
}

// ── NOVA IMPORTAÇÃO: arquivo → conferência → gravar ──────────

function novaImportacao() {
  impState.arquivo = null
  _impCorpo(`
    <div class="jt">
      <div class="jt-topo">
        <div class="imp-passos" id="imp-passo-arquivo"><b>1 Arquivo</b> › 2 Leitura › 3 Pré-curadoria e conferência › 4 Salvar</div>
        <p class="imp-expl">O DWG é convertido antes, no conversor de GeoJSON: o <b>loteamento</b> ou o <b>perímetro urbano</b>
          (o sistema reconhece qual é ao ler o arquivo). Nada é gravado nesta etapa: só a leitura do que ele traz.</p>
      </div>
      <div class="jt-rolagem jt-folga">
        <label class="imp-soltar" id="imp-soltar" for="imp-arquivo">
          <input type="file" id="imp-arquivo" accept=".geojson,.json" onchange="conferirArquivoImportacao()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>
            <path d="M12 17v-6"/><path d="M9.5 13.5 12 11l2.5 2.5"/></svg>
          <b id="imp-soltar-tit">Solte o arquivo .geojson aqui</b>
          <span id="imp-soltar-sub">ou clique para escolher no computador · EPSG:4326, um bairro por arquivo</span>
        </label>
        <div id="imp-conferencia"></div>
      </div>
      <div class="jt-rodape">
        <span class="imp-sub">Um bairro por arquivo.</span>
        <div class="btn-row">
          <button class="btn" onclick="carregarListaImportacoes()">Voltar à lista</button>
          <button class="btn primary" id="imp-gravar" disabled onclick="gravarImportacao()">Carregar como rascunho</button>
        </div>
      </div>
    </div>`, 'Nova importação')
  _prepararAreaDeSoltar()
}

/**
 * Arrastar e soltar sobre a área do arquivo. O clique continua valendo — a
 * área é o <label> do input —, e soltar só entrega o arquivo ao mesmo input,
 * para seguir exatamente o caminho do clique.
 */
function _prepararAreaDeSoltar(areaId = 'imp-soltar', inputId = 'imp-arquivo',
  aceita = /\.(geo)?json$/i, recusa = 'O arquivo precisa ser .geojson.', aoEscolher = conferirArquivoImportacao) {
  const area = document.getElementById(areaId)
  const input = document.getElementById(inputId)
  if (!area || !input) return

  const ligar = on => e => { e.preventDefault(); area.classList.toggle('arrastando', on) }
  area.addEventListener('dragenter', ligar(true))
  area.addEventListener('dragover', ligar(true))
  area.addEventListener('dragleave', ligar(false))
  area.addEventListener('drop', e => {
    ligar(false)(e)
    const arq = e.dataTransfer?.files?.[0]
    if (!arq) return
    if (!aceita.test(arq.name)) {
      toast(recusa, 'err')
      return
    }
    const dt = new DataTransfer()
    dt.items.add(arq)
    input.files = dt.files
    aoEscolher()
  })
}

/** A área de soltar diz QUAL arquivo está escolhido — e que dá para trocá-lo do mesmo jeito. */
function _mostrarEscolhido(areaId, arq, titVazio, subVazio) {
  const area = document.getElementById(areaId)
  if (!area) return
  area.classList.toggle('escolhido', !!arq)
  area.querySelector('b').textContent = arq ? arq.name : titVazio
  area.querySelector('span').textContent = arq
    ? `${(arq.size / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 0 })} KB · solte outro ou clique para trocar`
    : subVazio
}

const ICO_SOLTAR = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>
  <path d="M12 17v-6"/><path d="M9.5 13.5 12 11l2.5 2.5"/></svg>`

async function conferirArquivoImportacao() {
  const arq = document.getElementById('imp-arquivo').files[0]
  impState.arquivo = arq || null
  const alvo = document.getElementById('imp-conferencia')
  document.getElementById('imp-gravar').disabled = true

  // A área de soltar passa a dizer QUAL arquivo está escolhido — e que dá
  // para trocá-lo do mesmo jeito.
  const area = document.getElementById('imp-soltar')
  area?.classList.toggle('escolhido', !!arq)
  document.getElementById('imp-soltar-tit').textContent = arq ? arq.name : 'Solte o arquivo .geojson aqui'
  document.getElementById('imp-soltar-sub').textContent = arq
    ? `${(arq.size / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 0 })} KB · solte outro ou clique para trocar`
    : 'ou clique para escolher no computador · EPSG:4326, um bairro por arquivo'

  if (!arq) { alvo.innerHTML = ''; return }

  // Arquivo do PERÍMETRO URBANO: outro fluxo (perimetro-urbano.js).
  if (typeof arquivoEhPerimetro === 'function' && await arquivoEhPerimetro(arq)) {
    abrirPerimetroNovo(arq)
    return
  }

  alvo.innerHTML = '<div class="vazio-msg">Lendo o arquivo…</div>'
  const fd = new FormData()
  fd.append('arquivo', arq)
  try {
    const c = await _impPedir('/api/importacoes/conferir', { method: 'POST', body: fd })
    alvo.innerHTML = _htmlConferenciaArquivo(c)
    // A conferência traz a própria linha de passos; a do topo sai.
    document.getElementById('imp-passo-arquivo').hidden = true
    document.getElementById('imp-gravar').disabled = !c.pode_gravar
  } catch (e) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">${esc(e.message)}</div>`
  }
}

function _htmlConferenciaArquivo(c) {
  const kpi = (rot, n, cls = '') => `<div class="imp-kpi ${cls}"><small>${rot}</small><b>${Number(n).toLocaleString('pt-BR')}</b></div>`
  const avisos = []
  if (c.sem_quadra) avisos.push(`<tr><td>Sem quadra ou sem número — corrigir depois no mapa, com "Corrigir quadra"</td><td class="num">${c.sem_quadra}</td></tr>`)
  c.repetidos.forEach(r => avisos.push(`<tr><td>Quadra ${esc(r.quadra)} · Lote ${esc(r.lote)} aparece ${r.vezes} vezes no arquivo — entram <b>sem número</b>, para você escolher no mapa qual é o certo</td><td class="num">${r.vezes}</td></tr>`))
  if (c.ignoradas) avisos.push(`<tr><td>Feições que não são polígono, ou sem bairro (ignoradas)</td><td class="num">${c.ignoradas}</td></tr>`)
  c.conflitos.forEach(x => avisos.push(`<tr><td>Quadra ${esc(x.quadra)} · Lote ${esc(x.lote)} já existe na base</td><td class="num">1</td></tr>`))

  return `
    <div class="imp-passos">1 Arquivo › <b>2 Leitura</b> › 3 Pré-curadoria e conferência › 4 Salvar</div>
    <div class="imp-sub" style="margin-bottom:8px">Bairro: <b>${esc(c.bairro || '—')}</b>
      ${c.lotes_do_bairro_na_base ? ` · o bairro já tem ${c.lotes_do_bairro_na_base} lote(s) na base` : ''}</div>
    <div class="imp-kpis">
      ${kpi('Lotes lidos', c.lidos)}
      ${kpi('Sem quadra', c.sem_quadra, c.sem_quadra ? 'aviso' : '')}
      ${kpi('Nº repetido', c.repetidos_total, c.repetidos_total ? 'aviso' : '')}
      ${kpi('Conflito com a base', c.conflitos_total, c.conflitos_total ? 'erro' : 'ok')}
    </div>
    ${avisos.length ? `<table class="imp-tabela"><thead><tr><th>Aviso</th><th class="num">Lotes</th></tr></thead><tbody>${avisos.join('')}</tbody></table>` : ''}
    ${c.vinculo && c.pode_gravar ? _htmlVinculoBairro(c.bairro, c.vinculo, 'leitura') : ''}
    ${c.impedimentos.length
      ? `<div class="cad-nota cad-erro">${c.impedimentos.map(esc).join('<br>')}</div>`
      : `<div class="cad-nota imp-nota">Os lotes entram como <b>rascunho</b>: só você enxerga, e a importação
           ainda não vale. Faça a pré-curadoria e a conferência; se estiver bom, <b>salve</b>. Se não, descarte.</div>`}`
}

/**
 * "Bairro no cadastro": a que bairro da prefeitura o nome do arquivo se liga.
 * Sem isso não há código de bairro — nem inscrição, nem conferência.
 *
 * modo 'leitura' — na Nova importação: a escolha vai junto com o carregamento;
 * modo 'ficha'   — na ficha: tem o próprio botão Vincular;
 * modo 'fixo'    — importação publicada: só mostra.
 */
function _htmlVinculoBairro(nome, v, modo) {
  const a = v.atual
  if (modo === 'fixo') {
    return a ? `<div class="sec-title">Bairro no cadastro da prefeitura</div>
      <div class="imp-vinculo"><span class="badge bd-ok">${esc(a.codigo)}</span> <b>${esc(a.nome)}</b></div>` : ''
  }
  const trocavel = !a || !v.lotes_fora
  const opcao = b => {
    const preso = b.ocupado && b.ocupado.lotes > 0
    return `<option value="${b.id}" ${preso ? 'disabled' : ''} ${a && a.id === b.id ? 'selected' : ''}>`
      + `${esc(b.codigo)} · ${esc(b.nome)}${b.ocupado ? ` — ligado a "${esc(b.ocupado.nome)}"${preso ? ` (${b.ocupado.lotes} lotes)` : ''}` : ''}</option>`
  }
  const sugeridos = v.sugestoes.map(id => v.bairros.find(b => b.id === id)).filter(Boolean)

  return `
    <div class="sec-title">Bairro no cadastro da prefeitura</div>
    ${a ? `<div class="imp-vinculo"><span class="badge bd-ok">${esc(a.codigo)}</span>
        <span><b>${esc(nome)}</b> está ligado a <b>${esc(a.nome)}</b></span>
        ${trocavel ? `<a href="#" onclick="event.preventDefault(); _impAbrirTroca()">trocar</a>`
          : `<span class="imp-sub">· ${v.lotes_fora} lote(s) fora desta importação já usam essa ligação; trocar é em Parâmetros → Bairros</span>`}</div>`
      : `<div class="cad-nota cad-aviso"><b>${esc(nome)}</b> ainda não está ligado a um bairro do cadastro.
          Sem o vínculo os lotes ficam sem inscrição e a conferência com o cadastro não roda.</div>`}
    <div class="imp-vinculo-escolha" id="imp-vinculo-escolha" ${a ? 'hidden' : ''}>
      <select id="imp-vinculo" data-combo data-atual="${a ? a.id : ''}">
        <option value="">— escolha o bairro do cadastro —</option>
        ${sugeridos.length ? `<optgroup label="Parecidos pelo nome">${sugeridos.map(opcao).join('')}</optgroup>` : ''}
        <optgroup label="Todos os bairros do município">${v.bairros.map(opcao).join('')}</optgroup>
      </select>
      ${modo === 'ficha' ? '<button class="btn primary sm" id="imp-btn-vincular" onclick="vincularBairroDaImportacao()">Vincular</button>' : ''}
    </div>
    ${!a && sugeridos.length ? '<p class="imp-expl">Os "parecidos pelo nome" são só sugestão — confira o código antes de escolher.</p>' : ''}`
}

function _impAbrirTroca() {
  const e = document.getElementById('imp-vinculo-escolha')
  if (e) { e.hidden = false; document.getElementById('imp-vinculo')?.focus() }
}

/** O bairro escolhido no select, quando ele MUDA a ligação de hoje. */
function _impVinculoEscolhido() {
  const sel = document.getElementById('imp-vinculo')
  if (!sel || sel.closest('[hidden]') || !sel.value || sel.value === sel.dataset.atual) return null
  return sel.value
}

async function vincularBairroDaImportacao() {
  const i = impState.atual
  const id = _impVinculoEscolhido()
  if (!id) { exigirCampo('imp-vinculo', 'Escolha o bairro do cadastro.'); return }
  const btn = document.getElementById('imp-btn-vincular')
  btn.disabled = true
  try {
    const d = await _impPedir(`/api/importacoes/${i.id}/bairro`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cadastro_bairro_id: Number(id) }),
    })
    toast(d.message)
    await abrirImportacao(i.id)
  } catch (e) {
    toast(e.message, 'err')
    btn.disabled = false
  }
}

async function gravarImportacao() {
  if (!impState.arquivo) return
  const btn = document.getElementById('imp-gravar')
  btn.disabled = true
  btn.textContent = 'Carregando…'
  const fd = new FormData()
  fd.append('arquivo', impState.arquivo)
  const vinculo = _impVinculoEscolhido()
  if (vinculo) fd.append('cadastro_bairro_id', vinculo)
  try {
    const d = await _impPedir('/api/importacoes', { method: 'POST', body: fd })
    toast(d.message)
    recarregarLotesDoMapa()
    await abrirImportacao(d.id)
  } catch (e) {
    toast(e.message, 'err')
    btn.disabled = false
    btn.textContent = 'Carregar como rascunho'
  }
}

// ── FICHA DA IMPORTAÇÃO ──────────────────────────────────────

async function abrirImportacao(id) {
  // Vindo do mapa (a barra da importação), a janela pede a vez; com ela já
  // aberta (lista → ficha, atualizações) não há o que fechar.
  if (!document.getElementById('m-importacoes').classList.contains('open')) {
    await abrirJanelaDoMapa()
    openModal('m-importacoes')
  }
  _impCorpo('<div class="vazio-msg">Carregando…</div>', 'Importação de bairro')
  try {
    let i = await _impPedir('/api/importacoes/' + id)
    // Lotes corrigidos depois da conferência: ela se refaz sozinha, com a
    // mesma fonte, antes de a ficha aparecer — o que foi resolvido já sai.
    if (await _impReconferirSePreciso(i)) i = await _impPedir('/api/importacoes/' + id)
    impState.atual = i
    impState.podePublicar = i.pode_publicar
    renderFichaImportacao(i)
    pintarBarraImportacao()
  } catch (e) {
    _impCorpo(`<div class="cad-nota cad-erro">${esc(e.message)}</div>
      <div class="btn-row"><button class="btn" onclick="carregarListaImportacoes()">Voltar</button></div>`)
  }
}

/** Cartão da ficha clicado: só aquele tipo de divergência na lista. @param {string} tipo */
function _impFiltrarConf(tipo) {
  impState.filtroConf = tipo && impState.filtroConf !== tipo ? tipo : null
  _impAplicarFiltroConf()
  document.querySelectorAll('#m-importacoes .conf-kpi').forEach(b => {
    const at = b.getAttribute('onclick')?.includes(`'${impState.filtroConf}'`) && !!impState.filtroConf
    b.classList.toggle('ativo', at)
    b.setAttribute('aria-pressed', String(at))
  })
}

function _impAplicarFiltroConf() {
  const t = impState.filtroConf
  document.querySelectorAll('#m-importacoes .jt-grupo').forEach(g => g.classList.toggle('fora-filtro', !!t && g.dataset.tipo !== t))
}

function renderFichaImportacao(i) {
  // O filtro dos cartões é da importação aberta: outra importação começa sem ele.
  if (impState.filtroConfId !== i.id) { impState.filtroConf = null; impState.filtroConfId = i.id }
  const emRevisao = i.status === 'revisao'
  const rascunho = i.status === 'rascunho'
  const andamento = _impEmAndamento(i)
  const vinc = Object.entries(i.vinculos || {})
  const rotVinc = { vistorias: 'Vistorias', documentos: 'Autos e notificações', protocolos: 'Protocolos',
    ordens_servico: 'Ordens de serviço', obras: 'Obras', bci: 'Fichas do BCI', sucessao: 'Desmembramentos / unificações' }

  const cc = i.conferencia_cadastro
  const conf = cc ? _htmlConferenciaCadastro(i, cc) : null
  // A conferência é SEMPRE com o cadastro que já está no sistema (a carga
  // mensal): não há fonte a escolher nem planilha a anexar aqui. Por isso o
  // cabeçalho é uma linha só — o título, o resumo em texto e o botão.
  const resumo = cc ? `<span class="imp-sub imp-conf-resumo">${Number(cc.casaram).toLocaleString('pt-BR')} casaram ·
      ${cc.total_divergencias ? `${Number(cc.total_divergencias).toLocaleString('pt-BR')} divergência(s)` : 'sem divergências'}</span>` : ''
  const controles = andamento ? `
    <div class="sec-title-row imp-conf-linha">
      <div class="sec-title">Conferência com o cadastro da prefeitura</div>
      ${resumo}
      <button class="btn primary sm sec-title-acao" id="imp-btn-conferir" onclick="conferirComCadastro()">${cc ? 'Conferir de novo' : 'Conferir'}</button>
    </div>${cc ? htmlCartoesConferencia(cc.casaram, t => (cc[t] || []).length, impState.filtroConf, '_impFiltrarConf') : ''}${cc && cc.total_divergencias ? `<div class="jt-linha">${jtBusca('ficha-busca', 'Pesquisar por inscrição, quadra, lote ou detalhe')}</div>` : ''}`
    : (cc ? `<div class="sec-title-row imp-conf-linha"><div class="sec-title">Última conferência com o cadastro</div>${resumo}</div>` : '')
  const naoConferida = andamento && !cc ? `<p class="imp-expl">Ainda não conferida. ${rascunho
    ? 'Conferir antes de salvar é o recomendado; a publicação exige a conferência.'
    : 'A publicação exige a conferência.'}</p>` : ''

  const publicada = i.status === 'publicada'
    ? `<div class="cad-nota">Publicada em ${esc(i.publicado_em)}.${i.justificativa_publicacao
        ? `<br><b>Justificativa:</b> ${esc(i.justificativa_publicacao)}` : ''}</div>` : ''
  const excluida = i.status === 'excluida'
    ? `<div class="cad-nota">Excluída em ${esc(i.excluido_em)}. <b>Motivo:</b> ${esc(i.motivo_exclusao || '—')}</div>` : ''

  // Só a importação salva e ainda não publicada se exclui (ImportacaoDeBairro::excluir).
  const podeExcluir = emRevisao
  const acoes = [
    `<button class="btn" onclick="carregarListaImportacoes()">Voltar à lista</button>`,
    // Um botão só para ir ao mapa: importação não publicada vai em
    // pré-curadoria (com todas as ferramentas); a publicada, só para ver.
    andamento && ((cc?.total_divergencias || 0) + (i.pendencias_desenho?.invalidos?.length || 0) + (i.pendencias_desenho?.repetidos?.length || 0))
      ? `<button class="btn primary" onclick="abrirCorrecao('importacao')">Corrigir no mapa</button>` : '',
    // "Pré-curadoria no mapa" saiu: corrigir é pelo painel (Corrigir no mapa).
    !andamento && i.extensao && i.status !== 'excluida' ? `<button class="btn" onclick="verImportacaoNoMapa()">Ver no mapa</button>` : '',
    i.status === 'publicada' ? `<button class="btn" onclick="gerarContornoDoBairro(${jsArg(i.bairro)})">Gerar contorno do bairro</button>` : '',
    podeExcluir ? `<button class="btn danger" onclick="pedirExclusaoImportacao()">Excluir importação</button>` : '',
    rascunho ? `<button class="btn danger" onclick="pedirDescarteImportacao()">Descartar rascunho</button>` : '',
    rascunho ? `<button class="btn primary" onclick="pedirSalvamentoImportacao()">Salvar importação</button>` : '',
    emRevisao ? (impState.podePublicar
      ? `<button class="btn primary" onclick="pedirPublicacaoImportacao()">Publicar</button>`
      : `<button class="btn" disabled title="Só o administrador publica">Aguardando administrador</button>`) : '',
  ].join('')

  // TRÊS FAIXAS, e só a do meio rola (.imp-ficha em importacoes.css). A lista
  // de divergências pode ter centenas de linhas: quem rola até a Q 40 continua
  // vendo o vínculo do bairro e o botão de conferir (em cima) e as ações da
  // importação (embaixo). O cabeçalho vai até a linha da conferência.
  _impCorpo(`
    <div class="jt imp-ficha">
      <div class="jt-topo imp-ficha-topo">
        <div class="imp-cabeca">
          <span class="badge ${IMP_BADGE[i.status] || 'bd-in'}">${esc(i.status_rotulo)}</span>
          <b>${esc(i.bairro)}</b> · ${Number(i.lotes).toLocaleString('pt-BR')} lotes
          <span class="imp-sub">· ${esc(i.arquivo)} · ${rascunho ? 'carregado' : 'enviado'} por ${esc(i.enviado_por || '—')} em ${esc(i.enviado_em || '')}${i.salvo_em && i.salvo_em !== i.enviado_em ? ` · salvo em ${esc(i.salvo_em)}` : ''}</span>
        </div>
        ${i.vinculo ? _htmlVinculoBairro(i.bairro, i.vinculo, andamento ? 'ficha' : 'fixo') : ''}
        ${controles}
      </div>
      <div class="jt-rolagem">
        ${publicada}${excluida}
        ${vinc.length ? `<div class="cad-nota cad-aviso"><b>Lotes com vínculo</b> — a importação não pode ser excluída:
            ${vinc.map(([k, n]) => `${rotVinc[k] || k}: ${n}`).join(' · ')}</div>` : ''}
        ${_htmlPendenciasDoDesenho(i)}
        <div id="imp-resultado">${conf ? conf.corpo : naoConferida}</div>
        ${_htmlPreCuradoria(i.pre_curadoria, i.id)}
        <div class="jt-nada" hidden>Nenhuma linha com o que foi pesquisado.</div>
      </div>
      <div class="jt-rodape jt-rodape-col">
        ${conf ? conf.rodape : ''}
        <div id="imp-acao"></div>
        <div class="btn-row imp-acoes">${acoes}</div>
      </div>
    </div>`, rascunho ? `Rascunho de importação · ${i.bairro}` : `Importação nº ${i.id}`)
  jtAplicar('ficha-busca')
  _impAplicarFiltroConf()
}

/**
 * PENDÊNCIAS DO DESENHO: o que o arquivo trouxe de errado e NÃO impediu o
 * carregamento — contorno inválido e número repetido. Cada linha leva ao lote
 * no mapa, já em pré-curadoria e marcado, pronto para a ferramenta. O que foi
 * corrigido sai da lista sozinho (o servidor lê a base a cada abertura).
 */
function _htmlPendenciasDoDesenho(i) {
  const p = i.pendencias_desenho
  if (!p || (!p.invalidos.length && !p.repetidos.length)) return ''
  const ver = (l, tipo) => _impEmAndamento(i)
    ? `<button type="button" class="btn sm primary" onclick="abrirCorrecao('importacao', ${jsArg(_corrChave(tipo, l))})">Corrigir</button>`
    : `<button type="button" class="btn sm" onclick="irAoLoteDaImportacao(${Number(l.lote_id)})">Ver no mapa</button>`
  const grupo = (titulo, dica, itens, linha) => itens.length ? `
    <section class="jt-grupo">
      <div class="jt-grupo-tit"><span class="badge bd-pe">${itens.length}</span> <b>${titulo}</b></div>
      <p class="imp-expl">${dica}</p>
      <table class="imp-tabela jt-tabela"><thead><tr><th>Quadra · Lote</th><th>O que há</th><th></th></tr></thead>
        <tbody>${itens.map(linha).join('')}</tbody></table>
    </section>` : ''

  return `<div class="sec-title">Pendências do desenho</div>
    ${grupo('Contorno inválido (linha que se cruza)',
      `Corrija os vértices com <b>Editar lote (L)</b>, ou exclua <b>(X)</b> e desenhe de novo <b>(D)</b>.${
        i.status === 'rascunho' ? ' O rascunho só pode ser salvo depois disso.' : ''}`,
      p.invalidos, l => `<tr><td>Q ${esc(l.quadra ?? '—')} · L ${esc(l.lote ?? '—')}</td><td>Contorno inválido</td><td class="jt-acoes">${ver(l, 'invalido')}</td></tr>`)}
    ${grupo('Número repetido no arquivo — entraram sem número',
      'Veja cada um no mapa: informe o número do que está certo <b>(N)</b> e renumere ou exclua <b>(X)</b> o outro.',
      p.repetidos, l => `<tr><td>Q ${esc(l.quadra)}</td><td>No arquivo era o lote <b>${esc(l.lote)}</b></td><td class="jt-acoes">${ver(l, 'repetido')}</td></tr>`)}`
}

/** O registro próprio da pré-curadoria — fora do Histórico do cadastro. */
function _htmlPreCuradoria(pc, impId) {
  if (!pc || !pc.total) return ''
  const rot = { editou: 'Editou', 'corrigiu quadra': 'Corrigiu quadra', renumerou: 'Renumerou', desenhou: 'Desenhou',
    excluiu: 'Apagou', unificou: 'Unificou', desmembrou: 'Desmembrou', inativou: 'Inativou',
    criou: 'Criou', alterou: 'Alterou', 'instituiu frações': 'Instituiu frações', reativou: 'Reativou', restaurou: 'Restaurou' }
  // DESFAZER, linha a linha. Quando não dá, o porquê fica no lugar do botão
  // (passando o mouse): faz parte de uma operação, mudou de novo depois, etc.
  const acao = a => a.pode_desfazer
    ? `<button type="button" class="btn sm" onclick="desfazerAjusteDaImportacao(${Number(impId)}, ${Number(a.id)}, ${jsArg((rot[a.acao] || a.acao) + ' · ' + (a.lote || ''))})">Desfazer</button>`
    : `<span class="imp-sub pc-sem-desfazer" title="${esc(a.motivo || '')}">${a.motivo && /^Faz parte/.test(a.motivo) ? esc(a.motivo.replace(/^Faz parte ([^:]+):.*$/, 'parte $1')) : '—'}</span>`
  return `<section class="jt-grupo">
      <div class="jt-grupo-tit"><span class="badge bd-in">${pc.total}</span> <b>Ajustes da pré-curadoria</b>
        <span class="imp-sub">ficam com a importação e não entram no Histórico do cadastro</span></div>
      <table class="imp-tabela jt-tabela jt-registro pc-com-desfazer"><thead><tr><th>Ajuste</th><th>Lote</th><th>Usuário</th><th>Quando</th><th></th></tr></thead>
        <tbody>${pc.ultimos.map(a => `
        <tr><td>${esc(rot[a.acao] || a.acao)}</td><td>${esc(a.lote || '—')}</td>
            <td>${esc(a.usuario || '—')}</td><td>${esc(a.quando)}</td><td class="jt-acoes">${acao(a)}</td></tr>`).join('')}</tbody></table>
      ${pc.total > pc.ultimos.length ? `<div class="imp-sub">Mostrando os ${pc.ultimos.length} mais recentes.</div>` : ''}
    </section>`
}

/**
 * Desfaz um ajuste da pré-curadoria — como ajuste NOVO: a linha original
 * continua no registro (DesfazerAlteracao no servidor).
 */
function desfazerAjusteDaImportacao(impId, auditoriaId, rotulo) {
  confirmarAcao({
    titulo: 'Desfazer o ajuste',
    mensagem: `${rotulo} volta a ser como era antes deste ajuste. O ajuste e o desfazer ficam os dois no registro da pré-curadoria.`,
    textoBtn: 'Desfazer',
    onConfirm: async () => {
      try {
        const d = await _impPedir(`/api/importacoes/${Number(impId)}/ajustes/${Number(auditoriaId)}/desfazer`, { method: 'POST' })
        _depoisDaPreCuradoria(d.message)
        // A ficha aberta mostra o registro: refeita, com a linha nova e os botões em dia.
        if (document.getElementById('m-importacoes')?.classList.contains('open')) abrirImportacao(impId)
      } catch (e) { toast(e.message, 'err') }
    },
  })
}

/**
 * "TODOS OS LOTES DO CADASTRO ESTÃO NO MAPA?" — a cobertura, à parte das
 * divergências (ConferenciaComCadastro): cada inscrição do bairro, ativa ou
 * inativa, sem desmembramento nem unificação, tem de ter lote no mapa. Serve à
 * ficha da importação e à conferência do bairro.
 *
 * @param {Object|undefined} cob  resultado.cobertura
 * @param {(l:Object) => string} [acao]  botão da linha (ex.: ir à quadra)
 */
function htmlCoberturaDoCadastro(cob, acao = () => '') {
  if (!cob) {
    return `<section class="jt-grupo cob-grupo"><div class="jt-grupo-tit"><b>Todos os lotes do cadastro estão no mapa?</b></div>
      <div class="cad-nota cad-aviso">Esta conferência é de antes desta verificação. Confira de novo para ver se cada inscrição do bairro tem lote no mapa.</div></section>`
  }
  const n = v => Number(v || 0).toLocaleString('pt-BR')
  const faltam = [...(cob.faltam || [])]
  const num = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0
  faltam.sort((a, b) => num(a.quadra) - num(b.quadra) || num(a.lote) - num(b.lote))
  const ign = cob.ignoradas || {}
  const ignorados = [ign.desmembramentos ? `${n(ign.desmembramentos)} de desmembramento` : '', ign.unificacoes ? `${n(ign.unificacoes)} de unificação` : '']
    .filter(Boolean).join(' e ')
  return `<section class="jt-grupo cob-grupo" data-tipo="cobertura">
      <div class="jt-grupo-tit">
        <span class="badge ${faltam.length ? 'bd-er' : 'bd-ok'}">${faltam.length ? n(faltam.length) + ' sem lote' : 'Completo'}</span>
        <b>Todos os lotes do cadastro estão no mapa?</b>
        <span class="imp-sub">${n(cob.no_mapa)} de ${n(cob.esperadas)} inscrições do bairro têm lote no mapa${ignorados ? ` · fora da conta: ${ignorados}` : ''}</span>
        <span class="imp-sub conf-grupo-nota">Conta as inscrições ativas e inativas no cadastro, e os lotes ativos e inativos do mapa (o lote juntado numa unificação foi importado). Não entram: inscrição de desmembramento (variação diferente de 000) e de unificação — número de lote de 100 para cima, que é como o cadastro numera lotes juntados (01 + 02 = 0102).</span>
      </div>
      ${faltam.length ? `<table class="imp-tabela jt-tabela">
        <thead><tr><th>Inscrição</th><th>Quadra · Lote</th><th>Detalhe</th><th></th></tr></thead>
        <tbody>${faltam.slice(0, 300).map(l => `<tr>
          <td class="mono">${esc(l.inscricao || '—')}</td>
          <td>Q ${esc(l.quadra ?? '—')} · L ${esc(l.lote ?? '—')}</td>
          <td class="jt-quebra">${[l.ativo === false ? '<span class="badge bd-cx">Inativo no cadastro</span>' : '',
            l.area_m2 ? fmtNum(l.area_m2) + ' m²' : '', esc(l.endereco || '')].filter(Boolean).join(' · ') || '<span class="imp-sub">—</span>'}</td>
          <td class="jt-acoes">${acao(l)}</td></tr>`).join('')}</tbody></table>
        ${faltam.length > 300 ? `<div class="imp-sub">+ ${n(faltam.length - 300)} — confira de novo depois de importar os que faltam.</div>` : ''}` : ''}
    </section>`
}

/**
 * A conferência em DOIS pedaços, porque cada um mora num lugar da ficha: as
 * listas no meio (rolam) e a fonte com o CSV no rodapé fixo. Os números de
 * cada grupo vão no título do próprio grupo.
 *
 * @returns {{corpo:string, rodape:string}}
 */
function _htmlConferenciaCadastro(i, c) {
  const emDia = i.conferencia_em_dia === false
    ? `<div class="cad-nota cad-aviso">Houve alteração nos lotes depois desta conferência. Confira de novo antes de publicar.</div>` : ''

  // Importação em andamento: "Corrigir" abre o painel de correção naquela
  // pendência (correcao-mapa.js). Publicada ou excluída, só se vê no mapa.
  const andamento = _impEmAndamento(i)
  const corrigir = (tipo, l) => `<button type="button" class="btn sm primary" onclick="abrirCorrecao('importacao', ${jsArg(_corrChave(tipo, l))})">Corrigir</button>`
  const verLote = l => l.lote_id
    ? `<button type="button" class="btn sm" onclick="irAoLoteDaImportacao(${Number(l.lote_id)})">Ver no mapa</button>` : ''
  const acaoDe = tipo => l => andamento ? corrigir(tipo, l) : verLote(l)
  const alterado = l => l.alterado ? ' <span class="badge bd-pe" title="O lote mudou depois da conferência com a planilha: confirme com ela">alterado</span>' : ''
  // Todas as listas com as MESMAS quatro colunas: a pesquisa e o olho leem igual em qualquer grupo.
  const linha = (l, detalhe, acao) => `<tr>
      <td class="mono">${esc(l.inscricao || '—')}</td>
      <td>Q ${esc(l.quadra ?? '—')} · L ${esc(l.lote ?? '—')}${alterado(l)}</td>
      <td class="jt-quebra">${detalhe || '<span class="imp-sub">—</span>'}</td>
      <td class="jt-acoes">${acao}</td></tr>`
  const grupo = (titulo, cls, itens, fazLinha, tipo) => itens.length ? `
    <section class="jt-grupo" data-tipo="${tipo}">
      <div class="jt-grupo-tit"><span class="badge ${cls}">${itens.length}</span> <b>${titulo}</b></div>
      <table class="imp-tabela jt-tabela">
        <thead><tr><th>Inscrição</th><th>Quadra · Lote</th><th>Detalhe</th><th></th></tr></thead>
        <tbody>${itens.slice(0, 200).map(fazLinha).join('')}</tbody></table>
      ${itens.length > 200 ? `<div class="imp-sub">+ ${itens.length - 200} — veja todas no PDF ou no CSV.</div>` : ''}
    </section>` : ''

  return {
    corpo: `
    ${emDia}
    ${c.sem_situacao ? `<div class="cad-nota cad-aviso">A planilha não tem coluna de situação ("Situação" ou "Isenção ou Imunidade"):
        não deu para saber quais inscrições estão <b>inativas</b> no cadastro.</div>` : ''}
    ${c.total_divergencias === 0 ? '<div class="cad-nota imp-nota">Nenhuma divergência: todo lote do arquivo está ativo no cadastro, e todo imóvel ativo do cadastro tem lote.</div>' : ''}
    ${grupo('No arquivo, não encontrados no cadastro', 'bd-er', c.nao_encontrados, l => linha(l, '', acaoDe('nao_encontrados')(l)), 'nao_encontrados')}
    ${grupo('Desenhados no mapa, inativos no cadastro', 'bd-er', c.inativos,
      l => linha(l, `<span class="badge bd-in">Cadastro: ${esc(l.isencao)}</span>`, acaoDe('inativos')(l)), 'inativos')}
    ${grupo('No cadastro (ativos), sem lote no arquivo', 'bd-er', c.sem_lote,
      l => linha(l, [l.area_m2 ? fmtNum(l.area_m2) + ' m²' : '', esc(l.endereco || '')].filter(Boolean).join(' · '),
        andamento ? corrigir('sem_lote', l) : ''), 'sem_lote')}
    ${c.area_diferente === undefined ? '<div class="cad-nota cad-aviso">Esta conferência é de antes da comparação de áreas. Confira de novo para apontar os lotes com metragem diferente da do cadastro.</div>' : ''}
    ${c.sem_area_no_cadastro ? `<div class="cad-nota cad-aviso">${c.sem_area_no_cadastro} imóvel(is) casaram pela inscrição, mas o cadastro não traz a área do terreno deles: a metragem não pôde ser comparada.</div>` : ''}
    ${c.area_arredondamento ? `<div class="cad-nota imp-nota">${Number(c.area_arredondamento).toLocaleString('pt-BR')} lote(s) com diferença de arredondamento (até 0,02 m², medidas quebradas) resolvido(s) sozinho(s): vale a área do cadastro.</div>` : ''}
    ${grupo('Área do desenho diferente da área do cadastro', 'bd-er',
      [...(c.area_diferente || [])].sort((a, b) => Math.abs(b.diferenca ?? 0) - Math.abs(a.diferenca ?? 0)),
      l => linha(l, typeof _confAreas === 'function' ? _confAreas(l) : '', acaoDe('area_diferente')(l)), 'area_diferente')}
    ${grupo('Sem quadra ou número: a inscrição não se monta', 'bd-pe', c.sem_inscricao, l => linha(l, '', acaoDe('sem_inscricao')(l)), 'sem_inscricao')}
    ${htmlCoberturaDoCadastro(c.cobertura)}`,
    rodape: `
    <div class="imp-rodape-conf">
      <span class="imp-sub">${esc(c.fonte_descricao)} · conferido em ${esc(c.conferido_em)} por ${esc(c.conferido_por || '—')}${c.revisao ? ` · revisado sem planilha em ${esc(c.revisao.em)} (${c.revisao.resolvidas} resolvida(s))` : ''}
        · código do bairro ${esc(c.codigo_bairro)}</span>
      ${c.total_divergencias ? `<span class="jt-rodape-links"><a class="btn sm" href="/importacoes/${i.id}/divergencias.pdf" target="_blank" rel="noopener">Gerar PDF</a>
      <a class="btn sm" href="/api/importacoes/${i.id}/divergencias.csv">Baixar CSV</a></span>` : ''}
    </div>`,
  }
}

/**
 * RECONFERÊNCIA AUTOMÁTICA. Se algum lote da importação mudou depois da
 * última conferência, confere de novo com a MESMA fonte (a planilha guardada
 * ou o cadastro carregado) — sem anexar nada. É o que faz a pendência
 * resolvida no mapa sumir da lista sozinha.
 *
 * @returns {Promise<boolean>} true se reconferiu
 */
async function _impReconferirSePreciso(i) {
  if (!_impEmAndamento(i) || !i.conferencia_cadastro || i.conferencia_em_dia !== false) return false
  try {
    // Sem fonte nem planilha: o servidor confere com o cadastro carregado.
    const fd = new FormData()
    const antes = i.conferencia_cadastro.total_divergencias
    const r = await _impPedir(`/api/importacoes/${i.id}/conferir-cadastro`, { method: 'POST', body: fd })
    const resolvidas = antes - r.total_divergencias
    toast(resolvidas > 0
      ? `Conferência refeita: ${resolvidas} pendência(s) resolvida(s), ${r.total_divergencias} em aberto.`
      : `Conferência refeita: ${r.total_divergencias} pendência(s) em aberto.`)
    return true
  } catch { return false }
}

// Depois de cada correção no mapa (limparLotesDoMapa, app.js): com uma
// importação em andamento à vista, a barra e a ficha se atualizam sozinhas.
// Com espera, porque uma correção pode disparar mais de uma recarga seguida.
let _impReconferirTimer = null
document.addEventListener('lotes-alterados', () => {
  const i = impState.atual
  if (!impState.noMapa || !_impEmAndamento(i) || !i.conferencia_cadastro) return
  clearTimeout(_impReconferirTimer)
  _impReconferirTimer = setTimeout(async () => {
    try {
      const atual = await _impPedir('/api/importacoes/' + i.id)
      if (await _impReconferirSePreciso(atual)) {
        impState.atual = await _impPedir('/api/importacoes/' + i.id)
        pintarBarraImportacao()
      }
    } catch { /* a ficha reconfere ao abrir */ }
  }, 1500)
})

async function conferirComCadastro() {
  const i = impState.atual
  if (!i) return
  // Sempre com o cadastro carregado no sistema: pedido sem fonte e sem
  // planilha é exatamente isso para o servidor (ImportacaoController).
  const fd = new FormData()
  const btn = document.getElementById('imp-btn-conferir')
  btn.disabled = true
  btn.textContent = 'Conferindo…'
  try {
    await _impPedir(`/api/importacoes/${i.id}/conferir-cadastro`, { method: 'POST', body: fd })
    await abrirImportacao(i.id)
  } catch (e) {
    toast(e.message, 'err')
    btn.disabled = false
    btn.textContent = 'Conferir'
  }
}

// ── PUBLICAR / EXCLUIR ───────────────────────────────────────

function pedirPublicacaoImportacao() {
  const i = impState.atual
  const c = i.conferencia_cadastro
  const alvo = document.getElementById('imp-acao')
  if (!c || i.conferencia_em_dia === false) {
    alvo.innerHTML = `<div class="cad-nota cad-aviso">${!c
      ? 'Confira a importação com o cadastro da prefeitura antes de publicar.'
      : 'Houve alteração nos lotes depois da última conferência. Confira de novo antes de publicar.'}</div>`
    return
  }
  const n = c.total_divergencias
  alvo.innerHTML = n ? `
    <div class="imp-publicar">
      <div class="cad-nota cad-erro">A última conferência encontrou <b>${n} divergência(s)</b> com o cadastro.
        O recomendado é corrigir antes de publicar.</div>
      <div class="field">
        <label for="imp-justificativa">Justificativa para publicar mesmo assim (obrigatória, mínimo de 20 caracteres)</label>
        <textarea id="imp-justificativa" rows="3" maxlength="2000"></textarea>
      </div>
      <p class="imp-expl">A justificativa fica gravada na importação, com as divergências deste momento e quem publicou.</p>
      <div class="btn-row">
        <button class="btn" onclick="document.getElementById('imp-acao').innerHTML=''">Voltar e corrigir</button>
        <button class="btn primary" onclick="publicarImportacao()">Publicar com justificativa</button>
      </div>
    </div>` : `
    <div class="imp-publicar">
      <div class="cad-nota imp-nota">Sem divergências. Publicar libera os ${i.lotes} lotes para todos os usuários.</div>
      <div class="btn-row">
        <button class="btn" onclick="document.getElementById('imp-acao').innerHTML=''">Cancelar</button>
        <button class="btn primary" onclick="publicarImportacao()">Publicar</button>
      </div>
    </div>`
  alvo.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

async function publicarImportacao() {
  const i = impState.atual
  const just = document.getElementById('imp-justificativa')?.value.trim() ?? ''
  if (i.conferencia_cadastro.total_divergencias && just.length < 20) {
    exigirCampo('imp-justificativa', 'Escreva a justificativa (mínimo de 20 caracteres).')
    return
  }
  try {
    const d = await _impPedir(`/api/importacoes/${i.id}/publicar`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ justificativa: just || null }),
    })
    toast(d.message)
    if (impState.preCuradoria?.id === i.id) sairPreCuradoria()
    recarregarLotesDoMapa()
    // O bairro publicado ganha (ou renova) o contorno. Se falhar, a publicação
    // continua valendo: o bairro só fica "sem contorno" na lista da curadoria.
    if (typeof gerarContornoDoBairro === 'function') {
      const c = await gerarContornoDoBairro(i.bairro, CONTORNO_RAIO_PADRAO, { silencioso: true })
      toast(c ? `Contorno de ${i.bairro} gerado: ${c.area_ha.toLocaleString('pt-BR')} ha.`
              : `O contorno de ${i.bairro} não pôde ser gerado agora. Gere em "Contorno dos bairros".`, c ? 'ok' : 'aviso')
    }
    await abrirImportacao(i.id)
  } catch (e) {
    toast(e.message, 'err')
  }
}

function pedirExclusaoImportacao() {
  const i = impState.atual
  const alvo = document.getElementById('imp-acao')
  if (Object.keys(i.vinculos || {}).length) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">Não é possível excluir: há lotes desta importação com vínculos (listados acima).</div>`
    return
  }
  alvo.innerHTML = `
    <div class="imp-publicar">
      <div class="cad-nota cad-aviso">Os ${i.lotes} lotes desta importação serão apagados. Nenhum tem documento,
        vistoria, protocolo, ordem de serviço ou BCI. O desenho de cada um fica guardado, e o registro da
        importação continua na lista como "Excluída".</div>
      <div class="field">
        <label for="imp-motivo">Motivo</label>
        <input type="text" id="imp-motivo" maxlength="500" placeholder="Arquivo com quadras trocadas; será reenviado">
      </div>
      <div class="btn-row">
        <button class="btn" onclick="document.getElementById('imp-acao').innerHTML=''">Cancelar</button>
        <button class="btn cancel-bordo" onclick="excluirImportacao()">Excluir ${i.lotes} lotes</button>
      </div>
    </div>`
  alvo.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

async function excluirImportacao() {
  const i = impState.atual
  const motivo = document.getElementById('imp-motivo').value.trim()
  if (motivo.length < 10) { exigirCampo('imp-motivo', 'Descreva o motivo em ao menos 10 caracteres.'); return }
  try {
    const d = await _impPedir(`/api/importacoes/${i.id}/excluir`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ motivo }),
    })
    toast(d.message)
    if (impState.preCuradoria?.id === i.id) sairPreCuradoria()
    recarregarLotesDoMapa()
    // O loteamento saiu: a linha dele, o nome e as quadras saem do mapa junto.
    if (typeof recarregarContornosDoMapa === 'function') recarregarContornosDoMapa()
    await abrirImportacao(i.id)
  } catch (e) {
    toast(e.message, 'err')
  }
}

// ── SALVAR / DESCARTAR O RASCUNHO ────────────────────────────

function pedirSalvamentoImportacao() {
  const i = impState.atual
  const alvo = document.getElementById('imp-acao')
  const c = i.conferencia_cadastro
  const ajustes = i.pre_curadoria?.total || 0
  const situacao = !c
    ? '<div class="cad-nota cad-aviso">O rascunho <b>ainda não foi conferido</b> com o cadastro. Dá para salvar e conferir depois — a publicação é que exige a conferência.</div>'
    : i.conferencia_em_dia === false
      ? '<div class="cad-nota cad-aviso">Houve alteração nos lotes depois da última conferência. Dá para salvar e conferir de novo depois.</div>'
      : c.total_divergencias
        ? `<div class="cad-nota cad-aviso">A última conferência encontrou <b>${c.total_divergencias} divergência(s)</b>. Dá para salvar e seguir corrigindo — a publicação pede correção ou justificativa.</div>`
        : '<div class="cad-nota imp-nota">Conferido com o cadastro, sem divergências.</div>'
  alvo.innerHTML = `
    <div class="imp-publicar">
      ${situacao}
      <p class="imp-expl">Ao salvar, a importação de <b>${esc(i.bairro)}</b> passa a valer: ganha número, entra no
        registro${ajustes ? ` com os ${ajustes} ajuste(s) da pré-curadoria` : ''} e fica na lista como <b>salva, não publicada</b>. O bairro só passa a valer quando for publicado.
        Depois disso ela não se descarta mais — só se exclui, com motivo.</p>
      <div class="btn-row">
        <button class="btn" onclick="document.getElementById('imp-acao').innerHTML=''">Cancelar</button>
        <button class="btn primary" id="imp-btn-salvar" onclick="salvarImportacao()">Salvar importação</button>
      </div>
    </div>`
  alvo.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

async function salvarImportacao() {
  const i = impState.atual
  const btn = document.getElementById('imp-btn-salvar')
  if (btn) { btn.disabled = true; btn.textContent = 'Salvando…' }
  try {
    const d = await _impPedir(`/api/importacoes/${i.id}/salvar`, { method: 'POST' })
    toast(d.message)
    // SALVOU, JÁ TEM CONTORNO — mesmo sem publicar. É o que isola o bairro no
    // painel de correção e mostra o nome dele no mapa afastado. Se falhar, a
    // importação continua salva; "Contorno dos bairros" gera depois.
    if (typeof gerarContornoDoBairro === 'function') {
      const c = await gerarContornoDoBairro(i.bairro, CONTORNO_RAIO_PADRAO, { silencioso: true })
      if (c?.mantido) toast(c.message, 'aviso')
      else if (c) toast(`Contorno de ${i.bairro} gerado: ${c.area_ha.toLocaleString('pt-BR')} ha.`)
      else toast(`O contorno de ${i.bairro} não pôde ser gerado agora. Gere em "Contorno dos bairros".`, 'aviso')
      // Os VIZINHOS também: o contorno de um bairro gerado antes deste existir
      // não conhecia a divisa, e ficava com fresta (Atlântico Sul × Parque das
      // Águas, 19/10/2026). Editado à mão fica como está.
      if (c && !c.mantido && typeof gerarContornosVizinhos === 'function') await gerarContornosVizinhos(i.bairro)
    }
    await abrirImportacao(i.id)
    { const d = await _impPedir("/api/importacoes"); atualizarContadorImportacoes(d.em_revisao, _impPendentes(d)) }
  } catch (e) {
    toast(e.message, 'err')
    if (btn) { btn.disabled = false; btn.textContent = 'Salvar importação' }
  }
}

function pedirDescarteImportacao() {
  const i = impState.atual
  const alvo = document.getElementById('imp-acao')
  if (Object.keys(i.vinculos || {}).length) {
    alvo.innerHTML = `<div class="cad-nota cad-erro">Não é possível descartar: há lotes do rascunho com vínculos (listados acima).</div>`
    return
  }
  alvo.innerHTML = `
    <div class="imp-publicar">
      <div class="cad-nota cad-aviso">Os lotes do rascunho de <b>${esc(i.bairro)}</b>, a conferência e os ajustes da
        pré-curadoria serão apagados. Como a importação nunca foi salva, <b>não fica registro</b> dela.</div>
      <div class="btn-row">
        <button class="btn" onclick="document.getElementById('imp-acao').innerHTML=''">Cancelar</button>
        <button class="btn cancel-bordo" onclick="descartarImportacao()">Descartar rascunho</button>
      </div>
    </div>`
  alvo.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

async function descartarImportacao() {
  const i = impState.atual
  try {
    const d = await _impPedir(`/api/importacoes/${i.id}/descartar`, { method: 'POST' })
    toast(d.message)
    if (impState.preCuradoria?.id === i.id) sairPreCuradoria()
    impState.atual = null
    pintarBarraImportacao()
    recarregarLotesDoMapa()
    if (typeof recarregarContornosDoMapa === 'function') recarregarContornosDoMapa()
    if (!document.getElementById('m-importacoes').classList.contains('open')) openModal('m-importacoes')
    await carregarListaImportacoes()
  } catch (e) {
    toast(e.message, 'err')
  }
}

// ── PRÉ-CURADORIA ────────────────────────────────────────────
//
// As MESMAS ferramentas da alteração cadastral, aplicadas ao bairro em revisão
// antes de ele ser publicado. O que muda é o alcance (só os lotes desta
// importação — ver alternarSelecao em cadastro.js) e o registro: o servidor
// grava na ficha da importação, fora do Histórico do cadastro
// (Lote::tabelaDaAuditoria). Entra também o Editar lote, que só existe aqui.

function entrarPreCuradoria() {
  if (!_impEmAndamento(impState.atual)) return
  // Pré-curadoria É alteração cadastral: busca, pinos e cores abertos fecham.
  pedirFerramenta('curadoria', _entrarPreCuradoria)
}

/**
 * Liga a pré-curadoria da importação que foi ao mapa.
 *
 * UM MODO SÓ: importação não publicada no mapa É pré-curadoria. Antes havia
 * dois — "Ver no mapa" (sem Editar lote, Informar número e Excluir lotes) e
 * "Pré-curadoria" (com elas) —, e a diferença só existia na tela: o servidor
 * sempre tratou igual qualquer ajuste em lote não publicado
 * (Lote::tabelaDaAuditoria). Quem chegava pelo "ver no mapa ›" da conferência
 * ficava sem as ferramentas e sem entender por quê.
 */
function _ligarPreCuradoria(i) {
  if (!_impEmAndamento(i) || impState.preCuradoria?.id === i.id) return
  impState.preCuradoria = { id: i.id, bairro: i.bairro }
  if (typeof limparSelecaoCadastral === 'function') limparSelecaoCadastral()
  _ferramentasDaPreCuradoria()
}

/** Abre a alteração cadastral: a mesa lateral em tela grande, o painel no celular. */
function _abrirMesaDaCuradoria() {
  const mesa = document.getElementById('cad-mesa')
  const aberto = typeof ehMesaCadastral === 'function' && ehMesaCadastral()
    ? mesa && !mesa.hidden
    : document.getElementById('grupo-cadastro')?.classList.contains('aberto')
  if (!aberto && typeof alternarPainelMapa === 'function') alternarPainelMapa('grupo-cadastro')
  if (typeof montarReguaCadastral === 'function') montarReguaCadastral()
}

function _entrarPreCuradoria() {
  const i = impState.atual
  verImportacaoNoMapa()   // liga a pré-curadoria (_ligarPreCuradoria)
  setTimeout(_abrirMesaDaCuradoria, 200)
  toast(`Pré-curadoria · ${_impNome(i)}: as ferramentas só alcançam estes lotes.`)
}

/** Sair da pré-curadoria é tirar a importação do mapa: a barra fecha junto. */
function sairPreCuradoria() {
  impState.preCuradoria = null
  impState.noMapa = false
  _ferramentasDaPreCuradoria()
  if (typeof limparSelecaoCadastral === 'function') limparSelecaoCadastral()
  if (typeof montarReguaCadastral === 'function') montarReguaCadastral()
  pintarBarraImportacao()
}

/** Editar lote: o lote marcado (ou o do balão) abre na prancheta, com o contorno editável. */
function editarLoteDaPreCuradoria() {
  const marcado = typeof selState !== 'undefined' && selState.ids.size === 1
    ? mapaState.porId.get([...selState.ids][0])?.feature?.properties : null
  const p = marcado ?? state.selecionado?.properties
  if (!p?.id) { toast('Marque o lote que vai editar.', 'err'); return }
  // Em pré-curadoria as ferramentas só alcançam os lotes daquela importação.
  // Fora dela, edita-se qualquer lote ativo — o publicado inclusive (EdicaoDeLote).
  if (impState.preCuradoria && Number(p.importacao_id) !== impState.preCuradoria.id) {
    toast('Só entram lotes da importação em pré-curadoria.', 'err'); return
  }
  PranchetaCad.abrir('edicao', [p.id], null, null, {
    quadra: p.quadra, numero_lote: p.numero_lote,
    aoSalvar: () => { if (impState.atual) abrirImportacaoSilenciosa(impState.atual.id) },
  })
}

/**
 * Mostra (ou esconde) Editar lote, Informar número e Excluir lotes, e refaz a
 * régua da mesa, que é montada a partir dos lançadores visíveis.
 *
 * Aparecem enquanto HOUVER importação não publicada — e não só com a barra da
 * pré-curadoria aberta. O que elas exigem é o LOTE ser de importação não
 * publicada; quem abria a alteração cadastral direto no mapa via os lotes do
 * rascunho, marcava um e ouvia "só na pré-curadoria".
 */
function _ferramentasDaPreCuradoria() {
  const mostrar = !!impState.preCuradoria || impState.pendentes > 0
  // Quem tem a lixeira da curadoria ("Apagar lotes") não vê um segundo botão
  // de excluir: ela mesma apaga o lote não publicado (abrirExclusaoSelecionados).
  const temLixeira = !!document.querySelector('#cad-geral .cad-lanca[data-fer="apagar"]')
  document.querySelectorAll('#cad-geral .cad-lanca.so-pre').forEach(b => {
    b.hidden = !mostrar || (temLixeira && b.dataset.fer === 'excluir-pre')
  })
  if (typeof montarReguaCadastral === 'function') montarReguaCadastral()
}

/**
 * Os lotes marcados, conferindo que são todos de UMA importação não publicada.
 * A importação sai dos próprios lotes: a ferramenta vale com ou sem a barra da
 * pré-curadoria aberta. O servidor confere de novo (PreCuradoriaDeLotes).
 */
function _lotesMarcadosDaPreCuradoria() {
  const pre = impState.preCuradoria
  const props = [...(typeof selState !== 'undefined' ? selState.ids : [])]
    .map(id => mapaState.porId.get(id)?.feature?.properties).filter(Boolean)
  if (!props.length) { toast('Marque o lote no mapa.', 'err'); return null }
  if (props.some(p => !p.em_revisao || !p.importacao_id)) {
    toast('Esta ferramenta vale só para lote de importação não publicada.', 'err'); return null
  }
  const imp = Number(props[0].importacao_id)
  if (props.some(p => Number(p.importacao_id) !== imp) || (pre && imp !== pre.id)) {
    toast(pre ? 'Só entram lotes da importação em pré-curadoria.' : 'Marque lotes de uma importação só.', 'err'); return null
  }
  return props
}

/** Depois de mexer nos lotes: mapa, marcação, barra e ficha em dia. */
function _depoisDaPreCuradoria(msg) {
  toast(msg)
  if (typeof limparSelecaoCadastral === 'function') limparSelecaoCadastral()
  recarregarLotesDoMapa()
  if (impState.atual) abrirImportacaoSilenciosa(impState.atual.id)
}

/** Informar número do lote — 1 lote marcado. */
function numerarLoteDaPreCuradoria() {
  const lotes = _lotesMarcadosDaPreCuradoria()
  if (!lotes) return
  if (lotes.length !== 1) { toast('Marque só 1 lote para informar o número.', 'err'); return }
  const p = lotes[0]
  pedirTexto({
    titulo: 'Número do lote',
    rotulo: `Quadra ${p.quadra ?? '—'} · número atual: ${p.numero_lote ?? 'sem número'}`,
    dica: 'A quadra continua a mesma. Para mudar a quadra, use "Corrigir quadra".',
    minimo: 1, linhas: 1, valor: p.numero_lote ?? '', textoBtn: 'Gravar número',
    onOk: async numero => {
      try {
        const d = await _impPedir(`/api/importacoes/${Number(p.importacao_id)}/lotes/${p.id}/numero`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ numero_lote: numero }),
        })
        _depoisDaPreCuradoria(d.message)
      } catch (e) { toast(e.message, 'err') }
    },
  })
}

/** Excluir lotes — um ou vários marcados, todos da importação em pré-curadoria. */
function excluirLotesDaPreCuradoria() {
  const lotes = _lotesMarcadosDaPreCuradoria()
  if (!lotes) return
  const nomes = lotes.slice(0, 8).map(p => `Q${p.quadra ?? '?'} L${p.numero_lote ?? '?'}`).join(', ')
  confirmarAcao({
    titulo: lotes.length === 1 ? 'Excluir 1 lote' : `Excluir ${lotes.length} lotes`,
    mensagem: `${nomes}${lotes.length > 8 ? ' e outros' : ''} ${lotes.length === 1 ? 'sairá' : 'sairão'} desta importação. `
      + 'A exclusão fica no registro da pré-curadoria.',
    textoBtn: 'Excluir', perigo: true,
    onConfirm: async () => {
      try {
        const d = await _impPedir(`/api/importacoes/${Number(lotes[0].importacao_id)}/lotes/excluir`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ids: lotes.map(p => p.id) }),
        })
        _depoisDaPreCuradoria(d.message)
      } catch (e) { toast(e.message, 'err') }
    },
  })
}

/** Atualiza a importação aberta sem abrir o modal (a barra do mapa usa os números). */
async function abrirImportacaoSilenciosa(id) {
  try { impState.atual = await _impPedir('/api/importacoes/' + id); pintarBarraImportacao() } catch { /* a barra fica como estava */ }
}

// ── NO MAPA ──────────────────────────────────────────────────

/** Leva o mapa à importação aberta e deixa a barra de revisão à vista. */
function verImportacaoNoMapa() {
  const e = impState.atual?.extensao
  if (!e) return
  if (!camadaLigada('nao-publicados')) ligarCamada('nao-publicados', true)
  fecharImportacoes()
  impState.noMapa = true
  _ligarPreCuradoria(impState.atual)
  if (typeof irPara === 'function') irPara('mapa')
  setTimeout(() => {
    mapaState.obj?.fitBounds([[e.sul, e.oeste], [e.norte, e.leste]], { padding: [30, 30] })
  }, 120)
  pintarBarraImportacao()
}

/** Um lote da conferência, no mapa. */
async function irAoLoteDaImportacao(loteId) {
  try {
    const d = await _impPedir('/api/imoveis/' + loteId)
    fecharImportacoes()
    impState.noMapa = true
    _ligarPreCuradoria(impState.atual)
    // O lote é da importação: sem a camada de revisão ligada ele nem existe no mapa.
    if (!camadaLigada('nao-publicados')) ligarCamada('nao-publicados', true)
    if (d.lat && d.lon) verImovelNoMapa(d.lat, d.lon)
    pintarBarraImportacao()
    if (!impState.preCuradoria) { destacarLoteQuandoCarregar(Number(loteId)); return }   // mapa.js

    // Em pré-curadoria o "ver no mapa ›" é para CORRIGIR o lote: a mesa abre e
    // o lote já chega marcado, pronto para N, L ou X. Só destacado, com a mesa
    // fechada, o atalho não fazia nada — e sem aviso nenhum.
    setTimeout(_abrirMesaDaCuradoria, 200)
    await destacarLoteQuandoCarregar(Number(loteId))
    const camada = mapaState.porId?.get(Number(loteId))
    if (camada && typeof alternarSelecao === 'function' && typeof selState !== 'undefined') {
      if (typeof limparSelecaoCadastral === 'function') limparSelecaoCadastral()
      alternarSelecao(camada.feature, camada)
    }
  } catch (e) {
    toast(e.message, 'err')
  }
}

/** "Desenhar lote" da conferência: a ferramenta que já existe, no bairro em revisão. */
function desenharLoteDaConferencia() {
  // Desenhar o lote que falta É pré-curadoria desta importação — e levar a
  // importação ao mapa já a liga.
  verImportacaoNoMapa()
  setTimeout(() => {
    if (typeof modoCadastral === 'function') modoCadastral('desenho')
    toast('Desenhe o lote que falta. Ele entra na mesma revisão do bairro.')
  }, 400)
}

/** Liga/desliga a camada dos lotes em revisão. */
function alternarLotesEmRevisao() {
  recarregarLotesDoMapa()
  pintarBarraImportacao()
}

function recarregarLotesDoMapa() {
  if (typeof limparLotesDoMapa !== 'function') return
  limparLotesDoMapa()
  carregarLotesVisiveis()
}

/** A barra sobre o mapa: a importação que se está revisando. */
function pintarBarraImportacao() {
  const barra = document.getElementById('imp-barra')
  const i = impState.atual
  if (!barra) return
  const mostrando = camadaLigada('nao-publicados')
  // Ferramenta COMUM da curadoria em uso (fora da pré-curadoria desta
  // importação): a barra sai de cena enquanto ela dura — uma barra de contexto
  // por vez — e volta quando a ferramenta é largada.
  const ferramentaDeFora = !impState.preCuradoria
    && ((typeof cadModo !== 'undefined' && !!cadModo) || (typeof atoState !== 'undefined' && !!atoState.tipo))
  const comPainel = typeof correcaoAberta === 'function' && correcaoAberta()
  barra.hidden = !impState.noMapa || !_impEmAndamento(i) || !mostrando || ferramentaDeFora || comPainel
  if (barra.hidden) return
  curadoriaApareceu()   // a barra da importação é curadoria: a pesquisa sai (ferramentas-mapa.js)

  const rascunho = i.status === 'rascunho'
  const n = i.conferencia_cadastro ? i.conferencia_cadastro.total_divergencias : null
  const pre = impState.preCuradoria?.id === i.id
  barra.classList.toggle('pre', pre)
  // "‹ Ficha" é a volta: "Ver no mapa", "desenhar lote" e "ver no mapa ›" da
  // conferência fecham a janela da importação, e esta barra é o caminho de
  // volta para ela.
  barra.innerHTML = `
    <button class="btn sm imp-barra-ficha" onclick="abrirImportacao(${i.id})" title="Voltar à ficha da importação">&#8249; Ficha</button>
    <b ${pre ? `title="As ferramentas só alcançam os lotes ${rascunho ? 'deste rascunho' : 'desta importação'}"` : ''}>${pre ? 'Pré-curadoria · ' : ''}${rascunho
      ? `Rascunho · ${esc(i.bairro)} · não salvo`
      : `Importação nº ${i.id} · ${esc(i.bairro)} · não publicada`}</b>
    <span>${Number(i.lotes).toLocaleString('pt-BR')} lotes</span>
    ${n === null ? '<span class="badge bd-pe">não conferida</span>'
      : (n ? `<span class="badge bd-er">${n} divergência(s)</span>` : '<span class="badge bd-ok">sem divergências</span>')}
    <span class="imp-barra-acoes">
      ${rascunho ? `
      <button class="btn sm" onclick="abrirImportacao(${i.id}).then(pedirDescarteImportacao)">Descartar</button>
      <button class="btn sm primary" onclick="abrirImportacao(${i.id}).then(pedirSalvamentoImportacao)">Salvar importação</button>` : `
      <button class="btn sm" onclick="abrirImportacao(${i.id}).then(pedirExclusaoImportacao)">Excluir</button>
      ${impState.podePublicar
        ? `<button class="btn sm primary" onclick="abrirImportacao(${i.id}).then(pedirPublicacaoImportacao)">Publicar</button>`
        : '<span class="imp-sub">Publicar: aguardando administrador</span>'}`}
      <button class="btn sm imp-barra-x" title="Sair da pré-curadoria" onclick="sairPreCuradoria(); impState.atual=null; pintarBarraImportacao()">&#10005;</button>
    </span>`
}

// Contador do painel ao abrir a página: quantas importações esperam revisão.
document.addEventListener('DOMContentLoaded', async () => {
  try {
    const d = await _impPedir('/api/importacoes')
    atualizarContadorImportacoes(d.em_revisao, _impPendentes(d))
  } catch { /* sem contador — o botão continua funcionando */ }
})

// ── A BARRA LATERAL DA BASE DE LOTES ─────────────────────────
//
// Em tela grande, o ícone "Base de lotes" abre uma régua encostada na borda —
// a mesma da alteração cadastral, com os mesmos quadrados e a mesma dica —, e
// não um painel flutuante. Ela é MONTADA a partir dos lançadores de
// #grupo-base, como a da curadoria é montada de #cad-geral: ferramenta nova
// entra num lugar só. Em tela pequena continua valendo o painel.

/** A régua da base está à vista? */
const baseDeLotesAberta = () => document.getElementById('base-mesa')?.hidden === false

/** O ícone da coluna de controles. */
function alternarBaseDeLotes() {
  const mesa = document.getElementById('base-mesa')
  if (!mesa || typeof ehMesaCadastral !== 'function' || !ehMesaCadastral()) { alternarPainelMapa('grupo-base'); return }
  if (!mesa.hidden) { fecharBaseDeLotes(); return }
  fecharPaineisMapa()
  // Ocupa o mesmo canto da mesa da curadoria: abrir uma fecha a outra.
  pedirFerramenta('base', () => {
    _montarReguaDaBase()
    mesa.hidden = false
    document.body.classList.add('com-base')
    _pintarIconeDaBase()
  })
}

function fecharBaseDeLotes() {
  const mesa = document.getElementById('base-mesa')
  if (!mesa || mesa.hidden) return
  mesa.hidden = true
  document.body.classList.remove('com-base')
  _pintarIconeDaBase()
}

function _pintarIconeDaBase() {
  const b = document.querySelector('#grupo-base .ctrl-btn')
  if (!b) return
  b.classList.toggle('med-ativo', baseDeLotesAberta())
  b.setAttribute('aria-expanded', String(baseDeLotesAberta()))
}

function _montarReguaDaBase() {
  const regua = document.getElementById('base-regua')
  if (!regua) return
  let h = '<div class="cad-regua-rolo"><div class="cad-regua-gp">'
  for (const b of document.querySelectorAll('#grupo-base .cad-lanca')) {
    const nome = b.querySelector('.cad-lanca-txt')?.childNodes[0]?.textContent.trim() ?? ''
    const obs = b.querySelector('.cad-lanca-obs')?.childNodes[0]?.textContent.trim() ?? ''
    // As importações não publicadas, em número, no canto do quadrado.
    const conta = b.dataset.fer === 'importacoes' ? document.getElementById('imp-contador') : null
    const n = conta && !conta.hidden ? (conta.textContent.match(/\d+/) || [''])[0] : ''
    h += `<button type="button" class="cad-fer" data-base="${b.dataset.fer}" aria-label="${esc(nome)}" title="${esc(nome)}">
        ${b.querySelector('.cad-ico')?.outerHTML ?? ''}${n ? `<span class="base-selo">${esc(n)}</span>` : ''}
        <span class="cad-dica-fer"><b>${esc(nome)}</b><span>${esc(obs)}</span>${n ? `<span class="exige">${esc(conta.textContent.trim())}</span>` : ''}</span>
      </button>`
  }
  h += '</div></div><div class="cad-regua-pe">'
    + '<button type="button" class="cad-fer cad-fechar" onclick="fecharBaseDeLotes()" aria-label="Fechar a barra" title="Fechar a barra">'
    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'
    + '<span class="cad-dica-fer"><b>Fechar a barra</b><span>As ferramentas da base de lotes continuam no ícone da direita.</span></span>'
    + '</button></div>'
  regua.innerHTML = h
  regua.querySelectorAll('.cad-fer[data-base]').forEach(f => {
    f.onclick = () => document.querySelector(`#grupo-base .cad-lanca[data-fer="${f.dataset.base}"]`)?.click()
  })
}
