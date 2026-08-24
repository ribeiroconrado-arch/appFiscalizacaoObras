<?php

namespace App\Cadastro;

/**
 * O que uma fonte de cadastro devolve sobre um imóvel: dados do terreno, o
 * quadro de características e as construções.
 *
 * É um retrato, não um registro — não se salva, não tem id, e não sabe de que
 * lote é. Quem amarra ao lote e grava é o SincronizaBci. Assim a mesma classe
 * serve para a planilha de hoje e para o banco da prefeitura de amanhã.
 */
class RetratoBci
{
    /**
     * @param  array<string,mixed>    $imovel           campos de bci_imoveis
     * @param  array<string,?string>  $caracteristicas  chave => valor, na ordem do cadastro
     * @param  list<array<string,mixed>>  $unidades     construções
     */
    public function __construct(
        public array $imovel,
        public array $caracteristicas = [],
        public array $unidades = [],
    ) {
    }
}
