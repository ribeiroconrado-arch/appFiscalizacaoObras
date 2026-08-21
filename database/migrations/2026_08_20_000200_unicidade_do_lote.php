<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice único em (bairro, quadra, numero_lote).
 *
 * É a identificação do imóvel — a chave que amarra o sistema ao cadastro
 * imobiliário. Repetida, ela deixa de identificar: dois lotes diferentes
 * respondem pelo mesmo endereço cadastral, e o auto lavrado num deles pode
 * ser contestado por apontar para o outro.
 *
 * Sem este índice o `ON DUPLICATE KEY UPDATE` do importador NÃO FUNCIONA — o
 * MySQL não tem como reconhecer "o mesmo lote", e a reimportação volta a
 * duplicar em silêncio. Por isso o importador confere a existência dele antes
 * de gravar (ver ImportarLotes).
 *
 * A migração NÃO falha se houver duplicidade pendente: falhar aqui travaria
 * qualquer implantação por causa de dado sujo herdado. Ela avisa, em alto e
 * bom som, e o índice entra quando a base estiver limpa — o próprio importador
 * o cria assim que isso acontece (ver ImportarLotes::garantirIndice).
 *
 * Quadra nula não conta como duplicidade: no MySQL o índice único trata cada
 * NULL como distinto. É de propósito — o extrator deixa a quadra vazia quando
 * o desenho não permite prová-la, e esses lotes entram na base sem travar a
 * identidade dos demais, visíveis em `php artisan gis:conferir`.
 */
return new class extends Migration
{
    private const NOME = 'uk_lotes_identificacao';

    public function up(): void
    {
        if ($this->jaExiste()) {
            return;
        }

        $duplicadas = DB::selectOne('SELECT COUNT(*) n FROM (
            SELECT 1 FROM lotes
             WHERE quadra IS NOT NULL AND numero_lote IS NOT NULL
             GROUP BY bairro, quadra, numero_lote
            HAVING COUNT(*) > 1
        ) t')->n;

        if ($duplicadas > 0) {
            $exemplo = DB::select('SELECT bairro, quadra, numero_lote, COUNT(*) n FROM lotes
                                    GROUP BY bairro, quadra, numero_lote HAVING n > 1
                                    ORDER BY n DESC LIMIT 3');

            echo PHP_EOL;
            echo "  ┌─ ÍNDICE ÚNICO NÃO CRIADO ─────────────────────────────────" . PHP_EOL;
            echo "  │ {$duplicadas} combinações bairro|quadra|lote estão repetidas." . PHP_EOL;
            foreach ($exemplo as $e) {
                echo "  │   {$e->bairro} · Q{$e->quadra} · Lt{$e->numero_lote} aparece {$e->n}x" . PHP_EOL;
            }
            echo "  │" . PHP_EOL;
            echo "  │ Enquanto isso durar, a reimportação DUPLICA em vez de atualizar." . PHP_EOL;
            echo "  │ Diagnóstico:  php artisan gis:conferir" . PHP_EOL;
            echo "  │ Correção:     php artisan lotes:corrigir-quadras --aplicar" . PHP_EOL;
            echo "  └───────────────────────────────────────────────────────────" . PHP_EOL;

            return;
        }

        Schema::table('lotes', function ($t) {
            $t->unique(['bairro', 'quadra', 'numero_lote'], self::NOME);
        });
    }

    public function down(): void
    {
        if ($this->jaExiste()) {
            Schema::table('lotes', fn ($t) => $t->dropUnique(self::NOME));
        }
    }

    private function jaExiste(): bool
    {
        return (bool) DB::selectOne(
            'SELECT COUNT(*) n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = "lotes" AND index_name = ?',
            [self::NOME]
        )->n;
    }
};
