'use strict'
// A ponte entre a tela e o processo principal: só estes gestos, nenhum
// acesso a arquivo ou caminho. Ver main.js.
const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('cadastro', {
  versao: () => ipcRenderer.invoke('versao'),
  escolherReferencia: () => ipcRenderer.invoke('escolher-referencia'),
  escolherPlanilha: () => ipcRenderer.invoke('escolher-planilha'),
  gerar: () => ipcRenderer.invoke('gerar'),
  salvar: () => ipcRenderer.invoke('salvar'),
  mostrarNaPasta: () => ipcRenderer.invoke('mostrar-na-pasta'),
  aoProgredir: fn => ipcRenderer.on('progresso', (_e, linhas) => fn(linhas)),
})
