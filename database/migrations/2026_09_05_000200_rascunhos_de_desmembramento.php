<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desmembramento_rascunhos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lote_id')->constrained('lotes')->cascadeOnDelete();
            $t->foreignId('protocolo_id')->nullable()->constrained('protocolos')->cascadeOnDelete();
            // A chave evita a ambiguidade de UNIQUE com NULL no MySQL e garante
            // um rascunho por pessoa, processo (ou ato direto) e lote.
            $t->string('chave', 100)->unique();
            $t->json('estado');
            $t->timestamps();
            $t->index(['user_id', 'lote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desmembramento_rascunhos');
    }
};
