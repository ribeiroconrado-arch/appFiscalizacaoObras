{{--
  RELATÓRIO DA VISTORIA GUIADA — PÁGINA DE TESTE (24/11/2026).
  Mesmo papel do relatório de vistoria (impressao/vistoria.blade.php): cabeçalho
  institucional do módulo, tabelas para diagramar, nada de flex nem grid — o
  dompdf só entende esse subconjunto.

  Os itens saem numerados 1, 2, 3… em sequência única, na ordem do roteiro. A
  etapa é só um título de grupo: o número é do item, que é o que se cita.
--}}
@php
  $s = 0;
  $sec = function (string $t) use (&$s) { return (++$s) . ' – ' . $t; };
  $navegador = $navegador ?? false;
  $local = $v->gps_lat !== null
      ? sprintf('GPS %.6f, %.6f%s', $v->gps_lat, $v->gps_lng, $v->gps_precisao ? ' (±' . $v->gps_precisao . ' m)' : '')
      : null;
  $marcado = $v->mapa_lat !== null ? sprintf('Marcado no mapa %.6f, %.6f', $v->mapa_lat, $v->mapa_lng) : null;
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $titulo }} {{ $numero }}</title>
<style>
  @page { size: A4; margin: 8mm 10mm 10mm 10mm; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5px; color: #111; line-height: 1.45; margin: 0; }
  table.pagina { width: 100%; border-collapse: collapse; }
  table.pagina > thead > tr > td, table.pagina > tbody > tr > td { padding: 0; border: 0; }

@include('impressao._cabecalho-css')

  .sec { margin-bottom: 10px; }
  .sec-tit { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em;
             background: #ECECEC; border: 1px solid #bbb; padding: 3px 6px; margin-bottom: 6px; }
  .sec p { margin: 0 0 5px; text-align: justify; }

  table.campos { width: 100%; border-collapse: collapse; }
  table.campos td { border: 1px solid #ddd; padding: 3px 5px; vertical-align: top; }
  table.campos .lbl { width: 28%; color: #444; }
  .vazio { color: #777; font-style: italic; }

  .grupo { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: .05em;
           color: #444; border-bottom: 1px solid #bbb; padding: 6px 0 2px; margin: 4px 0 6px; }

  /* Cada item não se parte entre páginas: o texto numa folha e a foto na
     outra desmontariam o que o item junta. */
  table.item { width: 100%; border-collapse: collapse; page-break-inside: avoid; margin-bottom: 7px; }
  table.item td { vertical-align: top; padding: 0; border: 0; }
  table.item td.n { width: 26px; font-weight: bold; font-size: 11px; }
  table.item td.res { width: 92px; text-align: right; }
  .it-rot { font-weight: bold; }
  .it-obs { font-size: 9.5px; color: #333; margin-top: 2px; text-align: justify; }
  .it-val { font-weight: normal; }
  .it-art { font-size: 9.5px; color: #222; margin-top: 2px; text-align: justify; }
  .it-nota { font-size: 8px; color: #777; font-style: italic; margin-top: 2px; }
  .res-t { display: inline-block; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em;
           border: 1px solid #999; padding: 1px 5px; }
  .res-nao { border-color: #111; background: #111; color: #fff; }
  .res-livre { color: #555; }
  .fotos img { max-width: 48%; max-height: 220px; border: 1px solid #ccc; margin: 4px 4px 0 0; }
  .fotos-etapa { page-break-inside: avoid; margin: 0 0 8px 26px; }
  .fotos-etapa .rot { font-size: 8px; color: #666; text-transform: uppercase; letter-spacing: .05em; }

  table.itens { width: 100%; border-collapse: collapse; }
  table.itens td { border: 1px solid #ddd; padding: 3px 5px; font-size: 9.5px; }

  .conclusao { font-size: 13px; font-weight: bold; border: 2px solid #111; padding: 6px 8px; display: inline-block; }

  table.assina { width: 100%; border-collapse: collapse; margin: 16px 0 10px; page-break-inside: avoid; }
  table.assina td { width: 50%; text-align: center; vertical-align: bottom; padding: 18px 22px 2px; border: 0; }
  .assina-img { max-height: 62px; max-width: 100%; width: auto; }
  .assina-vazio { height: 62px; }
  .assina-linha { border-top: 1px solid #111; padding-top: 3px; margin-top: 3px; font-size: 8.5px; }
  .assina-em { font-size: 7.5px; color: #666; }

  .rodape-inst { border-top: 1px solid #ccc; margin-top: 10px; padding-top: 5px; font-size: 7.5px; color: #666; text-align: center; line-height: 1.4; }

@if ($navegador)
  .imp-barra { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; border-top: 1px solid #ddd; padding: 10px 14px; z-index: 999; }
  .imp-barra button, .imp-barra a { display: inline-block; width: 31%; border: 0; border-radius: 999px; padding: 12px 0; text-align: center;
                      font-size: 15px; font-weight: bold; font-family: Arial, sans-serif; text-decoration: none; cursor: pointer; }
  .imp-fechar { background: #ECEFF1; color: #37474F; }
  .imp-pdf { background: #ECEFF1; color: #37474F; }
  .imp-print { background: var(--imp-cor, #006B28); color: #fff; }
  body { padding-bottom: 62px; }
  @media print { .imp-barra { display: none; } body { padding-bottom: 0; } }
@endif
</style>
</head>
<body>

@include('impressao._pagina-abre', ['numero' => $numero])

      <table class="topo">
        <tr>
          <td><span class="topo-lbl">Vistoria</span><span class="topo-val">{{ $v->versao->nome }}</span></td>
          <td><span class="topo-lbl">Início</span><span class="topo-val">{{ $v->iniciada_em?->format('d/m/y H:i') ?? '—' }}</span></td>
          <td><span class="topo-lbl">Conclusão</span><span class="topo-val">{{ $v->concluida_em?->format('d/m/y H:i') ?? 'em andamento' }}</span></td>
          <td><span class="topo-lbl">Resultado</span><span class="topo-val">{{ $v->conclusao ?? '—' }}</span></td>
        </tr>
      </table>
      <div class="topo-regua"></div>

      <div class="sec">
        <div class="sec-tit">{{ $sec('Identificação') }}</div>
        <table class="campos">
          @foreach ($vinculos as $g)
            <tr><td class="lbl">{{ $g['rotulo'] }}</td><td>{!! collect($g['itens'])->map(fn ($x) => e($x))->implode('<br>') !!}</td></tr>
          @endforeach
          @if ($v->local)
            <tr><td class="lbl">Local</td><td>{{ $v->local }}</td></tr>
          @endif
          <tr><td class="lbl">Localização</td><td>
            @if ($local || $marcado)
              {!! collect([$local, $marcado])->filter()->map(fn ($x) => e($x))->implode('<br>') !!}
            @else
              <span class="vazio">não exigida por este modelo</span>
            @endif
          </td></tr>
          <tr><td class="lbl">Fiscais</td><td>{{ collect($fiscais)->pluck('nome')->filter()->implode(', ') }}</td></tr>
        </table>
      </div>

      @if ($v->descricao)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Descrição') }}</div>
          @foreach (preg_split('/\n\s*\n/', trim($v->descricao)) as $par)
            <p>{!! nl2br(e($par)) !!}</p>
          @endforeach
        </div>
      @endif

      @if (collect($grupos)->sum(fn ($g) => count($g['itens'])))
        <div class="sec">
          <div class="sec-tit">{{ $sec('Itens verificados') }}</div>
          @foreach ($grupos as $g)
            @if ($g['titulo'])
              <div class="grupo">{{ $g['titulo'] }}</div>
            @endif
            @foreach ($g['itens'] as $i)
              <table class="item">
                <tr>
                  <td class="n">{{ $i['n'] }}.</td>
                  <td>
                    <div class="it-rot">{{ $i['rotulo'] }}@if ($i['resp'] && $i['resp']['v'] === 'valor'):
                      <span class="it-val">{{ $i['resp']['t'] }}</span>@endif</div>
                    @if ($i['obs'])
                      <div class="it-obs">{!! nl2br(e($i['obs'])) !!}</div>
                    @endif
                    @if (! empty($i['artigos']))
                      <div class="it-art"><b>Enquadramento:</b>
                        {!! collect($i['artigos'])->map(fn ($a) => e($a['numero'] . ($a['lei'] ? ' da ' . $a['lei'] : '') . ($a['conduta'] ? ' — ' . $a['conduta'] : '')))->implode('; ') !!}</div>
                    @endif
                    @if (! empty($i['exig']))
                      <div class="it-art"><b>Exigência{{ $i['prazo'] ? ' (prazo de ' . $i['prazo'] . ' dia' . ($i['prazo'] > 1 ? 's' : '') . ')' : '' }}:</b>
                        {!! nl2br(e($i['exig'])) !!}</div>
                    @endif
                    @if ($i['nota'])
                      <div class="it-nota">{{ $i['nota'] }}</div>
                    @endif
                    @if (count($i['fotos']))
                      <div class="fotos">@foreach ($i['fotos'] as $src)<img src="{{ $src }}" alt="">@endforeach</div>
                    @endif
                  </td>
                  <td class="res">
                    @if ($i['livre'])
                      <span class="res-t res-livre">Constatado</span>
                    @elseif ($i['resp'] && $i['resp']['v'] === 'valor')
                      <span class="res-t">{{ ($i['tipo'] ?? '') === 'numero' ? 'Aferido' : 'Informado' }}</span>
                    @elseif ($i['resp'])
                      <span class="res-t {{ $i['resp']['v'] === 'nao' ? 'res-nao' : '' }}">{{ $i['resp']['t'] }}</span>
                    @else
                      <span class="vazio">sem resposta</span>
                    @endif
                  </td>
                </tr>
              </table>
            @endforeach
            @if (count($g['fotos']))
              <div class="fotos-etapa"><span class="rot">Fotos da etapa</span>
                <div class="fotos">@foreach ($g['fotos'] as $src)<img src="{{ $src }}" alt="">@endforeach</div>
              </div>
            @endif
          @endforeach
        </div>
      @endif

      @if ($anexos->count())
        <div class="sec">
          <div class="sec-tit">{{ $sec('Documentos consultados') }}</div>
          <table class="itens">
            @foreach ($anexos as $a)
              <tr><td>{{ $a->nome }}</td><td style="width:40%;color:#555">{{ $a->nome_original }}</td></tr>
            @endforeach
          </table>
        </div>
      @endif

      <div class="sec">
        <div class="sec-tit">{{ $sec('Conclusão') }}</div>
        @if ($v->conclusao)
          <span class="conclusao">{{ $v->conclusao }}</span>
        @else
          <span class="vazio">Vistoria ainda não concluída.</span>
        @endif
      </div>

      {{-- Uma assinatura por fiscal relacionado, duas por linha. Quem ainda
           não assinou sai com o espaço em branco e o nome — o papel mostra
           que a assinatura falta, em vez de esconder o fiscal. --}}
      <table class="assina">
        @foreach (array_chunk($fiscais, 2) as $par)
          <tr>
            @foreach ($par as $f)
              <td>
                @if ($f['assinatura'])
                  <img class="assina-img" src="{{ $f['assinatura'] }}" alt="">
                @else
                  <div class="assina-vazio"></div>
                @endif
                <div class="assina-linha">
                  {{ $f['nome'] }}<br>
                  Agente de fiscalização @if ($f['matricula'])– matrícula {{ $f['matricula'] }}@endif
                  {{-- Linha sempre presente: sem ela, a assinatura de quem
                       assinou sobe e as duas linhas do par desalinham. --}}
                  <div class="assina-em">{{ $f['em'] ? 'assinado no sistema em ' . $f['em'] : 'aguardando assinatura' }}</div>
                </div>
              </td>
            @endforeach
            @if (count($par) === 1)<td></td>@endif
          </tr>
        @endforeach
      </table>

      @if (count($rodape))
        <div class="rodape-inst">@foreach ($rodape as $linha)<div>{{ $linha }}</div>@endforeach</div>
      @endif

@include('impressao._pagina-fecha')

@if ($navegador)
  @include('impressao._tema-janela')
  <div class="imp-barra">
    <button type="button" class="imp-fechar" onclick="window.close()">Fechar</button>
    <a class="imp-pdf" href="{{ route('vg.relatorio.pdf', $v) }}" target="_blank" rel="noopener">PDF</a>
    <button type="button" class="imp-print" onclick="window.print()">Imprimir</button>
  </div>
@endif

</body>
</html>
