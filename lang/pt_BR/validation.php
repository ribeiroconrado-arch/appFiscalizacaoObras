<?php

/**
 * Mensagens de validação em português.
 *
 * O `.env` define APP_LOCALE=pt_BR desde a Etapa 2, mas sem arquivo de idioma
 * o Laravel devolvia a CHAVE crua — o usuário via "validation.required" em vez
 * de "O campo é obrigatório". Passou despercebido porque as telas tratavam o
 * erro pelo texto do controller; só apareceu quando a validação do formulário
 * de legislação falhou de verdade.
 *
 * Só as regras efetivamente usadas no sistema estão traduzidas. Acrescentar
 * uma regra nova sem a mensagem aqui volta a mostrar a chave crua.
 */
return [
    'required'      => 'O campo :attribute é obrigatório.',
    'string'        => 'O campo :attribute deve ser texto.',
    'integer'       => 'O campo :attribute deve ser um número inteiro.',
    'numeric'       => 'O campo :attribute deve ser um número.',
    'boolean'       => 'O campo :attribute deve ser sim ou não.',
    'array'         => 'O campo :attribute deve ser uma lista.',
    'email'         => 'Informe um e-mail válido.',
    'date'          => 'O campo :attribute não é uma data válida.',
    'date_format'   => 'O campo :attribute não está no formato :format.',
    'exists'        => 'O valor informado em :attribute não existe.',
    'unique'        => 'Este :attribute já está cadastrado.',
    'in'            => 'O valor de :attribute é inválido.',
    'regex'         => 'O formato de :attribute é inválido.',
    'confirmed'     => 'A confirmação de :attribute não confere.',
    'file'          => 'O campo :attribute deve ser um arquivo.',
    'mimetypes'     => 'O arquivo em :attribute deve ser do tipo: :values.',
    'image'         => 'O campo :attribute deve ser uma imagem.',

    'min' => [
        'numeric' => 'O campo :attribute deve ser no mínimo :min.',
        'string'  => 'O campo :attribute deve ter ao menos :min caracteres.',
        'array'   => 'O campo :attribute deve ter ao menos :min itens.',
        'file'    => 'O arquivo em :attribute deve ter ao menos :min kilobytes.',
    ],
    'max' => [
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'string'  => 'O campo :attribute não pode ter mais de :max caracteres.',
        'array'   => 'O campo :attribute não pode ter mais de :max itens.',
        'file'    => 'O arquivo em :attribute não pode ter mais de :max kilobytes.',
    ],
    'between' => [
        'numeric' => 'O campo :attribute deve estar entre :min e :max.',
        'string'  => 'O campo :attribute deve ter entre :min e :max caracteres.',
    ],

    /**
     * Nomes legíveis dos campos. Sem isto o usuário lê o nome da coluna do
     * banco ("legislacao_id"), que não diz nada a quem preenche o formulário.
     */
    'attributes' => [
        'email'                  => 'e-mail',
        'password'               => 'senha',
        'nome'                   => 'nome',
        'numero'                 => 'número',
        'ano'                    => 'ano',
        'ementa'                 => 'ementa',
        'legislacao_id'          => 'lei',
        'artigo_id'              => 'artigo',
        'irregularidades'        => 'irregularidades',
        'irregularidades.*'      => 'irregularidade',
        'artigos'                => 'artigos',
        'artigos.*'              => 'artigo',
        'prazo_defesa_dias'      => 'prazo de defesa',
        'prazo_cumprimento_dias' => 'prazo de cumprimento',
        'prazo_dias'             => 'prazo em dias',
        'multa_upf'              => 'multa em UPF',
        'conduta'                => 'conduta',
        'sancao'                 => 'sanção',
        'apelido'                => 'apelido',
        'tipo'                   => 'tipo',
        'situacao'               => 'situação',
        'data_hora'              => 'data e hora',
        'data_fato'              => 'data do fato',
        'observacoes'            => 'observações',
        'descricao'              => 'descrição',
        'autuado_nome'           => 'nome do autuado',
        'autuado_documento'      => 'documento do autuado',
        'endereco'               => 'endereço',
        'evidencias'             => 'evidências',
        'evidencias.*'           => 'evidência',
        'latitude'               => 'latitude',
        'longitude'              => 'longitude',
        'accuracy'               => 'precisão',
        'bbox'                   => 'área do mapa',
        'lat'                    => 'latitude',
        'lon'                    => 'longitude',
        'busca'                  => 'busca',
        'status'                 => 'status',
        'agente'                 => 'agente',
    ],
];
