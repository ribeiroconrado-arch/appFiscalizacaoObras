<?php

namespace Tests\Unit;

use App\Support\Cnpj;
use PHPUnit\Framework\TestCase;

/** Os dígitos verificadores do CNPJ — o que decide se a consulta sai do servidor. */
class CnpjTest extends TestCase
{
    public function test_aceita_cnpj_com_digitos_verificadores_certos(): void
    {
        // O da Prefeitura de Primavera do Leste, impresso no BCI.
        $this->assertTrue(Cnpj::valido('01974088000105'));
        $this->assertTrue(Cnpj::valido('11222333000181'));
    }

    public function test_recusa_digito_errado_tamanho_errado_e_repeticao(): void
    {
        $this->assertFalse(Cnpj::valido('01974088000106'));
        $this->assertFalse(Cnpj::valido('0197408800010'));
        $this->assertFalse(Cnpj::valido('11111111111111'));
        $this->assertFalse(Cnpj::valido(''));
    }
}
