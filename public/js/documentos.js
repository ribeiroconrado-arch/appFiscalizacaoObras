// ══════════════════════════════════════════════
// MÓDULO: DOCUMENTOS (Etapa 6)
//
// Lista e lavratura de notificações, autos, termos e vistorias documentais.
// O cartão segue o padrão do módulo Autos do AppPOSTURAS — quatro linhas com
// número em badge monoespaçado — porque são os mesmos servidores lendo.
// ══════════════════════════════════════════════

/** Estado da aba Documentos. */
const dState = {
  /** @type {Array<Object>} */ lista: [],
  /** @type {Object|null} */   opcoes: null,
  filtros: { tipo: '', status: '', agente: 'eu', busca: '' },
}

/**
 * O documento aberto na ficha, e o formato de saída escolhido no menu.
 *
 * Vive fora de `dState` porque não é estado da LISTA: o formulário
 * (documento-form.js) lê o mesmo objeto, e as ações do menu de Opções — lavrar,
 * anular, excluir, imprimir — precisam saber sobre qual documento agem, tanto
 * quando o menu sai do cartão da lista quanto quando sai do rodapé da ficha.
 *
 * `saida` guarda a escolha entre o clique no menu e a resposta sobre anexos:
 * a pergunta "imprimir com as fotos?" fica no meio do caminho, e sem isso a
 * confirmação não saberia se era PDF, A4 ou bobina.
 */
const dFicha = {
  /** @type {Object|null} */                  doc: null,
  /** @type {'pdf'|'a4'|'termica'|null} */    saida: null,
}

// ── LISTA ────────────────────────────────────────────────────

/** Busca a lista aplicando os filtros correntes. */
async function carregarDocumentos() {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(dState.filtros)) {
    if (v) p.set(k, v === 'todos' && k === 'agente' ? 'todos' : v)
  }
  const alvo = document.getElementById('lista-documentos')
  alvo.innerHTML = '<div class="lista-vazia">Carregando…</div>'
  mostrarCarregandoTela('Buscando documentos...')
  try {
    const r = await fetch('/api/documentos?' + p, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    dState.lista = d.documentos
    renderDocumentos()
    limparBuscaPendente('doc-buscar')
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="lista-vazia">Não foi possível carregar os documentos.</div>'
  } finally {
    esconderCarregandoTela()
  }
}

/**
 * A LISTA MISTURA PEÇAS E VISTORIAS, e cada linha abre a sua janela.
 *
 * As duas respondem à mesma pergunta — "o que foi feito neste imóvel?" — e
 * viviam em telas separadas, obrigando a procurar duas vezes. O que não se faz
 * é tratá-las como iguais: vistoria não tem autuado nem prazo de defesa, e as
 * colunas saem com travessão em vez de um valor inventado para preencher.
 *
 * @param {Object} d @returns {string} o onclick da linha
 */
/**
 * O enquadramento da linha.
 *
 * "Sem fundamentação" em vermelho é defeito de PEÇA: auto sem artigo não
 * sustenta sanção, e descobrir isso na hora de lavrar é tarde. Numa vistoria o
 * mesmo vazio é normal — a maioria delas não constata irregularidade nenhuma —,
 * e pintá-lo de vermelho ensinaria o fiscal a ignorar o alerta justamente onde
 * ele importa.
 *
 * @param {Object} d @returns {string}
 */
function fundamentacaoDe(d) {
  if (d.artigos) {
    return `${d.artigos} artigo(s)` + (d.valor_upf ? ` · ${fmtNum(d.valor_upf)} UPF` : '')
  }
  return d.registro === 'vistoria'
    ? '<span class="tl-fraco">sem artigo citado</span>'
    : '<span class="tl-falta">sem fundamentação</span>'
}

function aberturaDe(d) {
  return d.registro === 'vistoria' ? `verVistoria(${d.id})` : `abrirDocumento(${d.id})`
}

/** @param {Object} d @returns {string} o onclick do ⋮ */
function menuDe(d) {
  return d.registro === 'vistoria'
    ? `abrirOpcoesVistoria(event, ${d.id})`
    : `abrirOpcoesDoc(event, ${d.id})`
}

/**
 * O menu da linha de vistoria.
 *
 * Uma opção só, e é a honesta: vistoria gravada se lê, não se altera. Corrigir
 * uma exigiria trilha de alteração — quem mudou, quando e o quê —, porque ela
 * já fundamentou o que veio depois. Anular ou reimprimir são coisas de peça.
 *
 * @param {Event} ev @param {number} id
 */
function abrirOpcoesVistoria(ev, id) {
  abrirMenuNovo(ev, [{
    rotulo: 'Abrir a vistoria',
    obs: 'O relatório como foi escrito, com fotos e artigos.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>`,
    acao: () => verVistoria(id),
  }])
}

/**
 * O cartão em TRÊS níveis, no lugar de quatro linhas de peso igual.
 *
 * Antes, imóvel, autuado, lei e artigos saíam um sob o outro com o mesmo
 * rótulo cinza e o mesmo tamanho — e o número da peça, que é por onde ela é
 * citada, cobrada e procurada, tinha o mesmo destaque que o nome da lei.
 *
 *   identificação   ícone do tipo, número, tipo — o que responde "é esta?"
 *   quem e onde     autuado e imóvel, que é o que se procura em seguida
 *   rodapé          lei, artigos, valor e data: conferência, não busca
 *
 * A barra colorida na lateral repete o status em forma, e não só em cor: numa
 * lista de vinte, o selo sozinho obriga a ler cada um para achar o rascunho.
 */
function renderDocumentos() {
  const alvo = document.getElementById('lista-documentos')
  document.getElementById('cont-doc').textContent = dState.lista.length
  pintarChipsDoc()

  if (!dState.lista.length) {
    alvo.innerHTML = '<div class="lista-vazia">Nenhum documento com esses filtros.</div>'
    return
  }

  alvo.innerHTML = ehTelaLarga() ? tabelaDocumentos() : cartoesDocumentos()
}

/**
 * A LISTA EM TABELA, no computador.
 *
 * O que muda em relação ao cartão não é a roupa, é o alinhamento: "Situação"
 * lida de cima a baixo responde numa varredura o que o cartão só responde item
 * a item. O conteúdo é o mesmo — e o menu ⋮ é o mesmo `abrirOpcoesDoc`, com as
 * opções que o servidor liberou para aquela peça.
 *
 * @returns {string}
 */
function tabelaDocumentos() {
  const linhas = dState.lista.map(d => {
    // PADRÃO M1 (ver css/tabelas.css): uma coluna por informação, nada
    // empilhado. A fundamentação saiu da tabela — repetia "sem artigo citado"
    // em quase toda linha — e continua na ficha da peça e no cartão do celular.
    // O título do Tipo traz a lei e os artigos, para quem passar o mouse.
    // Na vistoria o rótulo é a FINALIDADE ("Fiscalização de obras"): na
    // coluna Tipo vai "Vistoria", e a finalidade fica na dica.
    const tipo = d.registro === 'vistoria' ? 'Vistoria' : d.tipo_rotulo
    const dica = d.lei && d.lei !== '—' ? `${d.lei} · ${d.artigos} artigo(s)` : d.tipo_rotulo
    return `
      <tr class="st-${esc(d.status.valor ?? '')}" onclick="${esc(aberturaDe(d))}">
        <td><span class="tl-cod">${esc(d.numero || '—')}</span></td>
        <td title="${esc(dica)}">${esc(tipo)}</td>
        <td>${esc(d.lote_curto ?? d.imovel)}</td>
        <td class="tl-cinza" title="${esc(d.bairro ?? '')}">${esc(d.bairro ?? '—')}</td>
        <td title="${esc(d.autuado)}">${esc(d.autuado)}</td>
        <td class="tl-cinza" title="${esc(d.agente ?? '')}">${esc(d.agente ?? '—')}</td>
        <td>${esc((d.data || '').slice(0, 5))}</td>
        <td>
          <span class="tl-tags">
            <span class="badge ${esc(d.status.classe)}">${esc(d.status.texto)}</span>
            ${d.prazo ? `<span class="badge ${esc(d.prazo.classe)}">${esc(d.prazo.texto)}</span>` : ''}
          </span>
        </td>
        <td class="tl-acao">
          <button type="button" class="card-opcoes-btn" title="Opções"
                  onclick="${esc(menuDe(d))}">${ICO_TRES_PONTOS}</button>
        </td>
      </tr>`
  }).join('')

  return `
    <div class="tabela-wrap">
      <table class="tabela-lista tl-doc">
        <thead><tr>
          <th>Nº</th><th>Tipo</th><th>Imóvel</th><th>Bairro</th><th>Autuado</th>
          <th>Agente</th><th>Data</th><th>Situação</th><th class="tl-acao"></th>
        </tr></thead>
        <tbody>${linhas}</tbody>
      </table>
      <div class="tl-rodape"><span>Mostrando ${dState.lista.length} documento(s)</span></div>
    </div>`
}

/** @returns {string} a mesma lista em cartões, no celular */
function cartoesDocumentos() {
  return dState.lista.map(d => {
    const tags = [
      `<span class="badge ${esc(d.status.classe)}">${esc(d.status.texto)}</span>`,
      d.prazo ? `<span class="badge ${esc(d.prazo.classe)}">${esc(d.prazo.texto)}</span>` : '',
    ].join('')

    const rodape = fundamentacaoDe(d)

    return `
      <div class="mob-card doc-card st-${esc(d.status.valor ?? '')}" onclick="${esc(aberturaDe(d))}">
        <div class="doc-card-topo">
          <span class="doc-ico">${ICO_TIPO_DOC[d.tipo] || ICO_TIPO_DOC.padrao}</span>
          <div class="doc-ident">
            <div class="doc-l1">
              ${d.numero ? `<span class="proto-badge">${esc(d.numero)}</span>` : ''}
              <span class="doc-tipo">${esc(d.tipo_rotulo)}</span>
            </div>
            ${d.autuado && d.autuado !== '—'
              ? `<div class="doc-autuado">${esc(d.autuado)}</div>` : ''}
            <div class="doc-imovel">${esc(d.imovel)}</div>
          </div>
          <div class="mc-acoes">
            ${tags}
            <div class="df-opcoes card-opcoes">
              <button type="button" class="card-opcoes-btn" title="Opções"
                      onclick="${esc(menuDe(d))}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
              </button>
            </div>
          </div>
        </div>
        <div class="doc-rodape">
          ${d.lei && d.lei !== '—'
            ? `<span class="doc-lei">${esc(d.lei)}</span>`
            : (d.registro === 'vistoria' ? '' : '<span class="doc-lei">sem legislação</span>')}
          <span class="doc-art">${rodape}</span>
          <span class="doc-data">${esc(d.data)}</span>
        </div>
      </div>`
  }).join('')
}

// ── FILTROS ──────────────────────────────────────────────────

/** O que cada filtro se chama na etiqueta, e como se lê o valor escolhido. */
const ROTULOS_FILTRO = {
  tipo:   { nome: 'Tipo',   campo: 'doc-f-tipo' },
  status: { nome: 'Status', campo: 'doc-f-status' },
  agente: { nome: 'Agente', campo: 'doc-f-agente' },
}

function abrirFiltrosDoc() {
  // A janela abre no estado que está valendo, e não em branco: filtro que
  // esquece o que estava aplicado faz a pessoa reconstruir tudo a cada ajuste.
  for (const [chave, f] of Object.entries(ROTULOS_FILTRO)) {
    const el = document.getElementById(f.campo)
    if (el) { el.value = dState.filtros[chave] ?? '' }
  }
  openModal('m-doc-filtros')
}

function aplicarFiltrosDoc() {
  for (const [chave, f] of Object.entries(ROTULOS_FILTRO)) {
    dState.filtros[chave] = document.getElementById(f.campo)?.value ?? ''
  }
  fModalBtn('m-doc-filtros')
  carregarDocumentos()
}

function limparFiltrosDoc() {
  for (const chave of Object.keys(ROTULOS_FILTRO)) { dState.filtros[chave] = '' }
  // "Meus documentos" é o padrão da tela, não a ausência de filtro: limpar
  // para "todos" mudaria o que o fiscal vê ao abrir, que não é o pedido.
  dState.filtros.agente = 'eu'

  // A BUSCA TAMBÉM. Ela não está em `ROTULOS_FILTRO` — não vira etiqueta,
  // porque o texto já está à vista no próprio campo —, e por isso escapava
  // do laço acima: "Limpar" devolvia os seletores ao padrão e deixava o termo
  // digitado filtrando a lista, sem nada na tela explicando por quê.
  dState.filtros.busca = ''
  const campo = document.getElementById('doc-busca')
  if (campo) { campo.value = '' }

  fModalBtn('m-doc-filtros')
  carregarDocumentos()
}

/** @param {string} chave */
function removerFiltroDoc(chave) {
  dState.filtros[chave] = chave === 'agente' ? 'eu' : ''
  carregarDocumentos()
}

/**
 * As etiquetas do que está filtrando.
 *
 * Existem porque o filtro saiu da vista: sem elas, a lista pode parecer vazia
 * sem que ninguém lembre que há um status marcado desde ontem. Cada uma sai
 * com o ✕ que a desfaz, no lugar onde a pergunta aparece.
 */
function pintarChipsDoc() {
  espelharFiltrosDoc()

  const alvo = document.getElementById('doc-chips')
  const cont = document.getElementById('doc-filtro-n')
  if (!alvo) { return }

  const ativos = Object.entries(ROTULOS_FILTRO)
    .map(([chave, f]) => {
      const valor = dState.filtros[chave]
      // O padrão da tela não é filtro: "Meus documentos" e "todos os tipos"
      // não merecem etiqueta, senão a faixa nasce cheia e para de informar.
      if (!valor || (chave === 'agente' && valor === 'eu')) { return null }

      const sel = document.getElementById(f.campo)
      const texto = [...(sel?.options ?? [])].find(o => o.value === valor)?.text ?? valor
      return { chave, rotulo: f.nome, texto }
    })
    .filter(Boolean)

  if (cont) {
    cont.hidden = ativos.length === 0
    cont.textContent = ativos.length
  }

  alvo.innerHTML = ativos.length
    ? ativos.map(a => `
        <button type="button" class="chip-filtro" onclick="removerFiltroDoc('${a.chave}')">
          <span class="chip-rot">${esc(a.rotulo)}</span>${esc(a.texto)}
          <span class="chip-x">&#10005;</span>
        </button>`).join('')
      + (ativos.length > 1
          ? `<button type="button" class="chip-limpar" onclick="limparFiltrosDoc()">Limpar tudo</button>`
          : '')
    : ''
}

/**
 * OS MESMOS FILTROS, À VISTA NO COMPUTADOR.
 *
 * Os seletores são CLONADOS dos da janela, e não escritos uma segunda vez no
 * Blade: as opções de tipo vêm de `Documento::TIPOS`, e duas listas escritas à
 * mão divergem no dia em que alguém acrescentar um tipo e lembrar de um lugar
 * só. O que vale continua sendo `dState.filtros` — os dois conjuntos de
 * seletores são vistas dele.
 */
function montarFiltroLargoDoc() {
  const faixa = document.getElementById('doc-filtro-larga')
  if (!faixa || faixa.dataset.pronta) { return }

  for (const chave of Object.keys(ROTULOS_FILTRO)) {
    const origem = document.getElementById(ROTULOS_FILTRO[chave].campo)
    if (!origem) { continue }

    const copia = origem.cloneNode(true)
    copia.id = 'doc-w-' + chave
    copia.onchange = () => filtrarDocumentos(chave, copia.value)
    const campo = document.createElement('div')
    campo.className = 'lista-campo'
    const label = document.createElement('label')
    label.htmlFor = copia.id
    label.textContent = ROTULOS_FILTRO[chave].nome
    campo.append(label, copia)
    faixa.appendChild(campo)
  }
  faixa.dataset.pronta = '1'
}

/** Põe os seletores largos no estado que está valendo. */
function espelharFiltrosDoc() {
  montarFiltroLargoDoc()
  for (const chave of Object.keys(ROTULOS_FILTRO)) {
    const el = document.getElementById('doc-w-' + chave)
    if (el) { el.value = dState.filtros[chave] ?? '' }
  }
}

/**
 * ANOTA O FILTRO, MAS NÃO BUSCA.
 *
 * A lista respondia enquanto se digitava. Parecia agilidade e era o contrário:
 * cada tecla virava uma consulta ao banco, a lista pulava embaixo da mão de
 * quem ainda estava escrevendo, e não havia como saber se o que estava na tela
 * já era o resultado final. Agora quem decide a hora é o botão Buscar.
 *
 * A exceção continua sendo o combobox que pesquisa DENTRO do próprio campo
 * (logradouro, artigo): ali a lista É a escrita, e esperar um
 * botão seria pior.
 *
 * @param {string} campo @param {string} valor
 */
function filtrarDocumentos(campo, valor) {
  dState.filtros[campo] = valor
  marcarBuscaPendente('doc-buscar')
}

// ── APOIO AO FORMULÁRIO ──────────────────────────────────────
// A montagem, o estado e a gravação do formulário vivem em documento-form.js.
// O que fica aqui é o que a LISTA também usa (opções, sugestão de artigos) e
// os campos cujo comportamento é do formulário mas cuja lógica é de negócio.

/** Carrega tipos e leis uma vez por sessão. */
async function carregarOpcoes() {
  if (dState.opcoes) return dState.opcoes
  const r = await fetch('/api/documentos/opcoes', { headers: { Accept: 'application/json' } })
  dState.opcoes = await r.json()
  return dState.opcoes
}

// ── DATA E HORA DO FATO ──────────────────────────────────────
//
// Dois campos de TEXTO com máscara (dd/mm/aaaa e hh:mm), no lugar dos
// seletores nativos do navegador. Os valores que o resto do sistema usa ficam
// nos campos escondidos: nd-data (aaaa-mm-dd), nd-hora (hh:mm) e nd-datahora.

/** Dos campos escondidos para o que se vê, e para o aaaa-mm-ddThh:mm. */
function syncDataDoc() {
  const d = document.getElementById('nd-data').value
  const h = document.getElementById('nd-hora').value || '00:00'
  document.getElementById('nd-datahora').value = d ? `${d}T${h}` : ''
  document.getElementById('nd-data-txt').value = d ? d.split('-').reverse().join('/') : ''
  document.getElementById('nd-hora-txt').value = document.getElementById('nd-hora').value || ''
}

/** Põe as barras enquanto se digita: 03102026 → 03/10/2026. */
function mascararDataDoc(inp) {
  const n = inp.value.replace(/\D/g, '').slice(0, 8)
  inp.value = [n.slice(0, 2), n.slice(2, 4), n.slice(4)].filter(Boolean).join('/')
}

/** Põe os dois-pontos enquanto se digita: 1257 → 12:57. */
function mascararHoraDoc(inp) {
  const n = inp.value.replace(/\D/g, '').slice(0, 4)
  inp.value = n.length > 2 ? n.slice(0, 2) + ':' + n.slice(2) : n
}

/**
 * Do que foi digitado para os campos escondidos. Data ou hora que não existe
 * (31/02, 25:00) é recusada na saída do campo, com o motivo — e o valor
 * anterior continua valendo.
 */
function lerDataHoraDoc() {
  const dt = document.getElementById('nd-data-txt').value.trim()
  const hr = document.getElementById('nd-hora-txt').value.trim()

  if (dt) {
    const m = dt.match(/^(\d{2})\/(\d{2})\/(\d{4})$/)
    const data = m ? new Date(+m[3], +m[2] - 1, +m[1]) : null
    const valida = data && data.getDate() === +m[1] && data.getMonth() === +m[2] - 1 && +m[3] >= 1990
    if (!valida) { exigirCampo('nd-data-txt', 'Data inválida. Use dd/mm/aaaa.'); return }
    document.getElementById('nd-data').value = `${m[3]}-${m[2]}-${m[1]}`
  } else {
    document.getElementById('nd-data').value = ''
  }

  if (hr) {
    const m = hr.match(/^(\d{1,2}):?(\d{2})$/)
    if (!m || +m[1] > 23 || +m[2] > 59) { exigirCampo('nd-hora-txt', 'Hora inválida. Use hh:mm.'); return }
    document.getElementById('nd-hora').value = m[1].padStart(2, '0') + ':' + m[2]
  } else {
    document.getElementById('nd-hora').value = ''
  }
  syncDataDoc()
}

// ── CALENDÁRIO E RELÓGIO ─────────────────────────────────────
//
// Dois balões próprios, abertos pelo botão dentro do campo. Não substituem a
// digitação — quem sabe a data escreve mais rápido —, só dão a outra porta.
// São iguais em todo aparelho, ao contrário dos seletores do navegador. Um
// balão por vez; fecha ao escolher, no Esc e no clique fora.

let _dhBalao = null

function fecharBalaoDataHora() {
  _dhBalao?.remove()
  _dhBalao = null
  document.removeEventListener('mousedown', _dhCliqueFora, true)
  document.removeEventListener('keydown', _dhTecla, true)
}
function _dhCliqueFora(ev) { if (_dhBalao && !_dhBalao.contains(ev.target) && !ev.target.closest('.dh-btn')) fecharBalaoDataHora() }
function _dhTecla(ev) { if (ev.key === 'Escape') { ev.stopPropagation(); fecharBalaoDataHora() } }

/** Cria o balão dentro do campo do botão. @param {HTMLElement} botao @param {string} classe */
function _dhAbrir(botao, classe) {
  const jaAberto = _dhBalao?.classList.contains(classe)
  fecharBalaoDataHora()
  if (jaAberto) return null          // o mesmo botão de novo fecha
  _dhBalao = document.createElement('div')
  _dhBalao.className = 'dh-balao ' + classe
  botao.closest('.dh-campo').appendChild(_dhBalao)
  document.addEventListener('mousedown', _dhCliqueFora, true)
  document.addEventListener('keydown', _dhTecla, true)
  return _dhBalao
}

const DH_MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']

/** Calendário: um mês por vez, com o dia escolhido e o de hoje marcados. */
function abrirCalendarioDoc(botao) {
  if (docTravado()) return
  lerDataHoraDoc()
  const balao = _dhAbrir(botao, 'dh-cal')
  if (!balao) return
  const iso = document.getElementById('nd-data').value
  const escolhida = iso ? new Date(iso + 'T12:00') : new Date()
  let ano = escolhida.getFullYear(), mes = escolhida.getMonth()
  const p2 = n => String(n).padStart(2, '0')
  const hoje = new Date()

  const pintar = () => {
    const primeiro = new Date(ano, mes, 1).getDay()          // 0 = domingo
    const dias = new Date(ano, mes + 1, 0).getDate()
    const celulas = Array(primeiro).fill('<span></span>')
    for (let d = 1; d <= dias; d++) {
      const marca = (iso === `${ano}-${p2(mes + 1)}-${p2(d)}` ? ' sel' : '')
        + (hoje.getFullYear() === ano && hoje.getMonth() === mes && hoje.getDate() === d ? ' hoje' : '')
      celulas.push(`<button type="button" class="dh-dia${marca}" data-dia="${d}">${d}</button>`)
    }
    balao.innerHTML = `
      <div class="dh-topo">
        <button type="button" class="dh-nav" data-passo="-1" aria-label="Mês anterior">&lsaquo;</button>
        <b>${DH_MESES[mes][0].toUpperCase() + DH_MESES[mes].slice(1)} de ${ano}</b>
        <button type="button" class="dh-nav" data-passo="1" aria-label="Próximo mês">&rsaquo;</button>
      </div>
      <div class="dh-grade dh-semana">${['D', 'S', 'T', 'Q', 'Q', 'S', 'S'].map(s => `<span>${s}</span>`).join('')}</div>
      <div class="dh-grade">${celulas.join('')}</div>
      <div class="dh-pe"><button type="button" class="dh-atalho" data-hoje="1">Hoje</button></div>`
  }
  const escolher = (a, m, d) => {
    document.getElementById('nd-data-txt').value = `${p2(d)}/${p2(m + 1)}/${a}`
    fecharBalaoDataHora()
    lerDataHoraDoc()
  }
  // `mousedown` com preventDefault: o campo de texto não perde o foco, e o
  // `onblur` dele não roda no meio do clique.
  balao.addEventListener('mousedown', ev => ev.preventDefault())
  balao.addEventListener('click', ev => {
    const alvo = ev.target.closest('button')
    if (!alvo) return
    if (alvo.dataset.passo) {
      mes += Number(alvo.dataset.passo)
      if (mes < 0) { mes = 11; ano-- } else if (mes > 11) { mes = 0; ano++ }
      pintar()
    } else if (alvo.dataset.hoje) {
      escolher(hoje.getFullYear(), hoje.getMonth(), hoje.getDate())
    } else if (alvo.dataset.dia) {
      escolher(ano, mes, Number(alvo.dataset.dia))
    }
  })
  pintar()
}

/** Relógio: horas de um lado, minutos (de 5 em 5) do outro. */
function abrirRelogioDoc(botao) {
  if (docTravado()) return
  lerDataHoraDoc()
  const balao = _dhAbrir(botao, 'dh-rel')
  if (!balao) return
  const p2 = n => String(n).padStart(2, '0')
  let [h, m] = (document.getElementById('nd-hora').value || '').split(':').map(Number)
  if (!Number.isFinite(h)) { const a = new Date(); h = a.getHours(); m = a.getMinutes() }

  const gravar = fechar => {
    document.getElementById('nd-hora-txt').value = `${p2(h)}:${p2(m)}`
    lerDataHoraDoc()
    if (fechar) fecharBalaoDataHora(); else pintar()
  }
  const pintar = () => {
    const col = (lista, atual, chave) => lista.map(v =>
      `<button type="button" class="dh-hm${v === atual ? ' sel' : ''}" data-${chave}="${v}">${p2(v)}</button>`).join('')
    balao.innerHTML = `
      <div class="dh-topo"><b>${p2(h)}:${p2(m)}</b></div>
      <div class="dh-colunas">
        <div><small>Hora</small><div class="dh-lista">${col([...Array(24).keys()], h, 'h')}</div></div>
        <div><small>Minuto</small><div class="dh-lista">${col([...Array(12).keys()].map(i => i * 5), m, 'm')}</div></div>
      </div>
      <div class="dh-pe"><button type="button" class="dh-atalho" data-agora="1">Agora</button>
        <button type="button" class="dh-atalho" data-ok="1">Pronto</button></div>`
    balao.querySelector('.dh-hm.sel')?.scrollIntoView({ block: 'center' })
  }
  balao.addEventListener('mousedown', ev => ev.preventDefault())
  balao.addEventListener('click', ev => {
    const alvo = ev.target.closest('button')
    if (!alvo) return
    if (alvo.dataset.agora) { const a = new Date(); h = a.getHours(); m = a.getMinutes(); gravar(true) }
    else if (alvo.dataset.ok) { gravar(true) }
    else if (alvo.dataset.h !== undefined) { h = Number(alvo.dataset.h); gravar(false) }
    // Escolhido o minuto, a hora está completa: fecha.
    else if (alvo.dataset.m !== undefined) { m = Number(alvo.dataset.m); gravar(true) }
  })
  pintar()
}

// ── CPF / CNPJ DO AUTUADO ────────────────────────────────────

/** Máscara conforme o tamanho: até 11 dígitos é CPF, daí em diante CNPJ. */
function mascararCpfCnpjDoc(inp) {
  _cnpjConsultadoDoc = ''   // mexeu no documento: a próxima saída do campo consulta de novo
  const n = inp.value.replace(/\D/g, '').slice(0, 14)
  inp.value = n.length <= 11
    ? n.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2')
    : n.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
       .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2')
}

/** O último CNPJ consultado — sair e voltar ao campo, sem mexer nele, não repete a consulta. */
let _cnpjConsultadoDoc = ''

/** Os campos que a consulta de CNPJ preenche: id → [rótulo, chave da resposta]. */
const CAMPOS_DO_CNPJ_DOC = {
  'nd-autuado': ['Nome', 'nome'], 'nd-aut-logradouro': ['Logradouro', 'logradouro'],
  'nd-aut-numero': ['Número', 'numero'], 'nd-aut-bairro': ['Bairro', 'bairro'],
  'nd-aut-cidade': ['Cidade', 'cidade'], 'nd-aut-uf': ['UF', 'uf'],
}

/**
 * Saindo do campo com um CNPJ completo, consulta a empresa e preenche a razão
 * social e o endereço — SUBSTITUINDO o que estava lá: o dado da Receita é o
 * atual, e o que estava no campo pode ser o de outra empresa ou de anos atrás.
 *
 * Como substituir em silêncio apagaria o que o fiscal digitou sem ele ver, o
 * que foi TROCADO é avisado: o campo fica marcado até ser editado, e o aviso
 * diz quais foram e o que havia antes. Campo que a consulta não trouxe fica
 * como estava.
 *
 * É o recurso do AppPOSTURAS; aqui a consulta passa pelo servidor
 * (CnpjController), que é quem fala com a BrasilAPI.
 */
async function buscarCnpjDoc(inp) {
  if (docTravado()) return
  const digitos = inp.value.replace(/\D/g, '')
  if (digitos.length !== 14 || digitos === _cnpjConsultadoDoc) return
  _cnpjConsultadoDoc = digitos
  try {
    const r = await fetch('/api/cnpj/' + digitos, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) { toast(d.message || 'Não foi possível consultar o CNPJ.', 'err'); return }

    const trocados = []
    for (const [id, [rotulo, chave]] of Object.entries(CAMPOS_DO_CNPJ_DOC)) {
      const el = document.getElementById(id)
      const novo = String(d[chave] ?? '').trim()
      const antes = el.value.trim()
      el.classList.remove('campo-trocado')
      if (!novo || novo.toLowerCase() === antes.toLowerCase()) continue
      el.value = novo
      if (antes) {
        trocados.push(`${rotulo} (era "${antes}")`)
        // Marcado até o fiscal mexer no campo — a marca é o "confira aqui".
        el.classList.add('campo-trocado')
        el.addEventListener('input', () => el.classList.remove('campo-trocado'), { once: true })
      }
    }
    if (trocados.length) {
      toast(`CNPJ consultado. ${trocados.length} campo(s) já preenchido(s) foram substituídos: ${trocados.join('; ')}.`, 'aviso', { duracao: 9000 })
    } else {
      toast('Dados do CNPJ preenchidos')
    }
  } catch {
    toast('Não foi possível consultar o CNPJ. Preencha manualmente.', 'err')
  }
}

/**
 * Ajusta o formulário ao tipo escolhido.
 *
 * Vistoria não impõe sanção: some a fundamentação e o prazo. Auto tem prazo de
 * DEFESA, fixo pela lei e não digitável. Notificação tem prazo de CUMPRIMENTO,
 * esse sim por documento.
 */
function trocarTipoDoc() {
  const tipo = document.getElementById('nd-tipo').value
  const t = dState.opcoes.tipos.find(x => x.valor === tipo)
  if (!t) return

  document.getElementById('bloco-fundamentacao').style.display = t.exige_artigos ? '' : 'none'
  document.getElementById('bloco-prazo').style.display = t.prazo === 'cumprimento' ? '' : 'none'

  const aviso = document.getElementById('nd-aviso-prazo')
  if (t.prazo === 'defesa') {
    const lei = dState.opcoes.leis.find(l => String(l.id) === document.getElementById('nd-lei').value)
    aviso.style.display = ''
    aviso.textContent = lei
      ? `Prazo de defesa: ${lei.prazo_defesa_dias} dias úteis, contados da lavratura — definido pela lei, não editável.`
      : 'O prazo de defesa vem da lei selecionada e é contado em dias úteis.'
  } else {
    aviso.style.display = 'none'
  }

  // O rótulo do cabeçalho acompanha o tipo escolhido.
  const sel = document.getElementById('nd-tipo')
  const rot = document.getElementById('fd-tipo-rotulo')
  if (rot) rot.textContent = sel.options[sel.selectedIndex]?.textContent || 'Documento'
}

// ── LEI E ARTIGOS (padrão do AppPOSTURAS) ────────────────────
//
// A lei é um campo pesquisável, que TRAVA enquanto houver artigo na lista: um
// documento cita artigos de uma lei só. O artigo é procurado por número,
// apelido, texto ou termo de busca; escolhido, entra na lista pelo "+add". Os
// artigos da lista aparecem em quadros cinzas, cada um com o seu X.
// `fdState.artigos` guarda os ids, na ordem em que entraram; a lei fica no
// campo escondido #nd-lei, que é de onde o resto do formulário a lê.

/** O artigo escolhido na busca, à espera do "+add". */
let artigoEscolhidoDoc = null

const leiDoDoc = () => dState.opcoes?.leis.find(l => String(l.id) === document.getElementById('nd-lei').value) || null
const leiDoArtigoDoc = id => dState.opcoes?.leis.find(l => l.artigos.some(a => a.id === id)) || null
const artigoDoc = id => leiDoArtigoDoc(id)?.artigos.find(a => a.id === id) || null
const docTravado = () => ['gravado', 'lavrado'].includes(fdState.estado) && !fdState.editando
const semAcentoDoc = t => String(t ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()

/** "Art. 12 - Obra sem alvará": número e apelido, sem repetir quando são iguais. */
function rotuloArtigoDoc(a) {
  if (!a) return ''
  return a.rotulo && a.rotulo !== a.numero ? `${a.numero} - ${a.rotulo}` : a.numero
}

function fecharAcDoc(id) { setTimeout(() => document.getElementById(id)?.classList.remove('open'), 150) }

/** Lista as leis que casam com o que foi digitado. */
function buscarLeiDoc(inp) {
  const lista = document.getElementById('ac-nd-lei')
  if (docTravado() || fdState.artigos.length) { lista.classList.remove('open'); return }
  const q = semAcentoDoc(inp.value.trim())
  const leis = dState.opcoes.leis.filter(l => !q || semAcentoDoc(l.rotulo).includes(q))
  lista.innerHTML = leis.length
    ? leis.map(l => `<div class="ac-item" onmousedown="event.preventDefault(); selLeiDoc(${l.id})">${esc(l.rotulo)}</div>`).join('')
    : '<div class="ac-empty">Nenhuma lei encontrada</div>'
  lista.classList.add('open')
}

/** @param {number} id */
function selLeiDoc(id) {
  if (docTravado()) return
  document.getElementById('nd-lei').value = id
  document.getElementById('ac-nd-lei').classList.remove('open')
  trocarLeiDoc()
}

function limparLeiDoc() {
  if (docTravado()) return
  if (fdState.artigos.length) { toast('Remova os artigos da lista para trocar a lei', 'err'); return }
  document.getElementById('nd-lei').value = ''
  trocarLeiDoc()
  document.getElementById('nd-lei-busca').focus()
}

/** Lista os artigos que casam: da lei escolhida, ou de todas se não há lei. */
function buscarArtigoDoc(inp) {
  const lista = document.getElementById('ac-nd-artigo')
  if (docTravado()) { lista.classList.remove('open'); return }
  artigoEscolhidoDoc = null
  const lei = leiDoDoc()
  const q = semAcentoDoc(inp.value.trim())
  // SÓ OS ARTIGOS MARCADOS PARA ESTA PEÇA (Parâmetros › Legislação › Artigos).
  const daPeca = (lei ? [lei] : dState.opcoes.leis)
    .flatMap(l => l.artigos.map(a => ({ a, lei: lei ? '' : l.rotulo })))
    .filter(({ a }) => artigoServeAoDoc(a))
  const pool = daPeca
    .filter(({ a }) => !fdState.artigos.includes(a.id))
    .filter(({ a }) => !q || semAcentoDoc([a.numero, a.rotulo, a.conduta, ...(a.termos || [])].join(' ')).includes(q))

  // Lista vazia tem de dizer POR QUÊ: "nenhum artigo encontrado" numa peça
  // sem artigo marcado para ela pareceria defeito da busca.
  const vazio = lei && !lei.artigos.length ? 'Esta lei ainda não tem artigos cadastrados (Parâmetros › Legislação).'
    : !daPeca.length ? 'Nenhum artigo está marcado para este documento. Marque em Parâmetros › Legislação › Artigos.'
    : 'Nenhum artigo encontrado'

  lista.innerHTML = pool.length
    ? pool.slice(0, 60).map(({ a, lei }) => `<div class="ac-item" onmousedown="event.preventDefault(); selArtigoDoc(${a.id})">
        ${esc(rotuloArtigoDoc(a))}${
          lei ? ` <span class="ac-sub">· ${esc(lei)}</span>` : ''}</div>`).join('')
    : `<div class="ac-empty">${vazio}</div>`
  lista.classList.add('open')
}

/**
 * Este artigo pode fundamentar a peça aberta? MESMA regra de Artigo::serveA,
 * no servidor — repetida aqui só para a lista não oferecer o que o servidor
 * vai recusar ao gravar. Artigo sem peça marcada serve a todas.
 */
function artigoServeAoDoc(a) {
  return !a.documentos?.length || a.documentos.includes(document.getElementById('nd-tipo').value)
}

/**
 * Escolhe o artigo na busca; ele só entra na lista com o "+add".
 *
 * Sem lei escolhida, o artigo JÁ ESCOLHE A LEI dele no campo de cima — como no
 * AppPOSTURAS. Quem procura pelo artigo ("escavação", "sem alvará") não tem de
 * saber de antemão em que lei ele está; e ver a lei aparecer é a confirmação
 * de que achou o artigo certo, antes de somá-lo à lista.
 */
function selArtigoDoc(id) {
  if (docTravado()) return
  artigoEscolhidoDoc = id
  document.getElementById('nd-artigo-busca').value = rotuloArtigoDoc(artigoDoc(id))
  document.getElementById('ac-nd-artigo').classList.remove('open')

  const lei = leiDoArtigoDoc(id)
  if (lei && !document.getElementById('nd-lei').value) {
    document.getElementById('nd-lei').value = lei.id
    document.getElementById('nd-lei-busca').value = lei.rotulo
    trocarTipoDoc()   // o aviso do prazo de defesa depende da lei
  }
}

function limparArtigoBuscaDoc() {
  artigoEscolhidoDoc = null
  const inp = document.getElementById('nd-artigo-busca')
  inp.value = ''
  if (!docTravado()) inp.focus()
}

function addArtigoDoc() {
  if (docTravado()) return
  const id = artigoEscolhidoDoc
  if (!id) { exigirCampo('nd-artigo-busca', 'Escolha um artigo na lista de sugestões.'); return }
  const lei = leiDoArtigoDoc(id)
  if (!lei) return
  const atual = document.getElementById('nd-lei').value
  if (atual && String(lei.id) !== atual) { toast('Só é possível adicionar artigos da mesma lei', 'err'); return }
  // Escolher o artigo antes da lei já define a lei.
  if (!atual) document.getElementById('nd-lei').value = lei.id
  if (!fdState.artigos.includes(id)) fdState.artigos.push(id)
  limparArtigoBuscaDoc()
  trocarLeiDoc()
  sugerirPrazoDoEmbargo()
}

/**
 * NAS NOTIFICAÇÕES, o prazo de cumprimento vem sugerido pelo artigo que tem
 * prazo próprio (o menor, se houver mais de um) — os 5 dias do art. 22, por
 * exemplo. O campo continua livre: é sugestão, e o fiscal responde pelo
 * prazo que der.
 */
function sugerirPrazoDoEmbargo() {
  if (!['notificacao', 'notificacao_embargo'].includes(document.getElementById('nd-tipo').value)) return
  const prazos = fdState.artigos.map(artigoDoc)
    .filter(a => a?.prazo_notificacao_dias).map(a => Number(a.prazo_notificacao_dias))
  if (prazos.length) document.getElementById('nd-prazo').value = Math.min(...prazos)
}

/** @param {number} id */
function removerArtigoDoc(id) {
  if (docTravado()) return
  fdState.artigos = fdState.artigos.filter(a => a !== id)
  trocarLeiDoc()
}

/**
 * Redesenha a lei, a trava e os quadros dos artigos a partir do estado
 * (#nd-lei e fdState.artigos). É o ponto único de atualização: quem muda a
 * lei ou a lista — a busca, o "+add", o X, a sugestão da vistoria — chama isto.
 */
function trocarLeiDoc() {
  const lei = leiDoDoc()
  const travado = docTravado()
  const busca = document.getElementById('nd-lei-busca')
  busca.value = lei ? lei.rotulo : ''
  // Com artigo na lista, a lei não se troca: o campo vira leitura.
  busca.readOnly = fdState.artigos.length > 0
  document.getElementById('nd-lei-trava').hidden = !fdState.artigos.length || travado

  document.getElementById('nd-artigos').innerHTML = fdState.artigos.length
    ? fdState.artigos.map(id => {
        const a = artigoDoc(id)
        if (!a) return ''
        return `<div class="artigo-tag">
          <div class="artigo-tag-topo">
            <span><strong>${esc(rotuloArtigoDoc(a))}</strong> · ${esc(leiDoArtigoDoc(a.id)?.rotulo || '')}</span>
            ${travado ? '' : `<button type="button" class="artigo-tag-x" title="Remover" onclick="removerArtigoDoc(${a.id})">&times;</button>`}
          </div>
          ${a.conduta ? `<div class="artigo-tag-texto">${esc(a.conduta)}</div>` : ''}
        </div>`
      }).join('')
    : '<div class="ac-dica">Nenhum artigo adicionado.</div>'

  trocarTipoDoc()
  recalcularMultaDoc()
}

/**
 * PRÉVIA DA MULTA, artigo por artigo.
 *
 * A conta NÃO é feita aqui: a tela pede a /api/multas/simular, que usa o
 * mesmo Artigo::calcularMulta da lavratura. Com faixa de área, "obra ou
 * terreno" e múltiplo do alvará, repetir a regra em JavaScript era pedir
 * para a prévia mostrar um valor e o auto sair com outro.
 *
 * Esta função só decide QUAIS campos aparecem (áreas, alvará,
 * multiplicador) e agenda o pedido.
 */
function recalcularMultaDoc() {
  const artigos = fdState.artigos.map(artigoDoc).filter(Boolean)
  const porArea = artigos.some(a => a.base_multa === 'por_m2' || a.base_multa === 'faixas')
  const doAlvara = artigos.filter(a => a.base_multa === 'multiplo_alvara')
  // O que o FISCAL informa por artigo: o multiplicador do alvará que tem
  // intervalo (1 a 10×; o fixo, 3×, já está dado) e o valor da multa "entre
  // mínimo e máximo", que é fixado conforme a gravidade.
  const informados = artigos.filter(a => a.base_multa === 'intervalo'
    || (a.base_multa === 'multiplo_alvara' && Number(a.multa_mult_min) !== Number(a.multa_mult_max)))
  const ehAuto = document.getElementById('nd-tipo').value === 'auto_infracao'

  document.getElementById('nd-bloco-area').style.display = porArea ? '' : 'none'
  document.getElementById('nd-bloco-alvara').style.display = doAlvara.length ? '' : 'none'
  document.getElementById('nd-bloco-informados').style.display = informados.length ? '' : 'none'
  document.getElementById('nd-bloco-reincidencia').style.display = ehAuto ? '' : 'none'

  // Redesenha os campos só quando o CONJUNTO de artigos muda — redesenhar a
  // cada tecla tiraria o foco de quem está digitando.
  const caixa = document.getElementById('nd-multiplicadores')
  const chave = informados.map(a => a.id).join(',')
  if (caixa.dataset.chave !== chave) {
    caixa.dataset.chave = chave
    caixa.innerHTML = informados.map(a => {
      const [min, max, rotulo] = a.base_multa === 'intervalo'
        ? [a.multa_min_upf, a.multa_max_upf, `Multa em UPF — ${esc(a.numero)} (${fmtNum(a.multa_min_upf)} a ${fmtNum(a.multa_max_upf)})`]
        : [a.multa_mult_min, a.multa_mult_max, `Multiplicador — ${esc(a.numero)} (${fmtNum(a.multa_mult_min)} a ${fmtNum(a.multa_mult_max)}×)`]
      return `<div class="field">
        <label for="nd-mult-${a.id}">${rotulo}</label>
        <input id="nd-mult-${a.id}" type="number" min="${min}" max="${max}" step="0.01" data-lock
               value="${esc(String(fdState.multiplicadores[a.id] ?? ''))}" ${docTravado() ? 'disabled' : ''}
               oninput="fdState.multiplicadores[${a.id}] = this.value === '' ? null : Number(this.value); recalcularMultaDoc()">
      </div>`
    }).join('')
  }

  if (!artigos.some(a => a.base_multa !== 'sem_multa')) {
    fdState.multa = null
    mostrarMultaDoc()
    return
  }
  // Peça só aberta para leitura: mostra o que ela GUARDOU, sem refazer a
  // conta com a lei e a UPF de hoje.
  if (docTravado() && fdState.multaGravada) {
    fdState.multa = fdState.multaGravada
    mostrarMultaDoc()
    return
  }
  clearTimeout(recalcularMultaDoc.espera)
  recalcularMultaDoc.espera = setTimeout(simularMultaDoc, 250)
}

/** Os multiplicadores só dos artigos que estão na peça. */
function multiplicadoresDoDoc() {
  return Object.fromEntries(fdState.artigos
    .filter(id => fdState.multiplicadores[id] !== null && fdState.multiplicadores[id] !== undefined)
    .map(id => [id, fdState.multiplicadores[id]]))
}

// ── ORIGEM DA NOTIFICAÇÃO ────────────────────────────────────

/** As peças que têm MOTIVO de origem, e não peça de origem (Documento::COM_CUMPRIMENTO). */
const NOTIFICACOES_DOC = ['notificacao', 'notificacao_embargo']

/**
 * Mostra o bloco de origem da notificação, os campos do motivo escolhido
 * (qual ordem de serviço / qual denúncia) e decide se dá para editar:
 *
 *   peça nova ou rascunho em edição   edita, e vai junto no Gravar;
 *   rascunho só aberto                 travado, como o resto;
 *   LAVRADA                            EDITA, com o botão "Salvar origem" —
 *                                      é o único dado que se corrige depois.
 */
function aplicarOrigemNotifDoc() {
  const g = id => document.getElementById(id)
  const ehNotificacao = NOTIFICACOES_DOC.includes(g('nd-tipo').value)
  g('nd-bloco-motivo').style.display = ehNotificacao ? '' : 'none'
  if (!ehNotificacao) return

  const motivo = g('nd-origem-motivo').value
  g('nd-origem-os-campo').hidden = motivo !== 'ordem_servico'
  g('nd-origem-ref-campo').hidden = motivo !== 'ouvidoria'
  if (motivo === 'ordem_servico') carregarOrdensDoc()

  const lavrado = fdState.estado === 'lavrado'
  const edita = lavrado ? !!fdState.podeEditarOrigem : !docTravado()
  for (const id of ['nd-origem-motivo', 'nd-origem-os', 'nd-origem-ref']) g(id).disabled = !edita
  g('nd-origem-salvar-linha').hidden = !(lavrado && fdState.podeEditarOrigem)
}

/** As ordens de serviço, para a origem. Carregadas uma vez por abertura do formulário. */
async function carregarOrdensDoc() {
  const sel = document.getElementById('nd-origem-os')
  if (sel.dataset.carregado) return
  sel.dataset.carregado = '1'
  const atual = sel.value || String(fdState.origemOsId || '')
  let ordens = []
  try {
    const r = await fetch('/api/documentos/ordens-de-servico', { headers: { Accept: 'application/json' } })
    if (r.ok) ordens = (await r.json()).ordens
  } catch (_) { sel.dataset.carregado = '' }
  // A peça reaberta mostra a ordem que tem, mesmo que não venha na lista.
  if (atual && fdState.origemOsRotulo && !ordens.some(o => String(o.id) === atual)) {
    ordens.unshift({ id: Number(atual), rotulo: fdState.origemOsRotulo })
  }
  sel.innerHTML = '<option value="">Escolha a ordem de serviço…</option>'
    + ordens.map(o => `<option value="${o.id}">${esc(o.rotulo)}</option>`).join('')
  sel.value = ordens.some(o => String(o.id) === atual) ? atual : ''
}

/** O motivo de origem como vai no pedido — vazio para as peças que não o têm. */
function motivoDeOrigemDoDoc() {
  const g = id => document.getElementById(id)
  if (!NOTIFICACOES_DOC.includes(g('nd-tipo').value)) return {}
  const motivo = g('nd-origem-motivo').value || 'direta'
  return {
    origem_motivo: motivo,
    origem_os_id: motivo === 'ordem_servico' ? Number(g('nd-origem-os').value) || null : null,
    origem_referencia: motivo === 'ouvidoria' ? g('nd-origem-ref').value.trim() || null : null,
  }
}

/** Grava a origem de uma notificação já lavrada (o único dado que ela ainda aceita). */
async function salvarOrigemNotifDoc() {
  const corpo = motivoDeOrigemDoDoc()
  if (corpo.origem_motivo === 'ordem_servico' && !corpo.origem_os_id) { exigirCampo('nd-origem-os', 'Escolha a ordem de serviço.'); return }
  if (corpo.origem_motivo === 'ouvidoria' && !corpo.origem_referencia) { exigirCampo('nd-origem-ref', 'Informe o número da denúncia.'); return }
  await comCarregando('Gravando a origem…', async () => {
    try {
      const r = await fetch(`/api/documentos/${fdState.id}/origem`, {
        method: 'PATCH', headers: { ...cabecalhoDoc(), 'Content-Type': 'application/json' }, body: JSON.stringify(corpo),
      })
      const d = await r.json().catch(() => ({}))
      if (!r.ok) throw new Error(d.errors ? Object.values(d.errors)[0][0] : (d.message || 'HTTP ' + r.status))
      toast(d.message)
      // A via A4 do resumo traz a origem no topo: da próxima vez ela vem nova.
      if (fdState.aba === 'resumo') renderResumoDoc()
      carregarDocumentos()
    } catch (e) {
      toast(e.message || 'Falha ao gravar a origem', 'err')
    }
  })
}

/** De quais peças cada documento pode nascer (Documento::ORIGENS). */
const ORIGENS_DO_DOC = ['auto_infracao', 'auto_embargo']

/** A peça de origem escolhida — só nos autos, que são os que nascem de outra peça. */
function origemDoDoc() {
  if (!ORIGENS_DO_DOC.includes(document.getElementById('nd-tipo').value)) return null
  return Number(document.getElementById('nd-origem').value) || null
}

/**
 * DOCUMENTO DE ORIGEM: as peças lavradas do imóvel de que este auto pode
 * nascer — a notificação ou o embargo que veio antes. É a que o texto de
 * ciência cita pelo marcador {origem}.
 */
async function carregarOrigensDoc() {
  const sel = document.getElementById('nd-origem')
  const tipo = document.getElementById('nd-tipo').value
  document.getElementById('nd-bloco-origem').style.display = ORIGENS_DO_DOC.includes(tipo) ? '' : 'none'
  aplicarOrigemNotifDoc()   // o bloco irmão, das notificações
  if (!ORIGENS_DO_DOC.includes(tipo)) return

  const atual = sel.value || String(fdState.origemId || '')
  let origens = []
  if (fdState.lote?.id) {
    const p = new URLSearchParams({ tipo, lote_id: fdState.lote.id })
    if (fdState.id) p.set('exceto', fdState.id)
    try {
      const r = await fetch('/api/documentos/origens?' + p, { headers: { Accept: 'application/json' } })
      if (r.ok) origens = (await r.json()).origens
    } catch (_) { /* sem lista: fica só o "sem origem" */ }
  }
  // A peça reaberta mostra a origem que tem, mesmo fora da lista.
  if (atual && fdState.origemRotulo && !origens.some(o => String(o.id) === atual)) {
    origens.unshift({ id: Number(atual), rotulo: fdState.origemRotulo, data: '' })
  }
  sel.innerHTML = '<option value="">Direta — sem documento de origem</option>' + origens.map(o =>
    `<option value="${o.id}">${esc(o.rotulo)}${o.data ? ' · ' + esc(o.data) : ''}</option>`).join('')
  sel.value = origens.some(o => String(o.id) === atual) ? atual : ''
}

/** O auto anterior escolhido como base da reincidência (só em Auto de Infração). */
function reincidenciaDoDoc() {
  if (document.getElementById('nd-tipo').value !== 'auto_infracao') return null
  return Number(document.getElementById('nd-reincidencia').value) || null
}

/**
 * REINCIDÊNCIA: lista os Autos de Infração lavrados do mesmo imóvel (ou do
 * mesmo CPF/CNPJ) — é de um deles que o auto novo pode ser reincidência, e
 * a multa dos artigos marcados dobra a cada elo.
 */
async function carregarAutosAnterioresDoc() {
  const sel = document.getElementById('nd-reincidencia')
  const atual = sel.value || String(fdState.reincidenciaId || '')
  const p = new URLSearchParams()
  if (fdState.lote?.id) p.set('lote_id', fdState.lote.id)
  const cpf = document.getElementById('nd-autuado-doc').value.trim()
  if (cpf) p.set('documento', cpf)
  if (fdState.id) p.set('exceto', fdState.id)
  let autos = []
  try {
    const r = await fetch('/api/documentos/autos-anteriores?' + p, { headers: { Accept: 'application/json' } })
    if (r.ok) autos = (await r.json()).autos
  } catch (_) { /* sem lista: fica só o "não é reincidência" */ }
  // A peça reaberta mostra o vínculo que tem, mesmo que o auto não venha na lista.
  if (atual && fdState.reincidenciaNumero && !autos.some(a => String(a.id) === atual)) {
    autos.unshift({ id: Number(atual), numero: fdState.reincidenciaNumero, data: '', proximo_fator: fdState.reincidenciaFator || 2 })
  }
  sel.innerHTML = '<option value="">Não é reincidência</option>' + autos.map(a =>
    `<option value="${a.id}">${esc(a.numero)}${a.data ? ' · ' + esc(a.data) : ''} — multa × ${a.proximo_fator}</option>`).join('')
  sel.value = autos.some(a => String(a.id) === atual) ? atual : ''
}

/** Pede a conta ao servidor. Resposta atrasada de um pedido antigo é descartada. */
async function simularMultaDoc() {
  const pedido = simularMultaDoc.ultimo = (simularMultaDoc.ultimo || 0) + 1
  const num = id => { const v = document.getElementById(id).value; return v === '' ? null : Number(v) }
  try {
    const r = await fetch('/api/multas/simular', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json', Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        artigos: fdState.artigos,
        area_terreno_m2: num('nd-area-terreno'),
        area_construida_m2: num('nd-area-construida'),
        alvara_valor: num('nd-alvara-valor'),
        multiplicadores: multiplicadoresDoDoc(),
        reincidencia_de_id: reincidenciaDoDoc(),
        data_fato: document.getElementById('nd-datahora').value || null,
      }),
    })
    if (pedido !== simularMultaDoc.ultimo) return
    fdState.multa = r.ok ? await r.json() : null
  } catch (_) {
    if (pedido !== simularMultaDoc.ultimo) return
    fdState.multa = null
  }
  mostrarMultaDoc()
  // O resumo aberto mostra a mesma conta: atualiza junto.
  if (fdState.aba === 'resumo' && typeof renderResumoDoc === 'function') renderResumoDoc()
}

/** Desenha a memória de cálculo que está em fdState.multa. */
function mostrarMultaDoc() {
  const m = fdState.multa
  const caixa = document.getElementById('nd-memoria-calculo')
  if (!m) { caixa.innerHTML = ''; return }
  const linhas = m.linhas.filter(l => l.base !== 'sem_multa').map(l => `<b>${esc(l.numero)}</b>: ${
    l.pendencia ? `<span style="color:var(--red)">falta informar ${esc(l.pendencia)}</span>` : esc(l.memoria)}`)
  caixa.innerHTML = `
    <div style="font-size:12px;color:var(--tx2);background:var(--blt);border-radius:var(--r);padding:10px 12px;margin-top:4px">
      ${linhas.join('<br>')}
      ${m.fator_reincidencia > 1 ? `<div style="margin-top:4px">Reincidência: os artigos que dobram saem multiplicados por ${m.fator_reincidencia}.</div>` : ''}
      <div style="margin-top:6px;font-weight:700;color:var(--chumbo)">Total${m.gravada ? '' : ' estimado'}: ${fmtNum(m.total_upf || 0)} UPF${
        m.total_reais ? ' · R$ ' + fmtNum(m.total_reais) : ''}</div>
      ${m.gravada ? '' : '<div style="margin-top:4px;color:var(--tx3)">O valor definitivo é calculado na lavratura, com a UPF do exercício.</div>'}
    </div>`
}

/**
 * A SUGESTÃO DE UMA VISTORIA ESCOLHIDA — e o vínculo com ela.
 *
 * Era o miolo de `sugerirDaUltimaVistoria`, que só sabia trabalhar com a
 * última vistoria do imóvel. Separado, serve também a quem vem DA vistoria:
 * gravou uma constatação irregular e pediu o auto ali mesmo.
 *
 * @param {number} vistoriaId
 */
async function sugerirDaVistoria(vistoriaId) {
  const caixa = document.getElementById('nd-sugestao')
  caixa.innerHTML = ''
  if (!vistoriaId) { return }
  // AUTO DE INFRAÇÃO NÃO SE VINCULA A VISTORIA: nasce de uma notificação ou
  // de um embargo (campo Origem). O servidor ignoraria o vínculo; a tela
  // avisa, para o fiscal não achar que ficou amarrado.
  if (document.getElementById('nd-tipo').value === 'auto_infracao') {
    fdState.vistoriaId = null
    toast('Auto de Infração não se vincula a vistoria: informe em Origem a notificação ou o embargo de que ele decorre.', 'aviso')
    return
  }

  try {
    fdState.vistoriaId = vistoriaId
    // Com o tipo, o servidor devolve só os artigos da vistoria que servem a esta peça.
    const tipo = encodeURIComponent(document.getElementById('nd-tipo').value)
    const r = await fetch(`/api/vistorias/${vistoriaId}/sugestao?tipo=${tipo}`, { headers: { Accept: 'application/json' } })
    const s = await r.json()

    // A área e as exigências vêm ANTES do aviso de artigo faltando: mesmo sem
    // fundamentação cadastrada, elas são o que a vistoria apurou, e perdê-las
    // por causa de um `return` seria jogar fora o trabalho de campo.
    aproveitarDaVistoria(s)

    if (s.aviso) {
      caixa.innerHTML = `<div class="aviso-legal">${esc(s.aviso)}</div>`
      return
    }

    fdState.artigos = s.artigos.map(a => a.id)
    if (s.artigos[0]?.legislacao_id) {
      document.getElementById('nd-lei').value = s.artigos[0].legislacao_id
      trocarLeiDoc()
    }
  } catch (e) {
    console.error(e)
    caixa.innerHTML = '<div class="lista-vazia">Não foi possível buscar a sugestão de artigos.</div>'
  }
}

/**
 * Leva ao documento o que a vistoria já apurou: a área e as exigências.
 *
 * Só preenche campo VAZIO. O que o fiscal digitou na peça é decisão dele sobre
 * a peça, e não pode ser sobrescrito por um dado de origem — nem quando o dado
 * de origem é o mais recente.
 *
 * @param {Object} s resposta de /api/vistorias/{id}/sugestao
 */
function aproveitarDaVistoria(s) {
  const area = document.getElementById('nd-area-construida')
  if (area && !area.value && s.vistoria?.area_construida_m2) {
    area.value = Number(s.vistoria.area_construida_m2).toFixed(2)
    recalcularMultaDoc()
  }

  const desc = document.getElementById('nd-descricao')
  if (desc && !desc.value.trim() && s.exigencias?.length) {
    desc.value = 'Fica o administrado NOTIFICADO a:\n'
      + s.exigencias.map((e, i) => `${i + 1}. ${e.rotulo}`).join('\n')
  }
}

// ── ABERTURA A PARTIR DA LISTA ───────────────────────────────

/**
 * Abre o documento no FORMULÁRIO, não numa ficha separada.
 *
 * É o desenho do AppPOSTURAS: uma tela só por documento, e a aba Resumo faz o
 * papel de leitura. Duas telas para a mesma peça obrigariam a manter dois
 * lugares em dia com o mesmo conteúdo.
 *
 * @param {number} id
 */
async function abrirDocumento(id) {
  try {
    const r = await fetch('/api/documentos/' + id, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const doc = await r.json()

    // dFicha alimenta o menu de Opções, compartilhado entre o formulário e o
    // cartão da lista.
    dFicha.doc = doc
    await abrirFormDoc({ documento: doc })
    // O menu da ficha nasce no clique, como o do cartão: guardar as opções
    // basta, e o desenho vem do mesmo lugar dos demais menus do sistema.
    dFicha.opcoes = doc.opcoes || []
  } catch (e) {
    console.error(e)
    toast('Não foi possível abrir o documento', 'err')
  }
}
// ── MENU DE OPÇÕES ───────────────────────────────────────────

/**
 * Catálogo do menu "Opções": chave, rótulo e se a ação é destrutiva.
 *
 * As chaves são exatamente as de Documento::opcoesPara() — é o servidor que
 * decide o que cada usuário pode fazer com cada documento, e este arquivo só
 * dá nome ao que veio liberado. Chave nova lá tem de ganhar rótulo aqui,
 * senão a ação existe e não aparece.
 *
 * A ordem é a da leitura: primeiro tirar uma via, depois agir sobre a peça,
 * e por último o que não tem volta — anular e excluir, marcados como perigo.
 *
 * @type {Array<[string, string, boolean]>}
 */
/**
 * O catálogo do menu de opções: rótulo, para que serve, e o ícone.
 *
 * "Gerar PDF" e "Imprimir em A4" eram duas linhas para a mesma coisa — o mesmo
 * layout, por dois motores diferentes (o gerador de PDF e a página que se
 * manda para a impressora). Quem lê o menu não escolhe motor: escolhe papel.
 * Ficou uma linha só, servida pelo PDF, que é arquivo de verdade e por isso
 * também se anexa ao processo. A bobina de 80mm continua à parte porque é
 * OUTRO papel — e é o único caminho para ela, já que o gerador de PDF não
 * trabalha com página de altura variável.
 */
/** O ⋮ das linhas e dos cartões, desenhado num lugar só. */
const ICO_TRES_PONTOS = `<svg viewBox="0 0 24 24" width="16" height="16" fill="none"
  stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>`

const OPCOES_DOC = {
  pdf: {
    rotulo: 'Imprimir em A4',
    obs: 'Abre o PDF pronto para imprimir ou anexar ao processo.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>`,
  },
  imprimir_termica: {
    rotulo: 'Imprimir em bobina 80mm',
    obs: 'A via que se entrega em campo, na impressora portátil.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M4 8h16a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-1"/><path d="M7 14h10v8H7z"/></svg>`,
  },
  lavrar: {
    rotulo: 'Lavrar documento',
    obs: 'Colhe as assinaturas. Depois disso a peça não se edita mais.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>`,
  },
  defesa: {
    rotulo: 'Defesa',
    obs: 'O protocolo da defesa do autuado e o julgamento dela.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 4.5-3.2 7.9-8 9-4.8-1.1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>`,
  },
  cancelar: {
    rotulo: 'Cancelar documento',
    obs: 'A peça continua na série, marcada como sem efeito.',
    perigo: true,
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>`,
  },
  excluir: {
    rotulo: 'Excluir rascunho',
    obs: 'Some de vez. Só vale antes de gravar: com número, cancela-se.',
    perigo: true,
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>`,
  },
}

/** A ordem do menu: primeiro o que produz papel, depois o que muda o estado. */
const ORDEM_OPCOES_DOC = ['pdf', 'imprimir_termica', 'lavrar', 'defesa', 'cancelar', 'excluir']

/**
 * Abre o menu de opções do documento — o MESMO menu do botão "Novo documento".
 *
 * Eram dois menus com desenho diferente na mesma tela: um com ícone e uma
 * linha explicando cada peça, outro com uma lista de texto puro. A pessoa que
 * acabou de aprender um tinha de aprender o outro logo em seguida.
 *
 * `id` nulo é o menu da FICHA, que lê as opções de `dFicha`. Com id, o menu
 * sai de um cartão da lista e as opções vêm do documento carregado ali — e
 * não embutidas no atributo `onclick`: JSON dentro de atributo HTML termina no
 * primeiro aspas duplas, e a lista chegava vazia sem erro nenhum para avisar.
 *
 * @param {MouseEvent} ev
 * @param {number|null} [id] documento, quando o menu sai de um cartão da lista
 */
function abrirOpcoesDoc(ev, id = null) {
  const liberadas = id === null
    ? (dFicha.opcoes || [])
    : (dState.lista.find(d => d.id === id)?.opcoes || [])

  const itens = ORDEM_OPCOES_DOC
    .filter(chave => liberadas.includes(chave) && OPCOES_DOC[chave])
    .map((chave, i, lista) => {
      const o = OPCOES_DOC[chave]
      return {
        rotulo: o.rotulo,
        obs: o.obs,
        icone: o.icone,
        perigo: o.perigo,
        // Traço antes do primeiro item destrutivo: acima está o que produz
        // uma via, abaixo o que mexe no estado da peça.
        separar: o.perigo && !OPCOES_DOC[lista[i - 1]]?.perigo,
        acao: () => (id === null ? acaoDoc(chave) : acaoDocDaLista(id, chave)),
      }
    })

  if (!itens.length) { toast('Nada a fazer com este documento', 'aviso'); return }
  abrirMenuNovo(ev, itens)
}

/** @param {string} chave */
function acaoDoc(chave) {
  switch (chave) {
    case 'pdf':              return pedirAnexos('pdf')
    case 'imprimir_termica': return pedirAnexos('termica')
    case 'lavrar':           return lavrarDaFicha()
    case 'defesa':           return abrirDefesaDoc()
    case 'cancelar':         return abrirCancelamentoDoc()
    case 'excluir':          return excluirRascunhoDoc()
  }
}

/**
 * Ação disparada do cartão da lista, sem abrir a ficha.
 *
 * Carrega a ficha em memória antes de agir — o cartão só traz as colunas
 * leves da lista, e as ações precisam do documento inteiro (quantidade de
 * anexos, por exemplo, decide se a pergunta de impressão aparece). Mesmo
 * atalho do menu de opções do cartão no AppPOSTURAS.
 *
 * @param {MouseEvent} e @param {number} id @param {string} chave
 */
async function acaoDocDaLista(id, chave) {
  try {
    const r = await fetch('/api/documentos/' + id, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    dFicha.doc = await r.json()
    acaoDoc(chave)
  } catch (err) {
    console.error(err)
    toast('Não foi possível carregar o documento', 'err')
  }
}

// ── IMPRESSÃO ────────────────────────────────────────────────

/**
 * Pergunta sobre os anexos antes de imprimir, e só quando há anexos: foto de
 * evidência ocupa página inteira, e boa parte das vias impressas circula sem
 * elas. Sem anexo nenhum, não há o que perguntar — sai direto.
 *
 * @param {'pdf'|'a4'|'termica'} saida
 */
function pedirAnexos(saida) {
  dFicha.saida = saida
  const qtd = dFicha.doc?.anexos || 0
  if (!qtd) { imprimirDoc(true); return }

  document.getElementById('imp-anexos-msg').textContent =
    `Este documento tem ${qtd} anexo${qtd > 1 ? 's' : ''} da vistoria vinculada. `
    + 'Cada foto entra em tamanho grande na via impressa.'
  openModal('m-imp-anexos')
}

/** @param {boolean} comAnexos */
function imprimirDoc(comAnexos) {
  fModalBtn('m-imp-anexos')
  const id = dFicha.doc.id
  const a = comAnexos ? 1 : 0

  // O PDF é gerado no servidor e vira arquivo de verdade — é o que se anexa
  // ao processo. As duas impressões abrem uma página que se manda para a
  // impressora sozinha; a bobina de 80mm só existe por esse caminho, porque
  // o gerador de PDF não trabalha com página de altura variável.
  const url = dFicha.saida === 'pdf'
    ? `/documentos/${id}/pdf?anexos=${a}`
    : `/documentos/${id}/impressao?formato=${dFicha.saida === 'termica' ? 'termica' : 'a4'}&anexos=${a}`

  const win = window.open(url, '_blank')
  if (!win) toast('Permita pop-ups para imprimir', 'err')
}

// ── AÇÕES DA FICHA ───────────────────────────────────────────

/**
 * Lavrar pela ficha leva à própria peça: a lavratura colhe assinaturas, e
 * isso se faz olhando o resumo do documento, não num aviso de confirmação.
 */
async function lavrarDaFicha() {
  const doc = dFicha.doc
  fModalBtn('m-doc-ficha')
  await abrirFormDoc({ documento: doc })
  lavrarDocumento()
}

/**
 * Cancelar a peça que já tem número. Gravada (ainda não lavrada), basta a
 * justificativa; LAVRADA, o servidor exige também a senha de quem cancela —
 * o campo só aparece nesse caso.
 */
function abrirCancelamentoDoc() {
  const lavrado = dFicha.doc?.status?.valor !== 'gravado'
  document.getElementById('da-motivo').value = ''
  document.getElementById('da-senha').value = ''
  document.getElementById('da-senha-campo').hidden = !lavrado
  document.getElementById('da-texto').textContent = lavrado
    ? 'Documento lavrado: o cancelamento exige a justificativa e a sua senha. '
      + 'A peça continua na série, impressa com a marca CANCELADO, e o registro fica com o seu nome.'
    : 'Documento gravado, ainda não lavrado. Ele continua na série, com o número, marcado como '
      + 'cancelado; a justificativa fica registrada com o seu nome.'
  openModal('m-doc-anular')
}

async function confirmarCancelamentoDoc() {
  const motivo = document.getElementById('da-motivo').value.trim()
  const campoSenha = document.getElementById('da-senha-campo')
  const senha = document.getElementById('da-senha').value
  if (motivo.length < 10) {
    toast('Descreva a justificativa do cancelamento com pelo menos 10 caracteres', 'err')
    return
  }
  if (!campoSenha.hidden && !senha) {
    toast('Informe a sua senha para cancelar um documento lavrado', 'err')
    return
  }
  await comCarregando('Cancelando o documento…', async () => {
    try {
      const r = await fetch(`/api/documentos/${dFicha.doc.id}/cancelar`, {
        method: 'POST',
        headers: { ...cabecalhoDoc(), 'Content-Type': 'application/json' },
        body: JSON.stringify(campoSenha.hidden ? { motivo } : { motivo, senha }),
      })
      const d = await r.json().catch(() => ({}))
      if (!r.ok) throw new Error(d.errors ? Object.values(d.errors)[0][0] : (d.message || 'HTTP ' + r.status))
      document.getElementById('da-senha').value = ''
      fModalBtn('m-doc-anular')
      fModalBtn('m-doc-ficha')
      // Com o formulário aberto, a peça reabre já cancelada.
      if (document.getElementById('m-doc')?.classList.contains('open') && fdState.id === dFicha.doc.id) {
        fModalBtn('m-doc')
      }
      toast(d.message)
      carregarDocumentos()
    } catch (e) {
      console.error(e)
      toast(e.message || 'Falha ao cancelar', 'err')
    }
  })
}

function excluirRascunhoDoc() {
  confirmarAcao({
    titulo: 'Excluir rascunho',
    mensagem: 'O rascunho será apagado definitivamente. Documento com número nunca é '
            + 'excluído — para desfazê-lo existe o cancelamento, que deixa rastro.',
    textoBtn: 'Excluir',
    perigo: true,
    onConfirm: async () => {
      const r = await fetch(`/api/documentos/${dFicha.doc.id}`, { method: 'DELETE', headers: cabecalhoDoc() })
      const d = await r.json().catch(() => ({}))
      if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)
      toast(d.message)
      fModalBtn('m-doc-ficha')
      carregarDocumentos()
    },
  })
}

/** Cabeçalhos com o token CSRF — toda escrita passa por aqui. */
function cabecalhoDoc() {
  return {
    Accept: 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
  }
}
