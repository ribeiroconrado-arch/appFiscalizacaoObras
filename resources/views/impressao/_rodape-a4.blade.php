<footer class="rodape-a4">
  <table>
    <tr>
      <td>{{ ($orgao['divisao'] ?? null) ?: ($orgao['nome'] ?? '') }}</td>
      <td class="documento">{{ $doc->rotuloTipo() }} {{ $numeroA4 }}</td>
    </tr>
  </table>
  @if ($contatoRodape !== '')
    <div class="contato">{{ $contatoRodape }}</div>
  @endif
</footer>
