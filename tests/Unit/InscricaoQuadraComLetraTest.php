<?php

namespace Tests\Unit;

use App\Support\InscricaoImobiliaria;
use PHPUnit\Framework\TestCase;

class InscricaoQuadraComLetraTest extends TestCase
{
    public function test_letra_da_quadra_vira_mais_um_digito(): void
    {
        $this->assertSame('010018210005000', InscricaoImobiliaria::montar(1, '82A', 5));
        $this->assertSame('010018220005000', InscricaoImobiliaria::montar(1, '82B', 5));
        $this->assertSame('010018230005000', InscricaoImobiliaria::montar(1, '82C', 5));
        $this->assertSame('010014630001000', InscricaoImobiliaria::montar(1, '46c', 1));
    }

    public function test_grafias_do_desenho_valem_igual(): void
    {
        foreach (['96/A', '96-A', '96 A', '096A'] as $q) {
            $this->assertSame('010029610003000', InscricaoImobiliaria::montar(2, $q, 3), $q);
        }
    }

    public function test_quadra_sem_letra_nao_muda(): void
    {
        $this->assertSame('010010820005000', InscricaoImobiliaria::montar(1, '82', 5));
        $this->assertSame('010010820005000', InscricaoImobiliaria::montar(1, '082', 5));
    }

    public function test_letra_que_nao_cabe_ou_quadra_que_estoura_nao_monta(): void
    {
        $this->assertNull(InscricaoImobiliaria::montar(1, '82J', 5));   // J = 10: não tem dígito
        $this->assertNull(InscricaoImobiliaria::montar(1, '109-A', 5)); // 1091: quatro dígitos
        $this->assertNull(InscricaoImobiliaria::montar(1, 'A', 5));     // sem número
    }

    public function test_caminho_de_volta(): void
    {
        $this->assertSame(['821', '82A'], InscricaoImobiliaria::grafiasDaQuadra(821));
        $this->assertSame(['5', ], InscricaoImobiliaria::grafiasDaQuadra(5));
        $this->assertSame(['820'], InscricaoImobiliaria::grafiasDaQuadra(820));
    }
}
