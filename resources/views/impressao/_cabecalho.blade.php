{{--
  CABEÇALHO OFICIAL — a marcação.

  Um lugar só para os três papéis: auto, relatório de vistoria e ordem de
  serviço. Eram três cópias, e trocar o tamanho do título corrigia um e deixava
  os outros dois para trás.

  Vai dentro do <thead> da tabela da página, que é o que faz o navegador E o
  dompdf repetirem o cabeçalho em cada folha reservando o espaço dele — o
  `position:fixed` repete mas só reserva na primeira, e cobre o conteúdo da
  segunda em diante.

  @param string      $titulo  "AUTO DE INFRAÇÃO", "RELATÓRIO DE VISTORIA"…
  @param string      $numero  já formatado ("AI 2026/0002")
  @param string|null $brasao  caminho ou data-URI; ausente, a coluna some
  @param array       $orgao   de App\Services\CabecalhoOficial::orgao()
--}}
<table class="cab">
  <tr>
    @if ($brasao)
      <td class="cab-brasao"><img src="{{ $brasao }}" alt=""></td>
    @endif
    <td>

      <table class="cab-l1">
        <tr>
          <td class="cab-titulo">{{ $titulo }}</td>
          <td class="cab-num">
            <span class="cab-num-lbl">NÚMERO</span><span class="cab-num-val">{{ $numero }}</span>
          </td>
        </tr>
      </table>

      <div class="cab-dash"></div>

      <table class="cab-l2">
        <tr>
          <td>
            <div class="cab-orgao">{{ $orgao['secretaria'] }}</div>
            <div class="cab-depto">{{ $orgao['nome'] }}@if ($orgao['departamento']) – {{ $orgao['departamento'] }}@endif</div>
            @if ($orgao['divisao'])
              <div class="cab-depto">{{ $orgao['divisao'] }}</div>
            @endif
            <div class="cab-end">{{ collect([$orgao['endereco'], $orgao['telefone'], $orgao['municipio']])->filter()->implode(' – ') }}</div>
          </td>
          @if ($orgao['selo'])
            {{-- Quebra sozinho em duas linhas ("FISCALIZAÇÃO / DE OBRAS")
                 porque a célula tem largura fixa: o texto vem de Parâmetros e
                 pode mudar, então quem decide onde quebrar é a largura, e não
                 um <br> escrito à mão que só serve para um valor. --}}
            <td class="cab-selo">{{ $orgao['selo'] }}</td>
          @endif
        </tr>
      </table>

    </td>
  </tr>
</table>
<div class="cab-regua"></div>
