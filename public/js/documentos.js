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

function renderDocumentos() {
  const alvo = document.getElementById('lista-documentos')
  document.getElementById('cont-doc').textContent = dState.lista.length

  if (!dState.lista.length) {
    alvo.innerHTML = '<div class="lista-vazia">Nenhum documento com esses filtros.</div>'
    return
  }

  alvo.innerHTML = dState.lista.map(d => {
    const tags = [
      `<span class="badge ${esc(d.status.classe)}">${esc(d.status.texto)}</span>`,
      d.prazo ? `<span class="badge ${esc(d.prazo.classe)}">${esc(d.prazo.texto)}</span>` : '',
    ].join('')

    const linhas = [
      ['Imóvel', esc(d.imovel)],
      ['Autuado', esc(d.autuado)],
      ['Lei', esc(d.lei)],
      // Documento sem artigo não sustenta sanção: mostrar isso na lista evita
      // a descoberta tardia, na hora de lavrar.
      ['Artigos', d.artigos ? `${d.artigos} artigo(s)` + (d.valor_upf ? ` · ${fmtNum(d.valor_upf)} UPF` : '')
                            : '<span style="color:var(--red)">sem fundamentação</span>'],
    ]

    // Número primeiro: é por ele que o documento é citado, cobrado e
    // procurado. Tipo e data vêm depois, como qualificação.
    //
    // O ⋮ ao lado das tags é o menu de opções do cartão, como no AppPOSTURAS:
    // imprimir ou anular direto da lista, sem abrir a ficha para depois
    // procurar a mesma ação lá dentro.
    return `
      <div class="mob-card" onclick="abrirDocumento(${d.id})">
        <div class="mc-top">
          <div class="notif-card-l1">
            <span class="proto-badge">${esc(d.numero)}</span>
            <span class="notif-card-tipo">${esc(d.tipo_rotulo)}</span>
            <span class="notif-card-data">${esc(d.data)}</span>
          </div>
          <div class="mc-acoes">
            ${tags}
            <div class="df-opcoes card-opcoes">
              <button type="button" class="card-opcoes-btn" title="Opções"
                      onclick="alternarOpcoesDoc(event, 'menu-card-${d.id}')">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
              </button>
              <div class="df-menu" id="menu-card-${d.id}"></div>
            </div>
          </div>
        </div>
        <div class="notif-card-linhas">
          ${linhas.map(([r, v]) => `<div><span class="notif-card-rot">${r}</span>${v}</div>`).join('')}
        </div>
      </div>`
  }).join('')

  // Os menus são montados depois do innerHTML: cada um depende das opções
  // que o servidor liberou para aquele documento.
  for (const d of dState.lista) {
    montarMenuOpcoes(d.opcoes || [], 'menu-card-' + d.id, d.id)
  }
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
    montarMenuOpcoes(doc.opcoes || [], 'fd-menu')
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
const OPCOES_DOC = [
  ['pdf',              'Gerar PDF',                 false],
  ['imprimir_a4',      'Imprimir em A4',            false],
  ['imprimir_termica', 'Imprimir em bobina 80mm',   false],
  ['lavrar',           'Lavrar documento',          false],
  ['anular',           'Anular documento',          true],
  ['excluir',          'Excluir rascunho',          true],
]

/**
 * Itens do menu, a partir do que o SERVIDOR liberou.
 *
 * A visibilidade é por classe `.open`, e não pelo atributo `hidden`: o menu é
 * um flex container, e `display:flex` na classe vence o `display:none` que o
 * `hidden` traz do navegador — foi assim que ele passou a nascer aberto.
 *
 * @param {string[]} liberadas
 * @param {string} [alvo] id do elemento de menu (o da ficha, por padrão)
 * @param {number} [id] documento, quando o menu é o de um cartão da lista
 */
function montarMenuOpcoes(liberadas, alvo = 'df-menu', id = null) {
  const menu = document.getElementById(alvo)
  if (!menu) return
  const chamada = c => (id === null ? `acaoDoc('${c}')` : `acaoDocDaLista(event, ${id}, '${c}')`)
  menu.innerHTML = OPCOES_DOC
    .filter(([chave]) => liberadas.includes(chave))
    .map(([chave, rotulo, perigo]) =>
      `<button type="button" class="df-item${perigo ? ' perigo' : ''}" onclick="${chamada(chave)}">${esc(rotulo)}</button>`)
    .join('')
  menu.classList.remove('open')
}

/** Fecha todos os menus abertos — só um por vez faz sentido. */
function fecharMenusOpcoes() {
  document.querySelectorAll('.df-menu.open').forEach(m => m.classList.remove('open'))
}

/** @param {MouseEvent} e @param {string} [alvo] */
function alternarOpcoesDoc(e, alvo = 'df-menu') {
  e.stopPropagation()
  const menu = document.getElementById(alvo)
  const jaAberto = menu.classList.contains('open')
  fecharMenusOpcoes()
  if (!jaAberto) menu.classList.add('open')
}

/** Clique em qualquer outro lugar fecha o menu — comportamento esperado de menu. */
document.addEventListener('click', fecharMenusOpcoes)

/** @param {string} chave */
function acaoDoc(chave) {
  fecharMenusOpcoes()

  switch (chave) {
    case 'pdf':              return pedirAnexos('pdf')
    case 'imprimir_a4':      return pedirAnexos('a4')
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
async function acaoDocDaLista(e, id, chave) {
  e.stopPropagation()
  fecharMenusOpcoes()
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
