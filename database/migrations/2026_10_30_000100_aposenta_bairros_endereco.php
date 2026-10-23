<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SAI A LISTA DE BAIRROS HERDADA DO POSTURAS (`bairros_endereco`).
 *
 * Ela era o autocomplete do bairro na denúncia e crescia com o que o agente
 * digitava — a mesma divisa com duas, três grafias. Desde 23/10/2026 o bairro
 * de endereço é o da aba Bairros (`cadastro_bairros.nome_cadastro`): a rua
 * sugere os bairros ligados a ela, e a denúncia só aceita bairro da lista.
 * Ninguém mais lê nem grava a tabela.
 *
 * Antes de apagar, o que ela tem de útil: o nome do bairro da aba Bairros que
 * está sem nome do cadastro recebe o dela, quando o CÓDIGO da prefeitura bate
 * (sem zeros à esquerda). As denúncias antigas guardam o bairro como texto e
 * não mudam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bairros_endereco')) {
            return;
        }
        $porCodigo = [];
        foreach (DB::table('bairros_endereco')->whereNotNull('codigo')->get(['nome', 'codigo']) as $b) {
            $porCodigo[ltrim(trim($b->codigo), '0')] ??= $b->nome;
        }
        foreach (DB::table('cadastro_bairros')->where(fn ($q) => $q->whereNull('nome_cadastro')->orWhere('nome_cadastro', ''))->get(['id', 'codigo']) as $c) {
            $nome = $porCodigo[ltrim(trim((string) $c->codigo), '0')] ?? null;
            if ($nome !== null) {
                DB::table('cadastro_bairros')->where('id', $c->id)->update(['nome_cadastro' => mb_substr($nome, 0, 160), 'updated_at' => now()]);
            }
        }

        Schema::drop('bairros_endereco');
    }

    /** Volta a tabela vazia, na largura de 2026_10_29_000100: o conteúdo não se recupera. */
    public function down(): void
    {
        Schema::create('bairros_endereco', function (Blueprint $t) {
            $t->id();
            $t->string('nome', 255)->unique();
            $t->string('codigo', 20)->nullable();
            $t->timestamps();
        });
    }
};
