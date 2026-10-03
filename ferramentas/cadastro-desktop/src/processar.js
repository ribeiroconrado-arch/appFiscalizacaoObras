'use strict'
// Processo de fundo: lê a planilha e monta o JSON sem travar a janela.
const { parentPort, workerData } = require('node:worker_threads')
const { gerar } = require('./gerar.js')

try {
  const { json, resumo } = gerar(workerData.referencia, workerData.planilha,
    linhas => parentPort.postMessage({ tipo: 'progresso', linhas }))
  parentPort.postMessage({ tipo: 'fim', json, resumo })
} catch (e) {
  parentPort.postMessage({ tipo: 'erro', mensagem: e.message })
}
