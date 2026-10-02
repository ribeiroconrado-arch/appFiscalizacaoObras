<?php

namespace App\Services;

use App\Cadastro\BairrosDoDesenho;
use App\Cadastro\FonteDoCadastro;
use App\Cadastro\PlanilhaDoCadastro;
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
     * A fonte que o pedido escolheu, com a descrição para o carimbo: a
     * planilha anexada (lida só em memória — ela NÃO é guardada) ou o cadastro
     * carregado no sistema. "Revisar sem planilha" não passa por aqui: ver
     * revisarImportacao / revisarBairro.
     *
     * @return array{0: FonteDoCadastro, 1: string}
     */
    public function fonteDoPedido(Request $r): array
    {
        if ($r->hasFile('planilha')) {
            $p = $r->file('planilha');
            if (strtolower($p->getClientOriginalExtension()) !== 'xlsx') {
                throw new RuntimeException('A planilha precisa ser .xlsx (Excel 2007 ou mais novo).');
            }

            return [new PlanilhaDoCadastro($p->getRealPath(), $p->getClientOriginalName()),
                'Planilha ' . $p->getClientOriginalName()];
        }

        $quando = DB::table('cadastro_externo_imoveis')->max('importado_em');

        return [app(FonteDoCadastro::class),
            'Cadastro carregado' . ($quando ? ' · exportação de ' . date('d/m/Y', strtotime($quando)) : '')];
    }

    /**
     * REVISAR AS DIVERGÊNCIAS, sem a planilha — o "Conferir de novo" sem anexo
     * e a reconferência automática depois de cada correção no mapa.
     *
     * A planilha não é guardada (decisão do usuário): o que fica são só as
     * divergências da última conferência. Revisar é olhar cada uma contra os
     * lotes COMO ESTÃO AGORA:
     *
     *   cadastro sem lote   resolvida se algum lote ativo do bairro passou a
     *                       ter aquela inscrição;
     *   não encontrado /    resolvida se o lote saiu (excluído, inativado), ou
     *   inativo             se a inscrição dele passou a ser uma das "sem lote"
     *                       — as duas pontas se encontraram;
     *   sem inscrição       resolvida se o lote saiu, ou se a inscrição que ele
     *                       passou a ter é uma das "sem lote".
     *
     * O que mudou para uma inscrição que a lista não conhece fica PENDENTE e
     * marcado `alterado`: sem a planilha não há como saber se ela existe no
     * cadastro — isso pede uma conferência com a planilha.
     *
     * @param  array<string,mixed>  $anterior
     * @return array<string,mixed>
     */
    private function revisar(array $anterior, string $bairro, ?int $importacaoId): array
    {
        $bairros = new BairrosDoDesenho();
        $nomes = $anterior['nomes_do_desenho'] ?? [$bairro];
        $campos = ['id', 'bairro', 'quadra', 'numero_lote', 'desmembramento', 'inscricao_imobiliaria', 'importacao_id'];
        $lotes = DB::table('lotes')->where('situacao', 'ativo')->whereIn('bairro', $nomes)->get($campos);

        $porId = [];          // lote ativo => [inscrição atual (ou null), linha]
        $comInscricao = [];   // inscrição => lote, bairro inteiro
        $conferidos = 0;
        foreach ($lotes as $l) {
            $insc = InscricaoImobiliaria::normalizar($bairros->inscricaoDe($l));
            $porId[(int) $l->id] = [$insc, $l];
            if ($insc !== null) { $comInscricao[$insc] = $l; }
            $conferidos += ($importacaoId === null || (int) $l->importacao_id === $importacaoId) ? 1 : 0;
        }

        $semLote = [];       // inscrição => item, os que continuam sem lote
        foreach ($anterior['sem_lote'] ?? [] as $item) {
            $semLote[InscricaoImobiliaria::normalizar($item['inscricao'])] = $item;
        }
        $resolvidas = 0;
        $casaramAgora = 0;

        // Lote da lista: saiu? continua igual? casou com um "sem lote"?
        $revisaLote = function (array $item, string $tipo) use (&$porId, &$semLote, &$resolvidas, &$casaramAgora): ?array {
            $atual = $porId[(int) ($item['lote_id'] ?? 0)] ?? null;
            if (! $atual) { $resolvidas++; return null; }                  // excluído ou inativado
            [$insc, $l] = $atual;
            $antes = InscricaoImobiliaria::normalizar($item['inscricao'] ?? null);
            if ($insc !== null && isset($semLote[$insc])) {                 // achou o par no cadastro
                unset($semLote[$insc]);
                $resolvidas++;
                $casaramAgora++;
                return null;
            }
            if ($insc === $antes) { return $item; }                         // nada mudou
            return ['lote_id' => $l->id, 'inscricao' => InscricaoImobiliaria::formatar($insc),
                'quadra' => $l->quadra, 'lote' => $l->numero_lote, 'alterado' => true]
                + ($tipo === 'inativos' ? ['isencao' => $item['isencao'] ?? null] : []);
        };

        $naoEncontrados = [];
        $inativos = [];
        $semInscricao = [];
        foreach ($anterior['nao_encontrados'] ?? [] as $i) { if ($r = $revisaLote($i, 'nao_encontrados')) { $naoEncontrados[] = $r; } }
        foreach ($anterior['inativos'] ?? [] as $i) { if ($r = $revisaLote($i, 'inativos')) { $inativos[] = $r; } }
        foreach ($anterior['sem_inscricao'] ?? [] as $i) {
            $r = $revisaLote($i, 'sem_inscricao');
            if (! $r) { continue; }
            // Ganhou inscrição que a lista não conhece: deixa de ser "sem
            // inscrição" e passa a "não confirmado no cadastro".
            if (($r['alterado'] ?? false) && $r['inscricao'] !== null) { $naoEncontrados[] = $r; } else { $semInscricao[] = $r; }
        }

        // "Cadastro sem lote" que ganhou lote por outro caminho (desenhado).
        foreach ($semLote as $insc => $item) {
            if (isset($comInscricao[$insc])) { unset($semLote[$insc]); $resolvidas++; $casaramAgora++; }
        }
        $semLote = array_values($semLote);

        return [
            'nao_encontrados'   => $naoEncontrados,
            'inativos'          => $inativos,
            'sem_lote'          => $semLote,
            'sem_inscricao'     => $semInscricao,
            'casaram'           => (int) ($anterior['casaram'] ?? 0) + $casaramAgora,
            'lotes_conferidos'  => $conferidos,
            'total_divergencias' => count($naoEncontrados) + count($inativos) + count($semLote) + count($semInscricao),
            'revisao'           => ['resolvidas' => $resolvidas, 'em' => now()->format('d/m/Y H:i'),
                                    'por' => auth()->user()?->name],
        ] + $anterior;
    }

    /** Revisa a conferência da IMPORTAÇÃO sem planilha (ver revisar). */
    public function revisarImportacao(ImportacaoLote $imp): array
    {
        $anterior = $imp->conferencia_cadastro
            ?? throw new RuntimeException('A importação ainda não foi conferida. Confira com a planilha ou com o cadastro carregado.');
        $resultado = $this->revisar($anterior, $imp->bairro, $imp->id);
        $imp->update(['conferencia_cadastro' => $resultado, 'conferido_em' => now()]);

        return $resultado;
    }

    /** Revisa a conferência do BAIRRO sem planilha (ver revisar). */
    public function revisarBairro(string $bairro): array
    {
        $json = DB::table('conferencias_bairro')->where('bairro', $bairro)->value('resultado')
            ?? throw new RuntimeException('Este bairro ainda não foi conferido. Confira com a planilha ou com o cadastro carregado.');
        $resultado = $this->revisar(json_decode($json, true), $bairro, null);
        DB::table('conferencias_bairro')->where('bairro', $bairro)->update([
            'resultado'    => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            'conferido_em' => now(),
            'updated_at'   => now(),
        ]);

        return $resultado;
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
        if (! $imp->emRascunho()) {
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

        foreach ($fonte->imoveisDoBairro((string) $codigo) as $c) {
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

        // Fonte SEM NENHUM imóvel do bairro não é base de comparação: conferir
        // contra ela marcaria todo lote como "não encontrado" — e gravaria esse
        // resultado por cima da última conferência boa. Foi o que aconteceu com
        // o cadastro carregado, que não tem o Buritis: recusa, e diz o porquê.
        if ($noCadastro === 0) {
            throw new RuntimeException(($fonte instanceof PlanilhaDoCadastro
                    ? 'A planilha' : 'O cadastro carregado no sistema')
                . " não tem nenhum imóvel do bairro de código {$codigo}, então não há com o que comparar. "
                . 'Confira com uma planilha .xlsx da exportação que traga esse bairro. A conferência anterior foi mantida.');
        }

        foreach ($doArquivo as $insc => $l) {
            if (! isset($vistos[$insc])) {
                $naoEncontrados[] = $this->lote($l, $insc);
            }
        }

        return [
            'bairro'            => $bairro,
            'nomes_do_desenho'  => $nomes,
            'fonte'             => $fonte->nome(),
            'fonte_descricao'   => $descricaoFonte,
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
