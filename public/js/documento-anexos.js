// ═══════════════════════════════════════════════════════════════
// DOCUMENTO-ANEXOS.JS — a aba Anexos do formulário de documento
//
// O documento tem os SEUS anexos: foto (câmera ou galeria) e PDF juntados na
// própria peça, mais o que o fiscal ESCOLHE trazer da vistoria vinculada ou
// da peça de origem. Nada entra sozinho.
//
// A FOTO É PREPARADA NA TELA antes de ir para o servidor: reduzida, com a
// marca d'água do brasão, o carimbo de data e hora e os rostos borrados — os
// que o aparelho reconhece sozinho e os que o fiscal toca. O que sobe já é a
// imagem final; a original não sai do aparelho.
//
// As regras de quem junta, altera e exclui são do servidor
// (DocumentoAnexoController); a tela só mostra o que ele diz que pode.
//
// Depende de documento-form.js (fdState), documentos.js (cabecalhoDoc) e
// ui.js (toast, confirmarAcao, comCarregando, esc).
// ═══════════════════════════════════════════════════════════════

const anxState = {
  /** @type {Object|null} a resposta de GET /api/documentos/{id}/anexos */ dados: null,
  /** @type {{img:CanvasImageSource, w:number, h:number, quando:Date, borroes:{x:number,y:number,r:number}[]}|null} */ foto: null,
  /** @type {HTMLCanvasElement|null} o brasão já em tons de cinza */ brasao: null,
}

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
      : `A foto ganha a data, a hora e a marca d'água antes de ser juntada. PDF até 10 MB. No máximo ${d.maximo} anexos.`}</p>
    <div class="sec-title">Anexos deste documento</div>
    <div class="anx-lista">${proprios}</div>
    ${d.vistoria ? paraTrazer('Fotos da vistoria vinculada', `Vistoria ${esc(d.vistoria)}. As fotos não entram sozinhas: escolha quais acompanham esta peça.`, d.da_vistoria, 'vistoria') : ''}
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

/** Sobe um arquivo já pronto (a foto preparada, ou o PDF). */
async function enviarAnexoDoc(arquivo, nome, titulo, quando) {
  const corpo = new FormData()
  corpo.append('arquivo', arquivo, nome)
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
    quando: daCamera ? new Date() : new Date(arq.lastModified || Date.now()),
    borroes: [],
  }
  document.getElementById('anxf-titulo').value = ''
  document.getElementById('anxf-raio').value = '6'
  await prepararBrasaoAnexo()
  openModal('m-anexo-foto')
  desenharFotoAnexo()
  // Rostos que o aparelho reconhece sozinho já entram borrados; o fiscal
  // completa com o toque, e desfaz o que não devia.
  const achados = await detectarRostosAnexo()
  const status = document.getElementById('anxf-status')
  if (achados === null) status.textContent = 'Toque sobre cada rosto para borrar.'
  else status.textContent = achados
    ? `${achados} rosto(s) borrado(s) automaticamente. Toque para borrar outros.`
    : 'Nenhum rosto reconhecido. Toque sobre um rosto para borrar.'
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
    ctx.putImageData(px, 0, 0)
    anxState.brasao = c
  } catch (_) { /* fica sem marca d'água */ }
}

/**
 * Rostos reconhecidos pelo próprio aparelho (API FaceDetector, onde existe —
 * hoje o Chrome do Android). Devolve quantos achou, ou null se o navegador
 * não tem o recurso: aí o borrão é só pelo toque.
 * @returns {Promise<number|null>}
 */
async function detectarRostosAnexo() {
  const f = anxState.foto
  if (!f || typeof window.FaceDetector !== 'function') return null
  try {
    const base = document.createElement('canvas')
    base.width = f.w; base.height = f.h
    base.getContext('2d').drawImage(f.img, 0, 0, f.w, f.h)
    const rostos = await new window.FaceDetector({ fastMode: false, maxDetectedFaces: 12 }).detect(base)
    if (anxState.foto !== f) return null   // a janela já foi fechada ou trocada
    for (const r of rostos) {
      const b = r.boundingBox
      f.borroes.push({ x: b.x + b.width / 2, y: b.y + b.height / 2, r: Math.max(b.width, b.height) * 0.65 })
    }
    if (rostos.length) desenharFotoAnexo()
    return rostos.length
  } catch (_) {
    return null
  }
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

  // CARIMBO de data e hora, no canto inferior esquerdo.
  const texto = f.quando.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) + '  ·  Fiscalização de Obras'
  const corpo = Math.max(13, Math.round(f.w * 0.022))
  ctx.font = `600 ${corpo}px Arial, sans-serif`
  const largura = ctx.measureText(texto).width, margem = Math.round(corpo * 0.6)
  ctx.fillStyle = 'rgba(0,0,0,.55)'
  ctx.fillRect(margem, f.h - corpo * 1.9 - margem, largura + corpo, corpo * 1.9)
  ctx.fillStyle = '#fff'
  ctx.textBaseline = 'middle'
  ctx.fillText(texto, margem + corpo / 2, f.h - corpo * 0.95 - margem)
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
  fModalBtn('m-anexo-foto')
}

/** Junta a foto como está na tela — já com carimbo, marca d'água e borrões. */
function juntarFotoAnexo() {
  const f = anxState.foto
  if (!f) return
  const titulo = document.getElementById('anxf-titulo').value.trim()
  document.getElementById('anxf-canvas').toBlob(async blob => {
    if (!blob) { toast('Não foi possível preparar a foto.', 'err'); return }
    const carimbo = horaLocalAnexo(f.quando).slice(0, 16).replace(/[-: ]/g, '')
    fecharFotoAnexo()
    await enviarAnexoDoc(blob, `foto-${carimbo}.jpg`, titulo || 'Foto', f.quando)
  }, 'image/jpeg', 0.86)
}
