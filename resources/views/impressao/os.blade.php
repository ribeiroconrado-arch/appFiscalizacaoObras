{{--
  ORDEM DE SERVIÇO — layout oficial A4.

  Escrito no mesmo subconjunto de CSS que o layout do documento (a4.blade.php):
  tabelas para diagramar, nada de flexbox nem grid, fontes seguras. O motivo é
  o dompdf, que gera o PDF e não entende layout moderno. O cabeçalho repetido
  vem de <thead>, que funciona nos dois motores — no navegador, position:fixed
  reservaria espaço só na primeira página e cobriria o conteúdo da segunda.

  A ordem responde, nesta sequência: o que se determina, a quem, quando, e
  quem tomou ciência. É a sequência de uma delegação — e é por ela que se cobra
  depois.
--}}
@php
  $navegador = $navegador ?? false;
  $n = 0;
  $sec = function (string $titulo) use (&$n) { return (++$n) . ' – ' . $titulo; };
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ordem de Serviço {{ $os->numero }}</title>
<style>
  @page { size: A4; margin: 8mm 10mm 10mm 10mm; }

  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5px; color: #111;
         line-height: 1.45; margin: 0; }

  table.pagina { width: 100%; border-collapse: collapse; }
  table.pagina > thead td, table.pagina > tbody td { padding: 0; border: 0; }

  .cab { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .cab td { vertical-align: middle; padding: 0; border: 0; }
  .cab .brasao-cel { width: 52px; }
  .cab img.brasao { width: 46px; height: auto; }
  .cab .doc-titulo { font-size: 15px; font-weight: bold; letter-spacing: .03em; }
  .cab .num-cel { text-align: right; white-space: nowrap; }
  .cab .num-lbl { display: block; font-size: 7.5px; color: #666; letter-spacing: .1em; }
  .cab .num-val { font-size: 14px; font-weight: bold; }
  .cab-regua { border-bottom: 1.5px solid #111; margin: 3px 0 4px; height: 0; }
  .cab .orgao { font-size: 9.5px; font-weight: bold; }
  .cab .depto, .cab .end { font-size: 8.5px; color: #444; }
  .cab .selo { text-align: right; font-size: 9px; font-weight: bold; line-height: 1.1;
               border-left: 1.5px solid #111; padding-left: 8px; width: 92px; }

  .topo { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  .topo td { border: 1px solid #bbb; padding: 3px 5px; vertical-align: top; }
  .topo .lbl { display: block; font-size: 7.5px; color: #666; text-transform: uppercase;
               letter-spacing: .04em; }
  .topo .val { font-size: 10px; font-weight: bold; }

  .sec { margin-bottom: 9px; }
  .sec-tit { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em;
             border-bottom: 1px solid #111; padding-bottom: 2px; margin-bottom: 4px; }
  .txt { text-align: justify; white-space: pre-wrap; }

  /* Os dias marcados em tabela: uma agenda se confere linha a linha, e o
     horário tem de ficar embaixo do horário. */
  .dias { width: 100%; border-collapse: collapse; }
  .dias th, .dias td { border: 1px solid #bbb; padding: 3px 5px; font-size: 9.5px; }
  .dias th { background: #eee; text-align: left; font-size: 8px; text-transform: uppercase;
             letter-spacing: .04em; }
  .dias .dia { width: 90px; font-weight: bold; }
  .dias .hora { width: 120px; }

  /* ── Ciência dos designados ──
     Uma linha por fiscal, cada uma com o seu campo de assinatura: a ordem
     pode ser dada a três e recebida por dois, e o papel tem de mostrar isso. */
  .ciencia { width: 100%; border-collapse: collapse; }
  .ciencia td { border: 1px solid #bbb; padding: 6px 5px 3px; vertical-align: bottom;
                width: 50%; height: 62px; }
  .ciencia .nome { font-size: 9.5px; font-weight: bold; }
  .ciencia .papel { font-size: 8px; color: #666; text-transform: uppercase; letter-spacing: .04em; }
  .ciencia .linha { border-top: 1px solid #111; margin-top: 20px; padding-top: 2px;
                    font-size: 8px; color: #666; }
  .ciencia img.assina { max-height: 34px; max-width: 190px; display: block; margin-bottom: -2px; }
  .ciencia .quando { font-size: 8px; color: #444; }

  .rodape { margin-top: 10px; border-top: 1px solid #bbb; padding-top: 4px;
            font-size: 7.5px; color: #666; text-align: center; }
  .vazio { color: #666; font-style: italic; }
</style>
@if ($navegador)
  {{-- A janela de impressão se abre sozinha: quem clicou em "imprimir" já
       disse o que queria, e um segundo clique no diálogo é só atrito. --}}
  <script>window.addEventListener('load', () => window.print())</script>
@endif
</head>
<body>

<table class="pagina">
  <thead>
    <tr><td>
      <table class="cab">
        <tr>
          @if ($brasao)
            <td class="brasao-cel" rowspan="2"><img class="brasao" src="{{ $brasao }}" alt=""></td>
          @endif
          <td class="doc-titulo">ORDEM DE SERVIÇO</td>
          <td class="num-cel">
            <span class="num-lbl">Nº</span>
            <span class="num-val">{{ $os->numero }}</span>
          </td>
        </tr>
        <tr>
          <td colspan="2">
            <table style="width:100%;border-collapse:collapse">
              <tr>
                <td>
                  <div class="orgao">{{ $orgao['secretaria'] }}</div>
                  <div class="depto">{{ $orgao['nome'] }}@if($orgao['departamento']) – {{ $orgao['departamento'] }}@endif</div>
                  <div class="depto">{{ $orgao['divisao'] }}</div>
                  <div class="end">{{ collect([$orgao['endereco'], $orgao['telefone'], $orgao['municipio']])->filter()->implode(' – ') }}</div>
                </td>
                @if ($orgao['selo'])
                  <td class="selo">{{ $orgao['selo'] }}</td>
                @endif
              </tr>
            </table>
          </td>
        </tr>
      </table>
      <div class="cab-regua"></div>
    </td></tr>
  </thead>

  <tbody>
    <tr><td>

      <table class="topo">
        <tr>
          <td><span class="lbl">Emitida em</span>
              <span class="val">{{ $os->created_at?->format('d/m/Y H:i') }}</span></td>
          <td><span class="lbl">Natureza</span>
              <span class="val">{{ \App\Models\OrdemServico::NATUREZAS[$os->natureza] ?? '—' }}</span></td>
          <td><span class="lbl">Prioridade</span>
              <span class="val">{{ \App\Models\OrdemServico::PRIORIDADES[$os->prioridade] ?? '—' }}</span></td>
          <td><span class="lbl">Situação</span>
              <span class="val">{{ $os->situacaoTag()['texto'] }}</span></td>
        </tr>
      </table>

      <div class="sec">
        <div class="sec-tit">{{ $sec('Objeto') }}</div>
        <div class="txt">{{ $os->objeto }}</div>
      </div>

      @if ($os->descricao)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Detalhamento') }}</div>
          <div class="txt">{{ $os->descricao }}</div>
        </div>
      @endif

      @if ($os->lote || $os->protocolo)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Referências') }}</div>
          @if ($os->lote)
            <div>Imóvel: Quadra {{ $os->lote->quadra ?? '—' }} · Lote {{ $os->lote->numero_lote ?? '—' }}
                 — {{ $os->lote->bairro }}</div>
          @endif
          @if ($os->protocolo)
            <div>Protocolo: {{ $os->protocolo->numero }}</div>
          @endif
        </div>
      @endif

      <div class="sec">
        <div class="sec-tit">{{ $sec('Período de execução') }}</div>
        @if ($os->regime === 'dias' && $os->jornadas->count())
          <table class="dias">
            <tr><th class="dia">Dia</th><th class="hora">Horário</th><th>Observação</th></tr>
            @foreach ($os->jornadas as $j)
              <tr>
                <td class="dia">{{ $j->data->format('d/m/Y') }}</td>
                <td class="hora">
                  @if ($j->hora_inicio && $j->hora_fim)
                    {{ substr($j->hora_inicio, 0, 5) }} às {{ substr($j->hora_fim, 0, 5) }}
                  @elseif ($j->hora_inicio)
                    a partir das {{ substr($j->hora_inicio, 0, 5) }}
                  @elseif ($j->hora_fim)
                    até às {{ substr($j->hora_fim, 0, 5) }}
                  @else
                    <span class="vazio">sem horário marcado</span>
                  @endif
                </td>
                <td>{{ $j->observacao }}</td>
              </tr>
            @endforeach
          </table>
        @else
          <div>{{ ucfirst($os->quandoRotulo()) }}</div>
        @endif
      </div>

      {{-- A CIÊNCIA é o que transforma a ordem em delegação: sem ela, na hora
           de cobrar, "não fiquei sabendo" não se distingue de "fiquei e não
           fiz". Quem assinou pelo sistema aparece com o traço e a data; quem
           não assinou recebe a linha para assinar no papel. --}}
      <div class="sec">
        <div class="sec-tit">{{ $sec('Ciência dos designados') }}</div>
        <table class="ciencia">
          @foreach ($os->fiscais->chunk(2) as $par)
            <tr>
              @foreach ($par as $f)
                <td>
                  @if ($f->pivot->assinatura)
                    <img class="assina" src="{{ $f->pivot->assinatura }}" alt="">
                  @endif
                  <div class="linha">
                    <span class="nome">{{ $f->name }}</span>
                    @if ($f->matricula)<span class="papel"> · matrícula {{ $f->matricula }}</span>@endif
                    <div class="quando">
                      @if ($f->pivot->ciencia_em)
                        Ciência em {{ \Illuminate\Support\Carbon::parse($f->pivot->ciencia_em)->format('d/m/Y H:i') }},
                        pelo sistema
                      @else
                        Data: ____/____/________
                      @endif
                    </div>
                  </div>
                </td>
              @endforeach
              @if ($par->count() === 1)<td></td>@endif
            </tr>
          @endforeach
        </table>
      </div>

      @if ($os->encerramento)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Encerramento') }}</div>
          <div class="txt">{{ $os->encerramento }}</div>
          @if ($os->encerrada_em)
            <div class="quando">Registrado em {{ $os->encerrada_em->format('d/m/Y H:i') }}</div>
          @endif
        </div>
      @endif

      <div class="sec">
        <div class="sec-tit">{{ $sec('Determinação') }}</div>
        <table class="ciencia">
          <tr>
            <td>
              <div class="linha">
                <span class="nome">{{ $os->emitente?->name ?? '—' }}</span>
                <span class="papel"> · coordenação</span>
                <div class="quando">Emitida em {{ $os->created_at?->format('d/m/Y H:i') }}</div>
              </div>
            </td>
            <td></td>
          </tr>
        </table>
      </div>

      @if ($rodape)
        <div class="rodape">{!! implode(' &nbsp;·&nbsp; ', array_map('e', $rodape)) !!}</div>
      @endif

    </td></tr>
  </tbody>
</table>

</body>
</html>
