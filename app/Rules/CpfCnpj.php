<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF ou CNPJ de verdade: o tamanho certo E os dígitos verificadores certos.
 *
 * Campo "CPF / CNPJ" aceita os dois, e o que decide qual é o tamanho: 11
 * dígitos é CPF, 14 é CNPJ. Qualquer outro tamanho está incompleto. A conta
 * dos verificadores é a da Receita; sequência repetida (111.111.111-11) passa
 * na conta e não existe, por isso é recusada à parte.
 *
 * A mesma regra roda na tela (cpfCnpjValido, em ui.js), para o aviso sair na
 * hora; esta é a que vale.
 */
class CpfCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $n = preg_replace('/\D/', '', (string) $value);
        if ($n === '') {
            return;
        }
        if (strlen($n) === 11) {
            if (! self::cpf($n)) { $fail('CPF incorreto: confira os números digitados.'); }
            return;
        }
        if (strlen($n) === 14) {
            if (! self::cnpj($n)) { $fail('CNPJ incorreto: confira os números digitados.'); }
            return;
        }
        $fail('CPF ou CNPJ incompleto: o CPF tem 11 dígitos e o CNPJ, 14.');
    }

    public static function cpf(string $n): bool
    {
        if (strlen($n) !== 11 || preg_match('/^(\d)\1{10}$/', $n)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $n[$i] * (($t + 1) - $i);
            }
            if ((int) $n[$t] !== ((10 * $soma) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    public static function cnpj(string $n): bool
    {
        if (strlen($n) !== 14 || preg_match('/^(\d)\1{13}$/', $n)) {
            return false;
        }
        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pos => $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $p) {
                $soma += (int) $n[$i] * $p;
            }
            $resto = $soma % 11;
            if ((int) $n[$pos] !== ($resto < 2 ? 0 : 11 - $resto)) {
                return false;
            }
        }

        return true;
    }
}
