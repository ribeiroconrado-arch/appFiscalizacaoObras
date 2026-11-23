/** Pesquisa livre e campos combinados; coordenadas continuam independentes. */
const pesqState = { opcoes: null, campos: {}, res: null, aviso: null, pedido: 0, ponto: null, coordenada: false, combinando: false, timer: null, abort: null, sugDigitando: false, sugAtivo: -1 }
const _pesqVal = id => document.getElementById(id)?.value.trim() ?? ''

/** Ancora à esquerda da lupa e limita o painel à área visível do mapa. */
function posicionarPesquisaMapa() {
  const painel = document.getElementById('pesq-barra')
  const mapa = document.getElementById('t-mapa')
  const botao = document.querySelector('#grupo-busca .ctrl-btn')
  if (!painel || painel.hidden || !mapa || !botao) return
  const r = mapa.getBoundingClientRect(), b = botao.getBoundingClientRect()
  const esquerda = Math.max(0, r.left) + 8
  const topo = Math.max(0, r.top) + 8
  const dock = window.innerWidth < 1280 ? document.querySelector('.abas')?.getBoundingClientRect().top : Infinity
  const base = Math.min(window.innerHeight, r.bottom, dock ?? Infinity) - 8
  const largura = Math.max(0, Math.min(400, b.left - 8 - esquerda))
  painel.style.width = largura + 'px'
  painel.style.maxHeight = Math.max(0, base - topo) + 'px'
  painel.style.left = (b.left - 8 - largura - r.left) + 'px'
  painel.style.top = (Math.max(topo, Math.min(b.top, base - painel.offsetHeight)) - r.top) + 'px'
}
window.addEventListener('resize', posicionarPesquisaMapa)
document.addEventListener('DOMContentLoaded', () => {
  const painel = document.getElementById('pesq-barra')
  const mapa = document.getElementById('t-mapa')
  const observer = new ResizeObserver(posicionarPesquisaMapa)
  if (painel) observer.observe(painel)
  if (mapa) observer.observe(mapa)
})

function abrirPesquisaMapa() {
  pedirFerramenta('busca', () => {
    fecharPaineisMapa()
    const barra = document.getElementById('pesq-barra')
    if (!barra) return
    // Fora da faixa central: o painel tem posição própria dentro do mapa.
    document.getElementById('t-mapa').appendChild(barra)
    barra.hidden = false
    pintarBarraPesquisa()
    document.querySelector('#grupo-busca .ctrl-btn')?.classList.add('at')
    _pesqCarregarOpcoes()
    // Reabrir mostra de novo o último resultado, sem ir ao servidor: a pesquisa
    // só sai pelo botão "Buscar".
    if (pesqState.res?.ids?.length) destacarLotes(pesqState.res.ids)
  })
}
function fecharPesquisaMapa() {
  clearTimeout(pesqState.timer)
  pesqState.abort?.abort()
  pesqState.pedido++
  const barra = document.getElementById('pesq-barra')
  if (barra) barra.hidden = true
  document.querySelector('#grupo-busca .ctrl-btn')?.classList.remove('at')
  if (typeof destacarLotes === 'function') destacarLotes(null)
  pesqState.ponto?.remove()
  pesqState.ponto = null
}
function alternarPesquisaMapa() {
  const barra = document.getElementById('pesq-barra')
  barra && !barra.hidden ? fecharPesquisaMapa() : abrirPesquisaMapa()
}
async function _pesqCarregarOpcoes() {
  if (pesqState.opcoes) return
  try {
    const r = await fetch('/api/mapa/pesquisa/opcoes', { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('Não foi possível carregar as sugestões.')
    pesqState.opcoes = await r.json()
    if (!document.getElementById('pesq-barra')?.hidden) pintarBarraPesquisa()
  } catch { /* Busca livre permanece disponível; reabrir permite tentar novamente. */ }
}
function pintarBarraPesquisa() {
  const barra = document.getElementById('pesq-barra')
  if (!barra) return
  const anterior = document.activeElement
  const foco = barra.contains(anterior) ? anterior.id : null
  const inicio = anterior?.selectionStart, fim = anterior?.selectionEnd
  const rolagem = barra.querySelector('.pesq-conteudo')?.scrollTop || 0
  const detalhes = [...barra.querySelectorAll('details[open]')].map(d => d.id)
  const s = pesqState, c = s.campos, o = s.opcoes || {}
  const campo = (nome, rotulo, extra = '', dica = '') => `<label class="pesq-direto-campo"><span>${rotulo}</span><input id="pd-${nome}" name="${nome}" value="${esc(c[nome] || '')}" ${extra} placeholder="${dica}" autocomplete="off"></label>`
  // data-combo: o COMBOBOX PADRÃO do sistema (ui.js, comboDeSelect). Sem ele
  // estes três ficavam de select nativo, por terem menos opções que o limite
  // que veste um select sozinho — eram os únicos campos de escolha do sistema
  // fora do padrão. Ver docs/ai/DESIGN-SYSTEM.md › Combobox.
  // Campo de texto LIVRE com a lista flutuante do sistema (.ac-wrap/.ac-list,
  // ver docs/ai/DESIGN-SYSTEM.md > Combobox). Não é <select>: o fiscal pode
  // digitar um pedaço do nome ("buritis") e pesquisar sem escolher da lista —
  // era o que o <datalist> fazia, só que com a cara do navegador, diferente do
  // resto do sistema.
  const campoLista = (nome, rotulo, fonte, extra, dica) => `<label class="pesq-direto-campo"><span>${rotulo}</span><div class="ac-wrap"><input id="pd-${nome}" name="${nome}" value="${esc(c[nome] || '')}" data-sugestao="${fonte}" role="combobox" aria-expanded="false" aria-autocomplete="list" ${extra} placeholder="${dica}" autocomplete="off"><button class="clr-btn" type="button" tabindex="-1" title="Limpar" ${c[nome] ? '' : 'hidden'}>&times;</button><div class="ac-list"></div></div></label>`
  const select = (nome, rotulo, opcoes) => `<label class="pesq-direto-campo"><span>${rotulo}</span><select id="pd-${nome}" name="${nome}" data-combo>${Object.entries(opcoes).map(([v, label]) => `<option value="${esc(v)}" ${String(c[nome] || '') === v ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label>`
  barra.innerHTML = `<div class="pesq-cabecalho"><h2>Pesquisar no mapa</h2><button type="button" data-pesq="fechar" aria-label="Fechar pesquisa">&#10005;</button></div>
    <div class="pesq-conteudo"><form id="pesq-direta">
    <div class="pesq-livre"><input id="pd-termo" name="termo" value="${esc(c.termo || '')}" maxlength="120" placeholder="Endereço, bairro ou inscrição…" aria-label="Pesquisa livre" autocomplete="off"><button type="button" class="btn" data-pesq="limpar">Limpar</button><button type="submit" class="btn primary">Buscar</button></div>
    <p class="pesq-dica">Digite um endereço, bairro ou inscrição.</p>
    <button type="button" class="pesq-combinar" data-pesq="combinar" aria-expanded="${s.combinando}" aria-controls="pesq-campos-diretos">Combinar campos <span>${s.combinando ? '−' : '+'}</span></button>
    <div id="pesq-campos-diretos" class="${s.combinando ? 'expandido' : ''}"><div class="pesq-direta-titulo">Pesquisar por campos <small>Preencha só o que souber</small></div>
    <div class="pesq-direta-grid">
    ${campoLista('bairro_texto', 'Bairro', 'bairros', 'maxlength="160"', 'Todos os bairros')}
    ${campo('inscricao', 'Inscrição imobiliária', 'maxlength="40"', 'Completa ou início')}
    <div class="pesq-linha-inteira">${campoLista('rua_texto', 'Rua / logradouro', 'ruas', 'maxlength="180"', 'Nome da rua')}</div>
    <div class="pesq-tres">${campo('numero_de', 'Número', 'type="number" min="0" inputmode="numeric"', 'Nº')}${campo('quadra_de', 'Quadra', 'type="number" min="0" inputmode="numeric"')}${campo('lote_de', 'Lote', 'type="number" min="0" inputmode="numeric"')}</div></div>
    <details id="pd-intervalos"><summary>Intervalos e lado da rua</summary><div class="pesq-direta-grid">
      ${campo('quadra_ate', 'Até a quadra', 'type="number" min="0" inputmode="numeric"')}${campo('lote_ate', 'Até o lote', 'type="number" min="0" inputmode="numeric"')}
      ${select('numero_modo', 'Número da rua', { '': 'Número exato', faixa: 'Intervalo', par: 'Lado par', impar: 'Lado ímpar' })}${campo('numero_ate', 'Até o número', 'type="number" min="0" inputmode="numeric"')}
    </div></details>
    ${Object.keys(o.pendencias || {}).length ? `<details id="pd-situacao"><summary>Situação e vistorias</summary><div class="pesq-direta-grid">${select('pendencia', 'Pendência', { '': 'Todas', ...o.pendencias, sem: 'Sem nenhuma pendência' })}${select('vistoria', 'Última vistoria', { '': 'Qualquer situação', ...o.vistorias })}</div></details>` : ''}
    </div>
    <div class="pesq-direta-pe"><span>Os campos preenchidos são combinados.</span></div>
    </form>
    <details id="pd-coordenada"><summary>Ir para coordenada</summary><div class="pesq-linha"><input id="pesq-coord" class="pesq-coord" aria-label="Coordenada" placeholder="Latitude, longitude ou UTM 21S"><button type="button" class="btn primary" data-pesq="ir-coordenada">Ir</button></div><p class="pesq-dica">Aceita -15.5129, -54.3138 · 15°30'46"S 54°18'49"W · UTM 788181, 8283132.</p><div id="pesq-coord-msg" hidden></div></details>
    <div id="pesq-resultado" class="pesq-resultado" aria-live="polite">${_pesqResultadoHtml()}</div></div>`
  detalhes.forEach(id => { const el = document.getElementById(id); if (el) el.open = true })
  if (foco) {
    const el = document.getElementById(foco)
    el?.focus({ preventScroll: true })
    if (el?.type === 'text' && inicio != null) el.setSelectionRange(inicio, fim)
  }
  barra.querySelector('.pesq-conteudo').scrollTop = rolagem
  posicionarPesquisaMapa()
}
function _pesqParametros() {
  const c = pesqState.campos, p = new URLSearchParams()
  for (const k of ['termo', 'bairro_texto', 'rua_texto', 'inscricao', 'quadra_de', 'quadra_ate', 'lote_de', 'lote_ate']) if (c[k]?.trim()) p.set(k, c[k].trim())
  for (const k of ['quadra', 'lote']) if (c[k + '_de'] && !c[k + '_ate']) p.set(k + '_ate', c[k + '_de'])
  if (['par', 'impar'].includes(c.numero_modo)) p.set('numero_modo', c.numero_modo)
  else if (c.numero_de || (c.numero_modo === 'faixa' && c.numero_ate)) {
    p.set('numero_modo', c.numero_modo || 'exato')
    if (c.numero_de) p.set('numero_de', c.numero_de)
    if (c.numero_modo === 'faixa' && c.numero_ate) p.set('numero_ate', c.numero_ate)
  }
  if (c.pendencia === 'sem') p.set('sem_pendencia', '1')
  else if (c.pendencia) p.append('pendencias[]', c.pendencia)
  if (c.vistoria) p.append('vistorias[]', c.vistoria)
  return p
}
function _pesqResultadoHtml() {
  const s = pesqState
  if (s.aviso) return `<div class="pesq-msg${s.aviso.erro ? ' pesq-erro' : ''}">${esc(s.aviso.texto)}</div>`
  if (!s.res) return '<div class="pesq-msg">Digite na busca livre ou preencha os campos e clique em Buscar.</div>'
  const r = s.res
  if (!r.total) return '<div class="pesq-msg">Nenhum imóvel combina com os critérios informados.</div>'
  return `<div class="pesq-msg">${r.total}${r.truncado ? '+' : ''} imóveis encontrados${r.truncado ? ' · refine a pesquisa' : ''}</div>
    ${r.imoveis.map(i => `<button type="button" class="pesq-imovel" data-pesq="ir" data-id="${Number(i.id)}"><strong>${esc(i.endereco || i.inscricao || 'Imóvel')}</strong><span>${esc(i.bairro || '')} · Q ${esc(i.quadra ?? '—')} · Lote ${esc(i.lote ?? '—')}</span><small>${esc(i.inscricao || '')}</small>${(i.pendencias || []).map(p => `<span class="pesq-tag">${esc(p)}</span>`).join('')}</button>`).join('')}
    ${r.total > r.imoveis.length ? `<div class="pesq-msg">Mostrando ${r.imoveis.length} na lista; ${r.total} destacados no mapa.</div>` : ''}`
}
function _pesqAtualizarResultado() {
  const el = document.getElementById('pesq-resultado')
  if (el) el.innerHTML = _pesqResultadoHtml()
}
function _pesqLerCampos() {
  const form = document.getElementById('pesq-direta')
  if (form) pesqState.campos = Object.fromEntries(new FormData(form))
}
/**
 * Um campo mudou: relê o formulário e cuida da lista de sugestão. NÃO
 * pesquisa — a pesquisa só sai pelo botão "Buscar" (ou Enter num campo, que é
 * o mesmo botão), a pedido dele em 22/11/2026: disparar a cada tecla mandava
 * ao servidor uma consulta por pedaço de palavra ("man", "mang", "manga"…),
 * pintava o mapa com resultados que ele não pediu e, no servidor pequeno,
 * empilhava consultas umas sobre as outras.
 *
 * Escuta 'input' E 'change'. O combobox padrão (comboDeSelect, em ui.js)
 * escreve no <select> escondido e avisa só por 'change' — sem ele, o valor
 * escolhido numa pendência não chegaria ao formulário lido no "Buscar".
 */
function _pesqCampoMudou(e) {
  if (!e.target.closest?.('#pesq-direta')) return
  // O texto que se digita DENTRO do combobox é filtro da lista dele, não um
  // campo da pesquisa: o valor só muda quando a opção é escolhida ('change').
  if (e.type === 'input' && e.target.classList?.contains('combo-entrada')) return
  if (e.type === 'input' && e.target.dataset?.sugestao) {
    pesqState.sugDigitando = true
    pesqState.sugAtivo = -1
    e.target.parentElement.querySelector('.clr-btn').hidden = !e.target.value
    _pesqPintarLista(e.target)
  }
  _pesqLerCampos()
}
// ── LISTA DE SUGESTÃO DOS CAMPOS DE TEXTO ────────────────────
//
// Mesmo contrato do combobox padrão (comboDeSelect, em ui.js): abre no foco com
// tudo, digitar filtra sem ligar para maiúscula nem acento, escolhe no
// mousedown (antes do blur), setas e Enter andam pela lista, × limpa. A
// diferença é que aqui o campo é texto livre — a lista sugere, não obriga.
//
// Por DELEGAÇÃO porque pintarBarraPesquisa() refaz o innerHTML da barra
// inteira, e ouvintes presos aos elementos morreriam a cada repintura.

/** As sugestões de um campo: o valor e o detalhe que vai em .ac-sub. */
function _pesqSugestoes(fonte) {
  const o = pesqState.opcoes || {}
  return fonte === 'bairros'
    ? (o.bairros || []).map(b => ({ valor: b.nome, sub: b.codigo ? 'Código ' + b.codigo : '' }))
    : (o.ruas || []).map(r => ({ valor: r.rua, sub: (r.bairros || []).join(' · ') }))
}

/** Por que a lista está vazia — a tela diz o motivo, não só "nada encontrado". */
function _pesqListaVazia(fonte, filtrou) {
  if (filtrou) {
    return fonte === 'bairros'
      ? 'Nenhum bairro com esse nome. O texto vale do mesmo jeito.'
      : 'Nenhuma rua com esse nome. O texto vale do mesmo jeito.'
  }
  return fonte === 'bairros'
    ? 'Nenhum bairro publicado ainda.'
    : 'Nenhuma rua disponível: o cadastro dos bairros publicados ainda não foi carregado.'
}

function _pesqFiltradas(inp) {
  const q = pesqState.sugDigitando ? _semAcento(inp.value.trim()) : ''
  return _pesqSugestoes(inp.dataset.sugestao).filter(x => !q || _semAcento(x.valor).includes(q))
}

/** O trecho digitado em destaque, como na variante de etiquetas do design system. */
function _pesqRealce(texto, q) {
  if (!q) return esc(texto)
  const i = _semAcento(texto).indexOf(_semAcento(q))
  return i < 0 ? esc(texto)
    : esc(texto.slice(0, i)) + '<mark>' + esc(texto.slice(i, i + q.length)) + '</mark>' + esc(texto.slice(i + q.length))
}

function _pesqPintarLista(inp) {
  const lista = inp.parentElement.querySelector('.ac-list')
  if (!lista) return
  // Uma lista de cada vez: ir de um campo a outro sem passar pelo foco
  // (clicar direto no outro) deixaria a anterior aberta por cima desta.
  document.querySelectorAll('#pesq-barra .ac-list.open').forEach(l => { if (l !== lista) l.classList.remove('open') })
  const itens = _pesqFiltradas(inp)
  const q = pesqState.sugDigitando ? inp.value.trim() : ''
  lista.innerHTML = itens.length
    ? itens.map((x, i) => `<div class="ac-item${i === pesqState.sugAtivo ? ' ativo' : ''}" data-i="${i}" role="option">${_pesqRealce(x.valor, q)}${x.sub ? `<span class="ac-sub">${esc(x.sub)}</span>` : ''}</div>`).join('')
    : `<div class="ac-empty">${esc(_pesqListaVazia(inp.dataset.sugestao, q !== ''))}</div>`
  lista.classList.add('open')
  inp.setAttribute('aria-expanded', 'true')
  _pesqLadoDaLista(inp, lista)
  lista.querySelector('.ac-item.ativo')?.scrollIntoView({ block: 'nearest' })
}

/**
 * Abre a lista para baixo, ou para cima quando não cabe. A barra da pesquisa
 * rola por dentro (.pesq-conteudo), então uma lista ancorada embaixo de um
 * campo do fim do formulário apareceria cortada pela borda da área que rola.
 */
function _pesqLadoDaLista(inp, lista) {
  const area = inp.closest('.pesq-conteudo')
  lista.classList.remove('para-cima')
  if (!area) return
  const campo = inp.getBoundingClientRect(), limite = area.getBoundingClientRect()
  const altura = lista.offsetHeight
  const cabeEmbaixo = limite.bottom - campo.bottom >= altura + 10
  const cabeEmCima = campo.top - limite.top >= altura + 10
  if (!cabeEmbaixo && cabeEmCima) lista.classList.add('para-cima')
}

function _pesqFecharLista(inp) {
  inp?.parentElement.querySelector('.ac-list')?.classList.remove('open')
  inp?.setAttribute('aria-expanded', 'false')
  pesqState.sugDigitando = false
  pesqState.sugAtivo = -1
}

/** Põe o valor no campo e repesquisa, como se tivesse sido digitado. */
function _pesqEscolherSugestao(inp, valor) {
  inp.value = valor
  inp.parentElement.querySelector('.clr-btn').hidden = !valor
  // O aviso vem ANTES de fechar: ele é o mesmo 'input' de quem digita, e o
  // tratador dele reabre a lista. Fechar depois deixa a lista fechada, que é o
  // que se espera de quem acabou de escolher.
  inp.dispatchEvent(new Event('input', { bubbles: true }))
  _pesqFecharLista(inp)
}

document.addEventListener('focusin', e => {
  const inp = e.target.closest?.('#pesq-barra input[data-sugestao]')
  if (!inp) return
  pesqState.sugDigitando = false
  pesqState.sugAtivo = -1
  _pesqPintarLista(inp)
})
document.addEventListener('focusout', e => {
  const inp = e.target.closest?.('#pesq-barra input[data-sugestao]')
  if (inp) setTimeout(() => _pesqFecharLista(inp), 150)
})
document.addEventListener('keydown', e => {
  const inp = e.target.closest?.('#pesq-barra input[data-sugestao]')
  if (!inp) return
  const lista = inp.parentElement.querySelector('.ac-list')
  const aberta = lista?.classList.contains('open')
  const itens = _pesqFiltradas(inp)
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    if (!aberta) { _pesqPintarLista(inp); return }
    pesqState.sugAtivo = Math.max(0, Math.min(itens.length - 1, pesqState.sugAtivo + (e.key === 'ArrowDown' ? 1 : -1)))
    _pesqPintarLista(inp)
  } else if (e.key === 'Enter' && aberta && pesqState.sugAtivo >= 0) {
    // Só rouba o Enter quando há uma opção REALÇADA: sem isso ele continua
    // sendo o "Buscar" do formulário, que é o que se espera ao digitar à mão.
    e.preventDefault()
    _pesqEscolherSugestao(inp, itens[pesqState.sugAtivo].valor)
  } else if (e.key === 'Escape' && aberta) {
    e.stopPropagation()
    _pesqFecharLista(inp)
  }
})
// mousedown, e não click: acontece ANTES do blur que fecharia a lista.
document.addEventListener('mousedown', e => {
  const item = e.target.closest?.('#pesq-barra .ac-list .ac-item')
  if (!item) return
  const inp = item.closest('.ac-wrap').querySelector('input[data-sugestao]')
  const escolhida = _pesqFiltradas(inp)[Number(item.dataset.i)]
  if (!escolhida) return
  e.preventDefault()
  _pesqEscolherSugestao(inp, escolhida.valor)
})
document.addEventListener('click', e => {
  const btn = e.target.closest?.('#pesq-barra .ac-wrap .clr-btn')
  if (!btn) return
  const inp = btn.parentElement.querySelector('input[data-sugestao]')
  _pesqEscolherSugestao(inp, '')
  inp.focus()
})

document.addEventListener('input', _pesqCampoMudou)
document.addEventListener('change', _pesqCampoMudou)
document.addEventListener('submit', e => {
  if (e.target.id !== 'pesq-direta') return
  e.preventDefault(); _pesqLerCampos(); _pesqBuscar()
})
document.addEventListener('keydown', e => {
  if (!e.target.closest?.('#pesq-barra')) return
  if (e.key === 'Escape') fecharPesquisaMapa()
  if (e.key === 'Enter' && e.target.id === 'pesq-coord') { e.preventDefault(); _pesqIrACoordenada(e.target.value) }
})
document.addEventListener('click', e => {
  const btn = e.target.closest?.('#pesq-barra [data-pesq]')
  if (!btn) return
  const { pesq, id } = btn.dataset
  setTimeout(() => {
    if (pesq === 'fechar') fecharPesquisaMapa()
    if (pesq === 'ir') { if (window.innerWidth <= 620) fecharPesquisaMapa(); irAoLoteNoMapa(Number(id)) }
    if (pesq === 'ir-coordenada') _pesqIrACoordenada(_pesqVal('pesq-coord'))
    if (pesq === 'combinar') {
      pesqState.combinando = !pesqState.combinando
      document.getElementById('pesq-campos-diretos').classList.toggle('expandido', pesqState.combinando)
      btn.setAttribute('aria-expanded', String(pesqState.combinando))
      btn.querySelector('span').textContent = pesqState.combinando ? '−' : '+'
    }
    if (pesq === 'limpar') {
      pesqState.campos = {}; pesqState.res = null; pesqState.aviso = null
      pesqState.ponto?.remove(); pesqState.ponto = null
      pintarBarraPesquisa(); _pesqBuscar()
    }
  }, 0)
})
/**
 * Quanto a tela espera o servidor antes de desistir, em milissegundos.
 *
 * Sem isso, um servidor que para de responder deixa "Pesquisando…" na tela
 * para sempre: o fetch do navegador não tem prazo próprio, e o 504 do gateway
 * nem sempre chega. Aconteceu em produção com termo de quatro palavras.
 */
const PESQ_TEMPO_MAX = 30000

/**
 * A resposta como JSON. Um 502/504 do gateway chega em HTML, e o JSON.parse
 * dele viraria "Unexpected token '<'" na cara do fiscal.
 */
async function _pesqJson(r) {
  const texto = await r.text()
  try {
    return JSON.parse(texto)
  } catch {
    throw new Error(r.status === 502 || r.status === 503 || r.status === 504
      ? 'O servidor demorou demais para responder. Tente de novo em instantes.'
      : `O servidor respondeu ${r.status} em vez do resultado.`)
  }
}

async function _pesqBuscar() {
  clearTimeout(pesqState.timer)
  const s = pesqState, pedido = ++s.pedido
  s.abort?.abort()
  s.res = null
  const p = _pesqParametros()
  if (!p.size) { s.aviso = null; destacarLotes(null); _pesqAtualizarResultado(); return }
  s.aviso = { texto: 'Pesquisando…' }; _pesqAtualizarResultado()
  s.abort = new AbortController()
  let porTempo = false
  const relogio = setTimeout(() => { porTempo = true; s.abort.abort() }, PESQ_TEMPO_MAX)
  try {
    const r = await fetch('/api/mapa/pesquisa?' + p, { headers: { Accept: 'application/json' }, signal: s.abort.signal })
    const d = await _pesqJson(r)
    if (pedido !== s.pedido) return
    if (!r.ok) throw new Error(d.message || 'Falha na pesquisa.')
    s.aviso = null; s.res = d
    destacarLotes(d.ids.length ? d.ids : null)
    if (d.caixa) {
      const [sul, oeste, norte, leste] = d.caixa
      const barra = document.getElementById('pesq-barra')
      const mapa = document.getElementById('map').getBoundingClientRect()
      const painel = barra.getBoundingClientRect()
      const espacoLateral = painel.left - mapa.left
      const lateral = espacoLateral >= 160
      const acima = painel.top - mapa.top > mapa.bottom - painel.bottom
      const inicio = lateral || acima ? [20, 20] : [20, Math.max(20, painel.bottom - mapa.top + 16)]
      const final = lateral ? [mapa.right - painel.left + 16, 20] : acima ? [20, mapa.bottom - painel.top + 16] : [20, 20]
      mapaState.obj?.fitBounds([[sul, oeste], [norte, leste]], { paddingTopLeft: inicio, paddingBottomRight: final, maxZoom: ZOOM_DO_LOTE, animate: false })
    }
  } catch (e) {
    if (pedido !== s.pedido) return
    // Cancelamento por tecla nova não é erro; estouro de prazo é, e precisa
    // aparecer — senão a tela fica dizendo "Pesquisando…" indefinidamente.
    if (e.name === 'AbortError' && ! porTempo) return
    s.aviso = {
      texto: porTempo
        ? 'A pesquisa passou de 30 segundos e foi interrompida. Tente com menos palavras, ou use os campos de bairro e rua.'
        : e.message,
      erro: true,
    }
    destacarLotes(null)
  } finally {
    clearTimeout(relogio)
  }
  _pesqAtualizarResultado()
}

// ── COORDENADA ───────────────────────────────────────────────

/** Mensagem embaixo do campo de coordenada. */
function _pesqSaida(html) {
  const el = document.getElementById('pesq-coord-msg')
  if (!el) return
  el.hidden = !html
  el.innerHTML = html || ''
}

/**
 * Até onde o salto por coordenada aceita ir, em quilômetros contados do centro
 * de Primavera do Leste. O município tem ~75 km de ponta a ponta, então 150 km
 * cobre qualquer ponto legítimo da região com folga. Serve para barrar o erro
 * de digitação que manda o mapa para o outro lado do mundo: hemisfério escrito
 * com a letra e sem o sinal de menos erra ~12.000 km, grau-minuto-segundo lido
 * como grau decimal erra ~9.600 km, e o fuso digitado na frente erra ~8.300 km.
 */
const COORD_RAIO_MAX_KM = 150

/** Centro da cidade, usado quando a extensão real do cadastro ainda não carregou. */
const COORD_CENTRO = [-15.5556, -54.2961]

/**
 * Lê a coordenada escrita à mão. Aceita, em qualquer ordem:
 *
 *   -15.512931, -54.313763          graus decimais, com ou sem vírgula decimal
 *   15°30'46.6"S 54°18'49.5"W       grau, minuto e segundo (o que o Google Maps mostra)
 *   15 30.777 S, 54 18.826 W        grau e minuto decimal (comum em memorial)
 *   788181.66, 8283132.48           UTM, fuso 21 sul por omissão
 *   21S 788181.66 8283132.48        UTM com o fuso escrito
 *
 * A letra do hemisfério (N/S e E/W, ou L/O em português) manda no sinal e também
 * diz qual número é a latitude. Sem letra e sem sinal, vale a ordem natural daqui:
 * a latitude é ~-15 e a longitude ~-54, então o de menor módulo é a latitude.
 *
 * @returns {{lat: number, lon: number, como: string}|{erro: string}}
 */
function _pesqLerCoordenada(termo) {
  let t = String(termo || '').toUpperCase()
    .replace(/[’′`]/g, "'").replace(/[”″]/g, '"').replace(/[º°]/g, '°')

  // Fuso escrito junto ("21S", "22 K"). Só conta se o que sobra for tamanho de
  // UTM — senão o "15" de 15°30' seria confundido com o número do fuso.
  let zona = 21
  const mz = t.match(/(?:^|\s)(\d{1,2})\s*[C-HJ-NP-X](?=$|[\s,;])/)
  if (mz) {
    const resto = t.replace(mz[0], ' ')
    const grandes = (resto.match(/\d+(?:[.,]\d+)?/g) || [])
      .filter(n => parseFloat(n.replace(',', '.')) >= 100000)
    if (grandes.length >= 2) { zona = Number(mz[1]); t = resto }
  }

  // Marca as letras de hemisfério que estão soltas, para não pescar o L de "LAT".
  t = t.replace(/(^|[\s,;°'"\d])([NSEWLO])(?=$|[\s,;.\d-])/g, '$1 ¤$2 ')

  const pecas = []
  const re = /(-?\d+(?:[.,]\d+)?)\s*(°|'|"|)|¤([NSEWLO])/g
  let m
  while ((m = re.exec(t)) !== null) {
    if (m[3]) pecas.push({ hemi: m[3] })
    else pecas.push({ n: parseFloat(m[1].replace(',', '.')), u: m[2] })
  }
  const numeros = pecas.filter(p => p.hemi === undefined).map(p => p.n)
  if (numeros.length < 2) return { erro: 'Informe as duas coordenadas.' }

  // Dois grupos: um por coordenada. A letra abre o grupo (quando vem antes dos
  // números) ou fecha (quando vem depois) — as duas escritas aparecem na prática.
  let grupos = []
  if (pecas[0].hemi) {
    for (const p of pecas) {
      if (p.hemi) grupos.push({ hemi: p.hemi, nums: [] })
      else if (grupos.length) grupos[grupos.length - 1].nums.push(p.n)
    }
  } else {
    let nums = []
    for (const p of pecas) {
      if (p.hemi) { grupos.push({ hemi: p.hemi, nums }); nums = [] }
      else nums.push(p.n)
    }
    if (nums.length) grupos.push({ hemi: null, nums })
  }
  grupos = grupos.filter(g => g.nums.length)
  if (grupos.length !== 2) {
    // Sem letra separando: 4 ou 6 números são grau-minuto(-segundo) dos dois
    // lados; qualquer outra quantidade vale pelos dois primeiros.
    grupos = numeros.length === 4 || numeros.length === 6
      ? [{ hemi: null, nums: numeros.slice(0, numeros.length / 2) },
         { hemi: null, nums: numeros.slice(numeros.length / 2) }]
      : [{ hemi: null, nums: [numeros[0]] }, { hemi: null, nums: [numeros[1]] }]
  }

  const valor = g => {
    const [d = 0, mi = 0, s = 0] = g.nums
    const magnitude = Math.abs(d) + Math.abs(mi) / 60 + Math.abs(s) / 3600
    const negativo = g.hemi ? /[SWO]/.test(g.hemi) : d < 0
    return negativo ? -magnitude : magnitude
  }
  const v1 = valor(grupos[0]), v2 = valor(grupos[1])
  const hemi = grupos.map(g => g.hemi || '')
  const grauMinutoSegundo = grupos.some(g => g.nums.length > 1) || /['"°]/.test(t)

  let lat, lon, como
  if (grauMinutoSegundo || (Math.abs(v1) <= 90 && Math.abs(v2) <= 180)) {
    if (/[NS]/.test(hemi[0]) && /[EWOL]/.test(hemi[1])) [lat, lon] = [v1, v2]
    else if (/[EWOL]/.test(hemi[0]) && /[NS]/.test(hemi[1])) [lat, lon] = [v2, v1]
    else [lat, lon] = Math.abs(v1) < Math.abs(v2) ? [v1, v2] : [v2, v1]
    como = grauMinutoSegundo ? 'grau/minuto/segundo' : 'lat/long'
  } else {
    const [E, N] = v1 < v2 ? [v1, v2] : [v2, v1]
    ;[lat, lon] = utmParaLatLon(E, N, zona, true)
    como = 'UTM ' + zona + 'S'
  }
  if (!isFinite(lat) || !isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
    return { erro: 'Coordenada inválida.' }
  }
  return { lat, lon, como }
}

/** Distância em km até o cadastro (ou até o centro da cidade, se ele não carregou). */
function _pesqDistanciaDaCidade(lat, lon) {
  const e = mapaState.extensao
  const centro = e
    ? [(e.sul + e.norte) / 2, (e.oeste + e.leste) / 2]
    : COORD_CENTRO
  const dLat = (lat - centro[0]) * 111.32
  const dLon = (lon - centro[1]) * 111.32 * Math.cos(centro[0] * Math.PI / 180)
  return Math.hypot(dLat, dLon)
}

function _pesqIrACoordenada(termo) {
  const r = _pesqLerCoordenada(termo)
  if (r.erro) { _pesqSaida(`<div class="pesq-msg pesq-erro">${r.erro}</div>`); return }
  const { lat, lon, como } = r

  const km = _pesqDistanciaDaCidade(lat, lon)
  if (km > COORD_RAIO_MAX_KM) {
    _pesqSaida(`<div class="pesq-msg pesq-erro">Entendi <strong>${como}</strong>:
      ${lat.toFixed(6)}, ${lon.toFixed(6)} — ${fmtBR(km, 0)} km de Primavera do Leste.
      Confira o que foi digitado: o hemisfério sul e oeste precisa do sinal de menos
      ou da letra (S e W), e o fuso, se escrever, vem antes dos dois números.</div>`)
    return
  }

  pesqState.ponto?.remove()
  pesqState.ponto = L.circleMarker([lat, lon], { radius: 9, color: '#facc15', weight: 3, fillOpacity: .25 })
    .addTo(mapaState.obj)
  mapaState.obj.setView([lat, lon], ZOOM_DO_LOTE)
  _pesqSaida(`<div class="pesq-msg">${como}: ${lat.toFixed(6)}, ${lon.toFixed(6)}</div>`)
}

/**
 * UTM → graus (elipsoide GRS80, o do SIRGAS 2000; para esta finalidade igual
 * ao WGS84). Fórmulas de Snyder, "Map Projections — A Working Manual", p. 63.
 *
 * @returns {[number, number]} [lat, lon]
 */
function utmParaLatLon(E, N, zona, sul) {
  const a = 6378137, f = 1 / 298.257222101, k0 = 0.9996
  const e2 = f * (2 - f), ep2 = e2 / (1 - e2)
  const x = E - 500000, y = sul ? N - 10000000 : N
  const M = y / k0
  const mu = M / (a * (1 - e2 / 4 - 3 * e2 ** 2 / 64 - 5 * e2 ** 3 / 256))
  const e1 = (1 - Math.sqrt(1 - e2)) / (1 + Math.sqrt(1 - e2))
  const phi1 = mu + (3 * e1 / 2 - 27 * e1 ** 3 / 32) * Math.sin(2 * mu)
    + (21 * e1 ** 2 / 16 - 55 * e1 ** 4 / 32) * Math.sin(4 * mu)
    + (151 * e1 ** 3 / 96) * Math.sin(6 * mu)
  const C1 = ep2 * Math.cos(phi1) ** 2, T1 = Math.tan(phi1) ** 2
  const N1 = a / Math.sqrt(1 - e2 * Math.sin(phi1) ** 2)
  const R1 = a * (1 - e2) / (1 - e2 * Math.sin(phi1) ** 2) ** 1.5
  const D = x / (N1 * k0)
  const lat = phi1 - (N1 * Math.tan(phi1) / R1) * (D ** 2 / 2
    - (5 + 3 * T1 + 10 * C1 - 4 * C1 ** 2 - 9 * ep2) * D ** 4 / 24
    + (61 + 90 * T1 + 298 * C1 + 45 * T1 ** 2 - 252 * ep2 - 3 * C1 ** 2) * D ** 6 / 720)
  const lon0 = (zona * 6 - 183) * Math.PI / 180
  const lon = lon0 + (D - (1 + 2 * T1 + C1) * D ** 3 / 6
    + (5 - 2 * C1 + 28 * T1 - 3 * C1 ** 2 + 8 * ep2 + 24 * T1 ** 2) * D ** 5 / 120) / Math.cos(phi1)
  return [lat * 180 / Math.PI, lon * 180 / Math.PI]
}
