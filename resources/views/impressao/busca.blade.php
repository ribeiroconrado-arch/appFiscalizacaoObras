{{--
  CONSULTA DE IMÓVEIS — a listagem em A4 deitado (BuscaController::pdf).

  Mesmo subconjunto de CSS dos outros papéis (tabelas, sem flexbox nem grid):
  quem desenha é o dompdf. Sai a mesma consulta da tela, com os filtros em
  cima — quem recebe o papel precisa saber o que foi perguntado.
--}}
@php
  $m2 = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.') . ' m²';
  $int = fn ($v) => number_format((int) $v, 0, ',', '.');
  $situacao = function (array $l) {
      if (($l['situacao'] ?? null) === 'inativo') { return 'Inativo'; }
      return match ($l['ativo_no_cadastro'] ?? null) { true => 'Ativo', false => 'Inativo', default => 'Sem info.' };
  };
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Consulta de imóveis</title>
<style>
  @page { size: A4 landscape; margin: 8mm 10mm 10mm 10mm; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9px; color: #111; line-height: 1.4; margin: 0; }
  table.pagina { width: 100%; border-collapse: collapse; }
  table.pagina > thead > tr > td, table.pagina > tbody > tr > td { padding: 0; border: 0; }

@include('impressao._cabecalho-css')

  .nota { font-size: 8.5px; color: #333; margin: 0 0 3px; }
  .filtro { font-size: 9px; border: 1px solid #111; padding: 3px 6px; margin: 4px 0 6px; }
  .filtro b { font-weight: bold; }
  .aviso { font-size: 8.5px; font-weight: bold; margin: 0 0 6px; }
  table.lista { width: 100%; border-collapse: collapse; }
  table.lista th { font-size: 7px; text-transform: uppercase; letter-spacing: .04em; text-align: left;
                   background: #eee; border: 1px solid #bbb; padding: 2px 5px; }
  table.lista td { border: 1px solid #ccc; padding: 2px 5px; vertical-align: top; }
  .mono { font-size: 8px; white-space: nowrap; }
  .c { text-align: center; } .d { text-align: right; white-space: nowrap; }
  .inativo td { color: #8a1c1c; }
  .rodape { margin-top: 10px; border-top: 1px solid #bbb; padding-top: 4px; font-size: 7.5px; color: #666; }
</style>
</head>
<body>

@include('impressao._pagina-abre', ['titulo' => 'CONSULTA DE IMÓVEIS', 'numero' => $int($total), 'numeroRotulo' => 'IMÓVEIS'])

  <div class="filtro"><b>Filtros:</b> {{ $filtros ? implode(' · ', $filtros) : '—' }}</div>
  <p class="nota">{{ $int($total) }} {{ $total == 1 ? 'imóvel encontrado' : 'imóveis encontrados' }}.</p>
  @if ($truncado)
    <p class="aviso">Listagem cortada: mostra os {{ $int(count($linhas)) }} primeiros de {{ $int($total) }}. Refine os filtros para ver o restante.</p>
  @endif

  @if ($linhas)
    <table class="lista">
      <thead>
        <tr>
          <th style="width:98px">Inscrição</th>
          <th>Logradouro</th>
          <th style="width:42px" class="c">Número</th>
          <th>Bairro</th>
          <th style="width:38px" class="c">Quadra</th>
          <th style="width:32px" class="c">Lote</th>
          <th style="width:68px" class="d">Área</th>
          <th style="width:52px" class="c">Situação</th>
          <th style="width:30px" class="c">Doc.</th>
          <th style="width:30px" class="c">Vist.</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($linhas as $l)
          <tr @class(['inativo' => $situacao($l) === 'Inativo'])>
            <td class="mono">{{ $l['inscricao'] ?? '—' }}</td>
            <td>{{ $l['logradouro'] ?? '—' }}</td>
            <td class="c">{{ $l['numero'] ?? '—' }}</td>
            <td>{{ $l['bairro'] ?? '—' }}</td>
            <td class="c">{{ $l['quadra'] ?? '—' }}</td>
            <td class="c">{{ $l['lote'] ?? '—' }}</td>
            <td class="d">{{ $m2($l['area'] ?? null) }}</td>
            <td class="c">{{ $situacao($l) }}</td>
            <td class="c">{{ $l['documentos'] ?: '—' }}</td>
            <td class="c">{{ $l['vistorias'] ?: '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @else
    <p class="nota" style="margin-top:10px">Nenhum imóvel com esses filtros.</p>
  @endif

  <div class="rodape">Emitido em {{ $emitido }}@if ($por) por {{ $por }}@endif · situação e endereço conforme a última carga do cadastro municipal.</div>

@include('impressao._pagina-fecha')

</body>
</html>
