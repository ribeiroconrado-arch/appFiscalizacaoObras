<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O CADASTRO DA PREFEITURA, DO LADO DE CÁ.
 *
 * Esta tabela faz o papel do banco da prefeitura enquanto o acesso a ele não
 * existe: uma exportação do cadastro imobiliário é carregada aqui, e o sistema
 * consulta ESTA tabela exatamente como consultaria a de lá — mesma pergunta
 * (bairro, quadra, lote), mesma resposta, mesmo caminho de código.
 *
 * ── Por que uma tabela separada, e não gravar direto em bci_imoveis ──
 *
 * `bci_imoveis` é a cópia do cadastro DE UM IMÓVEL NOSSO — só existe linha
 * para lote que o GIS conhece. A exportação traz o município inteiro, inclusive
 * bairros que ainda não foram levantados. Misturar as duas faria a ficha
 * mostrar imóvel que o mapa não tem, e o dia em que o banco real entrar, não
 * haveria como separar o que veio de onde.
 *
 * Com a separação, trocar a planilha pelo banco da prefeitura é trocar uma
 * classe (a FonteDoCadastro) e nada mais.
 *
 * ── O que NÃO entra aqui ──
 *
 * Proprietário e documento (CPF/CNPJ) não têm coluna. A exportação traz o nome
 * do proprietário, mas por ora só se carrega dado cadastral do IMÓVEL — quem
 * é o dono é dado pessoal, e ele entra quando for necessário para uma peça do
 * processo, não antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cadastro_externo_imoveis', function (Blueprint $t) {
            $t->id();

            // A inscrição é a identidade do imóvel no cadastro deles. Única:
            // recarregar a mesma exportação atualiza, não duplica.
            $t->string('inscricao', 30)->unique();
            $t->string('codigo_cadastro', 30)->nullable();
            $t->string('inscricao_alternativa', 40)->nullable();

            // A chave de casamento com o GIS. `codigo_bairro` é o que amarra
            // ao bairro; `nome_bairro` fica por legibilidade e conferência.
            $t->string('codigo_bairro', 10)->nullable();
            $t->string('nome_bairro', 160)->nullable();
            $t->string('quadra', 10)->nullable();
            $t->string('lote', 12)->nullable();
            $t->index(['codigo_bairro', 'quadra', 'lote'], 'ix_cadastro_externo_chave');

            $t->string('logradouro', 180)->nullable();
            $t->string('numero_predial', 20)->nullable();
            $t->string('complemento', 120)->nullable();

            $t->string('isencao', 60)->nullable();
            $t->decimal('area_terreno_m2', 12, 2)->nullable();
            $t->decimal('area_edificada_m2', 12, 2)->nullable();
            $t->decimal('testada_m', 10, 2)->nullable();
            $t->decimal('medida_lado_direito', 10, 2)->nullable();
            $t->decimal('medida_lado_esquerdo', 10, 2)->nullable();
            $t->decimal('medida_fundo', 10, 2)->nullable();
            $t->decimal('fracao_ideal', 10, 4)->nullable();

            $t->string('setor', 160)->nullable();
            $t->string('regiao_fiscal', 80)->nullable();

            // A exportação traz uma construção por linha. Quando houver mais de
            // uma, virão como linhas irmãs da mesma inscrição — e aí este
            // trio vira a lista de unidades da ficha.
            $t->unsignedSmallInteger('unidade_ano')->nullable();
            $t->decimal('unidade_area_m2', 12, 2)->nullable();
            $t->unsignedSmallInteger('unidade_pontos')->nullable();
            $t->string('unidade_padrao', 40)->nullable();

            // Chave/valor, porque a lista muda de município para município —
            // o mesmo motivo de bci_caracteristicas.
            $t->json('caracteristicas')->nullable();

            $t->string('arquivo_origem', 200)->nullable();
            $t->timestamp('importado_em')->nullable();
            $t->timestamps();
        });

        // A ponte entre os dois mundos: o cadastro chama de "LOTEAMENTO
        // RESIDENCIAL BURITIS VI" o que o GIS chama de outra coisa. Sem esta
        // coluna, casar imóvel dependeria de os dois nomes serem iguais — e
        // eles nunca são.
        // A ponte entre os dois mundos, em tabela própria.
        //
        // O primeiro desenho usava a tabela `bairros`, e ela recusou: `geom` é
        // NOT NULL com índice espacial, e o MySQL não aceita coluna com índice
        // espacial sendo nula. Ou seja: gravar a ponte exigiria ter o POLÍGONO
        // do bairro desenhado antes de poder consultar o cadastro dele — uma
        // dependência inventada, entre duas coisas que nada têm a ver.
        //
        // `bairros` continua sendo o que era: o bairro como geometria. Esta
        // aqui é o de-para com o cadastro da prefeitura, e só.
        Schema::create('cadastro_bairros', function (Blueprint $t) {
            $t->id();
            // O nome do bairro como está em `lotes.bairro` — é por ele que o
            // sistema chega ao código do cadastro.
            $t->string('nome_gis', 160)->unique();
            $t->string('codigo', 10);
            $t->string('nome_cadastro', 160)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cadastro_externo_imoveis');
        Schema::dropIfExists('cadastro_bairros');
    }
};
