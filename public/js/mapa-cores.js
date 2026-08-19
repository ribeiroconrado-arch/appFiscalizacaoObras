// ══════════════════════════════════════════════
// CORES POR ADJACÊNCIA E RÓTULOS DO MAPA (variante F)
//
// Problema clássico de coloração de mapas: cores podem se repetir, desde que
// dois vizinhos nunca compartilhem a mesma.
//
//   1. agrupa os lotes pela chave (bairro ou quadra);
//   2. descobre quem faz divisa com quem — dois grupos são vizinhos se algum
//      lote de um está a menos de TOL metros de algum lote do outro;
//   3. colore por guloso em ordem de grau: o grupo com mais vizinhos escolhe
//      primeiro, e cada um pega a cor livre MENOS USADA até então.
//
// O passo 3 tem uma sutileza que custou uma correção no protótipo: pegar a
// PRIMEIRA cor livre satisfaz a regra e produz um mapa quase monocromático,
// porque bairros distantes não são vizinhos e acabam todos com a mesma cor.
// Balancear o uso mantém a garantia e ainda distingue grupos afastados.
// ══════════════════════════════════════════════

/** Paleta que convive com o âmbar da interface sem competir com ele. */
const PALETA_MAPA = ['#E8834A', '#7FA98C', '#6E93B8', '#C98BA8', '#B9A24F', '#8E7CC3', '#5FA8A0', '#D4A15E']

/** Distância (m) abaixo da qual dois grupos são considerados vizinhos. */
const TOL_VIZINHO = { bairro: 400, quadra: 70 }

/** Estado da coloração. */
const corState = { chave: 'bairro', cores: {}, rotulos: [] }

/** Centroide aproximado (média dos vértices do anel externo). */
function centroLote(f) {
  const pol = f.geometry.type === 'MultiPolygon' ? f.geometry.coordinates[0] : f.geometry.coordinates
  const anel = pol[0]
  let sx = 0, sy = 0
  for (const [x, y] of anel) { sx += x; sy += y }
  return [sy / anel.length, sx / anel.length]   // [lat, lon]
}

function distM(a, b) {
  const dy = (a[0] - b[0]) * 111320
  const dx = (a[1] - b[1]) * 111320 * Math.cos(a[0] * Math.PI / 180)
  return Math.hypot(dx, dy)
}

/**
 * Resolve a coloração para os lotes carregados.
 * @param {'bairro'|'quadra'} chave
 * @returns {{cores:Object<string,number>, conflitos:number}}
 */
function colorirPorAdjacencia(chave) {
  const grupos = {}
  for (const f of state.lotes.values()) {
    const k = String(f.properties[chave] ?? '?')
    ;(grupos[k] = grupos[k] || []).push(centroLote(f))
  }

  const nomes = Object.keys(grupos)
  const tol = TOL_VIZINHO[chave]

  // matriz de vizinhança
  const viz = {}
  nomes.forEach(n => viz[n] = new Set())
  for (let i = 0; i < nomes.length; i++) {
    for (let j = i + 1; j < nomes.length; j++) {
      const A = grupos[nomes[i]], B = grupos[nomes[j]]
      let perto = false
      for (const a of A) { for (const b of B) { if (distM(a, b) < tol) { perto = true; break } } if (perto) break }
      if (perto) { viz[nomes[i]].add(nomes[j]); viz[nomes[j]].add(nomes[i]) }
    }
  }

  const ordem = [...nomes].sort((a, b) => viz[b].size - viz[a].size)
  const cores = {}
  const uso = new Array(PALETA_MAPA.length).fill(0)

  for (const n of ordem) {
    const usadas = new Set([...viz[n]].map(v => cores[v]).filter(c => c !== undefined))
    let melhor = -1
    for (let i = 0; i < PALETA_MAPA.length; i++) {
      if (usadas.has(i)) continue
      if (melhor < 0 || uso[i] < uso[melhor]) melhor = i
    }
    cores[n] = melhor < 0 ? 0 : melhor
    uso[cores[n]]++
  }

  let conflitos = 0
  nomes.forEach(n => viz[n].forEach(v => { if (cores[n] === cores[v]) conflitos++ }))

  return { cores, conflitos }
}

/** Estilo de um lote conforme a coloração corrente. */
function estiloColorido(f) {
  const k = String(f.properties[corState.chave] ?? '?')
  const cor = PALETA_MAPA[corState.cores[k] ?? 0]
  return { color: cor, weight: 1, opacity: .9, fillColor: cor, fillOpacity: .3 }
}

/** Recalcula cores e repinta. @param {'bairro'|'quadra'} [chave] */
function aplicarCores(chave) {
  if (chave) corState.chave = chave
  const { cores, conflitos } = colorirPorAdjacencia(corState.chave)
  corState.cores = cores

  mapaState.camadas.forEach(c => c.setStyle(estiloColorido(c.feature)))

  document.querySelectorAll('.ctrl-cor button').forEach(b =>
    b.classList.toggle('at', b.dataset.chave === corState.chave))

  const leg = document.getElementById('leg-cores')
  if (leg) {
    const nomes = Object.keys(cores).sort().slice(0, 8)
    leg.innerHTML = nomes.map(n =>
      `<div class="cor"><i style="background:${PALETA_MAPA[cores[n]]}"></i>${esc(n)}</div>`).join('')
      + `<div class="cor" style="margin-top:6px;color:${conflitos ? 'var(--red)' : 'var(--ok,#15803D)'}">`
      + (conflitos ? `⚠ ${conflitos} conflito(s)` : '✓ nenhum vizinho com cor repetida') + '</div>'
  }
  desenharRotulosDeGrupo()
}

// ── RÓTULOS ──────────────────────────────────────────────────

/**
 * Rótulos de bairro e quadra, no centro de cada agrupamento.
 * O do lote é criado junto com o polígono (ver mapa.js).
 */
function desenharRotulosDeGrupo() {
  corState.rotulos.forEach(m => m.remove())
  corState.rotulos = []

  for (const chave of ['bairro', 'quadra']) {
    const g = {}
    for (const f of state.lotes.values()) {
      // A quadra é agrupada por BAIRRO + número. O número só é único dentro
      // do loteamento: existe "Q 05" no Jardim Europa IV e outra no Buritis
      // V. Agrupando só pelo número, os dois conjuntos viravam um grupo só e
      // a média das coordenadas caía no vazio ENTRE os bairros — era o
      // rótulo solto no meio do nada.
      const k = chave === 'quadra'
        ? String(f.properties.bairro ?? '?') + '|' + String(f.properties.quadra ?? '?')
        : String(f.properties.bairro ?? '?')
      ;(g[k] = g[k] || []).push(centroLote(f))
    }
    for (const [k, pts] of Object.entries(g)) {
      const rotulo = chave === 'quadra' ? k.split('|')[1] : k
      if (!rotulo || rotulo === '?' || rotulo === 'null') continue

      const lat = pts.reduce((a, p) => a + p[0], 0) / pts.length
      const lon = pts.reduce((a, p) => a + p[1], 0) / pts.length
      const m = L.marker([lat, lon], {
        interactive: false,
        icon: L.divIcon({ className: '', html: '', iconSize: [0, 0] }),
      })
        .bindTooltip(chave === 'quadra' ? 'Q ' + rotulo : rotulo, {
          permanent: true, direction: 'center',
          className: 'rot rot-' + chave,
        })
        .addTo(mapaState.obj)
      corState.rotulos.push(m)
    }
  }
  rotulosPorZoom()
}

/**
 * Mostra cada camada de rótulo no zoom em que ela é legível.
 * Sem isso, o número dos 2.225 lotes vira um borrão em zoom baixo.
 */
function rotulosPorZoom() {
  const z = mapaState.obj?.getZoom() ?? 0
  document.body.classList.toggle('z-lote', z >= 18)
  document.body.classList.toggle('z-quadra', z >= 16)
  document.body.classList.toggle('z-bairro', z <= 17)

  const leg = document.getElementById('leg-zoom')
  if (leg) {
    leg.textContent = z >= 18 ? 'Bairro, quadra, lote e logradouro'
      : z >= 16 ? 'Bairro, quadra e logradouro · aproxime para o lote'
      : 'Bairro e logradouro · aproxime para quadra e lote'
  }
}
