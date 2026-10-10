{{--
  A JANELA DE IMPRESSÃO NA COR DO TEMA.

  A janela abre fora do aplicativo, sem as folhas de estilo dele; o tema é uma
  preferência guardada no navegador (localStorage, ver public/js/tema.js). Este
  trecho lê a mesma preferência e pinta o que é TELA — o botão Imprimir e a
  cor da barra do navegador. O papel não muda: documento oficial é preto.

  As cores são o --gd de cada tema em public/css/temas.css.
--}}
<script>
  try {
    var corDoTema = { f: '#EA580C', cinza: '#4B545E', azul: '#4B545E' }[localStorage.getItem('tema')]
    if (corDoTema) {
      document.documentElement.style.setProperty('--imp-cor', corDoTema)
      var metaCor = document.createElement('meta')
      metaCor.name = 'theme-color'
      metaCor.content = corDoTema
      document.head.appendChild(metaCor)
    }
  } catch (e) { /* sem acesso ao armazenamento: fica o padrão */ }
</script>
