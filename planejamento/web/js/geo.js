// ══════════════════════════════════════════════
// GEOMETRIA — consultas espaciais no cliente
//
// No protótipo o "banco espacial" é a memória do navegador: os 707 lotes cabem
// folgados e a busca é instantânea. Quando a Etapa 2 entrar, estas funções são
// substituídas pelo endpoint POST /api/localizacao/identificar, que fará o
// mesmo com ST_Contains + prefiltro por envelope no banco. A lógica de decisão
// (acerto direto → vizinhos → confirmação do fiscal) é a mesma dos dois lados,
// de propósito: é o fluxo do §9 do documento do projeto.
// ══════════════════════════════════════════════

/** Raio médio da Terra em metros. */
const R_TERRA = 6371000

/**
 * Distância em metros entre duas coordenadas geográficas (haversine).
 * @param {number} lat1 @param {number} lon1 @param {number} lat2 @param {number} lon2
 * @returns {number} distância em metros
 */
function distanciaM(lat1, lon1, lat2, lon2) {
  const rad = Math.PI / 180
  const dLat = (lat2 - lat1) * rad
  const dLon = (lon2 - lon1) * rad
  const a = Math.sin(dLat / 2) ** 2 +
            Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLon / 2) ** 2
  return 2 * R_TERRA * Math.asin(Math.sqrt(a))
}

/**
 * Teste ponto-em-polígono por lançamento de raio (ray casting).
 * `anel` é um array de pares [lon, lat], como vem do GeoJSON.
 *
 * @param {number} lon @param {number} lat
 * @param {Array<[number,number]>} anel
 * @returns {boolean}
 */
function pontoNoAnel(lon, lat, anel) {
  let dentro = false
  for (let i = 0, j = anel.length - 1; i < anel.length; j = i++) {
    const [xi, yi] = anel[i]
    const [xj, yj] = anel[j]
    // A aresta cruza a horizontal do ponto? Se sim, o cruzamento fica à direita?
    if ((yi > lat) !== (yj > lat) &&
        lon < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) {
      dentro = !dentro
    }
  }
  return dentro
}

/**
 * Ponto dentro de uma feição Polygon do GeoJSON: dentro do anel externo e fora
 * de qualquer anel interno (buraco). Lote não costuma ter buraco, mas tratar o
 * caso custa três linhas e evita surpresa em área institucional.
 *
 * @param {number} lon @param {number} lat
 * @param {{type:string, coordinates:any}} geom
 * @returns {boolean}
 */
function pontoNaFeicao(lon, lat, geom) {
  const partes = geom.type === 'MultiPolygon' ? geom.coordinates : [geom.coordinates]
  for (const pol of partes) {
    if (!pontoNoAnel(lon, lat, pol[0])) continue
    let emBuraco = false
    for (let k = 1; k < pol.length; k++) {
      if (pontoNoAnel(lon, lat, pol[k])) { emBuraco = true; break }
    }
    if (!emBuraco) return true
  }
  return false
}

/**
 * Centroide aproximado (média dos vértices do anel externo). Suficiente para
 * ordenar candidatos por proximidade e para posicionar rótulo — não é o
 * centroide de área exato, e não precisa ser.
 *
 * @param {{type:string, coordinates:any}} geom
 * @returns {{lat:number, lon:number}}
 */
function centroide(geom) {
  const pol = geom.type === 'MultiPolygon' ? geom.coordinates[0] : geom.coordinates
  const anel = pol[0]
  let sx = 0, sy = 0
  for (const [x, y] of anel) { sx += x; sy += y }
  return { lon: sx / anel.length, lat: sy / anel.length }
}

/**
 * Identifica o lote sob uma coordenada de GPS. Reproduz a decisão prevista no
 * §9 do projeto:
 *
 *   1. acerto direto  — o ponto cai dentro de um lote;
 *   2. sem acerto     — devolve os lotes próximos, ordenados por distância,
 *                       dentro da tolerância (que cresce com a imprecisão
 *                       informada pelo próprio GPS);
 *   3. mais de um     — quem decide é o fiscal, na tela de confirmação.
 *
 * A tolerância não é fixa: usa a `accuracy` do aparelho, com piso de 25 m,
 * porque um GPS que se declara preciso a ±40 m não pode ser tratado como um
 * que se declara preciso a ±5 m.
 *
 * @param {number} lat @param {number} lon
 * @param {number} accuracy precisão informada pelo GPS, em metros
 * @param {Array<Object>} feicoes feições GeoJSON dos lotes
 * @returns {{exato:Object|null, candidatos:Array<{feicao:Object,dist:number}>}}
 */
function identificarLote(lat, lon, accuracy, feicoes) {
  for (const f of feicoes) {
    if (pontoNaFeicao(lon, lat, f.geometry)) {
      return { exato: f, candidatos: [] }
    }
  }
  const tol = Math.max(25, Math.min(accuracy || 0, 120))
  const perto = []
  for (const f of feicoes) {
    const c = centroide(f.geometry)
    const d = distanciaM(lat, lon, c.lat, c.lon)
    if (d <= tol + 40) perto.push({ feicao: f, dist: d })
  }
  perto.sort((a, b) => a.dist - b.dist)
  return { exato: null, candidatos: perto.slice(0, 6) }
}
