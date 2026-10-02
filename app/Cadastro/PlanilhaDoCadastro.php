<?php

namespace App\Cadastro;

use App\Models\Lote;
use App\Support\InscricaoImobiliaria;
use RuntimeException;

/**
 * Uma exportação do cadastro (.xlsx) lida SÓ EM MEMÓRIA, para conferir uma
 * importação de bairro.
 *
 * Diferente de `cadastro:carregar`, nada é gravado: quem confere um loteamento
 * novo pode ter em mãos uma planilha mais recente — ou de outro recorte — que a
 * exportação carregada, e usá-la para conferir não deve trocar o cadastro que
 * a ficha do BCI de todos os outros lotes lê.
 *
 * Implementa FonteDoCadastro para entrar na conferência pelo mesmo caminho do
 * cadastro carregado — e, no dia em que houver o banco da prefeitura, pelo
 * mesmo caminho dele.
 */
class PlanilhaDoCadastro implements FonteDoCadastro
{
    public function __construct(private string $arquivo, private string $nomeOriginal = '') {}

    public function nome(): string
    {
        return 'planilha';
    }

    /**
     * Não responde por lote: a planilha avulsa serve à conferência, não à
     * ficha — quem lê a ficha é sempre o cadastro carregado.
     */
    public function consultar(Lote $lote): ?RetratoBci
    {
        return null;
    }

    public function porQueVazio(Lote $lote): string
    {
        return 'A planilha enviada para conferência não alimenta a ficha do imóvel.';
    }

    /**
     * O mesmo dado com nomes diferentes nas DUAS exportações que a prefeitura
     * tira do sistema dela: a do cadastro imobiliário (a de `cadastro:carregar`)
     * e a relação de imóveis ("Situação", "Quadra (cadastro)"...). A primeira
     * coluna que existir vale.
     *
     * "Situação" (Ativo/Inativo) é aceita com acento e caixa exatos de
     * propósito: a exportação do cadastro tem uma "SITUACAO" que é outra coisa
     * — a posição do lote na quadra.
     */
    private const SINONIMOS = [
        'Quadra'               => ['Quadra', 'Quadra (cadastro)'],
        'Lote'                 => ['Lote', 'Lote (cadastro)'],
        'Isenção ou Imunidade' => ['Isenção ou Imunidade', 'Situação'],
        'Área Terreno'         => ['Área Terreno', 'Área m²'],
        'Nome do Logradouro'   => ['Nome do Logradouro', 'Logradouro (cadastro)'],
        'Número do Endereço'   => ['Número do Endereço', 'Número (cadastro)'],
    ];

    /** A planilha tinha coluna de situação (ativo/inativo)? Só se sabe depois de ler. */
    public ?bool $temSituacao = null;

    public function imoveisDoBairro(string $codigoBairro): iterable
    {
        $leitor = new LeitorXlsx($this->arquivo);
        $posicao = null;
        $alvo = (int) ltrim($codigoBairro, '0');

        foreach ($leitor->linhas() as $celulas) {
            if ($posicao === null) {
                $posicao = ColunasDaExportacao::cabecalho($celulas);
                if ($posicao !== null) {
                    foreach (self::SINONIMOS as $nome => $opcoes) {
                        foreach ($opcoes as $o) {
                            if (isset($posicao[$o])) { $posicao[$nome] = $posicao[$o]; break; }
                        }
                    }
                    $this->temSituacao = isset($posicao['Isenção ou Imunidade']);
                }
                continue;
            }

            $ler = fn (string $col) => isset($posicao[$col]) ? trim($celulas[$posicao[$col]] ?? '') : '';

            $inscricao = InscricaoImobiliaria::normalizar($ler('Inscrição'));
            if ($inscricao === null) {
                continue;
            }
            // O bairro sai da PRÓPRIA inscrição, e não da coluna "Código do
            // Bairro": é a inscrição que se compara, e uma planilha de outro
            // formato pode nem ter a coluna.
            if ((int) substr($inscricao, 2, 3) !== $alvo) {
                continue;
            }

            // Vírgula decimal só quando ela é o último separador — a mesma
            // regra de CarregarCadastro::numero: "1.234,56" e "360.00" valem.
            $area = $ler('Área Terreno');
            if (str_contains($area, ',') && strrpos($area, ',') > (strrpos($area, '.') ?: -1)) {
                $area = str_replace(['.', ','], ['', '.'], $area);
            }

            // Sem coluna de quadra/lote, as partes da própria inscrição.
            $partes = InscricaoImobiliaria::partes($inscricao);

            yield [
                'inscricao'       => $inscricao,
                'quadra'          => $ler('Quadra') ?: (string) $partes['quadra'],
                'lote'            => $ler('Lote') ?: (string) $partes['lote'],
                'isencao'         => $ler('Isenção ou Imunidade') ?: null,
                'area_terreno_m2' => is_numeric($area) ? (float) $area : null,
                'endereco'        => trim($ler('Tipo de Logradouro') . ' ' . $ler('Nome do Logradouro')
                                          . ' ' . $ler('Número do Endereço')) ?: null,
            ];
        }

        if ($posicao === null) {
            throw new RuntimeException('Não achei na planilha a linha de cabeçalho — a que tem a coluna '
                . '"Inscrição". Ela precisa ter o formato da exportação do cadastro imobiliário.');
        }
    }
}
