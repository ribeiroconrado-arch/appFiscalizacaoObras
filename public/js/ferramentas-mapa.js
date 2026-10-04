/**
 * UMA FERRAMENTA DO MAPA POR VEZ.
 *
 * Localizar imóvel, cores, correção cadastral (mesa, modos, atos,
 * pré-curadoria) e o desenho de edificação disputam o mesmo mapa — o clique, o
 * alto da tela, a lateral. Abertas juntas, uma trabalhava por baixo da outra:
 * a busca aberta com a mesa de curadoria marcando lotes ao lado, a barra de
 * desenho de um lote por cima da barra da importação.
 *
 * Cada módulo continuava sabendo fechar só a si mesmo. Este arquivo é o
 * porteiro: quem vai abrir pede a vez (`pedirFerramenta`), e o que estiver
 * aberto é encerrado antes. Se o que está aberto tem TRABALHO EM CURSO que se
 * perderia (lotes marcados, contorno começado), a pessoa confirma antes.
 *
 * O estado não é guardado aqui: cada ferramenta diz se está aberta olhando o
 * próprio estado (DOM ou variáveis do módulo). Guardar uma cópia seria mais
 * um lugar para ficar desatualizado — e as ferramentas fecham por muitos
 * caminhos (Esc, "Sair", gravar, clique fora) que não passariam por aqui.
 */

/** Painel da coluna de controles, pelo id do grupo. */
const _grupoAberto = id => !!document.getElementById(id)?.classList.contains('aberto')

/**
 * @type {Object<string, {rotulo:string, aberta:() => boolean,
 *                         emCurso:() => (string|null), fechar:() => void}>}
 */
const FERRAMENTAS_MAPA = {
  busca: {
    rotulo: 'a pesquisa',
    aberta: () => document.getElementById('pesq-barra')?.hidden === false,
    emCurso: () => null,
    fechar: () => fecharPesquisaMapa(),
  },
  cores: {
    rotulo: 'cores do mapa',
    aberta: () => _grupoAberto('grupo-cores'),
    emCurso: () => null,
    fechar: () => fecharPaineisMapa(),
  },
  curadoria: {
    rotulo: 'correção cadastral',
    aberta: () => {
      const mesa = document.getElementById('cad-mesa')
      return _grupoAberto('grupo-cadastro') || (mesa && !mesa.hidden)
        || (typeof cadModo !== 'undefined' && !!cadModo)
        || (typeof atoState !== 'undefined' && !!atoState.tipo)
        || (typeof desmMesa !== 'undefined' && desmMesa.ativa)
        || (typeof impState !== 'undefined' && !!impState.preCuradoria)
        || (typeof selState !== 'undefined' && selState.ids.size > 0)
        // As barras da importação e da conferência também são curadoria:
        // com elas à vista, a pesquisa abrindo por cima é o que o usuário viu
        // como "duas ferramentas ao mesmo tempo".
        || document.getElementById('imp-barra')?.hidden === false
        || document.getElementById('conf-barra')?.hidden === false
    },
    // Para OUTRA ferramenta (busca, pesquisa), lote marcado é trabalho em
    // curso: pergunta antes. Para uma JANELA da própria curadoria (a ficha da
    // importação, a lista, a conferência) a marcação sozinha não conta: quem
    // exclui ou edita um lote e volta à ficha não está perdendo nada, e a
    // pergunta a cada volta era alarme sem perda. Ali só conta a marcação
    // feita DENTRO de uma ferramenta em uso (corrigir quadra, unificar…).
    emCurso: (para) => {
      const n = typeof selState !== 'undefined' ? selState.ids.size : 0
      if (!n) return null
      const emUso = (typeof cadModo !== 'undefined' && !!cadModo)
        || (typeof atoState !== 'undefined' && !!atoState.tipo)
      return para === 'janela' && !emUso ? null : `${n} lote(s) marcados`
    },
    fechar: () => sairDaCuradoria(),
  },
  // Uma JANELA da curadoria (Importações, ficha da importação, Conferência,
  // Contorno dos bairros). Não fica "aberta" no mapa — é modal —, mas abrir uma
  // encerra o que estiver em uso: a janela cobre a mesa e as barras, e voltar
  // ao mapa com uma ferramenta pela metade por baixo é a concorrência que se
  // quer evitar. Ver abrirJanelaDoMapa.
  janela: {
    rotulo: 'a janela',
    aberta: () => false,
    emCurso: () => null,
    fechar: () => {},
  },
  desenho: {
    rotulo: 'desenho no mapa',
    aberta: () => typeof desenhoState !== 'undefined' && desenhoState.ativo,
    emCurso: () => typeof desenhoState !== 'undefined' && desenhoState.vertices.length
      ? 'um contorno começado' : null,
    fechar: () => { if (typeof cancelarDesenho === 'function') cancelarDesenho() },
  },
}

/**
 * Pede a vez para abrir `nome`. Fecha o que estiver aberto — perguntando antes,
 * se houver trabalho que se perderia — e então chama `abrir`.
 *
 * @param {string} nome chave de FERRAMENTAS_MAPA
 * @param {() => void} abrir
 * @param {{convive?: string[]}} [opts] ferramentas que NÃO são fechadas: o
 *        desenho de edificação feito de dentro da mesa de curadoria não fecha
 *        a mesa que o lançou.
 */
function pedirFerramenta(nome, abrir, opts = {}) {
  const outras = Object.entries(FERRAMENTAS_MAPA)
    .filter(([k, f]) => k !== nome && !(opts.convive || []).includes(k) && f.aberta())

  const perdas = outras.map(([, f]) => f.emCurso(nome) && `${f.rotulo}: ${f.emCurso(nome)}`).filter(Boolean)

  const seguir = () => {
    outras.forEach(([, f]) => { try { f.fechar() } catch (e) { console.error(e) } })
    abrir()
  }

  if (!perdas.length) { seguir(); return }

  const novo = FERRAMENTAS_MAPA[nome]?.rotulo ?? 'esta ferramenta'
  confirmarAcao({
    titulo: 'Encerrar o que está aberto?',
    mensagem: `Há trabalho em curso — ${perdas.join('; ')}. Abrir ${novo} encerra isso e o que não foi gravado se perde.`,
    textoBtn: `Encerrar e abrir ${novo}`,
    perigo: true,
    // Na volta do laço: o clique no botão do modal ainda vai subir até o
    // `document`, e o ouvinte de "clique fora" (mapa.js) fecharia na mesma hora
    // o painel que acabou de abrir.
    onConfirm: () => setTimeout(seguir, 0),
  })
}

/**
 * Antes de abrir uma janela da curadoria: fecha tudo o que está no mapa
 * (perguntando, se houver trabalho que se perderia). Resolve quando pode abrir;
 * se a pessoa desistir na confirmação, nunca resolve — e a janela não abre.
 *
 * @returns {Promise<void>}
 */
function abrirJanelaDoMapa() {
  return new Promise(resolve => pedirFerramenta('janela', resolve))
}

/**
 * O caminho de volta: a curadoria aparece por muitas portas que não passam por
 * pedirFerramenta (a mesa reabre sozinha ao sair da prancheta ou do
 * desmembramento; a barra da importação aparece ao "Ver no mapa"). Cada uma
 * chama isto ao aparecer, e a pesquisa — a única ferramenta que não é
 * curadoria e fica à vista — sai de cena.
 */
function curadoriaApareceu() {
  if (document.getElementById('pesq-barra')?.hidden === false && typeof fecharPesquisaMapa === 'function') {
    fecharPesquisaMapa()
  }
}

/**
 * Sai de TUDO o que é correção cadastral, na ordem em que as peças dependem
 * umas das outras: o ato e o desmembramento guardam rascunho ao sair, o modo
 * larga a marcação, e só então a mesa fecha.
 */
function sairDaCuradoria() {
  if (typeof PranchetaCad !== 'undefined' && PranchetaCad.ativa()) PranchetaCad.fechar()
  if (typeof desmMesa !== 'undefined' && desmMesa.ativa) sairMesaDesmembramento()
  if (typeof atoState !== 'undefined' && atoState.tipo) cancelarAtoCadastral()
  if (typeof cadModo !== 'undefined' && cadModo) sairModoCadastral(true)
  if (typeof impState !== 'undefined' && impState.preCuradoria) sairPreCuradoria()
  if (typeof impState !== 'undefined' && impState.atual) {
    impState.atual = null
    impState.noMapa = false
    pintarBarraImportacao()
  }
  if (typeof confState !== 'undefined' && confState.noMapa) fecharConferenciaNoMapa()
  if (typeof limparSelecaoCadastral === 'function') limparSelecaoCadastral()
  if (typeof fecharModalCad === 'function' && document.getElementById('m-cad')?.classList.contains('open')) fecharModalCad()
  if (typeof fecharMesaCadastral === 'function') fecharMesaCadastral()
  if (typeof desligarSelecao === 'function' && typeof selState !== 'undefined' && selState.ativa) desligarSelecao()
  fecharPaineisMapa()
}
