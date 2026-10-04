<?php

namespace App\Support;

/** Validação de CNPJ pelos dígitos verificadores. */
final class Cnpj
{
    /** @param string $digitos só os 14 dígitos */
    public static function valido(string $digitos): bool
    {
        if (strlen($digitos) !== 14 || preg_match('/^(\d)\1{13}$/', $digitos)) {
            return false;
        }
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $p) {
                $soma += (int) $digitos[$i] * $p;
            }
            $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
            if ((int) $digitos[count($pesos)] !== $dv) {
                return false;
            }
        }

        return true;
    }
}
