<?php

namespace Tests\Unit;

use App\Models\Artigo;
use App\Models\Documento;
use App\Models\DocumentoItem;
use App\Models\Legislacao;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * As peças de Posturas (Fase 4): a multa da Lei 723 pela medição da peça, o
 * que falta para lavrar e o título impresso. Sem banco — modelos montados em
 * memória, relações postas à mão.
 */
class PecasDePosturasTest extends TestCase
{
    private function lei723(): Legislacao
    {
        return (new Legislacao())->forceFill([
            'formulario_tipo' => 'lei_723_2002',
            'areas_db' => [['area' => 'Zona residencial', 'diurno' => 50, 'noturno' => 45]],
            'faixa_db' => [['ate_db' => 10, 'upf' => 20, 'nivel' => 'Leve'], ['ate_db' => null, 'upf' => 60, 'nivel' => 'Grave']],
        ]);
    }

    private function artigoPorDb(): Artigo
    {
        $a = (new Artigo())->forceFill(['numero' => 'Art. 12', 'base_multa' => 'diferenca_db']);
        $a->setRelation('legislacao', $this->lei723());

        return $a;
    }

    private function peca(string $tipo, array $campos = []): Documento
    {
        $d = (new Documento())->forceFill(['tipo' => $tipo, 'modulo' => 'posturas'] + $campos);
        $d->setRelation('legislacao', $this->lei723());

        return $d;
    }

    public function test_multa_pela_diferenca_usa_a_medicao_da_peca(): void
    {
        // Noturno (22h): limite 45; 52,5 − 45 = +7,5 dB → faixa leve, 20 UPF.
        $c = $this->artigoPorDb()->calcularMulta(null, null, null, null, 120.0, 1,
            ['area' => 'Zona residencial', 'hora' => 22, 'aferido' => 52.5]);

        $this->assertNull($c['pendencia']);
        $this->assertSame(20.0, $c['valor']);
        $this->assertStringContainsString('noturno', $c['memoria']);
    }

    public function test_sem_medicao_a_multa_fica_pendente(): void
    {
        $c = $this->artigoPorDb()->calcularMulta(null, null);

        $this->assertNotNull($c['pendencia']);
        $this->assertSame(0.0, $c['valor']);
    }

    public function test_zona_que_a_lei_nao_tem_e_pendencia(): void
    {
        $c = $this->artigoPorDb()->calcularMulta(null, null, null, null, null, 1,
            ['area' => 'Zona industrial', 'hora' => 10, 'aferido' => 80]);

        $this->assertNotNull($c['pendencia']);
    }

    public function test_reincidencia_dobra_quando_o_artigo_manda(): void
    {
        $a = $this->artigoPorDb();
        $a->multa_dobra_reincidencia = true;
        // Diurno (10h): 50; 75 − 50 = +25 dB → grave, 60 UPF × 2.
        $c = $a->calcularMulta(null, null, null, null, null, 2, ['area' => 'Zona residencial', 'hora' => 10, 'aferido' => 75]);

        $this->assertSame(120.0, $c['valor']);
    }

    public function test_medicao_para_multa_pega_a_hora_do_fato(): void
    {
        $d = $this->peca('auto_infracao', ['data_fato' => Carbon::parse('2026-10-21 23:15'),
            'ruido' => ['area' => 'Zona residencial', 'db_aferido' => 61.2]]);

        $this->assertSame(['area' => 'Zona residencial', 'hora' => 23, 'aferido' => 61.2], $d->medicaoParaMulta());
        $this->assertNull($this->peca('auto_infracao', ['data_fato' => now()])->medicaoParaMulta());
    }

    public function test_lavrar_pede_a_medicao_completa_na_lei_do_ruido(): void
    {
        $faltam = $this->peca('notificacao', ['ruido' => ['area' => 'Zona residencial']])->pendenciasDePosturas();

        $this->assertContains('o equipamento da medição', $faltam);
        $this->assertContains('o dB aferido', $faltam);
        $this->assertNotContains('a zona (local) da medição', $faltam);
    }

    public function test_fonte_veiculo_pede_a_placa(): void
    {
        $ruido = ['equipamento' => 'DEC', 'fonte_causadora' => 'Veículo', 'area' => 'Zona residencial', 'db_aferido' => 70];

        $this->assertSame(['a placa do veículo'], $this->peca('auto_infracao', ['ruido' => $ruido])->pendenciasDePosturas());
        $this->assertSame([], $this->peca('auto_infracao', ['ruido' => $ruido, 'veiculo' => ['placa' => 'ABC1D23']])->pendenciasDePosturas());
    }

    public function test_auto_de_apreensao_pede_o_subtipo_e_na_retencao_a_placa(): void
    {
        $this->assertSame(['se é apreensão de bens ou retenção de veículo'], $this->peca('auto_apreensao')->pendenciasDePosturas());
        $this->assertSame(['a placa do veículo retido'], $this->peca('auto_apreensao', ['apreensao_subtipo' => 'retencao'])->pendenciasDePosturas());
    }

    public function test_apreensao_nao_mede_de_novo(): void
    {
        $this->assertFalse($this->peca('auto_apreensao')->pedeMedicao());
        $this->assertTrue($this->peca('notificacao')->pedeMedicao());
    }

    public function test_titulo_impresso_da_retencao(): void
    {
        $this->assertSame('AUTO DE RETENÇÃO', $this->peca('auto_apreensao', ['apreensao_subtipo' => 'retencao'])->tituloImpresso());
        $this->assertSame('AUTO DE APREENSÃO', $this->peca('auto_apreensao', ['apreensao_subtipo' => 'apreensao'])->tituloImpresso());
    }

    public function test_quantidade_inteira_sem_casas(): void
    {
        $this->assertSame('2 UN', (new DocumentoItem())->forceFill(['quantidade' => 2, 'unidade' => 'UN'])->quantidadeTexto());
        $this->assertSame('1,50 KG', (new DocumentoItem())->forceFill(['quantidade' => 1.5, 'unidade' => 'KG'])->quantidadeTexto());
    }
}
