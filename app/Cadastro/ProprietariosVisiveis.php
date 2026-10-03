<?php

namespace App\Cadastro;

use App\Models\User;

/**
 * O que cada usuário vê do proprietário do imóvel.
 *
 * | Quem                                   | Vê                        |
 * |----------------------------------------|---------------------------|
 * | agente de fiscalização, administrador  | nome, CPF/CNPJ, endereço  |
 * | demais servidores (coordenador etc.)   | nome e CPF MASCARADO      |
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

        return array_map(fn (array $d) => array_filter([
            'nome'                => $d['nome'],
            'documento_mascarado' => self::mascarar($d['documento'] ?? null),
        ], fn ($v) => $v !== null), $donos);
    }

    /**
     * CPF com seis dígitos escondidos: "123.456.789-00" vira "***.456.***-00".
     * Dá para distinguir dois homônimos sem entregar o número.
     *
     * A chave é OUTRA (`documento_mascarado`), e não `documento`: quem preenche
     * autuado a partir do proprietário lê `documento`, e uma máscara ali viraria
     * CPF inválido numa peça. CNPJ sai inteiro — é dado público de empresa.
     */
    public static function mascarar(?string $documento): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $documento);

        return match (strlen($digitos)) {
            11      => '***.' . substr($digitos, 3, 3) . '.***-' . substr($digitos, 9, 2),
            14      => $documento,
            default => null,
        };
    }
}
