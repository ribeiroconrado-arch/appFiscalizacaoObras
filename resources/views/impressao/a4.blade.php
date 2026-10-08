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
  // Referências estáveis: a ausência de recusa não renumera os anexos.
  $sec = fn (int $numero, string $titulo) => sprintf('%02d   %s', $numero, $titulo);

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
  .cab-regua { border-bottom: 1px solid #50565b; }
  .cab-num-lbl, .topo-lbl { font-weight: normal; }
  .cab-num-val, .topo-val { font-weight: bold; }
  table.topo { margin-top: 0; background: #f1f1f1; table-layout: fixed; }
  table.topo td { padding: 7px 6px; }
  table.topo td:last-child { padding-right: 6px; }
  .topo-lbl { font-size: 7px; white-space: normal; }
  .topo-val { font-size: 9px; }
  .topo-regua { border-bottom: 1px solid #50565b; margin-bottom: 14px; }

  /* ── Seções ── */
  .sec { margin-bottom: 6px; }
  .sec-tit { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em;
             border-bottom: 1px solid #d5d5d5; padding: 0 0 5px; margin: 10px 0 7px;
             page-break-after: avoid; }
  /* CPDF insere separadores NUL nos ajustes de espaço de texto Unicode
     justificado, incompatíveis com alguns importadores de PDF. */
  .sec p { margin: 0 0 5px; text-align: left; }

  table.campos { width: 100%; border-collapse: collapse; }
  table.campos td { border: 0; padding: 3px 12px 5px 0; vertical-align: top; font-weight: normal; }
  table.campos .lbl { display: block; font-size: 7.5px; color: #666; text-transform: uppercase;
                      letter-spacing: .04em; font-weight: normal; }

  /* ── Memória de cálculo da multa (específico de obras) ── */
  .lei-cab { border: 1px solid #d3d3d3; border-bottom: 0; padding: 8px 9px;
             page-break-after: avoid; }
  .lei-cab .lbl { font-size: 7.5px; color: #666; margin-right: 7px; }
  table.multa { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 4px; }
  table.multa th { background: #f5f5f5; border: 1px solid #ddd; padding: 6px 8px;
                   font-size: 8px; text-align: left; }
  table.multa td { border: 1px solid #ddd; padding: 8px; font-size: 10px; vertical-align: top; }
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
  .assina-linha { border-top: 1px solid #111; padding-top: 3px; margin-top: 3px; font-size: 8.5px; }

  /* ── Anexos ── */
  table.anexo { width: 100%; table-layout: fixed; border-collapse: collapse;
                border: 1px solid #ddd; page-break-inside: avoid; margin-bottom: 10px; }
  table.anexo td { padding: 10px; vertical-align: middle; }
  table.anexo td.anexo-foto { width: 150px; padding-right: 2px; text-align: center; }
  .anexo img { max-width: 150px; max-height: 110px; width: auto; height: auto; }
  .anexo-tit { font-size: 9.5px; font-weight: bold; overflow-wrap: break-word; }
  .anexo-obs { font-size: 8.5px; color: #555; overflow-wrap: break-word; white-space: pre-line; }

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
@if (! empty($previa))
  /* Prévia embutida no resumo do documento: as margens que o papel dá pelo
     @page, aqui vêm no corpo — a tela não tem margem de impressão. */
  body { padding: 8mm 10mm 10mm; background: #fff; }
@endif
</style>
</head>
<body>

@if ($marca)
  <div class="marca">{{ $marca }}</div>
@endif

@include('impressao._pagina-abre', ['numero' => $doc->numeroFormatado()])

      {{-- Faixa do topo: identifica quem lavrou e quando. Fica no tbody de
           propósito — só faz sentido na primeira página. --}}
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

      {{-- 1 — Autuado --}}
      <div class="sec">
        <div class="sec-tit">{{ $sec(1, $doc->exigeFundamentacao() ? 'Identificação do autuado' : 'Identificação do interessado') }}</div>
        <table class="campos">
          <tr>
            <td style="width:56%"><span class="lbl">Nome / Razão social</span>{{ $doc->autuado_nome ?: '—' }}</td>
            <td><span class="lbl">CPF / CNPJ</span>{{ $doc->autuado_documento ?: '—' }}</td>
          </tr>
          @if ($doc->autuado_endereco)
          <tr>
            <td colspan="2"><span class="lbl">Domicílio fiscal / correspondência</span>{{ preg_replace('/(?:[,;\s]|—|-)*CEP\s*:?\s*\d{5}-?\d{3}/iu', '', $doc->autuado_endereco) }}</td>
          </tr>
          @endif
        </table>
      </div>

      {{-- 2 — Imóvel. Em obras o objeto do ato é o LOTE, identificado pela
           inscrição imobiliária: é por ela que o processo é indexado e é ela
           que amarra o documento ao cadastro do município. --}}
      <div class="sec">
        <div class="sec-tit">{{ $sec(2, 'Local da infração e identificação do imóvel') }}</div>
        <table class="campos">
          <tr>
            <td style="width:56%"><span class="lbl">Inscrição imobiliária</span>{{ $imovel['inscricao'] ?: '—' }}</td>
            <td><span class="lbl">Bairro · quadra · lote</span>{{ $imovel['bairro'] ?: '—' }} · {{ $imovel['quadra'] ?? '—' }} · {{ $imovel['lote'] ?? '—' }}</td>
          </tr>
          <tr>
            <td colspan="2"><span class="lbl">Endereço / referência de localização</span>{{ $imovel['endereco'] ?: '—' }}</td>
          </tr>
          <tr>
            <td colspan="2"><span class="lbl">Terreno · área construída aferida</span>{{ $doc->area_terreno_m2 !== null ? $fmt($doc->area_terreno_m2) . ' m²' : '—' }} · {{ $doc->area_construida_m2 !== null ? $fmt($doc->area_construida_m2) . ' m²' : '—' }}</td>
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
          <div class="sec-tit">{{ $sec(3, $memoria['total'] !== null ? 'Infração / legislação infringida / multa e penalidade' : 'Infração / legislação infringida') }}</div>
          <div class="lei-cab"><span class="lbl">LEI INFRINGIDA:</span> <strong>{{ $doc->legislacao?->rotulo() ?: '—' }}</strong></div>
          <table class="multa">
            <thead>
              <tr>
                <th style="width:10%">Artigo</th>
                <th>Texto da legislação infringida</th>
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
        <div class="sec-tit">{{ $sec(4, 'Ciência / intimação') }}</div>
        @if ($ciencia)
          {{-- Já escapado, com o **negrito** resolvido (DocumentoImpressao::negrito). --}}
          <p>{!! $ciencia !!}</p>
        @endif
        @if ($prazo)
          <p><strong>{{ $prazo['rotulo'] }}: até {{ $prazo['data'] }}.</strong> {{ $prazo['nota'] }}</p>
        @endif
        @if ($doc->observacoes)
          <p><span class="lbl" style="font-size:8px;color:#666">OBSERVAÇÕES</span><br>{{ $doc->observacoes }}</p>
        @endif
      </div>

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
          <div class="sec-tit">{{ $sec(5, 'Termo de Recusa') }}</div>
          <p>{{ $termoRecusa }}</p>
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
        <div class="sec">
          <div class="sec-tit">{{ $sec(6, 'Anexos') }}</div>
          @foreach ($anexos as $a)
            <table class="anexo">
              <tr>
                @if ($a['foto'] && $a['src'])
                  <td class="anexo-foto" style="width:24%"><img src="{{ $a['src'] }}" alt="{{ $a['titulo'] ?: 'Imagem do anexo' }}"></td>
                @endif
                <td class="anexo-dados" style="width:{{ $a['foto'] && $a['src'] ? '76%' : '100%' }}">
                  <div class="anexo-tit">{{ $a['titulo'] ?: '—' }}</div>
                  @if ($a['dataHora'])<div class="anexo-obs">{{ $a['dataHora'] }}</div>@endif
                  @if ($a['descricao'])<div class="anexo-obs">{{ $a['descricao'] }}</div>@endif
                </td>
              </tr>
            </table>
          @endforeach
        </div>
      @endif

      {{-- CARIMBO DE PROCEDÊNCIA — de quando é o dado cadastral desta peça.

           O documento guarda cópia própria do autuado, então reintegrar o
           cadastro nunca o altera. Faltava dizer de QUANDO os valores são: sem
           isso, quem questionar o auto daqui a dois anos não tem como saber se
           o nome era o vigente na lavratura.

           A linha do "sem consulta" é impressa, e não omitida. Omitir
           esconderia justamente o caso em que alguém deveria olhar. --}}
      @if ($doc->status !== 'rascunho')
        <div class="rodape-inst" style="border-top:none; margin-top:6px; padding-top:0">
          @php
            // A origem em português, montada ANTES da frase.
            // `@elseif` colado a uma palavra não é reconhecido pelo Blade: a
            // diretiva sai impressa como texto, no papel, dentro do auto.
            $origem = match ($doc->cadastro_fonte) {
                'exportacao' => ', por exportação do cadastro imobiliário',
                'banco'      => ', por consulta ao cadastro da prefeitura',
                null         => '',
                default      => ', fonte: ' . $doc->cadastro_fonte,
            };
          @endphp
          @if ($doc->cadastro_consultado_em)
            Dados cadastrais do imóvel conforme o cadastro municipal integrado em
            {{ $doc->cadastro_consultado_em->format('d/m/Y') }}{{ $origem }}.
          @else
            Lavrado sem dado do cadastro municipal.
          @endif
        </div>
      @endif

      @if (count($rodape))
        <div class="rodape-inst">
          @foreach ($rodape as $linha)
            <div>{{ $linha }}</div>
          @endforeach
        </div>
      @endif

@include('impressao._pagina-fecha')

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
