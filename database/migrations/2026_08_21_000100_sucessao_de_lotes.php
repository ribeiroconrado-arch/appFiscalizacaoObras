<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sucessão de imóveis: desmembramento e unificação.
 *
 * O imóvel deixa de ser um registro que só existe ou não existe, e passa a ter
 * história: o lote 05 unificado com o 06 não some — fica BAIXADO, apontando
 * para o lote que o sucedeu. Excluir seria destrutivo de um jeito silencioso:
 * `vistorias` e `obras` têm FK em CASCADE e iriam junto sem aviso, e o auto de
 * infração já lavrado (`documentos`, RESTRICT) trancaria a operação pela
 * metade. Além disso o auto se refere àquele imóvel, e a peça de processo
 * precisa continuar apontando para o que existia quando ela foi lavrada.
 */
return new class extends Migration
{
    /** Nome do índice único de identificação, antes e depois. */
    private const INDICE_ANTIGO = 'uk_lotes_identificacao';
    private const INDICE_NOVO   = 'uk_lotes_identificacao_ativos';

    public function up(): void
    {
        $this->colunasDoLote();
        $this->identidadeSoDosAtivos();
        $this->tabelasDeAto();
        $this->tipoUnificacaoNoProtocolo();
    }

    /** Estado e procedência do lote. */
    private function colunasDoLote(): void
    {
        if (Schema::hasColumn('lotes', 'situacao')) {
            return;
        }

        Schema::table('lotes', function (Blueprint $t) {
            $t->enum('situacao', ['ativo', 'baixado'])->default('ativo')->after('fonte');
            $t->timestamp('baixado_em')->nullable()->after('situacao');

            // Último grupo da inscrição imobiliária (01.BBB.QQQ.LLLL.DDD).
            // Numérico, e não string "001", porque `montarInscricao()` no
            // front já formata com zeros à esquerda, e porque assim dá para
            // sugerir o próximo sufixo com MAX(desmembramento)+1.
            $t->unsignedSmallInteger('desmembramento')->default(0)->after('numero_lote');

            // NÃO é redundante com `fonte`. `fonte` é texto livre vindo do DWG
            // e serve de procedência documental; `origem` é TRAVA DE
            // COMPORTAMENTO — é ela que o importador consulta para não
            // sobrescrever a geometria de um lote desenhado ou desmembrado à
            // mão. Trava que depende de comparar string livre não é trava.
            $t->enum('origem', ['importacao', 'desenho', 'desmembramento', 'unificacao'])
                ->default('importacao')->after('fonte');

            $t->index('situacao', 'idx_lotes_situacao');
        });
    }

    /**
     * O índice único passa a valer só entre lotes ATIVOS.
     *
     * O problema: unificar os lotes 05 e 06 e chamar o resultado de "05" — que
     * é a prática — colidiria com o 05 que acabou de ser baixado. E não são
     * casos de borda: é o caminho normal da unificação.
     *
     * A saída é uma coluna gerada que só tem valor quando o lote está ativo.
     * No índice único do MySQL cada NULL é distinto, então lote baixado nunca
     * disputa identidade com ninguém, quantos baixados houver. Testado contra
     * o MySQL 8.0.46: dois ativos idênticos são recusados; ativo + baixado
     * idênticos passam; dois baixados idênticos passam.
     *
     * A cláusula sobre quadra e número reproduz de propósito o que a migration
     * 2026_08_20_000200 já fazia: lote sem quadra entra na base sem travar a
     * identidade dos demais (hoje são 160 no Residencial Buritis V).
     */
    private function identidadeSoDosAtivos(): void
    {
        if ($this->temIndice(self::INDICE_NOVO)) {
            return;
        }

        $duplicadas = DB::selectOne('SELECT COUNT(*) n FROM (
            SELECT 1 FROM lotes
             WHERE situacao = "ativo" AND quadra IS NOT NULL AND numero_lote IS NOT NULL
             GROUP BY bairro, quadra, numero_lote
            HAVING COUNT(*) > 1
        ) t')->n;

        if ($duplicadas > 0) {
            // Falhar aqui travaria a implantação inteira por causa de dado
            // sujo herdado. Avisa em alto e bom som e segue — o índice entra
            // quando a base estiver limpa, e o importador o cria sozinho.
            echo PHP_EOL;
            echo "  ┌─ IDENTIDADE ÚNICA NÃO MIGRADA ────────────────────────────" . PHP_EOL;
            echo "  │ {$duplicadas} combinações bairro|quadra|lote repetidas entre lotes ATIVOS." . PHP_EOL;
            echo "  │ Diagnóstico: php artisan gis:conferir" . PHP_EOL;
            echo "  └───────────────────────────────────────────────────────────" . PHP_EOL;

            return;
        }

        if (! Schema::hasColumn('lotes', 'chave_identidade')) {
            DB::statement("
                ALTER TABLE lotes
                  ADD COLUMN chave_identidade VARCHAR(200)
                    GENERATED ALWAYS AS (
                      CASE WHEN situacao = 'ativo'
                            AND quadra IS NOT NULL AND numero_lote IS NOT NULL
                           THEN CONCAT(bairro, '|', quadra, '|', numero_lote)
                      END
                    ) STORED
            ");
        }

        DB::statement('ALTER TABLE lotes ADD UNIQUE KEY ' . self::INDICE_NOVO . ' (chave_identidade)');

        // Só depois de o novo existir: derrubar o antigo antes deixaria uma
        // janela sem nenhuma proteção de identidade.
        if ($this->temIndice(self::INDICE_ANTIGO)) {
            DB::statement('ALTER TABLE lotes DROP INDEX ' . self::INDICE_ANTIGO);
        }
    }

    /**
     * O ato cadastral e quem participou dele.
     *
     * Duas tabelas, e não colunas no próprio lote, porque a relação é N:N nas
     * duas direções: no desmembramento um lote vira vários; na unificação
     * vários viram um. Uma coluna `lote_origem_id` comportaria o primeiro caso
     * e não o segundo; `lote_sucessor_id`, o contrário. Duas colunas fechariam
     * a aritmética e destruiriam a semântica — ficaria impossível responder
     * "estes três lotes viraram este um NO MESMO ATO", que é exatamente o que
     * o processo administrativo é.
     */
    private function tabelasDeAto(): void
    {
        if (Schema::hasTable('lote_atos')) {
            return;
        }

        Schema::create('lote_atos', function (Blueprint $t) {
            $t->id();
            $t->enum('tipo', ['desmembramento', 'unificacao']);

            // O ato nasce de um protocolo deferido. Nulo só para correção
            // administrativa sem requerimento, que hoje não existe mas que a
            // coluna não deve impedir de existir amanhã.
            $t->foreignId('protocolo_id')->nullable()->constrained('protocolos')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // 'poligonos' ou 'corte': como o operador definiu as partes. Não
            // muda nada no resultado — os dois caminhos entregam a mesma lista
            // de polígonos —, mas quem for investigar uma divisa torta daqui a
            // um ano vai querer saber por onde ela entrou.
            $t->string('modo', 20)->nullable();
            $t->text('observacao')->nullable();
            $t->timestamps();

            // A trava de idempotência REAL contra duplo clique e contra dois
            // administradores executando o mesmo protocolo. Uma consulta
            // prévia teria corrida entre o SELECT e o INSERT; o índice não.
            $t->unique('protocolo_id', 'uk_lote_atos_protocolo');
        });

        Schema::create('lote_ato_lotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ato_id')->constrained('lote_atos')->cascadeOnDelete();

            // RESTRICT: um lote que participou de sucessão não pode ser
            // apagado por baixo do ato, nem pelo --substituir do importador.
            $t->foreignId('lote_id')->constrained('lotes')->restrictOnDelete();

            $t->enum('papel', ['anterior', 'posterior']);

            // Área CONGELADA no momento do ato. Permite conferir anos depois
            // que a soma bateu, mesmo que alguém retifique a geometria de um
            // sucessor no meio do caminho.
            $t->decimal('area_m2', 12, 2)->nullable();

            $t->unique(['ato_id', 'lote_id'], 'uk_ato_lote');
            $t->index(['lote_id', 'papel'], 'idx_ato_lote_papel');
        });
    }

    /**
     * Separa unificação de desmembramento no tipo do protocolo.
     *
     * O rótulo atual ("Desmembramento / remembramento") cobre os dois, mas o
     * ATO executado é diferente — N→1 contra 1→N. Sem o tipo, o botão de
     * executar teria de perguntar ao operador qual dos dois fazer, que é
     * exatamente a ambiguidade que produz o ato errado.
     */
    private function tipoUnificacaoNoProtocolo(): void
    {
        $tipo = DB::selectOne("SHOW COLUMNS FROM protocolos LIKE 'tipo'")->Type;
        if (str_contains($tipo, "'unificacao'")) {
            return;
        }

        // `tipo` é ENUM de verdade, então o MODIFY substitui a definição
        // inteira: a lista vai completa e o NOT NULL vai junto. Omitir o NOT
        // NULL afrouxaria a coluna em silêncio.
        DB::statement("ALTER TABLE protocolos MODIFY tipo ENUM(
            'habite_se','vistoria_calcada','contestacao_area',
            'renovacao_alvara','desmembramento','unificacao','outro'
        ) NOT NULL");

        // Sem backfill de propósito: protocolos antigos ficam como
        // 'desmembramento'. Reclassificar por palpite é pior do que deixar o
        // que a pessoa escolheu na época.
    }

    public function down(): void
    {
        // Protocolo já classificado como unificação impede o rollback do ENUM:
        // reverter truncaria o valor para string vazia, em silêncio.
        $emUso = DB::table('protocolos')->where('tipo', 'unificacao')->count();
        if ($emUso > 0) {
            throw new RuntimeException(
                "Rollback recusado: {$emUso} protocolo(s) usam o tipo 'unificacao'. "
                . 'Reclassifique-os antes, senão o tipo deles vira vazio.'
            );
        }

        $atos = Schema::hasTable('lote_atos') ? DB::table('lote_atos')->count() : 0;
        if ($atos > 0) {
            throw new RuntimeException(
                "Rollback recusado: há {$atos} ato(s) de sucessão gravados. "
                . 'Desfazê-los apagaria a cadeia de sucessão dos imóveis.'
            );
        }

        Schema::dropIfExists('lote_ato_lotes');
        Schema::dropIfExists('lote_atos');

        DB::statement("ALTER TABLE protocolos MODIFY tipo ENUM(
            'habite_se','vistoria_calcada','contestacao_area',
            'renovacao_alvara','desmembramento','outro'
        ) NOT NULL");

        if ($this->temIndice(self::INDICE_NOVO)) {
            DB::statement('ALTER TABLE lotes DROP INDEX ' . self::INDICE_NOVO);
        }
        if (Schema::hasColumn('lotes', 'chave_identidade')) {
            DB::statement('ALTER TABLE lotes DROP COLUMN chave_identidade');
        }
        if (! $this->temIndice(self::INDICE_ANTIGO)) {
            DB::statement('ALTER TABLE lotes ADD UNIQUE KEY ' . self::INDICE_ANTIGO
                . ' (bairro, quadra, numero_lote)');
        }

        Schema::table('lotes', function (Blueprint $t) {
            $t->dropIndex('idx_lotes_situacao');
            $t->dropColumn(['situacao', 'baixado_em', 'desmembramento', 'origem']);
        });
    }

    /**
     * O `?->` não é zelo excessivo: em `migrate --pretend` o Laravel não
     * executa consulta nenhuma e `selectOne` devolve null, o que derrubava a
     * simulação com "Attempt to read property on null" antes de mostrar
     * qualquer coisa útil.
     */
    private function temIndice(string $nome): bool
    {
        return (bool) (DB::selectOne(
            'SELECT COUNT(*) n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = "lotes" AND index_name = ?',
            [$nome]
        )?->n ?? 0);
    }
};
