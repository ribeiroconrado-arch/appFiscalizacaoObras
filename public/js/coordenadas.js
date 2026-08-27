// ══════════════════════════════════════════════
// COORDENADAS EM GRAUS, MINUTOS E SEGUNDOS
//
// Terceiro caminho para criar um lote: em vez de desenhar no mapa, digitar (ou
// colar) os vértices que vêm do memorial descritivo da matrícula.
//
// ── Por que ler texto solto, e não pedir campos ──
//
// O memorial chega copiado de um PDF, de um e-mail ou digitado à mão, e cada
// cartório escreve de um jeito: 15°31'04,5"S ou 15º31'4.5" S ou -15 31 04.5.
// Pedir "grau", "minuto" e "segundo" em campos separados obrigaria a redigitar
// 4 vértices × 6 números — 24 chances de errar um dígito. Ler o texto como ele
// vem é o que torna a função utilizável em campo.
//
// ── O que NÃO se aceita ──
//
// Coordenada fora do município é recusada com o número da linha. Sinal trocado
// (hemisfério errado) é o erro mais comum ao transcrever memorial, e ele não
// aparece como erro: aparece como um lote no meio do oceano, ou na Ásia. Vale
// mais recusar e apontar a linha do que gravar geometria absurda.
// ══════════════════════════════════════════════

/**
 * Faixa aceitável para o município. Serve de rede: fora dela, o que existe é
 * erro de transcrição, não um lote distante.
 * @type {{lat:[number,number], lon:[number,number]}}
 */
const FAIXA_MUNICIPIO = { lat: [-16.2, -14.2], lon: [-55.2, -53.2] }

/**
 * Lê um bloco de texto com um vértice por linha.
 *
 * @param {string} texto
 * @returns {{vertices: Array<[number,number]>, erros: string[]}}
 *          vertices em [lon, lat], que é a ordem do GeoJSON
 */
function lerCoordenadasGMS(texto) {
  const vertices = []
  const erros = []

  const linhas = String(texto || '').split(/\r?\n/)
  linhas.forEach((linha, i) => {
    const limpa = linha.trim()
    if (!limpa || limpa.startsWith('#')) { return }   // linha vazia ou comentário

    const par = _parDaLinha(limpa)
    if (!par) {
      erros.push(`Linha ${i + 1}: não consegui ler duas coordenadas em "${_curta(limpa)}".`)
      return
    }

    const [lat, lon] = par
    if (lat < FAIXA_MUNICIPIO.lat[0] || lat > FAIXA_MUNICIPIO.lat[1]
        || lon < FAIXA_MUNICIPIO.lon[0] || lon > FAIXA_MUNICIPIO.lon[1]) {
      erros.push(`Linha ${i + 1}: ${lat.toFixed(6)}, ${lon.toFixed(6)} cai fora do município `
        + '— confira o hemisfério (S e O são negativos) e a ordem latitude/longitude.')
      return
    }

    // Arredondado já na leitura: 7 casas são ~1 cm, e é a mesma régua do
    // desenho livre. Sem isso, a prévia mostraria -54.31108333333333, que não
    // significa nada além de ruído de ponto flutuante.
    vertices.push([_arred(lon), _arred(lat)])
  })

  return { vertices, erros }
}

/**
 * Polígono GeoJSON a partir dos vértices lidos.
 *
 * @param {Array<[number,number]>} vertices
 * @returns {{erro:string}|{geometry:Object, fechado:boolean}}
 */
function poligonoDeCoordenadas(vertices) {
  if (!vertices || vertices.length < 3) {
    return { erro: 'São necessários pelo menos 3 vértices para formar um lote.' }
  }

  const v = vertices.map(c => [_arred(c[0]), _arred(c[1])])

  // Memorial costuma repetir o primeiro ponto no fim, para fechar o perímetro;
  // outros param no último canto. Os dois casos precisam produzir o mesmo
  // polígono, então a repetição é removida e o fechamento é sempre nosso.
  const fechado = _mesmoPonto(v[0], v[v.length - 1])
  const anel = fechado ? v.slice(0, -1) : v.slice()

  if (anel.length < 3) {
    return { erro: 'Depois de fechar o perímetro sobraram menos de 3 vértices.' }
  }

  return {
    fechado,
    geometry: { type: 'Polygon', coordinates: [[...anel, anel[0]]] },
  }
}

// ── leitura de uma linha ──────────────────────────────────────

/**
 * Duas coordenadas numa linha, em qualquer das grafias usuais.
 * @returns {[number,number]|null} [lat, lon]
 */
function _parDaLinha(linha) {
  const achados = []
  const re = _regexGMS()
  let m

  while ((m = re.exec(linha)) !== null) {
    const valor = _paraDecimal(m)

    if (valor === null) {
      // Trecho recusado (um rótulo "V1", por exemplo) — mas a varredura já
      // consumiu o que veio depois dele. Sem recuar, a coordenada verdadeira
      // que estava DENTRO do trecho consumido nunca seria vista, e a linha
      // inteira era dada como ilegível. Volta-se um caractere à frente do
      // início e a busca recomeça de lá.
      re.lastIndex = m.index + 1
      continue
    }

    achados.push(valor)
    if (achados.length === 2) { break }
  }

  if (achados.length < 2) { return null }

  // Com sufixo (S/N e O/W/L/E), a ordem escrita não importa: quem manda é a
  // letra. Sem sufixo, vale a convenção do memorial — latitude primeiro.
  const [a, b] = achados
  if (a.eixo === 'lon' && b.eixo === 'lat') { return [b.valor, a.valor] }

  return [a.valor, b.valor]
}

/**
 * Uma coordenada: graus obrigatórios, minutos e segundos opcionais, sufixo
 * opcional. Aceita ° º ' ′ " ″ e espaço como separadores, vírgula ou ponto
 * como decimal.
 */
function _regexGMS() {
  return new RegExp(
    '([+-]?\\d{1,3})\\s*(?:[°º]|\\s|$)\\s*'      // graus
    + '(?:(\\d{1,2}(?:[.,]\\d+)?)\\s*(?:[\'′´]|\\s)\\s*)?'   // minutos
    + '(?:(\\d{1,2}(?:[.,]\\d+)?)\\s*(?:["″”]{1,2}|\\s)?\\s*)?'  // segundos
    + '([NSLOWEnslowe])?',                        // hemisfério
    'g'
  )
}

/** @returns {{valor:number, eixo:'lat'|'lon'|null}|null} */
function _paraDecimal(m) {
  const g = parseInt(m[1], 10)
  if (Number.isNaN(g)) { return null }

  // Número solto NÃO é coordenada. Memorial vem com rótulo de vértice ("V1 -",
  // "P3:", "Vértice 2"), e sem esta guarda o "1" do V1 virava 1 grau, jogando
  // o lote para o meio do Atlântico. Para valer, o número precisa trazer ao
  // menos uma marca de coordenada: o símbolo de grau, os minutos, ou o
  // hemisfério.
  const temMarca = /[°º]/.test(m[0]) || m[2] !== undefined || (m[4] || '') !== ''
  if (!temMarca) { return null }

  const min = m[2] ? parseFloat(m[2].replace(',', '.')) : 0
  const seg = m[3] ? parseFloat(m[3].replace(',', '.')) : 0
  if (min >= 60 || seg >= 60) { return null }

  const sufixo = (m[4] || '').toUpperCase()
  let valor = Math.abs(g) + min / 60 + seg / 3600

  // Sinal: o sufixo manda; sem ele, o sinal escrito nos graus.
  const negativo = sufixo === 'S' || sufixo === 'O' || sufixo === 'W'
    || (!sufixo && /^-/.test(m[1]))
  if (negativo) { valor = -valor }

  const eixo = (sufixo === 'N' || sufixo === 'S') ? 'lat'
    : (sufixo === 'L' || sufixo === 'O' || sufixo === 'W' || sufixo === 'E') ? 'lon'
    : null

  return { valor, eixo }
}

/** 7 casas ≈ 1 cm, a mesma régua do desenho livre. */
function _arred(n) { return Number(n.toFixed(7)) }

function _mesmoPonto(a, b) {
  return Math.abs(a[0] - b[0]) < 1e-7 && Math.abs(a[1] - b[1]) < 1e-7
}

function _curta(s) { return s.length > 38 ? s.slice(0, 35) + '…' : s }
