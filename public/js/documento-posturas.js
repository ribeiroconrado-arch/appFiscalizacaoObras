// ══════════════════════════════════════════════
// PEÇAS DE POSTURAS — o que a notificação e os autos do AppPOSTURAS têm a
// mais que a peça de Obras, dentro do mesmo formulário (#m-doc):
//
//   · a MEDIÇÃO da Lei 723/2002 (zona, dB permitido, dB aferido, aparelho e
//     fonte causadora), na notificação e no auto de infração;
//   · o VEÍCULO — a fonte do ruído, ou o retido no Auto de Retenção;
//   · os BENS apreendidos no Auto de Apreensão.
//
// Carregado só em Posturas. documentos.js e documento-form.js chamam estas
// funções pelo nome, sempre com `typeof … === 'function'` antes: em Obras
// elas não existem, e o formulário segue como sempre foi.
// ══════════════════════════════════════════════

/** A lei do ruído é a escolhida, e a peça é das que medem? */
function _pedeMedicaoDoc() {
  const tipo = document.getElementById('nd-tipo').value
  return leiDoDoc()?.formulario_tipo === 'lei_723_2002' && ['notificacao', 'auto_infracao'].includes(tipo)
}

/**
 * Mostra os blocos que a peça pede — medição, apreensão, veículo — conforme
 * a lei, o tipo, a fonte causadora e o subtipo. Chamada a cada mudança de lei,
 * tipo ou artigo (trocarTipoDoc, recalcularMultaDoc) e pelos próprios campos.
 */
function aplicarPosturasDoc() {
  const g = id => document.getElementById(id)
  if (!g('nd-bloco-ruido')) return

  const tipo = g('nd-tipo').value
  const medir = _pedeMedicaoDoc()
  g('nd-bloco-ruido').style.display = medir ? '' : 'none'
  if (medir) _montarCamposDaMedicao(leiDoDoc())
  _pintarPermitidoDoc()

  const apreende = tipo === 'auto_apreensao'
  const subtipo = g('nd-apreensao-subtipo').value
  g('nd-bloco-apreensao').style.display = apreende ? '' : 'none'
  g('nd-bloco-itens').style.display = apreende && subtipo === 'apreensao' ? '' : 'none'

  const retencao = apreende && subtipo === 'retencao'
  const veiculo = retencao || (medir && g('nd-ruido-fonte').value === 'Veículo')
  g('nd-bloco-veiculo').style.display = veiculo ? '' : 'none'
  g('nd-veiculo-titulo').textContent = retencao ? 'Veículo retido' : 'Identificação do veículo'
  g('nd-retencao-extra').style.display = retencao ? '' : 'none'
  _aplicarLavraturaImediataDoc()
}

/**
 * A LAVRATURA IMEDIATA (art. 16 da Lei 723) — a regra do AppPOSTURAS: só no
 * auto de infração pela lei do ruído, e só quando a origem NÃO é uma
 * notificação (com notificação o infrator já foi cientificado e teve prazo).
 * O combo mostra o inciso a partir do "Inc."; a peça imprime o texto inteiro.
 */
function _aplicarLavraturaImediataDoc() {
  const bloco = document.getElementById('nd-bloco-lavratura')
  if (!bloco) return
  const lei = leiDoDoc()
  const itens = lei?.lavratura_imediata_itens || []
  const mostrar = document.getElementById('nd-tipo').value === 'auto_infracao'
    && lei?.formulario_tipo === 'lei_723_2002' && itens.length > 0 && !_origemEhNotificacaoDoc()
  bloco.style.display = mostrar ? '' : 'none'
  const sel = document.getElementById('nd-lavratura-item')
  if (!mostrar) return
  if (sel.dataset.lei !== String(lei.id)) {
    const atual = sel.value || sel.dataset.valor || ''
    sel.dataset.lei = String(lei.id)
    sel.innerHTML = '<option value="">Selecione…</option>' + itens.map(it => {
      const texto = String(it.texto || '')
      const corte = texto.search(/Inc\.\s*[IVXL]+\b/i)
      const base = corte >= 0 ? texto.slice(corte) : texto
      return `<option value="${Number(it.numero)}">Item ${Number(it.numero)} — ${esc(base.length > 70 ? base.slice(0, 70) + '…' : base)}</option>`
    }).join('')
    sel.value = atual
  }
}

/** A origem escolhida é uma notificação? (o select diz o tipo; peça reaberta, o rótulo) */
function _origemEhNotificacaoDoc() {
  const opt = document.getElementById('nd-origem')?.selectedOptions?.[0]
  if (!opt || !opt.value) return false
  return opt.dataset.tipo ? opt.dataset.tipo === 'notificacao' : /^notifica/i.test(opt.textContent.trim())
}

/**
 * As opções dos selects da medição: as zonas são as da LEI escolhida (cada
 * lei de ruído tem as suas), e os aparelhos, os cadastrados em uso. Refeitas
 * só quando a lei muda, para não apagar o que o fiscal escolheu.
 */
function _montarCamposDaMedicao(lei) {
  const area = document.getElementById('nd-ruido-area')
  if (area.dataset.lei !== String(lei?.id)) {
    const atual = area.value || area.dataset.valor || ''
    area.dataset.lei = String(lei?.id)
    area.innerHTML = '<option value="">Selecione a zona…</option>'
      + (lei?.areas_db || []).map(a => `<option value="${esc(a.area)}">${esc(a.area)}</option>`).join('')
    area.value = atual
  }
  const equip = document.getElementById('nd-ruido-equip')
  if (!equip.dataset.pronto) {
    const atual = equip.value || equip.dataset.valor || ''
    equip.dataset.pronto = '1'
    // O aparelho com calibração vencida continua na lista — a peça antiga
    // pode citá-lo —, mas avisa: medição com aparelho vencido não se sustenta.
    equip.innerHTML = '<option value="">Selecione o aparelho…</option>'
      + (dState.opcoes?.decibelimetros || []).map(x =>
        `<option value="${x.id}">${esc(x.rotulo)}${x.vencido ? ' — CALIBRAÇÃO VENCIDA' : ''}</option>`).join('')
    equip.value = atual
  }
}

/** O dB permitido da zona no período do fato (noturno das 18h às 6h). */
function _pintarPermitidoDoc() {
  const campo = document.getElementById('nd-ruido-permitido')
  if (!campo) return
  const zona = (leiDoDoc()?.areas_db || []).find(a => a.area === document.getElementById('nd-ruido-area').value)
  const hora = Number((document.getElementById('nd-hora').value || '').slice(0, 2))
  if (!zona || Number.isNaN(hora)) { campo.value = ''; return }
  const noturno = hora >= 18 || hora < 6
  campo.value = `${noturno ? zona.noturno : zona.diurno} dB (${noturno ? 'noturno' : 'diurno'})`
}

/** Zona ou dB mudou: o permitido e a prévia da multa acompanham. */
function mudouMedicaoDoc() {
  _pintarPermitidoDoc()
  recalcularMultaDoc()
}

/** A medição como vai ao servidor (na prévia da multa e no Gravar). */
function medicaoDoDoc() {
  if (!_pedeMedicaoDoc()) return null
  const v = id => document.getElementById(id).value.trim()
  return {
    decibelimetro_id: Number(v('nd-ruido-equip')) || null,
    fonte_causadora: v('nd-ruido-fonte') || null,
    area: v('nd-ruido-area') || null,
    db_aferido: v('nd-ruido-aferido') === '' ? null : Number(v('nd-ruido-aferido')),
  }
}

// ── BENS APREENDIDOS ─────────────────────────────────────────

/** Mais uma linha na lista de bens. @param {{descricao?:string, quantidade?:number, unidade?:string}} [item] */
function adicionarItemDoc(item = {}) {
  const travado = docTravado()
  const linha = document.createElement('div')
  linha.className = 'nd-item'
  linha.innerHTML = `
    <input type="text" class="nd-item-desc" maxlength="300" placeholder="Descrição do bem (ex.: CAIXA DE SOM JBL)" data-lock
           value="${esc(item.descricao || '')}" ${travado ? 'disabled' : ''}>
    <input type="number" class="nd-item-qtd" min="0.01" step="0.01" placeholder="Qtd." data-lock
           value="${esc(String(item.quantidade ?? 1))}" ${travado ? 'disabled' : ''}>
    <input type="text" class="nd-item-un" maxlength="20" placeholder="UN" data-lock
           value="${esc(item.unidade || '')}" ${travado ? 'disabled' : ''}>
    <button type="button" class="artigo-tag-x" title="Tirar" data-lock ${travado ? 'disabled' : ''}
            onclick="this.closest('.nd-item').remove()">&times;</button>`
  document.getElementById('nd-itens').appendChild(linha)
  if (!item.descricao && !travado) linha.querySelector('.nd-item-desc').focus()
}

function _itensDoDoc() {
  return [...document.querySelectorAll('#nd-itens .nd-item')].map(l => ({
    descricao: l.querySelector('.nd-item-desc').value.trim(),
    quantidade: Number(l.querySelector('.nd-item-qtd').value) || 1,
    unidade: l.querySelector('.nd-item-un').value.trim() || null,
  })).filter(i => i.descricao)
}

// ── FORMULÁRIO ───────────────────────────────────────────────

/** O que as peças de Posturas acrescentam ao corpo do Gravar (corpoDoDoc). */
function corpoPosturasDoc() {
  const g = id => document.getElementById(id)
  if (!g('nd-bloco-ruido')) return {}
  const tipo = g('nd-tipo').value
  const subtipo = tipo === 'auto_apreensao' ? (g('nd-apreensao-subtipo').value || null) : null
  const veiculoVisivel = g('nd-bloco-veiculo').style.display !== 'none'
  const v = id => g(id).value.trim() || null
  return {
    ruido: medicaoDoDoc(),
    veiculo: veiculoVisivel ? {
      placa: v('nd-veic-placa'), marca: v('nd-veic-marca'), modelo: v('nd-veic-modelo'), cor: v('nd-veic-cor'),
      ...(subtipo === 'retencao' ? { ano: v('nd-veic-ano'), proprietario: v('nd-veic-proprietario'), condutor: v('nd-veic-condutor') } : {}),
    } : null,
    apreensao_subtipo: subtipo,
    itens: subtipo === 'apreensao' ? _itensDoDoc() : [],
    // O formulário do AppPOSTURAS: inscrição municipal e e-mail do
    // contribuinte, a posição do local e o inciso da lavratura imediata.
    autuado_inscricao_municipal: v('nd-aut-inscricao'),
    autuado_email: v('nd-aut-email'),
    local_lat: g('nd-gps-lat').value ? Number(g('nd-gps-lat').value) : null,
    local_lng: g('nd-gps-lng').value ? Number(g('nd-gps-lng').value) : null,
    local_precisao: g('nd-gps-prec').value ? Number(g('nd-gps-prec').value) : null,
    lavratura_imediata_item: g('nd-bloco-lavratura').style.display !== 'none' && g('nd-lavratura-item').value
      ? Number(g('nd-lavratura-item').value) : null,
    // Prazo vazio é "não informado" (o Gravar cobra) — e não 0, que é imediato.
    ...(g('bloco-prazo').style.display !== 'none' ? { prazo_dias: g('nd-prazo').value === '' ? null : Number(g('nd-prazo').value) } : {}),
  }
}

/** Peça reaberta: os blocos voltam com o que ela guardou. @param {Object} d */
function preencherPosturasDoc(d) {
  const g = id => document.getElementById(id)
  if (!g('nd-bloco-ruido')) return
  limparPosturasDoc()
  const r = d.ruido || {}
  // As zonas só existem depois que a lei é montada: o valor fica guardado
  // no próprio select e é aplicado quando as opções chegam.
  g('nd-ruido-area').dataset.valor = r.area || ''
  g('nd-ruido-equip').dataset.valor = r.decibelimetro_id ? String(r.decibelimetro_id) : ''
  g('nd-ruido-fonte').value = r.fonte_causadora || ''
  g('nd-ruido-aferido').value = r.db_aferido ?? ''
  const v = d.veiculo || {}
  for (const [id, campo] of [['nd-veic-placa', 'placa'], ['nd-veic-marca', 'marca'], ['nd-veic-modelo', 'modelo'],
    ['nd-veic-cor', 'cor'], ['nd-veic-ano', 'ano'], ['nd-veic-proprietario', 'proprietario'], ['nd-veic-condutor', 'condutor']]) {
    g(id).value = v[campo] || ''
  }
  g('nd-apreensao-subtipo').value = d.apreensao_subtipo || ''
  ;(d.itens || []).forEach(adicionarItemDoc)
  g('nd-aut-inscricao').value = d.autuado?.inscricao_municipal || ''
  g('nd-aut-email').value = d.autuado?.email || ''
  if (d.local_gps?.lat != null) _marcarGpsDoc({ lat: d.local_gps.lat, lon: d.local_gps.lng, prec: d.local_gps.precisao })
  g('nd-lavratura-item').dataset.valor = d.lavratura_imediata?.numero ? String(d.lavratura_imediata.numero) : ''
  g('nd-prazo').value = d.prazo_dias ?? ''
  g('nd-prazo-imediato').hidden = String(d.prazo_dias ?? '') !== '0'
  // "Mesmo endereço" volta marcado quando o local é o domicílio fiscal.
  const igual = ['logradouro', 'numero', 'bairro'].every(p => (g('nd-aut-' + p).value.trim().toUpperCase()) === (g('nd-im-' + p).value.trim().toUpperCase()))
    && g('nd-im-logradouro').value.trim() !== ''
  g('nd-mesmo-endereco').checked = igual
  mesmoEnderecoDoc(igual)
  aplicarPosturasDoc()
}

function limparPosturasDoc() {
  const g = id => document.getElementById(id)
  if (!g('nd-bloco-ruido')) return
  for (const id of ['nd-ruido-fonte', 'nd-ruido-aferido', 'nd-ruido-permitido', 'nd-veic-placa', 'nd-veic-marca',
    'nd-veic-modelo', 'nd-veic-cor', 'nd-veic-ano', 'nd-veic-proprietario', 'nd-veic-condutor', 'nd-apreensao-subtipo']) {
    g(id).value = ''
  }
  // Selects refeitos na próxima montagem: a lei pode ser outra.
  for (const id of ['nd-ruido-area', 'nd-ruido-equip']) {
    const s = g(id)
    s.innerHTML = ''
    s.dataset.valor = ''
    delete s.dataset.lei
    delete s.dataset.pronto
  }
  g('nd-itens').innerHTML = ''
  for (const id of ['nd-aut-inscricao', 'nd-aut-email']) g(id).value = ''
  _limparGpsDoc()
  const lav = g('nd-lavratura-item')
  lav.innerHTML = '<option value="">Selecione…</option>'
  lav.dataset.valor = ''
  delete lav.dataset.lei
  g('nd-mesmo-endereco').checked = false
  mesmoEnderecoDoc(false)
  for (const q of ['aut', 'local']) { const c = g(_campoEndDoc[q].sugestao); c.hidden = true; c.innerHTML = '' }
  // Prazo em branco, como no AppPOSTURAS: o fiscal informa (0 = imediato).
  g('nd-prazo').value = ''
  g('nd-prazo-imediato').hidden = true
  g('nd-descricao').placeholder = 'Descrição da infração…'
}

// ── CONTRIBUINTE ─────────────────────────────────────────────

/**
 * Saindo do CPF/CNPJ: o cadastro de contribuintes preenche o que estiver
 * vazio (o AppPOSTURAS fazia o mesmo). Sem cadastro, um CNPJ ainda passa pela
 * consulta da Receita (buscarCnpjDoc).
 * @param {HTMLInputElement} inp
 */
async function buscarContribuinteDoc(inp) {
  if (docTravado()) return
  const digitos = inp.value.replace(/\D/g, '')
  if (digitos.length !== 11 && digitos.length !== 14) return
  if (inp.dataset.consultado === digitos) return
  inp.dataset.consultado = digitos
  try {
    const r = await fetch('/api/contribuintes/' + digitos, { headers: { Accept: 'application/json' } })
    if (r.ok) {
      const c = (await r.json()).contribuinte
      const poe = (id, v) => { const el = document.getElementById(id); if (el && v && !el.value.trim()) el.value = v }
      poe('nd-autuado', c.nome); poe('nd-aut-inscricao', c.inscricao_municipal); poe('nd-aut-email', c.email)
      poe('nd-aut-logradouro', c.logradouro); poe('nd-aut-numero', c.numero); poe('nd-aut-bairro', c.bairro)
      poe('nd-aut-cidade', c.cidade)
      const uf = document.getElementById('nd-aut-uf')
      if (c.estado && !uf.value) { uf.value = c.estado; uf.dispatchEvent(new Event('change')) }
      if (document.getElementById('nd-mesmo-endereco').checked) mesmoEnderecoDoc(true)
      toast('Contribuinte encontrado — dados preenchidos')
      return
    }
  } catch { /* sem cadastro à mão: segue para o CNPJ */ }
  if (digitos.length === 14) buscarCnpjDoc(inp)
}

// ── RUA → BAIRRO ─────────────────────────────────────────────
//
// A relação rua↔bairro do Posturas (Parâmetros › Ruas e bairros), nos dois
// endereços da peça — o domicílio fiscal e o local da infração —, como na
// denúncia: escolhida a rua, os bairros dela aparecem para um toque, e se for
// um só e o bairro estiver vazio, ele vem preenchido.

const _campoEndDoc = {
  aut:   { rua: 'nd-aut-logradouro', bairro: 'nd-aut-bairro', ruas: 'nd-lista-ruas-aut', bairros: 'nd-lista-bairros-aut', sugestao: 'nd-aut-sugestao' },
  local: { rua: 'nd-im-logradouro', bairro: 'nd-im-bairro', ruas: 'nd-lista-ruas-local', bairros: 'nd-lista-bairros-local', sugestao: 'nd-local-sugestao' },
}
const _ruaTimerDoc = {}

/** @param {'aut'|'local'} q */
function ruaDigitadaDoc(q) {
  const c = _campoEndDoc[q]
  clearTimeout(_ruaTimerDoc[q])
  _ruaTimerDoc[q] = setTimeout(async () => {
    const termo = document.getElementById(c.rua).value.trim()
    if (termo.length >= 2) {
      try {
        const r = await fetch('/api/enderecos/logradouros?q=' + encodeURIComponent(termo), { headers: { Accept: 'application/json' } })
        if (r.ok) document.getElementById(c.ruas).innerHTML = (await r.json()).logradouros.map(l => `<option value="${esc(l.nome)}">`).join('')
      } catch { /* sem sugestão: o campo aceita o que se digitar */ }
    }
    ruaEscolhidaDoc(q)
  }, 250)
  if (q === 'aut' && document.getElementById('nd-mesmo-endereco').checked) mesmoEnderecoDoc(true)
}

/** Rua definida: os bairros por onde ela passa. @param {'aut'|'local'} q */
async function ruaEscolhidaDoc(q) {
  const c = _campoEndDoc[q]
  const rua = document.getElementById(c.rua).value.trim()
  const caixa = document.getElementById(c.sugestao)
  if (!rua) { caixa.hidden = true; return }
  try {
    const r = await fetch('/api/enderecos/bairros?rua=' + encodeURIComponent(rua), { headers: { Accept: 'application/json' } })
    if (!r.ok) return
    const d = await r.json()
    const ligados = d.da_rua ? d.bairros.map(b => b.nome) : []
    document.getElementById(c.bairros).innerHTML = d.bairros.map(b => `<option value="${esc(b.nome)}">`).join('')
    const campo = document.getElementById(c.bairro)
    if (ligados.length === 1 && !campo.value.trim() && !campo.readOnly) {
      campo.value = ligados[0]
      caixa.hidden = true
      if (q === 'aut' && document.getElementById('nd-mesmo-endereco').checked) mesmoEnderecoDoc(true)
      return
    }
    caixa.hidden = !ligados.length || campo.readOnly
    caixa.innerHTML = ligados.length ? '<span>Bairros desta rua:</span> ' + ligados.map(b =>
      `<button type="button" onclick="escolherBairroDoc('${q}', ${jsArg(b)})">${esc(b)}</button>`).join('') : ''
  } catch { caixa.hidden = true }
}

/** @param {'aut'|'local'} q @param {string} b */
function escolherBairroDoc(q, b) {
  document.getElementById(_campoEndDoc[q].bairro).value = b
  document.getElementById(_campoEndDoc[q].sugestao).hidden = true
  if (q === 'aut' && document.getElementById('nd-mesmo-endereco').checked) mesmoEnderecoDoc(true)
}

/** A lista de bairros (a da aba Bairros), quando o campo ainda não tem a da rua. @param {'aut'|'local'} q */
async function carregarBairrosDoc(q) {
  const lista = document.getElementById(_campoEndDoc[q].bairros)
  if (lista.children.length) return
  try {
    const r = await fetch('/api/enderecos/bairros', { headers: { Accept: 'application/json' } })
    if (r.ok) lista.innerHTML = (await r.json()).bairros.map(b => `<option value="${esc(b.nome)}">`).join('')
  } catch { /* sem lista: o campo aceita o que se digitar */ }
}

/**
 * "Mesmo endereço do domicílio fiscal": o local da infração copia rua,
 * número e bairro do contribuinte e fica só para leitura; desmarcado, volta a
 * ser digitável (com o que já estava).
 * @param {boolean} ligado
 */
function mesmoEnderecoDoc(ligado) {
  for (const p of ['logradouro', 'numero', 'bairro']) {
    const destino = document.getElementById('nd-im-' + p)
    if (ligado) destino.value = document.getElementById('nd-aut-' + p).value
    destino.readOnly = ligado
    destino.classList.toggle('so-leitura-campo', ligado)
  }
  if (ligado) document.getElementById('nd-local-sugestao').hidden = true
}

// ── GPS DO LOCAL ─────────────────────────────────────────────

/**
 * A posição do local (a melhor leitura em alguns segundos, como na denúncia).
 * Segundo toque com o GPS marcado: remove.
 */
async function gpsDoLocalDoc() {
  if (document.getElementById('nd-gps-lat').value) { _limparGpsDoc(); return }
  if (!navigator.geolocation) { toast('Este aparelho não informa a localização.', 'err'); return }
  const txt = document.getElementById('nd-gps-txt')
  txt.textContent = 'Lendo…'
  try {
    const p = await melhorPosicaoGps({ aCadaMelhora: q => { txt.textContent = '±' + Math.round(q.prec) + ' m' } })
    _marcarGpsDoc(p)
    if (p.prec > GPS_FRACO_M) toast(`GPS fraco (±${Math.round(p.prec)} m). Confira o endereço digitado.`, 'aviso')
  } catch (e) {
    _limparGpsDoc()
    toast(e?.code === 1 ? 'Permita a localização para marcar o GPS.' : 'Não foi possível ler o GPS.', 'err')
  }
}

/** @param {{lat:number, lon:number, prec?:number|null}} p */
function _marcarGpsDoc(p) {
  document.getElementById('nd-gps-lat').value = p.lat
  document.getElementById('nd-gps-lng').value = p.lon
  document.getElementById('nd-gps-prec').value = p.prec ?? ''
  document.getElementById('nd-gps-txt').textContent = 'Remover GPS'
  document.getElementById('nd-gps').classList.add('marcado')
  const info = document.getElementById('nd-gps-info')
  info.hidden = false
  info.textContent = `GPS: ${Number(p.lat).toFixed(6)}, ${Number(p.lon).toFixed(6)}${p.prec ? ` (±${Math.round(p.prec)} m)` : ''}`
}

function _limparGpsDoc() {
  for (const id of ['nd-gps-lat', 'nd-gps-lng', 'nd-gps-prec']) document.getElementById(id).value = ''
  document.getElementById('nd-gps-txt').textContent = 'GPS'
  document.getElementById('nd-gps').classList.remove('marcado')
  document.getElementById('nd-gps-info').hidden = true
}

// ── O QUE O GRAVAR COBRA ─────────────────────────────────────

/**
 * Os obrigatórios do AppPOSTURAS (os campos com *), na ordem das abas. O
 * rascunho grava incompleto; o Gravar (que dá número) não.
 * @returns {{aba:string, id:string, msg:string}|null}
 */
function pendenciaPosturasDoc() {
  const v = id => document.getElementById(id)?.value?.trim() || ''
  const pede = [
    ['autuado', 'nd-autuado-doc', 'Informe o CPF/CNPJ do contribuinte.'],
    ['autuado', 'nd-autuado', 'Informe o nome do contribuinte.'],
    ['autuado', 'nd-aut-logradouro', 'Informe o logradouro do domicílio fiscal.'],
    ['autuado', 'nd-aut-numero', 'Informe o número do domicílio fiscal.'],
    ['autuado', 'nd-aut-bairro', 'Informe o bairro do domicílio fiscal.'],
    ['autuado', 'nd-aut-cidade', 'Informe a cidade do domicílio fiscal.'],
    ['autuado', 'nd-aut-uf', 'Informe o estado do domicílio fiscal.'],
    ['local', 'nd-im-logradouro', 'Informe o logradouro do local da infração.'],
    ['local', 'nd-im-numero', 'Informe o número do local da infração.'],
    ['local', 'nd-im-bairro', 'Informe o bairro do local da infração.'],
  ]
  if (document.getElementById('bloco-prazo').style.display !== 'none') pede.push(['infracao', 'nd-prazo', 'Informe o prazo em dias (0 = imediato).'])
  if (document.getElementById('nd-bloco-lavratura').style.display !== 'none') {
    pede.push(['infracao', 'nd-lavratura-item', 'Escolha o inciso da lavratura imediata (art. 16).'])
  }
  const falta = pede.find(([, id]) => !v(id))
  return falta ? { aba: falta[0], id: falta[1], msg: falta[2] } : null
}
