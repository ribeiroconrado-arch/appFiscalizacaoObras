{{--
  CONFERÊNCIA COM O CADASTRO — a listagem em A4 (App\Services\ConferenciaPdf).

  Mesmo subconjunto de CSS dos outros papéis (tabelas, sem flexbox nem grid):
  quem desenha é o dompdf. Sai o que a tela mostra — todas as divergências ou
  só as de um cartão —, com o resumo da conferência em cima.
--}}
@php
  $m2 = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.') . ' m²';
  $int = fn ($v) => number_format((int) $v, 0, ',', '.');
  $tol = $r['tolerancia_area'] ?? null;
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Conferência com o cadastro — {{ $onde }}</title>
<style>
  @page { size: A4; margin: 8mm 10mm 10mm 10mm; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5px; color: #111; line-height: 1.4; margin: 0; }
  table.pagina { width: 100%; border-collapse: collapse; }
  table.pagina > thead > tr > td, table.pagina > tbody > tr > td { padding: 0; border: 0; }

@include('impressao._cabecalho-css')

  .resumo { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .resumo td { border: 1px solid #bbb; padding: 4px 6px; text-align: center; }
  .resumo .lbl { display: block; font-size: 7px; text-transform: uppercase; letter-spacing: .04em; color: #555; }
  .resumo .val { display: block; font-size: 13px; font-weight: bold; }
  .nota { font-size: 8.5px; color: #333; margin: 0 0 3px; }
  .filtro { font-size: 9.5px; font-weight: bold; border: 1px solid #111; padding: 3px 6px; margin: 4px 0 6px; }

  .grupo { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em;
           border-bottom: 1px solid #111; padding-bottom: 2px; margin: 10px 0 0; }
  .grupo span { font-weight: normal; text-transform: none; letter-spacing: 0; color: #444; }
  table.lista { width: 100%; border-collapse: collapse; }
  table.lista th { font-size: 7px; text-transform: uppercase; letter-spacing: .04em; text-align: left;
                   background: #eee; border: 1px solid #bbb; padding: 2px 5px; }
  table.lista td { border: 1px solid #ccc; padding: 2px 5px; vertical-align: top; }
  .mono { font-size: 8.5px; white-space: nowrap; }
  .c-insc { width: 118px; } .c-ql { width: 92px; } .c-sit { width: 96px; }
  .just td { color: #555; }
  .obs { font-size: 8px; color: #555; }
  .rodape { margin-top: 10px; border-top: 1px solid #bbb; padding-top: 4px; font-size: 7.5px; color: #666; }
</style>
</head>
<body>

@include('impressao._pagina-abre', ['titulo' => 'CONFERÊNCIA COM O CADASTRO', 'numero' => $r['codigo_bairro'] ?? '—'])

  <p class="nota"><b>{{ $onde }}</b> · código do bairro {{ $r['codigo_bairro'] ?? '—' }}</p>
  <p class="nota">Fonte: {{ $r['fonte_descricao'] ?? '—' }} · conferido em {{ $r['conferido_em'] ?? '—' }} por {{ $r['conferido_por'] ?? '—' }}@if (! empty($r['revisao'])) · revisado sem planilha em {{ $r['revisao']['em'] }}@endif</p>
  @if ($tol)
    <p class="nota">Variação de área aceita: {{ ((float) $tol['valor']) > 0
        ? number_format((float) $tol['valor'], 2, ',', '.') . ($tol['unidade'] === 'pct' ? ' %' : ' m²')
        : 'nenhuma — a área tem de ser exata' }}.</p>
  @endif

  <table class="resumo">
    <tr>
      <td><span class="lbl">Casaram</span><span class="val">{{ $int($r['casaram'] ?? 0) }}</span></td>
      @foreach (\App\Services\ConferenciaPdf::TIPOS as $chave => $rotulo)
        <td><span class="lbl">{{ ['nao_encontrados' => 'Não está no cadastro', 'inativos' => 'Inativo no cadastro', 'sem_lote' => 'Cadastro sem lote', 'sem_inscricao' => 'Sem inscrição', 'area_diferente' => 'Área diferente'][$chave] }}</span>
            <span class="val">{{ $int(count($r[$chave] ?? [])) }}</span></td>
      @endforeach
    </tr>
  </table>

  @if ($filtro)
    <div class="filtro">Listagem filtrada: só "{{ $filtro }}".</div>
  @endif

  @forelse ($grupos as $g)
    <div class="grupo">{{ $g['rotulo'] }}
      <span>· {{ $g['pendentes'] }} pendente(s)@if (count($g['linhas']) > $g['pendentes']), {{ count($g['linhas']) - $g['pendentes'] }} justificada(s)@endif</span></div>
    <table class="lista">
      <thead>
        <tr>
          <th class="c-insc">Inscrição</th>
          <th class="c-ql">Quadra · Lote</th>
          <th>Detalhe</th>
          <th class="c-sit">Situação</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($g['linhas'] as $l)
          <tr @class(['just' => ! empty($l['justificativa'])])>
            <td class="mono">{{ $l['inscricao'] ?? '—' }}</td>
            <td>Q {{ $l['quadra'] ?? '—' }} · L {{ $l['lote'] ?? '—' }}</td>
            <td>
              @if ($g['tipo'] === 'area_diferente')
                desenho {{ $m2($l['area_desenho'] ?? null) }} · cadastro {{ $m2($l['area_cadastro'] ?? null) }}
                · diferença {{ ($l['diferenca'] ?? 0) > 0 ? '+' : '−' }}{{ number_format(abs((float) ($l['diferenca'] ?? 0)), 2, ',', '.') }} m²
              @elseif ($g['tipo'] === 'sem_lote')
                {{ ! empty($l['area_m2']) ? $m2($l['area_m2']) : '' }}{{ ! empty($l['area_m2']) && ! empty($l['endereco']) ? ' · ' : '' }}{{ $l['endereco'] ?? '' }}
              @elseif ($g['tipo'] === 'inativos')
                {{ ! empty($l['isencao']) ? 'Cadastro: ' . $l['isencao'] : '' }}
              @endif
              @if (! empty($l['alterado'])) <span class="obs">(lote alterado depois da conferência)</span> @endif
              @if (! empty($l['justificativa']))
                <div class="obs">Justificado por {{ $l['justificativa']['por'] ?? '—' }} em {{ $l['justificativa']['em'] ?? '—' }}: {{ $l['justificativa']['motivo'] ?? '' }}</div>
              @endif
            </td>
            <td>{{ ! empty($l['justificativa']) ? 'Justificada' : 'Pendente' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @empty
    <p class="nota" style="margin-top:10px">Nenhuma divergência{{ $filtro ? ' deste tipo' : '' }} nesta conferência.</p>
  @endforelse

  <div class="rodape">Emitido em {{ $emitido }}@if ($por) por {{ $por }}@endif · retrato da última conferência guardada no sistema.</div>

@include('impressao._pagina-fecha')

</body>
</html>
