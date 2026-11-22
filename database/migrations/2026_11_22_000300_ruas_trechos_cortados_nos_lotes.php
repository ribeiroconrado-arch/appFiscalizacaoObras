<?php

use App\Repositories\LoteRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CORREÇÃO ÚNICA (22/11/2026): corta os trechos de rua GERADOS onde passam
 * por dentro de lote — a reta do lado da quadra seguia por cima dos lotes da
 * quadra vizinha encostada (R. Rio de Janeiro, Jardim Maringá). Mesma regra
 * de LoteRepository::trechoForaDeLotes, que daqui em diante o "Gerar" aplica
 * ao gravar: ficam só os pedaços na rua; o trecho todo em lote sai.
 *
 * Só `ruas_trechos` (dado calculado, que o "Gerar" refaz). Os manuais não
 * são tocados.
 */
return new class extends Migration
{
    public function up(): void
    {
        $repo = app(LoteRepository::class);
        $apagar = [];
        $novos = [];
        foreach (DB::table('ruas_trechos')->orderBy('id')->cursor() as $t) {
            $de = [(float) $t->de_lat, (float) $t->de_lon];
            $ate = [(float) $t->ate_lat, (float) $t->ate_lon];
            $partes = $repo->trechoForaDeLotes($de, $ate);
            if (count($partes) === 1 && $partes[0] === [$de, $ate]) {
                continue;
            }
            $apagar[] = $t->id;
            foreach ($partes as [$a, $b]) {
                $novos[] = ['bairro' => $t->bairro, 'nome' => $t->nome,
                    'de_lat' => $a[0], 'de_lon' => $a[1], 'ate_lat' => $b[0], 'ate_lon' => $b[1],
                    'created_at' => $t->created_at, 'updated_at' => now()];
            }
        }
        DB::transaction(function () use ($apagar, $novos) {
            foreach (array_chunk($apagar, 500) as $ids) {
                DB::table('ruas_trechos')->whereIn('id', $ids)->delete();
            }
            foreach (array_chunk($novos, 500) as $bloco) {
                DB::table('ruas_trechos')->insert($bloco);
            }
        });
    }

    public function down(): void
    {
        // Sem volta: o "Gerar" do bairro recalcula os trechos.
    }
};
