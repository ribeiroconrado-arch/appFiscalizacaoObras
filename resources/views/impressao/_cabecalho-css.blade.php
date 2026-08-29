{{--
  CABEÇALHO OFICIAL — o CSS.

  Vem do AppPOSTURAS, a pedido: mesmo desenho, mesmas medidas. Lá ele é escrito
  em flexbox, porque só imprime pelo navegador; aqui a mesma página serve o
  dompdf, que NÃO entende flex nem grid — então cada linha do flex virou uma
  tabela aninhada. O resultado no papel é o mesmo; a receita é que muda.

  Incluído dentro do <style> dos três layouts (auto, vistoria e ordem de
  serviço) para os três não divergirem: eram três cópias do mesmo cabeçalho, e
  mudar o tamanho do título corrigia um papel e deixava os outros dois para trás.
--}}

  /* O fundo cinza do NÚMERO é o único fundo do documento, e o Chrome descarta
     fundos ao imprimir por padrão — sem isto a faixa some no papel e o número
     fica solto no branco. O dompdf não precisa da declaração, mas ela também
     não o atrapalha. */
  body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

  /* ── O cabeçalho ──
     Brasão numa coluna própria à esquerda, abrangendo as DUAS linhas (título e
     número em cima, órgão e endereço embaixo): é por isso que ele fica fora do
     corpo do cabeçalho, e não dentro dele. */
  table.cab { width: 100%; border-collapse: collapse; }
  table.cab > tr > td, table.cab > tbody > tr > td { padding: 0; border: 0; vertical-align: top; }
  .cab-brasao { width: 54px; vertical-align: top; }
  .cab-brasao img { width: 44px; height: auto; }

  /* Linha 1 — título à esquerda, número à direita, no MESMO tamanho de fonte.
     O título é o que se lê primeiro de longe, e o número é o que se procura
     num processo: os dois merecem o mesmo peso. */
  table.cab-l1 { width: 100%; border-collapse: collapse; }
  table.cab-l1 > tr > td, table.cab-l1 > tbody > tr > td { padding: 0; border: 0; vertical-align: bottom; }
  .cab-titulo { font-size: 18px; font-weight: bold; line-height: 1; letter-spacing: .01em; }
  /* A faixa cinza cobre o rótulo E o número — o fundo vai de um ao outro, e
     não só atrás da palavra "NÚMERO".

     É uma CÉLULA com fundo, e não uma tabela aninhada: `display:inline-table`
     resolveria no navegador, mas o dompdf o trata de forma irregular, e este
     papel é impresso pelos dois motores. `width:1%` é o jeito, entendido pelos
     dois, de a célula encolher até o conteúdo — sem isso ela dividiria a
     largura com o título e o cinza atravessaria meia página. */
  .cab-num { width: 1%; white-space: nowrap; text-align: right; background: #ccc;
             padding: 3px 8px 4px !important; }
  .cab-num-lbl { color: #333; font-size: 9px; text-transform: uppercase; letter-spacing: .02em; }
  .cab-num-val { font-size: 18px; font-weight: bold; line-height: 1; margin-left: 6px; }

  /* Tracejado sob o título — elemento do modelo oficial de referência. */
  .cab-dash { border-top: 1px dashed #000; height: 0; margin: 3px 0 4px; }

  /* Linha 2 — órgão à esquerda, o selo da fiscalização à direita, em texto
     puro: sem caixa e sem borda, como no modelo. */
  table.cab-l2 { width: 100%; border-collapse: collapse; }
  table.cab-l2 > tr > td, table.cab-l2 > tbody > tr > td { padding: 0; border: 0; vertical-align: top; }
  .cab-orgao { font-size: 11px; font-weight: bold; }
  .cab-depto { font-size: 10px; color: #333; margin-top: 1px; }
  .cab-end   { font-size: 9px;  color: #666; margin-top: 2px; }
  .cab-selo  { font-size: 11px; font-weight: bold; line-height: 1.4; text-align: right;
               width: 96px; padding-left: 10px !important; }

  /* A régua grossa que fecha o cabeçalho e se repete em toda página. */
  .cab-regua { border-bottom: 2px solid #111; height: 0; margin-top: 5px; }

  /* ── A faixa de identificação (só na primeira página) ──
     Sem moldura nas células: no modelo os campos são rótulo miúdo e valor em
     negrito, separados pelo espaço, e fechados por uma régua embaixo. A grade
     de linhas cinza que havia antes competia com o conteúdo. */
  table.topo { width: 100%; border-collapse: collapse; margin-top: 8px; }
  table.topo td { border: 0; padding: 0 12px 8px 0; vertical-align: top; }
  table.topo td:last-child { padding-right: 0; }
  .topo-lbl { display: block; font-size: 9.5px; color: #666; text-transform: uppercase;
              letter-spacing: .02em; white-space: nowrap; }
  .topo-val { font-size: 13px; font-weight: bold; }
  .topo-regua { border-bottom: 2px solid #111; height: 0; margin-bottom: 10px; }
