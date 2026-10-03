#!/usr/bin/env node
'use strict'
// Linha de comando, sem a janela:
//   node src/cli.js referencia.json planilha.xlsx saida.json
const fs = require('node:fs')
const { gerar } = require('./gerar.js')

const [ref, planilha, saida] = process.argv.slice(2)
if (!ref || !planilha || !saida) {
  console.error('uso: node src/cli.js referencia.json planilha.xlsx saida.json')
  process.exit(2)
}
try {
  const { json, resumo } = gerar(ref, planilha)
  fs.writeFileSync(saida, JSON.stringify(json))
  const { ausentesPorBairro, faltando, ...numeros } = resumo
  console.log(JSON.stringify({ ...numeros, faltando }))
} catch (e) {
  console.error(e.message)
  process.exit(1)
}
