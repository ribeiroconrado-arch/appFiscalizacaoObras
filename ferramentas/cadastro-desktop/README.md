# FiscObras Cadastro — app desktop

Gera, **no PC da prefeitura**, o arquivo que atualiza o cadastro imobiliário do
sistema. A planilha completa da prefeitura (com o CPF de todo o município) não
sai do computador: o app lê o `.xlsx`, compara com a **referência** baixada do
sistema e salva um `.json` só com o que mudou.

## Baixar

[FiscObras-Cadastro-win-x64.zip](https://github.com/ribeiroconrado-arch/appFiscalizacaoObras/releases/download/cadastro-desktop-v1.0.0/FiscObras-Cadastro-win-x64.zip)
— extraia e abra `FiscObras Cadastro.exe`. Não precisa instalar.

## Uso (todo mês)

1. No sistema: **Parâmetros › Cadastro municipal › Baixar referência**.
   A referência tem só inscrições e códigos de conferência — nenhum nome ou CPF.
2. No app: escolha a referência e a planilha `.xlsx` da prefeitura, clique em
   **Gerar** e confira os números (novos, alterados, iguais, que sumiram).
3. **Salvar arquivo…** e, no sistema, anexe o `.json` em
   **Parâmetros › Cadastro municipal**.
4. Apague o `.json` do computador depois de anexar: ele traz nome e CPF/CNPJ dos
   proprietários novos ou alterados.

Ao anexar, o navegador **compacta** o `.json` (gzip) e o sistema descompacta:
na primeira carga, com o município inteiro, viajam ~4 MB em vez de ~58 MB —
menos que a própria planilha, dentro do limite de envio do servidor.

Anexe **antes de qualquer outra carga**. O sistema recusa o arquivo se o
cadastro tiver mudado depois da referência (outra carga, ou o mesmo arquivo
anexado duas vezes) — aí é só baixar a referência de novo e gerar outro.

## Segurança

- O app não usa a internet: toda conexão é bloqueada.
- A tela não tem acesso a arquivos; quem abre e salva é o processo principal,
  só pelos diálogos do Windows.
- O sistema confere tudo o que chega no `.json` (formato, campos, inscrições,
  referência) e recalcula o código de conferência de cada imóvel.

## Como o app sabe o que mudou

Cada imóvel tem um **código de conferência** (sha1 do registro canônico mais os
proprietários) que o sistema grava a cada carga. O app calcula o mesmo código e
manda inteiro só o imóvel cujo código difere da referência. Para isso,
`src/nucleo.js` reproduz exatamente a leitura e a forma canônica do PHP
(`app/Cadastro/LeitorXlsx.php`, `ColunasDaExportacao.php`,
`DiferencaDoCadastro.php`). `tests/cadastro-desktop.test.cjs` confere os códigos
contra valores calculados pelo PHP — **se mudar a regra de um lado, mude do
outro**, ou todo imóvel vai parecer alterado todo mês.

## Desenvolvimento

```
npm install
npm start                    # abre o app
npm run gerar -- referencia.json planilha.xlsx saida.json   # sem janela
npm run empacotar            # dist/FiscObras Cadastro-win32-x64
```

O `.exe` oficial sai do workflow **App do cadastro (Windows)**, que testa e
empacota num Windows a cada mudança nesta pasta e publica o zip no release
`cadastro-desktop-v<versão>` (aba Releases), com link direto, sem login:
`https://github.com/ribeiroconrado-arch/appFiscalizacaoObras/releases/download/cadastro-desktop-v1.0.0/FiscObras-Cadastro-win-x64.zip`.
Para publicar uma versão nova, suba `version` no `package.json`.
