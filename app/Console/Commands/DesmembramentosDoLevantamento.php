<?php

namespace App\Console\Commands;

use App\Cadastro\SequenciaDoDesmembramento;
use App\Models\Lote;
use App\Services\DesmembramentoDeLote;
use App\Services\LevantamentoDaImportacao;
use App\Services\UnificacaoDeLotes;
use App\Support\GeometriaPlana;
use App\Support\InscricaoImobiliaria;
use Illuminate\Console\Command;
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
 * É CORREÇÃO DA CARGA INICIAL (decisão de 04/11/2026): vale para bairro em
 * revisão e para bairro publicado, e entra SEM trilha de auditoria — nada no
 * Histórico do cadastro nem na pré-curadoria, sem curador nem justificativa,
 * porque não há como dizer quem fez nem por quê. O lote de antes fica inativo
 * (com a inscrição e o BCI dele), ligado ao novo pela sucessão.
 *
 * Duas passadas:
 *   1. simulação (padrão): não grava nada; escreve a planilha-relatório;
 *   2. `--aplicar`: aplica TODOS os confirmados, pelos serviços de
 *      desmembramento e unificação, sem protocolo. Cada caso, aplicado ou não,
 *      fica na aba "Levantamento" da ficha da importação do bairro
 *      (LevantamentoDaImportacao), de onde também se desfaz. Caso já aplicado
 *      ou desfeito não é aplicado de novo.
 */
class DesmembramentosDoLevantamento extends Command
{
    protected $signature = 'gis:desmembramentos-levantamento
                            {arquivo : GeoJSON do conversor (levantamento-2022.geojson)}
                            {--aplicar : aplica os casos que o cadastro confirma}
                            {--redesenhar : redesenha as partes dos desmembramentos JÁ aplicados pelas áreas do cadastro (com --aplicar grava; sem, só simula)}
                            {--planilha= : onde escrever a planilha-relatório (padrão: ao lado do arquivo)}
                            {--tolerancia=20 : diferença de área, em %, acima da qual o caso sai com aviso}';

    protected $description = 'Levantamento de 2022: desmembramentos e unificações confirmados pelo cadastro (simula ou aplica)';

    /** Vai na sucessão (lote_atos.observacao) e na fonte dos lotes novos. */
    public const ORIGEM = 'Levantamento cadastral de 2022';

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
        $guardados = LevantamentoDaImportacao::situacoes();

        [$desmembrados, $unificados] = $this->lerArquivo($arquivo);
        if ($this->option('redesenhar')) {
            return $this->redesenhar($desm, $desmembrados, $aplicar);
        }
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

        // Caso já aplicado ou desfeito numa rodada anterior: fica como está
        // (o desfeito foi decisão de quem conferiu; o aplicado já não acha a mãe).
        foreach ($casos as $k => $c) {
            if (in_array($guardados[$c['chave']] ?? null, LevantamentoDaImportacao::SITUACOES_FIXAS, true)) {
                $casos[$k] = ['situacao' => $guardados[$c['chave']], 'motivo' => 'Já tratado numa rodada anterior.'] + $c;
            }
        }
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
            $g = app(LevantamentoDaImportacao::class)->gravar(array_map(
                fn ($c) => array_filter($c, fn ($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY), $casos));
            $this->info("Os casos estão na aba \"Levantamento\" da ficha de importação de cada bairro ({$g['importacoes']} importações).");
            if ($g['sem_importacao']) {
                $this->warn("{$g['sem_importacao']} caso(s) de bairro sem importação pela tela: ficam só na planilha.");
            }
        } else {
            $this->line('Nada foi gravado. Para aplicar os confirmados: --aplicar');
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

        // O arquivo do conversor escreve "|04"; o banco, "|4" (NumeroDoLote). A
        // chave do CASO continua a do arquivo: é ela que reconhece o caso já
        // aplicado ou descartado.
        $ativos = Lote::query()->where('chave', \App\Support\NumeroDoLote::naChave($chave))->where('situacao', 'ativo')->get();
        if ($ativos->count() !== 1) {
            return $nao($ativos->isEmpty()
                ? 'O lote não está ativo no sistema (já mudou depois da base, ou foi desmembrado aqui).'
                : 'Mais de um lote ativo com esta chave.');
        }
        /** @var Lote $lote */
        $lote = $ativos->first();
        $c['lote_ids'] = [$lote->id];
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
        // O levantamento dá a direção e a ordem; o corte sai da área do cadastro.
        $faixas = $this->faixas($lote, $m['partes'], array_map(fn ($f) => $livres[$f] ?? null, $finais));
        if (isset($faixas['motivo'])) {
            return $nao($faixas['motivo']);
        }
        $partes = $this->partesParaOServico($faixas['aneis'], $finais);
        if ($erro = $svc->impedimento(null, $lote, $partes, false, direto: true)) {
            return $nao($erro);
        }

        $zero = in_array(0, $finais, true);
        foreach ($m['partes'] as $i => $p) {
            $insc = $identidade['prefixo'] . sprintf('%03d', $finais[$i]);
            $c['previsto'][] = InscricaoImobiliaria::apelidoDesmembrado($insc, $finais[$i], $zero) . ' (' . InscricaoImobiliaria::formatar($insc) . ')';
        }
        // As partes seguem a proporção do cadastro; se o LOTE importado não tem
        // a soma das áreas do cadastro, cada parte vai sair diferente dele.
        $somaCadastro = array_sum(array_map(fn ($f) => (float) ($livres[$f] ?? 0), $finais));
        $areaLote = (float) $lote->area_gis_m2;
        if ($somaCadastro > 0 && $areaLote > 0 && 100 * abs($areaLote - $somaCadastro) / $somaCadastro > (float) $this->option('tolerancia')) {
            $c['aviso'] = sprintf('O lote tem %s m² e as partes no cadastro somam %s m²: vai aparecer como divergência na conferência.',
                $this->num($areaLote), $this->num($somaCadastro));
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
            $a = Lote::query()->where('chave', \App\Support\NumeroDoLote::naChave($ch))->where('situacao', 'ativo')->get();
            if ($a->count() !== 1) {
                return $nao($a->isEmpty() ? "O lote {$ch} não está ativo no sistema (já mudou depois da base)." : "Mais de um lote ativo com a chave {$ch}.");
            }
            $lotes[] = $a->first();
        }
        $c['lote_ids'] = array_map(fn ($l) => $l->id, $lotes);
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

    /**
     * Aplica um caso confirmado, pelo serviço de desmembramento ou unificação,
     * na sua própria transação e SEM trilha de auditoria (ver o cabeçalho).
     */
    private function aplicarCaso(DesmembramentoDeLote $desm, UnificacaoDeLotes $unif, array $c): array
    {
        try {
            $novos = Lote::semTrilha(fn () => DB::transaction(function () use ($desm, $unif, $c) {
                $novos = $c['tipo'] === 'desmembrado'
                    ? $desm->aplicar(null, $c['_lote'], $c['_partes'], false, 'poligonos', self::ORIGEM)
                    : [$unif->aplicar(null, $c['_ids'], $c['_numero'], null, self::ORIGEM)];
                DB::table('lotes')->whereIn('id', array_map(fn (Lote $l) => $l->id, $novos))->update(['fonte' => self::ORIGEM]);

                return $novos;
            }));
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

    /**
     * --redesenhar: as partes dos desmembramentos JÁ aplicados, cortadas de
     * novo pelas áreas do cadastro (RecorteEmFaixas). Antes de 04/11/2026 o
     * corte seguia a linha do levantamento, que vem deslocada do lote
     * importado. Só mexe na parte que ninguém tocou depois (sem registro na
     * auditoria, ativa, sem outro ato), e sem trilha, como a aplicação.
     *
     * @param  array<string,array>  $maes  as do arquivo, por chave
     */
    private function redesenhar(DesmembramentoDeLote $desm, array $maes, bool $aplicar): int
    {
        $repo = app(\App\Repositories\LoteRepository::class);
        $contas = [];
        $linhas = [];
        foreach (\App\Models\ImportacaoLote::whereNotNull('levantamento')->where('status', '<>', 'excluida')->get() as $imp) {
            foreach ($imp->levantamento['itens'] ?? [] as $it) {
                if ($it['tipo'] !== 'desmembrado' || $it['situacao'] !== 'aplicado') continue;
                $res = $this->redesenharCaso($desm, $repo, $it, $maes[substr($it['chave'], strlen('desmembrado|'))] ?? null, $aplicar);
                $contas[$res[0]] = ($contas[$res[0]] ?? 0) + 1;
                if ($res[0] !== 'já certo') {
                    $linhas[] = [$it['bairro'], $it['quadra'], implode(' + ', $it['lotes']), $res[0], $res[1]];
                }
            }
        }
        if ($linhas) $this->table(['Bairro', 'Quadra', 'Lote', 'Situação', 'Detalhe'], $linhas);
        $this->table(['Situação', 'Casos'], array_map(fn ($k, $n) => [$k, $n], array_keys($contas), $contas));
        $this->line($aplicar ? 'Redesenho gravado, sem histórico.' : 'Nada foi gravado. Para gravar: --redesenhar --aplicar');

        return self::SUCCESS;
    }

    /** @return array{0:string, 1:string} situação e detalhe */
    private function redesenharCaso(DesmembramentoDeLote $desm, \App\Repositories\LoteRepository $repo, array $it, ?array $m, bool $aplicar): array
    {
        $ids = array_map('intval', array_column($it['novos'] ?? [], 'lote_id'));
        $mae = Lote::find((int) ($it['lote_ids'][0] ?? 0));
        if (! $m || ! $mae || count($ids) !== count($m['partes'])) {
            return ['pulado', 'O caso não está no arquivo ou não guardou os lotes.'];
        }
        $filhos = Lote::whereIn('id', $ids)->get()->keyBy('id');
        if ($filhos->count() !== count($ids) || $filhos->contains(fn ($l) => $l->situacao !== 'ativo')) {
            return ['pulado', 'Alguma parte já não está ativa.'];
        }
        if (DB::table('auditoria')->whereIn('registro_id', $ids)->whereIn('tabela', ['lotes', 'lotes_em_revisao'])->exists()) {
            return ['pulado', 'Alguma parte foi alterada depois do levantamento.'];
        }
        if (DB::table('lote_ato_lotes')->whereIn('lote_id', $ids)->where('ato_id', '<>', (int) $it['ato_id'])->exists()) {
            return ['pulado', 'Alguma parte entrou em outro desmembramento ou unificação.'];
        }

        // As áreas do cadastro de cada parte, pelo final da inscrição dela.
        $familia = $this->cadastro($desm->identidade($mae)['prefixo']);
        $prefixo = $desm->identidade($mae)['prefixo'];
        $areas = array_map(fn ($id) => $familia[$prefixo . sprintf('%03d', (int) $filhos[$id]->desmembramento)]['area'] ?? null, $ids);
        $anelMae = $repo->anel($mae->id);
        $r = \App\Cadastro\RecorteEmFaixas::dividir($anelMae ?? [], array_map(fn ($p) => $p['geometry']['coordinates'][0] ?? [], $m['partes']), $areas);
        if (isset($r['motivo'])) {
            return ['pulado', $r['motivo']];
        }

        $novos = [];
        foreach ($ids as $k => $id) {
            $gj = json_encode(['type' => 'Polygon', 'coordinates' => [$r['aneis'][$k]]]);
            if (! $repo->ehValido($gj)) return ['pulado', 'A divisão saiu com contorno inválido.'];
            $novos[$id] = [$gj, $repo->areaDoGeoJson($gj)];
        }
        $muda = collect($novos)->contains(fn ($n, $id) => abs($n[1] - (float) $filhos[$id]->area_gis_m2) > 0.5);
        if (! $muda) {
            return ['já certo', ''];
        }
        $antes = implode(' + ', array_map(fn ($id) => $this->num((float) $filhos[$id]->area_gis_m2), $ids));
        $depois = implode(' + ', array_map(fn ($n) => $this->num($n[1]), $novos));
        if ($aplicar) {
            DB::transaction(function () use ($repo, $novos, $it) {
                foreach ($novos as $id => [$gj]) {
                    $area = $repo->trocarGeometriaSemTrilha($id, $gj);
                    DB::table('lote_ato_lotes')->where('ato_id', (int) $it['ato_id'])->where('lote_id', $id)->update(['area_m2' => $area]);
                }
            });
        }

        return [$aplicar ? 'redesenhado' : 'a redesenhar', "{$antes} m² → {$depois} m²"];
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
     * As faixas já são a partição exata do lote (RecorteEmFaixas). Derivar de
     * novo aqui passaria pelo `ST_Difference` do MySQL em 4326, que neste
     * arquivo devolveu um fiapo de 0,00001 m² no lugar de um resto de 266 m² —
     * com as N partes, o serviço só mede, e mede no plano (GeometriaPlana).
     *
     * @param  list<list<array{0:float,1:float}>>  $aneis
     */
    private function partesParaOServico(array $aneis, array $sufixos): array
    {
        return array_map(fn ($anel, $i) => ['geometry' => ['type' => 'Polygon', 'coordinates' => [$anel]], 'desmembramento' => $sufixos[$i] ?? null],
            $aneis, array_keys($aneis));
    }

    /**
     * O lote do sistema dividido em faixas com as áreas do cadastro, na direção
     * das partes do levantamento (RecorteEmFaixas, decisão de 04/11/2026).
     *
     * @param  list<array<string,mixed>>  $partes  as do arquivo, com 'geometry'
     * @param  list<float|null>           $areas   a do cadastro de cada parte
     * @return array{aneis?: list<list<array{0:float,1:float}>>, motivo?: string}
     */
    private function faixas(Lote $lote, array $partes, array $areas): array
    {
        $anel = app(\App\Repositories\LoteRepository::class)->anel($lote->id);
        if (! $anel) {
            return ['motivo' => 'Não deu para ler o desenho do lote no sistema.'];
        }
        $r = \App\Cadastro\RecorteEmFaixas::dividir($anel, array_map(fn ($p) => $p['geometry']['coordinates'][0] ?? [], $partes), $areas);
        if (isset($r['aneis'])) {
            $repo = app(\App\Repositories\LoteRepository::class);
            foreach ($r['aneis'] as $a) {
                if (! $repo->ehValido(json_encode(['type' => 'Polygon', 'coordinates' => [$a]]))) {
                    return ['motivo' => 'A divisão em faixas saiu com contorno inválido: o corte tem de ser feito à mão.'];
                }
            }
        }

        return $r;
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
