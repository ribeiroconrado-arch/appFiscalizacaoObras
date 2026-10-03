'use strict'
// A tela: só mostra e pede. Arquivos e caminhos ficam com o processo principal.
const $ = id => document.getElementById(id)
const fmt = n => Number(n).toLocaleString('pt-BR')
const mb = b => b < 1048576 ? Math.max(1, Math.round(b / 1024)).toLocaleString('pt-BR') + ' KB'
  : (b / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' MB'
const data = iso => { const d = new Date(iso); return isNaN(d) ? '—' : d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) }
const temos = { ref: false, planilha: false }

function texto(el, html) { el.innerHTML = html }
function esc(s) { const e = document.createElement('span'); e.textContent = s ?? ''; return e.innerHTML }

function pronto() {
  $('b-gerar').disabled = !(temos.ref && temos.planilha)
  $('resultado').hidden = true
  $('erro').hidden = true
  $('p4').hidden = true
  $('andamento').textContent = ''
}

$('b-ref').addEventListener('click', async () => {
  const r = await window.cadastro.escolherReferencia()
  if (!r) return
  if (r.erro) {
    temos.ref = false
    texto($('ref-info'), `<span class="ruim">${esc(r.erro)}</span>`)
  } else {
    temos.ref = true
    texto($('ref-info'), `<b>${esc(r.nome)}</b><span>gerada em ${data(r.geradaEm)} · ${fmt(r.total)} imóveis no sistema</span>`)
    $('p1').classList.add('feito')
  }
  pronto()
})

$('b-planilha').addEventListener('click', async () => {
  const r = await window.cadastro.escolherPlanilha()
  if (!r) return
  temos.planilha = true
  texto($('planilha-info'), `<b>${esc(r.nome)}</b><span>${mb(r.bytes)}</span>`)
  $('p2').classList.add('feito')
  pronto()
})

window.cadastro.aoProgredir(linhas => { $('andamento').textContent = `Lendo… ${fmt(linhas)} linhas` })

$('b-gerar').addEventListener('click', async () => {
  pronto()
  $('b-gerar').disabled = true
  $('andamento').textContent = 'Lendo a planilha…'
  const r = await window.cadastro.gerar()
  $('b-gerar').disabled = false
  $('andamento').textContent = ''
  if (r.erro) { $('erro').textContent = r.erro; $('erro').hidden = false; return }

  const s = r.resumo
  const cartao = (n, rot, classe = '') => `<div class="num-cartao ${classe}"><b>${fmt(n)}</b><span>${rot}</span></div>`
  texto($('numeros'), [
    cartao(s.linhas, 'linhas lidas'),
    cartao(s.imoveis, 'imóveis na planilha'),
    cartao(s.novos, 'novos', s.novos ? 'destaque' : ''),
    cartao(s.alterados + s.sem_codigo, 'alterados', s.alterados + s.sem_codigo ? 'destaque' : ''),
    cartao(s.iguais, 'iguais'),
    cartao(s.ausentes, 'sumiram da planilha', s.ausentes ? 'alerta' : ''),
    s.reaparecidos ? cartao(s.reaparecidos, 'voltaram') : '',
  ].join(''))

  const avisos = []
  if (s.pedeConfirmacao) {
    const pct = Math.round(100 * s.ausentes / s.universo)
    avisos.push(`<div class="aviso alerta"><b>${pct}% dos imóveis destes bairros não vieram na planilha.</b>
      Se ela estiver cortada, gere de novo com a planilha completa. Se estiver certa, o sistema vai pedir
      que você confirme as ausências depois de anexar.</div>`)
  }
  if (s.sem_codigo) {
    avisos.push(`<div class="aviso">${fmt(s.sem_codigo)} imóvel(is) de carga antiga ainda sem código de conferência
      vão inteiros desta vez — é normal na primeira vez com o app.</div>`)
  }
  if (s.faltando.length) {
    avisos.push(`<div class="aviso">Colunas que a planilha não tem (o campo fica em branco no sistema):
      ${s.faltando.map(esc).join(', ')}.</div>`)
  }
  if (!s.novos && !s.alterados && !s.sem_codigo && !s.ausentes && !s.reaparecidos) {
    avisos.push('<div class="aviso">Nada mudou desde a referência. O arquivo só confirma que tudo continua igual.</div>')
  }
  texto($('avisos'), avisos.join(''))
  $('salvo-info').textContent = ''
  $('resultado').hidden = false
})

$('b-salvar').addEventListener('click', async () => {
  const r = await window.cadastro.salvar()
  if (!r) return
  if (r.erro) { $('erro').textContent = r.erro; $('erro').hidden = false; return }
  texto($('salvo-info'), `<b>${esc(r.nome)}</b><span>${mb(r.bytes)} · salvo</span>`)
  $('p3').classList.add('feito')
  $('p4').hidden = false
  $('p4').scrollIntoView({ behavior: 'smooth' })
})

$('b-pasta').addEventListener('click', () => window.cadastro.mostrarNaPasta())

window.cadastro.versao().then(v => { $('rodape').textContent = `FiscObras Cadastro ${v}` })
