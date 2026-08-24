<?php

namespace App\Console\Commands;

use App\Cadastro\LeitorXlsx;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Carrega uma exportação do cadastro imobiliário (.xlsx) na tabela
 * `cadastro_externo_imoveis` — o banco da prefeitura simulado.
 *
 * Idempotente pela inscrição: recarregar a mesma exportação atualiza as linhas,
 * não duplica. Reimportar o município é operação de rotina, e um comando que
 * duplica a cada execução é um comando que ninguém roda.
 *
 * NÃO carrega proprietário nem documento. A exportação traz o nome do
 * proprietário; por ora só se guarda dado cadastral do imóvel.
 */
class CarregarCadastro extends Command
{
    protected $signature = 'cadastro:carregar
                            {arquivo : caminho da exportação .xlsx}
                            {--bairro= : nome do bairro no GIS, para amarrar ao bairro do cadastro}
                            {--limpar : apaga antes o que já está carregado dos mesmos bairros}';

    protected $description = 'Carrega uma exportação do cadastro imobiliário (.xlsx)';

    private const LOTE_INSERCAO = 200;

    /**
     * Colunas do cabeçalho que viram campo. A chave é o nome EXATO da coluna na
     * exportação; o valor, a coluna da tabela.
     *
     * Coluna ausente não quebra a carga: o campo fica nulo e o comando avisa no
     * fim o que não encontrou. É assim porque a exportação de outro município
     * terá outro conjunto de colunas, e o importador precisa DIZER o que faltou
     * em vez de morrer na primeira linha.
     */
    private const CAMPOS = [
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
    private const NUMERICOS = [
        'area_terreno_m2', 'area_edificada_m2', 'testada_m', 'medida_lado_direito',
        'medida_lado_esquerdo', 'medida_fundo', 'unidade_area_m2', 'unidade_ano',
        'unidade_pontos',
    ];

    /** Colunas que descrevem o imóvel e viram o quadro de características. */
    private const CARACTERISTICAS = [
        'OCUPACAO DO LOTE', 'UTILIZACAO', 'TIPO DE IMOVEL', 'BEM IMOV. PATRIMONIO',
        'SITUACAO', 'TOPOGRAFIA', 'PEDOLOGIA', 'ELEMENTO DE PROTECAO',
        'ENERGIA', 'AGUA', 'COLETA DE LIXO', 'ASFALTO', 'CALCADA',
        'REDE DE ESGOTO', 'REDE TELEFONICA', 'GALERIAS', 'ILUMINAÇÃO PUBL',
        'CONSERVACAO DE',
    ];

    public function handle(): int
    {
        $arquivo = $this->argument('arquivo');

        try {
            $leitor = new LeitorXlsx($arquivo);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $cabecalho = null;
        $posicao = [];
        $linhas = [];
        $semInscricao = 0;
        $bairros = [];

        foreach ($leitor->linhas() as $celulas) {
            // O cabeçalho não é necessariamente a primeira linha: estas
            // exportações abrem com o nome do relatório. Procura-se a linha que
            // traz "Inscrição", que é a coluna que sempre existe.
            if ($cabecalho === null) {
                if (! in_array('Inscrição', array_map('trim', $celulas), true)) {
                    continue;
                }
                $cabecalho = array_map('trim', $celulas);
                $posicao = array_flip($cabecalho);
                continue;
            }

            $ler = fn (string $col) => isset($posicao[$col]) ? trim($celulas[$posicao[$col]] ?? '') : '';

            if ($ler('Inscrição') === '') {
                $semInscricao++;
                continue;
            }

            $linha = [];
            foreach (self::CAMPOS as $coluna => $campo) {
                $v = $ler($coluna);
                $linha[$campo] = $v === '' ? null : $v;
            }
            foreach (self::NUMERICOS as $campo) {
                $linha[$campo] = $this->numero($linha[$campo]);
            }

            $linha['logradouro'] = trim($ler('Tipo de Logradouro') . ' ' . $ler('Nome do Logradouro')) ?: null;

            $carac = [];
            foreach (self::CARACTERISTICAS as $col) {
                $v = $ler($col);
                if ($v !== '' && $v !== '-') {
                    $carac[$col] = $v;
                }
            }
            $linha['caracteristicas'] = $carac ? json_encode($carac, JSON_UNESCAPED_UNICODE) : null;

            $linha['arquivo_origem'] = basename($arquivo);
            $linha['importado_em'] = now();
            $linha['created_at'] = now();
            $linha['updated_at'] = now();

            if ($linha['nome_bairro']) {
                $bairros[$linha['nome_bairro']] = $linha['codigo_bairro'];
            }

            $linhas[] = $linha;
        }

        if ($cabecalho === null) {
            $this->error('Não achei a linha de cabeçalho (a que tem a coluna "Inscrição").');

            return self::FAILURE;
        }

        $faltando = array_diff(array_keys(self::CAMPOS), $cabecalho);
        if ($faltando) {
            $this->warn('Colunas que a planilha não tem (ficam vazias): ' . implode(', ', $faltando));
        }

        if (! $linhas) {
            $this->error('Nenhuma linha com inscrição. A planilha está vazia ou é de outro formato.');

            return self::FAILURE;
        }

        if ($this->option('limpar')) {
            $codigos = array_values(array_filter(array_unique(array_column($linhas, 'codigo_bairro'))));
            $apagadas = DB::table('cadastro_externo_imoveis')->whereIn('codigo_bairro', $codigos)->delete();
            $this->line("Apagadas {$apagadas} linhas dos bairros " . implode(', ', $codigos));
        }

        $barra = $this->output->createProgressBar(count($linhas));
        foreach (array_chunk($linhas, self::LOTE_INSERCAO) as $bloco) {
            DB::table('cadastro_externo_imoveis')->upsert(
                $bloco,
                ['inscricao'],
                array_values(array_diff(array_keys($bloco[0]), ['inscricao', 'created_at']))
            );
            $barra->advance(count($bloco));
        }
        $barra->finish();
        $this->newLine(2);

        $this->info(sprintf('Carregados %d imóveis%s.', count($linhas),
            $semInscricao ? " ({$semInscricao} linhas sem inscrição, ignoradas)" : ''));

        $this->line('Bairros na exportação:');
        foreach ($bairros as $nome => $codigo) {
            $this->line(sprintf('  %-8s %s', (string) $codigo, $nome));
        }

        $this->amarrarBairro($bairros);

        return self::SUCCESS;
    }

    /**
     * Liga o bairro do cadastro ao bairro do GIS.
     *
     * Sem essa ligação o casamento de imóveis não acontece — e não acontecer é
     * o comportamento certo: casar por quadra e lote sem o bairro produz
     * ligação ERRADA, porque quadra 2 lote 5 existe em todo bairro do
     * município. Medido nesta base: 656 pares (quadra, lote) do Buritis existem
     * também no Jardim Europa IV. É por isso que a amarração é um passo
     * explícito, e não um palpite do importador.
     *
     * @param  array<string,?string>  $bairros  nome no cadastro => código
     */
    private function amarrarBairro(array $bairros): void
    {
        $gis = $this->option('bairro');
        if (! $gis) {
            $this->newLine();
            $this->warn('Nenhum bairro do GIS informado: os imóveis ficam carregados, mas ainda');
            $this->warn('não casam com lote nenhum. Para amarrar, rode de novo com');
            $this->warn('  --bairro="Nome do bairro como está no GIS"');

            return;
        }

        if (count($bairros) !== 1) {
            $this->error('A exportação tem ' . count($bairros) . ' bairros; --bairro só serve para arquivo de um.');

            return;
        }

        $lotes = DB::table('lotes')->where('bairro', $gis)->count();
        if ($lotes === 0) {
            $this->error("Não há lote nenhum no bairro \"{$gis}\". Confira o nome — ele tem de ser");
            $this->error('idêntico ao que está na coluna `bairro` da tabela lotes.');

            return;
        }

        $nomeCadastro = array_key_first($bairros);
        $codigo = $bairros[$nomeCadastro];

        DB::table('cadastro_bairros')->updateOrInsert(
            ['nome_gis' => $gis],
            ['codigo' => $codigo, 'nome_cadastro' => $nomeCadastro,
                'created_at' => now(), 'updated_at' => now()]
        );

        $this->newLine();
        $this->info("Bairro amarrado: \"{$nomeCadastro}\" (código {$codigo}) = \"{$gis}\" ({$lotes} lotes no GIS).");
    }

    /** "14891.33" e "14.891,33" viram 14891.33. Vazio vira null. */
    private function numero(?string $v): ?float
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
}
