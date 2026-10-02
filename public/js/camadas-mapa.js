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
  const alvo = document.getElementById('camadas-lista')
  if (!alvo) return
  const grupos = []
  for (const c of camadasMapa) {
    if (c.disponivel && !c.disponivel()) continue
    let g = grupos.find(x => x.nome === c.grupo)
    if (!g) { g = { nome: c.grupo, itens: [] }; grupos.push(g) }
    g.itens.push(c)
  }
  alvo.innerHTML = grupos.map(g => `
    <div class="cam-grupo">${esc(g.nome)}</div>
    ${g.itens.map(c => `
      <label class="cam-item">
        <input type="checkbox" ${camadaLigada(c.id) ? 'checked' : ''}
               onchange="ligarCamada('${c.id}', this.checked)">
        ${esc(c.rotulo)}
      </label>`).join('')}`).join('')
}

// ── AS CAMADAS QUE O MAPA JÁ TEM ─────────────────────────────
// Cada módulo novo registra a sua no próprio arquivo; estas são as que
// existiam antes do painel.

registrarCamada({ id: 'lotes', grupo: 'Lotes', rotulo: 'Contorno dos lotes' })
registrarCamada({ id: 'rot-lote', grupo: 'Lotes', rotulo: 'Número do lote' })
registrarCamada({ id: 'medidas', grupo: 'Lotes', rotulo: 'Medidas dos lados' })
registrarCamada({ id: 'rot-quadra', grupo: 'Quadras', rotulo: 'Número da quadra' })
registrarCamada({ id: 'bairro-contorno', grupo: 'Bairros', rotulo: 'Contorno do bairro' })
registrarCamada({ id: 'rot-bairro', grupo: 'Bairros', rotulo: 'Nome do bairro' })
// Desligada por padrão: o serviço de rótulos (Carto) passou a exigir chave e
// devolve "API KEY REQUIRED" estampado por cima do mapa. Quem quiser ver, liga.
registrarCamada({ id: 'ruas', grupo: 'Outras', rotulo: 'Nomes de rua (Carto)', padrao: false })
registrarCamada({ id: 'edificacoes', grupo: 'Outras', rotulo: 'Edificações' })
registrarCamada({ id: 'pinos', grupo: 'Outras', rotulo: 'Pinos do filtro' })
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
registrarCamada({ id: 'satelite', grupo: 'Fundo', rotulo: 'Imagem de satélite' })
