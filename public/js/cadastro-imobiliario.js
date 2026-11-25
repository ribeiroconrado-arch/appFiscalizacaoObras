// ══════════════════════════════════════════════
// ABA "CADASTRO IMOBILIÁRIO" DA FICHA
//
// Mostra o cadastro municipal do imóvel, lido ao vivo da última carga mensal
// da planilha da prefeitura. Três regras que explicam a forma desta tela:
//
// 1. CARREGA SÓ QUANDO A ABA ABRE. O mapa traz até 3.000 lotes; buscar o
//    cadastro de todos seria pagar caro por um dado que quase ninguém olha.
// 2. MOSTRA O IMÓVEL COMO O CADASTRO O CONHECE. Logradouro, número, bairro e
//    inscrição alternativa vêm de lá, e não do sistema: é por eles que se
//    confere se a ficha puxou o imóvel certo. Inscrição, quadra e lote do
//    sistema continuam só no cabeçalho da ficha.
// 3. CABE NA ABA, SEM ROLAGEM INTERNA. Linhas de rótulo e valor em duas
//    colunas; os serviços públicos na testada ficam de fora (BCI_OCULTAS).
// 4. NADA AQUI SE EDITA. Tudo vem do cadastro municipal — inclusive a
//    progressividade, que é do sistema tributário (24/11/2026: até então a
//    fiscalização lançava aqui).
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
      </div>${secTopo(d.proprietarios, null)}`
    return
  }

  // A ORDEM é a da leitura em campo: de quem é e qual imóvel é (para conferir
  // se puxou o certo), como é o terreno, o que interessa à fiscalização e, por
  // fim, o que está construído.
  const c = bciCaracteristicas(d.caracteristicas)
  caixa.innerHTML = [
    cabecalhoBci(d),
    secTopo(d.proprietarios, d.imovel),
    secTerreno(d.imovel, c),
    secFiscalizacao(d, c),
    secUnidades(d.unidades),
  ].filter(Boolean).join('')
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

// ── as peças do desenho ──────────────────────────────────────
//
// A aba inteira fala UMA gramática: linha de rótulo (cinza, à esquerda) e
// valor (escuro, à direita), em duas colunas. Sem cor e sem caixa alta — o que
// pesa é a ordem: de quem é, qual imóvel é, como é o terreno, o que interessa
// à fiscalização, o que está construído.

/** Uma seção com título. */
function bciSecao(titulo, corpo, classe = '') {
  return `<div class="bci-sec ${classe}"><div class="bci-sec-t">${esc(titulo)}</div>${corpo}</div>`
}

/**
 * Uma linha rótulo/valor. Valor vazio vira travessão: nas seções de
 * conferência, a linha em falta é informação ("o cadastro não tem número").
 *
 * @param {string} rot
 * @param {*} val
 * @param {{mono?:boolean, forte?:boolean, longo?:boolean}} [o]
 */
function bciLin(rot, val, o = {}) {
  const vazio = val === null || val === undefined || val === ''
  const corpo = vazio ? '—' : esc(val)
  // O vazio nunca vai em monoespaçado: o travessão da fonte mono é mais curto,
  // e a mesma coluna mostrava dois traços diferentes.
  const cls = vazio ? 'vazio' : [o.mono ? 'mono' : '', o.forte ? 'forte' : '', o.longo ? 'longo' : ''].filter(Boolean).join(' ')
  return `<div class="bci-lin"><span>${esc(rot)}</span><b${cls ? ` class="${cls}"` : ''}>${corpo}</b></div>`
}

/** Duas colunas de linhas. */
function bciCols(esq, dir, classe = '') {
  return `<div class="bci-g2 ${classe}"><div>${esq.join('')}</div><div>${dir.join('')}</div></div>`
}

const bciM2 = v => (v || v === 0) ? fmtNum(v) + ' m²' : null
const bciM  = v => (v || v === 0) ? fmtNum(v) : null

/** Dois valores numa linha só ("12 × 30 m"), ou o que houver deles. */
function bciPar(a, b, sep, unidade) {
  const partes = [a, b].filter(v => v !== null && v !== undefined && v !== '')
  return partes.length ? partes.join(sep) + (unidade ? ' ' + unidade : '') : null
}

/**
 * O cadastro grava tudo em CAIXA ALTA ("MEIO DA QUADRA"). Na tela vai em caixa
 * de frase: doze linhas gritando deixam de destacar a que importa.
 */
function bciFrase(v) {
  if (v === null || v === undefined || v === '') { return null }
  const s = String(v).trim().toLowerCase()
  return s.charAt(0).toUpperCase() + s.slice(1)
}

/** "SIM"/"NÃO" sozinhos não dizem nada ao lado de "Calçada": tem ou não tem. */
function bciTem(v) {
  const s = String(v ?? '').trim().toUpperCase()
  if (s === 'SIM') { return 'Tem' }
  if (s === 'NÃO' || s === 'NAO' || s === 'NAO TEM' || s === 'NÃO TEM') { return 'Não tem' }
  return bciFrase(v)
}

/**
 * Características que a aba NÃO mostra: os serviços públicos na testada. Foi
 * decisão de quem usa — na fiscalização de obras eles não mudam conduta, e
 * ocupavam um terço da tela.
 */
const BCI_OCULTAS = new Set(['ENERGIA', 'AGUA', 'COLETA DE LIXO', 'ASFALTO', 'REDE DE ESGOTO',
  'REDE TELEFONICA', 'GALERIAS', 'ILUMINAÇÃO PUBL'])

/**
 * Leitor das características: entrega cada uma pelo nome e lembra quais já
 * foram usadas. As que sobrarem — a lista muda de município para município —
 * vão para o fim do Terreno, em vez de sumir.
 */
function bciCaracteristicas(lista) {
  const mapa = new Map((lista || []).map(c => [c.chave, c.valor]))
  const usadas = new Set()
  return {
    pega(chave) { usadas.add(chave); return mapa.get(chave) ?? null },
    resto() {
      return [...mapa].filter(([k]) => !usadas.has(k) && !BCI_OCULTAS.has(k))
        .map(([k, v]) => [bciFrase(k), bciFrase(v)])
    },
  }
}

/**
 * PROPRIETÁRIO ao lado do IMÓVEL. O imóvel está aqui para CONFERIR se o
 * cadastro puxado é o deste lote: logradouro, número, bairro e inscrição
 * alternativa são os do cadastro municipal, não os do sistema.
 *
 * O servidor já mandou só o que este usuário pode ver (ver
 * App\Cadastro\ProprietariosVisiveis): CPF inteiro e endereço só para agente e
 * administrador; os demais servidores recebem o CPF mascarado; o externo não
 * recebe o bloco — e aí o imóvel ocupa as duas colunas.
 */
function secTopo(donos, i) {
  const imovel = i ? [
    bciLin('Logradouro', i.logradouro),
    bciLin('Número', bciPar(i.numero_predial, i.complemento, ' · '), { mono: !i.complemento }),
    bciLin('Bairro', i.nome_bairro),
    bciLin('Insc. alternativa', i.inscricao_alternativa, { mono: true }),
  ] : null

  const temDonos = donos && donos.length
  if (!temDonos) {
    return imovel ? bciSecao('Imóvel', bciCols(imovel.slice(0, 2), imovel.slice(2))) : ''
  }

  const dono = donos.map(p => {
    const doc = p.documento ?? p.documento_mascarado
    return [
      bciLin('Nome', p.nome),
      bciLin(/\//.test(doc ?? '') ? 'CNPJ' : 'CPF', doc, { mono: true }),
      p.endereco ? bciLin('Endereço', p.endereco, { longo: true }) : '',
    ].join('')
  }).join('')
  const titulo = donos.length > 1 ? 'Proprietários' : 'Proprietário'

  if (!imovel) { return bciSecao(titulo, dono) }
  return `<div class="bci-sec"><div class="bci-g2">${bciSecao(titulo, dono)}${bciSecao('Imóvel', imovel.join(''))}</div></div>`
}

function secTerreno(i, c) {
  const esq = [
    bciLin('Área', bciM2(i.area_terreno_m2), { mono: true }),
    bciLin('Testada × fundo', bciPar(bciM(i.testada_m), bciM(i.medida_fundo), ' × ', 'm'), { mono: true }),
    bciLin('Laterais', bciPar(bciM(i.medida_lado_direito), bciM(i.medida_lado_esquerdo), ' / ', 'm'), { mono: true }),
    // "Situação", no cadastro, é onde o lote está no quarteirão. Na ficha a
    // palavra já quer dizer outra coisa (ativo/inativo) — daí o outro nome.
    bciLin('Posição na quadra', bciFrase(c.pega('SITUACAO') ?? c.pega('Situação'))),
  ]
  const dir = [
    bciLin('Utilização', bciPar(bciFrase(c.pega('UTILIZACAO')), bciFrase(c.pega('TIPO DE IMOVEL')), ' · ')),
    bciLin('Topografia', bciPar(bciFrase(c.pega('TOPOGRAFIA')), bciFrase(c.pega('PEDOLOGIA')), ' · ')),
    bciLin('Setor', bciPar(i.setor, i.regiao_fiscal, ' · ')),
    bciLin('Isenção', bciFrase(i.isencao)),
  ]

  // O que só às vezes existe entra no fim, alternando as colunas.
  const extras = [
    ['Patrimônio', bciFrase(c.pega('BEM IMOV. PATRIMONIO'))],
    ['Fração ideal', i.fracao_ideal],
  ].filter(([, v]) => v !== null && v !== undefined && v !== '')
  // As da fiscalização são marcadas como usadas ANTES de calcular o resto.
  BCI_DA_FISCALIZACAO.forEach(k => c.pega(k))
  extras.concat(c.resto()).forEach(([rot, val], n) => (n % 2 ? dir : esq).push(bciLin(rot, val)))

  return bciSecao('Terreno', bciCols(esq, dir))
}

const BCI_DA_FISCALIZACAO = ['CALCADA', 'PROGRESSIVIDADE', 'ELEMENTO DE PROTECAO', 'OCUPACAO DO LOTE', 'CONSERVACAO DE']

/**
 * PARA A FISCALIZAÇÃO — o que decide conduta em campo: calçada,
 * progressividade, muro, ocupação, área edificada, conservação.
 *
 * Tudo lido do cadastro. A progressividade vem do sistema tributário e só é
 * alterada lá; sem a coluna na planilha, a linha mostra "—".
 */
function secFiscalizacao(d, c) {
  const i = d.imovel || {}
  const esq = [
    bciLin('Calçada', bciTem(c.pega('CALCADA')), { forte: true }),
    bciLin('Progressividade', bciFrase(c.pega('PROGRESSIVIDADE')), { forte: true }),
    bciLin('Muro / proteção', bciFrase(c.pega('ELEMENTO DE PROTECAO')), { forte: true }),
  ]
  const dir = [
    bciLin('Ocupação', bciFrase(c.pega('OCUPACAO DO LOTE')), { forte: true }),
    bciLin('Área edificada', bciM2(i.area_edificada_m2), { forte: true, mono: true }),
    bciLin('Conservação', bciFrase(c.pega('CONSERVACAO DE')), { forte: true }),
  ]
  return bciSecao('Para a fiscalização', bciCols(esq, dir), 'bci-marca')
}

function secUnidades(lista) {
  if (!lista || !lista.length) { return '' }
  // Só ano, área e padrão: é o que responde "o que está construído aí". O
  // número da unidade fica porque distingue as linhas.
  const corpo = '<table class="bci-tab"><thead><tr><th>Un.</th><th>Ano</th>'
    + '<th class="num">Área</th><th>Padrão</th></tr></thead><tbody>'
    + lista.map(u => `<tr><td>${esc(u.numero ?? '—')}</td><td>${esc(u.ano ?? '—')}</td>`
        + `<td class="num mono">${u.area || u.area === 0 ? esc(bciM2(u.area)) : '—'}</td>`
        + `<td>${esc(bciFrase(u.padrao) ?? '—')}</td></tr>`).join('')
    + '</tbody></table>'
  return bciSecao('Unidades', corpo)
}
