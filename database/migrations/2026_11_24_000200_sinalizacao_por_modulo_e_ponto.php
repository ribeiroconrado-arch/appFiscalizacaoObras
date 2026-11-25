<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SINALIZAÇÃO POR MÓDULO, E NA RUA (24/11/2026).
 *
 * Até aqui a sinalização era sempre de Obras e sempre de um lote. Duas coisas
 * mudam:
 *
 *  1. MÓDULO. Cada módulo tem os SEUS seis tipos — os que cabem no balão:
 *       Obras    — obra iniciada, invasão de recuo, entulho na calçada,
 *                  asseio da via, risco, outro
 *       Posturas — obstrução de calçada, veículo abandonado, águas servidas,
 *                  publicidade irregular, risco, outro
 *     A sinalização pertence ao módulo em que foi criada (o da sessão) e só
 *     aparece nele. As que existem são todas de Obras.
 *
 *  2. PONTO. Veículo abandonado, águas servidas, obstrução: o problema está
 *     na via, e o imóvel mais próximo é coincidência — prendê-lo ao lote
 *     encheria o histórico da casa do vizinho de coisa que não é dele. Por
 *     isso toda sinalização passa a guardar o PONTO marcado, e o lote vira
 *     opcional. Na rua, `referencia` diz onde é em texto ("próximo a Qd. 12 ·
 *     Lt. 3 — JARDIM PRIMAVERA"), e o fiscal pode corrigi-la.
 *
 * Tipos que saíram da lista (decisão de 24/11/2026):
 *   avanco          → invasao_recuo
 *   obra_sem_placa  → outro, com "Obra sem placa" no começo do comentário
 *   pedir_vistoria  → outro, com "Pedir vistoria" no começo do comentário
 * O lembrete continua existindo: é a vistoria que o cria, e ele não entra no
 * balão.
 */
return new class extends Migration
{
    private const VIROU_OUTRO = ['obra_sem_placa' => 'Obra sem placa', 'pedir_vistoria' => 'Pedir vistoria'];

    public function up(): void
    {
        Schema::table('sinalizacoes', function (Blueprint $t) {
            $t->string('modulo', 20)->default('obras')->after('id');
            $t->decimal('lat', 10, 7)->nullable()->after('lote_id');
            $t->decimal('lng', 10, 7)->nullable()->after('lat');
            $t->string('referencia', 255)->nullable()->after('lng');
            $t->index(['modulo', 'status']);
        });

        // Lote opcional. A chave estrangeira sai e volta: o MySQL não muda a
        // nulidade de uma coluna presa a ela.
        Schema::table('sinalizacoes', function (Blueprint $t) {
            $t->dropForeign(['lote_id']);
        });
        Schema::table('sinalizacoes', function (Blueprint $t) {
            $t->unsignedBigInteger('lote_id')->nullable()->change();
            $t->foreign('lote_id')->references('id')->on('lotes')->cascadeOnDelete();
        });

        // Tipos que saíram da lista.
        DB::table('sinalizacoes')->where('tipo', 'avanco')->update(['tipo' => 'invasao_recuo']);
        foreach (self::VIROU_OUTRO as $antigo => $nome) {
            DB::table('sinalizacoes')->where('tipo', $antigo)->orderBy('id')->each(function ($s) use ($nome) {
                $c = trim((string) $s->comentario);
                DB::table('sinalizacoes')->where('id', $s->id)->update([
                    'tipo' => 'outro',
                    'comentario' => mb_substr($nome . ($c !== '' ? ' — ' . $c : ''), 0, 500),
                ]);
            });
        }

        // O ponto das que já existem: o meio dos vértices do lote, o mesmo
        // lugar onde a bandeira sempre foi desenhada (ST_Centroid não existe
        // para SRS geográfico no MySQL — ver LoteRepository).
        $ids = DB::table('sinalizacoes')->whereNull('lat')->whereNotNull('lote_id')->distinct()->pluck('lote_id');
        foreach ($ids->chunk(500) as $parte) {
            $lotes = DB::select('SELECT id, ST_AsGeoJSON(geom) AS g FROM lotes WHERE id IN ('
                . $parte->map(fn ($i) => (int) $i)->implode(',') . ')');
            foreach ($lotes as $l) {
                $anel = json_decode($l->g, true)['coordinates'][0] ?? [];
                array_pop($anel);
                if (! $anel) { continue; }
                DB::table('sinalizacoes')->where('lote_id', $l->id)->whereNull('lat')->update([
                    'lng' => round(array_sum(array_column($anel, 0)) / count($anel), 7),
                    'lat' => round(array_sum(array_column($anel, 1)) / count($anel), 7),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Sinalização de rua não tem como voltar a um esquema que exige lote.
        DB::table('sinalizacoes')->whereNull('lote_id')->delete();
        DB::table('sinalizacoes')->where('tipo', 'invasao_recuo')->update(['tipo' => 'avanco']);

        Schema::table('sinalizacoes', function (Blueprint $t) {
            $t->dropForeign(['lote_id']);
        });
        Schema::table('sinalizacoes', function (Blueprint $t) {
            $t->unsignedBigInteger('lote_id')->nullable(false)->change();
            $t->foreign('lote_id')->references('id')->on('lotes')->cascadeOnDelete();
            $t->dropIndex(['modulo', 'status']);
            $t->dropColumn(['modulo', 'lat', 'lng', 'referencia']);
        });
    }
};
