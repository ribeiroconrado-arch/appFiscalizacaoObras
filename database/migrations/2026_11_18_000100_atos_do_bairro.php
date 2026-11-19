<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OS ATOS DO BAIRRO (18/11/2026): o loteamento é um bairro só, mas foi
 * aprovado em partes — a aprovação, as ampliações e etapas, as
 * regularizações, retificações, alterações e a revogação. O Jardim Riva tem
 * quatro decretos (1988, 1998, 2001 e 2011).
 *
 * Cada ato vira uma linha ligada ao bairro. `cadastro_bairros.ato_aprovacao` e
 * `data_aprovacao` continuam, agora CALCULADOS: o ato de aprovação mais antigo
 * (ParametroController::salvarBairro os recalcula a cada gravação). Quem já os
 * lia — o balão do bairro — não muda.
 *
 * A carga inicial vem da planilha montada a partir do Leis Municipais
 * (docs/loteamentos-atos-de-aprovacao-v2.csv, no repositório do mapa): a
 * aprovação de cada bairro, com a área total do decreto, e os outros atos que
 * a planilha guardava na observação. Só carrega bairro que ainda não tem ato:
 * rodar de novo não duplica nem desfaz o que foi editado na tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bairro_atos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cadastro_bairro_id')->constrained('cadastro_bairros')->cascadeOnDelete();
            // aprovacao, ampliacao, etapa, regularizacao, retificacao, alteracao, revogacao
            $t->string('tipo', 20);
            $t->string('ato', 160);              // "Decreto 655/2001"
            $t->date('data')->nullable();
            $t->decimal('area_m2', 14, 2)->nullable();
            $t->string('observacao', 200)->nullable();
            $t->timestamps();
            $t->index(['cadastro_bairro_id', 'data']);
        });

        // codigo do cadastro => [ato, data, área]: a aprovação.
        $aprovacoes = [
            '4' => ['Decreto 210/1992', '1992-04-06', 115600.00],
            '5' => ['Decreto 74/1988', '1988-12-16', 448290.00],
            '6' => ['Decreto 65/1988', '1988-09-15', 1484200.00],
            '7' => ['Decreto 959/2007', '2007-09-06', 60000.00],
            '9' => ['Decreto 156/1991', '1991-06-12', null],
            '10' => ['Decreto 155/1991', '1991-06-12', null],
            '11' => ['Decreto 154/1991', '1991-06-12', null],
            '14' => ['Decreto 360/1994', '1994-03-22', 40141.25],
            '15' => ['Decreto 961/2007', '2007-09-06', 100000.00],
            '16' => ['Decreto 442/1996', '1996-08-14', 859100.00],
            '17' => ['Decreto 459/1996', '1996-12-13', 6024.50],
            '18' => ['Decreto 447/1996', '1996-09-13', 65600.00],
            '19' => ['Decreto 445/1996', '1996-08-16', 20000.00],
            '20' => ['Decreto 446/1996', '1996-09-04', 15052.31],
            '22' => ['Decreto 560/1999', '1999-05-11', 41163.30],
            '23' => ['Decreto 401/1995', '1995-05-09', 60724.75],
            '24' => ['Decreto 933/2007', '2007-05-04', 10200.00],
            '26' => ['Decreto 461/1996', '1996-12-16', 10579.65],
            '27' => ['Decreto 500/1997', '1997-11-27', 29580.00],
            '28' => ['Decreto 462/1996', '1996-12-16', 164800.02],
            '30' => ['Decreto 608/2000', '2000-09-15', 107203.00],
            '31' => ['Decreto 654/2001', '2001-07-20', 11280.00],
            '32' => ['Decreto 589/2000', '2000-04-25', 94900.00],
            '33' => ['Decreto 682/2002', '2002-05-22', 92700.00],
            '34' => ['Decreto 606/2000', '2000-09-01', 35240.20],
            '36' => ['Decreto 939/2007', '2007-06-27', 84794.67],
            '37' => ['Decreto 666/2001', '2001-11-20', 23700.00],
            '38' => ['Decreto 686/2002', '2002-06-13', 1000000.00],
            '39' => ['Decreto 898/2006', '2006-07-10', 66300.00],
            '40' => ['Decreto 994/2008', '2008-05-06', 50000.00],
            '43' => ['Decreto 749/2003', '2003-06-26', null],
            '44' => ['Decreto 766/2003', '2003-12-19', 205200.00],
            '45' => ['Decreto 769/2004', '2004-02-10', 71400.00],
            '46' => ['Decreto 808/2004', '2004-08-24', 123285.22],
            '47' => ['Decreto 827/2004', '2004-12-27', 339379.00],
            '48' => ['Decreto 1000/2008', '2008-07-03', 50000.00],
            '49' => ['Decreto 906/2006', '2006-09-25', 63420.00],
            '52' => ['Decreto 1087/2009', '2009-10-21', 2272.50],
            '53' => ['Decreto 1374/2013', '2013-10-11', 1123.20],
            '54' => ['Decreto 1165/2010', '2010-10-26', 74971.58],
            '55' => ['Decreto 1078/2009', '2009-09-21', 29421.82],
            '56' => ['Decreto 1203/2011', '2011-06-17', 59006.78],
            '57' => ['Decreto 1210/2011', '2011-07-13', 65030.30],
            '58' => ['Decreto 1265/2012', '2012-04-24', 49960.94],
            '60' => ['Decreto 1224/2011', '2011-09-27', 543257.60],
            '61' => ['Decreto 1291/2012', '2012-08-16', 37380.00],
            '62' => ['Decreto 1292/2012', '2012-08-28', 88471.55],
            '63' => ['Decreto 1306/2012', '2012-11-22', 428497.30],
            '64' => ['Decreto 1316/2012', '2012-12-05', 149748.00],
            '65' => ['Decreto 1317/2012', '2012-12-13', 563492.94],
            '66' => ['Decreto 1278/2012', '2012-06-13', null],
            '67' => ['Decreto 1527/2015', '2015-11-09', 300000.00],
            '69' => ['Decreto 1963/2020', '2020-09-02', 23425.15],
            '70' => ['Decreto 1962/2020', '2020-09-02', 21913.85],
            '71' => ['Decreto 1371/2013', '2013-10-07', 317050.58],
            '72' => ['Decreto 1513/2015', '2015-07-02', 1289258.64],
            '73' => ['Decreto 1474/2014', '2014-12-17', 213473.00],
            '74' => ['Decreto 1491/2015', '2015-03-02', 312940.80],
            '75' => ['Decreto 1491/2015', '2015-03-02', 312940.80],
            '76' => ['Decreto 1491/2015', '2015-03-02', 312940.80],
            '77' => ['Decreto 1583/2016', '2016-07-01', 156186.00],
            '78' => ['Decreto 1280/2012', '2012-06-25', 14839.18],
            '81' => ['Decreto 1495/2015', '2015-04-01', 103410.00],
            '82' => ['Decreto 1640/2017', '2017-03-15', 50928.30],
            '83' => ['Decreto 1676/2017', '2017-09-27', 50906.00],
            '84' => ['Decreto 1727/2018', '2018-04-11', 96155.50],
            '85' => ['Decreto 1574/2016', '2016-05-10', 982869.60],
            '86' => ['Decreto 1749/2018', null, 299002.00],
            '88' => ['Decreto 1866/2019', '2019-12-12', 85707.81],
            '89' => ['Decreto 1893/2020', '2020-03-05', 74189.27],
            '90' => ['Decreto 1875/2020', '2020-01-13', 679858.50],
            '91' => ['Decreto 1944/2020', '2020-07-24', 93824.81],
            '92' => ['Decreto 1951/2020', '2020-08-07', 214662.88],
            '93' => ['Decreto 1751/2018', null, 86663.00],
            '94' => ['Decreto 2044/2021', '2021-03-23', 80013.00],
            '96' => ['Decreto 2098/2021', '2021-08-10', 262447.00],
            '97' => ['Decreto 2110/2021', '2021-09-09', 419029.00],
            '98' => ['Decreto 2118/2021', '2021-10-04', 289296.00],
            '99' => ['Decreto 2170/2022', '2022-01-07', 72260.26],
            '100' => ['Decreto 2215/2022', '2022-07-26', 363570.00],
            '102' => ['Decreto 2198/2022', '2022-05-27', 432076.00],
            '105' => ['Decreto 2337/2023', '2023-08-18', 487234.00],
            '106' => ['Decreto 2230/2022', '2022-10-17', 370436.00],
            '107' => ['Decreto 2327/2023', '2023-07-28', 70459.01],
            '108' => ['Decreto 2364/2023', '2023-09-26', 439535.00],
            '109' => ['Decreto 2367/2023', '2023-10-04', 421682.28],
            '110' => ['Decreto 2429/2024', '2024-02-16', null],
            '111' => ['Decreto 2446/2024', '2024-04-05', 274123.41],
            '114' => ['Decreto 2509/2024', '2024-11-19', 513757.00],
            '115' => ['Decreto 2463/2024', '2024-06-07', 191045.00],
            '116' => ['Decreto 2476/2024', '2024-08-05', 137047.16],
            '117' => ['Decreto 2427/2024', '2024-02-15', 414178.00],
            '118' => ['Decreto 2534/2025', '2025-01-28', 407674.29],
            '121' => ['Decreto 2558/2025', '2025-06-30', 790297.00],
            '122' => ['Decreto 2556/2025', '2025-06-24', 454984.00],
            '124' => ['Decreto 2572/2025', '2025-09-03', 775038.00],
            '125' => ['Decreto 2570/2025', '2025-08-21', 134971.00],
        ];

        // codigo do cadastro => [[tipo, ato, data, área, observação], …]: os outros.
        $outros = [
            '5'   => [['ampliacao', 'Decreto 522/1998', '1998-06-10', 114045.78, '1ª ampliação'],
                      ['ampliacao', 'Decreto 655/2001', '2001-07-20', 543490.00, 'Ampliação II'],
                      ['ampliacao', 'Decreto 1225/2011', '2011-09-27', 629495.98, '3ª ampliação']],
            '27'  => [['ampliacao', 'Decreto 523/1998', '1998-06-10', 29325.00, 'Ampliação do Jardim Riva II']],
            '30'  => [['ampliacao', 'Decreto 661/2001', '2001-10-03', 180300.00, 'Ampliação do Jardim Poncho Verde']],
            '61'  => [['ampliacao', 'Decreto 1406/2014', '2014-03-07', 32448.00, '1ª ampliação'],
                      ['ampliacao', 'Decreto 1475/2014', '2014-12-18', 31785.74, '2ª ampliação'],
                      ['ampliacao', 'Decreto 1557/2016', '2016-03-10', 84038.62, '3ª ampliação'],
                      ['ampliacao', 'Decreto 1774/2018', '2018-12-14', 90734.54, '4ª ampliação'],
                      ['alteracao', 'Decreto 1841/2019', '2019-10-09', null, 'Altera o Decreto 1774/2018'],
                      ['ampliacao', 'Decreto 2023/2021', '2021-02-16', 85752.59, '5ª ampliação'],
                      ['ampliacao', 'Decreto 2024/2021', '2021-02-16', 91400.74, '6ª ampliação'],
                      ['ampliacao', 'Decreto 2301/2023', '2023-05-11', 96373.75, '7ª ampliação'],
                      ['ampliacao', 'Decreto 2471/2024', '2024-07-08', 230446.32, '8ª ampliação']],
            '44'  => [['etapa', 'Decreto 1235/2011', '2011-11-17', 339379.00, '2ª etapa']],
            '46'  => [['etapa', 'Decreto 999/2008', '2008-06-23', 87270.00, '2ª etapa']],
            '36'  => [['etapa', 'Decreto 1164/2010', '2010-10-26', 91895.39, '2ª etapa']],
            '45'  => [['aprovacao', 'Decreto 804/2004', '2004-08-02', 21583.93, 'Segundo decreto com o mesmo nome']],
            '49'  => [['aprovacao', 'Decreto 965/2007', '2007-10-19', 63420.00, 'Aprovado de novo, mesma área']],
            '74'  => [['aprovacao', 'Decreto 2325/2023', '2023-07-17', 312940.80, 'Aprovado de novo, mesma área (Parque Imperial inteiro)']],
            '75'  => [['aprovacao', 'Decreto 2325/2023', '2023-07-17', 312940.80, 'Aprovado de novo, mesma área (Parque Imperial inteiro)']],
            '76'  => [['aprovacao', 'Decreto 2325/2023', '2023-07-17', 312940.80, 'Aprovado de novo, mesma área (Parque Imperial inteiro)']],
            '6'   => [['regularizacao', 'Decreto 1183/2011', '2011-02-02', 45000.00, 'Quadras 41 a 45'],
                      ['regularizacao', 'Decreto 1575/2016', '2016-05-10', 257783.74, null]],
            '100' => [['retificacao', 'Decreto 2276/2023', '2023-01-20', null, 'Retifica o Decreto 2215/2022']],
            '108' => [['alteracao', 'Decreto 2605/2025', '2025-11-27', null, 'Altera o Decreto 2364/2023']],
            '53'  => [['retificacao', 'Decreto 2662/2026', '2026-03-30', 1123.20, 'Corrige a área total (era 1.150,06 m²)']],
            '72'  => [['revogacao', 'Decreto 1646/2017', '2017-04-19', null, 'Revoga o Decreto 1513/2015']],
        ];

        $agora = now();
        foreach (DB::table('cadastro_bairros')->get(['id', 'codigo']) as $b) {
            $codigo = ltrim((string) $b->codigo, '0');
            if (! isset($aprovacoes[$codigo]) && ! isset($outros[$codigo])) {
                continue;
            }
            if (DB::table('bairro_atos')->where('cadastro_bairro_id', $b->id)->exists()) {
                continue;
            }
            $linhas = [];
            if (isset($aprovacoes[$codigo])) {
                [$ato, $data, $area] = $aprovacoes[$codigo];
                $linhas[] = ['aprovacao', $ato, $data, $area, null];
            }
            foreach ($outros[$codigo] ?? [] as $o) {
                $linhas[] = $o;
            }
            foreach ($linhas as [$tipo, $ato, $data, $area, $obs]) {
                DB::table('bairro_atos')->insert([
                    'cadastro_bairro_id' => $b->id, 'tipo' => $tipo, 'ato' => $ato, 'data' => $data,
                    'area_m2' => $area, 'observacao' => $obs, 'created_at' => $agora, 'updated_at' => $agora,
                ]);
            }
        }

        // O ato e a data do bairro passam a ser os da aprovação mais antiga
        // (a primeira de cada bairro nesta ordem). O Belvedere perde o
        // "(revogado pelo…)" do texto: a revogação agora é um ato da lista.
        $feitos = [];
        foreach (DB::table('bairro_atos')->where('tipo', 'aprovacao')
                     ->orderByRaw('data IS NULL')->orderBy('data')->orderBy('id')->get() as $a) {
            if (isset($feitos[$a->cadastro_bairro_id])) {
                continue;
            }
            $feitos[$a->cadastro_bairro_id] = true;
            DB::table('cadastro_bairros')->where('id', $a->cadastro_bairro_id)
                ->update(['ato_aprovacao' => $a->ato, 'data_aprovacao' => $a->data]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bairro_atos');
    }
};
