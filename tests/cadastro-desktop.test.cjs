// O núcleo do app desktop do cadastro (ferramentas/cadastro-desktop) tem de
// ler a planilha e calcular o código de conferência EXATAMENTE como o PHP do
// sistema — senão todo imóvel parece alterado todo mês.
//
// `fixtures/cadastro-dificil.xlsx` é fictícia e só tem casos de borda: texto
// compartilhado e rico, entidades, CRLF, espaço inseparável, células omitidas,
// número com vírgula, coluna repetida, várias unidades e donos por inscrição.
// `cadastro-dificil.esperado.json` é o que o PHP extrai dela (o laço de
// CargaDoCadastro::ler sobre LeitorXlsx e ColunasDaExportacao) — gerado no
// PHP, não à mão. Se a regra mudar de um lado, este teste quebra.
const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const zlib = require('node:zlib')
const N = require('../ferramentas/cadastro-desktop/src/nucleo.js')

const fixture = fs.readFileSync(path.join(__dirname, 'fixtures/cadastro-dificil.xlsx'))
const esperado = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/cadastro-dificil.esperado.json'), 'utf8'))
const copia = v => JSON.parse(JSON.stringify(v))

test('lê a planilha como o PHP: registros, donos, bairros e linhas', () => {
  const cad = N.lerCadastro(N.linhasDaPlanilha(fixture))
  assert.equal(cad.lidas, esperado.lidas)
  assert.deepEqual([...cad.bairros].sort(), esperado.bairros.map(String).sort())
  assert.deepEqual([...cad.registros.keys()], Object.keys(esperado.imoveis))
  for (const [insc, e] of Object.entries(esperado.imoveis)) {
    assert.deepEqual(copia(cad.registros.get(insc)), e.registro, insc)
    assert.deepEqual(copia([...(cad.donos.get(insc)?.values() ?? [])]), e.donos, insc)
  }
})

test('código de conferência idêntico ao DiferencaDoCadastro::hash do PHP', () => {
  const cad = N.lerCadastro(N.linhasDaPlanilha(fixture))
  for (const [insc, e] of Object.entries(esperado.imoveis)) {
    assert.equal(N.codigoDeConferencia(cad.registros.get(insc), [...(cad.donos.get(insc)?.values() ?? [])]), e.hash, insc)
  }
})

test('texto rico junta todos os trechos (o PHP guardava só o último)', () => {
  assert.equal(esperado.imoveis['01.124.004.0020.000'].registro.logradouro, 'Rua das Acácias')
})

test('number_format do PHP: arredonda metade para longe do zero, sem o erro binário do toFixed', () => {
  // Valores conferidos com number_format() do PHP.
  const casos = [['1.005', 2, '1.01'], ['2.675', 2, '2.68'], ['0.285', 2, '0.29'], ['-2.5', 0, '-3'], ['2.5', 0, '3'],
    ['-0.001', 2, '0.00'], ['999999999999.995', 2, '1000000000000.00'], ['1e3', 2, '1000.00'], ['80.125', 2, '80.13'],
    ['1234.5650', 2, '1234.57'], ['0.30000000000000004', 2, '0.30'], ['2008.5', 0, '2009']]
  for (const [v, casas, php] of casos) assert.equal(N.numberFormat(Number(v), casas), php, v)
})

test('canônico: vírgula decimal, vazio, "-" e texto não numérico', () => {
  assert.equal(N.canonico('area_terreno_m2', '1.234,56'), '1234.56')
  assert.equal(N.canonico('area_terreno_m2', '1.000.000,999'), '1000001.00')
  assert.equal(N.canonico('area_terreno_m2', '.5'), '0.50')
  assert.equal(N.canonico('area_terreno_m2', 'abc'), null)
  assert.equal(N.canonico('area_terreno_m2', '-'), null)
  assert.equal(N.canonico('quadra', ' 004 '), '004')
  assert.equal(N.canonico('quadra', '   '), null)
  // trim do PHP não tira o espaço inseparável
  assert.equal(N.canonico('quadra', '4 '), '4 ')
})

test('json_encode do PHP: barra escapada, U+2028 escapado, acento cru', () => {
  assert.equal(N.jsonPhp({ a: 'x/y "z"', b: null, c: 'Ação \u0001\t' }),
    '{"a":"x\\/y \\"z\\"","b":null,"c":"Ação\\u2028\\u0001\\t"}')
  assert.equal(N.jsonPhp([]), '[]')
})

/** .xlsx mínimo em memória, para casos que a fixture não cobre. */
function xlsxMinimo(sheetXml) {
  const partes = [
    ['xl/workbook.xml', '<workbook><sheets><sheet r:id="rId1"/></sheets></workbook>'],
    ['xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>'],
    ['xl/worksheets/sheet1.xml', sheetXml],
  ]
  const locais = [], central = []
  let off = 0
  for (const [nome, texto] of partes) {
    const dado = zlib.deflateRawSync(Buffer.from(texto)), n = Buffer.from(nome)
    const h = Buffer.alloc(30); h.writeUInt32LE(0x04034b50, 0); h.writeUInt16LE(8, 8); h.writeUInt32LE(dado.length, 18)
    h.writeUInt32LE(Buffer.byteLength(texto), 22); h.writeUInt16LE(n.length, 26)
    const c = Buffer.alloc(46); c.writeUInt32LE(0x02014b50, 0); c.writeUInt16LE(8, 10); c.writeUInt32LE(dado.length, 20)
    c.writeUInt32LE(Buffer.byteLength(texto), 24); c.writeUInt16LE(n.length, 28); c.writeUInt32LE(off, 42)
    locais.push(h, n, dado); central.push(c, n); off += 30 + n.length + dado.length
  }
  const cd = Buffer.concat(central), fim = Buffer.alloc(22)
  fim.writeUInt32LE(0x06054b50, 0); fim.writeUInt16LE(partes.length, 8); fim.writeUInt16LE(partes.length, 10)
  fim.writeUInt32LE(cd.length, 12); fim.writeUInt32LE(off, 16)
  return Buffer.concat([...locais, cd, fim])
}

const linha = (n, cels) => `<row r="${n}">` + cels.map((v, i) => `<c r="${String.fromCharCode(65 + i)}${n}" t="inlineStr"><is><t>${v}</t></is></c>`).join('') + '</row>'

test('recusa planilha sem cabeçalho, sem linhas, com XML inválido ou que não é .xlsx', () => {
  assert.throws(() => N.lerCadastro(N.linhasDaPlanilha(xlsxMinimo('<sheetData>' + linha(1, ['a']) + '</sheetData>'))), /cabeçalho/)
  assert.throws(() => N.lerCadastro(N.linhasDaPlanilha(xlsxMinimo('<sheetData>' + linha(1, ['Inscrição']) + '</sheetData>'))), /Nenhuma linha/)
  assert.throws(() => [...N.linhasDaPlanilha(xlsxMinimo('<sheetData>' + linha(1, ['a\u0001']) + '</sheetData>'))], /caractere inválido/)
  assert.throws(() => [...N.linhasDaPlanilha(Buffer.from('não é zip nenhum, só texto qualquer aqui'))], /\.xlsx/)
})

test('diferença: novo, alterado, sem código, igual, reaparecido e ausente', () => {
  const cab = ['Inscrição', 'Código do Bairro', 'Área Terreno', 'Nome do Proprietário', 'CPF/CNPJ', 'Quadra', 'Lote']
  const xml = '<sheetData>' + [cab, ['A1', '000900', '100', 'ANA', '1', '1', '1'], ['A2', '900', '200', 'BIA', '2', '1', '2'],
    ['A3', '900', '300', '', '', '1', '3'], ['A4', '900', '400', 'CAU', '4', '1', '4'], ['A5', '000900', '500', '', '', '1', '5'],
    ['B1', '901', '1', '', '', '2', '1']]
    .map((l, i) => linha(i + 1, l)).join('') + '</sheetData>'
  const cad = N.lerCadastro(N.linhasDaPlanilha(xlsxMinimo(xml)))
  const h = insc => N.codigoDeConferencia(cad.registros.get(insc), [...(cad.donos.get(insc)?.values() ?? [])])
  const ref = N.lerReferencia(JSON.stringify({
    formato: N.FORMATO_REFERENCIA, versao: 1, base_carga_id: 7, conferencia: 'abc', gerada_em: 'x',
    imoveis: [['A1', h('A1'), '900', 0], ['A2', 'velho', '900', 0], ['A3', null, '900', 0], ['A4', h('A4'), '900', 1],
      ['A9', 'z', '900', 0], ['A8', 'z', '900', 1], ['C1', 'z', '902', 0]],
  }))
  const { json, resumo } = N.gerarDiferenca(cad, ref, { nome: 'p.xlsx', bytes: 1, sha256: 's' })

  assert.deepEqual(json.iguais.sort(), ['A1', 'A4'])
  assert.deepEqual(json.registros.map(r => r.inscricao).sort(), ['A2', 'A3', 'A5', 'B1'])
  assert.equal(resumo.novos, 2)        // A5 e B1
  assert.equal(resumo.alterados, 1)    // A2
  assert.equal(resumo.sem_codigo, 1)   // A3: carga antiga, sem código
  assert.equal(resumo.reaparecidos, 1) // A4
  assert.equal(resumo.ausentes, 1)     // A9 (A8 já estava ausente; C1 é de bairro que não veio)
  assert.deepEqual(json.referencia, { base_carga_id: 7, conferencia: 'abc', gerada_em: 'x' })
  assert.equal(json.planilha.linhas, 6)
  // o registro vai inteiro, com o mesmo código que o sistema vai recalcular
  const a2 = json.registros.find(r => r.inscricao === 'A2')
  assert.equal(a2.hash, h('A2'))
  assert.deepEqual(a2.proprietarios, [{ nome: 'BIA', documento: '2', endereco: null }])
})

// O RELATÓRIO "IMOBILIÁRIO URBANO" da prefeitura tem outros nomes de coluna e
// não traz o código do bairro. Em 03/10/2026 uma carga feita com ele gravou
// 56.587 imóveis só com inscrição e código. Agora os nomes dele são aceitos, e
// bairro, quadra e lote saem da inscrição quando a planilha não os dá.
test('relatório "Imobiliário Urbano": outros nomes de coluna, e bairro/quadra/lote pela inscrição', () => {
  const titulo = ['Imóvel', '', '', '', '', '', '', '', '', '', '', '']
  const cab = ['Código', 'Situação', 'Inscrição', 'Nome', 'Área m²', 'Edificada', 'Logradouro (cadastro)',
    'Número (cadastro)', 'Bairro (cadastro)', 'Complemento (cadastro)', 'Quadra (cadastro)', 'Lote (cadastro)']
  const xml = '<sheetData>' + [titulo, cab,
    ['1', 'Ativo', '010010080019000', 'FULANO DE TAL', '600', '321.89999999999998', 'CUIABA', '156', 'CIDADE PRIMAVERA I', '', '008', '0019'],
    ['2', 'Inativo', '011050350001000', '', '16885,27', '', 'RUA DAS ACÁCIAS', '', 'JARDIM EUROPA IV', 'Casa B', '', ''],
  ].map((l, i) => linha(i + 1, l)).join('') + '</sheetData>'
  const cad = N.lerCadastro(N.linhasDaPlanilha(xlsxMinimo(xml)))

  const a = cad.registros.get('010010080019000')
  assert.equal(a.codigo_cadastro, '1')
  assert.equal(a.codigo_bairro, '001')            // da inscrição
  assert.equal(a.quadra, '008')                    // da coluna "Quadra (cadastro)"
  assert.equal(a.lote, '0019')
  assert.equal(a.nome_bairro, 'CIDADE PRIMAVERA I')
  assert.equal(a.isencao, 'Ativo')
  assert.equal(a.area_terreno_m2, '600.00')
  assert.equal(a.area_edificada_m2, '321.90')
  assert.equal(a.logradouro, 'CUIABA')
  assert.equal(a.numero_predial, '156')
  assert.deepEqual([...cad.donos.get('010010080019000').values()], [{ nome: 'FULANO DE TAL', documento: null, endereco: null }])

  const b = cad.registros.get('011050350001000')
  assert.equal(b.codigo_bairro, '105')             // sem quadra e lote na planilha:
  assert.equal(b.quadra, '035')                    // saem todos da inscrição
  assert.equal(b.lote, '0001')
  assert.equal(b.area_terreno_m2, '16885.27')
  assert.equal(b.complemento, 'Casa B')
  assert.equal(cad.donos.has('011050350001000'), false)   // sem nome, sem proprietário: não é obrigatório
  assert.deepEqual([...cad.bairros].sort(), ['1', '105'])
  // conferido com DiferencaDoCadastro::hash do PHP (tests/Unit/RelatorioImobiliarioUrbanoTest.php)
  assert.equal(N.codigoDeConferencia(a, [...cad.donos.get('010010080019000').values()]), '79b47cb5c57134164ad69a7123750d8c48a44cf9')
  assert.equal(N.codigoDeConferencia(b, []), '36a62bbbfee9c6fe8ce23b20b512c36d11774119')
  // do que o importador conhece, este relatório só não tem o que ele de fato não traz
  assert.ok(!cad.faltando.includes('Quadra') && !cad.faltando.includes('Área Terreno') && !cad.faltando.includes('Logradouro'))
  assert.ok(cad.faltando.includes('Testada Principal'))
})

test('planilha que não localiza os imóveis (sem bairro, quadra e lote) é recusada', () => {
  const cab = ['Inscrição', 'Código', 'Coluna Estranha']
  const xml = '<sheetData>' + [cab, ['A1', '1', 'x'], ['A2', '2', 'y']].map((l, i) => linha(i + 1, l)).join('') + '</sheetData>'
  assert.throws(() => N.lerCadastro(N.linhasDaPlanilha(xlsxMinimo(xml))), /recusada: só 0 de 2/)
})

test('referência: recusa arquivo que não é a referência do sistema', () => {
  assert.throws(() => N.lerReferencia('{'), /JSON válido/)
  assert.throws(() => N.lerReferencia(JSON.stringify({ formato: 'outro' })), /referência do cadastro/)
  assert.throws(() => N.lerReferencia(JSON.stringify({ formato: N.FORMATO_REFERENCIA, versao: 2 })), /versão/)
})
