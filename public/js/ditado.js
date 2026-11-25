/**
 * DITADO POR VOZ (24/11/2026) — o reconhecimento de fala do próprio navegador
 * (Web Speech API), sem custo e sem chave, ACERTADO PARA O ANDROID.
 *
 * ── Por que duplicava ──
 * No Chrome do Android, com `continuous = true`, cada resultado novo vem com o
 * texto dos anteriores repetido (o mesmo trecho final chega duas, três vezes),
 * e somar os resultados escrevia "rua rua cuiabá rua cuiabá 500". Por isso:
 *  - NUNCA modo contínuo: cada SESSÃO ouve uma frase (`continuous = false`) e
 *    termina sozinha na pausa; enquanto o ditado estiver ligado, a sessão
 *    seguinte começa na hora (_reabrir);
 *  - de cada sessão entra só UM texto final — o último resultado final dela,
 *    uma vez (`entregue`);
 *  - entre sessões, o texto que repete o anterior (igual, ou começando por
 *    ele) é cortado (_semRepeticao);
 *  - o parcial (`interimResults`) só aparece na tela, por cima do texto, e
 *    nunca é gravado no campo.
 *
 * ── Onde aparece ──
 * Em TODO campo de texto longo (<textarea>): ao focar o campo surge o botão do
 * microfone no canto dele. O campo com `data-sem-ditado` fica de fora (o que
 * não é texto corrido); `data-ditado-maiusculas` escreve em maiúsculas (a
 * observação da denúncia, como no app antigo). O texto entra onde está o
 * cursor. Comandos falados: "vírgula", "ponto final", "ponto de
 * interrogação", "dois pontos", "nova linha", "parágrafo".
 *
 * Firefox não tem o reconhecimento: o botão não aparece. No iPhone o Safari
 * tem, mas pior; o microfone do próprio teclado continua valendo.
 */
const Ditado = (() => {
  const Reconhecedor = window.SpeechRecognition || window.webkitSpeechRecognition
  const SILENCIOS_MAX = 3   // sessões seguidas sem fala: o ditado desliga sozinho e solta o microfone

  /** Tira a repetição do Android: o que o texto novo repete do anterior. */
  function _semRepeticao(anterior, novo) {
    const a = anterior.trim().toLowerCase(), n = novo.trim()
    if (!a) return n
    const nl = n.toLowerCase()
    if (nl === a || a.endsWith(nl)) return ''
    if (nl.startsWith(a)) return n.slice(a.length).trim()
    // O fim do anterior repetido no começo do novo ("… rua cuiabá" + "cuiabá 500").
    const pa = a.split(/\s+/), pn = nl.split(/\s+/)
    for (let k = Math.min(pa.length, pn.length - 1); k >= 2; k--) {
      if (pa.slice(-k).join(' ') === pn.slice(0, k).join(' ')) return n.split(/\s+/).slice(k).join(' ')
    }
    return n
  }

  /** Os comandos falados viram pontuação. */
  function _pontuar(t) {
    return t
      .replace(/\s*\bponto de interroga[cç][aã]o\b/gi, '?')
      .replace(/\s*\bponto de exclama[cç][aã]o\b/gi, '!')
      .replace(/\s*\bponto final\b/gi, '.')
      .replace(/\s*\bdois pontos\b/gi, ':')
      .replace(/\s*\bv[ií]rgula\b/gi, ',')
      .replace(/\s*\b(nova linha|par[aá]grafo)\b\s*/gi, '\n')
  }

  /**
   * Ouve. Cada frase terminada vai a `aoFinal` uma vez; o que está sendo dito,
   * a `aoParcial`. Com `umaFrase`, para depois da primeira.
   * @param {{aoFinal:(t:string)=>void, aoParcial?:(t:string)=>void, aoFim?:(motivo:string)=>void, umaFrase?:boolean}} op
   * @returns {{parar:()=>void}|null} null se o navegador não ouve
   */
  function ouvir(op) {
    if (!Reconhecedor) return null
    let ligado = true, silencios = 0, ultimo = '', rec = null

    const abrir = () => {
      const r = new Reconhecedor()
      r.lang = 'pt-BR'
      r.continuous = false
      r.interimResults = true
      r.maxAlternatives = 1
      let entregue = false, falou = false
      r.onresult = e => {
        let final = null, parcial = ''
        for (let i = 0; i < e.results.length; i++) {
          if (e.results[i].isFinal) final = e.results[i][0].transcript
          else parcial = e.results[i][0].transcript
        }
        if (parcial || final) falou = true
        if (final != null && !entregue) {
          entregue = true
          const limpo = _semRepeticao(ultimo, final)
          if (final.trim()) ultimo = final.trim()
          op.aoParcial?.('')
          if (limpo) op.aoFinal(limpo)
          if (op.umaFrase) { ligado = false; try { r.stop() } catch {} }
        } else if (!entregue) {
          op.aoParcial?.(parcial)
        }
      }
      r.onerror = e => {
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
          ligado = false
          toast('Permita o microfone para ditar (no cadeado da barra de endereço).', 'err')
        } else if (e.error === 'audio-capture') {
          ligado = false
          toast('O microfone não respondeu. Outro aplicativo pode estar usando-o.', 'err')
        } else if (e.error === 'network') {
          ligado = false
          toast('Sem internet: o ditado do navegador precisa de conexão.', 'err')
        }
        // no-speech e aborted: a sessão termina e o onend decide.
      }
      r.onend = () => {
        op.aoParcial?.('')
        silencios = falou ? 0 : silencios + 1
        if (ligado && silencios < SILENCIOS_MAX) { setTimeout(() => { if (ligado) abrir() }, 60); return }
        ligado = false
        rec = null
        op.aoFim?.(silencios >= SILENCIOS_MAX ? 'silencio' : 'fim')
      }
      rec = r
      try { r.start() } catch { ligado = false; rec = null; op.aoFim?.('erro') }
    }
    abrir()
    return { parar: () => { ligado = false; try { rec?.stop() } catch {} } }
  }

  // ── O BOTÃO NOS CAMPOS DE TEXTO LONGO ──

  let botao = null, alvo = null, sessao = null, dica = null
  let base = { antes: '', depois: '' }

  const elegivel = el => el instanceof HTMLTextAreaElement && !el.readOnly && !el.disabled && !el.hasAttribute('data-sem-ditado')

  function _criarBotao() {
    botao = document.createElement('button')
    botao.type = 'button'
    botao.className = 'ditado-btn'
    botao.title = 'Ditar por voz'
    botao.setAttribute('aria-label', 'Ditar por voz')
    botao.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3"/></svg>'
    // pointerdown sem default: o campo não perde o foco (nem o teclado do celular some).
    botao.addEventListener('pointerdown', e => e.preventDefault())
    botao.addEventListener('click', () => { if (alvo) alternar(alvo) })
    botao.style.display = 'none'
    document.body.appendChild(botao)
    dica = document.createElement('div')
    dica.className = 'ditado-parcial'
    dica.hidden = true
    document.body.appendChild(dica)
  }

  /** Põe o botão no canto de baixo, à direita, do campo. */
  function _posicionar() {
    if (!botao || !alvo) return
    const r = alvo.getBoundingClientRect()
    const visivel = r.width > 0 && r.height > 0
    botao.hidden = !visivel
    if (!visivel) return
    botao.style.left = (r.right - 34) + 'px'
    botao.style.top = (r.bottom - 34) + 'px'
    if (!dica.hidden) {
      dica.style.left = r.left + 'px'
      dica.style.top = (r.bottom + 4) + 'px'
      dica.style.maxWidth = Math.max(160, r.width) + 'px'
    }
  }

  /** Escreve no campo, no lugar do cursor, e avisa quem escuta o campo. */
  function _escrever(campo, texto) {
    const maiusc = campo.hasAttribute('data-ditado-maiusculas')
    let t = _pontuar(texto.trim())
    if (maiusc) t = t.toUpperCase()
    const antes = base.antes
    // Maiúscula no começo da frase (campo vazio, ou depois de . ? ! ou linha nova).
    if (!maiusc && (!antes.trim() || /[.?!\n]\s*$/.test(antes))) t = t.charAt(0).toUpperCase() + t.slice(1)
    const sep = !antes || /[\s\n]$/.test(antes) || /^[,.?!:\n]/.test(t) ? '' : ' '
    let novo = antes + sep + t
    const max = campo.maxLength > 0 ? campo.maxLength : Infinity
    if (novo.length + base.depois.length > max) novo = novo.slice(0, Math.max(0, max - base.depois.length))
    campo.value = novo + base.depois
    base.antes = novo
    campo.setSelectionRange(novo.length, novo.length)
    campo.dispatchEvent(new Event('input', { bubbles: true }))
  }

  /** Liga ou desliga o ditado no campo. @param {HTMLTextAreaElement} campo */
  function alternar(campo) {
    if (sessao) { sessao.parar(); return }
    if (!Reconhecedor) { toast('Este navegador não dita por voz. Use o microfone do teclado do celular.', 'aviso'); return }
    if (!botao) _criarBotao()
    alvo = campo
    const p = campo.selectionStart ?? campo.value.length
    base = { antes: campo.value.slice(0, p), depois: campo.value.slice(campo.selectionEnd ?? p) }
    sessao = ouvir({
      aoFinal: t => _escrever(campo, t),
      aoParcial: t => { dica.textContent = t; dica.hidden = !t; _posicionar() },
      aoFim: motivo => {
        sessao = null
        campo.classList.remove('ditando')
        botao?.classList.remove('ouvindo')
        dica.hidden = true
        if (motivo === 'silencio') toast('Ditado desligado: nenhuma fala por alguns segundos.', 'aviso')
      },
    })
    if (!sessao) return
    campo.classList.add('ditando')
    botao?.classList.add('ouvindo')
    if (elegivel(campo)) { botao.style.display = ''; _posicionar() }
  }

  if (Reconhecedor) {
    document.addEventListener('focusin', e => {
      if (!elegivel(e.target)) return
      if (!botao) _criarBotao()
      if (sessao && alvo !== e.target) sessao.parar()
      alvo = e.target
      botao.classList.toggle('ouvindo', !!sessao)
      _posicionar()
      botao.style.display = ''
    })
    document.addEventListener('focusout', e => {
      if (e.target !== alvo || !botao) return
      // O botão some com o foco; ditando, fica (o celular pode fechar o teclado).
      setTimeout(() => { if (!sessao && document.activeElement !== alvo) botao.style.display = 'none' }, 150)
    })
    window.addEventListener('scroll', _posicionar, true)
    window.addEventListener('resize', _posicionar)
  }

  /** Para o ditado em curso, se houver (a tela que fecha solta o microfone). */
  function parar() { sessao?.parar() }

  return { ouvir, alternar, parar, disponivel: !!Reconhecedor, _semRepeticao, _pontuar }
})()
