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
