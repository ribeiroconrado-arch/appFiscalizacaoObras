<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A IRREGULARIDADE VIRA TERMO DE BUSCA DO ARTIGO.
 *
 * Só se atua no que está fora da legalidade, e quem define a ilegalidade é o
 * artigo. O catálogo separado de irregularidades (com gravidade, código e o
 * vínculo `artigo_irregularidade`) obrigava a manter duas listas em sincronia,
 * e a vistoria só achava artigo pelo caminho da irregularidade. Agora cada
 * artigo tem os seus TERMOS DE BUSCA ("escavação", "terraplenagem"…): o
 * fiscal digita o problema e o sistema mostra os artigos que tratam dele.
 *
 * O que é aproveitado:
 *  - o nome de cada irregularidade LIGADA a artigo vira termo desses artigos;
 *  - irregularidade marcada numa vistoria, se ligada a artigo, vira ARTIGO
 *    CITADO no mesmo item da vistoria (vistoria_artigos), se ainda não está lá.
 * Irregularidade sem artigo (o catálogo genérico de teste) é descartada, por
 * decisão do usuário.
 *
 * O `down` recria as tabelas VAZIAS: o que foi convertido não volta a ser
 * irregularidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artigos', function (Blueprint $t) {
            $t->json('termos')->nullable()->after('apelido');
        });

        if (Schema::hasTable('artigo_irregularidade') && Schema::hasTable('irregularidades')) {
            // 1. Nome da irregularidade → termo do artigo.
            $vinculos = DB::table('artigo_irregularidade as ai')
                ->join('irregularidades as i', 'i.id', '=', 'ai.irregularidade_id')
                ->get(['ai.artigo_id', 'i.descricao']);
            $porArtigo = [];
            foreach ($vinculos as $v) {
                $termo = trim(preg_replace('/\s+/u', ' ', (string) $v->descricao));
                if ($termo === '') {
                    continue;
                }
                $chave = mb_strtolower($termo);
                $porArtigo[$v->artigo_id][$chave] ??= mb_substr($termo, 0, 60);
            }
            foreach ($porArtigo as $artigoId => $termos) {
                DB::table('artigos')->where('id', $artigoId)->update(['termos' => json_encode(array_values($termos), JSON_UNESCAPED_UNICODE)]);
            }

            // 2. Irregularidade marcada na vistoria → artigo citado no mesmo item.
            if (Schema::hasTable('vistoria_irregularidades')) {
                $marcadas = DB::table('vistoria_irregularidades as vi')
                    ->join('artigo_irregularidade as ai', 'ai.irregularidade_id', '=', 'vi.irregularidade_id')
                    ->orderBy('vi.id')
                    ->get(['vi.vistoria_id', 'vi.item_id', 'ai.artigo_id']);
                $agora = now();
                foreach ($marcadas as $m) {
                    $ja = DB::table('vistoria_artigos')->where('vistoria_id', $m->vistoria_id)
                        ->where('artigo_id', $m->artigo_id)
                        ->when($m->item_id, fn ($q) => $q->where('item_id', $m->item_id), fn ($q) => $q->whereNull('item_id'))
                        ->exists();
                    if ($ja) {
                        continue;
                    }
                    $ordem = (int) DB::table('vistoria_artigos')->where('vistoria_id', $m->vistoria_id)->max('ordem') + 1;
                    DB::table('vistoria_artigos')->insert([
                        'vistoria_id' => $m->vistoria_id, 'item_id' => $m->item_id, 'artigo_id' => $m->artigo_id,
                        'tipo' => 'citacao', 'ordem' => $ordem, 'created_at' => $agora, 'updated_at' => $agora,
                    ]);
                }
            }
        }

        Schema::dropIfExists('vistoria_irregularidades');
        Schema::dropIfExists('artigo_irregularidade');
        Schema::dropIfExists('irregularidades');
    }

    public function down(): void
    {
        Schema::create('irregularidades', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 20)->unique();
            $t->string('descricao', 200);
            $t->enum('gravidade', ['leve', 'media', 'grave'])->default('media');
            $t->string('base_legal', 200)->nullable();
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->boolean('ativo')->default(true);
            $t->timestamps();
            $t->index(['ativo', 'ordem']);
        });
        Schema::create('artigo_irregularidade', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artigo_id')->constrained('artigos')->cascadeOnDelete();
            $t->foreignId('irregularidade_id')->constrained('irregularidades')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['artigo_id', 'irregularidade_id'], 'uq_artigo_irreg');
        });
        Schema::create('vistoria_irregularidades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->nullable()->constrained('vistoria_itens')->nullOnDelete();
            $t->foreignId('vistoria_id')->constrained('vistorias')->cascadeOnDelete();
            $t->foreignId('irregularidade_id')->constrained('irregularidades');
            $t->text('observacao')->nullable();
            $t->timestamps();
            $t->unique(['vistoria_id', 'irregularidade_id']);
        });
        Schema::table('artigos', function (Blueprint $t) {
            $t->dropColumn('termos');
        });
    }
};
