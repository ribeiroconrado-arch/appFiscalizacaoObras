// ══════════════════════════════════════════════
// APLICAÇÃO — Fiscalização de Obras
//
// Fluxo do MVP-1: abrir o mapa → ver os lotes → clicar num lote e ver a ficha
// → "usar minha localização" e o sistema dizer em qual lote o fiscal está.
//
// Diferença para o protótipo da Etapa 3: os dados agora vêm da API Laravel.
// Os lotes carregam por BBOX, à medida que o mapa se move — o município tem
// 23.662 lotes e mandar tudo de uma vez trava o aparelho do fiscal. E a
// identificação por GPS é resolvida no banco, porque o servidor tem a base
// inteira enquanto o navegador só tem o que está na tela.
// ══════════════════════════════════════════════

/** Estado da aplicação. Objeto mutável simples, como em core/state.js do AppPOSTURAS. */
const state = {
  /** Lotes já carregados, indexados por id — evita redesenhar ao voltar a uma área. */
  lotes: new Map(),
  /** @type {{lat:number, lon:number, prec:number}|null} */ pos: null,
  /** @type {Object|null} */ selecionado: null,
  carregando: false,
  truncado: false,
}

/**
 * Abaixo deste zoom não se pedem lotes.
 *
 * Era 15, o que travava a carga justamente ao afastar para ver a base
 * inteira — os dois bairros do piloto ficam a 4 km um do outro e só cabem
 * juntos por volta do zoom 13. Quem protege contra pedido absurdo é o teto
 * do servidor (MAPA_MAX_LOTES), que sinaliza `truncado` e faz o mapa avisar.
 */
const ZOOM_MINIMO = 12

// ── CARGA POR BBOX ───────────────────────────────────────────

/**
 * Busca na API os lotes do retângulo visível e acrescenta ao mapa.
 * Só pede o que ainda não tem: `state.lotes` é o cache da sessão.
 */
async function carregarLotesVisiveis() {
  const mapa = mapaState.obj
  if (!mapa || state.carregando) return

  // Mapa escondido (o app abre no Painel) tem contêiner de tamanho zero, e
  // aí getBounds() devolve os quatro cantos no mesmo ponto — a API recusava
  // esse bbox degenerado com 422 e a base ficava vazia até o usuário
  // arrastar o mapa. Quem entra na aba dispara a carga, em prepararMapa().
  if (!mapaVisivel()) return

  if (mapa.getZoom() < ZOOM_MINIMO) {
    atualizarChip(`Aproxime o mapa para ver os lotes`)
    return
  }

  const b = mapa.getBounds()
  const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]
    .map(n => n.toFixed(6)).join(',')

  state.carregando = true
  try {
    const r = await fetch(`/api/mapa/lotes?bbox=${bbox}`, {
      headers: { 'Accept': 'application/json' },
    })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const gj = await r.json()

    let novos = 0
    for (const f of gj.features) {
      if (state.lotes.has(f.properties.id)) continue
      state.lotes.set(f.properties.id, f)
      novos++
    }
    state.truncado = !!gj.truncado
    if (novos) acrescentarLotes(gj.features.filter(f => !desenhados.has(f.properties.id)))

    atualizarChip()
  } catch (e) {
    console.error(e)
    toast('Falha ao carregar os lotes', 'err')
  } finally {
    state.carregando = false
  }
}

/** Ids já desenhados no mapa, para não duplicar polígono ao arrastar de volta. */
const desenhados = new Set()

/** @param {Array<Object>} feicoes */
function acrescentarLotes(feicoes) {
  const novas = feicoes.filter(f => !desenhados.has(f.properties.id))
  if (!novas.length) return
  novas.forEach(f => desenhados.add(f.properties.id))
  adicionarAoMapa({ type: 'FeatureCollection', features: novas }, abrirFicha)
  // A vizinhança muda conforme novos lotes entram, então a coloração é
  // recalculada a cada carga — não só na primeira.
  aplicarCores()
}

/** Atualiza o chip de estado no canto do mapa. @param {string} [texto] */
function atualizarChip(texto) {
  const el = document.getElementById('chip-txt')
  if (texto) { el.textContent = texto; return }
  el.textContent = state.truncado
    ? `${state.lotes.size} lotes — mostrando parte, aproxime mais`
    : `${state.lotes.size} lotes carregados`
}

/**
 * Pede ao servidor o retângulo de toda a base e enquadra o mapa nele.
 * Se falhar, cai no centro do município — melhor um mapa deslocado do que
 * uma tela em branco.
 */
async function enquadrarBase() {
  try {
    const r = await fetch('/api/mapa/extensao', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!d.extensao) throw new Error('base vazia')
    const e = d.extensao
    mapaState.obj.fitBounds([[e.sul, e.oeste], [e.norte, e.leste]], { padding: [30, 30] })
  } catch (erro) {
    console.warn(erro)
    mapaState.obj.setView([-15.5556, -54.2961], 13)
  }
}

/** O contêiner do mapa só tem dimensão quando a aba Mapa está ativa. */
function mapaVisivel() {
  const el = document.getElementById('map')
  return !!el && el.offsetWidth > 0 && el.offsetHeight > 0
}

/**
 * Enquadra a base e carrega os lotes — uma vez só, no primeiro momento em
 * que a aba Mapa fica visível.
 *
 * Separado do bootstrap porque o app abre no Painel: enquadrar um mapa de
 * tamanho zero não posiciona nada, e era isso que deixava a base pela metade.
 */
async function prepararMapa() {
  if (!mapaVisivel()) return
  if (mapaState.pronto) { carregarLotesVisiveis(); return }
  mapaState.pronto = true

  // Abre enquadrando a BASE REAL, e não uma coordenada fixa no código: com
  // coordenada fixa, tudo que estivesse fora daquele retângulo nunca era
  // carregado até o usuário arrastar o mapa até lá.
  await enquadrarBase()

  mostrarCarregandoTela('Carregando lotes...')
  try {
    await carregarLotesVisiveis()
  } finally {
    esconderCarregandoTela()
  }
}

/** Ponto de entrada. */
async function bootstrap() {
  iniciarMapa()
  mapaState.obj.on('moveend', carregarLotesVisiveis)

  carregarNotificacoes()
  carregarPainel()
  prepararMapa()   // sem efeito se a aba Mapa ainda não estiver visível
}

// ── FICHA DO IMÓVEL ──────────────────────────────────────────

/**
 * Abre a ficha do lote — embrião da "Consulta de Imóvel" do §11 do projeto.
 * Hoje mostra o que a base GIS tem; proprietário, obra e histórico entram com
 * a integração do cadastro imobiliário (Etapa 4).
 *
 * @param {Object} feicao feição GeoJSON do lote
 */
function abrirFicha(feicao) {
  const p = feicao.properties
  state.selecionado = feicao

  // O título é fixo — "Ficha Imóvel". A inscrição saiu dele e desceu para a
  // faixa de campos: no cabeçalho ela competia com o nome da tela, e quem
  // abre a ficha já sabe qual imóvel abriu.
  const inscricao = p.inscricao || montarInscricao(p)
  document.getElementById('fi-inscricao').textContent = inscricao

  document.getElementById('fi-endereco').innerHTML = montarEndereco(p)

  // A situação nasce "Ativo" e é corrigida pelo resumo, que chega junto do
  // histórico. Só o mapa nunca traz lote baixado, então este é o estado certo
  // enquanto a resposta não volta — e não um travessão que pisca.
  const sit = document.getElementById('fi-situacao')
  sit.className = 'badge bd-ok'
  sit.textContent = 'Ativo'
  // Enquanto a integração com o cadastro da prefeitura não roda, não há data
  // para mostrar — e inventar "hoje" faria o dado parecer conferido. O travessão
  // é o vazio pedido: diz "nunca integrado" sem gastar uma frase no cabeçalho.
  document.getElementById('fi-integracao').textContent =
    p.integrado_em ? formatarDataHoraCurta(p.integrado_em) : '—'

  document.getElementById('fi-area').textContent = fmtNum(p.area_gis_m2) + ' m²'

  const c = centroide(feicao.geometry)
  document.getElementById('fi-coord').textContent =
    `${c.lat.toFixed(6)}, ${c.lon.toFixed(6)}`

  subFicha('dados')

  const linhaDist = document.getElementById('fi-linha-dist')
  if (state.pos) {
    const d = distanciaM(state.pos.lat, state.pos.lon, c.lat, c.lon)
    document.getElementById('fi-dist').textContent = `${Math.round(d)} m de você`
    linhaDist.style.display = ''
  } else {
    linhaDist.style.display = 'none'
  }

  // O histórico é o motivo de a ficha existir antes da vistoria: saber que o
  // imóvel já foi notificado muda o que o fiscal faz na visita de hoje.
  if (p.id) carregarHistorico(p.id)

  openModal('m-ficha')
}

/** @param {string} nome aba da ficha do imóvel */
function subFicha(nome) {
  document.querySelectorAll('#m-ficha .sub-abas button')
    .forEach(b => b.classList.toggle('at', b.dataset.fi === nome))
  document.querySelectorAll('#m-ficha .fi-painel').forEach(p => {
    const alvo = nome === 'historico' ? 'fi-historico-painel' : 'fi-' + nome
    p.classList.toggle('at', p.id === alvo)
  })

  // O cadastro imobiliário é buscado ao ABRIR a aba, não junto da ficha: o
  // mapa carrega até 3.000 lotes de uma vez.
  if (nome === 'cadastro') { carregarBci(state.selecionado?.properties?.id) }
}

/**
 * Inscrição imobiliária no formato 01.BBB.QQQ.LLLL.DDD
 *
 *   01   constante do município
 *   BBB  código do bairro
 *   QQQ  quadra
 *   LLLL lote
 *   DDD  sufixo de desmembramento (000 enquanto o lote for original)
 *
 * Montada aqui só enquanto o cadastro imobiliário não é integrado: a partir
 * da Etapa 4 a inscrição vem da prefeitura, que é a fonte legítima. O código
 * do bairro sai do cadastro; sem ele, fica 000 — melhor um campo visivelmente
 * incompleto do que um número plausível e errado numa certidão.
 *
 * @param {Object} p propriedades do lote
 */
function montarInscricao(p) {
  const pad = (v, n) => String(v ?? '').replace(/\D/g, '').padStart(n, '0').slice(-n)
  return ['01', pad(p.codigo_bairro, 3), pad(p.quadra, 3),
          pad(p.numero_lote, 4), pad(p.desmembramento, 3)].join('.')
}

/**
 * Endereço em UMA linha, do jeito que se lê num ofício.
 *
 * Antes eram três linhas empilhadas, e o endereço sozinho gastava um terço da
 * altura útil da ficha. Quadra e lote abreviam porque nesta linha eles são
 * referência de localização, não rótulo de campo — quem precisa deles por
 * extenso lê a inscrição, logo abaixo.
 */
function montarEndereco(p) {
  const via = [p.logradouro, p.numero_predial].filter(Boolean).join(', ')
  return [
    via ? esc(via) : '<span style="color:var(--tx3)">logradouro não cadastrado</span>',
    `Qd. ${esc(p.quadra ?? '—')}`,
    `Lt. ${esc(p.numero_lote ?? '—')}`,
    esc(p.bairro || ''),
  ].filter(Boolean).join(' · ')
}

/**
 * Data e hora curtas — dd/mm/aa - hh:mm — para o cabeçalho da ficha.
 * Ano com dois dígitos porque ali o espaço é o que sobra ao lado do título.
 */
function formatarDataHoraCurta(iso) {
  const d = new Date(iso)
  if (isNaN(d)) { return '—' }
  const dd = n => String(n).padStart(2, '0')
  return `${dd(d.getDate())}/${dd(d.getMonth() + 1)}/${dd(d.getFullYear() % 100)}`
       + ` - ${dd(d.getHours())}:${dd(d.getMinutes())}`
}

/** Abre a ficha a partir do que a API devolveu (sem geometria). @param {Object} lote */
function abrirFichaPorLote(lote) {
  const f = state.lotes.get(lote.id)
  if (f) { destacarPorId(lote.id); abrirFicha(f); return }
  // O lote pode estar fora do bbox já carregado — mostra o que a API deu.
  abrirFicha({ properties: lote, geometry: null })
}

// `novaVistoria()` vive em vistoria.js — é o formulário da Etapa 5.

// ── GPS → LOTE (resolvido no servidor) ───────────────────────

/** Handler do botão "Usar minha localização". Toggle, como em geolocalizacao.js. */
function usarMinhaLocalizacao() {
  const btn = document.getElementById('btn-gps')
  if (btn.dataset.gpsCapturado) { removerGPS(); return }

  if (!navigator.geolocation) { toast('GPS não disponível neste aparelho', 'err'); return }
  btn.disabled = true
  rotuloGps('Localizando…')

  navigator.geolocation.getCurrentPosition(
    async pos => {
      btn.disabled = false
      const { latitude: lat, longitude: lon, accuracy: prec } = pos.coords
      state.pos = { lat, lon, prec }
      marcarMinhaPosicao(lat, lon, prec)
      btn.classList.add('gps-ativo')
      btn.dataset.gpsCapturado = '1'
      rotuloGps('Remover GPS')
      mapaState.obj.setView([lat, lon], 18)
      await identificarNoServidor(lat, lon, prec)
    },
    err => {
      btn.disabled = false
      rotuloGps('Usar minha localização')

      // Permissão negada não é um aviso passageiro: é uma trava que só o
      // usuário destrava, e num lugar que ele não vai adivinhar. Por isso vai
      // para um modal com o caminho exato, e não para um toast que some em
      // dois segundos deixando o fiscal sem saber o que fazer.
      if (err.code === 1) {
        confirmarAcao({
          titulo: 'Localização bloqueada',
          mensagem: comoLiberarLocalizacao(),
          textoBtn: 'Entendi',
          onConfirm: () => {},
        })

        return
      }

      toast({ 2: 'Sinal de GPS fraco. Tente a céu aberto.',
              3: 'O GPS demorou demais para responder. Tente de novo.' }[err.code]
            || 'Erro ao obter GPS', 'err')
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  )
}

/** Quebra de linha das instruções — ver o `white-space` de #mcg-msg. */
const SALTO = '\n'

/**
 * O caminho para destravar a localização, no aparelho de quem está lendo.
 *
 * No iPhone são DUAS travas em lugares diferentes, e é por isso que a
 * instrução genérica ("permita nas configurações") não resolve: o app precisa
 * de permissão nos Ajustes do iOS E o site precisa de permissão dentro do
 * navegador. Quem só mexe numa continua sem GPS e conclui que o sistema está
 * quebrado.
 */
function comoLiberarLocalizacao() {
  const ua = navigator.userAgent
  const iOS = /iPad|iPhone|iPod/.test(ua)
    // iPad recente se apresenta como Mac; o toque no lugar do mouse denuncia.
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  const chromeNoIOS = iOS && /CriOS/.test(ua)

  if (chromeNoIOS) {
    return [
      'No iPhone, o Chrome precisa de duas permissões:',
      '1) Ajustes do iPhone > Privacidade e Segurança > Serviços de Localização'
        + ' > Chrome > "Ao Usar o App".',
      '2) No Chrome, três pontos > Configurações > Configurações do site >'
        + ' Localização, e permita para fiscobras.duckdns.org.',
      'Depois feche a aba e abra o sistema de novo.',
    ].join(SALTO + SALTO)
  }

  if (iOS) {
    return [
      'No iPhone, o Safari precisa de duas permissões:',
      '1) Ajustes do iPhone > Privacidade e Segurança > Serviços de Localização'
        + ' > Safari > "Ao Usar o App".',
      '2) Ajustes > Apps > Safari > Localização > Perguntar ou Permitir.',
      'Depois recarregue a página e toque no botão outra vez.',
    ].join(SALTO + SALTO)
  }

  return [
    'O navegador está bloqueando a localização deste site.',
    'Toque no cadeado ao lado do endereço, encontre "Localização" e mude para'
      + ' Permitir. Depois recarregue a página.',
  ].join(SALTO + SALTO)
}

/** Limpa a posição capturada. */
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
      rotuloGps('Usar minha localização')
      toast('Localização removida')
    },
  })
}

/**
 * Pergunta ao servidor em qual lote está a coordenada.
 * @param {number} lat @param {number} lon @param {number} prec
 */
async function identificarNoServidor(lat, lon, prec) {
  mostrarCarregandoTela('Identificando o imóvel...')
  try {
    const r = await fetch('/api/localizacao/identificar', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        // A rota vive no grupo `web` (ver routes/web.php), logo passa pelo
        // VerifyCsrfToken. Sem este cabeçalho, todo POST volta 419.
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({ lat, lon, accuracy: prec }),
    })
    // 419 = sessão expirada. Recarregar devolve o usuário ao login, que é o
    // comportamento certo: continuar tentando com token morto só gera erro.
    if (r.status === 419) { toast('Sessão expirada. Recarregando...', 'err'); setTimeout(() => location.reload(), 1500); return }
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()

    if (d.resultado === 'exato') {
      toast(`Você está no lote ${d.lote.numero_lote}, quadra ${d.lote.quadra}`)
      abrirFichaPorLote(d.lote)
      return
    }
    if (d.resultado === 'nenhum') {
      toast(`Nenhum lote cadastrado num raio de ${Math.round(d.tolerancia)} m.`, 'err')
      return
    }
    abrirConfirmacao(d.candidatos, prec)
  } catch (e) {
    console.error(e)
    toast('Falha ao identificar o imóvel', 'err')
  } finally {
    esconderCarregandoTela()
  }
}

/**
 * Tela de confirmação do §9 do projeto: o sistema não adivinha, oferece.
 * @param {Array<Object>} candidatos @param {number} prec
 */
function abrirConfirmacao(candidatos, prec) {
  document.getElementById('cf-precisao').textContent = `Precisão do GPS: ±${Math.round(prec)} m`
  document.getElementById('cf-lista').innerHTML = candidatos.map((c, i) => `
    <div class="opcao${i === 0 ? ' sel' : ''}" data-id="${c.id}" onclick="selecionarOpcao(this)">
      <div class="radio"></div>
      <div class="txt">
        <div class="t1">Lote ${esc(c.numero_lote)} · Quadra ${esc(c.quadra)}</div>
        <div class="t2">${esc(c.bairro)} · ${fmtNum(c.area_gis_m2)} m²</div>
      </div>
      <div class="dist">${Math.round(c.dist_m)} m</div>
    </div>`).join('')

  // Guarda os candidatos para o Confirmar não precisar de nova ida ao servidor.
  state._candidatos = candidatos
  openModal('m-confirmar-lote')
}

/** @param {HTMLElement} el */
function selecionarOpcao(el) {
  el.parentElement.querySelectorAll('.opcao').forEach(o => o.classList.remove('sel'))
  el.classList.add('sel')
}

/** Confirma a escolha do fiscal e abre a ficha. */
function confirmarLote() {
  const sel = document.querySelector('#cf-lista .opcao.sel')
  if (!sel) { toast('Selecione um lote', 'err'); return }
  const id = Number(sel.dataset.id)
  const lote = (state._candidatos || []).find(c => c.id === id)
  fModalBtn('m-confirmar-lote')
  if (lote) abrirFichaPorLote(lote)
}

/**
 * Enquadra TODA a base carregada, e não um ponto fixo.
 *
 * Antes era `setView` numa coordenada do bairro piloto: com um bairro só isso
 * passava, mas a cada loteamento importado o "ver tudo" mostrava menos do
 * "tudo". Agora o enquadramento vem da extensão real dos lotes (o mesmo
 * cálculo que abre o mapa), então ele cresce sozinho conforme o município é
 * carregado.
 *
 * `mapaState.extensao` é preenchido em enquadrarBase(). Se ela ainda não
 * chegou, cai no limite do município — nunca deixa o botão sem efeito.
 */
function verTudo() {
  const e = mapaState.extensao
  if (e) {
    mapaState.obj.fitBounds([[e.sul, e.oeste], [e.norte, e.leste]], { padding: [24, 24] })
    return
  }
  mapaState.obj.fitBounds(LIMITE_MUNICIPIO)
}

document.addEventListener('DOMContentLoaded', bootstrap)

/**
 * Alterna entre as abas.
 *
 * O Leaflet calcula o tamanho do contêiner na criação; se o mapa estava
 * escondido (display:none) quando isso aconteceu, ele volta com dimensão
 * zerada. Daí o invalidateSize() ao reentrar na aba.
 *
 * `ordem` precisa espelhar a sequência dos botões da barra: o realce é feito
 * por índice, não por nome. Ao acrescentar uma aba, acrescente aqui.
 *
 * @param {'painel'|'mapa'|'documentos'|'protocolos'} destino
 */
function irPara(destino) {
  document.querySelectorAll('.tela').forEach(t => t.classList.remove('at'))
  document.getElementById('t-' + destino).classList.add('at')

  // A ordem tem de acompanhar a dos botões no rodapé: é o índice que decide
  // qual aba fica marcada.
  const ordem = ['painel', 'busca', 'mapa', 'documentos', 'protocolos']
  const i = ordem.indexOf(destino)
  document.querySelectorAll('.aba').forEach(a => a.classList.remove('at'))
  document.querySelectorAll('.aba')[i]?.classList.add('at')

  marcarModuloNoSubcabecalho(destino)

  if (destino === 'busca') {
    prepararBusca()
  } else if (destino === 'mapa') {
    // O Leaflet mede o contêiner na criação; se o mapa estava escondido,
    // volta com dimensão zerada. Daí o invalidateSize ao reentrar.
    setTimeout(() => {
      mapaState.obj?.invalidateSize()
      rotulosPorZoom()
      prepararMapa()
    }, 60)
  } else if (destino === 'documentos') {
    carregarDocumentos()
  } else if (destino === 'protocolos') {
    carregarProtocolos()
  } else if (destino === 'painel') {
    carregarPainel()
  }
}

/**
 * Texto do botão de GPS.
 *
 * O botão virou ícone quadrado na coluna de controles — não há mais rótulo
 * visível para trocar. O estado passa a viver no `title`, que é o que o
 * usuário lê ao pousar o ponteiro e o que o leitor de tela anuncia. A cor do
 * botão (.gps-ativo) continua sendo o aviso imediato de que há posição
 * capturada.
 *
 * @param {string} texto
 */
function rotuloGps(texto) {
  const btn = document.getElementById('btn-gps')
  if (btn) { btn.title = texto; btn.setAttribute('aria-label', texto) }
}

// ── SUB-CABEÇALHO ────────────────────────────────────────────

/**
 * Nome e ícone de cada módulo, no sub-cabeçalho.
 *
 * O rótulo é copiado do botão do rodapé em tempo de execução, e não escrito de
 * novo aqui: rótulo repetido em dois lugares vira rótulo divergente na
 * primeira vez que alguém renomear um só deles.
 */
function marcarModuloNoSubcabecalho(destino) {
  const ordem = ['painel', 'busca', 'mapa', 'documentos', 'protocolos']
  const botao = document.querySelectorAll('.aba')[ordem.indexOf(destino)]
  if (!botao) return

  const nome = document.getElementById('subcab-nome')
  const ico = document.getElementById('subcab-ico')
  if (nome) nome.textContent = botao.textContent.trim()
  if (ico) ico.innerHTML = botao.querySelector('svg')?.outerHTML || ''
}
