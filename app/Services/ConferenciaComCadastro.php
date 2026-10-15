<?php

namespace App\Services;

use App\Cadastro\BairrosDoDesenho;
use App\Cadastro\FonteDoCadastro;
use App\Cadastro\PlanilhaDoCadastro;
use Illuminate\Http\Request;
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
 *   sem inscrição        lote sem quadra ou número — a inscrição não se monta;
 *   área diferente       a inscrição casou, mas a área do desenho não é a
 *                        "Área Terreno" do cadastro.
 *
 * ── A área: exata por padrão, com variação aceitável à escolha ──
 *
 * A metragem do desenho é comparada com a do cadastro, ao centésimo de m². O
 * padrão é NÃO tolerar diferença nenhuma (decisão de 10/10/2026); quem confere
 * pode informar uma variação aceitável, em m² ou em % da área do cadastro —
 * inclusive zero (11/10/2026). A variação usada fica guardada no resultado.
 *
 * A diferença NÃO impede a importação nem a publicação: entra na lista de
 * divergências, para ser corrigida ou justificada. E é só AQUI que ela
 * aparece: no resto do sistema a área do terreno de um lote com imóvel no
 * cadastro é sempre a do cadastro (App\Cadastro\AreaOficial).
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
     * A variação de área que esta conferência aceita. Nula = não foi pedida:
     * a conferência nova usa zero, e a revisão sem planilha, a da última.
     *
     * @var array{valor:float, unidade:'m2'|'pct'}|null
     */
    private ?array $tolerancia = null;

    /**
     * Define a variação aceitável de área para a próxima conferência.
     *
     * @param  float|null  $valor    quanto; null mantém o padrão
     * @param  string|null $unidade  'm2' (metros quadrados) ou 'pct' (% da área do cadastro)
     */
    public function comTolerancia(?float $valor, ?string $unidade): static
    {
        $this->tolerancia = $valor === null ? null
            : ['valor' => max(0.0, round($valor, 2)), 'unidade' => $unidade === 'pct' ? 'pct' : 'm2'];

        return $this;
    }

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
        // A variação aceitável: a pedida agora, ou a da conferência que está sendo revista.
        $this->tolerancia ??= $anterior['tolerancia_area'] ?? null;
        $bairros = new BairrosDoDesenho();
        $nomes = $anterior['nomes_do_desenho'] ?? [$bairro];
        $campos = ['id', 'bairro', 'quadra', 'numero_lote', 'desmembramento', 'inscricao_imobiliaria', 'inscricao_montada', 'importacao_id', 'area_gis_m2', 'terreno_id', 'fracao_ideal_m2'];
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

        // ÁREA DIFERENTE: a área do cadastro ficou guardada na divergência, então
        // dá para rever sem a planilha — o lote saiu, ou o desenho foi corrigido
        // e agora bate, e a pendência some; senão continua, com a área de agora.
        $areaDiferente = [];
        foreach ($anterior['area_diferente'] ?? [] as $item) {
            $atual = $porId[(int) ($item['lote_id'] ?? 0)] ?? null;
            if (! $atual) { $resolvidas++; continue; }
            $d = $this->areaDiferente($atual[1], $atual[0], $item['area_cadastro'] ?? null);
            if ($d === null) { $resolvidas++; } else { $areaDiferente[] = $d; }
        }

        // Lote da lista: saiu? continua igual? casou com um "sem lote"?
        $revisaLote = function (array $item, string $tipo) use (&$porId, &$semLote, &$resolvidas, &$casaramAgora, &$areaDiferente): ?array {
            $atual = $porId[(int) ($item['lote_id'] ?? 0)] ?? null;
            if (! $atual) { $resolvidas++; return null; }                  // excluído ou inativado
            [$insc, $l] = $atual;
            $antes = InscricaoImobiliaria::normalizar($item['inscricao'] ?? null);
            if ($insc !== null && isset($semLote[$insc])) {                 // achou o par no cadastro
                // Casou agora: a área do cadastro veio na pendência "sem lote".
                if ($d = $this->areaDiferente($l, $insc, $semLote[$insc]['area_m2'] ?? null)) { $areaDiferente[] = $d; }
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
            if (isset($comInscricao[$insc])) {
                if ($d = $this->areaDiferente($comInscricao[$insc], $insc, $item['area_m2'] ?? null)) { $areaDiferente[] = $d; }
                unset($semLote[$insc]); $resolvidas++; $casaramAgora++;
            }
        }
        $semLote = array_values($semLote);

        return [
            'nao_encontrados'   => $naoEncontrados,
            'inativos'          => $inativos,
            'sem_lote'          => $semLote,
            'sem_inscricao'     => $semInscricao,
            'area_diferente'    => $areaDiferente,
            'tolerancia_area'   => $this->tolerancia ?? ['valor' => 0.0, 'unidade' => 'm2'],
            'casaram'           => (int) ($anterior['casaram'] ?? 0) + $casaramAgora,
            'lotes_conferidos'  => $conferidos,
            'total_divergencias' => count($naoEncontrados) + count($inativos) + count($semLote) + count($semInscricao) + count($areaDiferente),
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

        $campos = ['id', 'bairro', 'quadra', 'numero_lote', 'desmembramento', 'inscricao_imobiliaria', 'inscricao_montada', 'importacao_id', 'area_gis_m2', 'terreno_id', 'fracao_ideal_m2'];

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
        $areaDiferente = [];
        $semAreaNoCadastro = 0;
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

            $ativo = \App\Cadastro\RetratoBci::isencaoAtiva($c['isencao']);

            if (isset($doArquivo[$insc])) {
                if ($ativo === false) {
                    $inativos[] = $this->lote($doArquivo[$insc], $insc) + ['isencao' => $c['isencao']];
                } else {
                    $casaram++;
                    // A inscrição bate: agora a METRAGEM, que tem de ser a mesma.
                    if ($c['area_terreno_m2'] === null) {
                        $semAreaNoCadastro++;
                    } elseif ($d = $this->areaDiferente($doArquivo[$insc], $insc, $c['area_terreno_m2'])) {
                        $areaDiferente[] = $d;
                    }
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
            'area_diferente'    => $areaDiferente,
            // A variação de área aceita nesta conferência (zero = exata).
            'tolerancia_area'   => $this->tolerancia ?? ['valor' => 0.0, 'unidade' => 'm2'],
            // Imóveis que casaram mas o cadastro não traz a área do terreno:
            // não deu para comparar — e zero diferenças, sem este número,
            // pareceria que todas as áreas batem.
            'sem_area_no_cadastro' => $semAreaNoCadastro,
            'total_divergencias' => count($naoEncontrados) + count($inativos) + count($semLote) + count($semInscricao) + count($areaDiferente),
            // Planilha sem coluna de situação: "inativo no cadastro" não pôde
            // ser conferido — e zero inativos, sem este aviso, pareceria certeza.
            'sem_situacao'      => property_exists($fonte, 'temSituacao') && $fonte->temSituacao === false,
            'conferido_por'     => auth()->user()?->name,
            'conferido_em'      => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * A divergência de ÁREA de um lote que casou pela inscrição, ou null se
     * as áreas batem (ou se o cadastro não informa a dele).
     *
     * "Bater" é a diferença, ao centésimo de m², caber na variação aceitável
     * desta conferência — que por padrão é zero (ver o cabeçalho da classe).
     *
     * @return array<string,mixed>|null
     */
    private function areaDiferente(object $l, ?string $inscricao, mixed $areaCadastro): ?array
    {
        if ($areaCadastro === null || $areaCadastro === '' || ! is_numeric($areaCadastro)) {
            return null;
        }
        $cadastro = round((float) $areaCadastro, 2);
        // A UNIDADE de fração ideal: o cadastro grava a fração do terreno na
        // "área do terreno" da inscrição — é ela que se compara, e não a área
        // exclusiva desenhada (que nem existe no apartamento).
        $desenho = ! empty($l->terreno_id)
            ? ($l->fracao_ideal_m2 !== null ? round((float) $l->fracao_ideal_m2, 2) : null)
            : ($l->area_gis_m2 !== null ? round((float) $l->area_gis_m2, 2) : null);
        if ($desenho === $cadastro) {
            return null;
        }
        // Dentro da variação aceitável (em m², ou em % da área do cadastro)?
        $t = $this->tolerancia ?? ['valor' => 0.0, 'unidade' => 'm2'];
        $aceita = $t['unidade'] === 'pct' ? $cadastro * $t['valor'] / 100 : $t['valor'];
        if ($desenho !== null && abs($desenho - $cadastro) <= round($aceita, 2) + 0.000001) {
            return null;
        }

        return $this->lote($l, $inscricao) + [
            'area_desenho'  => $desenho,
            'area_cadastro' => $cadastro,
            'diferenca'     => $desenho !== null ? round($desenho - $cadastro, 2) : null,
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
