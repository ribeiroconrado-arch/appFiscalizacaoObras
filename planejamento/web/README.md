# web/ — Protótipo do mapa (Etapa 3)

Front-end funcional do MVP-1, servível hoje, sem back-end. Feito para **virar a
camada de views do Laravel sem reescrita** quando a Etapa 2 destravar.

## Rodar

```bash
npx --yes http-server web -p 5178 -c-1
```

Depois abrir <http://localhost:5178>. Para testar o GPS no celular, rodar na
mesma rede e acessar pelo IP da máquina — **o navegador só libera geolocalização
em `localhost` ou HTTPS**, então no celular use um túnel HTTPS (ngrok, cloudflared)
ou instale certificado local; via `http://192.168.x.x` o botão de GPS não funciona.

## O que já funciona

- Mapa Leaflet com dois fundos (mapa claro CartoDB e satélite Esri)
- **707 lotes reais** do Jardim Europa IV, com número, quadra e área
- Clique no lote → ficha do imóvel
- **"Usar minha localização"** → identifica o lote pelo GPS:
  - ponto dentro de um lote → abre a ficha direto
  - ponto na rua → lista os lotes próximos ordenados por distância, para o
    fiscal confirmar (o fluxo do §9 do documento do projeto)
  - nada por perto → diz isso, em vez de fingir um resultado
- Círculo de imprecisão do GPS desenhado no mapa — é o que explica ao fiscal
  por que às vezes o sistema pergunta em vez de afirmar

## Estrutura

| Arquivo | Vira, no Laravel |
|---|---|
| `index.html` | `resources/views/mapa.blade.php` |
| `css/app.css` | `resources/css/app.css` |
| `js/ui.js` | `resources/js/components/ui.js` |
| `js/geo.js` | substituído por `POST /api/localizacao/identificar` (lógica igual, no servidor) |
| `js/mapa.js` | `resources/js/mapa.js` |
| `js/app.js` | `resources/js/app.js` |
| `dados/*.geojson` | substituído por `GET /api/mapa/lotes?bbox=…` |

Só dois pontos falam com a fonte de dados — `carregarLotes()` e
`resolverLocalizacao()`, ambos em `js/app.js`. Trocar esses dois pela chamada de
API é o que a migração exige; o resto da tela não muda.

## Design system

Portado do AppPOSTURAS (`appPerturbação/AppPerturbacao/src/styles/main.css`).
As convenções são as mesmas e devem ser seguidas em qualquer tela nova — a
referência canônica é a seção "Convenções de UI" do `CLAUDE.md` daquele projeto:

- **Botões "Modelo E"**: pílula, `.primary` verde sólido para a ação principal
- **Campos "Modelo E"**: o `.field` é o cartão; o input por dentro não tem borda
- **Títulos de seção**: numeração automática por contador CSS
- **Tags "Modelo D"**: fundo em gradiente, texto sempre cinza-chumbo
- **Modais não fecham por clique no fundo** — só pelo `×` ou botão explícito
- **`confirmarAcao()` em vez de `confirm()`**; **`toast()` em vez de `alert()`**
- **Ícone é sempre SVG de linha** (`fill:none`, `stroke:currentColor`), nunca emoji

## Trocar o bairro exibido

Editar `FONTE_LOTES` em `js/app.js`. Já existe também
`dados/lotes_buritis_v.geojson`, com 1.518 lotes.

## Limitações conhecidas

- Sem autenticação (é a Etapa 2)
- Sem persistência: nada do que se faz na tela é gravado
- A busca espacial roda na memória do navegador. Aguenta os 2.225 lotes do
  piloto com folga, mas **não** os 23.662 do município — por isso a API do
  Laravel filtra por `bbox`, e não devolve a cidade inteira
- `chave` (`bairro|quadra|lote`) ainda tem repetição em ~5% dos lotes; ver
  [docs/etapa1-base-piloto.md](../docs/etapa1-base-piloto.md)
