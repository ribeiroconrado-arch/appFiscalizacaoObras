<?php

namespace App\Cadastro;

use App\Models\Lote;

/**
 * De onde vem o cadastro imobiliário de um imóvel.
 *
 * Existe uma implementação hoje — a exportação carregada em
 * `cadastro_externo_imoveis` (ver CadastroCarregado). No dia em que houver
 * acesso ao banco da prefeitura, entra uma segunda classe com a consulta real,
 * e NADA mais muda: nem o serviço que grava a cópia, nem o controller, nem a
 * tela. É essa a razão de a interface existir antes de haver duas fontes.
 */
interface FonteDoCadastro
{
    /**
     * O que o cadastro sabe sobre este lote, ou null quando não sabe nada.
     *
     * Devolver null não é erro: é a resposta correta para imóvel que o cadastro
     * não tem, para bairro ainda não amarrado, e para lote sem quadra — três
     * situações reais que a tela precisa saber distinguir de "deu erro".
     */
    public function consultar(Lote $lote): ?RetratoBci;

    /** Frase curta que explica por que a última consulta veio vazia. */
    public function porQueVazio(Lote $lote): string;

    /**
     * Como esta fonte se chama no carimbo do documento.
     *
     * Vai gravada no RETRATO, e de lá é copiada para a peça lavrada. Sem isso,
     * o dia em que a exportação der lugar ao banco da prefeitura, um documento
     * lavrado depois da troca — mas com integração feita antes — diria "banco"
     * sobre um dado que veio de planilha.
     */
    public function nome(): string;
}
