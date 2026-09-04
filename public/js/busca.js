// ══════════════════════════════════════════════
// MÓDULO: BUSCA DE IMÓVEIS
//
// Consulta de cadastro sem abrir o mapa. A camada de satélite é serviço pago
// por requisição, e conferir a situação de um lote — a maior parte das
// consultas de balcão — não deveria gerar faturamento de imagem aérea só para
// ler quatro campos de texto.
//
// Vários resultados viram tabela com o essencial. Escolhido um imóvel, a ficha
// abre NA PRÓPRIA TELA e não em modal: aqui não há mapa por baixo para
// preservar, então a sobreposição só atrapalharia.
// ══════════════════════════════════════════════

const bState = {
  /** @type {string[]|null} carregado uma vez por sessão */ bairros: null,
  /**
   * As ruas que a busca PODE achar — só as dos bairros cujo cadastro já foi
   * carregado e amarrado. Ver BuscaController::logradouros.
   * @type {string[]|null}
   */
  logradouros: null,
  /** @type {Array<Object>} */ resultado: [],
}

/** Primeira entrada na aba: popula o filtro de bairros. */
async function prepararBusca() {
  if (bState.bairros) return
  try {
    const r = await fetch('/api/imoveis/bairros', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    bState.bairros = d.bairros
    document.getElementById('bs-bairro').innerHTML =
      '<option value="">— todos —</option>' +
      d.bairros.map(b => `<option value="${esc(b)}">${esc(b)}</option>`).join('')
  } catch (e) {
    console.error(e)
  }
}

// ── COMBOBOX DE LOGRADOURO ───────────────────────────────────
//
// O logradouro NÃO VEM DO DESENHO: o DWG traz o polígono, a quadra e o lote,
// e nunca o nome da rua. Ele vem do cadastro da prefeitura, e chega ao lote
// pelo casamento bairro + quadra + lote.
//
// Por isso a lista é fechada — só oferece rua que pode achar alguma coisa — e
// por isso ela pode estar VAZIA: enquanto não houver bairro com cadastro
// carregado e amarrado, não há rua nenhuma a oferecer. Nesse caso o combo diz
// isso, em vez de abrir vazio e parecer defeito.

/** Carrega as ruas uma vez por sessão. */
async function carregarLogradouros() {
  if (bState.logradouros) { return bState.logradouros }
  try {
    const r = await fetch('/api/imoveis/logradouros', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    bState.logradouros = d.logradouros ?? []
  } catch (e) {
    console.error(e)
    bState.logradouros = []
  }
  return bState.logradouros
}

/** @param {string} texto */
async function buscarLogradouro(texto) {
  const alvo = document.getElementById('bs-logr-sugestoes')
  if (!alvo) { return }

  const ruas = await carregarLogradouros()
  const q = texto.trim().toLowerCase()

  if (!ruas.length) {
    alvo.classList.add('open')
    alvo.innerHTML = '<div class="ac-vazio">Nenhum logradouro disponível — '
      + 'depende de carregar o cadastro da prefeitura do bairro.</div>'
    return
  }

  const achados = (q ? ruas.filter(r => r.toLowerCase().includes(q)) : ruas).slice(0, 10)

  alvo.classList.toggle('open', achados.length > 0)
  alvo.innerHTML = achados.map(r =>
    `<button type="button" class="ac-item" onclick="escolherLogradouro(${JSON.stringify(r).replace(/"/g, '&quot;')})">
       <b>${esc(r)}</b></button>`).join('')
}

/** @param {string} rua */
function escolherLogradouro(rua) {
  document.getElementById('bs-logradouro').value = rua
  fecharListaLogradouro()
  marcarPrecedencia()
}

function fecharListaLogradouro() {
  const alvo = document.getElementById('bs-logr-sugestoes')
  if (!alvo) { return }
  alvo.classList.remove('open')
  alvo.innerHTML = ''
}

// Clicar fora fecha — é o que se espera de um dropdown.
document.addEventListener('mousedown', ev => {
  if (!ev.target.closest('#bs-logr-sugestoes') && !ev.target.closest('.bc-logr')) {
    fecharListaLogradouro()
  }
})

function limparBusca() {
  for (const id of ['bs-bairro', 'bs-quadra', 'bs-lote', 'bs-inscricao',
                    'bs-logradouro', 'bs-numero', 'bs-bci-de', 'bs-bci-ate', 'bs-vistoria']) {
    const e = document.getElementById(id)
    if (e) { e.value = '' }
  }
  fecharListaLogradouro()
  for (const id of ['bs-embargo', 'bs-pendente', 'bs-sem-vistoria', 'bs-baixados']) {
    const e = document.getElementById(id)
    if (e) { e.checked = false }
  }
  document.getElementById('busca-resultado').innerHTML = ''
  bState.resultado = []
  marcarPrecedencia()
}

/** Mostra e esconde o bloco de filtros avançados. */
function alternarFiltrosAvancados() {
  const bloco = document.getElementById('busca-avancado')
  const botao = document.getElementById('bs-mais')
  const abrindo = bloco.hasAttribute('hidden')
  bloco.toggleAttribute('hidden', !abrindo)
  botao.classList.toggle('aberto', abrindo)
  botao.setAttribute('aria-expanded', String(abrindo))
}

/**
 * Qual nível de filtro está mandando, segundo a precedência do domínio.
 *
 *   intervalo de BCI  >  inscrição unitária  >  demais filtros
 *
 * A inscrição imobiliária identifica UM imóvel; combiná-la com bairro ou
 * quadra só produz contradição, e a resposta vazia daí parece defeito do
 * sistema. Em vez de descartar em silêncio, a tela diz o que está valendo e
 * esmaece o que foi ignorado.
 *
 * @returns {'intervalo'|'inscricao'|'demais'}
 */
function nivelDeBusca() {
  const v = id => (document.getElementById(id)?.value ?? '').trim()
  if (v('bs-bci-de') || v('bs-bci-ate')) return 'intervalo'
  if (v('bs-inscricao')) return 'inscricao'
  return 'demais'
}

function marcarPrecedencia() {
  const nivel = nivelDeBusca()
  const aviso = document.getElementById('bs-precedencia')

  const demais = ['bs-bairro', 'bs-quadra', 'bs-lote', 'bs-vistoria',
                  'bs-embargo', 'bs-pendente', 'bs-sem-vistoria']

  const inativos = nivel === 'intervalo' ? [...demais, 'bs-inscricao']
                 : nivel === 'inscricao' ? demais
                 : []

  for (const id of [...demais, 'bs-inscricao']) {
    document.getElementById(id)?.closest('.field, .chk-item')
      ?.classList.toggle('bs-ignorado', inativos.includes(id))
  }

  if (nivel === 'demais') { aviso.hidden = true; return }
  aviso.hidden = false
  aviso.textContent = nivel === 'intervalo'
    ? 'Buscando pelo intervalo de BCI — os demais filtros, inclusive a inscrição unitária, estão sendo ignorados.'
    : 'Buscando pela inscrição imobiliária — os demais filtros estão sendo ignorados.'
}

async function executarBusca() {
  const p = new URLSearchParams()
  const nivel = nivelDeBusca()
  const v = id => (document.getElementById(id)?.value ?? '').trim()

  // A precedência é aplicada de novo no servidor; aqui ela existe para não
  // enviar o que já se sabe que será descartado.
  if (nivel === 'intervalo') {
    if (v('bs-bci-de'))  p.set('inscricao_de', v('bs-bci-de'))
    if (v('bs-bci-ate')) p.set('inscricao_ate', v('bs-bci-ate'))
  } else if (nivel === 'inscricao') {
    p.set('inscricao', v('bs-inscricao'))
  } else {
    for (const [campo, id] of [['bairro', 'bs-bairro'], ['quadra', 'bs-quadra'],
                               ['lote', 'bs-lote'], ['vistoria', 'bs-vistoria'],
                               ['logradouro', 'bs-logradouro'], ['numero', 'bs-numero']]) {
      if (v(id)) p.set(campo, v(id))
    }
    if (document.getElementById('bs-embargo').checked) p.set('embargo', '1')
    if (document.getElementById('bs-pendente').checked) p.set('doc_pendente', '1')
    if (document.getElementById('bs-sem-vistoria').checked) p.set('obra_sem_vistoria', '1')
  }

  // Fora do bloco acima de propósito: incluir baixados não é um filtro de
  // busca, é uma ampliação do universo consultado — vale inclusive quando se
  // procura por inscrição, que é justamente como se acha um lote extinto.
  if (document.getElementById('bs-baixados')?.checked) { p.set('incluir_baixados', '1') }

  if (![...p.keys()].length) {
    exigirCampo('bs-inscricao', 'Informe ao menos um filtro para buscar.')
    return
  }

  const alvo = document.getElementById('busca-resultado')
  alvo.innerHTML = '<div class="lista-vazia">Buscando…</div>'

  try {
    const r = await fetch('/api/imoveis/busca?' + p, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)

    bState.resultado = d.imoveis
    if (!d.imoveis.length) {
      alvo.innerHTML = '<div class="lista-vazia">Nenhum imóvel com esses filtros.</div>'
      return
    }
    // Resultado único vai direto para a ficha: obrigar a clicar numa tabela
    // de uma linha só é um passo sem informação nova.
    if (d.imoveis.length === 1) { abrirImovel(d.imoveis[0].id); return }
    renderTabelaBusca(d)
  } catch (e) {
    console.error(e)
    alvo.innerHTML = `<div class="lista-vazia">${esc(e.message || 'Falha na busca.')}</div>`
  }
}

/** Tabela só com o essencial — a ficha completa é um clique adiante. */
function renderTabelaBusca(d) {
  const linhas = d.imoveis.map(i => `
    <tr onclick="abrirImovel(${i.id})">
      <td class="mono">${esc(i.inscricao || '—')}</td>
      <td>${esc(i.bairro || '—')}</td>
      <td class="bs-c">${esc(i.quadra ?? '—')}</td>
      <td class="bs-c">${esc(i.lote ?? '—')}</td>
      <td class="bs-d">${i.area ? fmtNum(i.area) + ' m²' : '—'}</td>
      <td class="bs-c">${i.documentos || '—'}</td>
      <td class="bs-c">${i.vistorias || '—'}</td>
      <td class="bs-c">${i.situacao === 'baixado'
        ? `<span class="bs-selo-baixado" title="Baixado em ${esc(i.baixado_em || '—')}">baixado</span>`
        : ''}</td>
    </tr>`).join('')

  document.getElementById('busca-resultado').innerHTML = `
    <div class="bs-cabecalho">
      <span>${d.total} imóvel(is)</span>
      ${d.truncado ? '<span class="bs-aviso">Mostrando os 200 primeiros — refine os filtros.</span>' : ''}
    </div>
    <div class="bs-tabela-rolagem">
      <table class="bs-tabela">
        <thead>
          <tr>
            <th>Inscrição</th><th>Bairro</th><th class="bs-c">Q</th><th class="bs-c">Lt</th>
            <th class="bs-d">Área</th><th class="bs-c">Doc.</th><th class="bs-c">Vist.</th>
            <th class="bs-c"></th>
          </tr>
        </thead>
        <tbody>${linhas}</tbody>
      </table>
    </div>`
}

/** Ficha técnica do imóvel, carregada na própria tela. @param {number} id */
async function abrirImovel(id) {
  const alvo = document.getElementById('busca-resultado')
  alvo.innerHTML = '<div class="lista-vazia">Carregando ficha…</div>'

  try {
    const r = await fetch('/api/imoveis/' + id, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status)
    renderFichaImovel(d)
  } catch (e) {
    console.error(e)
    alvo.innerHTML = `<div class="lista-vazia">${esc(e.message || 'Não foi possível abrir o imóvel.')}</div>`
  }
}

function renderFichaImovel(d) {
  const voltar = bState.resultado.length > 1
    ? `<button class="btn" onclick="renderTabelaBusca({imoveis:bState.resultado,total:bState.resultado.length,truncado:false})">Voltar à lista</button>`
    : ''

  const docs = d.documentos.length
    ? d.documentos.map(x => `
        <div class="bs-linha" onclick="abrirDocumento(${x.id})">
          <span class="proto-badge">${esc(x.numero)}</span>
          <span class="bs-linha-tit">${esc(x.tipo)}</span>
          <span class="badge ${esc(x.status.classe)}">${esc(x.status.texto)}</span>
          <span class="bs-linha-data">${esc(x.data || '')}</span>
        </div>`).join('')
    : '<div class="lista-vazia">Nenhum documento neste imóvel.</div>'

  const vist = d.vistorias.length
    ? d.vistorias.map(x => `
        <div class="bs-linha">
          <span class="bs-linha-tit">${esc(x.data || '—')}</span>
          <span class="bs-linha-sub">${esc(x.fiscal || '—')}</span>
          <span class="bs-linha-data">${esc(x.situacao || '')}</span>
        </div>`).join('')
    : '<div class="lista-vazia">Nenhuma vistoria neste imóvel.</div>'

  // O botão do mapa é o ÚNICO ponto desta tela que aciona o serviço pago —
  // por isso é uma escolha explícita do usuário, nunca um efeito da busca.
  // Um imóvel BAIXADO não está mais na camada do mapa — ele foi unificado ou
  // desmembrado. Levar até a coordenada mostraria os sucessores desenhados por
  // cima e nada mais; por isso o botão dele é outro: desenha o contorno antigo
  // sozinho, tracejado, sobre o loteamento de hoje.
  const baixado = d.situacao === 'baixado'
  const noMapa = d.lat && d.lon
    ? (baixado
      ? `<button class="btn" onclick="verBaixadoNoMapa(${d.id}, ${d.lat}, ${d.lon})">
           Ver o contorno antigo no mapa</button>`
      : `<button class="btn" onclick="verImovelNoMapa(${d.lat}, ${d.lon})">Ver no mapa</button>`)
    : ''

  const selo = baixado
    ? `<div class="cad-nota cad-aviso" style="margin-bottom:10px">
         <b>Imóvel baixado</b>${d.baixado_em ? ' em ' + esc(d.baixado_em) : ''}. Ele deixou de
         existir como lote — foi unificado ou desmembrado —, mas os documentos e
         vistorias abaixo continuam sendo dele.</div>`
    : ''

  document.getElementById('busca-resultado').innerHTML = `
    <div class="bs-ficha">
      ${selo}
      <div class="bs-ficha-topo">
        <div>
          <h3 class="mono">${esc(d.inscricao || 'sem inscrição')}</h3>
          <div class="sub">${esc(d.bairro || '—')} · Quadra ${esc(d.quadra ?? '—')} · Lote ${esc(d.lote ?? '—')}</div>
        </div>
        <div class="btn-row" style="margin:0">${noMapa}${voltar}</div>
      </div>

      <div class="sec-title">Cadastro</div>
      <div class="df-grade">
        <div><span class="df-rot">Área do terreno</span><span class="df-val">${d.area ? fmtNum(d.area) + ' m²' : '—'}</span></div>
        <div><span class="df-rot">Chave de integração</span><span class="df-val">${esc(d.chave || '—')}</span></div>
        <div><span class="df-rot">Origem do dado</span><span class="df-val">${esc(d.fonte || '—')}</span></div>
        <div><span class="df-rot">Coordenadas</span><span class="df-val">${d.lat ? d.lat.toFixed(6) + ', ' + d.lon.toFixed(6) : '—'}</span></div>
      </div>

      ${d.quadra ? '' : '<div id="bs-quadra-pendente" class="qp-caixa">Verificando o quarteirao…</div>'}

      <div class="sec-title">Documentos (${d.documentos.length})</div>
      ${docs}

      <div class="sec-title">Vistorias (${d.vistorias.length})</div>
      ${vist}
    </div>`

  // Depois de a ficha estar na tela: a consulta do quarteirão é um segundo
  // acesso ao banco e só interessa a quem está sem quadra. Deixá-la fora da
  // ficha mantém leve a chamada usada em massa pelos pinos do mapa.
  if (!d.quadra) carregarQuarteiraoPendente(d.id)
}

/**
 * Lote sem quadra: oferece corrigir o QUARTEIRÃO INTEIRO de uma vez.
 *
 * Quadra vazia não é defeito do cadastro, é recusa do importador em chutar:
 * quando o desenho não separa as quadras pela rua, ou o rótulo não cai dentro
 * do quarteirão, ele prefere deixar em branco a gravar quadra errada — errada
 * passa despercebida e contamina a chave de integração.
 *
 * Mas quem está na ficha sabe qual é a quadra. Aqui ele informa uma vez, e os
 * lotes vizinhos que também estão sem quadra herdam. A herança nunca atravessa
 * lote que já tem quadra provada; o servidor confere de novo antes de gravar.
 *
 * @param {number} id
 */
async function carregarQuarteiraoPendente(id) {
  const alvo = document.getElementById('bs-quadra-pendente')
  if (!alvo) return

  try {
    const r = await fetch('/api/lotes/' + id + '/quarteirao', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok || !d.aplicavel) { alvo.remove(); return }

    const vizinhas = d.vizinhas.length
      ? `Faz divisa com a(s) quadra(s) <b>${d.vizinhas.map(esc).join(', ')}</b>.`
      : 'Não há quadra identificada fazendo divisa com ele.'
    const falta = d.sugestao
      ? ` Falta a quadra <b>${esc(d.sugestao)}</b> na sequência do bairro.`
      : ''

    // Quarteirão que repete número de lote por dentro são quadras coladas no
    // desenho: não há uma resposta certa a preencher, e insistir criaria
    // identificação duplicada. Aí a saída é o DWG, não esta tela.
    if (!d.coerente) {
      alvo.innerHTML = `
        <div class="qp-tit">Quadra não identificada</div>
        <p class="qp-txt">Este quarteirão repete número de lote por dentro — são duas ou mais
        quadras coladas no desenho, sem a rua entre elas. Preencher aqui criaria identificação
        repetida. A correção é no arquivo DWG do loteamento, com reimportação depois.</p>`
      return
    }

    if (!window.USUARIO_ADMIN) {
      alvo.innerHTML = `
        <div class="qp-tit">Quadra não identificada</div>
        <p class="qp-txt">O desenho do loteamento não permitiu identificar a quadra deste imóvel.
        ${vizinhas} Só o administrador pode preencher.</p>`
      return
    }

    alvo.innerHTML = `
      <div class="qp-tit">Quadra não identificada</div>
      <p class="qp-txt">O desenho do loteamento não permitiu identificar a quadra.
      ${vizinhas}${falta}</p>
      <p class="qp-txt">Informar aqui preenche os <b>${d.lotes} lote(s)</b> deste quarteirão
      (${esc(d.numeracao || 'sem numeração')}) de uma vez. Lote que já tem quadra não é tocado.</p>
      <div class="qp-linha">
        <input id="qp-quadra" class="inp" type="text" inputmode="numeric" maxlength="20"
               placeholder="Quadra" value="${esc(d.sugestao || '')}" style="max-width:8rem">
        <button class="btn btn-primario" onclick="aplicarQuadraQuarteirao(${id})">
          Aplicar ao quarteirão
        </button>
      </div>`
  } catch (e) {
    console.error(e)
    alvo.remove()
  }
}

/** @param {number} id */
async function aplicarQuadraQuarteirao(id) {
  const quadra = (document.getElementById('qp-quadra')?.value || '').trim()
  if (!quadra) { exigirCampo('qp-quadra', 'Informe o número da quadra deste quarteirão.'); return }

  try {
    const r = await fetch('/api/lotes/' + id + '/quadra', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({ quadra }),
    })
    const d = await r.json()
    if (r.status === 419) { toast('Sessão expirada. Recarregando...', 'err'); setTimeout(() => location.reload(), 1500); return }
    // A recusa do servidor é sempre sobre o VALOR digitado — quadra que já tem
    // esse número de lote, quarteirão que na verdade são duas quadras. Marcar o
    // campo poupa o usuário de procurar de onde veio o aviso.
    if (!r.ok) { toast(d.message || 'Não foi possível aplicar.', 'err', { campo: 'qp-quadra' }); return }

    toast(d.message)
    abrirImovel(id)   // recarrega a ficha já com a quadra
  } catch (e) {
    console.error(e)
    toast('Falha de rede ao aplicar a quadra.', 'err')
  }
}

/** Leva o mapa até o imóvel. @param {number} lat @param {number} lon */
function verImovelNoMapa(lat, lon) {
  irPara('mapa')
  setTimeout(() => mapaState.obj?.setView([lat, lon], 19), 220)
}

/** @type {L.GeoJSON|null} o contorno do baixado que está sendo mostrado */
let baixadoNoMapa = null

/**
 * Desenha SÓ o lote baixado, tracejado, por cima do loteamento atual.
 *
 * A camada geral continua sem baixados: religá-los ali traria o loteamento
 * antigo inteiro sobreposto ao novo, e não haveria como saber qual das duas
 * divisas é a que vale. Aqui é um contorno de cada vez, pedido, e que sai da
 * tela no próximo pedido ou no toque em "tirar do mapa".
 *
 * @param {number} id @param {number} lat @param {number} lon
 */
async function verBaixadoNoMapa(id, lat, lon) {
  irPara('mapa')
  await new Promise(r => setTimeout(r, 260))

  const mapa = mapaState.obj
  if (!mapa) { toast('Abra o mapa primeiro.', 'err'); return }

  try {
    const r = await fetch('/api/imoveis/' + id + '/geometria', { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok || !d.geometry) { toast(d.message || 'Este imóvel não tem desenho.', 'err'); return }

    tirarBaixadoDoMapa()

    if (!mapa.getPane('baixados')) {
      const p = mapa.createPane('baixados')
      p.style.zIndex = 645
      p.style.pointerEvents = 'none'
    }

    baixadoNoMapa = L.geoJSON(d.geometry, {
      pane: 'baixados',
      interactive: false,
      style: { color: '#6B7280', weight: 2.5, opacity: .95, dashArray: '7,6', fillOpacity: .07 },
    }).addTo(mapa)

    mapa.setView([lat, lon], 19)
    toast('Contorno antigo em cinza tracejado. Toque em "tirar do mapa" para limpar.', 'aviso')

    const btn = document.getElementById('btn-tirar-baixado')
    if (btn) { btn.hidden = false }
  } catch (e) {
    console.error(e)
    toast('Falha ao buscar o desenho do imóvel baixado', 'err')
  }
}

function tirarBaixadoDoMapa() {
  if (baixadoNoMapa) { mapaState.obj?.removeLayer(baixadoNoMapa); baixadoNoMapa = null }
  const btn = document.getElementById('btn-tirar-baixado')
  if (btn) { btn.hidden = true }
}
