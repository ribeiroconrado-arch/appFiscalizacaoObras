/**
 * PESQUISA DO MAPA — barra horizontal no alto do mapa, na mesma faixa das
 * barras da curadoria (#mapa-barras).
 *
 * NÃO HÁ CAMPO DE TEXTO LIVRE. Quem pesquisa MONTA a pergunta com filtros
 * ("+ Filtro"): onde (bairro, quadra, lote, inscrição), endereço (rua e
 * número) e pendências. Cada filtro vira uma etiqueta na barra; eles se SOMAM
 * (um E o outro), e dentro de um filtro de lista as escolhas valem como OU.
 * O resultado sai a cada mudança: pintado no mapa, enquadrado e listado.
 *
 * Todo filtro que é escolha numa lista (bairro, rua, pendência, vistoria) usa
 * o SELETOR COM BUSCA: digita-se um pedaço, a lista sugere, e o escolhido vira
 * etiqueta dentro do campo — vai-se adicionando.
 *
 * Servidor: GET /api/mapa/pesquisa (PesquisaMapaController). A coordenada é
 * resolvida aqui, sem ida ao servidor, e não se combina com os filtros.
 *
 * É uma FERRAMENTA (ferramentas-mapa.js): abrir a pesquisa fecha a curadoria,
 * e abrir a curadoria fecha a pesquisa.
 */

const pesqState = {
  /** @type {?{bairros:Array, ruas:Array, pendencias:Object, vistorias:Object}} listas dos seletores */
  opcoes: null,
  /** @type {Array<{tipo:string, v:Object}>} os filtros aplicados, na ordem em que entraram */
  ativos: [],
  menu: false,
  /** @type {?{i?:number, tipo?:string}} filtro em edição: índice de `ativos`, ou tipo ainda não aplicado */
  editando: null,
  /** O que está escolhido em cada seletor do editor aberto: campo => valores. */
  rasc: {},
  /** @type {?Object} a última resposta do servidor */
  res: null,
  /** @type {?string} mensagem de erro ou de espera, no lugar do resultado */
  aviso: null,
  coordenada: false,
  /** @type {L.Marker|null} o ponto da coordenada procurada */
  ponto: null,
  /** Número do pedido em curso: resposta atrasada de um pedido antigo é descartada. */
  pedido: 0,
}

// ── OS FILTROS ───────────────────────────────────────────────
//
// Cada filtro diz: grupo do menu, rótulo, valor inicial, o resumo que aparece
// na etiqueta, os campos do editor, como ler o editor, se está preenchido, e
// o que ele acrescenta ao pedido (`params`). `sels` liga cada seletor com
// busca do editor à chave do valor.

const _pesqFaixa = (de, ate) => de !== '' && ate !== '' ? (de === ate ? de : `${de} a ${ate}`)
  : de !== '' ? `a partir de ${de}` : `até ${ate}`
const _pesqVal = id => document.getElementById(id)?.value.trim() ?? ''
const _pesqMarcado = nome => document.querySelector(`#pesq-barra [name="${nome}"]:checked`)?.value
const _pesqCampo = (rot, html) => `<label class="pesq-campo"><span>${rot}</span>${html}</label>`
const _pesqRadios = (nome, opcoes, atual) => `<div class="pesq-ops">${Object.entries(opcoes).map(([k, r]) =>
  `<label class="pesq-op"><input type="radio" name="${nome}" value="${k}"${k === atual ? ' checked' : ''}>${esc(r)}</label>`).join('')}</div>`

const PESQ_FILTROS = {
  bairro: { grupo: 'Onde', rotulo: 'Bairro', ini: { nomes: [] }, sels: { bairro: 'nomes' },
    resumo: v => v.nomes.join(' ou '),
    editor: () => _pesqSeletor('bairro', 'Digite o nome ou o código do bairro…')
      + '<div class="pesq-dica">Vá adicionando: com mais de um bairro, vale qualquer um deles.</div>',
    ler: () => ({ nomes: pesqState.rasc.bairro || [] }), ok: v => v.nomes.length > 0,
    params: (p, v) => v.nomes.forEach(n => p.append('bairros[]', n)) },

  quadra: { grupo: 'Onde', rotulo: 'Quadra', ini: { de: '', ate: '' },
    resumo: v => _pesqFaixa(v.de, v.ate),
    editor: v => `<div class="pesq-campos">
      ${_pesqCampo('Da quadra', `<input id="pesq-q-de" type="number" min="0" inputmode="numeric" value="${esc(v.de)}">`)}
      ${_pesqCampo('Até a quadra', `<input id="pesq-q-ate" type="number" min="0" inputmode="numeric" value="${esc(v.ate)}" placeholder="igual à primeira">`)}</div>`,
    ler: () => { const de = _pesqVal('pesq-q-de'); return { de, ate: _pesqVal('pesq-q-ate') || de } },
    ok: v => v.de !== '' || v.ate !== '',
    params: (p, v) => { if (v.de !== '') p.set('quadra_de', v.de); if (v.ate !== '') p.set('quadra_ate', v.ate) } },

  lote: { grupo: 'Onde', rotulo: 'Lote', ini: { de: '', ate: '' },
    resumo: v => _pesqFaixa(v.de, v.ate),
    editor: v => `<div class="pesq-campos">
      ${_pesqCampo('Do lote', `<input id="pesq-l-de" type="number" min="0" inputmode="numeric" value="${esc(v.de)}">`)}
      ${_pesqCampo('Até o lote', `<input id="pesq-l-ate" type="number" min="0" inputmode="numeric" value="${esc(v.ate)}" placeholder="igual ao primeiro">`)}</div>`,
    ler: () => { const de = _pesqVal('pesq-l-de'); return { de, ate: _pesqVal('pesq-l-ate') || de } },
    ok: v => v.de !== '' || v.ate !== '',
    params: (p, v) => { if (v.de !== '') p.set('lote_de', v.de); if (v.ate !== '') p.set('lote_ate', v.ate) } },

  inscricao: { grupo: 'Onde', rotulo: 'Inscrição', ini: { comeco: '' },
    resumo: v => 'começa com ' + v.comeco,
    editor: v => `<div class="pesq-campos">${_pesqCampo('Começo da inscrição',
      `<input id="pesq-insc" class="mono" value="${esc(v.comeco)}" placeholder="01.090.002" autocomplete="off">`)}</div>
      <div class="pesq-dica">Só o começo já vale: 01.090 traz o bairro 90 inteiro; 01.090.002, a quadra 2.</div>`,
    ler: () => ({ comeco: _pesqVal('pesq-insc') }), ok: v => v.comeco.replace(/\D/g, '').length >= 2,
    params: (p, v) => p.set('inscricao', v.comeco) },

  rua: { grupo: 'Endereço', rotulo: 'Rua', ini: { ruas: [] }, sels: { rua: 'ruas' },
    resumo: v => v.ruas.join(' ou '),
    editor: () => _pesqSeletor('rua', 'Digite um pedaço do nome da rua…')
      + '<div class="pesq-dica">A rua vem da lista do cadastro municipal. Com mais de uma, vale qualquer uma delas.</div>',
    ler: () => ({ ruas: pesqState.rasc.rua || [] }), ok: v => v.ruas.length > 0,
    params: (p, v) => v.ruas.forEach(r => p.append('ruas[]', r)) },

  numero: { grupo: 'Endereço', rotulo: 'Número', ini: { modo: 'exato', de: '', ate: '' },
    resumo: v => v.modo === 'exato' ? 'nº ' + v.de : v.modo === 'faixa' ? `nº ${_pesqFaixa(v.de, v.ate)}`
      : v.modo === 'par' ? 'lado par' : 'lado ímpar',
    editor: v => _pesqRadios('pesq-n-modo', { exato: 'Número exato', faixa: 'Trecho (de–até)', par: 'Lado par', impar: 'Lado ímpar' }, v.modo)
      + `<div class="pesq-campos">
        ${_pesqCampo('Número / de', `<input id="pesq-n-de" type="number" min="0" inputmode="numeric" value="${esc(v.de)}">`)}
        ${_pesqCampo('Até', `<input id="pesq-n-ate" type="number" min="0" inputmode="numeric" value="${esc(v.ate)}">`)}</div>
      <div class="pesq-dica">Combine com Rua. Lado par e lado ímpar valem para a rua inteira; o Trecho traz os dois lados entre os números.</div>`,
    ler: () => {
      const modo = _pesqMarcado('pesq-n-modo') || 'exato'
      const lado = modo === 'par' || modo === 'impar'
      return { modo, de: lado ? '' : _pesqVal('pesq-n-de'), ate: lado || modo === 'exato' ? '' : _pesqVal('pesq-n-ate') }
    },
    ok: v => v.modo === 'par' || v.modo === 'impar' || v.de !== '' || (v.modo === 'faixa' && v.ate !== ''),
    params: (p, v) => { p.set('numero_modo', v.modo); if (v.de !== '') p.set('numero_de', v.de); if (v.ate !== '') p.set('numero_ate', v.ate) } },

  pendencia: { grupo: 'Pendências', interno: true, rotulo: 'Pendência', ini: { tipos: [], modo: 'qualquer' }, sels: { pendencia: 'tipos' },
    resumo: v => v.tipos.map(t => pesqState.opcoes?.pendencias?.[t] ?? t).join(v.modo === 'todas' ? ' e ' : ' ou '),
    editor: v => _pesqSeletor('pendencia', 'Digite ou escolha a pendência…')
      + _pesqRadios('pesq-p-modo', { qualquer: 'Com qualquer uma das escolhidas', todas: 'Com todas as escolhidas' }, v.modo),
    ler: () => ({ tipos: pesqState.rasc.pendencia || [], modo: _pesqMarcado('pesq-p-modo') || 'qualquer' }),
    ok: v => v.tipos.length > 0,
    params: (p, v) => { v.tipos.forEach(t => p.append('pendencias[]', t)); p.set('pendencias_modo', v.modo) } },

  vistoria: { grupo: 'Pendências', interno: true, rotulo: 'Última vistoria', ini: { sit: [] }, sels: { vistoria: 'sit' },
    resumo: v => v.sit.map(s => pesqState.opcoes?.vistorias?.[s] ?? s).join(' ou '),
    editor: () => _pesqSeletor('vistoria', 'Digite ou escolha a situação…'),
    ler: () => ({ sit: pesqState.rasc.vistoria || [] }), ok: v => v.sit.length > 0,
    params: (p, v) => v.sit.forEach(s => p.append('vistorias[]', s)) },

  sempendencia: { grupo: 'Pendências', interno: true, rotulo: 'Sem nenhuma pendência', ini: {}, direto: true,
    resumo: () => '', params: p => p.set('sem_pendencia', '1') },
}

/** Os filtros que ESTE usuário pode usar: pendência é só para quem é de dentro. */
function _pesqDisponiveis() {
  const interno = !!Object.keys(pesqState.opcoes?.pendencias || {}).length
  return Object.entries(PESQ_FILTROS).filter(([, d]) => !d.interno || interno)
}

// ── ABRIR E FECHAR ───────────────────────────────────────────

function abrirPesquisaMapa() {
  pedirFerramenta('busca', () => {
    fecharPaineisMapa()
    const barra = document.getElementById('pesq-barra')
    if (!barra) return
    if (barra.hidden) { barra.hidden = false; pintarBarraPesquisa() }
    document.getElementById('grupo-busca')?.querySelector('.ctrl-btn')?.classList.add('at')
    _pesqCarregarOpcoes()
    // Reabrir com filtros que ficaram montados refaz a pesquisa: fechar a
    // barra apagou o destaque do mapa, não a pergunta.
    if (pesqState.ativos.length) _pesqBuscar()
  })
}

function fecharPesquisaMapa() {
  const barra = document.getElementById('pesq-barra')
  if (barra) barra.hidden = true
  document.getElementById('grupo-busca')?.querySelector('.ctrl-btn')?.classList.remove('at')
  if (typeof destacarLotes === 'function') destacarLotes(null)
  pesqState.ponto?.remove()
  pesqState.ponto = null
  pesqState.menu = false
  pesqState.editando = null
  pesqState.pedido++
}

/** A lupa abre e fecha. */
function alternarPesquisaMapa() {
  const barra = document.getElementById('pesq-barra')
  barra && !barra.hidden ? fecharPesquisaMapa() : abrirPesquisaMapa()
}

async function _pesqCarregarOpcoes() {
  if (pesqState.opcoes) return
  try {
    const r = await fetch('/api/mapa/pesquisa/opcoes', { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    pesqState.opcoes = await r.json()
  } catch {
    pesqState.opcoes = { bairros: [], ruas: [], pendencias: {}, vistorias: {} }
  }
  if (!document.getElementById('pesq-barra')?.hidden) pintarBarraPesquisa()
}

// ── A BARRA ──────────────────────────────────────────────────

function pintarBarraPesquisa() {
  const barra = document.getElementById('pesq-barra')
  if (!barra) return
  const s = pesqState
  const ed = s.editando
    ? (s.editando.i != null ? s.ativos[s.editando.i] : { tipo: s.editando.tipo, v: PESQ_FILTROS[s.editando.tipo].ini })
    : null
  const grupos = [...new Set(_pesqDisponiveis().map(([, d]) => d.grupo))]

  barra.innerHTML = `
    <div class="pesq-linha">
      <div class="pesq-tit">
        <svg class="pesq-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
        Pesquisar no mapa</div>
      <button type="button" class="btn sm${s.coordenada ? ' at' : ''}" data-pesq="coordenada">Ir para coordenada</button>
      <button type="button" class="btn sm" data-pesq="limpar"${s.ativos.length ? '' : ' disabled'}>Limpar</button>
      <button type="button" class="btn sm pesq-x" title="Fechar (Esc)" data-pesq="fechar">&#10005;</button>
    </div>
    ${s.coordenada ? `<div class="pesq-linha pesq-secao">
        <input type="text" id="pesq-coord" class="pesq-coord" autocomplete="off"
               placeholder="lat, long (-15.5601, -54.3065) ou UTM 21S (E N: 788900 8277900)" aria-label="Coordenada">
        <button type="button" class="btn primary sm" data-pesq="ir-coordenada">Ir</button>
      </div><div id="pesq-coord-msg" class="pesq-msg" hidden></div>` : ''}
    <div class="pesq-linha pesq-secao pesq-chips">
      ${s.ativos.map((f, i) => {
        const d = PESQ_FILTROS[f.tipo]
        return `${i ? '<span class="pesq-e">e</span>' : ''}
          <span class="pesq-filtro${s.editando?.i === i ? ' aberto' : ''}" data-pesq="editar" data-i="${i}"
                role="button" tabindex="0" title="${d.direto ? '' : 'Alterar este filtro'}">
            <span>${esc(d.rotulo)}${d.direto ? '' : ': <small>' + esc(d.resumo(f.v)) + '</small>'}</span>
            <button type="button" class="pesq-tira" title="Tirar este filtro" data-pesq="tirar" data-i="${i}">&#10005;</button>
          </span>`
      }).join('')}
      ${s.ativos.length ? '' : '<span class="pesq-vazio">Nenhum filtro ainda.</span>'}
      <button type="button" class="pesq-mais" data-pesq="menu" aria-expanded="${s.menu}">+ Filtro</button>
    </div>
    ${s.menu ? `<div class="pesq-menu">${grupos.map(g => `<div><h4>${esc(g)}</h4>
        ${_pesqDisponiveis().filter(([, d]) => d.grupo === g).map(([k, d]) => {
          const ja = s.ativos.some(f => f.tipo === k)
          return `<button type="button" data-pesq="adicionar" data-tipo="${k}"${ja
            ? ' disabled title="Já está na pesquisa: clique na etiqueta para alterar"' : ''}>${esc(d.rotulo)}</button>`
        }).join('')}</div>`).join('')}</div>` : ''}
    ${ed ? `<div class="pesq-editor"><h4>${esc(PESQ_FILTROS[ed.tipo].rotulo)}</h4>
        ${PESQ_FILTROS[ed.tipo].editor(ed.v)}
        <div class="pesq-pe">
          <button type="button" class="btn sm" data-pesq="cancelar">Cancelar</button>
          <button type="button" class="btn primary sm" data-pesq="aplicar">Aplicar filtro</button>
        </div></div>` : ''}
    <div id="pesq-resultado" class="pesq-resultado">${_pesqResultadoHtml()}</div>`

  _pesqMontarSeletores()
  if (ed) barra.querySelector('.pesq-editor input:not([type=radio]), .pesq-editor select')?.focus()
  if (s.coordenada && !ed) document.getElementById('pesq-coord')?.focus()
}

function _pesqResultadoHtml() {
  const s = pesqState
  if (s.aviso) return `<div class="pesq-msg${s.aviso.erro ? ' pesq-erro' : ''}">${esc(s.aviso.texto)}</div>`
  if (!s.ativos.length) return '<div class="pesq-msg">Comece por "+ Filtro". Cada filtro novo estreita o resultado.</div>'
  const r = s.res
  if (!r) return ''
  if (!r.total) return '<div class="pesq-msg">Nenhum imóvel atende a todos os filtros juntos. Tire um deles para alargar a pesquisa.</div>'
  return `<div class="pesq-msg"><b>${r.total}${r.truncado ? '+' : ''} ${r.total === 1 ? 'imóvel' : 'imóveis'}</b>
      · ${r.total === 1 ? 'destacado' : 'destacados'} no mapa${r.truncado ? ` · teto de ${r.total}: refine a pesquisa` : ''}</div>
    <table class="pesq-tabela"><tbody>${r.imoveis.map(i => `
      <tr data-pesq="ir" data-id="${Number(i.id)}">
        <td class="mono">${esc(i.inscricao || '—')}</td>
        <td>${i.endereco ? esc(i.endereco) + '<br>' : ''}<span class="pesq-sub">${esc(i.bairro || '')} · Q ${esc(i.quadra ?? '—')} · Lt ${esc(i.lote ?? '—')}</span></td>
        <td>${(i.pendencias || []).map(p => `<span class="pesq-tag">${esc(p)}</span>`).join('')}</td>
        <td class="pesq-ver">ver ›</td>
      </tr>`).join('')}</tbody></table>
    ${r.total > r.imoveis.length ? `<div class="pesq-msg">Mostrando ${r.imoveis.length} na lista. Todos os ${r.total} estão pintados no mapa.</div>` : ''}`
}

// Um ouvinte só, na barra: os botões são redesenhados a cada mudança, e
// nomes de rua têm aspas — nada de valor do cadastro dentro de onclick.
//
// A AÇÃO ESPERA O CLIQUE TERMINAR (setTimeout 0): quase toda ação redesenha a
// barra, e tirar do DOM o botão clicado no meio do próprio clique faz o
// Leaflet ler "clique no mapa" — o mesmo defeito do "Resolvido" da sinalização.
document.addEventListener('click', e => {
  const alvo = e.target.closest?.('#pesq-barra [data-pesq]')
  if (!alvo) return
  const dados = { ...alvo.dataset }
  setTimeout(() => _pesqAcao(dados), 0)
})

/** @param {{pesq:string, i?:string, id?:string, tipo?:string}} dados o data-* do botão clicado */
function _pesqAcao(dados) {
  const s = pesqState
  const acao = dados.pesq
  const i = dados.i != null ? Number(dados.i) : null

  if (acao === 'fechar') { fecharPesquisaMapa(); return }
  if (acao === 'ir') { irAoLoteNoMapa(Number(dados.id)); return }
  if (acao === 'ir-coordenada') { _pesqIrACoordenada(_pesqVal('pesq-coord')); return }
  if (acao === 'coordenada') { s.coordenada = !s.coordenada; s.menu = false; s.editando = null }
  if (acao === 'limpar') { s.ativos = []; s.editando = null; s.menu = false; _pesqBuscar(); return }
  if (acao === 'menu') { s.menu = !s.menu; s.editando = null }
  if (acao === 'cancelar') { s.editando = null }
  if (acao === 'tirar') { s.ativos.splice(i, 1); s.editando = null; _pesqBuscar(); return }
  if (acao === 'editar') {
    if (!s.ativos[i] || PESQ_FILTROS[s.ativos[i].tipo].direto) return
    s.menu = false; s.editando = { i }; _pesqAbrirRascunho(s.ativos[i].tipo, s.ativos[i].v)
  }
  if (acao === 'adicionar') {
    const tipo = dados.tipo
    s.menu = false
    if (PESQ_FILTROS[tipo].direto) { s.ativos.push({ tipo, v: {} }); s.editando = null; _pesqBuscar(); return }
    s.editando = { tipo }; _pesqAbrirRascunho(tipo, PESQ_FILTROS[tipo].ini)
  }
  if (acao === 'aplicar') { _pesqAplicar(); return }
  pintarBarraPesquisa()
}

document.addEventListener('keydown', e => {
  if (!e.target.closest?.('#pesq-barra')) return
  if (e.key === 'Escape') {
    // Esc fecha primeiro o que está aberto por dentro; só depois a barra.
    if (pesqState.editando || pesqState.menu) { pesqState.editando = null; pesqState.menu = false; pintarBarraPesquisa() }
    else fecharPesquisaMapa()
    return
  }
  if (e.key !== 'Enter') return
  if (e.target.id === 'pesq-coord') { _pesqIrACoordenada(e.target.value); return }
  if (e.target.closest('.pesq-filtro') && e.target.tagName !== 'BUTTON') { e.target.click(); return }
  // No editor, Enter aplica — menos dentro do seletor, onde ele escolhe a sugestão.
  if (e.target.closest('.pesq-editor') && !e.target.closest('.pesq-sel')) { e.preventDefault(); _pesqAplicar() }
})

function _pesqAbrirRascunho(tipo, v) {
  pesqState.rasc = {}
  for (const [campo, chave] of Object.entries(PESQ_FILTROS[tipo].sels || {})) pesqState.rasc[campo] = [...(v[chave] || [])]
}

function _pesqAplicar() {
  const s = pesqState
  if (!s.editando) return
  const tipo = s.editando.i != null ? s.ativos[s.editando.i].tipo : s.editando.tipo
  const v = PESQ_FILTROS[tipo].ler()
  if (!PESQ_FILTROS[tipo].ok(v)) { toast('Preencha o filtro antes de aplicar.', 'aviso'); return }
  if (s.editando.i != null) s.ativos[s.editando.i].v = v
  else s.ativos.push({ tipo, v })
  s.editando = null
  _pesqBuscar()
}

// ── A PESQUISA ───────────────────────────────────────────────

/** Refaz a pesquisa com os filtros de agora, e pinta o resultado no mapa. */
async function _pesqBuscar() {
  const s = pesqState
  const pedido = ++s.pedido
  s.res = null

  if (!s.ativos.length) {
    s.aviso = null
    if (typeof destacarLotes === 'function') destacarLotes(null)
    pintarBarraPesquisa()
    return
  }

  const p = new URLSearchParams()
  s.ativos.forEach(f => PESQ_FILTROS[f.tipo].params(p, f.v))
  s.aviso = { texto: 'Pesquisando…' }
  pintarBarraPesquisa()

  try {
    const r = await fetch('/api/mapa/pesquisa?' + p, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (pedido !== s.pedido) return          // já há pesquisa mais nova a caminho
    if (!r.ok) throw new Error(d.message || 'Falha na pesquisa.')

    s.aviso = null
    s.res = d
    destacarLotes(d.ids.length ? d.ids : null)
    if (d.total === 1) irAoLoteNoMapa(d.ids[0])
    else if (d.caixa) {
      const [sul, oeste, norte, leste] = d.caixa
      // Sem animação: o salto é direto, e uma animação interrompida (aba em
      // segundo plano, outro movimento no meio) deixava o mapa a meio caminho.
      mapaState.obj?.fitBounds([[sul, oeste], [norte, leste]], { padding: [40, 40], maxZoom: 19, animate: false })
    }
  } catch (e) {
    if (pedido !== s.pedido) return
    s.aviso = { texto: e.message, erro: true }
    destacarLotes(null)
  }
  pintarBarraPesquisa()
}

// ── SELETOR COM BUSCA ────────────────────────────────────────
//
// Um campo onde se digita, a lista sugere, e o escolhido vira etiqueta dentro
// do próprio campo. Montado por DOM (e não por innerHTML com onclick) porque
// os valores são nomes do cadastro, com aspas e acentos.

/** As opções de cada seletor: valor => { rot, sub }. */
function _pesqOpcoes(campo) {
  const o = pesqState.opcoes || {}
  if (campo === 'bairro') return Object.fromEntries((o.bairros || []).map(b => [b.nome, { rot: b.nome, sub: b.codigo ? 'código ' + b.codigo : '' }]))
  if (campo === 'rua') return Object.fromEntries((o.ruas || []).map(r => [r.rua, { rot: r.rua, sub: (r.bairros || []).join(', ') }]))
  const lista = campo === 'pendencia' ? o.pendencias : o.vistorias
  return Object.fromEntries(Object.entries(lista || {}).map(([k, rot]) => [k, { rot, sub: '' }]))
}

const _pesqSeletor = (campo, dica) => `<div class="pesq-sel" data-campo="${campo}" data-dica="${esc(dica)}"></div>`
const _pesqSemAcento = t => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

function _pesqMontarSeletores() {
  document.querySelectorAll('#pesq-barra .pesq-sel').forEach(el => {
    const campo = el.dataset.campo
    el.innerHTML = `<span class="pesq-tks"></span>
      <input type="text" autocomplete="off" placeholder="${esc(el.dataset.dica)}" aria-label="${esc(el.dataset.dica)}">
      <div class="pesq-drop" hidden></div>`
    const inp = el.querySelector('input')
    inp.addEventListener('focus', () => _pesqPintarDrop(el))
    inp.addEventListener('input', () => _pesqPintarDrop(el))
    inp.addEventListener('blur', () => setTimeout(() => { el.querySelector('.pesq-drop').hidden = true }, 150))
    inp.addEventListener('keydown', e => {
      const escolhidos = pesqState.rasc[campo] || []
      if (e.key === 'Enter') {
        e.preventDefault()
        const s = _pesqSugestoes(el)
        if (s.length && inp.value.trim()) _pesqEscolher(el, s[0][0])
        else if (!inp.value.trim() && escolhidos.length) _pesqAplicar()   // campo vazio: Enter aplica o filtro
      }
      if (e.key === 'Backspace' && !inp.value && escolhidos.length) { escolhidos.pop(); _pesqPintarTks(el); _pesqPintarDrop(el) }
    })
    _pesqPintarTks(el)
  })
}

function _pesqPintarTks(el) {
  const campo = el.dataset.campo, opcoes = _pesqOpcoes(campo), cx = el.querySelector('.pesq-tks')
  cx.innerHTML = ''
  for (const v of (pesqState.rasc[campo] || [])) {
    const tk = document.createElement('span'); tk.className = 'pesq-tk'; tk.textContent = opcoes[v]?.rot ?? v
    const b = document.createElement('button'); b.type = 'button'; b.title = 'Tirar'; b.innerHTML = '&#10005;'
    b.addEventListener('mousedown', e => e.preventDefault())
    b.addEventListener('click', () => {
      pesqState.rasc[campo] = pesqState.rasc[campo].filter(x => x !== v)
      _pesqPintarTks(el); el.querySelector('input').focus()
    })
    tk.appendChild(b); cx.appendChild(tk)
  }
}

/** As opções que ainda não foram escolhidas e casam com o que se digitou. */
function _pesqSugestoes(el) {
  const campo = el.dataset.campo
  const termo = _pesqSemAcento(el.querySelector('input').value.trim())
  const ja = pesqState.rasc[campo] || []
  return Object.entries(_pesqOpcoes(campo))
    .filter(([k, o]) => !ja.includes(k) && (!termo || _pesqSemAcento(o.rot + ' ' + o.sub).includes(termo)))
}

function _pesqPintarDrop(el) {
  const drop = el.querySelector('.pesq-drop'), achadas = _pesqSugestoes(el)
  const termo = el.querySelector('input').value.trim()
  const realce = rot => {
    const i = termo ? _pesqSemAcento(rot).indexOf(_pesqSemAcento(termo)) : -1
    return i < 0 ? esc(rot) : esc(rot.slice(0, i)) + '<mark>' + esc(rot.slice(i, i + termo.length)) + '</mark>' + esc(rot.slice(i + termo.length))
  }
  drop.innerHTML = ''
  if (!achadas.length) {
    const total = Object.keys(_pesqOpcoes(el.dataset.campo)).length
    drop.innerHTML = `<div class="pesq-nada">${!pesqState.opcoes ? 'Carregando a lista…'
      : termo ? 'Nada com "' + esc(termo) + '" na lista.'
      : total ? 'Todos já foram escolhidos.' : 'A lista está vazia.'}</div>`
  }
  // Teto de 60 sugestões à vista: a lista de ruas tem centenas, e digitar estreita.
  achadas.slice(0, 60).forEach(([k, o], i) => {
    const b = document.createElement('button'); b.type = 'button'
    if (i === 0 && termo) b.className = 'at'
    b.innerHTML = realce(o.rot) + (o.sub ? ` <small>· ${esc(o.sub)}</small>` : '')
    b.addEventListener('mousedown', e => e.preventDefault())
    b.addEventListener('click', () => _pesqEscolher(el, k))
    drop.appendChild(b)
  })
  if (achadas.length > 60) drop.insertAdjacentHTML('beforeend', `<div class="pesq-nada">+ ${achadas.length - 60}. Digite para estreitar.</div>`)
  drop.hidden = false
}

function _pesqEscolher(el, valor) {
  const campo = el.dataset.campo
  ;(pesqState.rasc[campo] = pesqState.rasc[campo] || []).push(valor)
  const inp = el.querySelector('input')
  inp.value = ''
  _pesqPintarTks(el); _pesqPintarDrop(el); inp.focus()
}

// ── COORDENADA ───────────────────────────────────────────────

/** Mensagem embaixo do campo de coordenada. */
function _pesqSaida(html) {
  const el = document.getElementById('pesq-coord-msg')
  if (!el) return
  el.hidden = !html
  el.innerHTML = html || ''
}

function _pesqIrACoordenada(termo) {
  const nums = (termo.match(/-?\d+(?:[.,]\d+)?/g) || []).map(n => Number(n.replace(',', '.')))
  if (nums.length < 2) { _pesqSaida('<div class="pesq-msg pesq-erro">Informe as duas coordenadas.</div>'); return }
  let [a, b] = nums
  let lat, lon, como
  if (Math.abs(a) <= 90 && Math.abs(b) <= 180) {
    // Graus decimais. Aceita na ordem que vier: aqui a latitude é ~-15 e a longitude ~-54.
    ;[lat, lon] = Math.abs(a) < Math.abs(b) ? [a, b] : [b, a]
    como = 'lat/long'
  } else {
    // UTM — E tem 6 dígitos, N tem 7. Fuso 21 sul (SIRGAS 2000), o do município.
    const [E, N] = a < b ? [a, b] : [b, a]
    ;[lat, lon] = utmParaLatLon(E, N, 21, true)
    como = 'UTM 21S'
  }
  if (!isFinite(lat) || !isFinite(lon)) { _pesqSaida('<div class="pesq-msg pesq-erro">Coordenada inválida.</div>'); return }

  pesqState.ponto?.remove()
  pesqState.ponto = L.circleMarker([lat, lon], { radius: 9, color: '#facc15', weight: 3, fillOpacity: .25 })
    .addTo(mapaState.obj)
  mapaState.obj.setView([lat, lon], 19)
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
