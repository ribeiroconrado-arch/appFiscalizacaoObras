<?php

namespace Tests\Unit;

use App\Http\Controllers\PesquisaMapaController;
use App\Models\Lote;
use Illuminate\Http\Request;
use Tests\TestCase;

class PesquisaMapaDiretaTest extends TestCase
{
    private function consulta(array $dados)
    {
        $controller = new PesquisaMapaController();
        $query = Lote::query()->publicados();
        $method = new \ReflectionMethod($controller, 'aplicar');
        $this->assertTrue($method->invoke($controller, $query, $dados, Request::create('/')));
        return $query;
    }

    public function test_texto_e_campos_combinam_por_e_sem_remover_escopo_publicado(): void
    {
        $q = $this->consulta(['termo' => 'Nações Unidas', 'bairro_texto' => 'Europa', 'quadra_de' => 35, 'quadra_ate' => 35]);
        $sql = $q->toSql();
        $this->assertStringContainsString('situacao', $sql);
        $this->assertStringContainsString('em_revisao', $sql);
        $this->assertStringContainsString('pmc.logradouro', $sql);
        $this->assertStringContainsString('pmc.quadra', $sql);
        $this->assertStringContainsString('lotes.numero_lote', $sql);
        $this->assertContains('%Nações%', $q->getBindings());
        $this->assertContains('%Unidas%', $q->getBindings());
        $this->assertContains('%Europa%', $q->getBindings());
        foreach ($q->getQuery()->wheres as $where) $this->assertSame('and', $where['boolean']);
    }

    public function test_curingas_digitados_sao_literais_e_parametrizados(): void
    {
        $q = $this->consulta(['rua_texto' => "50%_!' OR 1=1"]);
        $this->assertContains("%50!%!_!!' OR 1=1%", $q->getBindings());
        $this->assertStringNotContainsString('OR 1=1', $q->toSql());
    }

    public function test_inscricao_formatada_tambem_procura_na_coluna_normalizada(): void
    {
        $q = $this->consulta(['termo' => '01.105.035']);
        $this->assertContains('01105035%', $q->getBindings());
        $this->assertStringContainsString('inscricao_montada', $q->toSql());
    }

    public function test_pesquisa_vazia_nao_libera_consulta_da_base_inteira(): void
    {
        $q = Lote::query()->publicados();
        $method = new \ReflectionMethod(PesquisaMapaController::class, 'aplicar');
        $this->assertFalse($method->invoke(new PesquisaMapaController(), $q, ['termo' => '  '], Request::create('/')));
    }
}
