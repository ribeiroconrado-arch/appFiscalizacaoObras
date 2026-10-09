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
  $numeroSecao = 0;
  $sec = function ($titulo) use (&$numeroSecao) { return (++$numeroSecao).' - '.$titulo; };
  $notificacao = in_array($doc->tipo, \App\Models\Documento::COM_CUMPRIMENTO, true);
  $destinatario = $notificacao ? 'Notificado' : ($doc->exigeFundamentacao() ? 'Autuado' : 'Interessado');
  $textoDestinatario = fn ($texto) => $notificacao
      ? str_replace(['AUTUADO', 'Autuado', 'autuado'], ['NOTIFICADO', 'Notificado', 'notificado'], $texto ?? '') : ($texto ?? '');
  $numero = $doc->numero ? sprintf('%d/%04d', $doc->exercicio, $doc->numero) : 'Sem número';
  $contato = collect([$orgao['endereco'] ?? null, $orgao['municipio'] ?? null, $orgao['telefone'] ?? null,
      empty($orgao['cnpj']) ? null : 'CNPJ: '.$orgao['cnpj']])->filter()->implode(' · ');
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

  * { box-sizing: border-box; }
  .cab { display: flex; align-items: center; gap: 5px; text-align: center; padding: 4px 3px 2px; }
  .cab img { width: 38px; height: auto; flex-shrink: 0; }
  .cab .instituicao { flex: 1; min-width: 0; overflow-wrap: anywhere; }
  .cab .org { font-size: 8px; font-weight: bold; line-height: 1.25; }
  .cab .end { font-size: 7px; }
  .tit { background: #C8C8C8; font-size: 10px; font-weight: bold; text-align: center; padding: 3px; }
  .grade { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .grade.meta { grid-template-columns: .6fr .9fr 1.5fr; margin-top: 3px; }
  .grade .campo { min-width: 0; }
  .meta .val { font-size: 8px; }

  .faixa { background: #C8C8C8; font-size: 8px; font-weight: bold; letter-spacing: .06em;
           padding: 2px 4px; margin: 5px 0 3px; text-transform: uppercase; }

  .campo { padding: 0 4px 3px; }
  .campo .lbl { font-size: 7px; text-transform: uppercase; letter-spacing: .04em; }
  .campo .val { font-size: 9px; overflow-wrap: anywhere; }
  .meta .val, .datas .val { font-weight: bold; }

  .par { padding: 0 4px 3px; text-align: left; overflow-wrap: anywhere; }
  .par b { font-weight: bold; }

  .calc { width: 100%; border-collapse: collapse; font-size: 8px; }
  .calc td { padding: 1px 4px; vertical-align: top; }
  .calc td.dir { text-align: right; white-space: nowrap; }
  .calc tr.total td { border-top: 1px solid #000; font-weight: bold; font-size: 9px; padding-top: 2px; }
  .infracao { margin: 0 4px; padding: 3px 0; border-bottom: 1px solid #ccc; overflow-wrap: anywhere; }
  .infracao .par { padding: 0; }
  .calculo { display: flex; justify-content: space-between; gap: 5px; font-size: 7.5px; margin-top: 2px; }
  .calculo strong { white-space: nowrap; }
  .assinaturas { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; padding: 0 4px; }
  .assinaturas .ass { padding-left: 0; padding-right: 0; min-width: 0; overflow-wrap: anywhere; }

  .ass { padding: 12px 4px 0; text-align: center; }
  .ass img { max-height: 34px; max-width: 90%; display: block; margin: 0 auto; }
  .ass .vazio { height: 26px; }
  .ass .linha { border-top: 1px solid #000; font-size: 7.5px; padding-top: 2px; margin-top: 2px; }

  .anexo { padding: 3px 4px; }
  .anexo img { width: 100%; height: auto; }
  .anexo .t { font-size: 8px; font-weight: bold; }
  .anexo .o { font-size: 7px; }

  .rodape { text-align: center; font-size: 7px; padding: 5px 4px 4px; line-height: 1.3; overflow-wrap: anywhere; border-top: 1px dotted #777; margin-top: 5px; }

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
  <div class="instituicao">
  <div class="org">{{ $orgao['secretaria'] }}</div>
  <div class="org">{{ $orgao['nome'] }}</div>
  @if (!empty($orgao['departamento']))<div class="end">{{ $orgao['departamento'] }}</div>@endif
  @if ($orgao['divisao'])
    <div class="end">{{ $orgao['divisao'] }}</div>
  @endif
  <div class="end">{{ collect([$orgao['endereco'], $orgao['telefone']])->filter()->implode(' – ') }}</div>
  </div>
</div>
<div class="tit">{{ $titulo }} · Nº {{ $numero }}</div>

<div class="grade meta">
<div class="campo"><div class="lbl">Exercício</div><div class="val">{{ $doc->exercicio ?: '—' }}</div></div>
<div class="campo">
  <div class="lbl">Matrícula do agente</div>
  <div class="val">{{ $doc->agente?->matricula ?: '—' }}</div>
</div>
<div class="campo"><div class="lbl">Origem</div><div class="val">{{ $origemTexto }}</div></div>
</div>
<div class="grade datas">
<div class="campo">
  <div class="lbl">Fato</div>
  <div class="val">{{ $doc->data_fato?->format('d/m/Y H:i') ?? '—' }}</div>
</div>
<div class="campo">
  <div class="lbl">Lavratura</div>
  <div class="val">{{ $doc->data_lavratura?->format('d/m/Y H:i') ?? '—' }}</div>
</div>
</div>

<div class="faixa">{{ $sec('Identificação do '.mb_strtolower($destinatario)) }}</div>
<div class="campo">
  <div class="lbl">Nome</div>
  <div class="val">{{ $doc->autuado_nome ?: '—' }}</div>
</div>
<div class="campo">
  <div class="lbl">CPF / CNPJ</div>
  <div class="val">{{ $doc->autuado_documento ?: '—' }}</div>
</div>
<div class="campo">
  <div class="lbl">Endereço</div>
  <div class="val">{{ \App\Services\DocumentoImpressao::enderecoDestinatario($doc) }}</div>
</div>

<div class="faixa">{{ $sec('Local da infração e identificação do imóvel') }}</div>
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
  <div class="par"><b>Constatação:</b> {{ $doc->descricao }}</div>
@endif

@if ($doc->exigeFundamentacao())
  <div class="faixa">{{ $sec($memoria['total'] !== null ? 'Infração / legislação infringida / multa e penalidade' : 'Infração / legislação infringida') }}</div>
  <div class="par"><b>Lei infringida:</b> {{ $doc->legislacao?->rotulo() ?: '—' }}</div>
  @forelse ($memoria['linhas'] as $l)
    <div class="infracao">
      <div class="par"><b>Art. {{ preg_replace('/^Art\.?\s*/i', '', $l['numero']) }}.</b> {{ $l['conduta'] ?: '—' }}@if ($l['sancao']) {{ $l['sancao'] }}@endif</div>
      @if ($memoria['total'] !== null)
        <div class="calculo">
          <span>{{ $l['base'] === 'Valor fixo' ? $l['base'] : $l['conta'] }}@if ($l['limite']) · {{ $l['limite'] }}@endif</span>
          <strong>{{ $l['valor'] !== null ? $fmt($l['valor']).' UPF' : '—' }}</strong>
        </div>
      @endif
    </div>
  @empty
    <div class="par">—</div>
  @endforelse
@endif

<div class="faixa">{{ $sec('Ciência / Intimação') }}</div>
@if ($ciencia)
  {{-- Texto escapado pelo serviço, permitindo apenas o negrito configurado. --}}
  <div class="par">{!! $textoDestinatario($ciencia) !!}</div>
@endif
@if ($prazo)
  <div class="par"><b>{{ $prazo['rotulo'] }}: {{ $prazo['data'] }}.</b> {{ $prazo['nota'] }}</div>
@endif
@if ($doc->observacoes)
  <div class="par"><b>Observações:</b> {{ $doc->observacoes }}</div>
@endif

<div class="assinaturas">
<div class="ass">
  @if ($doc->assinatura_agente)
    <img src="{{ $doc->assinatura_agente }}" alt="">
  @else
    <div class="vazio"></div>
  @endif
  <div class="linha">{{ $doc->agente?->name }}<br>Fiscal{{ $doc->agente?->matricula ? ' — Matrícula: ' . $doc->agente->matricula : '' }}</div>
</div>

<div class="ass">
  @if ($doc->assinatura_autuado && ! $doc->recusa_assinatura)
    <img src="{{ $doc->assinatura_autuado }}" alt="">
  @else
    <div class="vazio"></div>
  @endif
  <div class="linha">{{ $doc->autuado_nome }}<br>{{ $destinatario }} / Preposto</div>
</div>
</div>

@if ($doc->recusa_assinatura)
  <div class="faixa">{{ $sec('Termo de Recusa') }}</div>
  <div class="par">{{ $textoDestinatario($termoRecusa) }}</div>
  @if ($doc->testemunha_nome)
    <div class="ass">
      @if ($doc->assinatura_testemunha)
        <img src="{{ $doc->assinatura_testemunha }}" alt="">
      @else
        <div class="vazio"></div>
      @endif
      <div class="linha">{{ $doc->testemunha_nome }} — Testemunha</div>
    </div>
  @else
    <div class="par"><b>Registro do agente:</b> {{ $doc->recusa_assinatura }}</div>
  @endif
@endif

@if (count($anexos))
  <div class="faixa">{{ $sec('Anexos') }}</div>
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
  @if ($contato)<div>{{ $contato }}</div>@endif
  @if ($doc->status !== 'rascunho')
    <div>
      @if ($doc->cadastro_consultado_em)
        Dados cadastrais integrados em {{ $doc->cadastro_consultado_em->format('d/m/Y') }}.
      @else
        Lavrado sem dado do cadastro municipal.
      @endif
    </div>
  @endif
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
