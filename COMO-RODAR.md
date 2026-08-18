# Fiscalização de Obras — como rodar

Sistema municipal de fiscalização de obras de Primavera do Leste/MT.
Documentação do projeto (diagnóstico GIS, decisões, plano) fica em
`OneDrive\Programação\Apps\appFiscalizaçãoObras\docs\`.

## Endereço

<http://fiscalizacao-obras.test> — servido pelo Laravel Herd, que atende
automaticamente qualquer pasta em `%USERPROFILE%\Herd`.

## Ambiente

| | |
|---|---|
| PHP | 8.4 (Herd) |
| Laravel | 13 |
| Banco | MySQL 8.0.46, base `fiscalizacao_obras`, usuário `fiscalizacao` |
| CRS | armazenamento EPSG:4326 · origem EPSG:31981 (SIRGAS 2000 / UTM 21S) |

## Usuários de teste

Criados por `UsuariosSeeder`. Senha de todos: **`Trocar@2026`**
(sobrescrevível por `SEED_SENHA_ADMIN` no `.env`).

| E-mail | Perfil gravado | Cargo | Perfil **aplicado** |
|---|---|---|---|
| admin@primaveradoleste.mt.gov.br | admin | agente | Administrador |
| fiscal@primaveradoleste.mt.gov.br | comum | agente | Comum |
| coordenacao@primaveradoleste.mt.gov.br | comum | coordenador | **Visualizador** |

A terceira linha não é erro de cadastro: é o caso de teste da trava. Só
`tipo_usuario = 'agente'` pode ter perfil acima de `viewer` — qualquer outro
cargo é rebaixado por `User::perfilEfetivo()`, mesmo que o banco diga outra
coisa. Se essa linha um dia aparecer como "Comum", a regra quebrou.

> **Trocar essas senhas antes de qualquer uso fora da rede local.**

## Comandos

```bash
php artisan migrate
php artisan db:seed --class=UsuariosSeeder
php artisan gis:importar-lotes storage/app/gis/lotes_jardim_europa.geojson --substituir
```

`--substituir` apaga apenas os lotes dos bairros presentes no arquivo, nunca a
tabela toda: o município será importado bairro a bairro, e um `TRUNCATE`
acidental custaria semanas de trabalho.

Ao final da importação o comando confere sozinho SRID, validade das geometrias
e unicidade da chave. Um SRID errado não gera erro — gera mapa vazio, que é
muito pior de diagnosticar.

## Rotas

| Método | Rota | Acesso |
|---|---|---|
| GET | `/entrar` · POST `/entrar` | público (5 tentativas/min) |
| POST | `/sair` | — |
| GET | `/` | autenticado — o mapa |
| GET | `/api/mapa/lotes?bbox=O,S,L,N` | autenticado |
| POST | `/api/localizacao/identificar` | autenticado + CSRF |
| GET | `/api/irregularidades` | autenticado |
| GET | `/api/lotes/{lote}/historico` | autenticado |
| POST | `/api/lotes/{lote}/vistorias` | autenticado + `canEdit()` |
| DELETE | `/api/evidencias/{evidencia}` | autenticado + **autor** |
| GET | `/evidencias/{evidencia}/arquivo` | autenticado |

### Regras de autorização em vigor

- **Visualizador não registra vistoria** — botão desabilitado na tela *e* 403 no
  controller. A tela é conveniência; a regra real está no servidor.
- **Evidência só pode ser excluída por quem a cadastrou** — e **admin não é
  exceção**: a regra é de autoria, não de perfil. Quem lavra responde pelo que
  anexou.
- **Vistoria "irregular" exige ao menos uma irregularidade marcada** — sem isso
  o registro não sustenta documento nenhum na Etapa 6.

### Evidências ficam em disco privado

`storage/app/private/evidencias/{vistoria}/`, servidas por rota autenticada.
Nunca em `public/`: foto de fiscalização mostra o interior de propriedade
privada e identifica pessoas. Verificado — sem sessão a rota redireciona ao
login, e o caminho público devolve 403.

As rotas de API vivem em `routes/web.php`, no grupo `web`, para herdarem sessão
e CSRF. O cliente é a própria tela do mapa; exigir um segundo mecanismo de
credencial só para o front-end falar com o próprio back-end seria complicação
sem ganho. Quando houver consumidor externo, aí entra Sanctum.

## Onde as coisas estão

| Caminho | O quê |
|---|---|
| `app/Repositories/LoteRepository.php` | **todo** o SQL espacial, concentrado |
| `app/Http/Controllers/MapaController.php` | API de mapa e identificação por GPS |
| `app/Console/Commands/ImportarLotes.php` | importação de GeoJSON |
| `config/gis.php` | tolerância de GPS, teto de lotes, SRIDs |
| `resources/views/mapa.blade.php` | a tela do fiscal |
| `public/css/app.css` · `public/js/` | design system e front-end |
| `storage/app/gis/` | GeoJSON do piloto |

## Armadilhas do MySQL Spatial já mapeadas

Estão documentadas em `docs/ADR-001-banco-espacial.md` do projeto. As três que
mordem em silêncio:

1. **Ordem dos eixos.** Em SRID 4326 o MySQL usa lat/long. Todo WKT precisa de
   `'axis-order=long-lat'`. Sem isso a consulta não falha — devolve vazio, ou o
   lote errado.
2. **`ST_Centroid`, `ST_Envelope` e `ST_Buffer` não existem para SRS
   geográfico.** Levantam `ERROR 3618` só em tempo de execução. Use
   `ST_Distance(polígono, ponto)`, que funciona e devolve metros até a divisa.
3. **Não existe `ST_DWithin`.** A tolerância se faz com envelope
   (`MBRIntersects`, que usa o índice) e depois `ST_Distance` para ordenar.

## Assets

CSS e JS são estáticos, sem Vite — o front-end veio pronto do AppPOSTURAS. A
diretiva `@assetv('css/app.css')` acrescenta `?v={filemtime}` para o cache do
navegador cair a cada edição. Sem isso, alterar o CSS e não ver diferença é o
resultado esperado, não um bug.

## Pendências

- **Chave `bairro|quadra|lote` ainda não é única** (~5% de repetição, por erro
  de atribuição de quadra). Ver `docs/etapa1-base-piloto.md`. Só trava a Etapa 4.
- Cadastro de usuários pela interface (hoje só por seeder).
- Auditoria, vistorias, documentos: Etapas 5 a 7.
