      <table class="topo">
        <colgroup><col style="width:11%"><col style="width:17%"><col style="width:30%"><col style="width:21%"><col style="width:21%"></colgroup>
        <tr>
          <td style="width:11%"><span class="topo-lbl">Exercício</span><span class="topo-val">{{ $doc->exercicio ?? '—' }}</span></td>
          <td style="width:17%"><span class="topo-lbl">Matrícula do agente</span><span class="topo-val">{{ $doc->agente?->matricula ?? '—' }}</span></td>
          <td style="width:30%"><span class="topo-lbl">Origem</span><span class="topo-val">{{ $origemTexto }}</span></td>
          <td style="width:21%"><span class="topo-lbl">Data/hora do fato</span><span class="topo-val">{{ $doc->data_fato?->format('d/m/y H:i') ?? '—' }}</span></td>
          <td style="width:21%"><span class="topo-lbl">Data/hora da lavratura</span><span class="topo-val">{{ $doc->data_lavratura?->format('d/m/y H:i') ?? '—' }}</span></td>
        </tr>
      </table>
      <div class="topo-regua"></div>

