<?php

namespace Tests\Unit;

use App\Models\Denuncia;
use App\Models\Documento;
use App\Models\User;
use App\Posturas\ProtocoloDenuncia;
use App\Support\Modulo;
use Tests\TestCase;

/**
 * As regras da aba Denúncias (Posturas) que não precisam de banco: o protocolo
 * DN, a máscara do CPF/CNPJ, quem altera e como a notificação cita a denúncia.
 */
class DenunciasTest extends TestCase
{
    private function usuario(int $id, string $perfil = 'comum', string $nome = 'Fulano de Tal'): User
    {
        $u = (new User())->forceFill(['perfil' => $perfil, 'tipo_usuario' => 'agente', 'ativo' => true, 'name' => $nome]);
        $u->id = $id;

        return $u;
    }

    public function test_protocolo_no_formato_de_la_com_verificador_que_confere(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $p = ProtocoloDenuncia::gerar(fn () => false);
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z]{2}\d{4}-\d$/', $p);
            $this->assertTrue(ProtocoloDenuncia::valido($p));
        }
    }

    public function test_verificador_igual_ao_do_appposturas(): void
    {
        // D=68, N=78; 4+8+2+7 = 21 → (68+78+21) mod 9 = 167 mod 9 = 5.
        $this->assertSame(5, ProtocoloDenuncia::verificador('DN', '4827'));
        $this->assertTrue(ProtocoloDenuncia::valido('DN4827-5'));
        $this->assertFalse(ProtocoloDenuncia::valido('DN4827-3'));
        $this->assertFalse(ProtocoloDenuncia::valido('DO4827-5'), 'O fica de fora: lido como zero');
    }

    public function test_protocolo_repetido_e_sorteado_de_novo(): void
    {
        $usados = 0;
        $p = ProtocoloDenuncia::gerar(function () use (&$usados) { return ++$usados < 3; });
        $this->assertSame(3, $usados);
        $this->assertTrue(ProtocoloDenuncia::valido($p));
    }

    public function test_mascara_deixa_so_o_meio(): void
    {
        $this->assertSame('***.456.789-**', Denuncia::mascarar('123.456.789-09'));
        $this->assertSame('**.345.678/0001-**', Denuncia::mascarar('12.345.678/0001-95'));
        $this->assertNull(Denuncia::mascarar(null));
        $this->assertNull(Denuncia::mascarar(''));
    }

    public function test_altera_o_autor_ou_o_administrador(): void
    {
        $d = (new Denuncia())->forceFill(['agente_id' => 7, 'agente_nome' => 'Fulano de Tal']);

        $this->assertTrue($d->podeEditar($this->usuario(7)));
        $this->assertFalse($d->podeEditar($this->usuario(8, 'comum', 'Outro')));
        $this->assertTrue($d->podeEditar($this->usuario(8, 'admin', 'Outro')));
        $this->assertFalse($d->podeEditar($this->usuario(7, 'viewer')), 'Visualizador não escreve');
    }

    public function test_importada_sem_usuario_vale_pelo_nome(): void
    {
        $d = (new Denuncia())->forceFill(['agente_id' => null, 'agente_nome' => 'FULANO DE TAL']);

        $this->assertTrue($d->podeEditar($this->usuario(9, 'comum', 'Fulano de Tal')));
        $this->assertFalse($d->podeEditar($this->usuario(9, 'comum', 'Beltrano')));
    }

    public function test_cancelada_nao_se_altera_nem_pelo_administrador(): void
    {
        $d = (new Denuncia())->forceFill(['agente_id' => 7, 'tratativa' => Denuncia::CANCELADO]);

        $this->assertFalse($d->podeEditar($this->usuario(7)));
        $this->assertFalse($d->podeEditar($this->usuario(1, 'admin')));
    }

    public function test_notificacao_cita_a_denuncia_pelo_protocolo(): void
    {
        $doc = (new Documento())->forceFill(['tipo' => 'notificacao', 'origem_motivo' => 'denuncia']);
        $doc->setRelation('origem', null);
        $doc->setRelation('denuncia', (new Denuncia())->forceFill(['protocolo' => 'DN4827-5']));

        $this->assertSame('DENÚNCIA Nº DN4827-5', $doc->origemTexto());
    }

    public function test_denuncias_e_aba_so_de_posturas(): void
    {
        $this->assertContains('denuncias', Modulo::abas('posturas'));
        $this->assertNotContains('denuncias', Modulo::abas('obras'));
    }
}
