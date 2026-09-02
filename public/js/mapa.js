// ══════════════════════════════════════════════
// MÓDULO: MAPA (Leaflet)
//
// Duas camadas de fundo: mapa claro do CartoDB (mesmo tile do AppPOSTURAS —
// discreto, para não competir com os polígonos) e satélite da Esri, que é o
// que o fiscal quer ver quando está conferindo obra em campo.
//
// A camada de lotes é ACUMULATIVA: os dados chegam por bbox conforme o mapa se
// move, então cada resposta acrescenta polígonos em vez de recriar a camada.
// Recriar apagaria o que já está desenhado e faria o mapa piscar a cada arrasto.
// ══════════════════════════════════════════════

/** Estado do mapa. Objeto mutável simples, como em core/state.js do AppPOSTURAS. */
const mapaState = {
  /** @type {L.Map|null} */     obj: null,
  /** @type {L.GeoJSON|null} */ camadaLotes: null,
  /** @type {L.Marker|null} */  marcadorEu: null,
  /** @type {L.Circle|null} */  precisaoEu: null,
  /** @type {L.Path|null} */    destacado: null,
  /** id do lote -> camada Leaflet, para destacar sem varrer a camada inteira */
  porId: new Map(),
  /** todas as camadas de lote, para repintar na coloração por adjacência */
  camadas: [],
  /** já enquadrado na base? só ocorre quando a aba Mapa fica visível */
  pronto: false,
}

/** Cores dos polígonos. Hex puro: o Leaflet não lê variável CSS. */
const COR = { lote: '#006C16', loteFundo: '#009B3A', destaque: '#F5C400' }

/**
 * Retângulo inicial de navegação, trocado pelo bbox real do município assim
 * que a malha do IBGE carrega (ver recortarMunicipio). Existe só para o mapa
 * já nascer travado, antes do fetch responder.
 */
let LIMITE_MUNICIPIO = [[-15.70, -54.75], [-14.58, -53.72]]

function estiloLote() {
  return { color: COR.lote, weight: 1, opacity: .85, fillColor: COR.loteFundo, fillOpacity: .18 }
}
function estiloDestaque() {
  return { color: COR.destaque, weight: 3, opacity: 1, fillColor: COR.destaque, fillOpacity: .38 }
}

/** Cria o mapa. Idempotente. */
function iniciarMapa() {
  if (mapaState.obj) return
  if (typeof L === 'undefined') { console.warn('Leaflet não carregado'); return }

  // BASE ÚNICA: imagem de satélite.
  //
  // O mapa vetorial saiu, e com ele o seletor de camadas. É decisão de uso,
  // não de estilo: os polígonos de quadra e lote foram digitalizados sobre
  // foto aérea, então é sobre foto que eles coincidem com o que está no chão.
  // Sobre base vetorial o desenho e a rua não batem, e o fiscal passa a
  // duvidar do dado certo.
  //
  // maxNativeZoom 17: nesta região o acervo da Esri termina no zoom 17 —
  // z18, z19 e z20 devolvem o MESMO arquivo de 2.521 bytes, que é a placa
  // cinza "Map data not yet available". Verificado no centro, no Jardim
  // Europa, no Buritis e na entrada sul, e nas 196 capturas históricas do
  // acervo Wayback: nenhuma passa de 17. Declarando o limite real, o Leaflet
  // amplia o tile de 17 em vez de pedir um que não existe.
  const satelite = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { attribution: '© Esri', maxZoom: 20, maxNativeZoom: 17, className: 'tile-satelite' })

  mapaState.obj = L.map('map', {
    zoomControl: false, layers: [satelite],
    /*
     * CANVAS, NÃO SVG — e é isto que faz o mapa funcionar no celular.
     *
     * Com o renderizador padrão, cada lote vira um elemento <path> no DOM.
     * São 2.239 lotes no município: 2.239 elementos que o navegador precisa
     * criar, posicionar e transformar a cada arrasto e a cada zoom. No
     * desktop passa; no iOS (onde todo navegador é WebKit por baixo, Chrome
     * inclusive) o mapa fica arrastado a ponto de não dar para usar.
     *
     * No canvas os polígonos são pintados num bitmap só — um elemento, não
     * milhares. Perde-se a possibilidade de estilizar lote por CSS, o que
     * aqui não custa nada: os estilos saem todos de estiloColorido(), em
     * opções do Leaflet (color, weight, fillColor), que o canvas suporta.
     */
    preferCanvas: true,
    // Prende a navegação ao município: viscosity 1 faz a borda não ceder,
    // então arrastar para fora simplesmente não sai do lugar.
    maxBounds: LIMITE_MUNICIPIO, maxBoundsViscosity: 1, minZoom: 11,
  })
  L.control.zoom({ position: 'topright' }).addTo(mapaState.obj)

  // Sem o "Leaflet |" no rodapé: o espaço ali é curto e o que precisa aparecer
  // é o crédito da IMAGEM — exigido pelos termos de uso da API e útil ao
  // fiscal, que vê de quem e de que ano é a foto que está olhando.
  //
  // `setPrefix` depois de criar o mapa, e não `attributionControl: {prefix}`
  // nas opções: o Leaflet lê essa opção só como sim/não e cria o controle sem
  // repassar nada. Passar o objeto não dá erro — dá o prefixo lá, do mesmo
  // jeito, que foi o que aconteceu aqui antes de medir no navegador.
  mapaState.obj.attributionControl.setPrefix(false)

  montarOrtofoto(satelite)

  // estiloColorido, e não estiloLote: o lote nasce já na coloração corrente
  // (uniforme, por bairro ou destacado por filtro). Com estiloLote ele nascia
  // verde e só era repintado na chamada seguinte de aplicarCores.
  mapaState.camadaLotes = L.geoJSON(null, { style: f => estiloColorido(f) }).addTo(mapaState.obj)

  // Painel dedicado aos rótulos de logradouro, acima dos lotes.
  mapaState.obj.createPane('rotulos')
  mapaState.obj.getPane('rotulos').style.zIndex = 650
  mapaState.obj.getPane('rotulos').style.pointerEvents = 'none'
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png',
    { subdomains: 'abcd', maxZoom: 20, pane: 'rotulos' }).addTo(mapaState.obj)

  montarGoogle(satelite)
  ancorarControleCores()

  mapaState.obj.on('zoomend', () => { rotulosPorZoom(); ajustarNitidezSatelite() })

  // Arrastar também mexe nos rótulos: no zoom em que eles aparecem, cada
  // deslocamento traz lotes novos para a tela e leva outros embora.
  mapaState.obj.on('moveend', sincronizarRotulos)
  mapaState.obj.on('baselayerchange', () => ajustarNitidezSatelite())

  recortarMunicipio()
}

/**
 * Realce leve quando a imagem aérea está sendo ampliada além do zoom que ela
 * realmente tem.
 *
 * Não inventa detalhe — isso é impossível: o pixel que não foi fotografado
 * não existe. O que o filtro faz é recuperar contraste e definição de borda
 * que a interpolação do navegador achata, deixando telhado e muro um pouco
 * mais legíveis. É um ganho de leitura, não de resolução. A solução de fato
 * é a ortofoto municipal.
 */
function ajustarNitidezSatelite() {
  const m = mapaState.obj
  if (!m) return

  const z = m.getZoom()

  // Com o Google no ar acima do zoom 18, a imagem no topo é nativa e não
  // precisa de realce nenhum — aplicá-lo ali só degradaria uma foto boa.
  const g = mapaState.googleTiles
  if (g && m.hasLayer(g) && z >= (g.options.minZoom ?? 18)) {
    document.getElementById('map')?.classList.remove('sat-ampliado')
    return
  }

  let ampliando = false
  m.eachLayer(l => {
    if (!l.options?.className?.includes('tile-satelite')) return
    const nativo = l.options.maxNativeZoom ?? l.options.maxZoom ?? 20
    if (z > nativo) ampliando = true
  })

  document.getElementById('map')?.classList.toggle('sat-ampliado', ampliando)
}

/**
 * Imagem aérea do Google (Map Tiles API), quando configurada.
 *
 * Entra como camada BASE ao lado do satélite da Esri, e não como
 * sobreposição: cobre o município inteiro, então não há área sem imagem
 * para o Esri complementar. O acervo do Google nesta região chega ao zoom
 * 20 — contra 17 da Esri —, que é o ganho de nitidez procurado.
 *
 * A sessão vem do servidor (ver MapaController::googleSessao). Se a API não
 * estiver habilitada ou a chave for recusada, a camada simplesmente não
 * aparece e o mapa segue com o que já tinha.
 *
 * @param {L.TileLayer} satelite camada da Esri, usada como referência de ordem
 */
async function montarGoogle(satelite) {
  try {
    const r = await fetch('/api/mapa/google-sessao', { headers: { Accept: 'application/json' } })
    if (!r.ok) {
      if (r.status !== 404) {
        console.warn('Google Tiles indisponível:', (await r.json()).message)
      }
      return
    }
    const { session, key, creditos } = await r.json()

    const google = L.tileLayer(
      `https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session=${session}&key=${key}`,
      {
        // O crédito vem do próprio Google (endpoint `viewport`) e traz quem
        // produziu a imagem e o ANO dela — "Imagens ©2026 Airbus, Maxar
        // Technologies". É a única informação de tempo que a API expõe: data
        // de captura, dia e mês, ela não devolve. Exibi-lo também é exigência
        // dos termos de uso, que o "© Google" fixo não cumpria.
        attribution: creditos || '© Google', maxZoom: 20, maxNativeZoom: 20,
        // ── O PONTO DE ECONOMIA ──
        // minZoom 18: abaixo disso o Leaflet nem pede o tile, e a Esri (que é
        // gratuita) cobre sozinha. Como o acervo da Esri aqui para no 17, do
        // 18 em diante ela só ampliaria o mesmo pixel — que é exatamente onde
        // o Google passa a acrescentar detalhe de verdade.
        //
        // Ou seja: paga-se pelo tile só no zoom em que ele melhora a imagem.
        // Navegar, procurar bairro e enquadrar quadra acontece abaixo de 18 e
        // não gera uma requisição sequer.
        minZoom: 18,
        className: 'tile-satelite',
      }
    )

    // Sobreposição sempre ligada, e não base alternativa: sem seletor, quem
    // decide qual imagem aparece é o zoom. Acima do 17 o Google cobre a Esri;
    // abaixo, ele simplesmente não existe. Se a chave for recusada ou a API
    // falhar, a Esri continua embaixo e o mapa não fica em branco.
    google.addTo(mapaState.obj)
    mapaState.googleTiles = google
  } catch (e) {
    console.warn('Não foi possível preparar a camada do Google:', e)
  }
}

/**
 * Registra o painel de cores como CONTROLE do Leaflet, no mesmo canto do
 * zoom e do seletor de camadas.
 *
 * Medir a posição e aplicar `top` no CSS não funciona: quando o mapa é
 * criado ele ainda está escondido (o app abre no Painel), então a medição
 * sai zerada e o botão gruda no topo. Como controle, quem empilha é o
 * próprio Leaflet — e a ordem se ajusta sozinha se o seletor de camadas
 * mudar de altura ao ganhar a ortofoto.
 */
function ancorarControleCores() {
  const el = document.getElementById('ctrl-mapa')
  if (!el) return

  const Cores = L.Control.extend({
    onAdd() {
      // Sem isto, clicar nos botões arrasta o mapa e a roda dá zoom.
      L.DomEvent.disableClickPropagation(el)
      L.DomEvent.disableScrollPropagation(el)
      return el
    },
    onRemove() {},
  })

  new Cores({ position: 'topright' }).addTo(mapaState.obj)
  igualarAoControleDeCamadas()
}

/**
 * Copia as medidas do seletor de camadas do Leaflet para os nossos botões.
 *
 * Fixar 36px ou 40px no CSS não resolve: o tamanho do controle de camadas
 * muda com o modo de toque (36 no ponteiro, 44 no toque) e com a versão da
 * biblioteca, e qualquer número escrito à mão fica errado num dos casos — foi
 * assim que os botões saíram ~10% menores que o vizinho. Medindo o vizinho de
 * verdade, eles casam sempre.
 */
function igualarAoControleDeCamadas() {
  const vizinho = document.querySelector('.leaflet-control-layers')
  if (!vizinho) return

  const r = vizinho.getBoundingClientRect()
  if (!r.width || !r.height) return

  document.getElementById('ctrl-mapa')?.style.setProperty('--ctrl-lado-l', r.width + 'px')
  document.getElementById('ctrl-mapa')?.style.setProperty('--ctrl-lado-a', r.height + 'px')
}

/**
 * Abre e fecha um painel da coluna de controles.
 *
 * O grupo aberto troca o ícone pelo painel (o CSS cuida disso a partir da
 * classe `.aberto`), como faz o seletor de camadas do Leaflet logo acima.
 * Só um por vez: abertos juntos, os painéis empilhados cobririam o mapa, que
 * é justamente o que recolhê-los pretendia evitar.
 *
 * @param {string} idGrupo grupo a alternar
 */
function alternarPainelMapa(idGrupo) {
  // O grupo cadastral tem casa própria em tela grande: a mesa lateral. O ícone
  // continua sendo a porta, mas o que ele abre é a coluna, não o popover — que
  // ali seria um segundo menu com exatamente os mesmos botões.
  if (idGrupo === 'grupo-cadastro' && typeof ehMesaCadastral === 'function' && ehMesaCadastral()) {
    const mesa = document.getElementById('cad-mesa')
    if (mesa) {
      fecharPaineisMapa()
      if (mesa.hidden) { abrirMesaCadastral('Edição cadastral') } else { fecharMesaCadastral() }
      return
    }
  }

  const grupo = document.getElementById(idGrupo)
  if (!grupo) return
  const abrindo = !grupo.classList.contains('aberto')

  fecharPaineisMapa()
  if (!abrindo) return

  grupo.classList.add('aberto')
  grupo.querySelector('.ctrl-btn')?.setAttribute('aria-expanded', 'true')

  // Ao abrir a busca o cursor já entra no campo — quem clicou na lupa quer
  // digitar, não clicar de novo.
  if (idGrupo === 'grupo-busca') setTimeout(() => document.getElementById('mb-termo')?.focus(), 20)
  if (idGrupo === 'grupo-pins') popularBairrosPins()
}

/**
 * Escreve (ou apaga) o selo de estado no ícone recolhido de um controle.
 *
 * Recolhido, o botão não diz nada sobre o que está aplicado — e um mapa com
 * pinos filtrados ou pintado por quadra parece um mapa comum para quem chegou
 * depois. O selo é o único aviso de que há filtro em vigor.
 *
 * @param {string} idGrupo
 * @param {string|number|null} texto null apaga o selo
 */
function marcarIndicadorControle(idGrupo, texto) {
  const btn = document.querySelector('#' + idGrupo + ' .ctrl-btn')
  if (!btn) return

  let selo = btn.querySelector('.ctrl-selo')
  if (texto === null || texto === '' || texto === 0) {
    selo?.remove()
    btn.classList.remove('com-filtro')
    return
  }

  if (!selo) {
    selo = document.createElement('span')
    selo.className = 'ctrl-selo'
    btn.appendChild(selo)
  }
  selo.textContent = String(texto)
  btn.classList.add('com-filtro')
}

/** Recolhe todos os painéis. */
function fecharPaineisMapa() {
  for (const g of document.querySelectorAll('#ctrl-mapa .ctrl-grupo')) {
    g.classList.remove('aberto')
    g.querySelector('.ctrl-btn')?.setAttribute('aria-expanded', 'false')
  }
}

// Clique fora recolhe o painel, como no seletor de camadas.
//
// O teste de "fora" é explícito, e não pode ser delegado ao Leaflet: o
// disableClickPropagation aplicado em ancorarControleCores intercepta
// mousedown, touchstart, dblclick e contextmenu — mas NÃO o `click`. Sem esta
// verificação, o clique que abria o painel subia até aqui e o fechava no mesmo
// gesto (no desktop o painel nunca abria; no tablet abria no segundo toque e
// depois fechava ao tocar em qualquer campo de dentro).
document.addEventListener('click', ev => {
  if (ev.target.closest?.('#ctrl-mapa')) return
  fecharPaineisMapa()
})

/**
 * Localiza um imóvel e leva o mapa até ele.
 *
 * Campo único de propósito: aceita bairro, inscrição imobiliária, chave ou
 * "quadra lote", e quem decide o que é cada coisa é o servidor (ver
 * BuscaController::aplicarFiltros). Obrigar o usuário a escolher antes em qual
 * campo o que ele sabe se encaixa é transferir a ele um trabalho que a
 * consulta faz sozinha.
 */
async function buscarNoMapa() {
  const termo = document.getElementById('mb-termo').value.trim()
  const saida = document.getElementById('mb-resultado')
  if (!termo) { saida.textContent = 'Digite bairro, inscrição imobiliária ou “quadra lote”.'; return }

  saida.textContent = 'Procurando…'

  try {
    const p = new URLSearchParams({ termo })
    const r = await fetch('/api/imoveis/busca?' + p, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)
    if (!d.imoveis.length) { saida.textContent = 'Nenhum imóvel encontrado.'; return }

    // Vários acertos NÃO viram pino. O pino é a resposta do filtro, que é uma
    // pergunta sobre situação ("onde estão os embargados"); a busca é uma
    // pergunta sobre identidade ("cadê este imóvel"), e enfileirar alfinetes
    // por um termo genérico só suja o mapa. Aqui o resultado é pintado: os
    // lotes que casam ganham destaque, os demais recuam.
    if (d.imoveis.length > 1) {
      destacarLotes(d.imoveis.map(i => i.id))
      saida.innerHTML = `${d.total} imóveis destacados. `
        + '<a href="#" onclick="limparDestaqueMapa();return false">Limpar</a>'
      return
    }

    const f = await fetch('/api/imoveis/' + d.imoveis[0].id, { headers: { Accept: 'application/json' } })
    const ficha = await f.json()
    if (!ficha.lat) { saida.textContent = 'Imóvel sem geometria cadastrada.'; return }

    mapaState.obj?.setView([ficha.lat, ficha.lon], 19)
    saida.innerHTML = `${esc(ficha.bairro || '')} · Q ${esc(ficha.quadra ?? '—')} · Lt ${esc(ficha.lote ?? '—')}`
  } catch (e) {
    console.error(e)
    saida.textContent = e.message || 'Falha na busca.'
  }
}

// ── PINOS POR FILTRO ─────────────────────────────────────────
// Marcam no mapa os imóveis que atendem a um critério de fiscalização.
// Camada própria, separada dos polígonos: os pinos entram e saem sem tocar
// nos lotes desenhados, e o "Limpar" é remover uma camada, não redesenhar o
// mapa inteiro.

/** @type {L.LayerGroup|null} */
let camadaPins = null

/** Preenche o seletor de bairro do painel, uma vez. */
async function popularBairrosPins() {
  const sel = document.getElementById('pin-bairro')
  if (!sel || sel.dataset.pronto) return
  try {
    const r = await fetch('/api/imoveis/bairros', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    sel.innerHTML = '<option value="">Bairro — todos</option>'
      + d.bairros.map(b => `<option value="${esc(b)}">${esc(b)}</option>`).join('')
    sel.dataset.pronto = '1'
  } catch (e) { console.error(e) }
}

/** Lê o painel e marca no mapa o que o filtro devolver. */
async function marcarPins() {
  const saida = document.getElementById('pin-resultado')
  const p = new URLSearchParams()

  const bairro = document.getElementById('pin-bairro').value
  const vistoria = document.getElementById('pin-vistoria').value
  if (bairro) p.set('bairro', bairro)
  if (vistoria) p.set('vistoria', vistoria)
  if (document.getElementById('pin-embargo').checked) p.set('embargo', '1')
  if (document.getElementById('pin-pendente').checked) p.set('doc_pendente', '1')
  if (document.getElementById('pin-sem-vistoria').checked) p.set('obra_sem_vistoria', '1')

  if (![...p.keys()].length) { saida.textContent = 'Escolha ao menos um filtro.'; return }

  saida.textContent = 'Marcando…'

  try {
    const r = await fetch('/api/imoveis/pins?' + p, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)

    if (!d.pins.length) { limparPins(); saida.textContent = 'Nenhum imóvel atende ao filtro.'; return }

    plotarPins(d.pins)
    marcarIndicadorControle('grupo-pins', d.total)

    // O filtro também PINTA os lotes que atendem. O pino diz onde procurar
    // de longe; a cor mostra qual é o lote quando o zoom chega perto, onde o
    // alfinete já cobre a própria construção que se quer ver.
    destacarLotes(d.pins.map(p => p.id))

    saida.innerHTML = `${d.total} marcado(s).`
      + (d.truncado ? ` <b>Teto de ${d.teto} — refine o filtro.</b>` : '')
  } catch (e) {
    console.error(e)
    saida.textContent = e.message || 'Falha ao marcar.'
  }
}

/**
 * Busca as coordenadas de uma lista de ids e marca. Usada pela busca do mapa,
 * quando o termo casa com vários imóveis.
 * @param {number[]} ids @returns {Promise<number>} quantos foram marcados
 */
async function desenharPins(ids) {
  const pins = []
  // Sequencial e limitado: a ficha é uma consulta por imóvel, e disparar
  // duzentas de uma vez trava o aparelho do fiscal — que é o alvo.
  for (const id of ids.slice(0, 60)) {
    try {
      const r = await fetch('/api/imoveis/' + id, { headers: { Accept: 'application/json' } })
      const f = await r.json()
      if (f.lat) pins.push({ id: f.id, lat: f.lat, lon: f.lon, bairro: f.bairro, quadra: f.quadra, lote: f.lote })
    } catch (e) { /* um imóvel sem geometria não invalida os demais */ }
  }
  if (pins.length) plotarPins(pins)
  return pins.length
}

/** @param {Array<{id:number,lat:number,lon:number,bairro:string,quadra:string,lote:string}>} pins */
function plotarPins(pins) {
  if (!mapaState.obj) return
  limparPins()

  camadaPins = L.layerGroup(pins.map(p => {
    const m = L.marker([p.lat, p.lon], { title: `Q ${p.quadra ?? '—'} · Lt ${p.lote ?? '—'}` })
    m.bindPopup(
      `<div class="pin-balao"><b>Quadra ${esc(p.quadra ?? '—')} · Lote ${esc(p.lote ?? '—')}</b>`
      + `<div>${esc(p.bairro || '')}</div>`
      + `<button type="button" onclick="abrirFichaDoPin(${p.id})">Abrir ficha</button></div>`
    )
    return m
  })).addTo(mapaState.obj)

  // Enquadra o conjunto: marcar cem imóveis e deixar o mapa onde estava
  // esconde o resultado do próprio filtro.
  const grupo = L.featureGroup(camadaPins.getLayers())
  mapaState.obj.fitBounds(grupo.getBounds().pad(0.15))
}

/** Limpa só a pintura de destaque, mantendo os pinos (usado pela busca). */
function limparDestaqueMapa() {
  destacarLotes(null)
  const saida = document.getElementById('mb-resultado')
  if (saida) saida.textContent = 'Digite bairro, inscrição imobiliária ou “quadra lote”.'
}

function limparPins() {
  marcarIndicadorControle('grupo-pins', null)
  destacarLotes(null)
  if (camadaPins) {
    mapaState.obj?.removeLayer(camadaPins)
    camadaPins = null
  }
  const saida = document.getElementById('pin-resultado')
  if (saida) saida.textContent = 'Escolha ao menos um filtro.'
}

/** Abre a ficha do imóvel a partir do balão de um pino. @param {number} id */
function abrirFichaDoPin(id) {
  irPara('busca')
  setTimeout(() => abrirImovel(id), 60)
}

/**
 * Ortofoto em modo HÍBRIDO, sobreposta ao satélite.
 *
 * A ortofoto entra como camada de cima, não como base alternativa. Isso
 * resolve dois problemas de uma vez:
 *
 *   1. cobertura parcial — a imagem municipal costuma cobrir só a mancha
 *      urbana; com `bounds`, o Leaflet nem pede tile fora dela, e o satélite
 *      continua aparecendo no resto do município;
 *   2. zoom — com `minZoom`, ela só entra quando o satélite já esgotou o que
 *      tinha (zoom 17). Abaixo disso o Esri dá contexto melhor e a ortofoto
 *      seria download desperdiçado.
 *
 * O resultado é o que o mapa deve fazer: mostrar a melhor imagem disponível
 * para aquele ponto e aquela escala, sem o fiscal escolher camada.
 *
 * @param {L.TileLayer} satelite camada base que a ortofoto complementa
 */
function montarOrtofoto(satelite) {
  const alt = window.SATELITE_ALT
  if (!alt?.url) return

  const m = mapaState.obj

  const opcoes = {
    attribution: alt.atribuicao || '',
    maxZoom: 20,
    maxNativeZoom: Number(alt.maxNativeZoom) || 19,
    // Só pede tile a partir do zoom em que o satélite já não ajuda.
    minZoom: Number(alt.minZoom) || 17,
    // O Mapbox serve tile de 512 px (@2x). Declarar o tamanho evita que o
    // Leaflet trate como 256 e desloque a imagem meio tile.
    tileSize: Number(alt.tamanhoTile) || 256,
    zoomOffset: Number(alt.tamanhoTile) === 512 ? -1 : 0,
    className: 'tile-satelite',
    // Fora da área coberta o tile simplesmente não é requisitado.
    bounds: alt.bounds ? L.latLngBounds(alt.bounds) : undefined,
  }

  const orto = L.tileLayer(alt.url, opcoes)
  mapaState.ortofoto = orto

  // Sempre ligada, sem entrada em seletor: o seletor de camadas saiu junto com
  // a base vetorial. Como a ortofoto só se manifesta dentro da área e do zoom
  // dela, ligá-la de vez não atrapalha o resto — e é a melhor imagem que
  // existe onde existe.
  //
  // Ela fica ACIMA do Google porque é imagem municipal, de voo próprio e mais
  // recente; onde houver ortofoto, é ela que vale — e cada tile dela é um
  // tile do Google que deixa de ser cobrado.
  orto.addTo(m)
  orto.bringToFront?.()
}

/**
 * Recorta o mapa no limite de Primavera do Leste.
 *
 * A máscara é UM polígono com dois anéis: o externo cobre o mundo, o interno
 * é o município. Pela regra even-odd, o interior do anel interno fica de
 * fora do preenchimento — ou seja, o município aparece limpo e todo o resto
 * some sob a cor de fundo. É mais barato que recortar cada tile e funciona
 * igual nas duas bases (mapa e satélite).
 *
 * O contorno vai numa camada própria, acima dos lotes, para a divisa
 * continuar visível quando o fiscal estiver com a malha toda desenhada.
 */
async function recortarMunicipio() {
  try {
    const r = await fetch('/geo/primavera-do-leste.geojson', { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const f = await r.json()

    // GeoJSON é [lon, lat]; o Leaflet quer [lat, lon].
    const paraLatLng = anel => anel.map(([lon, lat]) => [lat, lon])
    const aneis = f.geometry.type === 'MultiPolygon'
      ? f.geometry.coordinates.flat().map(paraLatLng)
      : f.geometry.coordinates.map(paraLatLng)

    const mundo = [[-90, -360], [-90, 360], [90, 360], [90, -360]]

    mapaState.obj.createPane('mascara')
    mapaState.obj.getPane('mascara').style.zIndex = 350
    mapaState.obj.getPane('mascara').style.pointerEvents = 'none'

    L.polygon([mundo, ...aneis], {
      pane: 'mascara', stroke: false, fillColor: '#FAF7F4', fillOpacity: .93,
      interactive: false,
    }).addTo(mapaState.obj)

    const contorno = L.polygon(aneis, {
      pane: 'rotulos', color: '#EA580C', weight: 1.6, opacity: .75,
      fill: false, interactive: false,
    }).addTo(mapaState.obj)

    // Limite de navegação = o próprio município, com uma folga pequena para
    // a divisa não colar na borda da tela.
    LIMITE_MUNICIPIO = contorno.getBounds().pad(0.04)
    mapaState.obj.setMaxBounds(LIMITE_MUNICIPIO)
  } catch (e) {
    // Sem a malha o mapa continua utilizável: perde o recorte, mantém o
    // retângulo de navegação declarado acima.
    console.warn('Não foi possível carregar o limite do município:', e)
  }
}

/**
 * Acrescenta lotes à camada existente, sem recriá-la.
 * @param {Object} geojson FeatureCollection
 * @param {(feicao:Object)=>void} aoClicar
 */
function adicionarAoMapa(geojson, aoClicar) {
  L.geoJSON(geojson, {
    // A coloração corrente vale já na criação — ver o comentário em
    // iniciarMapa(). Nascer verde e ser repintado depois fazia o lote piscar.
    style: f => estiloColorido(f),
    onEachFeature: (feicao, camada) => {
      mapaState.porId.set(feicao.properties.id, camada)
      mapaState.camadas.push(camada)
      // Clique abre o BALÃO, não a ficha: a maior parte das consultas em
      // campo é "que lote é este?", e para isso abrir um modal de tela cheia
      // é caro demais. A ficha completa fica a um toque de distância, dentro
      // do balão.
      //
      // Em modo de correção cadastral o clique MARCA o lote em vez de abrir o
      // balão — ver cadastro.js. Fora dele, nada muda.
      camada.on('click', () => {
        if (typeof selecaoAtiva === 'function' && selecaoAtiva()) {
          alternarSelecao(feicao, camada)
          return
        }
        destacar(camada)
        abrirBalao(feicao, camada)
      })
      // O número do lote fica GUARDADO, não criado.
      //
      // Antes, cada lote recebia aqui um tooltip permanente, escondido por CSS
      // abaixo do zoom 18. Escondido por CSS continua existindo: eram 2.239
      // elementos que o Leaflet reposicionava a cada arrasto e a cada zoom,
      // invisíveis e caros — a maior fonte de lentidão do mapa no celular.
      //
      // Agora o rótulo nasce só quando vai ser visto, e só para os lotes que
      // estão na tela. Ver sincronizarRotulos().
      camada._numeroLote = feicao.properties.numero_lote
        ? String(feicao.properties.numero_lote) : null
      mapaState.camadaLotes.addLayer(camada)
    },
  })

  // Os lotes chegam por lote (a cada movimento do mapa). Sem esta chamada, os
  // que entram depois só ganhariam rótulo no próximo arrasto.
  sincronizarRotulos()
}

/** A partir deste zoom o número do lote é legível — abaixo, vira borrão. */
const ZOOM_ROTULO_LOTE = 18

/**
 * Cria e destrói os rótulos de lote conforme o zoom e o que está na tela.
 *
 * Dois cortes, e os dois importam. O do ZOOM evita 2.239 elementos existirem
 * quando nenhum deles seria legível. O do ENQUADRAMENTO evita que, no zoom em
 * que eles aparecem, sejam criados também os do bairro inteiro — em zoom 18
 * cabem algumas dezenas de lotes na tela, não milhares.
 */
function sincronizarRotulos() {
  const mapa = mapaState.obj
  if (!mapa) { return }

  const mostrar = mapa.getZoom() >= ZOOM_ROTULO_LOTE
  const vista = mostrar ? mapa.getBounds().pad(0.2) : null

  for (const camada of mapaState.camadas) {
    if (!camada._numeroLote) { continue }

    // `getBounds` do polígono é barato: o Leaflet já o mantém calculado.
    const dentro = mostrar && vista.intersects(camada.getBounds())
    const tem = !!camada.getTooltip()

    if (dentro && !tem) {
      camada.bindTooltip(camada._numeroLote,
        { permanent: true, direction: 'center', className: 'rot rot-lote' })
    } else if (!dentro && tem) {
      camada.unbindTooltip()
    }
  }
}

/**
 * Balão com o essencial do lote e o caminho para a ficha completa.
 *
 * @param {Object} feicao feição GeoJSON
 * @param {L.Path} camada polígono clicado
 */
function abrirBalao(feicao, camada) {
  const p = feicao.properties
  state.selecionado = feicao

  const area = p.area_gis_m2
    ? Number(p.area_gis_m2).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' m²'
    : 'área não informada'

  // O chip só aparece quando existe inscrição imobiliária — o código pelo
  // qual a prefeitura conhece o imóvel. Cair para a chave interna aqui
  // repetiria bairro, quadra e lote, que já estão no título; enquanto o
  // cadastro não é integrado (Etapa 4), o balão fica sem o chip.
  const html = `
    <div class="balao">
      <div class="balao-tit">Quadra ${esc(p.quadra ?? '—')} · Lote ${esc(p.numero_lote ?? '—')}</div>
      <div class="balao-sub">${esc(p.bairro ?? '')}</div>
      ${p.inscricao ? `<div class="balao-chip">${esc(p.inscricao)}</div>` : ''}
      <div class="balao-area">${area}</div>
      <button class="btn primary sm balao-btn" onclick="abrirFichaDoBalao()">Ver ficha completa</button>
    </div>`

  camada.bindPopup(html, {
    className: 'popup-lote', closeButton: true, maxWidth: 260, autoPan: true,
  }).openPopup()
}

/** Ponte do balão para a ficha: o lote já está em `state.selecionado`. */
function abrirFichaDoBalao() {
  mapaState.obj?.closePopup()
  if (state.selecionado) abrirFicha(state.selecionado)
}

// O "apagar" saiu do balão: resíduo raramente vem sozinho — a conversão do
// DWG deixa faixas em série —, e apagar de um em um, abrindo e fechando balão,
// é trabalho repetido. Ele virou modo de SELEÇÃO no painel de correção
// cadastral, onde já se marcam vários lotes: ver `modoCadastral('apagar')`.

/**
 * Destaca uma camada, devolvendo a anterior à coloração CORRENTE.
 *
 * Antes ela voltava com `estiloLote()`, o verde fixo do começo do projeto —
 * então cada lote que saía da seleção ficava verde, independentemente de o
 * mapa estar uniforme, colorido por bairro ou com filtro aplicado. É a mesma
 * razão de o estilo inicial da camada também ler `estiloColorido`: só existe
 * uma resposta para "de que cor este lote deveria estar", e é essa.
 *
 * @param {L.Path} camada
 */
function destacar(camada) {
  if (mapaState.destacado) {
    mapaState.destacado.setStyle(estiloColorido(mapaState.destacado.feature))
  }
  mapaState.destacado = camada
  camada.setStyle(estiloDestaque())
  camada.bringToFront()
}

/**
 * Tira a seleção do lote e fecha o que ela abriu.
 *
 * Sem isto o único jeito de largar um lote era selecionar outro — e o fiscal
 * que clicou por engano ficava com um lote preso em amarelo e com o "Novo
 * documento" apontando para o imóvel errado.
 */
function limparSelecao() {
  if (mapaState.destacado) {
    mapaState.destacado.setStyle(estiloColorido(mapaState.destacado.feature))
    mapaState.destacado = null
  }
  state.selecionado = null
  mapaState.obj?.closePopup()
}

// Esc larga a seleção. Só age quando não há modal aberto: dentro de um
// formulário, Esc é do formulário, e desfazer a seleção por baixo dele
// deixaria o documento sem imóvel sem que ninguém pedisse.
document.addEventListener('keydown', ev => {
  if (ev.key !== 'Escape') return
  if (document.querySelector('.modal-bg.open')) return

  // O modo de correção sai PRIMEIRO e consome o Esc. Sem o `return`, os dois
  // comportamentos disparariam no mesmo toque: a pessoa sairia do modo e ainda
  // perderia a seleção de consulta por baixo, sem ter pedido.
  if (typeof selecaoAtiva === 'function' && selecaoAtiva()) {
    desligarSelecao()
    return
  }

  limparSelecao()
})

/** Destaca e enquadra um lote pelo id. @param {number} id */
function destacarPorId(id) {
  const c = mapaState.porId.get(id)
  if (!c) return
  destacar(c)
  mapaState.obj.fitBounds(c.getBounds(), { padding: [80, 80], maxZoom: 19 })
}

/**
 * Marca a posição do fiscal com o círculo de imprecisão do próprio GPS —
 * mostrar o raio é o que faz o fiscal entender por que o sistema às vezes
 * pergunta em vez de afirmar.
 *
 * @param {number} lat @param {number} lon @param {number} accuracy
 */
function marcarMinhaPosicao(lat, lon, accuracy) {
  limparMinhaPosicao()
  mapaState.precisaoEu = L.circle([lat, lon], {
    radius: Math.max(accuracy || 0, 5),
    color: '#1565C0', weight: 1, opacity: .5, fillColor: '#1565C0', fillOpacity: .12,
  }).addTo(mapaState.obj)
  mapaState.marcadorEu = L.marker([lat, lon], {
    icon: L.divIcon({ className: '', html: '<div class="eu-pin"></div>',
                      iconSize: [16, 16], iconAnchor: [8, 8] }),
    zIndexOffset: 1000,
  }).addTo(mapaState.obj)
}

/** Remove o marcador de posição do fiscal. */
function limparMinhaPosicao() {
  if (mapaState.marcadorEu) { mapaState.marcadorEu.remove(); mapaState.marcadorEu = null }
  if (mapaState.precisaoEu) { mapaState.precisaoEu.remove(); mapaState.precisaoEu = null }
}
