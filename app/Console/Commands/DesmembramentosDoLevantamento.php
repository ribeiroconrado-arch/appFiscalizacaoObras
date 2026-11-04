<?php

namespace App\Console\Commands;

use App\Cadastro\SequenciaDoDesmembramento;
use App\Models\Lote;
use App\Models\User;
use App\Services\ConferenciaComCadastro;
use App\Services\DesmembramentoDeLote;
use App\Services\UnificacaoDeLotes;
use App\Support\GeometriaPlana;
use App\Support\InscricaoImobiliaria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Desmembramentos e unificações do levantamento de 2022 — USO ÚNICO.
 *
 * O arquivo vem do conversor (`tools/levantamento_desmembrados.py`): os lotes
 * do mapa que o levantamento mostra divididos em PARTES IGUAIS (±20 % da média)
 * e os grupos de lotes que ele mostra num polígono só. Do levantamento vem só
 * o QUE aconteceu: contorno, posição e área continuam os do lote importado —
 * as partes são o próprio lote cortado nas linhas do levantamento, e a
 * unificação é a união exata dos lotes do mapa (UnificacaoDeLotes).
 *
 * Quem decide é o CADASTRO da prefeitura (regra de 03/11/2026). Inscrição
 * inativa é imóvel alterado lá; lotes separados e ATIVOS são loteamento que o
 * desenho de 2022 ainda não tinha.
 *   desmembrado  a inscrição do lote está inativa, ou a família dela já tem
 *                2+ inscrições ativas. A numeração é a do sistema
 *                (App\Cadastro\SequenciaDoDesmembramento); parte sem inscrição
 *                ativa livre deixa o caso de fora;
 *   unificado    existe ativa a inscrição dos números juntados (0708 = 07 +
 *                08), ou as dos lotes estão inativas — todas (o número sai pela
 *                sugestão do sistema), ou todas menos uma, que é a do lote novo.
 *
 * Duas passadas:
 *   1. simulação (padrão): não grava nada; escreve a planilha-relatório;
 *   2. `--aplicar --usuario=...`: aplica TODOS os confirmados, pelos serviços
 *      da curadoria sem protocolo. Cada ato vai para o Histórico com o usuário e
 *      a justificativa, e desfazer é pela tela. Cada caso, aplicado ou não, fica
 *      na aba "Levantamento" da conferência do bairro
 *      (ConferenciaComCadastro::gravarLevantamento).
 */
class DesmembramentosDoLevantamento extends Command
{
    protected $signature = 'gis:desmembramentos-levantamento
                            {arquivo : GeoJSON do conversor (levantamento-2022.geojson)}
                            {--aplicar : aplica os casos que o cadastro confirma}
                            {--usuario= : e-mail do curador que assina os atos (obrigatório no --aplicar)}
                            {--planilha= : onde escrever a planilha-relatório (padrão: ao lado do arquivo)}
                            {--tolerancia=20 : diferença de área, em %, acima da qual o caso sai com aviso}';

    protected $description = 'Levantamento de 2022: desmembramentos e unificações confirmados pelo cadastro (simula ou aplica)';

    private const JUSTIFICATIVA = 'Levantamento cadastral de 2022, confirmado pelo cadastro da prefeitura.';

    /** IoU mínimo entre a mãe do arquivo e a da produção. */
    private const IOU_MESMA_MAE = 0.95;

    private const COLUNAS = ['tipo', 'bairro', 'quadra', 'lotes', 'inscricoes', 'cadastro', 'resultado', 'situacao', 'motivo', 'aviso'];

    public function handle(DesmembramentoDeLote $desm, UnificacaoDeLotes $unif): int
    {
        $arquivo = $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");
            return self::FAILURE;
        }
        $aplicar = (bool) $this->option('aplicar');
        if ($aplicar) {
            $usuario = User::where('email', $this->option('usuario'))->first();
            if (! $usuario || ! $usuario->podeCurarCadastro()) {
                $this->error('Informe --usuario= com o e-mail de um curador do cadastro ativo.');
                return self::FAILURE;
            }
            Auth::login($usuario);
        }

        [$desmembrados, $unificados] = $this->lerArquivo($arquivo);
        $this->info(sprintf('Arquivo: %d desmembrados, %d unificados.', count($desmembrados), count($unificados)));

        $casos = [];
        $barra = $this->output->createProgressBar(count($desmembrados) + count($unificados));
        foreach ($desmembrados as $chave => $m) {
            $barra->advance();
            $casos[] = $this->casoDesmembrado($desm, $chave, $m);
        }
        foreach ($unificados as $u) {
            $barra->advance();
            $casos[] = $this->casoUnificado($unif, $u);
        }
        $barra->finish();
        $this->newLine(2);

        if ($aplicar) {
            foreach ($casos as $k => $c) {
                if ($c['situacao'] === 'pronto') {
                    $casos[$k] = $this->aplicarCaso($desm, $unif, $c);
                }
            }
        }

        $planilha = $this->option('planilha') ?: preg_replace('/\.geojson$/i', '', $arquivo) . '-relatorio.csv';
        $this->gravarCsv($planilha, $casos);

        $resumo = [];
        foreach ($casos as $c) {
            $resumo[$c['tipo'] . '|' . $c['situacao']] = ($resumo[$c['tipo'] . '|' . $c['situacao']] ?? 0) + 1;
        }
        ksort($resumo);
        $this->table(['Tipo', 'Situação', 'Casos'],
            array_map(fn ($k, $n) => [...explode('|', $k), $n], array_keys($resumo), $resumo));
        $this->info("Relatório: {$planilha}");

        if ($aplicar) {
            $porBairro = [];
            foreach ($casos as $c) {
                $porBairro[$c['bairro']][] = array_filter($c, fn ($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY);
            }
            foreach ($porBairro as $bairro => $itens) {
                ConferenciaComCadastro::gravarLevantamento($bairro, $itens);
            }
            $this->info('Os casos estão na aba "Levantamento" da conferência de cada bairro (' . count($porBairro) . ' bairros).');
        } else {
            $this->line('Nada foi gravado. Para aplicar os confirmados: --aplicar --usuario=e-mail-do-curador');
        }

        return self::SUCCESS;
    }

    // ── os casos ─────────────────────────────────────────────────

    private function novoCaso(string $tipo, string $chave, string $bairro, string $quadra, array $lotes): array
    {
        return ['chave' => $tipo . '|' . $chave, 'tipo' => $tipo, 'bairro' => $bairro, 'quadra' => $quadra,
            'lotes' => $lotes, 'lote_ids' => [], 'inscricoes' => [], 'cadastro' => '', 'previsto' => [],
            'situacao' => 'nao_aplicado', 'motivo' => null, 'aviso' => null, 'novos' => [], 'ato_id' => null];
    }

    /**
     * Desmembrado: a mãe ainda é a da produção? O cadastro confirma? Cada parte
     * tem inscrição? Não grava nada.
     */
    private function casoDesmembrado(DesmembramentoDeLote $svc, string $chave, array $m): array
    {
        [$bairro, $quadra, $numero] = array_pad(explode('|', $chave), 3, '');
        $c = $this->novoCaso('desmembrado', $chave, $bairro, $quadra, [$numero]);
        $nao = function (string $motivo) use (&$c) { return ['motivo' => $motivo] + $c; };

        $ativos = Lote::query()->where('chave', $chave)->where('situacao', 'ativo')->get();
        if ($ativos->count() !== 1) {
            return $nao($ativos->isEmpty()
                ? 'O lote não está ativo no sistema (já mudou depois da base, ou foi desmembrado aqui).'
                : 'Mais de um lote ativo com esta chave.');
        }
        /** @var Lote $lote */
        $lote = $ativos->first();
        $c['lote_ids'] = [$lote->id];
        if ($lote->em_revisao) {
            return $nao('O bairro ainda está em revisão (não publicado).');
        }
        $iou = $this->iouComProducao($lote, $m['mae']);
        if ($iou === null || $iou < self::IOU_MESMA_MAE) {
            return $nao($iou === null ? 'Não deu para medir o lote do sistema contra o do arquivo.'
                : sprintf('O lote mudou no sistema depois da base (IoU %s).', $this->num($iou, 3)));
        }
        $inscricao = $lote->inscricao();
        if (! $inscricao) {
            return $nao('O lote não tem inscrição: o desmembramento precisa dela.');
        }
        $c['inscricoes'] = [InscricaoImobiliaria::formatar($inscricao)];

        // ── o cadastro confirma? ──
        $identidade = $svc->identidade($lote);
        $familia = $this->cadastro($identidade['prefixo']);
        $c['cadastro'] = $this->descrever($familia, 12);
        if (! $familia) {
            return $nao('O cadastro não tem este imóvel: não há como confirmar.');
        }
        $propria = $familia[$inscricao] ?? null;
        $ativas = array_filter($familia, fn ($x) => $x['ativa']);
        if (! (($propria && ! $propria['ativa']) || count($ativas) >= 2)) {
            return $nao('No cadastro o lote continua inteiro.');
        }

        // ── a numeração (SequenciaDoDesmembramento), entre as ativas livres ──
        $livres = [];
        foreach ($ativas as $insc => $x) {
            $final = (int) substr($insc, 12);
            if (! in_array($final, $identidade['usados'], true)) $livres[$final] = $x['area'];
        }
        $finais = SequenciaDoDesmembramento::atribuir(array_map(fn ($p) => (float) $p['area'], $m['partes']), $livres);
        if (in_array(null, $finais, true)) {
            return $nao(sprintf('O cadastro tem %d inscrição(ões) ativa(s) livre(s) para %d partes.', count($livres), count($m['partes'])));
        }
        $partes = $this->partesParaOServico($m['partes'], $finais);
        if ($erro = $svc->impedimento(null, $lote, $partes, false, direto: true)) {
            return $nao($erro);
        }

        $zero = in_array(0, $finais, true);
        $longe = [];
        foreach ($m['partes'] as $i => $p) {
            $insc = $identidade['prefixo'] . sprintf('%03d', $finais[$i]);
            $c['previsto'][] = InscricaoImobiliaria::apelidoDesmembrado($insc, $finais[$i], $zero) . ' (' . InscricaoImobiliaria::formatar($insc) . ')';
            $area = $livres[$finais[$i]];
            if ($area && 100 * abs($p['area'] - $area) / $area > (float) $this->option('tolerancia')) $longe[] = sprintf('.%03d', $finais[$i]);
        }
        if ($longe) {
            $c['aviso'] = 'Área longe da do cadastro em ' . implode(', ', $longe) . ': vai aparecer como divergência na conferência.';
        }

        return ['situacao' => 'pronto', '_lote' => $lote, '_partes' => $partes] + $c;
    }

    /** Unificado: os lotes ainda estão ativos? O cadastro confirma? Não grava nada. */
    private function casoUnificado(UnificacaoDeLotes $svc, array $u): array
    {
        $c = $this->novoCaso('unificado', implode('+', $u['chaves']), $u['bairro'], $u['quadra'], $u['lotes']);
        $nao = function (string $motivo) use (&$c) { return ['motivo' => $motivo] + $c; };

        $lotes = [];
        foreach ($u['chaves'] as $ch) {
            $a = Lote::query()->where('chave', $ch)->where('situacao', 'ativo')->get();
            if ($a->count() !== 1) {
                return $nao($a->isEmpty() ? "O lote {$ch} não está ativo no sistema (já mudou depois da base)." : "Mais de um lote ativo com a chave {$ch}.");
            }
            $lotes[] = $a->first();
        }
        $c['lote_ids'] = array_map(fn ($l) => $l->id, $lotes);
        if (collect($lotes)->contains(fn ($l) => $l->em_revisao)) {
            return $nao('O bairro ainda está em revisão (não publicado).');
        }
        $inscricoes = array_map(fn ($l) => $l->inscricao(), $lotes);
        if (in_array(null, $inscricoes, true)) {
            return $nao('Algum lote não tem inscrição: a unificação precisa dela.');
        }
        $c['inscricoes'] = array_map(fn ($i) => InscricaoImobiliaria::formatar($i), $inscricoes);
        $prefixos = array_unique(array_map(fn ($i) => substr($i, 0, 8), $inscricoes));
        if (count($prefixos) !== 1) {
            return $nao('As inscrições dos lotes são de quadras diferentes.');
        }

        // ── o cadastro confirma? ──
        $quadra = $this->cadastro(reset($prefixos));
        $numeros = array_map(fn ($i) => (int) substr($i, 8, 4), $inscricoes);
        $juntada = null;   // a inscrição dos números juntados: 0708 = 07 + 08
        foreach ($quadra as $insc => $x) {
            $n = substr($insc, 8, 4);
            [$a, $b] = [(int) substr($n, 0, 2), (int) substr($n, 2, 2)];
            if ($x['ativa'] && (int) substr($insc, 12) === 0 && $a !== $b && in_array($a, $numeros, true)
                && in_array($b, $numeros, true) && ! in_array((int) $n, $numeros, true)) {
                $juntada = $insc;
                break;
            }
        }
        $dos = array_intersect_key($quadra, array_flip($inscricoes));
        $c['cadastro'] = $this->descrever($dos + ($juntada ? [$juntada => $quadra[$juntada]] : []), 8);
        $ativas = array_keys(array_filter($dos, fn ($x) => $x['ativa']));
        $inativas = count($dos) - count($ativas);

        if ($juntada) {
            $numero = substr($juntada, 8, 4);
            $areaCadastro = $quadra[$juntada]['area'];
        } elseif ($inativas === count($lotes)) {
            $numero = '';   // a sugestão do sistema (o menor e o maior número)
            $areaCadastro = null;
        } elseif (count($ativas) === 1 && $inativas === count($lotes) - 1) {
            $numero = substr($ativas[0], 8, 4);
            $areaCadastro = $quadra[$ativas[0]]['area'];
        } else {
            return $nao(count($ativas) >= 2 ? 'No cadastro os lotes continuam separados e ativos.'
                : 'O cadastro não tem todos estes imóveis: não há como confirmar.');
        }
        $ids = $c['lote_ids'];
        if ($erro = $svc->impedimento(null, $ids, $numero, true)) {
            return $nao($erro);
        }
        $retrato = $svc->retrato($ids);
        $numeroNovo = $numero !== '' ? $numero : (string) $retrato['sugestao_lote'];
        $c['previsto'] = [$numeroNovo . ' (' . InscricaoImobiliaria::formatar(reset($prefixos) . str_pad($numeroNovo, 4, '0', STR_PAD_LEFT) . '000') . ')'];
        $soma = (float) $retrato['soma_area'];
        if ($areaCadastro && 100 * abs($soma - $areaCadastro) / $areaCadastro > (float) $this->option('tolerancia')) {
            $c['aviso'] = sprintf('Os lotes somam %s m² e o cadastro tem %s m²: vai aparecer como divergência na conferência.',
                $this->num($soma), $this->num($areaCadastro));
        }

        return ['situacao' => 'pronto', '_ids' => $ids, '_numero' => $numero] + $c;
    }

    /** Aplica um caso confirmado, pelo serviço da curadoria, na sua própria transação. */
    private function aplicarCaso(DesmembramentoDeLote $desm, UnificacaoDeLotes $unif, array $c): array
    {
        $justificativa = self::JUSTIFICATIVA . ' Arquivo: ' . basename($this->argument('arquivo')) . '.';
        try {
            $novos = DB::transaction(fn () => $c['tipo'] === 'desmembrado'
                ? $desm->aplicar(null, $c['_lote'], $c['_partes'], false, 'poligonos', $justificativa)
                : [$unif->aplicar(null, $c['_ids'], $c['_numero'], null, $justificativa)]);
        } catch (\Throwable $e) {
            return ['situacao' => 'nao_aplicado', 'motivo' => 'O serviço recusou: ' . ($e instanceof \Illuminate\Validation\ValidationException
                ? implode(' ', $e->validator->errors()->all()) : $e->getMessage())] + $c;
        }
        $c['situacao'] = 'aplicado';
        $c['novos'] = array_map(fn (Lote $l) => ['lote_id' => $l->id, 'lote' => $l->numero_lote,
            'inscricao' => InscricaoImobiliaria::formatar($l->inscricao())], array_values($novos));
        $c['ato_id'] = DB::table('lote_ato_lotes')->where('lote_id', $c['novos'][0]['lote_id'])
            ->where('papel', 'posterior')->value('ato_id');

        return $c;
    }

    // ── apoio ────────────────────────────────────────────────────

    /**
     * Lê o GeoJSON do conversor: as mães com as partes em ordem (a derivada é
     * a última), e os grupos unificados.
     *
     * @return array{0: array<string,array>, 1: list<array>}
     */
    private function lerArquivo(string $arquivo): array
    {
        $fc = json_decode(file_get_contents($arquivo), true);
        $maes = [];
        $unificados = [];
        foreach ($fc['features'] ?? [] as $f) {
            $p = $f['properties'] ?? [];
            $tipo = $p['tipo'] ?? '';
            if ($tipo === 'unificado' && count($p['chaves'] ?? []) >= 2) {
                $unificados[] = ['chaves' => $p['chaves'], 'bairro' => (string) $p['bairro'], 'quadra' => (string) $p['quadra'],
                    'lotes' => $p['lotes'] ?? []];
                continue;
            }
            $chave = $p['chave_mae'] ?? null;
            if (! $chave) continue;
            if ($tipo === 'mae') {
                $maes[$chave]['mae'] = $f['geometry'];
            } elseif ($tipo === 'parte') {
                $maes[$chave]['partes'][] = ['ordem' => (int) $p['ordem'], 'derivada' => (bool) ($p['derivada'] ?? false),
                    'area' => (float) $p['area_m2'], 'geometry' => $f['geometry']];
            }
        }
        foreach ($maes as $k => &$m) {
            if (empty($m['mae']) || count($m['partes'] ?? []) < 2) { unset($maes[$k]); continue; }
            usort($m['partes'], fn ($a, $b) => $a['ordem'] <=> $b['ordem']);
        }

        return [$maes, $unificados];
    }

    /**
     * O cadastro carregado sob um prefixo de inscrição (12 dígitos = a família
     * de um lote; 8 = a quadra).
     *
     * @return array<string, array{ativa:bool, area:?float}>  inscrição (15 dígitos) => situação
     */
    private function cadastro(string $prefixo): array
    {
        $saida = [];
        $linhas = DB::table('cadastro_externo_imoveis')
            ->whereRaw("REPLACE(REPLACE(REPLACE(inscricao, '.', ''), '-', ''), ' ', '') LIKE ?", [$prefixo . '%'])
            ->whereNull('ausente_desde_carga_id')
            ->get(['inscricao', 'isencao', 'area_terreno_m2']);
        foreach ($linhas as $l) {
            $n = InscricaoImobiliaria::normalizar($l->inscricao);
            if (! $n || ! str_starts_with($n, $prefixo) || isset($saida[$n])) continue;
            $saida[$n] = ['ativa' => \App\Cadastro\RetratoBci::isencaoAtiva($l->isencao) !== false,
                'area' => $l->area_terreno_m2 !== null ? (float) $l->area_terreno_m2 : null];
        }
        ksort($saida);

        return $saida;
    }

    /** ".000 inativa · .001 ativa 225,00 m²" — o que o cadastro diz, para a aba e a planilha. */
    private function descrever(array $itens, int $corte): string
    {
        if (! $itens) return 'sem registro no cadastro';

        return implode(' · ', array_map(fn ($insc, $x) => ($corte === 12 ? '.' . substr($insc, 12) : substr($insc, 8, 4) . '.' . substr($insc, 12))
            . ' ' . ($x['ativa'] ? 'ativa' : 'inativa') . ($x['area'] !== null ? ' ' . $this->num($x['area']) . ' m²' : ''),
            array_keys($itens), $itens));
    }

    /**
     * No formato do serviço, TODAS as partes desenhadas (sem derivar a última).
     *
     * O conversor já entrega a partição exata (a última parte é "mãe menos as
     * outras", feita no GEOS). Derivar de novo aqui passaria pelo
     * `ST_Difference` do MySQL em 4326, que neste arquivo devolveu um fiapo de
     * 0,00001 m² no lugar de um resto de 266 m² — com as N partes, o serviço
     * só mede, e mede no plano (GeometriaPlana).
     */
    private function partesParaOServico(array $partes, array $sufixos): array
    {
        return array_map(fn ($p, $i) => ['geometry' => $p['geometry'], 'desmembramento' => $sufixos[$i] ?? null],
            $partes, array_keys($partes));
    }

    /** IoU entre a mãe da produção e a do arquivo, medido no plano. */
    private function iouComProducao(Lote $lote, array $geometria): ?float
    {
        $anelProducao = app(\App\Repositories\LoteRepository::class)->anel($lote->id);
        $anelArquivo = $geometria['coordinates'][0] ?? null;
        if (! $anelProducao || ! $anelArquivo) return null;

        [$lon, $lat] = $anelProducao[0];
        $a = GeometriaPlana::projetar($anelProducao, $lat, $lon);
        $b = GeometriaPlana::projetar($anelArquivo, $lat, $lon);
        $comum = GeometriaPlana::areaComum($a, $b);
        if (! $comum['confiavel']) {
            // Nenhum dos dois é convexo: aceita só se os vértices são os mesmos.
            return $this->mesmosVertices($anelProducao, $anelArquivo) ? 1.0 : null;
        }
        $uniao = GeometriaPlana::area($a) + GeometriaPlana::area($b) - $comum['area'];
        return $uniao > 0 ? $comum['area'] / $uniao : null;
    }

    private function mesmosVertices(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        foreach ($a as $i => $p) {
            if (abs($p[0] - $b[$i][0]) > 1e-7 || abs($p[1] - $b[$i][1]) > 1e-7) return false;
        }
        return true;
    }

    private function num(float $v, int $casas = 2): string
    {
        return number_format($v, $casas, ',', '.');
    }

    /** A planilha-relatório, para o Excel brasileiro: `;`, UTF-8 com BOM, uma linha por caso. */
    private function gravarCsv(string $caminho, array $casos): void
    {
        $f = fopen($caminho, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, self::COLUNAS, ';');
        foreach ($casos as $c) {
            $resultado = $c['novos'] ? array_map(fn ($n) => $n['lote'] . ' (' . $n['inscricao'] . ')', $c['novos']) : $c['previsto'];
            fputcsv($f, [$c['tipo'], $c['bairro'], $c['quadra'], implode(' + ', $c['lotes']), implode(' + ', $c['inscricoes']),
                $c['cadastro'], implode(' · ', $resultado), $c['situacao'], $c['motivo'] ?? '', $c['aviso'] ?? ''], ';');
        }
        fclose($f);
    }
}
