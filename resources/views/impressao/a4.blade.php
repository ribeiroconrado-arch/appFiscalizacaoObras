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
  // Contar somente os grupos efetivamente exibidos, inclusive recusa e anexos.
  $numeroSecao = 0;
  $sec = function (string $titulo) use (&$numeroSecao) {
      return (++$numeroSecao) . ' - ' . $titulo;
  };
  $notificacao = in_array($doc->tipo, \App\Models\Documento::COM_CUMPRIMENTO, true);
  $destinatario = $notificacao ? 'Notificado' : ($doc->exigeFundamentacao() ? 'Autuado' : 'Interessado');
  $textoDestinatario = fn ($texto) => $notificacao
      ? str_replace(['AUTUADO', 'Autuado', 'autuado'], ['NOTIFICADO', 'Notificado', 'notificado'], $texto ?? '')
      : ($texto ?? '');
  $numeroA4 = $doc->numero ? sprintf('%d/%04d', $doc->exercicio, $doc->numero) : 'Sem número';
  $navegador = $navegador ?? false;
  $fmt = fn ($v, $c = 2) => $v === null ? '—' : number_format((float) $v, $c, ',', '.');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $titulo }} {{ $doc->numeroFormatado() }}</title>
<style>
  @page { size: A4; margin: 8mm 10mm 14mm 10mm; }
  @if ($navegador)
    @page {
      @bottom-left { content: "pág.: " counter(page) "/" counter(pages); font-family: Arial; font-size: 7pt; color: #555; border-top: 1px solid #bbb; }
      @bottom-right { content: "{{ $doc->rotuloTipo() }} {{ $numeroA4 }}"; font-family: Arial; font-size: 7pt; color: #555; border-top: 1px solid #bbb; }
    }
    @media print { .rodape-a4 { display: none; } }
  @endif

  body { font-family: Arial, Helvetica, sans-serif; font-size: 8pt; color: #111;
         line-height: 1.3; margin: 0; }

  /* ── Cabeçalho institucional (repete em toda página) ── */
  table.pagina { width: 100%; border-collapse: collapse; }
  /* O `>` até o `td` é obrigatório: sem ele, "qualquer td dentro do invólucro"
     alcança as células de TODAS as tabelas aninhadas — e, por ter mais
     elementos no seletor, vence as regras próprias delas. Era o que zerava, em
     silêncio, a moldura e o respiro da faixa do topo, da tabela de dias e dos
     campos de assinatura: o CSS estava escrito, e nunca chegava a valer. */
  table.pagina > thead > tr > td, table.pagina > tbody > tr > td { padding: 0; border: 0; }

@include('impressao._cabecalho-css')

  /* Ajustes exclusivos do A4 dos documentos; OS e bobina conservam seus modelos. */
  .cab-brasao { width: 64px; }
  .cab-brasao img { width: 54px; height: auto; }
  .cab-titulo, .cab-num-val { font-size: 14pt; }
  .cab-num-lbl { font-size: 7pt; }
  .cab-orgao { font-size: 8pt; }
  .cab-depto, .cab-end { font-size: 7pt; margin-top: 1px; }
  .cab-selo { font-size: 7pt; }
  @unless ($navegador)
    @page { margin-top: 29mm; }
    .cab-fixo { top: -21mm; height: 20mm; }
  @endunless
  .cab-regua { border-bottom: 1px solid #50565b; }
  .cab-num-lbl, .topo-lbl { font-weight: normal; }
  .cab-num-val, .topo-val { font-weight: bold; }
  table.topo { margin-top: 0; background: #f1f1f1; table-layout: fixed; }
  table.topo td { padding: 4px 3px; }
  table.topo td:last-child { padding-right: 6px; }
  .topo-lbl { font-size: 6pt; white-space: normal; }
  .topo-val { font-size: 7pt; }
  .topo-regua { border-bottom: 1px solid #50565b; margin-bottom: 8px; }

  /* ── Seções ── */
  .sec { margin-bottom: 6px; }
  .sec-tit { font-size: 8pt; font-weight: bold; text-transform: uppercase;
             border-bottom: 1px solid #d5d5d5; padding: 0 0 5px; margin: 10px 0 5px;
             page-break-after: avoid; }
  /* CPDF insere separadores NUL nos ajustes de espaço de texto Unicode
     justificado, incompatíveis com alguns importadores de PDF. */
  .sec p { margin: 0 0 5px; text-align: left; }

  table.campos { width: 100%; border-collapse: collapse; }
  table.campos td { border: 0; padding: 2px 12px 4px 0; vertical-align: top; font-weight: normal; }
  table.campos .lbl { display: block; font-size: 6pt; color: #666; text-transform: uppercase;
                      letter-spacing: .04em; font-weight: normal; }

  /* ── Memória de cálculo da multa (específico de obras) ── */
  .lei-cab { font-family: 'Times New Roman', Times, serif; padding: 8px 9px 5px;
             page-break-after: avoid; }
  .lei-cab .lbl { font-size: 6pt; color: #666; margin-right: 7px; }
  table.multa { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 16px;
                font-family: 'Times New Roman', Times, serif; }
  table.multa th { background: #f5f5f5; border: 1px solid #ddd; padding: 6px 8px;
                   font-size: 8px; text-align: left; }
  table.multa td { border-bottom: 1px solid #ddd; padding: 8px; font-size: 8pt; vertical-align: top; line-height: 1.45; }
  table.multa td + td { border-left: 1px solid #ddd; }
  table.multa td.dir { text-align: right; white-space: nowrap; font-weight: bold; }
  table.multa .calculo { color: #555; font-size: 8px; }
  table.multa .limite { font-size: 8px; }
  table.multa tr { page-break-inside: avoid; }

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
  .assina-linha { border-top: 1px solid #111; padding-top: 3px; margin-top: 3px; font-size: 7pt; }

  /* ── Anexos ── */
  .sec-anexos { page-break-before: always; break-before: page; }
  table.anexos-grade { width: 100%; table-layout: fixed; border-collapse: collapse; margin-bottom: 12px; page-break-inside: avoid; }
  .anexos-grade td { width: 50%; border: 1px solid #ddd; padding: 0; vertical-align: top; }
  .anexo-foto { height: 55mm; text-align: center; padding: 6px; }
  .anexo-foto img { max-width: 85mm; max-height: 52mm; width: auto; height: auto; }
  .anexo-dados { background: #f5f5f5; padding: 5px 10px; }
  .anexo-tit { font-size: 8pt; font-weight: bold; overflow-wrap: break-word; }
  .anexo-obs { font-size: 7pt; color: #555; overflow-wrap: break-word; white-space: pre-line; }
  .anexos-grade td.anexo-vazio { border: 0; }
  .prazo-destaque { background: #f1f1f1; padding: 7px 9px; margin: 12px 0 !important; page-break-inside: avoid; }

  /* ── Marca d'água ── */
  .marca { position: fixed; top: 40%; left: 12%; font-size: 96px; font-weight: bold;
           color: #E4E4E4; letter-spacing: 8px; transform: rotate(-28deg); z-index: -1; }

  .rodape-inst { border-top: 1px solid #ccc; margin-top: 10px; padding-top: 5px;
                 font-size: 6pt; color: #666; text-align: center; line-height: 1.4; }
  .rodape-a4 { font-size: 7pt; color: #555; line-height: 1.3;
               border-top: 1px solid #bbb; padding-top: 4px; margin-top: 12px; }
  .rodape-a4 table { width: 100%; border-collapse: collapse; }
  .rodape-a4 td { padding: 0; vertical-align: top; }
  .rodape-a4 .documento { text-align: right; width: 42%; }
  .rodape-a4 .contato { margin-top: 3px; white-space: pre-line; }
  @unless ($navegador || ! empty($previa))
    @page { margin-bottom: 14mm; }
    .rodape-a4 { position: fixed; bottom: -8mm; left: 0; right: 0; margin-top: 0; }
  @endunless

@if ($navegador)
  /* Barra só da janela de impressão — some no papel. Existe porque o app
     instalado abre essa janela sem barra de navegador nenhuma: cancelada a
     caixa de impressão, o usuário ficaria sem como voltar nem reimprimir. */
  .imp-barra { position: fixed; left: 0; right: 0; bottom: 0; background: #fff;
               border-top: 1px solid #ddd; padding: 10px 14px; z-index: 999; }
  .imp-barra button { width: 48%; border: 0; border-radius: 999px; padding: 12px 0;
                      font-size: 15px; font-weight: bold; font-family: Arial, sans-serif; }
  .imp-fechar { background: #ECEFF1; color: #37474F; }
  /* A cor é a do tema escolhido pelo usuário (_tema-janela); o verde é o padrão. */
  .imp-print { background: var(--imp-cor, #006B28); color: #fff; }
  body { padding-bottom: 62px; }
  @media print { .imp-barra { display: none; } body { padding-bottom: 0; } }
@endif
@if (! empty($previa))
  /* Prévia embutida no resumo do documento: só uma folga mínima nas
     bordas. A margem do papel (o @page) não se repete aqui: na tela ela seria
     espaço em branco, e é esse espaço que deixa a letra maior. */
  body { padding: 3mm 3mm 4mm; background: #fff; }
@endif
</style>
</head>
<body>

@if ($marca)
  <div class="marca">{{ $marca }}</div>
@endif

@unless ($navegador || ! empty($previa))
  @include('impressao._rodape-a4')
@endunless

@include('impressao._pagina-abre', ['numero' => $numeroA4, 'cabecalhoA4Compacto' => true])

      @include('impressao._identificacao-a4')

      {{-- 1 — Autuado --}}
      <div class="sec">
        <div class="sec-tit">{{ $sec('Identificação do ' . mb_strtolower($destinatario)) }}</div>
        <table class="campos">
          <tr>
            <td style="width:56%"><span class="lbl">Nome / Razão social</span>{{ $doc->autuado_nome ?: '—' }}</td>
            <td><span class="lbl">CPF / CNPJ</span>{{ $doc->autuado_documento ?: '—' }}</td>
          </tr>
          <tr>
            <td colspan="2"><span class="lbl">Endereço:</span>{{ \App\Services\DocumentoImpressao::enderecoDestinatario($doc) }}</td>
          </tr>
        </table>
      </div>

      {{-- 2 — Imóvel. Em obras o objeto do ato é o LOTE, identificado pela
           inscrição imobiliária: é por ela que o processo é indexado e é ela
           que amarra o documento ao cadastro do município. --}}
      <div class="sec">
        <div class="sec-tit">{{ $sec('Local da infração e identificação do imóvel') }}</div>
        <table class="campos">
          <tr>
            <td style="width:24%"><span class="lbl">Inscrição imobiliária</span>{{ $imovel['inscricao'] ?: '—' }}</td>
            <td style="width:38%"><span class="lbl">Logradouro</span>{{ $imovel['endereco'] ?: '—' }}</td>
            <td style="width:14%"><span class="lbl">Quadra</span>{{ $imovel['quadra'] ?? '—' }}</td>
            <td style="width:24%"><span class="lbl">Lote</span>{{ $imovel['lote'] ?? '—' }}</td>
          </tr>
          <tr>
            <td colspan="2"><span class="lbl">Bairro / loteamento</span>{{ $imovel['bairro'] ?: '—' }}</td>
            <td><span class="lbl">Área lote</span>{{ $doc->area_terreno_m2 !== null ? $fmt($doc->area_terreno_m2) . ' m²' : '—' }}</td>
            <td><span class="lbl">Área da obra</span>{{ $doc->area_construida_m2 !== null ? $fmt($doc->area_construida_m2) . ' m²' . ($doc->origemDaAreaDaObra() ? ' (' . $doc->origemDaAreaDaObra() . ')' : '') : '—' }}</td>
          </tr>
        </table>
      </div>

      {{-- A constatação continua íntegra, sem criar outra seção numerada. --}}
      @if ($doc->descricao)
        <div class="sec">
          <span class="lbl" style="font-size:8px;color:#666">CONSTATAÇÃO</span>
          <p>{{ $doc->descricao }}</p>
        </div>
      @endif

      @if ($doc->exigeFundamentacao())
        <div class="sec">
          <div class="sec-tit">{{ $sec($memoria['total'] !== null ? 'Infração / legislação infringida / multa e penalidade' : 'Infração / legislação infringida') }}</div>
          <div class="lei-cab"><span class="lbl">LEI INFRINGIDA:</span> <strong>{{ $doc->legislacao?->rotulo() ?: '—' }}</strong></div>
          <table class="multa">
            <thead>
              <tr>
                <th style="width:10%">ARTIGO(S)</th>
                <th>@if ($memoria['total'] !== null) Texto da legislação infringida @endif</th>
                @if ($memoria['total'] !== null)
                  <th style="width:17%">Cálculo</th>
                  <th style="width:11%;text-align:right">Multa · UPF</th>
                @endif
              </tr>
            </thead>
            <tbody>
              @forelse ($memoria['linhas'] as $l)
                <tr>
                  <td><strong>{{ preg_replace('/^Art\.?\s*/i', '', $l['numero']) }}</strong></td>
                  <td>{{ $l['conduta'] ?: '—' }}@if ($l['sancao']) {{ $l['sancao'] }}@endif</td>
                  @if ($memoria['total'] !== null)
                    <td class="calculo">
                      {{ $l['base'] === 'Valor fixo' ? $l['base'] : $l['conta'] }}
                      @if ($l['limite'])<br><span class="limite">{{ $l['limite'] }}</span>@endif
                    </td>
                    <td class="dir">{{ $l['valor'] !== null ? $fmt($l['valor']) : '—' }}</td>
                  @endif
                </tr>
              @empty
                <tr><td colspan="{{ $memoria['total'] !== null ? 4 : 2 }}">—</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      @endif

      <div class="sec">
        <div class="sec-tit">{{ $sec('Ciência / intimação') }}</div>
        @if ($ciencia)
          {{-- Já escapado, com o **negrito** resolvido (DocumentoImpressao::negrito). --}}
          <p>{!! $textoDestinatario($ciencia) !!}</p>
        @endif
        @if ($prazo)
          <p class="prazo-destaque"><strong>{{ mb_strtoupper($prazo['rotulo']) }}:</strong> {{ $prazo['nota'] }} <strong>VENCE EM {{ $prazo['data'] }}.</strong></p>
        @endif
        @if ($doc->observacoes)
          <p><span class="lbl" style="font-size:8px;color:#666">OBSERVAÇÕES</span><br>{{ $doc->observacoes }}</p>
        @endif
      </div>

      {{-- Assinaturas. A do autuado fica em branco quando houve recusa: nesse
           caso quem assina é a testemunha, na seção do Termo de Recusa. --}}
      <table class="assina">
        <tr>
          {{-- data-assina: na lavratura a tela mostra aqui a rubrica do fiscal. --}}
          <td data-assina="agente">
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
          {{-- data-assina: na lavratura, a tela põe aqui o campo em que o
               autuado assina, em cima do nome dele (documento-lavratura.js). --}}
          <td data-assina="autuado">
            @if ($doc->assinatura_autuado && ! $doc->recusa_assinatura)
              <img class="assina-img" src="{{ $doc->assinatura_autuado }}" alt="">
            @else
              <div class="assina-vazio"></div>
            @endif
            <div class="assina-linha">
              {{ $doc->autuado_nome ?: $destinatario }}@if ($doc->autuado_documento) — {{ $doc->autuado_documento }}@endif
            </div>
          </td>
        </tr>
      </table>

      @if ($doc->recusa_assinatura)
        <div class="sec">
          <div class="sec-tit">{{ $sec('Termo de Recusa') }}</div>
          <p>{{ $textoDestinatario($termoRecusa) }}</p>
          @if ($doc->testemunha_nome)
            {{-- Com recusa, quem assina é a testemunha. --}}
            <table class="assina">
              <tr>
                <td>
                  @if ($doc->assinatura_testemunha)
                    <img class="assina-img" src="{{ $doc->assinatura_testemunha }}" alt="">
                  @else
                    <div class="assina-vazio"></div>
                  @endif
                  <div class="assina-linha">{{ $doc->testemunha_nome }} — Testemunha</div>
                </td>
                <td></td>
              </tr>
            </table>
          @else
            <p><strong>Registro do agente:</strong> {{ $doc->recusa_assinatura }}</p>
          @endif
        </div>
      @endif

      @if (count($anexos))
        <div class="sec sec-anexos">
          @include('impressao._identificacao-a4')
          <div class="sec-tit">{{ $sec('Anexos') }}</div>
          @foreach (array_chunk($anexos, 2) as $par)
            <table class="anexos-grade"><tr>
              @foreach ($par as $a)
                <td>
                  @if ($a['foto'] && $a['src'])
                    <div class="anexo-foto"><img src="{{ $a['src'] }}" alt="{{ $a['titulo'] ?: 'Imagem do anexo' }}"></div>
                  @endif
                  <div class="anexo-dados">
                    <div class="anexo-tit">{{ $a['titulo'] ?: '—' }}</div>
                    @if ($a['dataHora'])<div class="anexo-obs">{{ $a['dataHora'] }}</div>@endif
                    @if ($a['descricao'])<div class="anexo-obs">{{ $a['descricao'] }}</div>@endif
                  </div>
                </td>
              @endforeach
              @if (count($par) === 1)<td class="anexo-vazio"></td>@endif
            </tr></table>
          @endforeach
        </div>
      @endif

@include('impressao._pagina-fecha')

@if ($navegador || ! empty($previa))
  @include('impressao._rodape-a4')
@endif

@if ($navegador)
  @include('impressao._tema-janela')
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
