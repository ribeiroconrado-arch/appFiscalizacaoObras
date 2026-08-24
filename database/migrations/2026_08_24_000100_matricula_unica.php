<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matrícula única.
 *
 * A partir daqui a matrícula identifica o servidor no login, e identificador
 * repetido deixa de identificar: com duas pessoas na mesma matrícula, o
 * sistema teria de escolher uma das duas — e o auto assinado passaria a
 * apontar para alguém que pode não tê-lo lavrado. Num processo administrativo,
 * é o tipo de dúvida que anula a peça.
 *
 * Matrícula nula continua permitida, e de propósito: no MySQL cada NULL é
 * distinto para efeito de índice único. Assim, usuário sem matrícula (um
 * visualizador externo, um perfil de teste) entra pelo e-mail e não trava a
 * identidade dos demais.
 *
 * A migração NÃO falha se houver duplicidade herdada — falhar aqui travaria a
 * implantação por causa de dado antigo. Ela avisa, e o índice entra quando a
 * base estiver limpa.
 */
return new class extends Migration
{
    private const NOME = 'uk_users_matricula';

    public function up(): void
    {
        if ($this->jaExiste()) {
            return;
        }

        $duplicadas = DB::select("
            SELECT matricula, COUNT(*) n FROM users
             WHERE matricula IS NOT NULL AND matricula <> ''
             GROUP BY matricula HAVING n > 1");

        if ($duplicadas) {
            $lista = implode(', ', array_map(fn ($d) => "{$d->matricula} ({$d->n}x)", $duplicadas));
            echo PHP_EOL
                . "  AVISO: matrículas repetidas impedem o índice único: {$lista}" . PHP_EOL
                . '  Corrija em Parâmetros > Usuários e rode a migração de novo.' . PHP_EOL;

            return;
        }

        // Vazia vira nula: string vazia NÃO é distinta para o índice único, e
        // dois usuários sem matrícula gravados como '' colidiriam entre si.
        DB::table('users')->where('matricula', '')->update(['matricula' => null]);

        Schema::table('users', fn ($t) => $t->unique('matricula', self::NOME));
    }

    public function down(): void
    {
        if ($this->jaExiste()) {
            Schema::table('users', fn ($t) => $t->dropUnique(self::NOME));
        }
    }

    private function jaExiste(): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['users', self::NOME]
        );
    }
};
