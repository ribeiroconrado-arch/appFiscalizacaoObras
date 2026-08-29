<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A VISTORIA PASSA A TER NÚMERO.
 *
 * Ela ganhou uma via de papel — o relatório que se anexa ao processo e se
 * entrega ao proprietário —, e relatório sem identificador não se cita: "a
 * vistoria de 29/08" é ambíguo no dia em que houver duas no mesmo imóvel.
 *
 * A série é a mesma máquina dos autos: `documento_contadores`, chaveada por
 * (tipo, exercicio), com a linha travada em transação. `Documento::TIPOS` já
 * tratava `vistoria` como um tipo, com a sigla VIS — o contador só passa a ter
 * uma linha a mais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vistorias', function (Blueprint $t) {
            $t->unsignedInteger('exercicio')->nullable()->after('fiscal_id');
            $t->unsignedInteger('numero')->nullable()->after('exercicio');
            // Único por exercício, como nos documentos: a série recomeça a cada
            // ano, e é assim que ela é citada — VIS 2026/0001.
            $t->unique(['exercicio', 'numero'], 'vistorias_serie_unica');
        });

        // ── AS QUE JÁ EXISTEM ──
        //
        // Numeradas na ordem em que aconteceram, e não na ordem do id: a série
        // conta a sequência dos ATOS. Empate de instante desempata pelo id, que
        // é a ordem de gravação.
        //
        // Tudo numa transação: parar no meio deixaria metade da base numerada e
        // o contador mentindo sobre onde a série está.
        DB::transaction(function () {
            $porAno = [];

            $vistorias = DB::table('vistorias')
                ->select('id', 'data_hora', 'created_at')
                ->orderBy('data_hora')->orderBy('id')
                ->get();

            foreach ($vistorias as $v) {
                $quando = $v->data_hora ?: $v->created_at;
                $ano = (int) date('Y', strtotime((string) $quando));

                $porAno[$ano] = ($porAno[$ano] ?? 0) + 1;

                DB::table('vistorias')->where('id', $v->id)->update([
                    'exercicio' => $ano,
                    'numero'    => $porAno[$ano],
                ]);
            }

            // O contador precisa saber onde a série parou, senão a próxima
            // vistoria gravada sairia com o número 1 e esbarraria no índice
            // único — falha na hora errada, no meio do trabalho de campo.
            foreach ($porAno as $ano => $ultimo) {
                $existente = DB::table('documento_contadores')
                    ->where('tipo', 'vistoria')->where('exercicio', $ano)->first();

                if ($existente) {
                    DB::table('documento_contadores')->where('id', $existente->id)
                        ->update(['ultimo' => max($existente->ultimo, $ultimo), 'updated_at' => now()]);
                } else {
                    DB::table('documento_contadores')->insert([
                        'tipo' => 'vistoria', 'exercicio' => $ano, 'ultimo' => $ultimo,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('vistorias', function (Blueprint $t) {
            $t->dropUnique('vistorias_serie_unica');
            $t->dropColumn(['exercicio', 'numero']);
        });

        DB::table('documento_contadores')->where('tipo', 'vistoria')->delete();
    }
};
