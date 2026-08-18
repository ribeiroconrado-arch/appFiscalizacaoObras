<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parâmetros do sistema: usuários (tela), UPF, feriados e a base de cálculo
 * das multas de obras.
 *
 * Duas coisas que estavam em código e não podiam ficar:
 *
 *  1. UPF — muda todo exercício por decreto. Documento lavrado em 2026 tem de
 *     continuar valendo a UPF de 2026 mesmo depois da atualização, então o
 *     valor é histórico por exercício, não um número único editável.
 *
 *  2. Feriados — estavam numa constante de LavraturaService. Ponto facultativo
 *     municipal muda todo ano e não dá para versionar em código: prazo de
 *     defesa contado com feriado errado vicia o processo.
 *
 * E uma diferença de regra em relação ao AppPOSTURAS que motivou esta
 * migration: no Código de Obras a maioria das multas é proporcional à ÁREA
 * (construída ou do terreno), não valor fixo. Um único campo `multa_upf` não
 * distingue "5 UPF" de "5 UPF por m²" — e num imóvel de 300 m² a diferença
 * entre os dois é de 5 para 1.500 UPF.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── PARÂMETROS GERAIS ────────────────────────────────────
        // Chave/valor porque são dados de cabeçalho/rodapé de PDF (órgão,
        // secretaria, endereço, lei de criação) e configurações soltas. Como
        // colunas, cada dado novo seria uma migration; aqui é tela de admin.
        Schema::create('parametros', function (Blueprint $t) {
            $t->string('chave', 60)->primary();
            $t->text('valor')->nullable();
            $t->string('grupo', 30)->default('geral');
            $t->string('descricao', 160)->nullable();
            $t->timestamps();
        });

        // ── UPF POR EXERCÍCIO ────────────────────────────────────
        Schema::create('upfs', function (Blueprint $t) {
            $t->id();
            $t->year('exercicio')->unique();
            // 4 casas: decreto de UPF costuma trazer valor com frações
            // (ex.: 4,7896), e arredondar aqui distorce a multa.
            $t->decimal('valor', 12, 4);
            $t->date('vigencia_inicio');
            $t->string('norma', 80)->nullable();      // "Decreto 1.234/2025"
            $t->timestamps();
        });

        // ── FERIADOS ─────────────────────────────────────────────
        Schema::create('feriados', function (Blueprint $t) {
            $t->id();
            $t->date('data');
            $t->string('nome', 80);
            $t->enum('tipo', ['nacional', 'estadual', 'municipal', 'facultativo'])
              ->default('municipal');
            // Feriado de data fixa (1/1, 7/9) repete todo ano: marcado como
            // recorrente, o cadastro não precisa ser refeito a cada exercício.
            // Móvel (Carnaval, Corpus Christi) entra ano a ano.
            $t->boolean('recorrente')->default(false);
            $t->timestamps();
            $t->unique(['data', 'nome'], 'uq_feriado');
            $t->index('data');
        });

        // ── BASE DE CÁLCULO DA MULTA, NO ARTIGO ──────────────────
        Schema::table('artigos', function (Blueprint $t) {
            // `fixa`            -> multa_upf é o valor devido
            // `area_construida` -> multa_upf_m2 x área construída
            // `area_terreno`    -> multa_upf_m2 x área do terreno
            // `sem_multa`       -> artigo que só embasa notificação/embargo
            $t->enum('base_multa', ['fixa', 'area_construida', 'area_terreno', 'sem_multa'])
              ->default('fixa')->after('sancao');

            // Valor por m² em UPF. 4 casas porque a fração por m² é pequena
            // (0,05 UPF/m² é comum) e 2 casas já arredondariam para zero.
            $t->decimal('multa_upf_m2', 10, 4)->nullable()->after('multa_upf');

            // Piso e teto. O piso é o que torna a multa por área aplicável a
            // uma edícula de 8 m²; o teto evita que um galpão gere multa
            // confiscatória e derrube o auto por desproporcionalidade.
            $t->decimal('multa_min_upf', 10, 2)->nullable()->after('multa_upf_m2');
            $t->decimal('multa_max_upf', 10, 2)->nullable()->after('multa_min_upf');
        });

        // ── ÁREAS NO DOCUMENTO ───────────────────────────────────
        Schema::table('documentos', function (Blueprint $t) {
            // A do terreno vem do GIS e o fiscal só confere; a construída é
            // MEDIDA em campo — não existe no cadastro hoje, e derivá-la do
            // lote produziria multa sem lastro.
            $t->decimal('area_terreno_m2', 12, 2)->nullable()->after('valor_upf');
            $t->decimal('area_construida_m2', 12, 2)->nullable()->after('area_terreno_m2');
            // UPF do exercício, copiada na lavratura: o valor em reais do
            // documento não pode mudar quando a UPF do ano seguinte entrar.
            $t->decimal('upf_valor', 12, 4)->nullable()->after('area_construida_m2');
        });

        // ── MEMÓRIA DE CÁLCULO, ARTIGO POR ARTIGO ────────────────
        // Sem isto o documento mostra só o total e ninguém consegue refazer a
        // conta em defesa administrativa — que é justamente o direito do
        // autuado. Congelada junto com o texto do artigo.
        Schema::table('documento_artigos', function (Blueprint $t) {
            $t->enum('base_multa', ['fixa', 'area_construida', 'area_terreno', 'sem_multa'])
              ->default('fixa')->after('sancao');
            $t->decimal('multa_upf_m2', 10, 4)->nullable()->after('multa_upf');
            $t->decimal('area_m2', 12, 2)->nullable()->after('multa_upf_m2');
            // Resultado já com piso e teto aplicados.
            $t->decimal('valor_upf', 10, 2)->nullable()->after('area_m2');
        });
    }

    public function down(): void
    {
        Schema::table('documento_artigos', function (Blueprint $t) {
            $t->dropColumn(['base_multa', 'multa_upf_m2', 'area_m2', 'valor_upf']);
        });
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn(['area_terreno_m2', 'area_construida_m2', 'upf_valor']);
        });
        Schema::table('artigos', function (Blueprint $t) {
            $t->dropColumn(['base_multa', 'multa_upf_m2', 'multa_min_upf', 'multa_max_upf']);
        });
        Schema::dropIfExists('feriados');
        Schema::dropIfExists('upfs');
        Schema::dropIfExists('parametros');
    }
};
