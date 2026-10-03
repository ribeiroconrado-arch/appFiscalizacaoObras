'use strict'
// ══════════════════════════════════════════════════════════════════════
// FiscObras Cadastro — o processo principal da janela.
//
// SEGURANÇA, que é a razão de o app existir:
//  - nada sai do PC: toda requisição de rede é cancelada (onBeforeRequest),
//    e a página não tem acesso ao Node (contextIsolation + sandbox);
//  - a tela não escolhe caminho de arquivo: quem abre os diálogos e guarda
//    os caminhos é este processo. A tela só pede "escolher", "gerar" e
//    "salvar" (ver preload.js);
//  - a planilha é lida num processo de fundo (src/processar.js), para a
//    janela não congelar nas 56 mil linhas.
// ══════════════════════════════════════════════════════════════════════
const { app, BrowserWindow, Menu, dialog, ipcMain, session, shell } = require('electron')
const path = require('node:path')
const fs = require('node:fs')
const { Worker } = require('node:worker_threads')
const N = require('./src/nucleo.js')

/** O que o usuário escolheu e o que foi gerado — mora aqui, não na tela. */
const estado = { referencia: null, planilha: null, resultado: null, salvoEm: null }
let janela = null

if (!app.requestSingleInstanceLock()) app.quit()
app.on('second-instance', () => { if (janela) { if (janela.isMinimized()) janela.restore(); janela.focus() } })

app.whenReady().then(() => {
  // Nenhuma conexão: o app trabalha só com arquivos locais.
  session.defaultSession.webRequest.onBeforeRequest({ urls: ['http://*/*', 'https://*/*', 'ws://*/*', 'wss://*/*'] },
    (_d, cb) => cb({ cancel: true }))
  session.defaultSession.setPermissionRequestHandler((_wc, _p, cb) => cb(false))
  Menu.setApplicationMenu(null)

  janela = new BrowserWindow({
    width: 900, height: 820, minWidth: 640, minHeight: 560,
    title: 'FiscObras Cadastro', backgroundColor: '#f3f1ef', show: false,
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: true },
  })
  janela.webContents.setWindowOpenHandler(() => ({ action: 'deny' }))
  janela.webContents.on('will-navigate', e => e.preventDefault())
  janela.once('ready-to-show', () => janela.show())
  janela.loadFile(path.join(__dirname, 'tela', 'index.html'))
})
app.on('window-all-closed', () => app.quit())

ipcMain.handle('versao', () => N.VERSAO_APP)

ipcMain.handle('escolher-referencia', async () => {
  const r = await dialog.showOpenDialog(janela, {
    title: 'Referência do sistema', properties: ['openFile'],
    filters: [{ name: 'Referência do cadastro (.json)', extensions: ['json'] }],
  })
  if (r.canceled || !r.filePaths[0]) return null
  const caminho = r.filePaths[0]
  try {
    const ref = N.lerReferencia(fs.readFileSync(caminho, 'utf8'))
    estado.referencia = caminho
    estado.resultado = null
    return { nome: path.basename(caminho), geradaEm: ref.geradaEm, total: ref.imoveis.size }
  } catch (e) {
    estado.referencia = null
    return { erro: e.message }
  }
})

ipcMain.handle('escolher-planilha', async () => {
  const r = await dialog.showOpenDialog(janela, {
    title: 'Planilha da prefeitura', properties: ['openFile'],
    filters: [{ name: 'Planilha do Excel (.xlsx)', extensions: ['xlsx'] }],
  })
  if (r.canceled || !r.filePaths[0]) return null
  const caminho = r.filePaths[0]
  estado.planilha = caminho
  estado.resultado = null
  return { nome: path.basename(caminho), bytes: fs.statSync(caminho).size }
})

ipcMain.handle('gerar', () => new Promise(resolve => {
  if (!estado.referencia || !estado.planilha) return resolve({ erro: 'Escolha a referência e a planilha.' })
  estado.resultado = null
  estado.salvoEm = null
  const w = new Worker(path.join(__dirname, 'src', 'processar.js'),
    { workerData: { referencia: estado.referencia, planilha: estado.planilha } })
  w.on('message', m => {
    if (m.tipo === 'progresso') { janela?.webContents.send('progresso', m.linhas); return }
    if (m.tipo === 'erro') { resolve({ erro: m.mensagem }); return }
    estado.resultado = m.json
    resolve({ resumo: m.resumo })
  })
  w.on('error', e => resolve({ erro: e.message }))
}))

ipcMain.handle('salvar', async () => {
  if (!estado.resultado) return { erro: 'Gere o arquivo primeiro.' }
  const hoje = new Date().toISOString().slice(0, 10)
  const r = await dialog.showSaveDialog(janela, {
    title: 'Salvar o arquivo para o sistema',
    defaultPath: path.join(app.getPath('documents'), `cadastro-diferenca-${hoje}.json`),
    filters: [{ name: 'JSON do cadastro', extensions: ['json'] }],
  })
  if (r.canceled || !r.filePath) return null
  fs.writeFileSync(r.filePath, JSON.stringify(estado.resultado))
  estado.salvoEm = r.filePath
  return { caminho: r.filePath, nome: path.basename(r.filePath), bytes: fs.statSync(r.filePath).size }
})

ipcMain.handle('mostrar-na-pasta', () => { if (estado.salvoEm) shell.showItemInFolder(estado.salvoEm) })
