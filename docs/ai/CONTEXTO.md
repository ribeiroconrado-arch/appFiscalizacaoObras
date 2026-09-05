# CONTEXTO

> Para quem chega agora — pessoa ou IA — e precisa entender **o que este
> sistema é** antes de mexer nele.
> Atualizado em 04/09/2026.

## O que é

Sistema municipal de **fiscalização de obras** de Primavera do Leste/MT. Não é
um CRUD de obras: é a ferramenta com que o fiscal **pratica atos
administrativos** — vistoria um imóvel, constata irregularidade, notifica,
embarga, autua e cobra multa.

A diferença importa em cada decisão de código. O que sai daqui vira peça de
processo administrativo, é entregue ao autuado, e pode ser contestado em
defesa. Um número errado numa tela de CRUD é um incômodo; aqui é um vício que
aparece meses depois, quando já não há como refazer.

## Para quem

| Papel | O que faz | Onde |
|---|---|---|
| **Agente de fiscalização** | Vistoria, lavra as peças, cumpre OS | Em campo, no **celular**, muitas vezes sem rede boa |
| **Coordenador** | Distribui ordens de serviço, acompanha | Escritório |
| **Secretário** | Acompanha, não autua | Escritório |
| **Curador do cadastro** | Corrige a base de lotes (quadra, desmembramento, resíduo) | Escritório, **monitor grande** |

O agente é o usuário do meio-dia ao sol, de pé, com uma mão no celular. O
curador é o do monitor. **São dois contextos de uso, não um** — e há telas
desenhadas para cada (ver `DECISOES-UX.md`).

## O problema que resolve

Antes: a fiscalização identificava o imóvel na planta impressa, anotava em
papel, digitava depois no escritório, e o histórico de um imóvel só existia na
memória de quem o visitou. Não havia como saber, diante da obra, se aquele lote
já fora notificado no mês passado.

Agora: **o mapa é a interface operacional**. Toca-se no lote, vê-se o
histórico, registra-se a vistoria com foto e coordenada no local, e a peça
nasce da vistoria — com o enquadramento legal que o catálogo de
irregularidades sugere.

## O ciclo que o sistema executa

```
     lote no mapa
          │
          ▼
     VISTORIA  ──── fotos com data/hora/coordenada próprias
          │         irregularidades do catálogo
          │         artigos que as enquadram
          ▼
   ┌──────┴───────┬──────────────┬───────────────┐
   ▼              ▼              ▼               ▼
NOTIFICAÇÃO   NOT. EMBARGO   AUTO EMBARGO   AUTO INFRAÇÃO
(dá prazo)    (avisa parar)  (para a obra)  (aplica multa)
   │                                             │
   └──────────────── prazos ─────────────────────┘
```

Em paralelo, dois fluxos de apoio:

- **Protocolo** — o requerimento do cidadão (desmembramento, unificação) que
  gera vistoria e ato cadastral.
- **Ordem de serviço** — a demanda distribuída ao agente, com ciência e prazo.

## Regras de domínio que não são negociáveis

Estas custaram a ser descobertas e o código as protege em mais de um lugar.
Mexer nelas exige entender por quê.

**A multa de obras é por m² construído.** Por isso toda peça com multa imprime
a memória de cálculo, e por isso a área tem duas fontes que nunca se
substituem: a **aferida em campo** (trena) e a **do desenho** (`area_gis_m2`).
São coisas diferentes — uma é medição, a outra é aferição — e a tela mostra as
duas lado a lado.

**Vistoria sem irregularidade é o caso comum**, não o desvio. A maioria das
vistorias constata regularidade. Uma tela que trate irregularidade como o
caminho principal faz o trabalho normal parecer exceção.

**O que foi lavrado não se edita.** Rascunho se corrige; peça lavrada só se
anula, e a anulação fica registrada. Formulário gravado que continua aberto
para digitação convida à alteração acidental de peça de processo.

**Nada se apaga em silêncio.** Lote unificado ou desmembrado não some: fica
`situacao = 'inativo'`, apontando para os sucessores. Vistorias e peças
continuam penduradas nele.

**Toda alteração de identificação de imóvel é auditada.** Quadra, número de
lote, inscrição, amarração de bairro — tudo passa pela trilha (`auditoria`),
que é o que responde "quem mudou o quê" num processo.

**Dado incompleto entra vazio, não chutado.** Se o sistema não sabe o código
do bairro, a inscrição imobiliária é **nula** — nunca `000`. Inventar número
de imóvel numa tela de onde se copia para dentro de auto de infração é pior do
que não ter número nenhum.

## Os dois nomes de cada bairro

Um mesmo lugar tem dois nomes, e confundi-los já causou defeito em produção:

| | exemplo | vale em |
|---|---|---|
| **Nome do desenho** (`lotes.bairro`) | `Residencial Buritis V` | só o rótulo escrito sobre o mapa, e a chave de integração com o DWG |
| **Nome oficial** (`cadastro_bairros.nome_cadastro`) | `RESIDENCIAL BURITIS PRIMAVERA V - PRIME` | **todo o resto**: busca, ficha, documento, peça |

A amarração entre os dois é `cadastro_bairros.nome_gis`, preenchida à mão em
Parâmetros. Sem ela o bairro não tem código, e sem código não há inscrição
imobiliária.

## Onde o sistema roda

| | |
|---|---|
| Desenvolvimento | Laravel Herd, `C:\Users\<user>\Herd\fiscalizacao-obras` |
| Produção | <https://fiscobras.duckdns.org> — Oracle Always Free (Ubuntu 24.04), DuckDNS, Let's Encrypt |
| Deploy | `sudo /usr/local/bin/deploy-fiscobras.sh` no servidor — puxa do GitHub, migra, refaz caches e testa o site |
| Repositório | <https://github.com/ribeiroconrado-arch/appFiscalizacaoObras> (público) |

O servidor **lê do GitHub**: nada chega em produção sem passar por um push.

## Documentação relacionada

- `ARQUITETURA.md` — como o código está organizado e por quê
- `DECISOES-UX.md` — as escolhas de interface e o que as motivou
- `DESIGN-SYSTEM.md` — tokens, componentes e o padrão visual
- `ESTADO-ATUAL.md` — o que está pronto, o que falta, o que está quebrado
- `ROADMAP.md` — o que vem pela frente
- `TAREFA-ATUAL.md` — o que está em curso agora
- `CHANGELOG-IA.md` — o que foi feito, em ordem

Fora desta pasta, no OneDrive do projeto: o diagnóstico da base cartográfica,
a ADR do banco espacial e as decisões de georreferenciamento.
