/**
 * NOME DO BAIRRO ENCURTADO PARA CABER (06/11/2026).
 *
 * "CONDOMÍNIO RESIDENCIAL VILLA PADOVA" não cabe numa linha de cartão nem no
 * rótulo do mapa; "COND. RESID. VILLA PADOVA" cabe. A abreviação é feita SÓ NA
 * HORA DE MOSTRAR — o nome gravado continua inteiro (a carga do cadastro
 * compara por ele) — e SÓ ONDE O NOME NÃO CABE: quem chama diz quantas letras
 * cabem, e o nome que já cabe fica como está. Ficha, A4, formulários e filtros
 * mostram sempre o nome inteiro.
 *
 * Ordem: primeiro a palavra do início ("LOTEAMENTO …"), depois as do meio,
 * uma por vez, até caber. Se nem assim couber, o resto é com o CSS.
 *
 * Tabela aprovada por ele em 06/11/2026. Só entra palavra que economize pelo
 * menos 3 letras (por isso "Vila" ficou de fora). A mesma tabela está em
 * app/Cadastro/NomeDoBairro.php — mudou aqui, muda lá.
 */
const BAIRRO_ABREVIACOES = [
  // `de`: as palavras sem acento, em maiúsculas. `meio`: nunca na 1ª palavra.
  { de: ['DISTRITO', 'INDUSTRIAL'], para: 'DIST. IND.' },
  { de: ['LOTEAMENTO'], para: 'LOT.' },
  { de: ['JARDIM'], para: 'JD.' },
  { de: ['RESIDENCIAL'], para: 'RESID.' },
  { de: ['PARQUE'], para: 'PQ.' },
  { de: ['CONDOMINIO'], para: 'COND.' },
  { de: ['CONJUNTO'], para: 'CONJ.' },
  { de: ['CHACARA'], para: 'CHÁC.' },
  { de: ['CHACARAS'], para: 'CHÁC.' },
  { de: ['AMPLIACAO'], para: 'AMPL.', meio: true },
  { de: ['EXPANSAO'], para: 'EXP.', meio: true },
]

/** Nomes próprios que contêm uma palavra da tabela: "CIDADE JARDIM" não vira "CIDADE JD.". */
const BAIRRO_SEM_ABREVIAR = [['CIDADE', 'JARDIM']]

/** Quantas letras cabem numa linha de resumo (cartão, tabela, "bairro · quadra · lote"). */
const BAIRRO_CABE_LINHA = 22

/** A palavra sem acento, em maiúsculas, sem parêntese nem pontuação em volta. */
function _bairroPalavra(p) {
  return String(p).normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/^[^A-Z0-9]+|[^A-Z0-9]+$/g, '')
}

/** "JD." no nome em maiúsculas; "Jd." no nome escrito normal. */
function _bairroNaCaixa(ab, original) {
  return original === original.toUpperCase() ? ab : ab.toLowerCase().replace(/(^|\s)(\p{L})/gu, (m, s, l) => s + l.toUpperCase())
}

/**
 * O nome do bairro com no máximo `cabe` letras, se der.
 * @param {string} nome @param {number} [cabe] @returns {string}
 */
function bairroCurto(nome, cabe = BAIRRO_CABE_LINHA) {
  const texto = String(nome ?? '').trim()
  if (texto.length <= cabe) { return texto }
  const p = texto.split(/\s+/)
  const chave = p.map(_bairroPalavra)
  const protegida = i => BAIRRO_SEM_ABREVIAR.some(f => f.some((w, k) =>
    i - k >= 0 && f.every((v, j) => chave[i - k + j] === v)))

  // Onde cada abreviação cabe, da esquerda para a direita: a da 1ª palavra vem primeiro.
  const achados = []
  for (let i = 0; i < p.length; i++) {
    const a = BAIRRO_ABREVIACOES.find(a => a.de.every((w, j) => chave[i + j] === w))
    if (!a || (a.meio && i === 0) || protegida(i)) { continue }
    achados.push({ i, a })
    i += a.de.length - 1
  }

  // Uma abreviação a mais por volta, até caber; dentro da volta, de trás para a frente para os índices não andarem.
  const usados = []
  let atual = texto
  for (const ac of achados) {
    usados.push(ac)
    const q = p.slice()
    for (const u of [...usados].sort((x, y) => y.i - x.i)) {
      const n = u.a.de.length
      const ini = q[u.i].match(/^[^\p{L}\p{N}]*/u)[0]
      const fim = q[u.i + n - 1].match(/[^\p{L}\p{N}]*$/u)[0].replace(/^\./, '')
      q.splice(u.i, n, ini + _bairroNaCaixa(u.a.para, p.slice(u.i, u.i + n).join(' ')) + fim)
    }
    atual = q.join(' ')
    if (atual.length <= cabe) { break }
  }
  return atual
}
