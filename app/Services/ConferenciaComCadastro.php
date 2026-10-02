<?php

namespace App\Services;

use App\Cadastro\BairrosDoDesenho;
use App\Cadastro\FonteDoCadastro;
use App\Cadastro\PlanilhaDoCadastro;
use App\Cadastro\RetratoDoCadastro;
use Illuminate\Http\Request;
use App\Models\Bci\BciImovel;
use App\Models\ImportacaoLote;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Confere um bairro importado com o cadastro imobiliário da prefeitura, NOS
 * DOIS SENTIDOS, e devolve só o que não bate.
 *
 *   arquivo → cadastro   lote do arquivo cuja inscrição o cadastro não tem,
 *                        ou tem como inativa;
 *   cadastro → arquivo   imóvel ativo do cadastro, naquele bairro, sem lote
 *                        desenhado;
 *   sem inscrição        lote sem quadra ou número — a inscrição não se monta.
 *
 * ── A chave é a INSCRIÇÃO, e não quadra + lote ──
 *
 * 01 + bairro + quadra + lote + variação, montada por InscricaoImobiliaria a
 * partir do código amarrado em Parâmetros (BairrosDoDesenho). Quadra e lote
 * sozinhos enganam: na exportação do Buritis há imóveis com a MESMA quadra e o
 * mesmo "Lote" e inscrições diferentes — lote unificado que o cadastro ainda
 * lista pelos números de origem. A inscrição é o que a prefeitura usa para
 * dizer "este imóvel".
 *
 * ── O sentido cadastro → arquivo olha o bairro inteiro ──
 *
 * Um imóvel do cadastro só é "sem lote" se NENHUM lote ativo do bairro tiver a
 * inscrição dele — desta importação ou de antes. Senão, completar um bairro
 * que já tinha parte carregada acusaria como faltante tudo o que já existia.
 */
class ConferenciaComCadastro
{
    public function __construct(private ImportacaoDeBairro $importacao) {}

    /**
     * A fonte que o pedido escolheu, com a descrição para o carimbo.
     *
     *   planilha anexada   lida agora (e guardada como retrato do bairro);
     *   fonte=ultima       a mesma da conferência anterior — o retrato da
     *                      planilha, ou o cadastro carregado —, sem arquivo;
     *   nada               o cadastro carregado no sistema.
     *
     * @param  array<string,mixed>|null  $anterior  o resultado da conferência anterior
     * @return array{0: FonteDoCadastro, 1: string}
     */
    public function fonteDoPedido(Request $r, ?array $anterior): array
    {
        if ($r->hasFile('planilha')) {
            $p = $r->file('planilha');
            if (strtolower($p->getClientOriginalExtension()) !== 'xlsx') {
                throw new RuntimeException('A planilha precisa ser .xlsx (Excel 2007 ou mais novo).');
            }

            return [new PlanilhaDoCadastro($p->getRealPath(), $p->getClientOriginalName()),
                'Planilha ' . $p->getClientOriginalName()];
        }

        if ($r->input('fonte') === 'ultima') {
            if (! $anterior) {
                throw new RuntimeException('Ainda não há conferência anterior para repetir. Escolha a fonte.');
            }
            if (! empty($anterior['retrato_id'])) {
                $f = RetratoDoCadastro::carregar((int) $anterior['retrato_id']);

                return [$f, $f->descricao];
            }
            // Planilha de antes de os retratos serem guardados: não há o que
            // reabrir — e cair no cadastro carregado sem dizer trocaria a fonte
            // por baixo da pessoa.
            if (($anterior['fonte'] ?? null) === 'planilha') {
                throw new RuntimeException('A planilha da última conferência não foi guardada (ela é de antes desta '
                    . 'função). Anexe-a uma vez; daí em diante ela fica guardada para as próximas conferências.');
            }
            // A anterior foi com o cadastro carregado: é ele de novo.
        }

        $quando = DB::table('cadastro_externo_imoveis')->max('importado_em');

        return [app(FonteDoCadastro::class),
            'Cadastro carregado' . ($quando ? ' · exportação de ' . date('d/m/Y', strtotime($quando)) : '')];
    }

    /**
     * A conferência do bairro ainda vale? Falso quando algum lote do bairro
     * mudou depois dela, ou o número de lotes ativos não é mais o conferido.
     *
     * @param  array<string,mixed>  $resultado
     */
    public function bairroEmDia(array $resultado, string $conferidoEm): bool
    {
        $nomes = $resultado['nomes_do_desenho'] ?? [$resultado['bairro'] ?? ''];
        $ultima = DB::table('lotes')->whereIn('bairro', $nomes)->max('updated_at');
        $ativos = DB::table('lotes')->whereIn('bairro', $nomes)->where('situacao', 'ativo')->count();

        return ! ($ultima && strtotime($ultima) > strtotime($conferidoEm))
            && $ativos === (int) ($resultado['lotes_conferidos'] ?? -1);
    }

    /**
     * Confere a IMPORTAÇÃO: "arquivo" são só os lotes dela.
     *
     * @return array<string,mixed> o resultado, também gravado na importação
     */
    public function conferir(ImportacaoLote $imp, FonteDoCadastro $fonte, string $descricaoFonte): array
    {
        $resultado = $this->calcular($imp->bairro, $fonte, $descricaoFonte, $imp->id,
            'Vincule-o em "Bairro no cadastro", na ficha da importação, e confira de novo.');

        $imp->update(['conferencia_cadastro' => $resultado, 'conferido_em' => now()]);
        // Conferência de rascunho fica só na própria importação: a trilha
        // começa quando ela é salva.
        // Nem a reconferência com a planilha guardada: ela roda sozinha a cada
        // correção, e encheria a trilha de linhas que só repetem a fonte.
        if (! $imp->emRascunho() && ! $fonte instanceof RetratoDoCadastro) {
            $this->importacao->auditar('conferiu com cadastro', $imp, null, [
                'fonte' => $descricaoFonte, 'divergencias' => $resultado['total_divergencias'],
                'casaram' => $resultado['casaram'],
            ]);
        }

        return $resultado;
    }

    /**
     * Confere o BAIRRO inteiro — publicado ou não, importado pela tela ou pelo
     * comando. É a conferência que fica: o que não dá para resolver agora
     * continua na lista de pendências do bairro, no mapa, até ser corrigido
     * ou justificado (ver ConferenciaBairroController).
     *
     * @return array<string,mixed> o resultado, gravado em conferencias_bairro
     */
    public function conferirBairro(string $bairro, FonteDoCadastro $fonte, string $descricaoFonte): array
    {
        $resultado = $this->calcular($bairro, $fonte, $descricaoFonte, null,
            'Faça a ligação em Parâmetros → Bairros (ou na ficha de uma importação do bairro) e confira de novo.');
        $resultado['centros_quadras'] = app(\App\Repositories\LoteRepository::class)
            ->centrosDasQuadras($resultado['nomes_do_desenho']);

        DB::table('conferencias_bairro')->updateOrInsert(['bairro' => $bairro], [
            'codigo_bairro' => $resultado['codigo_bairro'],
            'resultado'     => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            'user_id'       => auth()->id(),
            'conferido_em'  => now(),
            'updated_at'    => now(),
            'created_at'    => now(),
        ]);

        return $resultado;
    }

    /**
     * O cálculo, dos dois lados. `$importacaoId` decide o que é "arquivo": os
     * lotes daquela importação, ou (null) todos os lotes ativos do bairro.
     *
     * @return array<string,mixed>
     */
    private function calcular(string $bairro, FonteDoCadastro $fonte, string $descricaoFonte,
                              ?int $importacaoId, string $comoLigar): array
    {
        $bairros = new BairrosDoDesenho();
        $codigo = $bairros->codigos()[BairrosDoDesenho::chave($bairro)] ?? null;
        if (! $codigo) {
            throw new RuntimeException("O bairro \"{$bairro}\" ainda não foi ligado a um bairro do "
                . 'cadastro, e sem o código dele não há como montar a inscrição dos lotes. ' . $comoLigar);
        }

        $campos = ['id', 'bairro', 'quadra', 'numero_lote', 'desmembramento', 'inscricao_imobiliaria', 'importacao_id'];

        // Todos os nomes de desenho amarrados ao MESMO código: o cadastro não
        // sabe que o desenho partiu o bairro em dois nomes.
        $nomes = DB::table('cadastro_bairros')->whereNotNull('nome_gis')->get()
            ->filter(fn ($b) => ltrim((string) $b->codigo, '0') === ltrim((string) $codigo, '0'))
            ->pluck('nome_gis')->push($bairro)->unique()->values()->all();

        $lotesDoBairro = DB::table('lotes')->where('situacao', 'ativo')
            ->whereIn('bairro', $nomes)->get($campos);

        $doBairro = [];              // inscrição => true, bairro inteiro
        $doArquivo = [];             // inscrição => lote, o que está sendo conferido
        $semInscricao = [];
        $lotesConferidos = 0;

        foreach ($lotesDoBairro as $l) {
            $insc = InscricaoImobiliaria::normalizar($bairros->inscricaoDe($l));
            $conta = $importacaoId === null || (int) $l->importacao_id === $importacaoId;
            $lotesConferidos += $conta ? 1 : 0;

            if ($insc === null) {
                if ($conta) {
                    $semInscricao[] = $this->lote($l, null);
                }
                continue;
            }
            $doBairro[$insc] = true;
            if ($conta) {
                $doArquivo[$insc] = $l;
            }
        }

        $naoEncontrados = [];
        $inativos = [];
        $semLote = [];
        $casaram = 0;
        $noCadastro = 0;
        $vistos = [];
        $linhasDaFonte = [];   // para o retrato, quando a fonte é planilha avulsa

        foreach ($fonte->imoveisDoBairro((string) $codigo) as $c) {
            $linhasDaFonte[] = $c;
            $insc = $c['inscricao'];
            if (isset($vistos[$insc])) {
                continue;   // linha repetida da mesma inscrição (várias construções)
            }
            $vistos[$insc] = true;
            $noCadastro++;

            $ativo = BciImovel::isencaoAtiva($c['isencao']);

            if (isset($doArquivo[$insc])) {
                if ($ativo === false) {
                    $inativos[] = $this->lote($doArquivo[$insc], $insc) + ['isencao' => $c['isencao']];
                } else {
                    $casaram++;
                }
            } elseif ($ativo !== false && ! isset($doBairro[$insc])) {
                $semLote[] = [
                    'inscricao' => InscricaoImobiliaria::formatar($insc),
                    'quadra'    => $c['quadra'],
                    'lote'      => $c['lote'],
                    'area_m2'   => $c['area_terreno_m2'],
                    'endereco'  => $c['endereco'],
                ];
            }
        }

        foreach ($doArquivo as $insc => $l) {
            if (! isset($vistos[$insc])) {
                $naoEncontrados[] = $this->lote($l, $insc);
            }
        }

        // A planilha avulsa é guardada (só este bairro): a próxima conferência
        // — pelo botão ou automática, depois de uma correção — usa a mesma, sem
        // pedir o arquivo de novo. O retrato reusado mantém o próprio id.
        $retratoId = match (true) {
            $fonte instanceof RetratoDoCadastro => $fonte->id,
            $fonte instanceof PlanilhaDoCadastro => RetratoDoCadastro::guardar(
                (string) $codigo, $descricaoFonte, $linhasDaFonte, $fonte->temSituacao),
            default => null,
        };

        return [
            'bairro'            => $bairro,
            'nomes_do_desenho'  => $nomes,
            'fonte'             => $fonte->nome(),
            'fonte_descricao'   => $descricaoFonte,
            'retrato_id'        => $retratoId,
            'codigo_bairro'     => (string) $codigo,
            'lotes_conferidos'  => $lotesConferidos,
            'imoveis_no_cadastro' => $noCadastro,
            'casaram'           => $casaram,
            'nao_encontrados'   => $naoEncontrados,
            'inativos'          => $inativos,
            'sem_lote'          => $semLote,
            'sem_inscricao'     => $semInscricao,
            'total_divergencias' => count($naoEncontrados) + count($inativos) + count($semLote) + count($semInscricao),
            // Planilha sem coluna de situação: "inativo no cadastro" não pôde
            // ser conferido — e zero inativos, sem este aviso, pareceria certeza.
            'sem_situacao'      => property_exists($fonte, 'temSituacao') && $fonte->temSituacao === false,
            'conferido_por'     => auth()->user()?->name,
            'conferido_em'      => now()->format('d/m/Y H:i'),
        ];
    }

    /** @return array<string,mixed> */
    private function lote(object $l, ?string $inscricao): array
    {
        return [
            'lote_id'   => $l->id,
            'inscricao' => InscricaoImobiliaria::formatar($inscricao),
            'quadra'    => $l->quadra,
            'lote'      => $l->numero_lote,
        ];
    }
}
