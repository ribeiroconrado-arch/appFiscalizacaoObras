/**
 * FUNDO DA CIDADE — o mapa cadastral completado com o OpenStreetMap nos vazios.
 *
 * O arquivo vem pronto do servidor (App\Services\FundoDaCidade): vias, verde
 * e água do perímetro urbano, já recortados fora dos bairros importados. Aqui
 * só se desenha, num pane ABAIXO do branco dos bairros (fundo-cadastral.js):
 * o que o recorte deixou passar na borda, o bairro cobre.
 *
 * Só no fundo cadastral. Sobre a foto de satélite as ruas já aparecem.
 *
 * A largura da via é em METROS (a da faixa de verdade) e vira pixels a cada
 * zoom, como as ruas entre as quadras, que crescem ao aproximar.
 * Os nomes entram no mesmo desenho dos nomes do cadastro
 * (_desenharRotulosDeRua, em bairros-contorno.js): uma rua, um nome.
 */

const FUNDO_OSM_COR = {
  borda: '#D3D6CF', faixa: '#FFFFFF',
  verde: '#DCE8D2', campo: '#D3E7C6', verdeBorda: '#C3D6B4',
  agua: '#CBE0EC', aguaBorda: '#AFCBDC',
}
const FUNDO_OSM_FINAS = ['cycleway', 'footway', 'path', 'track']
const FUNDO_OSM_RIOS = ['river', 'canal', 'stream']
/** Só estes tipos têm nome no mapa (trilha e ciclovia não). */
const FUNDO_OSM_COM_NOME = ['motorway', 'trunk', 'primary', 'secondary', 'tertiary', 'unclassified', 'residential', 'living_street']

const fundoCidadeState = {
  /** @type {{vias:Object[], areas:Object[]}|null} */ dados: null,
  /** @type {L.LayerGroup|null} */ grupo: null,
  /** @type {L.Renderer|null} */ renderer: null,
  /** As vias desenhadas, para refazer a largura a cada zoom: [camada, metros, ehBorda]. */
  larguras: [],
  /** Os trechos com nome, já prontos para o desenho dos nomes. */
  trechos: [],
}

const _fundoNoCadastral = () => typeof fundoCadastral === 'function' && fundoCadastral()

for (const [id, rotulo] of [['fundo-vias', 'Vias'], ['fundo-nomes', 'Nomes de rua'], ['fundo-verde', 'Áreas verdes'], ['fundo-agua', 'Rios e lagoas']]) {
  registrarCamada({
    id, grupo: 'Fundo da cidade (OpenStreetMap)', rotulo,
    disponivel: () => !camadaLigada('satelite'),
    aoMudar: () => {
      if (id === 'fundo-nomes') { if (typeof _desenharRotulosDeRua === 'function') _desenharRotulosDeRua() }
      else desenharFundoDaCidade()
    },
  })
}

/** Pede o arquivo (uma vez; de novo depois de "Atualizar o fundo"). */
async function carregarFundoDaCidade() {
  const d = await jsonDoFundo('/api/mapa/fundo-cidade')   // da pré-carga (app.js), na primeira vez
  if (!d) return
  fundoCidadeState.dados = d
  fundoCidadeState.trechos = _trechosComNome(fundoCidadeState.dados.vias || [])
  desenharFundoDaCidade()
  if (typeof _desenharRotulosDeRua === 'function') _desenharRotulosDeRua()
}

/** Metros por pixel no centro do mapa. */
function _fundoMetrosPorPixel(m) {
  return 156543.03392 * Math.cos(m.getCenter().lat * Math.PI / 180) / Math.pow(2, m.getZoom())
}

/** Desenha (ou tira) o fundo conforme o fundo do mapa e as camadas ligadas. */
function desenharFundoDaCidade() {
  const m = mapaState.obj
  fundoCidadeState.grupo?.remove()
  fundoCidadeState.grupo = null
  fundoCidadeState.larguras = []
  const d = fundoCidadeState.dados
  if (!m || !d || !_fundoNoCadastral()) return
  if (!m.getPane('fundoCidade')) {
    // Abaixo dos bairros brancos (210): o bairro cobre o que passar da borda.
    m.createPane('fundoCidade').style.zIndex = 205
    m.getPane('fundoCidade').style.pointerEvents = 'none'
    m.on('zoomend', _fundoLarguras)
  }
  fundoCidadeState.renderer ||= L.canvas({ pane: 'fundoCidade', padding: 0.3 })
  const renderer = fundoCidadeState.renderer
  const base = { renderer, interactive: false, lineCap: 'round', lineJoin: 'round' }
  const ll = c => c.map(([x, y]) => [y, x])
  const camadas = []

  if (camadaLigada('fundo-verde')) {
    for (const a of d.areas) if (a.t !== 'agua') camadas.push(L.polygon(ll(a.c), { ...base, color: FUNDO_OSM_COR.verdeBorda, weight: .8, fillColor: a.t === 'campo' ? FUNDO_OSM_COR.campo : FUNDO_OSM_COR.verde, fillOpacity: 1 }))
  }
  if (camadaLigada('fundo-agua')) {
    for (const a of d.areas) if (a.t === 'agua') camadas.push(L.polygon(ll(a.c), { ...base, color: FUNDO_OSM_COR.aguaBorda, weight: .8, fillColor: FUNDO_OSM_COR.agua, fillOpacity: 1 }))
    for (const v of d.vias) if (FUNDO_OSM_RIOS.includes(v.t)) {
      const c = L.polyline(ll(v.c), { ...base, color: FUNDO_OSM_COR.aguaBorda })
      fundoCidadeState.larguras.push([c, v.l, 'rio']); camadas.push(c)
    }
  }
  if (camadaLigada('fundo-vias')) {
    const vias = d.vias.filter(v => !FUNDO_OSM_RIOS.includes(v.t))
    const largas = vias.filter(v => !FUNDO_OSM_FINAS.includes(v.t))
    // Todas as bordas antes de todas as faixas: no cruzamento, as faixas se fundem.
    for (const v of largas) {
      const c = L.polyline(ll(v.c), { ...base, color: FUNDO_OSM_COR.borda })
      fundoCidadeState.larguras.push([c, v.l, 'borda']); camadas.push(c)
    }
    for (const v of largas) {
      const c = L.polyline(ll(v.c), { ...base, color: FUNDO_OSM_COR.faixa })
      fundoCidadeState.larguras.push([c, v.l, 'faixa']); camadas.push(c)
    }
    for (const v of vias.filter(v => FUNDO_OSM_FINAS.includes(v.t))) {
      const c = L.polyline(ll(v.c), { ...base, color: FUNDO_OSM_COR.faixa, opacity: .95, dashArray: v.t === 'cycleway' ? null : '3 3' })
      fundoCidadeState.larguras.push([c, v.l, 'fina']); camadas.push(c)
    }
  }
  if (!camadas.length) return
  _fundoLarguras()
  fundoCidadeState.grupo = L.layerGroup(camadas, { attribution: '© colaboradores do OpenStreetMap' }).addTo(m)
}

/** A largura de cada via no zoom atual. */
function _fundoLarguras() {
  const m = mapaState.obj
  if (!m || !fundoCidadeState.larguras.length) return
  const mpp = _fundoMetrosPorPixel(m)
  for (const [c, metros, papel] of fundoCidadeState.larguras) {
    const px = metros / mpp
    const w = papel === 'borda' ? (px >= 2 ? px + 1.6 : 0)
      : papel === 'faixa' ? Math.max(1, px)
      : papel === 'rio' ? Math.max(1.2, px)
      : Math.max(1, px)
    c.setStyle({ weight: w, opacity: w ? (papel === 'fina' ? .95 : 1) : 0 })
  }
}

/**
 * Os trechos com nome, no formato dos trechos de rua do cadastro
 * ({t:{nome, de:[lat,lon], ate:[lat,lon]}}). Lados seguidos quase na mesma
 * direção viram um trecho só: o nome precisa de reta comprida para caber.
 */
function _trechosComNome(vias) {
  const r = []
  for (const v of vias) {
    if (!v.n || !FUNDO_OSM_COM_NOME.includes(v.t)) continue
    const nome = v.n.toLocaleUpperCase('pt-BR')
    let ini = 0
    for (let k = 1; k < v.c.length; k++) {
      const fim = k === v.c.length - 1
      if (!fim) {
        const a = v.c[ini], b = v.c[k], c = v.c[k + 1]
        const ang = (p, q) => Math.atan2(q[1] - p[1], q[0] - p[0])
        let dif = Math.abs(ang(a, b) - ang(b, c))
        if (dif > Math.PI) dif = 2 * Math.PI - dif
        if (dif < 0.21) continue   // ~12°: ainda é a mesma reta
      }
      const de = v.c[ini], ate = v.c[k]
      r.push({ nome, origem: 'osm', de: [de[1], de[0]], ate: [ate[1], ate[0]] })
      ini = k
    }
  }
  return r
}

/** O ponto cai dentro do contorno de algum bairro (de agora, não o do dia da cópia)? */
function _fundoDentroDeBairro(lat, lon) {
  for (const f of contornoState?.dados?.features || []) {
    const g = f.geometry
    if (!g) continue
    const poligonos = g.type === 'MultiPolygon' ? g.coordinates : [g.coordinates]
    const caixa = f._caixa || (f._caixa = _caixaDosAneis(poligonos.map(p => p[0])))
    if (lon < caixa[0] || lon > caixa[2] || lat < caixa[1] || lat > caixa[3]) continue
    if (poligonos.some(p => _pontoNoAnel(lon, lat, p[0]))) return true
  }
  return false
}

/** Os trechos que entram no desenho dos nomes, agora. */
function trechosDeRuaDoFundo() {
  if (!_fundoNoCadastral() || !camadaLigada('fundo-nomes') || !fundoCidadeState.trechos.length) return []
  // Os contornos mudaram (bairro importado ou gerado de novo): conferir outra vez.
  if (fundoCidadeState.contornos !== contornoState?.dados) {
    fundoCidadeState.contornos = contornoState?.dados
    fundoCidadeState.trechos.forEach(t => { delete t._noBairro })
  }
  // Só os da tela (com folga): a cidade inteira tem milhares, e o desenho dos
  // nomes é refeito a cada movimento.
  const vista = mapaState.obj.getBounds().pad(0.5)
  return fundoCidadeState.trechos.filter(t => {
    if (!vista.contains(t.de) && !vista.contains(t.ate)) return false
    if (t._noBairro === undefined) t._noBairro = _fundoDentroDeBairro((t.de[0] + t.ate[0]) / 2, (t.de[1] + t.ate[1]) / 2)
    return !t._noBairro
  })
}

// ── IMPORTAÇÕES › MAPA ───────────────────────────────────────────
//
// A aba da janela de Importações (importacoes.js chama quando
// `impState.aba === 'mapa'`): a última cópia e o botão de atualizar, que é
// só do administrador. A atualização roda no servidor DEPOIS da resposta
// (leva de 40 s a alguns minutos); a aba pergunta a cada 2 s e mostra a
// etapa e o percentual numa barra (FundoDaCidade::progresso) até acabar.

/** "45 s", "2 min 10 s" */
const _fundoTempo = s => s < 60 ? s + ' s' : Math.floor(s / 60) + ' min ' + (s % 60) + ' s'

/** @param {boolean} [aoTerminar] avisar e redesenhar o mapa quando acabar */
async function pintarAbaMapa(aoTerminar = false) {
  impState.aba = 'mapa'
  if (!aoTerminar) _impCorpo('<div class="vazio-msg">Carregando…</div>')
  try {
    const d = await _impPedir('/api/mapa/fundo-cidade/estado')
    if (impState.aba !== 'mapa') return   // trocou de aba enquanto esperava
    const r = d.resumo
    _impCorpo(`
      <div class="jt">
        <div class="jt-topo">${_impAbas()}</div>
        <div class="jt-rolagem" style="padding:4px 2px">
          <div class="par-sec">Fundo da cidade (OpenStreetMap)</div>
          <p class="imp-sub" style="margin:6px 0 12px">
            Completa o mapa cadastral fora dos bairros importados: vias com nome, áreas verdes e água,
            só dentro do perímetro urbano. É só desenho — nada entra no cadastro.
            Atualize depois de importar bairros ou de publicar um perímetro novo.
          </p>
          <div class="imp-sub" style="margin-bottom:12px">${r
            ? `Última cópia: <b>${esc(r.gerado_em)}</b> · ${esc(r.perimetro)}<br>
               ${r.vias} trechos de via (${r.nomes} nomes) · ${r.verde} áreas verdes · ${r.agua} rios e lagoas`
            : 'Ainda não foi baixado. O mapa cadastral mostra só os bairros importados.'}</div>
          ${!d.andamento && d.erro ? `<div class="cad-nota cad-erro" style="margin-bottom:12px">A última tentativa falhou: ${esc(d.erro)}</div>` : ''}
          ${d.andamento ? `
            <div class="fundo-prog" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${d.pct}">
              <div class="fundo-prog-barra ${d.aguardando ? 'esperando' : ''}" style="width:${Math.max(3, d.pct)}%"></div>
            </div>
            <div class="imp-sub fundo-prog-texto"><span>${esc(d.etapa || '')}</span><b>${d.decorrido != null ? _fundoTempo(d.decorrido) + ' · ' : ''}${d.pct}%</b></div>`
          : d.pode_atualizar
            ? '<button class="btn out-verde sm" onclick="atualizarFundoDaCidade()">Atualizar o fundo</button>'
            : '<span class="imp-sub">Só o administrador atualiza.</span>'}
          <p class="imp-sub" style="margin-top:14px">Dados © colaboradores do OpenStreetMap (licença ODbL).</p>
        </div>
        <div class="jt-rodape">
          <span></span>
          <div class="btn-row"><button class="btn" onclick="fecharImportacoes()">Fechar</button></div>
        </div>
      </div>`)
    if (d.andamento) { setTimeout(() => { if (impState.aba === 'mapa') pintarAbaMapa(true) }, 2000); return }
    if (!aoTerminar) return
    if (d.erro) { toast(d.erro, 'err'); return }
    toast('Fundo da cidade atualizado', 'ok')
    carregarFundoDaCidade()
  } catch (e) {
    _impCorpo(`${_impAbas()}<div class="cad-nota cad-erro">${esc(e.message)}</div>`)
  }
}

/** Pede a atualização e passa a acompanhar. */
async function atualizarFundoDaCidade() {
  try {
    await _impPedir('/api/mapa/fundo-cidade/atualizar', { method: 'POST' })
    pintarAbaMapa(true)
  } catch (e) {
    toast(e.message || 'Não foi possível atualizar o fundo', 'err')
  }
}
