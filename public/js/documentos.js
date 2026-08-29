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
  try {
    const r = await fetch('/api/documentos?' + p, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    dState.lista = d.documentos
    renderDocumentos()
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="lista-vazia">Não foi possível carregar os documentos.</div>'
  }
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

  alvo.innerHTML = dState.lista.map(d => {
    const tags = [
      `<span class="badge ${esc(d.status.classe)}">${esc(d.status.texto)}</span>`,
      d.prazo ? `<span class="badge ${esc(d.prazo.classe)}">${esc(d.prazo.texto)}</span>` : '',
    ].join('')

    // Rodapé: o que se confere DEPOIS de achar a peça. Documento sem artigo
    // não sustenta sanção, e mostrar isso na lista evita a descoberta tardia,
    // na hora de lavrar.
    const rodape = d.artigos
      ? `${d.artigos} artigo(s)` + (d.valor_upf ? ` · ${fmtNum(d.valor_upf)} UPF` : '')
      : '<span style="color:var(--red)">sem fundamentação</span>'

    return `
      <div class="mob-card doc-card st-${esc(d.status.valor ?? '')}" onclick="abrirDocumento(${d.id})">
        <div class="doc-card-topo">
          <span class="doc-ico">${ICO_TIPO_DOC[d.tipo] || ICO_TIPO_DOC.padrao}</span>
          <div class="doc-ident">
            <div class="doc-l1">
              <span class="proto-badge">${esc(d.numero)}</span>
              <span class="doc-tipo">${esc(d.tipo_rotulo)}</span>
            </div>
            <div class="doc-autuado">${esc(d.autuado)}</div>
            <div class="doc-imovel">${esc(d.imovel)}</div>
          </div>
          <div class="mc-acoes">
            ${tags}
            <div class="df-opcoes card-opcoes">
              <button type="button" class="card-opcoes-btn" title="Opções"
                      onclick="abrirOpcoesDoc(event, ${d.id})">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
              </button>
            </div>
          </div>
        </div>
        <div class="doc-rodape">
          <span class="doc-lei">${esc(d.lei || 'sem legislação')}</span>
          <span class="doc-art">${rodape}</span>
          <span class="doc-data">${esc(d.data)}</span>
        </div>
      </div>`
  }).join('')

  // Os menus são montados depois do innerHTML: cada um depende das opções
  // que o servidor liberou para aquele documento.
  for (const d of dState.lista) {
  }
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

/** @param {string} campo @param {string} valor */
function filtrarDocumentos(campo, valor) {
  dState.filtros[campo] = valor
  carregarDocumentos()
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

/** Mantém o campo escondido com aaaa-mm-ddThh:mm. */
function syncDataDoc() {
  const d = document.getElementById('nd-data').value
  const h = document.getElementById('nd-hora').value || '00:00'
  document.getElementById('nd-datahora').value = d ? `${d}T${h}` : ''
  atualizarDisplayData(document.getElementById('nd-data'))
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

/** Renderiza os artigos da lei escolhida, marcando os sugeridos. */
function trocarLeiDoc() {
  const id = document.getElementById('nd-lei').value
  const lei = dState.opcoes.leis.find(l => String(l.id) === id)
  const alvo = document.getElementById('nd-artigos')

  if (!lei) { alvo.innerHTML = '<div class="lista-vazia">Escolha a lei para ver os artigos.</div>'; trocarTipoDoc(); return }

  if (!lei.artigos.length) {
    // Este é o caso real hoje: leis cadastradas, artigos não. Dizer o que
    // falta e onde resolver é melhor do que mostrar lista vazia.
    alvo.innerHTML = `<div class="aviso-legal"><b>Esta lei ainda não tem artigos cadastrados.</b><br>
      A fundamentação legal precisa ser cadastrada em Parâmetros &gt; Legislação, com
      validação jurídica. Sem artigo, o sistema não permite lavrar o documento.</div>`
    trocarTipoDoc(); return
  }

  alvo.innerHTML = lei.artigos.map(a => `
    <label class="chk-item ${fdState.artigos.includes(a.id) ? 'marcado' : ''}">
      <input type="checkbox" value="${a.id}" ${fdState.artigos.includes(a.id) ? 'checked' : ''}
             onchange="marcarArtigo(${a.id}, this.checked); this.closest('.chk-item').classList.toggle('marcado', this.checked)">
      <span class="desc">${esc(a.rotulo)} · ${a.base_multa === 'fixa' ? fmtNum(a.multa_upf || 0) + ' UPF'
          : a.base_multa === 'sem_multa' ? 'sem multa'
          : fmtNum(a.multa_upf_m2 || 0) + ' UPF/m² · ' + (a.base_multa === 'area_terreno' ? 'terreno' : 'construído')}
        <br><span class="cod">${esc(a.conduta ?? '')}</span></span>
    </label>`).join('')

  trocarTipoDoc()
  recalcularMultaDoc()
  // Artigo recém-renderizado nasce habilitado; o estado do documento manda.
  travarCamposDoc(fdState.estado !== 'novo' && !fdState.editando)
}

/** @param {number} id @param {boolean} marcado */
function marcarArtigo(id, marcado) {
  const i = fdState.artigos.indexOf(id)
  if (marcado && i < 0) fdState.artigos.push(id)
  if (!marcado && i >= 0) fdState.artigos.splice(i, 1)
  recalcularMultaDoc()
}

/**
 * Prévia da multa, artigo por artigo — mesma regra de App\Models\Artigo::
 * calcularMulta(), reproduzida aqui só para o fiscal ver o total ANTES de
 * lavrar. O valor que vale de verdade é recalculado no servidor na lavratura;
 * esta função nunca é enviada ao back-end.
 */
function recalcularMultaDoc() {
  const lei = dState.opcoes.leis.find(l => String(l.id) === document.getElementById('nd-lei').value)
  const artigos = (lei?.artigos || []).filter(a => fdState.artigos.includes(a.id))
  const porArea = artigos.filter(a => a.base_multa === 'area_construida' || a.base_multa === 'area_terreno')

  document.getElementById('nd-bloco-area').style.display = porArea.length ? '' : 'none'
  if (!porArea.length) { document.getElementById('nd-memoria-calculo').innerHTML = ''; return }

  const areaTerreno = parseFloat(document.getElementById('nd-area-terreno').value) || null
  const areaConstruida = parseFloat(document.getElementById('nd-area-construida').value) || null

  let total = 0
  const linhas = artigos.map(a => {
    if (a.base_multa === 'sem_multa') return null
    if (a.base_multa === 'fixa') { total += Number(a.multa_upf || 0); return `${esc(a.numero)}: ${fmtNum(a.multa_upf || 0)} UPF (fixo)` }

    const area = a.base_multa === 'area_terreno' ? areaTerreno : areaConstruida
    if (area === null) return `${esc(a.numero)}: <span style="color:var(--red)">informe a área para calcular</span>`

    let valor = Number(a.multa_upf_m2 || 0) * area
    let obs = ''
    if (a.multa_min_upf !== null && valor < Number(a.multa_min_upf)) { valor = Number(a.multa_min_upf); obs = ' (piso aplicado)' }
    else if (a.multa_max_upf !== null && valor > Number(a.multa_max_upf)) { valor = Number(a.multa_max_upf); obs = ' (teto aplicado)' }
    total += valor
    return `${esc(a.numero)}: ${fmtNum(a.multa_upf_m2)} UPF/m² × ${fmtNum(area)} m² = ${fmtNum(valor)} UPF${obs}`
  }).filter(Boolean)

  document.getElementById('nd-memoria-calculo').innerHTML = `
    <div style="font-size:12px;color:var(--tx2);background:var(--blt);border-radius:var(--r);padding:10px 12px;margin-top:4px">
      ${linhas.join('<br>')}
      <div style="margin-top:6px;font-weight:700;color:var(--chumbo)">Total estimado: ${fmtNum(total)} UPF</div>
      <div style="margin-top:4px;color:var(--tx3)">O valor definitivo é calculado na lavratura.</div>
    </div>`
}

/**
 * Motor de legislação: busca a última vistoria do lote e pede ao servidor os
 * artigos que enquadram as irregularidades constatadas. É o passo que dispensa
 * o fiscal de procurar dispositivo na lei impressa (§18 do projeto).
 */
async function sugerirDaUltimaVistoria(loteId) {
  const caixa = document.getElementById('nd-sugestao')
  caixa.innerHTML = ''
  if (!loteId) return

  try {
    const h = await fetch(`/api/lotes/${loteId}/historico`, { headers: { Accept: 'application/json' } })
    const dados = await h.json()
    const ultima = dados.vistorias?.[0]
    if (!ultima) { caixa.innerHTML = '<div class="lista-vazia">Sem vistoria neste imóvel — o documento nascerá sem vínculo.</div>'; return }

    fdState.vistoriaId = ultima.id
    const r = await fetch(`/api/vistorias/${ultima.id}/sugestao`, { headers: { Accept: 'application/json' } })
    const s = await r.json()

    // A área e as exigências vêm ANTES do aviso de artigo faltando: mesmo sem
    // fundamentação cadastrada, elas são o que a vistoria apurou, e perdê-las
    // por causa de um `return` seria jogar fora o trabalho de campo.
    aproveitarDaVistoria(s)

    if (s.aviso) { caixa.innerHTML = `<div class="aviso-legal">${esc(s.aviso)}</div>`; return }

    fdState.artigos = s.artigos.map(a => a.id)
    if (s.artigos[0]?.legislacao_id) {
      document.getElementById('nd-lei').value = s.artigos[0].legislacao_id
      trocarLeiDoc()
    }
    const areaDita = s.vistoria?.area_rotulo
      ? ` Área aferida: <b>${esc(s.vistoria.area_rotulo)}</b>.` : ''
    caixa.innerHTML = `<div style="font-size:12.5px;color:var(--tx2);padding:9px 12px;
        background:var(--gl);border:1.5px solid var(--gm);border-radius:var(--r)">
        Vistoria de <b>${esc(ultima.data_hora)}</b> · ${s.irregularidades.length} irregularidade(s).
        <b>${s.artigos.length} artigo(s)</b> sugeridos automaticamente.${areaDita}</div>`
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
    obs: 'Dá número e data. Depois disso a peça não se edita mais.',
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>`,
  },
  anular: {
    rotulo: 'Anular documento',
    obs: 'A peça continua no processo, marcada como sem efeito.',
    perigo: true,
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>`,
  },
  excluir: {
    rotulo: 'Excluir rascunho',
    obs: 'Some de vez. Só vale antes de lavrar.',
    perigo: true,
    icone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>`,
  },
}

/** A ordem do menu: primeiro o que produz papel, depois o que muda o estado. */
const ORDEM_OPCOES_DOC = ['pdf', 'imprimir_termica', 'lavrar', 'anular', 'excluir']

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
    case 'anular':           return abrirAnulacaoDoc()
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

function lavrarDaFicha() {
  confirmarAcao({
    titulo: 'Lavrar documento',
    mensagem: 'A lavratura atribui número definitivo, congela o prazo e fecha o documento '
            + 'para edição. Esta ação não pode ser desfeita — só anulada.',
    textoBtn: 'Lavrar',
    onConfirm: async () => {
      const r = await fetch(`/api/documentos/${dFicha.doc.id}/lavrar`, { method: 'POST', headers: cabecalhoDoc() })
      const d = await r.json().catch(() => ({}))
      if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)
      toast(d.message)
      fModalBtn('m-doc-ficha')
      carregarDocumentos()
    },
  })
}

function abrirAnulacaoDoc() {
  document.getElementById('da-motivo').value = ''
  openModal('m-doc-anular')
}

async function confirmarAnulacaoDoc() {
  const motivo = document.getElementById('da-motivo').value.trim()
  if (motivo.length < 10) {
    toast('Descreva o motivo da anulação com pelo menos 10 caracteres', 'err')
    return
  }
  try {
    const r = await fetch(`/api/documentos/${dFicha.doc.id}/anular`, {
      method: 'POST',
      headers: { ...cabecalhoDoc(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ motivo }),
    })
    const d = await r.json().catch(() => ({}))
    if (!r.ok) throw new Error(d.errors ? Object.values(d.errors)[0][0] : (d.message || 'HTTP ' + r.status))
    fModalBtn('m-doc-anular')
    fModalBtn('m-doc-ficha')
    toast(d.message)
    carregarDocumentos()
  } catch (e) {
    console.error(e)
    toast(e.message || 'Falha ao anular', 'err')
  }
}

function excluirRascunhoDoc() {
  confirmarAcao({
    titulo: 'Excluir rascunho',
    mensagem: 'O rascunho será apagado definitivamente. Documento já lavrado nunca é '
            + 'excluído — para desfazê-lo existe a anulação, que deixa rastro.',
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
