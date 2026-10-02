<?php

namespace App\Cadastro;

use App\Models\User;

/**
 * O que cada usuário vê do proprietário do imóvel.
 *
 * | Quem                                   | Vê                        |
 * |----------------------------------------|---------------------------|
 * | agente de fiscalização, administrador  | nome, CPF/CNPJ, endereço  |
 * | demais servidores (coordenador etc.)   | só o nome                 |
 * | externo (topógrafo, arquiteto, contrib.)| nada                     |
 *
 * Decidido aqui, no servidor, e não na tela: esconder o CPF com CSS seria
 * mandá-lo mesmo assim para o navegador de quem não pode vê-lo.
 */
final class ProprietariosVisiveis
{
    /**
     * @param  list<array{nome:string, documento:?string, endereco:?string}>  $donos
     * @return list<array<string,?string>>|null  null = o usuário não vê o bloco
     */
    public static function para(?User $usuario, array $donos): ?array
    {
        if (! $usuario || ! $usuario->ativo || $usuario->isExterno()) {
            return null;
        }

        if ($usuario->podeVerDadosDoProprietario()) {
            return $donos;
        }

        return array_map(fn (array $d) => ['nome' => $d['nome']], $donos);
    }
}
