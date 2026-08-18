// ══════════════════════════════════════════════
// MÓDULO: PARÂMETROS DO SISTEMA (só administrador)
//
// Usuários, legislação (leis + artigos), UPF por exercício e calendário de
// feriados. Tela fora do fluxo das abas — abre por cima de tudo via a
// engrenagem no cabeçalho, porque é configuração, não trabalho do dia a dia.
// ══════════════════════════════════════════════

/** Estado carregado de uma vez em /api/parametros. */
const parState = {
  carregado: false,
  usuarios: [],
  leis: [],
  irregularidades: [],
  upfs: [],
  feriados: [],
  geral: [],
}

function abrirParametros() {
  document.getElementById('t-parametros').classList.add('at')
  document.body.style.overflow = 'hidden'
  carregarParametros()
}

function fecharParametros() {
  document.getElementById('t-parametros').classList.remove('at')
  document.body.style.overflow = ''
}

/** @param {string} nome */
function subParametros(nome) {
  document.querySelectorAll('.sub-abas button').forEach(b => b.classList.toggle('at', b.dataset.sub === nome))
  document.querySelectorAll('.par-painel').forEach(p => p.classList.toggle('at', p.id === 'par-' + nome))
}

async function carregarParametros() {
  try {
    const r = await fetch('/api/parametros', { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    parState.usuarios = d.usuarios
    parState.upfs = d.upfs
    parState.feriados = d.feriados
    parState.geral = d.geral
    parState.carregado = true
    renderUsuarios()
    renderUpfs()
    renderFeriados()
    renderGeral()
    await recarregarLegislacao()
  } catch (e) {
    console.error(e)
    toast('Não foi possível carregar os parâmetros', 'err')
  }
}

// ── USUÁRIOS ─────────────────────────────────────────────────

function renderUsuarios() {
  document.getElementById('cont-usuarios').textContent = parState.usuarios.length
  document.getElementById('lista-usuarios').innerHTML = parState.usuarios.map(u => `
    <div class="par-linha">
      <div class="principal">
        <b>${esc(u.name)}</b>
        <span>${esc(u.email)}${u.matricula ? ' · ' + esc(u.matricula) : ''} · ${esc(u.perfil_rotulo)}
          ${u.tipo_usuario ? '· ' + esc(u.tipo_usuario) : ''}${u.ativo ? '' : ' · inativo'}</span>
      </div>
      <button class="acao-x" onclick="editarUsuario(${u.id})" title="Editar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
          <path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
      </button>
    </div>`).join('') || '<div class="lista-vazia">Nenhum usuário cadastrado.</div>'
}

function novoUsuario() {
  document.getElementById('us-titulo').textContent = 'Novo usuário'
  document.getElementById('us-id').value = ''
  document.getElementById('us-nome').value = ''
  document.getElementById('us-email').value = ''
  document.getElementById('us-matricula').value = ''
  document.getElementById('us-cargo').value = 'agente'
  document.getElementById('us-perfil').value = 'comum'
  document.getElementById('us-ativo').checked = true
  document.getElementById('us-senha').value = ''
  document.getElementById('us-senha2').value = ''
  openModal('m-usuario')
}

/** @param {number} id */
function editarUsuario(id) {
  const u = parState.usuarios.find(x => x.id === id)
  if (!u) return
  document.getElementById('us-titulo').textContent = u.name
  document.getElementById('us-id').value = u.id
  document.getElementById('us-nome').value = u.name
  document.getElementById('us-email').value = u.email
  document.getElementById('us-matricula').value = u.matricula || ''
  document.getElementById('us-cargo').value = u.tipo_usuario || 'agente'
  document.getElementById('us-perfil').value = u.perfil
  document.getElementById('us-ativo').checked = !!u.ativo
  document.getElementById('us-senha').value = ''
  document.getElementById('us-senha2').value = ''
  openModal('m-usuario')
}

async function salvarUsuario() {
  const senha = document.getElementById('us-senha').value
  const senha2 = document.getElementById('us-senha2').value
  if (senha && senha !== senha2) { toast('As senhas não conferem', 'err'); return }

  const corpo = {
    id: document.getElementById('us-id').value || null,
    name: document.getElementById('us-nome').value.trim(),
    email: document.getElementById('us-email').value.trim(),
    matricula: document.getElementById('us-matricula').value.trim() || null,
    tipo_usuario: document.getElementById('us-cargo').value,
    perfil: document.getElementById('us-perfil').value,
    ativo: document.getElementById('us-ativo').checked,
    senha: senha || null,
    senha_confirmation: senha2 || null,
  }
  await postParametro('/api/parametros/usuarios', corpo, 'm-usuario', carregarParametros)
}

// ── LEGISLAÇÃO ───────────────────────────────────────────────

function renderLeis() {
  document.getElementById('cont-leis').textContent = parState.leis.length

  const avisoEl = document.getElementById('par-legislacao-aviso')
  avisoEl.innerHTML = parState._semEnquadramento
    ? `<p class="aviso-legal"><b>${parState._semEnquadramento} irregularidade(s) sem artigo vinculado.</b>
       Enquanto isso, o sistema recusa lavrar o auto correspondente.</p>` : ''

  document.getElementById('lista-leis').innerHTML = parState.leis.map(l => `
    <div class="bloco" style="margin-bottom:12px">
      <div class="topo-lista">
        <div>
          <b style="font-size:14px;color:var(--chumbo)">${esc(l.nome)}</b>
          <div style="font-size:11.5px;color:var(--tx3)">${esc(l.numero)}${l.ano ? ' · ' + l.ano : ''}
            ${l.ativa ? '' : ' · <span style="color:var(--red)">inativa</span>'}</div>
        </div>
        <div style="display:flex;gap:6px">
          <button class="btn sm" onclick="editarLei(${l.id})">Editar</button>
          <button class="btn sm primary" onclick="novoArtigo(${l.id})">+ Artigo</button>
        </div>
      </div>
      <div>${(l.artigos || []).map(a => `
        <div class="par-linha" style="cursor:pointer" onclick="editarArtigo(${l.id},${a.id})">
          <div class="principal">
            <b>${esc(a.apelido || a.numero)}</b>
            <span>${esc(a.numero)} · ${rotuloBaseMulta(a)}${a.irregularidades.length ? ' · ' + a.irregularidades.length + ' irregularidade(s)' : ' · <span style="color:var(--red)">sem irregularidade vinculada</span>'}
              ${a.ativo ? '' : ' · inativo'}</span>
          </div>
        </div>`).join('') || '<div class="lista-vazia">Nenhum artigo cadastrado nesta lei.</div>'}
      </div>
    </div>`).join('') || '<div class="lista-vazia">Nenhuma lei cadastrada.</div>'
}

/** @param {Object} a */
function rotuloBaseMulta(a) {
  if (a.base_multa === 'fixa') return fmtNum(a.multa_upf || 0) + ' UPF'
  if (a.base_multa === 'sem_multa') return 'sem multa'
  const alvo = a.base_multa === 'area_terreno' ? 'terreno' : 'construído'
  return fmtNum(a.multa_upf_m2 || 0) + ' UPF/m² · ' + alvo
}

function novaLei() {
  document.getElementById('lei-id').value = ''
  document.getElementById('lei-numero').value = ''
  document.getElementById('lei-nome').value = ''
  document.getElementById('lei-ano').value = new Date().getFullYear()
  document.getElementById('lei-ementa').value = ''
  document.getElementById('lei-prazo-defesa').value = 5
  document.getElementById('lei-prazo-cumprimento').value = 10
  document.getElementById('lei-ciencia-notif').value = ''
  document.getElementById('lei-ciencia-auto').value = ''
  document.getElementById('lei-ativa').checked = true
  openModal('m-lei')
}

/** @param {number} id */
function editarLei(id) {
  const l = parState.leis.find(x => x.id === id)
  if (!l) return
  document.getElementById('lei-id').value = l.id
  document.getElementById('lei-numero').value = l.numero
  document.getElementById('lei-nome').value = l.nome
  document.getElementById('lei-ano').value = l.ano || ''
  document.getElementById('lei-ementa').value = l.ementa || ''
  document.getElementById('lei-prazo-defesa').value = l.prazo_defesa_dias
  document.getElementById('lei-prazo-cumprimento').value = l.prazo_cumprimento_dias
  document.getElementById('lei-ciencia-notif').value = l.ciencia_notificacao || ''
  document.getElementById('lei-ciencia-auto').value = l.ciencia_auto || ''
  document.getElementById('lei-ativa').checked = !!l.ativa
  openModal('m-lei')
}

async function salvarLei() {
  const corpo = {
    id: document.getElementById('lei-id').value || null,
    numero: document.getElementById('lei-numero').value.trim(),
    nome: document.getElementById('lei-nome').value.trim(),
    ano: document.getElementById('lei-ano').value || null,
    ementa: document.getElementById('lei-ementa').value.trim() || null,
    prazo_defesa_dias: document.getElementById('lei-prazo-defesa').value,
    prazo_cumprimento_dias: document.getElementById('lei-prazo-cumprimento').value,
    ciencia_notificacao: document.getElementById('lei-ciencia-notif').value.trim() || null,
    ciencia_auto: document.getElementById('lei-ciencia-auto').value.trim() || null,
    ativa: document.getElementById('lei-ativa').checked,
  }
  await postParametro('/api/legislacao', corpo, 'm-lei', recarregarLegislacao)
}

/** Só a legislação — evita re-buscar usuários/UPF/feriados ao salvar um artigo. */
async function recarregarLegislacao() {
  const r = await fetch('/api/legislacao', { headers: { Accept: 'application/json' } })
  const d = await r.json()
  parState.leis = d.leis
  parState.irregularidades = d.irregularidades
  parState._semEnquadramento = d.sem_enquadramento
  renderLeis()
}

function trocarBaseMulta() {
  const base = document.getElementById('art-base').value
  document.getElementById('art-bloco-fixa').style.display = base === 'fixa' ? '' : 'none'
  document.getElementById('art-bloco-area').style.display = (base === 'area_construida' || base === 'area_terreno') ? '' : 'none'
}

/** @param {number} legislacaoId */
function novoArtigo(legislacaoId) {
  const lei = parState.leis.find(x => x.id === legislacaoId)
  document.getElementById('art-titulo').textContent = 'Novo artigo'
  document.getElementById('art-lei').textContent = lei ? lei.nome : '—'
  document.getElementById('art-id').value = ''
  document.getElementById('art-legislacao-id').value = legislacaoId
  document.getElementById('art-numero').value = ''
  document.getElementById('art-apelido').value = ''
  document.getElementById('art-conduta').value = ''
  document.getElementById('art-sancao').value = ''
  document.getElementById('art-base').value = 'fixa'
  document.getElementById('art-multa-upf').value = ''
  document.getElementById('art-multa-m2').value = ''
  document.getElementById('art-multa-min').value = ''
  document.getElementById('art-multa-max').value = ''
  document.getElementById('art-ativo').checked = true
  trocarBaseMulta()
  renderIrregularidadesChecklist([])
  openModal('m-artigo')
}

/** @param {number} legislacaoId @param {number} artigoId */
function editarArtigo(legislacaoId, artigoId) {
  const lei = parState.leis.find(x => x.id === legislacaoId)
  const a = lei?.artigos.find(x => x.id === artigoId)
  if (!a) return
  document.getElementById('art-titulo').textContent = a.apelido || a.numero
  document.getElementById('art-lei').textContent = lei.nome
  document.getElementById('art-id').value = a.id
  document.getElementById('art-legislacao-id').value = legislacaoId
  document.getElementById('art-numero').value = a.numero
  document.getElementById('art-apelido').value = a.apelido || ''
  document.getElementById('art-conduta').value = a.conduta || ''
  document.getElementById('art-sancao').value = a.sancao || ''
  document.getElementById('art-base').value = a.base_multa || 'fixa'
  document.getElementById('art-multa-upf').value = a.multa_upf ?? ''
  document.getElementById('art-multa-m2').value = a.multa_upf_m2 ?? ''
  document.getElementById('art-multa-min').value = a.multa_min_upf ?? ''
  document.getElementById('art-multa-max').value = a.multa_max_upf ?? ''
  document.getElementById('art-ativo').checked = !!a.ativo
  trocarBaseMulta()
  renderIrregularidadesChecklist(a.irregularidade_ids || [])
  openModal('m-artigo')
}

/** @param {Array<number>} marcadas */
function renderIrregularidadesChecklist(marcadas) {
  const alvo = document.getElementById('art-irregularidades')
  alvo.innerHTML = parState.irregularidades.map(i => `
    <label class="chk-item ${marcadas.includes(i.id) ? 'marcado' : ''}"
           onclick="setTimeout(()=>this.classList.toggle('marcado', this.querySelector('input').checked),0)">
      <input type="checkbox" value="${i.id}" ${marcadas.includes(i.id) ? 'checked' : ''}>
      <span class="desc">${esc(i.descricao)}<br><span class="cod">${esc(i.codigo)} · ${esc(i.gravidade)}</span></span>
    </label>`).join('') || '<div class="lista-vazia">Nenhuma irregularidade cadastrada.</div>'
}

async function salvarArtigo() {
  const irregularidades = [...document.querySelectorAll('#art-irregularidades input:checked')].map(i => Number(i.value))
  const corpo = {
    id: document.getElementById('art-id').value || null,
    legislacao_id: document.getElementById('art-legislacao-id').value,
    numero: document.getElementById('art-numero').value.trim(),
    apelido: document.getElementById('art-apelido').value.trim() || null,
    conduta: document.getElementById('art-conduta').value.trim() || null,
    sancao: document.getElementById('art-sancao').value.trim() || null,
    base_multa: document.getElementById('art-base').value,
    multa_upf: document.getElementById('art-multa-upf').value || null,
    multa_upf_m2: document.getElementById('art-multa-m2').value || null,
    multa_min_upf: document.getElementById('art-multa-min').value || null,
    multa_max_upf: document.getElementById('art-multa-max').value || null,
    ativo: document.getElementById('art-ativo').checked,
    irregularidades,
  }
  await postParametro('/api/legislacao/artigos', corpo, 'm-artigo', recarregarLegislacao)
}

// ── UPF ──────────────────────────────────────────────────────

function renderUpfs() {
  document.getElementById('cont-upf').textContent = parState.upfs.length
  document.getElementById('lista-upf').innerHTML = parState.upfs.map(u => `
    <div class="par-linha">
      <div class="principal">
        <b>${u.exercicio} · ${fmtNum(u.valor)}</b>
        <span>Vigente desde ${formatarDataBR(u.vigencia_inicio)}${u.norma ? ' · ' + esc(u.norma) : ''}</span>
      </div>
      <button class="acao-x" onclick="excluirUpf(${u.id})" title="Excluir">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
          <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
      </button>
    </div>`).join('') || '<div class="lista-vazia">Nenhuma UPF cadastrada.</div>'
}

function novaUpf() {
  document.getElementById('upf-exercicio').value = new Date().getFullYear()
  document.getElementById('upf-valor').value = ''
  document.getElementById('upf-vigencia').value = ''
  atualizarDisplayData(document.getElementById('upf-vigencia'))
  document.getElementById('upf-norma').value = ''
  openModal('m-upf')
}

async function salvarUpf() {
  const corpo = {
    exercicio: document.getElementById('upf-exercicio').value,
    valor: document.getElementById('upf-valor').value,
    vigencia_inicio: document.getElementById('upf-vigencia').value,
    norma: document.getElementById('upf-norma').value.trim() || null,
  }
  if (!corpo.exercicio || !corpo.valor || !corpo.vigencia_inicio) {
    toast('Exercício, valor e vigência são obrigatórios', 'err'); return
  }
  await postParametro('/api/parametros/upf', corpo, 'm-upf', carregarParametros)
}

/** @param {number} id */
function excluirUpf(id) {
  confirmarAcao({
    titulo: 'Excluir UPF',
    mensagem: 'Documentos já lavrados mantêm o valor de UPF que foi congelado neles. Excluir?',
    perigo: true,
    onConfirm: async () => {
      const r = await fetch('/api/parametros/upf/' + id, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      })
      const d = await r.json()
      if (!r.ok) { toast(d.message || 'Não foi possível excluir', 'err'); return }
      toast(d.message)
      carregarParametros()
    },
  })
}

// ── FERIADOS ─────────────────────────────────────────────────

function renderFeriados() {
  document.getElementById('cont-feriados').textContent = parState.feriados.length
  document.getElementById('lista-feriados').innerHTML = parState.feriados.map(f => `
    <div class="par-linha">
      <div class="principal">
        <b>${formatarDataBR(f.data)} — ${esc(f.nome)}</b>
        <span>${esc(f.tipo)}${f.recorrente ? ' · repete todo ano' : ''}</span>
      </div>
      <button class="acao-x" onclick="excluirFeriado(${f.id})" title="Excluir">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
          <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
      </button>
    </div>`).join('') || '<div class="lista-vazia">Nenhum feriado cadastrado.</div>'
}

function novoFeriado() {
  document.getElementById('fer-data').value = ''
  atualizarDisplayData(document.getElementById('fer-data'))
  document.getElementById('fer-nome').value = ''
  document.getElementById('fer-tipo').value = 'municipal'
  document.getElementById('fer-recorrente').checked = false
  openModal('m-feriado')
}

async function salvarFeriado() {
  const corpo = {
    data: document.getElementById('fer-data').value,
    nome: document.getElementById('fer-nome').value.trim(),
    tipo: document.getElementById('fer-tipo').value,
    recorrente: document.getElementById('fer-recorrente').checked,
  }
  if (!corpo.data || !corpo.nome) { toast('Data e nome são obrigatórios', 'err'); return }
  await postParametro('/api/parametros/feriados', corpo, 'm-feriado', carregarParametros)
}

/** @param {number} id */
function excluirFeriado(id) {
  confirmarAcao({
    titulo: 'Excluir feriado',
    mensagem: 'Prazos já calculados não mudam retroativamente. Excluir mesmo assim?',
    perigo: true,
    onConfirm: async () => {
      const r = await fetch('/api/parametros/feriados/' + id, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      })
      const d = await r.json()
      if (!r.ok) { toast(d.message || 'Não foi possível excluir', 'err'); return }
      toast(d.message)
      carregarParametros()
    },
  })
}

// ── DADOS DO ÓRGÃO ───────────────────────────────────────────

function renderGeral() {
  document.getElementById('cont-geral').textContent = parState.geral.length
  document.getElementById('lista-geral').innerHTML = parState.geral.map(p => `
    <div class="field">
      <label for="geral-${esc(p.chave)}">${esc(p.descricao)}</label>
      <input type="text" id="geral-${esc(p.chave)}" data-chave="${esc(p.chave)}" value="${esc(p.valor)}">
    </div>`).join('')
}

async function salvarGeral() {
  const valores = {}
  document.querySelectorAll('#lista-geral [data-chave]').forEach(i => { valores[i.dataset.chave] = i.value })
  const r = await fetch('/api/parametros/geral', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json', Accept: 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
    },
    body: JSON.stringify({ valores }),
  })
  const d = await r.json()
  if (!r.ok) { toast(d.message || 'Não foi possível salvar', 'err'); return }
  toast(d.message)
}

// ── HELPER COMUM ─────────────────────────────────────────────

/**
 * POST genérico dos formulários desta tela: mesmo padrão de cabeçalho, erro
 * e fechamento em todos — só muda o endpoint, o modal e o que recarregar.
 */
async function postParametro(url, corpo, modalId, aoTerminar) {
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json', Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
      },
      body: JSON.stringify(corpo),
    })
    const d = await r.json()
    if (!r.ok) { toast(d.message || primeiroErroPar(d), 'err'); return }
    toast(d.message || (d.aviso ?? 'Gravado.'))
    if (d.aviso) toast(d.aviso, 'err')
    fModalBtn(modalId)
    await aoTerminar()
  } catch (e) {
    console.error(e)
    toast('Falha de rede ao salvar', 'err')
  }
}

function primeiroErroPar(d) {
  const e = d?.errors && Object.values(d.errors)[0]
  return Array.isArray(e) ? e[0] : 'Não foi possível concluir a operação'
}
