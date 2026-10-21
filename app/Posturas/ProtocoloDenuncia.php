<?php

namespace App\Posturas;

/**
 * O protocolo da denúncia, no formato do AppPOSTURAS: duas letras, quatro
 * dígitos e o dígito verificador — DN4827-3.
 *
 * É o número que o agente dita por telefone e que o cidadão anota num papel.
 * Por isso as letras I e O ficam de fora (lidas como 1 e 0), e o dígito
 * verificador pega o erro simples de digitação na hora da consulta.
 *
 * O verificador é o mesmo de lá — (soma dos códigos ASCII das letras + soma
 * dos dígitos) mod 9 — para que os protocolos importados continuem válidos
 * aqui, e os novos sigam valendo se alguém os conferir pelo app antigo.
 */
class ProtocoloDenuncia
{
    public const LETRAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** DN4827-3: o formato, sem conferir o verificador. */
    private const FORMATO = '/^([A-HJ-NP-Z]{2})(\d{4})-(\d)$/';

    /**
     * Um protocolo novo e livre.
     *
     * Sorteado, como lá, e não sequencial: o número não revela quantas
     * denúncias a fiscalização recebe. São 24×24×9000 ≈ 5 milhões de
     * combinações — a repetição é rara, e quando acontece sorteia-se de novo.
     *
     * @param  callable(string):bool  $existe  se o protocolo já está em uso
     */
    public static function gerar(callable $existe): string
    {
        for ($tentativa = 0; $tentativa < 50; $tentativa++) {
            $l1 = self::LETRAS[random_int(0, strlen(self::LETRAS) - 1)];
            $l2 = self::LETRAS[random_int(0, strlen(self::LETRAS) - 1)];
            $numero = (string) random_int(1000, 9999);
            $protocolo = $l1 . $l2 . $numero . '-' . self::verificador($l1 . $l2, $numero);
            if (! $existe($protocolo)) {
                return $protocolo;
            }
        }

        throw new \RuntimeException('Não foi possível sortear um protocolo livre.');
    }

    /** O dígito verificador de "DN" + "4827". */
    public static function verificador(string $letras, string $numero): int
    {
        $soma = ord($letras[0]) + ord($letras[1]) + array_sum(str_split($numero));

        return $soma % 9;
    }

    /** O protocolo está no formato e o verificador confere? */
    public static function valido(?string $protocolo): bool
    {
        if (! preg_match(self::FORMATO, strtoupper(trim((string) $protocolo)), $m)) {
            return false;
        }

        return self::verificador($m[1], $m[2]) === (int) $m[3];
    }
}
