{{--
  RELATÓRIO DE VISTORIA — A4, usado por dois destinos:
    · dompdf, em GET /vistorias/{id}/pdf
    · janela de impressão do navegador, em GET /vistorias/{id}/impressao

  Escrito no mesmo subconjunto de CSS do auto (impressao/a4.blade.php): tabelas
  para diagramar, nada de flexbox nem grid, e o cabeçalho repetido por <thead>,
  que funciona nos dois motores — `position:fixed` reserva espaço só na primeira
  página no navegador e passa a cobrir o conteúdo da segunda em diante.
--}}
@php
  /** Numeração das seções, calculada e não fixa: "Irregularidades",
      "Exigências" e "Documentos emitidos" só existem em algumas vistorias, e a
      sequência tem de fechar mesmo assim.

      Closure com `use (&$n)`, e não arrow function: a arrow captura o escopo
      por VALOR, e todas as seções sairiam numeradas "1". */
  $n = 0;
  $sec = function (string $titulo) use (&$n) {
      return (++$n) . ' – ' . $titulo;
  };

  $navegador = $navegador ?? false;
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $titulo }} {{ $v->numeroFormatado() }}</title>
<style>
  @page { size: A4; margin: 8mm 10mm 10mm 10mm; }

  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5px; color: #111;
         line-height: 1.45; margin: 0; }

  /* ── Cabeçalho institucional (repete em toda página) ── */
  table.pagina { width: 100%; border-collapse: collapse; }
  /* O `>` até o `td` é obrigatório: sem ele, "qualquer td dentro do invólucro"
     alcança as células de TODAS as tabelas aninhadas — e, por ter mais
     elementos no seletor, vence as regras próprias delas. Foi o que já zerou,
     em silêncio, a moldura da faixa do topo e o respiro das assinaturas no
     layout do auto. */
  table.pagina > thead > tr > td, table.pagina > tbody > tr > td { padding: 0; border: 0; }

@include('impressao._cabecalho-css')

  /* ── Seções ── */
  .sec { margin-bottom: 9px; }
  .sec-tit { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em;
             background: #ECECEC; border: 1px solid #bbb; padding: 3px 6px; margin-bottom: 5px; }
  .sec p { margin: 0 0 5px; text-align: justify; }

  table.campos { width: 100%; border-collapse: collapse; }
  table.campos td { border: 1px solid #ddd; padding: 3px 5px; vertical-align: top; }
  table.campos .lbl { width: 34%; color: #444; }
  /* O que ficou em branco sai em itálico e cinza: some do peso do dado
     apurado sem sumir da página — quem lê precisa saber que a pergunta foi
     feita e não foi respondida. */
  .vazio { color: #777; font-style: italic; }

  /* ── Irregularidades e exigências ── */
  table.itens { width: 100%; border-collapse: collapse; }
  table.itens th { background: #ECECEC; border: 1px solid #bbb; padding: 4px 5px; font-size: 8px;
                   text-transform: uppercase; letter-spacing: .04em; text-align: left; }
  table.itens td { border: 1px solid #ddd; padding: 4px 5px; font-size: 9.5px; vertical-align: top; }
  table.itens td.cod { white-space: nowrap; font-weight: bold; width: 62px; }
  table.itens td.grav { white-space: nowrap; width: 66px; text-transform: uppercase;
                        font-size: 8px; letter-spacing: .04em; }

  /* ── O relatório, na ordem escrita ── */
  .item { page-break-inside: avoid; margin-bottom: 10px; }
  .item img { max-width: 100%; max-height: 300px; border: 1px solid #ccc; }
  .item-tit { font-size: 9.5px; font-weight: bold; margin-top: 3px; }
  .item-obs { font-size: 9px; color: #444; text-align: justify; }
  .item-lei { border-left: 3px solid #999; padding-left: 7px; }
  .item-lei .rot { font-size: 7.5px; color: #666; text-transform: uppercase; letter-spacing: .05em; }
  /* Anexo que não é imagem (laudo, projeto, alvará em PDF): consta pelo nome.
     Um anexo que existe no processo e some do papel é peça que ninguém sabe
     que existe. */
  .item-arq { border: 1px dashed #aaa; padding: 5px 7px; font-size: 9px; color: #444; }

  /* ── Assinatura ── */
  table.assina { width: 100%; border-collapse: collapse; margin: 14px 0 12px;
                 page-break-inside: avoid; }
  table.assina td { width: 50%; text-align: center; vertical-align: bottom;
                    padding: 18px 22px 2px; border: 0; }
  /* O traço vem aparado do banco (App\Services\Assinatura): a altura da imagem
     é a da assinatura, sem a margem vazia do canvas. */
  .assina-img { max-height: 62px; max-width: 100%; width: auto; }
  .assina-vazio { height: 62px; }
  .assina-linha { border-top: 1px solid #111; padding-top: 3px; margin-top: 3px; font-size: 8.5px; }

  .rodape-inst { border-top: 1px solid #ccc; margin-top: 10px; padding-top: 5px;
                 font-size: 7.5px; color: #666; text-align: center; line-height: 1.4; }

@if ($navegador)
  /* Barra só da janela de impressão — some no papel. Existe porque o app
     instalado abre essa janela sem barra de navegador nenhuma: cancelada a
     caixa de impressão, o usuário ficaria sem como voltar nem reimprimir. */
  .imp-barra { position: fixed; left: 0; right: 0; bottom: 0; background: #fff;
               border-top: 1px solid #ddd; padding: 10px 14px; z-index: 999; }
  .imp-barra button { width: 48%; border: 0; border-radius: 999px; padding: 12px 0;
                      font-size: 15px; font-weight: bold; font-family: Arial, sans-serif; }
  .imp-fechar { background: #ECEFF1; color: #37474F; }
  .imp-print { background: #006B28; color: #fff; }
  body { padding-bottom: 62px; }
  @media print { .imp-barra { display: none; } body { padding-bottom: 0; } }
@endif
</style>
</head>
<body>

<table class="pagina">
  <thead>
    <tr><td>
      @include('impressao._cabecalho', ['numero' => $v->numeroFormatado()])
    </td></tr>
  </thead>

  <tbody>
    <tr><td>

      {{-- Faixa do topo: quem vistoriou, quando e o quê. Só na primeira
           página, por isso fica no tbody. --}}
      <table class="topo">
        <tr>
          <td><span class="topo-lbl">Exercício</span><span class="topo-val">{{ $v->exercicio ?? '—' }}</span></td>
          <td><span class="topo-lbl">Matrícula do fiscal</span><span class="topo-val">{{ $v->fiscal?->matricula ?? '—' }}</span></td>
          <td><span class="topo-lbl">Imóvel</span><span class="topo-val">{{ $imovel }}</span></td>
          <td><span class="topo-lbl">Finalidade</span><span class="topo-val">{{ $v->finalidadeRotulo() }}</span></td>
          <td><span class="topo-lbl">Data/hora da vistoria</span><span class="topo-val">{{ $v->data_hora?->format('d/m/y H:i') ?? '—' }}</span></td>
        </tr>
      </table>
      <div class="topo-regua"></div>

      {{-- 1 — O que foi constatado --}}
      <div class="sec">
        <div class="sec-tit">{{ $sec('O que foi constatado') }}</div>
        <table class="campos">
          @foreach ($constatado as $c)
            <tr>
              <td class="lbl">{{ $c['rotulo'] }}</td>
              <td>
                @if ($c['valor'])
                  {{ $c['valor'] }}
                @else
                  <span class="vazio">{{ $c['falta'] }}</span>
                @endif
              </td>
            </tr>
          @endforeach
        </table>
      </div>

      {{-- 2 — Irregularidades --}}
      @if ($v->irregularidades->count())
        <div class="sec">
          <div class="sec-tit">{{ $sec('Irregularidades constatadas') }}</div>
          <table class="itens">
            <thead>
              <tr><th>Código</th><th>Descrição</th><th>Gravidade</th></tr>
            </thead>
            <tbody>
              @foreach ($v->irregularidades as $i)
                <tr>
                  <td class="cod">{{ $i->codigo }}</td>
                  <td>{{ $i->descricao }}</td>
                  <td class="grav">{{ $i->gravidade }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif

      {{-- 3 — O relatório, na ordem em que foi escrito --}}
      @if (count($relatorio))
        <div class="sec">
          <div class="sec-tit">{{ $sec('Relatório') }}</div>
          @foreach ($relatorio as $i)
            <div class="item">
              @if ($i['tipo'] === 'foto')
                @if ($i['src'])
                  <img src="{{ $i['src'] }}" alt="">
                  <div class="item-tit">{{ $i['titulo'] ?: 'Fotografia' }}</div>
                  @if ($i['texto'])
                    <div class="item-obs">{{ $i['texto'] }}</div>
                  @endif
                @else
                  {{-- Sem imagem para estampar: ou é PDF, ou o arquivo sumiu do
                       disco. Nos dois casos o anexo consta. --}}
                  <div class="item-arq">
                    <b>Anexo:</b> {{ $i['titulo'] ?: ($i['arquivo_nome'] ?: 'arquivo') }}
                    @if (! $i['imagem']) (documento anexado ao processo) @endif
                    @if ($i['texto']) — {{ $i['texto'] }} @endif
                  </div>
                @endif
              @else
                <div class="item-lei">
                  <span class="rot">{{ $i['tipo'] === 'parecer' ? 'Parecer do fiscal' : 'Dispositivo citado' }}</span>
                  <div class="item-tit">{{ $i['titulo'] ?? '—' }}</div>
                  @if ($i['texto'])
                    <div class="item-obs">{{ $i['texto'] }}</div>
                  @endif
                </div>
              @endif
            </div>
          @endforeach
        </div>
      @endif

      {{-- 4 — Exigências --}}
      @if ($v->exigencias->count())
        <div class="sec">
          <div class="sec-tit">{{ $sec('Exigências') }}</div>
          <table class="itens">
            <tbody>
              @foreach ($v->exigencias as $e)
                <tr>
                  <td>{{ $e->texto }}</td>
                  <td class="grav">{{ $e->prazo_dias ? $e->prazo_dias . ' dias' : '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif

      {{-- 5 — Observações --}}
      @if ($v->observacoes)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Observações') }}</div>
          <p>{{ $v->observacoes }}</p>
        </div>
      @endif

      {{-- 6 — O que esta constatação gerou --}}
      @if (count($documentos))
        <div class="sec">
          <div class="sec-tit">{{ $sec('Documentos emitidos a partir desta vistoria') }}</div>
          <table class="itens">
            <thead>
              <tr><th>Número</th><th>Peça</th><th>Data</th><th>Situação</th></tr>
            </thead>
            <tbody>
              @foreach ($documentos as $d)
                <tr>
                  <td class="cod">{{ $d['numero'] }}</td>
                  <td>{{ $d['tipo'] }}</td>
                  <td class="grav">{{ $d['data'] }}</td>
                  <td class="grav">{{ $d['status'] }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif

      {{-- Assinatura: só a do fiscal. A vistoria é ato de constatação da
           administração — quem acompanhou está nomeado na seção 1, e não
           assina o que ele não declarou. --}}
      <table class="assina">
        <tr>
          <td>
            @if ($assinatura)
              <img class="assina-img" src="{{ $assinatura }}" alt="">
            @else
              <div class="assina-vazio"></div>
            @endif
            <div class="assina-linha">
              {{ $v->fiscal?->name ?? '' }}<br>
              {{-- O espaço antes do @if é obrigatório: colado a uma letra
                   ("fiscalização@if"), o Blade não reconhece a diretiva e ela
                   sai crua no HTML — deixando um @endif solto que quebra a
                   view inteira. No layout do auto ele vem depois de "}}", e
                   por isso lá nunca deu problema. --}}
              Agente de fiscalização @if ($v->fiscal?->matricula)– matrícula {{ $v->fiscal->matricula }}@endif
            </div>
          </td>
        </tr>
      </table>

      @if (count($rodape))
        <div class="rodape-inst">
          @foreach ($rodape as $linha)
            <div>{{ $linha }}</div>
          @endforeach
        </div>
      @endif

    </td></tr>
  </tbody>
</table>

@if ($navegador)
  <div class="imp-barra">
    <button type="button" class="imp-fechar" onclick="window.close()">Fechar</button>
    <button type="button" class="imp-print" onclick="window.print()">Imprimir</button>
  </div>
@endif

</body>
</html>
