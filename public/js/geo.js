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

// ── GRADE E TERRENO: O FATOR DE ESCALA DO UTM ────────────────
//
// A base municipal nasce em SIRGAS 2000 / UTM 21S (EPSG:31981, ver
// config/gis.php) e é reprojetada para 4326 só para o Leaflet consumir. UTM é
// uma projeção de GRADE: a distância que ela mede não é a distância no chão,
// e a razão entre as duas é o fator de escala `k` do ponto.
//
// Em Primavera do Leste, a 2,7° do meridiano central da zona 21, k ≈ 1,00063.
// Medir no plano tangente ao elipsoide — que é a distância de TERRENO — dava
// 0,063% a menos que o DWG em cada lado, e 0,126% a menos em área. Conferido
// sobre os 2.482 lotes dos três loteamentos importados: a razão medida entre
// as duas réguas bate com este `k` com resíduo de 0,0015%, o que prova que a
// diferença é a projeção e mais nada.
//
// Em números do Residencial Buritis V: lote projetado com 10,00 × 21,50 m
// aparecia na tela como 9,99 × 21,49 m.
//
// ── Por que a GRADE é a régua do sistema ──
//
// Porque é a régua dos documentos com os quais o sistema conversa. A matrícula,
// o projeto de loteamento e a planta do agrimensor trazem medida de grade — é
// o que está desenhado no DWG. Um auto que diga 9,99 onde a matrícula diz
// 10,00 obriga o fiscal a explicar a diferença em cada peça, e "é a projeção"
// não é resposta que se dê a quem contesta uma multa por metro quadrado.
//
// Nenhuma das duas medidas é errada; são réguas diferentes. O que não se pode
// é medir o mesmo lote com as duas — e a que vale aqui é a do papel.

/** Fator de escala no meridiano central do UTM. */
const UTM_K0 = 0.9996

/** Primeira excentricidade ao quadrado do GRS80 / SIRGAS 2000. */
const UTM_E2 = 0.00669438002290

/**
 * O fator de escala do UTM no ponto: quantos metros de GRADE valem um metro
 * de terreno ali.
 *
 * A zona sai da própria longitude, e não de configuração: assim o cálculo
 * acompanha o município sem depender de ninguém lembrar de ajustar uma chave.
 * Para Primavera do Leste isto devolve a zona 21 (meridiano central 57°O), que
 * é a mesma do `srid_origem` declarado em config/gis.php — se um dia as duas
 * discordarem, é a base que está em outra zona, e aí o pipeline de importação
 * é que precisa ser conferido.
 *
 * @param {number} lat @param {number} lon em graus
 * @returns {number} fator de escala (adimensional, ~1,0006 aqui)
 */
function fatorEscalaUTM(lat, lon) {
  const rad = Math.PI / 180
  const zona = Math.floor((lon + 180) / 6) + 1
  const meridianoCentral = zona * 6 - 183

  const f = lat * rad
  const t = Math.tan(f)
  const linhaE2 = UTM_E2 / (1 - UTM_E2)          // e'², segunda excentricidade
  const eta2 = linhaE2 * Math.cos(f) ** 2
  const a = (lon - meridianoCentral) * rad * Math.cos(f)
  const a2 = a * a

  // Série do fator de escala pontual da Transversa de Mercator. O termo de
  // quarta ordem vale menos de um milímetro em cem metros nesta distância do
  // meridiano central, mas custa uma linha e evita ter de justificar o corte.
  return UTM_K0 * (1
    + (1 + eta2) * a2 / 2
    + (5 - 4 * t * t + 42 * eta2 + 13 * eta2 * eta2 - 28 * linhaE2) * a2 * a2 / 24)
}

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
