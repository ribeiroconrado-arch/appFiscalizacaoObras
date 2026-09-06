# Editor de desmembramento

Na curadoria, selecione um lote e abra **Desmembrar lote direto**. Processos por protocolo usam a mesma mesa.

1. Em **Editor de divisas**, ligue os encaixes desejados: Endpoint (canto), Midpoint (meio do lado) e Perpendicular (projeção do ponto anterior na divisa). Um círculo verde indica a captura. A perpendicular é calculada no plano local métrico.
2. Clique em **Traçar a divisa**. Marque dois pontos para uma reta ou vários para uma linha quebrada, atravessando o lote. É possível começar e terminar nos cantos ou nos meios dos lados.
3. Use **Ajustar vértices**: arraste os pontos, arraste a alça intermediária para inserir um ponto, dê duplo clique em um ponto para removê-lo. Clique na medida de um segmento para digitar o comprimento. Os botões de desfazer/refazer ajuste recuperam posições anteriores.
4. Para precisão numérica, marque o primeiro ponto e use distância/azimute para adicionar os seguintes. Uma divisa reta pode ser deslocada paralelamente por uma distância com sinal; os extremos são estendidos para que continuem atravessando o lote.
5. A prévia colorida e as áreas se atualizam durante o ajuste. **Aplicar corte** só aceita uma divisão válida. Isso altera a mesa, ainda não o cadastro oficial.
6. Cada divisa aplicada aparece na lista e pode ser editada novamente. Divida uma parte para obter três ou mais imóveis. Alterações em divisas anteriores recalculam os cortes dependentes; se algum ficar inválido, o ajuste não é aplicado. Excluir uma divisa também remove seus cortes dependentes, com confirmação e possibilidade de desfazer.
7. Preencha os dados de cada imóvel. Salve como rascunho e retome depois pela mesma conta, no mesmo lote/processo. Rascunhos antigos que só possuem polígonos precisam de **Refazer o corte** para usar a lista de divisas editáveis.
8. **Conferir** valida a divisão no servidor. A confirmação final inativa o original e cria os sucessores em uma transação; o contorno, documentos e histórico do original permanecem preservados. Alterações após a conferência exigem uma nova conferência.

Limites: 20 partes; polígonos com vazios internos não são atendidos. As medidas digitadas para traçar a geometria são distintas das medidas de matrícula informadas nos campos de cada parte.

Verificação geométrica e do histórico de edição: `node --test tests/editor-cortes.test.cjs`.
