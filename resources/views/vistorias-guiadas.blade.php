<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Vistorias — GeUrb</title>
{{-- A VISTORIA POR MODELO (vistoria guiada). Nasceu como página de teste em
     22/11/2026 e substituiu a vistoria de obra em 26/11/2026: o mapa, a
     Documentos, o protocolo e a sinalização abrem esta página. Ver
     VistoriaGuiadaController. Não carrega app.css de propósito — só os tokens
     de tema —, para não herdar nem quebrar o estilo do mapa. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" sizes="32x32" href="@assetv('img/favicon-32.png')">
<link rel="stylesheet" href="@assetv('css/temas.css')">
<link rel="stylesheet" href="/vendor/leaflet-1.9.4/leaflet.css">
<link rel="stylesheet" href="@assetv('css/vistorias-guiadas.css')">
<script src="@assetv('js/tema.js')"></script>
</head>
<body class="{{ $embutida ? 'embutida' : '' }}">

{{-- EMBUTIDA (?embed=1): aberta numa janela do mapa — só o formulário, sem
     cabeçalho nem lista. A lista das vistorias é a da Documentos. --}}
<header class="vg-topo" @if ($embutida) hidden @endif>
  <a class="vg-voltar" href="{{ route('mapa') }}" title="Voltar ao mapa"><svg class="i"><use href="#i-esq"/></svg>Mapa</a>
  <div class="vg-marca"><b>Vistorias</b><span class="vg-teste">{{ $modulo === 'posturas' ? 'Posturas' : 'Obras' }}</span></div>
  <nav class="abas" role="tablist">
    <button id="aba-vistorias" class="at" onclick="irAba('vistorias')">Vistorias</button>
    <button id="aba-nova" onclick="irAba('nova')" @if (! $usuario['edita']) hidden @endif>Nova vistoria</button>
    <button id="aba-modelos" onclick="irAba('modelos')" @if (! $usuario['admin']) hidden @endif>Modelos</button>
  </nav>
</header>

<main class="vg-main">
  <section id="v-vistorias"></section>
  <section id="v-nova" hidden></section>
  {{-- Os modelos do módulo aberto: a lista e o editor (abas Roteiro e Regras). --}}
  <section id="v-modelos" class="mont" hidden>
    <aside class="caixa mod-lado">
      <div class="mod-lado-cab"><h2 id="mod-titulo">Modelos</h2>
        <button class="btn sm pri" onclick="novoModelo()"><svg class="i"><use href="#i-mais"/></svg>Novo</button></div>
      <div class="modelos" id="lista-modelos"></div>
      <button class="mod-link" title="Acrescenta os exemplos deste módulo que ainda não existem" onclick="carregarExemplos()">Carregar exemplos</button>
    </aside>
    <section class="caixa" id="editor"></section>
  </section>
</main>

{{-- A vistoria em execução cobre a página inteira: no aparelho, é a tela. --}}
<div class="exec" id="exec" hidden>
  <div class="exec-corpo">
    <nav class="trilho" id="trilho"></nav>
    <div class="exec-main">
      <div class="exec-cab" id="cab"></div>
      <div class="rolar" id="corpo"></div>
      <div class="rodape" id="rodape"></div>
    </div>
    <div id="lado-slot" hidden></div>
  </div>
  <div id="pdf-slot"></div>
</div>

<input type="file" id="cam" accept="image/*" capture="environment" hidden>
<input type="file" id="arq-pdf" accept="application/pdf" hidden>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-mais" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="i-volta" viewBox="0 0 24 24"><path d="M4 4v6h6"/><path d="M5 15a8 8 0 1 0 2-8.5L4 10"/></symbol>
  <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></symbol>
  <symbol id="i-gps" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></symbol>
  <symbol id="i-mapa" viewBox="0 0 24 24"><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14"/></symbol>
  <symbol id="i-camera" viewBox="0 0 24 24"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></symbol>
  <symbol id="i-doc" viewBox="0 0 24 24"><path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4M9 12h6M9 16h6"/></symbol>
  <symbol id="i-min" viewBox="0 0 24 24"><path d="M5 19h14"/></symbol>
  <symbol id="i-jan" viewBox="0 0 24 24"><rect x="3" y="7" width="12" height="12" rx="1.5"/><path d="M9 7V4h12v12h-6"/></symbol>
  <symbol id="i-max" viewBox="0 0 24 24"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/></symbol>
  <symbol id="i-lado" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="1.5"/><path d="M13 4v16"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
  <symbol id="i-esq" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></symbol>
  <symbol id="i-dir" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></symbol>
  <symbol id="i-cima" viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></symbol>
  <symbol id="i-baixo" viewBox="0 0 24 24"><path d="M5 9l7 7 7-7"/></symbol>
  <symbol id="i-lixo" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></symbol>
  <symbol id="i-ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
  <symbol id="i-alerta" viewBox="0 0 24 24"><path d="M12 3 2 20h20L12 3zM12 10v4M12 17h0"/></symbol>
  <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h0"/></symbol>
  <symbol id="i-zmais" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5M11 8v6M8 11h6"/></symbol>
  <symbol id="i-zmenos" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5M8 11h6"/></symbol>
  <symbol id="i-nota" viewBox="0 0 24 24"><path d="M5 4h14v12l-4 4H5z"/><path d="M15 20v-4h4M8 9h8M8 13h5"/></symbol>
</svg>

<script>window.VG_EU = @json($usuario); window.VG_MODULO = @json($modulo); window.VG_EMBUTIDA = @json($embutida);</script>
<script src="/vendor/leaflet-1.9.4/leaflet.js"></script>
<script src="@assetv('js/vistorias-guiadas.js')"></script>
</body>
</html>
