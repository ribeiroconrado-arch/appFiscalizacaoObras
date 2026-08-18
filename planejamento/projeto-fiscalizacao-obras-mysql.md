# Sistema Municipal de Fiscalização de Obras — PHP + MySQL + GIS

## 1. Objetivo

Desenvolver uma aplicação web/PWA para fiscalização municipal de obras, permitindo que fiscais realizem vistorias em campo pelo celular e que a administração acompanhe todo o processo por um painel web.

A aplicação deverá integrar:

- Cadastro imobiliário
- Mapa GIS
- Lotes, quadras e bairros
- GPS
- Obras
- Vistorias
- Fotografias e evidências
- Irregularidades
- Legislação
- Notificações
- Autos de infração
- Embargos
- Prazos
- Assinaturas
- Histórico do imóvel
- Relatórios
- Auditoria

A persistência principal da aplicação será feita em **MySQL 8**.

---

## 2. Stack Tecnológica

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8.3+ |
| Framework | Laravel |
| Banco | MySQL 8.x |
| Dados espaciais | MySQL Spatial |
| Front-end | HTML5 + CSS3 + JavaScript |
| Interface | Bootstrap ou Tailwind CSS |
| Mapas | Leaflet |
| API | REST |
| Aplicativo | PWA |
| Preparação GIS | QGIS |
| Origem cartográfica | DWG |
| PDF | Dompdf ou equivalente |
| Versionamento | Git |
| Servidor | Linux + Nginx/Apache |

---

## 3. Arquitetura

```text
                    CELULAR DO FISCAL
                           │
                           ▼
                    PWA / NAVEGADOR
                           │
                  ┌────────┴────────┐
                  │                 │
                Leaflet           GPS
                  │                 │
                  └────────┬────────┘
                           │
                         HTTPS
                           │
                           ▼
                    PHP / LARAVEL
                           │
                  ┌────────┴────────┐
                  │                 │
                  API          Aplicação Web
                  │                 │
                  └────────┬────────┘
                           │
                           ▼
                         MYSQL 8
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
             GIS       CADASTRO     FISCALIZAÇÃO
```

---

## 4. Conceito GIS

O sistema terá duas fontes conceituais:

### Base espacial

- Bairros
- Quadras
- Lotes
- Logradouros
- Zoneamento
- Edificações, se disponível

### Base cadastral

- Inscrição
- Proprietário
- Endereço
- Área
- Características cadastrais
- Demais informações municipais

As bases serão relacionadas por um identificador cadastral estável.

```text
LOTE GIS
   │
   │ inscrição / codigo_imovel
   ▼
IMÓVEL CADASTRAL
   │
   ▼
OBRA
   │
   ▼
FISCALIZAÇÃO
```

---

## 5. DWG → GIS

Arquivo de origem:

`BAIRROS DE PVA DO LESTE 2026-04-06.dwg`

O conteúdo interno do DWG deverá ser analisado antes da conversão definitiva.

### Informações a validar

- Layers
- Bairros
- Quadras
- Lotes
- Logradouros
- Edificações
- Textos
- Cotas
- Coordenadas
- Sistema de referência
- Georreferenciamento
- Geometrias abertas
- Geometrias duplicadas
- Problemas topológicos

### Fluxo

```text
DWG
 ↓
QGIS
 ↓
Diagnóstico
 ↓
Georreferenciamento, se necessário
 ↓
Limpeza
 ↓
Separação das camadas
 ↓
Linhas → polígonos
 ↓
Validação
 ↓
MySQL Spatial
```

O arquivo DWG original não deverá ser alterado durante a preparação.

---

## 6. Camadas GIS

### bairros

```text
id
codigo
nome
geom
created_at
updated_at
```

Geometria: `MULTIPOLYGON`

### quadras

```text
id
codigo
bairro_id
numero
geom
created_at
updated_at
```

Geometria: `POLYGON / MULTIPOLYGON`

### lotes

```text
id
codigo_lote
quadra_id
bairro_id
inscricao_imobiliaria
area_gis
geom
created_at
updated_at
```

Geometria: `POLYGON / MULTIPOLYGON`

### logradouros

```text
id
codigo
nome
tipo
geom
created_at
updated_at
```

Geometria: `LINESTRING / MULTILINESTRING`

### zoneamentos

```text
id
codigo
nome
descricao
geom
created_at
updated_at
```

Geometria: `POLYGON / MULTIPOLYGON`

---

## 7. MySQL Spatial

O MySQL será utilizado também para armazenar as geometrias.

Exemplo conceitual:

```sql
CREATE TABLE lotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo_lote VARCHAR(50),
    quadra_id BIGINT UNSIGNED,
    bairro_id BIGINT UNSIGNED,
    inscricao_imobiliaria VARCHAR(50),
    area_gis DECIMAL(12,2),
    geom POLYGON NOT NULL SRID 4326,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    SPATIAL INDEX idx_lotes_geom (geom),
    INDEX idx_lotes_inscricao (inscricao_imobiliaria),
    INDEX idx_lotes_quadra (quadra_id),
    INDEX idx_lotes_bairro (bairro_id)
);
```

> O SRID definitivo deverá ser definido após a validação do DWG e da base municipal. Não assumir 4326 antecipadamente.

---

## 8. Relação GIS × Cadastro Imobiliário

Evitar duplicar desnecessariamente os dados cadastrais.

```text
GIS
┌──────────────────────┐
│ LOTE                 │
│ id                   │
│ geom                 │
│ inscricao_imobiliaria│
└──────────┬───────────┘
           │
           │ inscrição
           ▼
┌──────────────────────┐
│ IMÓVEL               │
│ id                   │
│ inscrição            │
│ proprietário_id      │
│ endereço_id          │
│ área                 │
└──────────────────────┘
```

A chave definitiva de integração deverá ser definida após análise do cadastro imobiliário.

**Nunca utilizar o nome do proprietário como chave de relacionamento.**

---

## 9. GPS → Lote → Imóvel

O fiscal seleciona:

**Usar minha localização**

Fluxo:

```text
GPS do celular
      ↓
latitude + longitude
      ↓
Laravel
      ↓
MySQL Spatial
      ↓
consulta espacial
      ↓
lote correspondente
      ↓
imóvel cadastral
      ↓
histórico
      ↓
nova vistoria
```

O sistema deverá possuir tolerância para imprecisão do GPS.

Quando houver mais de um lote possível:

```text
LOCALIZAÇÃO

Foram encontrados imóveis próximos:

○ Lote 08 — Rua X, 100
○ Lote 09 — Rua X, 102

[ CONFIRMAR ]
```

---

## 10. Mapa Web

Utilizar **Leaflet**.

```text
Leaflet
   ↓
GET /api/lotes
   ↓
Laravel
   ↓
MySQL Spatial
   ↓
GeoJSON
   ↓
Leaflet
```

Camadas:

- Mapa base
- Satélite
- Bairros
- Quadras
- Lotes
- Zoneamento
- Obras
- Fiscalizações
- Imóveis pendentes

---

## 11. Consulta de Imóvel

Ao clicar em um lote:

```text
┌───────────────────────────────┐
│ IMÓVEL                        │
├───────────────────────────────┤
│ Inscrição: 123456             │
│ Endereço: Rua X, 100          │
│ Bairro: Centro                │
│ Quadra: 15                    │
│ Lote: 08                      │
│                               │
│ Proprietário                  │
│ João da Silva                 │
│                               │
│ Área cadastral: 300 m²        │
│ Área GIS: 301 m²              │
├───────────────────────────────┤
│ OBRA                          │
│ Alvará: 456/2026              │
│ Situação: Em construção       │
├───────────────────────────────┤
│ HISTÓRICO                     │
│ 3 vistorias                   │
│ 1 notificação                 │
│ 1 auto                        │
├───────────────────────────────┤
│ [ NOVA VISTORIA ]             │
└───────────────────────────────┘
```

---

## 12. Módulos

### Autenticação

- Login
- Logout
- Recuperação de senha
- Sessão
- Perfis
- Permissões

### Usuários

- Administradores
- Coordenadores
- Fiscais
- Consulta

### Imóveis

- Consulta
- Cadastro complementar
- Histórico
- Localização
- Relação com lote GIS

### Obras

- Cadastro
- Alvará
- Projeto
- Responsável técnico
- Área
- Tipo
- Situação
- Histórico

### Vistorias

- Nova vistoria
- GPS
- Checklist
- Observações
- Fotos
- Evidências
- Assinatura
- Resultado

### Irregularidades

- Cadastro
- Classificação
- Gravidade
- Legislação
- Artigo
- Sanção
- Prazo

### Documentos

- Notificação
- Auto de infração
- Embargo
- Interdição
- Termos
- Relatório de vistoria
- Relatório fotográfico

### Prazos

- Data inicial
- Prazo
- Vencimento
- Situação
- Histórico

### Dashboard

- Vistorias
- Notificações
- Autos
- Embargos
- Regularizações
- Fiscalizações por bairro
- Irregularidades
- Prazos

---

## 13. Modelo de Dados

Estrutura inicial:

```text
users
roles
permissions

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

---

## 14. Tabela de Vistorias

```text
vistorias

id
imovel_id
obra_id
fiscal_id
latitude
longitude
accuracy
localizacao
data_hora_inicio
data_hora_fim
situacao
observacoes
created_at
updated_at
```

`localizacao` poderá ser um campo espacial `POINT`, além dos campos de latitude/longitude caso seja necessário manter ambos para integração e auditoria.

---

## 15. Evidências

```text
evidencias

id
vistoria_id
tipo
arquivo
descricao
latitude
longitude
data_hora
created_at
updated_at
```

Tipos:

```text
foto
video
audio
documento
```

Cada evidência pertence a uma vistoria específica.

---

## 16. Fotografias

O aplicativo deverá permitir:

- Câmera
- Galeria
- Múltiplas fotos
- Descrição
- Ordenação
- Visualização
- Exclusão antes do envio
- Upload seguro

Opcionalmente, aplicar marca d'água:

```text
Data
Hora
Fiscal
Vistoria
Coordenadas
```

---

## 17. Assinaturas

### Fiscal

Associada ao usuário autenticado.

### Autuado/Interessado

Capturada especificamente durante o documento.

Uma assinatura capturada em um documento não deverá ser automaticamente reutilizada em outro.

---

## 18. Motor de Legislação

Estrutura:

```text
IRREGULARIDADE
      ↓
LEGISLAÇÃO
      ↓
ARTIGO
      ↓
CONDUTA
      ↓
SANÇÃO
      ↓
DOCUMENTO
```

O objetivo é reduzir digitação e erros na fundamentação legal.

---

## 19. Fluxo da Fiscalização

```text
LOGIN
  ↓
MAPA
  ↓
GPS
  ↓
IDENTIFICAÇÃO DO IMÓVEL
  ↓
CONSULTA HISTÓRICO
  ↓
NOVA VISTORIA
  ↓
CHECKLIST
  ↓
IRREGULARIDADES
  ↓
FOTOS/EVIDÊNCIAS
  ↓
OBSERVAÇÕES
  ↓
ASSINATURA
  ↓
SALVAR
  ↓
GERAR DOCUMENTO
  ↓
PRAZO
  ↓
ACOMPANHAMENTO
```

---

## 20. Histórico do Imóvel

```text
IMÓVEL
 │
 ├── Vistoria
 ├── Notificação
 ├── Nova vistoria
 ├── Auto
 ├── Embargo
 └── Regularização
```

O histórico deverá ser cronológico e auditável.

---

## 21. Status

Sugestão:

```text
RASCUNHO
EM VISTORIA
AGUARDANDO DOCUMENTO
NOTIFICADO
EM PRAZO
PRAZO VENCIDO
REGULARIZADO
AUTO EMITIDO
EMBARGADO
ENCERRADO
CANCELADO
```

Os status definitivos deverão ser ajustados ao processo administrativo municipal.

---

## 22. API

Exemplos:

```text
GET    /api/imoveis
GET    /api/imoveis/{id}
GET    /api/lotes
GET    /api/lotes/{id}
GET    /api/mapa/lotes

GET    /api/obras
POST   /api/obras

GET    /api/vistorias
POST   /api/vistorias
PUT    /api/vistorias/{id}

POST   /api/vistorias/{id}/evidencias

GET    /api/irregularidades
GET    /api/legislacao

POST   /api/notificacoes
POST   /api/autos
```

---

## 23. Estrutura Laravel

```text
app/
├── Models/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
├── Services/
├── Repositories/
└── Policies/

database/
├── migrations/
├── seeders/
└── factories/

resources/
├── views/
├── js/
└── css/

routes/
├── web.php
└── api.php

storage/
├── app/
├── public/
└── logs/
```

---

## 24. Persistência

O MySQL será a fonte persistente principal.

Não utilizar como banco principal:

- JSON
- localStorage
- arquivos locais
- dados fictícios

O armazenamento local do navegador poderá existir apenas como apoio para:

- cache
- rascunhos
- funcionamento offline
- fila de sincronização

A fonte oficial será o MySQL.

---

## 25. Funcionamento Offline

A aplicação deverá ser preparada como PWA.

```text
Sem internet
    ↓
Salvar vistoria localmente
    ↓
Pendente de sincronização
    ↓
Internet retorna
    ↓
Enviar para Laravel
    ↓
Persistir no MySQL
    ↓
Confirmar sincronização
```

A sincronização deverá possuir identificadores únicos e mecanismos para evitar duplicação.

---

## 26. Segurança

Implementar:

- HTTPS
- Autenticação
- Autorização
- Laravel Sanctum ou mecanismo equivalente
- CSRF
- Validação
- Proteção contra SQL Injection
- Proteção de uploads
- Limite de tamanho
- Validação de MIME type
- Armazenamento privado de documentos
- Logs
- Auditoria
- Backups

---

## 27. Auditoria

Registrar:

```text
usuario
acao
tabela
registro_id
data_hora
ip
dados_anteriores
dados_novos
```

Eventos críticos:

- Criação
- Alteração
- Exclusão
- Emissão de documento
- Assinatura
- Mudança de status
- Alteração de prazo
- Embargo
- Desembargo

---

## 28. Backup

Estratégia mínima:

```text
Backup diário do MySQL
        +
Backup dos arquivos
        +
Retenção
        +
Teste periódico de restauração
```

---

## 29. Fases de Implementação

### Fase 1 — Fundação

- [ ] Criar Laravel
- [ ] Configurar MySQL
- [ ] Configurar autenticação
- [ ] Usuários
- [ ] Perfis
- [ ] Permissões

### Fase 2 — GIS

- [ ] Analisar DWG
- [ ] Georreferenciar, se necessário
- [ ] Preparar QGIS
- [ ] Criar bairros
- [ ] Criar quadras
- [ ] Criar lotes
- [ ] Validar geometrias
- [ ] Importar para MySQL Spatial
- [ ] Criar mapa Leaflet

### Fase 3 — Cadastro

- [ ] Estruturar imóveis
- [ ] Definir chave de integração
- [ ] Importar/consultar cadastro
- [ ] Relacionar lotes e imóveis
- [ ] Validar correspondências

### Fase 4 — Obras

- [ ] Obras
- [ ] Alvarás
- [ ] Responsáveis técnicos
- [ ] Situação
- [ ] Histórico

### Fase 5 — Fiscalização

- [ ] Vistoria
- [ ] GPS
- [ ] Checklist
- [ ] Irregularidades
- [ ] Fotografias
- [ ] Evidências
- [ ] Assinaturas

### Fase 6 — Documentos

- [ ] Notificações
- [ ] Autos
- [ ] Embargos
- [ ] Termos
- [ ] PDFs

### Fase 7 — Gestão

- [ ] Prazos
- [ ] Dashboard
- [ ] Relatórios
- [ ] Auditoria
- [ ] Mapas temáticos

### Fase 8 — PWA

- [ ] Instalação
- [ ] Cache
- [ ] Offline
- [ ] Fila de sincronização
- [ ] Sincronização segura

---

## 30. Estratégia de Piloto

Não iniciar pelo município inteiro.

Usar:

```text
1 bairro
   ↓
algumas quadras
   ↓
lotes
   ↓
cadastro imobiliário
   ↓
MySQL Spatial
   ↓
mapa
   ↓
GPS
   ↓
vistoria
   ↓
foto
   ↓
documento
```

Depois de validar, expandir para o restante do município.

---

## 31. Critérios de Sucesso do MVP

O fiscal deverá conseguir:

- [ ] Fazer login
- [ ] Abrir o mapa
- [ ] Visualizar lotes
- [ ] Usar GPS
- [ ] Identificar o imóvel
- [ ] Consultar cadastro
- [ ] Consultar histórico
- [ ] Criar vistoria
- [ ] Registrar irregularidade
- [ ] Tirar foto
- [ ] Salvar vistoria
- [ ] Gerar documento
- [ ] Consultar prazo

---

## 32. Regra Fundamental

O **imóvel é a entidade central**.

```text
                 MAPA
                  │
                  ▼
                 LOTE
                  │
                  ▼
                IMÓVEL
                  │
                  ▼
                 OBRA
                  │
                  ▼
               VISTORIA
                  │
        ┌─────────┼─────────┐
        ▼         ▼         ▼
     FOTOS    INFRAÇÕES   GPS
        │         │
        └────┬────┘
             ▼
        DOCUMENTOS
             │
             ▼
           PRAZOS
             │
             ▼
          HISTÓRICO
```

O mapa identifica **onde** está o imóvel.

O cadastro identifica **qual** é o imóvel.

A fiscalização registra **o que aconteceu**.

---

## 33. Próximo Passo Técnico

Antes de desenvolver todas as telas:

1. Diagnosticar o DWG;
2. Definir o sistema de coordenadas;
3. Criar uma pequena área piloto;
4. Converter os lotes para GIS;
5. Testar MySQL Spatial;
6. Definir a chave de relacionamento com o cadastro imobiliário;
7. Criar migrations Laravel;
8. Importar a base GIS;
9. Criar o mapa Leaflet;
10. Testar GPS → lote → imóvel;
11. Criar o primeiro fluxo de vistoria.

**Não iniciar pelo município inteiro. Validar primeiro o ciclo completo em uma pequena área.**
