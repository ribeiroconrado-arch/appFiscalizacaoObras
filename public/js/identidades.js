/**
 * Procedência na ficha (aba Dados) e troca de identidades entre as partes de
 * um desmembramento. A troca é só da curadoria: o servidor confere de novo.
 *
 * Regra da tela: a 1ª posição recebe a 1ª identidade (menor sufixo), a 2ª a
 * 2ª, e assim por diante. Mexer nas setas muda QUAL parte fica com cada
 * identidade; o desenho de cada parte não muda.
 */

/** @type {{loteId:number, dados:any, ordem:number[], conferido:boolean}|null} */
let _ident = null

function _csrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

/**
 * Preenche a procedência na aba Dados. Sem origem nem destino, a faixa some.
 * @param {{origens:any[], destinos:any[]}|null} p
 * @param {number} loteId
 */
function renderProcedencia(p, loteId) {
  const el = document.getElementById('fi-procedencia')
  if (!el) { return }
  const origens = p?.origens ?? []
  const destinos = p?.destinos ?? []
  if (!origens.length && !destinos.length) {
    el.hidden = true
    el.innerHTML = ''
    return
  }

  const quando = x => [
    x.tipo === 'desmembramento' ? 'Desmembramento' : 'Unificação',
    x.protocolo ? 'protocolo ' + x.protocolo : 'direto',
    x.data || null,
  ].filter(Boolean).join(' · ')

  // Só a parte (quem está como posterior) tem as identidades para trocar.
  const linha = (x, papel, podeVerAto) => `
    <div class="fi-proc-linha">
      <span class="fi-rot">${papel}</span>
      <span class="fi-val">${esc(x.rotulo)}</span>
      <span class="fi-proc-sub">${esc(quando(x))}</span>
      ${podeVerAto && x.tipo === 'desmembramento' ? `<button type="button" class="btn sm" onclick="abrirIdentidades(${loteId})">Ver ato</button>` : ''}
    </div>`

  el.innerHTML = origens.map(x => linha(x, 'Veio de', true)).join('')
    + destinos.map(x => linha(x, 'Foi para', false)).join('')
  el.hidden = false
}

/**
 * Abre a tela do ato: as partes em ordem, com setas, motivo e prévia.
 * @param {number} loteId  qualquer parte do ato (o servidor acha o ato)
 */
async function abrirIdentidades(loteId) {
  const corpo = document.getElementById('m-identidades-corpo')
  document.getElementById('m-identidades').classList.add('open')
  corpo.innerHTML = '<div class="vazio-msg">Carregando o ato…</div>'

  try {
    const r = await fetch(`/api/lotes/${loteId}/identidades`, { headers: { Accept: 'application/json' } })
    const d = await r.json()
    if (!r.ok) {
      corpo.innerHTML = `<div class="ident-erro">${esc(d.message || 'Não foi possível abrir o ato.')}</div>`
      return
    }
    _ident = { loteId, dados: d, ordem: d.partes.map(p => p.id), conferido: false, previa: null }
    pintarIdentidades()
  } catch (e) {
    console.error(e)
    corpo.innerHTML = '<div class="ident-erro">Falha de rede ao abrir o ato.</div>'
  }
}

function fecharIdentidades() {
  fModalBtn('m-identidades')
}

/** Identidades ordenadas pelo sufixo: a posição i recebe identidades()[i]. */
function _identidades() {
  return [..._ident.dados.partes].sort((a, b) => a.desmembramento - b.desmembramento)
}

function _ordemMudou() {
  const atual = _ident.dados.partes.map(p => p.id)
  return _ident.ordem.some((id, i) => id !== atual[i])
}

function pintarIdentidades() {
  const corpo = document.getElementById('m-identidades-corpo')
  const { dados, ordem } = _ident
  const idents = _identidades()
  const mudou = _ordemMudou()
  const motivo = document.getElementById('ident-motivo')?.value ?? ''

  const cartoes = ordem.map((id, i) => {
    const parte = dados.partes.find(p => p.id === id)
    const recebe = idents[i]
    const v = parte.vinculos
    const temVinculo = v.documentos || v.vistorias || v.obras
    return `
      <div class="ident-cartao">
        <div class="ident-topo">
          <div>
            <span class="ident-pos">Posição ${i + 1}</span>
            <b>Recebe: Lote ${esc(recebe.numero_lote)} · ${esc(recebe.inscricao ?? '—')}</b>
          </div>
          <div class="ident-setas">
            <button type="button" class="btn sm" aria-label="Subir" ${i === 0 ? 'disabled' : ''} onclick="moverParte(${i}, -1)">↑</button>
            <button type="button" class="btn sm" aria-label="Descer" ${i === ordem.length - 1 ? 'disabled' : ''} onclick="moverParte(${i}, 1)">↓</button>
          </div>
        </div>
        <div class="ident-sub">Desenho hoje: Lote ${esc(parte.numero_lote)} · ${esc(parte.inscricao ?? '—')} · ${Number(parte.area).toLocaleString('pt-BR', { minimumFractionDigits: 2 })} m²</div>
        ${temVinculo ? `<div class="ident-aviso">Vínculos: ${v.documentos} documento(s), ${v.vistorias} vistoria(s), ${v.obras} obra(s) — ficam com o desenho, e a inscrição muda.</div>` : ''}
      </div>`
  }).join('')

  corpo.innerHTML = `
    <h3 class="ident-titulo">Identidades do desmembramento</h3>
    <p class="ident-explica">${dados.ato.direto ? 'Ato direto' : 'Protocolo ' + esc(dados.ato.protocolo ?? '—')}${dados.ato.data ? ' · ' + esc(dados.ato.data) : ''}. Troque quem fica com cada inscrição, sem mexer no desenho.</p>
    ${dados.bloqueio ? `<div class="ident-erro">${esc(dados.bloqueio)}</div>` : ''}
    <div class="ident-lista">${cartoes}</div>
    <label class="ident-campo">Motivo da troca (obrigatório)
      <textarea id="ident-motivo" maxlength="500" rows="3" placeholder="Ex.: placa e vistoria mostram a parte da esquerda como 07A" oninput="_ident.conferido=false;_atualizarBotoes()">${esc(motivo)}</textarea>
    </label>
    <div id="ident-previa" class="ident-previa">${_ident.previa ? _htmlPrevia(_ident.previa) : ''}</div>
    <div class="ident-acoes">
      <button type="button" class="btn" onclick="fecharIdentidades()">Fechar</button>
      <button type="button" class="btn" id="ident-btn-conferir" ${mudou && !dados.bloqueio ? '' : 'disabled'} onclick="conferirIdentidades()">Conferir</button>
      <button type="button" class="btn primary" id="ident-btn-trocar" disabled onclick="trocarIdentidades()">Trocar identidades</button>
    </div>
    <div id="ident-msg" role="status"></div>`
  _atualizarBotoes()
}

function _atualizarBotoes() {
  const motivo = (document.getElementById('ident-motivo')?.value ?? '').trim()
  const conferir = document.getElementById('ident-btn-conferir')
  const trocar = document.getElementById('ident-btn-trocar')
  if (conferir) { conferir.disabled = !_ordemMudou() || !!_ident.dados.bloqueio }
  if (trocar) { trocar.disabled = !(_ident.conferido && motivo.length >= 10) }
}

/** Sobe (-1) ou desce (+1) a parte da posição i. Invalida a prévia. */
function moverParte(i, direcao) {
  const j = i + direcao
  if (j < 0 || j >= _ident.ordem.length) { return }
  const o = _ident.ordem
  ;[o[i], o[j]] = [o[j], o[i]]
  _ident.conferido = false
  _ident.previa = null
  pintarIdentidades()
}

function _htmlPrevia(p) {
  if (p.erro) { return `<div class="ident-erro">${esc(p.erro)}</div>` }
  return `<div class="ident-trocas"><b>Como fica:</b>${p.trocas.map(t => `
      <div>${esc(t.de)} <span aria-hidden="true">→</span> <b>${esc(t.para)}</b></div>`).join('')}</div>`
}

async function _post(caminho, corpo) {
  const r = await fetch(caminho, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': _csrf() },
    body: JSON.stringify(corpo),
  })
  const d = await r.json().catch(() => ({}))
  return { ok: r.ok, status: r.status, d }
}

function _erroDoServidor(d) {
  if (d.errors) { return Object.values(d.errors).flat().join(' ') }
  return d.message || 'Não foi possível concluir.'
}

async function conferirIdentidades() {
  const motivo = document.getElementById('ident-motivo').value.trim()
  const { ok, d } = await _post(`/api/lotes/${_ident.loteId}/identidades/previa`, { ordem: _ident.ordem, motivo })
  if (!ok) {
    document.getElementById('ident-msg').innerHTML = `<div class="ident-erro">${esc(_erroDoServidor(d))}</div>`
    return
  }
  _ident.previa = d
  _ident.conferido = !d.erro
  document.getElementById('ident-previa').innerHTML = _htmlPrevia(d)
  _atualizarBotoes()
}

async function trocarIdentidades() {
  const motivo = document.getElementById('ident-motivo').value.trim()
  const msg = document.getElementById('ident-msg')
  const { ok, d } = await _post(`/api/lotes/${_ident.loteId}/identidades`, { ordem: _ident.ordem, motivo })
  if (!ok) {
    msg.innerHTML = `<div class="ident-erro">${esc(_erroDoServidor(d))}</div>`
    return
  }
  // Recarrega o ato e a ficha: a inscrição de cada lote já mudou no banco.
  const loteId = _ident.loteId
  msg.innerHTML = `<div class="ident-ok">${esc(d.message)}</div>`
  if (typeof carregarHistorico === 'function') { carregarHistorico(loteId) }
  await abrirIdentidades(loteId)
  document.getElementById('ident-msg').innerHTML = `<div class="ident-ok">${esc(d.message)}</div>`
}
