<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#004D1C">
{{-- O POST de identificação passa pelo grupo `web`, então precisa do token. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Fiscalização de Obras — Mapa</title>
<link rel="icon" type="image/png" sizes="32x32" href="@assetv('img/favicon-32.png')">
<link rel="icon" type="image/png" sizes="16x16" href="@assetv('img/favicon-16.png')">
<link rel="apple-touch-icon" sizes="180x180" href="@assetv('img/apple-touch-icon.png')">
<link rel="manifest" href="@assetv('manifest.json')">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="@assetv('css/app.css')">
{{-- Tema em camada separada: o design ainda está em avaliação (seis
     variantes). Trocar de proposta é trocar esta linha, não refazer o CSS. --}}
<link rel="stylesheet" href="@assetv('css/tema-f.css')">
</head>
<body>

{{--
  TELA ÚNICA: MAPA
  Convenções de UI herdadas do AppPOSTURAS — ver web/README.md do projeto:
  botões "Modelo E", campos "Modelo E", seções numeradas por contador CSS,
  modais que não fecham por clique no fundo, ícone sempre em SVG de linha.
--}}

<header class="topo">
  {{-- Ícone oficial (public/img/icone.svg), sem o fundo fora do squircle. --}}
  <img src="@assetv('img/logo-64.png')" alt="" style="width:26px;height:26px;flex-shrink:0">
  <div>
    <h1>Fiscalização de Obras</h1>
    <div class="sub">{{ number_format($total, 0, ',', '.') }} lotes na base</div>
  </div>

  {{-- A navegação vive só no rodapé, em qualquer largura: repeti-la aqui no
       desktop deixava dois menus para a mesma coisa, e ainda era o que
       espremia o cabeçalho no celular. --}}

  <div class="usuario-topo">
    @if (auth()->user()->isAdmin())
      {{-- Só administrador: parâmetros decidem quem tem acesso a quê e as
           regras que travam a lavratura (feriado, UPF, legislação). --}}
      <button class="sino" onclick="abrirParametros()" title="Parâmetros do sistema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
      </button>
    @endif
    <button class="sino" onclick="abrirNotificacoes()" title="Avisos">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
      <span class="n" id="sino-n" style="display:none">0</span>
    </button>
    {{-- Avatar e identificação num alvo só: são a mesma coisa para quem
         clica — "meus dados". No celular o texto sai e sobra o avatar. --}}
    <button class="perfil-btn" onclick="abrirPerfil()" title="Meu perfil">
      <span class="avatar">{{ auth()->user()->iniciais() }}</span>
      <span class="ident">
        <span class="nome">{{ auth()->user()->name }}</span>
        <span class="cargo">
          {{ auth()->user()->perfilRotulo() }}@if (auth()->user()->tipo_usuario) · {{ ucfirst(auth()->user()->tipo_usuario) }}@endif
        </span>
      </span>
    </button>
    <form method="POST" action="{{ route('logout') }}">
      @csrf
      <button type="submit" class="btn-sair" title="Sair">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>
        </svg>
      </button>
    </form>
  </div>
</header>

{{-- ══════ ABA: PAINEL ══════ --}}
<section class="tela at" id="t-painel">
  <div class="linha-filtro">
    <select onchange="filtrarPainel('dias', this.value)">
      <option value="30">Últimos 30 dias</option>
      <option value="7">Últimos 7 dias</option>
      <option value="90">Últimos 90 dias</option>
      <option value="365">Este ano</option>
    </select>
    <select id="pn-bairro" onchange="filtrarPainel('bairro', this.value)">
      <option value="">Todos os bairros</option>
    </select>
    <select onchange="filtrarPainel('agente', this.value)">
      <option value="todos">Todos os agentes</option>
      <option value="eu">Meus registros</option>
    </select>
  </div>

  {{-- Ordem das colunas = ordem de leitura no celular, onde a grade vira uma
       coluna só. Pendência vem antes de estatística: o painel existe para
       responder "o que precisa de mim?", não para exibir gráfico. --}}
  <div class="painel-grid">
    <div class="col">
      <div class="metricas" id="pn-metricas"></div>
      <div class="bloco">
        <div class="sec-simples">Precisa de atenção <span class="cont" id="pn-atencao-n">0</span></div>
        {{-- Inclui os documentos com prazo vencendo ou vencido do próprio
             agente — é aqui que a pendência de documento aparece. --}}
        <div id="pn-atencao"></div>
      </div>
    </div>

    <div class="col">
      <div class="bloco">
        <div class="sec-simples">Documentos por tipo</div>
        <div id="pn-por-tipo"></div>
      </div>
      <div class="bloco">
        <div class="sec-simples">Irregularidades frequentes</div>
        <div id="pn-irregs"></div>
      </div>
    </div>

    <div class="col">
      <div class="bloco">
        <div class="sec-simples">Alterações recentes</div>
        {{-- Alimentado pela tabela de auditoria — a mesma trilha que responde
             "quem fez o quê" no processo administrativo, não um log paralelo. --}}
        <div class="feed" id="pn-recentes"></div>
      </div>
    </div>
  </div>
</section>

{{-- ══════ ABA: MAPA ══════ --}}
<section class="tela" id="t-mapa">

<div id="map"></div>

<div class="chip-estado">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round">
    <path d="M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3z"/><path d="M9 3v15"/><path d="M15 6v15"/>
  </svg>
  <span id="chip-txt">Carregando…</span>
</div>

{{-- Controle de coloração e legenda dos rótulos --}}
{{-- Legenda recolhida num ícone, logo abaixo do seletor de camadas: o painel
     aberto comia um pedaço do mapa o tempo todo, e a legenda só é consultada
     de vez em quando. O JS o posiciona sob o controle do Leaflet. --}}
<div class="ctrl-mapa" id="ctrl-mapa">
  <button class="ctrl-btn" onclick="alternarLegenda()" title="Cores e legenda" aria-expanded="false">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="14" r="2.5"/>
      <circle cx="8.5" cy="7.5" r="2.5"/><circle cx="6.5" cy="12.5" r="2.5"/>
      <path d="M12 2a10 10 0 1 0 0 20 1.5 1.5 0 0 0 1.1-2.5 1.4 1.4 0 0 1 1-2.4h2.3A4.6 4.6 0 0 0 22 12 10 10 0 0 0 12 2z"/>
    </svg>
  </button>

  <div class="ctrl-corpo" id="ctrl-corpo" hidden>
    <b>Colorir por</b>
    <div class="seg ctrl-cor">
      <button class="at" data-chave="bairro" onclick="aplicarCores('bairro')">Bairro</button>
      <button data-chave="quadra" onclick="aplicarCores('quadra')">Quadra</button>
    </div>
    <div class="leg" id="leg-zoom">Bairro e logradouro · aproxime para quadra e lote</div>
    <div id="leg-cores"></div>
  </div>
</div>

<div class="acoes">
  <button class="btn primary" id="btn-gps" onclick="usarMinhaLocalizacao()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
      <circle cx="12" cy="12" r="8"/>
    </svg>
    <span id="gps-txt">Usar minha localização</span>
  </button>
  <button class="btn" onclick="verTudo()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/>
    </svg>
    Ver tudo
  </button>
</div>
</section>

{{-- ══════ ABA: DOCUMENTOS (Etapa 6) ══════ --}}
<section class="tela" id="t-documentos">
  <div class="topo-lista">
    <div class="sec-simples">Documentos <span class="cont" id="cont-doc">0</span></div>
    @if (auth()->user()->podeLavrarDocumento())
      <button class="btn primary sm" onclick="novoDocumento()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Novo documento
      </button>
    @else
      {{-- Só agente de fiscalização lavra: coordenador e secretário acompanham,
           não autuam. A regra real está no controller; aqui é conveniência. --}}
      <button class="btn sm" disabled title="Só agente de fiscalização emite documentos">Novo documento</button>
    @endif
  </div>

  <div class="linha-filtro">
    <select onchange="filtrarDocumentos('tipo', this.value)">
      <option value="">Todos os tipos</option>
      @foreach (\App\Models\Documento::TIPOS as $valor => $t)
        <option value="{{ $valor }}">{{ $t[0] }}</option>
      @endforeach
    </select>
    <select onchange="filtrarDocumentos('agente', this.value)">
      <option value="eu">Meus documentos</option>
      <option value="todos">Todos os agentes</option>
    </select>
  </div>
  <div class="linha-filtro">
    <input type="text" placeholder="Buscar nº, imóvel ou autuado…"
           oninput="filtrarDocumentos('busca', this.value)">
    <select onchange="filtrarDocumentos('status', this.value)">
      <option value="">Todos os status</option>
      <option value="rascunho">Rascunho</option>
      <option value="lavrado">Lavrado</option>
      <option value="atendido">Atendido</option>
      <option value="anulado">Anulado</option>
    </select>
  </div>

  <div id="lista-documentos"></div>
</section>

{{-- ══════ ABA: PROTOCOLOS ══════
     Requerimentos do contribuinte. Sem dashboard, por decisão de projeto: a
     aba existe para trabalhar a fila, e o painel já resume os números. --}}
<section class="tela" id="t-protocolos">
  <div class="topo-lista">
    <div class="sec-simples">Protocolos <span class="cont" id="cont-proto">0</span></div>
    @if (auth()->user()->canEdit())
      <button class="btn primary sm" onclick="novoProtocolo()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Novo protocolo
      </button>
    @else
      <button class="btn sm" disabled title="Seu perfil é somente de consulta">Novo protocolo</button>
    @endif
  </div>

  <div class="linha-filtro">
    <select onchange="filtrarProtocolos('tipo', this.value)">
      <option value="">Todos os tipos</option>
      @foreach (\App\Models\Protocolo::TIPOS as $valor => $rotulo)
        <option value="{{ $valor }}">{{ $rotulo }}</option>
      @endforeach
    </select>
    {{-- Padrão "todos" e não "meus": protocolo chega sem dono, e abrir a lista
         filtrada pelo agente esconderia justamente o que ninguém assumiu. --}}
    <select onchange="filtrarProtocolos('agente', this.value)">
      <option value="todos">Todos os agentes</option>
      <option value="eu">Meus protocolos</option>
      <option value="sem_dono">Não distribuídos</option>
    </select>
  </div>
  <div class="linha-filtro">
    <input type="text" placeholder="Buscar nº, requerente ou imóvel…"
           oninput="filtrarProtocolos('busca', this.value)">
    <select onchange="filtrarProtocolos('situacao', this.value)">
      <option value="">Todas as situações</option>
      @foreach (\App\Models\Protocolo::SITUACOES as $valor => $s)
        <option value="{{ $valor }}">{{ $s[0] }}</option>
      @endforeach
    </select>
  </div>

  <div id="lista-protocolos"></div>
</section>

{{-- ══════ PARÂMETROS DO SISTEMA — modal (só administrador) ══════ --}}
@if (auth()->user()->isAdmin())
<div class="modal-bg" id="m-parametros" onclick="fModal()">
  <div class="modal largo" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-parametros')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
      </svg>
      Parâmetros do sistema
    </h3>

  <div class="sub-abas">
    <button class="at" data-sub="usuarios" onclick="subParametros('usuarios')">Usuários</button>
    <button data-sub="legislacao" onclick="subParametros('legislacao')">Legislação</button>
    <button data-sub="upf" onclick="subParametros('upf')">UPF</button>
    <button data-sub="feriados" onclick="subParametros('feriados')">Feriados</button>
    <button data-sub="geral" onclick="subParametros('geral')">Órgão</button>
  </div>

  {{-- USUÁRIOS --}}
  <div class="par-painel at" id="par-usuarios">
    <div class="topo-lista">
      <div class="sec-simples">Usuários <span class="cont" id="cont-usuarios">0</span></div>
      <button class="btn primary sm" onclick="novoUsuario()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Novo usuário
      </button>
    </div>
    <div id="lista-usuarios"></div>
  </div>

  {{-- LEGISLAÇÃO — lista de leis → detalhe da lei, como no AppPOSTURAS.
       Aninhar os artigos dentro da lista virava uma árvore longa demais
       para achar qualquer coisa. --}}
  <div class="par-painel" id="par-legislacao">

    {{-- SUB-TELA: LISTA DE LEIS --}}
    <div id="leg-lista">
      <div id="par-legislacao-aviso"></div>
      <div class="sec-simples">Leis <span class="cont" id="cont-leis">0</span></div>
      <div class="cad-row">
        <input type="text" id="nova-lei-numero" class="mono" placeholder="Número (Lei Complementar 1/2023)">
        <input type="text" id="nova-lei-nome" placeholder="Nome (Código de Obras)"
               onkeydown="if(event.key==='Enter')novaLei()">
        <button class="btn primary sm" onclick="novaLei()">+ Nova lei</button>
      </div>
      <div class="cad-dica">Toque numa lei para ver os artigos e os textos de ciência.</div>
      <div id="lista-leis"></div>
    </div>

    {{-- SUB-TELA: DETALHE DA LEI --}}
    <div id="leg-detalhe" style="display:none">
      <div class="sub-topo">
        <button class="btn sm" onclick="voltarLeis()">← Voltar</button>
        <div class="titulo" id="leg-detalhe-titulo">—</div>
      </div>

      <div class="sub-abas">
        <button class="at" data-leg="dados" onclick="subLei('dados')">Dados</button>
        <button data-leg="textos" onclick="subLei('textos')">Textos de ciência</button>
        <button data-leg="artigos" onclick="subLei('artigos')">Artigos</button>
      </div>

      <div class="leg-painel at" id="leg-dados">
        <input type="hidden" id="lei-id">
        <div class="field"><label for="lei-numero">Número</label><input type="text" id="lei-numero" class="mono" maxlength="40"></div>
        <div class="field"><label for="lei-nome">Nome</label><input type="text" id="lei-nome" maxlength="160"></div>
        <div class="field"><label for="lei-ano">Ano</label><input type="number" id="lei-ano" min="1900" max="2100"></div>
        <div class="field"><label for="lei-ementa">Ementa</label>
          <textarea id="lei-ementa" rows="2" style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"></textarea></div>
        <div class="field">
          {{-- Prazo de defesa é DA LEI, não do documento: o auto não tem esse
               campo no formulário, o sistema calcula a data a partir daqui. --}}
          <label for="lei-prazo-defesa">Prazo de defesa (dias úteis)</label>
          <input type="number" id="lei-prazo-defesa" min="1" max="120">
        </div>
        <div class="field">
          <label for="lei-prazo-cumprimento">Prazo de cumprimento sugerido (dias corridos)</label>
          <input type="number" id="lei-prazo-cumprimento" min="0" max="365">
        </div>
        <label class="lembrar"><input type="checkbox" id="lei-ativa"> Lei ativa</label>
        <div class="btn-row"><button class="btn primary" onclick="salvarLei()">Salvar lei</button></div>
      </div>

      <div class="leg-painel" id="leg-textos">
        <div class="field">
          <label for="lei-ciencia-notif">Ciência da notificação (aceita {prazo})</label>
          <textarea id="lei-ciencia-notif" rows="8" style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"></textarea>
        </div>
        <div class="field">
          <label for="lei-ciencia-auto">Ciência do auto de infração</label>
          <textarea id="lei-ciencia-auto" rows="8" style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"></textarea>
        </div>
        <div class="btn-row"><button class="btn primary" onclick="salvarLei()">Salvar textos</button></div>
      </div>

      <div class="leg-painel" id="leg-artigos">
        <div class="topo-lista">
          <div class="sec-simples">Artigos <span class="cont" id="cont-artigos">0</span></div>
          <button class="btn primary sm" onclick="novoArtigoDaLei()">+ Novo artigo</button>
        </div>
        <div id="lista-artigos"></div>
      </div>
    </div>
  </div>

  {{-- UPF — cadastro direto na linha, sem modal (padrão AppPOSTURAS) --}}
  <div class="par-painel" id="par-upf">
    <div class="sec-simples">UPF por exercício <span class="cont" id="cont-upf">0</span></div>
    <p class="aviso-legal">
      <b>Por que por exercício:</b> um documento lavrado em 2026 tem de continuar
      valendo a UPF de 2026 mesmo depois que o decreto do ano seguinte entrar —
      o valor em reais de um auto já emitido não pode mudar sozinho.
    </p>
    <div class="cad-row">
      <input type="number" id="novo-upf-ano" placeholder="Ano (2026)" min="2020" max="2100">
      <input type="number" id="novo-upf-valor" placeholder="Valor (5,8234)" step="0.0001" min="0">
      <input type="text" id="novo-upf-norma" placeholder="Norma (Decreto 1.234/2025)"
             onkeydown="if(event.key==='Enter')salvarUpf()">
      <button class="btn primary sm" onclick="salvarUpf()">+ Nova UPF</button>
    </div>
    <div id="lista-upf"></div>
  </div>

  {{-- FERIADOS — lista de anos → feriados do ano --}}
  <div class="par-painel" id="par-feriados">

    {{-- SUB-TELA: ANOS --}}
    <div id="fer-anos">
      <div class="sec-simples">Calendário de feriados <span class="cont" id="cont-feriados">0</span></div>
      <p class="aviso-legal">
        Usado para contar o prazo de defesa em <b>dias úteis</b>. Feriado errado
        ou faltando encurta o prazo real do autuado e vicia o processo.
      </p>
      <div class="cad-row">
        <input type="number" id="novo-ano-feriados" placeholder="Ano (2026)" min="1900" max="2200"
               onkeydown="if(event.key==='Enter')novoAnoFeriados()">
        <button class="btn primary sm" onclick="novoAnoFeriados()">+ Novo ano</button>
      </div>
      <div class="cad-dica">Toque num ano para ver os feriados cadastrados.</div>
      <div id="lista-anos-feriados"></div>
    </div>

    {{-- SUB-TELA: FERIADOS DO ANO --}}
    <div id="fer-lista" style="display:none">
      <div class="sub-topo">
        <button class="btn sm" onclick="voltarAnosFeriados()">← Voltar</button>
        <div class="titulo" id="fer-ano-titulo">—</div>
      </div>
      <div class="cad-row">
        {{-- min/max presos ao ano aberto: evita cadastrar 2027 dentro de 2026. --}}
        <label class="date-ov" style="flex:1;min-width:130px">
          <input type="date" id="novo-feriado-data" onchange="atualizarDisplayData(this)">
          <span class="date-ov-txt vazio">dd/mm/aaaa</span>
        </label>
        <input type="text" id="novo-feriado-nome" placeholder="Nome (Natal)"
               onkeydown="if(event.key==='Enter')salvarFeriado()">
        <select id="novo-feriado-tipo">
          <option value="municipal">Municipal</option>
          <option value="nacional">Nacional</option>
          <option value="estadual">Estadual</option>
          <option value="facultativo">Facultativo</option>
        </select>
        <label class="lembrar" style="margin:0"><input type="checkbox" id="novo-feriado-recorrente"> Repete todo ano</label>
        <button class="btn primary sm" onclick="salvarFeriado()">+ Novo feriado</button>
      </div>
      <div id="lista-feriados"></div>
    </div>
  </div>

  {{-- ÓRGÃO --}}
  <div class="par-painel" id="par-geral">
    <div class="sec-simples">Dados do órgão <span class="cont" id="cont-geral">0</span></div>
    <p class="aviso-legal">Impressos no cabeçalho e rodapé dos documentos emitidos.</p>
    <div id="lista-geral"></div>
    <div class="btn-row" style="margin-top:14px">
      <button class="btn primary" onclick="salvarGeral()">Salvar</button>
    </div>
  </div>
  </div>
</div>
@endif

{{-- ══════ ABAS ══════ --}}
<nav class="abas">
  <button class="aba at" onclick="irPara('painel')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
      <rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/>
      <rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
    Painel
  </button>
  <button class="aba" onclick="irPara('mapa')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
      <path d="M9 20l-6 3V6l6-3 6 3 6-3v17l-6 3z"/><path d="M9 3v17M15 6v17"/></svg>
    Mapa
  </button>
  <button class="aba" onclick="irPara('documentos')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
      <path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/></svg>
    Documentos
  </button>
  <button class="aba" onclick="irPara('protocolos')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
      <path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/>
      <rect x="9" y="2" width="6" height="4" rx="1"/><path d="M8 12h8M8 16h5"/></svg>
    Protocolos
  </button>
</nav>

{{-- CENTRAL DE NOTIFICAÇÕES DO SISTEMA --}}
<div class="modal-bg" id="m-notif" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-notif')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
      Avisos
    </h3>
    <div class="sub">
      Ligados aos seus atos no sistema. Não confundir com a aba
      <b>Documentos</b>, que reúne notificações e autos fiscais.
    </div>
    <div id="lista-notificacoes"></div>
    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-notif')">Fechar</button>
    </div>
  </div>
</div>

{{-- FICHA DO IMÓVEL --}}
<div class="modal-bg" id="m-ficha" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-ficha')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>
      </svg>
      <span id="fi-titulo">Imóvel</span>
    </h3>
    <div class="sub" id="fi-linha-dist" style="display:none">
      <span class="badge bd-ok"><span id="fi-dist"></span></span>
    </div>

    <div class="sub-abas">
      <button class="at" data-fi="dados" onclick="subFicha('dados')">Dados</button>
      <button data-fi="historico" onclick="subFicha('historico')">Histórico</button>
      <button data-fi="cadastro" onclick="subFicha('cadastro')">Cadastro imobiliário</button>
      <button data-fi="croquis" onclick="subFicha('croquis')">Croquis</button>
      <button data-fi="anexos" onclick="subFicha('anexos')">Anexos</button>
    </div>

    {{-- DADOS --}}
    <div class="fi-painel at" id="fi-dados">
      {{-- Endereço como texto corrido, não em campos: aqui ninguém edita, só
           lê. Campo com moldura sugere edição que não existe. --}}
      <div class="fi-bloco">
        <div class="fi-rot">Endereço</div>
        <div class="fi-endereco" id="fi-endereco">—</div>
      </div>

      <div class="fi-grade">
        <div><div class="fi-rot">Inscrição imobiliária</div><div class="fi-val mono" id="fi-inscricao">—</div></div>
        <div><div class="fi-rot">Situação</div><div class="fi-val" id="fi-situacao">—</div></div>
        <div><div class="fi-rot">Coordenadas</div><div class="fi-val mono" id="fi-coord">—</div></div>
        <div><div class="fi-rot">Última integração</div><div class="fi-val" id="fi-integracao">—</div></div>
      </div>

      {{-- Fachada e croqui lado a lado: são as duas imagens que respondem
           "como é o imóvel" antes de ir a campo. --}}
      <div class="fi-midias">
        <figure class="fi-midia" id="fi-fachada">
          <figcaption>Fachada mais recente</figcaption>
          <div class="fi-vazio">Sem foto de fachada registrada</div>
        </figure>
        <figure class="fi-midia" id="fi-croqui-atual">
          <figcaption>Croqui mais recente</figcaption>
          <div class="fi-vazio">Sem croqui registrado</div>
        </figure>
      </div>
    </div>

    {{-- HISTÓRICO --}}
    <div class="fi-painel" id="fi-historico-painel">
      <div class="sec-title-row">
        <div class="sec-title">Linha do tempo</div>
        <span class="sec-title-acao" id="fi-hist-total"
              style="font-size:11px;color:var(--tx3);font-weight:700"></span>
      </div>
      <div class="linha-tempo" id="fi-historico">
        <div class="vazio-msg">Carregando histórico…</div>
      </div>
    </div>

    {{-- CADASTRO IMOBILIÁRIO (integração — Etapa 4) --}}
    <div class="fi-painel" id="fi-cadastro">
      <div class="field" style="background:var(--gold-lt);border-color:#FFE9A8">
        <label>Situação da integração</label>
        <div class="valor" style="font-size:12.5px;font-weight:600">
          Proprietário, área construída, uso e situação fiscal virão do cadastro
          da prefeitura. A integração é a Etapa 4 do plano.
        </div>
      </div>
      <div class="fi-grade" style="margin-top:12px">
        <div><div class="fi-rot">Chave de integração</div><div class="fi-val mono" id="fi-chave">—</div></div>
        <div><div class="fi-rot">Área GIS</div><div class="fi-val" id="fi-area">—</div></div>
      </div>
    </div>

    {{-- CROQUIS --}}
    <div class="fi-painel" id="fi-croquis">
      <div id="fi-lista-croquis"><div class="vazio-msg">Nenhum croqui registrado neste imóvel.</div></div>
    </div>

    {{-- ANEXOS --}}
    <div class="fi-painel" id="fi-anexos">
      <div id="fi-lista-anexos"><div class="vazio-msg">Nenhum anexo neste imóvel.</div></div>
    </div>

    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-ficha')">Fechar</button>
      @if (auth()->user()->canEdit())
        <button class="btn primary" onclick="novaVistoria()">Nova vistoria</button>
      @else
        {{-- Visualizador não registra: esconder o botão evita a ida ao
             servidor só para receber 403. A regra real está no controller. --}}
        <button class="btn" disabled title="Seu perfil permite apenas consulta">Nova vistoria</button>
      @endif
    </div>
  </div>
</div>

{{-- NOVA VISTORIA (Etapa 5) --}}
<div class="modal-bg" id="m-vistoria" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-vistoria')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/>
      </svg>
      Nova vistoria
    </h3>
    <div class="sub" id="nv-lote">—</div>

    <div class="sec-title">Identificação</div>

    {{-- Data + hora como UM campo visual, dois inputs nativos por baixo.
         Nunca datetime-local: mistura os dois no formato do SO. --}}
    <div class="data-hora-combo">
      <span class="rot">Data e hora</span>
      <div class="campos">
        <label class="date-ov">
          <input type="date" id="nv-data" onchange="syncDataHora()"
                 onfocus="preencherDataHojeSeVazio(this)">
          <span class="date-ov-txt vazio">dd/mm/aaaa</span>
        </label>
        <span class="sep"></span>
        <input type="time" id="nv-hora" onchange="syncDataHora()"
               onfocus="preencherHoraAgoraSeVazio(this)">
      </div>
    </div>
    <input type="hidden" id="nv-datahora">

    <div class="field" style="margin-top:9px">
      <label for="nv-situacao">Situação constatada</label>
      <select id="nv-situacao">
        @foreach (\App\Models\Vistoria::SITUACOES as $valor => $rotulo)
          <option value="{{ $valor }}">{{ $rotulo }}</option>
        @endforeach
      </select>
    </div>

    <div class="field" style="display:none">
      <label>Coordenada capturada</label>
      <div class="valor" id="nv-gps" style="font-size:12.5px">—</div>
    </div>

    <div class="sec-title">Irregularidades constatadas</div>
    <div class="checklist" id="nv-checklist"></div>

    <div class="sec-title">Observações</div>
    <div class="field">
      <label for="nv-obs">Descrição livre</label>
      <textarea id="nv-obs" rows="3" maxlength="5000"
                style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"
                placeholder="O que foi constatado em campo"></textarea>
    </div>

    <div class="sec-title-row">
      <div class="sec-title">Evidências</div>
      <label class="btn out-green sm sec-title-acao" style="cursor:pointer">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
          <circle cx="12" cy="13" r="4"/>
        </svg>
        Foto
        {{-- `capture="environment"` abre a câmera traseira direto no celular;
             no desktop o mesmo input vira seletor de arquivo. --}}
        <input type="file" accept="image/*,application/pdf" multiple capture="environment"
               style="display:none" onchange="anexarArquivos(this)">
      </label>
    </div>
    <div class="anexos" id="nv-anexos"></div>

    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-vistoria')">Cancelar</button>
      <button class="btn primary" onclick="gravarVistoria()">Gravar vistoria</button>
    </div>
  </div>
</div>

{{-- NOVO DOCUMENTO (Etapa 6) --}}
<div class="modal-bg" id="m-doc" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-doc')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/></svg>
      Novo documento
    </h3>
    <div class="sub" id="nd-imovel">—</div>

    <div class="sec-title">Tipo e data</div>
    <div class="field">
      <label for="nd-tipo">Tipo de documento</label>
      <select id="nd-tipo" onchange="trocarTipoDoc()"></select>
    </div>

    {{-- Data + hora como um campo visual, dois inputs nativos por baixo.
         Nunca datetime-local: mistura os dois no formato do sistema. --}}
    <div class="data-hora-combo">
      <span class="rot" style="font-size:10px;font-weight:700;color:var(--f-rot);
            text-transform:uppercase;letter-spacing:.05em;white-space:nowrap">Data do fato</span>
      <div class="campos" style="display:flex;align-items:center;gap:8px;flex:1;min-width:0">
        <label class="date-ov" style="flex:1;min-width:0">
          <input type="date" id="nd-data" onchange="syncDataDoc()" onfocus="preencherDataHojeSeVazio(this)">
          <span class="date-ov-txt vazio">dd/mm/aaaa</span>
        </label>
        <span style="width:1px;height:18px;background:var(--bord)"></span>
        <input type="time" id="nd-hora" onchange="syncDataDoc()" onfocus="preencherHoraAgoraSeVazio(this)"
               style="border:none;background:none;font-family:inherit;font-size:14px;font-weight:700;
                      color:var(--chumbo);padding:0;width:74px;outline:none">
      </div>
    </div>
    <input type="hidden" id="nd-datahora">

    <div class="sec-title">Autuado</div>
    <div class="field">
      <label for="nd-autuado">Nome do autuado / interessado</label>
      <input id="nd-autuado" type="text" maxlength="160" placeholder="Como consta no cadastro">
    </div>

    <div id="bloco-fundamentacao">
      <div class="sec-title">Fundamentação legal</div>
      <div id="nd-sugestao" style="margin-bottom:10px"></div>
      <div class="field">
        <label for="nd-lei">Lei aplicável</label>
        <select id="nd-lei" onchange="trocarLeiDoc()"></select>
      </div>
      <div class="checklist" id="nd-artigos"></div>

      {{-- Só aparece quando algum artigo marcado cobra por área — a maioria
           das multas de obras é assim, diferente de posturas. --}}
      <div id="nd-bloco-area" style="display:none">
        <div class="field">
          <label for="nd-area-terreno">Área do terreno (m²)</label>
          <input id="nd-area-terreno" type="number" min="0" step="0.01" oninput="recalcularMultaDoc()">
        </div>
        <div class="field">
          {{-- Não vem do GIS: só a medição em campo é confiável para multa. --}}
          <label for="nd-area-construida">Área construída (m²) — medida em campo</label>
          <input id="nd-area-construida" type="number" min="0" step="0.01" oninput="recalcularMultaDoc()">
        </div>
        <div id="nd-memoria-calculo"></div>
      </div>
    </div>

    <div id="bloco-prazo">
      <div class="sec-title">Prazo de cumprimento</div>
      <div class="field">
        <label for="nd-prazo">Dias para cumprimento (0 = imediato)</label>
        <input id="nd-prazo" type="number" min="0" max="365" value="10">
      </div>
    </div>
    <div id="nd-aviso-prazo" class="aviso-legal" style="display:none"></div>

    <div class="sec-title">Descrição</div>
    <div class="field">
      <label for="nd-descricao">Relato do fato</label>
      <textarea id="nd-descricao" rows="3" maxlength="5000"
                style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"
                placeholder="O que foi constatado e está sendo imputado"></textarea>
    </div>

    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-doc')">Cancelar</button>
      <button class="btn" onclick="salvarRascunho()">Salvar rascunho</button>
      <button class="btn primary" onclick="lavrarDocumento()">Lavrar</button>
    </div>
  </div>
</div>

{{-- CONFIRMAÇÃO DE LOTE (GPS não conclusivo) --}}
<div class="modal-bg" id="m-confirmar-lote" onclick="fModal()">
  <div class="modal sm" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-confirmar-lote')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>
      </svg>
      Confirme o imóvel
    </h3>
    <div class="sub">
      Sua posição não caiu dentro de um lote. Estes são os mais próximos —
      escolha o correto. <span id="cf-precisao"></span>
    </div>
    <div id="cf-lista"></div>
    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-confirmar-lote')">Cancelar</button>
      <button class="btn primary" onclick="confirmarLote()">Confirmar</button>
    </div>
  </div>
</div>

{{-- CONFIRMAÇÃO GENÉRICA --}}
<div class="modal-bg" id="m-confirm" onclick="fModal()">
  <div class="modal sm" onclick="event.stopPropagation()" style="max-width:400px">
    <button class="modal-x" onclick="fModalBtn('m-confirm')">&#10005;</button>
    <h3 id="mcg-titulo">Confirmar ação</h3>
    <div class="sub" id="mcg-msg" style="color:var(--tx2);font-size:13px">Tem certeza?</div>
    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-confirm')">Cancelar</button>
      <button class="btn primary" id="mcg-btn-ok" onclick="_mcgConfirmar()">Confirmar</button>
    </div>
  </div>
</div>

<div class="tela-carregando" id="tela-carregando">
  <div class="tela-carregando-spin"></div>
  <div class="tela-carregando-txt" id="tela-carregando-txt">Carregando...</div>
</div>

<div id="toast"></div>

{{-- FICHA DO PROTOCOLO --}}
<div class="modal-bg" id="m-proto" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-proto')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/>
        <rect x="9" y="2" width="6" height="4" rx="1"/><path d="M8 12h8M8 16h5"/></svg>
      Protocolo <span id="pf-numero" class="mono">—</span>
    </h3>
    <div class="sub" id="pf-tipo">—</div>

    <div class="sec-title">Dados do requerimento</div>
    <div id="pf-corpo"></div>

    @if (auth()->user()->canEdit())
      <div class="sec-title">Tramitação</div>
      <div class="field">
        <label for="pf-situacao">Nova situação</label>
        <select id="pf-situacao">
          <option value="">— manter como está —</option>
          @foreach (\App\Models\Protocolo::SITUACOES as $valor => $s)
            <option value="{{ $valor }}">{{ $s[0] }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        {{-- Deferimento e indeferimento exigem parecer no servidor: ato
             administrativo sem motivação é anulável. --}}
        <label for="pf-parecer">Parecer do setor</label>
        <textarea id="pf-parecer" rows="4" style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical" placeholder="Fundamentação da decisão…"></textarea>
      </div>
      <div class="btn-row">
        <button class="btn" onclick="assumirProtocolo()">Assumir</button>
        <button class="btn primary" onclick="concluirProtocolo()">Salvar tramitação</button>
      </div>
    @endif
  </div>
</div>

{{-- NOVO PROTOCOLO --}}
<div class="modal-bg" id="m-novo-proto" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-novo-proto')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/>
        <rect x="9" y="2" width="6" height="4" rx="1"/><path d="M8 12h8M8 16h5"/></svg>
      Novo protocolo
    </h3>
    <div class="sub" id="np-imovel">—</div>
    <input type="hidden" id="np-lote">

    <div class="sec-title">Identificação</div>
    <div class="field">
      {{-- Número vem do protocolo geral da prefeitura; o sistema não gera. --}}
      <label for="np-numero">Número do protocolo</label>
      <input type="text" id="np-numero" class="mono" placeholder="2026/0412" maxlength="30">
    </div>
    <div class="field">
      <label for="np-tipo">Tipo de requerimento</label>
      <select id="np-tipo">
        @foreach (\App\Models\Protocolo::TIPOS as $valor => $rotulo)
          <option value="{{ $valor }}">{{ $rotulo }}</option>
        @endforeach
      </select>
    </div>

    <div class="sec-title">Requerente</div>
    <div class="field">
      <label for="np-requerente">Nome</label>
      <input type="text" id="np-requerente" maxlength="160">
    </div>
    <div class="field">
      <label for="np-documento">CPF/CNPJ</label>
      <input type="text" id="np-documento" class="mono" maxlength="20">
    </div>
    <div class="field">
      <label for="np-contato">Telefone ou e-mail</label>
      <input type="text" id="np-contato" maxlength="120">
    </div>

    <div class="sec-title">Prazos</div>
    <div class="field">
      <label for="np-data">Protocolado em</label>
      <label class="date-ov">
        <input type="date" id="np-data" onchange="atualizarDisplayData(this)">
        <span class="date-ov-txt vazio">dd/mm/aaaa</span>
      </label>
    </div>
    <div class="field">
      {{-- Prazo do MUNICÍPIO para responder. Fica em branco quando a lei não
           fixa prazo — inventar um aqui criaria cobrança sem base legal. --}}
      <label for="np-prazo">Prazo de resposta do município</label>
      <label class="date-ov">
        <input type="date" id="np-prazo" onchange="atualizarDisplayData(this)">
        <span class="date-ov-txt vazio">dd/mm/aaaa</span>
      </label>
    </div>

    <div class="sec-title">Objeto</div>
    <div class="field">
      <label for="np-objeto">O que o contribuinte requer</label>
      <textarea id="np-objeto" rows="4" style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"></textarea>
    </div>

    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-novo-proto')">Cancelar</button>
      <button class="btn primary" onclick="salvarNovoProtocolo()">Registrar</button>
    </div>
  </div>
</div>

@if (auth()->user()->isAdmin())
{{-- NOVO/EDITAR USUÁRIO --}}
<div class="modal-bg" id="m-usuario" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-usuario')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      <span id="us-titulo">Novo usuário</span>
    </h3>
    <input type="hidden" id="us-id">

    <div class="sec-title">Identificação</div>
    <div class="field"><label for="us-nome">Nome</label><input type="text" id="us-nome" maxlength="160"></div>
    <div class="field"><label for="us-email">E-mail</label><input type="email" id="us-email" maxlength="160"></div>
    <div class="field"><label for="us-matricula">Matrícula</label><input type="text" id="us-matricula" maxlength="30"></div>

    <div class="sec-title">Acesso</div>
    <div class="field">
      <label for="us-cargo">Cargo</label>
      <select id="us-cargo">
        <option value="agente">Agente de fiscalização</option>
        <option value="coordenador">Coordenador</option>
        <option value="secretario">Secretário</option>
      </select>
    </div>
    <div class="field">
      {{-- Só agente pode ter perfil acima de viewer — regra em User::perfilEfetivo(). --}}
      <label for="us-perfil">Perfil</label>
      <select id="us-perfil">
        <option value="admin">Administrador</option>
        <option value="comum">Comum</option>
        <option value="viewer">Visualizador</option>
      </select>
    </div>
    <label class="lembrar">
      <input type="checkbox" id="us-ativo" checked> Usuário ativo
    </label>

    <div class="sec-title">Senha</div>
    <div class="field">
      <label for="us-senha">Nova senha (deixe em branco para manter)</label>
      <input type="password" id="us-senha" autocomplete="new-password">
    </div>
    <div class="field">
      <label for="us-senha2">Confirmar senha</label>
      <input type="password" id="us-senha2" autocomplete="new-password">
    </div>

    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-usuario')">Cancelar</button>
      <button class="btn primary" onclick="salvarUsuario()">Salvar</button>
    </div>
  </div>
</div>

{{-- NOVA/EDITAR LEI --}}

{{-- NOVO/EDITAR ARTIGO --}}
<div class="modal-bg" id="m-artigo" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-artigo')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
      <span id="art-titulo">Novo artigo</span>
    </h3>
    <div class="sub" id="art-lei">—</div>
    <input type="hidden" id="art-id"><input type="hidden" id="art-legislacao-id">

    <div class="field"><label for="art-numero">Número</label><input type="text" id="art-numero" class="mono" placeholder="Art. 42, par. 1, II" maxlength="30"></div>
    <div class="field"><label for="art-apelido">Apelido (rótulo curto na lista)</label><input type="text" id="art-apelido" maxlength="60"></div>
    <div class="field"><label for="art-conduta">Conduta (o que a norma proíbe)</label><textarea id="art-conduta" rows="2" style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"></textarea></div>
    <div class="field"><label for="art-sancao">Sanção prevista</label><textarea id="art-sancao" rows="2" style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"></textarea></div>

    <div class="sec-title">Base de cálculo da multa</div>
    {{-- A maioria das multas de obras é por ÁREA, diferente de posturas, onde
         quase tudo é fixo. Por isso o formulário troca de campos conforme a
         base escolhida — mistura os dois formatos é o que confunde o fiscal
         na hora de lançar o valor. --}}
    <div class="field">
      <label for="art-base">Como a multa é calculada</label>
      <select id="art-base" onchange="trocarBaseMulta()">
        <option value="fixa">Valor fixo</option>
        <option value="area_construida">Por m² construído</option>
        <option value="area_terreno">Por m² de terreno</option>
        <option value="sem_multa">Sem multa (só notificação/embargo)</option>
      </select>
    </div>
    <div id="art-bloco-fixa" class="field">
      <label for="art-multa-upf">Multa (UPF)</label>
      <input type="number" id="art-multa-upf" min="0" step="0.01">
    </div>
    <div id="art-bloco-area" style="display:none">
      <div class="field"><label for="art-multa-m2">UPF por m²</label><input type="number" id="art-multa-m2" min="0" step="0.0001"></div>
      <div class="field"><label for="art-multa-min">Piso da multa (UPF)</label><input type="number" id="art-multa-min" min="0" step="0.01"></div>
      <div class="field"><label for="art-multa-max">Teto da multa (UPF)</label><input type="number" id="art-multa-max" min="0" step="0.01"></div>
    </div>

    <div class="sec-title">Irregularidades enquadradas</div>
    <div id="art-irregularidades" class="checklist"></div>
    <label class="lembrar"><input type="checkbox" id="art-ativo" checked> Artigo ativo</label>

    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-artigo')">Cancelar</button>
      <button class="btn primary" onclick="salvarArtigo()">Salvar</button>
    </div>
  </div>
</div>

{{-- NOVA UPF --}}

{{-- NOVO FERIADO --}}
@endif

{{-- MEU PERFIL — senha e assinatura do próprio usuário --}}
<div class="modal-bg" id="m-perfil" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-perfil')">&#10005;</button>

    <div class="perfil-cabeca">
      <div class="avatar grande">{{ auth()->user()->iniciais() }}</div>
      <div>
        <h3 style="margin:0">{{ auth()->user()->name }}</h3>
        <div class="sub" style="margin:0">
          {{ auth()->user()->perfilRotulo() }}@if (auth()->user()->tipo_usuario) · {{ ucfirst(auth()->user()->tipo_usuario) }}@endif
        </div>
      </div>
    </div>

    <div class="sec-title">Identificação</div>
    <div class="field">
      <label>E-mail</label>
      <input type="text" value="{{ auth()->user()->email }}" readonly>
    </div>
    <div class="field">
      <label>Matrícula</label>
      <input type="text" class="mono" value="{{ auth()->user()->matricula ?: '—' }}" readonly>
    </div>
    <p class="aviso-legal">
      Nome, e-mail, matrícula e perfil são alterados pelo administrador do
      sistema — mudam quem você é no processo administrativo.
    </p>

    <div class="sec-title">Trocar senha</div>
    <div class="field">
      {{-- Exigida mesmo com a sessão aberta: sem isso, um computador deixado
           destravado na repartição vira perda da conta. --}}
      <label for="pf-senha-atual">Senha atual</label>
      <input type="password" id="pf-senha-atual" autocomplete="current-password">
    </div>
    <div class="field">
      <label for="pf-senha-nova">Nova senha (mínimo 8 caracteres)</label>
      <input type="password" id="pf-senha-nova" autocomplete="new-password">
    </div>
    <div class="field">
      <label for="pf-senha-conf">Confirmar nova senha</label>
      <input type="password" id="pf-senha-conf" autocomplete="new-password">
    </div>
    <div class="btn-row">
      <button class="btn primary" onclick="salvarSenha()">Alterar senha</button>
    </div>

    <div class="sec-title">Minha assinatura</div>
    <p class="aviso-legal">
      Desenhada uma vez e aplicada automaticamente nos documentos que você
      lavrar. Documentos já lavrados guardam a assinatura do dia e não mudam.
    </p>
    <div id="pf-assinatura-atual"></div>
    <div class="assina-caixa">
      <canvas id="pf-canvas"></canvas>
      <span class="assina-linha"></span>
      <span class="assina-dica">Assine acima com o dedo ou o mouse</span>
    </div>
    <div class="btn-row">
      <button class="btn" onclick="limparAssinatura()">Limpar</button>
      <button class="btn" onclick="removerAssinatura()">Remover salva</button>
      <button class="btn primary" onclick="salvarAssinatura()">Salvar assinatura</button>
    </div>
  </div>
</div>

@php
  // Camada de imagem aérea alternativa, ligada pelo .env (ver config/gis.php).
  // Sem configuração o array sai vazio e o seletor mostra só Mapa e Satélite.
  //
  // Duas formas: MAPBOX_TOKEN monta a URL do Mapbox sozinho; SATELITE_ALT_URL
  // aceita qualquer serviço de tiles — é por onde a ortofoto municipal entra.
  $sateliteAlt = [];

  if ($token = config('gis.mapbox_token')) {
      $estilo = config('gis.mapbox_estilo');
      $sateliteAlt = [
          // @2x = tile de 512 px, que rende o dobro de definição na mesma área.
          'url'           => "https://api.mapbox.com/v4/{$estilo}/{z}/{x}/{y}@2x.jpg90?access_token={$token}",
          'rotulo'        => 'Satélite HD (Mapbox)',
          'atribuicao'    => '© Mapbox © Maxar',
          'maxNativeZoom' => 20,
          'tamanhoTile'   => 512,
      ];
  } elseif (config('gis.satelite_alt_url')) {
      $sateliteAlt = array_filter([
          'url'           => config('gis.satelite_alt_url'),
          'rotulo'        => config('gis.satelite_alt_rotulo'),
          'atribuicao'    => config('gis.satelite_alt_atribuicao'),
          'maxNativeZoom' => config('gis.satelite_alt_maxzoom'),
          // A partir de qual zoom a ortofoto entra por cima do satélite.
          'minZoom'       => config('gis.satelite_alt_minzoom'),
          // Retângulo coberto pela imagem: fora dele o tile nem é pedido, e
          // o satélite continua valendo. É o que permite ortofoto parcial.
          'bounds'        => config('gis.satelite_alt_bounds')
              ? array_map(
                  fn ($par) => array_map('floatval', explode(',', $par)),
                  explode(';', config('gis.satelite_alt_bounds'))
                )
              : null,
      ]);
  }
@endphp
<script>
window.USUARIO_ID = {{ auth()->id() }}
window.SATELITE_ALT = {{ Js::from($sateliteAlt) }}
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="@assetv('js/ui.js')"></script>
<script src="@assetv('js/geo.js')"></script>
<script src="@assetv('js/mapa.js')"></script>
<script src="@assetv('js/vistoria.js')"></script>
<script src="@assetv('js/mapa-cores.js')"></script>
<script src="@assetv('js/painel.js')"></script>
<script src="@assetv('js/documentos.js')"></script>
<script src="@assetv('js/protocolos.js')"></script>
<script src="@assetv('js/perfil.js')"></script>
@if (auth()->user()->isAdmin())
  <script src="@assetv('js/parametros.js')"></script>
@endif
<script src="@assetv('js/app.js')"></script>
</body>
</html>
