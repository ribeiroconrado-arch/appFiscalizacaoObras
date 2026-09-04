<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Baixado" passa a se chamar "inativo" — a palavra do cadastro da prefeitura.
 *
 * O sistema tributário chama de INATIVO o imóvel que saiu do cadastro. O nosso
 * chamava de "baixado" a mesma condição, herdada do vocabulário de registro. O
 * fiscal lê as duas telas no mesmo dia, e duas palavras para o mesmo estado é
 * uma tradução que alguém tem de fazer de cabeça toda vez.
 *
 * ── A colisão que isto cria, dita de propósito ──
 *
 * `bci_imoveis.isencao` também tem o valor "Inativo", vindo do BCI. São coisas
 * DIFERENTES com a mesma palavra: aqui é o lote que deixou de existir por
 * unificação ou desmembramento; lá é a situação fiscal do imóvel, que pode
 * estar inativa por isenção, imunidade ou cancelamento, sem ato geométrico
 * nenhum. Um lote unificado hoje pode continuar ativo no cadastro tributário
 * até alguém dar baixa lá.
 *
 * A migração original de `bci_imoveis` evitou justamente esta colisão, e o
 * comentário dela continua valendo — a diferença é que agora a escolha é
 * consciente e está escrita aqui.
 *
 * ── Por que três comandos e não um ──
 *
 * ENUM não aceita UPDATE para um valor que ainda não está na lista. Abre-se o
 * enum com os dois, converte-se o dado, fecha-se o enum. Fazer em um passo só
 * transformaria as linhas existentes em string vazia, em silêncio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lotes', 'situacao')) {
            return;
        }

        DB::statement("ALTER TABLE lotes MODIFY situacao
                       ENUM('ativo','baixado','inativo') NOT NULL DEFAULT 'ativo'");

        $convertidos = DB::table('lotes')->where('situacao', 'baixado')
            ->update(['situacao' => 'inativo']);

        DB::statement("ALTER TABLE lotes MODIFY situacao
                       ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo'");

        // A data acompanha a palavra. Deixar `baixado_em` ao lado de
        // `situacao = 'inativo'` seria renomear pela metade — e meia
        // renomeação custa mais do que nenhuma, porque quem lê passa a ter de
        // saber que as duas colunas falam da mesma coisa.
        if (Schema::hasColumn('lotes', 'baixado_em') && ! Schema::hasColumn('lotes', 'inativado_em')) {
            DB::statement('ALTER TABLE lotes RENAME COLUMN baixado_em TO inativado_em');
        }

        if ($convertidos) {
            echo "  {$convertidos} lote(s) de 'baixado' para 'inativo'." . PHP_EOL;
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('lotes', 'situacao')) {
            return;
        }

        if (Schema::hasColumn('lotes', 'inativado_em') && ! Schema::hasColumn('lotes', 'baixado_em')) {
            DB::statement('ALTER TABLE lotes RENAME COLUMN inativado_em TO baixado_em');
        }

        DB::statement("ALTER TABLE lotes MODIFY situacao
                       ENUM('ativo','baixado','inativo') NOT NULL DEFAULT 'ativo'");

        DB::table('lotes')->where('situacao', 'inativo')->update(['situacao' => 'baixado']);

        DB::statement("ALTER TABLE lotes MODIFY situacao
                       ENUM('ativo','baixado') NOT NULL DEFAULT 'ativo'");
    }
};
