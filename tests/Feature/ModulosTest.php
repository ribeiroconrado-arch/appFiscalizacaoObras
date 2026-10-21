<?php

namespace Tests\Feature;

use App\Models\Documento;
use App\Models\User;
use App\Support\Modulo;
use Tests\TestCase;

/**
 * Obras e Posturas no mesmo sistema (config/modulos.php). Regra pura: usuário
 * montado em memória, sem banco — o que precisa da aplicação é só a config.
 */
class ModulosTest extends TestCase
{
    private function usuario(string $perfil, string $tipo, ?array $modulos): User
    {
        return (new User())->forceFill([
            'perfil' => $perfil, 'tipo_usuario' => $tipo, 'ativo' => true, 'modulos' => $modulos,
        ]);
    }

    public function test_obras_e_o_primeiro_e_o_padrao(): void
    {
        $this->assertSame(['obras', 'posturas'], Modulo::todos());
        $this->assertSame('obras', Modulo::ativo(null));
    }

    public function test_sem_modulo_gravado_vale_obras(): void
    {
        $u = $this->usuario('comum', 'agente', null);
        $this->assertSame(['obras'], $u->modulos());
        $this->assertFalse($u->temModulo('posturas'));
    }

    public function test_agente_so_de_posturas(): void
    {
        $u = $this->usuario('comum', 'agente', ['posturas']);
        $this->assertSame(['posturas'], $u->modulos());
        $this->assertFalse($u->temModulo('obras'));
        $this->assertSame('posturas', Modulo::ativo($u));
    }

    public function test_modulos_saem_na_ordem_do_seletor_e_sem_desconhecidos(): void
    {
        $u = $this->usuario('comum', 'agente', ['posturas', 'inexistente', 'obras']);
        $this->assertSame(['obras', 'posturas'], $u->modulos());
    }

    public function test_administrador_tem_todos(): void
    {
        $u = $this->usuario('admin', 'agente', ['obras']);
        $this->assertSame(['obras', 'posturas'], $u->modulos());
    }

    public function test_obras_continua_com_as_mesmas_pecas(): void
    {
        $this->assertSame(
            ['vistoria', 'notificacao', 'notificacao_embargo', 'auto_embargo', 'auto_infracao'],
            array_keys(Documento::tiposDoModulo('obras'))
        );
    }

    public function test_posturas_emite_notificacao_auto_e_apreensao(): void
    {
        $this->assertSame(
            ['notificacao', 'auto_infracao', 'auto_apreensao'],
            array_keys(Documento::tiposDoModulo('posturas'))
        );
        // A apreensão tem prazo de defesa e nasce da notificação ou do auto.
        $this->assertContains('auto_apreensao', Documento::COM_DEFESA);
        $this->assertSame(['notificacao', 'auto_infracao'], Documento::ORIGENS['auto_apreensao']);
    }

    public function test_abas_de_posturas_nao_tem_consulta(): void
    {
        $this->assertNotContains('busca', Modulo::abas('posturas'));
        $this->assertContains('busca', Modulo::abas('obras'));
    }
}
