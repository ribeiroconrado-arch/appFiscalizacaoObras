// ══════════════════════════════════════════════
// A MESA DE DESMEMBRAMENTO
//
// Dividir um lote não é a mesma coisa que corrigir o mapa, e por isso ganha
// tela própria: o lote alvo ocupa a tela, os vizinhos ficam como referência
// apagada e sem clique, e o satélite pode ser desligado quando a imagem
// atrapalha mais do que ajuda.
//
// ── O contorno externo é INTOCÁVEL ──
//
// No modo "desenhar polígonos" cada parte é traçada à mão, e nada impede que a
// divisa externa saia diferente da original — o lote muda de contorno num ato
// que só deveria dividi-lo. Aqui esse modo não existe: só há o CORTE POR LINHA
// (corte.js), que devolve as partes compartilhando a divisa vértice a vértice
// e preserva as extremidades do pai. Não é preferência de interface: é a única
// forma de o ato ser aritmeticamente o que ele diz ser.
// ══════════════════════════════════════════════

const desmMesa = {
  ativa: false,
  /** @type {number|null} */ loteId: null,
  /** @type {L.Polygon|null} realce do lote alvo */ realce: null,
  /** @type {L.Marker[]} medidas dos lados do alvo */ rotulos: [],
  satelite: true,
}

/**
 * Entra na mesa: foca o lote, apaga a vizinhança e trava o resto.
 *
 * @param {number} [loteId] o alvo; sem ele, o lote selecionado no mapa
 */
function abrirMesaDesmembramento(loteId) {
  const id = loteId || desmState.loteId || state.selecionado?.properties?.id
  if (!id) { toast('Selecione no mapa o lote a desmembrar.', 'err'); return }

  const feicao = state.lotes.get(id)
  if (!feicao?.geometry?.coordinates?.[0]) {
    toast('O lote precisa estar visível no mapa para ser desmembrado.', 'err')
    return
  }
  if ((feicao.geometry.coordinates.length ?? 1) > 1) {
    toast('Este lote tem um vazio interno; o corte por linha não trata esse caso.', 'err')
    return
  }

  desmMesa.ativa = true
  desmMesa.loteId = id
  desmState.loteId = id

  document.getElementById('map')?.classList.add('desm-foco')
  const barra = document.getElementById('desm-mesa')
  if (barra) { barra.hidden = false }

  // A mesa cobre a lateral; a mesa cadastral sai de cena para não haver duas
  // colunas disputando o mesmo assunto.
  if (typeof fecharMesaCadastral === 'function') { fecharMesaCadastral() }

  _enquadrarAlvo(feicao)
  _realcarAlvo(feicao)
  pintarMesaDesmembramento()
}

function sairMesaDesmembramento() {
  desmMesa.ativa = false
  _limparRealce()
  document.getElementById('map')?.classList.remove('desm-foco', 'sem-satelite')
  desmMesa.satelite = true
  const barra = document.getElementById('desm-mesa')
  if (barra) { barra.hidden = true }
  cancelarDesenho()

  // Devolve às FERRAMENTAS. Sem isto, sair do desmembramento deixava a tela
  // sem coluna nenhuma, e a única saída era reabrir tudo pelo ícone — quem
  // desiste de um ato quase sempre quer o ato ao lado, não o mapa limpo.
  if (typeof ehMesaCadastral === 'function' && ehMesaCadastral()) {
    abrirMesaCadastral()
  }
}

/**
 * Painel próprio para o realce e as medidas do alvo.
 *
 * Não dá para usar o painel `desenho`: ele só é criado quando um desenho
 * começa, e a mesa realça o lote ANTES de haver traço nenhum — usá-lo aqui
 * derrubava o Leaflet com "Cannot read properties of undefined".
 *
 * Fica ACIMA dos lotes (z-index 640) e abaixo do painel de desenho (650), e é
 * surdo a cliques: o realce é para ler, não para tocar.
 */
function _paneMesa() {
  const mapa = mapaState.obj
  if (!mapa.getPane('desmfoco')) {
    const p = mapa.createPane('desmfoco')
    p.style.zIndex = 640
    p.style.pointerEvents = 'none'
  }
  return 'desmfoco'
}

/** @param {Object} feicao */
function _enquadrarAlvo(feicao) {
  const anel = feicao.geometry.coordinates[0]
  const lats = anel.map(c => c[1])
  const lons = anel.map(c => c[0])
  mapaState.obj.fitBounds(
    [[Math.min(...lats), Math.min(...lons)], [Math.max(...lats), Math.max(...lons)]],
    { padding: [90, 90], animate: false }
  )
}

/**
 * Realça o alvo e escreve a medida de cada lado dele.
 *
 * As medidas do PAI ficam à vista o tempo todo porque é contra elas que se
 * confere o corte: uma frente de 24 m que vira duas de 12 é o caso comum, e
 * ver os dois números na tela evita a conta de cabeça.
 */
function _realcarAlvo(feicao) {
  _limparRealce()
  const mapa = mapaState.obj

  desmMesa.realce = L.geoJSON(feicao.geometry, {
    pane: _paneMesa(),
    interactive: false,
    style: { color: '#EA580C', weight: 3, opacity: 1, fillColor: '#EA580C', fillOpacity: .06 },
  }).addTo(mapa)

  const anel = feicao.geometry.coordinates[0]
  // O anel do GeoJSON repete o primeiro ponto no fim; medir esse lado de novo
  // escreveria a mesma etiqueta duas vezes em cima do mesmo lugar.
  const v = anel.slice(0, -1)
  const cx = v.reduce((s, c) => s + c[0], 0) / v.length
  const cy = v.reduce((s, c) => s + c[1], 0) / v.length

  // O plano local do desenho é fixado no primeiro vértice do que está sendo
  // desenhado; aqui não há desenho ainda, então mede-se com o plano do próprio
  // lote — a mesma projeção, outra origem.
  const p = planoLocal(v[0][1], v[0][0])
  const emMetros = c => [(c[0] - p.lonRef) * p.porGrauLon, (c[1] - p.latRef) * p.porGrauLat]

  // LADO CURTO NÃO GANHA ETIQUETA.
  //
  // A esquina do loteamento vem do DWG como uma curva de concordância: 23
  // segmentos de 2,05 m que, rotulados, viram uma nuvem de números iguais em
  // cima do lote e escondem justamente as divisas que interessam. O corte de
  // 3 m deixa passar frente, fundos e laterais e cala o arco.
  //
  // Zero também é filtrado por aqui: há vértices repetidos na base convertida,
  // e "0,00 m" só diz que o desenho tem um ponto sobrando.
  const MINIMO_ROTULO_M = 3

  desmMesa.rotulos = v.map((a, i) => {
    const b = v[(i + 1) % v.length]
    const pa = emMetros(a)
    const pb = emMetros(b)
    const d = Math.hypot(pb[0] - pa[0], pb[1] - pa[1])
    if (d < MINIMO_ROTULO_M) { return null }

    return L.marker([(a[1] + b[1]) / 2, (a[0] + b[0]) / 2], {
      pane: _paneMesa(), interactive: false, keyboard: false,
      icon: L.divIcon({
        className: 'des-medida', iconSize: null,
        html: `<span class="des-rot">${d.toFixed(2).replace('.', ',')} m</span>`,
      }),
    }).addTo(mapa)
  }).filter(Boolean)
}

function _limparRealce() {
  const mapa = mapaState.obj
  if (!mapa) { return }
  if (desmMesa.realce) { mapa.removeLayer(desmMesa.realce); desmMesa.realce = null }
  desmMesa.rotulos.forEach(r => mapa.removeLayer(r))
  desmMesa.rotulos = []
}

/**
 * Liga e desliga a imagem aérea.
 *
 * Sobre telhado escuro, o traço laranja do corte some; sobre terreno vazio, a
 * imagem é o que diz onde está o muro. Nenhuma das duas serve sempre, e por
 * isso a escolha é do operador e não do sistema.
 */
function alternarSateliteDesm() {
  desmMesa.satelite = !desmMesa.satelite
  document.getElementById('map')?.classList.toggle('sem-satelite', !desmMesa.satelite)
  const b = document.getElementById('desm-sat')
  if (b) {
    b.classList.toggle('at', desmMesa.satelite)
    b.setAttribute('aria-pressed', String(desmMesa.satelite))
  }
}

/** O painel lateral da mesa: as partes e o que cada uma vai virar. */
function pintarMesaDesmembramento() {
  const alvo = document.getElementById('desm-mesa-corpo')
  if (!alvo || !desmMesa.ativa) { return }

  const pai = state.lotes.get(desmMesa.loteId)?.properties
  const partes = desmState.partes

  const cabeca = `
    <div class="cad-nota">Dividindo <b>Quadra ${esc(pai?.quadra ?? '—')} · Lote
      ${esc(pai?.numero_lote ?? '—')}</b> de ${fmtNum(pai?.area_gis_m2 ?? 0)} m².
      O contorno externo não muda: as partes saem do corte, não de um novo desenho.</div>`

  if (!partes.length) {
    alvo.innerHTML = cabeca + `
      <div class="cad-dica">Trace uma linha atravessando o lote. Ela vira a divisa
        entre as duas partes.</div>
      <div class="seg" style="margin:8px 0 0">
        <button type="button" onclick="sairMesaDesmembramento()">Sair</button>
        <button type="button" onclick="cortarLote()">Traçar a divisa</button>
      </div>`
    return
  }

  alvo.innerHTML = cabeca + partes.map((p, i) => `
    <div class="desm-bloco">
      <div class="desm-bloco-tit"><span class="desm-num">${i + 1}</span>
        ${fmtNum(areaDoAnel(p.geometry) || 0)} m²</div>
      <div class="g2" style="margin-bottom:6px">
        <div class="field" style="margin:0">
          <label>Lote</label>
          <input type="text" class="mono" maxlength="20" value="${esc(p.numero_lote)}"
                 oninput="desmState.partes[${i}].numero_lote=this.value">
        </div>
        <div class="field" style="margin:0">
          <label>Sufixo</label>
          <input type="text" class="mono" inputmode="numeric" maxlength="3"
                 value="${p.desmembramento ?? ''}"
                 oninput="desmState.partes[${i}].desmembramento=this.value">
        </div>
      </div>
      <div class="g2" style="margin-bottom:6px">
        <div class="field" style="margin:0">
          <label>Frente (m)</label>
          <input type="number" class="mono" step="0.01" min="0" value="${p.frente_m ?? ''}"
                 oninput="desmState.partes[${i}].frente_m=this.value">
        </div>
        <div class="field" style="margin:0">
          <label>Fundos (m)</label>
          <input type="number" class="mono" step="0.01" min="0" value="${p.fundos_m ?? ''}"
                 oninput="desmState.partes[${i}].fundos_m=this.value">
        </div>
      </div>
      <div class="g2" style="margin-bottom:6px">
        <div class="field" style="margin:0">
          <label>Lado direito (m)</label>
          <input type="number" class="mono" step="0.01" min="0" value="${p.lado_direito_m ?? ''}"
                 oninput="desmState.partes[${i}].lado_direito_m=this.value">
        </div>
        <div class="field" style="margin:0">
          <label>Lado esquerdo (m)</label>
          <input type="number" class="mono" step="0.01" min="0" value="${p.lado_esquerdo_m ?? ''}"
                 oninput="desmState.partes[${i}].lado_esquerdo_m=this.value">
        </div>
      </div>
      <div class="field" style="margin:0">
        <label>Área da matrícula (m²)</label>
        <input type="number" class="mono" step="0.01" min="0" value="${p.area_matricula_m2 ?? ''}"
               oninput="desmState.partes[${i}].area_matricula_m2=this.value">
      </div>
    </div>`).join('') + `
    <div class="seg" style="margin:8px 0 0">
      <button type="button" onclick="sairMesaDesmembramento()">Sair</button>
      <button type="button" onclick="refazerCorte()">Refazer o corte</button>
      <button type="button" onclick="conferirDesmembramento()">Conferir</button>
    </div>
    <div id="desm-previa"></div>`
}

/** Larga as partes e volta a pedir a linha. */
function refazerCorte() {
  desmState.partes = []
  desmState.derivar = false
  pintarMesaDesmembramento()
  cortarLote()
}
