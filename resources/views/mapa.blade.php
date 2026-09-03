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
      data-src-institucional="@assetv('img/favicon-32.png')" data-src-f="@assetv('img/favicon-32-ambar.png')">
<link rel="icon" type="image/png" sizes="16x16" href="@assetv('img/favicon-16.png')"
      data-src-institucional="@assetv('img/favicon-16.png')" data-src-f="@assetv('img/favicon-16-ambar.png')">
<link rel="apple-touch-icon" sizes="180x180" href="@assetv('img/apple-touch-icon.png')"
      data-src-institucional="@assetv('img/apple-touch-icon.png')" data-src-f="@assetv('img/apple-touch-icon-ambar.png')">
<link rel="manifest" href="@assetv('manifest.json')">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="@assetv('css/app.css')">
{{-- Tema em camada separada: o design ainda está em avaliação (seis
     variantes). Trocar de proposta é trocar esta linha, não refazer o CSS. --}}
<link rel="stylesheet" href="@assetv('css/tema-f.css')">
{{-- Camada institucional (verde do município). Só pinta quando o <html> traz
     data-tema="institucional" — sem o atributo este arquivo é inerte, e é por
     isso que os dois temas convivem sem custo: um tema é um bloco de tokens,
     não uma segunda folha de componentes. --}}
<link rel="stylesheet" href="@assetv('css/tema-institucional.css')">
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

<header class="topo">
  {{-- Ícone oficial, sem o fundo de fora do squircle. Troca junto com o tema
       (ver js/tema.js): verde no institucional, âmbar no Tema F. --}}
  <img class="topo-marca" src="@assetv('img/logo-64.png')" alt=""
       data-src-institucional="@assetv('img/logo-64.png')" data-src-f="@assetv('img/logo-64-ambar.png')">
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

{{-- ══════ SUB-CABEÇALHO ══════
     Diz DE QUEM é o sistema (esquerda) e ONDE se está dentro dele (direita).

     A entidade vem dos parâmetros e o brasão de um arquivo enviado em
     Parâmetros → Órgão — não há nada de Primavera do Leste escrito no código.
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
  {{-- ORDEM DA TELA = ORDEM DA PERGUNTA.
       Quem abre o sistema de manhã não quer saber quantos autos saíram nos
       últimos 30 dias: quer saber o que precisa dele hoje. Por isso o trabalho
       vem primeiro e o número depois — e os filtros descem junto com o que
       eles filtram, porque avisos, pendências e atividade NÃO são filtrados
       por período, bairro ou agente. Deixá-los no topo dava a entender o
       contrário. --}}

  {{-- FAIXA 1 — as duas listas de AÇÃO, lado a lado.
       Ficam juntas porque se comparam: uma é o que o sistema avisa, a outra é
       o que ele cobra. Em tela estreita empilham nesta mesma ordem. --}}
  <div class="painel-duo">
    <div class="bloco">
      <div class="sec-simples">Avisos <span class="cont" id="pn-avisos-n">0</span></div>
      {{-- A mesma lista do sino, e da mesma rota: duas fontes para o mesmo
           aviso divergiriam no primeiro ajuste de regra. --}}
      <div id="pn-avisos"></div>
    </div>

    <div class="bloco">
      <div class="sec-simples">Precisa de você <span class="cont" id="pn-atencao-n">0</span></div>
      {{-- Prazos de documento, ordens de serviço designadas a mim e
           protocolos sob minha responsabilidade — ver PainelController::atencao. --}}
      <div id="pn-atencao"></div>
    </div>
  </div>

  {{-- FAIXA 2 — a linha do tempo, em largura cheia: ela se lê de corrida, e
       não item a item, então ganha com a largura que as listas não precisam. --}}
  <div class="bloco">
    <div class="sec-simples">Atividade recente</div>
    {{-- Alimentada pela tabela de auditoria — a mesma trilha que responde
         "quem fez o quê" no processo administrativo, não um log paralelo. --}}
    <div class="feed" id="pn-recentes"></div>
  </div>

  {{-- FAIXA 3 — o dashboard, com os filtros que valem só para ele. --}}
  <div class="painel-dash">
    <div class="dash-tit">
      <span class="sec-simples">Números do período</span>
    </div>

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

    <div class="metricas" id="pn-metricas"></div>

    <div class="painel-duo">
      <div class="bloco">
        <div class="sec-simples">Documentos por tipo</div>
        <div id="pn-por-tipo"></div>
      </div>
      <div class="bloco">
        <div class="sec-simples">Irregularidades frequentes</div>
        <div id="pn-irregs"></div>
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

  {{-- LOCALIZAR IMÓVEL
       Campo único: bairro, inscrição imobiliária, chave ou "quadra lote". Quem
       procura não deveria ter de decidir antes em qual campo o que sabe se
       encaixa. Consulta o cadastro do próprio município — nenhum
       geocodificador externo, nenhum custo por consulta. --}}
  <div class="ctrl-grupo" id="grupo-busca">
    <button class="ctrl-btn" onclick="alternarPainelMapa('grupo-busca')"
            title="Localizar imóvel" aria-expanded="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
    </button>
    <div class="ctrl-corpo">
      <b>Localizar imóvel</b>
      <input type="text" id="mb-termo" class="ctrl-input" placeholder="Bairro, inscrição ou quadra/lote"
             aria-label="Bairro, inscrição imobiliária ou quadra e lote"
             onkeydown="if(event.key==='Enter')buscarNoMapa()">
      <div class="seg" style="margin:8px 0 0">
        <button type="button" onclick="buscarNoMapa()">Localizar</button>
      </div>
      <div class="leg" id="mb-resultado">Digite bairro, inscrição imobiliária ou “quadra lote”.</div>
    </div>
  </div>

  {{-- PINOS POR FILTRO
       Marca no mapa os imóveis que atendem a um critério de fiscalização. É a
       pergunta que o mapa responde melhor que uma lista: onde estão. --}}
  <div class="ctrl-grupo" id="grupo-pins">
    <button class="ctrl-btn" onclick="alternarPainelMapa('grupo-pins')"
            title="Marcar imóveis no mapa" aria-expanded="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
    </button>
    <div class="ctrl-corpo">
      <b>Marcar no mapa</b>
      <select id="pin-bairro" class="ctrl-input" style="margin-bottom:6px">
        <option value="">Bairro — todos</option>
      </select>
      <select id="pin-vistoria" class="ctrl-input" style="margin-bottom:6px">
        <option value="">Situação da vistoria — qualquer</option>
        @foreach (\App\Models\Vistoria::SITUACOES as $valor => $rotulo)
          <option value="{{ $valor }}">{{ $rotulo }}</option>
        @endforeach
      </select>
      <label class="ctrl-chk"><input type="checkbox" id="pin-embargo"> Com embargo ativo</label>
      <label class="ctrl-chk"><input type="checkbox" id="pin-pendente"> Com documento pendente</label>
      <label class="ctrl-chk"><input type="checkbox" id="pin-sem-vistoria"> Projeto aprovado sem vistoria</label>
      <div class="seg" style="margin:8px 0 0">
        <button type="button" onclick="limparPins()">Limpar</button>
        <button type="button" onclick="marcarPins()">Marcar</button>
      </div>
      <div class="leg" id="pin-resultado">Escolha ao menos um filtro.</div>
    </div>
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

        <button type="button" class="btn sm cad-lanca" onclick="modoCadastral('quadra')">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="1.2"/>
            <rect x="14" y="3" width="7" height="7" rx="1.2"/>
            <rect x="3" y="14" width="7" height="7" rx="1.2"/>
            <path d="M14 17.5h7M17.5 14v7"/>
          </svg>
          <span class="cad-lanca-txt">Corrigir quadra
            <span class="cad-lanca-obs">Toque em vários lotes; todos recebem a mesma quadra.</span>
          </span>
        </button>

        <button type="button" class="btn sm cad-lanca" onclick="modoCadastral('desenho')">
          <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 20 20 4"/><path d="M4 14v6h6"/>
            <path d="M13.5 4H20v6.5"/>
          </svg>
          <span class="cad-lanca-txt">Desenhar lote faltante
            <span class="cad-lanca-obs">Com medida, esquadro e encaixe no vizinho.</span>
          </span>
        </button>

        <button type="button" class="btn sm cad-lanca" onclick="modoCadastral('coordenadas')">
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

        <button type="button" class="btn sm cad-lanca" onclick="desenharEdificacao()">
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

          <button type="button" class="btn sm cad-lanca" onclick="atoDiretoCadastral('unificacao')">
            <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="3" y="6" width="8" height="12" rx="1.2"/>
              <rect x="13" y="6" width="8" height="12" rx="1.2"/>
              <path d="M11 12h2"/>
            </svg>
            <span class="cad-lanca-txt">Unificar lotes direto
              <span class="cad-lanca-obs">Toque em 2 ou mais lotes vizinhos; eles viram um.</span>
            </span>
          </button>

          <button type="button" class="btn sm cad-lanca" onclick="atoDiretoCadastral('desmembramento')">
            <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="4" y="4" width="16" height="16" rx="1.4"/>
              <path d="M12 3v18" stroke-dasharray="3 2.5"/>
            </svg>
            <span class="cad-lanca-txt">Desmembrar lote direto
              <span class="cad-lanca-obs">Selecione 1 lote; a divisa sai de um corte, não de novo desenho.</span>
            </span>
          </button>

          <button type="button" class="btn sm cad-lanca cad-lanca-perigo" onclick="apagarLoteDoPainel()">
            <svg class="cad-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/>
              <path d="M6 6v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6"/>
            </svg>
            <span class="cad-lanca-txt">Apagar lote residual
              <span class="cad-lanca-obs">Marque um ou vários. Só para sobra da conversão do desenho.</span>
            </span>
          </button>
        @endif

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
        <input type="text" id="bs-inscricao" class="mono" maxlength="40"
               placeholder="01.000.024.0009.000" oninput="marcarPrecedencia()">
      </div>
      <div class="field bc-bairro">
        <label for="bs-bairro">Bairro / loteamento</label>
        <select id="bs-bairro" onchange="marcarPrecedencia()">
          <option value="">— todos —</option>
        </select>
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
          <select id="bs-vistoria" onchange="marcarPrecedencia()">
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
             baixado, com os documentos e vistorias dele pendurados. Some do
             mapa, some da consulta — e era só por aqui que se podia chegar de
             volta a um processo que corre contra um lote já desmembrado. --}}
        <label class="chk-item"><input type="checkbox" id="bs-baixados">
          <span class="desc">Incluir imóveis baixados (unificados/desmembrados)</span></label>
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
  <div class="filtro-barra">
    <div class="filtro-busca">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
      <input type="text" id="doc-busca" placeholder="Buscar nº, imóvel ou autuado…"
             oninput="filtrarDocumentos('busca', this.value)">
    </div>
    <button type="button" class="btn opcoes" onclick="abrirFiltrosDoc()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
      Filtros<span class="filtro-cont" id="doc-filtro-n" hidden>0</span>
    </button>
  </div>
  <div class="filtro-chips" id="doc-chips"></div>
  {{-- No computador os mesmos seletores voltam para cá, à vista. Nascem
       clonados dos da janela (`montarFiltroLargoDoc`), para as opções de tipo
       não serem escritas duas vezes e divergirem depois. --}}
  <div class="doc-filtro-larga" id="doc-filtro-larga"></div>

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
  <div class="sub-abas" id="po-abas">
    <button class="at" data-po="protocolos" onclick="abaProtocoloOs('protocolos')">Protocolos</button>
    <button data-po="os" onclick="abaProtocoloOs('os')">Ordens de serviço</button>
  </div>

  <div class="po-painel at" id="po-protocolos">
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

  {{-- No celular continuam duas linhas de dois controles; acima de 1000px o
       invólucro vira faixa e os quatro ficam lado a lado. --}}
  <div class="filtros-lista">
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
  </div>{{-- /filtros-lista --}}

  <div id="lista-protocolos"></div>
  </div>{{-- /po-protocolos --}}

  {{-- ══════ ORDENS DE SERVIÇO ══════ --}}
  <div class="po-painel" id="po-os">
    <div class="topo-lista">
      <div class="sec-simples">Ordens de serviço <span class="cont" id="cont-os">0</span></div>
      {{-- Emitir é da coordenação. Esconder o botão de quem não pode não é a
           segurança — quem autoriza é OrdemServicoController::store —, é não
           oferecer o que vai ser recusado. --}}
      @if (auth()->user()->isAdmin())
        <button class="btn primary sm" onclick="novaOs()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
               stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
          Nova OS
        </button>
      @else
        <button class="btn sm" disabled title="Só a coordenação emite ordem de serviço">Nova OS</button>
      @endif
    </div>

    <div class="filtros-lista">
    <div class="linha-filtro">
      <select onchange="filtrarOs('situacao', this.value)">
        <option value="">Todas as situações</option>
        @foreach (\App\Models\OrdemServico::SITUACOES as $valor => $sit)
          <option value="{{ $valor }}">{{ $sit['texto'] }}</option>
        @endforeach
      </select>
      {{-- O padrão vem do servidor: coordenação abre em "todas" para ver o que
           distribuiu; o fiscal abre em "minhas" para ver o que lhe cabe. --}}
      <select id="os-escopo" onchange="filtrarOs('agente', this.value)">
        <option value="eu">Minhas ordens</option>
        <option value="todas">Todas</option>
      </select>
    </div>
    <div class="linha-filtro">
      <input type="text" placeholder="Buscar nº ou objeto…"
             oninput="filtrarOs('busca', this.value)">
      <select onchange="filtrarOs('natureza', this.value)">
        <option value="">Toda natureza</option>
        @foreach (\App\Models\OrdemServico::NATUREZAS as $valor => $rotulo)
          <option value="{{ $valor }}">{{ $rotulo }}</option>
        @endforeach
      </select>
    </div>
    </div>{{-- /filtros-lista --}}

    <div id="lista-os"></div>
  </div>{{-- /po-os --}}
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
    <button data-sub="irregularidades" onclick="subParametros('irregularidades')">Irregularidades</button>
    <button data-sub="bairros" onclick="subParametros('bairros')">Bairros</button>
    <button data-sub="geral" onclick="subParametros('geral')">Órgão</button>
  </div>

  {{-- USUÁRIOS — desenho do painel administrativo do AppPOSTURAS: seção
       numerada, botão de contorno para criar, e um cartão por usuário com
       avatar, identificação e o Editar à direita. --}}
  <div class="par-painel at" id="par-usuarios">
    <div class="par-sec"><span class="par-num">1</span>Usuários<span class="cont" id="cont-usuarios">0</span></div>
    <button class="btn out-verde sm" onclick="novoUsuario()">+ Novo usuário</button>
    <div id="lista-usuarios" style="margin-top:12px"></div>
  </div>

  {{-- LEGISLAÇÃO — lista de leis → detalhe da lei, como no AppPOSTURAS.
       Aninhar os artigos dentro da lista virava uma árvore longa demais
       para achar qualquer coisa. --}}
  <div class="par-painel" id="par-legislacao">

    {{-- SUB-TELA: LISTA DE LEIS --}}
    <div id="leg-lista">
      <div id="par-legislacao-aviso"></div>
      <div class="par-sec"><span class="par-num">1</span>Leis<span class="cont" id="cont-leis">0</span></div>

      {{-- Busca e criação na mesma linha, como no AppPOSTURAS. O campo serve
           aos dois: filtra a lista enquanto se digita e, se nada casar, o
           texto vira o nome da lei nova — quem procurou e não achou está,
           quase sempre, prestes a cadastrar. --}}
      <div class="par-busca">
        <input type="text" id="lei-busca" placeholder="Nome da lei (ex: Lei Complementar 1.234/2020)…"
               oninput="filtrarLeis()" onkeydown="if(event.key==='Enter')novaLei()">
        <button class="btn out-verde sm" onclick="novaLei()">+ Nova lei</button>
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

  {{-- BAIRROS — cadastro direto na linha, como a UPF: são três campos curtos,
       e abrir uma janela para digitar um código e um nome custa mais do que o
       dado vale. --}}
  <div class="par-painel par-fixo" id="par-bairros">
    <div class="par-fixo-topo">
      <div class="sec-simples">Bairros do município <span class="cont" id="cont-bairros">0</span></div>
      <p class="aviso-legal">
        <b>Três nomes, de propósito.</b> O <b>código</b> e o <b>nome do cadastro</b>
        são os da prefeitura. O <b>nome no desenho</b> é como o bairro aparece no
        DWG convertido — é ele que amarra os lotes ao código, e fica vazio
        enquanto aquele bairro não tiver sido levantado.
      </p>
      <div class="cad-row">
        <input type="number" id="novo-bairro-codigo" placeholder="Código" min="1" style="max-width:110px">
        <input type="text" id="novo-bairro-nome" placeholder="Nome no cadastro (JARDIM EUROPA IV)">
        <input type="text" id="novo-bairro-gis" placeholder="Nome no desenho (opcional)"
               onkeydown="if(event.key==='Enter')salvarBairro()">
        <button class="btn primary sm" onclick="salvarBairro()">+ Novo bairro</button>
      </div>
      <div class="cad-row">
        <input type="search" id="filtro-bairros" placeholder="Procurar por código ou nome"
               oninput="renderBairros()" style="flex:1">
      </div>
    </div>
    <div class="par-fixo-lista" id="lista-bairros"></div>
  </div>

  {{-- IRREGULARIDADES — o catálogo que a vistoria oferece. Excluir é recusado
       quando alguma vistoria já constatou; desativar (a caixa "Ativa") tira
       da lista sem apagar o histórico. --}}
  <div class="par-painel par-fixo" id="par-irregularidades">
    <div class="par-fixo-topo">
      <div class="sec-simples">Catálogo de irregularidades <span class="cont" id="cont-irregularidades">0</span></div>
      <p class="aviso-legal">
        É o que a lei chama de infração — o que o fiscal marca na vistoria, e
        de onde saem os artigos sugeridos. Desativada, ela some das próximas
        vistorias mas continua legível nas já lavradas.
      </p>
      <div class="cad-row">
        <input type="text" id="irr-codigo" class="mono" placeholder="Código" style="max-width:90px">
        <input type="text" id="irr-descricao" placeholder="Descrição da irregularidade" style="flex:2">
        <select id="irr-gravidade">
          <option value="leve">Leve</option>
          <option value="media" selected>Média</option>
          <option value="grave">Grave</option>
        </select>
      </div>
      <div class="cad-row">
        <input type="text" id="irr-base-legal" placeholder="Base legal (opcional)" style="flex:1">
        <input type="number" id="irr-ordem" class="mono" placeholder="Ordem" min="0" style="max-width:90px">
        <label class="lembrar" style="margin:0">
          <input type="checkbox" id="irr-ativo" checked> Ativa</label>
        <button class="btn primary sm" onclick="salvarIrregularidade()">+ Nova irregularidade</button>
      </div>
      <div class="cad-row">
        <input type="search" id="filtro-irregularidades" placeholder="Procurar por código ou descrição"
               oninput="renderIrregularidades()" style="flex:1">
      </div>
    </div>
    <div class="par-fixo-lista" id="lista-irregularidades"></div>
  </div>

  {{-- ÓRGÃO --}}
  <div class="par-painel" id="par-geral">
    <div class="par-sec"><span class="par-num">1</span>Brasão do município</div>
    {{-- É o brasão que torna o sistema replicável: instalar a mesma aplicação
         em outra prefeitura passa a ser trocar dois cadastros, em vez de mexer
         no código. Por isso ele é enviado aqui, e não embutido em public/img. --}}
    <p class="aviso-legal">
      Aparece no sub-cabeçalho da tela e no cabeçalho dos documentos impressos.
      O fundo branco de fora do desenho é removido automaticamente no envio.
    </p>
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

    <div class="par-sec" style="margin-top:20px"><span class="par-num">2</span>Dados do órgão<span class="cont" id="cont-geral">0</span></div>
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
  {{-- Busca antes do Mapa de propósito: a camada de satélite é paga por
       requisição, e conferir a situação de um lote — que é a maior parte das
       consultas — não precisa de imagem aérea. O caminho mais barato vem
       primeiro. --}}
  <button class="aba" onclick="irPara('busca')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
    Consulta
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
    Protocolo &amp; OS
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
      <button data-fi="croquis" onclick="subFicha('croquis')">Croquis</button>
      <button data-fi="anexos" onclick="subFicha('anexos')">Anexos</button>
    </div>

    {{-- DADOS --}}
    <div class="fi-painel at" id="fi-dados">
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
      <div class="fi-midias">
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
      @if (auth()->user()->canEdit())
        {{-- As mesmas peças do botão da tela de Documentos, e não só vistoria:
             estando na ficha, o fiscal já sabe sobre qual imóvel vai lavrar —
             obrigá-lo a sair daqui para abrir uma notificação era um desvio sem
             motivo. --}}
        <button class="btn opcoes" onclick="novoDocumento(event)">Opções</button>
      @else
        {{-- Visualizador não registra: esconder o botão evita a ida ao
             servidor só para receber 403. A regra real está no controller. --}}
        <button class="btn opcoes" disabled title="Seu perfil permite apenas consulta">Opções</button>
      @endif
    </div>
  </div>
</div>

{{-- NOVA VISTORIA (Etapa 5) --}}
{{-- ══════════════════════════════════════════════
     VISTORIA DE OBRA (#m-vistoria) — CINCO PASSOS

     Um assunto por vez, e não uma coluna longa. O fiscal usa esta tela de pé,
     no sol, num celular: rolagem infinita ali é o que faz alguém desistir de
     registrar e "anotar depois" — que na prática é não registrar.

     O ATALHO existe pelo mesmo motivo. A ronda de rotina é a maioria absoluta
     das vistorias, e obrigá-la a atravessar cinco passos custaria mais do que
     a informação que os passos coletam.
     ══════════════════════════════════════════════ --}}
<div class="modal-bg" id="m-vistoria" onclick="fModal()">
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
      {{-- Barra de passos montada pelo JavaScript: quantos passos existem, e
           como se chama o segundo, dependem da FINALIDADE. Um auto de
           constatação não tem passo de medição nenhum — ver
           Vistoria::FINALIDADES, que é a fonte dessa regra dos dois lados. --}}
      <div class="vs-passos" id="nv-passos"></div>
    </div>

    <div class="vs-corpo">

    {{-- ── 1 · IDENTIFICAÇÃO ── --}}
    <div class="vs-painel at" id="nv-p-id" data-passo="id">
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

      {{-- A finalidade vem ANTES de tudo: ela decide quais passos existem e o
           que cada um pergunta. Escolhê-la depois obrigaria a refazer o que já
           tivesse sido preenchido. --}}
      <div class="sec-title">Para que é esta vistoria</div>
      <div class="vs-opcoes vs-finalidades" id="nv-finalidade">
        @foreach (\App\Models\Vistoria::FINALIDADES as $valor => $f)
          <button type="button" class="vs-op vs-op-larga" data-valor="{{ $valor }}"
                  onclick="escolherFinalidade('{{ $valor }}')">
            <span class="t">{{ $f['rotulo'] }}</span>
            <span class="o">{{ $f['obs'] }}</span>
          </button>
        @endforeach
      </div>

      <div class="field" style="margin-top:9px">
        <label for="nv-situacao">Situação constatada</label>
        <select id="nv-situacao">
          @foreach (\App\Models\Vistoria::SITUACOES as $valor => $rotulo)
            <option value="{{ $valor }}">{{ $rotulo }}</option>
          @endforeach
        </select>
      </div>

      {{-- A posição é capturada AQUI, e não só aproveitada do mapa: a vistoria
           acontece em frente ao imóvel, e é essa coordenada que vale como
           prova de que o fiscal esteve lá. --}}
      <div class="vs-gps">
        <div>
          <div class="fi-rot">Coordenada da vistoria</div>
          <div class="fi-val mono" id="nv-gps">não capturada</div>
        </div>
        <button type="button" class="btn sm out-green" id="nv-gps-btn"
                onclick="capturarGpsVistoria()">Capturar</button>
      </div>

      <div class="sec-title">Quem acompanhou</div>
      <div class="g2">
        <div class="field" style="margin:0">
          <label for="nv-acomp-nome">Nome</label>
          <input type="text" id="nv-acomp-nome" maxlength="160" placeholder="Quem recebeu o fiscal">
        </div>
        <div class="field" style="margin:0">
          <label for="nv-acomp-qual">Qualificação</label>
          <select id="nv-acomp-qual">
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
          <select id="nv-protocolo"><option value="">— nenhum —</option></select>
        </div>
      </div>

      <button type="button" class="btn sm vs-atalho" onclick="vistoriaRapida()">
        Vistoria rápida — só situação e foto</button>
    </div>

    {{-- ── 2 · A OBRA ── --}}
    {{-- Os blocos são todos escritos aqui e MOSTRADOS conforme a finalidade
         (data-bloco). Montar a marcação por finalidade daria cinco cópias
         quase iguais para manter em dia. --}}
    <div class="vs-painel" id="nv-p-obra" data-passo="obra">
      <div data-bloco="alvara">
      <div class="sec-title">Alvará</div>
      <div class="vs-opcoes" id="nv-alvara">
        @foreach (\App\Models\Vistoria::ALVARA as $valor => $rotulo)
          <button type="button" class="vs-op" data-valor="{{ $valor }}"
                  onclick="escolherAlvara('{{ $valor }}')">{{ $rotulo }}</button>
        @endforeach
      </div>
      <div class="field" id="nv-alvara-num-campo" hidden style="margin-top:8px">
        <label for="nv-alvara-numero">Número do alvará</label>
        <input type="text" id="nv-alvara-numero" class="mono" maxlength="40">
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
          <select id="nv-area-metodo">
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
      <div class="sec-title">Fase da obra</div>
      <div class="vs-opcoes" id="nv-fase">
        @foreach (\App\Models\Vistoria::FASES_OBRA as $valor => $rotulo)
          <button type="button" class="vs-op" data-valor="{{ $valor }}"
                  onclick="escolherFase('{{ $valor }}')">{{ $rotulo }}</button>
        @endforeach
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
    </div>

    {{-- ── 3 · RELATÓRIO ──
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
          Adicionar</button>
      </div>
      <div class="leg">Cada item é um ponto da obra, com o que for preciso dentro —
        na ordem em que você quiser contar.</div>
      <div id="nv-relatorio"></div>
      {{-- Fora do menu de propósito: é o mesmo input para o item "Foto" e para
           o atalho da vistoria rápida. `capture="environment"` abre a câmera
           traseira direto no celular; no desktop vira seletor de arquivo. --}}
      <input type="file" id="nv-arquivo" accept="image/*,application/pdf" multiple
             capture="environment" style="display:none" onchange="anexarArquivos(this)">

      {{-- O CHECKLIST SAIU DAQUI. Ele era uma lista única da vistoria, num
           bloco recolhido ao pé da tela; agora a irregularidade pertence ao
           ITEM onde foi constatada, e é escolhida dentro dele. Os artigos que
           ela sugere alimentam o seletor da mesma janela. --}}

      <div class="sec-title">Observações gerais</div>
      <div class="field">
        <label for="nv-obs">Descrição livre</label>
        <textarea id="nv-obs" rows="3" maxlength="5000"
                  style="width:100%;border:none;background:none;font-family:inherit;font-size:14px;resize:vertical"
                  placeholder="O que não coube nos itens acima"></textarea>
      </div>
    </div>

    {{-- ── 4 · REVISÃO ── --}}
    <div class="vs-painel" id="nv-p-rev" data-passo="rev">
      <div class="leg">Confira antes de gravar. A vistoria é ato: depois de
        gravada, ela fundamenta notificação, auto e embargo.</div>
      <div id="nv-revisao"></div>
    </div>

    </div>{{-- /vs-corpo --}}

    <div class="btn-row vs-rodape">
      <button class="btn" id="nv-voltar" onclick="passo(-1)">Voltar</button>
      <div style="flex:1"></div>
      {{-- O ÚNICO caminho que guarda rascunho. A tela não guarda mais nada por
           conta: gravar é decisão de quem escreve, e o botão fica ao lado de
           onde se sai, que é quando a decisão aparece. --}}
      <button class="btn" onclick="guardarRascunho()" title="Guarda o que está na tela neste aparelho, para continuar depois">Salvar rascunho</button>
      <button class="btn" onclick="fecharVistoria()">Cancelar</button>
      <button class="btn primary" id="nv-avancar" onclick="passo(1)">Avançar</button>
      <button class="btn primary" id="nv-gravar" onclick="gravarVistoria()" hidden>Gravar vistoria</button>
    </div>
  </div>
</div>

{{-- ══════ ITEM DO RELATÓRIO DE VISTORIA ══════
     Uma janela pequena por item, e não campos soltos crescendo na lista: o
     que se escreve num item é texto de peça, e merece o espaço de um
     formulário. A lista fica legível porque cada linha é só o resumo. --}}
{{-- ══════ UM ITEM DO RELATÓRIO ══════
     Os CINCO BLOCOS numa janela só, na mesma ordem em que sairão no papel:
     irregularidades, texto livre, artigos, exigências e fotos. É a ordem do
     raciocínio de uma peça — o fato, a narrativa, a lei, a providência e a
     prova —, e por isso ela é fixa: deixá-la à escolha faria cada relatório
     sair diferente, e quem lê vinte por semana perde o hábito de leitura.

     Editar em cinco telas separadas quebraria justamente o que o item existe
     para juntar. --}}
<div class="modal-bg" id="m-vs-item" onclick="fModal()">
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

    {{-- OS CINCO BLOCOS VIRAM CINCO BOTÕES.
         Antes eles vinham empilhados numa janela só: abrir um item despejava
         o catálogo inteiro de irregularidades, mais um formulário de artigo
         com três campos, mais um de exigência com dois, mais as fotos — tudo
         de uma vez, para preencher talvez um deles. A janela dizia o que ela
         PODE ter, quando o que o fiscal precisa ver é o que ela TEM.
         Cada botão traz a contagem do que já foi posto ali dentro. --}}
    {{-- Mesmo padrão de aba do resto do sistema (Parâmetros, o formulário de
         documento): trilho cinza, aba ativa em pílula branca com texto verde. --}}
    <div class="sub-abas" id="vsi-abas">
      <button type="button" data-bloco="irreg" onclick="abaDoItem('irreg')">
        Irregularidades <span class="vsi-conta" id="vsi-n-irreg"></span></button>
      <button type="button" data-bloco="texto" onclick="abaDoItem('texto')">
        O que você viu <span class="vsi-conta" id="vsi-n-texto"></span></button>
      <button type="button" data-bloco="artigos" onclick="abaDoItem('artigos')">
        Artigos <span class="vsi-conta" id="vsi-n-artigos"></span></button>
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

      {{-- 1 — IRREGULARIDADES: um combo, não mais um catálogo inteiro. As já
           marcadas aparecem no resumo, não como caixa marcada aqui. --}}
      <div class="vsi-bloco" data-bloco="irreg">
        <div class="vsi-busca">
          <div class="field" style="flex:1;margin:0">
            <label for="vsi-irreg-busca">Irregularidade</label>
            <input type="text" id="vsi-irreg-busca" autocomplete="off"
                   placeholder="Digite para buscar…"
                   oninput="buscarIrregularidade(this.value)"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();adicionarIrregularidadeAoItem()}">
          </div>
          <button type="button" class="btn sm" onclick="adicionarIrregularidadeAoItem()">+ add</button>
        </div>
        <div class="vsi-sugestoes" id="vsi-irreg-sugestoes" hidden></div>
        <div class="leg" id="vsi-irreg-nota">O que a lei chama de infração. É daqui que saem
          os artigos sugeridos — e é o que o auto de infração vai usar.</div>
      </div>

      {{-- 2 — TEXTO LIVRE --}}
      <div class="vsi-bloco" data-bloco="texto" hidden>
        <div class="field" style="margin:0">
          <label for="vsi-texto">O que você viu</label>
          <textarea id="vsi-texto" rows="3" maxlength="5000"
                    placeholder="Com as suas palavras — é este texto que vira o FATO na peça."
                    oninput="pintarContasDoItem()"></textarea>
        </div>
      </div>

      {{-- 3 — ARTIGOS --}}
      <div class="vsi-bloco" data-bloco="artigos" hidden>
        <div class="vsi-busca">
          <div class="field" style="flex:1;margin:0">
            <label for="vsi-artigo-busca">Artigo infringido</label>
            <input type="text" id="vsi-artigo-busca" autocomplete="off"
                   placeholder="Digite para buscar o artigo…"
                   oninput="buscarArtigo(this.value)"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();adicionarArtigoAoItem()}">
          </div>
          <button type="button" class="btn sm" onclick="adicionarArtigoAoItem()">+ add</button>
        </div>
        <div class="vsi-sugestoes" id="vsi-artigo-sugestoes" hidden></div>
        <div class="g2" style="margin:8px 0 0">
          <div class="field" style="margin:0">
            <label for="vsi-artigo-tipo">Como entra</label>
            {{-- Citação vira FATO na peça; parecer vira FUNDAMENTAÇÃO. --}}
            <select id="vsi-artigo-tipo">
              <option value="citacao">Citação</option>
              <option value="parecer">Parecer</option>
            </select>
          </div>
          <div class="field" style="margin:0">
            <label for="vsi-artigo-obs">Observação (opcional)</label>
            <input type="text" id="vsi-artigo-obs" maxlength="2000">
          </div>
        </div>
        <div class="leg" id="vsi-artigo-nota"></div>
      </div>

      {{-- 4 — EXIGÊNCIAS --}}
      <div class="vsi-bloco" data-bloco="exigencias" hidden>
        <div class="g2">
          <div class="field" style="margin:0">
            <label for="vsi-exig-texto">Providência exigida</label>
            <input type="text" id="vsi-exig-texto" maxlength="500">
          </div>
          <div class="field" style="margin:0;max-width:120px">
            <label for="vsi-exig-prazo">Prazo (dias)</label>
            <input type="number" id="vsi-exig-prazo" class="mono" min="1" max="3650">
          </div>
        </div>
        <button type="button" class="btn sm" onclick="adicionarExigenciaAoItem()" style="margin-top:8px">+ add</button>
      </div>

      {{-- 5 — FOTOS. Mantém lista própria: é a única aba onde o que se
           adiciona é visual, e miniatura não cabe numa linha de resumo. --}}
      <div class="vsi-bloco" data-bloco="fotos" hidden>
        <div class="sec-title-row">
          <button type="button" class="btn sm" onclick="document.getElementById('nv-arquivo').click()">Anexar foto</button>
          <span class="leg">Toque na foto para apontar o que a legenda descreve.</span>
        </div>
        <div id="vsi-fotos"></div>
      </div>

      <div class="vsi-resumo" id="vsi-resumo"></div>
    </div>

    <div class="doc-foot">
      {{-- "Excluir item" saiu daqui. Item recém-criado que ainda não foi
           Guardado não tem o que excluir — ele só existe se você cancelar
           (e some sozinho, vazio). Item já na lista se exclui DE LÁ, com o
           mesmo cuidado de qualquer exclusão do sistema. --}}
      <button class="btn" onclick="fecharItemRelatorio()">Cancelar</button>
      <button class="btn primary" onclick="salvarItemRelatorio()">Guardar</button>
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
<div class="modal-bg" id="m-doc" onclick="fModal()">
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
      <button class="doc-tab ativa" data-aba="autuado"  onclick="irAbaDoc('autuado')">Autuado</button>
      <button class="doc-tab" data-aba="imovel"   onclick="irAbaDoc('imovel')">Imóvel/Origem</button>
      <button class="doc-tab" data-aba="infracao" onclick="irAbaDoc('infracao')">Infração</button>
      <button class="doc-tab" data-aba="anexos"   onclick="irAbaDoc('anexos')">Anexos</button>
      <button class="doc-tab" data-aba="resumo"   onclick="irAbaDoc('resumo')">Resumo</button>
    </div>
  </div>

  {{-- ── CORPO ROLÁVEL ── --}}
  <div class="doc-body" id="fd-body">

    {{-- AUTUADO --}}
    <div class="doc-painel ativa" id="fdp-autuado">
      <div class="sec-title">Dados do autuado</div>
      <div class="field">
        <label for="nd-autuado-doc">CPF / CNPJ</label>
        <input type="text" id="nd-autuado-doc" class="mono" maxlength="20" data-lock
               placeholder="000.000.000-00">
      </div>
      <div class="field">
        <label for="nd-autuado">Nome / razão social</label>
        <input type="text" id="nd-autuado" maxlength="160" data-lock
               placeholder="Como consta no cadastro">
      </div>
      <p class="aviso-legal">
        Sem autuado identificado o documento ainda pode ser lavrado — a
        fiscalização encontra obra sem responsável no local o tempo todo. O
        nome pode ser completado antes da entrega da via.
      </p>
    </div>

    {{-- IMÓVEL / ORIGEM --}}
    <div class="doc-painel" id="fdp-imovel">
      <div class="sec-title">Imóvel</div>
      {{-- Só leitura: o imóvel vem do mapa ou da busca, e trocá-lo aqui
           faria o documento mudar de objeto no meio da lavratura. --}}
      <div class="df-grade" id="nd-imovel-dados"></div>

      <div class="sec-title">Endereço da obra</div>
      <div class="field">
        <label for="nd-endereco">Endereço</label>
        <input type="text" id="nd-endereco" maxlength="200" data-lock
               placeholder="Rua, número — complemento">
      </div>

      <div class="sec-title">Origem</div>
      <div class="field">
        <label for="nd-origem">Documento que originou este</label>
        <select id="nd-origem" data-lock>
          <option value="">Direta — sem documento anterior</option>
        </select>
      </div>
    </div>

    {{-- INFRAÇÃO --}}
    <div class="doc-painel" id="fdp-infracao">
      <div class="sec-title">Tipo e data</div>
      <div class="field">
        <label for="nd-tipo">Tipo de documento</label>
        <select id="nd-tipo" data-lock onchange="trocarTipoDoc()"></select>
      </div>
      <div class="field">
        <label for="nd-data">Data e hora do fato</label>
        <div class="data-hora-combo">
          <input type="date" id="nd-data" data-lock onchange="syncDataDoc()" onfocus="preencherDataHojeSeVazio(this)">
          <input type="time" id="nd-hora" data-lock onchange="syncDataDoc()" onfocus="preencherHoraAgoraSeVazio(this)">
        </div>
      </div>
      <input type="hidden" id="nd-datahora">

      <div id="bloco-fundamentacao">
        <div class="sec-title">Legislação infringida</div>
        <div id="nd-sugestao" style="margin-bottom:10px"></div>
        <div class="field">
          <label for="nd-lei">Lei</label>
          <select id="nd-lei" data-lock onchange="trocarLeiDoc()"></select>
        </div>
        <div class="checklist" id="nd-artigos"></div>

        {{-- Áreas: a base da multa em obras. Aparece só quando algum artigo
             escolhido cobra por metro quadrado. --}}
        <div id="nd-bloco-area" style="display:none">
          <div class="sec-title">Áreas para cálculo</div>
          <div class="field">
            <label for="nd-area-terreno">Área do terreno (m²)</label>
            <input id="nd-area-terreno" type="number" min="0" step="0.01" data-lock oninput="recalcularMultaDoc()">
          </div>
          <div class="field">
            <label for="nd-area-construida">Área construída aferida (m²)</label>
            <input id="nd-area-construida" type="number" min="0" step="0.01" data-lock oninput="recalcularMultaDoc()">
          </div>
          <div id="nd-memoria-calculo"></div>
        </div>
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
      <div class="sec-title">Anexos</div>
      <div id="nd-anexos"></div>
      <p class="aviso-legal">
        Os anexos deste documento são as evidências da vistoria vinculada —
        em obras a prova é fotografada na vistoria, e é ela que instrui o auto.
        Para acrescentar fotos, registre-as na vistoria do imóvel.
      </p>
    </div>

    {{-- RESUMO --}}
    <div class="doc-painel" id="fdp-resumo">
      <div class="doc-resumo" id="nd-resumo"></div>
    </div>
  </div>

  {{-- ── RODAPÉ FIXO ──
       Navegação entre abas à esquerda; ações à direita. Quais ações aparecem
       depende do estado — ver renderRodapeDoc(). --}}
  <div class="doc-foot">
    <button class="btn sm" id="fd-primeira" title="Primeira aba" onclick="irAbaDoc('autuado')">&laquo;</button>
    <button class="btn" id="fd-voltar" title="Aba anterior" onclick="passoAbaDoc(-1)">&lsaquo;</button>
    <button class="btn primary" id="fd-avancar" title="Próxima aba" onclick="passoAbaDoc(1)">&rsaquo;</button>
    <button class="btn sm" id="fd-ultima" title="Última aba" onclick="irAbaDoc('resumo')">&raquo;</button>
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
    <button class="btn atencao" id="fd-lavrar" onclick="lavrarDocumento()" hidden>Lavrar</button>
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
{{-- ══════ CORREÇÃO CADASTRAL — BARRA DE MODO ══════
     Fica sobre o mapa, fina, dizendo o passo em que se está e quantos lotes já
     foram marcados. É o que substitui o painel lateral durante o trabalho: o
     gesto acontece no mapa, e o mapa continua inteiro à vista. --}}
<div class="cad-barra" id="cad-barra" hidden>
  <span class="cad-barra-modo" id="cad-barra-modo">Corrigir quadra</span>
  <span class="cad-barra-passo" id="cad-barra-passo">Toque nos lotes do mapa.</span>
  <div class="cad-barra-acoes">
    <button type="button" class="btn sm" id="cad-barra-extra" hidden></button>
    <button type="button" class="btn sm primary" id="cad-barra-ok" hidden></button>
    <button type="button" class="btn sm" onclick="sairModoCadastral()">Sair</button>
  </div>
</div>

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
      <div class="leg" id="cad-contagem">Toque nos lotes do mapa para marcá-los.</div>
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
          <select id="des-bairro"><option value="">— escolha —</option></select>
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
          <button type="button" onclick="conferirDesenho()">Conferir</button>
        </div>
        <div id="des-previa"></div>
      </div>
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
  <div class="cad-mesa-topo">
    {{-- Só aparece com ferramenta ativa: no menu não há para onde voltar. --}}
    <button class="cad-mesa-voltar" id="cad-mesa-voltar" hidden
            onclick="voltarAsFerramentas()" title="Voltar às ferramentas">&#8592;</button>
    <span class="cad-mesa-tit" id="cad-mesa-titulo">Ferramentas do cadastro</span>
    <button class="cad-mesa-x" onclick="fecharMesaCadastral()"
            title="Fechar a mesa" aria-label="Fechar a mesa">&#10005;</button>
  </div>
  <div class="cad-mesa-rolo">
    <div id="mesa-lanca"></div>
    <div id="mesa-props"></div>
  </div>
</aside>

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
  <button type="button" class="btn primary sm" id="des-barra-fechar"
          onclick="concluirDesenho()" title="Enter">Fechar contorno</button>
  <button type="button" class="btn sm" onclick="cancelarDesenho()"
          title="Esc">Cancelar</button>
</div>

{{-- ══════ MESA DE DESMEMBRAMENTO ══════

     Tela própria porque o assunto é outro: aqui não se corrige o mapa, divide-se
     um lote. O alvo fica realçado e com as medidas de cada lado à vista; os
     vizinhos ficam apagados e sem clique — referência, não material de trabalho.

     Não há "desenhar as partes à mão": só o corte por linha, que preserva o
     contorno externo do lote. Um ato que divide não pode mudar a divisa com o
     vizinho, e o desenho livre permitia exatamente isso. --}}
{{-- O contorno de um imóvel BAIXADO fica por cima do mapa até alguém tirá-lo.
     Sem este botão, sair dele exigiria recarregar a página — e um traço cinza
     que não sai vira ruído em cima do trabalho seguinte. --}}
<button type="button" class="btn sm baixado-sair" id="btn-tirar-baixado" hidden
        onclick="tirarBaixadoDoMapa()">Tirar o contorno antigo do mapa</button>

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
    <img class="marca-face" src="@assetv('img/logo-128.png')" alt="">
    <img class="marca-face marca-verso" src="@assetv('img/logo-128-ambar.png')" alt="">
  </div>
  <div class="tela-carregando-txt" id="tela-carregando-txt" role="status"
       aria-live="polite">Carregando...</div>
</div>

<div id="toast"></div>

{{-- ══════ NOVA ORDEM DE SERVIÇO ══════
     A ordem responde quatro coisas, e o formulário segue essa ordem: o QUE se
     determina, a QUEM, QUANDO, e com que peso. --}}
<div class="modal-bg" id="m-os-nova" onclick="fModal()">
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
<div class="modal-bg" id="m-vist-ato" onclick="fModal()">
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
           ESTA vistoria, e não à última do imóvel. --}}
      @if (auth()->user()->podeLavrarDocumento())
        <button class="btn primary" onclick="documentoDaVistoria(event)">Gerar documento</button>
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
      <select id="doc-f-tipo">
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
      <select id="doc-f-status">
        <option value="">Todos os status</option>
        <option value="rascunho">Rascunho</option>
        <option value="lavrado">Lavrado</option>
        <option value="atendido">Atendido</option>
        <option value="anulado">Anulado</option>
      </select>
    </div>

    <div class="field">
      <label for="doc-f-agente">Agente</label>
      <select id="doc-f-agente">
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
    {{-- Permissão à parte do perfil: corrigir a base do mapa muda a geometria
         que fundamenta o cálculo de área, e área é a base da multa. Quem
         administra o sistema não é, por isso, quem responde pelo cadastro. --}}
    <label class="lembrar">
      <input type="checkbox" id="us-curador"> Curadoria cadastral
      <span class="lembrar-obs">— pode corrigir quadra e desenhar lote direto no mapa</span>
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
window.SATELITE_ALT = {{ Js::from($sateliteAlt) }}
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="@assetv('js/ui.js')"></script>
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
<script src="@assetv('js/edificacoes.js')"></script>
<script src="@assetv('js/desmembramento.js')"></script>
<script src="@assetv('js/cadastro-imobiliario.js')"></script>
<script src="@assetv('js/painel.js')"></script>
<script src="@assetv('js/busca.js')"></script>
<script src="@assetv('js/documentos.js')"></script>
<script src="@assetv('js/documento-form.js')"></script>
<script src="@assetv('js/protocolos.js')"></script>
<script src="@assetv('js/os.js')"></script>
<script src="@assetv('js/perfil.js')"></script>
@if (auth()->user()->isAdmin())
  <script src="@assetv('js/parametros.js')"></script>
@endif
<script src="@assetv('js/app.js')"></script>
</body>
</html>
