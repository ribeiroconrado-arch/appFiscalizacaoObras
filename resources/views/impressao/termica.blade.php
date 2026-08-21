{{--
  Layout BOBINA TÉRMICA 80mm — a via que o fiscal entrega na hora, em campo.

  Só o navegador renderiza este formato: a página tem altura variável
  (`size: 80mm auto`), e o dompdf exige altura fixa. Quem precisa de arquivo
  arquivável usa o A4 (impressao/a4.blade.php), que sai igual nos dois motores.

  Duas medidas que parecem erro e não são, herdadas do AppPOSTURAS:
    · o corpo tem 72mm, não 80mm — a cabeça térmica de uma bobina de 80mm não
      cobre a largura toda, e o que passa de ~72mm sai cortado;
    · a margem da página é 0 — o respiro da esquerda vem do padding interno dos
      campos; somar margem de página empurra tudo para a direita e corta o fim
      das linhas.
--}}
@php
  $navegador = $navegador ?? true;
  $fmt = fn ($v, $c = 2) => $v === null ? '—' : number_format((float) $v, $c, ',', '.');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $titulo }} {{ $doc->numeroFormatado() }}</title>
<style>
  @page { size: 80mm auto; margin: 0; }

  /* print-color-adjust é o que faz as faixas cinza saírem impressas: por
     padrão o navegador descarta fundos na impressão e elas vinham em branco. */
  body { margin: 0; width: 72mm; color: #000; font-size: 9px; line-height: 1.35;
         font-family: 'Courier New', Courier, monospace;
         -webkit-print-color-adjust: exact; print-color-adjust: exact; }

  .cab { text-align: center; padding: 4px 3px 2px; }
  .cab img { width: 34px; height: auto; margin-bottom: 2px; }
  .cab .org { font-size: 8px; font-weight: bold; line-height: 1.25; }
  .cab .end { font-size: 7px; }
  .cab .tit { font-size: 12px; font-weight: bold; margin-top: 4px; letter-spacing: .05em; }
  .cab .num { font-size: 11px; font-weight: bold; }

  .faixa { background: #C8C8C8; font-size: 8px; font-weight: bold; letter-spacing: .06em;
           padding: 2px 4px; margin: 5px 0 3px; text-transform: uppercase; }

  .campo { padding: 0 4px 3px; }
  .campo .lbl { font-size: 7px; text-transform: uppercase; letter-spacing: .04em; }
  .campo .val { font-size: 9.5px; font-weight: bold; word-wrap: break-word; }

  .par { padding: 0 4px 3px; text-align: justify; }
  .par b { font-weight: bold; }

  .calc { width: 100%; border-collapse: collapse; font-size: 8px; }
  .calc td { padding: 1px 4px; vertical-align: top; }
  .calc td.dir { text-align: right; white-space: nowrap; }
  .calc tr.total td { border-top: 1px solid #000; font-weight: bold; font-size: 9px; padding-top: 2px; }

  .ass { padding: 12px 4px 0; text-align: center; }
  .ass img { max-height: 34px; max-width: 90%; display: block; margin: 0 auto; }
  .ass .vazio { height: 26px; }
  .ass .linha { border-top: 1px solid #000; font-size: 7.5px; padding-top: 2px; margin-top: 2px; }

  .anexo { padding: 3px 4px; }
  .anexo img { width: 100%; height: auto; }
  .anexo .t { font-size: 8px; font-weight: bold; }
  .anexo .o { font-size: 7px; }

  .rodape { text-align: center; font-size: 6.5px; padding: 6px 4px 14px; line-height: 1.3; }

  .marca { position: fixed; top: 40%; left: 50%; transform: translate(-50%,-50%) rotate(-30deg);
           font-size: 28px; font-weight: bold; color: rgba(0,0,0,.18); letter-spacing: 3px;
           white-space: nowrap; z-index: 9999; }

@if ($navegador)
  .imp-barra { position: fixed; left: 0; right: 0; bottom: 0; background: #fff;
               border-top: 1px solid #ddd; padding: 10px 14px; z-index: 99999;
               font-family: Arial, sans-serif; }
  .imp-barra button { width: 48%; border: 0; border-radius: 999px; padding: 12px 0;
                      font-size: 15px; font-weight: bold; font-family: Arial, sans-serif; }
  .imp-fechar { background: #ECEFF1; color: #37474F; }
  .imp-print { background: #006B28; color: #fff; }
  body { padding-bottom: 62px; }
  @media print { .imp-barra { display: none; } body { padding-bottom: 0; width: 72mm; } }
@endif
</style>
</head>
<body>

@if ($marca)
  <div class="marca">{{ $marca }}</div>
@endif

<div class="cab">
  @if ($brasao)
    <img src="{{ $brasao }}" alt="">
  @endif
  <div class="org">{{ $orgao['secretaria'] }}</div>
  <div class="org">{{ $orgao['nome'] }}</div>
  @if ($orgao['divisao'])
    <div class="end">{{ $orgao['divisao'] }}</div>
  @endif
  <div class="end">{{ collect([$orgao['endereco'], $orgao['telefone']])->filter()->implode(' – ') }}</div>
  <div class="tit">{{ $titulo }}</div>
  <div class="num">{{ $doc->numeroFormatado() }}</div>
</div>

<div class="campo">
  <div class="lbl">Agente / Matrícula</div>
  <div class="val">{{ $doc->agente?->name }}{{ $doc->agente?->matricula ? ' — ' . $doc->agente->matricula : '' }}</div>
</div>
<div class="campo">
  <div class="lbl">Fato</div>
  <div class="val">{{ $doc->data_fato?->format('d/m/Y H:i') ?? '—' }}</div>
</div>
<div class="campo">
  <div class="lbl">Lavratura</div>
  <div class="val">{{ $doc->data_lavratura?->format('d/m/Y H:i') ?? '—' }}</div>
</div>
@if ($origemTexto !== 'DIRETA')
  <div class="campo">
    <div class="lbl">Origem</div>
    <div class="val">{{ $origemTexto }}</div>
  </div>
@endif

<div class="faixa">Autuado</div>
<div class="campo">
  <div class="lbl">Nome</div>
  <div class="val">{{ $doc->autuado_nome ?: '—' }}</div>
</div>
<div class="campo">
  <div class="lbl">CPF / CNPJ</div>
  <div class="val">{{ $doc->autuado_documento ?: '—' }}</div>
</div>

<div class="faixa">Imóvel</div>
<div class="campo">
  <div class="lbl">Inscrição imobiliária</div>
  <div class="val">{{ $imovel['inscricao'] ?: '—' }}</div>
</div>
<div class="campo">
  <div class="lbl">Bairro / Quadra / Lote</div>
  <div class="val">{{ $imovel['bairro'] ?: '—' }} · Q {{ $imovel['quadra'] ?? '—' }} · Lt {{ $imovel['lote'] ?? '—' }}</div>
</div>
@if ($imovel['endereco'])
  <div class="campo">
    <div class="lbl">Endereço</div>
    <div class="val">{{ $imovel['endereco'] }}</div>
  </div>
@endif
@if ($doc->area_terreno_m2 || $doc->area_construida_m2)
  <div class="campo">
    <div class="lbl">Áreas</div>
    <div class="val">
      Terreno {{ $doc->area_terreno_m2 ? $fmt($doc->area_terreno_m2) . ' m²' : '—' }} ·
      Construída {{ $doc->area_construida_m2 ? $fmt($doc->area_construida_m2) . ' m²' : '—' }}
    </div>
  </div>
@endif

@if ($doc->descricao)
  <div class="faixa">Constatação</div>
  <div class="par">{{ $doc->descricao }}</div>
@endif

@if ($doc->exigeFundamentacao())
  <div class="faixa">Legislação Infringida</div>
  @if ($doc->legislacao)
    <div class="par"><b>{{ $doc->legislacao->rotulo() }}</b></div>
  @endif
  @forelse ($doc->artigos as $a)
    <div class="par"><b>Art. {{ preg_replace('/^Art\.?\s*/i', '', $a->numero) }}.</b> {{ $a->conduta }}</div>
  @empty
    <div class="par">—</div>
  @endforelse

  {{-- Olha o valor, não os artigos: a notificação cita artigo mas não impõe
       multa. Ver o mesmo raciocínio em impressao/a4.blade.php. --}}
  @if ($memoria['total'] !== null)
    <div class="faixa">Multa — Memória de Cálculo</div>
    <table class="calc">
      @foreach ($memoria['linhas'] as $l)
        <tr>
          <td>
            <b>{{ $l['numero'] }}</b><br>{{ $l['conta'] }}
            @if ($l['limite'])<br>({{ $l['limite'] }})@endif
          </td>
          <td class="dir">{{ $l['valor'] !== null ? $fmt($l['valor']) . ' UPF' : '—' }}</td>
        </tr>
      @endforeach
      @if ($memoria['total'] !== null)
        <tr class="total">
          <td>TOTAL</td>
          <td class="dir">
            {{ $fmt($memoria['total']) }} UPF
            @if ($memoria['emReais'])<br>R$ {{ $fmt($memoria['emReais']) }}@endif
          </td>
        </tr>
      @endif
    </table>
  @endif
@endif

@if ($prazo)
  <div class="faixa">{{ $prazo['rotulo'] }}</div>
  <div class="par"><b>Até {{ $prazo['data'] }}.</b> {{ $prazo['nota'] }}</div>
@endif

@if ($ciencia)
  <div class="faixa">Ciência / Intimação</div>
  <div class="par">{{ $ciencia }}</div>
@endif

@if ($doc->observacoes)
  <div class="faixa">Observações</div>
  <div class="par">{{ $doc->observacoes }}</div>
@endif

<div class="ass">
  @if ($doc->assinatura_agente)
    <img src="{{ $doc->assinatura_agente }}" alt="">
  @else
    <div class="vazio"></div>
  @endif
  <div class="linha">Fiscal{{ $doc->agente?->matricula ? ' — Matrícula: ' . $doc->agente->matricula : '' }}</div>
</div>

<div class="ass">
  @if ($doc->assinatura_autuado && ! $doc->recusa_assinatura)
    <img src="{{ $doc->assinatura_autuado }}" alt="">
  @else
    <div class="vazio"></div>
  @endif
  <div class="linha">Autuado / Preposto{{ $doc->autuado_documento ? ' — ' . $doc->autuado_documento : '' }}</div>
</div>

@if ($doc->recusa_assinatura)
  <div class="faixa">Termo de Recusa</div>
  <div class="par">{{ $termoRecusa }}</div>
  <div class="par"><b>Registro do agente:</b> {{ $doc->recusa_assinatura }}</div>
@endif

@if (count($anexos))
  <div class="faixa">Anexos</div>
  @foreach ($anexos as $a)
    <div class="anexo">
      @if ($a['foto'])
        <img src="{{ $a['src'] }}" alt="">
      @endif
      <div class="t">{{ $a['titulo'] ?: '—' }}</div>
      @if ($a['descricao'] || $a['dataHora'])
        <div class="o">{{ collect([$a['dataHora'], $a['descricao']])->filter()->implode(' · ') }}</div>
      @endif
    </div>
  @endforeach
@endif

<div class="rodape">
  @foreach ($rodape as $linha)
    <div>{{ $linha }}</div>
  @endforeach
  <div>Emitido pelo Sistema Municipal de Fiscalização de Obras</div>
</div>

@if ($navegador)
  <div class="imp-barra">
    <button type="button" class="imp-fechar" onclick="window.close()">Fechar</button>
    <button type="button" class="imp-print" onclick="window.print()">Imprimir</button>
  </div>
  <script>
    window.addEventListener('load', () => setTimeout(() => window.print(), 400))
  </script>
@endif

</body>
</html>
