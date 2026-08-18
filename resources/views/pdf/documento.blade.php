<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $doc->numeroFormatado() }}</title>
<style>
  {{-- Dompdf usa um motor CSS próprio, bem mais limitado que um navegador:
       nada de flexbox/grid confiável, fontes seguras e tabelas para layout.
       Por isso este arquivo não reaproveita o tema-f.css do app. --}}
  @page { margin: 90px 36px 70px 36px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #222; }
  header { position: fixed; top: -70px; left: 0; right: 0; height: 70px;
    border-bottom: 2px solid #B4470D; padding-bottom: 8px; }
  header .orgao { font-size: 13px; font-weight: bold; color: #B4470D; }
  header .secretaria { font-size: 10px; color: #555; }
  header .numero { position: absolute; top: 0; right: 0; text-align: right;
    font-size: 14px; font-weight: bold; color: #222; }
  header .numero span { display: block; font-size: 9px; font-weight: normal; color: #777; }
  footer { position: fixed; bottom: -60px; left: 0; right: 0; height: 50px;
    border-top: 1px solid #ccc; padding-top: 6px; font-size: 8.5px; color: #888; }

  h1 { font-size: 15px; text-align: center; margin: 4px 0 2px; text-transform: uppercase; letter-spacing: .04em; }
  .subtitulo { text-align: center; font-size: 10px; color: #666; margin-bottom: 16px; }

  .rascunho-marca { position: fixed; top: 300px; left: 90px; font-size: 90px; color: #f0c9a8;
    transform: rotate(-30deg); z-index: -1; }

  table.dados { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  table.dados td { padding: 3px 6px; vertical-align: top; border: 1px solid #ddd; }
  table.dados td.rot { width: 120px; background: #FAF7F4; font-size: 9px; text-transform: uppercase;
    color: #777; letter-spacing: .03em; }

  .sec { font-size: 11px; font-weight: bold; color: #B4470D; text-transform: uppercase;
    letter-spacing: .03em; border-bottom: 1px solid #eee; margin: 14px 0 6px; padding-bottom: 3px; }

  table.artigos { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  table.artigos th { background: #FFF2E8; font-size: 9px; text-transform: uppercase; color: #B4470D;
    text-align: left; padding: 5px 6px; border: 1px solid #EAE1D9; }
  table.artigos td { padding: 5px 6px; border: 1px solid #EAE1D9; font-size: 10px; vertical-align: top; }
  table.artigos td.valor { text-align: right; white-space: nowrap; }
  .total-linha td { font-weight: bold; background: #FAF7F4; }

  .texto-corrido { line-height: 1.5; text-align: justify; margin-bottom: 8px; }
  .ciencia { border: 1px solid #ddd; padding: 8px 10px; background: #FAFAFA; font-size: 10px;
    line-height: 1.5; text-align: justify; margin-top: 10px; }

  .assinaturas { width: 100%; margin-top: 46px; }
  .assinaturas td { width: 50%; text-align: center; vertical-align: bottom; padding: 0 20px; }
  .linha-assinatura { border-top: 1px solid #333; margin-top: 40px; padding-top: 4px; font-size: 9px; }
  .assinatura-img { max-height: 46px; max-width: 90%; }
  .recusa { font-size: 9px; color: #C2352B; margin-top: 4px; }
</style>
</head>
<body>

@if ($doc->status === 'rascunho')
  <div class="rascunho-marca">RASCUNHO</div>
@endif

<header>
  <div class="orgao">{{ $orgaoNome }}</div>
  <div class="secretaria">{{ $orgaoSecretaria }}</div>
  <div class="numero">
    {{ $doc->numeroFormatado() }}
    <span>{{ ($doc->data_lavratura ?? $doc->created_at)->format('d/m/Y') }}</span>
  </div>
</header>

<footer>
  {{ $orgaoSecretaria }}{{ $orgaoEndereco ? ' · ' . $orgaoEndereco : '' }}{{ $orgaoTelefone ? ' · ' . $orgaoTelefone : '' }}
  — Documento emitido pelo Sistema Municipal de Fiscalização de Obras.
</footer>

<h1>{{ $doc->rotuloTipo() }}</h1>
<div class="subtitulo">{{ $doc->numeroFormatado() }}</div>

<div class="sec">Identificação do imóvel</div>
<table class="dados">
  <tr>
    <td class="rot">Bairro / Loteamento</td><td>{{ $doc->lote->bairro }}</td>
    <td class="rot">Quadra / Lote</td><td>{{ $doc->lote->quadra ?? '—' }} / {{ $doc->lote->numero_lote ?? '—' }}</td>
  </tr>
  <tr>
    <td class="rot">Inscrição imobiliária</td><td>{{ $doc->lote->inscricao_imobiliaria ?? '—' }}</td>
    <td class="rot">Área do terreno</td><td>{{ $doc->area_terreno_m2 ? number_format($doc->area_terreno_m2, 2, ',', '.') . ' m²' : '—' }}</td>
  </tr>
  @if ($doc->area_construida_m2)
  <tr>
    <td class="rot">Área construída</td><td colspan="3">{{ number_format($doc->area_construida_m2, 2, ',', '.') }} m² (medida em campo)</td>
  </tr>
  @endif
</table>

<div class="sec">Autuado / Interessado</div>
<table class="dados">
  <tr>
    <td class="rot">Nome</td><td>{{ $doc->autuado_nome ?: '—' }}</td>
    <td class="rot">CPF/CNPJ</td><td>{{ $doc->autuado_documento ?: '—' }}</td>
  </tr>
  @if ($doc->endereco)
  <tr><td class="rot">Endereço</td><td colspan="3">{{ $doc->endereco }}</td></tr>
  @endif
</table>

<div class="sec">Fato e fundamentação legal</div>
<table class="dados">
  <tr>
    <td class="rot">Data do fato</td><td>{{ $doc->data_fato?->format('d/m/Y H:i') ?? '—' }}</td>
    <td class="rot">Legislação</td><td>{{ $doc->legislacao?->rotulo() ?? '—' }}</td>
  </tr>
</table>

@if ($doc->descricao)
  <p class="texto-corrido">{{ $doc->descricao }}</p>
@endif

@if ($doc->artigos->isNotEmpty())
<table class="artigos">
  <thead>
    <tr><th>Artigo</th><th>Conduta / Sanção</th><th>Base de cálculo</th><th style="text-align:right">Valor</th></tr>
  </thead>
  <tbody>
    @foreach ($doc->artigos as $a)
    <tr>
      <td>{{ $a->numero }}</td>
      <td>{{ $a->conduta }}@if($a->sancao)<br><i>{{ $a->sancao }}</i>@endif</td>
      <td>
        @if ($a->base_multa === 'fixa') Valor fixo
        @elseif ($a->base_multa === 'sem_multa') Sem multa
        @else
          {{ number_format($a->multa_upf_m2, 4, ',', '.') }} UPF/m²
          @if ($a->area_m2) × {{ number_format($a->area_m2, 2, ',', '.') }} m² @endif
        @endif
      </td>
      <td class="valor">{{ $a->valor_upf !== null ? number_format($a->valor_upf, 2, ',', '.') . ' UPF' : '—' }}</td>
    </tr>
    @endforeach
    @if ($doc->valor_upf)
    <tr class="total-linha">
      <td colspan="3">Total da multa
        @if ($doc->upf_valor) — UPF do exercício: {{ number_format($doc->upf_valor, 4, ',', '.') }} @endif
      </td>
      <td class="valor">
        {{ number_format($doc->valor_upf, 2, ',', '.') }} UPF
        @if ($doc->upf_valor)
          <br><span style="font-weight:normal;font-size:9px">
            R$ {{ number_format($doc->valor_upf * $doc->upf_valor, 2, ',', '.') }}
          </span>
        @endif
      </td>
    </tr>
    @endif
  </tbody>
</table>
@endif

<div class="sec">Prazos</div>
<table class="dados">
  @if ($doc->defesa_ate)
  <tr><td class="rot">Prazo de defesa</td><td colspan="3">Até {{ $doc->defesa_ate->format('d/m/Y') }} (dias úteis, contados da lavratura)</td></tr>
  @elseif ($doc->prazo_ate)
  <tr><td class="rot">Prazo de cumprimento</td><td colspan="3">Até {{ $doc->prazo_ate->format('d/m/Y') }}</td></tr>
  @else
  <tr><td class="rot">Prazo</td><td colspan="3">Não aplicável a este documento</td></tr>
  @endif
</table>

@if ($textoCiencia)
  <div class="ciencia">{{ $textoCiencia }}</div>
@endif

@if ($doc->observacoes)
  <div class="sec">Observações</div>
  <p class="texto-corrido">{{ $doc->observacoes }}</p>
@endif

<table class="assinaturas">
  <tr>
    <td>
      @if ($doc->assinatura_agente)
        <img class="assinatura-img" src="{{ $doc->assinatura_agente }}">
      @endif
      <div class="linha-assinatura">
        {{ $doc->agente->name }}<br>Agente de fiscalização
      </div>
    </td>
    <td>
      @if ($doc->assinatura_autuado)
        <img class="assinatura-img" src="{{ $doc->assinatura_autuado }}">
      @endif
      <div class="linha-assinatura">
        {{ $doc->autuado_nome ?: 'Autuado / Interessado' }}
      </div>
      @if ($doc->recusa_assinatura)
        <div class="recusa">Recusou assinar: {{ $doc->recusa_assinatura }}</div>
      @endif
    </td>
  </tr>
</table>

</body>
</html>
