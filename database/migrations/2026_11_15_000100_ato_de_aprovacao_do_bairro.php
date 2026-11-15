<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O ATO QUE APROVOU O BAIRRO (15/11/2026): a lei ou o decreto e a data.
 *
 * Os valores iniciais saíram do Leis Municipais (leis.org), lendo o art. 1º de
 * cada decreto "Aprova o Projeto de Loteamento / de Condomínio" e "Regulariza
 * o Condomínio" de Primavera do Leste, e casando o nome com o do cadastro
 * (docs/loteamentos-atos-de-aprovacao-v2.csv, no repositório do mapa). Quando o
 * bairro tem ampliações ou etapas, fica o ato da PRIMEIRA aprovação; as
 * demais estão na planilha. Bairro sem ato encontrado fica vazio — não se
 * chuta —, e a tela de Parâmetros › Bairros completa à mão.
 *
 * Só preenche o que está vazio: rodar de novo não apaga correção feita na tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cadastro_bairros', function (Blueprint $t) {
            $t->string('ato_aprovacao', 160)->nullable()->after('apelido');
            $t->date('data_aprovacao')->nullable()->after('ato_aprovacao');
        });

        // codigo do cadastro => [ato, data]
        $atos = [
            '4' => ['Decreto 210/1992', '1992-04-06'],
            '5' => ['Decreto 74/1988', '1988-12-16'],
            '6' => ['Decreto 65/1988', '1988-09-15'],
            '7' => ['Decreto 959/2007', '2007-09-06'],
            '9' => ['Decreto 156/1991', '1991-06-12'],
            '10' => ['Decreto 155/1991', '1991-06-12'],
            '11' => ['Decreto 154/1991', '1991-06-12'],
            '14' => ['Decreto 360/1994', '1994-03-22'],
            '15' => ['Decreto 961/2007', '2007-09-06'],
            '16' => ['Decreto 442/1996', '1996-08-14'],
            '17' => ['Decreto 459/1996', '1996-12-13'],
            '18' => ['Decreto 447/1996', '1996-09-13'],
            '19' => ['Decreto 445/1996', '1996-08-16'],
            '20' => ['Decreto 446/1996', '1996-09-04'],
            '22' => ['Decreto 560/1999', '1999-05-11'],
            '23' => ['Decreto 401/1995', '1995-05-09'],
            '24' => ['Decreto 933/2007', '2007-05-04'],
            '26' => ['Decreto 461/1996', '1996-12-16'],
            '27' => ['Decreto 500/1997', '1997-11-27'],
            '28' => ['Decreto 462/1996', '1996-12-16'],
            '30' => ['Decreto 608/2000', '2000-09-15'],
            '31' => ['Decreto 654/2001', '2001-07-20'],
            '32' => ['Decreto 589/2000', '2000-04-25'],
            '33' => ['Decreto 682/2002', '2002-05-22'],
            '34' => ['Decreto 606/2000', '2000-09-01'],
            '36' => ['Decreto 939/2007', '2007-06-27'],
            '37' => ['Decreto 666/2001', '2001-11-20'],
            '38' => ['Decreto 686/2002', '2002-06-13'],
            '39' => ['Decreto 898/2006', '2006-07-10'],
            '40' => ['Decreto 994/2008', '2008-05-06'],
            '43' => ['Decreto 749/2003', '2003-06-26'],
            '44' => ['Decreto 766/2003', '2003-12-19'],
            '45' => ['Decreto 769/2004', '2004-02-10'],
            '46' => ['Decreto 808/2004', '2004-08-24'],
            '47' => ['Decreto 827/2004', '2004-12-27'],
            '48' => ['Decreto 1000/2008', '2008-07-03'],
            '49' => ['Decreto 906/2006', '2006-09-25'],
            '52' => ['Decreto 1087/2009', '2009-10-21'],
            '53' => ['Decreto 1374/2013', '2013-10-11'],
            '54' => ['Decreto 1165/2010', '2010-10-26'],
            '55' => ['Decreto 1078/2009', '2009-09-21'],
            '56' => ['Decreto 1203/2011', '2011-06-17'],
            '57' => ['Decreto 1210/2011', '2011-07-13'],
            '58' => ['Decreto 1265/2012', '2012-04-24'],
            '60' => ['Decreto 1224/2011', '2011-09-27'],
            '61' => ['Decreto 1291/2012', '2012-08-16'],
            '62' => ['Decreto 1292/2012', '2012-08-28'],
            '63' => ['Decreto 1306/2012', '2012-11-22'],
            '64' => ['Decreto 1316/2012', '2012-12-05'],
            '65' => ['Decreto 1317/2012', '2012-12-13'],
            '66' => ['Decreto 1278/2012', '2012-06-13'],
            '67' => ['Decreto 1527/2015', '2015-11-09'],
            '69' => ['Decreto 1963/2020', '2020-09-02'],
            '70' => ['Decreto 1962/2020', '2020-09-02'],
            '71' => ['Decreto 1371/2013', '2013-10-07'],
            '72' => ['Decreto 1513/2015 (revogado pelo Decreto 1646/2017)', '2015-07-02'],
            '73' => ['Decreto 1474/2014', '2014-12-17'],
            '74' => ['Decreto 1491/2015', '2015-03-02'],
            '75' => ['Decreto 1491/2015', '2015-03-02'],
            '76' => ['Decreto 1491/2015', '2015-03-02'],
            '77' => ['Decreto 1583/2016', '2016-07-01'],
            '78' => ['Decreto 1280/2012', '2012-06-25'],
            '81' => ['Decreto 1495/2015', '2015-04-01'],
            '82' => ['Decreto 1640/2017', '2017-03-15'],
            '83' => ['Decreto 1676/2017', '2017-09-27'],
            '84' => ['Decreto 1727/2018', '2018-04-11'],
            '85' => ['Decreto 1574/2016', '2016-05-10'],
            '86' => ['Decreto 1749/2018', null],
            '88' => ['Decreto 1866/2019', '2019-12-12'],
            '89' => ['Decreto 1893/2020', '2020-03-05'],
            '90' => ['Decreto 1875/2020', '2020-01-13'],
            '91' => ['Decreto 1944/2020', '2020-07-24'],
            '92' => ['Decreto 1951/2020', '2020-08-07'],
            '93' => ['Decreto 1751/2018', null],
            '94' => ['Decreto 2044/2021', '2021-03-23'],
            '96' => ['Decreto 2098/2021', '2021-08-10'],
            '97' => ['Decreto 2110/2021', '2021-09-09'],
            '98' => ['Decreto 2118/2021', '2021-10-04'],
            '99' => ['Decreto 2170/2022', '2022-01-07'],
            '100' => ['Decreto 2215/2022', '2022-07-26'],
            '102' => ['Decreto 2198/2022', '2022-05-27'],
            '105' => ['Decreto 2337/2023', '2023-08-18'],
            '106' => ['Decreto 2230/2022', '2022-10-17'],
            '107' => ['Decreto 2327/2023', '2023-07-28'],
            '108' => ['Decreto 2364/2023', '2023-09-26'],
            '109' => ['Decreto 2367/2023', '2023-10-04'],
            '110' => ['Decreto 2429/2024', '2024-02-16'],
            '111' => ['Decreto 2446/2024', '2024-04-05'],
            '114' => ['Decreto 2509/2024', '2024-11-19'],
            '115' => ['Decreto 2463/2024', '2024-06-07'],
            '116' => ['Decreto 2476/2024', '2024-08-05'],
            '117' => ['Decreto 2427/2024', '2024-02-15'],
            '118' => ['Decreto 2534/2025', '2025-01-28'],
            '121' => ['Decreto 2558/2025', '2025-06-30'],
            '122' => ['Decreto 2556/2025', '2025-06-24'],
            '124' => ['Decreto 2572/2025', '2025-09-03'],
            '125' => ['Decreto 2570/2025', '2025-08-21'],
        ];
        foreach ($atos as $codigo => [$ato, $data]) {
            DB::table('cadastro_bairros')->where('codigo', (string) $codigo)->whereNull('ato_aprovacao')
                ->update(['ato_aprovacao' => $ato, 'data_aprovacao' => $data]);
        }
    }

    public function down(): void
    {
        Schema::table('cadastro_bairros', function (Blueprint $t) {
            $t->dropColumn(['ato_aprovacao', 'data_aprovacao']);
        });
    }
};
