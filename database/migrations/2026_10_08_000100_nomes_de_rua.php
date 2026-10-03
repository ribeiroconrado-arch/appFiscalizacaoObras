<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOMES DE RUA no mapa, tirados do cadastro municipal.
 *
 * O DWG trouxe lotes, não ruas: não há eixo de logradouro na base. O nome sai
 * dos LADOS das quadras — cada lote vota no logradouro do seu endereço — e
 * vira um TRECHO: um segmento sobre o meio da rua, de onde o mapa tira ponto,
 * ângulo e comprimento do rótulo. Ver calcularQuadrasERuas (bairros-contorno.js).
 *
 * Duas tabelas, de vidas diferentes:
 *  - `ruas_trechos`: o que o "Gerar" calcula. Substituída inteira a cada
 *    geração do bairro. `nome` nulo é o trecho em que o voto não decidiu
 *    (esquina sem logradouro, por exemplo) — guardado para a ferramenta
 *    mostrar em vermelho, onde falta informar;
 *  - `ruas_manuais`: o que o curador informa (ou oculta). O "Gerar" nunca
 *    apaga: na leitura, o manual cobre o trecho gerado com que coincide
 *    (App\Cadastro\TrechosDeRua).
 *
 * Sem geometria espacial: são poucas linhas por bairro, lidas por bairro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ruas_trechos', function (Blueprint $t) {
            $t->id();
            $t->string('bairro', 120)->index();
            $t->string('nome', 180)->nullable();
            $t->decimal('de_lat', 10, 7);
            $t->decimal('de_lon', 10, 7);
            $t->decimal('ate_lat', 10, 7);
            $t->decimal('ate_lon', 10, 7);
            $t->timestamps();
        });

        Schema::create('ruas_manuais', function (Blueprint $t) {
            $t->id();
            $t->string('bairro', 120)->index();
            $t->string('nome', 180)->nullable();
            // Rótulo errado e sem nome melhor: o trecho some do mapa.
            $t->boolean('oculto')->default(false);
            $t->decimal('de_lat', 10, 7);
            $t->decimal('de_lon', 10, 7);
            $t->decimal('ate_lat', 10, 7);
            $t->decimal('ate_lon', 10, 7);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruas_manuais');
        Schema::dropIfExists('ruas_trechos');
    }
};
