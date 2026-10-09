// ═══════════════════════════════════════════════════════════════
// DOCUMENTO-LAVRATURA.JS — as assinaturas do ato
//
// A lavratura acontece no RESUMO da peça, como no AppPOSTURAS: o fiscal
// confere o documento, o autuado assina na tela — ou, se ele se recusar,
// registra-se a recusa com uma testemunha (nome e assinatura) — e só então o
// documento ganha número e fecha.
//
// A assinatura do fiscal não é desenhada aqui: vem do cadastro dele (Meu
// perfil › Assinatura), para ser a mesma em toda peça.
//
// Depende de documento-form.js (fdState, irAbaDoc, renderRodapeDoc,
// voltarAFicha) e de ui.js (toast, confirmarAcao, esc).
// ═══════════════════════════════════════════════════════════════

const lavState = {
  /** @type {Object<string, {canvas:HTMLCanvasElement, ctx:CanvasRenderingContext2D, temTraco:boolean, desenhando:boolean, ultimo:{x:number,y:number}|null}>} */
  pads: {},
  /** @type {string|null} a rubrica do fiscal, do cadastro dele */ fiscal: null,
  /** @type {Date|null} */ quando: null,
}

/** Os dois campos de assinatura desenhada e os canvas de cada um. */
const PADS_LAVRATURA = { autuado: 'lav-autuado', testemunha: 'lav-testemunha-canvas' }

// ── ABRIR E FECHAR ───────────────────────────────────────────

/** Abre a área de lavratura no Resumo. Chamada por lavrarDocumento(), já com a peça conferida. */
async function abrirAreaLavratura() {
  fdState.lavrando = true
  irAbaDoc('resumo')

  const g = id => document.getElementById(id)
  g('nd-lavratura').hidden = false
  g('lav-recusa').checked = false
  g('lav-testemunha-outro').value = ''
  lavState.quando = new Date()
  g('lav-quando').textContent = lavState.quando.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })

  // Testemunhas possíveis e a rubrica do fiscal vêm juntas, de uma vez.
  let d = { usuarios: [], minha_assinatura: null }
  try {
    const r = await fetch('/api/documentos/testemunhas', { headers: { Accept: 'application/json' } })
    if (r.ok) d = await r.json()
  } catch (_) { /* sem lista: a testemunha pode ser digitada */ }

  lavState.fiscal = d.minha_assinatura
  const fiscal = g('lav-fiscal')
  fiscal.classList.toggle('falta', !lavState.fiscal)
  fiscal.innerHTML = lavState.fiscal
    ? `<img src="${esc(lavState.fiscal)}" alt="Assinatura do fiscal">`
    : 'Sem assinatura cadastrada — desenhe a sua em Meu perfil › Assinatura para poder lavrar.'

  g('lav-testemunha').innerHTML = '<option value="">Selecione…</option>'
    + d.usuarios.map(u => `<option value="${esc(u.nome)}">${esc(u.nome)}${u.matricula ? ' — mat. ' + esc(u.matricula) : ''}</option>`).join('')
    + '<option value="__outro">Outra pessoa (digitar o nome)</option>'

  alternarRecusaLavratura()
  // Os canvas só têm tamanho depois de aparecer na tela.
  // (setTimeout, e não requestAnimationFrame: com a aba do navegador em segundo
  // plano o quadro não chega, e o campo ficaria sem preparar.)
  setTimeout(() => {
    // SEM rolar até aqui: a tela fica na peça, que é o que o autuado lê
    // antes de assinar. As assinaturas vêm logo abaixo dela.
    Object.keys(PADS_LAVRATURA).forEach(prepararPadLavratura)
  }, 60)
}

/** Esconde a área e descarta o que foi desenhado. Não altera o documento. */
function fecharAreaLavratura() {
  const area = document.getElementById('nd-lavratura')
  if (area) area.hidden = true
  lavState.pads = {}
}

/** Botão Cancelar: desiste da lavratura; a peça continua rascunho. */
function cancelarLavraturaDoc() {
  fdState.lavrando = false
  fecharAreaLavratura()
  renderRodapeDoc()
}

/**
 * Recusa marcada: some a assinatura do autuado e entram a testemunha e a
 * assinatura dela. O nome digitado só aparece para "outra pessoa".
 */
function alternarRecusaLavratura() {
  const g = id => document.getElementById(id)
  const recusa = g('lav-recusa').checked
  g('lav-autuado-caixa').classList.toggle('desligada', recusa)
  g('lav-recusa-bloco').hidden = !recusa
  g('lav-testemunha-outro-campo').hidden = g('lav-testemunha').value !== '__outro'
  // A testemunha entrou em cena agora: o canvas dela só tem tamanho a partir daqui.
  if (recusa) setTimeout(() => prepararPadLavratura('testemunha'), 60)
}

// ── OS CAMPOS DE ASSINATURA ──────────────────────────────────

/**
 * Prepara um canvas para receber a assinatura. O bitmap nasce no tamanho
 * FÍSICO da tela (devicePixelRatio): sem isso o traço sai borrado em
 * celular. Chamado de novo, mantém o que já foi desenhado só se o tamanho não
 * mudou — canvas redimensionado apaga o conteúdo de qualquer jeito.
 * @param {'autuado'|'testemunha'} qual
 */
function prepararPadLavratura(qual) {
  const canvas = document.getElementById(PADS_LAVRATURA[qual])
  if (!canvas || !canvas.clientWidth) return
  const dpr = window.devicePixelRatio || 1
  const largura = Math.round(canvas.clientWidth * dpr), altura = Math.round(canvas.clientHeight * dpr)
  const atual = lavState.pads[qual]
  if (atual && atual.canvas === canvas && canvas.width === largura && canvas.height === altura) return

  canvas.width = largura
  canvas.height = altura
  const ctx = canvas.getContext('2d')
  ctx.scale(dpr, dpr)
  ctx.lineWidth = 2.2
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.strokeStyle = '#1B2A27'
  const pad = lavState.pads[qual] = { canvas, ctx, temTraco: false, desenhando: false, ultimo: null }
  canvas.classList.remove('falta')

  if (canvas.dataset.ligado) return
  canvas.dataset.ligado = '1'
  const ponto = ev => { const r = canvas.getBoundingClientRect(); return { x: ev.clientX - r.left, y: ev.clientY - r.top } }
  // Os ouvintes leem o pad VIGENTE: o canvas é o mesmo a cada lavratura.
  const vigente = () => lavState.pads[qual]
  canvas.addEventListener('pointerdown', ev => {
    const p = vigente(); if (!p) return
    ev.preventDefault()
    canvas.setPointerCapture(ev.pointerId)
    p.desenhando = true
    p.ultimo = ponto(ev)
  })
  canvas.addEventListener('pointermove', ev => {
    const p = vigente(); if (!p?.desenhando) return
    ev.preventDefault()
    const agora = ponto(ev)
    p.ctx.beginPath()
    p.ctx.moveTo(p.ultimo.x, p.ultimo.y)
    p.ctx.lineTo(agora.x, agora.y)
    p.ctx.stroke()
    p.ultimo = agora
    p.temTraco = true
    canvas.classList.remove('falta')
  })
  const soltar = () => { const p = vigente(); if (p) p.desenhando = false }
  canvas.addEventListener('pointerup', soltar)
  canvas.addEventListener('pointercancel', soltar)
  canvas.addEventListener('pointerleave', soltar)
  void pad
}

/** @param {'autuado'|'testemunha'} qual */
function limparPadLavratura(qual) {
  const p = lavState.pads[qual]
  if (!p) return
  p.ctx.clearRect(0, 0, p.canvas.width, p.canvas.height)
  p.temTraco = false
}

/** A assinatura desenhada, em PNG — ou null se o campo está em branco. @param {'autuado'|'testemunha'} qual */
function assinaturaDoPad(qual) {
  const p = lavState.pads[qual]
  return p?.temTraco ? p.canvas.toDataURL('image/png') : null
}

// ── CONFIRMAR ────────────────────────────────────────────────

/**
 * Confere o que o ato exige e lavra. As mesmas exigências estão no servidor
 * (DocumentoController::lavrar); aqui é só para o fiscal saber NA HORA o que
 * falta, com o campo marcado.
 */
function confirmarLavraturaDoc() {
  const g = id => document.getElementById(id)
  const recusa = g('lav-recusa').checked
  const falta = (qual, msg) => { g(PADS_LAVRATURA[qual]).classList.add('falta'); toast(msg, 'err') }

  if (!lavState.fiscal) { toast('Cadastre a sua assinatura em Meu perfil › Assinatura antes de lavrar', 'err'); return }

  const corpo = { recusa }
  if (recusa) {
    const sel = g('lav-testemunha').value
    const nome = (sel === '__outro' ? g('lav-testemunha-outro').value : sel).trim()
    if (!nome) { toast('Selecione ou informe a testemunha da recusa', 'err'); return }
    const assinatura = assinaturaDoPad('testemunha')
    if (!assinatura) { falta('testemunha', 'Colha a assinatura da testemunha'); return }
    corpo.testemunha_nome = nome
    corpo.assinatura_testemunha = assinatura
  } else {
    const assinatura = assinaturaDoPad('autuado')
    if (!assinatura) { falta('autuado', 'Colha a assinatura do autuado, ou marque a recusa'); return }
    corpo.assinatura_autuado = assinatura
  }

  confirmarAcao({
    titulo: 'Confirmar lavratura',
    mensagem: 'A lavratura atribui número definitivo, congela o prazo e fecha o documento '
            + 'para edição. Esta ação não pode ser desfeita — só anulada.'
            + (recusa ? ' Será registrada a recusa de assinatura, com a testemunha informada.' : ''),
    textoBtn: 'Lavrar',
    onConfirm: async () => {
      const [r, d] = await comCarregando('Lavrando o documento…', async () => {
        const r = await fetch(`/api/documentos/${fdState.id}/lavrar`, {
          method: 'POST',
          headers: { ...cabecalhoDoc(), 'Content-Type': 'application/json' },
          body: JSON.stringify(corpo),
        })
        return [r, await r.json().catch(() => ({}))]
      })
      if (!r.ok) throw new Error(d.message || Object.values(d.errors || {})[0]?.[0] || 'HTTP ' + r.status)
      toast(d.message)
      fdState.lavrando = false
      fecharAreaLavratura()
      // A PEÇA FICA ABERTA, já lavrada: é agora que o fiscal imprime a via do
      // autuado, e o menu de Opções tem de estar ali. Antes a janela fechava
      // e era preciso procurar o documento na lista para imprimir.
      const doc = await atualizarFichaDoc()
      if (doc) {
        await abrirFormDoc({ documento: doc })
        dFicha.opcoes = doc.opcoes || []
        irAbaDoc('resumo')
      } else {
        fModalBtn('m-doc')
      }
      carregarDocumentos()
    },
  })
}
