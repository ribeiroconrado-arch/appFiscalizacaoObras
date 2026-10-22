<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COLUNAS COM FOLGA PARA O QUE VEM DO APPPOSTURAS.
 *
 * Lá toda coluna de texto é `text`, sem limite, e o agente escrevia o que
 * precisava no campo que tinha: "S/N, AO LADO DO POSTO" no número, o nome
 * inteiro da empresa, a cor e o modelo por extenso. Os limites de
 * 2026_10_26 eram os de um formulário novo, e a primeira importação real
 * parou no número da denúncia ("Data too long for column 'numero'").
 *
 * Aqui as colunas das tabelas importadas ganham folga; o importador ainda
 * corta, com aviso, o que passar dela (ImportadorPosturas::caber).
 * Só alarga — nenhum dado muda.
 */
return new class extends Migration
{
    /** @var array<string, array<string, int>> tabela → coluna → tamanho novo */
    private const LARGURAS = [
        'denuncias' => [
            'protocolo' => 30, 'agente_nome' => 255, 'agente_matricula' => 60,
            'logradouro' => 255, 'numero' => 120, 'bairro' => 255, 'local_tipo' => 60,
            'fonte_sonora' => 60, 'resp_nome' => 255, 'resp_cpf' => 40, 'empresa_nome' => 255,
            'empresa_cnpj' => 40, 'placa' => 20, 'veic_marca' => 120, 'veic_modelo' => 120,
            'veic_cor' => 60, 'tratativa' => 120, 'cancelado_por_nome' => 255, 'cancelado_matricula' => 60,
        ],
        'contribuintes' => [
            'cpf_cnpj' => 40, 'nome' => 255, 'inscricao_municipal' => 60, 'email' => 255,
            'logradouro' => 255, 'numero' => 120, 'bairro' => 255, 'cidade' => 160,
        ],
        'decibelimetros' => [
            'tipo' => 120, 'fabricante' => 120, 'modelo' => 120, 'identificacao' => 120, 'serie' => 120,
            'certificado_calibracao' => 255, 'empresa_calibradora' => 255, 'certificado_pdf_nome' => 255,
        ],
        'bairros_endereco'   => ['nome' => 255, 'codigo' => 20],
        'logradouros'        => ['nome' => 255],
        'logradouro_bairros' => ['bairro' => 255],
    ];

    /** Os tamanhos de antes, para o down(). */
    private const ANTES = [
        'denuncias' => [
            'protocolo' => 16, 'agente_nome' => 160, 'agente_matricula' => 30,
            'logradouro' => 180, 'numero' => 20, 'bairro' => 160, 'local_tipo' => 20,
            'fonte_sonora' => 20, 'resp_nome' => 160, 'resp_cpf' => 20, 'empresa_nome' => 160,
            'empresa_cnpj' => 20, 'placa' => 12, 'veic_marca' => 60, 'veic_modelo' => 60,
            'veic_cor' => 40, 'tratativa' => 60, 'cancelado_por_nome' => 160, 'cancelado_matricula' => 30,
        ],
        'contribuintes' => [
            'cpf_cnpj' => 20, 'nome' => 160, 'inscricao_municipal' => 40, 'email' => 160,
            'logradouro' => 180, 'numero' => 20, 'bairro' => 160, 'cidade' => 120,
        ],
        'decibelimetros' => [
            'tipo' => 80, 'fabricante' => 80, 'modelo' => 80, 'identificacao' => 80, 'serie' => 80,
            'certificado_calibracao' => 120, 'empresa_calibradora' => 160, 'certificado_pdf_nome' => 160,
        ],
        'bairros_endereco'   => ['nome' => 160, 'codigo' => 10],
        'logradouros'        => ['nome' => 180],
        'logradouro_bairros' => ['bairro' => 160],
    ];

    /** Colunas NOT NULL (as demais são nullable) e o padrão das que têm. */
    private const OBRIGATORIAS = [
        'contribuintes.cpf_cnpj', 'bairros_endereco.nome', 'logradouros.nome', 'logradouro_bairros.bairro',
        'denuncias.local_tipo', 'decibelimetros.tipo',
    ];

    private const PADROES = [
        'denuncias.local_tipo' => 'Local Exato',
        'decibelimetros.tipo'  => 'Decibelímetro Digital',
    ];

    public function up(): void
    {
        $this->aplicar(self::LARGURAS);
    }

    public function down(): void
    {
        $this->aplicar(self::ANTES);
    }

    /** `change()` reescreve a coluna inteira: nulidade e padrão vão junto, senão se perdem. */
    private function aplicar(array $larguras): void
    {
        foreach ($larguras as $tabela => $colunas) {
            Schema::table($tabela, function (Blueprint $t) use ($tabela, $colunas) {
                foreach ($colunas as $coluna => $tamanho) {
                    $c = $t->string($coluna, $tamanho);
                    if (! in_array("$tabela.$coluna", self::OBRIGATORIAS, true)) {
                        $c->nullable();
                    }
                    if (isset(self::PADROES["$tabela.$coluna"])) {
                        $c->default(self::PADROES["$tabela.$coluna"]);
                    }
                    $c->change();
                }
            });
        }
    }
};
