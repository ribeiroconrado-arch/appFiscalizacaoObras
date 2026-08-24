// ══════════════════════════════════════════════
// ABA "CADASTRO IMOBILIÁRIO" DA FICHA
//
// Mostra a cópia local do BCI da prefeitura. Três regras que explicam a forma
// desta tela:
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
    const r = await fetch(`/api/imoveis/${loteId}/bci`, { headers: { Accept: 'application/json' } })
    if (!r.ok) { throw new Error(r.status) }
    const dados = await r.json()
    bciCache.set(loteId, dados)
    // Entre o pedido e a resposta o usuário pode ter aberto outro imóvel.
    if (bciLoteAtual === loteId) { desenharBci(caixa, dados) }
  } catch (e) {
    caixa.innerHTML = '<div class="vazio-msg">Não foi possível ler o cadastro agora.</div>'
  }
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
        ${botaoConsultar('Consultar o cadastro')}
      </div>`
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

/** Linha de topo: quando foi consultado, e o botão de consultar de novo. */
function cabecalhoBci(d) {
  return `<div class="bci-topo">
    <span>Consultado em <b>${esc(dataHoraCurta(d.consultado_em))}</b></span>
    ${botaoConsultar('Atualizar')}
  </div>`
}

function botaoConsultar(rotulo) {
  return `<button class="btn sm out-green" onclick="atualizarBci(this)">${esc(rotulo)}</button>`
}

/**
 * Consulta o cadastro AGORA e regrava a cópia local.
 *
 * É um ato do usuário, e não algo que a ficha faça sozinha ao abrir: a consulta
 * depende do cadastro da prefeitura estar de pé, e o fiscal em campo precisa
 * que a ficha abra mesmo quando ele não está.
 */
async function atualizarBci(botao) {
  const loteId = state.selecionado?.properties?.id
  if (!loteId) { return }

  const rotulo = botao.textContent
  botao.disabled = true
  botao.textContent = 'Consultando...'
  try {
    const r = await fetch(`/api/imoveis/${loteId}/bci/atualizar`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
    })
    if (!r.ok) { throw new Error(r.status) }
    const dados = await r.json()
    bciCache.set(loteId, dados)
    desenharBci(document.getElementById('fi-bci'), dados)
    toast(dados.tem ? 'Cadastro atualizado' : 'O cadastro não tem este imóvel', dados.tem ? '' : 'err')
  } catch (e) {
    botao.disabled = false
    botao.textContent = rotulo
    toast('Não foi possível consultar o cadastro agora', 'err')
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
  return '<div class="fi-linha">' + cheios.map(([rot, val]) =>
    `<div class="fi-campo"><span class="fi-rot">${esc(rot)}</span>`
    + `<span class="fi-val">${esc(val)}</span></div>`).join('') + '</div>'
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
 * Rótulos do BCI que precisam de outro nome NA TELA.
 *
 * "Situação", no quadro de características, quer dizer onde o lote está no
 * quarteirão (MEIO DA QUADRA, ESQUINA). Na ficha, "Situação" já quer dizer
 * outra coisa — imóvel ativo ou baixado por sucessão. Duas palavras iguais com
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
