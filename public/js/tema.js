// ══════════════════════════════════════════════
// TEMA
//
// Carregado no <head> SEM defer, de propósito: roda antes do primeiro pintar.
// Aplicado depois, o tema salvo entraria por cima de um quadro já desenhado no
// outro tema, e a tela piscaria a cada carregamento.
//
// Um tema aqui é mais do que a paleta: troca também a MARCA (favicon, ícone da
// aba, logo do cabeçalho e da tela de entrada). Um app verde com ícone laranja
// na aba do navegador não parece o mesmo app.
//
// Os dois endereços de cada imagem vêm prontos do Blade, em data-src-*, para
// não perder o parâmetro de versão que o @assetv acrescenta — montar a URL
// aqui devolveria o arquivo do cache depois de uma regeração de ícones.
// ══════════════════════════════════════════════

// `dataset` é o nome do data-src-* de cada tema, já no formato do JavaScript
// (data-src-cinza → srcCinza).
const TEMAS = {
  institucional: { cor: '#00451A', dataset: 'srcInstitucional' },
  f:             { cor: '#B4470D', dataset: 'srcF' },
  cinza:         { cor: '#3F4750', dataset: 'srcCinza' },
}

/** Tema salvo, ou o institucional. @returns {'institucional'|'f'|'cinza'} */
function temaSalvo() {
  try {
    // O azul virou o cinza: quem tinha escolhido o azul continua no tema
    // que substituiu o dele, e não cai no padrão sem ter pedido.
    const salvo = localStorage.getItem('tema')
    const t = salvo === 'azul' ? 'cinza' : salvo
    return TEMAS[t] ? t : 'institucional'
  } catch (e) {
    return 'institucional'   // modo privado: vale só nesta sessão
  }
}

/**
 * @param {'institucional'|'f'|'cinza'} tema
 * @param {boolean} [salvar=false] false na carga inicial — não há o que gravar.
 */
function aplicarTema(tema, salvar = false) {
  if (!TEMAS[tema]) tema = 'institucional'
  document.documentElement.setAttribute('data-tema', tema)

  const m = document.querySelector('meta[name=theme-color]')
  if (m) m.content = TEMAS[tema].cor

  for (const el of document.querySelectorAll('[data-src-institucional]')) {
    const url = el.dataset[TEMAS[tema].dataset]
    if (!url) continue
    if (el.tagName === 'LINK') el.href = url
    else el.src = url
  }

  if (salvar) {
    try { localStorage.setItem('tema', tema) } catch (e) { /* modo privado */ }
  }
}

aplicarTema(temaSalvo())

// No <head> só existem ainda os ícones da aba; o logo do cabeçalho e o da tela
// de entrada chegam depois. Sem esta segunda passada eles abriam sempre no
// verde, e só trocavam quando o tema era escolhido de novo em Meu perfil.
document.addEventListener('DOMContentLoaded', () => aplicarTema(temaSalvo()))
