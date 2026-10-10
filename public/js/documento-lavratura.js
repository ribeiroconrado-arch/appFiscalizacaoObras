// ═══════════════════════════════════════════════════════════════
// DOCUMENTO-LAVRATURA.JS — as assinaturas do ato
//
// A lavratura acontece no RESUMO da peça, como no AppPOSTURAS: o fiscal
// confere o documento, o autuado assina na tela — ou, se ele se recusar,
// registra-se a recusa com uma testemunha (nome e assinatura) — e só então o
// documento ganha número e fecha.
//
// O AUTUADO ASSINA NA PRÓPRIA FOLHA: o campo é posto dentro da via A4 do
// resumo, em cima do nome dele, que é onde a assinatura vai sair impressa.
// Durante a lavratura as duas assinaturas ficam uma embaixo da outra, para o
// campo ter a largura da folha — lado a lado, no celular, não caberia um dedo.
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

/** Os dois campos de assinatura desenhada e os canvas de cada um (os da tela). */
const PADS_LAVRATURA = { autuado: 'lav-autuado', testemunha: 'lav-testemunha-canvas' }

/**
 * O canvas em que se assina. O do autuado é o que está DENTRO DA FOLHA, quando
 * ela pôde recebê-lo; senão, o da caixa de reserva, abaixo da folha.
 * @param {'autuado'|'testemunha'} qual @returns {HTMLCanvasElement|null}
 */
function canvasDoPad(qual) {
  if (qual === 'autuado') {
    const naVia = documentoDaVia()?.getElementById('lav-via-autuado')
    if (naVia) return naVia
  }
  return document.getElementById(PADS_LAVRATURA[qual])
}

/** O documento de dentro da folha A4 do resumo, ou null se não der para alcançar. */
function documentoDaVia() {
  try { return document.querySelector('#nd-resumo .rs-a4-quadro')?.contentDocument || null } catch (_) { return null }
}

const CSS_ASSINATURA_NA_VIA = `
  table.assina.assinando, table.assina.assinando tbody, table.assina.assinando tr { display: block; width: 100%; }
  table.assina.assinando td { display: block; width: 100%; padding-top: 14px; }
  #lav-via-autuado { display: block; width: 100%; height: 150px; box-sizing: border-box; background: rgba(250,204,21,.16);
    border: 1.5px dashed #B58900; border-radius: 6px; touch-action: none; cursor: crosshair; }
  #lav-via-autuado.falta { border: 2px solid #C0392B; background: rgba(192,57,43,.08); }
  .lav-via-dica { font-size: 9px; font-weight: bold; color: #7A5C00; text-align: left; margin-bottom: 3px; letter-spacing: .04em; }
  td.sem-campo #lav-via-autuado, td.sem-campo .lav-via-dica { display: none; }
  td.sem-campo .assina-vazio { display: block !important; }
`

/**
 * Põe o campo de assinatura do autuado dentro da folha, em cima do nome dele.
 * Chamada ao abrir a lavratura e sempre que a folha é recarregada. Se a folha
 * não tiver a célula (ou não puder ser alcançada), fica a caixa de reserva.
 * @returns {boolean} se o campo está na folha
 */
function montarAssinaturaNaVia() {
  const g = id => document.getElementById(id)
  const doc = documentoDaVia()
  const celula = doc?.querySelector('[data-assina="autuado"]')
  const naVia = Boolean(celula)
  g('lav-autuado-dica').hidden = !naVia
  g('lav-autuado-caixa').hidden = naVia
  if (!naVia) return false

  if (!doc.getElementById('lav-via-autuado')) {
    const estilo = doc.createElement('style')
    estilo.textContent = CSS_ASSINATURA_NA_VIA
    doc.head.appendChild(estilo)
    celula.closest('table')?.classList.add('assinando')
    const vazio = celula.querySelector('.assina-vazio, .assina-img')
    if (vazio) vazio.style.display = 'none'
    const dica = doc.createElement('div')
    dica.className = 'lav-via-dica'
    dica.textContent = 'ASSINE AQUI'
    const canvas = doc.createElement('canvas')
    canvas.id = 'lav-via-autuado'
    celula.insertBefore(canvas, celula.firstChild)
    celula.insertBefore(dica, canvas)
    delete lavState.pads.autuado
    // A folha cresceu: a moldura acompanha.
    if (typeof ajustarViaA4NoResumo === 'function') ajustarViaA4NoResumo()
  }
  celula.classList.toggle('sem-campo', g('lav-recusa').checked)
  prepararPadLavratura('autuado')
  return true
}

/** Tira o campo da folha (lavratura cancelada): ela volta a ser só a via. */
function desmontarAssinaturaNaVia() {
  const doc = documentoDaVia()
  const canvas = doc?.getElementById('lav-via-autuado')
  if (!canvas) return
  const celula = canvas.parentElement
  celula.querySelector('.lav-via-dica')?.remove()
  canvas.remove()
  celula.classList.remove('sem-campo')
  celula.closest('table')?.classList.remove('assinando')
  const vazio = celula.querySelector('.assina-vazio, .assina-img')
  if (vazio) vazio.style.display = ''
  if (typeof ajustarViaA4NoResumo === 'function') ajustarViaA4NoResumo()
}

/** Rola a tela até o campo de assinatura da folha. */
function irAoCampoDaVia() {
  const canvas = documentoDaVia()?.getElementById('lav-via-autuado')
  const quadro = document.querySelector('#nd-resumo .rs-a4-quadro')
  if (!canvas || !quadro) return
  // A folha está encolhida ou ampliada por transform: a posição do campo na
  // tela é a de dentro da folha vezes a escala.
  const q = quadro.getBoundingClientRect()
  const escala = q.width / quadro.offsetWidth
  const topo = q.top + canvas.getBoundingClientRect().top * escala
  // Quem rola é o primeiro ancestral com barra de rolagem.
  let rolavel = quadro.parentElement
  while (rolavel && !(rolavel.scrollHeight > rolavel.clientHeight + 4 && /auto|scroll/.test(getComputedStyle(rolavel).overflowY))) rolavel = rolavel.parentElement
  if (!rolavel) rolavel = document.scrollingElement
  rolavel.scrollBy({ top: topo - window.innerHeight * 0.3, behavior: 'smooth' })
}

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
    // antes de assinar. O campo dele está na própria folha.
    montarAssinaturaNaVia()
    Object.keys(PADS_LAVRATURA).forEach(prepararPadLavratura)
  }, 60)
}

/** Esconde a área e descarta o que foi desenhado. Não altera o documento. */
function fecharAreaLavratura() {
  const area = document.getElementById('nd-lavratura')
  if (area) area.hidden = true
  desmontarAssinaturaNaVia()
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
  g('lav-autuado-dica').classList.toggle('desligada', recusa)
  // Com recusa, o campo sai da folha: quem assina é a testemunha.
  documentoDaVia()?.querySelector('[data-assina="autuado"]')?.classList.toggle('sem-campo', recusa)
  if (!recusa) setTimeout(() => prepararPadLavratura('autuado'), 60)
  if (typeof ajustarViaA4NoResumo === 'function') ajustarViaA4NoResumo()
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
  const canvas = canvasDoPad(qual)
  if (!canvas || !canvas.clientWidth) return
  // O campo da folha é ampliado ou encolhido junto com ela: nasce com folga
  // de pixels, para o traço não sair serrilhado no papel.
  const dpr = Math.max(window.devicePixelRatio || 1, canvas.ownerDocument === document ? 1 : 2.5)
  const largura = Math.round(canvas.clientWidth * dpr), altura = Math.round(canvas.clientHeight * dpr)
  const atual = lavState.pads[qual]
  if (atual && atual.canvas === canvas && canvas.width === largura && canvas.height === altura) return

  canvas.width = largura
  canvas.height = altura
  const ctx = canvas.getContext('2d')
  ctx.scale(dpr, dpr)
  ctx.lineWidth = canvas.ownerDocument === document ? 2.2 : 3
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
  const falta = (qual, msg) => { canvasDoPad(qual)?.classList.add('falta'); toast(msg, 'err'); if (qual === 'autuado') irAoCampoDaVia() }

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
