<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cópia local do BCI — Boletim de Cadastro Imobiliário da prefeitura.
 *
 * ── Por que uma CÓPIA e não uma consulta ao vivo ──
 *
 * O fiscal abre a ficha em campo, com 3G ruim, e o banco da prefeitura fica
 * dentro da rede deles. Consulta ao vivo transformaria cada abertura de ficha
 * numa aposta. A cópia responde sempre, e diz de quando ela é: `consultado_em`
 * é o "Últ. Integração" do cabeçalho da ficha. Dado velho identificado vale
 * mais do que tela em branco.
 *
 * ── O que NÃO está aqui, de propósito ──
 *
 * Inscrição, quadra, lote e CEP não têm coluna. O sistema já sabe bairro,
 * quadra e lote, e a inscrição é montada a partir deles com o código do
 * bairro. Guardar de novo criaria duas versões do mesmo fato, que um dia
 * divergem — e aí ninguém sabe qual vale. O nome do proprietário, que no
 * relatório do BCI aparece no cabeçalho e outra vez na seção dele, é gravado
 * uma vez só, em `bci_proprietarios`.
 *
 * ── Por que as características em chave/valor ──
 *
 * São 22 pares no BCI de Primavera (água, asfalto, calçada, topografia,
 * pedologia…), e a lista muda de município para município. Este sistema é
 * para ser replicável: colunas fixas exigiriam uma migration por cidade.
 * Chave/valor exibe qualquer conjunto sem tocar no esquema. Os campos do
 * cabeçalho, esses sim, são colunas — são estruturais e alimentam outras
 * telas.
 *
 * ── As medidas ──
 *
 * `testada_m` é a frente do lote; `medida_lado_direito`, `medida_lado_esquerdo`
 * e `medida_fundo` são as outras três. Juntas são a forma do terreno segundo o
 * cadastro, e é com elas que o croqui vai poder trazer as medidas do imóvel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bci_imoveis', function (Blueprint $t) {
            $t->id();

            // Um imóvel do cadastro para cada lote, e não mais: o BCI é o
            // retrato do imóvel, não um histórico. Reconsultar sobrescreve.
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

            // NÃO se chama `situacao`: o lote já tem uma (ativo/baixado por
            // sucessão) e a colisão de nome viraria colisão de sentido. Aqui
            // é o campo Isenção do BCI — diferente de "Inativo" quer dizer
            // imóvel ativo, e o valor em si (Normal, Isento…) é informação.
            $t->string('isencao', 40)->nullable();

            $t->string('setor', 120)->nullable();
            $t->string('regiao_fiscal', 80)->nullable();

            $t->timestamp('consultado_em')->nullable();
            $t->string('consultado_por', 120)->nullable();
            $t->timestamps();
        });

        Schema::create('bci_proprietarios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bci_imovel_id')->constrained('bci_imoveis')->cascadeOnDelete();
            $t->string('nome', 160);
            $t->string('documento', 24)->nullable();   // CPF ou CNPJ, como veio
            $t->string('rg_ie', 30)->nullable();
            $t->string('nacionalidade', 40)->nullable();
            $t->string('estado_civil', 40)->nullable();
            $t->string('endereco_logradouro', 160)->nullable();
            $t->string('endereco_numero', 20)->nullable();
            $t->string('endereco_bairro', 120)->nullable();
            $t->string('endereco_cidade', 120)->nullable();
            $t->string('endereco_uf', 2)->nullable();
            $t->timestamps();
        });

        Schema::create('bci_caracteristicas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bci_imovel_id')->constrained('bci_imoveis')->cascadeOnDelete();
            $t->string('chave', 60);
            $t->string('valor', 120)->nullable();
            $t->unsignedSmallInteger('ordem')->default(0);   // preserva a ordem do BCI
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
    }

    public function down(): void
    {
        Schema::dropIfExists('bci_unidades');
        Schema::dropIfExists('bci_caracteristicas');
        Schema::dropIfExists('bci_proprietarios');
        Schema::dropIfExists('bci_imoveis');
    }
};
