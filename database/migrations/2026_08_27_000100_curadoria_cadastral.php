<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CURADORIA CADASTRAL — o privilégio de corrigir a base do mapa.
 *
 * ── Por que não bastava ser administrador ──
 *
 * Administrador cuida do SISTEMA: cria usuário, cadastra legislação, ajusta a
 * UPF. Corrigir a base cadastral é outra natureza de poder — muda a quadra de
 * um lote, desenha um imóvel que não existia, altera a geometria que fundamenta
 * o cálculo de área e, por consequência, o valor de uma multa. Quem administra
 * o sistema não é necessariamente quem responde tecnicamente pelo cadastro.
 *
 * Por isso é uma PERMISSÃO à parte, e não um quarto perfil: perfil é o degrau
 * de acesso (visualizar, operar, administrar) e cresce em linha reta. Este é um
 * poder transversal — pode existir num coordenador de cadastro que não
 * administra nada, e faltar num administrador de TI que administra tudo.
 *
 * Ela NÃO substitui a regra da vistoria. Unificação e desmembramento continuam
 * exigindo vistoria de protocolo — lá o portão é o ato administrativo, e nenhum
 * privilégio de usuário passa por cima dele (ver CadastroLoteController). Esta
 * permissão vale para a correção direta no mapa: quadra em massa e desenho de
 * lote faltante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('curador_cadastral')->default(false)->after('perfil');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('curador_cadastral'));
    }
};
