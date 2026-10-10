// ═══════════════════════════════════════════════════════════════
// DOCUMENTO-DEFESA.JS — a defesa do auto (#m-doc-defesa)
//
// O mesmo procedimento do AppPOSTURAS, em duas sub-abas:
//
//   DEFESA      o protocolo: número, data e o arquivo apresentado. Registra
//               quem lavrou o auto, ou o administrador. A peça passa a
//               "em defesa".
//   JULGAMENTO  só o ADMINISTRADOR: resultado, data, o texto da decisão e o
//               arquivo dela. Deferida, a peça vira "defendido" e não gera
//               custa; indeferida, volta a lavrada, apta.
//
// Aberta pelo menu Opções do documento. O que cada um pode fazer vem do
// servidor (ficha.defesa.pode_protocolar / pode_julgar) — a tela só mostra.
// Julgada, a defesa fica só para consulta.
//
// Depende de documentos.js (dFicha, cabecalhoDoc, carregarDocumentos) e de
// ui.js (toast, confirmarAcao, comCarregando, esc, openModal, fModalBtn,
// atualizarDisplayData).
// ═══════════════════════════════════════════════════════════════

/** @type {{aba:'dados'|'julgamento'}} */
const defState = { aba: 'dados' }

/** Abre a janela da defesa do documento da ficha (dFicha.doc). */
function abrirDefesaDoc() {
  const doc = dFicha.doc, d = doc?.defesa
  if (!d) { toast('Este tipo de documento não tem defesa', 'aviso'); return }
  document.getElementById('def-numero').textContent = doc.numero || ''
  pintarDefesaDoc()
  // Com a defesa protocolada e por julgar, o administrador já cai no julgamento.
  trocarAbaDefesa(d.pode_julgar ? 'julgamento' : 'dados')
  openModal('m-doc-defesa')
}

/** @param {'dados'|'julgamento'} aba */
function trocarAbaDefesa(aba) {
  // O julgamento só existe depois do protocolo.
  if (aba === 'julgamento' && !dFicha.doc?.defesa?.protocolo) {
    toast('Registre primeiro o protocolo da defesa', 'aviso')
    aba = 'dados'
  }
  defState.aba = aba
  const g = id => document.getElementById(id)
  g('def-tab-dados').classList.toggle('ativa', aba === 'dados')
  g('def-tab-julgamento').classList.toggle('ativa', aba === 'julgamento')
  g('def-aba-dados').hidden = aba !== 'dados'
  g('def-aba-julgamento').hidden = aba !== 'julgamento'
  pintarRodapeDefesa()
}

/** Preenche as duas sub-abas com o que a ficha traz, e trava o que não se altera. */
function pintarDefesaDoc() {
  const d = dFicha.doc.defesa
  const g = id => document.getElementById(id)
  const data = (id, valor) => { g(id).value = valor || ''; atualizarDisplayData(g(id)) }

  // ── o protocolo ──
  g('def-protocolo').value = d.protocolo || ''
  data('def-data-protocolo', d.data_protocolo)
  g('def-anexo').value = ''
  for (const id of ['def-protocolo', 'def-data-protocolo', 'def-anexo']) g(id).disabled = !d.pode_protocolar
  g('def-anexo-campo').hidden = !d.pode_protocolar
  g('def-anexo-atual').innerHTML = linkArquivoDefesa(d.anexo, 'Nenhum arquivo juntado.')
  g('def-prazo').textContent = d.prazo_ate ? 'Prazo de defesa do documento: até ' + d.prazo_ate + '.' : ''
  g('def-intempestiva').hidden = !d.intempestiva
  g('def-registrado').textContent = d.registrado ? 'Registrada por ' + d.registrado + '.' : ''

  // ── o julgamento ──
  const julgada = !!d.resultado
  g('def-resultado-deferida').checked = d.resultado === 'deferida'
  g('def-resultado-indeferida').checked = d.resultado === 'indeferida'
  // A data da decisão só existe em dia/mês/ano na ficha: julgada, vai como texto.
  g('def-data-resultado-campo').hidden = julgada
  g('def-data-resultado-lida').hidden = !julgada
  g('def-data-resultado-lida').textContent = julgada ? 'Decisão de ' + d.data_resultado_br + '.' : ''
  if (!julgada) data('def-data-resultado', '')
  g('def-parecer').value = d.parecer || ''
  g('def-julg-anexo').value = ''
  for (const id of ['def-resultado-deferida', 'def-resultado-indeferida', 'def-data-resultado', 'def-parecer', 'def-julg-anexo']) {
    g(id).disabled = !d.pode_julgar
  }
  g('def-julg-anexo-campo').hidden = !d.pode_julgar
  g('def-julg-anexo-atual').innerHTML = linkArquivoDefesa(d.julgamento_anexo, julgada ? 'Nenhum arquivo juntado.' : '')
  g('def-julgado').textContent = d.julgado ? 'Julgada por ' + d.julgado + '.' : ''
  g('def-julg-aviso').textContent = julgada
    ? (d.resultado === 'deferida'
      ? 'Defesa DEFERIDA: o documento está "defendido" e não gera custa.'
      : 'Defesa INDEFERIDA: o documento voltou a lavrado, apto.')
    : (d.pode_julgar ? 'Deferida, o documento passa a "defendido" e não gera custa. Indeferida, volta a lavrado, apto. A decisão não se altera depois de registrada.'
      : 'Aguardando o julgamento, que é registrado pelo administrador.')
}

/** @param {{nome:string,url:string}|null} a @param {string} vazio */
function linkArquivoDefesa(a, vazio) {
  return a
    ? `<a href="${esc(a.url)}" target="_blank" rel="noopener">${esc(a.nome)}</a>`
    : esc(vazio)
}

/** O botão do rodapé acompanha a sub-aba e o que este usuário pode fazer. */
function pintarRodapeDefesa() {
  const d = dFicha.doc?.defesa || {}
  const g = id => document.getElementById(id)
  g('def-salvar').hidden = !(defState.aba === 'dados' && d.pode_protocolar)
  g('def-salvar').textContent = d.protocolo ? 'Salvar protocolo' : 'Registrar defesa'
  g('def-julgar').hidden = !(defState.aba === 'julgamento' && d.pode_julgar)
}

/** Manda um formulário (com arquivo) à API da defesa e devolve a resposta. */
async function enviarDefesaDoc(url, campos, arquivo) {
  const corpo = new FormData()
  for (const [k, v] of Object.entries(campos)) corpo.append(k, v)
  if (arquivo) corpo.append('anexo', arquivo, arquivo.name)
  const r = await fetch(url, { method: 'POST', headers: cabecalhoDoc(), body: corpo })
  const d = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(d.errors ? Object.values(d.errors)[0][0] : (d.message || 'HTTP ' + r.status))
  return d
}

/** Depois de registrar: a ficha é relida, porque o status e as opções mudaram. */
async function recarregarDefesaDoc(mensagem) {
  toast(mensagem)
  const r = await fetch('/api/documentos/' + dFicha.doc.id, { headers: { Accept: 'application/json' } })
  if (r.ok) {
    dFicha.doc = await r.json()
    dFicha.opcoes = dFicha.doc.opcoes || []
    // Com o formulário da peça aberto por baixo, o cabeçalho acompanha.
    if (typeof fdState !== 'undefined' && fdState.id === dFicha.doc.id && typeof renderCabecalhoDoc === 'function') {
      renderCabecalhoDoc(dFicha.doc)
    }
  }
  pintarDefesaDoc()
  trocarAbaDefesa(defState.aba)
  carregarDocumentos()
}

/** Registra (ou corrige) o protocolo da defesa. */
async function salvarDefesaDoc() {
  const g = id => document.getElementById(id)
  const protocolo = g('def-protocolo').value.trim(), data = g('def-data-protocolo').value
  if (!protocolo) { toast('Informe o número do protocolo da defesa', 'err'); g('def-protocolo').focus(); return }
  if (!data) { toast('Informe a data do protocolo', 'err'); return }
  await comCarregando('Registrando a defesa…', async () => {
    try {
      const d = await enviarDefesaDoc(`/api/documentos/${dFicha.doc.id}/defesa`,
        { protocolo, data_protocolo: data }, g('def-anexo').files?.[0])
      await recarregarDefesaDoc(d.message)
    } catch (e) { toast(e.message || 'Falha ao registrar a defesa', 'err') }
  })
}

/** Registra o julgamento. Pede confirmação: a decisão não se altera depois. */
function julgarDefesaDoc() {
  const g = id => document.getElementById(id)
  const resultado = g('def-resultado-deferida').checked ? 'deferida' : (g('def-resultado-indeferida').checked ? 'indeferida' : '')
  const data = g('def-data-resultado').value, parecer = g('def-parecer').value.trim()
  if (!resultado) { toast('Marque o resultado: deferida ou indeferida', 'err'); return }
  if (!data) { toast('Informe a data da decisão', 'err'); return }
  if (parecer.length < 10) { toast('Escreva o texto da decisão', 'err'); g('def-parecer').focus(); return }

  confirmarAcao({
    titulo: resultado === 'deferida' ? 'Deferir a defesa' : 'Indeferir a defesa',
    mensagem: (resultado === 'deferida'
      ? 'O documento passa a "defendido": deixa de valer e não gera custa ao contribuinte.'
      : 'O documento volta a lavrado, apto.')
      + ' A decisão não pode ser alterada depois de registrada.',
    textoBtn: resultado === 'deferida' ? 'Deferir' : 'Indeferir',
    onConfirm: async () => {
      const d = await enviarDefesaDoc(`/api/documentos/${dFicha.doc.id}/defesa/julgamento`,
        { resultado, data_resultado: data, parecer }, g('def-julg-anexo').files?.[0])
      await recarregarDefesaDoc(d.message)
    },
  })
}
