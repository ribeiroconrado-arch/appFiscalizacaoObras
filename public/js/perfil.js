// ══════════════════════════════════════════════
// MÓDULO: MEU PERFIL
//
// Senha e assinatura do próprio usuário. A assinatura é desenhada uma vez
// aqui e copiada para dentro de cada documento na lavratura — pedir que o
// agente redesenhe a cada auto só produziria rubricas diferentes entre si,
// que é o oposto do que uma assinatura deveria demonstrar.
// ══════════════════════════════════════════════

/** Estado do canvas de assinatura. */
const perfState = {
  ctx: null,
  desenhando: false,
  temTraco: false,
  ultimo: null,
}

async function abrirPerfil() {
  openModal('m-perfil')
  marcarTemaAtivo()
  subPerfil('dados')
  document.getElementById('pf-senha-atual').value = ''
  document.getElementById('pf-senha-nova').value = ''
  document.getElementById('pf-senha-conf').value = ''

  try {
    const r = await fetch('/api/perfil', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    mostrarAssinaturaAtual(d.assinatura)
  } catch (e) {
    console.error(e)
  }
}

/**
 * Troca a aba do perfil.
 *
 * O canvas só é medido quando a aba Assinatura entra em cena: medido enquanto
 * o painel está `display:none`, ele devolve largura zero e o traço sai
 * deslocado do cursor. Por isso a preparação acontece aqui, e não na abertura
 * do modal.
 *
 * @param {'dados'|'assinatura'} nome
 */
function subPerfil(nome) {
  document.querySelectorAll('#m-perfil .sub-abas button')
    .forEach(b => b.classList.toggle('at', b.dataset.pf === nome))
  document.querySelectorAll('#m-perfil .pf-painel')
    .forEach(p => p.classList.toggle('at', p.id === 'pf-' + nome))

  if (nome === 'assinatura') {
    setTimeout(prepararCanvas, 30)
  }
}

/** @param {string|null} dataUrl */
function mostrarAssinaturaAtual(dataUrl) {
  document.getElementById('pf-assinatura-atual').innerHTML = dataUrl
    ? `<div class="assina-salva">
         <span class="rot">Assinatura em uso</span>
         <img src="${dataUrl}" alt="Assinatura cadastrada">
       </div>`
    : `<div class="lista-vazia">Nenhuma assinatura cadastrada — os documentos que
       você lavrar sairão com a linha em branco para assinar à mão.</div>`
}

// ── CANVAS ───────────────────────────────────────────────────

/**
 * Ajusta a resolução interna do canvas à densidade da tela.
 *
 * Sem isso o traço fica borrado em aparelho retina: o canvas desenha na
 * resolução CSS e o navegador amplia depois. Aqui o bitmap nasce no tamanho
 * físico e o contexto é escalado, então a linha sai nítida.
 */
function prepararCanvas() {
  const c = document.getElementById('pf-canvas')
  const dpr = window.devicePixelRatio || 1
  const larguraCss = c.parentElement.clientWidth - 2

  // Altura acompanha a tela, com piso e teto: numa assinatura o que falta em
  // altura vira rubrica espremida. 150px fixos serviam quando isto dividia o
  // modal com senha e aparência; com a aba só para isto, cabe bem mais.
  const alturaCss = Math.max(180, Math.min(300, Math.round(window.innerHeight * 0.32)))

  c.style.width = larguraCss + 'px'
  c.style.height = alturaCss + 'px'
  c.width = Math.round(larguraCss * dpr)
  c.height = Math.round(alturaCss * dpr)

  const ctx = c.getContext('2d')
  ctx.scale(dpr, dpr)
  ctx.lineWidth = 2.2
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.strokeStyle = '#1B2A27'
  perfState.ctx = ctx
  perfState.temTraco = false

  if (!c.dataset.ligado) {
    ligarEventos(c)
    c.dataset.ligado = '1'
  }
}

/** @param {HTMLCanvasElement} c */
function ligarEventos(c) {
  const ponto = ev => {
    const r = c.getBoundingClientRect()
    return { x: ev.clientX - r.left, y: ev.clientY - r.top }
  }

  c.addEventListener('pointerdown', ev => {
    ev.preventDefault()
    c.setPointerCapture(ev.pointerId)
    perfState.desenhando = true
    perfState.ultimo = ponto(ev)
  })

  c.addEventListener('pointermove', ev => {
    if (!perfState.desenhando) return
    ev.preventDefault()
    const p = ponto(ev)
    const ctx = perfState.ctx
    ctx.beginPath()
    ctx.moveTo(perfState.ultimo.x, perfState.ultimo.y)
    ctx.lineTo(p.x, p.y)
    ctx.stroke()
    perfState.ultimo = p
    perfState.temTraco = true
  })

  const soltar = () => { perfState.desenhando = false }
  c.addEventListener('pointerup', soltar)
  c.addEventListener('pointercancel', soltar)
  c.addEventListener('pointerleave', soltar)
}

function limparAssinatura() {
  const c = document.getElementById('pf-canvas')
  perfState.ctx?.clearRect(0, 0, c.width, c.height)
  perfState.temTraco = false
}

async function salvarAssinatura() {
  if (!perfState.temTraco) {
    toast('Desenhe a assinatura antes de salvar', 'err')
    return
  }
  const dataUrl = document.getElementById('pf-canvas').toDataURL('image/png')
  const d = await postPerfil('/api/perfil/assinatura', { assinatura: dataUrl })
  if (d) {
    mostrarAssinaturaAtual(dataUrl)
    limparAssinatura()
  }
}

function removerAssinatura() {
  confirmarAcao({
    titulo: 'Remover assinatura',
    mensagem: 'Os documentos já lavrados mantêm a assinatura que foi aplicada neles. '
            + 'Os próximos sairão com a linha em branco.',
    textoBtn: 'Remover',
    perigo: true,
    onConfirm: async () => {
      const r = await fetch('/api/perfil/assinatura', {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      })
      const d = await r.json()
      if (!r.ok) { toast(d.message || 'Não foi possível remover', 'err'); return }
      toast(d.message)
      mostrarAssinaturaAtual(null)
    },
  })
}

// ── SENHA ────────────────────────────────────────────────────

async function salvarSenha() {
  const atual = document.getElementById('pf-senha-atual').value
  const nova = document.getElementById('pf-senha-nova').value
  const conf = document.getElementById('pf-senha-conf').value

  if (!atual || !nova) { exigirCampo('pf-senha-atual', 'Informe a senha atual e a nova.'); return }
  if (nova !== conf) { toast('A confirmação não confere com a nova senha', 'err'); return }

  const d = await postPerfil('/api/perfil/senha', {
    senha_atual: atual, senha: nova, senha_confirmation: conf,
  })
  if (d) {
    document.getElementById('pf-senha-atual').value = ''
    document.getElementById('pf-senha-nova').value = ''
    document.getElementById('pf-senha-conf').value = ''
  }
}

/** POST com o cabeçalho padrão desta tela. @returns {Object|null} */
async function postPerfil(url, corpo) {
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json', Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
      },
      body: JSON.stringify(corpo),
    })
    const d = await r.json()
    if (!r.ok) {
      const erro = d.errors ? Object.values(d.errors)[0][0] : d.message
      toast(erro || 'Não foi possível salvar', 'err')
      return null
    }
    toast(d.message)
    return d
  } catch (e) {
    console.error(e)
    toast('Falha de rede', 'err')
    return null
  }
}

// ── TEMA ─────────────────────────────────────────────────────
// Dois temas convivem porque todo componente lê token: trocar de tema é
// trocar um atributo no <html>, não recarregar folha de estilo nenhuma.
// A aplicação em si (paleta, ícones, cor da barra do sistema) mora em
// js/tema.js, que roda no <head>; aqui fica só o que é da tela de perfil.

/** @param {'institucional'|'f'} tema */
function escolherTema(tema) {
  aplicarTema(tema, true)
  marcarTemaAtivo()
  toast(tema === 'institucional' ? 'Tema institucional aplicado' : 'Tema âmbar aplicado')
}

/** Deixa selecionado o botão do tema em uso. */
function marcarTemaAtivo() {
  const atual = document.documentElement.getAttribute('data-tema') || 'institucional'
  for (const t of ['institucional', 'f']) {
    document.getElementById('tema-op-' + t)?.classList.toggle('sel', t === atual)
  }
}
