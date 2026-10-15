/**
 * ALERTAS DO CADASTRO — o lote publicado que ficou desatualizado.
 *
 * O loteamento pode ser publicado com divergência justificada, e a carga
 * mensal do cadastro pode desativar ou mudar um imóvel depois. A última
 * conferência do bairro (ConferenciaComCadastro::alertasDoBairro) vem em cada
 * lote do mapa como `alerta`, e aqui vira:
 *
 *   mapa    borda na cor do problema e um SELO redondo junto do número
 *           (! inativo · ? fora do cadastro · m² área · # sem inscrição;
 *           ✓ discreto para a divergência justificada) — desenhado numa tela
 *           só, como os números (mapa.js), sem elemento por lote;
 *   balão   faixa logo abaixo do título, com o que fazer;
 *   ficha   tag no cabeçalho e a linha do detalhe.
 *
 * A camada "Alertas do cadastro" (painel Camadas) desliga tudo isso.
 */

/** Cada tipo, a sua cor e o seu sinal. Inativo é vermelho por decisão do setor (11/10/2026). */
const ALERTA_TIPOS = {
  inativos:        { rotulo: 'Inativo no cadastro', sinal: '!', cor: '#C81E1E', tag: 'bd-cx',
    nota: 'A inscrição foi desativada no cadastro: o imóvel mudou (desmembramento, unificação ou retificação) e o desenho ficou para trás.' },
  nao_encontrados: { rotulo: 'Não está no cadastro', sinal: '?', cor: '#E24B4A', tag: 'bd-er',
    nota: 'A prefeitura não tem esta inscrição: confira a quadra e o número do lote.' },
  area_diferente:  { rotulo: 'Área diferente do cadastro', sinal: 'm²', cor: '#D97706', tag: 'bd-al',
    nota: 'A área do desenho não bate com a do cadastro.' },
  sem_inscricao:   { rotulo: 'Sem inscrição', sinal: '#', cor: '#6B7280', tag: 'bd-in',
    nota: 'Falta quadra ou número: a inscrição não se monta.' },
}

registrarCamada({
  id: 'alertas', grupo: 'Lotes', rotulo: 'Alertas do cadastro',
  aoMudar: () => {
    mapaState.camadas?.forEach(c => c.setStyle(estiloColorido(c.feature)))
    desenharSelosDosLotes()
  },
})

/** O alerta que vale mostrar neste lote (null se não há, ou se a camada está desligada). */
function alertaDoLote(p) {
  const a = p?.alerta
  if (!a || !ALERTA_TIPOS[a.tipo]) return null
  if (typeof camadaLigada === 'function' && !camadaLigada('alertas')) return null
  return a
}

/**
 * A borda do lote com alerta. Só por cima do estilo comum: a seleção, a
 * conferência e o painel de correção têm as cores deles, e mandam.
 */
function estiloDoAlerta(f, base) {
  const a = alertaDoLote(f?.properties)
  if (!a || a.justificado) return base
  if (typeof selState !== 'undefined' && selState.ids.has(f.properties.id)) return base
  if (typeof corDaConferencia === 'function' && corDaConferencia(f)) return base
  return { ...base, color: ALERTA_TIPOS[a.tipo].cor, weight: 2.2, opacity: 1 }
}

// ── selos ───────────────────────────────────────────────────────

/** Os selos, numa tela própria (desligar o número do lote não os esconde). */
function desenharSelosDosLotes() {
  const mapa = mapaState.obj
  if (!mapa || !mapa._loaded) return
  if (!mapa.getPane('selos')) {
    const pane = mapa.createPane('selos')
    pane.style.zIndex = 641
    pane.style.pointerEvents = 'none'
  }
  const tela = mapaState.telaSelos ||= L.DomUtil.create('canvas', 'leaflet-zoom-hide', mapa.getPane('selos'))
  const tam = mapa.getSize()
  const folga = typeof NUMEROS_FOLGA === 'number' ? NUMEROS_FOLGA : 0.25
  const fx = Math.round(tam.x * folga), fy = Math.round(tam.y * folga)
  const larg = tam.x + 2 * fx, alt = tam.y + 2 * fy, dpr = window.devicePixelRatio || 1
  if (tela.width !== Math.round(larg * dpr) || tela.height !== Math.round(alt * dpr)) {
    tela.width = Math.round(larg * dpr); tela.height = Math.round(alt * dpr)
    tela.style.width = larg + 'px'; tela.style.height = alt + 'px'
  }
  L.DomUtil.setPosition(tela, mapa.containerPointToLayerPoint([-fx, -fy]))
  const ctx = tela.getContext('2d')
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, larg, alt)

  // Do zoom 17 em diante (um antes do número): o selo é o que se vê de longe.
  const noNivel = typeof nivelDoMapa !== 'function' || nivelDoMapa(mapa) === 'lotes'
  if (!(mapa.getZoom() >= 17) || !noNivel) return

  const vista = mapa.getBounds().pad(folga)
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  for (const camada of mapaState.camadas || []) {
    const a = alertaDoLote(camada.feature?.properties)
    if (!a || !vista.intersects(camada.getBounds())) continue
    const centro = camada._centroDoNumero ||= (camada._map ? camada.getCenter() : camada.getBounds().getCenter())
    const p = mapa.latLngToContainerPoint(centro)
    // À direita e acima do número do lote, sem cobri-lo.
    const x = p.x + fx + 15, y = p.y + fy - 11
    const t = ALERTA_TIPOS[a.tipo]
    ctx.beginPath(); ctx.arc(x, y, a.justificado ? 6.5 : 8.5, 0, Math.PI * 2)
    ctx.fillStyle = a.justificado ? '#FFFFFF' : t.cor; ctx.fill()
    ctx.lineWidth = 1.5; ctx.strokeStyle = a.justificado ? '#0F6E56' : '#FFFFFF'; ctx.stroke()
    ctx.fillStyle = a.justificado ? '#0F6E56' : '#FFFFFF'
    const sinal = a.justificado ? '✓' : t.sinal
    ctx.font = `600 ${sinal.length > 1 ? 7.5 : a.justificado ? 9 : 11}px Inter, Arial, sans-serif`
    ctx.fillText(sinal, x, y + 0.5)
  }
}

// ── balão e ficha ───────────────────────────────────────────────

/** A faixa do balão, logo abaixo do título. */
function htmlAlertaNoBalao(p) {
  const a = alertaDoLote(p)
  if (!a) return ''
  const t = ALERTA_TIPOS[a.tipo]
  const corrigir = window.USUARIO_CURADOR && typeof abrirCorrecao === 'function' && !a.justificado
    ? `<button type="button" class="btn sm alerta-corrigir" onclick="corrigirDoAlerta()">Corrigir</button>` : ''
  return `<div class="alerta-faixa ${a.justificado ? 'alerta-just' : ''}" style="--alerta-cor:${t.cor}">
    <b>${a.justificado ? '✓ ' + esc(t.rotulo) + ' · justificado' : esc(t.rotulo)}</b>
    <span>${esc(a.justificado ? (a.motivo || '') : (a.detalhe ? a.detalhe + '. ' : '') + t.nota)}</span>
    ${a.conferido_em ? `<small>Conferência de ${esc(a.conferido_em)}</small>` : ''}${corrigir}</div>`
}

/** Do balão para o painel de correção do bairro, já na pendência deste lote. */
async function corrigirDoAlerta() {
  const p = state.selecionado?.properties
  const a = p?.alerta
  if (!p || !a) return
  mapaState.obj?.closePopup()
  // O painel de correção do bairro trabalha sobre a conferência carregada:
  // carrega a do bairro deste lote (sem abrir a janela) e vai à pendência dele.
  try {
    confState.bairro = p.bairro
    await _confCarregar(p.bairro, { janela: false })
  } catch (err) { toast(err.message || 'Não foi possível carregar a conferência do bairro.', 'err'); return }
  abrirCorrecao('bairro', _corrChave(a.tipo, { lote_id: p.id }))
}

/** A tag na ficha (cabeçalho fixo). */
function pintarAlertaNaFicha(p) {
  const alvo = document.getElementById('fi-alerta')
  if (!alvo) return
  const a = alertaDoLote(p)
  if (!a) { alvo.hidden = true; alvo.innerHTML = ''; return }
  const t = ALERTA_TIPOS[a.tipo]
  alvo.hidden = false
  alvo.innerHTML = `<span class="badge ${a.justificado ? 'bd-in' : t.tag}">${esc(t.rotulo)}${a.justificado ? ' · justificado' : ''}</span>
    <span class="fi-alerta-txt">${esc(a.justificado ? (a.motivo || '') : (a.detalhe ? a.detalhe + '. ' : '') + t.nota)}${a.conferido_em ? ' Conferência de ' + esc(a.conferido_em) + '.' : ''}</span>`
}
