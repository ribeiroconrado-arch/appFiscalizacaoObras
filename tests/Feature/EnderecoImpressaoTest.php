<?php

namespace Tests\Feature;

use App\Models\Documento;
use App\Services\DocumentoImpressao;
use Tests\TestCase;

class EnderecoImpressaoTest extends TestCase
{
    public function test_preserva_endereco_da_peca_sem_cep(): void
    {
        $doc = new Documento(['autuado_endereco' => 'Rua A, 12 — Centro — CEP 78850-000',
            'autuado_endereco_partes' => ['logradouro' => 'Rua B']]);
        $this->assertSame('Rua A, 12 — Centro', DocumentoImpressao::enderecoDestinatario($doc));
    }

    public function test_recupera_partes_e_informa_ausencia_sem_usar_endereco_do_imovel(): void
    {
        $doc = new Documento(['autuado_endereco' => ' ', 'autuado_endereco_partes' => [
            'logradouro' => 'Rua A', 'numero' => '0', 'bairro' => 'Centro', 'cidade' => 'Primavera', 'uf' => 'MT',
        ]]);
        $this->assertSame('Rua A, 0 — Centro — Primavera/MT', DocumentoImpressao::enderecoDestinatario($doc));
        $doc->autuado_endereco_partes = null;
        $doc->endereco = 'Endereço da obra';
        $this->assertSame('Não informado', DocumentoImpressao::enderecoDestinatario($doc));
    }
}
