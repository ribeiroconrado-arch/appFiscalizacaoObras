<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OS CADASTROS DO POSTURAS, trazidos do AppPOSTURAS (Supabase).
 *
 * As tabelas seguem as de lá, com os nomes deste sistema. Toda linha que vem
 * de lá guarda `origem_id` — o uuid do Supabase —, e é por ele que o comando
 * `posturas:importar` reconhece o que já trouxe: rodar de novo atualiza, não
 * duplica. Linha nascida aqui fica com `origem_id` nulo.
 *
 * - denuncias: o registro de perturbação do sossego, como o agente faz em campo.
 * - contribuintes: o autuado/notificado, pelo CPF ou CNPJ.
 * - decibelimetros: o equipamento de medição, com a calibração.
 * - logradouros + logradouro_bairros + bairros_endereco: a relação rua↔bairro
 *   do Posturas — escolhida a rua, o formulário sugere os bairros dela. É por
 *   NOME, como lá: o bairro do endereço é o que o cidadão diz, não o polígono do
 *   cadastro (cadastro_bairros).
 * - legislacoes/artigos: os campos do formulário da Lei 723/2002 (ruído) e das
 *   peças de apreensão e reincidência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('denuncias', function (Blueprint $t) {
            $t->id();
            $t->string('origem_id', 40)->nullable()->unique();
            // DN4827-3: duas letras, quatro dígitos e o dígito verificador.
            // Pode faltar nas antigas (o gerador falhou e ninguém refez).
            $t->string('protocolo', 16)->nullable()->unique();
            // Hora LOCAL de Primavera do Leste, sem fuso — como no Posturas.
            $t->dateTime('data_hora')->index();

            // O agente é ligado pelo usuário quando ele existe aqui; o nome e a
            // matrícula ficam copiados, porque é assim que a denúncia antiga o
            // identifica e porque usuário pode ser desligado.
            $t->foreignId('agente_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('agente_nome', 160)->nullable();
            $t->string('agente_matricula', 30)->nullable();

            $t->string('logradouro', 180)->nullable();
            $t->string('numero', 20)->nullable();
            $t->string('bairro', 160)->nullable();
            $t->string('local_tipo', 20)->default('Local Exato');
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->decimal('gps_precisao', 8, 2)->nullable();
            // Achado pelo ponto do GPS dentro do polígono (Fase 3); opcional.
            $t->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();

            $t->string('fonte_sonora', 20)->nullable();   // Residência, Empresa, Veículo
            $t->string('resp_nome', 160)->nullable();
            $t->string('resp_cpf', 20)->nullable();
            $t->string('empresa_nome', 160)->nullable();
            $t->string('empresa_cnpj', 20)->nullable();
            $t->string('placa', 12)->nullable();
            $t->string('veic_marca', 60)->nullable();
            $t->string('veic_modelo', 60)->nullable();
            $t->string('veic_cor', 40)->nullable();

            // Vazio = Pendente; "Cancelado" é o cancelamento (não há outro estado).
            $t->string('tratativa', 60)->nullable()->index();
            $t->text('obs')->nullable();
            $t->string('cancelado_por_nome', 160)->nullable();
            $t->string('cancelado_matricula', 30)->nullable();
            $t->timestamp('cancelado_em')->nullable();
            // [{ts, agente, mat, acao, mudancas: [...]}] — o histórico de lá.
            $t->json('logs')->nullable();
            $t->timestamps();

            // A reincidência procura pelo endereço.
            $t->index(['logradouro', 'numero']);
        });

        Schema::create('contribuintes', function (Blueprint $t) {
            $t->id();
            $t->string('origem_id', 40)->nullable()->unique();
            $t->string('cpf_cnpj', 20)->unique();   // com a máscara, como digitado
            $t->string('nome', 160)->nullable();
            $t->string('inscricao_municipal', 40)->nullable();
            $t->string('email', 160)->nullable();
            $t->string('logradouro', 180)->nullable();
            $t->string('numero', 20)->nullable();
            $t->string('bairro', 160)->nullable();
            $t->string('cidade', 120)->nullable();
            $t->string('estado', 2)->nullable();
            $t->timestamps();
        });

        Schema::create('decibelimetros', function (Blueprint $t) {
            $t->id();
            $t->string('origem_id', 40)->nullable()->unique();
            $t->string('tipo', 80)->default('Decibelímetro Digital');
            $t->string('fabricante', 80)->nullable();
            $t->string('modelo', 80)->nullable();
            $t->string('identificacao', 80)->nullable();
            $t->string('serie', 80)->nullable();
            $t->string('certificado_calibracao', 120)->nullable();
            $t->string('empresa_calibradora', 160)->nullable();
            $t->date('data_calibracao')->nullable();
            $t->unsignedSmallInteger('validade_calibracao_meses')->nullable();
            // O PDF do certificado continua no Storage do Supabase até ser
            // trazido; o endereço fica aqui para não se perder.
            $t->string('certificado_pdf_url', 500)->nullable();
            $t->string('certificado_pdf_nome', 160)->nullable();
            $t->boolean('ativo')->default(true);
            $t->timestamps();
        });

        Schema::create('bairros_endereco', function (Blueprint $t) {
            $t->id();
            $t->string('nome', 160)->unique();   // em maiúsculas, como no Posturas
            $t->string('codigo', 10)->nullable(); // o código do bairro na prefeitura
            $t->timestamps();
        });

        Schema::create('logradouros', function (Blueprint $t) {
            $t->id();
            $t->string('nome', 180)->unique();   // em maiúsculas
            $t->timestamps();
        });

        Schema::create('logradouro_bairros', function (Blueprint $t) {
            $t->id();
            $t->foreignId('logradouro_id')->constrained('logradouros')->cascadeOnDelete();
            $t->string('bairro', 160);
            $t->timestamps();
            $t->unique(['logradouro_id', 'bairro']);
            $t->index('bairro');
        });

        Schema::table('legislacoes', function (Blueprint $t) {
            $t->string('origem_id', 40)->nullable()->unique()->after('modulo');
            // 'padrao' ou 'lei_723_2002' — esta abre o bloco de medição de ruído.
            $t->string('formulario_tipo', 30)->default('padrao')->after('ativa');
            // [{ate_db, upf, nivel}] — a multa pela diferença acima do limite.
            $t->json('faixa_db')->nullable()->after('formulario_tipo');
            // [{area, diurno, noturno}] — o limite em dB de cada zona.
            $t->json('areas_db')->nullable()->after('faixa_db');
            // [{numero, texto}] — os casos de lavratura imediata da Lei 723.
            $t->json('lavratura_imediata_itens')->nullable()->after('areas_db');
            $t->string('artigo_multa', 120)->nullable()->after('lavratura_imediata_itens');
            $t->boolean('aplica_apreensao')->default(false)->after('artigo_multa');
            $t->boolean('aplica_retencao')->default(false)->after('aplica_apreensao');
            $t->string('artigo_apreensao', 120)->nullable()->after('aplica_retencao');
            $t->string('artigo_retencao', 120)->nullable()->after('artigo_apreensao');
            $t->text('ciencia_apreensao')->nullable()->after('artigo_retencao');
            $t->text('ciencia_retencao')->nullable()->after('ciencia_apreensao');
            $t->boolean('aplica_reincidencia')->default(false)->after('ciencia_retencao');
            $t->string('artigo_reincidencia', 120)->nullable()->after('aplica_reincidencia');
            $t->text('texto_reincidencia')->nullable()->after('artigo_reincidencia');
        });

        Schema::table('artigos', function (Blueprint $t) {
            // O id do artigo dentro do JSON da lei, no Posturas ("art-500-16").
            $t->string('origem_id', 60)->nullable()->after('legislacao_id');
            $t->index(['legislacao_id', 'origem_id']);
        });

        // A multa pela diferença de decibéis (Artigo::BASES_MULTA). ALTER cru,
        // como em 2026_10_16_000100: o Schema Builder recriaria o ENUM.
        if (DB::getDriverName() === 'mysql') {
            foreach (['artigos', 'documento_artigos'] as $tabela) {
                DB::statement("ALTER TABLE {$tabela} MODIFY base_multa ENUM(" . self::BASES . ",'diferenca_db') NOT NULL DEFAULT 'fixa'");
            }
        }
    }

    private const BASES = "'sem_multa','fixa','por_m2','faixas','multiplo_alvara','intervalo'";

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['artigos', 'documento_artigos'] as $tabela) {
                DB::table($tabela)->where('base_multa', 'diferenca_db')->update(['base_multa' => 'sem_multa']);
                DB::statement("ALTER TABLE {$tabela} MODIFY base_multa ENUM(" . self::BASES . ") NOT NULL DEFAULT 'fixa'");
            }
        }
        Schema::table('artigos', function (Blueprint $t) {
            $t->dropIndex(['legislacao_id', 'origem_id']);
            $t->dropColumn('origem_id');
        });
        Schema::table('legislacoes', function (Blueprint $t) {
            $t->dropUnique(['origem_id']);
            $t->dropColumn([
                'origem_id', 'formulario_tipo', 'faixa_db', 'areas_db', 'lavratura_imediata_itens',
                'artigo_multa', 'aplica_apreensao', 'aplica_retencao', 'artigo_apreensao',
                'artigo_retencao', 'ciencia_apreensao', 'ciencia_retencao', 'aplica_reincidencia',
                'artigo_reincidencia', 'texto_reincidencia',
            ]);
        });
        Schema::dropIfExists('logradouro_bairros');
        Schema::dropIfExists('logradouros');
        Schema::dropIfExists('bairros_endereco');
        Schema::dropIfExists('decibelimetros');
        Schema::dropIfExists('contribuintes');
        Schema::dropIfExists('denuncias');
    }
};
