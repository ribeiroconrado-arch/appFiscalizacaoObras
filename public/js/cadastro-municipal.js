// ══════════════════════════════════════════════
// PARÂMETROS → CADASTRO MUNICIPAL
//
// O cadastro imobiliário mensal da prefeitura: o JSON de diferenças gerado
// pelo app desktop (ferramentas/cadastro-desktop), ou a planilha .xlsx direta.
// O servidor compara com o que já tem e grava só o que mudou (ver
// App\Cadastro\CargaDoCadastro); esta tela envia, acompanha o processamento e
// mostra o que mudou.
//
// O processamento roda DEPOIS da resposta do envio, então a tela consulta a
// carga a cada 2 s até ela sair de "na fila"/"processando".
// ══════════════════════════════════════════════

const cmState = {
  /** @type {Array<Object>} */ cargas: [],
  /** @type {number|null} */ acompanhando: null,
  /** @type {number|null} */ timer: null,
  /** @type {{id:number, campo:string, pagina:number}|null} */ detalhe: null,
}

const CM_STATUS = {
  na_fila:                ['Na fila', 'bd-pe'],
  processando:            ['Processando', 'bd-pe'],
  aguardando_confirmacao: ['Aguardando confirmação', 'bd-al'],
  concluida:              ['Concluída', 'bd-ok'],
  falhou:                 ['Falhou', 'bd-er'],
}

const CM_EM_ANDAMENTO = ['na_fila', 'processando']

const cmNum = n => Number(n || 0).toLocaleString('pt-BR')

function cmCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

// ── lista ────────────────────────────────────────────────────

async function carregarCargasDoCadastro() {
  const lista = document.getElementById('cm-lista')
  if (!lista) { return }
  try {
    const r = await fetch('/api/cadastro/cargas', { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error(r.status) }
    const d = await r.json()
    cmState.cargas = d.cargas
    document.getElementById('cont-cargas').textContent = d.cargas.length
    desenharCargas(d.imoveis)

    const andando = d.cargas.find(c => CM_EM_ANDAMENTO.includes(c.status))
    if (andando) { acompanharCarga(andando.id) }
  } catch {
    lista.innerHTML = '<div class="vazio-msg">Não foi possível carregar as cargas.</div>'
  }
}

function desenharCargas(imoveis) {
  const lista = document.getElementById('cm-lista')
  if (!cmState.cargas.length) {
    lista.innerHTML = '<p class="imp-expl">Nenhuma carga do cadastro ainda.</p>'
    return
  }
  lista.innerHTML = `
    <p class="imp-expl">${cmNum(imoveis)} imóveis no cadastro carregado.</p>
    <div class="imp-tabela-rolagem"><table class="imp-tabela">
      <thead><tr><th>Enviada</th><th>Arquivo</th><th>Situação</th>
        <th class="num">Novos</th><th class="num">Alterados</th><th class="num">Ausentes</th><th class="num">Iguais</th></tr></thead>
      <tbody>${cmState.cargas.map(c => {
        const [rotulo, cls] = CM_STATUS[c.status] || [c.status, 'bd-pe']
        return `<tr onclick="abrirCargaDoCadastro(${Number(c.id)})">
          <td>${esc(formatarDataHoraCurta(c.enviada_em))}<div class="imp-sub">${esc(c.usuario || 'terminal')}</div></td>
          <td>${esc(c.arquivo)}${c.origem === 'app' ? '<div class="imp-sub">gerado pelo app</div>' : ''}</td>
          <td><span class="badge ${cls}">${esc(rotulo)}</span></td>
          <td class="num">${cmNum(c.novos)}</td><td class="num">${cmNum(c.alterados)}</td>
          <td class="num">${cmNum(c.ausentes)}</td><td class="num">${cmNum(c.iguais)}</td>
        </tr>`
      }).join('')}</tbody>
    </table></div>`
}

// ── envio ────────────────────────────────────────────────────

function cmArquivoEscolhido() {
  const arq = document.getElementById('cm-arquivo').files[0]
  const area = document.getElementById('cm-soltar')
  area.classList.toggle('escolhido', !!arq)
  area.querySelector('b').textContent = arq ? arq.name : 'Solte aqui o .json do app ou a planilha .xlsx'
  const tamanho = arq && (arq.size < 1048576
    ? `${Math.max(1, Math.round(arq.size / 1024)).toLocaleString('pt-BR')} KB`
    : `${(arq.size / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`)
  area.querySelector('span').textContent = arq
    ? `${tamanho} · ${/\.json$/i.test(arq.name) ? 'gerado pelo app' : 'planilha'} · solte outro ou clique para trocar`
    : 'ou clique para escolher no computador'
  document.getElementById('cm-enviar').disabled = !arq
}

/** Arrastar e soltar na área — o mesmo gesto da importação de bairro. */
document.addEventListener('DOMContentLoaded', () => {
  const area = document.getElementById('cm-soltar')
  const input = document.getElementById('cm-arquivo')
  if (!area || !input) { return }
  const ligar = on => e => { e.preventDefault(); area.classList.toggle('arrastando', on) }
  area.addEventListener('dragenter', ligar(true))
  area.addEventListener('dragover', ligar(true))
  area.addEventListener('dragleave', ligar(false))
  area.addEventListener('drop', e => {
    ligar(false)(e)
    const arq = e.dataTransfer?.files?.[0]
    if (!arq) { return }
    if (!/\.(xlsx|json)$/i.test(arq.name)) { toast('Envie o .json do app do cadastro ou a planilha .xlsx.', 'err'); return }
    const dt = new DataTransfer()
    dt.items.add(arq)
    input.files = dt.files
    cmArquivoEscolhido()
  })
})

async function enviarCargaDoCadastro(forcar = false) {
  const arq = document.getElementById('cm-arquivo').files[0]
  if (!arq) { return }
  const botao = document.getElementById('cm-enviar')
  botao.disabled = true
  botao.textContent = 'Enviando…'

  const corpo = new FormData()
  corpo.append('arquivo', arq)
  if (forcar) { corpo.append('forcar', '1') }

  try {
    const r = await fetch('/api/cadastro/cargas', {
      method: 'POST', body: corpo,
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': cmCsrf() },
    })
    const d = await r.json().catch(() => ({}))
    if (r.status === 422 && d.repetida) {
      confirmarAcao({
        titulo: 'Mesmo arquivo',
        mensagem: d.message + ' Enviar mesmo assim?',
        textoBtn: 'Enviar mesmo assim',
        onConfirm: () => enviarCargaDoCadastro(true),
      })
      return
    }
    if (!r.ok) { throw new Error(d.message || 'Não foi possível enviar o arquivo.') }

    document.getElementById('cm-arquivo').value = ''
    cmArquivoEscolhido()
    toast('Arquivo recebido. Processando…')
    await carregarCargasDoCadastro()
    acompanharCarga(d.carga.id)
  } catch (e) {
    toast(e.message, 'err')
  } finally {
    botao.textContent = 'Enviar e processar'
    botao.disabled = !document.getElementById('cm-arquivo').files[0]
  }
}

// ── acompanhamento ───────────────────────────────────────────

function acompanharCarga(id) {
  cmState.acompanhando = id
  clearTimeout(cmState.timer)
  const passo = async () => {
    if (cmState.acompanhando !== id) { return }
    try {
      const r = await fetch(`/api/cadastro/cargas/${id}`, { headers: { Accept: 'application/json' } })
      if (!r.ok) { throw new Error(r.status) }
      const c = (await r.json()).carga
      desenharAndamento(c)
      if (CM_EM_ANDAMENTO.includes(c.status)) {
        cmState.timer = setTimeout(passo, 2000)
      } else {
        cmState.acompanhando = null
        carregarCargasDoCadastro()
        if (c.status === 'concluida') { toast('Carga do cadastro concluída.') }
      }
    } catch {
      cmState.timer = setTimeout(passo, 5000)
    }
  }
  passo()
}

function desenharAndamento(c) {
  const el = document.getElementById('cm-andamento')
  if (!CM_EM_ANDAMENTO.includes(c.status)) { el.innerHTML = ''; return }
  el.innerHTML = `<div class="cad-nota">
    <b>${c.status === 'na_fila' ? 'Na fila' : 'Processando'}</b> ${esc(c.arquivo)} —
    ${cmNum(c.linhas_lidas)} linhas lidas…
    ${c.mensagem ? `<div class="imp-sub">${esc(c.mensagem)}</div>` : ''}
  </div>`
}

// ── detalhe de uma carga ─────────────────────────────────────

async function abrirCargaDoCadastro(id, campo = '', pagina = 1) {
  cmState.detalhe = { id, campo, pagina }
  const caixa = document.getElementById('cm-detalhe')
  const c = cmState.cargas.find(x => x.id === id)
  if (!c) { return }

  const [rotulo, cls] = CM_STATUS[c.status] || [c.status, 'bd-pe']
  const acoes = [
    c.status === 'aguardando_confirmacao'
      ? `<button class="btn primary sm" onclick="confirmarAusenciasDaCarga(${Number(c.id)})">Confirmar ausências e concluir</button>` : '',
    c.status === 'falhou' || c.status === 'na_fila'
      ? `<button class="btn sm" onclick="reprocessarCargaDoCadastro(${Number(c.id)})">Tentar de novo</button>` : '',
  ].join('')

  let alteracoes = ''
  if (c.status === 'concluida') {
    try {
      const q = new URLSearchParams({ pagina: String(pagina) })
      if (campo) { q.set('campo', campo) }
      const r = await fetch(`/api/cadastro/cargas/${id}/alteracoes?${q}`, { headers: { Accept: 'application/json' } })
      if (!r.ok) { throw new Error(r.status) }
      alteracoes = desenharAlteracoes(c, await r.json(), campo)
    } catch {
      alteracoes = '<p class="imp-expl">Não foi possível carregar as alterações.</p>'
    }
  }

  caixa.innerHTML = `
    <div class="sec-title">Carga de ${esc(formatarDataHoraCurta(c.enviada_em))} · <span class="badge ${cls}">${esc(rotulo)}</span></div>
    <p class="imp-expl">${esc(c.arquivo)} · ${cmNum(c.linhas_lidas)} linhas · ${cmNum(c.bairros)} bairros<br>
      <b>${cmNum(c.novos)}</b> novos, <b>${cmNum(c.alterados)}</b> alterados, <b>${cmNum(c.ausentes)}</b> ausentes,
      <b>${cmNum(c.reaparecidos)}</b> reapareceram, ${cmNum(c.iguais)} iguais.
      ${c.primeira ? '<br>Primeira carga: os imóveis que já existiam viraram a base de comparação, sem histórico.' : ''}</p>
    ${c.mensagem ? `<div class="cad-nota">${esc(c.mensagem)}</div>` : ''}
    ${acoes ? `<div class="btn-row">${acoes}</div>` : ''}
    ${alteracoes}`
}

function desenharAlteracoes(c, d, campo) {
  if (!d.total && !campo) { return '<p class="imp-expl">Nenhuma alteração registrada nesta carga.</p>' }
  const opcoes = ['<option value="">Todos os campos</option>']
    .concat(d.campos.map(k => `<option value="${esc(k)}" ${k === campo ? 'selected' : ''}>${esc(k)}</option>`)).join('')
  const tipo = { novo: 'Novo', alterado: 'Alterado', ausente: 'Fora do cadastro', reapareceu: 'Reapareceu' }
  return `
    <div class="lista-campo" style="margin:6px 0">
      <select onchange="abrirCargaDoCadastro(${Number(c.id)}, this.value, 1)">${opcoes}</select>
      <span class="imp-sub">${cmNum(d.total)} registros · página ${d.pagina} de ${Math.max(1, d.paginas)}</span>
    </div>
    <div class="imp-tabela-rolagem"><table class="imp-tabela">
      <thead><tr><th>Inscrição</th><th>O quê</th><th>Antes</th><th>Depois</th></tr></thead>
      <tbody>${d.itens.map(a => `<tr>
        <td class="mono">${esc(a.inscricao)}</td>
        <td>${esc(a.campo || tipo[a.tipo] || a.tipo)}</td>
        <td>${esc(a.antes ?? '—')}</td><td>${esc(a.depois ?? '—')}</td>
      </tr>`).join('')}</tbody>
    </table></div>
    <div class="btn-row">
      ${d.pagina > 1 ? `<button class="btn sm" onclick="abrirCargaDoCadastro(${Number(c.id)}, ${jsArg(campo)}, ${d.pagina - 1})">‹ Anterior</button>` : ''}
      ${d.pagina < d.paginas ? `<button class="btn sm" onclick="abrirCargaDoCadastro(${Number(c.id)}, ${jsArg(campo)}, ${d.pagina + 1})">Próxima ›</button>` : ''}
    </div>`
}

async function cmAcao(url, sucesso) {
  try {
    const r = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': cmCsrf() } })
    const d = await r.json().catch(() => ({}))
    if (!r.ok) { throw new Error(d.message || 'Não foi possível concluir.') }
    toast(sucesso)
    document.getElementById('cm-detalhe').innerHTML = ''
    await carregarCargasDoCadastro()
    acompanharCarga(d.carga.id)
  } catch (e) {
    toast(e.message, 'err')
  }
}

function confirmarAusenciasDaCarga(id) {
  const c = cmState.cargas.find(x => x.id === id)
  confirmarAcao({
    titulo: 'Confirmar ausências',
    mensagem: `${cmNum(c?.ausentes)} imóveis destes bairros não vieram na planilha e serão marcados como fora do cadastro (não são apagados). Confirmar?`,
    textoBtn: 'Confirmar',
    onConfirm: () => cmAcao(`/api/cadastro/cargas/${id}/confirmar`, 'Ausências confirmadas. Processando…'),
  })
}

function reprocessarCargaDoCadastro(id) {
  cmAcao(`/api/cadastro/cargas/${id}/reprocessar`, 'Processando de novo…')
}
