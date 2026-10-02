<?php

namespace App\Cadastro;

/**
 * As colunas da exportação do cadastro imobiliário (.xlsx) e o que cada uma
 * vira no sistema.
 *
 * Moram aqui, e não no comando que carrega a exportação, porque agora são DUAS
 * as leituras da mesma planilha: `cadastro:carregar`, que a grava em
 * `cadastro_externo_imoveis`, e a conferência de importação de bairro, que a
 * lê só em memória (ver PlanilhaDoCadastro). Duas cópias da lista divergiriam
 * na primeira coluna renomeada pela prefeitura.
 */
final class ColunasDaExportacao
{
    /**
     * Colunas do cabeçalho que viram campo. A chave é o nome EXATO da coluna na
     * exportação; o valor, a coluna da tabela.
     *
     * Coluna ausente não quebra a carga: o campo fica nulo e o comando avisa no
     * fim o que não encontrou. É assim porque a exportação de outro município
     * terá outro conjunto de colunas, e o importador precisa DIZER o que faltou
     * em vez de morrer na primeira linha.
     */
    public const CAMPOS = [
        'Inscrição'               => 'inscricao',
        'Código'                  => 'codigo_cadastro',
        'Inscrição Alternativa'   => 'inscricao_alternativa',
        'Código do Bairro'        => 'codigo_bairro',
        'Nome do Bairro'          => 'nome_bairro',
        'Quadra'                  => 'quadra',
        'Lote'                    => 'lote',
        'Número do Endereço'      => 'numero_predial',
        'Complemento do Endereço' => 'complemento',
        'Isenção ou Imunidade'    => 'isencao',
        'Área Terreno'            => 'area_terreno_m2',
        'Área Edificada'          => 'area_edificada_m2',
        'Testada Principal'       => 'testada_m',
        'LADO DIR.'               => 'medida_lado_direito',
        'LADO ESQ.'               => 'medida_lado_esquerdo',
        'FUNDO'                   => 'medida_fundo',
        'SETOR'                   => 'setor',
        'REGIAO FISCAL'           => 'regiao_fiscal',
        'AREA EDIFICADA'          => 'unidade_area_m2',
        'ANO CONSTRUÇÃO'          => 'unidade_ano',
        'PONTOS'                  => 'unidade_pontos',
    ];

    /** Colunas numéricas — o resto entra como texto, como veio. */
    public const NUMERICOS = [
        'area_terreno_m2', 'area_edificada_m2', 'testada_m', 'medida_lado_direito',
        'medida_lado_esquerdo', 'medida_fundo', 'unidade_area_m2', 'unidade_ano',
        'unidade_pontos',
    ];

    /** Colunas que descrevem o imóvel e viram o quadro de características. */
    public const CARACTERISTICAS = [
        'OCUPACAO DO LOTE', 'UTILIZACAO', 'TIPO DE IMOVEL', 'BEM IMOV. PATRIMONIO',
        'SITUACAO', 'TOPOGRAFIA', 'PEDOLOGIA', 'ELEMENTO DE PROTECAO',
        'ENERGIA', 'AGUA', 'COLETA DE LIXO', 'ASFALTO', 'CALCADA',
        'REDE DE ESGOTO', 'REDE TELEFONICA', 'GALERIAS', 'ILUMINAÇÃO PUBL',
        'CONSERVACAO DE',
    ];


    /**
     * Colunas do PROPRIETÁRIO. Cada campo aceita mais de um nome porque o
     * cabeçalho exato da exportação ainda não foi conferido contra uma planilha
     * real; vale o primeiro nome que existir. Quando o nome certo for
     * confirmado, ele entra no topo da lista.
     *
     * Linha da planilha = unidade, não imóvel: o mesmo proprietário se repete
     * em cada unidade do terreno. Quem carrega junta por inscrição e descarta
     * a repetição (ver `CarregarCadastro`).
     */
    public const PROPRIETARIO = [
        'nome'      => ['Nome do Proprietário', 'Proprietário', 'Nome do Contribuinte', 'Contribuinte'],
        'documento' => ['CPF/CNPJ do Proprietário', 'CPF/CNPJ', 'CPF/CNPJ do Contribuinte', 'CPF', 'CNPJ'],
        'endereco'  => ['Endereço de Correspondência', 'Endereço do Proprietário', 'Endereço do Contribuinte'],
    ];

    /**
     * O proprietário de uma linha, ou null se ela não traz nome.
     *
     * @param  callable(string): string  $ler  valor da célula pelo nome da coluna
     * @return array{nome:string, documento:?string, endereco:?string}|null
     */
    public static function proprietario(callable $ler): ?array
    {
        $campo = function (string $qual) use ($ler): ?string {
            foreach (self::PROPRIETARIO[$qual] as $coluna) {
                $v = trim($ler($coluna));
                if ($v !== '' && $v !== '-') {
                    return $v;
                }
            }
            return null;
        };

        $nome = $campo('nome');

        return $nome === null ? null : [
            'nome'      => mb_substr($nome, 0, 200),
            'documento' => ($d = $campo('documento')) !== null ? mb_substr($d, 0, 24) : null,
            'endereco'  => ($e = $campo('endereco')) !== null ? mb_substr($e, 0, 300) : null,
        ];
    }

    /**
     * Casas decimais de cada coluna numérica, iguais às da tabela. É o que faz
     * "350" da planilha e "350.00" do banco serem o MESMO valor na comparação
     * da carga mensal — sem isso todo imóvel pareceria alterado todo mês.
     */
    public const CASAS = [
        'area_terreno_m2' => 2, 'area_edificada_m2' => 2, 'testada_m' => 2,
        'medida_lado_direito' => 2, 'medida_lado_esquerdo' => 2, 'medida_fundo' => 2,
        'unidade_area_m2' => 2, 'unidade_ano' => 0, 'unidade_pontos' => 0,
    ];

    /**
     * Uma linha da planilha como registro do cadastro, já na forma CANÔNICA
     * (texto aparado, vazio vira null, número com as casas da tabela), ou null
     * se a linha não tem inscrição.
     *
     * As chaves são as colunas de `cadastro_externo_imoveis`: CAMPOS, mais
     * `logradouro` e `caracteristicas` (JSON). O proprietário fica fora — sai
     * por `proprietario()`, porque uma inscrição pode ter vários.
     *
     * @param  callable(string): string  $ler  valor da célula pelo nome da coluna
     * @return array<string,?string>|null
     */
    public static function linha(callable $ler): ?array
    {
        if (trim($ler('Inscrição')) === '') {
            return null;
        }

        $r = [];
        foreach (self::CAMPOS as $coluna => $campo) {
            $r[$campo] = self::canonico($campo, $ler($coluna));
        }
        $r['logradouro'] = self::canonico('logradouro',
            trim(trim($ler('Tipo de Logradouro')) . ' ' . trim($ler('Nome do Logradouro'))));

        $carac = [];
        foreach (self::CARACTERISTICAS as $col) {
            $v = trim($ler($col));
            if ($v !== '' && $v !== '-') {
                $carac[$col] = $v;
            }
        }
        $r['caracteristicas'] = $carac ? json_encode($carac, JSON_UNESCAPED_UNICODE) : null;

        return $r;
    }

    /**
     * Um valor na forma em que é comparado e gravado. Serve tanto para o que
     * vem da planilha quanto para o que já está no banco.
     */
    public static function canonico(string $campo, mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }

        if (array_key_exists($campo, self::CASAS)) {
            $n = self::numero($v);

            return $n === null ? null : number_format($n, self::CASAS[$campo], '.', '');
        }

        return $v;
    }

    /** "1.234,56", "1234.56" ou "350" → float; o resto, null. */
    public static function numero(?string $v): ?float
    {
        if ($v === null || trim($v) === '') {
            return null;
        }

        $v = trim($v);
        // Vírgula decimal: só quando ela é o último separador da cadeia.
        if (str_contains($v, ',') && strrpos($v, ',') > (strrpos($v, '.') ?: -1)) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * A linha de cabeçalho, ou null se esta não for ela.
     *
     * O cabeçalho não é necessariamente a primeira linha: estas exportações
     * abrem com o nome do relatório. É a linha que traz "Inscrição", a coluna
     * que sempre existe.
     *
     * @param  list<string>  $celulas
     * @return array<string,int>|null  nome da coluna => posição
     */
    public static function cabecalho(array $celulas): ?array
    {
        $nomes = array_map('trim', $celulas);

        return in_array('Inscrição', $nomes, true) ? array_flip($nomes) : null;
    }
}
