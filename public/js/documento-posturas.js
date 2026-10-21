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
}
