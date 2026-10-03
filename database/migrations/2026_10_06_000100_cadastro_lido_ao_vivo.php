<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A FICHA LÊ O CADASTRO MUNICIPAL AO VIVO — sai a cópia por lote.
 *
 * `bci_imoveis`, `bci_caracteristicas` e `bci_unidades` eram uma CÓPIA do
 * cadastro, tirada quando alguém clicava "Atualizar" na aba BCI. Com a carga
 * mensal (2026_10_05_000100), o cadastro já está no banco, sempre na última
 * versão, com histórico do que mudou. A cópia virou duplicação — e duas
 * versões do mesmo fato divergem. Nada se perde ao apagá-la: tudo nela veio
 * de `cadastro_externo_imoveis`.
 *
 * O documento lavrado continua congelando o que usou (LavraturaService):
 * além da data e da fonte, passa a guardar a CARGA (`cadastro_carga_id`) e o
 * retrato do terreno naquele momento (`cadastro_retrato`).
 *
 * E a colação de `cadastro_bairros.nome_gis` passa a ser a mesma de
 * `lotes.bairro` (utf8mb4_0900_ai_ci). Com colações diferentes, juntar as
 * duas tabelas em SQL falhava ("Illegal mix of collations") e o código
 * resolvia os nomes em PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->foreignId('cadastro_carga_id')->nullable()->after('cadastro_fonte')
                ->constrained('cadastro_cargas')->nullOnDelete();
            $t->json('cadastro_retrato')->nullable()->after('cadastro_carga_id');
        });

        Schema::dropIfExists('bci_unidades');
        Schema::dropIfExists('bci_caracteristicas');
        Schema::dropIfExists('bci_imoveis');

        DB::statement('ALTER TABLE cadastro_bairros MODIFY nome_gis VARCHAR(160)
            CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cadastro_bairros MODIFY nome_gis VARCHAR(160)
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');

        // A estrutura volta, vazia: a cópia se refaria pelo "Atualizar" antigo.
        Schema::create('bci_imoveis', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lote_id')->unique()->constrained('lotes')->cascadeOnDelete();
            $t->string('codigo_cadastro', 30)->nullable();
            $t->string('inscricao_alternativa', 40)->nullable();
            $t->string('logradouro', 160)->nullable();
            $t->string('numero_predial', 20)->nullable();
            $t->string('complemento', 120)->nullable();
            $t->decimal('area_terreno_m2', 12, 2)->nullable();
            $t->decimal('area_edificada_m2', 12, 2)->nullable();
            $t->decimal('testada_m', 10, 2)->nullable();
            $t->decimal('medida_lado_direito', 10, 2)->nullable();
            $t->decimal('medida_lado_esquerdo', 10, 2)->nullable();
            $t->decimal('medida_fundo', 10, 2)->nullable();
            $t->decimal('fracao_ideal', 10, 4)->nullable();
            $t->string('isencao', 40)->nullable();
            $t->string('setor', 120)->nullable();
            $t->string('regiao_fiscal', 80)->nullable();
            $t->timestamp('consultado_em')->nullable();
            $t->string('consultado_por', 120)->nullable();
            $t->string('fonte', 40)->nullable();
            $t->timestamps();
        });
        Schema::create('bci_caracteristicas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bci_imovel_id')->constrained('bci_imoveis')->cascadeOnDelete();
            $t->string('chave', 60);
            $t->string('valor', 120)->nullable();
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->unique(['bci_imovel_id', 'chave']);
        });
        Schema::create('bci_unidades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bci_imovel_id')->constrained('bci_imoveis')->cascadeOnDelete();
            $t->string('numero', 20)->nullable();
            $t->unsignedSmallInteger('ano_construcao')->nullable();
            $t->decimal('area_edificada_m2', 12, 2)->nullable();
            $t->unsignedSmallInteger('pontos')->nullable();
            $t->string('padrao', 40)->nullable();
            $t->timestamps();
        });

        Schema::table('documentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cadastro_carga_id');
            $t->dropColumn('cadastro_retrato');
        });
    }
};
