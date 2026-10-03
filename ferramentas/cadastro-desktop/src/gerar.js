'use strict'
// Os arquivos de entrada → o JSON de diferenças. Usado pelo processo de fundo
// do app (processar.js) e pela linha de comando (cli.js); a tela nunca toca
// na planilha.
const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')
const N = require('./nucleo.js')

/**
 * @param {string} caminhoReferencia o .json baixado do sistema
 * @param {string} caminhoPlanilha o .xlsx da prefeitura
 * @param {(n: number) => void} [progresso]
 */
function gerar(caminhoReferencia, caminhoPlanilha, progresso) {
  const ref = N.lerReferencia(fs.readFileSync(caminhoReferencia, 'utf8'))
  const buf = fs.readFileSync(caminhoPlanilha)
  const cad = N.lerCadastro(N.linhasDaPlanilha(buf), progresso)
  return N.gerarDiferenca(cad, ref, {
    nome: path.basename(caminhoPlanilha),
    bytes: buf.length,
    sha256: crypto.createHash('sha256').update(buf).digest('hex'),
  })
}

module.exports = { gerar }
