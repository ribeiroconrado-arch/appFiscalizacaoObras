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
  /** rascunho em edição */    atual: null,
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

    return `
      <div class="mob-card" onclick="abrirDocumento(${d.id})">
        <div class="mc-top">
          <div class="notif-card-l1">
            <span class="notif-card-data">${esc(d.data)}</span>
            <span class="notif-card-tipo">${esc(d.tipo_rotulo)}:</span>
            <span class="proto-badge">${esc(d.numero)}</span>
          </div>
          <div class="mc-acoes">${tags}</div>
        </div>
        <div class="notif-card-linhas">
          ${linhas.map(([r, v]) => `<div><span class="notif-card-rot">${r}</span>${v}</div>`).join('')}
        </div>
      </div>`
  }).join('')
}

/** @param {string} campo @param {string} valor */
function filtrarDocumentos(campo, valor) {
  dState.filtros[campo] = valor
  carregarDocumentos()
}

// ── NOVO DOCUMENTO ───────────────────────────────────────────

/** Carrega tipos e leis uma vez por sessão. */
async function carregarOpcoes() {
  if (dState.opcoes) return dState.opcoes
  const r = await fetch('/api/documentos/opcoes', { headers: { Accept: 'application/json' } })
  dState.opcoes = await r.json()
  return dState.opcoes
}

/**
 * Abre o formulário de novo documento para o lote selecionado no mapa.
 * Sem lote não há documento: o imóvel é a entidade central do sistema.
 */
async function novoDocumento() {
  if (!state.selecionado) {
    toast('Selecione um lote no mapa antes de emitir um documento', 'err')
    return
  }
  const p = state.selecionado.properties
  dState.atual = { lote_id: p.id, artigos: [], vistoria_id: null }

  const o = await carregarOpcoes()
  document.getElementById('nd-imovel').textContent =
    `${p.bairro} · Quadra ${p.quadra ?? '—'} · Lote ${p.numero_lote ?? '—'}`

  document.getElementById('nd-tipo').innerHTML =
    o.tipos.map(t => `<option value="${t.valor}">${esc(t.rotulo)}</option>`).join('')
  document.getElementById('nd-lei').innerHTML =
    '<option value="">— selecione —</option>' +
    o.leis.map(l => `<option value="${l.id}">${esc(l.rotulo)}</option>`).join('')

  document.getElementById('nd-data').value = dataHojeLocal()
  document.getElementById('nd-hora').value = horaAgoraLocal()
  syncDataDoc()
  document.getElementById('nd-autuado').value = ''
  document.getElementById('nd-descricao').value = ''
  document.getElementById('nd-artigos').innerHTML =
    '<div class="lista-vazia">Escolha a lei para ver os artigos.</div>'

  // Terreno vem do GIS e o fiscal só confere; construída não existe em
  // cadastro nenhum — só a medição em campo é confiável para basear multa.
  document.getElementById('nd-area-terreno').value = p.area_gis_m2 ? Number(p.area_gis_m2).toFixed(2) : ''
  document.getElementById('nd-area-construida').value = ''
  document.getElementById('nd-bloco-area').style.display = 'none'
  document.getElementById('nd-memoria-calculo').innerHTML = ''

  trocarTipoDoc()
  await sugerirDaUltimaVistoria(p.id)
  openModal('m-doc')
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
 * Vistoria documental não impõe sanção: some a fundamentação e o prazo.
 * Auto tem prazo de DEFESA, fixo pela lei e não digitável. Notificação tem
 * prazo de CUMPRIMENTO, esse sim por documento.
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
    <label class="chk-item ${dState.atual.artigos.includes(a.id) ? 'marcado' : ''}">
      <input type="checkbox" value="${a.id}" ${dState.atual.artigos.includes(a.id) ? 'checked' : ''}
             onchange="marcarArtigo(${a.id}, this.checked); this.closest('.chk-item').classList.toggle('marcado', this.checked)">
      <span class="desc">${esc(a.rotulo)} · ${a.base_multa === 'fixa' ? fmtNum(a.multa_upf || 0) + ' UPF'
          : a.base_multa === 'sem_multa' ? 'sem multa'
          : fmtNum(a.multa_upf_m2 || 0) + ' UPF/m² · ' + (a.base_multa === 'area_terreno' ? 'terreno' : 'construído')}
        <br><span class="cod">${esc(a.conduta ?? '')}</span></span>
    </label>`).join('')
  trocarTipoDoc()
  recalcularMultaDoc()
}

/** @param {number} id @param {boolean} marcado */
function marcarArtigo(id, marcado) {
  const i = dState.atual.artigos.indexOf(id)
  if (marcado && i < 0) dState.atual.artigos.push(id)
  if (!marcado && i >= 0) dState.atual.artigos.splice(i, 1)
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
  const artigos = (lei?.artigos || []).filter(a => dState.atual.artigos.includes(a.id))
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
 * artigos que enquadram as irregularidades constatadas. É o passo que
 * dispensa o fiscal de procurar dispositivo na lei impressa (§18 do projeto).
 */
async function sugerirDaUltimaVistoria(loteId) {
  const caixa = document.getElementById('nd-sugestao')
  caixa.innerHTML = ''
  try {
    const h = await fetch(`/api/lotes/${loteId}/historico`, { headers: { Accept: 'application/json' } })
    const dados = await h.json()
    const ultima = dados.vistorias?.[0]
    if (!ultima) { caixa.innerHTML = '<div class="lista-vazia">Sem vistoria neste imóvel — o documento nascerá sem vínculo.</div>'; return }

    dState.atual.vistoria_id = ultima.id
    const r = await fetch(`/api/vistorias/${ultima.id}/sugestao`, { headers: { Accept: 'application/json' } })
    const s = await r.json()

    if (s.aviso) {
      caixa.innerHTML = `<div class="aviso-legal">${esc(s.aviso)}</div>`
      return
    }
    dState.atual.artigos = s.artigos.map(a => a.id)
    if (s.artigos[0]?.legislacao_id) {
      document.getElementById('nd-lei').value = s.artigos[0].legislacao_id
      trocarLeiDoc()
    }
    caixa.innerHTML = `<div style="font-size:12.5px;color:var(--tx2);padding:9px 12px;
        background:var(--gl);border:1.5px solid var(--gm);border-radius:var(--r)">
        Vistoria de <b>${esc(ultima.data_hora)}</b> · ${s.irregularidades.length} irregularidade(s).
        <b>${s.artigos.length} artigo(s)</b> sugeridos automaticamente.</div>`
  } catch (e) {
    console.error(e)
    caixa.innerHTML = '<div class="lista-vazia">Não foi possível buscar a sugestão de artigos.</div>'
  }
}

// ── GRAVAR E LAVRAR ──────────────────────────────────────────

/** Grava como rascunho. Sem número: o número nasce só na lavratura. */
function salvarRascunho() {
  confirmarAcao({
    titulo: 'Salvar rascunho',
    mensagem: 'O documento será salvo sem número. O número é atribuído apenas na lavratura.',
    textoBtn: 'Salvar',
    onConfirm: () => enviarDocumento(false),
  })
}

/** Grava e lavra: atribui número, congela prazo e fecha para edição. */
function lavrarDocumento() {
  const tipo = document.getElementById('nd-tipo').value
  const t = dState.opcoes.tipos.find(x => x.valor === tipo)
  if (t?.exige_artigos && !dState.atual.artigos.length) {
    toast('Selecione ao menos um artigo — documento sem fundamentação não pode ser lavrado', 'err')
    return
  }
  confirmarAcao({
    titulo: 'Lavrar documento',
    mensagem: 'A lavratura atribui número definitivo, congela o prazo e fecha o documento '
            + 'para edição. Esta ação não pode ser desfeita — só anulada.',
    textoBtn: 'Lavrar',
    onConfirm: () => enviarDocumento(true),
  })
}

/** @param {boolean} lavrar */
async function enviarDocumento(lavrar) {
  const fd = new FormData()
  fd.append('tipo', document.getElementById('nd-tipo').value)
  fd.append('data_fato', document.getElementById('nd-datahora').value)
  fd.append('autuado_nome', document.getElementById('nd-autuado').value)
  fd.append('descricao', document.getElementById('nd-descricao').value)
  const lei = document.getElementById('nd-lei').value
  if (lei) fd.append('legislacao_id', lei)
  if (dState.atual.vistoria_id) fd.append('vistoria_id', dState.atual.vistoria_id)
  const prazo = document.getElementById('nd-prazo').value
  if (prazo !== '') fd.append('prazo_dias', prazo)
  const areaTerreno = document.getElementById('nd-area-terreno').value
  const areaConstruida = document.getElementById('nd-area-construida').value
  if (areaTerreno !== '') fd.append('area_terreno_m2', areaTerreno)
  if (areaConstruida !== '') fd.append('area_construida_m2', areaConstruida)
  dState.atual.artigos.forEach(a => fd.append('artigos[]', a))

  const cab = {
    Accept: 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
  }

  try {
    const r = await fetch(`/api/lotes/${dState.atual.lote_id}/documentos`, { method: 'POST', headers: cab, body: fd })
    if (r.status === 419) { toast('Sessão expirada. Recarregando...', 'err'); setTimeout(() => location.reload(), 1500); return }
    const d = await r.json().catch(() => ({}))
    if (!r.ok) throw new Error(d.errors ? Object.values(d.errors)[0][0] : (d.message || 'HTTP ' + r.status))

    if (!lavrar) {
      fModalBtn('m-doc'); toast('Rascunho salvo'); irPara('documentos'); return
    }

    const l = await fetch(`/api/documentos/${d.documento.id}/lavrar`, { method: 'POST', headers: cab })
    const dl = await l.json().catch(() => ({}))
    if (!l.ok) throw new Error(dl.message || 'HTTP ' + l.status)

    fModalBtn('m-doc')
    toast(dl.message)
    irPara('documentos')
  } catch (e) {
    console.error(e)
    toast(e.message || 'Falha ao gravar o documento', 'err')
    throw e   // mantém o modal de confirmação aberto para nova tentativa
  }
}

/** Placeholder — a ficha completa do documento entra junto com o PDF. */
function abrirDocumento(id) {
  const d = dState.lista.find(x => x.id === id)
  if (!d) return
  // Abre numa aba nova — é o próprio navegador quem renderiza o PDF, não
  // uma tela do app. Rascunho também imprime (com marca-d'água), útil para
  // conferir antes de lavrar.
  window.open('/documentos/' + id + '/pdf', '_blank')
}
