<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UM SISTEMA, DOIS MÓDULOS — Obras e Posturas.
 *
 * O Posturas era outro aplicativo (AppPOSTURAS, Supabase). Ele passa a morar
 * aqui, ao lado de Obras, e as duas fiscalizações dividem mapa, formulário de
 * documento, impressão, legislação e usuários. O que separa uma da outra é a
 * coluna `modulo` nas tabelas que ficam lado a lado, e `users.modulos`, que
 * diz a quem cada uma está aberta. Ver config/modulos.php e App\Support\Modulo.
 *
 * Tudo o que já existe é de Obras, e por isso o padrão das colunas é 'obras':
 * nenhum registro muda de comportamento com esta migration.
 */
return new class extends Migration
{
    private const TIPOS_ANTES  = "'vistoria','notificacao','notificacao_embargo','auto_infracao','auto_embargo','termo_advertencia'";
    private const TIPOS_DEPOIS = "'vistoria','notificacao','notificacao_embargo','auto_infracao','auto_embargo','termo_advertencia','auto_apreensao'";

    public function up(): void
    {
        // Quem usa o quê. NULO vale ["obras"] (User::modulos), mas o valor vai
        // gravado para a tela de usuários mostrar o que está valendo.
        Schema::table('users', function (Blueprint $t) {
            $t->json('modulos')->nullable()->after('tipo_usuario');
        });
        DB::table('users')->update(['modulos' => json_encode(['obras'])]);

        foreach (['documentos', 'legislacoes', 'ordens_servico'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->string('modulo', 20)->default('obras')->after('id')->index();
            });
        }

        // A numeração passa a ser POR MÓDULO: a Notificação 0001/2026 de
        // Posturas não pode tomar o número da de Obras, nem o contrário.
        Schema::table('documento_contadores', function (Blueprint $t) {
            $t->string('modulo', 20)->default('obras')->after('id');
        });
        Schema::table('documento_contadores', function (Blueprint $t) {
            $t->dropUnique(['tipo', 'exercicio']);
            $t->unique(['modulo', 'tipo', 'exercicio']);
        });

        // O Auto de Apreensão é peça de Posturas. ALTER cru pelo mesmo motivo
        // de 2026_08_20_000100: o Schema Builder recriaria o ENUM e perderia dados.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE documentos MODIFY tipo ENUM(' . self::TIPOS_DEPOIS . ') NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('documentos')->where('tipo', 'auto_apreensao')->delete();
            DB::statement('ALTER TABLE documentos MODIFY tipo ENUM(' . self::TIPOS_ANTES . ') NOT NULL');
        }

        // Voltar a uma série só exige que não haja duas linhas do mesmo tipo e
        // ano — as de outro módulo saem antes.
        DB::table('documento_contadores')->where('modulo', '<>', 'obras')->delete();
        Schema::table('documento_contadores', function (Blueprint $t) {
            $t->dropUnique(['modulo', 'tipo', 'exercicio']);
            $t->unique(['tipo', 'exercicio']);
        });
        Schema::table('documento_contadores', function (Blueprint $t) {
            $t->dropColumn('modulo');
        });

        foreach (['documentos', 'legislacoes', 'ordens_servico'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->dropIndex(['modulo']);
                $t->dropColumn('modulo');
            });
        }

        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('modulos');
        });
    }
};
