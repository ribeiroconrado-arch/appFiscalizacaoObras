# Roteiro para documentar o AppFiscalizaçãoObras com IA

## Como usar

Abra este projeto em uma IA com acesso aos arquivos e envie:

> Leia `docs/ROTEIRO-DOCUMENTACAO-IA.md` e execute suas instruções. Produza a documentação no repositório, conferindo as informações no código atual. Comece pelo inventário e pelo guia “Onde fica cada coisa” e prossiga até concluir os entregáveis. Não implemente funcionalidades nem altere o comportamento da aplicação.

O conteúdo abaixo é a instrução de execução para a IA.

---

## Missão

Atue como arquiteto de software e redator técnico. Documente a aplicação AppFiscalizaçãoObras em português do Brasil, com precisão, clareza e manutenção simples.

O principal resultado deve ser um guia resumido que permita encontrar rapidamente **onde cada funcionalidade aparece na interface e onde seu comportamento é implementado no código**. Complemente esse guia com documentação técnica suficiente para manutenção, evolução e transferência do projeto a outro profissional ou IA.

Execute o trabalho; não entregue apenas uma proposta. Resolva escolhas rotineiras com base no código e registre dúvidas que não puder esclarecer. Só solicite informação quando ela realmente impedir o avanço, continuando as partes independentes.

## Limites e cuidados

1. Leia as instruções aplicáveis do projeto, incluindo arquivos `AGENTS.md`, antes de editar.
2. Confira o estado do Git. Preserve alterações preexistentes e arquivos de trabalho do usuário. Documente o estado observado sem presumir que arquivos locais estejam publicados ou concluídos.
3. Altere somente documentação. Não modifique código, banco, configurações, dependências ou infraestrutura. Não faça commit, push ou deploy.
4. Não execute migrações, seeders, importações, rotinas de manutenção ou testes que possam gravar em bancos reais. Priorize inspeção estática; só use verificações locais sem efeitos colaterais conhecidos.
5. Não leia nem reproduza segredos de `.env`, backups de ambiente, chaves privadas ou credenciais. Use exemplos sanitizados e nomes de variáveis, consultando `.env.example` quando necessário.
6. Não inclua dados pessoais reais, registros de contribuintes, fotos de fiscalização ou detalhes privados de infraestrutura. Documente estruturas e comportamentos com exemplos fictícios.
7. Não invente funcionalidades, caminhos, métodos, tabelas, regras, cobertura de testes ou garantias de segurança. Diferencie implementação observada, intenção documentada e ponto não verificado.
8. Se identificar um possível defeito, registre a evidência e o impacto em uma pendência; não o corrija nesta tarefa.
9. Use o código como fonte do comportamento implementado. Documentos antigos explicam intenção e histórico, mas precisam ser conferidos. Não trate regras implementadas como validação jurídica da legislação.
10. Não copie este roteiro inteiro para os entregáveis. Ele é uma instrução de trabalho, não a documentação da aplicação.

## Etapa 1 — Inventário e diagnóstico

Leia primeiro `README.md`, `COMO-RODAR.md`, os documentos relevantes em `docs/ai/` e os manifestos de dependências. Inspecione as pastas reais antes de assumir nomes ou responsabilidades.

Mapeie progressivamente:

- Telas, menus e pontos de entrada da interface.
- JavaScript, CSS, templates e carregamento dos arquivos.
- Rotas, controllers, validações, middlewares e verificações de permissão.
- Serviços, modelos, consultas, jobs e comandos.
- Migrations, relacionamentos, índices e estruturas espaciais.
- Integrações, arquivos enviados, impressão e geração de PDF.
- Testes existentes e documentação operacional.

Use buscas direcionadas. Não carregue recursivamente `vendor/`, dependências, logs, dados GIS volumosos ou todos os arquivos do projeto no contexto. Consulte arquivos de planejamento apenas quando forem relevantes para explicar intenção ou decisões.

Registre divergências entre documentação e código. Confira especialmente contagens fixas, caminhos antigos, afirmações absolutas como “todas as consultas passam aqui” e declarações de funcionalidades concluídas.

## Etapa 2 — Guia rápido: onde fica cada coisa

Crie `docs/ONDE-FICA-CADA-COISA.md`. Este é o entregável prioritário.

O guia deve ser consultável em aproximadamente um minuto, com uma linha por funcionalidade principal e links para detalhes. Evite transformar a tabela em um inventário de todos os arquivos.

Use a estrutura:

| Quero consultar ou alterar… | Onde acessar na interface | Por onde começar no código | Processamento principal | Detalhes |
|---|---|---|---|---|
| Nome da funcionalidade | Menu → tela → ação, conferidos | Link para arquivo principal | Link para controller ou serviço | Link para documentação do módulo |

Inclua somente funcionalidades encontradas. Investigue, sem presumir existência ou conclusão: mapa, busca, cadastro imobiliário, curadoria de lotes, vistorias, documentos, legislação, demandas, denúncias, ordens de serviço, protocolos, importações, painel, perfil, parâmetros, permissões, auditoria e impressão.

Acrescente uma tabela curta para mudanças transversais: layout, cores e componentes, rotas, autenticação, acesso a dados e cabeçalho de impressão. Para uma função sem tela própria, informe o gatilho real: comando, rotina, evento ou chamada interna.

## Etapa 3 — Documentação por módulo

Crie páginas em `docs/modulos/`, agrupadas por domínio real. Não crie páginas vazias nem uma página para cada arquivo.

Use o seguinte modelo, omitindo itens sem aplicação e identificando itens não verificados:

### Nome do módulo

1. **Finalidade:** explicação curta em linguagem de negócio.
2. **Acesso:** tela, caminho na interface e perfis que podem utilizá-la.
3. **Fluxo principal:** ação do usuário, requisição, validação, regra, persistência e resposta.
4. **Onde acontece:** tabela com responsabilidade, arquivo, classe ou função e observações.
5. **Rotas relevantes:** método HTTP, caminho, handler e controle de acesso efetivo.
6. **Dados:** modelos, tabelas, relações, arquivos e efeitos importantes.
7. **Regras:** condições, estados, cálculos, restrições e exceções encontrados.
8. **Dependências e impacto:** quais módulos são afetados por alterações.
9. **Verificação:** testes existentes, o que verificam e roteiro manual seguro quando necessário.
10. **Limitações e pendências:** questões observadas, com evidência e sem suposições apresentadas como fatos.

Siga chamadas e referências para validar cada fluxo. Não deduza uma regra apenas pelo nome do arquivo. Não suponha que ocultar um botão na interface equivale a autorização no servidor. Registre quando uma responsabilidade estiver distribuída entre vários arquivos.

Documente estados e transições em tabela quando existirem. Use Mermaid apenas para relações ou fluxos em que o diagrama realmente facilite a compreensão; mantenha até cinco nós na horizontal.

## Etapa 4 — Referência técnica consolidada

Reaproveite documentos existentes como fontes principais sempre que forem adequados. Crie novos documentos apenas para lacunas, evitando versões concorrentes do mesmo assunto.

Cubra:

| Assunto | Conteúdo esperado |
|---|---|
| Arquitetura | Organização, responsabilidades, tecnologias, integrações e fluxo de requisição |
| Banco de dados | Tabelas por domínio, relações, campos relevantes, índices, dados espaciais e origem da evidência |
| Regras de negócio | Regras transversais e links para as regras específicas dos módulos |
| Permissões | Matriz de ações por perfil, confrontando interface e controles de servidor |
| Interface | Templates, componentes, estilos, padrões e carregamento de scripts |
| Operação | Instalação, execução, publicação, backup, restauração e diagnóstico, sem segredos |
| Decisões | Escolhas arquiteturais, justificativas verificadas e consequências |

Para o banco, diferencie esquema descrito por migrations e esquema efetivamente verificado. Não afirme ter inspecionado produção se isso não ocorreu. Quando a justificativa de uma decisão não estiver registrada, marque como desconhecida em vez de inventá-la.

Não reproduza instruções operacionais potencialmente destrutivas sem contexto, pré-condições e impacto. Preserve a separação existente entre documentação compartilhável e procedimentos restritos.

## Etapa 5 — Índice e contexto para IA

Crie `docs/README.md` como índice central e atualize o `README.md` da raiz para apontar para ele e para o guia rápido.

Organize a leitura em três percursos:

- **Localizar uma funcionalidade:** guia rápido → módulo.
- **Entender ou manter a aplicação:** visão geral → arquitetura → dados → módulo → verificações.
- **Continuar trabalho com IA:** contexto essencial → guia rápido → arquivos e documentação da tarefa.

Revise os documentos de contexto existentes em `docs/ai/`, sem apagar decisões históricas úteis. Mantenha contexto estável, estado atual, tarefa em andamento e histórico com responsabilidades distintas. Não substitua o registro de uma tarefa preexistente por esta tarefa de documentação.

O contexto inicial para IA deve ser breve e apontar para os detalhes. Não exija a leitura de toda a documentação para uma mudança pequena.

## Padrão editorial e rastreabilidade

- Use Markdown em UTF-8, títulos claros, frases diretas e linguagem consistente.
- Use links relativos para navegação entre documentos e arquivos do repositório.
- Prefira arquivo + classe/método/função a números de linha que ficam desatualizados.
- Explique o que acontece, onde acontece e por que importa. Evite transcrever grandes blocos de código.
- Mantenha uma fonte principal por assunto; os demais documentos devem apontar para ela.
- Identifique a data de revisão e a referência Git observada quando disponível. Se houver alterações locais relevantes, informe que a revisão também considera o estado de trabalho local.
- Separe claramente: implementado e verificado no código; descrito mas não confirmado; planejado.
- Use exemplos fictícios e mínimos. Não transforme documentação técnica em material promocional.
- Evite contagens de arquivos e versões fixas sem necessidade. Quando necessárias, indique a fonte.

## Etapa 6 — Validação final

Antes de concluir:

1. Confira se todos os caminhos e links locais novos ou alterados existem.
2. Verifique se cada funcionalidade principal inventariada está no guia e aponta para seu módulo.
3. Reconfira os fluxos de maior impacto, quando existentes: gravação de vistoria, lavratura, impressão, alteração cadastral e controle de acesso.
4. Confirme rotas e permissões pelas definições e verificações efetivas no código.
5. Remova duplicações e resolva contradições; registre as que exigirem informação externa.
6. Revise o diff e confirme que suas alterações atingem apenas documentação, preservando o trabalho preexistente.
7. Não declare testes executados, funcionalidades funcionando ou produção validada sem evidência. Diferencie inspeção estática de teste em execução.

## Critérios de conclusão

- Existe um guia curto e navegável de localização funcional e técnica.
- Os módulos principais têm fluxos, arquivos, regras, dados e verificações documentados.
- A documentação existente foi aproveitada e as divergências encontradas foram corrigidas ou registradas.
- O índice central permite encontrar a informação sem ler todos os documentos.
- As afirmações técnicas têm referência verificável no projeto.
- Nenhuma mudança funcional foi feita.

## Entrega ao usuário

Ao finalizar, apresente uma resposta breve com links para o índice, o guia rápido e os principais documentos criados ou atualizados. Informe quais verificações foram realizadas e quais limitações relevantes permaneceram.

Se houver bloqueios reais, entregue o que estiver concluído e identifique precisamente o que falta e a informação necessária. Não apresente uma documentação parcial como completa.
