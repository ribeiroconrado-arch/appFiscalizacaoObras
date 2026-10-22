/**
 * CAMADAS DO MAPA — liga e desliga cada coisa que o mapa desenha.
 *
 * Tudo o que aparece no mapa se REGISTRA aqui (`registrarCamada`), e o painel
 * "Camadas" é montado a partir do registro. Camada nova não precisa mexer no
 * painel: registrou, apareceu.
 *
 * Quase todas desligam por CLASSE no contêiner do mapa (`#map.cam-sem-<id>`),
 * e o CSS esconde o pane ou o rótulo correspondente (ver tema-f.css, "CAMADAS").
 * É de propósito: os rótulos e polígonos são recriados a todo momento — ao
 * carregar lotes, ao trocar a cor, ao aproximar —, e uma camada desligada por
 * JavaScript teria de ser desligada de novo a cada recriação. Com a classe,
 * o que nasce depois já nasce escondido.
 *
 * A escolha fica neste navegador (localStorage): é preferência de quem olha,
 * não dado do sistema.
 */

const CAMADAS_CHAVE = 'camadas-mapa'

/**
 * @typedef {{id:string, grupo:string, rotulo:string, padrao?:boolean,
 *            disponivel?:() => boolean, aoMudar?:(ligada:boolean) => void}} Camada
 * @type {Camada[]}
 */
const camadasMapa = []

/** @type {Object<string, boolean>} */
let _camadasEscolha = {}
try { _camadasEscolha = JSON.parse(localStorage.getItem(CAMADAS_CHAVE) || '{}') || {} } catch { _camadasEscolha = {} }

/** @param {Camada} c */
function registrarCamada(c) {
  if (camadasMapa.some(x => x.id === c.id)) return
  camadasMapa.push(c)
  // Só a classe: `aoMudar` é reação a uma TROCA, e no registro nada trocou
  // (e o mapa pode nem existir ainda).
  document.getElementById('map')?.classList.toggle('cam-sem-' + c.id, !camadaLigada(c.id))
  pintarPainelCamadas()
}

/** @param {string} id */
function camadaLigada(id) {
  const c = camadasMapa.find(x => x.id === id)
  if (!c) return true
  return _camadasEscolha[id] ?? c.padrao ?? true
}

/** @param {string} id @param {boolean} ligada */
function ligarCamada(id, ligada) {
  _camadasEscolha[id] = !!ligada
  try { localStorage.setItem(CAMADAS_CHAVE, JSON.stringify(_camadasEscolha)) } catch { /* navegador sem armazenamento: vale até recarregar */ }
  const c = camadasMapa.find(x => x.id === id)
  if (c) aplicarCamada(c)
  pintarPainelCamadas()   // ligada também por código (ex.: "Ver no mapa" da importação)
}

/** @param {Camada} c */
function aplicarCamada(c) {
  const ligada = camadaLigada(c.id)
  document.getElementById('map')?.classList.toggle('cam-sem-' + c.id, !ligada)
  if (c.aoMudar) { try { c.aoMudar(ligada) } catch (e) { console.error(e) } }
}

/** O painel, agrupado na ordem em que os grupos aparecem no registro. */
function pintarPainelCamadas() {
  atualizarIndicadorCamadas()
  const alvo = document.getElementById('camadas-lista')
  if (!alvo) return
  const abertos = new Set([...alvo.querySelectorAll('details[open]')].map(d => d.dataset.grupo))
  const inicial = !alvo.children.length
  const foco = alvo.contains(document.activeElement) ? document.activeElement.id : null
  const rolagem = alvo.scrollTop
  const grupos = []
  for (const c of camadasMapa) {
    if (c.disponivel && !c.disponivel()) continue
    const nome = ['Quadras', 'Bairros'].includes(c.grupo) ? 'Quadras e bairros'
      : ['sinalizacoes', 'nao-publicados', 'conferencia'].includes(c.id) ? 'Fiscalização e curadoria'
      : c.grupo === 'Outras' ? 'Ruas e edificações' : c.grupo
    let g = grupos.find(x => x.nome === nome)
    if (!g) { g = { nome, itens: [] }; grupos.push(g) }
    g.itens.push(c)
  }
  // O FUNDO É UMA ESCOLHA ENTRE DOIS, e não um liga-desliga: sem satélite o
  // mapa não fica vazio, fica cadastral (fundo-cadastral.js).
  const fundo = () => `
    <label class="cam-item"><input id="cam-fundo-cadastral" type="radio" name="cam-fundo" ${camadaLigada('satelite') ? '' : 'checked'}
           onchange="ligarCamada('satelite', false)"><span>Cadastral<small class="cam-obs">Leve, sem imagem</small></span></label>
    <label class="cam-item"><input id="cam-fundo-satelite" type="radio" name="cam-fundo" ${camadaLigada('satelite') ? 'checked' : ''}
           onchange="ligarCamada('satelite', true)"><span>Satélite<small class="cam-obs">Imagem aérea</small></span></label>`
  alvo.innerHTML = `<div class="cam-secao">Fundo do mapa</div><div class="cam-fundos">${fundo()}</div><div class="cam-secao">Camadas do mapa</div>` + grupos.filter(g => g.nome !== 'Fundo').map(g => `
    <details class="cam-grupo" data-grupo="${esc(g.nome)}" ${abertos.has(g.nome) || (inicial && g.nome === 'Lotes') ? 'open' : ''}>
    <summary>${esc(g.nome)}<span>${g.itens.filter(c => camadaLigada(c.id)).length}/${g.itens.length}</span></summary>
    ${g.itens.map(c => `
      <label class="cam-item">
        <input id="cam-${c.id}" role="switch" type="checkbox" ${camadaLigada(c.id) ? 'checked' : ''}
               onchange="ligarCamada('${c.id}', this.checked)">
        ${esc(c.rotulo)}
      </label>`).join('')}</details>`).join('')
  alvo.scrollTop = rolagem
  if (foco) document.getElementById(foco)?.focus({ preventScroll: true })
}

/** Compara as opções disponíveis com os padrões declarados pelos módulos. */
function camadasForaDoPadrao() {
  return camadasMapa.filter(c => c.id !== 'satelite' && (!c.disponivel || c.disponivel())
    && camadaLigada(c.id) !== (c.padrao ?? true))
}

/** O fundo é uma preferência independente; restaura somente as camadas disponíveis. */
function restaurarPadraoCamadas() {
  const alteradas = camadasForaDoPadrao()
  // Atualiza todas as preferências antes dos callbacks que recarregam o mapa.
  alteradas.forEach(c => { delete _camadasEscolha[c.id] })
  try { localStorage.setItem(CAMADAS_CHAVE, JSON.stringify(_camadasEscolha)) } catch { /* vale nesta sessão */ }
  alteradas.forEach(aplicarCamada)
  pintarPainelCamadas()
}

function atualizarIndicadorCamadas() {
  const quantidade = camadasForaDoPadrao().length
  const restaurar = document.getElementById('cam-restaurar')
  if (restaurar) restaurar.disabled = quantidade === 0
  if (typeof marcarIndicadorControle === 'function') marcarIndicadorControle('grupo-camadas', quantidade || null)
  const botao = document.querySelector('#grupo-camadas .ctrl-btn')
  const descricao = quantidade ? `Mapa e camadas — ${quantidade} camadas diferentes do padrão` : 'Mapa e camadas — camadas no padrão'
  botao?.setAttribute('title', descricao)
  botao?.setAttribute('aria-label', descricao)
}
document.addEventListener('DOMContentLoaded', atualizarIndicadorCamadas)

function posicionarPainelCamadas() {
  const painel = document.querySelector('#grupo-camadas.aberto .ctrl-camadas')
  const mapa = document.getElementById('map')
  if (!painel || !mapa) return
  const r = mapa.getBoundingClientRect()
  const mobile = window.innerWidth <= 620
  const altura = Math.max(0, Math.min(r.bottom, window.innerHeight) - Math.max(r.top, 0))
  const largura = Math.max(0, mobile ? r.width : Math.min(360, r.width - 76))
  painel.style.width = largura + 'px'
  painel.style.left = (mobile ? r.left : r.right - largura - 62) + 'px'
  painel.style.top = (mobile ? Math.max(r.top, 0) + altura - Math.min(altura, window.innerHeight * .78) : Math.max(r.top, 0) + 10) + 'px'
  painel.style.maxHeight = (mobile ? Math.min(altura, window.innerHeight * .78) : Math.max(0, altura - 20)) + 'px'
  painel.style.height = mobile ? painel.style.maxHeight : 'auto'
}
window.addEventListener('resize', posicionarPainelCamadas)
document.addEventListener('DOMContentLoaded', () => {
  const mapa = document.getElementById('map')
  if (mapa) new ResizeObserver(posicionarPainelCamadas).observe(mapa)
})
document.addEventListener('keydown', ev => {
  if (ev.key === 'Escape' && document.querySelector('#grupo-camadas.aberto')) {
    alternarPainelMapa('grupo-camadas')
    document.querySelector('#grupo-camadas .ctrl-btn')?.focus()
  }
})

// ── AS CAMADAS QUE O MAPA JÁ TEM ─────────────────────────────
// Cada módulo novo registra a sua no próprio arquivo; estas são as que
// existiam antes do painel.

registrarCamada({ id: 'lotes', grupo: 'Lotes', rotulo: 'Contorno dos lotes' })
registrarCamada({ id: 'rot-lote', grupo: 'Lotes', rotulo: 'Número do lote' })
registrarCamada({ id: 'medidas', grupo: 'Lotes', rotulo: 'Medidas dos lados' })
registrarCamada({ id: 'rot-quadra', grupo: 'Quadras', rotulo: 'Número da quadra' })
// O desenho das quadras com o mapa afastado (bairros-contorno.js): as ruas aparecem de longe.
registrarCamada({ id: 'quadras-silhueta', grupo: 'Quadras', rotulo: 'Silhueta das quadras (mapa afastado)',
  aoMudar: () => { if (typeof aplicarNivelDoMapa === 'function') aplicarNivelDoMapa() } })
registrarCamada({ id: 'bairro-contorno', grupo: 'Bairros', rotulo: 'Contorno do bairro' })
registrarCamada({ id: 'rot-bairro', grupo: 'Bairros', rotulo: 'Nome do bairro' })
// Nomes de rua tirados do cadastro municipal (bairros-contorno.js): o nome
// oficial, o mesmo dos documentos. Ligados de início; o do Carto, abaixo, não
// serve mais.
registrarCamada({ id: 'ruas-cadastro', grupo: 'Outras', rotulo: 'Nomes de rua (cadastro)', padrao: true })
// Desligada por padrão: o serviço de rótulos (Carto) passou a exigir chave e
// devolve "API KEY REQUIRED" estampado por cima do mapa. Quem quiser ver, liga.
registrarCamada({ id: 'ruas', grupo: 'Outras', rotulo: 'Nomes de rua (Carto)', padrao: false })
registrarCamada({ id: 'edificacoes', grupo: 'Outras', rotulo: 'Edificações' })
// Os lotes de importação ainda não publicada: só o curador os carrega. Era a
// caixa "Mostrar lotes não publicados" da curadoria; agora mora aqui, com as
// outras camadas. O pedido ao servidor lê camadaLigada('nao-publicados') (app.js).
registrarCamada({
  id: 'nao-publicados', grupo: 'Outras', rotulo: 'Lotes não publicados',
  disponivel: () => !!window.USUARIO_CURADOR,
  aoMudar: () => {
    if (typeof recarregarLotesDoMapa === 'function' && mapaState?.obj) recarregarLotesDoMapa()
    if (typeof pintarBarraImportacao === 'function') pintarBarraImportacao()
  },
})
registrarCamada({
  id: 'satelite', grupo: 'Fundo', rotulo: 'Imagem de satélite',
  aoMudar: () => { if (typeof aplicarFundoDoMapa === 'function') aplicarFundoDoMapa() },
})
