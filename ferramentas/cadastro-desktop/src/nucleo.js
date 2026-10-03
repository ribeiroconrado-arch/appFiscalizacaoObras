'use strict'
// ══════════════════════════════════════════════════════════════════════
// NÚCLEO DO APP DO CADASTRO — a planilha da prefeitura vira o JSON de
// diferenças que o sistema aceita em Parâmetros › Cadastro municipal.
//
// Sem tela e sem dependência: só `zlib` e `crypto` do Node. É o que os testes
// exercitam (tests/cadastro-desktop.test.cjs) e o que o processo de fundo do
// app executa (processar.js).
//
// ── POR QUE ESPELHAR O PHP LINHA A LINHA ──
//
// O sistema decide se um imóvel mudou comparando um código de conferência
// (sha1 do registro canônico mais os proprietários) com o que ele gravou na
// carga anterior. Este app calcula o MESMO código, no PC, para mandar só o
// que mudou. Se a leitura da planilha, a forma canônica ou a codificação do
// JSON divergirem um caractere do PHP, o código não bate e o imóvel parece
// alterado todo mês. Por isso cada função aqui diz qual função do PHP ela
// espelha, e os testes conferem os códigos contra valores calculados pelo PHP.
//
//   leitura da planilha ... app/Cadastro/LeitorXlsx.php
//   colunas e canônico .... app/Cadastro/ColunasDaExportacao.php
//   código de conferência . app/Cadastro/DiferencaDoCadastro.php::hash
//   leitura da carga ...... app/Cadastro/CargaDoCadastro.php::ler
// ══════════════════════════════════════════════════════════════════════

const zlib = require('node:zlib')
const crypto = require('node:crypto')

const VERSAO_APP = '1.0.0'
const FORMATO_REFERENCIA = 'fiscobras-cadastro-referencia'
const FORMATO_DIFERENCA = 'fiscobras-cadastro-diferenca'
const VERSAO_FORMATO = 1

/** LeitorXlsx::TETO_DESCOMPACTADO — o mesmo limite contra "zip bomb". */
const TETO_DESCOMPACTADO = 200 * 1024 * 1024

/** CargaDoCadastro::LIMITE_AUSENCIA — acima disto o sistema pede confirmação. */
const LIMITE_AUSENCIA = 0.20

// ── ColunasDaExportacao ────────────────────────────────────────────────

const CAMPOS = [
  ['Inscrição', 'inscricao'],
  ['Código', 'codigo_cadastro'],
  ['Inscrição Alternativa', 'inscricao_alternativa'],
  ['Código do Bairro', 'codigo_bairro'],
  ['Nome do Bairro', 'nome_bairro'],
  ['Quadra', 'quadra'],
  ['Lote', 'lote'],
  ['Número do Endereço', 'numero_predial'],
  ['Complemento do Endereço', 'complemento'],
  ['Isenção ou Imunidade', 'isencao'],
  ['Área Terreno', 'area_terreno_m2'],
  ['Área Edificada', 'area_edificada_m2'],
  ['Testada Principal', 'testada_m'],
  ['LADO DIR.', 'medida_lado_direito'],
  ['LADO ESQ.', 'medida_lado_esquerdo'],
  ['FUNDO', 'medida_fundo'],
  ['SETOR', 'setor'],
  ['REGIAO FISCAL', 'regiao_fiscal'],
  ['AREA EDIFICADA', 'unidade_area_m2'],
  ['ANO CONSTRUÇÃO', 'unidade_ano'],
  ['PONTOS', 'unidade_pontos'],
]

const CARACTERISTICAS = [
  'OCUPACAO DO LOTE', 'UTILIZACAO', 'TIPO DE IMOVEL', 'BEM IMOV. PATRIMONIO',
  'SITUACAO', 'TOPOGRAFIA', 'PEDOLOGIA', 'ELEMENTO DE PROTECAO',
  'ENERGIA', 'AGUA', 'COLETA DE LIXO', 'ASFALTO', 'CALCADA',
  'REDE DE ESGOTO', 'REDE TELEFONICA', 'GALERIAS', 'ILUMINAÇÃO PUBL',
  'CONSERVACAO DE',
]

const PROPRIETARIO = {
  nome: ['Nome do Proprietário', 'Proprietário', 'Nome do Contribuinte', 'Contribuinte'],
  documento: ['CPF/CNPJ do Proprietário', 'CPF/CNPJ', 'CPF/CNPJ do Contribuinte', 'CPF', 'CNPJ'],
  endereco: ['Endereço de Correspondência', 'Endereço do Proprietário', 'Endereço do Contribuinte'],
}

const CASAS = {
  area_terreno_m2: 2, area_edificada_m2: 2, testada_m: 2,
  medida_lado_direito: 2, medida_lado_esquerdo: 2, medida_fundo: 2,
  unidade_area_m2: 2, unidade_ano: 0, unidade_pontos: 0,
}

// ── o PHP, em miúdos ──────────────────────────────────────────────────

/** trim() do PHP: só " \t\n\r\0\x0B" — o do JS tira também o espaço inseparável. */
function trimPhp(s) {
  return String(s).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '')
}

/** mb_substr($s, 0, n): conta caracteres, não unidades UTF-16. */
function mbSubstr(s, n) {
  const c = Array.from(s)
  return c.length > n ? c.slice(0, n).join('') : s
}

/** is_numeric() do PHP 8 (depois do trim, que já foi feito). */
function ehNumerico(v) {
  return /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/.test(v)
}

/**
 * Número em texto decimal sem expoente ("1.5e+21" → "1500000000000000000000").
 * @param {string} s saída de toPrecision
 */
function semExpoente(s) {
  const m = /^(-?)(\d+)(?:\.(\d+))?e([+-]\d+)$/.exec(s)
  if (!m) return s
  const [, sinal, int, frac = '', e] = m
  const exp = Number(e)
  let digitos = int + frac
  let ponto = int.length + exp
  if (ponto <= 0) { digitos = '0'.repeat(1 - ponto) + digitos; ponto = 1 }
  if (ponto >= digitos.length) return sinal + digitos + '0'.repeat(ponto - digitos.length)
  return sinal + digitos.slice(0, ponto) + '.' + digitos.slice(ponto)
}

/**
 * number_format($n, $casas, '.', '') do PHP: arredonda "metade para longe do
 * zero" sobre o número com 15 algarismos significativos — é o que o
 * arredondamento do PHP faz para não errar em 1.005 (que em binário é
 * 1.00499999…). `toFixed` do JS erra justamente esse caso.
 */
function numberFormat(n, casas) {
  if (!Number.isFinite(n)) return String(n)
  const neg = n < 0
  const texto = semExpoente(Math.abs(n).toPrecision(15))
  let [int, frac = ''] = texto.split('.')
  frac = frac.padEnd(casas + 1, '0')
  const manter = int + frac.slice(0, casas)
  const proximo = Number(frac[casas])
  // Soma 1 no último algarismo mantido, em texto, para não voltar ao binário.
  let digitos = manter
  if (proximo >= 5) {
    const arr = digitos.split('').map(Number)
    let i = arr.length - 1
    while (i >= 0) {
      if (arr[i] === 9) { arr[i] = 0; i-- } else { arr[i]++; break }
    }
    if (i < 0) arr.unshift(1)
    digitos = arr.join('')
  }
  const corte = digitos.length - casas
  let parteInt = digitos.slice(0, corte).replace(/^0+(?=\d)/, '') || '0'
  const parteFrac = casas > 0 ? digitos.slice(corte) : ''
  const zero = /^0*$/.test(parteInt + parteFrac)
  return (neg && !zero ? '-' : '') + parteInt + (casas > 0 ? '.' + parteFrac : '')
}

/** ColunasDaExportacao::numero — "1.234,56", "1234.56" ou "350" → número; o resto, null. */
function numero(v) {
  if (v === null || v === undefined || trimPhp(v) === '') return null
  v = trimPhp(v)
  // `strrpos($v, '.') ?: -1`: ponto na posição 0 também vira -1 (0 é falso no PHP).
  const ponto = v.lastIndexOf('.')
  if (v.includes(',') && v.lastIndexOf(',') > (ponto > 0 ? ponto : -1)) {
    v = v.split('.').join('').split(',').join('.')
  }
  return ehNumerico(v) ? Number(v.trim()) : null
}

/** ColunasDaExportacao::canonico — texto aparado, vazio vira null, número com as casas da tabela. */
function canonico(campo, v) {
  if (v === null || v === undefined) return null
  v = trimPhp(String(v))
  if (v === '') return null
  if (Object.prototype.hasOwnProperty.call(CASAS, campo)) {
    const n = numero(v)
    return n === null ? null : numberFormat(n, CASAS[campo])
  }
  return v
}

/**
 * json_encode($v, JSON_UNESCAPED_UNICODE) do PHP.
 *
 * Difere do JSON.stringify em três pontos que mudam o código de conferência:
 * a barra vira "\/", U+2028/U+2029 são escapados, e o controle sem atalho
 * sai como \u00xx minúsculo. Objetos saem na ordem das chaves, como o array
 * associativo do PHP.
 */
function jsonPhp(v) {
  if (v === null || v === undefined) return 'null'
  if (typeof v === 'string') return textoJson(v)
  if (typeof v === 'number') return Number.isInteger(v) ? String(v) : String(v)
  if (typeof v === 'boolean') return v ? 'true' : 'false'
  if (Array.isArray(v)) return '[' + v.map(jsonPhp).join(',') + ']'
  const chaves = Object.keys(v)
  if (!chaves.length) return '[]'   // array vazio do PHP é lista
  return '{' + chaves.map(k => textoJson(k) + ':' + jsonPhp(v[k])).join(',') + '}'
}

const ESCAPES = { '"': '\\"', '\\': '\\\\', '/': '\\/', '\b': '\\b', '\f': '\\f', '\n': '\\n', '\r': '\\r', '\t': '\\t' }

function textoJson(s) {
  return '"' + s.replace(/["\\/\u0000-\u001f\u2028\u2029]/g, c =>
    ESCAPES[c] ?? '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0')) + '"'
}

/** Ordem de chave do ksort() para estas chaves: a do texto, byte a byte. */
function ordenarChaves(obj) {
  const saida = {}
  for (const k of Object.keys(obj).sort((a, b) => Buffer.compare(Buffer.from(a), Buffer.from(b)))) saida[k] = obj[k]
  return saida
}

/** DiferencaDoCadastro::caracteristicas — JSON normalizado (chaves em ordem). */
function caracteristicasNormalizadas(json) {
  if (json === null || json === undefined || json === '') return null
  let a = json
  if (typeof json === 'string') {
    try { a = JSON.parse(json) } catch { return null }
  }
  if (a === null || typeof a !== 'object' || !Object.keys(a).length) return null
  return jsonPhp(ordenarChaves(a))
}

/** DiferencaDoCadastro::proprietarios — os donos como texto comparável. */
function proprietariosTexto(donos) {
  if (!donos.length) return null
  return jsonPhp(donos.map(d => ({ nome: d.nome ?? null, documento: d.documento ?? null, endereco: d.endereco ?? null })))
}

/** DiferencaDoCadastro::hash — o código de conferência do imóvel. */
function codigoDeConferencia(registro, donos) {
  const r = ordenarChaves(registro)
  r.caracteristicas = caracteristicasNormalizadas(r.caracteristicas ?? null)
  return crypto.createHash('sha1').update(jsonPhp([r, proprietariosTexto(donos)]), 'utf8').digest('hex')
}

// ── LeitorXlsx ────────────────────────────────────────────────────────

/**
 * As partes do .xlsx (um ZIP). Lê o diretório central e descompacta sob
 * demanda, recusando parte maior que o teto — o mesmo cuidado do PHP.
 * @param {Buffer} buf
 * @returns {(nome: string) => (string|false)}
 */
function abrirZip(buf) {
  let fim = -1
  for (let i = buf.length - 22; i >= Math.max(0, buf.length - 22 - 65535); i--) {
    if (buf.readUInt32LE(i) === 0x06054b50) { fim = i; break }
  }
  if (fim < 0) {
    throw new Error('Não consegui abrir o arquivo como .xlsx. Se for .xls antigo (formato binário do '
      + 'Excel 97), reabra no Excel e salve como .xlsx.')
  }
  const total = buf.readUInt16LE(fim + 10)
  let p = buf.readUInt32LE(fim + 16)
  const partes = new Map()
  for (let n = 0; n < total; n++) {
    if (buf.readUInt32LE(p) !== 0x02014b50) throw new Error('O .xlsx está corrompido (diretório do ZIP).')
    const metodo = buf.readUInt16LE(p + 10)
    const compactado = buf.readUInt32LE(p + 20)
    const tamanho = buf.readUInt32LE(p + 24)
    const lenNome = buf.readUInt16LE(p + 28)
    const lenExtra = buf.readUInt16LE(p + 30)
    const lenComent = buf.readUInt16LE(p + 32)
    const local = buf.readUInt32LE(p + 42)
    const nome = buf.toString('utf8', p + 46, p + 46 + lenNome)
    partes.set(nome, { metodo, compactado, tamanho, local })
    p += 46 + lenNome + lenExtra + lenComent
  }

  return nome => {
    const e = partes.get(nome)
    if (!e) return false
    if (e.tamanho === 0xffffffff || e.compactado === 0xffffffff) {
      throw new Error('A planilha é grande demais para ser lida (ZIP64). Exporte só os bairros necessários.')
    }
    if (e.tamanho > TETO_DESCOMPACTADO) {
      throw new Error(`A planilha é grande demais para ser lida (${Math.round(e.tamanho / 1048576)} MB `
        + 'descompactada). Exporte só os bairros necessários.')
    }
    const ini = e.local + 30 + buf.readUInt16LE(e.local + 26) + buf.readUInt16LE(e.local + 28)
    const dado = buf.subarray(ini, ini + e.compactado)
    if (e.metodo === 0) return dado.toString('utf8')
    if (e.metodo === 8) return zlib.inflateRawSync(dado, { maxOutputLength: TETO_DESCOMPACTADO }).toString('utf8')
    throw new Error('O .xlsx usa uma compactação que o app não conhece.')
  }
}

/** Texto de nó XML como a libxml entrega: fim de linha normalizado, entidades resolvidas. */
function textoXml(s) {
  return s.replace(/\r\n?/g, '\n').replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, (_, c) => c.replace(/&/g, '&amp;').replace(/</g, '&lt;'))
    .replace(/&(#x[0-9a-fA-F]+|#\d+|amp|lt|gt|quot|apos);/g, (_, e) => {
      if (e[0] === '#') {
        const cp = e[1] === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10)
        return String.fromCodePoint(cp)
      }
      return { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" }[e]
    })
}

/** Conteúdo do primeiro filho `<nome>` DIRETO (o (string)$el->nome do SimpleXML). */
const RE_FILHO = {
  t: /<t(?:\s[^>]*)?(?:\/>|>([\s\S]*?)<\/t>)/,
  v: /<v(?:\s[^>]*)?(?:\/>|>([\s\S]*?)<\/v>)/,
}

function filhoDireto(xml, nome) {
  // Tira os filhos que podem conter um <nome> neto: runs de texto rico
  // (<r>) e fonética (<rPh>). Só o filho direto conta, como no SimpleXML.
  // (O teste do '<r' poupa a limpeza nas 2 milhões de células comuns.)
  const limpo = xml.includes('<r') ? xml.replace(/<(r|rPh)\b[^>]*\/>|<(r|rPh)\b[^>]*>[\s\S]*?<\/\2>/g, '') : xml
  const m = RE_FILHO[nome].exec(limpo)
  if (!m) return null
  const t = m[1] ?? ''
  return /[&<\r]/.test(t) ? textoXml(t) : t
}

const RE_ATRIBUTO = {}

function atributo(tag, nome) {
  const re = RE_ATRIBUTO[nome] ??= new RegExp(`\\s${nome}\\s*=\\s*(?:"([^"]*)"|'([^']*)')`)
  const m = re.exec(tag)
  if (!m) return ''
  const v = m[1] ?? m[2]
  return /[&\r]/.test(v) ? textoXml(v) : v
}

/** LeitorXlsx::carregarTextos — a tabela de textos compartilhados. */
function textosCompartilhados(ler) {
  const xml = ler('xl/sharedStrings.xml')
  if (xml === false) return []
  const textos = []
  for (const m of xml.matchAll(/<si(?:\s[^>]*)?(?:\/>|>([\s\S]*?)<\/si>)/g)) {
    const si = m[1] ?? ''
    const t = filhoDireto(si, 't')
    if (t !== null) { textos.push(t); continue }
    let junto = ''
    for (const r of si.matchAll(/<r(?:\s[^>]*)?>([\s\S]*?)<\/r>/g)) junto += filhoDireto(r[1], 't') ?? ''
    textos.push(junto)
  }
  return textos
}

/** LeitorXlsx::caminhoDaPrimeiraAba */
function caminhoDaPrimeiraAba(ler) {
  const wb = ler('xl/workbook.xml')
  const rels = ler('xl/_rels/workbook.xml.rels')
  if (wb !== false && rels !== false) {
    const sheet = /<sheet\b[^>]*>/.exec(wb)
    const id = sheet ? (/\sr:id\s*=\s*"([^"]*)"/.exec(sheet[0]) ?? [])[1] : undefined
    for (const rel of rels.matchAll(/<Relationship\b[^>]*>/g)) {
      if (id !== undefined && atributo(rel[0], 'Id') === id) {
        return 'xl/' + atributo(rel[0], 'Target').replace(/^\/+/, '')
      }
    }
  }
  return 'xl/worksheets/sheet1.xml'
}

/** LeitorXlsx::indiceDaColuna — "BC12" → 54. */
function indiceDaColuna(ref) {
  const letras = ref.replace(/[0-9]+$/, '')
  let n = 0
  for (const l of letras) {
    let c = l.charCodeAt(0)
    if (c >= 97 && c <= 122) c -= 32
    n = n * 26 + (c - 64)
  }
  return Math.max(0, n - 1)
}

/**
 * LeitorXlsx::linhas — as linhas da primeira aba, cada uma como lista de
 * células em texto, com os buracos preenchidos.
 * @param {Buffer} buf o arquivo .xlsx
 */
function* linhasDaPlanilha(buf) {
  const ler = abrirZip(buf)
  const textos = textosCompartilhados(ler)
  const xml = ler(caminhoDaPrimeiraAba(ler))
  if (xml === false) throw new Error('A planilha não tem aba legível.')
  // XML com caractere de controle cru é inválido; a leitura do sistema PARA
  // nele em silêncio e a carga sairia cortada. O Excel nunca grava assim
  // (usa _x0001_), então é arquivo corrompido ou gerado à mão: recusa.
  if (/[\x00-\x08\x0B\x0C\x0E-\x1F]/.test(xml)) {
    throw new Error('A planilha tem caractere inválido no XML (arquivo corrompido?). Abra no Excel e salve de novo.')
  }

  for (const row of xml.matchAll(/<row\b[^>]*?(?:\/>|>([\s\S]*?)<\/row>)/g)) {
    const saida = new Map()
    let max = -1
    for (const c of (row[1] ?? '').matchAll(/<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g)) {
      const attrs = ' ' + c[1]
      const corpo = c[2] ?? ''
      const i = indiceDaColuna(atributo(attrs, 'r'))
      const tipo = atributo(attrs, 't')
      let valor
      if (tipo === 's') {
        valor = textos[parseInt(filhoDireto(corpo, 'v') ?? '', 10) || 0] ?? ''
      } else if (tipo === 'inlineStr') {
        const is = /<is(?:\s[^>]*)?>([\s\S]*?)<\/is>/.exec(corpo)
        valor = trimPhp(is ? (filhoDireto(is[1], 't') ?? '') : '')
      } else {
        valor = trimPhp(filhoDireto(corpo, 'v') ?? '')
      }
      saida.set(i, valor)
      if (i > max) max = i
    }
    const lista = []
    for (let i = 0; i <= max; i++) lista.push(saida.get(i) ?? '')
    yield lista
  }
}

// ── CargaDoCadastro::ler ──────────────────────────────────────────────

/** ColunasDaExportacao::cabecalho — nome da coluna → posição (a última, se repetir). */
function cabecalho(celulas) {
  const nomes = celulas.map(trimPhp)
  if (!nomes.includes('Inscrição')) return null
  const pos = new Map()
  nomes.forEach((n, i) => pos.set(n, i))
  return pos
}

/** ColunasDaExportacao::linha */
function registroDaLinha(lerCol) {
  if (trimPhp(lerCol('Inscrição')) === '') return null
  const r = {}
  for (const [col, campo] of CAMPOS) r[campo] = canonico(campo, lerCol(col))
  r.logradouro = canonico('logradouro', trimPhp(trimPhp(lerCol('Tipo de Logradouro')) + ' ' + trimPhp(lerCol('Nome do Logradouro'))))
  const carac = {}
  for (const col of CARACTERISTICAS) {
    const v = trimPhp(lerCol(col))
    if (v !== '' && v !== '-') carac[col] = v
  }
  r.caracteristicas = Object.keys(carac).length ? jsonPhp(carac) : null
  return r
}

/** ColunasDaExportacao::proprietario */
function proprietarioDaLinha(lerCol) {
  const campo = qual => {
    for (const col of PROPRIETARIO[qual]) {
      const v = trimPhp(lerCol(col))
      if (v !== '' && v !== '-') return v
    }
    return null
  }
  const nome = campo('nome')
  if (nome === null) return null
  const doc = campo('documento')
  const end = campo('endereco')
  return {
    nome: mbSubstr(nome, 200),
    documento: doc !== null ? mbSubstr(doc, 24) : null,
    endereco: end !== null ? mbSubstr(end, 300) : null,
  }
}

/**
 * A planilha inteira, por inscrição, como a carga do sistema a lê.
 * @param {Iterable<string[]>} linhas
 * @param {(n: number) => void} [progresso] chamado a cada 2.000 linhas
 */
function lerCadastro(linhas, progresso) {
  let pos = null
  let lidas = 0
  const registros = new Map()
  const donos = new Map()
  const bairros = new Set()

  for (const celulas of linhas) {
    if (pos === null) { pos = cabecalho(celulas); continue }
    const lerCol = col => pos.has(col) ? trimPhp(celulas[pos.get(col)] ?? '') : ''
    const r = registroDaLinha(lerCol)
    if (r === null) continue

    const insc = r.inscricao
    registros.set(insc, r)   // a última linha da inscrição vale para o imóvel
    const dono = proprietarioDaLinha(lerCol)
    if (dono) {
      if (!donos.has(insc)) donos.set(insc, new Map())
      donos.get(insc).set((dono.nome + '|' + (dono.documento ?? '')).toLowerCase(), dono)
    }
    if (r.codigo_bairro !== null) bairros.add(r.codigo_bairro.replace(/^0+/, ''))
    if (++lidas % 2000 === 0 && progresso) progresso(lidas)
  }

  if (pos === null) throw new Error('Não achei a linha de cabeçalho (a que tem a coluna "Inscrição").')
  if (!registros.size) throw new Error('Nenhuma linha com inscrição. A planilha está vazia ou é de outro formato.')

  const colunas = new Set(pos.keys())
  const faltando = [
    ...CAMPOS.map(c => c[0]), 'Tipo de Logradouro', 'Nome do Logradouro',
  ].filter(c => !colunas.has(c))
  if (!PROPRIETARIO.nome.some(c => colunas.has(c))) faltando.push('proprietário (nome)')
  if (!PROPRIETARIO.documento.some(c => colunas.has(c))) faltando.push('proprietário (CPF/CNPJ)')

  return { registros, donos, bairros, lidas, faltando }
}

// ── referência e diferença ────────────────────────────────────────────

/** Confere o arquivo de referência baixado do sistema. */
function lerReferencia(texto) {
  let d
  try { d = JSON.parse(texto) } catch { throw new Error('O arquivo de referência não é um JSON válido.') }
  if (!d || d.formato !== FORMATO_REFERENCIA) {
    throw new Error('Este arquivo não é a referência do cadastro. Baixe-a em Parâmetros › Cadastro municipal.')
  }
  if (d.versao !== VERSAO_FORMATO) throw new Error('A referência é de outra versão do sistema. Atualize o app.')
  if (typeof d.conferencia !== 'string' || !Array.isArray(d.imoveis)) throw new Error('A referência está incompleta.')
  const imoveis = new Map()
  for (const i of d.imoveis) imoveis.set(String(i[0]), { hash: i[1] ?? null, bairro: String(i[2] ?? ''), ausente: !!i[3] })
  return { baseCargaId: d.base_carga_id ?? null, conferencia: d.conferencia, geradaEm: d.gerada_em ?? null, imoveis }
}

/**
 * Compara a planilha com a referência e monta o JSON que o sistema aceita.
 *
 * Vai INTEIRO só o imóvel novo, alterado, ou que o sistema ainda não tem
 * código (carga antiga). O igual vai só como inscrição — o sistema já tem o
 * dado, e é a lista dos iguais que deixa ele marcar quem SUMIU da planilha.
 *
 * @param {ReturnType<typeof lerCadastro>} cad
 * @param {ReturnType<typeof lerReferencia>} ref
 * @param {{nome: string, bytes: number, sha256: string}} planilha
 */
function gerarDiferenca(cad, ref, planilha) {
  const registros = []
  const iguais = []
  const r = { novos: 0, alterados: 0, sem_codigo: 0, iguais: 0, reaparecidos: 0, ausentes: 0, universo: 0 }

  for (const [insc, reg] of cad.registros) {
    const donos = [...(cad.donos.get(insc)?.values() ?? [])]
    const hash = codigoDeConferencia(reg, donos)
    const atual = ref.imoveis.get(insc)
    if (atual?.ausente) r.reaparecidos++
    if (atual && atual.hash === hash) { iguais.push(insc); r.iguais++; continue }
    if (!atual) r.novos++
    else if (atual.hash === null) r.sem_codigo++
    else r.alterados++
    registros.push({ inscricao: insc, hash, dados: reg, proprietarios: donos })
  }

  // A mesma conta de ausência do sistema, para avisar ANTES de anexar.
  const porBairro = {}
  for (const [insc, atual] of ref.imoveis) {
    if (!cad.bairros.has(atual.bairro)) continue
    r.universo++
    if (!cad.registros.has(insc) && !atual.ausente) {
      r.ausentes++
      porBairro[atual.bairro] = (porBairro[atual.bairro] ?? 0) + 1
    }
  }

  return {
    json: {
      formato: FORMATO_DIFERENCA,
      versao: VERSAO_FORMATO,
      gerado_em: new Date().toISOString(),
      app: { nome: 'FiscObras Cadastro', versao: VERSAO_APP },
      referencia: { base_carga_id: ref.baseCargaId, conferencia: ref.conferencia, gerada_em: ref.geradaEm },
      planilha: { nome: planilha.nome, bytes: planilha.bytes, sha256: planilha.sha256, linhas: cad.lidas },
      resumo: { novos: r.novos, alterados: r.alterados + r.sem_codigo, iguais: r.iguais,
        reaparecidos: r.reaparecidos, ausentes: r.ausentes },
      registros,
      iguais,
    },
    resumo: {
      ...r,
      linhas: cad.lidas,
      imoveis: cad.registros.size,
      bairros: cad.bairros.size,
      faltando: cad.faltando,
      ausentesPorBairro: porBairro,
      pedeConfirmacao: r.universo > 0 && r.ausentes / r.universo > LIMITE_AUSENCIA,
    },
  }
}

module.exports = {
  VERSAO_APP, FORMATO_DIFERENCA, FORMATO_REFERENCIA,
  trimPhp, numberFormat, numero, canonico, jsonPhp, codigoDeConferencia,
  linhasDaPlanilha, lerCadastro, lerReferencia, gerarDiferenca,
}
