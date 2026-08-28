// ══════════════════════════════════════════════
// MÓDULO: ORDEM DE SERVIÇO
//
// O sistema registrava o que o fiscal FEZ — vistoria, documento, protocolo.
// Não havia onde dizer o que ele DEVE fazer, e a distribuição do trabalho
// vivia no papel e no grupo de mensagens. O que fica de fora do sistema não
// entra em relatório, não cobra prazo e não responde "quem estava incumbido
// disto?".
//
// Protocolo é o que CHEGA de fora; ordem de serviço é o que a coordenação
// determina para dentro. Dividem a mesma tela porque respondem à mesma
// pergunta — "o que há para fazer?" —, e se separam em abas porque quem
// responde por cada uma é outro.
// ══════════════════════════════════════════════

/** Estado da tela e do formulário. */
const osState = {
  /** @type {Object} filtros da lista */ filtros: {},
  /** @type {Array<{id:number,name:string}>} */ fiscais: [],
  /** @type {Set<number>} designados no formulário */ designados: new Set(),
  /** @type {Array<{data:string, ini:string|null, fim:string|null}>} */ jornadas: [],
  natureza: 'especifica',
  regime: 'periodo',
  prioridade: 'normal',
  /** @type {Object|null} a ordem aberta na ficha */ aberta: null,
  emitindo: false,
}

// ── AS DUAS ABAS DA TELA ─────────────────────────────────────

/** @param {'protocolos'|'os'} qual */
function abaProtocoloOs(qual) {
  document.querySelectorAll('#po-abas button').forEach(b =>
    b.classList.toggle('at', b.dataset.po === qual))
  document.querySelectorAll('.po-painel').forEach(p =>
    p.classList.toggle('at', p.id === 'po-' + qual))

  if (qual === 'os') { carregarOs() }
}

// ── LISTA ────────────────────────────────────────────────────

/** @param {string} chave @param {string} valor */
function filtrarOs(chave, valor) {
  if (valor) { osState.filtros[chave] = valor } else { delete osState.filtros[chave] }
  carregarOs()
}

async function carregarOs() {
  const alvo = document.getElementById('lista-os')
  if (!alvo) { return }

  try {
    const q = new URLSearchParams(osState.filtros).toString()
    const r = await fetch('/api/os' + (q ? '?' + q : ''), { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const d = await r.json()

    document.getElementById('cont-os').textContent = d.total ?? 0
    // O escopo padrão vem do servidor (coordenação vê todas; fiscal vê as
    // suas), e o seletor tem de refletir o que está de fato em vigor.
    if (!osState.filtros.agente && d.escopo) {
      document.getElementById('os-escopo').value = d.escopo
    }
    renderOs(d.ordens ?? [])
  } catch (e) {
    console.error(e)
    alvo.innerHTML = '<div class="lista-vazia">Não foi possível carregar as ordens.</div>'
  }
}

/** @param {Array<Object>} ordens */
function renderOs(ordens) {
  const alvo = document.getElementById('lista-os')

  if (!ordens.length) {
    alvo.innerHTML = '<div class="lista-vazia">Nenhuma ordem de serviço por aqui.</div>'
    return
  }

  alvo.innerHTML = ordens.map(o => {
    // Os designados são o dado que responde "isto é comigo?" — a pergunta que
    // faz o fiscal abrir a tela. Por isso ficam na linha, e não escondidos na
    // ficha.
    const quem = o.fiscais.length
      ? o.fiscais.slice(0, 3).map(esc).join(', ') + (o.fiscais.length > 3 ? ` +${o.fiscais.length - 3}` : '')
      : '<i>sem designado</i>'

    return `
      <div class="item os-item${o.prioridade === 'alta' ? ' os-alta' : ''}" onclick="abrirOs(${o.id})">
        <div class="os-topo">
          <span class="mono os-num">${esc(o.numero)}</span>
          <span class="badge ${esc(o.situacao.classe)}">${esc(o.situacao.texto)}</span>
          ${o.prioridade === 'alta' ? '<span class="badge bd-cr">Alta</span>' : ''}
          <span class="os-nat">${esc(o.natureza)}</span>
        </div>
        <div class="os-obj">${esc(o.objeto)}</div>
        <div class="os-linha">
          <span class="os-quem">${quem}</span>
          <span class="os-quando">${esc(o.quando)}</span>
        </div>
      </div>`
  }).join('')
}

// ── FICHA ────────────────────────────────────────────────────

/** @param {number} id */
async function abrirOs(id) {
  try {
    const r = await fetch('/api/os/' + id, { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error('HTTP ' + r.status) }
    const o = await r.json()
    osState.aberta = o

    document.getElementById('osf-numero').textContent = o.numero
    document.getElementById('osf-objeto').textContent = o.objeto
    const selo = document.getElementById('osf-situacao')
    selo.className = 'badge ' + o.situacao_tag.classe
    selo.textContent = o.situacao_tag.texto

    const linha = (rot, val) => val
      ? `<div class="fi-linha"><div class="fi-campo"><span class="fi-rot">${rot}</span>
           <span class="fi-val">${val}</span></div></div>`
      : ''

    const dias = o.jornadas.length
      ? '<ol class="os-dias">' + o.jornadas.map(j =>
          `<li>${esc(j.rotulo)}${j.observacao ? ' — ' + esc(j.observacao) : ''}</li>`).join('') + '</ol>'
      : ''

    document.getElementById('osf-corpo').innerHTML =
        linha('Natureza', esc(o.natureza_rotulo ?? ''))
      + linha('Quando', esc(o.quando))
      + (dias ? `<div class="sec-title">Dias marcados</div>${dias}` : '')
      + linha('Designados', o.fiscais.map(f => esc(f.name)).join(', ') || '<i>nenhum</i>')
      + linha('Emitida por', esc(o.emitente ?? '—') + (o.emitida_em ? ' · ' + esc(o.emitida_em) : ''))
      + linha('Imóvel', o.imovel ? esc(o.imovel) : '')
      + linha('Protocolo', o.protocolo ? esc(o.protocolo) : '')
      + (o.descricao ? `<div class="sec-title">Detalhamento</div><div class="os-desc">${esc(o.descricao)}</div>` : '')
      + (o.encerramento ? `<div class="sec-title">Encerramento</div><div class="os-desc">${esc(o.encerramento)}</div>` : '')

    renderTramitacaoOs(o)
    openModal('m-os')
  } catch (e) {
    console.error(e)
    toast('Não foi possível abrir a ordem', 'err')
  }
}

/**
 * O que se pode fazer com a ordem, de onde ela está.
 *
 * Uma ordem concluída não volta a "em andamento" por aqui: reabrir um ato de
 * coordenação é decisão da coordenação, e não um botão a um toque de quem
 * estava cumprindo.
 *
 * @param {Object} o
 */
function renderTramitacaoOs(o) {
  const alvo = document.getElementById('osf-tramitacao')
  if (!alvo) { return }

  if (o.situacao === 'concluida' || o.situacao === 'cancelada') {
    alvo.innerHTML = `<div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-os')">Fechar</button></div>`
    return
  }

  alvo.innerHTML = `
    <div class="sec-title">Andamento</div>
    <div class="field">
      <label for="osf-encerramento">Como ficou</label>
      <textarea id="osf-encerramento" rows="2" maxlength="2000"
        style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"
        placeholder="O que foi feito, ou por que a ordem não seguiu"></textarea>
    </div>
    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-os')">Fechar</button>
      <div style="flex:1"></div>
      ${o.situacao === 'aberta'
        ? `<button class="btn out-green" onclick="andarOs('em_andamento')">Iniciar</button>` : ''}
      <button class="btn primary" onclick="andarOs('concluida')">Concluir</button>
    </div>`
}

/** @param {string} situacao */
function andarOs(situacao) {
  const o = osState.aberta
  if (!o) { return }

  const texto = document.getElementById('osf-encerramento')?.value.trim() || ''
  const rotulos = { em_andamento: 'Iniciar', concluida: 'Concluir', cancelada: 'Cancelar' }

  confirmarAcao({
    titulo: rotulos[situacao] + ' a ordem ' + o.numero,
    mensagem: situacao === 'concluida'
      ? 'Dar a ordem por cumprida? A coordenação passa a ver como concluída.'
      : 'Registrar que o serviço começou?',
    textoBtn: rotulos[situacao],
    onConfirm: async () => {
      const r = await fetch(`/api/os/${o.id}/situacao`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ situacao, encerramento: texto }),
      })
      const d = await r.json().catch(() => ({}))
      if (!r.ok) { throw new Error(d.message || 'HTTP ' + r.status) }

      fModalBtn('m-os')
      toast('Ordem atualizada')
      carregarOs()
    },
  })
}

// ── EMISSÃO ──────────────────────────────────────────────────

async function novaOs() {
  osState.designados = new Set()
  osState.jornadas = []
  osState.natureza = 'especifica'
  osState.regime = 'periodo'
  osState.prioridade = 'normal'

  const põe = (id, v) => { const e = document.getElementById(id); if (e) { e.value = v } }
  põe('os-objeto', ''); põe('os-descricao', '')
  põe('os-inicio', ''); põe('os-fim', '')
  põe('os-dia-data', ''); põe('os-dia-ini', ''); põe('os-dia-fim', '')

  await carregarFiscais()
  pintarOs()
  renderJornadas()
  openModal('m-os-nova')
}

/** A quem se pode designar. Buscado uma vez por sessão. */
async function carregarFiscais() {
  if (!osState.fiscais.length) {
    try {
      const r = await fetch('/api/os/fiscais', { headers: { Accept: 'application/json' } })
      osState.fiscais = await r.json()
    } catch (e) {
      console.error(e)
      toast('Não foi possível carregar a lista de fiscais', 'err')
    }
  }

  document.getElementById('os-fiscais').innerHTML = osState.fiscais.map(f => `
    <label class="chk-item" onclick="setTimeout(()=>alternarFiscal(${f.id}, this),0)">
      <input type="checkbox" value="${f.id}">
      <span class="desc">${esc(f.name)}<br>
        <span class="cod">${esc(f.perfil === 'admin' ? 'Coordenação' : 'Agente de fiscalização')}</span></span>
    </label>`).join('')
}

/** @param {number} id @param {HTMLElement} el */
function alternarFiscal(id, el) {
  const marcado = el.querySelector('input').checked
  marcado ? osState.designados.add(id) : osState.designados.delete(id)
  el.classList.toggle('marcado', marcado)
}

/** @param {string} v */
function escolherNatureza(v) { osState.natureza = v; pintarOs() }

/** @param {string} v */
function escolherPrioridade(v) { osState.prioridade = v; pintarOs() }

/**
 * Período e dias marcados são exclusivos.
 *
 * Não é economia de tela: um serviço não é "de 1º a 30 E também nos dias 12 e
 * 19". Deixar os dois preenchidos deixaria a ordem dizer duas coisas, e quem
 * fosse cumpri-la teria de escolher qual valia.
 *
 * @param {string} v
 */
function escolherRegime(v) {
  osState.regime = v
  document.getElementById('os-periodo').hidden = v !== 'periodo'
  document.getElementById('os-dias').hidden = v !== 'dias'
  pintarOs()
}

function pintarOs() {
  const marca = (id, valor) => document.querySelectorAll('#' + id + ' .vs-op')
    .forEach(b => b.classList.toggle('at', b.dataset.valor === valor))
  marca('os-natureza', osState.natureza)
  marca('os-regime', osState.regime)
  marca('os-prioridade', osState.prioridade)
}

// ── OS DIAS MARCADOS ─────────────────────────────────────────

function addJornada() {
  const data = document.getElementById('os-dia-data').value
  const ini = document.getElementById('os-dia-ini').value
  const fim = document.getElementById('os-dia-fim').value

  if (!data) { toast('Escolha o dia', 'err'); return }
  if (ini && fim && fim <= ini) { toast('O fim vem antes do começo', 'err'); return }
  if (osState.jornadas.length >= 60) { toast('Limite de 60 dias por ordem', 'err'); return }

  // O mesmo dia pode ter dois turnos (manhã e tarde), então não se barra a
  // data repetida — barra-se o horário idêntico, que seria duplicata.
  const igual = osState.jornadas.some(j => j.data === data && j.ini === (ini || null) && j.fim === (fim || null))
  if (igual) { toast('Este dia e horário já estão na lista', 'aviso'); return }

  osState.jornadas.push({ data, ini: ini || null, fim: fim || null })
  osState.jornadas.sort((a, b) => (a.data + (a.ini || '')).localeCompare(b.data + (b.ini || '')))

  document.getElementById('os-dia-ini').value = ''
  document.getElementById('os-dia-fim').value = ''
  renderJornadas()
}

/** @param {number} i */
function removerJornada(i) {
  osState.jornadas.splice(i, 1)
  renderJornadas()
}

function renderJornadas() {
  const alvo = document.getElementById('os-jornadas')
  if (!alvo) { return }

  if (!osState.jornadas.length) {
    alvo.innerHTML = '<div class="leg">Nenhum dia marcado ainda.</div>'
    return
  }

  alvo.innerHTML = osState.jornadas.map((j, i) => {
    const [a, m, d] = j.data.split('-')
    const hora = j.ini && j.fim ? `das ${j.ini} às ${j.fim}`
      : j.ini ? `a partir das ${j.ini}`
      : j.fim ? `até às ${j.fim}` : 'sem horário marcado'
    return `
      <div class="vs-exig">
        <span class="num">${i + 1}</span>
        <span class="txt">${d}/${m}/${a}<span class="prazo">${hora}</span></span>
        <button type="button" class="btn danger sm" onclick="removerJornada(${i})">Excluir</button>
      </div>`
  }).join('')
}

// ── GRAVAÇÃO ─────────────────────────────────────────────────

function emitirOs() {
  if (osState.emitindo) { return }

  const objeto = document.getElementById('os-objeto').value.trim()
  if (!objeto) { exigirCampo('os-objeto', 'Diga o que a ordem determina'); return }
  if (!osState.designados.size) { toast('Designe ao menos um fiscal', 'err'); return }

  if (osState.regime === 'dias' && !osState.jornadas.length) {
    toast('Marque ao menos um dia de trabalho', 'err'); return
  }

  const inicio = document.getElementById('os-inicio').value
  const fim = document.getElementById('os-fim').value
  if (osState.regime === 'periodo' && inicio && fim && fim < inicio) {
    toast('O fim do período vem antes do início', 'err'); return
  }

  const quando = osState.regime === 'dias'
    ? `${osState.jornadas.length} dia(s) marcado(s)`
    : (inicio && fim ? `de ${inicio.split('-').reverse().join('/')} a ${fim.split('-').reverse().join('/')}`
                     : 'sem prazo fixado')

  confirmarAcao({
    titulo: 'Emitir ordem de serviço',
    mensagem: `Determinar "${objeto}" a ${osState.designados.size} fiscal(is), ${quando}?`,
    textoBtn: 'Emitir',
    onConfirm: () => enviarOs({ objeto, inicio, fim }),
  })
}

/** @param {{objeto:string, inicio:string, fim:string}} campos */
async function enviarOs({ objeto, inicio, fim }) {
  osState.emitindo = true

  const corpo = {
    objeto,
    descricao: document.getElementById('os-descricao').value.trim() || null,
    natureza: osState.natureza,
    regime: osState.regime,
    prioridade: osState.prioridade,
    fiscais: [...osState.designados],
  }

  // Só vai o que o regime escolhido usa: mandar os dois deixaria a ordem
  // dizer duas coisas, e o servidor teria de escolher qual vale.
  if (osState.regime === 'periodo') {
    corpo.inicio = inicio || null
    corpo.fim = fim || null
  } else {
    corpo.jornadas = osState.jornadas.map(j => ({
      data: j.data, hora_inicio: j.ini, hora_fim: j.fim,
    }))
  }

  try {
    const r = await fetch('/api/os', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify(corpo),
    })

    if (r.status === 419) { toast('Sessão expirada. Recarregando...', 'err'); setTimeout(() => location.reload(), 1500); return }
    const d = await r.json().catch(() => ({}))
    if (!r.ok) {
      const primeiro = d.errors ? Object.values(d.errors)[0][0] : d.message
      throw new Error(primeiro || 'HTTP ' + r.status)
    }

    fModalBtn('m-os-nova')
    toast(d.message || 'Ordem emitida')
    carregarOs()
  } catch (e) {
    console.error(e)
    toast(e.message || 'Falha ao emitir a ordem', 'err')
    throw e   // mantém o modal de confirmação aberto para nova tentativa
  } finally {
    osState.emitindo = false
  }
}
