// ═══════════════════════════════════════════════════════════════
// DOCUMENTO-ANEXOS.JS — a aba Anexos do formulário de documento
//
// O documento tem os SEUS anexos: foto (câmera ou galeria) e PDF juntados na
// própria peça, mais o que o fiscal ESCOLHE trazer da vistoria vinculada ou
// da peça de origem. Nada entra sozinho.
//
// A FOTO É PREPARADA NA TELA antes de ir para o servidor: reduzida, com a
// marca d'água do brasão, o carimbo (data, hora, latitude e longitude) e os rostos borrados — os
// que o aparelho reconhece sozinho e os que o fiscal toca. O que sobe já é a
// imagem final; a original não sai do aparelho.
//
// OS ROSTOS SÃO RECONHECIDOS NO APARELHO, pelo MediaPipe (biblioteca do Google,
// hospedada aqui mesmo em /vendor — nada vai a serviço externo). Ela pesa uns
// 10 MB e só é baixada uma vez por aparelho; por isso é adiantada em segundo
// plano depois do login, e a foto NUNCA espera por ela: abre na hora, e os
// borrões automáticos entram quando o reconhecimento termina.
//
// As regras de quem junta, altera e exclui são do servidor
// (DocumentoAnexoController); a tela só mostra o que ele diz que pode.
//
// Depende de documento-form.js (fdState), documentos.js (cabecalhoDoc) e
// ui.js (toast, confirmarAcao, comCarregando, esc).
// ═══════════════════════════════════════════════════════════════

const anxState = {
  /** @type {Object|null} a resposta de GET /api/documentos/{id}/anexos */ dados: null,
  /** @type {{img:CanvasImageSource, w:number, h:number, quando:Date, pos:{lat:number,lon:number}|null, daVistoria?:number, borroes:{x:number,y:number,r:number}[]}|null} */ foto: null,
  /** @type {HTMLCanvasElement|null} o brasão já em tons de cinza */ brasao: null,
  /** @type {Promise<Object>|null} o reconhecedor de rostos, carregado uma vez */ detector: null,
  /** @type {boolean} a procura de rostos da foto aberta ainda não terminou */ procurando: false,
}

/** Onde está a biblioteca de reconhecimento de rostos (public/vendor). */
const ANX_MEDIAPIPE = '/vendor/mediapipe-0.10.14/'

/** Lado maior da foto que sobe: nítida no papel, leve no celular. */
const ANX_LADO_MAX = 1600

// ── A LISTA ──────────────────────────────────────────────────

/** Carrega e desenha a aba. Peça ainda não gravada não tem onde guardar anexo. */
async function carregarAnexosDoc() {
  const caixa = document.getElementById('nd-anexos')
  if (!caixa) return
  if (!fdState.id) {
    anxState.dados = null
    caixa.innerHTML = '<div class="lista-vazia">Grave o documento para juntar anexos: eles ficam guardados na peça.</div>'
    return
  }
  try {
    const r = await fetch(`/api/documentos/${fdState.id}/anexos`, { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    anxState.dados = await r.json()
  } catch (e) {
    caixa.innerHTML = '<div class="lista-vazia">Não foi possível carregar os anexos.</div>'
    return
  }
  fdState.anexos = anxState.dados.anexos.length
  pintarAnexosDoc()
}

function pintarAnexosDoc() {
  const d = anxState.dados, caixa = document.getElementById('nd-anexos')
  if (!d || !caixa) return
  const cheio = d.anexos.length >= d.maximo
  const pode = d.pode_juntar && !cheio

  const miniatura = a => a.foto
    ? `<a href="${esc(a.url)}" target="_blank" rel="noopener"><img class="anx-mini" src="${esc(a.url)}" alt="" loading="lazy"></a>`
    : `<a class="anx-mini anx-pdf" href="${esc(a.url)}" target="_blank" rel="noopener">PDF</a>`

  const proprios = d.anexos.length ? d.anexos.map((a, i) => `
    <div class="anx${a.pode_excluir ? '' : ' anx-fixo'}">
      ${miniatura(a)}
      <div class="anx-corpo">
        <input type="text" class="anx-tit" id="anx-tit-${a.id}" value="${esc(a.titulo || '')}" maxlength="160" aria-label="Título do anexo"
               ${d.pode_alterar ? '' : 'readonly'} onchange="alterarAnexoDoc(${a.id}, { titulo: this.value })">
        <div class="anx-meta">${esc(a.quando || '')}${a.quem ? ' · juntado por ' + esc(a.quem) : ''}</div>
        <div class="anx-etqs">
          ${a.origem === 'vistoria' ? '<span class="anx-etq vist">da vistoria</span>' : ''}
          ${a.origem === 'documento' ? '<span class="anx-etq orig">da peça de origem</span>' : ''}
          ${a.juntado_depois ? '<span class="anx-etq depois">juntado depois da lavratura</span>' : ''}
        </div>
        <label class="anx-imprime"><input type="checkbox" id="anx-imp-${a.id}" ${a.imprime ? 'checked' : ''} ${d.pode_alterar ? '' : 'disabled'}
          onchange="alterarAnexoDoc(${a.id}, { imprime: this.checked })"> sai na impressão</label>
      </div>
      <div class="anx-lado">
        ${d.pode_alterar ? `<div class="anx-setas">
          <button type="button" class="anx-btn" title="Subir" ${i === 0 ? 'disabled' : ''} onclick="moverAnexoDoc(${i}, -1)">&uarr;</button>
          <button type="button" class="anx-btn" title="Descer" ${i === d.anexos.length - 1 ? 'disabled' : ''} onclick="moverAnexoDoc(${i}, 1)">&darr;</button>
        </div>` : ''}
        ${a.pode_excluir ? `<button type="button" class="btn out-vermelho sm" onclick="excluirAnexoDoc(${a.id})">Excluir</button>` : ''}
      </div>
    </div>`).join('')
    : '<div class="lista-vazia">Nenhum anexo neste documento.</div>'

  /** Lista de onde se TRAZ: fotos da vistoria ou anexos da peça de origem. */
  const paraTrazer = (titulo, nota, itens, de) => itens.length ? `
    <div class="sec-title">${titulo}</div>
    <p class="anx-nota">${nota}</p>
    <div class="anx-lista">${itens.map(v => `
      <div class="anx">
        ${v.foto === false ? `<a class="anx-mini anx-pdf" href="${esc(v.url)}" target="_blank" rel="noopener">PDF</a>`
          : `<a href="${esc(v.url)}" target="_blank" rel="noopener"><img class="anx-mini" src="${esc(v.url)}" alt="" loading="lazy"></a>`}
        <div class="anx-corpo"><div class="anx-tit-fixo">${esc(v.titulo || '—')}</div><div class="anx-meta">${esc(v.quando || '')}</div></div>
        <div class="anx-lado"><button type="button" class="btn out-verde sm" ${v.usada || !pode ? 'disabled' : ''}
          onclick="trazerAnexoDoc('${de}', ${v.id})">${v.usada ? 'Já está no documento' : 'Usar neste documento'}</button></div>
      </div>`).join('')}</div>` : ''

  caixa.innerHTML = `
    <div class="anx-acoes">
      <button type="button" class="btn out-cinza" ${pode ? '' : 'disabled'} onclick="document.getElementById('anx-camera').click()">Câmera</button>
      <button type="button" class="btn out-cinza" ${pode ? '' : 'disabled'} onclick="document.getElementById('anx-galeria').click()">Galeria</button>
      <button type="button" class="btn out-cinza" ${pode ? '' : 'disabled'} onclick="document.getElementById('anx-pdf').click()">Arquivo PDF</button>
    </div>
    <p class="anx-nota">${!d.pode_juntar ? 'Este documento não recebe mais anexos de você.'
      : cheio ? `O documento já tem ${d.maximo} anexos, que é o limite.`
      : d.lavrado ? 'Documento lavrado: o que for juntado agora sai marcado como “juntado depois”, com a data e o seu nome.'
      : `Toda foto ganha o carimbo (data, hora e posição) e a marca d'água antes de ser juntada. PDF até 10 MB. No máximo ${d.maximo} anexos.`}</p>
    <div class="sec-title">Anexos deste documento</div>
    <div class="anx-lista">${proprios}</div>
    ${d.vistoria ? paraTrazer('Fotos da vistoria vinculada', `Vistoria ${esc(d.vistoria)}. As fotos não entram sozinhas: escolha quais acompanham esta peça. Cada uma passa pelo preparo e ganha o carimbo.`, d.da_vistoria, 'vistoria') : ''}
    ${d.origem ? paraTrazer('Anexos da peça de origem', `${esc(d.origem)}. Traga os que este documento deve herdar.`, d.da_origem, 'documento') : ''}`
}

// ── AÇÕES SOBRE A LISTA ──────────────────────────────────────

/** Pedido JSON à API dos anexos; devolve o corpo ou lança o erro com a mensagem do servidor. */
async function pedirAnexoDoc(url, metodo, corpo) {
  const r = await fetch(url, {
    method: metodo,
    headers: { ...cabecalhoDoc(), ...(corpo instanceof FormData ? {} : { 'Content-Type': 'application/json' }) },
    body: corpo instanceof FormData ? corpo : (corpo ? JSON.stringify(corpo) : undefined),
  })
  const d = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(d.errors ? Object.values(d.errors)[0][0] : (d.message || 'HTTP ' + r.status))
  return d
}

/** @param {number} id @param {{titulo?:string, imprime?:boolean}} dados */
async function alterarAnexoDoc(id, dados) {
  try {
    await pedirAnexoDoc('/api/documentos/anexos/' + id, 'PATCH', dados)
    Object.assign(anxState.dados.anexos.find(a => a.id === id) || {}, dados)
  } catch (e) {
    toast(e.message, 'err')
    carregarAnexosDoc()   // volta ao que está gravado
  }
}

/** @param {number} i posição atual @param {number} d -1 sobe, +1 desce */
async function moverAnexoDoc(i, d) {
  const lista = anxState.dados.anexos
  if (i < 0 || i >= lista.length || i + d < 0 || i + d >= lista.length) return
  const [a] = lista.splice(i, 1)
  lista.splice(i + d, 0, a)
  pintarAnexosDoc()
  try {
    await pedirAnexoDoc(`/api/documentos/${fdState.id}/anexos/ordem`, 'POST', { ids: lista.map(x => x.id) })
  } catch (e) {
    toast(e.message, 'err')
    carregarAnexosDoc()
  }
}

/** @param {'vistoria'|'documento'} de @param {number} id */
async function trazerAnexoDoc(de, id) {
  // FOTO DA VISTORIA: não vem crua. Passa pela mesma janela de preparo das
  // outras — carimbo, marca d'água e borrão — e sobe como imagem nova.
  if (de === 'vistoria') { await prepararFotoDaVistoriaAnexo(id); return }
  await comCarregando('Trazendo o anexo…', async () => {
    try {
      const d = await pedirAnexoDoc(`/api/documentos/${fdState.id}/anexos/trazer`, 'POST', { de, id })
      toast(d.message)
      await carregarAnexosDoc()
    } catch (e) { toast(e.message, 'err') }
  })
}

/** @param {number} id */
function excluirAnexoDoc(id) {
  const a = anxState.dados.anexos.find(x => x.id === id)
  confirmarAcao({
    titulo: 'Excluir anexo',
    mensagem: `"${a?.titulo || 'Anexo'}" sai do documento.`
      + (anxState.dados.lavrado ? ' O documento já está lavrado: a exclusão fica registrada na trilha de auditoria.' : ''),
    textoBtn: 'Excluir',
    perigo: true,
    onConfirm: async () => {
      const d = await pedirAnexoDoc('/api/documentos/anexos/' + id, 'DELETE')
      toast(d.message)
      await carregarAnexosDoc()
    },
  })
}

/**
 * Abre a janela de preparo com uma foto da vistoria vinculada. A data do
 * carimbo é a da foto na vistoria; a posição, a gravada no arquivo ou, na
 * falta, a da vistoria.
 * @param {number} id a evidência
 */
async function prepararFotoDaVistoriaAnexo(id) {
  const v = anxState.dados?.da_vistoria.find(x => x.id === id)
  if (!v) return
  let arq
  try {
    arq = await comCarregando('Abrindo a foto…', async () => {
      const r = await fetch(v.url)
      if (!r.ok) throw new Error('HTTP ' + r.status)
      return r.blob()
    })
  } catch (_) {
    toast('Não foi possível abrir essa foto.', 'err')
    return
  }
  await abrirPreparoFotoAnexo(arq, {
    quando: v.data ? new Date(v.data.replace(' ', 'T')) : new Date(),
    titulo: v.titulo || '',
    daVistoria: id,
    posReserva: v.lat != null && v.lon != null ? { lat: Number(v.lat), lon: Number(v.lon) } : null,
  })
}

/** Sobe um arquivo já pronto (a foto preparada, ou o PDF). */
async function enviarAnexoDoc(arquivo, nome, titulo, quando, daVistoria = null) {
  const corpo = new FormData()
  corpo.append('arquivo', arquivo, nome)
  if (daVistoria) corpo.append('da_vistoria', daVistoria)
  if (titulo) corpo.append('titulo', titulo)
  // Hora LOCAL, sem fuso: o servidor guarda a hora como o fiscal a vê. O
  // toISOString mandaria em UTC, e a foto das 15h10 apareceria como das 19h10.
  if (quando) corpo.append('data_hora', horaLocalAnexo(quando))
  await comCarregando('Juntando o anexo…', async () => {
    try {
      const d = await pedirAnexoDoc(`/api/documentos/${fdState.id}/anexos`, 'POST', corpo)
      toast(d.message)
      await carregarAnexosDoc()
    } catch (e) { toast(e.message, 'err') }
  })
}

/** 'AAAA-MM-DD HH:MM:SS' na hora do aparelho. @param {Date} d */
function horaLocalAnexo(d) {
  const p = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

/** @param {HTMLInputElement} inp */
function escolherPdfAnexo(inp) {
  const arq = inp.files?.[0]
  inp.value = ''
  if (!arq) return
  if (arq.size > 10 * 1024 * 1024) { toast('O PDF passa de 10 MB.', 'err'); return }
  enviarAnexoDoc(arq, arq.name, arq.name.replace(/\.pdf$/i, ''), null)
}

// ── PREPARAR A FOTO ──────────────────────────────────────────

/**
 * Foto escolhida (câmera ou galeria): abre a janela de preparo.
 *
 * A data do carimbo é a de AGORA quando a foto vem da câmera, e a de
 * modificação do arquivo quando vem da galeria — é o melhor que o navegador
 * informa sobre quando a foto foi feita.
 *
 * @param {HTMLInputElement} inp @param {boolean} daCamera
 */
async function escolherFotoAnexo(inp, daCamera) {
  const arq = inp.files?.[0]
  inp.value = ''
  if (!arq) return
  await abrirPreparoFotoAnexo(arq, {
    quando: daCamera ? new Date() : new Date(arq.lastModified || Date.now()),
    daCamera,
  })
}

/**
 * Abre a janela de preparo com uma imagem — a escolhida no aparelho ou a
 * trazida da vistoria. É por aqui que TODA foto passa antes de entrar na peça.
 *
 * @param {Blob} arq
 * @param {{quando:Date, daCamera?:boolean, titulo?:string, daVistoria?:number, posReserva?:{lat:number,lon:number}|null}} o
 */
async function abrirPreparoFotoAnexo(arq, { quando, daCamera = false, titulo = '', daVistoria = null, posReserva = null }) {
  let img
  try {
    img = await carregarImagemAnexo(arq)
  } catch (_) {
    toast('Não foi possível abrir essa imagem.', 'err')
    return
  }
  const lw = img.naturalWidth || img.width, lh = img.naturalHeight || img.height
  const escala = Math.min(1, ANX_LADO_MAX / Math.max(lw, lh))
  anxState.foto = {
    img, w: Math.round(lw * escala), h: Math.round(lh * escala),
    quando,
    borroes: [],
    pos: posReserva,
    daVistoria,
  }
  buscarPosicaoFotoAnexo(anxState.foto, arq, daCamera)
  document.getElementById('anxf-titulo').value = titulo
  document.getElementById('anxf-raio').value = '6'
  await prepararBrasaoAnexo()
  openModal('m-anexo-foto')
  desenharFotoAnexo()
  // Rostos que o aparelho reconhece sozinho já entram borrados; o fiscal
  // completa com o toque, e desfaz o que não devia. A foto não espera: com
  // internet ruim o reconhecedor pode demorar a chegar na primeira vez.
  const f = anxState.foto
  const status = document.getElementById('anxf-status')
  status.textContent = 'Procurando rostos na foto… Você já pode borrar pelo toque.'
  anxState.procurando = true
  const achados = await detectarRostosAnexo()
  if (anxState.foto !== f) return   // a janela já foi fechada ou trocada
  anxState.procurando = false
  if (achados === null) status.textContent = 'A procura automática de rostos não funcionou neste aparelho. Toque sobre cada rosto para borrar.'
  else status.textContent = achados
    ? `${achados} rosto(s) borrado(s) automaticamente. Confira, e toque para borrar outros.`
    : 'Nenhum rosto reconhecido. Se houver algum, toque sobre ele para borrar.'
}

/** @param {File} arq @returns {Promise<HTMLImageElement>} */
function carregarImagemAnexo(arq) {
  return new Promise((ok, falha) => {
    const url = URL.createObjectURL(arq)
    const img = new Image()
    img.onload = () => { URL.revokeObjectURL(url); ok(img) }
    img.onerror = () => { URL.revokeObjectURL(url); falha(new Error('imagem')) }
    img.src = url
  })
}

/**
 * O brasão do município em TONS DE CINZA, para a marca d'água. Feito uma vez:
 * lê os pixels do brasão (mesmo servidor) e troca a cor pela luminância.
 */
async function prepararBrasaoAnexo() {
  if (anxState.brasao !== null) return
  anxState.brasao = false   // sem brasão cadastrado, a foto sai sem marca
  const origem = document.querySelector('.subcab-brasao')?.getAttribute('src')
  if (!origem) return
  try {
    const img = await new Promise((ok, falha) => { const i = new Image(); i.onload = () => ok(i); i.onerror = falha; i.src = origem })
    const c = document.createElement('canvas')
    c.width = img.naturalWidth; c.height = img.naturalHeight
    const ctx = c.getContext('2d')
    ctx.drawImage(img, 0, 0)
    const px = ctx.getImageData(0, 0, c.width, c.height)
    for (let i = 0; i < px.data.length; i += 4) {
      const cinza = 0.299 * px.data[i] + 0.587 * px.data[i + 1] + 0.114 * px.data[i + 2]
      px.data[i] = px.data[i + 1] = px.data[i + 2] = cinza
    }
    tirarFundoBrancoAnexo(px)
    ctx.putImageData(px, 0, 0)
    anxState.brasao = c
  } catch (_) { /* fica sem marca d'água */ }
}

/**
 * Tira o FUNDO BRANCO do brasão: torna transparente o branco que está LIGADO
 * À BORDA da imagem, alastrando de fora para dentro. O branco de dentro do
 * escudo (faixas, letras) não é fundo e fica — por isso não basta apagar todo
 * pixel claro. Espera a imagem já em tons de cinza.
 * @param {ImageData} px
 */
function tirarFundoBrancoAnexo(px) {
  const { width: w, height: h, data } = px
  const LIMIAR = 236   // de cinza para cima, é fundo
  const fundo = i => data[i * 4 + 3] < 16 || data[i * 4] >= LIMIAR
  const visto = new Uint8Array(w * h), fila = []
  const entra = i => { if (!visto[i] && fundo(i)) { visto[i] = 1; fila.push(i) } }
  for (let x = 0; x < w; x++) { entra(x); entra((h - 1) * w + x) }
  for (let y = 0; y < h; y++) { entra(y * w); entra(y * w + w - 1) }
  while (fila.length) {
    const i = fila.pop(), x = i % w
    data[i * 4 + 3] = 0
    if (x > 0) entra(i - 1)
    if (x < w - 1) entra(i + 1)
    if (i >= w) entra(i - w)
    if (i < w * (h - 1)) entra(i + w)
  }
  // A borda do desenho guarda um resto de branco misturado: fica meio
  // transparente, para o recorte não deixar um fio claro em volta.
  for (let i = 0; i < w * h; i++) {
    if (visto[i] || data[i * 4] < 200) continue
    const x = i % w
    const vizinhoFundo = (x > 0 && visto[i - 1]) || (x < w - 1 && visto[i + 1]) || (i >= w && visto[i - w]) || (i < w * (h - 1) && visto[i + w])
    if (vizinhoFundo) data[i * 4 + 3] = Math.round(data[i * 4 + 3] * (LIMIAR - data[i * 4]) / (LIMIAR - 200) * 0.8 + data[i * 4 + 3] * 0.2)
  }
}

/**
 * Carrega o reconhecedor de rostos (MediaPipe), uma vez por sessão. A promessa
 * é guardada: quem chama de novo espera a mesma carga. Se falhar (sem sinal),
 * a próxima chamada tenta de novo.
 * @returns {Promise<Object>} o detector, com `detect(canvas)`
 */
function carregarDetectorRostos() {
  if (!anxState.detector) {
    anxState.detector = (async () => {
      const mp = await import(ANX_MEDIAPIPE + 'vision_bundle.js')
      const arquivos = await mp.FilesetResolver.forVisionTasks(ANX_MEDIAPIPE + 'wasm')
      return mp.FaceDetector.createFromOptions(arquivos, {
        baseOptions: { modelAssetPath: ANX_MEDIAPIPE + 'blaze_face_short_range.tflite' },
        runningMode: 'IMAGE',
        minDetectionConfidence: 0.5,
      })
    })()
    anxState.detector.catch(() => { anxState.detector = null })
  }
  return anxState.detector
}

/**
 * Adianta o download do reconhecedor para o navegador guardar: quem entra no
 * sistema com sinal bom (o Wi-Fi da prefeitura) sai a campo com ele pronto.
 * Só baixa — não liga o reconhecedor, que ocupa memória. Não roda com a
 * economia de dados do aparelho ligada.
 */
function adiantarDetectorRostos() {
  if (navigator.connection?.saveData || !window.WebAssembly) return
  for (const arq of ['vision_bundle.js', 'wasm/vision_wasm_internal.js', 'wasm/vision_wasm_internal.wasm', 'blaze_face_short_range.tflite']) {
    fetch(ANX_MEDIAPIPE + arq).then(r => r.arrayBuffer()).catch(() => { /* fica para a hora da foto */ })
  }
}
// Bem depois de a tela abrir, para não disputar a rede com o mapa.
window.addEventListener('load', () => setTimeout(adiantarDetectorRostos, 20000))

/**
 * Os pedaços da foto em que se procura rosto: a foto inteira e, por cima, uma
 * grade de recortes que se sobrepõem. O modelo foi treinado para rosto perto
 * da câmera; numa foto de obra as pessoas estão longe e saem pequenas — no
 * recorte, o mesmo rosto ocupa mais da imagem e passa a ser reconhecido.
 * @param {number} w @param {number} h @returns {{x:number,y:number,w:number,h:number}[]}
 */
function recortesParaRostos(w, h) {
  const lista = [{ x: 0, y: 0, w, h }]
  for (const n of [2, 3]) {
    // Cada recorte tem 1/n da foto mais 30% de sobra para os lados, para o
    // rosto que cai na emenda aparecer inteiro em algum deles.
    const lw = w / n * 1.3, lh = h / n * 1.3
    for (let i = 0; i < n; i++) for (let j = 0; j < n; j++) {
      lista.push({
        x: Math.round(Math.min(w - lw, Math.max(0, i * w / n - (lw - w / n) / 2))),
        y: Math.round(Math.min(h - lh, Math.max(0, j * h / n - (lh - h / n) / 2))),
        w: Math.round(lw), h: Math.round(lh),
      })
    }
  }
  return lista
}

/**
 * Procura rostos na foto aberta e borra os que achar. Usa o MediaPipe; se ele
 * não carregar, tenta o reconhecimento do próprio navegador (FaceDetector,
 * onde existe). Devolve quantos rostos borrou, ou null se nenhum dos dois
 * funcionou — aí o borrão é só pelo toque.
 * @returns {Promise<number|null>}
 */
async function detectarRostosAnexo() {
  const f = anxState.foto
  if (!f) return null
  const base = document.createElement('canvas')
  base.width = f.w; base.height = f.h
  base.getContext('2d').drawImage(f.img, 0, 0, f.w, f.h)

  /** @type {{x:number,y:number,w:number,h:number}[]} caixas dos rostos, em pixels da foto */
  let caixas = null
  try {
    const detector = await carregarDetectorRostos()
    if (anxState.foto !== f) return null   // a janela já foi fechada ou trocada
    caixas = []
    const pedaco = document.createElement('canvas')
    for (const r of recortesParaRostos(f.w, f.h)) {
      pedaco.width = r.w; pedaco.height = r.h
      pedaco.getContext('2d').drawImage(base, r.x, r.y, r.w, r.h, 0, 0, r.w, r.h)
      for (const d of detector.detect(pedaco).detections || []) {
        const b = d.boundingBox
        if (b && b.width >= 10 && b.height >= 10) caixas.push({ x: r.x + b.originX, y: r.y + b.originY, w: b.width, h: b.height })
      }
    }
  } catch (e) {
    console.warn('Reconhecimento de rostos (MediaPipe) indisponível:', e)
    caixas = null
  }

  if (caixas === null && typeof window.FaceDetector === 'function') {
    try {
      const rostos = await new window.FaceDetector({ fastMode: false, maxDetectedFaces: 12 }).detect(base)
      caixas = rostos.map(r => ({ x: r.boundingBox.x, y: r.boundingBox.y, w: r.boundingBox.width, h: r.boundingBox.height }))
    } catch (_) { /* fica só o toque */ }
  }
  if (caixas === null || anxState.foto !== f) return null

  // O mesmo rosto aparece em mais de um recorte: entra uma vez só. Os maiores
  // primeiro, para o borrão que fica cobrir o rosto todo.
  let novos = 0
  for (const c of caixas.sort((a, b) => b.w * b.h - a.w * a.h)) {
    const x = c.x + c.w / 2, y = c.y + c.h / 2, r = Math.max(c.w, c.h) * 0.65
    if (f.borroes.some(b => Math.hypot(b.x - x, b.y - y) < b.r)) continue
    f.borroes.push({ x, y, r })
    novos++
  }
  if (novos) desenharFotoAnexo()
  return novos
}

/** Redesenha a foto: imagem, borrões, marca d'água e carimbo de data. */
function desenharFotoAnexo() {
  const f = anxState.foto, canvas = document.getElementById('anxf-canvas')
  if (!f || !canvas) return
  canvas.width = f.w; canvas.height = f.h
  const ctx = canvas.getContext('2d')
  ctx.drawImage(f.img, 0, 0, f.w, f.h)

  // BORRÃO em mosaico: a região é reduzida a poucos blocos e ampliada de volta
  // sem suavizar. Funciona em qualquer navegador (o desfoque do canvas não
  // existe no Safari antigo) e não se desfaz ampliando a foto.
  const blocos = document.createElement('canvas')
  blocos.width = blocos.height = 9
  const bctx = blocos.getContext('2d')
  for (const b of f.borroes) {
    const x = Math.max(0, b.x - b.r), y = Math.max(0, b.y - b.r)
    const lado = Math.min(b.r * 2, f.w - x, f.h - y)
    if (lado <= 2) continue
    bctx.clearRect(0, 0, 9, 9)
    bctx.drawImage(canvas, x, y, lado, lado, 0, 0, 9, 9)
    ctx.save()
    ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2); ctx.clip()
    ctx.imageSmoothingEnabled = false
    ctx.drawImage(blocos, 0, 0, 9, 9, x, y, lado, lado)
    ctx.restore()
  }

  // MARCA D'ÁGUA: o brasão em cinza, no centro, quase transparente.
  if (anxState.brasao) {
    const lado = Math.min(f.w, f.h) * 0.5
    const prop = anxState.brasao.width / anxState.brasao.height
    const lw = prop >= 1 ? lado : lado * prop, lh = prop >= 1 ? lado / prop : lado
    ctx.save()
    ctx.globalAlpha = 0.16
    ctx.drawImage(anxState.brasao, (f.w - lw) / 2, (f.h - lh) / 2, lw, lh)
    ctx.restore()
  }

  carimbarFotoAnexo(ctx, f)
}

/** Quanto do quadro do carimbo é branco: o resto deixa a foto aparecer. */
const ANX_CARIMBO_FUNDO = 0.45

/**
 * CARIMBO da foto: uma etiqueta no canto inferior direito, com o brasão em
 * cinza à esquerda e, à direita, a data e a hora em destaque, a latitude e a
 * longitude (quando há), a quadra e o lote do documento e o nome do órgão.
 *
 * O quadro é meio transparente; por isso cada letra leva um contorno claro
 * fino, que segura a leitura sobre foto escura.
 *
 * @param {CanvasRenderingContext2D} ctx
 * @param {{w:number, h:number, quando:Date, pos:{lat:number,lon:number}|null}} f
 */
function carimbarFotoAnexo(ctx, f) {
  const base = Math.min(f.w, f.h)
  const corpoG = Math.max(15, Math.round(base * 0.04)), corpoP = Math.max(11, Math.round(base * 0.024))
  const passo = corpoP * 1.38, p = Math.round(base * 0.022), margem = Math.round(base * 0.025)

  const titulo = f.quando.toLocaleDateString('pt-BR') + '  '
    + f.quando.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
  const linhas = []
  if (f.pos) linhas.push('Lat  ' + f.pos.lat.toFixed(6), 'Lon  ' + f.pos.lon.toFixed(6))
  const quadra = document.getElementById('nd-im-quadra')?.value.trim()
  const lote = document.getElementById('nd-im-lote')?.value.trim()
  if (quadra || lote) linhas.push([quadra && 'Qd. ' + quadra, lote && 'Lt. ' + lote].filter(Boolean).join(' · '))
  linhas.push('Fiscalização de Obras')

  // O quadro tem a largura do texto mais comprido, sem passar da foto.
  ctx.font = `700 ${corpoG}px Arial, sans-serif`
  let larguraTexto = ctx.measureText(titulo).width
  ctx.font = `600 ${corpoP}px Arial, sans-serif`
  for (const l of linhas) larguraTexto = Math.max(larguraTexto, ctx.measureText(l).width)

  const alt = corpoG * 1.5 + linhas.length * passo + p * 1.4
  const brasao = anxState.brasao || null
  const bh = brasao ? Math.min(alt - p * 1.6, base * 0.15) : 0
  const bw = brasao ? bh * brasao.width / brasao.height : 0
  const larg = Math.min(f.w - margem * 2, p * (brasao ? 3 : 2) + bw + larguraTexto)
  const x = f.w - larg - margem, y = f.h - alt - margem, raio = Math.round(base * 0.015)

  const quadro = () => {
    ctx.beginPath()
    ctx.moveTo(x + raio, y)
    ctx.arcTo(x + larg, y, x + larg, y + alt, raio); ctx.arcTo(x + larg, y + alt, x, y + alt, raio)
    ctx.arcTo(x, y + alt, x, y, raio); ctx.arcTo(x, y, x + larg, y, raio)
    ctx.closePath()
  }
  ctx.save()
  ctx.fillStyle = `rgba(255,255,255,${ANX_CARIMBO_FUNDO})`; quadro(); ctx.fill()
  ctx.strokeStyle = 'rgba(30,40,36,.2)'; ctx.lineWidth = Math.max(1, base * 0.002); quadro(); ctx.stroke()
  if (brasao) ctx.drawImage(brasao, x + p, y + (alt - bh) / 2, bw, bh)

  const tx = x + p * (brasao ? 2 : 1) + bw
  ctx.textBaseline = 'alphabetic'; ctx.lineJoin = 'round'
  const escreve = (txt, ty, corpo, peso, cor) => {
    ctx.font = `${peso} ${corpo}px Arial, sans-serif`
    ctx.strokeStyle = 'rgba(255,255,255,.62)'; ctx.lineWidth = Math.max(2, corpo * 0.14); ctx.strokeText(txt, tx, ty)
    ctx.fillStyle = cor; ctx.fillText(txt, tx, ty)
  }
  let ty = y + p * 0.7 + corpoG
  escreve(titulo, ty, corpoG, '700', '#14201D')
  ty += corpoG * 0.5
  for (const l of linhas) { ty += passo; escreve(l, ty, corpoP, '600', '#24322F') }
  ctx.restore()
}

// ── ONDE A FOTO FOI FEITA ────────────────────────────────────

/**
 * Procura a posição da foto e redesenha o carimbo quando ela chega.
 *
 * Da CÂMERA, é o GPS do aparelho agora (a melhor leitura, como na vistoria).
 * Da GALERIA, é a posição que a câmera gravou dentro do arquivo (EXIF) — a de
 * agora seria a de onde o fiscal está, não a de onde a foto foi feita.
 * Sem nenhuma das duas, o carimbo sai sem as linhas de latitude e longitude.
 *
 * @param {Object} f a foto em preparo (anxState.foto) @param {Blob} arq @param {boolean} daCamera
 */
async function buscarPosicaoFotoAnexo(f, arq, daCamera) {
  const chegou = pos => {
    if (anxState.foto !== f) return   // a janela já foi fechada ou trocada
    f.pos = { lat: pos.lat, lon: pos.lon }
    desenharFotoAnexo()
  }
  try {
    if (!daCamera) {
      const pos = await posicaoExifAnexo(arq)
      if (pos) chegou(pos)
      return
    }
    if (!navigator.geolocation) return
    await melhorPosicaoGps({ aCadaMelhora: chegou, esperaMs: 12000 })
  } catch (_) { /* sem posição: o carimbo sai sem ela */ }
}

/**
 * Lê a latitude e a longitude gravadas num JPEG (bloco EXIF, diretório GPS).
 * @param {File} arq @returns {Promise<{lat:number, lon:number}|null>}
 */
async function posicaoExifAnexo(arq) {
  // O EXIF fica no começo do arquivo; 256 KB cobrem com folga.
  const v = new DataView(await arq.slice(0, 262144).arrayBuffer())
  if (v.byteLength < 4 || v.getUint16(0) !== 0xFFD8) return null
  let o = 2
  while (o + 4 <= v.byteLength) {
    const marca = v.getUint16(o), tam = v.getUint16(o + 2)
    if (marca === 0xFFE1 && o + 10 <= v.byteLength && v.getUint32(o + 4) === 0x45786966) {   // "Exif"
      const t = o + 10                                   // início do TIFF
      const le = v.getUint16(t) === 0x4949               // "II" = little-endian
      const u16 = p => v.getUint16(p, le), u32 = p => v.getUint32(p, le)
      /** Posição do valor de uma etiqueta num diretório, ou 0. */
      const achar = (dir, etiqueta) => {
        for (let i = 0, n = u16(dir); i < n; i++) if (u16(dir + 2 + i * 12) === etiqueta) return dir + 2 + i * 12 + 8
        return 0
      }
      const gps = achar(t + u32(t + 4), 0x8825)
      if (!gps) return null
      const dir = t + u32(gps)
      /** Graus, minutos e segundos (três frações) em graus decimais. */
      const graus = etiqueta => {
        const onde = achar(dir, etiqueta)
        if (!onde) return null
        const p = t + u32(onde)
        const fr = i => u32(p + i * 8) / (u32(p + i * 8 + 4) || 1)
        return fr(0) + fr(1) / 60 + fr(2) / 3600
      }
      const sinal = etiqueta => { const onde = achar(dir, etiqueta); return onde ? String.fromCharCode(v.getUint8(onde)) : '' }
      const lat = graus(2), lon = graus(4)
      if (lat === null || lon === null || (!lat && !lon)) return null
      return { lat: sinal(1) === 'S' ? -lat : lat, lon: sinal(3) === 'W' ? -lon : lon }
    }
    if ((marca & 0xFF00) !== 0xFF00 || marca === 0xFFDA) return null
    o += 2 + tam
  }
  return null
}

/** Toque na foto: borra ali. O raio vem do controle de tamanho. @param {MouseEvent} ev */
function borrarNaFotoAnexo(ev) {
  const f = anxState.foto, canvas = document.getElementById('anxf-canvas')
  if (!f) return
  const r = canvas.getBoundingClientRect()
  const tamanho = Number(document.getElementById('anxf-raio').value) || 6
  f.borroes.push({
    x: (ev.clientX - r.left) * (f.w / r.width),
    y: (ev.clientY - r.top) * (f.h / r.height),
    r: Math.min(f.w, f.h) * tamanho / 100,
  })
  desenharFotoAnexo()
}

function desfazerBorraoAnexo() {
  if (!anxState.foto?.borroes.length) { toast('Não há borrão para desfazer.', 'aviso'); return }
  anxState.foto.borroes.pop()
  desenharFotoAnexo()
}

function fecharFotoAnexo() {
  anxState.foto = null
  anxState.procurando = false
  fModalBtn('m-anexo-foto')
}

/** Junta a foto como está na tela — já com carimbo, marca d'água e borrões. */
function juntarFotoAnexo(mesmoProcurando = false) {
  const f = anxState.foto
  if (!f) return
  // A procura de rostos ainda não terminou: a foto subiria sem os borrões
  // automáticos. O fiscal decide — pode já ter borrado pelo toque.
  if (anxState.procurando && !mesmoProcurando) {
    confirmarAcao({
      titulo: 'A procura de rostos não terminou',
      mensagem: 'Os rostos que você não borrou pelo toque vão aparecer na foto. Juntar assim mesmo?',
      textoBtn: 'Juntar assim mesmo',
      onConfirm: async () => juntarFotoAnexo(true),
    })
    return
  }
  const titulo = document.getElementById('anxf-titulo').value.trim()
  document.getElementById('anxf-canvas').toBlob(async blob => {
    if (!blob) { toast('Não foi possível preparar a foto.', 'err'); return }
    const carimbo = horaLocalAnexo(f.quando).slice(0, 16).replace(/[-: ]/g, '')
    fecharFotoAnexo()
    await enviarAnexoDoc(blob, `foto-${carimbo}.jpg`, titulo || 'Foto', f.quando, f.daVistoria)
  }, 'image/jpeg', 0.86)
}
