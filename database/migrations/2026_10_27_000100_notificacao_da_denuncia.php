<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A NOTIFICAÇÃO QUE NASCE DE UMA DENÚNCIA (Posturas).
 *
 * É o "Gerar notificação" do AppPOSTURAS: o agente atende a denúncia de
 * perturbação e, ali mesmo, abre a notificação com o endereço, o responsável
 * e a hora já preenchidos. A peça guarda de qual denúncia veio — é a quarta
 * origem possível de uma notificação, ao lado de direta, ordem de serviço e
 * ouvidoria —, e sai impressa como "DENÚNCIA Nº DN4827-3".
 *
 * Diferente da ouvidoria, que guarda só o número digitado, aqui a ligação é
 * pela chave: a denúncia está no sistema, e a ficha dela lista as peças que
 * geraram.
 */
return new class extends Migration
{
    private const MOTIVOS_ANTES = "'direta','ordem_servico','ouvidoria'";

    private const MOTIVOS_DEPOIS = "'direta','ordem_servico','ouvidoria','denuncia'";

    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->foreignId('denuncia_id')->nullable()->after('origem_referencia')->constrained('denuncias')->nullOnDelete();
        });

        // ENUM se muda com ALTER cru (o mesmo de 2026_08_20_000100). Nos testes,
        // em sqlite, a coluna é texto e aceita o valor novo sem mudar nada.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE documentos MODIFY origem_motivo ENUM(' . self::MOTIVOS_DEPOIS . ') NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE documentos SET origem_motivo = 'direta' WHERE origem_motivo = 'denuncia'");
            DB::statement('ALTER TABLE documentos MODIFY origem_motivo ENUM(' . self::MOTIVOS_ANTES . ') NULL');
        }

        Schema::table('documentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('denuncia_id');
        });
    }
};
