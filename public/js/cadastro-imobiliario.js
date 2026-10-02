// ══════════════════════════════════════════════
// ABA "CADASTRO IMOBILIÁRIO" DA FICHA
//
// Mostra o cadastro municipal do imóvel, lido ao vivo da última carga mensal
// da planilha da prefeitura. Três regras que explicam a forma desta tela:
//
// 1. CARREGA SÓ QUANDO A ABA ABRE. O mapa traz até 3.000 lotes; buscar o
//    cadastro de todos seria pagar caro por um dado que quase ninguém olha.
// 2. NÃO REPETE O QUE A FICHA JÁ SABE. Inscrição, quadra, lote, bairro e CEP
//    ficam de fora — o sistema já os tem, e guardar duas versões do mesmo fato
//    é garantir que um dia elas divirjam.
// 3. CABE NA ABA, SEM ROLAGEM INTERNA. As características vão em duas colunas
//    e fonte menor; nenhuma seção rola por dentro.
// ══════════════════════════════════════════════

/** Cache por lote: reabrir a aba do mesmo imóvel não repete a ida ao servidor. */
const bciCache = new Map()

/** Lote cuja aba está desenhada agora — evita pintar resposta de imóvel antigo. */
let bciLoteAtual = null

/**
 * Carrega e desenha a aba. Chamada por `subFicha('cadastro')`.
 * @param {number|string} loteId
 */
async function carregarBci(loteId) {
  const caixa = document.getElementById('fi-bci')
  if (!caixa || !loteId) { return }
  bciLoteAtual = loteId

  if (bciCache.has(loteId)) { desenharBci(caixa, bciCache.get(loteId)); return }

  caixa.innerHTML = '<div class="vazio-msg">Carregando cadastro…</div>'
  try {
    const dados = await obterBci(loteId)
    // Entre o pedido e a resposta o usuário pode ter aberto outro imóvel.
    if (bciLoteAtual === loteId) { desenharBci(caixa, dados) }
  } catch (e) {
    caixa.innerHTML = '<div class="vazio-msg">Não foi possível ler o cadastro agora.</div>'
  }
}

/**
 * O BCI de um lote, do cache ou do servidor — sem desenhar nada.
 *
 * Usado pela aba, pelo cabeçalho da ficha ("Últ. Integração") e pelo
 * formulário de documento (proprietário como autuado): uma ida ao servidor
 * serve aos três.
 *
 * @param {number|string} loteId
 * @returns {Promise<Object>}
 */
async function obterBci(loteId) {
  if (bciCache.has(loteId)) { return bciCache.get(loteId) }
  const r = await fetch(`/api/imoveis/${loteId}/bci`, { headers: { Accept: 'application/json' } })
  if (!r.ok) { throw new Error(r.status) }
  const dados = await r.json()
  bciCache.set(loteId, dados)
  return dados
}

/** Esquece o que está em cache de um lote — usar depois de reconsultar. */
function limparCacheBci(loteId) {
  loteId === undefined ? bciCache.clear() : bciCache.delete(loteId)
}

// ── desenho ──────────────────────────────────────────────────

function desenharBci(caixa, d) {
  if (!d.tem) {
    // O vazio DIZ O MOTIVO e oferece a providência. Cada motivo tem uma
    // providência diferente — amarrar o bairro, corrigir o lote, carregar a
    // exportação — e é o servidor que sabe qual é o caso.
    caixa.innerHTML = `
      <div class="bci-vazio">
        <div class="bci-vazio-t">Sem dados do cadastro imobiliário</div>
        <p>${esc(d.motivo || '')}</p>
        <p class="bci-vazio-p">Área de terreno, medidas, características e
           construções vêm do cadastro da prefeitura. Esta aba fica vazia — e não
           em branco: o que falta é o dado de lá, não o imóvel.</p>
      </div>${secProprietarios(d.proprietarios)}`
    return
  }

  const i = d.imovel
  caixa.innerHTML = [
    cabecalhoBci(d),
    secImovel(i),
    secProprietarios(d.proprietarios),
    secCaracteristicas(d.caracteristicas),
    secUnidades(d.unidades),
  ].filter(Boolean).join('')
}

/**
 * Proprietários do imóvel. O servidor já mandou só o que este usuário pode ver
 * (ver App\Cadastro\ProprietariosVisiveis): CPF/CNPJ e endereço chegam só para
 * agente e administrador, e o externo não recebe o bloco.
 */
function secProprietarios(lista) {
  if (!lista || !lista.length) { return '' }
  const corpo = lista.map(p => `
    <div class="bci-prop">
      <div class="bci-prop-n">${esc(p.nome)}${p.documento
        ? ` <span class="mono bci-doc">${esc(p.documento)}</span>` : ''}</div>
      ${p.endereco ? `<div class="bci-prop-e">${esc(p.endereco)}</div>` : ''}
    </div>`).join('')
  return bciSecao(lista.length > 1 ? 'Proprietários' : 'Proprietário', corpo)
}

/**
 * Linha de topo: a situação do imóvel nas cargas mensais do cadastro. O dado
 * desta aba é sempre o da última carga — não há o que "atualizar" aqui; a
 * planilha nova entra por Parâmetros → Cadastro municipal.
 */
function cabecalhoBci(d) {
  return linhaDasCargas(d)
}

/** "Últ. integração" e "Últ. alteração" do cadastro municipal. */
function linhaDasCargas(d) {
  const g = d.integracao || {}
  if (!g.em && !g.ausente_desde) { return '' }
  return `<div class="bci-cargas imp-sub">
      ${g.ausente_desde
        ? `<b>Fora do cadastro</b> desde a carga de ${esc(dataHoraCurta(g.ausente_desde))}`
        : `Últ. integração <b>${esc(dataHoraCurta(g.em))}</b>`}
      ${g.alterado_em ? ` · Últ. alteração <b>${esc(dataHoraCurta(g.alterado_em))}</b>
        · <a href="#" onclick="event.preventDefault(); verHistoricoDoCadastro()">ver o que mudou</a>` : ''}
    </div>
    <div id="bci-historico"></div>`
}

/** O que mudou no cadastro deste imóvel, carga a carga. */
async function verHistoricoDoCadastro() {
  const loteId = state.selecionado?.properties?.id
  const caixa = document.getElementById('bci-historico')
  if (!loteId || !caixa) { return }
  caixa.innerHTML = '<div class="imp-sub">Carregando…</div>'
  try {
    const r = await fetch(`/api/imoveis/${loteId}/cadastro/historico`, { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error(r.status) }
    const itens = (await r.json()).itens
    const tipo = { novo: 'Entrou no cadastro', ausente: 'Saiu do cadastro', reapareceu: 'Voltou ao cadastro' }
    caixa.innerHTML = itens.length ? `<table class="imp-tabela"><tbody>${itens.map(a => `<tr>
        <td class="imp-sub">${esc(dataHoraCurta(a.em))}</td>
        <td>${esc(a.campo || tipo[a.tipo] || a.tipo)}</td>
        <td>${a.campo ? `${esc(a.antes ?? '—')} → <b>${esc(a.depois ?? '—')}</b>` : ''}</td>
      </tr>`).join('')}</tbody></table>`
      : '<div class="imp-sub">Nenhuma alteração registrada.</div>'
  } catch {
    caixa.innerHTML = '<div class="imp-sub">Não foi possível carregar o histórico.</div>'
  }
}

/** dd/mm/aa - hh:mm, a mesma régua do cabeçalho da ficha. */
function dataHoraCurta(iso) {
  const d = new Date(iso)
  if (!iso || isNaN(d)) { return '—' }
  const p = n => String(n).padStart(2, '0')
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${p(d.getFullYear() % 100)}`
       + ` - ${p(d.getHours())}:${p(d.getMinutes())}`
}

/** Uma seção com título. */
function bciSecao(titulo, corpo) {
  return `<div class="bci-sec"><div class="bci-sec-t">${esc(titulo)}</div>${corpo}</div>`
}

/** Faixa de campos — a mesma estrutura da ficha, para o traço não quebrar. */
function bciFaixa(campos) {
  const cheios = campos.filter(c => c[1] !== null && c[1] !== undefined && c[1] !== '')
  if (!cheios.length) { return '' }
  return '<div class="fi-linha">' + cheios.map(([rot, val]) => {
    // Monoespaçado quando o valor é para CONFERIR dígito a dígito — código,
    // inscrição, área, medida. Texto corrido (setor, isenção) fica na fonte
    // do sistema, que é mais legível para ler do que para comparar.
    const numero = /^[\d.,\/\s-]+(\s?m²|\s?m)?$/.test(String(val))
    return `<div class="fi-campo"><span class="fi-rot">${esc(rot)}</span>`
      + `<span class="fi-val${numero ? ' mono' : ''}">${esc(val)}</span></div>`
  }).join('') + '</div>'
}

const bciM2 = v => (v || v === 0) ? fmtNum(v) + ' m²' : null
const bciM  = v => (v || v === 0) ? fmtNum(v) + ' m' : null

function secImovel(i) {
  const faixas = [
    bciFaixa([['Código', i.codigo_cadastro], ['Insc. alternativa', i.inscricao_alternativa],
              ['Isenção', i.isencao]]),
    bciFaixa([['Área terreno', bciM2(i.area_terreno_m2)],
              ['Área edificada', bciM2(i.area_edificada_m2)],
              ['Fração ideal', i.fracao_ideal]]),
    bciFaixa([['Testada', bciM(i.testada_m)], ['Lado dir.', bciM(i.medida_lado_direito)],
              ['Lado esq.', bciM(i.medida_lado_esquerdo)], ['Fundo', bciM(i.medida_fundo)]]),
    bciFaixa([['Setor', i.setor], ['Região fiscal', i.regiao_fiscal]]),
    bciFaixa([['Complemento', i.complemento]]),
  ].join('')

  return faixas ? bciSecao('Imóvel', `<div class="fi-linhas">${faixas}</div>`) : ''
}

/**
 * Rótulos do BCI que precisam de outro nome NA TELA.
 *
 * "Situação", no quadro de características, quer dizer onde o lote está no
 * quarteirão (MEIO DA QUADRA, ESQUINA). Na ficha, "Situação" já quer dizer
 * outra coisa — imóvel ativo ou inativo por sucessão. Duas palavras iguais com
 * sentidos diferentes na mesma tela é erro esperando acontecer, e quem paga é
 * quem lê o auto depois.
 */
const BCI_ROTULOS = {
  'Situação': 'Posição na quadra',
  'SITUACAO': 'Posição na quadra',
}

function secCaracteristicas(lista) {
  if (!lista || !lista.length) { return '' }
  // Duas colunas: são 22 pares no BCI de Primavera, e em uma coluna só eles
  // sozinhos passariam da altura da aba.
  const corpo = '<div class="bci-carac">' + lista.map(c =>
    `<div class="bci-par"><span>${esc(BCI_ROTULOS[c.chave] ?? c.chave)}</span>`
    + `<b>${esc(c.valor ?? '—')}</b></div>`
  ).join('') + '</div>'
  return bciSecao('Características', corpo)
}

function secUnidades(lista) {
  if (!lista || !lista.length) { return '' }
  // Só ano, área e padrão: foi o pedido, e é o que responde "o que está
  // construído aí". O número da unidade fica porque distingue as linhas.
  const corpo = '<table class="bci-tab"><thead><tr><th>Un.</th><th>Ano</th>'
    + '<th class="num">Área</th><th>Padrão</th></tr></thead><tbody>'
    + lista.map(u => `<tr><td>${esc(u.numero ?? '—')}</td><td>${esc(u.ano ?? '—')}</td>`
        + `<td class="num">${u.area || u.area === 0 ? esc(bciM2(u.area)) : '—'}</td>`
        + `<td>${esc(u.padrao ?? '—')}</td></tr>`).join('')
    + '</tbody></table>'
  return bciSecao('Unidades', corpo)
}
