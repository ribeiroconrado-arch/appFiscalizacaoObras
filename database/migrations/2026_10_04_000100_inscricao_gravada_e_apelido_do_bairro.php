<?php

use App\Cadastro\InscricoesGravadas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inscrição gravada no lote, e apelido do bairro separado da amarração.
 *
 * O QUE ACONTECEU: o "nome no desenho" (`cadastro_bairros.nome_gis`) era, ao
 * mesmo tempo, o rótulo que se queria ver no mapa e a CHAVE que liga os lotes
 * ao código do bairro. Encurtá-lo para o mapa ("Residencial Buritis V" →
 * "BURITIS V") desligou os lotes, e a inscrição deixou de ser montada.
 *
 * O QUE ESTA MIGRAÇÃO FAZ:
 *   1. `cadastro_bairros.apelido` — o nome curto do mapa, livre para editar;
 *      `nome_gis` passa a ser só a amarração (e trava quando há lote nela).
 *   2. `lotes.inscricao_montada` (+ quando) — a inscrição gravada, para não se
 *      perder e para comparar com o cadastro da prefeitura em SQL.
 *   3. REPARO: bairro cujo `nome_gis` não liga lote nenhum, mas cujo nome
 *      ANTERIOR na trilha de auditoria ainda é o de lotes existentes, volta a
 *      esse nome — e o nome novo, que era a intenção de quem editou, vira o
 *      apelido. Nada se apaga: a troca entra na trilha como "terminal".
 *   4. Grava a inscrição de todos os lotes que já podem ser montados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cadastro_bairros', function (Blueprint $t) {
            $t->string('apelido', 160)->nullable()->after('nome_gis');
        });

        Schema::table('lotes', function (Blueprint $t) {
            $t->char('inscricao_montada', 15)->nullable()->after('inscricao_imobiliaria');
            $t->timestamp('inscricao_montada_em')->nullable()->after('inscricao_montada');
            $t->index('inscricao_montada');
        });

        $gravadas = new InscricoesGravadas();
        $gravadas->repararAmarracoes();
        $gravadas->gravar();
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->dropIndex(['inscricao_montada']);
            $t->dropColumn(['inscricao_montada', 'inscricao_montada_em']);
        });
        Schema::table('cadastro_bairros', function (Blueprint $t) {
            $t->dropColumn('apelido');
        });
    }
};
