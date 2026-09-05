<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O desenho do lote apagado, guardado antes de a linha sumir.
 *
 * Apagar lote era a única operação do cadastro SEM VOLTA, e não por decisão:
 * `Lote` tem um escopo global `sem_geometria` — um `SELECT *` traria o polígono
 * em toda consulta de mapa —, então `geom` nunca chega ao registro de auditoria.
 * A auditoria da exclusão guarda id, chave, bairro, quadra, número e área. Tudo,
 * menos o desenho. E sem o desenho não há o que restaurar.
 *
 * Em produção isso já aconteceu quatro vezes (31/08). Eram resíduo real da
 * conversão do DWG — chave "…|?|?", sem quadra e sem número —, mas a ferramenta
 * aceita seleção múltipla, e o dia em que o clique for no lote errado não terá
 * volta.
 *
 * ── Por que tabela própria, e não `geom` na auditoria ──
 *
 * A auditoria guarda alteração campo a campo: um polígono em cada uma das 179
 * correções de quadra inflaria a tabela sem que ninguém fosse ler. Aqui o volume
 * é outro — 4 linhas em três semanas. E tabela própria admite ÍNDICE ESPACIAL,
 * que a auditoria não tem: sem ele não há como responder "o que existia neste
 * ponto do mapa?", que é a pergunta de quem procura um lote apagado sem lembrar
 * o número.
 *
 * ── Por que não é `softDeletes` ──
 *
 * `deleted_at` em `lotes` obrigaria toda consulta de mapa, busca, GPS e
 * relatório a lembrar do filtro. O escopo global resolveria — e passaria a
 * conviver com o `sem_geometria` e o de situação, três escopos decidindo quem
 * aparece. Aqui a linha SAI de `lotes`: nada no sistema precisa saber que ela
 * existiu, exceto a tela que restaura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_apagados', function (Blueprint $t) {
            $t->id();

            // O id que ele TINHA. Não é chave estrangeira: a linha original já
            // não existe. Serve para casar com a linha da auditoria, que grava
            // esse mesmo número em `registro_id`.
            $t->unsignedBigInteger('lote_id')->index();

            $t->string('bairro', 120);
            $t->string('quadra', 20)->nullable();
            $t->string('numero_lote', 20)->nullable();
            $t->unsignedSmallInteger('desmembramento')->default(0);
            $t->string('chave', 180)->nullable();
            $t->string('inscricao_imobiliaria', 50)->nullable();
            $t->decimal('area_gis_m2', 12, 2)->nullable();
            $t->decimal('frente_m', 10, 2)->nullable();
            $t->decimal('fundos_m', 10, 2)->nullable();
            $t->decimal('lado_direito_m', 10, 2)->nullable();
            $t->decimal('lado_esquerdo_m', 10, 2)->nullable();
            $t->decimal('area_matricula_m2', 12, 2)->nullable();
            $t->string('fonte', 180)->nullable();
            $t->string('origem', 20)->nullable();

            // O desenho. É por ele que esta tabela existe.
            $t->geometry('geom', subtype: 'polygon', srid: 4326);

            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('usuario_nome', 120)->nullable();

            // O motivo JÁ é pedido na tela de exclusão — e hoje só vai para a
            // mensagem de retorno, que ninguém guarda. Aqui ele fica.
            $t->text('motivo')->nullable();

            $t->timestamp('apagado_em');

            // Restaurar não apaga esta linha: marca-a. Assim a trilha mostra o
            // ciclo inteiro — apagou, restaurou, e sob qual id novo — em vez de
            // fingir que o apagamento nunca aconteceu.
            $t->timestamp('restaurado_em')->nullable();
            $t->unsignedBigInteger('restaurado_como')->nullable();

            $t->timestamps();
            $t->spatialIndex('geom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes_apagados');
    }
};
