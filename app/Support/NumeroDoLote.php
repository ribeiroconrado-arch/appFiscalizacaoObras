<?php

namespace App\Support;

/**
 * O NÚMERO DO LOTE e a QUADRA numa grafia só (regra dele: lote em 05/11/2026,
 * quadra em 06/11/2026).
 *
 * "4" e "04" são o mesmo lote; "5" e "05", a mesma quadra. O DWG, o cadastro e
 * o próprio sistema (o apelido do desmembrado saía da inscrição: "0004A"; a
 * quadra era gravada com dois dígitos: "07") escreviam cada um de um jeito, e
 * o banco guardava as duas grafias como coisas diferentes.
 *
 * A grafia que vale é SEM zero à esquerda: "04" → "4", "0004A" → "4A",
 * "00" → "0". Só o começo muda: "4-001" (unidade de fração ideal) e "10/12"
 * ficam como estão. A coluna gerada `chave_identidade` usa a mesma regra
 * (migrations 2026_11_05_000100 e 2026_11_06_000100), então duas grafias do
 * mesmo lote não convivem nem que algum caminho esqueça de normalizar.
 *
 * Sem o zero, ordenar por TEXTO põe "10" antes de "5": a ordem é por `ORDEM`.
 */
final class NumeroDoLote
{
    /** A expressão SQL equivalente, para a coluna gerada e as consultas. */
    public const SQL = "REGEXP_REPLACE(numero_lote, '^0+([0-9])', '$1')";

    public const SQL_QUADRA = "REGEXP_REPLACE(quadra, '^0+([0-9])', '$1')";

    /** ORDER BY de quadra e lote na ordem dos números ("5" antes de "10"; "82A" depois de "82"). */
    public const ORDEM = 'CAST(quadra AS UNSIGNED), quadra, CAST(numero_lote AS UNSIGNED), numero_lote';

    public static function normalizar(?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }
        $numero = trim($numero);

        return $numero === '' ? $numero : preg_replace('/^0+(?=\d)/', '', $numero);
    }

    /**
     * A chave de integração "bairro|quadra|lote" com quadra e número
     * normalizados. O "?" do lote sem quadra ou sem número fica.
     */
    public static function naChave(?string $chave): ?string
    {
        if ($chave === null) {
            return null;
        }
        $partes = explode('|', $chave);
        if (count($partes) < 3) {
            return $chave;
        }
        $n = count($partes);
        $partes[$n - 1] = self::normalizar($partes[$n - 1]);
        $partes[$n - 2] = self::normalizar($partes[$n - 2]);

        return implode('|', $partes);
    }
}
