<?php

namespace App\Support;

/**
 * O NÚMERO DO LOTE numa grafia só (05/11/2026, regra dele).
 *
 * "4" e "04" são o mesmo lote. O DWG, o cadastro e o próprio sistema (o
 * apelido do desmembrado saía da inscrição: "0004A") escreviam cada um de um
 * jeito, e o banco guardava as duas grafias como lotes diferentes — o índice
 * único deixava entrar o "04" ao lado do "4" na mesma quadra.
 *
 * A grafia que vale é SEM zero à esquerda: "04" → "4", "0004A" → "4A",
 * "00" → "0". Só o começo muda: "4-001" (unidade de fração ideal) e "10/12"
 * ficam como estão. A coluna gerada `chave_identidade` usa a mesma regra
 * (migration 2026_11_05_000100), então duas grafias do mesmo número não
 * convivem na mesma quadra nem que algum caminho esqueça de normalizar.
 */
final class NumeroDoLote
{
    /** A expressão SQL equivalente, para a coluna gerada e as consultas. */
    public const SQL = "REGEXP_REPLACE(numero_lote, '^0+([0-9])', '$1')";

    public static function normalizar(?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }
        $numero = trim($numero);

        return $numero === '' ? $numero : preg_replace('/^0+(?=\d)/', '', $numero);
    }

    /**
     * A chave de integração "bairro|quadra|lote" com o número normalizado. Só
     * mexe no último pedaço quando ele É o número (o "?" do lote sem número fica).
     */
    public static function naChave(?string $chave): ?string
    {
        if ($chave === null || ! str_contains($chave, '|')) {
            return $chave;
        }
        $pos = strrpos($chave, '|');

        return substr($chave, 0, $pos + 1) . self::normalizar(substr($chave, $pos + 1));
    }
}
