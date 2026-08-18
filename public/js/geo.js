// ══════════════════════════════════════════════
// GEOMETRIA — auxiliares de cliente
//
// A identificação do lote por GPS saiu daqui: agora é
// POST /api/localizacao/identificar, resolvida no MySQL com ST_Contains e
// prefiltro por envelope (app/Repositories/LoteRepository.php). O servidor tem
// a base inteira; o navegador só tem o que está na tela, então identificar no
// cliente daria a resposta errada assim que o mapa passasse a carregar por bbox.
//
// O que sobra aqui é o que continua sendo pergunta do cliente: onde desenhar e
// a que distância está.
// ══════════════════════════════════════════════

/** Raio médio da Terra em metros. */
const R_TERRA = 6371000

/**
 * Distância em metros entre duas coordenadas geográficas (haversine).
 * @param {number} lat1 @param {number} lon1 @param {number} lat2 @param {number} lon2
 * @returns {number} metros
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
 * Centroide aproximado (média dos vértices do anel externo). Suficiente para
 * posicionar rótulo e medir distância até o fiscal — não é o centroide de área
 * exato, e não precisa ser.
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
