{{--
  ABRE A PÁGINA — cabeçalho repetido em toda folha, de um jeito para cada motor.

  NAVEGADOR (janela de impressão): o corpo vai dentro de uma tabela, com o
  cabeçalho no <thead>. É o que faz o navegador repeti-lo em cada folha
  reservando o espaço dele.

  DOMPDF (o PDF): a mesma tabela NÃO serve. O corpo inteiro ficava numa célula
  só, e o dompdf não parte uma célula entre páginas: quando o conteúdo passava
  de uma folha (auto com fotos anexas), ele empurrava a célula toda para
  adiante. O PDF saía com o cabeçalho sozinho na página 1, a página 2 em
  branco e o documento começando na 3. No dompdf o cabeçalho é um bloco de
  posição fixa na MARGEM de cima da página — que ele repete em toda folha — e o
  corpo corre solto, quebrando onde tiver de quebrar. A margem que reserva o
  espaço está em _cabecalho-css.

  Fecha com @include('impressao._pagina-fecha').

  @param string $numero  e os demais parâmetros de _cabecalho
--}}
@if ($navegador)
<table class="pagina">
  <thead>
    <tr><td>
      @include('impressao._cabecalho')
    </td></tr>
  </thead>

  <tbody>
    <tr><td>
@else
<div class="cab-fixo">
  @include('impressao._cabecalho')
</div>
<div class="corpo-pdf">
@endif
