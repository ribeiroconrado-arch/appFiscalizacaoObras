<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#004D1C">
{{-- O POST de identificação passa pelo grupo `web`, então precisa do token. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Fiscalização de Obras — Mapa</title>
{{-- Dois conjuntos de ícone, um por tema. Os dois endereços vêm daqui, e não
     montados no JavaScript, para não perder o ?v= do @assetv — sem ele, uma
     regeração de ícones sairia do cache do navegador. --}}
<link rel="icon" type="image/png" sizes="32x32" href="@assetv('img/favicon-32.png')"
      data-src-institucional="@assetv('img/favicon-32.png')" data-src-f="@assetv('img/favicon-32-ambar.png')" data-src-cinza="@assetv('img/favicon-32-cinza.png')">
<link rel="icon" type="image/png" sizes="16x16" href="@assetv('img/favicon-16.png')"
      data-src-institucional="@assetv('img/favicon-16.png')" data-src-f="@assetv('img/favicon-16-ambar.png')" data-src-cinza="@assetv('img/favicon-16-cinza.png')">
<link rel="apple-touch-icon" sizes="180x180" href="@assetv('img/apple-touch-icon.png')"
      data-src-institucional="@assetv('img/apple-touch-icon.png')" data-src-f="@assetv('img/apple-touch-icon-ambar.png')" data-src-cinza="@assetv('img/apple-touch-icon-cinza.png')">
{{-- O manifesto também troca com o tema: é dele que saem o ícone e a tela de
     abertura do app instalado (ver js/tema.js). --}}
<link rel="manifest" href="@assetv('manifest.json')"
      data-src-institucional="@assetv('manifest.json')" data-src-f="@assetv('manifest-ambar.json')" data-src-cinza="@assetv('manifest-cinza.json')">
{{-- Leaflet servido daqui (public/vendor), não da CDN: script de terceiro roda
     com a sessão do fiscal, e uma CDN comprometida seria o sistema comprometido. --}}
<link rel="stylesheet" href="{{ asset('vendor/leaflet-1.9.4/leaflet.css') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
{{-- Primeiro as paletas: um tema é um bloco de variáveis, e as folhas
     seguintes só leem variável. Trocar de tema é trocar o data-tema do <html>. --}}
<link rel="stylesheet" href="@assetv('css/temas.css')">
<link rel="stylesheet" href="@assetv('css/app.css')">
<link rel="stylesheet" href="@assetv('css/tema-f.css')">
<link rel="stylesheet" href="@assetv('css/importacoes.css')">
<link rel="stylesheet" href="@assetv('css/painel-responsivo.css')">
<link rel="stylesheet" href="@assetv('css/prancheta-cadastral.css')">
{{-- Por último entre as de lista: o padrão de tabela vale por cima dos temas. --}}
<link rel="stylesheet" href="@assetv('css/tabelas.css')">
<link rel="stylesheet" href="@assetv('css/sinalizacoes.css')">
{{-- Sem defer: precisa rodar antes do primeiro pintar (ver js/tema.js). --}}
<script src="@assetv('js/tema.js')"></script>
</head>
<body>

{{--
  TELA ÚNICA: MAPA
  Convenções de UI herdadas do AppPOSTURAS — ver web/README.md do projeto:
  botões "Modelo E", campos "Modelo E", seções numeradas por contador CSS,
  modais que não fecham por clique no fundo, ícone sempre em SVG de linha.
--}}

@php
  // Topógrafo, arquiteto ou contribuinte: mapa, consulta e ficha, sem o que é
  // da fiscalização. Ver User::EXTERNOS.
  $externo = auth()->user()->isExterno();
@endphp
<header class="topo">
  {{-- Ícone oficial, sem o fundo de fora do squircle. Troca junto com o tema
       (ver js/tema.js): verde no institucional, âmbar no Tema F. --}}
  <img class="topo-marca" src="@assetv('img/logo-64.png')" alt=""
       data-src-institucional="@assetv('img/logo-64.png')" data-src-f="@assetv('img/logo-64-ambar.png')" data-src-cinza="@assetv('img/logo-64-cinza.png')">
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
    {{-- Os avisos são prazos de documento e demandas da fiscalização. --}}
    @unless ($externo)
    <button class="sino" onclick="abrirNotificacoes()" title="Avisos">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
      <span class="n" id="sino-n" style="display:none">0</span>
    </button>
    @endunless
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

{{-- ══════ SUB-CABEÇALHO ══════
     Diz DE QUEM é o sistema (esquerda) e ONDE se está dentro dele (direita).

     A entidade vem dos parâmetros e o brasão de um arquivo enviado em
     Parâmetros → Formulário — não há nada de Primavera do Leste escrito no código.
     É o que permite instalar o mesmo sistema em outro município trocando dois
     cadastros, em vez de mexer no fonte.

     O nome do módulo é preenchido a cada troca de aba (ver irPara). --}}
<div class="subcab">
  <div class="subcab-entidade">
    @php $brasaoUrl = \App\Models\Parametro::get('brasao_url'); @endphp
    @if ($brasaoUrl)
      <img src="{{ $brasaoUrl }}" alt="" class="subcab-brasao">
    @endif
    <span class="subcab-nome">{{ \App\Models\Parametro::get('orgao_secretaria') }}</span>
  </div>
  <div class="subcab-modulo">
    <span class="subcab-ico" id="subcab-ico"></span>
    <span id="subcab-nome">Painel</span>
  </div>
</div>

{{-- ══════ ABA: PAINEL ══════ --}}
<section class="tela at" id="t-painel">
  {{-- Visão por prioridades: indicadores, trabalho e atividade recente. --}}
  <header class="painel-cabecalho">
    <h1>Painel</h1>
    <p>Pendências e resultados da fiscalização.</p>
  </header>
  <div class="painel-dash">
    <div class="dash-tit">
      <div>
        <span class="sec-simples">Números do período</span>
        <p class="painel-filtro-resumo" id="pn-filtro-resumo">Últimos 30 dias · Todos os bairros · Todos os agentes</p>
      </div>
      <button type="button" class="btn painel-filtros-btn" aria-expanded="false" aria-controls="pn-filtros"
              onclick="alternarFiltrosPainel(this)">Filtros</button>
    </div>

    <div class="linha-filtro" id="pn-filtros" hidden>
      <select data-combo aria-label="Período" onchange="filtrarPainel('dias', this.value)">
        <option value="30">Últimos 30 dias</option>
        <option value="7">Últimos 7 dias</option>
        <option value="90">Últimos 90 dias</option>
        <option value="365">Últimos 365 dias</option>
      </select>
      <select data-combo id="pn-bairro" aria-label="Bairro" onchange="filtrarPainel('bairro', this.value)">
        <option value="">Todos os bairros</option>
      </select>
      <select data-combo aria-label="Agente" onchange="filtrarPainel('agente', this.value)">
        <option value="todos">Todos os agentes</option>
        <option value="eu">Meus registros</option>
      </select>
    </div>

    <div class="metricas" id="pn-metricas"></div>

  </div>
  <div class="painel-operacional">
    <div class="painel-coluna">
    <div class="bloco painel-pendencias">
      <div class="sec-simples">Prioridades para resolver <span class="cont" id="pn-atencao-n">0</span></div>
      {{-- Prazos de documento, ordens de serviço designadas a mim e
           protocolos sob minha responsabilidade — ver PainelController::atencao. --}}
      <div id="pn-atencao"></div>
    </div>

    <div class="bloco" id="pn-avisos-bloco" hidden>
      <div class="sec-simples">Outros avisos <span class="cont" id="pn-avisos-n">0</span></div>
      <div id="pn-avisos"></div>
    </div>
    <p class="painel-aviso-agrupado" id="pn-avisos-agrupados" hidden></p>
    </div>
    <div class="painel-lateral">
    <div class="bloco" id="pn-hoje-bloco" hidden>
      <div class="sec-simples">Para hoje <span class="cont" id="pn-hoje-n">0</span></div>
      <div id="pn-hoje"></div>
    </div>
    <div class="painel-duo">
      <div class="bloco">
        <div class="sec-simples">Documentos por tipo</div>
        <div id="pn-por-tipo"></div>
      </div>
      <div class="bloco">
        <div class="sec-simples">Infrações mais frequentes</div>
        <div id="pn-infracoes"></div>
      </div>
    </div>
    </div>
  </div>
    <div class="bloco painel-feed">
      <div class="sec-simples">Atividade recente</div>
      {{-- Alimentada pela tabela de auditoria — a mesma trilha que responde
           "quem fez o quê" no processo administrativo, não um log paralelo. --}}
      <div class="feed" id="pn-recentes"></div>
      <button type="button" class="painel-mais" id="pn-recentes-mais" hidden
              aria-expanded="false" aria-controls="pn-recentes" onclick="alternarRecentesPainel()">Mostrar mais atividades</button>
    </div>

</section>

{{-- ══════ ABA: MAPA ══════ --}}
<section class="tela" id="t-mapa">

<div id="map"></div>

{{-- ══════ FAIXA DAS BARRAS DO MAPA ══════
     Todas as barras que aparecem no alto do mapa moram AQUI, uma embaixo da
     outra: a da importação em revisão, a do desenho, a do modo de correção e a
     do contorno de inativo. Antes cada uma tinha o seu "top" fixo, calculado
     contra as outras — e bastava duas aparecerem juntas para uma cobrir a
     outra. Empilhadas num só lugar, não há altura a recalcular.

     Dentro de #t-mapa, para sumirem junto com o mapa ao trocar de aba. --}}
<div class="mapa-barras" id="mapa-barras">

{{-- A barra da PESQUISA (pesquisa-mapa.js): é a primeira, no topo. --}}
<div class="pesq-barra" id="pesq-barra" hidden></div>

{{-- A importação em revisão que se está olhando: publicar, conferir, excluir.
     Dentro de #t-mapa, e não fixa na página, para sumir junto com o mapa ao
     trocar de aba. Montada por importacoes.js. --}}
@if (auth()->user()->podeCurarCadastro())
  <div class="imp-barra" id="imp-barra" hidden></div>
  {{-- As pendências da conferência do bairro com o cadastro (conferencia-bairro.js). --}}
  <div class="imp-barra conf-barra" id="conf-barra" hidden></div>
  {{-- A ferramenta Nomes de rua (ruas-manuais.js). --}}
  <div class="imp-barra ruas-barra" id="ruas-barra" hidden></div>
@endif

{{-- ══════ BARRA DE DESENHO ══════

     UMA barra para todo desenho no mapa. Ela é do motor de desenho
     (public/js/desenho.js), não do cadastro: aparece sozinha sempre que um
     traçado começa, seja lote novo, edificação ou a divisa de um
     desmembramento, e some quando ele termina.

     Antes estes controles moravam dentro do painel "desenhar lote faltante" —
     então desenhar uma edificação era o mesmo motor sem trava de esquadro e
     sem desfazer, e a divisa do desmembramento idem. Mesmo gesto, três
     experiências diferentes. --}}
<div class="des-barra" id="des-barra" hidden>
  <span class="des-barra-modo" id="des-barra-modo">Desenhando</span>
  <span class="des-barra-passo" id="des-barra-passo">Toque nos cantos.</span>

  <button type="button" class="btn sm at" id="des-trava" aria-pressed="true"
          onclick="alternarTravaAngulo()"
          title="Trava cada lado em múltiplo de 45° do anterior. Segure Shift para soltar num canto.">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M4 4v16h16"/><path d="M4 12h8v8"/>
    </svg>
    90°
  </button>

  <button type="button" class="btn sm" onclick="desfazerVertice()"
          title="Ctrl+Z">Desfazer canto</button>
  <button type="button" class="btn sm" id="des-barra-voltar" hidden
          onclick="voltarATracar()">Voltar a traçar</button>
  {{-- Concordância (fillet): arredonda um canto do contorno já fechado. --}}
  <button type="button" class="btn sm" id="des-barra-concord" hidden aria-pressed="false"
          onclick="alternarConcordanciaDesenho()" title="Arredonda um canto com o raio informado">⌒ Concordância</button>
  <input type="number" id="des-raio" class="des-raio" hidden min="0" step="0.1" value="3"
         aria-label="Raio da concordância, em metros" title="Raio (m)">

  <button type="button" class="btn primary sm" id="des-barra-fechar"
          onclick="concluirDesenho()" title="Enter">Fechar contorno</button>
  <button type="button" class="btn sm" onclick="cancelarDesenho()"
          title="Esc">Cancelar</button>
</div>

{{-- ══════ CORREÇÃO CADASTRAL — BARRA DE MODO ══════
     Fica sobre o mapa, fina, dizendo o passo em que se está e quantos lotes já
     foram marcados. É o que substitui o painel lateral durante o trabalho: o
     gesto acontece no mapa, e o mapa continua inteiro à vista. --}}
<div class="cad-barra" id="cad-barra" hidden>
  <span class="cad-barra-modo" id="cad-barra-modo">Corrigir quadra</span>
  <span class="cad-barra-passo" id="cad-barra-passo">Marque os lotes no mapa.</span>
  <div class="cad-barra-acoes">
    <button type="button" class="btn sm" id="cad-barra-extra" hidden></button>
    <button type="button" class="btn sm primary" id="cad-barra-ok" hidden></button>
    <button type="button" class="btn sm" onclick="sairModoCadastral()">Sair</button>
  </div>
</div>

{{-- O contorno de um imóvel INATIVO fica por cima do mapa até alguém tirá-lo.
     Sem este botão, sair dele exigiria recarregar a página — e um traço cinza
     que não sai vira ruído em cima do trabalho seguinte. --}}
<button type="button" class="btn sm inativo-sair" id="btn-tirar-inativo" hidden
        onclick="tirarInativoDoMapa()">Tirar o contorno antigo do mapa</button>

</div>


<div class="chip-estado">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round">
    <path d="M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3z"/><path d="M9 3v15"/><path d="M15 6v15"/>
  </svg>
  <span id="chip-txt">Carregando…</span>
</div>

{{-- Coluna de controles recolhidos, sob o seletor de camadas do Leaflet.

     Cada controle é um GRUPO: enquanto fechado mostra só o ícone; aberto, o
     ícone dá lugar ao painel, e clicar fora devolve o ícone. É exatamente o
     comportamento do seletor de camadas logo acima — e é o que mantém o mapa
     visível, que era o motivo de recolher os painéis.

     A troca ícone/painel é feita por CSS a partir da classe .aberto no grupo
     (ver .ctrl-grupo em tema-f.css), não escondendo elementos no JavaScript. --}}
<div class="ctrl-mapa" id="ctrl-mapa">

  {{-- LOCALIZAÇÃO E ENQUADRAMENTO
       Eram dois botões largos flutuando sobre o rodapé; viraram ícones da
       mesma coluna dos demais. Ganho duplo: devolvem ao mapa a faixa que
       ocupavam na base da tela, e as ações do mapa passam a estar todas no
       mesmo lugar em vez de espalhadas por dois cantos.
       Ação direta, sem painel — por isso ficam fora de .ctrl-grupo. --}}
  <button class="ctrl-btn" id="btn-gps" onclick="usarMinhaLocalizacao()" title="Usar minha localização">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
      <circle cx="12" cy="12" r="8"/></svg>
  </button>

  <button class="ctrl-btn" onclick="verTudo()" title="Ver tudo">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/></svg>
  </button>

  {{-- CORES E LEGENDA --}}
  <div class="ctrl-grupo" id="grupo-cores">
    <button class="ctrl-btn" onclick="alternarPainelMapa('grupo-cores')"
            title="Cores e legenda" aria-expanded="false">
      {{-- Balde de tinta despejando.
           O leque de amostras que estava aqui tinha lâminas demais: a 40px, que
           é o tamanho real do botão, elas se fundiam num borrão. Quatro formas
           grandes e separadas sobrevivem à miniatura; quatro finas e
           sobrepostas, não. --}}
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <g transform="rotate(-32 11 11)">
          <path d="M5.6 7.4h10.8l-1.7 8a1.7 1.7 0 0 1-1.7 1.4H9a1.7 1.7 0 0 1-1.7-1.4z"/>
          <path d="M8.8 7.4C8.8 3.6 9.9 2 11 2s2.2 1.6 2.2 5.4"/>
        </g>
        <path d="M18.4 15.4s-2.1 2.6-2.1 3.9a2.1 2.1 0 0 0 4.2 0c0-1.3-2.1-3.9-2.1-3.9z"/>
      </svg>
    </button>
    <div class="ctrl-corpo">
      <b>Colorir por</b>
      {{-- "Uniforme" é o padrão. Sobre a imagem de satélite, pintar cada
           bairro de uma cor vira um mosaico que disputa com a própria foto: as
           manchas passam a ser o que se vê, no lugar das construções. Bairro e
           quadra continuam à mão para quem precisa da leitura de conjunto. --}}
      <div class="seg ctrl-cor">
        <button class="at" data-chave="uniforme" onclick="aplicarCores('uniforme')">Uniforme</button>
        <button data-chave="bairro" onclick="aplicarCores('bairro')">Bairro</button>
        <button data-chave="quadra" onclick="aplicarCores('quadra')">Quadra</button>
      </div>
      <div class="leg" id="leg-zoom">Bairro e logradouro · aproxime para quadra e lote</div>
      <div id="leg-cores"></div>
    </div>
  </div>

  {{-- CAMADAS — liga e desliga cada coisa que o mapa desenha. A lista é
       montada por camadas-mapa.js a partir do registro: camada nova aparece
       aqui sozinha. Não é ferramenta: abrir não fecha a curadoria. --}}
  <div class="ctrl-grupo" id="grupo-camadas">
    <button class="ctrl-btn" onclick="alternarPainelMapa('grupo-camadas')"
            title="Camadas" aria-expanded="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 3 2 8l10 5 10-5z"/><path d="m2 13 10 5 10-5"/><path d="m2 18 10 5 10-5" opacity=".55"/></svg>
    </button>
    <div class="ctrl-corpo ctrl-camadas">
      <div class="cam-topo">
        <b>Camadas</b>
        <button type="button" class="cam-x" onclick="alternarPainelMapa('grupo-camadas')"
                title="Fechar" aria-label="Fechar as camadas">&#10005;</button>
      </div>
      <div id="camadas-lista"></div>
    </div>
  </div>

  {{-- PESQUISAR NO MAPA — a lupa abre a BARRA de pesquisa no alto do mapa
       (#pesq-barra, montada por pesquisa-mapa.js), e não mais um painel aqui:
       com tipo (inscrição, quadra e lote, endereço, bairro, coordenada) e
       limite por bairro. Consulta o cadastro do próprio município — nenhum
       geocodificador externo, nenhum custo por consulta. --}}
  <div class="ctrl-grupo" id="grupo-busca">
    <button class="ctrl-btn" onclick="alternarPesquisaMapa()"
            title="Pesquisar no mapa" aria-expanded="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3"/><circle cx="11" cy="11" r="4"/><path d="m14 14 3 3"/></svg>
    </button>
  </div>

  {{-- CORREÇÃO CADASTRAL — só quem tem curadoria cadastral.
       Esconder o controle não é a segurança: quem autoriza de verdade é o
       servidor, em CadastroLoteController. Aqui é para não oferecer a quem
       não pode. --}}
  @if (auth()->user()->podeCurarCadastro())
  <div class="ctrl-grupo" id="grupo-cadastro">
    <button class="ctrl-btn" onclick="alternarPainelMapa('grupo-cadastro')"
            title="Correção cadastral" aria-expanded="false">
      {{-- Lápis sobre quadrículas: corrigir o desenho do cadastro, não o mapa. --}}
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
           stroke-linecap="round" stroke-linejoin="round">
        <rect x="2.5" y="2.5" width="8" height="8" rx="1.4"/>
        <rect x="2.5" y="13.5" width="8" height="8" rx="1.4"/>
        <rect x="13.5" y="13.5" width="8" height="8" rx="1.4"/>
        <path d="M21.2 2.8a1.9 1.9 0 0 1 0 2.7l-6 6-3 .8.8-3 6-6a1.9 1.9 0 0 1 2.2-.5z"/>
      </svg>
    </button>
    <div class="ctrl-corpo">
      <b>Correção cadastral</b>

      {{-- Painel do DESMEMBRAMENTO. Toma a vez do resto enquanto o ato está em
           curso: oferecer "corrigir quadra" e "desenhar lote faltante" no meio
           de um desmembramento seriam três assuntos ao mesmo tempo. --}}
      <div id="desm-caixa" hidden></div>

      {{-- LANÇADOR, e não formulário.
           Antes tudo morava aqui: campo de quadra, dados do lote, caixa de
           coordenadas, prévias. Numa coluna de 262px, ao lado do mapa onde o
           trabalho de fato acontece, isso obrigava a ler de lado, encolhia o
           mapa e escondia o passo seguinte. Agora aqui só se ESCOLHE o que
           fazer; o que é feito no mapa fica no mapa (barra de estado no topo)
           e o que é formulário vai para o modal. --}}
      <div id="cad-geral">
        <div class="cad-sep">Corrigir o desenho</div>

        <button type="button" class="btn sm cad-lanca" data-fer="quadra" data-tecla="Q" data-min="1" data-exige="1 lote ou mais" onclick="modoCadastral('quadra')">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="1.2"/>
            <rect x="14" y="3" width="7" height="7" rx="1.2"/>
            <rect x="3" y="14" width="7" height="7" rx="1.2"/>
            <path d="M14 17.5h7M17.5 14v7"/>
          </svg>
          <span class="cad-lanca-txt">Corrigir quadra
            <span class="cad-lanca-obs">Marque vários lotes; todos recebem a mesma quadra.</span>
          </span>
        </button>

        <button type="button" class="btn sm cad-lanca" data-fer="desenho" data-tecla="D" data-min="0" data-exige="nenhuma seleção" onclick="modoCadastral('desenho')">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 20 20 4"/><path d="M4 14v6h6"/>
            <path d="M13.5 4H20v6.5"/>
          </svg>
          <span class="cad-lanca-txt">Desenhar lote faltante
            <span class="cad-lanca-obs">Na prancheta: medida, perpendicular, offset, concordância.</span>
          </span>
        </button>

        {{-- EDITAR LOTE — só na pré-curadoria: lote publicado muda por
             unificação, desmembramento ou correção de quadra, com prova e
             histórico. Aparece quando importacoes.js entra no modo. --}}
        <button type="button" class="btn sm cad-lanca so-pre" id="cad-editar-lote" hidden
                data-fer="editar" data-tecla="L" data-min="1" data-max="1" data-exige="exatamente 1 lote"
                onclick="editarLoteDaPreCuradoria()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 4h10l6 6v10H4z"/><circle cx="4" cy="4" r="1.6"/><circle cx="14" cy="4" r="1.6"/>
            <circle cx="20" cy="10" r="1.6"/><circle cx="20" cy="20" r="1.6"/><circle cx="4" cy="20" r="1.6"/>
          </svg>
          <span class="cad-lanca-txt">Editar lote
            <span class="cad-lanca-obs">Vértices, número e quadra — só em lote de importação não publicada.</span>
          </span>
        </button>

        {{-- INFORMAR NÚMERO e EXCLUIR LOTES — também só na pré-curadoria
             (PreCuradoriaDeLotes): o número que a conversão do DWG errou e o
             polígono que sobrou. Lote publicado tem caminho próprio. --}}
        <button type="button" class="btn sm cad-lanca so-pre" hidden
                data-fer="numero" data-tecla="N" data-min="1" data-max="1" data-exige="exatamente 1 lote"
                onclick="numerarLoteDaPreCuradoria()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 4 7 20M17 4l-2 16M4 9h16M3 15h16"/>
          </svg>
          <span class="cad-lanca-txt">Informar número do lote
            <span class="cad-lanca-obs">Marque 1 lote e digite o número — só em lote de importação não publicada.</span>
          </span>
        </button>

        <button type="button" class="btn sm cad-lanca cad-lanca-perigo so-pre" hidden
                data-fer="excluir-pre" data-tecla="X" data-min="1" data-exige="1 lote ou mais"
                onclick="excluirLotesDaPreCuradoria()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="4" y="4" width="16" height="16" rx="1.4"/><path d="m9 9 6 6M15 9l-6 6"/>
          </svg>
          <span class="cad-lanca-txt">Excluir lotes
            <span class="cad-lanca-obs">Marque um ou vários lotes de uma importação não publicada.</span>
          </span>
        </button>

        <button type="button" class="btn sm cad-lanca" data-fer="coordenadas" data-tecla="C" data-min="0" data-exige="nenhuma seleção" onclick="modoCadastral('coordenadas')">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 21s7-6.5 7-11.5A7 7 0 0 0 5 9.5C5 14.5 12 21 12 21z"/>
            <circle cx="12" cy="9.5" r="2.4"/>
          </svg>
          <span class="cad-lanca-txt">Lote por coordenadas
            <span class="cad-lanca-obs">Cole os vértices do memorial descritivo.</span>
          </span>
        </button>

        {{-- A edificação não é um "modo cadastral": ela não mexe na divisa do
             lote, não pede quadra nem número, e o desenho termina numa única
             pergunta. Por isso chama direto, sem passar por `modoCadastral`. --}}
        <div class="cad-sep">O que está construído</div>

        <button type="button" class="btn sm cad-lanca" data-fer="edificacao" data-tecla="E" data-min="1" data-max="1" data-exige="exatamente 1 lote" onclick="desenharEdificacao()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/>
            <path d="M10 20v-5h4v5"/>
          </svg>
          <span class="cad-lanca-txt">Desenhar edificação
            <span class="cad-lanca-obs">Selecione 1 lote e contorne a construção dentro dele.</span>
          </span>
        </button>

        @if (auth()->user()->podeCurarCadastro())
          {{-- ATOS DIRETOS — o desenho em dia com o que já aconteceu.
               Separados dos de cima por um traço porque são de outra natureza:
               os três primeiros CORRIGEM o desenho; estes executam um ato que
               normalmente viria de protocolo deferido, ou apagam um resíduo. Só
               o curador do cadastro os vê.

               Cada um diz O QUE PRECISA ANTES, porque as três exigências são
               diferentes e descobrir isso na recusa é tarde:
                 unificar     dois ou mais lotes, que se encostam
                 desmembrar   um lote, que será dividido em partes desenhadas
                 apagar       um lote, sem nada preso a ele --}}
          <div class="cad-sep">Sem protocolo — só curadoria</div>

          <button type="button" class="btn sm cad-lanca" data-fer="unificacao" data-tecla="U" data-min="2" data-exige="2 lotes ou mais, vizinhos" onclick="atoDiretoCadastral('unificacao')">
            <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="3" y="6" width="8" height="12" rx="1.2"/>
              <rect x="13" y="6" width="8" height="12" rx="1.2"/>
              <path d="M11 12h2"/>
            </svg>
            <span class="cad-lanca-txt">Unificar lotes direto
              <span class="cad-lanca-obs">Marque 2 ou mais lotes vizinhos; eles viram um.</span>
            </span>
          </button>

          <button type="button" class="btn sm cad-lanca" data-fer="desmembramento" data-tecla="S" data-min="1" data-max="1" data-exige="1 lote" onclick="atoDiretoCadastral('desmembramento')">
            <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="4" y="4" width="16" height="16" rx="1.4"/>
              <path d="M12 3v18" stroke-dasharray="3 2.5"/>
            </svg>
            <span class="cad-lanca-txt">Desmembrar lote direto
              <span class="cad-lanca-obs">Selecione 1 lote; a divisa sai de um corte, não de novo desenho.</span>
            </span>
          </button>

          <button type="button" class="btn sm cad-lanca cad-lanca-perigo" data-fer="apagar" data-tecla="Del" data-min="1" data-exige="1 lote ou mais, sem nada preso" onclick="apagarLoteDoPainel()">
            <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/>
              <path d="M6 6v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6"/>
            </svg>
            <span class="cad-lanca-txt">Apagar lote residual
              <span class="cad-lanca-obs">Marque um ou vários. Só para sobra da conversão do desenho.</span>
            </span>
          </button>
        {{-- O DESFAZER MORA AQUI, e não numa tela de administração.

             Quem desfaz um ato do cadastro é quem estava fazendo o ato — o
             curador, no mapa, com o desenho à frente. Levar isso para
             Parâmetros obrigaria a sair do trabalho, procurar a linha numa
             lista do sistema inteiro e voltar. O histórico do cadastro é
             ferramenta de cadastro. --}}
        <div class="cad-sep">O que foi feito</div>

        <button type="button" class="btn sm cad-lanca"
                data-fer="historico" data-tecla="H" data-min="0"
                data-exige="nenhuma seleção" onclick="abrirHistoricoCadastral()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 4v4h4"/><path d="M12 8v4l3 2"/>
          </svg>
          <span class="cad-lanca-txt">Histórico do cadastro
            <span class="cad-lanca-obs">O que foi alterado no desenho, e o caminho de volta.</span>
          </span>
        </button>

        @endif

        {{-- BASE DE LOTES — bairro novo entra por aqui, em revisão, e só vale
             para todos depois que o administrador publica. Ver
             ImportacaoController e public/js/importacoes.js. --}}
        <div class="cad-sep">Base de lotes</div>

        <button type="button" class="btn sm cad-lanca" data-fer="importacoes" data-min="0"
                data-exige="nenhuma seleção" onclick="abrirImportacoes()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/>
          </svg>
          <span class="cad-lanca-txt">Importações de bairro
            <span class="cad-lanca-obs">Carregar GeoJSON, revisar e publicar.
              <span class="imp-contador" id="imp-contador" hidden></span></span>
          </span>
        </button>

        {{-- Contorno de cada bairro, derivado dos lotes — ver bairros-contorno.js. --}}
        <button type="button" class="btn sm cad-lanca" data-fer="contornos" data-min="0"
                data-exige="nenhuma seleção" onclick="abrirContornosDosBairros()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="3 2.5" aria-hidden="true">
            <path d="M4 7l6-3 10 4-2 10-9 2-5-6z"/>
          </svg>
          <span class="cad-lanca-txt">Contorno dos bairros
            <span class="cad-lanca-obs">Gerar e atualizar a linha de cada bairro.</span>
          </span>
        </button>

        {{-- O nome dos trechos de rua que o cadastro não resolveu — ver ruas-manuais.js. --}}
        <button type="button" class="btn sm cad-lanca" data-fer="ruas" data-min="0"
                data-exige="nenhuma seleção" onclick="abrirNomesDeRua()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 20L10 4"/><path d="M20 20L14 4"/><path d="M12 6v2"/><path d="M12 11v2"/><path d="M12 16v2"/>
          </svg>
          <span class="cad-lanca-txt">Nomes de rua
            <span class="cad-lanca-obs">Informar o nome dos trechos que o cadastro não resolveu.</span>
          </span>
        </button>

        {{-- Conferência do bairro com o cadastro — a que fica depois da
             importação: pendências no mapa, justificar o que não dá agora. --}}
        <button type="button" class="btn sm cad-lanca" data-fer="conferencia" data-min="0"
                data-exige="nenhuma seleção" onclick="abrirConferenciaBairro()">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>
          </svg>
          <span class="cad-lanca-txt">Conferência com o cadastro
            <span class="cad-lanca-obs">Pendências do bairro no mapa; justificar o que não dá agora.</span>
          </span>
        </button>

        {{-- "Mostrar lotes não publicados" mudou-se para o painel Camadas. --}}

        <div class="cad-dica">O trabalho acontece no mapa; o que a ferramenta
          pede aparece aqui nesta coluna.</div>
      </div>{{-- /cad-geral --}}
    </div>
  </div>
  @endif
</div>


</section>

{{-- ══════ ABA: BUSCA DE IMÓVEIS ══════
     Consulta de cadastro sem abrir o mapa. A camada de satélite é serviço
     pago por requisição; conferir a situação de um lote — que é a maior parte
     das consultas de balcão — não deveria gerar faturamento de imagem aérea
     para ler quatro campos de texto.

     Resultado com vários imóveis vira TABELA (só o essencial). Escolhido um,
     a ficha técnica abre NA PRÓPRIA TELA, não em modal: aqui não há mapa por
     baixo para preservar, então a sobreposição só atrapalharia. --}}
<section class="tela" id="t-busca">
  <div class="topo-lista">
    <div class="sec-simples">Consulta de imóveis</div>
    <span class="bs-selo" id="bs-selo" hidden></span>
  </div>

  <div class="busca-form">
    {{-- Uma linha só, e a inscrição imobiliária primeiro: ela é o identificador
         do imóvel e tem precedência sobre todos os demais filtros (ver
         marcarPrecedencia). A ordem na tela acompanha a ordem da regra.
         As larguras seguem o conteúdo real: quadra e lote têm dois ou três
         dígitos, a inscrição tem formato fixo, e a sobra vai para o bairro. --}}
    <div class="busca-campos">
      <div class="field bc-insc">
        <label for="bs-inscricao">Inscrição imobiliária</label>
        {{-- O exemplo traz um BAIRRO DE VERDADE (105). O anterior mostrava
             000 no lugar dele, herdado de quando a tela montava a inscrição
             sem conhecer o código do bairro — e ensinava, no campo de busca,
             um número que não corresponde a imóvel nenhum. --}}
        <input type="text" id="bs-inscricao" class="mono" maxlength="40"
               placeholder="01.105.024.0009.000" oninput="marcarPrecedencia()">
      </div>
      {{-- LOGRADOURO E NÚMERO vêm do cadastro da prefeitura, não do desenho: o
           DWG traz o polígono, a quadra e o lote, e nunca o nome da rua. O
           combo só oferece ruas que PODEM achar alguma coisa — as dos bairros
           cujo cadastro já foi carregado e amarrado (ver /api/imoveis/
           logradouros). Oferecer o resto seria oferecer busca vazia. --}}
      {{-- Combobox padrão (docs/ai/DESIGN-SYSTEM.md): campo, × e lista. --}}
      <div class="field bc-logr">
        <label for="bs-logradouro">Logradouro</label>
        <div class="ac-wrap">
          <input type="text" id="bs-logradouro" autocomplete="off"
                 placeholder="Digite para buscar a rua…"
                 oninput="buscarLogradouro(this.value); marcarPrecedencia()"
                 onfocus="buscarLogradouro(this.value)">
          <button class="clr-btn" type="button" tabindex="-1" title="Limpar"
                  onclick="escolherLogradouro(''); document.getElementById('bs-logradouro').focus()">&times;</button>
          <div class="ac-list" id="bs-logr-sugestoes"></div>
        </div>
      </div>
      <div class="field bc-num">
        <label for="bs-numero">Número</label>
        <input type="text" id="bs-numero" class="mono" inputmode="numeric" maxlength="12"
               placeholder="1300" oninput="marcarPrecedencia()">
      </div>
      <div class="field bc-num">
        <label for="bs-quadra">Quadra</label>
        <input type="text" id="bs-quadra" class="mono" inputmode="numeric" maxlength="20"
               placeholder="24" oninput="marcarPrecedencia()">
      </div>
      <div class="field bc-num">
        <label for="bs-lote">Lote</label>
        <input type="text" id="bs-lote" class="mono" inputmode="numeric" maxlength="20"
               placeholder="9" oninput="marcarPrecedencia()">
      </div>
      {{-- O bairro fecha a linha, encostado no funil: é o filtro mais LARGO e
           o menos específico dos cinco — quem sabe a rua ou a quadra não
           precisa dele, e quem só tem o bairro começa por ele mesmo. --}}
      <div class="field bc-bairro">
        <label for="bs-bairro">Bairro / loteamento</label>
        <select data-combo id="bs-bairro" onchange="marcarPrecedencia()">
          <option value="">— todos —</option>
        </select>
      </div>

      {{-- Mais filtros recolhidos: são de uso ocasional e ocupariam a linha
           inteira o tempo todo se ficassem expostos. --}}
      <button type="button" class="bs-mais" id="bs-mais" onclick="alternarFiltrosAvancados()"
              title="Mais filtros" aria-expanded="false">
        {{-- O MESMO funil do botão "Filtros" da lista de documentos. Aqui era
             um hambúrguer — três linhas iguais —, que no resto do mundo quer
             dizer "menu", não "filtrar". Duas telas do mesmo sistema pedindo a
             mesma coisa com desenhos diferentes obrigam a reaprender o ícone a
             cada aba. --}}
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
      </button>
    </div>

    <div class="busca-avancado" id="busca-avancado" hidden>
      <div class="busca-campos">
        <div class="field bc-insc">
          <label for="bs-bci-de">BCI — de</label>
          <input type="text" id="bs-bci-de" class="mono" maxlength="40"
                 placeholder="01.000.024.0001.000" oninput="marcarPrecedencia()">
        </div>
        <div class="field bc-insc">
          <label for="bs-bci-ate">BCI — até</label>
          <input type="text" id="bs-bci-ate" class="mono" maxlength="40"
                 placeholder="01.000.024.0099.000" oninput="marcarPrecedencia()">
        </div>
        <div class="field bc-bairro">
          <label for="bs-vistoria">Situação da última vistoria</label>
          <select data-combo id="bs-vistoria" onchange="marcarPrecedencia()">
            <option value="">— qualquer —</option>
            @foreach (\App\Models\Vistoria::SITUACOES as $valor => $rotulo)
              <option value="{{ $valor }}">{{ $rotulo }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="bs-chks">
        <label class="chk-item"><input type="checkbox" id="bs-embargo" onchange="marcarPrecedencia()">
          <span class="desc">Com embargo ativo</span></label>
        <label class="chk-item"><input type="checkbox" id="bs-pendente" onchange="marcarPrecedencia()">
          <span class="desc">Com documento pendente</span></label>
        <label class="chk-item"><input type="checkbox" id="bs-sem-vistoria" onchange="marcarPrecedencia()">
          <span class="desc">Projeto aprovado sem vistoria</span></label>
        {{-- O IMÓVEL QUE DEIXOU DE EXISTIR.
             Unificação e desmembramento não apagam o lote de origem: ele fica
             inativo, com os documentos e vistorias dele pendurados. Some do
             mapa, some da consulta — e era só por aqui que se podia chegar de
             volta a um processo que corre contra um lote já desmembrado. --}}
        <label class="chk-item"><input type="checkbox" id="bs-inativos">
          <span class="desc">Incluir imóveis inativos (unificados/desmembrados)</span></label>
      </div>
    </div>

    {{-- Aviso de precedência: a inscrição identifica UM imóvel, então combiná-la
         com bairro ou quadra só produziria contradição. Em vez de devolver
         vazio e parecer defeito, o sistema diz o que está valendo. --}}
    <div class="bs-precedencia" id="bs-precedencia" hidden></div>

    <div class="btn-row">
      <button class="btn" onclick="limparBusca()">Limpar</button>
      <button class="btn primary" onclick="executarBusca()">Buscar</button>
    </div>
  </div>

  <div id="busca-resultado"></div>
</section>

{{-- ══════ ABA: DOCUMENTOS (Etapa 6) ══════ --}}
<section class="tela" id="t-documentos">
  <div class="topo-lista">
    <div class="sec-simples">Documentos <span class="cont" id="cont-doc">0</span></div>
    @if (auth()->user()->podeLavrarDocumento())
      <button class="btn primary sm" onclick="novoDocumento(event)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Novo documento
      </button>
    @else
      {{-- Só agente de fiscalização lavra: coordenador e secretário acompanham,
           não autuam. A regra real está no controller; aqui é conveniência. --}}
      <button class="btn sm" disabled title="Só agente de fiscalização emite documentos">Novo documento</button>
    @endif
  </div>

  {{-- BUSCA À VISTA, FILTRO GUARDADO.
       Eram quatro controles soltos em duas linhas, ocupando o topo da tela
       mais usada do sistema para uma combinação que quase ninguém muda. A
       busca fica; o resto vai para uma janela, e o que estiver aplicado volta
       como etiqueta — porque filtro escondido é como uma lista parece vazia
       sem que ninguém lembre por quê. --}}
  {{-- MESMO INVÓLUCRO DA CONSULTA (`.busca-form`): caixa branca delimitando o
       filtro, o funil recolhendo o que é ocasional, e as ações à direita. As
       três listas do sistema e a Consulta pediam a mesma coisa com desenhos
       diferentes — quatro telas, quatro molduras. --}}
  <div class="busca-form lista-form">
    <div class="filtro-barra">
      <div class="lista-campo lista-campo-busca"><label for="doc-busca">Busca</label><div class="filtro-busca">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
        <input type="text" id="doc-busca" placeholder="Buscar nº, imóvel ou autuado…"
               oninput="filtrarDocumentos('busca', this.value)"></div>
      </div>
      <button type="button" class="bs-mais" onclick="abrirFiltrosDoc()" title="Mais filtros">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
        <span class="filtro-cont" id="doc-filtro-n" hidden>0</span>
      </button>
    </div>
    {{-- No computador os mesmos seletores voltam para cá, à vista. Nascem
         clonados dos da janela (`montarFiltroLargoDoc`), para as opções de tipo
         não serem escritas duas vezes e divergirem depois. --}}
    <div class="doc-filtro-larga" id="doc-filtro-larga"></div>
    <div class="filtro-chips" id="doc-chips"></div>
    {{-- As mesmas duas ações da Consulta — e aqui "Buscar" é a ÚNICA porta:
         escolher filtro não consulta nada, só anota. O ponto no botão avisa que
         há escolha ainda não aplicada. Serve também para recarregar sem trocar
         de aba, depois de alguém ter lavrado uma peça em outra janela. --}}
    <div class="btn-row lista-form-acoes">
      <button type="button" class="btn" onclick="limparFiltrosDoc()">Limpar</button>
      <button type="button" class="btn primary" id="doc-buscar"
              onclick="carregarDocumentos()">Buscar</button>
    </div>
  </div>

  <div id="lista-documentos"></div>
</section>

{{-- ══════ ABA: PROTOCOLOS ══════
     Requerimentos do contribuinte. Sem dashboard, por decisão de projeto: a
     aba existe para trabalhar a fila, e o painel já resume os números. --}}
<section class="tela" id="t-protocolos">
  {{-- A aba se chama "Protocolo & OS" desde sempre, mas só tinha protocolo.
       Protocolo é o que CHEGA de fora; ordem de serviço é o que a coordenação
       determina para dentro. Moram na mesma tela porque é a mesma pergunta —
       "o que há para fazer?" —, e se separam em abas porque as respostas têm
       dono diferente. --}}
  {{-- AS DUAS ABAS VIRARAM UMA LISTA. Elas respondiam à mesma pergunta e
       obrigavam a olhar duas telas para saber o que estava pendente. O TIPO
       virou coluna e filtro; o que continua separado é o formulário — e as
       tabelas do banco, que só têm quatro colunas em comum (ver
       DemandaController). --}}
  <div class="topo-lista">
    <div class="sec-simples">Protocolos e ordens de serviço
      <span class="cont" id="cont-demandas">0</span></div>
    @if (auth()->user()->canEdit())
      <button class="btn primary sm" onclick="novoProtocolo()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Novo protocolo
      </button>
    @else
      <button class="btn sm" disabled title="Seu perfil é somente de consulta">Novo protocolo</button>
    @endif
    {{-- Emitir é da coordenação. Esconder de quem não pode não é a segurança —
         quem autoriza é OrdemServicoController::store —, é não oferecer o que
         seria recusado. --}}
    @if (auth()->user()->isAdmin())
      <button class="btn primary sm" onclick="novaOs()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Nova OS
      </button>
    @else
      <button class="btn sm" disabled title="Só a coordenação emite ordem de serviço">Nova OS</button>
    @endif
  </div>

  <div class="busca-form lista-form">
    <div class="filtros-lista">
      <div class="linha-filtro">
        <div class="lista-campo"><label for="dm-tipo">Tipo</label><select data-combo id="dm-tipo" onchange="filtrarDemandas('tipo', this.value)">
          <option value="">Protocolos e ordens</option>
          <option value="protocolo">Só protocolos</option>
          <option value="os">Só ordens de serviço</option>
        </select></div>
        {{-- "Todos" e não "meus": protocolo chega SEM DONO, e abrir a fila
             filtrada pelo agente esconderia justamente o que ninguém assumiu.
             Vale para a lista inteira agora. --}}
        <div class="lista-campo"><label for="dm-agente">Responsável</label><select data-combo id="dm-agente" onchange="filtrarDemandas('agente', this.value)">
          <option value="todos">Todos os responsáveis</option>
          <option value="eu">Meus</option>
          <option value="sem_dono">Não distribuídos</option>
        </select></div>
      </div>
      <div class="linha-filtro">
        <div class="lista-campo lista-campo-busca"><label for="dm-busca">Busca</label><input type="text" id="dm-busca" placeholder="Buscar nº, requerente, objeto ou imóvel…"
               oninput="filtrarDemandas('busca', this.value)"></div>
        {{-- Agrupada por tipo: "Deferido" e "Concluída" não são alternativas
             da mesma pergunta. --}}
        <div class="lista-campo"><label for="dm-situacao">Situação</label><select data-combo id="dm-situacao" onchange="filtrarDemandas('situacao', this.value)">
          <option value="">Todas as situações</option>
        </select></div>
      </div>
    </div>
    <div class="btn-row lista-form-acoes">
      <button type="button" class="btn" onclick="limparFiltrosDemandas()">Limpar</button>
      <button type="button" class="btn primary" id="dm-buscar"
              onclick="carregarDemandas()">Buscar</button>
    </div>
  </div>

  <div id="lista-demandas"></div>
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
    <button data-sub="bairros" onclick="subParametros('bairros')">Bairros</button>
    <button data-sub="cadastro" onclick="subParametros('cadastro')">Cadastro municipal</button>
    <button data-sub="geral" onclick="subParametros('geral')">Formulário</button>
  </div>

  {{-- PADRÃO DE TODAS AS ABAS (o do AppPOSTURAS): no topo só a busca e o
       "+ Novo"; cada item da lista tem o próprio Editar, que abre os campos
       dentro da linha. Os cartões são desenhados por parametros.js. --}}

  {{-- USUÁRIOS — o Editar abre a janela do usuário (senha e permissões). --}}
  <div class="par-painel par-fixo at" id="par-usuarios">
    <div class="par-fixo-topo">
      <div class="sec-simples">Usuários <span class="cont" id="cont-usuarios">0</span></div>
      <div class="par-busca">
        <input type="search" id="busca-usuarios" placeholder="Procurar por nome, login ou matrícula" oninput="renderUsuarios()">
        <button class="btn out-verde sm" onclick="novoUsuario()">+ Novo usuário</button>
      </div>
    </div>
    <div class="par-fixo-lista" id="lista-usuarios"></div>
  </div>

  {{-- LEGISLAÇÃO — lista de leis → detalhe da lei (artigos e textos de
       ciência). Aninhar os artigos dentro da lista virava uma árvore longa
       demais para achar qualquer coisa. --}}
  <div class="par-painel par-fixo" id="par-legislacao">
    <div class="par-fixo-topo" id="leg-topo-lista">
      <div class="sec-simples">Leis <span class="cont" id="cont-leis">0</span></div>
      <div class="par-busca">
        <input type="search" id="lei-busca" placeholder="Procurar lei por número ou nome" oninput="renderLeis()">
        <button class="btn out-verde sm" onclick="novaLei()">+ Nova lei</button>
      </div>
    </div>
    <div class="par-fixo-topo" id="leg-topo-detalhe" style="display:none">
      <div class="sub-topo">
        <button class="btn sm" onclick="voltarLeis()">← Voltar</button>
        <div class="titulo" id="leg-detalhe-titulo">—</div>
      </div>
      {{-- A JANELA DA LEI: dados gerais, textos de ciência e artigos, cada um
           na sua aba. Ver, editar e cadastrar uma lei acontecem aqui. --}}
      <div class="sub-abas">
        <button class="at" data-leg="dados" onclick="subLei('dados')">Dados gerais</button>
        <button data-leg="textos" onclick="subLei('textos')">Textos de ciência</button>
        <button data-leg="artigos" onclick="subLei('artigos')">Artigos</button>
      </div>
      <div class="par-busca" id="leg-busca-artigos">
        <input type="search" id="busca-artigos" placeholder="Procurar artigo por número, apelido ou termo" oninput="renderLeis()">
        <button class="btn out-verde sm" onclick="parNovo('artigos')">+ Novo artigo</button>
      </div>
    </div>
    <div class="par-fixo-lista" id="lista-leis"></div>
  </div>

  {{-- UPF — por exercício: um documento lavrado em 2026 continua valendo a
       UPF de 2026 depois que o decreto do ano seguinte entra. --}}
  <div class="par-painel par-fixo" id="par-upf">
    <div class="par-fixo-topo">
      <div class="sec-simples">UPF por exercício <span class="cont" id="cont-upf">0</span></div>
      <div class="par-busca">
        <input type="search" id="busca-upf" placeholder="Procurar por ano ou norma" oninput="renderUpfs()">
        <button class="btn out-verde sm" onclick="parNovo('upf')">+ Nova UPF</button>
      </div>
    </div>
    <div class="par-fixo-lista" id="lista-upf"></div>
  </div>

  {{-- FERIADOS — lista de anos → feriados do ano. Usados para contar o prazo
       de defesa em dias úteis. --}}
  <div class="par-painel par-fixo" id="par-feriados">
    <div class="par-fixo-topo" id="fer-topo-anos">
      <div class="sec-simples">Calendário de feriados <span class="cont" id="cont-feriados">0</span></div>
      <div class="par-busca">
        <input type="search" id="busca-anos" placeholder="Procurar ano" oninput="renderFeriados()">
        <button class="btn out-verde sm" onclick="parNovo('anos')">+ Novo ano</button>
      </div>
    </div>
    <div class="par-fixo-topo" id="fer-topo-ano" style="display:none">
      <div class="sub-topo">
        <button class="btn sm" onclick="voltarAnosFeriados()">← Voltar</button>
        <div class="titulo" id="fer-ano-titulo">—</div>
      </div>
      <div class="par-busca">
        <input type="search" id="busca-feriados" placeholder="Procurar feriado" oninput="renderFeriados()">
        <button class="btn out-verde sm" onclick="parNovo('feriados')">+ Novo feriado</button>
      </div>
    </div>
    <div class="par-fixo-lista" id="lista-feriados"></div>
  </div>

  {{-- BAIRROS --}}
  <div class="par-painel par-fixo" id="par-bairros">
    <div class="par-fixo-topo">
      <div class="sec-simples">Bairros do município <span class="cont" id="cont-bairros">0</span></div>
      <div class="par-busca">
        <input type="search" id="filtro-bairros" placeholder="Procurar por código ou nome" oninput="renderBairros()">
        <button class="btn out-verde sm" onclick="parNovo('bairros')">+ Novo bairro</button>
      </div>
    </div>
    <div class="par-fixo-lista" id="lista-bairros"></div>
  </div>

  {{-- ÓRGÃO --}}
  {{-- CADASTRO MUNICIPAL — a planilha mensal da prefeitura. Grava só o que
       mudou, marca o que sumiu e apaga o arquivo ao fim. Montado por
       cadastro-municipal.js; ver CadastroCargaController. --}}
  <div class="par-painel" id="par-cadastro">
    <div class="par-sec"><span class="par-num">1</span>Enviar o cadastro do mês</div>
    {{-- DOIS CAMINHOS, o mesmo resultado no banco. O recomendado é o app
         desktop (ferramentas/cadastro-desktop): a planilha bruta, com o CPF de
         todo o município, nem chega ao servidor — o app lê no PC e gera um
         JSON só com o que mudou. Para saber o que mudou ele precisa da
         REFERÊNCIA, que não tem dado pessoal (App\Cadastro\ReferenciaDoCadastro).
         A planilha .xlsx direta continua aceita. --}}
    <div class="btn-row" style="margin-bottom:8px;justify-content:flex-start;align-items:center">
      <a class="btn out-verde sm" style="text-decoration:none" href="/api/cadastro/referencia" download>Baixar referência</a>
      <span class="imp-sub">Só inscrições e códigos de conferência — sem nome nem CPF.</span>
    </div>
    <label class="imp-soltar" id="cm-soltar" for="cm-arquivo">
      <input type="file" id="cm-arquivo" accept=".json,.xlsx" onchange="cmArquivoEscolhido()">
      <b>Solte aqui o .json do app ou a planilha .xlsx</b>
      <span>ou clique para escolher no computador</span>
    </label>
    <div class="btn-row" style="margin-top:8px">
      <button class="btn primary sm" id="cm-enviar" onclick="enviarCargaDoCadastro()" disabled>Enviar e processar</button>
    </div>
    <div id="cm-andamento"></div>

    <div class="par-sec" style="margin-top:20px"><span class="par-num">2</span>Cargas<span class="cont" id="cont-cargas">0</span></div>
    <div id="cm-lista"></div>
    <div id="cm-detalhe"></div>
  </div>

  <div class="par-painel" id="par-geral">
    {{-- Sub-abas: cada uma junta os campos que saem no mesmo lugar do
         documento (GERAL_ABAS, em parametros.js). --}}
    <div class="sub-abas" id="abas-geral"></div>

    {{-- É o brasão que torna o sistema replicável: instalar a mesma aplicação
         em outra prefeitura passa a ser trocar dois cadastros, em vez de mexer
         no código. Por isso ele é enviado aqui, e não embutido em public/img. --}}
    <div id="geral-brasao">
      <div class="par-sec">Brasão do município</div>
      <div class="brasao-caixa">
        <div class="brasao-previa" id="brasao-previa"></div>
        <div class="brasao-acoes">
          <input type="file" id="brasao-arquivo" accept="image/png,image/jpeg" hidden
                 onchange="enviarBrasao(this)">
          <button class="btn out-verde sm" onclick="document.getElementById('brasao-arquivo').click()">
            Enviar imagem
          </button>
          <button class="btn out-vermelho sm" id="brasao-remover" onclick="removerBrasao()" hidden>
            Remover
          </button>
        </div>
      </div>
    </div>

    <div id="lista-geral" style="margin-top:14px"></div>
  </div>
  </div>
</div>
@endif

{{-- ══════ ABAS ══════ --}}
{{-- Menu lateral recolhido (só ícones)? Antes do primeiro pintar, para a tela não
     abrir larga e depois encolher. Ver alternarMenuLateral (ui.js). --}}
<script>try{if(localStorage.getItem('menu-recolhido')==='1')document.documentElement.classList.add('menu-recolhido')}catch(e){}</script>
<nav class="abas" aria-label="Navegação principal">
  {{-- ☰ Recolher/expandir — só existe quando o menu é LATERAL (tela larga); na
       barra de baixo do celular ele fica escondido (painel-responsivo.css). --}}
  <button type="button" class="aba-recolher" onclick="alternarMenuLateral()" aria-label="Recolher menu" title="Recolher menu">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
  </button>
  @unless ($externo)
  <button class="aba at" aria-current="page" data-destino="painel" title="Painel" onclick="irPara('painel')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
      <rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/>
      <rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
    <span class="aba-txt">Painel</span>
  </button>
  @endunless
  {{-- Busca antes do Mapa de propósito: a camada de satélite é paga por
       requisição, e conferir a situação de um lote — que é a maior parte das
       consultas — não precisa de imagem aérea. O caminho mais barato vem
       primeiro. --}}
  <button class="aba" data-destino="busca" title="Consulta" onclick="irPara('busca')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
    <span class="aba-txt">Consulta</span>
  </button>
  <button class="aba" data-destino="mapa" title="Mapa" onclick="irPara('mapa')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
      <path d="M9 20l-6 3V6l6-3 6 3 6-3v17l-6 3z"/><path d="M9 3v17M15 6v17"/></svg>
    <span class="aba-txt">Mapa</span>
  </button>
  {{-- Painel, Documentos e Protocolo são da fiscalização. O externo
       (User::EXTERNOS) não os vê — e o servidor recusa as rotas deles de
       qualquer jeito (middleware `interno`). --}}
  @unless ($externo)
  <button class="aba" data-destino="documentos" title="Documentos" onclick="irPara('documentos')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
      <path d="M14 2v6h6"/><path d="M9 9h1M9 13h6M9 17h6"/></svg>
    <span class="aba-txt">Documentos</span>
  </button>
  <button class="aba" data-destino="protocolos" title="Protocolo e OS" onclick="irPara('protocolos')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
      <path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/>
      <rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 13 3 3 5-6"/></svg>
    <span class="aba-txt">Protocolo &amp; OS</span>
  </button>
  @endunless
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
    {{-- CABEÇALHO — a linha que responde "que imóvel é este e como ele está".
         A situação vem ANTES da integração porque é o estado do imóvel; a data
         da integração diz de quando é o dado lido do cadastro, e por isso fica
         por último, encostada no ✕. --}}
    <h3 class="fi-cabeca">
      <span class="cab-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>
        </svg>
      </span>
      <span id="fi-titulo">Ficha Imóvel</span>
      <span class="badge bd-ok" id="fi-situacao">Ativo</span>
      <span class="fi-integracao-topo">Últ. Integração:
        <b id="fi-integracao">—</b></span>
    </h3>
    <div class="sub" id="fi-linha-dist" style="display:none">
      <span class="badge bd-ok"><span id="fi-dist"></span></span>
    </div>

    {{-- IDENTIFICAÇÃO FIXA, acima das abas.
         Endereço, inscrição, coordenada e área não pertencem a nenhuma aba: são
         a resposta a "de qual imóvel estamos falando", e essa pergunta continua
         valendo enquanto se navega pelo histórico ou pelo BCI. Aqui, ela não
         some quando a aba muda. --}}
    <div class="fi-fixo">
      <div class="fi-endereco" id="fi-endereco">—</div>
      <div class="fi-fixo-dados">
        <span><span class="fi-rot">Insc. Imob.</span><span class="mono" id="fi-inscricao">—</span></span>
        <span><span class="fi-rot">Coord.</span><span class="mono" id="fi-coord">—</span></span>
        <span><span class="fi-rot">Área GIS</span><span id="fi-area">—</span></span>
      </div>
    </div>

    <div class="sub-abas">
      <button class="at" data-fi="dados" onclick="subFicha('dados')">Dados</button>
      <button data-fi="historico" onclick="subFicha('historico')">Histórico</button>
      <button data-fi="cadastro" onclick="subFicha('cadastro')">BCI</button>
      {{-- Croquis, anexos e fotos saem das vistorias: são conteúdo da
           fiscalização, e o externo não os vê. --}}
      @unless ($externo)
      <button data-fi="croquis" onclick="subFicha('croquis')">Croquis</button>
      <button data-fi="anexos" onclick="subFicha('anexos')">Anexos</button>
      @endunless
    </div>

    {{-- DADOS --}}
    <div class="fi-painel at" id="fi-dados">
      {{-- Sinalizações pendentes do lote (sinalizacoes.js). Âmbar: é aviso. --}}
      <div id="fi-sinal-aviso" hidden></div>
      {{-- O que muda o que o fiscal faz HOJE: em que pé está o imóvel, quantas
           vistorias já teve e quando foi a última. Tudo derivado do que está
           registrado — ver resumoDoImovel() em VistoriaController. --}}
      <div class="fi-linhas">
        <div class="fi-linha">
          <div class="fi-campo"><span class="fi-rot">Status</span>
            <span class="fi-val" id="fi-status">—</span></div>
          <div class="fi-campo"><span class="fi-rot">Vistorias</span>
            <span class="fi-val" id="fi-qt-vistorias">—</span></div>
          <div class="fi-campo"><span class="fi-rot">Última vistoria</span>
            <span class="fi-val" id="fi-ultima-vistoria">—</span></div>
        </div>
      </div>

      {{-- Fachada e croqui lado a lado, ocupando o que sobra da altura: são as
           duas imagens que respondem "como é o imóvel" antes de ir a campo, e
           imagem espremida em 90px não responde nada. A data de cada uma vai no
           rótulo — foto de dois anos atrás e foto de ontem valem coisas
           diferentes numa fiscalização. --}}
      <div class="fi-midias" @if ($externo) style="display:none" @endif>
        <figure class="fi-midia" id="fi-fachada">
          <figcaption>Fachada mais recente
            <span class="fi-midia-data" id="fi-fachada-data"></span></figcaption>
          <div class="fi-vazio">Sem foto de fachada registrada</div>
        </figure>
        <figure class="fi-midia" id="fi-croqui-atual">
          <figcaption>Croqui mais recente
            <span class="fi-midia-data" id="fi-croqui-data"></span></figcaption>
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

    {{-- CADASTRO IMOBILIÁRIO — a cópia local do BCI da prefeitura.
         O conteúdo é montado em cadastro-imobiliario.js quando a aba é aberta,
         e não junto da ficha: o mapa carrega até 3.000 lotes de uma vez, e
         enriquecer todos seria pagar por um dado que quase ninguém vai olhar. --}}
    <div class="fi-painel" id="fi-cadastro">
      <div id="fi-bci"><div class="vazio-msg">Carregando cadastro…</div></div>
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
      {{-- Sinalizar: o aviso rápido sobre o imóvel (sinalizacoes.js). Para
           todos, inclusive quem é de fora. --}}
      <button class="btn" style="margin-left:auto" onclick="sinalizarDaFicha()">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg>
        Sinalizar
      </button>
      @if (auth()->user()->canEdit())
        {{-- As mesmas peças do botão da tela de Documentos, e não só vistoria:
             estando na ficha, o fiscal já sabe sobre qual imóvel vai lavrar —
             obrigá-lo a sair daqui para abrir uma notificação era um desvio sem
             motivo. --}}
        {{-- Com os três pontos, como no rodapé do formulário de documento: é o
             MESMO gesto (abrir um menu de ações), e sem o ícone ele se lia
             como um botão comum que executa alguma coisa direto. --}}
        <button class="btn opcoes" onclick="novoDocumento(event)">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
          Opções
        </button>
      @elseif (! $externo)
        {{-- Visualizador não registra: esconder o botão evita a ida ao
             servidor só para receber 403. A regra real está no controller.
             Ao externo nem se mostra: lavrar não é assunto dele. --}}
        <button class="btn opcoes" disabled title="Seu perfil permite apenas consulta">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
          Opções
        </button>
      @endif
    </div>
  </div>
</div>

{{-- NOVA VISTORIA (Etapa 5) --}}
{{-- ══════════════════════════════════════════════
     VISTORIA DE OBRA (#m-vistoria) — TRÊS PASSOS

     Um assunto por vez, e não uma coluna longa. O fiscal usa esta tela de pé,
     no sol, num celular: rolagem infinita ali é o que faz alguém desistir de
     registrar e "anotar depois" — que na prática é não registrar.

     Os campos da obra (alvará, área, fase...) vivem dentro da Identificação,
     e não num segundo passo à parte: são a maioria das vistorias, e separá-los
     custava uma troca de aba para o caso mais comum.

     O ATALHO existe pelo mesmo motivo. A ronda de rotina é a maioria absoluta
     das vistorias, e obrigá-la a atravessar todos os passos custaria mais do
     que a informação que eles coletam.
     ══════════════════════════════════════════════ --}}
<div class="modal-bg" id="m-vistoria" onclick="fModal()" data-caixa-alta>
  <div class="modal modal-flex" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fecharVistoria()">&#10005;</button>

    <div class="vs-head">
      <h3 class="fi-cabeca">
        <span class="cab-ico">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/>
          </svg>
        </span>
        <span>Vistoria</span>
      </h3>
      <div class="sub" id="nv-lote">—</div>
      {{-- Linha própria, e não um selo ao lado do título: o texto varia de
           tamanho ("refaça as fotos") e, na largura de um celular, ia parar
           debaixo do botão de fechar. --}}
      <div class="vs-aviso" id="nv-rascunho" hidden>Rascunho recuperado</div>

      {{-- Barra de passos: mostra onde se está e o que falta. Clicável para
           voltar, porque conferir o que já foi preenchido é gesto legítimo. --}}
      {{-- Barra de passos montada pelo JavaScript: são sempre três
           (Identificação, Relatório, Revisão) — o que muda com a FINALIDADE
           é quais campos de obra aparecem dentro da Identificação, ver
           Vistoria::FINALIDADES, que é a fonte dessa regra dos dois lados. --}}
      <div class="vs-passos" id="nv-passos"></div>
    </div>

    <div class="vs-corpo">

    {{-- ── 1 · IDENTIFICAÇÃO ── --}}
    <div class="vs-painel at" id="nv-p-id" data-passo="id">
      {{-- O IMÓVEL PODE SER ESCOLHIDO AQUI, e não só no mapa. "Novo documento"
           oferece cinco peças; quatro abriam sem imóvel e a vistoria era a
           única que recusava com "selecione um lote no mapa" — obrigando a
           fechar o menu, achar o lote no mapa e recomeçar. O localizador é o
           MESMO da aba Imóvel do formulário de documento (rota
           /api/imoveis/busca), e some assim que o imóvel é conhecido. --}}
      <div id="nv-imovel-escolha" hidden>
        <div class="vs-aviso">Vistoria é de um imóvel: escolha qual, ou selecione um lote no mapa.</div>
        <div class="vsi-busca">
          <div class="field campo-add" style="flex:1;margin:0">
            <div class="campo-add-corpo">
              <label for="nv-imovel-termo">Imóvel</label>
              <input type="text" id="nv-imovel-termo" autocomplete="off"
                     placeholder="Inscrição imobiliária ou “quadra lote”"
                     onkeydown="if(event.key==='Enter'){event.preventDefault();procurarImovelVistoria()}">
            </div>
            <button type="button" class="btn out-verde sm"
                    onclick="procurarImovelVistoria()">Localizar</button>
          </div>
        </div>
        <div id="nv-imovel-resultado" class="vsi-nota"></div>
      </div>

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

      {{-- A finalidade vem ANTES de tudo: ela decide o que os campos de obra,
           logo abaixo, perguntam. Escolhê-la depois obrigaria a refazer o que
           já tivesse sido preenchido. Combobox, e não cartões: as cinco
           opções com sua descrição ocupavam a tela toda antes de chegar ao
           resto da identificação — e a esmagadora maioria das vistorias é
           "Fiscalização de obras", que por isso já vem selecionada. --}}
      <div class="field" style="margin-top:9px">
        <label for="nv-finalidade">Para que é esta vistoria</label>
        <select data-combo id="nv-finalidade" onchange="escolherFinalidade(this.value)">
          @foreach (\App\Models\Vistoria::FINALIDADES as $valor => $f)
            <option value="{{ $valor }}" data-obs="{{ $f['obs'] }}" @selected($valor === 'obras')>{{ $f['rotulo'] }}</option>
          @endforeach
        </select>
      </div>
      <div class="cad-nota" id="nv-finalidade-obs" style="margin:-4px 0 2px"></div>

      {{-- OS CAMPOS DA OBRA, que antes viviam num segundo passo só deles.
           Juntá-los à identificação poupa uma troca de aba para o caso comum
           (fiscalização de obra), que é exatamente quem mais os usa. Cada
           bloco aparece ou não conforme a finalidade escolhida acima
           (data-bloco) — ver Vistoria::FINALIDADES no servidor, fonte única
           dessa regra. Um auto de constatação não mostra nenhum. --}}
      <div data-bloco="alvara">
      <div class="field" style="margin-top:9px">
        <label for="nv-alvara">Alvará</label>
        {{-- "Não verificado" é estado legítimo, distinto de "não possui": o
             fiscal pode não ter conseguido conferir. --}}
        <select data-combo id="nv-alvara" onchange="escolherAlvara(this.value)">
          <option value="">—</option>
          @foreach (\App\Models\Vistoria::ALVARA as $valor => $rotulo)
            <option value="{{ $valor }}">{{ $rotulo }}</option>
          @endforeach
        </select>
      </div>
      <div class="g2">
        <div class="field" id="nv-alvara-num-campo" hidden style="margin:0">
          <label for="nv-alvara-numero">Número do alvará</label>
          <input type="text" id="nv-alvara-numero" class="mono" maxlength="40">
        </div>
        <div class="field" id="nv-alvara-vencimento-campo" hidden style="margin:0">
          <label for="nv-alvara-vencimento">Vencimento do alvará</label>
          <label class="date-ov">
            <input type="date" id="nv-alvara-vencimento" onchange="atualizarDisplayData(this)">
            <span class="date-ov-txt vazio">dd/mm/aaaa</span>
          </label>
        </div>
      </div>
      </div>{{-- /alvara --}}

      <div data-bloco="area">
      <div class="sec-title">Área construída aferida</div>
      {{-- O método vai IMPRESSO junto do número. Perito que contesta multa por
           metro quadrado contesta a medição, e "estimativa visual" precisa
           aparecer como o que é — ver Vistoria::METODOS_AREA. --}}
      <div class="g2">
        <div class="field" style="margin:0">
          <label for="nv-area">Área (m²)</label>
          <input type="number" id="nv-area" class="mono" inputmode="decimal"
                 min="0" max="999999" step="0.01" placeholder="88,02">
          {{-- O QUE ESTÁ DESENHADO, ao lado do que foi medido.
               Não preenche o campo sozinho: o número que vai para a multa é o
               que o fiscal aferiu com trena, e um valor que aparece pronto é
               um valor que ninguém confere. Aqui ele é oferecido, e quem
               decide usá-lo assume isso com um toque. --}}
          <div class="cad-dica" id="nv-area-desenhada" hidden></div>
        </div>
        <div class="field" style="margin:0">
          <label for="nv-area-metodo">Como foi obtida</label>
          <select data-combo id="nv-area-metodo">
            <option value="">—</option>
            @foreach (\App\Models\Vistoria::METODOS_AREA as $valor => $rotulo)
              <option value="{{ $valor }}">{{ $rotulo }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="cad-nota" style="margin-top:8px">É esta área que calcula a multa
        por metro quadrado no auto de infração.</div>
      </div>{{-- /area --}}

      <div data-bloco="fase">
      <div class="field" style="margin-top:9px">
        <label for="nv-fase">Fase da obra</label>
        <select data-combo id="nv-fase" onchange="escolherFase(this.value)">
          <option value="">—</option>
          @foreach (\App\Models\Vistoria::FASES_OBRA as $valor => $rotulo)
            <option value="{{ $valor }}">{{ $rotulo }}</option>
          @endforeach
        </select>
      </div>
      </div>{{-- /fase --}}

      {{-- Habite-se e regularização: o construído bate com o aprovado? --}}
      <div data-bloco="projeto">
      <div class="sec-title">Conformidade com o projeto</div>
      <div class="vs-opcoes" id="nv-projeto">
        @foreach (\App\Models\Vistoria::CONFORMIDADES as $valor => $rotulo)
          <button type="button" class="vs-op" data-valor="{{ $valor }}"
                  onclick="escolherProjeto('{{ $valor }}')">{{ $rotulo }}</button>
        @endforeach
      </div>
      </div>{{-- /projeto --}}

      {{-- O uso REAL, que a atualização cadastral vai a campo conferir e que
           costuma divergir do declarado no cadastro. --}}
      <div data-bloco="uso">
      <div class="sec-title">Uso constatado</div>
      <div class="vs-opcoes" id="nv-uso">
        @foreach (\App\Models\Vistoria::USOS as $valor => $rotulo)
          <button type="button" class="vs-op" data-valor="{{ $valor }}"
                  onclick="escolherUso('{{ $valor }}')">{{ $rotulo }}</button>
        @endforeach
      </div>
      </div>{{-- /uso --}}

      <div data-bloco="ano">
      <div class="sec-title">Época da construção</div>
      <div class="field">
        <label for="nv-ano">Ano aproximado</label>
        {{-- Ano, e não data: ninguém sabe o dia, e um campo de data pediria
             uma precisão que não existe. --}}
        <input type="number" id="nv-ano" class="mono" inputmode="numeric"
               min="1900" max="{{ date('Y') + 1 }}" placeholder="{{ date('Y') - 10 }}">
      </div>
      </div>{{-- /ano --}}

      {{-- Situação e coordenada NA MESMA LINHA: as duas são respostas curtas
           sobre o estado da vistoria, e lado a lado cabem sem disputar
           espaço com os campos de obra acima, que são mais longos. --}}
      <div class="g2">
        <div class="field" style="margin:0">
          <label for="nv-situacao">Situação constatada</label>
          <select data-combo id="nv-situacao">
            @foreach (\App\Models\Vistoria::SITUACOES as $valor => $rotulo)
              <option value="{{ $valor }}">{{ $rotulo }}</option>
            @endforeach
          </select>
        </div>
        {{-- A posição é capturada AQUI, e não só aproveitada do mapa: a
             vistoria acontece em frente ao imóvel, e é essa coordenada que
             vale como prova de que o fiscal esteve lá. --}}
        <div class="vs-gps">
          <div>
            <div class="fi-rot">Coordenada da vistoria</div>
            <div class="fi-val mono" id="nv-gps">não capturada</div>
          </div>
          <button type="button" class="btn sm out-green" id="nv-gps-btn"
                  onclick="capturarGpsVistoria()">Capturar</button>
        </div>
      </div>

      <div class="sec-title">Quem acompanhou</div>
      <div class="g2">
        <div class="field" style="margin:0">
          <label for="nv-acomp-nome">Nome</label>
          <input type="text" id="nv-acomp-nome" maxlength="160" placeholder="Quem recebeu o fiscal">
        </div>
        <div class="field" style="margin:0">
          <label for="nv-acomp-qual">Qualificação</label>
          <select data-combo id="nv-acomp-qual">
            <option value="">—</option>
            @foreach (\App\Models\Vistoria::QUALIFICACOES as $valor => $rotulo)
              <option value="{{ $valor }}">{{ $rotulo }}</option>
            @endforeach
          </select>
        </div>
      </div>

      {{-- Só aparece quando o imóvel tem protocolo de desmembramento ou
           unificação deferido e ainda sem vistoria. É o vínculo que, mais
           tarde, libera o ato cadastral. --}}
      <div id="nv-protocolo-caixa" hidden>
        <div class="sec-title">Processo atendido</div>
        <div class="field">
          <label for="nv-protocolo">Esta vistoria atende ao protocolo</label>
          <select data-combo id="nv-protocolo"><option value="">— nenhum —</option></select>
        </div>
      </div>

      <button type="button" class="btn sm vs-atalho" onclick="vistoriaRapida()">
        Vistoria rápida — só situação e foto</button>
    </div>

    {{-- ── 2 · RELATÓRIO ──
         Uma lista só, montada na ordem em que o fiscal escreve.

         Antes eram dois passos, "Constatações" e "Fotos". O problema não era
         de arrumação: a maioria das vistorias NÃO constata irregularidade
         nenhuma, e uma tela chamada Constatações, com um checklist de
         irregularidades à frente das fotos, fazia o registro do trabalho
         regular parecer desvio do caminho — quando é o caso comum.

         Aqui há um botão só, "Adicionar ao relatório", e ele oferece os quatro
         tipos de linha que uma vistoria produz. A ordem é conteúdo: a foto
         depois do artigo que ela ilustra diz o que a mesma foto no fim de uma
         pilha de fotos não diz. --}}
    <div class="vs-painel" id="nv-p-rel" data-passo="rel">
      <div class="sec-title-row">
        <div class="sec-title">Relatório da vistoria</div>
        <button type="button" class="btn primary sm sec-title-acao" onclick="novoItemRelatorio()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
          Adicionar item</button>
      </div>
      <div class="leg">Cada item é um ponto da obra, com o que for preciso dentro —
        na ordem em que você quiser contar.</div>
      <div id="nv-relatorio"></div>
      {{-- DOIS INPUTS, e não um: `capture="environment"` manda o celular abrir
           a CÂMERA direto, o que é certo para "Tirar foto" e errado para
           "Escolher arquivo" — com ele, não havia como pegar uma foto que já
           estava na galeria, nem um PDF de projeto. --}}
      <input type="file" id="nv-arquivo" accept="image/*" multiple
             capture="environment" style="display:none" onchange="anexarArquivos(this)">
      <input type="file" id="nv-arquivo-galeria" accept="image/*,application/pdf" multiple
             style="display:none" onchange="anexarArquivos(this)">

      {{-- O CHECKLIST SAIU DAQUI. Ele era uma lista única da vistoria, num
           bloco recolhido ao pé da tela; agora o artigo infringido pertence
           ao ITEM onde foi constatado, e é buscado dentro dele. --}}

      {{-- "OBSERVAÇÕES GERAIS" SAIU DAQUI. Era um segundo lugar para escrever
           a mesma coisa: tudo que se observa numa obra pertence a um ponto
           dela, e ponto da obra é ITEM. Um campo de sobra no fim da tela só
           dividia o relato em dois — parte nos itens, parte solta — e quem
           lesse depois teria de juntar. A COLUNA `observacoes` CONTINUA no
           banco e continua sendo exibida nas vistorias antigas que a usaram:
           o que sai é a porta de entrada, não o que já foi escrito. --}}
    </div>

    {{-- ── 3 · REVISÃO ── --}}
    <div class="vs-painel" id="nv-p-rev" data-passo="rev">
      <div class="leg">Confira antes de gravar. A vistoria é ato: depois de
        gravada, ela fundamenta notificação, auto e embargo.</div>
      <div id="nv-revisao"></div>
      {{-- Sinalizações do lote que esta vistoria atende, e o lembrete de
           voltar (sinalizacoes.js). --}}
      <div id="nv-sinal"></div>
    </div>

    </div>{{-- /vs-corpo --}}

    {{-- MESMAS SETAS DAS OUTRAS DUAS JANELAS de várias abas (item do relatório
         e formulário de documento). "Voltar" e "Avançar" escritos saíram: os
         quatro passos da vistoria se percorrem para frente e para trás o tempo
         todo, e duas palavras longas empurravam "Gravar" para fora da linha no
         celular.

         E "Gravar" fica VISÍVEL SEMPRE, não só no último passo. A vistoria é
         ato de campo: quem terminou o que tinha para registrar deve poder
         gravar de onde está, sem percorrer os passos restantes só para achar o
         botão. O que falta continua sendo cobrado por `gravarVistoria`, que
         leva ao passo do problema. --}}
    <div class="btn-row vs-rodape" id="nv-setas">
      <div class="foot-setas">
        <button class="btn sm" data-ir="primeira" title="Primeiro passo"
                onclick="irPassoPara('primeira')">&laquo;</button>
        <button class="btn" data-ir="anterior" title="Passo anterior"
                onclick="irPassoPara('anterior')">&lsaquo;</button>
        <button class="btn primary" data-ir="proxima" title="Próximo passo"
                onclick="irPassoPara('proxima')">&rsaquo;</button>
        <button class="btn sm" data-ir="ultima" title="Último passo"
                onclick="irPassoPara('ultima')">&raquo;</button>
      </div>
      <div style="flex:1"></div>
      {{-- O ÚNICO caminho que guarda rascunho. A tela não guarda mais nada por
           conta: gravar é decisão de quem escreve, e o botão fica ao lado de
           onde se sai, que é quando a decisão aparece. --}}
      <button class="btn" onclick="guardarRascunho()" title="Guarda o que está na tela neste aparelho, para continuar depois">Salvar rascunho</button>
      <button class="btn" onclick="fecharVistoria()">Cancelar</button>
      <button class="btn primary" id="nv-gravar" onclick="gravarVistoria()">Gravar vistoria</button>
    </div>
  </div>
</div>

{{-- ══════ ITEM DO RELATÓRIO DE VISTORIA ══════
     Uma janela pequena por item, e não campos soltos crescendo na lista: o
     que se escreve num item é texto de peça, e merece o espaço de um
     formulário. A lista fica legível porque cada linha é só o resumo. --}}
{{-- ══════ UM ITEM DO RELATÓRIO ══════
     Os QUATRO BLOCOS numa janela só, na mesma ordem em que sairão no papel:
     artigos, texto livre, exigências e fotos. É a ordem do raciocínio de uma
     peça — a infração, a narrativa, a providência e a prova —, e por isso ela
     é fixa: deixá-la à escolha faria cada relatório
     sair diferente, e quem lê vinte por semana perde o hábito de leitura.

     Editar em quatro telas separadas quebraria justamente o que o item existe
     para juntar. --}}
<div class="modal-bg" id="m-vs-item" onclick="fModal()" data-caixa-alta>
  <div class="modal modal-flex" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fecharItemRelatorio()">&#10005;</button>

    <div class="doc-head">
      <div class="doc-head-top">
        <span class="cab-ico">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
        </span>
        <span class="doc-head-doc" id="vsi-titulo">Item do relatório</span>
      </div>
    </div>

    {{-- OS QUATRO BLOCOS VIRAM QUATRO BOTÕES.
         Antes eles vinham empilhados numa janela só: abrir um item despejava
         um catálogo inteiro, mais um formulário de artigo
         com três campos, mais um de exigência com dois, mais as fotos — tudo
         de uma vez, para preencher talvez um deles. A janela dizia o que ela
         PODE ter, quando o que o fiscal precisa ver é o que ela TEM.
         Cada botão traz a contagem do que já foi posto ali dentro. --}}
    {{-- Mesmo padrão de aba do resto do sistema (Parâmetros, o formulário de
         documento): trilho cinza, aba ativa em pílula branca com texto verde. --}}
    <div class="sub-abas" id="vsi-abas">
      <button type="button" data-bloco="artigos" onclick="abaDoItem('artigos')">
        Artigos <span class="vsi-conta" id="vsi-n-artigos"></span></button>
      <button type="button" data-bloco="texto" onclick="abaDoItem('texto')">
        O que você viu <span class="vsi-conta" id="vsi-n-texto"></span></button>
      <button type="button" data-bloco="exigencias" onclick="abaDoItem('exigencias')">
        Exigências <span class="vsi-conta" id="vsi-n-exigencias"></span></button>
      <button type="button" data-bloco="fotos" onclick="abaDoItem('fotos')">
        Fotos <span class="vsi-conta" id="vsi-n-fotos"></span></button>
    </div>


    {{-- O CORPO TEM DUAS PARTES, sempre nesta ordem:

         (1) O QUE ADICIONAR — muda com a aba, e é só isso: um combo, um
             texto, um formulário curto. Nunca uma lista do que já foi posto.

         (2) O QUE JÁ ESTÁ NO ITEM — `#vsi-resumo`, FORA dos blocos de aba, o
             mesmo em qualquer uma delas. É aqui que se vê (e se remove) o que
             já foi adicionado, sem precisar visitar cada aba para conferir.
             Altura travada e com rolagem própria: ele não pode crescer e
             empurrar o "adicionar" para fora da vista — é coadjuvante, não
             tela principal. --}}
    <div class="doc-body">

      {{-- 1 — ARTIGOS. A irregularidade é só o NOME POPULAR do problema, e
           por isso não tem aba própria: é termo de busca do artigo. O fiscal
           digita "escavação" e o combo mostra os artigos que tratam disso, de
           qualquer lei — cada um com a lei e o termo que casou. Por isso o
           seletor de lei saiu: a busca já diz de que lei é cada artigo. --}}
      <div class="vsi-bloco" data-bloco="artigos">
        <div class="vsi-linha-lei">
          <div class="ac-wrap" style="flex:1;min-width:0">
            <div class="field campo-add" style="margin:0">
              <div class="campo-add-corpo">
                <label for="vsi-artigo-busca">Problema ou artigo</label>
                {{-- Combobox padrão: o × limpa o que foi digitado. --}}
                <div class="ac-wrap">
                  <input type="text" id="vsi-artigo-busca" autocomplete="off"
                         placeholder="Ex.: escavação, calçada, art. 12…"
                         oninput="buscarArtigo(this.value)"
                         onfocus="buscarArtigo(this.value)"
                         onkeydown="if(event.key==='Enter'){event.preventDefault();adicionarArtigoAoItem()}">
                  <button class="clr-btn" type="button" tabindex="-1" title="Limpar"
                          onclick="const c = document.getElementById('vsi-artigo-busca'); c.value = ''; buscarArtigo(''); c.focus()">&times;</button>
                </div>
              </div>
              <button type="button" class="btn out-verde sm"
                      onclick="adicionarArtigoAoItem()">+add</button>
            </div>
            <div class="ac-list" id="vsi-artigo-sugestoes"></div>
          </div>
          <div class="field vsi-campo-curto" style="margin:0">
            <label for="vsi-artigo-tipo">Como entra</label>
            {{-- Citação vira FATO na peça; parecer vira FUNDAMENTAÇÃO. --}}
            <select data-combo id="vsi-artigo-tipo">
              <option value="citacao">Citação</option>
              <option value="parecer">Parecer</option>
            </select>
          </div>
        </div>
        <div class="vsi-nota" id="vsi-artigo-nota">Digite o problema que você viu ("escavação", "sem alvará") ou o número do artigo.</div>
      </div>

      {{-- 2 — O QUE VOCÊ VIU: também uma LISTA, e não um campo só.
           Um item da obra costuma render mais de uma constatação, e escrever
           tudo num bloco corrido obrigava a reescrever o parágrafo inteiro
           para tirar uma frase. Cada relato entra pelo "+add" e sai sozinho
           do resumo, como o artigo. --}}
      <div class="vsi-bloco" data-bloco="texto" hidden>
        <div class="field campo-add campo-add-alto" style="margin:0">
          <div class="campo-add-corpo">
            <label for="vsi-texto">O que você viu</label>
            <textarea id="vsi-texto" rows="3" maxlength="5000"
                      placeholder="Com as suas palavras — é este texto que vira o FATO na peça."
                      onkeydown="if(event.key==='Enter'&&(event.ctrlKey||event.metaKey)){event.preventDefault();adicionarRelatoAoItem()}"></textarea>
          </div>
          <button type="button" class="btn out-verde sm" onclick="adicionarRelatoAoItem()">+add</button>
        </div>
        <div class="vsi-nota">Um parágrafo por constatação. Ctrl+Enter também adiciona.</div>
      </div>

      {{-- 3 — EXIGÊNCIAS.
           Os dois campos e o "+add" NUMA LINHA SÓ. O botão cinza embaixo, com
           a largura do próprio texto, sobrava no canto esquerdo sem se ligar a
           nada — e era o único "+ add" do sistema fora do padrão dos outros
           três. O prazo é curto por natureza (três dígitos bastam) e cede a
           largura à providência, que é uma frase. --}}
      <div class="vsi-bloco" data-bloco="exigencias" hidden>
        <div class="vsi-linha-lei">
          <div class="field" style="flex:1;min-width:0;margin:0">
            <label for="vsi-exig-texto">Providência exigida</label>
            <input type="text" id="vsi-exig-texto" maxlength="500"
                   placeholder="O que o responsável tem de fazer"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();adicionarExigenciaAoItem()}">
          </div>
          <div class="field campo-add vsi-campo-curto" style="margin:0">
            <div class="campo-add-corpo">
              <label for="vsi-exig-prazo">Prazo (dias)</label>
              <input type="number" id="vsi-exig-prazo" class="mono" min="1" max="3650"
                     onkeydown="if(event.key==='Enter'){event.preventDefault();adicionarExigenciaAoItem()}">
            </div>
            <button type="button" class="btn out-verde sm"
                    onclick="adicionarExigenciaAoItem()">+add</button>
          </div>
        </div>
        <div class="vsi-nota">O prazo é opcional — sem ele, a exigência entra sem contagem.</div>
      </div>

      {{-- 4 — FOTOS. A ABA SÓ ADICIONA, como as outras três: a lista do que
           já foi anexado é a MESMA do resumo, e por isso aparece igual em
           qualquer aba. Escolher o arquivo não anexa nada ainda — abre a
           ficha da foto (legenda, fachada) e o "+ add" é que a põe no item.
           Antes o arquivo entrava na lista no instante em que era escolhido,
           sem chance de dizer o que ele mostra. --}}
      <div class="vsi-bloco" data-bloco="fotos" hidden>
        {{-- Câmera e Galeria, com o ícone à frente — o mesmo par do
             AppPOSTURAS. Em cinza, e não no verde de lá: os dois caminhos
             valem o mesmo, e a cor de ação principal em ambos não escolheria
             nada. O ícone é o que se lê primeiro na mão, ao sol. --}}
        <div class="vsi-foto-botoes">
          <button type="button" class="btn out-cinza sm" onclick="tirarFotoDaCamera()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
              <circle cx="12" cy="13" r="4"/></svg>
            Câmera</button>
          <button type="button" class="btn out-cinza sm" onclick="escolherFotoDaGaleria()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
              <circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            Galeria</button>
        </div>

        {{-- A FICHA DA FOTO PENDENTE. Fica escondida até haver uma escolhida. --}}
        <div id="vsi-foto-nova" hidden>
          <div class="vsi-foto-nova-tit" id="vsi-foto-nova-titulo"></div>
          <div class="vsi-palco" id="vsi-foto-nova-palco" onclick="marcarNaFotoPendente(event)">
            <img id="vsi-foto-nova-img" alt="">
            <div class="vsi-pinos" id="vsi-foto-nova-pinos"></div>
          </div>
          <div class="vsi-nota" id="vsi-foto-nova-meta"></div>
          <div class="field" style="margin:8px 0 0">
            <label for="vsi-foto-nova-legenda">Legenda</label>
            <textarea id="vsi-foto-nova-legenda" rows="2" maxlength="1000"
                      placeholder="O que esta foto mostra"></textarea>
          </div>
          <div class="vsi-foto-acoes">
            <label class="chk-item chk-linha">
              <input type="checkbox" id="vsi-foto-nova-fachada">
              <span class="desc">É a fachada do imóvel</span>
            </label>
            <button type="button" class="btn sm" onclick="limparMarcacoesPendente()">Limpar marcas</button>
            <div style="flex:1"></div>
            <button type="button" class="btn sm" onclick="descartarFotoPendente()">Cancelar</button>
            <button type="button" class="btn sm primary" id="vsi-foto-nova-add"
                    onclick="adicionarFotoAoItem()">+ add</button>
          </div>
        </div>

        <div class="vsi-nota" id="vsi-foto-dica">Toque na foto para numerar o que a legenda descreve.</div>
      </div>

      <div class="vsi-resumo" id="vsi-resumo"></div>
    </div>

    {{-- RODAPÉ: navegação entre abas à esquerda, ações à direita — a mesma
         divisão do formulário de documento, que é onde este padrão nasceu.
         As setas ficam aqui, e não sob as abas: é o rodapé que a mão já
         procura para gravar, e as duas coisas que se faz ao terminar uma aba
         (ir para a próxima, ou fechar) passam a ficar no mesmo lugar. --}}
    <div class="doc-foot" id="vsi-setas">
      <div class="foot-setas">
        <button class="btn sm" data-ir="primeira" title="Primeira aba"
                onclick="irAbaItem('primeira')">&laquo;</button>
        <button class="btn" data-ir="anterior" title="Aba anterior"
                onclick="irAbaItem('anterior')">&lsaquo;</button>
        <button class="btn primary" data-ir="proxima" title="Próxima aba"
                onclick="irAbaItem('proxima')">&rsaquo;</button>
        <button class="btn sm" data-ir="ultima" title="Última aba"
                onclick="irAbaItem('ultima')">&raquo;</button>
      </div>
      <div style="flex:1"></div>
      {{-- "Excluir item" saiu daqui. Item recém-criado que ainda não foi
           Guardado não tem o que excluir — ele só existe se você cancelar
           (e some sozinho, vazio). Item já na lista se exclui DE LÁ, com o
           mesmo cuidado de qualquer exclusão do sistema. --}}
      <button class="btn" onclick="fecharItemRelatorio()">Cancelar</button>
      <button class="btn primary" onclick="salvarItemRelatorio()">Guardar</button>
    </div>
  </div>
</div>

{{-- ══════ VISUALIZADOR DE FOTO (#m-foto-view) ══════
     O olho da lista abre AQUI, e o lápis abre a edição — antes os dois
     faziam a mesma coisa. Mesmo visualizador do AppPOSTURAS
     (`#m-anexo-view`): a foto grande, as setas andando pelas fotos do
     MESMO item (dá a volta nas pontas, como o visualizador do Windows) e o
     contador "N de M". Aqui ele mostra também os pinos numerados e a
     legenda — ver a marca "2" sem saber o que ela aponta é meio caminho. --}}
<div class="modal-bg" id="m-foto-view" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fecharVisualizadorDeFoto()">&#10005;</button>
    <h3 id="foto-view-titulo">Foto</h3>
    <div class="foto-view-box">
      <button type="button" class="foto-view-nav foto-view-prev" id="foto-view-prev"
              title="Anterior" onclick="navegarFoto(-1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <div class="foto-view-palco">
        <img id="foto-view-img" alt="">
        <div class="vsi-pinos" id="foto-view-pinos"></div>
      </div>
      <button type="button" class="foto-view-nav foto-view-next" id="foto-view-next"
              title="Próxima" onclick="navegarFoto(1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
    </div>
    <div class="foto-view-legenda" id="foto-view-legenda"></div>
    <div class="vsi-nota" id="foto-view-meta"></div>
    <div class="btn-row">
      <div id="foto-view-contador" class="foto-view-contador"></div>
      <button class="btn" onclick="fecharVisualizadorDeFoto()">Fechar</button>
    </div>
  </div>
</div>

{{-- NOVO DOCUMENTO (Etapa 6) --}}
{{-- ══════════════════════════════════════════════
     FORMULÁRIO DE DOCUMENTO (#m-doc)

     Estrutura do formulário de Notificação do AppPOSTURAS: cabeçalho e rodapé
     FIXOS, corpo rolável no meio, altura travada (a caixa não muda de tamanho
     ao trocar de aba). Abas em sequência — Autuado → Imóvel/Origem → Infração
     → Anexos → Resumo — e um rodapé que muda conforme o estado do documento
     (novo → rascunho gravado → lavrado).

     Regra de edição, também herdada do POSTURAS: o que já está GRAVADO só
     volta a ser editável clicando em "Editar". Formulário gravado que continua
     aberto para digitação convida à alteração acidental de peça de processo.
     Os campos travados carregam data-lock (ver travarCamposDoc).

     As quatro peças de obras: Vistoria, Notificação, Auto de Infração e Auto
     de Embargo. A vistoria usa o mesmo invólucro, sem a parte de sanção — ela
     ganha formulário próprio depois.
     ══════════════════════════════════════════════ --}}
<div class="modal-bg" id="m-doc" onclick="fModal()" data-caixa-alta>
<div class="modal modal-flex" onclick="event.stopPropagation()">
  <button class="modal-x" onclick="fecharFormDoc()">&#10005;</button>

  {{-- ── CABEÇALHO FIXO ── --}}
  <div class="doc-head">
    <div class="doc-head-top">
      {{-- O ícone diz que peça é ANTES de o nome ser lido — e muda com o tipo,
           em irAbaDoc/abrirFormDoc. Mesmo tratamento do cabeçalho da ficha do
           imóvel e do avatar do usuário. --}}
      <span class="cab-ico" id="fd-icone"></span>
      <span class="doc-head-doc" id="fd-tipo-rotulo">Documento</span>
      <span class="doc-head-num-wrap">
        <span class="doc-head-lbl">Nº</span>
        <span id="fd-numero" class="proto-badge doc-head-num">—</span>
      </span>
      <span id="fd-status" class="badge bd-in">Novo</span>
    </div>
    <div class="doc-head-meta">
      <div><span class="doc-head-lbl">Data registro:</span> <span id="fd-registro">—</span></div>
      <div><span class="doc-head-lbl">Agente</span> <span id="fd-agente">—</span></div>
      <div id="fd-prazo-wrap" hidden><span class="doc-head-lbl">Prazo</span> <span id="fd-prazo-badge" class="badge bd-al"></span></div>
    </div>

    <div class="doc-tabs" id="fd-tabs">
      {{-- Autuado e imóvel numa aba só: são as duas metades de "contra quem e
           sobre o quê", e em campo se preenche uma olhando a outra. --}}
      <button class="doc-tab ativa" data-aba="autuado"  onclick="irAbaDoc('autuado')">Autuado/Imóvel</button>
      <button class="doc-tab" data-aba="infracao" onclick="irAbaDoc('infracao')">Infração</button>
      <button class="doc-tab" data-aba="anexos"   onclick="irAbaDoc('anexos')">Anexos</button>
      <button class="doc-tab" data-aba="resumo"   onclick="irAbaDoc('resumo')">Resumo</button>
    </div>
    {{-- As setas de aba desta peça ficam NO RODAPÉ (#fd-primeira e as três
         seguintes), que é onde o sistema as põe desde sempre — junto das
         ações, na mão que já está lá para gravar. --}}
  </div>

  {{-- ── CORPO ROLÁVEL ── --}}
  <div class="doc-body" id="fd-body">

    {{-- AUTUADO / IMÓVEL --}}
    <div class="doc-painel ativa" id="fdp-autuado">
      <div class="sec-title">Dados do autuado</div>
      {{-- LINHA 1: o documento na largura de um CNPJ, e o nome com o resto.
           Saindo do campo com um CNPJ válido, o sistema consulta a empresa e
           preenche o que estiver vazio (buscarCnpjDoc) — como no AppPOSTURAS. --}}
      <div class="doc-lin doc-lin-autuado">
        <div class="field">
          <label for="nd-autuado-doc">CPF / CNPJ</label>
          <input type="text" id="nd-autuado-doc" class="mono" maxlength="18" data-lock inputmode="numeric"
                 placeholder="000.000.000-00" autocomplete="off"
                 oninput="mascararCpfCnpjDoc(this)" onblur="buscarCnpjDoc(this)">
        </div>
        <div class="field">
          <label for="nd-autuado">Nome / razão social</label>
          <input type="text" id="nd-autuado" maxlength="160" data-lock
                 placeholder="Como consta no cadastro">
        </div>
      </div>
      {{-- O DOMICÍLIO do autuado, e não o endereço da obra (que fica abaixo, no
           imóvel): é para onde a peça é entregue ou enviada. Vem sugerido do
           endereço de correspondência do cadastro municipal, ou do CNPJ. --}}
      <div class="doc-lin doc-lin-rua">
        <div class="field">
          <label for="nd-aut-logradouro">Logradouro</label>
          <input type="text" id="nd-aut-logradouro" maxlength="160" data-lock
                 placeholder="Onde ele recebe correspondência">
        </div>
        <div class="field">
          <label for="nd-aut-numero">Número</label>
          <input type="text" id="nd-aut-numero" class="mono" maxlength="20" data-lock>
        </div>
      </div>
      <div class="doc-lin doc-lin-cidade">
        <div class="field">
          <label for="nd-aut-bairro">Bairro</label>
          <input type="text" id="nd-aut-bairro" maxlength="120" data-lock>
        </div>
        <div class="field">
          <label for="nd-aut-cidade">Cidade</label>
          <input type="text" id="nd-aut-cidade" maxlength="120" data-lock>
        </div>
        <div class="field">
          <label for="nd-aut-uf">UF</label>
          <input type="text" id="nd-aut-uf" maxlength="2" data-lock autocomplete="off"
                 style="text-transform:uppercase" oninput="this.value = this.value.replace(/[^a-zA-Z]/g, '').toUpperCase()">
        </div>
      </div>
      {{-- CARIMBO DE PROCEDÊNCIA. Só em peça lavrada: no rascunho ainda não há
           carimbo, porque o dado ainda pode mudar. Discreto de propósito — é
           conferência, e quem abre a peça quase nunca está atrás dele. --}}
      <p class="doc-carimbo" id="nd-carimbo" hidden></p>

      <div class="sec-title">Imóvel</div>
      {{-- O localizador, quando a peça ainda não tem imóvel (renderImovelDoc). --}}
      <div id="nd-imovel-dados"></div>

      {{-- A IDENTIFICAÇÃO DO IMÓVEL, em campos. Quando o imóvel está no
           cadastro municipal, eles vêm de lá e ficam só para leitura; quando
           não está, ficam abertos para o fiscal informar à mão
           (renderBciDoc → travarImovelDoc). --}}
      {{-- LINHA 1: a inscrição na largura dela, o número para cinco dígitos, e
           o logradouro com o que sobra. A área do terreno saiu daqui: é base
           de cálculo, e mora na aba Infração, com a área construída. --}}
      <div class="doc-lin doc-lin-imovel">
        <div class="field">
          <label for="nd-im-inscricao">Inscrição imobiliária</label>
          <input type="text" id="nd-im-inscricao" class="mono" maxlength="30" data-lock placeholder="01.000.000.0000.000">
        </div>
        <div class="field">
          <label for="nd-im-logradouro">Logradouro</label>
          <input type="text" id="nd-im-logradouro" maxlength="160" data-lock>
        </div>
        <div class="field">
          <label for="nd-im-numero">Número</label>
          <input type="text" id="nd-im-numero" class="mono" maxlength="20" data-lock>
        </div>
      </div>
      <div class="doc-lin doc-lin-quadra">
        <div class="field">
          <label for="nd-im-bairro">Bairro</label>
          <input type="text" id="nd-im-bairro" maxlength="160" data-lock>
        </div>
        <div class="field">
          <label for="nd-im-quadra">Quadra</label>
          <input type="text" id="nd-im-quadra" class="mono" maxlength="20" data-lock>
        </div>
        <div class="field">
          <label for="nd-im-lote">Lote</label>
          <input type="text" id="nd-im-lote" class="mono" maxlength="20" data-lock>
        </div>
      </div>

      {{-- O RESTO DO CADASTRO MUNICIPAL (BCI) deste imóvel — só aparece quando
           o imóvel está no cadastro. Desenhado por renderBciDoc. --}}
      <div id="nd-bci-bloco" hidden>
        <div class="sec-title">Cadastro municipal (BCI)</div>
        <div id="nd-bci"></div>
      </div>

      {{-- DE ONDE VEIO A NOTIFICAÇÃO: direta (vistoria em campo), ordem de
           serviço ou denúncia da ouvidoria. Só nas notificações. SEM data-lock
           de propósito: é o único dado que continua editável depois da
           lavratura — quem trava e destrava é aplicarOrigemNotifDoc
           (documentos.js). --}}
      <div id="nd-bloco-motivo" style="display:none">
        <div class="sec-title">Origem</div>
        <div class="g2">
          <div class="field">
            <label for="nd-origem-motivo">O que originou esta notificação</label>
            <select data-combo id="nd-origem-motivo" onchange="aplicarOrigemNotifDoc()">
              <option value="direta">Direta — vistoria em campo</option>
              <option value="ordem_servico">Ordem de serviço</option>
              <option value="ouvidoria">Denúncia da ouvidoria</option>
            </select>
          </div>
          <div class="field" id="nd-origem-os-campo" hidden>
            <label for="nd-origem-os">Ordem de serviço</label>
            <select data-combo id="nd-origem-os" onfocus="carregarOrdensDoc()">
              <option value="">Escolha a ordem de serviço…</option>
            </select>
          </div>
          <div class="field" id="nd-origem-ref-campo" hidden>
            <label for="nd-origem-ref">Nº da denúncia na ouvidoria</label>
            <input type="text" id="nd-origem-ref" maxlength="80" placeholder="Ex.: 4471/2026">
          </div>
        </div>
        <div class="btn-row" id="nd-origem-salvar-linha" style="justify-content:flex-end;margin-top:6px" hidden>
          <span class="imp-sub" style="margin-right:auto">A origem pode ser corrigida mesmo com a peça lavrada.</span>
          <button type="button" class="btn out-verde sm" onclick="salvarOrigemNotifDoc()">Salvar origem</button>
        </div>
      </div>

      {{-- De qual peça o auto nasceu (a notificação ou o embargo anterior). É
           a que o texto de ciência cita pelo marcador {origem}. Só nos autos
           (carregarOrigensDoc, documentos.js). --}}
      <div id="nd-bloco-origem" style="display:none">
        <div class="sec-title">Origem</div>
        <div class="field">
          <label for="nd-origem">Documento que originou este</label>
          <select data-combo id="nd-origem" data-lock onfocus="carregarOrigensDoc()">
            <option value="">Direta — sem documento de origem</option>
          </select>
        </div>
      </div>
    </div>

    {{-- INFRAÇÃO --}}
    <div class="doc-painel" id="fdp-infracao">
      {{-- O TIPO não aparece: foi escolhido antes de o formulário abrir, e
           está no cabeçalho. O <select> continua existindo, escondido, porque
           o resto do formulário lê dele. --}}
      <select id="nd-tipo" hidden onchange="trocarTipoDoc()"></select>

      <div class="sec-title">Data do fato</div>
      {{-- Data e hora em TEXTO, com máscara (dd/mm/aaaa e hh:mm) — e, no botão
           ao lado, um calendário e um relógio PRÓPRIOS do sistema. Os seletores
           nativos do navegador mudavam de cara a cada aparelho; estes são
           iguais em todos (abrirCalendarioDoc / abrirRelogioDoc). Os valores
           que o sistema usa ficam nos campos escondidos (syncDataDoc). --}}
      <div class="g2">
        <div class="field dh-campo">
          <label for="nd-data-txt">Data</label>
          <input type="text" id="nd-data-txt" class="mono" inputmode="numeric" maxlength="10" data-lock
                 placeholder="dd/mm/aaaa" autocomplete="off" oninput="mascararDataDoc(this)" onblur="lerDataHoraDoc()">
          <button type="button" class="dh-btn" data-lock title="Escolher no calendário" aria-label="Escolher a data no calendário"
                  onclick="abrirCalendarioDoc(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
          </button>
        </div>
        <div class="field dh-campo">
          <label for="nd-hora-txt">Hora</label>
          <input type="text" id="nd-hora-txt" class="mono" inputmode="numeric" maxlength="5" data-lock
                 placeholder="hh:mm" autocomplete="off" oninput="mascararHoraDoc(this)" onblur="lerDataHoraDoc()">
          <button type="button" class="dh-btn" data-lock title="Escolher no relógio" aria-label="Escolher a hora no relógio"
                  onclick="abrirRelogioDoc(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          </button>
        </div>
      </div>
      <input type="hidden" id="nd-data">
      <input type="hidden" id="nd-hora">
      <input type="hidden" id="nd-datahora">

      <div id="bloco-fundamentacao">
        <div class="sec-title">Legislação infringida</div>
        <div id="nd-sugestao" style="margin-bottom:10px"></div>

        {{-- LEI E ARTIGO NO PADRÃO DO APPPOSTURAS: lei pesquisável, que trava
             enquanto houver artigo na lista; artigo pesquisável por número,
             apelido, texto ou termo, somado à lista pelo "+add"; e os artigos
             escolhidos em quadros cinzas, cada um com o seu X. --}}
        <input type="hidden" id="nd-lei">
        <div class="field">
          <label for="nd-lei-busca">Lei infringida *</label>
          <div class="ac-wrap">
            <input type="text" id="nd-lei-busca" placeholder="Digite para buscar a lei..." autocomplete="off" data-lock
                   oninput="buscarLeiDoc(this)" onfocus="buscarLeiDoc(this)" onblur="fecharAcDoc('ac-nd-lei')">
            <button class="clr-btn" type="button" onclick="limparLeiDoc()" tabindex="-1" title="Limpar a lei">&times;</button>
            <div class="ac-list" id="ac-nd-lei"></div>
          </div>
        </div>
        <div class="ac-dica" id="nd-lei-trava" hidden>Remova os artigos da lista para trocar a lei.</div>

        <div class="field">
          <label for="nd-artigo-busca">Artigo infringido</label>
          <div class="ac-linha">
            <div class="ac-wrap">
              <input type="text" id="nd-artigo-busca" placeholder="Digite para buscar o artigo..." autocomplete="off" data-lock
                     oninput="buscarArtigoDoc(this)" onfocus="buscarArtigoDoc(this)" onblur="fecharAcDoc('ac-nd-artigo')"
                     onkeydown="if(event.key==='Enter'){event.preventDefault();addArtigoDoc()}">
              <button class="clr-btn" type="button" onclick="limparArtigoBuscaDoc()" tabindex="-1" title="Limpar">&times;</button>
              <div class="ac-list" id="ac-nd-artigo"></div>
            </div>
            <button type="button" class="btn out-verde sm" onclick="addArtigoDoc()" data-lock>+add</button>
          </div>
        </div>
        <div id="nd-artigos"></div>

        {{-- Áreas: a base da multa em obras. Aparece só quando algum artigo
             escolhido cobra por metro quadrado. --}}
        <div id="nd-bloco-area" style="display:none">
          <div class="sec-title">Áreas para cálculo</div>
          {{-- As duas bases de multa lado a lado: há artigo que cobra pelo
               terreno e artigo que cobra pela construção. --}}
          <div class="g2">
            <div class="field">
              <label for="nd-area-terreno">Área do terreno (m²)</label>
              <input id="nd-area-terreno" type="number" min="0" step="0.01" data-lock oninput="recalcularMultaDoc()">
            </div>
            <div class="field">
              <label for="nd-area-construida">Área construída aferida (m²)</label>
              <input id="nd-area-construida" type="number" min="0" step="0.01" data-lock oninput="recalcularMultaDoc()">
            </div>
          </div>
        </div>
        {{-- Multa por múltiplo do valor do alvará (LC 001/2023, art. 120,
             parágrafo único): aparece só com artigo desse tipo na peça. --}}
        <div id="nd-bloco-alvara" style="display:none">
          <div class="sec-title">Alvará — base da multa</div>
          <div class="g2">
            <div class="field">
              <label for="nd-alvara-valor">Valor do alvará (R$)</label>
              <input id="nd-alvara-valor" type="number" min="0" step="0.01" data-lock oninput="recalcularMultaDoc()">
            </div>
          </div>
        </div>
        {{-- O que o FISCAL informa por artigo: o multiplicador do alvará com
             intervalo, e o valor da multa "entre mínimo e máximo" (art. 35,
             §5º), fixado conforme a gravidade. --}}
        <div id="nd-bloco-informados" style="display:none">
          <div class="sec-title">Multa a critério do fiscal</div>
          <div class="g2" id="nd-multiplicadores"></div>
        </div>
        {{-- Reincidência (art. 121-B, §2º): o auto é lavrado a partir de outro
             auto, e a multa dos artigos marcados dobra. Só em Auto de Infração. --}}
        <div id="nd-bloco-reincidencia" style="display:none">
          <div class="sec-title">Reincidência</div>
          <div class="field">
            <label for="nd-reincidencia">Reincidência do auto</label>
            <select data-combo id="nd-reincidencia" data-lock onfocus="carregarAutosAnterioresDoc()" onchange="recalcularMultaDoc()">
              <option value="">Não é reincidência</option>
            </select>
          </div>
        </div>
        {{-- A memória de cálculo vem do servidor (/api/multas/simular). --}}
        <div id="nd-memoria-calculo"></div>
      </div>

      <div id="bloco-prazo">
        <div class="sec-title">Prazo para cumprimento</div>
        <div class="field">
          <label for="nd-prazo">Prazo (dias corridos)</label>
          <input id="nd-prazo" type="number" min="0" max="365" value="10" data-lock>
        </div>
      </div>
      <div id="nd-aviso-prazo" class="aviso-legal" style="display:none"></div>

      <div class="sec-title">Constatação</div>
      <div class="field">
        <label for="nd-descricao">Descrição do fato</label>
        <textarea id="nd-descricao" rows="4" maxlength="5000" data-lock
                  style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"
                  placeholder="O que foi constatado e está sendo imputado"></textarea>
      </div>
    </div>

    {{-- ANEXOS --}}
    <div class="doc-painel" id="fdp-anexos">
      {{-- Os anexos PRÓPRIOS da peça: foto e PDF juntados aqui, e o que o
           fiscal escolhe trazer da vistoria ou da peça de origem. Desenhado
           por documento-anexos.js. --}}
      <div class="sec-title">Juntar ao documento</div>
      <div id="nd-anexos"></div>
      <input type="file" id="anx-camera" accept="image/*" capture="environment" hidden onchange="escolherFotoAnexo(this, true)">
      <input type="file" id="anx-galeria" accept="image/*" hidden onchange="escolherFotoAnexo(this, false)">
      <input type="file" id="anx-pdf" accept="application/pdf" hidden onchange="escolherPdfAnexo(this)">
    </div>

    {{-- RESUMO --}}
    <div class="doc-painel" id="fdp-resumo">
      <div class="doc-resumo" id="nd-resumo"></div>

      {{-- LAVRATURA, na própria tela do resumo (aparece ao tocar em Lavrar):
           o fiscal confere a peça, colhe a assinatura do autuado — ou
           registra a recusa, com testemunha — e confirma. Estado em
           documento-lavratura.js. --}}
      <div class="lav" id="nd-lavratura" hidden>
        <div class="sec-title">Lavratura</div>
        <div class="g2">
          <div class="field">
            <label>Data e hora da lavratura</label>
            <div class="lav-quando" id="lav-quando">—</div>
          </div>
          <div class="field">
            <label>Assinatura do fiscal</label>
            <div class="lav-fiscal" id="lav-fiscal"></div>
          </div>
        </div>

        {{-- O autuado assina NA PRÓPRIA VIA, em cima do nome dele: o campo é
             posto dentro da folha acima. Esta caixa só aparece se a folha
             não puder receber o campo. --}}
        <p class="lav-dica" id="lav-autuado-dica" hidden>
          O autuado / preposto assina na própria folha, no campo amarelo acima do nome dele.
          <button type="button" class="btn sm" onclick="irAoCampoDaVia()">Ir ao campo</button>
          <button type="button" class="btn sm" onclick="limparPadLavratura('autuado')">Limpar assinatura</button>
        </p>
        <div class="lav-caixa" id="lav-autuado-caixa">
          <div class="lav-cap">Assinatura do autuado / preposto</div>
          <canvas class="lav-canvas" id="lav-autuado"></canvas>
          <button type="button" class="btn sm" onclick="limparPadLavratura('autuado')">Limpar</button>
        </div>

        <label class="lav-recusa">
          <input type="checkbox" id="lav-recusa" onchange="alternarRecusaLavratura()">
          Declaro que o autuado se recusou a assinar
        </label>

        <div id="lav-recusa-bloco" hidden>
          <div class="g2">
            <div class="field">
              <label for="lav-testemunha">Testemunha</label>
              <select data-combo id="lav-testemunha" onchange="alternarRecusaLavratura()">
                <option value="">Selecione…</option>
              </select>
            </div>
            <div class="field" id="lav-testemunha-outro-campo" hidden>
              <label for="lav-testemunha-outro">Nome da testemunha</label>
              <input type="text" id="lav-testemunha-outro" maxlength="120" placeholder="Nome completo">
            </div>
          </div>
          <div class="lav-caixa">
            <div class="lav-cap">Assinatura da testemunha</div>
            <canvas class="lav-canvas" id="lav-testemunha-canvas"></canvas>
            <button type="button" class="btn sm" onclick="limparPadLavratura('testemunha')">Limpar</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- ── RODAPÉ FIXO ──
       Navegação entre abas à esquerda; ações à direita. Quais ações aparecem
       depende do estado — ver renderRodapeDoc(). --}}
  <div class="doc-foot">
    <div class="foot-setas">
      <button class="btn sm" id="fd-primeira" title="Primeira aba" onclick="irAbaDoc('autuado')">&laquo;</button>
      <button class="btn" id="fd-voltar" title="Aba anterior" onclick="passoAbaDoc(-1)">&lsaquo;</button>
      <button class="btn primary" id="fd-avancar" title="Próxima aba" onclick="passoAbaDoc(1)">&rsaquo;</button>
      <button class="btn sm" id="fd-ultima" title="Última aba" onclick="irAbaDoc('resumo')">&raquo;</button>
    </div>
    <div style="flex:1"></div>

    <div class="df-opcoes" id="fd-opcoes-wrap" hidden>
      <button type="button" class="btn opcoes" id="fd-btn-opcoes" onclick="abrirOpcoesDoc(event)">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        Opções
      </button>
    </div>

    <button class="btn" id="fd-sair-edicao" onclick="sairEdicaoDoc()" hidden>Sair</button>
    {{-- Mesmo desenho do Editar de usuários e de leis: verde de contorno com
         o lápis. Era o único da aplicação sem o ícone, e sem ele não se
         reconhecia como o mesmo gesto. --}}
    <button class="btn edit-verde" id="fd-editar" onclick="editarDoc()" hidden>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
        <path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>
      </svg>Editar</button>
    <button class="btn primary" id="fd-gravar" onclick="gravarDoc()" hidden>Gravar</button>
    {{-- Mesmo corpo do Editar ao lado: ícone de 18px e o rótulo. --}}
    <button class="btn lavrar" id="fd-lavrar" onclick="lavrarDocumento()" hidden>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
        <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>
      </svg>Lavrar</button>
    <button class="btn" id="fd-lavrar-cancelar" onclick="cancelarLavraturaDoc()" hidden>Cancelar</button>
    <button class="btn lavrar" id="fd-lavrar-ok" onclick="confirmarLavraturaDoc()" hidden>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
        <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>
      </svg>Confirmar lavratura</button>
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
    {{-- `pre-line` para a mensagem poder ter parágrafos: instrução de
         configuração em bloco corrido não se lê, e é justamente quando o
         usuário está travado que ela precisa ser fácil de seguir. --}}
    <div class="sub" id="mcg-msg"
         style="color:var(--tx2);font-size:13px;white-space:pre-line;line-height:1.5">Tem certeza?</div>
    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-confirm')">Cancelar</button>
      <button class="btn primary" id="mcg-btn-ok" onclick="_mcgConfirmar()">Confirmar</button>
    </div>
  </div>
</div>

{{-- OVERLAY DE CARREGAMENTO

     A MARCA girando, não um anel genérico. As duas logos são as duas faces do
     mesmo cartão: a institucional na frente, a âmbar no verso. A cada meia
     volta o giro entrega uma à outra — a alternância é o próprio movimento, e
     não dois desenhos piscando por conta própria.

     Estas duas imagens NÃO levam `data-src-institucional`, de propósito: o
     seletor de tema (tema.js) troca a logo de quem tem esse atributo, e aqui
     as duas precisam coexistir, uma em cada face, seja qual for o tema.

     `aria-hidden` na marca e `aria-live` no texto: para quem usa leitor de
     tela, o que informa é a frase, não o desenho. --}}

{{-- ══════ PEDIR UM TEXTO ══════
     O primo do modal de confirmação para quando a confirmação exige MOTIVO
     escrito. Genérico de propósito: já são três lugares que pedem justificativa
     (unificação direta, desmembramento direto e exclusão de resíduo), e três
     janelas quase iguais divergem na primeira vez que alguém mexer numa. --}}
<div class="modal-bg" id="m-texto" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()" style="max-width:460px">
    <button class="modal-x" onclick="fModalBtn('m-texto')">&#10005;</button>
    <h3 class="fi-cabeca">
      <span class="cab-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
      </span>
      <span id="mtx-titulo">Justificativa</span>
    </h3>

    <div class="field">
      <label for="mtx-campo" id="mtx-rotulo">Motivo</label>
      <textarea id="mtx-campo" rows="4"></textarea>
    </div>
    <div class="leg" id="mtx-dica" hidden></div>

    <div class="btn-row">
      <div style="flex:1"></div>
      <button class="btn" onclick="fModalBtn('m-texto')">Cancelar</button>
      <button class="btn primary" id="mtx-btn" onclick="_mtxConfirmar()">Confirmar</button>
    </div>
  </div>
</div>

{{-- ══════ APAGAR LOTE RESIDUAL ══════
     Pede SENHA além do motivo. Não é excesso: a ação é irreversível e o sistema
     é usado no celular, em campo, com o dedo — um toque errado não pode apagar
     um lote. Quem confere a senha é o servidor; a tela só a transporta e a
     esquece em seguida. --}}
<div class="modal-bg" id="m-excluir-lote" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()" style="max-width:460px">
    <button class="modal-x" onclick="fModalBtn('m-excluir-lote')">&#10005;</button>
    <h3 class="fi-cabeca">
      <span class="cab-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
      </span>
      <span>Apagar lote do desenho</span>
    </h3>
    <div class="sub">Apagar <b id="mex-lote">este lote</b> não tem volta. Use só para
      resíduo da conversão do desenho — faixa sem quadra, sem número e sem dono.
      Lote que deixou de existir de verdade se resolve por desmembramento ou
      unificação, que guardam a sucessão.
      {{-- Tudo ou nada: se algum dos marcados tiver vistoria, peça ou protocolo,
           o lote inteiro é recusado com o nome dele. Apagar parte da seleção em
           silêncio deixaria o fiscal sem saber o que sobrou. --}}
      Se algum dos marcados tiver história, nada é apagado e o sistema diz qual é.</div>

    <div class="field">
      <label for="mex-motivo">Por que este lote é resíduo?</label>
      <textarea id="mex-motivo" rows="3"
                placeholder="Ex.: sobra da conversão do DWG; faixa sem lote correspondente em campo."></textarea>
    </div>

    <div class="field">
      <label for="mex-senha">Sua senha</label>
      <input type="password" id="mex-senha" autocomplete="current-password">
    </div>

    <div class="btn-row">
      <div style="flex:1"></div>
      <button class="btn" onclick="fModalBtn('m-excluir-lote')">Cancelar</button>
      <button class="btn danger" id="mex-btn" onclick="confirmarExclusaoLote()">Apagar</button>
    </div>
  </div>
</div>

{{-- ══════ CORREÇÃO CADASTRAL — JANELA DE DADOS ══════
     Só o que é digitação e conferência. A janela pode ser fechada sem perder o
     trabalho: o que foi marcado ou desenhado continua no mapa, e a barra
     oferece reabrir. --}}
<div class="modal-bg" id="m-cad" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fecharModalCad()">&#10005;</button>
    <h3 class="fi-cabeca">
      <span class="cab-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
             stroke-linecap="round" stroke-linejoin="round">
          <rect x="2.5" y="2.5" width="8" height="8" rx="1.4"/>
          <rect x="2.5" y="13.5" width="8" height="8" rx="1.4"/>
          <rect x="13.5" y="13.5" width="8" height="8" rx="1.4"/>
          <path d="M21.2 2.8a1.9 1.9 0 0 1 0 2.7l-6 6-3 .8.8-3 6-6a1.9 1.9 0 0 1 2.2-.5z"/>
        </svg>
      </span>
      <span id="cad-modal-titulo">Correção cadastral</span>
    </h3>

    {{-- O CORPO É MÓVEL.
         Em tela grande ele sai daqui e vai para a mesa lateral (#cad-mesa),
         onde fica ao lado do mapa em vez de por cima dele. É movido, e não
         duplicado: dois formulários com os mesmos ids seriam dois campos
         disputando cada `getElementById`, e o que a tela lê deixaria de ser o
         que o operador digitou. --}}
    <div id="cad-modal-corpo">
    <div id="cad-corpo">

    {{-- QUADRA EM MASSA --}}
    <div class="cad-painel" id="cadp-quadra">
      <div id="cad-ato" hidden></div>
      <div class="leg" id="cad-contagem">Marque os lotes no mapa.</div>
      <div id="cad-acoes" hidden>
        <div class="field" style="margin:10px 0 6px" id="cad-quadra-campo">
          <label for="cad-quadra">Quadra a gravar</label>
          <input type="text" id="cad-quadra" class="mono" inputmode="numeric" maxlength="20"
                 placeholder="24">
        </div>
        <div class="seg" style="margin:0">
          <button type="button" id="cad-btn-limpar" onclick="limparSelecaoCadastral()">Limpar</button>
          <button type="button" id="cad-btn-conferir" onclick="conferirQuadraSelecao()">Conferir</button>
        </div>
        <div id="cad-previa"></div>
      </div>
    </div>

    {{-- DESENHO / COORDENADAS — os dois terminam no mesmo formulário, porque
         o que muda é como a geometria foi obtida, não o que se pede depois. --}}
    <div class="cad-painel" id="cadp-desenho" hidden>
      {{-- Saiu da prancheta sem fechar o contorno: sem isto o painel ficava
           com o título "Desenhar lote" e nada embaixo — nem como voltar à
           prancheta, nem como largar a ferramenta. --}}
      <div id="des-espera" hidden>
        <div class="cad-nota">O contorno do lote é traçado na prancheta. O rascunho do traçado fica guardado neste navegador.</div>
        <div class="btn-row">
          <button type="button" class="btn" onclick="sairModoCadastral()">Largar a ferramenta</button>
          <button type="button" class="btn primary" onclick="iniciarDesenhoDeLote()">Abrir a prancheta</button>
        </div>
      </div>
      <div id="coo-caixa" hidden>
        <div class="leg">
          Um vértice por linha, como vem no memorial. Exemplo:<br>
          <span class="mono" style="font-size:10.5px">V1 15°31'03,7"S 54°18'39,9"W</span>
        </div>
        <textarea id="coo-texto" rows="7" spellcheck="false"
                  style="width:100%;margin:6px 0;font-family:'JetBrains Mono',monospace;font-size:11.5px"
                  placeholder="15°31'03,7&quot;S 54°18'39,9&quot;W&#10;15°31'03,7&quot;S 54°18'39,5&quot;W&#10;15°31'04,4&quot;S 54°18'39,5&quot;W"></textarea>
        <div class="seg" style="margin:0">
          <button type="button" onclick="largarCoordenadas()">Limpar</button>
          <button type="button" onclick="lerCoordenadas()">Ler coordenadas</button>
        </div>
        <div id="coo-resultado"></div>
      </div>

      {{-- Enquanto se desenha, esta coluna fica VAZIA de propósito: quem está
           traçando olha para o mapa, e é lá que a barra `#des-barra` diz o
           passo. Aqui só aparece o formulário, depois que o contorno fecha. --}}

      <div id="des-dados" hidden>
        {{-- BAIRRO ESCOLHIDO, não digitado.
             Texto livre é a razão de o mesmo bairro estar hoje grafado de mais
             de um jeito na base — e bairro grafado diferente é lote que não se
             acha na consulta. A lista vem do cadastro da prefeitura
             (Parâmetros › Bairros). --}}
        <div class="field" style="margin:10px 0 6px">
          <label for="des-bairro">Bairro</label>
          <select data-combo id="des-bairro"><option value="">— escolha —</option></select>
        </div>
        <div class="g2" style="margin-bottom:6px">
          <div class="field" style="margin:0">
            <label for="des-quadra">Quadra</label>
            <input type="text" id="des-quadra" class="mono" inputmode="numeric" maxlength="20" placeholder="05">
          </div>
          <div class="field" style="margin:0">
            <label for="des-lote">Lote</label>
            <input type="text" id="des-lote" class="mono" maxlength="20" placeholder="1">
          </div>
        </div>

        {{-- AS MEDIDAS DA MATRÍCULA.
             Digitadas, e não deduzidas do desenho: o que o registro diz é fato
             jurídico, e o desenho é aferição. O quadro logo abaixo confronta
             as duas e aponta a diferença — sem impedir a gravação, porque
             campo obrigatório que atrapalha vira número inventado. --}}
        <div class="sec-simples" style="margin:12px 0 2px">Medidas da matrícula
          <span class="cont" id="des-conf-selo" hidden>—</span></div>
        <div class="cad-dica" style="margin-bottom:6px">
          Opcional. Preencha o que a matrícula trouxer; o desenho confere.</div>
        <div class="g2" style="margin-bottom:6px">
          <div class="field" style="margin:0">
            <label for="des-frente">Frente (m)</label>
            <input type="number" id="des-frente" class="mono" step="0.01" min="0"
                   inputmode="decimal" oninput="conferirMedidas()">
          </div>
          <div class="field" style="margin:0">
            <label for="des-fundos">Fundos (m)</label>
            <input type="number" id="des-fundos" class="mono" step="0.01" min="0"
                   inputmode="decimal" oninput="conferirMedidas()">
          </div>
        </div>
        <div class="g2" style="margin-bottom:6px">
          <div class="field" style="margin:0">
            <label for="des-lado-dir">Lado direito (m)</label>
            <input type="number" id="des-lado-dir" class="mono" step="0.01" min="0"
                   inputmode="decimal" oninput="conferirMedidas()">
          </div>
          <div class="field" style="margin:0">
            <label for="des-lado-esq">Lado esquerdo (m)</label>
            <input type="number" id="des-lado-esq" class="mono" step="0.01" min="0"
                   inputmode="decimal" oninput="conferirMedidas()">
          </div>
        </div>
        <div class="field" style="margin:0 0 6px">
          <label for="des-area-mat">Área da matrícula (m²)</label>
          <input type="number" id="des-area-mat" class="mono" step="0.01" min="0"
                 inputmode="decimal" oninput="conferirMedidas()">
        </div>
        <div id="des-conferencia"></div>
        <div class="seg" style="margin:0">
          <button type="button" onclick="largarDesenho()">Descartar</button>
          {{-- Só para o lote desenhado na prancheta: o das coordenadas se
               corrige no memorial. --}}
          <button type="button" id="des-redesenhar" onclick="voltarAoDesenhoDoLote()">Redesenhar</button>
          <button type="button" onclick="conferirDesenho()">Conferir</button>
        </div>
        <div id="des-previa"></div>
      </div>
    </div>


    {{-- HISTÓRICO DO CADASTRO — o que foi feito no desenho, e o desfazer.

         Só o que acontece NO MAPA: quadra corrigida, lote renumerado,
         unificação, desmembramento, exclusão e restauração. A auditoria do
         sistema inteiro — usuários, documentos, protocolos — é outra coisa, com
         outro público, e não cabe na coluna de trabalho do cadastro. --}}
    <div class="cad-painel" id="cadp-historico" hidden>
      <div class="hc-filtros">
        <select data-combo id="hc-escopo" onchange="carregarHistoricoCadastral()">
          <option value="tudo">Todo o cadastro</option>
          <option value="marcados">Só os lotes marcados</option>
        </select>
        <select data-combo id="hc-dias" onchange="carregarHistoricoCadastral()">
          <option value="7">Últimos 7 dias</option>
          <option value="30">Últimos 30 dias</option>
          <option value="90">Últimos 90 dias</option>
        </select>
      </div>
      <div id="hc-lista"></div>
    </div>

    </div>{{-- /cad-corpo --}}
    </div>{{-- /cad-modal-corpo --}}

    <div class="btn-row">
      <button class="btn" onclick="fecharModalCad()">Ver no mapa</button>
    </div>
  </div>
</div>

{{-- ══════ MESA DE EDIÇÃO CADASTRAL (tela grande) ══════

     Desenhar lote é trabalho de mesa, não de campo: acontece no monitor, com a
     matrícula do lado. Num painel de 262px flutuando sobre o mapa — que é o que
     cabe no celular — o operador lia de lado, o mapa encolhia e o passo
     seguinte ficava escondido atrás da própria janela onde ele trabalhava.

     Acima de 1000px (o mesmo ponto de quebra das listas em tabela) o lançador e
     o formulário saem de cima do mapa e vêm para esta coluna fixa à esquerda: as
     ferramentas em cima, o que a ferramenta ativa precisa logo abaixo, e o mapa
     inteiro livre à direita. Abaixo de 1000px nada disto existe e tudo volta
     para o painel flutuante — que é o que cabe na mão. --}}
<aside class="cad-mesa" id="cad-mesa" hidden aria-label="Edição cadastral">
  {{-- A RÉGUA — as ferramentas encostadas na borda, e não empilhadas dentro da
       coluna. O menu deixa de ser uma TELA que se troca pela ferramenta e passa
       a ser uma barra permanente: escolher a próxima é um clique, e não voltar
       e escolher.

       Ela é MONTADA a partir dos próprios botões de #cad-geral (ver
       montarReguaCadastral). Não há catálogo em JavaScript: manter dois
       significaria que uma ferramenta nova precisa ser lembrada em dois
       lugares — e a permissão de curador, que é decidida aqui no Blade com
       @@if, não chegaria ao segundo. --}}
  <nav class="cad-regua" id="cad-regua" aria-label="Ferramentas do cadastro"></nav>

  <div class="cad-mesa-lado">
    <div class="cad-mesa-topo">
      {{-- Com a régua à vista, ele não é mais "voltar ao menu" — o menu nunca
           saiu. É largar a ferramenta, e por isso só aparece com uma ativa. --}}
      <button class="cad-mesa-voltar" id="cad-mesa-voltar" hidden
              onclick="voltarAsFerramentas()" title="Largar a ferramenta">&#8592;</button>
      <span class="cad-mesa-tit" id="cad-mesa-titulo">Ferramentas do cadastro</span>
    </div>
    <div class="cad-mesa-rolo">
      <div id="mesa-lanca"></div>
      <div id="mesa-props"></div>
    </div>
  </div>
</aside>


{{-- ══════ MESA DE DESMEMBRAMENTO ══════

     Tela própria porque o assunto é outro: aqui não se corrige o mapa, divide-se
     um lote. O alvo fica realçado e com as medidas de cada lado à vista; os
     vizinhos ficam apagados e sem clique — referência, não material de trabalho.

     Não há "desenhar as partes à mão": só o corte por linha, que preserva o
     contorno externo do lote. Um ato que divide não pode mudar a divisa com o
     vizinho, e o desenho livre permitia exatamente isso. --}}

<aside class="desm-mesa" id="desm-mesa" hidden aria-label="Desmembramento">
  <div class="cad-mesa-topo">
    <span class="cad-mesa-tit">Desmembrar lote</span>
    <button type="button" class="btn sm at" id="desm-sat" aria-pressed="true"
            onclick="alternarSateliteDesm()" title="Liga e desliga a imagem aérea">Satélite</button>
    <button class="cad-mesa-x" onclick="sairMesaDesmembramento()"
            title="Sair da mesa" aria-label="Sair da mesa">&#10005;</button>
  </div>
  <div class="cad-mesa-rolo" id="desm-mesa-corpo"></div>
</aside>

<div class="tela-carregando" id="tela-carregando">
  <div class="carregando-marca" aria-hidden="true">
    {{-- Uma face e o verso; a imagem é a do tema, trocada por js/tema.js. --}}
    @foreach (['marca-face', 'marca-face marca-verso'] as $face)
      <img class="{{ $face }}" src="@assetv('img/logo-128.png')" alt=""
           data-src-institucional="@assetv('img/logo-128.png')" data-src-f="@assetv('img/logo-128-ambar.png')" data-src-cinza="@assetv('img/logo-128-cinza.png')">
    @endforeach
  </div>
  <div class="tela-carregando-txt" id="tela-carregando-txt" role="status"
       aria-live="polite">Carregando...</div>
</div>
{{-- Já aqui, e não só no fim da página: a tela de carregamento aparece antes
     de o documento terminar de chegar, e abriria com a marca verde. --}}
<script>aplicarTema(temaSalvo())</script>

<div id="toast"></div>

{{-- ══════ NOVA ORDEM DE SERVIÇO ══════
     A ordem responde quatro coisas, e o formulário segue essa ordem: o QUE se
     determina, a QUEM, QUANDO, e com que peso. --}}
<div class="modal-bg" id="m-os-nova" onclick="fModal()" data-caixa-alta>
  <div class="modal modal-flex" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-os-nova')">&#10005;</button>
    <div class="vs-head">
      <h3 class="fi-cabeca">
        <span class="cab-ico">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/>
            <rect x="9" y="2" width="6" height="4" rx="1"/>
            <path d="M9 13l2 2 4-4"/>
          </svg>
        </span>
        <span>Nova ordem de serviço</span>
      </h3>
      <div class="sub">O número é dado na emissão, pelo sistema.</div>
    </div>

    <div class="vs-corpo">
      <div class="sec-title">O que se determina</div>
      <div class="field">
        <label for="os-objeto">Objeto</label>
        <input type="text" id="os-objeto" maxlength="200"
               placeholder="Ex.: ronda de fiscalização no Jardim Europa IV">
      </div>
      <div class="field">
        <label for="os-descricao">Detalhamento</label>
        <textarea id="os-descricao" rows="3" maxlength="5000"
                  style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"
                  placeholder="O que deve ser feito, onde, e o que se espera de retorno"></textarea>
      </div>

      {{-- Contínuo x específico não é rótulo: muda o que significa "concluída".
           A específica termina quando é cumprida; a contínua, quando o período
           acaba ou a coordenação encerra. --}}
      <div class="vs-opcoes" id="os-natureza">
        @foreach (\App\Models\OrdemServico::NATUREZAS as $valor => $rotulo)
          <button type="button" class="vs-op" data-valor="{{ $valor }}"
                  onclick="escolherNatureza('{{ $valor }}')">{{ $rotulo }}</button>
        @endforeach
      </div>

      <div class="sec-title">A quem</div>
      <div class="leg">Mais de um fiscal na mesma ordem é o caso comum numa operação.</div>
      <div class="checklist" id="os-fiscais"></div>

      <div class="sec-title">Quando</div>
      <div class="vs-opcoes" id="os-regime">
        @foreach (\App\Models\OrdemServico::REGIMES as $valor => $rotulo)
          <button type="button" class="vs-op" data-valor="{{ $valor }}"
                  onclick="escolherRegime('{{ $valor }}')">{{ $rotulo }}</button>
        @endforeach
      </div>

      {{-- PERÍODO: uma janela contínua. --}}
      <div id="os-periodo" style="margin-top:9px">
        <div class="g2">
          <div class="field" style="margin:0">
            <label for="os-inicio">Início</label>
            <input type="date" id="os-inicio">
          </div>
          <div class="field" style="margin:0">
            <label for="os-fim">Fim</label>
            <input type="date" id="os-fim">
          </div>
        </div>
        <div class="cad-nota" style="margin-top:8px">Serviço contínuo pode ficar sem
          data de fim — e aí ele vale até a coordenação encerrar.</div>
      </div>

      {{-- DIAS MARCADOS: uma agenda, com horário por dia. --}}
      <div id="os-dias" hidden style="margin-top:9px">
        <div class="vs-nova-exig">
          <input type="date" id="os-dia-data" title="Dia">
          <input type="time" id="os-dia-ini" title="Começa">
          <input type="time" id="os-dia-fim" title="Termina">
          <button type="button" class="btn sm primary" onclick="addJornada()">+</button>
        </div>
        <div class="leg">O horário é opcional: "dia 12" sem hora é ordem legítima,
          e diferente de "dia 12 o dia inteiro".</div>
        <div id="os-jornadas"></div>
      </div>

      <div class="sec-title">Prioridade</div>
      <div class="vs-opcoes" id="os-prioridade">
        @foreach (\App\Models\OrdemServico::PRIORIDADES as $valor => $rotulo)
          <button type="button" class="vs-op" data-valor="{{ $valor }}"
                  onclick="escolherPrioridade('{{ $valor }}')">{{ $rotulo }}</button>
        @endforeach
      </div>
    </div>

    <div class="btn-row vs-rodape">
      <div style="flex:1"></div>
      <button class="btn" onclick="fModalBtn('m-os-nova')">Cancelar</button>
      <button class="btn primary" onclick="emitirOs()">Emitir ordem</button>
    </div>
  </div>
</div>

{{-- ══════ FICHA DA ORDEM DE SERVIÇO ══════ --}}
<div class="modal-bg" id="m-os" onclick="fModal()">
  <div class="modal modal-trab" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-os')">&#10005;</button>
    <h3 class="fi-cabeca">
      <span class="cab-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/>
          <rect x="9" y="2" width="6" height="4" rx="1"/>
          <path d="M9 13l2 2 4-4"/>
        </svg>
      </span>
      <span>Ordem de serviço</span>
      <span id="osf-numero" class="mono">—</span>
      <span class="badge" id="osf-situacao">—</span>
    </h3>
    <div class="sub" id="osf-objeto">—</div>

    <div class="mt-corpo">
      <div class="sec-title">A determinação</div>
      <div id="osf-corpo"></div>

      <div id="osf-ciencia"></div>
      <div id="osf-tramitacao"></div>
    </div>
  </div>
</div>

{{-- ══════ O ATO QUE NASCE DA VISTORIA ══════
     Aparece logo depois de gravar uma vistoria IRREGULAR, no único momento em
     que o fiscal ainda está com a obra na cabeça. Antes o caminho terminava na
     gravação: o painel cobrava "vistorias irregulares sem documento" e não
     havia por onde fechar. Vistoria regular não abre esta janela — nada
     aconteceu, e está certo. --}}
<div class="modal-bg" id="m-vist-ato" onclick="fModal()" data-caixa-alta>
  <div class="modal" onclick="event.stopPropagation()" style="max-width:460px">
    <button class="modal-x" onclick="fModalBtn('m-vist-ato')">&#10005;</button>
    <h3 class="fi-cabeca">
      <span class="cab-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
      </span>
      <span id="vato-titulo">Vistoria registrada</span>
    </h3>
    <div class="sub">A constatação é de irregularidade. A peça nasce vinculada a esta
      vistoria, com os artigos, a área aferida e as exigências que você registrou em campo.</div>

    <div class="vato-lista" id="vato-lista"></div>

    <div class="btn-row">
      <div style="flex:1"></div>
      {{-- "Agora não" e não "Cancelar": não há nada a cancelar — a vistoria já
           está gravada. O que se adia é o ato. --}}
      <button class="btn" onclick="fModalBtn('m-vist-ato')">Agora não</button>
    </div>
  </div>
</div>

{{-- ══════ VISTORIA GRAVADA — leitura ══════     A vistoria tinha formulário de criar e mais nada: depois de gravada virava
     uma linha na linha do tempo, e as fotos, o relatório e o que o fiscal
     escreveu sobre cada artigo não podiam mais ser vistos. Num processo o ato
     precisa poder ser reaberto e conferido, inclusive por quem não o praticou.
     A janela é só de leitura: corrigir vistoria gravada seria outro assunto —
     e um que exige trilha de alteração, não um campo editável. --}}
<div class="modal-bg" id="m-vistoria-ver" onclick="fModal()">
  <div class="modal modal-flex" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fecharVistoriaVer()">&#10005;</button>

    <div class="doc-head">
      <div class="doc-head-top">
        <span class="cab-ico">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
        </span>
        <span class="doc-head-doc" id="vv-finalidade">Vistoria</span>
        <span class="doc-head-num-wrap">
          <span class="doc-head-lbl">Nº</span>
          <span id="vv-numero" class="proto-badge doc-head-num">—</span>
        </span>
        <span id="vv-situacao" class="badge bd-in">—</span>
      </div>
      <div class="doc-head-meta">
        <div><span class="doc-head-lbl">Quando</span> <span id="vv-quando">—</span></div>
        <div><span class="doc-head-lbl">Fiscal</span> <span id="vv-fiscal">—</span></div>
        <div><span class="doc-head-lbl">Imóvel</span> <span id="vv-imovel">—</span></div>
      </div>
    </div>

    <div class="doc-body" id="vv-corpo"></div>

    <div class="doc-foot">
      <button class="btn" onclick="fecharVistoriaVer()">Fechar</button>
      <div style="flex:1"></div>
      <button class="btn" onclick="imprimirVistoria()">Imprimir</button>
      {{-- O caminho de quem volta ao caso dias depois. A peça nasce presa a
           ESTA vistoria, e não à última do imóvel.

           "Opções", e não "Gerar documento": o botão ABRE UM MENU com as
           quatro peças — nunca gerou nada direto. O nome antigo prometia um
           ato e entregava uma escolha, e é o mesmo gesto do ⋮ do resto do
           sistema. --}}
      @if (auth()->user()->podeLavrarDocumento())
        <button class="btn opcoes" onclick="documentoDaVistoria(event)">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
          Opções
        </button>
      @endif
    </div>
  </div>
</div>

{{-- ══════ FILTROS DA LISTA DE DOCUMENTOS ══════     Janela, e não menu: são três escolhas que se combinam, e menu é para
     escolher uma coisa e sair. --}}
<div class="modal-bg" id="m-doc-filtros" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()" style="max-width:420px">
    <button class="modal-x" onclick="fModalBtn('m-doc-filtros')">&#10005;</button>
    <h3 class="fi-cabeca">
      <span class="cab-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
      </span>
      <span>Filtrar documentos</span>
    </h3>

    <div class="field">
      <label for="doc-f-tipo">Tipo de peça</label>
      <select data-combo id="doc-f-tipo">
        <option value="">Tudo — peças e vistorias</option>
        @foreach (\App\Models\Documento::TIPOS as $valor => $t)
          <option value="{{ $valor }}">{{ $t[0] }}</option>
        @endforeach
        {{-- Não é um tipo de documento: é o recorte que mostra só os atos de
             campo. Fica no mesmo seletor porque, para quem usa, os dois estão
             na mesma lista e o filtro é um só. --}}
        <option value="vistoria">Vistorias</option>
      </select>
    </div>

    <div class="field">
      <label for="doc-f-status">Status</label>
      <select data-combo id="doc-f-status">
        <option value="">Todos os status</option>
        <option value="rascunho">Rascunho</option>
        <option value="lavrado">Lavrado</option>
        <option value="atendido">Atendido</option>
        <option value="anulado">Anulado</option>
      </select>
    </div>

    <div class="field">
      <label for="doc-f-agente">Agente</label>
      <select data-combo id="doc-f-agente">
        <option value="eu">Meus documentos</option>
        <option value="todos">Todos os agentes</option>
      </select>
    </div>

    <div class="btn-row">
      <button class="btn" onclick="limparFiltrosDoc()">Limpar</button>
      <div style="flex:1"></div>
      <button class="btn" onclick="fModalBtn('m-doc-filtros')">Cancelar</button>
      <button class="btn primary" onclick="aplicarFiltrosDoc()">Aplicar</button>
    </div>
  </div>
</div>

{{-- FICHA DO PROTOCOLO --}}
<div class="modal-bg" id="m-proto" onclick="fModal()">
  {{-- `modal-trab`: a MESMA janela das telas de trabalho (ficha, vistoria,
       peças). Passar de uma para a outra e ver a caixa mudar de tamanho no meio
       do caminho parece troca de sistema. --}}
  <div class="modal modal-trab" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-proto')">&#10005;</button>
    {{-- Mesmo cabeçalho das demais fichas do sistema: selo com o ícone,
         nome da peça, e o número em monoespaçada ao lado. Era um <h3> solto
         com o SVG inline, e por isso o ícone vinha sem o selo e o título com
         outro peso — a mesma tela, com duas caras. --}}
    <h3 class="fi-cabeca">
      <span class="cab-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/>
          <rect x="9" y="2" width="6" height="4" rx="1"/><path d="M8 12h8M8 16h5"/>
        </svg>
      </span>
      <span>Protocolo</span>
      <span id="pf-numero" class="mono">—</span>
    </h3>
    <div class="sub" id="pf-tipo">—</div>

    <div class="mt-corpo">
    <div class="sec-title">Dados do requerimento</div>
    <div id="pf-corpo"></div>
    {{-- Só em protocolo de desmembramento/unificação já deferido e ainda sem
         vistoria: o ato cadastral depende dela para ter fundamento. --}}
    <div id="pf-vistoria-cadastral" hidden></div>

    @if (auth()->user()->canEdit())
      <div class="sec-title">Tramitação</div>
      <div class="field">
        <label for="pf-situacao">Nova situação</label>
        <select data-combo id="pf-situacao">
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
    @endif
    </div>{{-- /mt-corpo --}}

    @if (auth()->user()->canEdit())
      {{-- Os botões saem da área que rola e ficam presos embaixo: numa janela
           alta, ação no meio do vazio parece que a tela quebrou. --}}
      <div class="btn-row">
        <button class="btn" onclick="assumirProtocolo()">Assumir</button>
        <button class="btn primary" onclick="concluirProtocolo()">Salvar tramitação</button>
      </div>
    @endif
  </div>
</div>

{{-- NOVO PROTOCOLO --}}
<div class="modal-bg" id="m-novo-proto" onclick="fModal()" data-caixa-alta>
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
      <select data-combo id="np-tipo">
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

@if (auth()->user()->podeCurarCadastro())
{{-- IMPORTAÇÕES DE BAIRRO — lista, envio com conferência do arquivo, e a
     ficha de cada importação (conferência com o cadastro, publicar, excluir).
     Um modal só, com três vistas trocadas por importacoes.js: é o mesmo
     assunto, e empilhar três modais para ele seria perder o caminho de volta. --}}
<div class="modal-bg" id="m-importacoes" onclick="fModal()">
  <div class="modal largo imp-modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fecharImportacoes()">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/></svg>
      <span id="imp-titulo">Importações de bairro</span>
    </h3>
    <div id="imp-corpo"><div class="vazio-msg">Carregando…</div></div>
  </div>
</div>

{{-- CONFERÊNCIA DO BAIRRO COM O CADASTRO — a que fica depois da importação
     (conferencia-bairro.js, ConferenciaBairroController). --}}
<div class="modal-bg" id="m-conferencia" onclick="fModal()">
  <div class="modal largo imp-modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fModalBtn('m-conferencia')">&#10005;</button>
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>
      <span>Conferência do bairro com o cadastro</span>
    </h3>
    <div id="conf-corpo"><div class="vazio-msg">Carregando…</div></div>
  </div>
</div>
@endif

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
    {{-- Um dos dois basta: é por um deles que o usuário entra. --}}
    <div class="field"><label for="us-matricula">Matrícula</label><input type="text" id="us-matricula" maxlength="30"></div>
    <div class="field"><label for="us-email">E-mail <span class="lembrar-obs">— opcional se houver matrícula</span></label><input type="email" id="us-email" maxlength="160"></div>

    <div class="sec-title">Acesso</div>
    <div class="field">
      <label for="us-cargo">Cargo</label>
      <select data-combo id="us-cargo" onchange="ajustarPerfilDoCargo()">
        <option value="agente">Agente de fiscalização</option>
        <option value="coordenador">Coordenador</option>
        <option value="secretario">Secretário</option>
        {{-- Externos: veem mapa e cadastro, e só a EXISTÊNCIA de vistorias e
             autos. Ver User::EXTERNOS. --}}
        <optgroup label="Externos — só mapa">
          <option value="topografo">Topógrafo</option>
          <option value="arquiteto">Arquiteto</option>
          <option value="contribuinte">Contribuinte</option>
        </optgroup>
      </select>
    </div>
    <div class="field">
      {{-- Só agente pode ter perfil acima de viewer — regra em User::perfilEfetivo(). --}}
      <label for="us-perfil">Perfil</label>
      <select data-combo id="us-perfil">
        <option value="admin">Administrador</option>
        <option value="comum">Comum</option>
        <option value="viewer">Visualizador</option>
      </select>
    </div>
    <label class="lembrar">
      <input type="checkbox" id="us-ativo" checked> Usuário ativo
    </label>
    {{-- Permissão à parte do perfil: corrigir a base do mapa muda a geometria
         que fundamenta o cálculo de área, e área é a base da multa. Quem
         administra o sistema não é, por isso, quem responde pelo cadastro. --}}
    <label class="lembrar">
      <input type="checkbox" id="us-curador"> Curadoria cadastral
      <span class="lembrar-obs">— pode corrigir quadra, desenhar lote e importar bairro; publicar é do administrador</span>
    </label>
    <p class="lembrar-obs" id="us-externo-obs" hidden>
      Externo vê mapa, busca e ficha do lote. Vistorias, autos e notificações
      aparecem na lista, sem abrir. O perfil fica em Visualizador.
    </p>

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
      <button class="btn" id="us-cancelar" onclick="fModalBtn('m-usuario')">Cancelar</button>
      {{-- Só na visualização (clique no cartão do usuário): libera os campos. --}}
      <button class="btn edit-verde" id="us-editar" onclick="liberarUsuario()" hidden>Editar</button>
      <button class="btn primary" id="us-salvar" onclick="salvarUsuario()">Salvar</button>
    </div>
  </div>
</div>

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

    {{-- Duas abas, e não uma coluna só: empilhado, o conteúdo estourava a
         altura do modal e a assinatura acabava desenhada dentro de uma área
         rolante — o pior lugar possível para arrastar o dedo, porque o gesto
         de desenhar disputa com o gesto de rolar. Separadas, cada aba cabe na
         tela e o canvas ganha a altura que a assinatura precisa. --}}
    <div class="sub-abas">
      <button class="at" data-pf="dados" onclick="subPerfil('dados')">Dados</button>
      <button data-pf="senha" onclick="subPerfil('senha')">Senha</button>
      <button data-pf="assinatura" onclick="subPerfil('assinatura')">Assinatura</button>
    </div>

    {{-- ── DADOS ── --}}
    <div class="pf-painel at" id="pf-dados">
      <div class="sec-title">Identificação</div>
      {{-- Duas colunas: ver o perfil não pode exigir rolagem, e é a rolagem
           que esconde o botão de salvar a senha lá embaixo. --}}
      <div class="pf-dupla">
        <div class="field">
          <label>E-mail</label>
          <input type="text" value="{{ auth()->user()->email }}" readonly>
        </div>
        <div class="field">
          <label>Matrícula</label>
          <input type="text" class="mono" value="{{ auth()->user()->matricula ?: '—' }}" readonly>
        </div>
      </div>
      <p class="aviso-legal">
        Nome, e-mail, matrícula e perfil são alterados pelo administrador do
        sistema — mudam quem você é no processo administrativo.
      </p>

      {{-- A escolha fica no navegador (localStorage), não no cadastro: é
           preferência de exibição, não dado do servidor administrativo. Vale
           por aparelho, que é o comportamento esperado de quem usa o celular
           em campo e o desktop na repartição. --}}
      <div class="sec-title">Aparência</div>
      <div class="tema-opcoes">
        <button type="button" class="tema-op" id="tema-op-institucional" onclick="escolherTema('institucional')">
          <span class="amostra" style="background:linear-gradient(160deg,#00451A,#006B28)"></span>
          <span>
            <span class="nome">Institucional</span>
            <span class="obs">Verde do município</span>
          </span>
        </button>
        <button type="button" class="tema-op" id="tema-op-f" onclick="escolherTema('f')">
          <span class="amostra" style="background:linear-gradient(135deg,#EA580C,#F97316)"></span>
          <span>
            <span class="nome">Âmbar</span>
            <span class="obs">Tema anterior</span>
          </span>
        </button>
        <button type="button" class="tema-op" id="tema-op-cinza" onclick="escolherTema('cinza')">
          <span class="amostra" style="background:linear-gradient(160deg,#4B545E,#5F6973)"></span>
          <span>
            <span class="nome">Cinza</span>
            <span class="obs">Chumbo e cinza claro</span>
          </span>
        </button>
      </div>

    </div>

    {{-- ── SENHA ──
         Aba própria, e não uma seção no fim de Dados. Empilhado, o conteúdo
         somava 443px numa área de 322 e a tela rolava — e o que ficava
         escondido embaixo era justamente o botão que conclui a troca. Espremer
         os campos resolveria a rolagem e criaria outro problema; separar
         resolve os dois. --}}
    <div class="pf-painel" id="pf-senha">
      <div class="field">
        {{-- Exigida mesmo com a sessão aberta: sem isso, um computador deixado
             destravado na repartição vira perda da conta. --}}
        <label for="pf-senha-atual">Senha atual</label>
        <input type="password" id="pf-senha-atual" autocomplete="current-password">
      </div>
      <div class="pf-dupla">
        <div class="field">
          <label for="pf-senha-nova">Nova senha (mín. 8)</label>
          <input type="password" id="pf-senha-nova" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="pf-senha-conf">Confirmar nova senha</label>
          <input type="password" id="pf-senha-conf" autocomplete="new-password">
        </div>
      </div>
      <p class="aviso-legal">
        A troca vale só para você. Senha de outro servidor é redefinida pelo
        administrador, em Parâmetros.
      </p>
      <div class="btn-row">
        <button class="btn primary" onclick="salvarSenha()">Alterar senha</button>
      </div>
    </div>

    {{-- ── ASSINATURA ── --}}
    <div class="pf-painel" id="pf-assinatura">
      <p class="aviso-legal">
        Desenhada uma vez e aplicada automaticamente nos documentos que você
        lavrar. Documentos já lavrados guardam a assinatura do dia e não mudam.
      </p>
      <div id="pf-assinatura-atual"></div>
      <div class="assina-caixa alta">
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
</div>


{{-- ESCOLHA DE ANEXOS ANTES DE IMPRIMIR
     Foto de evidência ocupa espaço grande na via impressa, e nem toda cópia
     precisa delas — a mesma pergunta do `#m-pdf-anexos` do AppPOSTURAS. --}}
<div class="modal-bg" id="m-imp-anexos" onclick="fModal()">
  <div class="modal sm" onclick="event.stopPropagation()" style="max-width:420px">
    <button class="modal-x" onclick="fModalBtn('m-imp-anexos')">&#10005;</button>
    <h3>Incluir anexos?</h3>
    <div class="sub" id="imp-anexos-msg" style="color:var(--tx2);font-size:13px">—</div>
    <div class="btn-row">
      <button class="btn" onclick="imprimirDoc(false)">Sem anexos</button>
      <button class="btn primary" onclick="imprimirDoc(true)">Com anexos</button>
    </div>
  </div>
</div>

{{-- ANULAÇÃO
     Motivo obrigatório: anulação sem motivação declarada não é ato
     administrativo. O documento não é apagado — passa a sair com marca. --}}
{{-- ══════ VISUALIZAR ANEXO (#m-anexo-view) ══════
     Igual ao do AppPOSTURAS: aberto pelo olho (ou pela miniatura) de cada
     anexo. Foto vai no <img>; PDF, no <iframe>. As setas andam pelos outros
     anexos da MESMA lista e somem quando só há um (documento-anexos.js). --}}
<div class="modal-bg" id="m-anexo-view" onclick="fModal()">
  <div class="modal" onclick="event.stopPropagation()">
    <button class="modal-x" onclick="fecharVisualizadorAnexo()">&#10005;</button>
    <h3 id="anexo-view-titulo">Anexo</h3>
    <div class="anexo-view-box">
      <button type="button" class="anexo-view-nav anexo-view-prev" id="anexo-view-prev" title="Anterior" onclick="navegarAnexoDoc(-1)" style="display:none">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <img id="anexo-view-img" alt="" style="display:none">
      <iframe id="anexo-view-frame" title="Anexo em PDF" style="display:none"></iframe>
      {{-- LEITOR DE PDF DO SISTEMA (PDF.js). Celular (Chrome do Android, app
           instalado) não mostra PDF dentro da página; aqui as folhas são
           desenhadas uma embaixo da outra, e rolam dentro do visualizador. --}}
      <div id="anexo-view-pdf" class="anexo-view-pdf" style="display:none">
        <div class="anexo-view-zoom">
          <button type="button" title="Diminuir" aria-label="Diminuir" onclick="zoomPdfAnexo(-1)">&minus;</button>
          <span id="anexo-view-zoom-v">100%</span>
          <button type="button" title="Aumentar" aria-label="Aumentar" onclick="zoomPdfAnexo(1)">+</button>
        </div>
        <div id="anexo-view-folhas" class="anexo-view-folhas"></div>
      </div>
      {{-- Só se o leitor não conseguir abrir o arquivo. --}}
      <div id="anexo-view-semleitor" class="anexo-view-semleitor" style="display:none">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <p>Não foi possível mostrar este PDF aqui.</p>
        <a class="btn primary" id="anexo-view-abrir" href="#" target="_blank" rel="noopener">Abrir o PDF</a>
      </div>
      <button type="button" class="anexo-view-nav anexo-view-next" id="anexo-view-next" title="Próximo" onclick="navegarAnexoDoc(1)" style="display:none">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
    </div>
    <div id="anexo-view-contador" class="anexo-view-contador"></div>
    <div class="btn-row">
      <button class="btn" onclick="fecharVisualizadorAnexo()">Fechar</button>
      <a class="btn out-verde" id="anexo-view-baixar" href="#" download target="_blank" rel="noopener">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Baixar
      </a>
    </div>
  </div>
</div>

{{-- ══════ PREPARAR A FOTO DO ANEXO ══════
     A foto é mostrada como vai ficar: com a data e a hora, a marca d'água do
     brasão e os rostos borrados. Tocar na foto borra ali. O que sobe para o
     servidor é esta imagem, já pronta (documento-anexos.js). --}}
<div class="modal-bg" id="m-anexo-foto" data-caixa-alta>
  <div class="modal" onclick="event.stopPropagation()" style="max-width:720px">
    <button class="modal-x" onclick="fecharFotoAnexo()">&#10005;</button>
    <h3>Preparar a foto</h3>
    <p class="anx-nota" id="anxf-status">Toque sobre cada rosto para borrar.</p>
    <div class="anxf-palco"><canvas id="anxf-canvas" onclick="borrarNaFotoAnexo(event)"></canvas></div>
    <div class="anxf-ferr">
      <label for="anxf-raio">Tamanho do borrão</label>
      <input type="range" id="anxf-raio" min="3" max="18" value="6">
      <button type="button" class="btn sm" onclick="desfazerBorraoAnexo()">Desfazer borrão</button>
    </div>
    <div class="field" style="margin-top:10px">
      <label for="anxf-titulo">Título do anexo</label>
      <input type="text" id="anxf-titulo" maxlength="160" placeholder="Ex.: fachada da obra, vista da rua">
    </div>
    <div class="btn-row" style="justify-content:flex-end;margin-top:12px">
      <button type="button" class="btn" onclick="fecharFotoAnexo()">Cancelar</button>
      <button type="button" class="btn primary" onclick="juntarFotoAnexo()">Juntar ao documento</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="m-doc-anular" onclick="fModal()">
  <div class="modal sm" onclick="event.stopPropagation()" style="max-width:460px">
    <button class="modal-x" onclick="fModalBtn('m-doc-anular')">&#10005;</button>
    <h3>Anular documento</h3>
    <div class="sub" style="color:var(--tx2);font-size:13px">
      O documento continua no processo e passa a ser impresso com a marca
      <b>ANULADO</b>. O motivo fica registrado com o seu nome.
    </div>
    <div class="field">
      <label for="da-motivo">Motivo da anulação</label>
      <textarea id="da-motivo" rows="4" maxlength="1000"
                style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"
                placeholder="Ex.: erro na identificação do imóvel autuado…"></textarea>
    </div>
    <div class="btn-row">
      <button class="btn" onclick="fModalBtn('m-doc-anular')">Cancelar</button>
      <button class="btn danger" onclick="confirmarAnulacaoDoc()">Anular</button>
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
// Quem só consulta não vê "Assumir" no menu da linha. A regra que vale
// continua no ProtocoloController; isto é para não oferecer o que seria recusado.
window.PODE_EDITAR = {{ Js::from(auth()->user()->canEdit()) }}
// O balão do mapa é montado em JavaScript, então o privilégio de curadoria
// precisa chegar até lá. A regra real está no CadastroLoteController.
window.PODE_CURAR_CADASTRO = {{ Js::from(auth()->user()->podeCurarCadastro()) }}
window.USUARIO_NOME = {{ Js::from(auth()->user()->name) }}
{{-- A tela usa isto so para ESCONDER o que o usuario nao pode fazer. Quem
     autoriza de verdade e o servidor, em QuarteiraoController::aplicar(). --}}
window.USUARIO_ADMIN = {{ Js::from(auth()->user()->isAdmin()) }}
{{-- Falso para topógrafo, arquiteto e contribuinte: a ficha lista vistorias e
     autos, mas sem abrir. Quem recusa de verdade é o middleware `interno`. --}}
window.PODE_VER_DOCUMENTOS = {{ Js::from(auth()->user()->podeVerDocumentos()) }}
{{-- Curador do cadastro: vê lotes não publicados e a conferência com o cadastro. --}}
window.USUARIO_CURADOR = {{ Js::from(auth()->user()->podeCurarCadastro()) }}
window.SATELITE_ALT = {{ Js::from($sateliteAlt) }}
</script>
<script src="{{ asset('vendor/leaflet-1.9.4/leaflet.js') }}"></script>
<script src="@assetv('js/ui.js')"></script>
{{-- O registro das camadas vem cedo: os módulos seguintes registram as suas. --}}
<script src="@assetv('js/camadas-mapa.js')"></script>
<script src="@assetv('js/pesquisa-mapa.js')"></script>
{{-- Uma ferramenta do mapa por vez: busca, cores, curadoria, desenho. --}}
<script src="@assetv('js/ferramentas-mapa.js')"></script>
<script src="@assetv('js/geo.js')"></script>
{{-- O perímetro urbano vem do servidor porque é configuração de município,
     e não constante de código: outra prefeitura muda o retângulo sem tocar no
     JavaScript. Ver config/gis.php. --}}
<script>
  const PERIMETRO_URBANO = @json(config('gis.perimetro_urbano'))
</script>
<script src="@assetv('js/mapa.js')"></script>
<script src="@assetv('js/vistoria.js')"></script>
<script src="@assetv('js/mapa-cores.js')"></script>
{{-- Depois de mapa-cores.js: `estiloColorido` consulta o `selState` daqui. A
     ordem não é obrigatória (o acesso é sempre em tempo de execução), mas
     manter o leitor perto do escritor poupa a próxima pessoa. --}}
{{-- Antes de cadastro.js: sao quem oferece iniciarDesenho/estaDesenhando e
     cortarPorLinha. O corte vive num arquivo proprio porque e geometria pura —
     nao toca no mapa, nao toca na tela, e por isso pode ser exercitado fora do
     navegador. --}}
<script src="@assetv('js/desenho.js')"></script>
<script src="@assetv('js/coordenadas.js')"></script>
<script src="@assetv('js/corte.js')"></script>
<script src="@assetv('js/cadastro.js')"></script>
<script src="@assetv('js/historico-cadastro.js')"></script>
<script src="@assetv('js/edificacoes.js')"></script>
<script src="@assetv('js/desmembramento.js')"></script>
<script src="@assetv('js/editor-cortes.js')"></script>
<script src="@assetv('js/prancheta-geo.js')"></script>
{{-- Depois de prancheta-geo.js: o cálculo do contorno usa a mesma régua (PranchetaGeo.plano). --}}
<script src="@assetv('js/bairros-contorno.js')"></script>
<script src="@assetv('js/ruas-manuais.js')"></script>
<script src="@assetv('js/prancheta-cadastral.js')"></script>
<script src="@assetv('js/cadastro-imobiliario.js')"></script>
<script src="@assetv('js/painel.js')"></script>
<script src="@assetv('js/busca.js')"></script>
<script src="@assetv('js/documentos.js')"></script>
<script src="@assetv('js/documento-form.js')"></script>
<script src="@assetv('js/documento-lavratura.js')"></script>
<script src="@assetv('js/documento-anexos.js')"></script>
<script src="@assetv('js/protocolos.js')"></script>
<script src="@assetv('js/os.js')"></script>
{{-- Depois dos dois: a fila lê as duas fontes e abre a ficha de cada uma. --}}
<script src="@assetv('js/demandas.js')"></script>
<script src="@assetv('js/perfil.js')"></script>
{{-- Sinalização e lembrete de revistoria: bandeiras no mapa, aviso na ficha,
     "Para hoje" no Painel. Depois de camadas-mapa.js (registra a camada). --}}
<script src="@assetv('js/sinalizacoes.js')"></script>
@if (auth()->user()->podeCurarCadastro())
  <script src="@assetv('js/importacoes.js')"></script>
  {{-- Depois de importacoes.js: usa a área de soltar a planilha de lá. --}}
  <script src="@assetv('js/conferencia-bairro.js')"></script>
@endif
@if (auth()->user()->isAdmin())
  <script src="@assetv('js/parametros.js')"></script>
  <script src="@assetv('js/cadastro-municipal.js')"></script>
@endif
<script src="@assetv('js/app.js')"></script>
</body>
</html>
