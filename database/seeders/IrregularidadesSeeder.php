<?php

namespace Database\Seeders;

use App\Models\Irregularidade;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial de irregularidades, a partir do checklist do §13 do
 * documento do projeto.
 *
 * ⚠ A COLUNA `base_legal` VAI VAZIA, DE PROPÓSITO. ⚠
 *
 * Preencher artigo e lei aqui exigiria eu transcrever dispositivos do Código de
 * Obras (Lei Complementar 1/2023) e do Código de Posturas (Lei 500/1998) sem
 * conferência jurídica. Fundamentação legal errada num auto de infração é vício
 * insanável: derruba a autuação e expõe o município. O enquadramento entra na
 * Etapa 6, junto do motor de legislação, e precisa de validação da procuradoria
 * antes de qualquer documento ser emitido.
 *
 * A gravidade abaixo é operacional (para ordenar o trabalho do fiscal), não
 * jurídica — não define sanção.
 */
class IrregularidadesSeeder extends Seeder
{
    public function run(): void
    {
        $itens = [
            ['OBR-01', 'Construção sem alvará de licença',              'grave', 10],
            ['OBR-02', 'Obra em desacordo com o projeto aprovado',      'grave', 20],
            ['OBR-03', 'Ampliação não licenciada',                      'grave', 30],
            ['OBR-04', 'Recuo frontal irregular',                       'media', 40],
            ['OBR-05', 'Recuo lateral ou de fundos irregular',          'media', 50],
            ['OBR-06', 'Taxa de ocupação acima do permitido',           'media', 60],
            ['OBR-07', 'Altura ou número de pavimentos irregular',      'media', 70],
            ['OBR-08', 'Ausência de placa de identificação da obra',    'leve',  80],
            ['OBR-09', 'Ausência de tapume ou proteção',                'media', 90],
            ['OBR-10', 'Material de construção em via pública',         'media', 100],
            ['OBR-11', 'Entulho ou resíduo em via pública',             'media', 110],
            ['OBR-12', 'Obstrução do passeio público',                  'media', 120],
            ['OBR-13', 'Calçada irregular ou inexistente',              'leve',  130],
            ['OBR-14', 'Situação de risco a terceiros',                 'grave', 140],
            ['OBR-15', 'Obra embargada em atividade',                   'grave', 150],
            ['OBR-16', 'Ocupação de área pública',                      'grave', 160],
            ['OBR-17', 'Edificação em faixa não edificável',            'grave', 170],
            ['OBR-18', 'Ausência de responsável técnico',               'grave', 180],
            ['OBR-19', 'Descarte irregular de água ou esgoto',          'media', 190],
            ['OBR-20', 'Outras irregularidades',                        'leve',  999],
        ];

        foreach ($itens as [$codigo, $descricao, $gravidade, $ordem]) {
            Irregularidade::updateOrCreate(
                ['codigo' => $codigo],
                [
                    'descricao'  => $descricao,
                    'gravidade'  => $gravidade,
                    'ordem'      => $ordem,
                    'ativo'      => true,
                    'base_legal' => null,   // ver o aviso no topo desta classe
                ]
            );
        }

        $this->command->newLine();
        $this->command->info(count($itens) . ' irregularidades cadastradas.');
        $this->command->warn('Fundamentação legal (base_legal) em branco — preencher na Etapa 6, com validação jurídica.');
    }
}
