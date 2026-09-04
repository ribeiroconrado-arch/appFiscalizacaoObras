<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O documento passa a dizer DE QUANDO é o dado cadastral que ele usou.
 *
 * `documentos` já guarda cópia própria do autuado (`autuado_nome`,
 * `autuado_documento`, `endereco`), então reintegrar o cadastro nunca alterou
 * peça lavrada. O que faltava era a data: o documento guardava os valores e não
 * dizia de que dia eles eram. Daqui a dois anos, ninguém saberia se o nome no
 * auto era o vigente na lavratura ou um dado que já estava velho.
 *
 * ── Por que CÓPIA e não uma chave para bci_imoveis ──
 *
 * Apontar para a linha do BCI seria menos colunas e estaria errado: aquela
 * linha é SUBSTITUÍDA INTEIRA a cada integração (ver SincronizaBci). O
 * documento passaria a citar o retrato de hoje, e não o que ele usou — que é
 * exatamente o problema que este carimbo existe para resolver.
 *
 * ── Por que a fonte também vai para bci_imoveis ──
 *
 * A fonte é do RETRATO, não do momento da lavratura. Se um dia a exportação der
 * lugar ao acesso ao banco da prefeitura, um documento lavrado depois da troca
 * mas com integração feita antes tem de continuar dizendo "exportação". Lida do
 * serviço no instante da lavratura, ela diria a fonte de hoje sobre um dado de
 * ontem.
 *
 * ── Nulo é informação ──
 *
 * Documento antigo, e documento lavrado sobre imóvel nunca integrado, ficam com
 * nulo. Nulo aqui quer dizer "lavrado sem consulta ao cadastro", que é um fato
 * do processo — e o PDF diz isso com todas as letras, em vez de omitir a linha
 * e esconder justamente o caso em que alguém deveria olhar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->timestamp('cadastro_consultado_em')->nullable()->after('endereco');
            $t->string('cadastro_fonte', 40)->nullable()->after('cadastro_consultado_em');
        });

        // A fonte do retrato, gravada onde o retrato mora. As linhas que já
        // existem vieram todas da exportação — é a única fonte que houve até
        // hoje —, então preencher é dizer a verdade, e deixar nulo seria
        // perder um dado que se sabe.
        Schema::table('bci_imoveis', function (Blueprint $t) {
            $t->string('fonte', 40)->nullable()->after('consultado_por');
        });

        DB::table('bci_imoveis')->whereNull('fonte')->update(['fonte' => 'exportacao']);
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            $t->dropColumn(['cadastro_consultado_em', 'cadastro_fonte']);
        });
        Schema::table('bci_imoveis', function (Blueprint $t) {
            $t->dropColumn('fonte');
        });
    }
};
