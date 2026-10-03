<?php

namespace App\Cadastro;

/**
 * O que uma fonte de cadastro devolve sobre um imóvel: dados do terreno, o
 * quadro de características e as construções.
 *
 * É um retrato, não um registro — não se salva e não sabe de que lote é. A aba
 * BCI o lê AO VIVO do cadastro municipal carregado (não há mais cópia por
 * lote). Assim a mesma classe serve para a planilha de hoje e para o banco da
 * prefeitura de amanhã.
 */
class RetratoBci
{
    /**
     * @param  array<string,mixed>    $imovel           campos do terreno
     * @param  array<string,?string>  $caracteristicas  chave => valor, na ordem do cadastro
     * @param  list<array<string,mixed>>  $unidades     construções
     */
    public function __construct(
        public array $imovel,
        public array $caracteristicas = [],
        public array $unidades = [],
    ) {
    }

    /**
     * O imóvel está ativo no cadastro?
     *
     * A prefeitura não tem um campo "situação": quem responde é a Isenção.
     * Qualquer valor que não seja "Inativo" — Normal, Isento, e o que mais
     * vier — é imóvel ativo. Sem isenção nenhuma, o cadastro não disse nada,
     * e `null` é a resposta honesta. Uma regra só, para a ficha e para a
     * conferência de bairro.
     */
    public static function isencaoAtiva(?string $isencao): ?bool
    {
        return $isencao === null || trim($isencao) === ''
            ? null
            : mb_strtolower(trim($isencao)) !== 'inativo';
    }
}
