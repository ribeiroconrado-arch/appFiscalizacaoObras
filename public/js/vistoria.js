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
    renderHistorico(d.vistorias)
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="vazio-msg">Não foi possível carregar o histórico.</div>'
  }
}

/** @param {Array<Object>} vistorias */
function renderHistorico(vistorias) {
  const alvo = document.getElementById('fi-historico')
  document.getElementById('fi-hist-total').textContent =
    vistorias.length ? `${vistorias.length} vistoria${vistorias.length > 1 ? 's' : ''}` : ''

  if (!vistorias.length) {
    alvo.innerHTML = '<div class="vazio-msg">Nenhuma vistoria registrada neste imóvel.</div>'
    return
  }

  alvo.innerHTML = vistorias.map(v => {
    const irr = v.irregularidades.length
      ? `<div class="hist-irr">${v.irregularidades.map(i => '• ' + esc(i.descricao)).join('<br>')}</div>`
      : ''
    const obs = v.observacoes ? `<div class="hist-obs">${esc(v.observacoes)}</div>` : ''
    const fotos = v.evidencias ? ` · ${v.evidencias} evidência${v.evidencias > 1 ? 's' : ''}` : ''
    return `
      <div class="hist-item ${esc(v.situacao)}">
        <div class="hist-topo">
          <span class="hist-data">${esc(v.data_hora)}</span>
          <span class="badge ${esc(v.situacao_badge)}">${esc(v.situacao_rotulo)}</span>
        </div>
        <div class="hist-meta">${esc(v.fiscal ?? '—')}${fotos}</div>
        ${irr}${obs}
      </div>`
  }).join('')
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

  await carregarCatalogo()
  openModal('m-vistoria')
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
