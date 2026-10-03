# Fiscalização de Obras — Primavera do Leste/MT

Sistema municipal de fiscalização de obras. O fiscal localiza o imóvel no mapa
cadastral, registra a vistoria (relatório em itens, fotos georreferenciadas,
irregularidades enquadradas direto no artigo da lei) e lavra os atos — notificação, embargo,
auto de infração — com numeração, prazos e memória de cálculo da multa. O mesmo
mapa serve à curadoria do cadastro imobiliário: desenho, desmembramento,
unificação e importação de bairros, tudo auditado.

Em produção: <https://fiscobras.duckdns.org>

## Stack

| | |
|---|---|
| Back-end | PHP 8.4 · Laravel 13 |
| Banco | MySQL 8 Spatial (armazenamento em EPSG:4326; origem SIRGAS 2000 / UTM 21S) |
| Front-end | JavaScript puro e CSS estáticos em `public/`, **sem build** · Leaflet |
| Impressão | Blade (`resources/views/impressao/`) |

## Começando

Instalação, usuários de teste, comandos de importação e as armadilhas do MySQL
Spatial estão em **[COMO-RODAR.md](COMO-RODAR.md)**.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class=UsuariosSeeder
```

## Testes

```bash
php artisan test   # PHPUnit: tests/Feature, tests/Unit
npm test           # geometria do front-end: tests/*.test.cjs (Node, sem dependências)
```

Os scripts `tests/*-backend.php` são diagnósticos contra o banco MySQL real
(rodam numa transação desfeita no fim) e os `tests/*-browser.cjs` exigem um
navegador local; ver o cabeçalho de cada um.

## Estrutura

| Pasta | O quê |
|---|---|
| `app/Http/Controllers` | rotas da tela e da API interna (`routes/web.php`) |
| `app/Services` | regras de negócio: lavratura, desmembramento, unificação, importação, desfazer |
| `app/Repositories/LoteRepository.php` | **todo** o SQL espacial, concentrado |
| `app/Cadastro` | leitura e conferência do cadastro imobiliário da prefeitura (BCI) |
| `app/Support` | geometria plana na grade UTM, inscrição imobiliária |
| `app/Console/Commands` | importação, conferências e correções da base GIS |
| `public/js`, `public/css` | front-end (um arquivo por tela/ferramenta) |
| `resources/views/mapa.blade.php` | a aplicação (todas as telas) |
| `tools/` | utilitários de desenvolvimento (ícones) |

## Documentação

`docs/ai/` é a documentação viva do projeto:

| Arquivo | Para quê |
|---|---|
| [CONTEXTO.md](docs/ai/CONTEXTO.md) | o que o sistema é e as regras que não se negociam |
| [ESTADO-ATUAL.md](docs/ai/ESTADO-ATUAL.md) | o que funciona, o que falta, o que está quebrado |
| [TAREFA-ATUAL.md](docs/ai/TAREFA-ATUAL.md) | onde o trabalho parou |
| [ARQUITETURA.md](docs/ai/ARQUITETURA.md) | decisões técnicas e por quê |
| [ROADMAP.md](docs/ai/ROADMAP.md) | o que vem a seguir |
| [DECISOES-UX.md](docs/ai/DECISOES-UX.md) · [DESIGN-SYSTEM.md](docs/ai/DESIGN-SYSTEM.md) | telas e componentes |
| [CHANGELOG-IA.md](docs/ai/CHANGELOG-IA.md) | histórico comentado das entregas |
| [seguranca-servidor.md](docs/seguranca-servidor.md) | `.env`, Nginx, fail2ban, firewall e backup da produção |

### Documentos citados que ficam fora do repositório

O código cita alguns documentos que **não estão versionados aqui** de
propósito: diagnóstico da base e ADRs ficam na pasta de documentação do projeto
(OneDrive), e o roteiro de deploy fica só no servidor, por descrever a
infraestrutura (o `.gitignore` bloqueia `docs/deploy.md`).

| Citação | Onde está |
|---|---|
| `docs/ADR-001-banco-espacial.md` | OneDrive — por que MySQL Spatial e não PostGIS |
| `docs/etapa0-conclusoes.md`, `docs/etapa1-base-piloto.md` | OneDrive — diagnóstico da base GIS |
| `gis/tools/dxf_para_geojson.py` | OneDrive — conversão DWG/DXF → GeoJSON |
| `docs/deploy.md` | servidor de produção |
