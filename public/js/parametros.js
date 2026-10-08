// ══════════════════════════════════════════════
// MÓDULO: PARÂMETROS DO SISTEMA (só administrador)
//
// Usuários, legislação, UPF, feriados, bairros e órgão.
//
// O padrão de gravação é o do AppPOSTURAS, igual em todas as abas:
//   - no topo, SÓ a busca e o "+ Novo …" — a busca filtra e nada mais;
//   - cada item da lista tem o próprio Editar, que abre os campos DENTRO da
//     linha, com Salvar e Cancelar; o "+ Novo" abre um cartão em branco no
//     topo da lista, no mesmo formato.
// Antes, tocar numa linha trazia os valores para os campos fixos do cabeçalho
// — e não dava para saber se aqueles campos estavam criando um item novo ou
// alterando o que se tinha tocado.
//
// Uma edição por vez (`parState.ed`): abrir outra fecha a anterior sem gravar.
// Onde há hierarquia (lei → artigos, ano → feriados) a lista do pai ocupa a
// tela e o detalhe abre com "← Voltar".
// ══════════════════════════════════════════════

/** Estado carregado de uma vez em /api/parametros. */
const parState = {
  carregado: false,
  usuarios: [],
  leis: [],
  upfs: [],
  feriados: [],
  bairros: [],
  geral: [],
  /** id da lei aberta no detalhe */   leiAberta: null,
  /** aba do detalhe da lei */          subLei: 'artigos',
  /** ano aberto na lista de feriados */ anoAberto: null,
  /** @type {{lista: string, id: (number|string)}|null} o item em edição; id 'novo' é o cartão em branco */
  ed: null,
}

function abrirParametros() {
  openModal('m-parametros')
  carregarParametros()
}

/** @param {string} nome */
function subParametros(nome) {
  document.querySelectorAll('#m-parametros > .modal > .sub-abas > button[data-sub]')
    .forEach(b => b.classList.toggle('at', b.dataset.sub === nome))
  document.querySelectorAll('.par-painel').forEach(p => p.classList.toggle('at', p.id === 'par-' + nome))
  // Trocar de aba abandona a edição aberta — ela ficaria escondida, viva, e
  // reapareceria ao voltar como se nada tivesse acontecido.
  if (parState.ed) { parState.ed = null; renderTudoPar() }

  // A TRILHA CARREGA SÓ QUANDO ABRE, e não junto de `carregarParametros`.
  // Ela é a única aba cujo conteúdo cresce sem parar — 244 linhas hoje,
  // milhares em um ano — e trazê-la na abertura de Parâmetros faria toda
  // visita à tela pagar por um dado que quase ninguém vai olhar.
  if (nome === 'trilha' && typeof carregarTrilha === 'function') { carregarTrilha() }
  // Cadastro municipal também carrega só quando abre: a lista de cargas é
  // consultada de novo enquanto uma planilha processa.
  if (nome === 'cadastro' && typeof carregarCargasDoCadastro === 'function') { carregarCargasDoCadastro() }
}

async function carregarParametros() {
  try {
    const r = await fetch('/api/parametros', { headers: { Accept: 'application/json' } })
    if (!r.ok) throw new Error('HTTP ' + r.status)
    const d = await r.json()
    parState.usuarios = d.usuarios
    parState.cargosExternos = d.cargos_externos
    parState.upfs = d.upfs
    parState.feriados = d.feriados
    parState.bairros = d.bairros
    parState.geral = d.geral
    parState.carregado = true
    renderUsuarios()
    renderUpfs()
    renderFeriados()
    renderBairros()
    renderGeral()
    renderBrasao()
    await recarregarLegislacao()
  } catch (e) {
    console.error(e)
    toast('Não foi possível carregar os parâmetros', 'err')
  }
}

function renderTudoPar() {
  if (!parState.carregado) return
  renderUsuarios(); renderLeis(); renderUpfs(); renderFeriados()
  renderBairros(); renderGeral()
}

// ── EDIÇÃO NA LINHA (o motor comum de todas as abas) ─────────

/** Qual render redesenha cada lista editável. */
const PAR_RENDER = {
  leis: () => renderLeis(), artigos: () => renderLeis(), textos: () => renderLeis(),
  upf: () => renderUpfs(), anos: () => renderFeriados(), feriados: () => renderFeriados(),
  bairros: () => renderBairros(), geral: () => renderGeral(),
}

/** Abre a edição de um item. @param {string} lista @param {number|string} id */
function parEditar(lista, id) {
  const anterior = parState.ed?.lista
  parState.ed = { lista, id }
  if (anterior && anterior !== lista) PAR_RENDER[anterior]?.()
  PAR_RENDER[lista]()
  parFocarEdicao()
}

/**
 * VISUALIZAR: clicar em qualquer item abre o mesmo cartão da edição, com os
 * campos travados e os botões Fechar e Editar. É o que deixa conferir um
 * parâmetro inteiro sem o risco de alterá-lo — a lista só mostra um resumo.
 *
 * @param {string} lista @param {number|string} id
 */
function parVer(lista, id) {
  // Clicar de novo no que já está aberto não faz nada (nem fecha a edição).
  if (parEditando(lista, id)) return
  const anterior = parState.ed?.lista
  parState.ed = { lista, id, ver: true }
  if (anterior && anterior !== lista) PAR_RENDER[anterior]?.()
  PAR_RENDER[lista]()
  setTimeout(() => document.querySelector('#m-parametros .par-linha.editando')?.scrollIntoView({ block: 'nearest' }), 0)
}

/** Abre o cartão em branco no topo da lista. @param {string} lista */
function parNovo(lista) {
  // A busca é limpa: o cartão novo entra no topo, e um filtro ativo poderia
  // escondê-lo — ou esconder a lista inteira, deixando o cartão sozinho.
  const busca = document.querySelector('.par-painel.at .par-busca input')
  if (busca) busca.value = ''
  parEditar(lista, 'novo')
}

function parCancelar() {
  const lista = parState.ed?.lista
  parState.ed = null
  // Desistir de uma lei NOVA fecha a janela dela: não há lei para mostrar.
  if (lista === 'leis' && parState.leiAberta === 'nova') parState.leiAberta = null
  if (lista) PAR_RENDER[lista]()
}

/** @returns {boolean} se este item é o que está em edição */
function parEditando(lista, id) { return !!parState.ed && parState.ed.lista === lista && parState.ed.id === id }

function parFocarEdicao() {
  setTimeout(() => {
    const el = document.querySelector('#m-parametros .par-linha.editando')
    el?.querySelector('input:not([type=checkbox]):not([type=hidden]), textarea, select')?.focus()
    el?.scrollIntoView({ block: 'nearest' })
  }, 0)
}

/** Valor de um campo do cartão em edição. Caixa de marcar devolve booleano. */
function parCampo(nome) {
  const el = document.querySelector(`#m-parametros .par-linha.editando [name="${nome}"]`)
  if (!el) return ''
  return el.type === 'checkbox' ? el.checked : el.value.trim()
}

/** Termo da busca de uma lista, já em minúsculas. */
function parBusca(id) { return (document.getElementById(id)?.value || '').trim().toLowerCase() }

function parContador(id, mostrados, total) {
  document.getElementById(id).textContent = mostrados === total ? total : `${mostrados}/${total}`
}

// Peças de HTML do cartão em edição. `onclick` com aspas simples dentro de
// template: os ids são números ou chaves sem aspas, então não há o que escapar.
const parId = id => typeof id === 'number' ? id : `'${id}'`

function parAcoes(lista, id, extra = '') {
  return `<div class="par-acoes">${extra}
    <button type="button" class="btn edit-verde sm" onclick="parEditar('${lista}', ${parId(id)})">${ICO_EDITAR}Editar</button>
    <button type="button" class="btn out-vermelho sm" onclick="parExcluir('${lista}', ${parId(id)})">Excluir</button>
  </div>`
}

/**
 * Cartão aberto: título, campos e os botões.
 *
 * EDITANDO: Cancelar e Salvar. VISUALIZANDO (`ver`): os campos vão dentro de
 * um <fieldset disabled> — o navegador trava todos de uma vez, inclusive os
 * botões internos do cartão — e os botões são Fechar e Editar.
 *
 * @param {{lista:string, id:(number|string), semFechar?:boolean}|null} [ver]
 *        por padrão, o item que está em visualização (parVer)
 */
function parFormLinha(titulo, corpo, aoSalvar, ver = parState.ed?.ver ? parState.ed : null) {
  if (ver) {
    return `<div class="par-linha editando vendo">
      <div class="ed-tit">${titulo.replace(/^Editando/, 'Visualizando')}</div>
      <fieldset class="ed-corpo" disabled>${corpo}</fieldset>
      <div class="ed-botoes">
        ${ver.semFechar ? '' : '<button type="button" class="btn sm" onclick="parCancelar()">Fechar</button>'}
        <button type="button" class="btn edit-verde sm" onclick="parEditar('${ver.lista}', ${parId(ver.id)})">${ICO_EDITAR}Editar</button>
      </div>
    </div>`
  }
  return `<div class="par-linha editando">
    <div class="ed-tit">${titulo}</div>
    <fieldset class="ed-corpo">${corpo}</fieldset>
    <div class="ed-botoes">
      <button type="button" class="btn sm" onclick="parCancelar()">Cancelar</button>
      <button type="button" class="btn primary sm" onclick="${aoSalvar}">Salvar</button>
    </div>
  </div>`
}

/** O bloco de texto de uma linha da lista: clicar nele abre a visualização. */
const parPrincipal = (lista, id) => `<div class="principal clicavel" title="Clique para visualizar" onclick="parVer('${lista}', ${parId(id)})">`


const parRot = (rot, html, estilo = '') => `<label class="ed-campo"${estilo ? ` style="${estilo}"` : ''}><span>${rot}</span>${html}</label>`
const parInp = (nome, valor, extra = '') => `<input name="${nome}" value="${esc(valor ?? '')}" ${extra}>`
const parTxt = (nome, valor, linhas = 2) => `<textarea name="${nome}" rows="${linhas}">${esc(valor ?? '')}</textarea>`
const parSel = (nome, valor, opcoes, extra = '') =>
  `<select name="${nome}" ${extra}>${opcoes.map(([k, r]) => `<option value="${k}"${k === valor ? ' selected' : ''}>${r}</option>`).join('')}</select>`
const parChk = (nome, marcado, rot) =>
  `<label class="lembrar"><input type="checkbox" name="${nome}"${marcado ? ' checked' : ''}> ${rot}</label>`

/** Grava o cartão aberto; fecha a edição só se o servidor aceitou. */
async function parGravar(url, corpo, aoTerminar) {
  const d = await postParametro(url, corpo, null, async () => { parState.ed = null; await aoTerminar() })
  return d
}

/** Excluir, com a confirmação de cada lista. */
function parExcluir(lista, id) {
  ({ leis: excluirLei, artigos: excluirArtigo, upf: excluirUpf, feriados: excluirFeriado,
     bairros: excluirBairro })[lista](id)
}

// Enter salva e Esc cancela o cartão aberto. Em textarea o Enter é quebra de linha.
document.addEventListener('keydown', e => {
  const cartao = e.target.closest?.('#m-parametros .par-linha.editando')
  if (!cartao) return
  if (e.key === 'Escape') { e.stopPropagation(); parCancelar() }
  if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
    e.preventDefault()
    cartao.querySelector('.ed-botoes .btn.primary')?.click()
  }
})

// ── USUÁRIOS ─────────────────────────────────────────────────

/**
 * Cartão de usuário — desenho do painel administrativo do AppPOSTURAS:
 * avatar com a inicial, nome, e uma linha de identificação com login,
 * matrícula, situação e perfil. O "Editar" abre a janela do usuário (e não a
 * linha), porque ela tem senha e permissões — campos demais para um cartão.
 */
function renderUsuarios() {
  const termo = parBusca('busca-usuarios')
  const lista = parState.usuarios.filter(u => !termo
    || [u.name, u.email, u.matricula].filter(Boolean).join(' ').toLowerCase().includes(termo))
  parContador('cont-usuarios', lista.length, parState.usuarios.length)

  document.getElementById('lista-usuarios').innerHTML = lista.map(u => {
    const inicial = (u.name || '?').trim().charAt(0).toUpperCase()
    // Sem e-mail, o login é a matrícula — e ela já aparece logo ao lado.
    const login = u.email ? '@' + u.email.split('@')[0] : ''
    const admin = u.perfil === 'admin'

    return `
      <div class="par-card">
        <div class="par-card-ident clicavel" title="Clique para visualizar" onclick="verUsuario(${u.id})">
          <div class="par-av${admin ? ' adm' : ''}">${esc(inicial)}</div>
          <div class="par-card-txt">
            <div class="par-card-nome">${esc(u.name)}</div>
            <div class="par-card-meta">
              ${[login, u.matricula].filter(Boolean).map(esc).join(' · ')} ·
              <span class="pil ${u.ativo ? 'pil-ok' : 'pil-off'}">${u.ativo ? 'Ativo' : 'Inativo'}</span>
              <span class="badge ${admin ? 'bd-cx' : 'bd-in'}">${esc(u.perfil_rotulo)}</span>
              ${u.tipo_usuario ? `<span class="par-card-cargo">${esc(u.tipo_usuario)}</span>` : ''}
            </div>
          </div>
        </div>
        <button class="btn edit-verde sm" onclick="editarUsuario(${u.id})">${ICO_EDITAR}Editar</button>
      </div>`
  }).join('') || `<div class="lista-vazia">${termo ? 'Nenhum usuário encontrado.' : 'Nenhum usuário cadastrado.'}</div>`
}

function novoUsuario() {
  travarUsuario(false)
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
  ajustarPerfilDoCargo()
  openModal('m-usuario')
}

/**
 * Cargo externo trava o perfil em Visualizador. O servidor grava assim de
 * qualquer jeito (ParametroController::salvarUsuario); aqui é para a tela não
 * oferecer uma escolha que seria ignorada.
 */
function ajustarPerfilDoCargo() {
  const externos = parState.cargosExternos || ['topografo', 'arquiteto', 'contribuinte']
  const externo = externos.includes(document.getElementById('us-cargo').value)
  const perfil = document.getElementById('us-perfil')
  if (externo) perfil.value = 'viewer'
  perfil.disabled = externo
  document.getElementById('us-externo-obs').hidden = !externo
}

/**
 * A janela do usuário só para LEITURA: os mesmos campos, travados, e o botão
 * Editar no lugar do Salvar. @param {number} id
 */
function verUsuario(id) {
  editarUsuario(id)
  travarUsuario(true)
}

/** Sai da visualização para a edição, na mesma janela. */
function liberarUsuario() {
  travarUsuario(false)
  ajustarPerfilDoCargo()   // o perfil de cargo externo continua travado
  document.getElementById('us-nome').focus()
}

/** @param {boolean} travar */
function travarUsuario(travar) {
  document.querySelectorAll('#m-usuario input, #m-usuario select').forEach(el => { el.disabled = travar })
  document.getElementById('us-salvar').hidden = travar
  document.getElementById('us-editar').hidden = !travar
  document.getElementById('us-cancelar').textContent = travar ? 'Fechar' : 'Cancelar'
}

/** @param {number} id */
function editarUsuario(id) {
  const u = parState.usuarios.find(x => x.id === id)
  if (!u) return
  travarUsuario(false)
  document.getElementById('us-titulo').textContent = u.name
  document.getElementById('us-id').value = u.id
  document.getElementById('us-nome').value = u.name
  document.getElementById('us-email').value = u.email || ''
  document.getElementById('us-matricula').value = u.matricula || ''
  document.getElementById('us-cargo').value = u.tipo_usuario || 'agente'
  document.getElementById('us-perfil').value = u.perfil
  document.getElementById('us-ativo').checked = !!u.ativo
  document.getElementById('us-curador').checked = !!u.curador_cadastral
  document.getElementById('us-senha').value = ''
  document.getElementById('us-senha2').value = ''
  ajustarPerfilDoCargo()
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

// ── LEGISLAÇÃO ───────────────────────────────────────────────

async function recarregarLegislacao() {
  const r = await fetch('/api/legislacao', { headers: { Accept: 'application/json' } })
  const d = await r.json()
  parState.leis = d.leis
  if (parState.leiAberta && !parState.leis.some(l => l.id === parState.leiAberta)) parState.leiAberta = null
  renderLeis()
}

/**
 * A aba Legislação desenha a lista de leis OU a JANELA da lei aberta.
 *
 * A janela ocupa o lugar da lista, dentro do mesmo modal, com "← Voltar" e
 * três abas: Dados gerais, Textos de ciência e Artigos. Ver, editar e
 * cadastrar uma lei acontecem nela — antes, editar abria os campos no meio da
 * lista, e os artigos e os textos ficavam noutra tela.
 */
function renderLeis() {
  const aberta = parState.leiAberta === 'nova' ? {} : parState.leis.find(l => l.id === parState.leiAberta)
  document.getElementById('leg-topo-lista').style.display = aberta ? 'none' : ''
  document.getElementById('leg-topo-detalhe').style.display = aberta ? '' : 'none'
  if (aberta) { renderDetalheLei(aberta); return }

  const termo = parBusca('lei-busca')
  const leis = parState.leis.filter(l => !termo || (l.numero + ' ' + l.nome).toLowerCase().includes(termo))
  parContador('cont-leis', leis.length, parState.leis.length)

  document.getElementById('lista-leis').innerHTML = leis.map(l => `
      <div class="par-linha${l.ativa ? '' : ' par-linha-inativa'}">
        <div class="principal clicavel" title="Clique para visualizar" onclick="abrirLei(${l.id})">
          <b>${esc(l.numero)} · ${esc(l.nome)}</b>
          <span>${l.artigos.length} artigo(s) · defesa em ${esc(l.prazo_defesa_dias)} dia(s) úteis${l.ativa ? '' : ' · inativa'}</span>
        </div>
        <div class="par-acoes">
          <button type="button" class="btn sm" onclick="abrirLei(${l.id}, 'artigos')">Artigos ›</button>
          <button type="button" class="btn edit-verde sm" onclick="editarLei(${l.id})">${ICO_EDITAR}Editar</button>
          <button type="button" class="btn out-vermelho sm" onclick="excluirLei(${l.id})">Excluir</button>
        </div>
      </div>`).join('')
    || `<div class="lista-vazia">${termo ? 'Nenhuma lei encontrada.' : 'Nenhuma lei cadastrada.'}</div>`
}

/**
 * Os dados gerais da lei, na aba dela.
 *
 * @param {Object} l lei ({} para nova)
 * @param {boolean} ver  só leitura, com o botão Editar
 */
function formLei(l, ver = false) {
  return parFormLinha(l.id ? 'Editando lei' : 'Nova lei', `
    <div class="cad-row">
      ${parRot('Número', parInp('numero', l.numero, 'class="mono" maxlength="40"'), 'max-width:190px')}
      ${parRot('Nome', parInp('nome', l.nome, 'maxlength="160"'), 'flex:2')}
      ${parRot('Ano', parInp('ano', l.ano ?? new Date().getFullYear(), 'type="number" min="1900" max="2100"'), 'max-width:110px')}
    </div>
    <div class="cad-row">${parRot('Ementa', parTxt('ementa', l.ementa))}</div>
    <div class="cad-row">
      ${parRot('Prazo de defesa (dias úteis)', parInp('prazo_defesa_dias', l.prazo_defesa_dias ?? 5, 'type="number" min="1" max="120"'))}
      ${parRot('Prazo de cumprimento sugerido (dias corridos)', parInp('prazo_cumprimento_dias', l.prazo_cumprimento_dias ?? 10, 'type="number" min="0" max="365"'))}
      ${parChk('ativa', l.ativa ?? true, 'Lei ativa')}
    </div>`, 'salvarLei()', ver ? { lista: 'leis', id: l.id, semFechar: true } : null)
}

/** O corpo inteiro da lei vai junto: o servidor grava o que receber. */
function corpoDaLei(l) {
  return {
    id: l.id ?? null, numero: l.numero, nome: l.nome, ano: l.ano || null, ementa: l.ementa || null,
    prazo_defesa_dias: l.prazo_defesa_dias, prazo_cumprimento_dias: l.prazo_cumprimento_dias,
    ciencia_notificacao: l.ciencia_notificacao || null, ciencia_auto: l.ciencia_auto || null,
    ativa: !!l.ativa,
  }
}

async function salvarLei() {
  const nova = parState.leiAberta === 'nova'
  const atual = parState.leis.find(l => l.id === parState.ed?.id) || {}
  const numero = parCampo('numero'), nome = parCampo('nome')
  if (!numero || !nome) { toast('Informe o número e o nome da lei', 'err'); return }
  const d = await parGravar('/api/legislacao', corpoDaLei({
    ...atual, numero, nome,
    ano: parCampo('ano'), ementa: parCampo('ementa'),
    prazo_defesa_dias: parCampo('prazo_defesa_dias'),
    prazo_cumprimento_dias: parCampo('prazo_cumprimento_dias'),
    ativa: parCampo('ativa'),
  }), recarregarLegislacao)
  // Lei recém-criada: a janela continua aberta nela, agora com as abas de
  // textos e de artigos liberadas — é o passo seguinte de quem cadastra.
  if (d?.id && nova) abrirLei(d.id)
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

/**
 * Abre a janela da lei. Sem aba, cai nos Dados gerais, em visualização.
 *
 * @param {number} id
 * @param {'dados'|'textos'|'artigos'} [aba]
 */
function abrirLei(id, aba = 'dados') {
  parState.leiAberta = id
  parState.subLei = aba
  parState.ed = null
  const busca = document.getElementById('busca-artigos')
  if (busca) busca.value = ''
  renderLeis()
}

/** Abre a janela da lei já com os dados gerais em edição. @param {number} id */
function editarLei(id) {
  abrirLei(id)
  parEditar('leis', id)
}

/** A janela de uma lei que ainda não existe: só os dados gerais, em edição. */
function novaLei() {
  parState.leiAberta = 'nova'
  parState.subLei = 'dados'
  parState.ed = { lista: 'leis', id: 'novo' }
  renderLeis()
  parFocarEdicao()
}

function voltarLeis() {
  parState.leiAberta = null
  parState.ed = null
  renderLeis()
}

/** @param {'dados'|'textos'|'artigos'} nome */
function subLei(nome) {
  if (parState.leiAberta === 'nova' && nome !== 'dados') {
    toast('Salve os dados gerais da lei antes de cadastrar os textos e os artigos.', 'aviso')
    return
  }
  parState.subLei = nome
  parState.ed = null
  renderLeis()
}

function renderDetalheLei(l) {
  const nova = !l.id
  document.getElementById('leg-detalhe-titulo').textContent = nova ? 'Nova lei' : l.numero + ' · ' + l.nome
  document.querySelectorAll('#leg-topo-detalhe .sub-abas button').forEach(b => {
    b.classList.toggle('at', b.dataset.leg === parState.subLei)
    // Lei nova: textos e artigos só depois de salvar os dados gerais.
    b.classList.toggle('inativa', nova && b.dataset.leg !== 'dados')
  })
  document.getElementById('leg-busca-artigos').style.display = parState.subLei === 'artigos' ? '' : 'none'

  if (parState.subLei === 'dados') {
    // Em edição só quando se pediu (Editar, ou lei nova); senão, leitura.
    const editando = parEditando('leis', nova ? 'novo' : l.id) && !parState.ed.ver
    document.getElementById('lista-leis').innerHTML = formLei(l, !editando)
    return
  }
  if (parState.subLei === 'textos') { renderTextosDaLei(l); return }

  const termo = parBusca('busca-artigos')
  const artigos = l.artigos.filter(a => !termo
    || [a.numero, a.apelido, ...(a.termos || [])].join(' ').toLowerCase().includes(termo))
  document.getElementById('lista-leis').innerHTML =
    (parEditando('artigos', 'novo') ? formArtigo({}) : '')
    + (artigos.map(a => parEditando('artigos', a.id) ? formArtigo(a) : `
      <div class="par-linha${a.ativo ? '' : ' par-linha-inativa'}">
        ${parPrincipal('artigos', a.id)}
          <b>${esc(a.apelido || a.numero)}</b>
          <span>${esc(a.numero)} · ${rotuloBaseMulta(a)}${a.ativo ? '' : ' · inativo'}${a.documentos_rotulo ? ` · <span class="art-embargo">${esc(a.documentos_rotulo)}</span>` : ''} · ${a.termos?.length
            ? 'busca: ' + a.termos.map(t => esc(t)).join(', ')
            : '<span class="art-sem-termos">sem termos de busca</span>'}</span>
        </div>
        ${parAcoes('artigos', a.id)}
      </div>`).join('')
    || `<div class="lista-vazia">${termo ? 'Nenhum artigo encontrado.' : 'Nenhum artigo cadastrado nesta lei.'}</div>`)
}

/** O resumo da multa vem pronto do servidor (Artigo::rotuloMulta). @param {Object} a */
function rotuloBaseMulta(a) {
  return esc(a.multa_rotulo || 'sem multa')
}

// ── ARTIGOS ──────────────────────────────────────────────────

/** @param {Object} a artigo ({} para novo) */
function formArtigo(a) {
  const base = a.base_multa || 'fixa'
  const porArea = base === 'por_m2' || base === 'faixas'
  const comMinMax = base === 'por_m2' || base === 'intervalo'
  // Artigo novo nasce para notificação e auto de infração — o caso comum.
  const docs = a.documentos || ['notificacao', 'auto_infracao']
  // EM GRUPOS: o que é do mesmo assunto fica na mesma moldura, e o que cabe
  // lado a lado vai lado a lado — o cartão tinha virado uma coluna comprida.
  const grupo = (titulo, corpo) => `<div class="ed-grupo"><div class="ed-grupo-tit">${titulo}</div>${corpo}</div>`
  return parFormLinha(a.id ? 'Editando artigo' : 'Novo artigo', grupo('Identificação', `
      <div class="cad-row">
        ${parRot('Número', parInp('numero', a.numero, 'class="mono" maxlength="30" placeholder="Art. 42, par. 1, II"'), 'max-width:220px')}
        ${parRot('Apelido (rótulo curto na lista)', parInp('apelido', a.apelido, 'maxlength="60"'), 'flex:2')}
        ${parChk('ativo', a.ativo ?? true, 'Artigo ativo')}
      </div>
      <div class="cad-row">${parRot('Texto da lei', parTxt('conduta', a.conduta, 4))}</div>`)
    + grupo('Multa', `
      <div class="cad-row">
        ${parRot('Como a multa é calculada', parSel('base_multa', base, [
          ['fixa', 'Valor fixo'], ['por_m2', 'Por m²'], ['faixas', 'Por faixa de área'],
          ['intervalo', 'Entre mínimo e máximo (a critério do fiscal)'],
          ['multiplo_alvara', 'Múltiplo do valor do alvará'], ['sem_multa', 'Sem multa (só notificação/embargo)'],
        ], 'onchange="trocarBaseMulta(this)"'), 'flex:2')}
        <span class="art-bloco-qual-area" style="display:${porArea ? 'contents' : 'none'}">
          ${parRot('Sobre qual área', parSel('multa_area', a.multa_area || 'construida', [
            ['construida', 'Área construída (obra)'], ['terreno', 'Área do terreno'],
            ['construida_ou_terreno', 'Obra, se houver; senão, o terreno'],
          ]), 'flex:2')}</span>
        <span class="art-bloco-fixa" style="display:${base === 'fixa' ? 'contents' : 'none'}">
          ${parRot('Multa (UPF)', parInp('multa_upf', a.multa_upf, 'type="number" min="0" step="0.01"'))}</span>
        <span class="art-bloco-alvara" style="display:${base === 'multiplo_alvara' ? 'contents' : 'none'}">
          ${parRot('De (× o alvará)', parInp('multa_mult_min', a.multa_mult_min ?? 1, 'type="number" min="0.01" step="0.01"'))}
          ${parRot('Até (× o alvará)', parInp('multa_mult_max', a.multa_mult_max ?? 1, 'type="number" min="0.01" step="0.01"'))}</span>
        <span class="art-bloco-m2" style="display:${base === 'por_m2' ? 'contents' : 'none'}">
          ${parRot('UPF por m²', parInp('multa_upf_m2', a.multa_upf_m2, 'type="number" min="0" step="0.0001"'))}</span>
        <span class="art-bloco-minmax" style="display:${comMinMax ? 'contents' : 'none'}">
          ${parRot('Mínimo (UPF)', parInp('multa_min_upf', a.multa_min_upf, 'type="number" min="0" step="0.01"'))}
          ${parRot('Máximo (UPF)', parInp('multa_max_upf', a.multa_max_upf, 'type="number" min="0" step="0.01"'))}</span>
        <span class="art-bloco-dobra" style="display:${base === 'sem_multa' ? 'none' : 'contents'}">
          ${parChk('multa_dobra_reincidencia', a.multa_dobra_reincidencia, 'Dobra na reincidência')}</span>
      </div>
      <div class="ed-campo art-bloco-faixas" style="display:${base === 'faixas' ? '' : 'none'}"><span>Faixas de área — valor da multa em cada uma</span>
        <div class="art-faixas">
          ${faixasDoArtigo(a).map(linhaDeFaixa).join('')}
          <button type="button" class="btn sec sm art-faixa-mais" onclick="adicionarFaixa(this)">+ faixa</button>
        </div>
        <small class="art-termos-dica">O limite inclui o próprio número: "até 60" vale para 60 m². A última faixa
          fica aberta e cobre tudo acima do último limite.</small>
      </div>
      <div class="art-bloco-alvara-dica" style="display:${base === 'multiplo_alvara' ? '' : 'none'}">
        <small class="art-termos-dica">Iguais (3 e 3) = multiplicador fixo. Diferentes (1 e 10) = o fiscal informa o
          multiplicador no auto, dentro do intervalo. O valor do alvará é informado em reais na peça.</small>
      </div>`)
    + '<div class="ed-grupos-lado">' + grupo('Documentos em que se aplica', `
      <div class="cad-row art-docs">
        ${ARTIGO_DOCUMENTOS.map(([tipo, rotulo]) => parChk('doc_' + tipo, docs.includes(tipo), rotulo)).join('')}
      </div>
      <div class="cad-row">
        ${parRot('Prazo sugerido na notificação (dias)', parInp('prazo_notificacao_dias', a.prazo_notificacao_dias, 'type="number" min="1" max="365" placeholder="o da lei"'), 'max-width:260px')}
      </div>`)
    + grupo('Busca em campo', `
      <div class="ed-campo"><span>Termos de busca</span>
        <div class="art-termos" onclick="this.querySelector('input')?.focus()">
          ${(a.termos || []).map(etiquetaDeTermo).join('')}
          <input type="text" class="art-termo-novo" maxlength="60" autocomplete="off"
                 placeholder="escavação, sem alvará… (Enter adiciona)"
                 onkeydown="teclaNoTermo(event)" onblur="adicionarTermo(this)">
        </div>
        <small class="art-termos-dica">Como o fiscal chama o problema em campo. Na vistoria, digitar
          um destes termos mostra este artigo.</small>
      </div>`) + '</div>', 'salvarArtigo()')
}

/** @param {string} t */
function etiquetaDeTermo(t) {
  return `<span class="art-termo" data-termo="${esc(t)}">${esc(t)}<button type="button"
    title="Tirar" onclick="event.stopPropagation(); this.parentElement.remove()">&times;</button></span>`
}

/**
 * Enter ou vírgula põem o termo; Backspace no campo vazio tira o último.
 * O `stopPropagation` impede o Enter de chegar ao atalho "Enter salva" do
 * cartão — aqui ele só fecha a etiqueta.
 * @param {KeyboardEvent} e
 */
function teclaNoTermo(e) {
  const inp = /** @type {HTMLInputElement} */ (e.target)
  if (e.key === 'Enter' || e.key === ',') {
    e.preventDefault(); e.stopPropagation()
    adicionarTermo(inp)
  } else if (e.key === 'Backspace' && !inp.value) {
    inp.parentElement.querySelector('.art-termo:last-of-type')?.remove()
  }
}

/** @param {HTMLInputElement} inp */
function adicionarTermo(inp) {
  const t = inp.value.replace(/\s+/g, ' ').trim().slice(0, 60)
  inp.value = ''
  if (!t) return
  const caixa = inp.parentElement
  const igual = [...caixa.querySelectorAll('.art-termo')]
    .some(el => el.dataset.termo.toLowerCase() === t.toLowerCase())
  if (!igual) inp.insertAdjacentHTML('beforebegin', etiquetaDeTermo(t))
}

/** Os termos do cartão aberto, incluindo o que ficou digitado sem Enter. */
function termosDoCartao() {
  const caixa = document.querySelector('#m-parametros .par-linha.editando .art-termos')
  if (!caixa) return []
  const inp = caixa.querySelector('input')
  if (inp) adicionarTermo(inp)
  return [...caixa.querySelectorAll('.art-termo')].map(el => el.dataset.termo)
}

/**
 * As peças em que um artigo pode entrar (Artigo::DOCUMENTOS). Marcadas uma a
 * uma: é isso que decide quais artigos cada documento oferece.
 */
const ARTIGO_DOCUMENTOS = [
  ['notificacao', 'Notificação'], ['notificacao_embargo', 'Notificação de Embargo'],
  ['auto_embargo', 'Auto de Embargo'], ['auto_infracao', 'Auto de Infração'],
]

/** Mostra só os campos de valor da base escolhida. @param {HTMLSelectElement} sel */
function trocarBaseMulta(sel) {
  const cartao = sel.closest('.par-linha')
  const v = sel.value
  const mostra = (classe, sim, como = '') => { cartao.querySelector(classe).style.display = sim ? como : 'none' }
  mostra('.art-bloco-fixa', v === 'fixa', 'contents')
  mostra('.art-bloco-qual-area', v === 'por_m2' || v === 'faixas', 'contents')
  mostra('.art-bloco-alvara', v === 'multiplo_alvara', 'contents')
  mostra('.art-bloco-alvara-dica', v === 'multiplo_alvara')
  mostra('.art-bloco-m2', v === 'por_m2', 'contents')
  mostra('.art-bloco-minmax', v === 'por_m2' || v === 'intervalo', 'contents')
  mostra('.art-bloco-dobra', v !== 'sem_multa', 'contents')
  mostra('.art-bloco-faixas', v === 'faixas')
}

/**
 * As faixas do artigo para o editor. Artigo sem faixas começa com duas: uma
 * com limite e a aberta — o mínimo que faz sentido.
 * @param {Object} a
 */
function faixasDoArtigo(a) {
  return a.multa_faixas?.length ? a.multa_faixas : [{ ate_m2: '', upf: '' }, { ate_m2: null, upf: '' }]
}

/** Uma linha do editor. `ate_m2` nulo é a faixa aberta, sempre a última. @param {Object} fx */
function linhaDeFaixa(fx) {
  const valor = `<input type="number" class="fx-upf" min="0" step="0.01" value="${esc(String(fx.upf ?? ''))}" placeholder="0,00"> <span>UPF</span>`
  return fx.ate_m2 === null
    ? `<div class="art-faixa fx-aberta"><span class="fx-rot">acima do último limite</span><span>=</span>${valor}</div>`
    : `<div class="art-faixa"><span class="fx-rot">até</span>
        <input type="number" class="fx-ate" min="0" step="0.01" value="${esc(String(fx.ate_m2 ?? ''))}" placeholder="0"> <span>m² =</span>${valor}
        <button type="button" class="fx-x" title="Tirar a faixa" onclick="this.parentElement.remove()">&times;</button></div>`
}

/** Nova faixa com limite, logo antes da aberta. @param {HTMLElement} btn */
function adicionarFaixa(btn) {
  const caixa = btn.closest('.art-faixas')
  if (caixa.querySelectorAll('.art-faixa').length >= 12) { toast('No máximo 12 faixas', 'err'); return }
  caixa.querySelector('.fx-aberta').insertAdjacentHTML('beforebegin', linhaDeFaixa({ ate_m2: '', upf: '' }))
  caixa.querySelector('.fx-aberta').previousElementSibling.querySelector('.fx-ate').focus()
}

/** As faixas do cartão aberto, na ordem da tela. A conferência é do servidor. */
function faixasDoCartao() {
  return [...document.querySelectorAll('#m-parametros .par-linha.editando .art-faixa')].map(el => ({
    ate_m2: el.classList.contains('fx-aberta') ? null : (el.querySelector('.fx-ate').value || null),
    upf: el.querySelector('.fx-upf').value || 0,
  }))
}

async function salvarArtigo() {
  const numero = parCampo('numero')
  if (!numero) { toast('Informe o número do artigo', 'err'); return }
  const base = parCampo('base_multa')
  if (base === 'faixas' && faixasDoCartao().slice(0, -1).some(fx => !fx.ate_m2)) {
    toast('Preencha o limite (m²) de cada faixa', 'err'); return
  }
  if (!ARTIGO_DOCUMENTOS.some(([tipo]) => parCampo('doc_' + tipo))) {
    toast('Marque ao menos um documento em que o artigo se aplica', 'err'); return
  }
  await parGravar('/api/legislacao/artigos', {
    id: parState.ed.id === 'novo' ? null : parState.ed.id,
    legislacao_id: parState.leiAberta,
    numero,
    apelido: parCampo('apelido') || null,
    conduta: parCampo('conduta') || null,
    base_multa: parCampo('base_multa'),
    multa_upf: parCampo('multa_upf') || null,
    multa_upf_m2: parCampo('multa_upf_m2') || null,
    multa_min_upf: parCampo('multa_min_upf') || null,
    multa_max_upf: parCampo('multa_max_upf') || null,
    multa_area: base === 'por_m2' || base === 'faixas' ? parCampo('multa_area') : null,
    multa_faixas: base === 'faixas' ? faixasDoCartao() : null,
    multa_mult_min: base === 'multiplo_alvara' ? parCampo('multa_mult_min') || null : null,
    multa_mult_max: base === 'multiplo_alvara' ? parCampo('multa_mult_max') || null : null,
    ativo: parCampo('ativo'),
    documentos: ARTIGO_DOCUMENTOS.map(([tipo]) => tipo).filter(tipo => parCampo('doc_' + tipo)),
    prazo_notificacao_dias: parCampo('prazo_notificacao_dias') || null,
    multa_dobra_reincidencia: base !== 'sem_multa' && !!parCampo('multa_dobra_reincidencia'),
    termos: termosDoCartao(),
  }, recarregarLegislacao)
}

/** @param {number} id */
function excluirArtigo(id) {
  const a = parState.leis.find(l => l.id === parState.leiAberta)?.artigos.find(x => x.id === id)
  confirmarAcao({
    titulo: 'Excluir artigo',
    mensagem: `"${a?.apelido || a?.numero || 'Artigo'}" sai da lei. Documentos já lavrados guardam cópia da redação e não mudam.`,
    textoBtn: 'Excluir',
    perigo: true,
    onConfirm: async () => {
      await excluirParametro('/api/legislacao/artigos/' + id)
      await recarregarLegislacao()
    },
  })
}

// ── TEXTOS DE CIÊNCIA ────────────────────────────────────────

/**
 * Marcadores que o sistema troca na hora de emitir (Legislacao::ciencia).
 * Texto sem marcador não ganha quadro de dica — ele só aparece onde ajuda.
 */
const MARCADORES = {
  ciencia_notificacao: [['{prazo}', 'vira "no prazo de N dias" ou "de imediato", conforme o prazo do documento']],
  ciencia_auto: [],
}

const TEXTOS_DA_LEI = [
  ['ciencia_notificacao', 'Ciência da notificação'],
  ['ciencia_auto', 'Ciência do auto de infração'],
]

function renderTextosDaLei(l) {
  document.getElementById('lista-leis').innerHTML = TEXTOS_DA_LEI.map(([chave, rotulo]) =>
    parEditando('textos', chave)
      ? parFormLinha(rotulo, `<div class="cad-row">${parTxt('valor', l[chave], 8)}</div>${dicaMarcadores(MARCADORES[chave])}`,
          `salvarTextoDaLei('${chave}')`)
      : `<div class="par-linha" style="align-items:flex-start">
          <div class="principal"><b>${rotulo}</b>
            <span class="texto-longo">${l[chave] ? esc(l[chave]) : '<em>— vazio —</em>'}</span></div>
          <div class="par-acoes"><button type="button" class="btn edit-verde sm" onclick="parEditar('textos', '${chave}')">${ICO_EDITAR}Editar</button></div>
        </div>`).join('')
}

/** Quadro cinza com os marcadores do texto; clicar insere no cursor. */
function dicaMarcadores(marcadores) {
  if (!marcadores?.length) return ''
  return `<div class="dica-tags"><b>Marcadores deste texto</b> — clique para inserir onde está o cursor. Na emissão, o sistema troca pelo valor do documento.
    <div class="tags">${marcadores.map(([m, desc]) => `
      <button type="button" class="tag" onmousedown="event.preventDefault()" onclick="inserirMarcador('${m}')">${m}</button>
      <span class="tag-desc">${esc(desc)}</span>`).join('')}</div></div>`
}

/** @param {string} m */
function inserirMarcador(m) {
  const ta = document.querySelector('#m-parametros .par-linha.editando textarea')
  if (!ta) return
  const ini = ta.selectionStart ?? ta.value.length, fim = ta.selectionEnd ?? ini
  ta.value = ta.value.slice(0, ini) + m + ta.value.slice(fim)
  ta.focus()
  ta.selectionStart = ta.selectionEnd = ini + m.length
}

/** @param {string} chave */
async function salvarTextoDaLei(chave) {
  const l = parState.leis.find(x => x.id === parState.leiAberta)
  if (!l) return
  await parGravar('/api/legislacao', corpoDaLei({ ...l, [chave]: parCampo('valor') }), recarregarLegislacao)
}

// ── UPF ──────────────────────────────────────────────────────

function renderUpfs() {
  const termo = parBusca('busca-upf')
  const lista = parState.upfs.filter(u => !termo || (u.exercicio + ' ' + (u.norma || '')).toLowerCase().includes(termo))
  parContador('cont-upf', lista.length, parState.upfs.length)
  document.getElementById('lista-upf').innerHTML =
    (parEditando('upf', 'novo') ? formUpf({}) : '')
    + (lista.map(u => parEditando('upf', u.id) ? formUpf(u) : `
      <div class="par-linha">
        ${parPrincipal('upf', u.id)}
          <b>${u.exercicio} · ${fmtNum(u.valor)}</b>
          <span>Vigente desde ${formatarDataBR(u.vigencia_inicio)}${u.norma ? ' · ' + esc(u.norma) : ''}</span>
        </div>
        ${parAcoes('upf', u.id)}
      </div>`).join('')
    || `<div class="lista-vazia">${termo ? 'Nenhuma UPF encontrada.' : 'Nenhuma UPF cadastrada.'}</div>`)
}

/** @param {Object} u UPF ({} para nova) */
function formUpf(u) {
  return parFormLinha(u.id ? 'Editando UPF ' + u.exercicio : 'Nova UPF', `
    <div class="cad-row">
      ${parRot('Exercício', parInp('exercicio', u.exercicio ?? new Date().getFullYear() + 1, 'type="number" min="2020" max="2100"'), 'max-width:120px')}
      ${parRot('Valor (R$)', parInp('valor', u.valor, 'type="number" step="0.0001" min="0"'))}
      ${parRot('Vigente desde', parInp('vigencia_inicio', u.vigencia_inicio, 'type="date"'))}
      ${parRot('Norma', parInp('norma', u.norma, 'maxlength="80" placeholder="Decreto 1.234/2025"'), 'flex:2')}
    </div>`, 'salvarUpf()')
}

async function salvarUpf() {
  const exercicio = parCampo('exercicio'), valor = parCampo('valor')
  if (!exercicio || !valor) { toast('Informe o exercício e o valor da UPF', 'err'); return }
  await parGravar('/api/parametros/upf', {
    id: parState.ed.id === 'novo' ? null : parState.ed.id,
    exercicio, valor,
    // Sem data, a vigência começa em 1º de janeiro do exercício, que é a regra:
    // a UPF é anual. Decreto que muda no meio do ano é a exceção.
    vigencia_inicio: parCampo('vigencia_inicio') || exercicio + '-01-01',
    norma: parCampo('norma') || null,
  }, carregarParametros)
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

// ── FERIADOS ─────────────────────────────────────────────────

/** Anos existentes, deduzidos das datas cadastradas. */
function anosDeFeriados() {
  const porAno = {}
  for (const f of parState.feriados) {
    const ano = f.data.slice(0, 4)
    porAno[ano] = (porAno[ano] || 0) + 1
  }
  return Object.entries(porAno).sort((a, b) => b[0].localeCompare(a[0]))
}

/** A aba Feriados desenha a lista de anos OU os feriados do ano aberto. */
function renderFeriados() {
  const ano = parState.anoAberto
  document.getElementById('fer-topo-anos').style.display = ano ? 'none' : ''
  document.getElementById('fer-topo-ano').style.display = ano ? '' : 'none'
  document.getElementById('cont-feriados').textContent = parState.feriados.length
  const lista = document.getElementById('lista-feriados')

  if (!ano) {
    const termo = parBusca('busca-anos')
    const anos = anosDeFeriados().filter(([a]) => !termo || a.includes(termo))
    lista.innerHTML =
      (parEditando('anos', 'novo') ? parFormLinha('Novo ano', `<div class="cad-row">
          ${parRot('Ano', parInp('ano', new Date().getFullYear() + 1, 'type="number" min="1900" max="2200"'), 'max-width:140px')}</div>`,
          'novoAnoFeriados()') : '')
      + (anos.map(([a, n]) => `
        <div class="par-linha clicavel" onclick="abrirAnoFeriados('${a}')">
          <div class="principal"><b>${a}</b><span>${n} feriado(s) cadastrado(s)</span></div>
          <span class="seta">›</span>
        </div>`).join('')
      || `<div class="lista-vazia">${termo ? 'Nenhum ano encontrado.' : 'Nenhum ano com feriados cadastrados.'}</div>`)
    return
  }

  document.getElementById('fer-ano-titulo').textContent = 'Feriados de ' + ano
  const termo = parBusca('busca-feriados')
  const doAno = parState.feriados
    .filter(f => f.data.startsWith(ano) && (!termo || f.nome.toLowerCase().includes(termo)))
    .sort((a, b) => a.data.localeCompare(b.data))
  lista.innerHTML =
    (parEditando('feriados', 'novo') ? formFeriado({}) : '')
    + (doAno.map(f => parEditando('feriados', f.id) ? formFeriado(f) : `
      <div class="par-linha">
        ${parPrincipal('feriados', f.id)}
          <b>${formatarDataBR(f.data)} — ${esc(f.nome)}</b>
          <span>${esc(f.tipo)}${f.recorrente ? ' · repete todo ano' : ''}</span>
        </div>
        ${parAcoes('feriados', f.id)}
      </div>`).join('')
    || `<div class="lista-vazia">${termo ? 'Nenhum feriado encontrado.' : 'Nenhum feriado neste ano.'}</div>`)
}

/** @param {Object} f feriado ({} para novo) */
function formFeriado(f) {
  const ano = parState.anoAberto
  // min/max presos ao ano aberto: evita cadastrar 2027 dentro de 2026.
  return parFormLinha(f.id ? 'Editando feriado' : 'Novo feriado em ' + ano, `
    <div class="cad-row">
      ${parRot('Data', parInp('data', f.data, `type="date" min="${ano}-01-01" max="${ano}-12-31"`), 'max-width:180px')}
      ${parRot('Nome', parInp('nome', f.nome, 'maxlength="80" placeholder="Natal"'), 'flex:2')}
      ${parRot('Tipo', parSel('tipo', f.tipo || 'municipal', [
        ['municipal', 'Municipal'], ['nacional', 'Nacional'], ['estadual', 'Estadual'], ['facultativo', 'Facultativo']]))}
      ${parChk('recorrente', f.recorrente, 'Repete todo ano')}
    </div>`, 'salvarFeriado()')
}

/**
 * "Novo ano" só abre a sub-tela: o ano passa a existir quando o primeiro
 * feriado é gravado. Criar um registro vazio só para o ano aparecer na lista
 * seria uma linha sem significado no banco.
 */
function novoAnoFeriados() {
  const ano = parseInt(parCampo('ano'), 10)
  if (!ano || ano < 1900 || ano > 2200) { toast('Informe um ano válido', 'err'); return }
  abrirAnoFeriados(String(ano))
  // Ano novo está vazio por definição: o cartão do primeiro feriado já vem aberto.
  if (!parState.feriados.some(f => f.data.startsWith(String(ano)))) parNovo('feriados')
}

/** @param {string} ano */
function abrirAnoFeriados(ano) {
  parState.anoAberto = ano
  parState.ed = null
  const busca = document.getElementById('busca-feriados')
  if (busca) busca.value = ''
  renderFeriados()
}

function voltarAnosFeriados() {
  parState.anoAberto = null
  parState.ed = null
  renderFeriados()
}

async function salvarFeriado() {
  const data = parCampo('data'), nome = parCampo('nome')
  if (!data || !nome) { toast('Informe a data e o nome do feriado', 'err'); return }
  if (!data.startsWith(parState.anoAberto)) { toast('A data precisa ser do ano ' + parState.anoAberto, 'err'); return }
  await parGravar('/api/parametros/feriados', {
    id: parState.ed.id === 'novo' ? null : parState.ed.id,
    data, nome,
    tipo: parCampo('tipo'),
    recorrente: parCampo('recorrente'),
  }, carregarParametros)
}

/** @param {number} id */
function excluirFeriado(id) {
  confirmarAcao({
    titulo: 'Excluir feriado',
    mensagem: 'Prazos já calculados não mudam retroativamente. Excluir mesmo assim?',
    perigo: true,
    onConfirm: () => excluirParametro('/api/parametros/feriados/' + id),
  })
}

// ── DADOS DO ÓRGÃO ───────────────────────────────────────────
//
// Não é lista: são campos fixos. Por isso um bloco só, lido como ficha, com
// UM Editar que abre todos os campos de uma vez.

/**
 * As sub-abas da aba Formulário: cada uma junta os campos que saem no MESMO
 * lugar do documento. Chave nova de Parametro::CHAVES que não esteja aqui cai
 * na primeira — nunca some da tela.
 */
const GERAL_ABAS = [
  ['orgao', 'Órgão', ['orgao_nome', 'orgao_secretaria', 'orgao_cnpj', 'orgao_telefone', 'orgao_endereco']],
  ['cabecalho', 'Cabeçalho', ['orgao_departamento', 'orgao_divisao', 'orgao_municipio', 'impressao_selo']],
  ['rodape', 'Rodapé', ['rodape_protocolo', 'rodape_ouvidoria']],
  ['recusa', 'Termo de recusa', ['termo_recusa']],
]
/** Textos longos ocupam a linha inteira e editam em caixa de várias linhas. */
const GERAL_LONGOS = ['termo_recusa', 'orgao_endereco']

/** Os campos da sub-aba, na ordem dela. O brasão tem tela própria (envio de imagem). */
function geralDaAba(aba) {
  const editaveis = parState.geral.filter(p => p.chave !== 'brasao_url')
  const classificadas = GERAL_ABAS.flatMap(([, , chaves]) => chaves)
  const chaves = GERAL_ABAS.find(([id]) => id === aba)[2]
  const daAba = chaves.map(c => editaveis.find(p => p.chave === c)).filter(Boolean)
  return aba === 'orgao' ? [...daAba, ...editaveis.filter(p => !classificadas.includes(p.chave))] : daAba
}

/** @param {string} aba */
function subGeral(aba) {
  parState.subGeral = aba
  parState.ed = null
  renderGeral()
}

function renderGeral() {
  const aba = parState.subGeral || 'orgao'
  const campos = geralDaAba(aba)
  const largo = p => GERAL_LONGOS.includes(p.chave) ? ' style="grid-column:1/-1"' : ''

  document.getElementById('abas-geral').innerHTML = GERAL_ABAS.map(([id, rotulo]) =>
    `<button type="button" class="${id === aba ? 'at' : ''}" onclick="subGeral('${id}')">${rotulo}</button>`).join('')
  // O brasão é do órgão: aparece só na primeira sub-aba.
  document.getElementById('geral-brasao').hidden = aba !== 'orgao'

  document.getElementById('lista-geral').innerHTML = parEditando('geral', 'todos')
    ? parFormLinha('Editando ' + GERAL_ABAS.find(([id]) => id === aba)[1].toLowerCase(), `<div class="orgao-grade">${campos.map(p => `
        <label class="ed-campo"${largo(p)}><span>${esc(p.descricao)}</span>${p.chave === 'termo_recusa'
          ? parTxt(p.chave, p.valor, 4) : parInp(p.chave, p.valor)}</label>`).join('')}</div>`, 'salvarGeral()')
    : `<div class="par-linha" style="align-items:flex-start">
        <div class="orgao-grade">${campos.map(p => `
          <div class="ed-campo"${largo(p)}><span>${esc(p.descricao)}</span>
            <b class="texto-longo">${p.valor ? esc(p.valor) : '<em>— vazio —</em>'}</b></div>`).join('')}</div>
        <div class="par-acoes"><button type="button" class="btn edit-verde sm" onclick="parEditar('geral', 'todos')">${ICO_EDITAR}Editar</button></div>
      </div>`
}

/** Grava só os campos da sub-aba aberta: os das outras não estão na tela. */
async function salvarGeral() {
  const valores = {}
  geralDaAba(parState.subGeral || 'orgao').forEach(p => { valores[p.chave] = parCampo(p.chave) })
  await parGravar('/api/parametros/geral', { valores }, carregarParametros)
}

// ── HELPERS COMUNS ───────────────────────────────────────────

const ICO_EDITAR = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
  <path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>`

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
  parState.ed = null
  toast(d.message)
  await carregarParametros()
}

function primeiroErroPar(d) {
  const e = d?.errors && Object.values(d.errors)[0]
  return Array.isArray(e) ? e[0] : 'Não foi possível concluir a operação'
}

// ── BAIRROS ──────────────────────────────────────────────────
//
// A lista tem 125 linhas, por isso a busca e o "+ Novo" ficam fixos no topo e
// só a lista rola.

function renderBairros() {
  const termo = parBusca('filtro-bairros')
  const lista = parState.bairros.filter(b => !termo
    || String(b.codigo).includes(termo)
    || (b.nome_cadastro || '').toLowerCase().includes(termo)
    || (b.nome_gis || '').toLowerCase().includes(termo)
    || (b.apelido || '').toLowerCase().includes(termo))
  parContador('cont-bairros', lista.length, parState.bairros.length)

  document.getElementById('lista-bairros').innerHTML =
    (parEditando('bairros', 'novo') ? formBairro({}) : '')
    + (lista.map(b => parEditando('bairros', b.id) ? formBairro(b) : `
      <div class="par-linha">
        ${parPrincipal('bairros', b.id)}
          <b>${esc(b.codigo)} · ${esc(b.nome_cadastro || b.nome_gis || '(sem nome)')}</b>
          <span>${b.nome_gis
            ? 'No desenho: ' + esc(b.nome_gis) + (b.apelido ? ' · no mapa: ' + esc(b.apelido) : '')
              + (b.lotes ? ` · ${b.lotes} lote(s)` : ' · sem lote ainda')
            : 'Ainda sem desenho convertido'}</span>
        </div>
        ${parAcoes('bairros', b.id)}
      </div>`).join('')
    || '<div class="lista-vazia">Nenhum bairro encontrado.</div>')
}

/** @param {Object} b bairro ({} para novo) */
function formBairro(b) {
  // O código e o nome do cadastro são os da prefeitura. O nome no desenho é
  // como o bairro aparece no DWG convertido — é ele que amarra os lotes ao
  // código (e daí sai a inscrição), por isso TRAVA quando já há lote nele. O
  // apelido é só o rótulo do mapa, e muda à vontade.
  const travado = !!(b.nome_gis && b.lotes)
  return parFormLinha(b.id ? 'Editando bairro' : 'Novo bairro', `
    <div class="cad-row">
      ${parRot('Código', parInp('codigo', b.codigo, 'type="number" min="1"'), 'max-width:110px')}
      ${parRot('Nome no cadastro', parInp('nome_cadastro', b.nome_cadastro, 'placeholder="JARDIM EUROPA IV"'), 'flex:2')}
    </div>
    <div class="cad-row">
      ${parRot('Nome no desenho' + (travado ? ` · liga ${b.lotes} lote(s)` : ''),
        parInp('nome_gis', b.nome_gis, travado
          ? 'readonly title="Liga os lotes ao código do bairro e não pode mudar. Para o mapa, use o apelido."'
          : 'placeholder="opcional"'), 'flex:2')}
      ${parRot('Apelido no mapa', parInp('apelido', b.apelido, 'maxlength="160" placeholder="opcional — ex.: BURITIS V"'), 'flex:2')}
    </div>`, 'salvarBairro()')
}

async function salvarBairro() {
  const codigo = parCampo('codigo'), nome = parCampo('nome_cadastro')
  if (!codigo || !nome) { toast('Informe o código e o nome do bairro', 'err'); return }
  await parGravar('/api/parametros/bairros', {
    id: parState.ed.id === 'novo' ? null : parState.ed.id,
    codigo,
    nome_cadastro: nome,
    // Vazio vira nulo no servidor: bairro sem desenho convertido não tem nome
    // de GIS, e string vazia colidiria com a próxima no índice único.
    nome_gis: parCampo('nome_gis') || null,
    apelido: parCampo('apelido') || null,
  }, carregarParametros)
}

/** @param {number} id */
function excluirBairro(id) {
  const b = parState.bairros.find(x => x.id === id)
  confirmarAcao({
    titulo: 'Excluir bairro',
    mensagem: b?.lotes
      ? `${b.lotes} lote(s) estão neste bairro — o sistema vai recusar. Tentar mesmo assim?`
      : 'O bairro sai da lista de escolha no cadastro de lote. Excluir?',
    perigo: true,
    onConfirm: () => excluirParametro('/api/parametros/bairros/' + id),
  })
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
