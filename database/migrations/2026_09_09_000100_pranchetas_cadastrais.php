<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('lote_atos', fn (Blueprint $t) => $t->json('visualizacao')->nullable());
        Schema::create('pranchetas_cadastrais', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('chave', 64)->unique();
            $t->json('estado');
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('pranchetas_cadastrais');
        Schema::table('lote_atos', fn (Blueprint $t) => $t->dropColumn('visualizacao'));
    }
};
