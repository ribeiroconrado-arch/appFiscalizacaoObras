/**
 * Correção cadastral → NOMES DE RUA.
 *
 * O nome automático (bairros-contorno.js) sai do voto dos lotes de cada lado
 * de quadra. Onde o voto não decide — lado só com lotes de esquina, lote sem
 * logradouro no cadastro — o trecho fica SEM NOME. Aqui o curador informa o
 * nome do TRECHO, e não do lote: o endereço do lote vem do cadastro e seria
 * sobrescrito na próxima carga, e nem está errado (a esquina tem mesmo um
 * endereço só). O que falta é o nome do lado.
 *
 * Com a ferramenta aberta, cada trecho do bairro vira uma linha clicável:
 *   verde    nome do cadastro      azul     informado à mão
 *   vermelho sem nome              cinza    oculto
 * O nome é escolhido da lista de logradouros do cadastro do bairro; só o
 * administrador digita fora dela (ver RuaManualController). Rua sem lote de
 * frente (rodovia, avenida ao lado de área verde) é DESENHADA: dois toques,
 * início e fim. O informado à mão vale por cima do gerado e o "Gerar todos"
 * não o apaga (App\Cadastro\TrechosDeRua).
 */

const ruaState = {
  ativa: false,
  /** @type {L.LayerGroup|null} as linhas clicáveis */ camada: null,
  /** @type {{pontos: L.LatLng[], marcas: L.Layer[]}|null} trecho sendo desenhado */ desenhando: null,
  /** @type {{bairro:string, trecho:Object}|null} o trecho do balão aberto */ balao: null,
  /** @type {Map<string, {logradouros:string[], livre:boolean}>} por bairro */ listas: new Map(),
}

const COR_TRECHO = { cadastro: '#16a34a', manual: '#2563eb', sem_nome: '#dc2626', oculto: '#9ca3af', novo: '#2563eb' }
const ORIGEM_TRECHO = { cadastro: 'nome do cadastro', manual: 'informado à mão', sem_nome: 'sem nome', oculto: 'oculto', novo: 'trecho novo' }

FERRAMENTAS_MAPA.ruas = {
  rotulo: 'nomes de rua',
  aberta: () => ruaState.ativa,
  emCurso: () => ruaState.desenhando?.pontos.length ? 'um trecho de rua começado' : null,
  fechar: () => fecharNomesDeRua(),
}

/** Abre a ferramenta (botão "Nomes de rua" da correção cadastral). */
function abrirNomesDeRua() {
  pedirFerramenta('ruas', () => {
    const mapa = mapaState.obj
    ruaState.ativa = true
    if (!mapa.getPane('ruasEdicao')) {
      // Acima dos lotes e dos rótulos, para o clique pegar a linha.
      mapa.createPane('ruasEdicao').style.zIndex = 640
    }
    // Reaberta, a camada volta a existir (ver fecharNomesDeRua).
    mapa.getPane('ruasEdicao').style.display = ''
    ruaState.camada = L.layerGroup().addTo(mapa)
    mapa.on('click', _cliqueNoMapaRuas)
    _pintarBarraRuas()
    if (!['quadras', 'lotes'].includes(nivelDoMapa(mapa))) {
      toast('Aproxime o mapa até ver as quadras: é nessa escala que os trechos de rua aparecem.', 'aviso')
    }
    carregarQuadrasVisiveis()
    aoMudarRuas()
  })
}

function fecharNomesDeRua() {
  const mapa = mapaState.obj
  ruaState.ativa = false
  _cancelarDesenhoRua()
  ruaState.camada?.remove()
  ruaState.camada = null
  ruaState.balao = null
  mapa?.off('click', _cliqueNoMapaRuas)
  mapa?.closePopup()
  // A CAMADA DE EDIÇÃO SAI DA FRENTE. As linhas foram removidas, mas a tela
  // de desenho (canvas) que o Leaflet cria para esta camada continua no mapa,
  // do tamanho dele inteiro e ACIMA dos lotes — vazia, e engolindo todo
  // clique. Era por isso que, fechada a ferramenta, não se selecionava mais
  // nenhum lote. Escondida, ela não recebe clique nenhum.
  const pane = mapa?.getPane('ruasEdicao')
  if (pane) pane.style.display = 'none'
  const barra = document.getElementById('ruas-barra')
  if (barra) barra.hidden = true
}

function _pintarBarraRuas() {
  const barra = document.getElementById('ruas-barra')
  if (!barra) return
  const d = ruaState.desenhando
  const passo = !d ? '' : d.pontos.length ? 'Toque no FIM do trecho.' : 'Toque no INÍCIO do trecho, sobre a rua.'
  barra.innerHTML = `
    <b>Nomes de rua</b>
    <span class="ruas-legenda">
      <i style="background:${COR_TRECHO.cadastro}"></i>cadastro
      <i style="background:${COR_TRECHO.manual}"></i>à mão
      <i style="background:${COR_TRECHO.sem_nome}"></i>sem nome
      <i style="background:${COR_TRECHO.oculto}"></i>oculto
    </span>
    ${d ? `<span class="ruas-passo">${passo}</span>` : ''}
    <span class="imp-barra-acoes">
      ${d ? '<button class="btn sm" onclick="_cancelarDesenhoRua()">Cancelar</button>'
        : '<button class="btn sm" onclick="comecarDesenhoRua()" title="Rua sem lote de frente: rodovia, avenida ao lado de área verde">Desenhar trecho</button>'}
      <button class="btn sm imp-barra-x" title="Fechar Nomes de rua" onclick="fecharNomesDeRua()">&#10005;</button>
    </span>`
  barra.hidden = false
}

/**
 * Redesenha as linhas clicáveis com o que está guardado de cada bairro.
 * Chamada por carregarQuadrasVisiveis (bairro novo na tela) e depois de
 * cada gravação.
 */
function aoMudarRuas() {
  if (!ruaState.ativa || !ruaState.camada) return
  ruaState.camada.clearLayers()
  for (const [bairro, b] of quadraState.porBairro) {
    if (b === 'carregando') continue
    for (const t of b.ruas) {
      const linha = L.polyline([t.de, t.ate], {
        pane: 'ruasEdicao', color: COR_TRECHO[t.origem] || '#666', weight: 6, opacity: .85,
        dashArray: t.origem === 'sem_nome' || t.origem === 'oculto' ? '8 6' : null,
      })
      linha.on('click', e => {
        // Desenhando, o toque é ponto do trecho novo: deixa passar para o mapa.
        if (ruaState.desenhando) return
        L.DomEvent.stopPropagation(e)
        _abrirBalaoRua(bairro, t, e.latlng)
      })
      linha.bindTooltip(t.nome || ORIGEM_TRECHO[t.origem], { sticky: true, direction: 'top' })
      ruaState.camada.addLayer(linha)
    }
  }
}

/** A lista oficial de logradouros do bairro, guardada por bairro. @param {string} bairro */
async function _listaDoBairro(bairro) {
  if (ruaState.listas.has(bairro)) return ruaState.listas.get(bairro)
  const r = await fetch('/api/ruas/logradouros?bairro=' + encodeURIComponent(bairro), { headers: { Accept: 'application/json' } })
  const d = await r.json()
  if (!r.ok) throw new Error(d.message || 'Não foi possível ler os logradouros do bairro.')
  ruaState.listas.set(bairro, d)
  return d
}

/**
 * O balão de um trecho: nome atual, de onde veio, e a escolha do nome.
 * @param {string} bairro @param {Object} trecho @param {L.LatLng} onde
 */
async function _abrirBalaoRua(bairro, trecho, onde) {
  let lista
  try { lista = await _listaDoBairro(bairro) } catch (e) { toast(e.message, 'err'); return }
  ruaState.balao = { bairro, trecho }

  const opcoes = lista.logradouros.map(n => `<option value="${esc(n)}"${n === trecho.nome ? ' selected' : ''}>${esc(n)}</option>`).join('')
  // Livre (administrador, ou bairro sem cadastro): campo de texto com a lista
  // como sugestão. Senão, só a lista — é ela que garante a grafia oficial.
  const campo = lista.livre
    ? `<input id="rua-nome" list="rua-nomes" value="${esc(trecho.nome || '')}" placeholder="Nome do logradouro" maxlength="180">
       <datalist id="rua-nomes">${opcoes}</datalist>`
    : `<select id="rua-nome"><option value="">Escolha o logradouro…</option>${opcoes}</select>`
  const manual = trecho.origem === 'manual' || trecho.origem === 'oculto'

  L.popup({ maxWidth: 320, minWidth: 260, className: 'rua-balao' })
    .setLatLng(onde)
    .setContent(`
      <div class="rua-balao-tit">Trecho de rua · ${esc(ORIGEM_TRECHO[trecho.origem] || '')}</div>
      <div class="rua-balao-nome">${trecho.nome ? esc(trecho.nome) : '<span class="rua-sem">sem nome</span>'}</div>
      <div class="field"><label for="rua-nome">${lista.livre ? 'Nome do logradouro' : 'Logradouro do cadastro do bairro'}</label>${campo}</div>
      ${!lista.cadastro ? '<div class="rua-aviso">Bairro sem cadastro amarrado: o nome é livre.</div>' : ''}
      <div class="rua-balao-acoes">
        <button class="btn sm primary" onclick="salvarNomeDeRua()">Salvar</button>
        ${trecho.origem !== 'oculto' && trecho.origem !== 'novo' ? '<button class="btn sm" onclick="ocultarTrechoDeRua()" title="Rótulo errado e sem nome melhor: some do mapa">Ocultar</button>' : ''}
        ${manual ? '<button class="btn sm" onclick="voltarTrechoAoCadastro()">Voltar ao cadastro</button>' : ''}
      </div>`)
    .openOn(mapaState.obj)
}

/** Grava o nome escolhido no balão. */
async function salvarNomeDeRua() {
  const nome = document.getElementById('rua-nome')?.value.trim()
  if (!nome) { toast('Escolha o logradouro.', 'err'); return }
  await _gravarTrecho({ nome })
}

/** Esconde o trecho do mapa (rótulo errado, sem nome melhor). */
async function ocultarTrechoDeRua() {
  await _gravarTrecho({ oculto: true })
}

/** Apaga a informação manual: o trecho volta ao que o cadastro diz. */
async function voltarTrechoAoCadastro() {
  const { trecho } = ruaState.balao || {}
  if (!trecho?.id) return
  await _enviarRua('DELETE', '/api/ruas/manuais/' + Number(trecho.id), null)
}

/** @param {{nome?:string, oculto?:boolean}} dados */
async function _gravarTrecho(dados) {
  const { bairro, trecho } = ruaState.balao || {}
  if (!trecho) return
  // Trecho que já é manual é ALTERADO; o gerado (ou o desenhado agora) ganha
  // um manual por cima, com a mesma posição.
  if (trecho.id) {
    await _enviarRua('PUT', '/api/ruas/manuais/' + Number(trecho.id), dados)
  } else {
    await _enviarRua('POST', '/api/ruas/manuais', { bairro, de: trecho.de, ate: trecho.ate, ...dados })
  }
}

async function _enviarRua(metodo, url, corpo) {
  try {
    const r = await fetch(url, {
      method: metodo,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: corpo ? JSON.stringify(corpo) : undefined,
    })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'Não foi possível gravar o nome de rua.')
    atualizarRuasDoBairro(d.bairro, d.ruas)
    _cancelarDesenhoRua()
    mapaState.obj.closePopup()
    aoMudarRuas()
    toast(d.message, 'ok')
  } catch (e) {
    toast(e.message, 'err')
  }
}

// ── DESENHAR UM TRECHO (rua sem lote de frente) ──────────────

function comecarDesenhoRua() {
  _cancelarDesenhoRua()
  ruaState.desenhando = { pontos: [], marcas: [] }
  _pintarBarraRuas()
}

function _cancelarDesenhoRua() {
  ruaState.desenhando?.marcas.forEach(m => m.remove())
  ruaState.desenhando = null
  if (ruaState.ativa) _pintarBarraRuas()
}

/** @param {L.LeafletMouseEvent} e */
function _cliqueNoMapaRuas(e) {
  const d = ruaState.desenhando
  if (!d) return
  d.pontos.push(e.latlng)
  d.marcas.push(L.circleMarker(e.latlng, { pane: 'ruasEdicao', radius: 6, color: COR_TRECHO.novo, weight: 2, fillOpacity: .9 }).addTo(mapaState.obj))
  if (d.pontos.length < 2) { _pintarBarraRuas(); return }

  const [a, b] = d.pontos
  d.marcas.push(L.polyline([a, b], { pane: 'ruasEdicao', color: COR_TRECHO.novo, weight: 6, opacity: .85 }).addTo(mapaState.obj))
  const meio = L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2)
  const bairro = _bairroDoPonto(meio) || _bairroDoPonto(a) || _bairroDoPonto(b)
  if (!bairro) {
    toast('O trecho precisa estar dentro do contorno de um bairro.', 'err')
    _cancelarDesenhoRua()
    return
  }
  const r = n => Number(n.toFixed(7))
  _abrirBalaoRua(bairro, { id: null, nome: null, origem: 'novo', de: [r(a.lat), r(a.lng)], ate: [r(b.lat), r(b.lng)] }, meio)
}

/** O bairro cujo contorno contém o ponto (nome do desenho), ou null. @param {L.LatLng} p */
function _bairroDoPonto(p) {
  let achado = null
  contornoState.camada?.eachLayer(c => {
    if (achado || !c.getBounds().contains(p)) return
    // getLatLngs de multipolígono: [[anel externo, furos...], ...]; de polígono: [anel, furos...].
    const ll = c.getLatLngs()
    const poligonos = Array.isArray(ll[0]?.[0]) ? ll : [ll]
    if (poligonos.some(pol => _dentroDoAnel(p, pol[0]) && !pol.slice(1).some(furo => _dentroDoAnel(p, furo)))) {
      achado = c.feature?.properties?.nome ?? null
    }
  })
  return achado
}

/** Ponto dentro do anel (raio cruzando as arestas). */
function _dentroDoAnel(p, anel) {
  let dentro = false
  for (let i = 0, j = anel.length - 1; i < anel.length; j = i++) {
    const a = anel[i], b = anel[j]
    if ((a.lat > p.lat) !== (b.lat > p.lat)
        && p.lng < (b.lng - a.lng) * (p.lat - a.lat) / (b.lat - a.lat) + a.lng) dentro = !dentro
  }
  return dentro
}
