{{--
  Layout OFICIAL A4 — usado por dois destinos:
    · dompdf, em GET /documentos/{id}/pdf
    · janela de impressão do navegador, em GET /documentos/{id}/impressao

  Por isso é escrito no subconjunto de CSS que o dompdf entende: tabelas para
  diagramar, nada de flexbox nem grid, fontes seguras. O cabeçalho repetido em
  todas as páginas vem de <thead> — que funciona nos dois motores — e não de
  position:fixed, que no navegador reserva espaço só na primeira página e
  passa a cobrir o conteúdo da segunda em diante.

  Estrutura herdada do AppPOSTURAS: cabeçalho institucional com brasão, faixa
  do topo com exercício/matrícula/origem/datas, seções NUMERADAS em sequência,
  assinaturas, termo de recusa (condicional) e anexos (condicional).
--}}
@php
  /** Numeração das seções. Calculada, não fixa: "Constatação", "Termo de
      Recusa" e "Anexos" só existem em certos documentos, e a numeração tem de
      fechar mesmo assim.

      Closure com `use (&$n)`, e não arrow function: a arrow function captura
      o escopo por VALOR, então `++$n` incrementaria uma cópia a cada chamada
      e todas as seções sairiam numeradas "1". */
  $n = 0;
  $sec = function (string $titulo) use (&$n) {
      return (++$n) . ' – ' . $titulo;
  };

  $navegador = $navegador ?? false;
  $fmt = fn ($v, $c = 2) => $v === null ? '—' : number_format((float) $v, $c, ',', '.');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $titulo }} {{ $doc->numeroFormatado() }}</title>
<style>
  @page { size: A4; margin: 8mm 10mm 10mm 10mm; }

  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5px; color: #111;
         line-height: 1.45; margin: 0; }

  /* ── Cabeçalho institucional (repete em toda página) ── */
  table.pagina { width: 100%; border-collapse: collapse; }
  /* O `>` até o `td` é obrigatório: sem ele, "qualquer td dentro do invólucro"
     alcança as células de TODAS as tabelas aninhadas — e, por ter mais
     elementos no seletor, vence as regras próprias delas. Era o que zerava, em
     silêncio, a moldura e o respiro da faixa do topo, da tabela de dias e dos
     campos de assinatura: o CSS estava escrito, e nunca chegava a valer. */
  table.pagina > thead > tr > td, table.pagina > tbody > tr > td { padding: 0; border: 0; }

@include('impressao._cabecalho-css')

  /* ── Seções ── */
  .sec { margin-bottom: 9px; }
  .sec-tit { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em;
             background: #ECECEC; border: 1px solid #bbb; padding: 3px 6px; margin-bottom: 5px; }
  .sec p { margin: 0 0 5px; text-align: justify; }

  table.campos { width: 100%; border-collapse: collapse; }
  table.campos td { border: 1px solid #ddd; padding: 3px 5px; vertical-align: top; }
  table.campos .lbl { display: block; font-size: 7.5px; color: #666; text-transform: uppercase;
                      letter-spacing: .04em; }

  /* ── Memória de cálculo da multa (específico de obras) ── */
  table.multa { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  table.multa th { background: #ECECEC; border: 1px solid #bbb; padding: 4px 5px; font-size: 8px;
                   text-transform: uppercase; letter-spacing: .04em; text-align: left; }
  table.multa td { border: 1px solid #ddd; padding: 4px 5px; font-size: 9.5px; vertical-align: top; }
  table.multa td.dir { text-align: right; white-space: nowrap; }
  table.multa .conduta { color: #444; font-size: 9px; }
  table.multa .limite { color: #8a4b00; font-size: 8.5px; }
  table.multa tr.total td { background: #F4F4F4; font-weight: bold; }
  .total-reais { font-weight: normal; font-size: 8.5px; color: #444; }

  /* ── Assinaturas ── */
  table.assina { width: 100%; border-collapse: collapse; margin: 4px 0 12px; page-break-inside: avoid; }
  /* `padding-top` afasta uma fila de assinaturas da anterior — sem ele, o
     nome de quem assina em cima encosta na linha de quem assina embaixo. */
  table.assina td { width: 50%; text-align: center; vertical-align: bottom;
                    padding: 18px 22px 2px; border: 0; }
  table.assina tr:first-child td { padding-top: 2px; }
  /* O traço vem aparado do banco (App\Services\Assinatura): a altura da
     imagem é a altura da assinatura, sem a margem vazia do canvas. Por isso o
     limite pode subir — antes, esticar só esticava o vazio em volta. */
  .assina-img { max-height: 62px; max-width: 100%; width: auto; }
  .assina-vazio { height: 62px; }
  .assina-linha { border-top: 1px solid #111; padding-top: 3px; margin-top: 3px; font-size: 8.5px; }

  /* ── Anexos ── */
  .anexo { page-break-inside: avoid; margin-bottom: 10px; }
  .anexo img { max-width: 100%; max-height: 300px; border: 1px solid #ccc; }
  .anexo-tit { font-size: 9.5px; font-weight: bold; margin-top: 3px; }
  .anexo-obs { font-size: 8.5px; color: #555; }

  /* ── Marca d'água ── */
  .marca { position: fixed; top: 40%; left: 12%; font-size: 96px; font-weight: bold;
           color: #E4E4E4; letter-spacing: 8px; transform: rotate(-28deg); z-index: -1; }

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

@if ($marca)
  <div class="marca">{{ $marca }}</div>
@endif

<table class="pagina">
  <thead>
    <tr><td>
      @include('impressao._cabecalho', ['numero' => $doc->numeroFormatado()])
    </td></tr>
  </thead>

  <tbody>
    <tr><td>

      {{-- Faixa do topo: identifica quem lavrou e quando. Fica no tbody de
           propósito — só faz sentido na primeira página. --}}
      <table class="topo">
        <tr>
          <td><span class="topo-lbl">Exercício</span><span class="topo-val">{{ $doc->exercicio ?? '—' }}</span></td>
          <td><span class="topo-lbl">Matrícula do agente</span><span class="topo-val">{{ $doc->agente?->matricula ?? '—' }}</span></td>
          <td><span class="topo-lbl">Origem</span><span class="topo-val">{{ $origemTexto }}</span></td>
          <td><span class="topo-lbl">Data/hora do fato</span><span class="topo-val">{{ $doc->data_fato?->format('d/m/y H:i') ?? '—' }}</span></td>
          <td><span class="topo-lbl">Data/hora da lavratura</span><span class="topo-val">{{ $doc->data_lavratura?->format('d/m/y H:i') ?? '—' }}</span></td>
        </tr>
      </table>
      <div class="topo-regua"></div>

      {{-- 1 — Autuado --}}
      <div class="sec">
        <div class="sec-tit">{{ $sec($doc->exigeFundamentacao() ? 'Autuado / Interessado' : 'Interessado') }}</div>
        <table class="campos">
          <tr>
            <td colspan="2"><span class="lbl">Nome / Razão social</span>{{ $doc->autuado_nome ?: '—' }}</td>
            <td><span class="lbl">CPF / CNPJ</span>{{ $doc->autuado_documento ?: '—' }}</td>
          </tr>
        </table>
      </div>

      {{-- 2 — Imóvel. Em obras o objeto do ato é o LOTE, identificado pela
           inscrição imobiliária: é por ela que o processo é indexado e é ela
           que amarra o documento ao cadastro do município. --}}
      <div class="sec">
        <div class="sec-tit">{{ $sec('Identificação do Imóvel') }}</div>
        <table class="campos">
          <tr>
            <td colspan="2"><span class="lbl">Inscrição imobiliária</span>{{ $imovel['inscricao'] ?: '—' }}</td>
            <td><span class="lbl">Bairro / Loteamento</span>{{ $imovel['bairro'] ?: '—' }}</td>
            <td><span class="lbl">Quadra / Lote</span>{{ $imovel['quadra'] ?? '—' }} / {{ $imovel['lote'] ?? '—' }}</td>
          </tr>
          <tr>
            <td colspan="2"><span class="lbl">Endereço</span>{{ $imovel['endereco'] ?: '—' }}</td>
            <td><span class="lbl">Área do terreno</span>{{ $doc->area_terreno_m2 ? $fmt($doc->area_terreno_m2) . ' m²' : '—' }}</td>
            <td><span class="lbl">Área construída aferida</span>{{ $doc->area_construida_m2 ? $fmt($doc->area_construida_m2) . ' m²' : '—' }}</td>
          </tr>
        </table>
      </div>

      {{-- 3 — Constatação --}}
      @if ($doc->descricao)
        <div class="sec">
          <div class="sec-tit">{{ $sec($doc->exigeFundamentacao() ? 'Constatação' : 'Constatação da Vistoria') }}</div>
          <p>{{ $doc->descricao }}</p>
        </div>
      @endif

      @if ($doc->exigeFundamentacao())
        {{-- 4 — Legislação infringida --}}
        <div class="sec">
          <div class="sec-tit">{{ $sec('Legislação Infringida') }}</div>
          @if ($doc->legislacao)
            <p><strong>{{ $doc->legislacao->rotulo() }}</strong></p>
          @endif
          @forelse ($doc->artigos as $a)
            <p><strong>Art. {{ preg_replace('/^Art\.?\s*/i', '', $a->numero) }}.</strong>
               {{ $a->conduta }}@if ($a->sancao) <em>{{ $a->sancao }}</em>@endif</p>
          @empty
            <p>—</p>
          @endforelse
        </div>

        {{-- 5 — Multa. A memória de cálculo é o que diferencia obras de
             posturas: multa por m² tem de mostrar a conta, senão o autuado
             não tem como conferir e o auto não se sustenta em defesa.

             A condição olha para o VALOR, não para a existência de artigos: a
             notificação também cita artigo, mas não impõe multa — ela manda
             regularizar. Uma notificação com seção de penalidade anuncia uma
             sanção que ela não aplica. --}}
        @if ($memoria['total'] !== null)
          <div class="sec">
            <div class="sec-tit">{{ $sec('Penalidade e Memória de Cálculo') }}</div>
            <table class="multa">
              <thead>
                <tr>
                  <th style="width:52px">Artigo</th>
                  <th>Enquadramento</th>
                  <th style="width:110px">Base de cálculo</th>
                  <th style="width:150px">Cálculo</th>
                  <th style="width:78px;text-align:right">Valor</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($memoria['linhas'] as $l)
                  <tr>
                    <td>{{ $l['numero'] }}</td>
                    <td><span class="conduta">{{ $l['conduta'] ?: '—' }}</span></td>
                    <td>{{ $l['base'] }}</td>
                    <td>
                      {{ $l['conta'] }}
                      @if ($l['limite'])<br><span class="limite">{{ $l['limite'] }}</span>@endif
                    </td>
                    <td class="dir">{{ $l['valor'] !== null ? $fmt($l['valor']) . ' UPF' : '—' }}</td>
                  </tr>
                @endforeach
                @if ($memoria['total'] !== null)
                  <tr class="total">
                    <td colspan="4">
                      Total da multa
                      @if ($memoria['upf'])
                        <span class="total-reais">— UPF do exercício: R$ {{ $fmt($memoria['upf'], 4) }}</span>
                      @endif
                    </td>
                    <td class="dir">
                      {{ $fmt($memoria['total']) }} UPF
                      @if ($memoria['emReais'])
                        <br><span class="total-reais">R$ {{ $fmt($memoria['emReais']) }}</span>
                      @endif
                    </td>
                  </tr>
                @endif
              </tbody>
            </table>
          </div>
        @endif
      @endif

      {{-- 6 — Prazo --}}
      @if ($prazo)
        <div class="sec">
          <div class="sec-tit">{{ $sec($prazo['rotulo']) }}</div>
          <p><strong>Até {{ $prazo['data'] }}.</strong> {{ $prazo['nota'] }}</p>
        </div>
      @endif

      {{-- 7 — Ciência --}}
      @if ($ciencia)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Ciência / Intimação') }}</div>
          <p>{{ $ciencia }}</p>
        </div>
      @endif

      @if ($doc->observacoes)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Observações') }}</div>
          <p>{{ $doc->observacoes }}</p>
        </div>
      @endif

      {{-- Assinaturas. A do autuado fica em branco quando houve recusa: nesse
           caso quem assina é a testemunha, na seção do Termo de Recusa. --}}
      <table class="assina">
        <tr>
          <td>
            @if ($doc->assinatura_agente)
              <img class="assina-img" src="{{ $doc->assinatura_agente }}" alt="">
            @else
              <div class="assina-vazio"></div>
            @endif
            <div class="assina-linha">
              {{-- O @if precisa vir depois de um caractere não-alfanumérico:
                   colado numa palavra, o Blade não reconhece a diretiva e ela
                   sai literal no HTML, quebrando o par com o @endif. --}}
              {{ $doc->agente?->name }} — Agente de fiscalização{{ $doc->agente?->matricula ? ', matrícula ' . $doc->agente->matricula : '' }}
            </div>
          </td>
          <td>
            @if ($doc->assinatura_autuado && ! $doc->recusa_assinatura)
              <img class="assina-img" src="{{ $doc->assinatura_autuado }}" alt="">
            @else
              <div class="assina-vazio"></div>
            @endif
            <div class="assina-linha">
              {{ $doc->autuado_nome ?: 'Autuado / Interessado' }}@if ($doc->autuado_documento) — {{ $doc->autuado_documento }}@endif
            </div>
          </td>
        </tr>
      </table>

      @if ($doc->recusa_assinatura)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Termo de Recusa') }}</div>
          <p>{{ $termoRecusa }}</p>
          <p><strong>Registro do agente:</strong> {{ $doc->recusa_assinatura }}</p>
        </div>
      @endif

      @if (count($anexos))
        <div class="sec">
          <div class="sec-tit">{{ $sec('Anexos') }}</div>
          @foreach ($anexos as $a)
            <div class="anexo">
              @if ($a['foto'])
                <img src="{{ $a['src'] }}" alt="">
              @endif
              <div class="anexo-tit">{{ $a['titulo'] ?: '—' }}</div>
              @if ($a['descricao'] || $a['dataHora'])
                <div class="anexo-obs">{{ collect([$a['dataHora'], $a['descricao']])->filter()->implode(' · ') }}</div>
              @endif
            </div>
          @endforeach
        </div>
      @endif

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
  <script>
    // Espera as fotos dos anexos carregarem: disparar print() antes disso
    // manda a página para a impressora com os quadros de imagem vazios.
    window.addEventListener('load', () => setTimeout(() => window.print(), 400))
  </script>
@endif

</body>
</html>
