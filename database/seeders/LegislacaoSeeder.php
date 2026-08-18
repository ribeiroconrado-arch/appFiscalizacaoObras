<?php

namespace Database\Seeders;

use App\Models\Legislacao;
use Illuminate\Database\Seeder;

/**
 * Cadastra as LEIS aplicáveis à fiscalização de obras em Primavera do Leste.
 *
 * ⚠ O QUE ESTE SEEDER FAZ E O QUE NÃO FAZ ⚠
 *
 * FAZ: registra a identificação das leis — número, nome e ano. São dados
 * públicos e verificáveis, conferidos contra os arquivos oficiais em
 * `Escritório\LEIS E MAPAS MUNICIPIOS\PRIMAVERA DO LESTE - MT\`.
 *
 * NÃO FAZ: cadastrar ARTIGOS, condutas, sanções, valores de multa ou textos
 * de ciência. Isso exigiria transcrever dispositivos legais sem conferência
 * jurídica, e fundamentação errada em auto de infração é vício insanável —
 * derruba a autuação e expõe o município a responsabilização.
 *
 * Os artigos são cadastrados pela própria administração, em Parâmetros >
 * Legislação, com validação da procuradoria. Enquanto não houver artigo
 * vinculado a uma irregularidade, o sistema BLOQUEIA a lavratura do auto
 * correspondente — o que é o comportamento correto, não uma limitação.
 *
 * Os prazos abaixo são VALORES INICIAIS de formulário, não afirmações
 * jurídicas: precisam ser conferidos contra cada lei antes do uso real.
 */
class LegislacaoSeeder extends Seeder
{
    public function run(): void
    {
        $leis = [
            [
                'numero' => 'Lei Complementar 1/2023',
                'nome'   => 'Código de Obras',
                'ano'    => 2023,
                'ementa' => 'Dispõe sobre as normas de construção no município.',
            ],
            [
                'numero' => 'Lei 500/1998',
                'nome'   => 'Código de Posturas',
                'ano'    => 1998,
                'ementa' => 'Dispõe sobre as posturas municipais.',
            ],
            [
                'numero' => 'Lei 497/1998',
                'nome'   => 'Zoneamento e Uso do Solo',
                'ano'    => 1998,
                'ementa' => 'Dispõe sobre o zoneamento e o uso e ocupação do solo urbano.',
            ],
            [
                'numero' => 'Lei 1.000/2007',
                'nome'   => 'Plano Diretor',
                'ano'    => 2007,
                'ementa' => 'Institui o Plano Diretor do município.',
            ],
        ];

        foreach ($leis as $lei) {
            Legislacao::updateOrCreate(
                ['numero' => $lei['numero']],
                $lei + [
                    // Valores iniciais de formulário — CONFERIR na lei.
                    'prazo_defesa_dias'     => 5,
                    'prazo_cumprimento_dias' => 10,
                    // Em branco de propósito: texto de intimação é citação
                    // legal e precisa vir da procuradoria, não de mim.
                    'ciencia_notificacao'   => null,
                    'ciencia_auto'          => null,
                    'ativa'                 => true,
                ]
            );
        }

        $this->command->newLine();
        $this->command->info(count($leis) . ' leis cadastradas (identificação apenas).');
        $this->command->warn('ARTIGOS, sanções, multas e textos de ciência NÃO foram cadastrados.');
        $this->command->warn('Sem artigo vinculado, o sistema bloqueia a lavratura — de propósito.');
        $this->command->line('Cadastrar em Parâmetros > Legislação, com validação jurídica.');
    }
}
