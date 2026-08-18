# Sistema Municipal de Fiscalização de Obras + GIS

## 1. Visão do Projeto

Projeto de um sistema municipal para apoiar a fiscalização de obras em campo, integrando:

- Cadastro imobiliário;
- Base cartográfica/GIS;
- Georreferenciamento;
- Obras;
- Vistorias;
- Fotografias e evidências;
- Legislação municipal;
- Infrações;
- Notificações;
- Autos de infração;
- Embargos;
- Controle de prazos;
- Histórico de fiscalizações;
- Relatórios e indicadores.

A proposta é transformar o mapa municipal em uma interface operacional para o fiscal: localizar um imóvel, identificar automaticamente seus dados cadastrais, consultar o histórico e realizar uma nova fiscalização diretamente pelo celular.

---

## 2. Objetivo

Criar uma plataforma que permita:

1. Localizar imóveis por mapa, endereço, inscrição ou GPS;
2. Relacionar os polígonos dos lotes à base cadastral imobiliária;
3. Registrar fiscalizações em campo;
4. Capturar coordenadas GPS;
5. Registrar fotografias e demais evidências;
6. Identificar irregularidades;
7. Vincular cada irregularidade à legislação aplicável;
8. Gerar documentos fiscais;
9. Controlar prazos;
10. Manter histórico completo do imóvel;
11. Produzir mapas e indicadores da fiscalização;
12. Permitir futura expansão para outras áreas de fiscalização municipal.

---

# 3. Arquitetura Geral

```text
                         APLICATIVO
                            │
              ┌─────────────┴─────────────┐
              │                           │
          Fiscal Mobile              Administrativo
              │                           │
              └─────────────┬─────────────┘
                            │
                         HTTPS
                            │
                            ▼
                    PHP / Laravel
                            │
                  ┌─────────┴─────────┐
                  │                   │
                 API              Aplicação Web
                  │
                  ▼
             PostgreSQL
                  +
               PostGIS
                  │
       ┌──────────┼───────────┐
       ▼          ▼           ▼
      GIS      Cadastro    Fiscalização
```

## Tecnologias propostas

| Componente | Tecnologia |
|---|---|
| Backend | PHP 8.3+ |
| Framework | Laravel |
| Banco de dados | PostgreSQL |
| Banco espacial | PostGIS |
| Mapa | Leaflet |
| Front-end | HTML + CSS + JavaScript |
| Interface | Bootstrap ou Tailwind |
| API | REST |
| Aplicativo | PWA |
| Preparação GIS | QGIS |
| CAD de origem | DWG |
| Documentos | PDF |
| Armazenamento | Storage local ou objeto |
| Controle de versão | Git |

---

# 4. Base Cartográfica

## Arquivo de origem

Arquivo recebido:

`BAIRROS DE PVA DO LESTE 2026-04-06.dwg`

O conteúdo interno do DWG ainda deverá ser analisado tecnicamente antes da definição definitiva das camadas.

### Informações a validar

- Sistema de coordenadas;
- Datum;
- Fuso UTM;
- Existência de georreferenciamento;
- Layers existentes;
- Bairros;
- Quadras;
- Lotes;
- Logradouros;
- Edificações;
- Textos;
- Cotas;
- Polígonos fechados;
- Linhas abertas;
- Elementos duplicados;
- Topologia;
- Informações cadastrais incorporadas ao desenho.

---

# 5. Fluxo DWG → GIS

```text
DWG
 │
 ▼
Diagnóstico no QGIS
 │
 ▼
Identificação do sistema de coordenadas
 │
 ▼
Georreferenciamento, se necessário
 │
 ▼
Limpeza do desenho
 │
 ▼
Separação das camadas
 │
 ▼
Correção topológica
 │
 ▼
Conversão de linhas em polígonos
 │
 ▼
Criação dos atributos
 │
 ▼
Validação
 │
 ▼
PostgreSQL + PostGIS
 │
 ▼
Mapa Web
```

---

# 6. Camadas GIS

A estrutura inicial prevista é:

## Bairros

Geometria:

`POLYGON / MULTIPOLYGON`

Campos sugeridos:

```text
id
codigo
nome
geom
```

## Quadras

Geometria:

`POLYGON / MULTIPOLYGON`

Campos:

```text
id
codigo
bairro_id
numero
geom
```

## Lotes

Geometria:

`POLYGON / MULTIPOLYGON`

Campos:

```text
id
codigo_lote
quadra_id
bairro_id
inscricao_imobiliaria
area_gis
geom
```

## Logradouros

Geometria:

`LINESTRING / MULTILINESTRING`

Campos:

```text
id
codigo
nome
tipo
geom
```

## Zoneamento

Geometria:

`POLYGON / MULTIPOLYGON`

Campos:

```text
id
codigo
nome
descricao
geom
```

---

# 7. Relação GIS × Cadastro Imobiliário

O GIS não deve necessariamente armazenar todos os dados cadastrais.

A geometria do lote deve ser relacionada ao imóvel por uma chave cadastral estável.

Modelo:

```text
                 LOTE GIS
                    │
             codigo/inscricao
                    │
                    ▼
          CADASTRO IMOBILIÁRIO
                    │
          ┌─────────┼─────────┐
          ▼         ▼         ▼
     Proprietário Endereço  Área
```

## Chave de integração

A definir após análise da base cadastral.

Possibilidades:

- inscrição imobiliária;
- código único do imóvel;
- código cadastral;
- outro identificador municipal estável.

### Regra

Não utilizar nome do proprietário como chave de relacionamento.

---

# 8. Modelo Conceitual

```text
BAIRRO
  │
  └── QUADRA
        │
        └── LOTE
              │
              └── IMÓVEL
                    │
                    ├── PROPRIETÁRIO
                    ├── ENDEREÇO
                    └── OBRA
                          │
                          └── VISTORIA
                                │
                                ├── FOTOS
                                ├── IRREGULARIDADES
                                ├── OBSERVAÇÕES
                                ├── GPS
                                └── DOCUMENTOS
                                      │
                                      ├── NOTIFICAÇÃO
                                      ├── AUTO
                                      ├── EMBARGO
                                      └── OUTROS
```

---

# 9. Fiscalização por GPS

O fiscal poderá iniciar uma fiscalização utilizando sua localização.

Fluxo:

```text
Fiscal
  │
  ▼
"Usar minha localização"
  │
  ▼
GPS do celular
  │
  ▼
Latitude + Longitude
  │
  ▼
API Laravel
  │
  ▼
PostGIS
  │
  ▼
Identificação do lote
  │
  ▼
Imóvel cadastral
  │
  ▼
Histórico
  │
  ▼
Nova vistoria
```

## Tolerância GPS

A identificação não deverá depender exclusivamente de uma interseção exata.

Deve existir uma tolerância configurável, por exemplo:

```text
GPS
 ↓
Busca espacial
 ↓
Lotes próximos
 ↓
Possíveis imóveis
 ↓
Confirmação do fiscal
```

O valor definitivo da tolerância deverá ser validado em campo.

---

# 10. Tela Inicial do Fiscal

A tela principal deverá priorizar ações de campo:

```text
┌─────────────────────────────┐
│ Fiscalização                │
├─────────────────────────────┤
│                             │
│       MAPA                  │
│                             │
│     📍 Minha posição        │
│                             │
├─────────────────────────────┤
│ [ NOVA VISTORIA ]           │
│ [ PESQUISAR IMÓVEL ]        │
│ [ MINHAS VISTORIAS ]        │
│ [ PENDÊNCIAS ]              │
└─────────────────────────────┘
```

---

# 11. Consulta do Imóvel

Ao selecionar um lote:

```text
IMÓVEL

Inscrição: XXXXXXX
Endereço: XXXXXXX
Bairro: XXXXXXX
Quadra: XX
Lote: XX

Área cadastral: XXX m²
Área GIS: XXX m²

PROPRIETÁRIO
XXXXXXXX

OBRA
Situação: XXXXX
Alvará: XXXXX
Área construída: XXX m²

HISTÓRICO
- Vistoria
- Notificação
- Auto
- Regularização

[ NOVA VISTORIA ]
```

---

# 12. Módulo de Obras

Campos previstos:

- Tipo da obra;
- Situação;
- Alvará;
- Projeto aprovado;
- Responsável técnico;
- CREA/CAU;
- Área construída;
- Área do terreno;
- Número de pavimentos;
- Data de início;
- Situação da obra;
- Observações;
- Coordenadas;
- Fotografias.

---

# 13. Módulo de Vistoria

## Identificação

- Imóvel;
- Obra;
- Fiscal;
- Data;
- Hora;
- GPS.

## Checklist

Exemplos:

- Construção sem licença;
- Obra em desacordo com projeto;
- Recuo irregular;
- Ocupação irregular;
- Calçada irregular;
- Obstrução de passeio;
- Material em via pública;
- Ausência de tapume;
- Ausência de placa;
- Situação de risco;
- Outras irregularidades.

A lista definitiva deverá ser baseada na legislação municipal aplicável.

---

# 14. Evidências

Cada vistoria poderá possuir:

- Fotografias;
- Vídeos;
- Áudios;
- Documentos;
- Observações;
- Assinaturas.

Cada evidência deve ser vinculada à vistoria.

## Fotografias

Registrar, quando disponível:

```text
vistoria_id
data_hora
latitude
longitude
usuario_id
arquivo
descricao
```

Não reutilizar automaticamente fotografias ou assinaturas de outra vistoria.

---

# 15. Motor de Legislação

O fiscal não deve precisar procurar manualmente todos os artigos.

Modelo:

```text
IRREGULARIDADE
      │
      ▼
LEGISLAÇÃO
      │
      ▼
ARTIGO
      │
      ▼
CONDUTA
      │
      ▼
SANÇÃO
      │
      ▼
DOCUMENTO
```

Exemplo conceitual:

```text
Construção sem alvará
        ↓
Lei X
        ↓
Artigo Y
        ↓
Infração
        ↓
Multa / Notificação / Embargo
```

A legislação específica deverá ser cadastrada e validada posteriormente.

---

# 16. Documentos

Documentos previstos:

- Relatório de vistoria;
- Notificação;
- Auto de infração;
- Auto de embargo;
- Auto de interdição;
- Termo de intimação;
- Termo de desembargo;
- Relatório fotográfico;
- Outros documentos municipais.

Os documentos devem utilizar os dados já registrados na vistoria, evitando redigitação.

---

# 17. Controle de Prazos

Dashboard:

```text
🔴 Vencidos
🟠 Vencendo hoje
🟡 Próximos do vencimento
🟢 Dentro do prazo
```

Cada prazo deverá possuir:

```text
documento
data_inicio
prazo_dias
data_vencimento
status
```

---

# 18. Histórico do Imóvel

O imóvel será o elemento central do histórico.

Exemplo:

```text
IMÓVEL
 │
 ├── 01/02/2026 - Vistoria
 │
 ├── 03/02/2026 - Notificação
 │
 ├── 15/02/2026 - Nova vistoria
 │
 ├── 16/02/2026 - Auto
 │
 ├── 20/02/2026 - Embargo
 │
 └── 10/03/2026 - Regularização
```

Isso permitirá visualizar toda a trajetória fiscal do imóvel.

---

# 19. Dashboard Administrativo

Indicadores:

- Total de vistorias;
- Vistorias por período;
- Vistorias por fiscal;
- Obras irregulares;
- Notificações;
- Autos;
- Embargos;
- Regularizações;
- Prazos vencidos;
- Irregularidades mais frequentes;
- Fiscalizações por bairro.

## Mapa temático

Possíveis classificações:

```text
🟢 Regular
🟡 Em acompanhamento
🟠 Notificado
🔴 Irregular
⚫ Embargado
```

---

# 20. Perfis de Usuário

## Administrador

- Usuários;
- Permissões;
- Configurações;
- Legislação;
- Cadastros;
- Auditoria.

## Coordenador

- Dashboard;
- Fiscalizações;
- Relatórios;
- Acompanhamento;
- Distribuição de demandas.

## Fiscal

- Mapa;
- Imóveis;
- Vistorias;
- Fotos;
- Notificações;
- Autos;
- Pendências.

## Consulta

- Visualização limitada.

---

# 21. Banco de Dados Inicial

Estrutura conceitual:

```text
usuarios
fiscais
bairros
quadras
lotes
logradouros
zoneamentos

imoveis
proprietarios
enderecos

obras
responsaveis_tecnicos
vistorias
irregularidades
legislacoes
artigos
sancoes

vistoria_irregularidades
evidencias
documentos
notificacoes
autos
embargos
prazos
assinaturas

auditoria
```

As tabelas definitivas deverão ser normalizadas durante o projeto do banco.

---

# 22. PostGIS

O PostGIS será responsável pelos dados espaciais.

Exemplos de operações:

- localizar lote por coordenada;
- localizar lotes próximos;
- descobrir bairro;
- descobrir quadra;
- verificar zona;
- calcular distância;
- verificar interseções;
- gerar consultas espaciais.

Exemplo conceitual:

```sql
SELECT *
FROM lotes
WHERE ST_Contains(
    geom,
    ST_SetSRID(
        ST_Point(:longitude, :latitude),
        4326
    )
);
```

O SRID definitivo deverá ser definido de acordo com o sistema de coordenadas adotado para a base municipal.

---

# 23. Segurança

Requisitos:

- HTTPS;
- autenticação;
- autorização por perfil;
- senhas com hash seguro;
- controle de sessão;
- proteção contra SQL Injection;
- proteção CSRF;
- validação de uploads;
- controle de acesso aos documentos;
- logs;
- auditoria;
- backups;
- controle de alterações.

---

# 24. Auditoria

Operações críticas deverão registrar:

```text
usuario
data_hora
acao
registro
valor_anterior
valor_novo
ip
dispositivo, quando disponível
```

Especialmente:

- criação de vistoria;
- alteração de vistoria;
- emissão de documento;
- assinatura;
- alteração de infração;
- alteração de prazo;
- cancelamento;
- embargo;
- desembargo.

---

# 25. Fases de Desenvolvimento

## Fase 1 — Diagnóstico GIS

- [ ] Analisar DWG;
- [ ] Identificar layers;
- [ ] Verificar coordenadas;
- [ ] Identificar sistema de referência;
- [ ] Verificar necessidade de georreferenciamento;
- [ ] Selecionar uma área piloto.

## Fase 2 — Preparação GIS

- [ ] Georreferenciar DWG, se necessário;
- [ ] Limpar geometria;
- [ ] Separar camadas;
- [ ] Criar polígonos de lotes;
- [ ] Criar quadras;
- [ ] Criar bairros;
- [ ] Criar logradouros;
- [ ] Validar topologia.

## Fase 3 — Cadastro

- [ ] Obter estrutura do cadastro imobiliário;
- [ ] Identificar chave de relacionamento;
- [ ] Fazer correspondência lote × imóvel;
- [ ] Identificar registros sem correspondência;
- [ ] Validar resultados.

## Fase 4 — Banco

- [ ] Criar PostgreSQL;
- [ ] Instalar PostGIS;
- [ ] Criar tabelas;
- [ ] Criar índices;
- [ ] Importar camadas GIS;
- [ ] Criar relacionamentos.

## Fase 5 — Aplicação

- [ ] Criar Laravel;
- [ ] Autenticação;
- [ ] Usuários;
- [ ] Mapa;
- [ ] Consulta de imóveis;
- [ ] GPS;
- [ ] Vistorias;
- [ ] Fotografias;
- [ ] Irregularidades;
- [ ] Documentos.

## Fase 6 — Fiscalização

- [ ] Notificações;
- [ ] Autos;
- [ ] Embargos;
- [ ] Prazos;
- [ ] Histórico;
- [ ] Assinaturas;
- [ ] Auditoria.

## Fase 7 — Gestão

- [ ] Dashboard;
- [ ] Indicadores;
- [ ] Relatórios;
- [ ] Mapas temáticos;
- [ ] Exportações.

---

# 26. Estratégia de Piloto

Não iniciar pelo município inteiro.

Utilizar uma pequena área:

```text
1 bairro
  ↓
algumas quadras
  ↓
lotes
  ↓
cadastro imobiliário
  ↓
PostGIS
  ↓
mapa
  ↓
GPS
  ↓
vistoria
```

Somente depois de validar o ciclo completo ampliar para toda a cidade.

---

# 27. Critério de Sucesso do GIS

O piloto será considerado funcional quando for possível:

1. Abrir o mapa;
2. Visualizar os lotes;
3. Clicar em um lote;
4. Identificar o imóvel;
5. Consultar os dados cadastrais;
6. Obter a posição GPS do fiscal;
7. Identificar o lote correspondente;
8. Criar uma vistoria;
9. Registrar fotos;
10. Salvar o histórico da fiscalização.

---

# 28. Próximo Passo

O próximo passo técnico não é programar o aplicativo.

É analisar o arquivo:

`BAIRROS DE PVA DO LESTE 2026-04-06.dwg`

e determinar:

1. quais layers existem;
2. como os bairros estão representados;
3. se existem quadras;
4. se existem lotes;
5. se os lotes são linhas ou polígonos;
6. se há coordenadas;
7. qual CRS pode ser utilizado;
8. quais informações podem ser aproveitadas;
9. quais correções serão necessárias;
10. como preparar a primeira área piloto.

Após essa análise, deve ser elaborado o **modelo GIS definitivo** antes da importação para o PostGIS.
