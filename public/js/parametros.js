// ══════════════════════════════════════════════
// MÓDULO: PARÂMETROS DO SISTEMA (só administrador)
//
// Usuários, legislação, UPF e feriados. A navegação segue o padrão do
// AppPOSTURAS: onde há hierarquia (lei → artigos, ano → feriados), a lista
// do pai ocupa a tela inteira e tocar num item leva ao detalhe, com
// "← Voltar". Aninhar tudo numa árvore só produzia uma página longa demais
// para achar qualquer coisa.
//
// Cadastros simples entram direto na linha (.cad-row), sem modal: abrir uma
// janela para digitar um ano e um valor custa mais cliques do que o dado vale.
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
  /** id da lei aberta no detalhe */   leiAberta: null,
  /** ano aberto na lista de feriados */ anoAberto: null,
}

function abrirParametros() {
  openModal('m-parametros')
  carregarParametros()
}

/** @param {string} nome */
function subParametros(nome) {
  document.querySelectorAll('.sub-abas > button[data-sub]').forEach(b => b.classList.toggle('at', b.dataset.sub === nome))
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
    renderAnosFeriados()
    renderGeral()
    renderBrasao()
    await recarregarLegislacao()
  } catch (e) {
    console.error(e)
    toast('Não foi possível carregar os parâmetros', 'err')
  }
}

// ── USUÁRIOS ─────────────────────────────────────────────────

/**
 * Cartão de usuário — desenho do painel administrativo do AppPOSTURAS:
 * avatar com a inicial, nome, e uma linha de identificação com login,
 * matrícula, situação e perfil. O "Editar" fica à direita, cheio de verde,
 * porque é a única ação do cartão.
 */
function renderUsuarios() {
  document.getElementById('cont-usuarios').textContent = parState.usuarios.length

  document.getElementById('lista-usuarios').innerHTML = parState.usuarios.map(u => {
    const inicial = (u.name || '?').trim().charAt(0).toUpperCase()
    const login = (u.email || '').split('@')[0]
    const admin = u.perfil === 'admin'

    return `
      <div class="par-card">
        <div class="par-card-ident">
          <div class="par-av${admin ? ' adm' : ''}">${esc(inicial)}</div>
          <div class="par-card-txt">
            <div class="par-card-nome">${esc(u.name)}</div>
            <div class="par-card-meta">
              @${esc(login)}${u.matricula ? ' · ' + esc(u.matricula) : ''} ·
              <span class="pil ${u.ativo ? 'pil-ok' : 'pil-off'}">${u.ativo ? 'Ativo' : 'Inativo'}</span>
              <span class="badge ${admin ? 'bd-cx' : 'bd-in'}">${esc(u.perfil_rotulo)}</span>
              ${u.tipo_usuario ? `<span class="par-card-cargo">${esc(u.tipo_usuario)}</span>` : ''}
            </div>
          </div>
        </div>
        <button class="btn edit-verde sm" onclick="editarUsuario(${u.id})">${ICO_EDITAR}Editar</button>
      </div>`
  }).join('') || '<div class="lista-vazia">Nenhum usuário cadastrado.</div>'
}

/**
 * Lista de leis — cartão com nome, contagem de artigos e as duas ações.
 *
 * O cartão inteiro abre os artigos; os botões param a propagação, senão
 * clicar em "Excluir" abriria o detalhe por baixo do modal de confirmação.
 */
function renderLeis() {
  document.getElementById('cont-leis').textContent = parState.leis.length

  document.getElementById('par-legislacao-aviso').innerHTML = parState.semEnquadramento
    ? `<p class="aviso-legal"><b>${parState.semEnquadramento} irregularidade(s) sem artigo vinculado.</b>
       Enquanto isso, o sistema recusa lavrar o auto correspondente.</p>` : ''

  const termo = (document.getElementById('lei-busca')?.value || '').trim().toLowerCase()
  const leis = termo
    ? parState.leis.filter(l => (l.nome + ' ' + l.numero).toLowerCase().includes(termo))
    : parState.leis

  document.getElementById('lista-leis').innerHTML = leis.map(l => `
    <div class="par-card clicavel" onclick="abrirLei(${l.id})">
      <div class="par-card-txt">
        <div class="par-card-nome">${esc(l.numero)} - ${esc(l.nome)}</div>
        <div class="par-card-meta">${l.artigos.length} artigo(s)${l.ativa ? '' : ' · inativa'}</div>
      </div>
      <div class="par-card-acoes" onclick="event.stopPropagation()">
        <button class="btn edit-verde sm" onclick="abrirLei(${l.id})">${ICO_EDITAR}Editar</button>
        <button class="btn out-vermelho sm" onclick="excluirLei(${l.id})">Excluir</button>
      </div>
    </div>`).join('')
    || `<div class="lista-vazia">${termo ? 'Nenhuma lei com esse nome.' : 'Nenhuma lei cadastrada.'}</div>`
}

/** Refiltra sem ir ao servidor: a lista inteira já está em memória. */
function filtrarLeis() { renderLeis() }

/**
 * Cria a lei com o mínimo e já abre o detalhe para completar o resto.
 *
 * O nome vem do próprio campo de busca: quem procurou e não achou está, quase
 * sempre, prestes a cadastrar o que procurava. O número entra depois, no
 * detalhe, junto dos prazos e dos textos de ciência.
 */
async function novaLei() {
  const campo = document.getElementById('lei-busca')
  const nome = campo.value.trim()
  if (!nome) { exigirCampo('lei-busca', 'Digite o nome da lei no campo ao lado.'); return }

  const d = await postParametro('/api/legislacao', {
    numero: nome,
    nome,
    ano: new Date().getFullYear(),
    // Padrões da praxe; o detalhe da lei permite ajustar.
    prazo_defesa_dias: 5,
    prazo_cumprimento_dias: 10,
    ativa: true,
  }, null, recarregarLegislacao)

  if (d) {
    campo.value = ''
    if (d.id) abrirLei(d.id)
  }
}

/**
 * Exclui a lei. O servidor recusa quando algum documento a cita — nesse caso
 * o caminho é desativá-la, e a mensagem de erro diz isso.
 *
 * @param {number} id
 */
function excluirLei(id) {
  const l = parState.leis.find(x => x.id === id)
  if (!l) return

  confirmarAcao({
    titulo: 'Excluir lei',
    mensagem: `"${l.nome}" e seus ${l.artigos.length} artigo(s) serão apagados. `
            + 'Documentos já lavrados guardam cópia da redação e não mudam.',
    textoBtn: 'Excluir',
    perigo: true,
    onConfirm: async () => {
      await excluirParametro('/api/legislacao/' + id)
      await recarregarLegislacao()
    },
  })
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
  // Curadoria cadastral nasce DESMARCADA: é permissão para redesenhar a base
  // do município, e permissão desse porte se concede uma a uma, nunca por
  // padrão de formulário.
  document.getElementById('us-curador').checked = false
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
  document.getElementById('us-curador').checked = !!u.curador_cadastral
  document.getElementById('us-senha').value = ''
  document.getElementById('us-senha2').value = ''
  openModal('m-usuario')
}

async function salvarUsuario() {
  const senha = document.getElementById('us-senha').value
  const senha2 = document.getElementById('us-senha2').value
  if (senha && senha !== senha2) { exigirCampo('us-senha2', 'As senhas não conferem.'); return }

  await postParametro('/api/parametros/usuarios', {
    id: document.getElementById('us-id').value || null,
    name: document.getElementById('us-nome').value.trim(),
    email: document.getElementById('us-email').value.trim(),
    matricula: document.getElementById('us-matricula').value.trim() || null,
    tipo_usuario: document.getElementById('us-cargo').value,
    perfil: document.getElementById('us-perfil').value,
    ativo: document.getElementById('us-ativo').checked,
    curador_cadastral: document.getElementById('us-curador').checked,
    senha: senha || null,
    senha_confirmation: senha2 || null,
  }, 'm-usuario', carregarParametros)
}

// ── LEGISLAÇÃO: LISTA DE LEIS ────────────────────────────────

async function recarregarLegislacao() {
  const r = await fetch('/api/legislacao', { headers: { Accept: 'application/json' } })
  const d = await r.json()
  parState.leis = d.leis
  parState.irregularidades = d.irregularidades
  parState.semEnquadramento = d.sem_enquadramento
  renderLeis()
  if (parState.leiAberta) { renderArtigosDaLei() }
}


// ── LEGISLAÇÃO: DETALHE DA LEI ───────────────────────────────

/** @param {number} id */
function abrirLei(id) {
  const l = parState.leis.find(x => x.id === id)
  if (!l) return
  parState.leiAberta = id

  document.getElementById('leg-lista').style.display = 'none'
  document.getElementById('leg-detalhe').style.display = ''
  document.getElementById('leg-detalhe-titulo').textContent = l.nome
  subLei('dados')

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

  renderArtigosDaLei()
}

function voltarLeis() {
  parState.leiAberta = null
  document.getElementById('leg-detalhe').style.display = 'none'
  document.getElementById('leg-lista').style.display = ''
}

/** @param {string} nome */
function subLei(nome) {
  document.querySelectorAll('#leg-detalhe .sub-abas button').forEach(b => b.classList.toggle('at', b.dataset.leg === nome))
  document.querySelectorAll('.leg-painel').forEach(p => p.classList.toggle('at', p.id === 'leg-' + nome))
}

async function salvarLei() {
  await postParametro('/api/legislacao', {
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
  }, null, recarregarLegislacao)
}

function renderArtigosDaLei() {
  const l = parState.leis.find(x => x.id === parState.leiAberta)
  if (!l) return
  document.getElementById('leg-detalhe-titulo').textContent = l.nome
  document.getElementById('cont-artigos').textContent = l.artigos.length

  document.getElementById('lista-artigos').innerHTML = l.artigos.map(a => `
    <div class="par-linha clicavel" onclick="editarArtigo(${a.id})">
      <div class="principal">
        <b>${esc(a.apelido || a.numero)}</b>
        <span>${esc(a.numero)} · ${rotuloBaseMulta(a)}${a.irregularidades.length
          ? ' · ' + a.irregularidades.length + ' irregularidade(s)'
          : ' · <span style="color:var(--red)">sem irregularidade vinculada</span>'}${a.ativo ? '' : ' · inativo'}</span>
      </div>
      <span class="seta">›</span>
    </div>`).join('') || '<div class="lista-vazia">Nenhum artigo cadastrado nesta lei.</div>'
}

/** @param {Object} a */
function rotuloBaseMulta(a) {
  if (a.base_multa === 'fixa') return fmtNum(a.multa_upf || 0) + ' UPF'
  if (a.base_multa === 'sem_multa') return 'sem multa'
  const alvo = a.base_multa === 'area_terreno' ? 'terreno' : 'construído'
  return fmtNum(a.multa_upf_m2 || 0) + ' UPF/m² · ' + alvo
}

// ── ARTIGOS (modal: tem campos demais para caber numa linha) ──

function novoArtigoDaLei() {
  const l = parState.leis.find(x => x.id === parState.leiAberta)
  if (!l) return
  document.getElementById('art-titulo').textContent = 'Novo artigo'
  document.getElementById('art-lei').textContent = l.nome
  document.getElementById('art-id').value = ''
  document.getElementById('art-legislacao-id').value = l.id
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

/** @param {number} artigoId */
function editarArtigo(artigoId) {
  const l = parState.leis.find(x => x.id === parState.leiAberta)
  const a = l?.artigos.find(x => x.id === artigoId)
  if (!a) return
  document.getElementById('art-titulo').textContent = a.apelido || a.numero
  document.getElementById('art-lei').textContent = l.nome
  document.getElementById('art-id').value = a.id
  document.getElementById('art-legislacao-id').value = l.id
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

function trocarBaseMulta() {
  const base = document.getElementById('art-base').value
  document.getElementById('art-bloco-fixa').style.display = base === 'fixa' ? '' : 'none'
  document.getElementById('art-bloco-area').style.display =
    (base === 'area_construida' || base === 'area_terreno') ? '' : 'none'
}

/** @param {Array<number>} marcadas */
function renderIrregularidadesChecklist(marcadas) {
  document.getElementById('art-irregularidades').innerHTML = parState.irregularidades.map(i => `
    <label class="chk-item ${marcadas.includes(i.id) ? 'marcado' : ''}"
           onclick="setTimeout(()=>this.classList.toggle('marcado', this.querySelector('input').checked),0)">
      <input type="checkbox" value="${i.id}" ${marcadas.includes(i.id) ? 'checked' : ''}>
      <span class="desc">${esc(i.descricao)}<br><span class="cod">${esc(i.codigo)} · ${esc(i.gravidade)}</span></span>
    </label>`).join('') || '<div class="lista-vazia">Nenhuma irregularidade cadastrada.</div>'
}

async function salvarArtigo() {
  const irregularidades = [...document.querySelectorAll('#art-irregularidades input:checked')].map(i => Number(i.value))
  await postParametro('/api/legislacao/artigos', {
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
  }, 'm-artigo', recarregarLegislacao)
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
      <button class="acao-x" onclick="excluirUpf(${u.id})" title="Excluir">${ICO_LIXO}</button>
    </div>`).join('') || '<div class="lista-vazia">Nenhuma UPF cadastrada.</div>'
}

async function salvarUpf() {
  const exercicio = document.getElementById('novo-upf-ano').value
  const valor = document.getElementById('novo-upf-valor').value
  if (!exercicio || !valor) { toast('Informe o ano e o valor da UPF', 'err'); return }

  const d = await postParametro('/api/parametros/upf', {
    exercicio, valor,
    // A vigência começa em 1º de janeiro do exercício, que é a regra: a UPF
    // é anual. Decreto que muda no meio do ano é a exceção, e aí se edita.
    vigencia_inicio: exercicio + '-01-01',
    norma: document.getElementById('novo-upf-norma').value.trim() || null,
  }, null, carregarParametros)

  if (d) {
    document.getElementById('novo-upf-ano').value = ''
    document.getElementById('novo-upf-valor').value = ''
    document.getElementById('novo-upf-norma').value = ''
  }
}

/** @param {number} id */
function excluirUpf(id) {
  confirmarAcao({
    titulo: 'Excluir UPF',
    mensagem: 'Documentos já lavrados mantêm o valor de UPF congelado neles. Excluir?',
    perigo: true,
    onConfirm: () => excluirParametro('/api/parametros/upf/' + id),
  })
}

// ── FERIADOS: ANOS ───────────────────────────────────────────

/** Anos existentes, deduzidos das datas cadastradas. */
function anosDeFeriados() {
  const porAno = {}
  for (const f of parState.feriados) {
    const ano = f.data.slice(0, 4)
    porAno[ano] = (porAno[ano] || 0) + 1
  }
  return Object.entries(porAno).sort((a, b) => b[0].localeCompare(a[0]))
}

function renderAnosFeriados() {
  const anos = anosDeFeriados()
  document.getElementById('cont-feriados').textContent = parState.feriados.length
  document.getElementById('lista-anos-feriados').innerHTML = anos.map(([ano, n]) => `
    <div class="par-linha clicavel" onclick="abrirAnoFeriados('${ano}')">
      <div class="principal">
        <b>${ano}</b>
        <span>${n} feriado(s) cadastrado(s)</span>
      </div>
      <span class="seta">›</span>
    </div>`).join('') || '<div class="lista-vazia">Nenhum ano com feriados cadastrados.</div>'
}

/**
 * "Novo ano" só abre a sub-tela: o ano passa a existir quando o primeiro
 * feriado é gravado. Criar um registro vazio só para o ano aparecer na lista
 * seria uma linha sem significado no banco.
 */
function novoAnoFeriados() {
  const inp = document.getElementById('novo-ano-feriados')
  const ano = parseInt(inp.value, 10)
  if (!ano || ano < 1900 || ano > 2200) { toast('Informe um ano válido', 'err'); return }
  inp.value = ''
  abrirAnoFeriados(String(ano))
}

/** @param {string} ano */
function abrirAnoFeriados(ano) {
  parState.anoAberto = ano
  document.getElementById('fer-anos').style.display = 'none'
  document.getElementById('fer-lista').style.display = ''
  document.getElementById('fer-ano-titulo').textContent = ano

  // Trava a data ao ano aberto: sem isso é fácil cadastrar 2027 dentro de 2026.
  const data = document.getElementById('novo-feriado-data')
  data.min = ano + '-01-01'
  data.max = ano + '-12-31'
  data.value = ''
  atualizarDisplayData(data)
  document.getElementById('novo-feriado-nome').value = ''

  renderFeriadosDoAno()
}

function voltarAnosFeriados() {
  parState.anoAberto = null
  document.getElementById('fer-lista').style.display = 'none'
  document.getElementById('fer-anos').style.display = ''
  renderAnosFeriados()
}

function renderFeriadosDoAno() {
  const doAno = parState.feriados
    .filter(f => f.data.startsWith(parState.anoAberto))
    .sort((a, b) => a.data.localeCompare(b.data))

  document.getElementById('lista-feriados').innerHTML = doAno.map(f => `
    <div class="par-linha">
      <div class="principal">
        <b>${formatarDataBR(f.data)} — ${esc(f.nome)}</b>
        <span>${esc(f.tipo)}${f.recorrente ? ' · repete todo ano' : ''}</span>
      </div>
      <button class="acao-x" onclick="excluirFeriado(${f.id})" title="Excluir">${ICO_LIXO}</button>
    </div>`).join('') || '<div class="lista-vazia">Nenhum feriado neste ano.</div>'
}

async function salvarFeriado() {
  const data = document.getElementById('novo-feriado-data').value
  const nome = document.getElementById('novo-feriado-nome').value.trim()
  if (!data || !nome) { toast('Informe a data e o nome do feriado', 'err'); return }
  if (parState.anoAberto && !data.startsWith(parState.anoAberto)) {
    toast('A data precisa ser do ano ' + parState.anoAberto, 'err'); return
  }

  const d = await postParametro('/api/parametros/feriados', {
    data, nome,
    tipo: document.getElementById('novo-feriado-tipo').value,
    recorrente: document.getElementById('novo-feriado-recorrente').checked,
  }, null, async () => {
    await carregarParametros()
    if (parState.anoAberto) renderFeriadosDoAno()
  })

  if (d) {
    document.getElementById('novo-feriado-data').value = ''
    atualizarDisplayData(document.getElementById('novo-feriado-data'))
    document.getElementById('novo-feriado-nome').value = ''
    document.getElementById('novo-feriado-recorrente').checked = false
  }
}

/** @param {number} id */
function excluirFeriado(id) {
  confirmarAcao({
    titulo: 'Excluir feriado',
    mensagem: 'Prazos já calculados não mudam retroativamente. Excluir mesmo assim?',
    perigo: true,
    onConfirm: async () => {
      await excluirParametro('/api/parametros/feriados/' + id)
      if (parState.anoAberto) renderFeriadosDoAno()
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
  await postParametro('/api/parametros/geral', { valores }, null, async () => {})
}

// ── HELPERS COMUNS ───────────────────────────────────────────

const ICO_EDITAR = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
  <path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>`

const ICO_LIXO = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
  <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>`

/**
 * POST dos formulários desta tela. `modalId` nulo quando o cadastro é feito
 * na própria linha e não há janela para fechar.
 *
 * @returns {Object|null} corpo da resposta, ou null se falhou
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
    if (!r.ok) { toast(d.message || primeiroErroPar(d), 'err'); return null }
    toast(d.message || 'Gravado.')
    if (d.aviso) toast(d.aviso, 'err')
    if (modalId) fModalBtn(modalId)
    await aoTerminar()
    return d
  } catch (e) {
    console.error(e)
    toast('Falha de rede ao salvar', 'err')
    return null
  }
}

/** DELETE com recarga do painel. */
async function excluirParametro(url) {
  const r = await fetch(url, {
    method: 'DELETE',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
  })
  const d = await r.json()
  if (!r.ok) { toast(d.message || 'Não foi possível excluir', 'err'); return }
  toast(d.message)
  await carregarParametros()
}

function primeiroErroPar(d) {
  const e = d?.errors && Object.values(d.errors)[0]
  return Array.isArray(e) ? e[0] : 'Não foi possível concluir a operação'
}

// ── BRASÃO DO MUNICÍPIO ──────────────────────────────────────
// O sistema não traz brasão embutido. É esta tela que o torna replicável:
// instalar a mesma aplicação em outra prefeitura passa a ser trocar dois
// cadastros — brasão e nome da entidade — em vez de mexer no código.

/** Mostra o brasão em uso, ou o convite para enviar um. */
function renderBrasao() {
  const url = parState.geral.find(p => p.chave === 'brasao_url')?.valor
  const previa = document.getElementById('brasao-previa')
  const remover = document.getElementById('brasao-remover')
  if (!previa) return

  previa.innerHTML = url
    ? `<img src="${esc(url)}" alt="Brasão do município">`
    : '<span class="brasao-vazio">Nenhum brasão enviado</span>'
  if (remover) remover.hidden = !url
}

/** @param {HTMLInputElement} input */
async function enviarBrasao(input) {
  const arquivo = input.files?.[0]
  if (!arquivo) return

  const fd = new FormData()
  fd.append('brasao', arquivo)

  try {
    const r = await fetch('/api/parametros/brasao', {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      body: fd,
    })
    const d = await r.json()
    if (!r.ok) throw new Error(primeiroErroPar(d))

    toast(d.message)
    await carregarParametros()
    renderBrasao()
    // O sub-cabeçalho mostra o brasão: sem atualizá-lo, o antigo continua na
    // tela até alguém recarregar a página.
    trocarBrasaoNoSubcabecalho(d.url)
  } catch (e) {
    console.error(e)
    toast(e.message || 'Não foi possível enviar o brasão', 'err')
  } finally {
    input.value = ''   // permite reenviar o mesmo arquivo
  }
}

function removerBrasao() {
  confirmarAcao({
    titulo: 'Remover brasão',
    mensagem: 'A tela e os documentos passam a sair sem o símbolo do município.',
    textoBtn: 'Remover',
    perigo: true,
    onConfirm: async () => {
      await excluirParametro('/api/parametros/brasao')
      await carregarParametros()
      renderBrasao()
      trocarBrasaoNoSubcabecalho(null)
    },
  })
}

/** @param {string|null} url */
function trocarBrasaoNoSubcabecalho(url) {
  const cx = document.querySelector('.subcab-entidade')
  if (!cx) return
  let img = cx.querySelector('.subcab-brasao')

  if (!url) { img?.remove(); return }

  if (!img) {
    img = document.createElement('img')
    img.className = 'subcab-brasao'
    img.alt = ''
    cx.prepend(img)
  }
  img.src = url
}
