<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O QUE ESTÁ CONSTRUÍDO DENTRO DO LOTE.
 *
 * A multa de obras é por metro quadrado construído, e até aqui esse número
 * vinha só da trena do fiscal, anotado na vistoria. Não havia onde desenhar a
 * edificação — nem para conferir o que ele mediu, nem para mostrar no croqui
 * que acompanha a peça.
 *
 * ── Tabela própria, e não uma coluna em `lotes` ──
 *
 * Um lote tem VÁRIAS edificações: a casa, a edícula, o barracão. Guardar uma
 * área construída somada numa coluna do lote responderia "quanto", mas nunca
 * "onde" nem "quais" — e é o "onde" que faz o croqui e que sustenta o auto de
 * embargo de uma construção específica.
 *
 * ── A área é gravada, e não calculada na leitura ──
 *
 * `area_m2` sai do ST_Area do próprio polígono no momento da gravação, pelo
 * mesmo caminho de `lotes.area_gis_m2`. Recalcular a cada consulta seria mais
 * "correto" e teria dois defeitos práticos: custo em toda listagem, e um
 * número que muda sozinho se o cálculo do banco mudar de versão — debaixo de
 * uma multa já lavrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edificacoes', function (Blueprint $t) {
            $t->id();

            // Apagar o lote leva junto o que estava construído nele: edificação
            // sem lote não é registro incompleto, é lixo — e o apagar de lote
            // já recusa qualquer imóvel que tenha história (ver
            // CadastroLoteController::oQuePrende).
            $t->foreignId('lote_id')->constrained('lotes')->cascadeOnDelete();

            $t->decimal('area_m2', 12, 2);
            $t->string('descricao', 160)->nullable();
            $t->timestamps();
        });

        // Fora do Blueprint: a coluna espacial precisa ser NOT NULL para
        // aceitar índice espacial, e o SRID tem de vir declarado na coluna —
        // é como `lotes.geom` foi criada, e divergir aqui faria o ST_Within
        // entre as duas recusar por SRID diferente.
        DB::statement('ALTER TABLE edificacoes ADD COLUMN geom POLYGON NOT NULL SRID 4326');
        DB::statement('ALTER TABLE edificacoes ADD SPATIAL INDEX ix_edificacoes_geom (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('edificacoes');
    }
};
