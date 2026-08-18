// ══════════════════════════════════════════════
// COMPONENTES DE UI
// Portados do AppPOSTURAS (components/toast.js, components/modals.js,
// components/ui.js). Mantidos com os mesmos nomes de função para que a
// migração para o Laravel seja recorte-e-cola, e para que quem já mexeu no
// outro sistema reconheça o código.
// ══════════════════════════════════════════════

/**
 * Mensagem curta e efêmera no rodapé. Substitui `alert()` em todo o sistema.
 * @param {string} msg
 * @param {'ok'|'err'} [tipo='ok']
 */
function toast(msg, tipo = 'ok') {
  const el = document.getElementById('toast')
  el.textContent = msg
  el.className = tipo === 'err' ? 'show err' : 'show'
  clearTimeout(el._t)
  el._t = setTimeout(() => { el.className = '' }, 3200)
}

/** Abre o modal e trava o scroll do body. @param {string} id */
function openModal(id) {
  document.getElementById(id).classList.add('open')
  document.body.style.overflow = 'hidden'
}

/** Fecha o modal e devolve o scroll. @param {string} id */
function fModalBtn(id) {
  const el = document.getElementById(id)
  if (!el) return
  el.classList.remove('open')
  document.body.style.overflow = ''
}

/**
 * Handler do clique no fundo do modal — intencionalmente vazio.
 * Clicar fora NÃO fecha, de propósito: evita perder dados já digitados.
 * Os modais só fecham pelo × ou por um botão "Fechar"/"Cancelar" explícito.
 */
function fModal() { /* vazio de propósito — ver comentário acima */ }

/** Ação pendente do modal genérico de confirmação. @type {Function|null} */
let _confirmAcao = null

/**
 * Confirmação antes de uma ação — substitui `confirm()` nativo em qualquer
 * módulo. O botão fica "Aguarde…" durante o await, e o modal só fecha depois
 * que a ação resolve.
 *
 * @param {{titulo?:string, mensagem?:string, textoBtn?:string, perigo?:boolean,
 *          onConfirm:() => (void|Promise<void>)}} opts
 */
function confirmarAcao({ titulo = 'Confirmar ação', mensagem = 'Tem certeza?',
                         textoBtn = 'Confirmar', perigo = false, onConfirm }) {
  document.getElementById('mcg-titulo').textContent = titulo
  document.getElementById('mcg-msg').textContent = mensagem
  const btn = document.getElementById('mcg-btn-ok')
  btn.textContent = textoBtn
  btn.className = 'btn ' + (perigo ? 'danger' : 'primary')
  btn.disabled = false
  btn.dataset.textoOriginal = textoBtn
  _confirmAcao = onConfirm
  openModal('m-confirm')
}

/** Handler do OK do modal genérico de confirmação. */
async function _mcgConfirmar() {
  if (!_confirmAcao) return
  const btn = document.getElementById('mcg-btn-ok')
  const acao = _confirmAcao
  btn.disabled = true
  btn.textContent = 'Aguarde...'
  try {
    await acao()
    fModalBtn('m-confirm')
  } finally {
    btn.disabled = false
    btn.textContent = btn.dataset.textoOriginal || 'Confirmar'
  }
}

/**
 * Overlay de tela cheia para transições que dependem de carga de dados.
 * Sempre usar em try/finally, para não deixar o overlay preso se a carga falhar.
 * @param {string} [txt]
 */
function mostrarCarregandoTela(txt = 'Carregando...') {
  document.getElementById('tela-carregando-txt').textContent = txt
  document.getElementById('tela-carregando').classList.add('show')
}

/** Esconde o overlay de carregamento. */
function esconderCarregandoTela() {
  document.getElementById('tela-carregando').classList.remove('show')
}

/** Escapa texto antes de injetar em innerHTML. @param {*} s @returns {string} */
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ))
}

/** Formata número com separador de milhar pt-BR. @param {number} n */
function fmtNum(n) {
  return Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 })
}

// ── DATA E HORA ──────────────────────────────────────────────
// Nunca usar `new Date().toISOString()` para "hoje"/"agora": o ISO converte
// para UTC e, no fuso de Cuiabá, das 20h em diante já devolve o dia seguinte.
// Uma vistoria feita às 21h ficaria registrada como do dia seguinte — bug que
// o AppPOSTURAS já pagou para aprender.

/** Data de hoje em aaaa-mm-dd, no fuso do aparelho. @returns {string} */
function dataHojeLocal() {
  const d = new Date()
  const p = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/** Hora atual em hh:mm, no fuso do aparelho. @returns {string} */
function horaAgoraLocal() {
  const d = new Date()
  const p = n => String(n).padStart(2, '0')
  return `${p(d.getHours())}:${p(d.getMinutes())}`
}

/**
 * Converte aaaa-mm-dd em dd/mm/aaaa sem passar por `new Date()`, que
 * interpretaria a string como UTC e poderia devolver o dia anterior.
 * @param {string} iso @returns {string}
 */
function formatarDataBR(iso) {
  if (!iso) return ''
  const [a, m, d] = iso.split('-')
  return `${d}/${m}/${a}`
}

/**
 * Atualiza o texto sobreposto ao <input type=date>. É esse span que mostra
 * dd/mm/aaaa; o input nativo fica com o texto transparente, preservando o
 * calendário do navegador sem exibir o formato do sistema operacional.
 * @param {HTMLInputElement} input
 */
function atualizarDisplayData(input) {
  const span = input.parentElement?.querySelector('.date-ov-txt')
  if (!span) return
  if (input.value) {
    span.textContent = formatarDataBR(input.value)
    span.classList.remove('vazio')
  } else {
    span.textContent = 'dd/mm/aaaa'
    span.classList.add('vazio')
  }
}

/** Pré-preenche com hoje ao focar, se vazio. @param {HTMLInputElement} i */
function preencherDataHojeSeVazio(i) {
  if (!i.value) { i.value = dataHojeLocal(); atualizarDisplayData(i) }
}

/** Pré-preenche com agora ao focar, se vazio. @param {HTMLInputElement} i */
function preencherHoraAgoraSeVazio(i) {
  if (!i.value) i.value = horaAgoraLocal()
}
