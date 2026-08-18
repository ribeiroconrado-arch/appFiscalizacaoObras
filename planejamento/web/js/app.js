// ══════════════════════════════════════════════
// APLICAÇÃO — Fiscalização de Obras (protótipo da Etapa 3)
//
// Fluxo alvo do MVP-1: abrir o mapa → ver os lotes → clicar num lote e ver a
// ficha → "usar minha localização" e o sistema dizer em qual lote o fiscal está.
//
// Fonte de dados: por enquanto o GeoJSON estático gerado na Etapa 1. Quando o
// Laravel entrar, troca-se `carregarLotes()` por uma chamada a
// GET /api/mapa/lotes?bbox=... e `usarMinhaLocalizacao()` por
// POST /api/localizacao/identificar — o resto da tela não muda.
// ══════════════════════════════════════════════

/** Estado da aplicação. @type {{feicoes:Array, bairro:string, selecionado:Object|null, pos:Object|null}} */
const state = {
  feicoes: [],
  bairro: '',
  selecionado: null,
  pos: null,
}

/** Onde os lotes do piloto moram enquanto não há banco. */
const FONTE_LOTES = 'dados/lotes_jardim_europa.geojson'

// ── CARGA ────────────────────────────────────────────────────

/** Busca o GeoJSON e desenha no mapa. */
async function carregarLotes() {
  const r = await fetch(FONTE_LOTES)
  if (!r.ok) throw new Error('HTTP ' + r.status)
  const gj = await r.json()
  state.feicoes = gj.features
  state.bairro = gj.features[0]?.properties?.bairro || gj.name || ''
  desenharLotes(gj, abrirFicha)
  ajustarAoConteudo()

  document.getElementById('chip-txt').textContent =
    `${state.feicoes.length} lotes · ${state.bairro}`
}

/** Ponto de entrada, disparado no DOMContentLoaded. */
async function bootstrap() {
  iniciarMapa()
  mostrarCarregandoTela('Carregando lotes...')
  try {
    await carregarLotes()
  } catch (e) {
    console.error(e)
    toast('Não foi possível carregar os lotes', 'err')
  } finally {
    esconderCarregandoTela()
  }
}

// ── FICHA DO IMÓVEL ──────────────────────────────────────────

/**
 * Abre a ficha do lote. É o embrião da "Consulta de Imóvel" do §11 do projeto:
 * hoje mostra só o que a base GIS tem; obra, proprietário e histórico entram
 * quando o cadastro imobiliário for integrado (Etapa 4).
 *
 * @param {Object} feicao feição GeoJSON do lote
 */
function abrirFicha(feicao) {
  const p = feicao.properties
  state.selecionado = feicao

  document.getElementById('fi-titulo').textContent =
    `Quadra ${p.quadra ?? '—'} · Lote ${p.numero_lote ?? '—'}`
  document.getElementById('fi-bairro').textContent = p.bairro || '—'
  document.getElementById('fi-quadra').textContent = p.quadra ?? '—'
  document.getElementById('fi-lote').textContent = p.numero_lote ?? '—'
  document.getElementById('fi-area').textContent = fmtNum(p.area_gis_m2) + ' m²'
  document.getElementById('fi-chave').textContent = p.chave || '—'
  document.getElementById('fi-fonte').textContent = p.fonte || '—'

  const c = centroide(feicao.geometry)
  document.getElementById('fi-coord').textContent =
    `${c.lat.toFixed(6)}, ${c.lon.toFixed(6)}`

  // Distância até o fiscal, quando ele já capturou a posição — é o que responde
  // "esse lote é mesmo o que estou vendo na minha frente?"
  const linhaDist = document.getElementById('fi-linha-dist')
  if (state.pos) {
    const d = distanciaM(state.pos.lat, state.pos.lon, c.lat, c.lon)
    document.getElementById('fi-dist').textContent = `${Math.round(d)} m de você`
    linhaDist.style.display = ''
  } else {
    linhaDist.style.display = 'none'
  }

  openModal('m-ficha')
}

/** Ainda não implementado — a vistoria é a Etapa 5 do plano. */
function novaVistoria() {
  fModalBtn('m-ficha')
  const p = state.selecionado?.properties
  confirmarAcao({
    titulo: 'Nova vistoria',
    mensagem: `O cadastro de vistorias entra na Etapa 5 do plano. O lote ` +
              `${p?.quadra}/${p?.numero_lote} já está identificado e será ` +
              `vinculado automaticamente quando a tela existir.`,
    textoBtn: 'Entendi',
    onConfirm: () => {},
  })
}

// ── GPS → LOTE ───────────────────────────────────────────────

/**
 * Handler do botão "Usar minha localização". Toggle, no mesmo padrão de
 * geolocalizacao.js do AppPOSTURAS: capturado o GPS, o botão passa a oferecer
 * a remoção.
 */
function usarMinhaLocalizacao() {
  const btn = document.getElementById('btn-gps')
  if (btn.dataset.gpsCapturado) { removerGPS(); return }

  if (!navigator.geolocation) { toast('GPS não disponível neste aparelho', 'err'); return }
  btn.disabled = true
  document.getElementById('gps-txt').textContent = 'Localizando...'

  navigator.geolocation.getCurrentPosition(
    pos => {
      btn.disabled = false
      const { latitude: lat, longitude: lon, accuracy: prec } = pos.coords
      state.pos = { lat, lon, prec }
      marcarMinhaPosicao(lat, lon, prec)
      btn.classList.add('gps-ativo')
      btn.dataset.gpsCapturado = '1'
      document.getElementById('gps-txt').textContent = 'Remover GPS'
      resolverLocalizacao(lat, lon, prec)
    },
    err => {
      btn.disabled = false
      const m = { 1: 'Permissão de localização negada.', 2: 'Sinal de GPS fraco.', 3: 'Tempo esgotado.' }
      toast(m[err.code] || 'Erro ao obter GPS', 'err')
      document.getElementById('gps-txt').textContent = 'Usar minha localização'
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  )
}

/** Limpa a posição capturada e devolve o botão ao estado padrão. */
function removerGPS() {
  confirmarAcao({
    titulo: 'Remover localização',
    mensagem: 'Deseja limpar a sua posição do mapa?',
    textoBtn: 'Remover',
    perigo: true,
    onConfirm: () => {
      state.pos = null
      limparMinhaPosicao()
      const btn = document.getElementById('btn-gps')
      btn.classList.remove('gps-ativo')
      btn.dataset.gpsCapturado = ''
      document.getElementById('gps-txt').textContent = 'Usar minha localização'
      toast('Localização removida')
    },
  })
}

/**
 * Decide o que fazer com a coordenada capturada:
 * acerto direto abre a ficha; vários candidatos abrem a confirmação; nenhum
 * candidato diz isso com todas as letras, em vez de fingir um resultado.
 *
 * @param {number} lat @param {number} lon @param {number} prec
 */
function resolverLocalizacao(lat, lon, prec) {
  const { exato, candidatos } = identificarLote(lat, lon, prec, state.feicoes)

  if (exato) {
    destacarPorChave(exato.properties.chave)
    toast(`Você está no lote ${exato.properties.numero_lote}, quadra ${exato.properties.quadra}`)
    abrirFicha(exato)
    return
  }
  if (candidatos.length === 0) {
    toast('Nenhum lote da base piloto por perto. O piloto cobre só o Jardim Europa IV.', 'err')
    return
  }
  abrirConfirmacao(candidatos, prec)
}

/**
 * Tela de confirmação do §9 do projeto: o sistema não adivinha, oferece.
 * @param {Array<{feicao:Object,dist:number}>} candidatos
 * @param {number} prec precisão informada pelo GPS
 */
function abrirConfirmacao(candidatos, prec) {
  document.getElementById('cf-precisao').textContent =
    `Precisão do GPS: ±${Math.round(prec)} m`

  const lista = document.getElementById('cf-lista')
  lista.innerHTML = candidatos.map((c, i) => {
    const p = c.feicao.properties
    return `<div class="opcao${i === 0 ? ' sel' : ''}" data-chave="${esc(p.chave)}"
                 onclick="selecionarOpcao(this)">
              <div class="radio"></div>
              <div class="txt">
                <div class="t1">Lote ${esc(p.numero_lote)} · Quadra ${esc(p.quadra)}</div>
                <div class="t2">${esc(p.bairro)} · ${fmtNum(p.area_gis_m2)} m²</div>
              </div>
              <div class="dist">${Math.round(c.dist)} m</div>
            </div>`
  }).join('')

  openModal('m-confirmar-lote')
}

/** Marca a opção clicada na lista de confirmação. @param {HTMLElement} el */
function selecionarOpcao(el) {
  el.parentElement.querySelectorAll('.opcao').forEach(o => o.classList.remove('sel'))
  el.classList.add('sel')
}

/** Confirma a escolha do fiscal e abre a ficha do lote escolhido. */
function confirmarLote() {
  const sel = document.querySelector('#cf-lista .opcao.sel')
  if (!sel) { toast('Selecione um lote', 'err'); return }
  const chave = sel.dataset.chave
  const f = state.feicoes.find(x => x.properties.chave === chave)
  fModalBtn('m-confirmar-lote')
  if (f) { destacarPorChave(chave); abrirFicha(f) }
}

/** Reenquadra o mapa nos lotes carregados. */
function verTudo() {
  ajustarAoConteudo()
}

document.addEventListener('DOMContentLoaded', bootstrap)
