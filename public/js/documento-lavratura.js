// ═══════════════════════════════════════════════════════════════
// DOCUMENTO-LAVRATURA.JS — as assinaturas do ato
//
// A lavratura acontece no RESUMO da peça, como no AppPOSTURAS: o fiscal
// confere o documento, o autuado assina na tela — ou, se ele se recusar,
// registra-se a recusa com uma testemunha (nome e assinatura) — e só então o
// documento ganha número e fecha.
//
// TUDO ACONTECE NA PRÓPRIA FOLHA, à vista do autuado: a rubrica do fiscal no
// campo dele, o campo "assine aqui" em cima do nome do autuado, com a data e a
// hora da lavratura embaixo, e — antes dos anexos — a declaração de recusa,
// com a testemunha. Nada fica numa caixa à parte que o autuado não vê.
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
  /** @type {boolean} a rubrica do fiscal e as testemunhas já vieram do servidor */ carregou: false,
}

/** Os dois campos de assinatura desenhada: o canvas da folha e o de reserva (da tela). */
const PADS_LAVRATURA = { autuado: 'lav-autuado', testemunha: 'lav-testemunha-canvas' }
const PADS_NA_VIA = { autuado: 'lav-via-autuado', testemunha: 'lav-via-testemunha' }

/**
 * O canvas em que se assina: o que está DENTRO DA FOLHA, quando ela pôde
 * recebê-lo; senão, o da caixa de reserva, abaixo da folha.
 * @param {'autuado'|'testemunha'} qual @returns {HTMLCanvasElement|null}
 */
function canvasDoPad(qual) {
  return documentoDaVia()?.getElementById(PADS_NA_VIA[qual]) || document.getElementById(PADS_LAVRATURA[qual])
}

/** O documento de dentro da folha A4 do resumo, ou null se não der para alcançar. */
function documentoDaVia() {
  try { return document.querySelector('#nd-resumo .rs-a4-quadro')?.contentDocument || null } catch (_) { return null }
}

// Tudo o que a lavratura põe na folha leva a classe lav-via-el, para sair
// inteiro se a lavratura for cancelada. Medidas em pixels DA FOLHA: no celular
// ela aparece encolhida, por isso os alvos de toque são generosos.
const CSS_ASSINATURA_NA_VIA = `
  table.assina.assinando, table.assina.assinando tbody, table.assina.assinando tr { display: block; width: 100%; }
  table.assina.assinando td { display: block; width: 100%; box-sizing: border-box; padding-top: 14px; }
  .lav-via-pad { display: block; width: 100%; height: 150px; box-sizing: border-box; background: rgba(250,204,21,.16);
    border: 1.5px dashed #B58900; border-radius: 6px; touch-action: none; cursor: crosshair; }
  .lav-via-pad.falta { border: 2px solid #C0392B; background: rgba(192,57,43,.08); }
  .lav-via-dica { font-size: 9px; font-weight: bold; color: #7A5C00; text-align: left; margin-bottom: 3px; letter-spacing: .04em; }
  .lav-via-pe { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 4px;
    font-size: 9.5px; color: #333; text-align: left; }
  .lav-via-pe b { font-size: 10.5px; }
  .lav-via-btn { font: inherit; font-size: 10px; padding: 5px 12px; border: 1px solid #999; border-radius: 999px;
    background: #fff; color: #333; cursor: pointer; }
  .lav-via-fiscal { max-height: 62px; max-width: 100%; }
  .lav-via-sem-fiscal { font-size: 10px; color: #C0392B; border: 1px dashed #C0392B; border-radius: 6px; padding: 10px; margin-bottom: 3px; }
  td.sem-campo .lav-via-el { display: none; }
  td.sem-campo .assina-vazio { display: block !important; }
  .lav-via-recusa { margin: 4px 0 12px; padding: 10px 12px; border: 1px solid #999; border-radius: 6px; text-align: left; font-size: 11px; }
  .lav-via-recusa label.lav-via-marca { display: flex; align-items: center; gap: 9px; font-weight: bold; cursor: pointer; }
  .lav-via-recusa input[type=checkbox] { width: 20px; height: 20px; margin: 0; flex: none; }
  .lav-via-recusa .campos { margin-top: 10px; }
  .lav-via-recusa .campos[hidden] { display: none; }
  .lav-via-recusa .rot { display: block; font-size: 8.5px; font-weight: bold; color: #555; letter-spacing: .04em; margin: 8px 0 3px; }
  .lav-via-recusa select, .lav-via-recusa input[type=text] { width: 100%; box-sizing: border-box; font: inherit; font-size: 12px;
    padding: 8px; border: 1px solid #999; border-radius: 5px; background: #fff; color: #111; }
  .lav-via-recusa input[type=text] { text-transform: uppercase; }
  .lav-via-recusa [hidden] { display: none; }
`

/**
 * Monta a lavratura DENTRO DA FOLHA: a rubrica do fiscal no campo dele, o
 * "assine aqui" do autuado com a data e a hora embaixo, e a declaração de
 * recusa (com a testemunha) logo depois das assinaturas, antes dos anexos.
 *
 * Chamada ao abrir a lavratura, quando os dados do fiscal chegam e sempre que
 * a folha é recarregada — pode ser chamada quantas vezes for. Se a folha não
 * tiver as células (ou não puder ser alcançada), aparece a caixa de reserva.
 *
 * @returns {boolean} se a lavratura está na folha
 */
function montarAssinaturaNaVia() {
  const g = id => document.getElementById(id)
  // A folha ainda está chegando: espera, sem mostrar a reserva à toa.
  if (document.querySelector('#nd-resumo .rs-a4-aviso')) return false
  const doc = documentoDaVia()
  const celula = doc?.querySelector('[data-assina="autuado"]')
  const naVia = Boolean(celula)
  g('nd-lavratura').hidden = naVia
  g('lav-autuado-dica').hidden = true
  g('lav-autuado-caixa').hidden = false
  if (!naVia) return false

  const novo = (tag, classe, texto) => {
    const el = doc.createElement(tag)
    el.className = 'lav-via-el' + (classe ? ' ' + classe : '')
    if (texto != null) el.textContent = texto
    return el
  }

  if (!doc.getElementById('lav-via-autuado')) {
    const estilo = novo('style')
    estilo.textContent = CSS_ASSINATURA_NA_VIA
    doc.head.appendChild(estilo)
    const tabela = celula.closest('table')
    tabela.classList.add('assinando')

    // O AUTUADO: "assine aqui", o campo e, no pé, a data e a hora do ato.
    const vazio = celula.querySelector('.assina-vazio, .assina-img')
    if (vazio) vazio.style.display = 'none'
    const canvas = novo('canvas', 'lav-via-pad')
    canvas.id = 'lav-via-autuado'
    const pe = novo('div', 'lav-via-pe')
    pe.innerHTML = '<span>Lavratura em <b id="lav-via-quando"></b></span>'
    const limpar = novo('button', 'lav-via-btn', 'Limpar')
    limpar.type = 'button'
    limpar.addEventListener('click', () => limparPadLavratura('autuado'))
    pe.appendChild(limpar)
    celula.insertBefore(pe, celula.firstChild)
    celula.insertBefore(canvas, pe)
    celula.insertBefore(novo('div', 'lav-via-dica', 'ASSINE AQUI'), canvas)

    // A RECUSA, logo depois das assinaturas — antes do título dos anexos.
    const recusa = novo('div', 'lav-via-recusa')
    recusa.id = 'lav-via-recusa'
    recusa.innerHTML = `
      <label class="lav-via-marca"><input type="checkbox" id="lav-via-recusou"> Declaro que o autuado se recusou a assinar</label>
      <div class="campos" id="lav-via-campos" hidden>
        <span class="rot">TESTEMUNHA</span>
        <select id="lav-via-testemunha-sel"></select>
        <input type="text" id="lav-via-testemunha-nome" maxlength="120" placeholder="NOME COMPLETO DA TESTEMUNHA" hidden style="margin-top:6px">
        <span class="rot">ASSINATURA DA TESTEMUNHA</span>
        <canvas class="lav-via-pad" id="lav-via-testemunha"></canvas>
        <div class="lav-via-pe"><span></span><button type="button" class="lav-via-btn" id="lav-via-limpar-test">Limpar</button></div>
      </div>`
    tabela.parentNode.insertBefore(recusa, tabela.nextSibling)

    // Os controles da folha escrevem nos campos de reserva, que continuam
    // sendo a fonte do que o Confirmar lê.
    const marca = doc.getElementById('lav-via-recusou')
    marca.checked = g('lav-recusa').checked
    marca.addEventListener('change', () => { g('lav-recusa').checked = marca.checked; alternarRecusaLavratura() })
    const sel = doc.getElementById('lav-via-testemunha-sel')
    sel.addEventListener('change', () => { g('lav-testemunha').value = sel.value; alternarRecusaLavratura() })
    const nome = doc.getElementById('lav-via-testemunha-nome')
    nome.addEventListener('input', () => { g('lav-testemunha-outro').value = nome.value.toUpperCase() })
    doc.getElementById('lav-via-limpar-test').addEventListener('click', () => limparPadLavratura('testemunha'))
    delete lavState.pads.autuado
    delete lavState.pads.testemunha
  }

  // O que muda entre uma chamada e outra: a data, a rubrica e a lista de testemunhas.
  doc.getElementById('lav-via-quando').textContent =
    (lavState.quando || new Date()).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })

  // O FISCAL: a rubrica do cadastro dele, no campo dele.
  const doFiscal = doc.querySelector('[data-assina="agente"]')
  if (doFiscal && lavState.carregou) {
    doFiscal.querySelectorAll('.lav-via-fiscal, .lav-via-sem-fiscal').forEach(el => el.remove())
    const lugar = doFiscal.querySelector('.assina-vazio, .assina-img')
    if (lugar) lugar.style.display = 'none'
    let el
    if (lavState.fiscal) {
      el = novo('img', 'lav-via-fiscal')
      el.src = lavState.fiscal
      el.alt = 'Assinatura do fiscal'
    } else {
      el = novo('div', 'lav-via-sem-fiscal', 'Sem assinatura cadastrada — desenhe a sua em Meu perfil › Assinatura para poder lavrar.')
    }
    doFiscal.insertBefore(el, doFiscal.firstChild)
  }

  const sel = doc.getElementById('lav-via-testemunha-sel')
  const escolhida = g('lav-testemunha').value
  sel.innerHTML = g('lav-testemunha').innerHTML
  sel.value = escolhida

  aplicarRecusaNaVia()
  prepararPadLavratura('autuado')
  // A folha cresceu: a moldura acompanha.
  if (typeof ajustarViaA4NoResumo === 'function') ajustarViaA4NoResumo()
  return true
}

/** Mostra na folha o que a recusa pede: sai o campo do autuado, entra a testemunha. */
function aplicarRecusaNaVia() {
  const doc = documentoDaVia()
  if (!doc?.getElementById('lav-via-recusa')) return
  const g = id => document.getElementById(id)
  const recusa = g('lav-recusa').checked
  doc.getElementById('lav-via-recusou').checked = recusa
  doc.querySelector('[data-assina="autuado"]')?.classList.toggle('sem-campo', recusa)
  doc.getElementById('lav-via-campos').hidden = !recusa
  doc.getElementById('lav-via-testemunha-nome').hidden = g('lav-testemunha').value !== '__outro'
  if (typeof ajustarViaA4NoResumo === 'function') ajustarViaA4NoResumo()
}

/** Tira a lavratura da folha (cancelada): ela volta a ser só a via. */
function desmontarAssinaturaNaVia() {
  const doc = documentoDaVia()
  if (!doc?.getElementById('lav-via-autuado')) return
  doc.querySelectorAll('.lav-via-el').forEach(el => el.remove())
  doc.querySelectorAll('[data-assina]').forEach(celula => {
    celula.classList.remove('sem-campo')
    const lugar = celula.querySelector('.assina-vazio, .assina-img')
    if (lugar) lugar.style.display = ''
  })
  doc.querySelector('table.assina.assinando')?.classList.remove('assinando')
  if (typeof ajustarViaA4NoResumo === 'function') ajustarViaA4NoResumo()
}

/** Rola a tela até o campo de assinatura da folha. @param {'autuado'|'testemunha'} [qual] */
function irAoCampoDaVia(qual = 'autuado') {
  const canvas = documentoDaVia()?.getElementById(PADS_NA_VIA[qual])
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

/** Abre a lavratura no Resumo. Chamada por lavrarDocumento(), já com a peça conferida. */
async function abrirAreaLavratura() {
  fdState.lavrando = true
  irAbaDoc('resumo')

  const g = id => document.getElementById(id)
  // A caixa de baixo é reserva: só aparece se a folha não receber os campos.
  g('nd-lavratura').hidden = true
  g('lav-recusa').checked = false
  g('lav-testemunha-outro').value = ''
  lavState.quando = new Date()
  lavState.carregou = false
  g('lav-quando').textContent = lavState.quando.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })

  // Testemunhas possíveis e a rubrica do fiscal vêm juntas, de uma vez.
  let d = { usuarios: [], minha_assinatura: null }
  try {
    const r = await fetch('/api/documentos/testemunhas', { headers: { Accept: 'application/json' } })
    if (r.ok) d = await r.json()
  } catch (_) { /* sem lista: a testemunha pode ser digitada */ }

  lavState.fiscal = d.minha_assinatura
  lavState.carregou = true
  const fiscal = g('lav-fiscal')
  fiscal.classList.toggle('falta', !lavState.fiscal)
  fiscal.innerHTML = lavState.fiscal
    ? `<img src="${esc(lavState.fiscal)}" alt="Assinatura do fiscal">`
    : 'Sem assinatura cadastrada — desenhe a sua em Meu perfil › Assinatura para poder lavrar.'

  g('lav-testemunha').innerHTML = '<option value="">Selecione…</option>'
    + d.usuarios.map(u => `<option value="${esc(u.nome)}">${esc(u.nome)}${u.matricula ? ' — mat. ' + esc(u.matricula) : ''}</option>`).join('')
    + '<option value="__outro">Outra pessoa (digitar o nome)</option>'

  alternarRecusaLavratura()
  toast('As assinaturas ficam no fim da folha: o autuado assina acima do nome dele.')
  // Os canvas só têm tamanho depois de aparecer na tela.
  // (setTimeout, e não requestAnimationFrame: com a aba do navegador em segundo
  // plano o quadro não chega, e o campo ficaria sem preparar.)
  setTimeout(() => {
    // SEM rolar até aqui: a tela fica na peça, que é o que o autuado lê
    // antes de assinar. Tudo o que é da lavratura está na própria folha.
    montarAssinaturaNaVia()
    Object.keys(PADS_LAVRATURA).forEach(prepararPadLavratura)
  }, 60)
}

/** Tira a lavratura da tela e descarta o que foi desenhado. Não altera o documento. */
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
 * assinatura dela. O nome digitado só aparece para "outra pessoa". Vale para
 * os campos da folha e para os de reserva.
 */
function alternarRecusaLavratura() {
  const g = id => document.getElementById(id)
  const recusa = g('lav-recusa').checked
  g('lav-autuado-caixa').classList.toggle('desligada', recusa)
  g('lav-recusa-bloco').hidden = !recusa
  g('lav-testemunha-outro-campo').hidden = g('lav-testemunha').value !== '__outro'
  aplicarRecusaNaVia()
  // Quem entrou em cena agora só tem tamanho a partir daqui.
  setTimeout(() => prepararPadLavratura(recusa ? 'testemunha' : 'autuado'), 60)
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
  const falta = (qual, msg) => { canvasDoPad(qual)?.classList.add('falta'); toast(msg, 'err'); irAoCampoDaVia(qual) }

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
