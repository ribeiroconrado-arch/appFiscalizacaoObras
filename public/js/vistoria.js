// ══════════════════════════════════════════════
// MÓDULO: VISTORIA (Etapa 5)
//
// Fecha o ciclo de campo: do lote identificado no mapa até a vistoria gravada
// com checklist, observações e fotos — e o histórico do imóvel logo abaixo,
// que é o que o fiscal consulta ANTES de decidir o que fazer na visita.
// ══════════════════════════════════════════════

/** Estado do formulário de vistoria. */
const vState = {
  /** @type {Object|null} lote sendo vistoriado */ lote: null,
  /** @type {Array<Object>} catálogo de irregularidades (cache da sessão) */ catalogo: [],
  /** @type {Array<{arquivo:File, titulo:string, url:string}>} */ anexos: [],
  /**
   * Protocolo de desmembramento/unificação que esta vistoria vai atender.
   *
   * Preenchido quando o formulário é aberto A PARTIR do protocolo; nulo
   * quando o fiscal abriu pelo mapa, e aí o seletor pergunta.
   */
  /** @type {number|null} */ protocoloId: null,
  enviando: false,
}

// ── HISTÓRICO ────────────────────────────────────────────────

/**
 * Busca e renderiza o histórico do lote dentro da ficha.
 * @param {number} loteId
 */
async function carregarHistorico(loteId) {
  const alvo = document.getElementById('fi-historico')
  alvo.innerHTML = '<div class="vazio-msg">Carregando histórico…</div>'
  try {
    const r = await fetch(`/api/lotes/${loteId}/historico`, { headers: { 'Accept': 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    renderHistorico(d.eventos ?? [])
    renderResumo(d.resumo ?? null)
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="vazio-msg">Não foi possível carregar o histórico.</div>'
  }
}

/**
 * Preenche a aba Dados com o retrato do imóvel: status, vistorias, fachada.
 *
 * Vem junto do histórico de propósito — é a mesma consulta, e pedir duas vezes
 * ao servidor a mesma informação seria trabalho dobrado para o aparelho do
 * fiscal, que é o dispositivo mais fraco da cadeia.
 *
 * @param {Object|null} resumo
 */
function renderResumo(resumo) {
  const põe = (id, texto) => {
    const el = document.getElementById(id)
    if (el) { el.textContent = texto }
  }

  if (!resumo) {
    põe('fi-status', '—'); põe('fi-qt-vistorias', '—'); põe('fi-ultima-vistoria', '—')
    return
  }

  const st = document.getElementById('fi-status')
  if (st) {
    st.innerHTML = `<span class="badge ${esc(resumo.status.classe)}">${esc(resumo.status.texto)}</span>`
  }

  põe('fi-qt-vistorias', String(resumo.vistorias ?? 0))
  põe('fi-ultima-vistoria', resumo.ultima_vistoria || 'nenhuma')

  // A situação do cabeçalho segue o status quando ele é definitivo (lote
  // baixado), porque aí o imóvel não existe mais e isso vale mais do que
  // qualquer outra informação da ficha.
  const sit = document.getElementById('fi-situacao')
  if (sit && resumo.status.texto === 'Baixado') {
    sit.className = 'badge bd-cx'
    sit.textContent = 'Baixado'
  }

  renderFachada(resumo.fachada)
}

/** A foto mais recente do imóvel, com a data dela. @param {Object|null} f */
function renderFachada(f) {
  const fig = document.getElementById('fi-fachada')
  const data = document.getElementById('fi-fachada-data')
  if (!fig) { return }

  const vazio = fig.querySelector('.fi-vazio')
  const img = fig.querySelector('img')

  if (!f) {
    if (data) { data.textContent = '' }
    if (img) { img.remove() }
    if (vazio) { vazio.style.display = '' }
    return
  }

  if (data) { data.textContent = f.quando ? '· ' + f.quando : '' }
  if (vazio) { vazio.style.display = 'none' }

  const alvo = img || document.createElement('img')
  alvo.src = f.url
  alvo.alt = 'Fachada do imóvel'
  alvo.loading = 'lazy'
  if (!img) { fig.appendChild(alvo) }
}

/** Ícone de cada tipo de evento da linha do tempo. */
const ICO_EVENTO = {
  vistoria: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/>',
  documento: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/>',
  protocolo: '<path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/><rect x="9" y="2" width="6" height="4" rx="1"/>',
}

/**
 * Linha do tempo do imóvel, do mais recente para o mais antigo.
 *
 * Um evento por marco: vistoria, documento lavrado e requerimento. O traço
 * vertical ligando os marcos é o que transforma uma lista em sequência —
 * é a sequência que conta a história do processo.
 *
 * @param {Array<Object>} eventos
 */
function renderHistorico(eventos) {
  const alvo = document.getElementById('fi-historico')
  document.getElementById('fi-hist-total').textContent =
    eventos.length ? `${eventos.length} registro${eventos.length > 1 ? 's' : ''}` : ''

  if (!eventos.length) {
    alvo.innerHTML = '<div class="vazio-msg">Nada registrado neste imóvel ainda.</div>'
    return
  }

  alvo.innerHTML = eventos.map(e => {
    const itens = (e.itens || []).length
      ? `<div class="lt-itens">${e.itens.map(i => '• ' + esc(i)).join('<br>')}</div>` : ''
    const obs = e.obs ? `<div class="lt-obs">${esc(e.obs)}</div>` : ''
    const det = e.detalhe ? `<div class="lt-det">${esc(e.detalhe)}</div>` : ''
    return `
      <div class="lt-item lt-${esc(e.tipo)}">
        <div class="lt-marca">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">${ICO_EVENTO[e.tipo] || ''}</svg>
        </div>
        <div class="lt-corpo">
          <div class="lt-topo">
            <span class="lt-data">${esc(e.quando ?? '—')}</span>
            ${e.badge ? `<span class="badge ${esc(e.badge.classe)}">${esc(e.badge.texto)}</span>` : ''}
          </div>
          <div class="lt-tit">${esc(e.titulo)}</div>
          ${det}${itens}${obs}${_atoCadastral(e)}
        </div>
      </div>`
  }).join('')
}

/**
 * Bloco do ato cadastral dentro do evento de vistoria.
 *
 * Aparece em três estados, e o do meio é o que costuma faltar nos sistemas:
 *
 *   nada        vistoria comum, sem protocolo de desmembramento/unificação;
 *   explicação  há o protocolo, mas algo trava — e o texto diz o quê;
 *   botão       tudo no lugar: executa o ato a partir desta vistoria.
 *
 * Mostrar o motivo em vez de simplesmente esconder o botão é o que evita o
 * chamado "por que não aparece a opção de unificar?".
 *
 * @param {Object} e evento da linha do tempo
 */
function _atoCadastral(e) {
  const a = e.ato_cadastral
  if (!a || !a.tipo) { return '' }

  const rotulo = a.tipo === 'unificacao' ? 'Unificar lotes' : 'Desenhar desmembramento'
  const proto = a.protocolo ? `Protocolo ${esc(a.protocolo.numero)}` : ''

  if (!a.pode) {
    return `<div class="lt-ato lt-ato-travado">${proto}: ${esc(a.motivo || 'ato indisponível.')}</div>`
  }

  return `<div class="lt-ato">
    <span>${proto} — deferido e vistoriado.</span>
    <button class="btn sm primary" onclick="iniciarAtoCadastral(${a.protocolo.id}, '${esc(a.tipo)}', ${e.lote_id ?? 'null'})">
      ${rotulo}</button></div>`
}

// ── FORMULÁRIO ───────────────────────────────────────────────

/** Abre o formulário de nova vistoria para o lote selecionado. */
async function novaVistoria() {
  const f = state.selecionado
  if (!f) { toast('Selecione um lote no mapa', 'err'); return }

  vState.lote = f.properties
  vState.anexos = []
  fModalBtn('m-ficha')

  document.getElementById('nv-lote').textContent =
    `${f.properties.bairro} · Quadra ${f.properties.quadra ?? '—'} · Lote ${f.properties.numero_lote ?? '—'}`

  // Data e hora já preenchidas com o momento da abertura — o fiscal está em
  // campo, e digitar data no celular é o que ele menos quer fazer.
  document.getElementById('nv-data').value = dataHojeLocal()
  document.getElementById('nv-hora').value = horaAgoraLocal()
  syncDataHora()

  document.getElementById('nv-situacao').value = 'irregular'
  document.getElementById('nv-obs').value = ''
  renderAnexos()

  // GPS: se o fiscal já capturou a posição, ela vai junto na vistoria.
  const gps = document.getElementById('nv-gps')
  if (state.pos) {
    gps.textContent = `${state.pos.lat.toFixed(6)}, ${state.pos.lon.toFixed(6)} (±${Math.round(state.pos.prec)} m)`
    gps.parentElement.style.display = ''
  } else {
    gps.parentElement.style.display = 'none'
  }

  await carregarProtocolosCadastrais(f.properties.id)
  await carregarCatalogo()
  openModal('m-vistoria')
}

/**
 * Protocolos de desmembramento/unificação deste imóvel à espera de vistoria.
 *
 * O seletor só aparece quando há algum: numa vistoria de rotina — que é a
 * esmagadora maioria — perguntar "atende a qual protocolo?" seria ruído.
 *
 * Este é o caminho de quem parte do MAPA, em campo. Quem parte da tela de
 * protocolos chega pelo botão "Registrar vistoria", que já traz o protocolo
 * escolhido.
 *
 * @param {number} loteId
 */
async function carregarProtocolosCadastrais(loteId) {
  const caixa = document.getElementById('nv-protocolo-caixa')
  const sel = document.getElementById('nv-protocolo')
  if (!caixa || !sel) { return }

  sel.innerHTML = '<option value="">— nenhum —</option>'
  caixa.hidden = true
  if (!loteId) { return }

  try {
    const r = await fetch('/api/lotes/' + loteId + '/protocolos-cadastrais',
      { headers: { Accept: 'application/json' } })
    if (!r.ok) { return }
    const d = await r.json()
    if (!d.protocolos.length) { return }

    d.protocolos.forEach(p => {
      const o = document.createElement('option')
      o.value = p.id
      o.textContent = p.rotulo
      sel.appendChild(o)
    })
    caixa.hidden = false

    // Quando a vistoria foi aberta A PARTIR de um protocolo, ele já vem
    // escolhido e o campo não é uma pergunta, é uma confirmação.
    if (vState.protocoloId) { sel.value = String(vState.protocoloId) }
  } catch (e) {
    console.error(e)   // sem protocolo o formulário funciona igual
  }
}

/** Busca o catálogo de irregularidades uma vez por sessão. */
async function carregarCatalogo() {
  if (vState.catalogo.length) { renderChecklist(); return }
  try {
    const r = await fetch('/api/irregularidades', { headers: { 'Accept': 'application/json' } })
    vState.catalogo = await r.json()
  } catch (e) {
    console.error(e)
    toast('Não foi possível carregar o checklist', 'err')
  }
  renderChecklist()
}

function renderChecklist() {
  const alvo = document.getElementById('nv-checklist')
  alvo.innerHTML = vState.catalogo.map(i => `
    <label class="chk-item" onclick="setTimeout(()=>this.classList.toggle('marcado', this.querySelector('input').checked),0)">
      <input type="checkbox" name="irregularidades[]" value="${i.id}">
      <span class="desc">${esc(i.descricao)}<br><span class="cod">${esc(i.codigo)} · ${esc(i.gravidade)}</span></span>
    </label>`).join('')
}

/** Mantém o campo escondido com o valor combinado aaaa-mm-ddThh:mm. */
function syncDataHora() {
  const d = document.getElementById('nv-data').value
  const h = document.getElementById('nv-hora').value || '00:00'
  document.getElementById('nv-datahora').value = d ? `${d}T${h}` : ''
  atualizarDisplayData(document.getElementById('nv-data'))
}

// ── ANEXOS ───────────────────────────────────────────────────

/** Handler do input de arquivo. @param {HTMLInputElement} input */
function anexarArquivos(input) {
  for (const arquivo of input.files) {
    vState.anexos.push({
      arquivo,
      titulo: arquivo.name.replace(/\.[^.]+$/, '').slice(0, 160),
      url: arquivo.type.startsWith('image/') ? URL.createObjectURL(arquivo) : null,
    })
  }
  input.value = ''   // permite reanexar o mesmo arquivo depois de remover
  renderAnexos()
}

function renderAnexos() {
  const alvo = document.getElementById('nv-anexos')
  if (!vState.anexos.length) {
    alvo.innerHTML = '<div class="vazio-msg">Nenhuma foto anexada.</div>'
    return
  }
  alvo.innerHTML = vState.anexos.map((a, i) => `
    <div class="anexo-item">
      <div class="anexo-thumb">
        ${a.url ? `<img src="${a.url}" alt="">` : '<div class="pdf">PDF</div>'}
      </div>
      <div class="anexo-info">
        <input class="t" style="width:100%;border:none;background:none;font-family:inherit"
               value="${esc(a.titulo)}" maxlength="160"
               oninput="vState.anexos[${i}].titulo = this.value"
               aria-label="Título da evidência">
        <div class="s">${(a.arquivo.size / 1024 / 1024).toFixed(1)} MB</div>
      </div>
      <button type="button" class="btn danger sm" onclick="removerAnexo(${i})">Excluir</button>
    </div>`).join('')
}

/** Exclusão SEMPRE pergunta antes — regra sem exceção. @param {number} i */
function removerAnexo(i) {
  const a = vState.anexos[i]
  confirmarAcao({
    titulo: 'Remover evidência',
    mensagem: `Remover "${a.titulo}" desta vistoria?`,
    textoBtn: 'Remover',
    perigo: true,
    onConfirm: () => {
      if (a.url) URL.revokeObjectURL(a.url)
      vState.anexos.splice(i, 1)
      renderAnexos()
    },
  })
}

// ── GRAVAÇÃO ─────────────────────────────────────────────────

/** Grava a vistoria. Confirma antes: é registro que passa a valer como ato. */
function gravarVistoria() {
  if (vState.enviando) return

  const marcadas = [...document.querySelectorAll('#nv-checklist input:checked')]
  const situacao = document.getElementById('nv-situacao').value

  if (!document.getElementById('nv-datahora').value) {
    toast('Informe data e hora da vistoria', 'err'); return
  }
  if (situacao === 'irregular' && !marcadas.length) {
    toast('Marque ao menos uma irregularidade', 'err'); return
  }

  const resumo = marcadas.length
    ? `${marcadas.length} irregularidade${marcadas.length > 1 ? 's' : ''}`
    : 'sem irregularidades'

  confirmarAcao({
    titulo: 'Gravar vistoria',
    mensagem: `Registrar vistoria do lote ${vState.lote.numero_lote}, quadra `
            + `${vState.lote.quadra}, com ${resumo} e ${vState.anexos.length} evidência(s)?`,
    textoBtn: 'Gravar',
    onConfirm: () => enviarVistoria(marcadas),
  })
}

/** @param {Array<HTMLInputElement>} marcadas */
async function enviarVistoria(marcadas) {
  vState.enviando = true
  const fd = new FormData()
  fd.append('data_hora', document.getElementById('nv-datahora').value)
  fd.append('situacao', document.getElementById('nv-situacao').value)
  fd.append('observacoes', document.getElementById('nv-obs').value)
  // O vínculo com o protocolo é o que, mais tarde, libera o ato cadastral.
  const proto = document.getElementById('nv-protocolo')?.value
  if (proto) { fd.append('protocolo_id', proto) }
  marcadas.forEach(c => fd.append('irregularidades[]', c.value))
  if (state.pos) {
    fd.append('latitude', state.pos.lat)
    fd.append('longitude', state.pos.lon)
    fd.append('accuracy', state.pos.prec)
  }
  vState.anexos.forEach((a, i) => {
    fd.append('evidencias[]', a.arquivo)
    fd.append(`titulos[${i}]`, a.titulo)
  })

  try {
    const r = await fetch(`/api/lotes/${vState.lote.id}/vistorias`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: fd,   // sem Content-Type: o navegador põe o boundary do multipart
    })

    if (r.status === 419) { toast('Sessão expirada. Recarregando...', 'err'); setTimeout(() => location.reload(), 1500); return }
    const d = await r.json().catch(() => ({}))

    if (!r.ok) {
      // 422 traz os erros campo a campo; mostrar o primeiro é mais útil que
      // um "erro ao gravar" genérico.
      const primeiro = d.errors ? Object.values(d.errors)[0][0] : d.message
      throw new Error(primeiro || 'HTTP ' + r.status)
    }

    vState.anexos.forEach(a => a.url && URL.revokeObjectURL(a.url))
    vState.anexos = []
    // Zera o vinculo: sem isto ele vazaria para a proxima vistoria aberta
    // na mesma sessao, amarrando-a a um protocolo que ninguem escolheu.
    vState.protocoloId = null
    fModalBtn('m-vistoria')
    toast('Vistoria registrada')

    // Reabre a ficha já com o histórico atualizado — o fiscal confere o que
    // acabou de gravar sem ter que procurar o lote de novo.
    if (state.selecionado) abrirFicha(state.selecionado)
  } catch (e) {
    console.error(e)
    toast(e.message || 'Falha ao gravar a vistoria', 'err')
    throw e   // mantém o modal de confirmação aberto para nova tentativa
  } finally {
    vState.enviando = false
  }
}
