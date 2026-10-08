<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Três coisas que o uso mostrou faltar na parametrização do artigo.
 *
 * 1. EM QUAIS DOCUMENTOS O ARTIGO ENTRA — marcados um a um.
 *    O seletor "não cabe / cabe / exclusivo de embargo" só sabia falar de
 *    embargo. Na prática o art. 22 (prazo de 5 dias) é de NOTIFICAÇÃO e não de
 *    auto, e o art. 121-A é do Auto de Embargo: cada artigo tem as suas peças.
 *    `artigos.documentos` guarda a lista de tipos; o seletor antigo é
 *    convertido e some. O prazo do embargo vira o prazo SUGERIDO da
 *    notificação (`prazo_notificacao_dias`).
 *
 * 2. MULTA ENTRE UM MÍNIMO E UM MÁXIMO, A CRITÉRIO DO FISCAL (`intervalo`).
 *    LC 001/2023, art. 35, §5º: "multa de 50 a 200 UPFs". O fiscal informa o
 *    valor na peça, conforme a gravidade, dentro do intervalo do artigo
 *    (`multa_min_upf` e `multa_max_upf`, que já existiam).
 *
 * 3. REINCIDÊNCIA DOBRA (art. 121-B, §2º).
 *    O auto de infração pode ser lavrado como reincidência de OUTRO auto
 *    (`documentos.reincidencia_de_id`); a cada elo da cadeia a multa dos
 *    artigos marcados (`artigos.multa_dobra_reincidencia`) dobra.
 */
return new class extends Migration
{
    private const BASES = "'sem_multa','fixa','por_m2','faixas','multiplo_alvara'";

    public function up(): void
    {
        Schema::table('artigos', function (Blueprint $t) {
            $t->json('documentos')->nullable()->after('multa_mult_max');
            $t->unsignedSmallInteger('prazo_notificacao_dias')->nullable()->after('documentos');
            $t->boolean('multa_dobra_reincidencia')->default(false)->after('prazo_notificacao_dias');
        });

        // O seletor de embargo vira a lista de documentos.
        $de = [
            'nao'       => ['notificacao', 'auto_infracao'],
            'cabe'      => ['notificacao', 'notificacao_embargo', 'auto_embargo', 'auto_infracao'],
            'exclusivo' => ['notificacao_embargo', 'auto_embargo'],
        ];
        foreach ($de as $embargo => $documentos) {
            DB::table('artigos')->where('embargo', $embargo)->update(['documentos' => json_encode($documentos)]);
        }
        DB::table('artigos')->whereNotNull('embargo_prazo_dias')
            ->update(['prazo_notificacao_dias' => DB::raw('embargo_prazo_dias')]);

        Schema::table('artigos', fn (Blueprint $t) => $t->dropColumn(['embargo', 'embargo_modo', 'embargo_prazo_dias']));

        foreach (['artigos', 'documento_artigos'] as $tabela) {
            DB::statement("ALTER TABLE {$tabela} MODIFY base_multa ENUM(" . self::BASES . ",'intervalo') NOT NULL DEFAULT 'fixa'");
        }

        Schema::table('documentos', function (Blueprint $t) {
            // O auto anterior, de que este é reincidência — e a que distância
            // dele na cadeia (1 = primeira reincidência: multa em dobro).
            $t->foreignId('reincidencia_de_id')->nullable()->after('origem_id')->constrained('documentos')->nullOnDelete();
            $t->unsignedTinyInteger('reincidencia_nivel')->default(0)->after('reincidencia_de_id');
        });
        Schema::table('documento_artigos', function (Blueprint $t) {
            // Por quanto a multa deste artigo foi multiplicada pela reincidência.
            $t->unsignedSmallInteger('fator_reincidencia')->nullable()->after('multiplicador');
        });
    }

    public function down(): void
    {
        Schema::table('documento_artigos', fn (Blueprint $t) => $t->dropColumn('fator_reincidencia'));
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reincidencia_de_id');
            $t->dropColumn('reincidencia_nivel');
        });

        foreach (['artigos', 'documento_artigos'] as $tabela) {
            DB::table($tabela)->where('base_multa', 'intervalo')->update(['base_multa' => 'sem_multa']);
            DB::statement("ALTER TABLE {$tabela} MODIFY base_multa ENUM(" . self::BASES . ") NOT NULL DEFAULT 'fixa'");
        }

        Schema::table('artigos', function (Blueprint $t) {
            $t->enum('embargo', ['nao', 'cabe', 'exclusivo'])->default('nao')->after('multa_max_upf');
            $t->enum('embargo_modo', ['imediato', 'apos_prazo'])->nullable()->after('embargo');
            $t->unsignedSmallInteger('embargo_prazo_dias')->nullable()->after('embargo_modo');
        });
        // De volta ao seletor: quem tem peça de embargo e peça comum "cabe";
        // só de embargo, "exclusivo".
        foreach (DB::table('artigos')->get(['id', 'documentos', 'prazo_notificacao_dias']) as $a) {
            $docs = json_decode($a->documentos ?? '[]', true) ?: [];
            $embarga = (bool) array_intersect($docs, ['notificacao_embargo', 'auto_embargo']);
            $comum = (bool) array_intersect($docs, ['notificacao', 'auto_infracao']);
            DB::table('artigos')->where('id', $a->id)->update([
                'embargo'            => ! $embarga ? 'nao' : ($comum ? 'cabe' : 'exclusivo'),
                'embargo_modo'       => ! $embarga ? null : ($a->prazo_notificacao_dias ? 'apos_prazo' : 'imediato'),
                'embargo_prazo_dias' => $embarga ? $a->prazo_notificacao_dias : null,
            ]);
        }
        Schema::table('artigos', fn (Blueprint $t) => $t->dropColumn(
            ['documentos', 'prazo_notificacao_dias', 'multa_dobra_reincidencia']));
    }
};
