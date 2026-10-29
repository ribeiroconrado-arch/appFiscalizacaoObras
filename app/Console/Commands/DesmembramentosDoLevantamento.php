<?php

namespace App\Console\Commands;

use App\Models\Lote;
use App\Models\User;
use App\Services\DesmembramentoDeLote;
use App\Support\GeometriaPlana;
use App\Support\InscricaoImobiliaria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Aplica os desmembramentos achados no levantamento de 2022 — USO ÚNICO.
 *
 * O arquivo vem do conversor (`tools/levantamento_desmembrados.py`): para cada
 * lote-mãe, as partes já recortadas pelo contorno dele. Este comando não
 * decide nada sozinho; são duas passadas:
 *
 *   1. simulação (padrão): confere cada mãe contra a produção, numera as
 *      partes pelo cadastro da prefeitura e escreve uma planilha de revisão;
 *   2. `--aplicar --planilha=...`: desmembra só as mães com TODAS as partes
 *      marcadas `aplicar = sim` na planilha revisada.
 *
 * O desmembramento é o direto da curadoria (DesmembramentoDeLote, sem
 * protocolo): a mãe fica inativa, as partes nascem com `origem =
 * desmembramento`, e o ato vai para o Histórico com o usuário e a
 * justificativa — desfazer é pela tela, como qualquer outro.
 *
 * Numeração pelo cadastro: as candidatas são as inscrições ATIVAS do cadastro
 * carregado com os mesmos 12 primeiros dígitos da mãe e sufixo ainda livre.
 * Cada parte fica com a de "Área Terreno" mais próxima, uma inscrição por
 * parte, dentro da tolerância; empate ou nada perto deixa em branco — melhor
 * em branco na planilha do que lote com a inscrição do vizinho.
 */
class DesmembramentosDoLevantamento extends Command
{
    protected $signature = 'gis:desmembramentos-levantamento
                            {arquivo : GeoJSON do conversor (desmembramentos-2022.geojson)}
                            {--planilha= : CSV de revisão (gerado na simulação; lido no --aplicar)}
                            {--aplicar : desmembra as mães marcadas "sim" na planilha}
                            {--usuario= : e-mail do curador que assina o ato (obrigatório no --aplicar)}
                            {--tolerancia=10 : diferença máxima de área, em %, para sugerir a inscrição}
                            {--max-partes=4 : acima disso a mãe sai como "não" na planilha (quadra inteira gravada como lote?)}';

    protected $description = 'Desmembramentos do levantamento de 2022: simula (planilha) ou aplica';

    private const JUSTIFICATIVA = 'Levantamento cadastral de 2022: lote já desmembrado no levantamento e inteiro no mapa.';

    /** IoU mínimo entre a mãe do arquivo e a da produção. */
    private const IOU_MESMA_MAE = 0.95;

    private const COLUNAS = ['chave_mae', 'lote_id', 'inscricao_mae', 'ordem', 'derivada', 'area_desenho_m2',
        'inscricao_sugerida', 'area_cadastro_m2', 'diferenca_pct', 'numero_resultante', 'situacao',
        'observacao', 'aplicar'];

    public function handle(DesmembramentoDeLote $svc): int
    {
        $arquivo = $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");
            return self::FAILURE;
        }
        $maes = $this->lerArquivo($arquivo);
        $this->info(sprintf('Arquivo: %d lotes-mãe, %d partes.', count($maes),
            array_sum(array_map(fn ($m) => count($m['partes']), $maes))));

        return $this->option('aplicar') ? $this->aplicar($svc, $maes) : $this->simular($svc, $maes);
    }

    // ── simulação ────────────────────────────────────────────────

    private function simular(DesmembramentoDeLote $svc, array $maes): int
    {
        $planilha = $this->option('planilha') ?: preg_replace('/\.geojson$/i', '', $this->argument('arquivo')) . '-revisao.csv';
        $tolerancia = (float) $this->option('tolerancia');
        $maxPartes = (int) $this->option('max-partes');

        $linhas = [];
        $resumo = [];
        $barra = $this->output->createProgressBar(count($maes));
        foreach ($maes as $chave => $m) {
            $barra->advance();
            $r = $this->conferir($svc, $chave, $m, $tolerancia);
            $situacao = $r['situacao'];
            $obs = $r['observacao'];
            if ($situacao === 'pronta' && count($m['partes']) > $maxPartes) {
                $situacao = 'conferir';
                $obs = count($m['partes']) . ' partes: a mãe pode ser uma quadra inteira gravada como lote.';
            }
            $resumo[$situacao] = ($resumo[$situacao] ?? 0) + 1;
            foreach ($m['partes'] as $i => $p) {
                $s = $r['inscricoes'][$i] ?? null;
                $linhas[] = [
                    'chave_mae'          => $chave,
                    'lote_id'            => $r['lote']?->id,
                    'inscricao_mae'      => InscricaoImobiliaria::formatar($r['lote']?->inscricao()),
                    'ordem'              => $p['ordem'],
                    'derivada'           => $p['derivada'] ? 'sim' : 'não',
                    'area_desenho_m2'    => $this->num($p['area']),
                    'inscricao_sugerida' => $s ? InscricaoImobiliaria::formatar($s['inscricao']) : '',
                    'area_cadastro_m2'   => $s && $s['area'] !== null ? $this->num($s['area']) : '',
                    'diferenca_pct'      => $s && $s['area'] ? $this->num(100 * ($p['area'] - $s['area']) / $s['area'], 1) : '',
                    'numero_resultante'  => $s ? InscricaoImobiliaria::apelidoDesmembrado($s['inscricao'], (int) substr($s['inscricao'], 12)) : '',
                    'situacao'           => $situacao,
                    'observacao'         => $obs,
                    'aplicar'            => $situacao === 'pronta' ? 'sim' : 'não',
                ];
            }
        }
        $barra->finish();
        $this->newLine(2);

        $this->gravarCsv($planilha, $linhas);
        ksort($resumo);
        $this->table(['Situação', 'Lotes-mãe'], array_map(fn ($k, $v) => [$k, $v], array_keys($resumo), $resumo));
        $this->info("Planilha: {$planilha}");
        $this->line('Revise a coluna "aplicar" (sim/não) e a "inscricao_sugerida"; depois rode com --aplicar --planilha=... --usuario=...');

        return self::SUCCESS;
    }

    /**
     * A mãe ainda é a mesma da produção? E qual inscrição do cadastro cada
     * parte recebe? Não grava nada.
     *
     * @return array{lote:?Lote, situacao:string, observacao:string, inscricoes:array<int,?array>}
     */
    private function conferir(DesmembramentoDeLote $svc, string $chave, array $m, float $tolerancia): array
    {
        $saida = fn ($situacao, $obs, $lote = null, $insc = []) =>
            ['lote' => $lote, 'situacao' => $situacao, 'observacao' => $obs, 'inscricoes' => $insc];

        $candidatos = Lote::query()->where('chave', $chave)->get();
        $ativos = $candidatos->where('situacao', 'ativo');
        if ($ativos->isEmpty()) {
            return $saida('desatualizada', $candidatos->isEmpty()
                ? 'Lote não encontrado no sistema por esta chave.'
                : 'O lote já foi inativado (desmembrado ou unificado depois da base).');
        }
        if ($ativos->count() > 1) {
            return $saida('desatualizada', 'Mais de um lote ativo com esta chave.');
        }
        /** @var Lote $lote */
        $lote = $ativos->first();
        if ($lote->em_revisao) {
            return $saida('desatualizada', 'O bairro ainda está em revisão (não publicado).', $lote);
        }

        $iou = $this->iouComProducao($lote, $m['mae']);
        if ($iou === null || $iou < self::IOU_MESMA_MAE) {
            return $saida('desatualizada', $iou === null
                ? 'Não deu para medir a mãe da produção contra a do arquivo.'
                : sprintf('A mãe mudou no sistema depois da base (IoU %s).', $this->num($iou, 3)), $lote);
        }

        $inscricaoMae = $lote->inscricao();
        if (! $inscricaoMae) {
            return $saida('sem_inscricao', 'A mãe não tem inscrição: o desmembramento precisa dela.', $lote);
        }

        [$inscricoes, $empates] = $this->numerarPeloCadastro($svc, $lote, $m['partes'], $tolerancia);
        $faltam = count(array_filter($m['partes'], fn ($p, $i) => empty($inscricoes[$i]), ARRAY_FILTER_USE_BOTH));
        if ($faltam) {
            return $saida('sem_numero', $empates
                ? "{$faltam} parte(s) sem inscrição: áreas iguais no cadastro ({$empates}). Confira pelo endereço e preencha."
                : "{$faltam} parte(s) sem inscrição do cadastro dentro de {$this->num($tolerancia, 0)} %.",
                $lote, $inscricoes);
        }

        $partes = $this->partesParaOServico($m['partes'], array_map(fn ($s) => (int) substr($s['inscricao'], 12), $inscricoes));
        if ($erro = $svc->impedimento(null, $lote, $partes, false, direto: true)) {
            return $saida('impedida', $erro, $lote, $inscricoes);
        }

        return $saida('pronta', '', $lote, $inscricoes);
    }

    /**
     * Inscrições ativas do cadastro na família da mãe, casadas parte a parte
     * pela área. Atribuição única pela menor diferença.
     *
     * @return array{0: array<int,array{inscricao:string, area:?float}|null>, 1: string}
     *         as inscrições por parte, e as candidatas que empataram (formatadas)
     */
    private function numerarPeloCadastro(DesmembramentoDeLote $svc, Lote $lote, array $partes, float $tolerancia): array
    {
        $identidade = $svc->identidade($lote);
        $prefixo = $identidade['prefixo'];
        $usados = $identidade['usados'];
        $propria = (int) substr($lote->inscricao(), 12);

        $cadastro = [];
        $linhas = DB::table('cadastro_externo_imoveis')
            ->whereRaw("REPLACE(REPLACE(REPLACE(inscricao, '.', ''), '-', ''), ' ', '') LIKE ?", [$prefixo . '%'])
            ->whereNull('ausente_desde_carga_id')
            ->get(['inscricao', 'isencao', 'area_terreno_m2']);
        foreach ($linhas as $l) {
            $n = InscricaoImobiliaria::normalizar($l->inscricao);
            if (! $n || isset($cadastro[$n])) continue;
            if (\App\Cadastro\RetratoBci::isencaoAtiva($l->isencao) === false) continue;
            $sufixo = (int) substr($n, 12);
            if ($sufixo === $propria || in_array($sufixo, $usados, true)) continue;
            $cadastro[$n] = $l->area_terreno_m2 !== null ? (float) $l->area_terreno_m2 : null;
        }

        // todos os pares, do mais parecido ao menos
        $pares = [];
        foreach ($partes as $i => $p) {
            foreach ($cadastro as $insc => $area) {
                if (! $area) continue;
                $pct = 100 * abs($p['area'] - $area) / $area;
                if ($pct <= $tolerancia) $pares[] = [$pct, $i, $insc];
            }
        }
        usort($pares, fn ($a, $b) => $a[0] <=> $b[0]);

        $resultado = array_fill_keys(array_keys($partes), null);
        $tomadas = [];
        $empatadas = [];
        foreach ($pares as $k => [$pct, $i, $insc]) {
            if ($resultado[$i] !== null || isset($tomadas[$insc])) continue;
            // Empate: outra inscrição livre com a mesma diferença para esta
            // parte (lotes iguais lado a lado). Escolher seria chutar.
            $empate = false;
            foreach ($pares as $j => [$pct2, $i2, $insc2]) {
                if ($j !== $k && $i2 === $i && ! isset($tomadas[$insc2]) && $insc2 !== $insc && abs($pct2 - $pct) < 0.05) {
                    $empate = true;
                    $empatadas[$insc] = $empatadas[$insc2] = true;
                    break;
                }
            }
            if ($empate) continue;
            $resultado[$i] = ['inscricao' => $insc, 'area' => $cadastro[$insc]];
            $tomadas[$insc] = true;
        }

        $empatadas = array_diff_key($empatadas, $tomadas);
        return [$resultado, implode(', ', array_map(fn ($n) => InscricaoImobiliaria::formatar($n), array_keys($empatadas)))];
    }

    // ── aplicação ────────────────────────────────────────────────

    private function aplicar(DesmembramentoDeLote $svc, array $maes): int
    {
        $planilha = $this->option('planilha');
        if (! $planilha || ! is_file($planilha)) {
            $this->error('Informe a planilha revisada: --planilha=caminho.csv');
            return self::FAILURE;
        }
        $usuario = User::where('email', $this->option('usuario'))->first();
        if (! $usuario || ! $usuario->podeCurarCadastro()) {
            $this->error('Informe --usuario= com o e-mail de um curador do cadastro ativo.');
            return self::FAILURE;
        }
        Auth::login($usuario);

        $porMae = [];
        foreach ($this->lerCsv($planilha) as $l) {
            $porMae[$l['chave_mae']][(int) $l['ordem']] = $l;
        }

        $feitos = $pulados = 0;
        $falhas = [];
        foreach ($porMae as $chave => $linhas) {
            $sim = array_filter($linhas, fn ($l) => $this->ehSim($l['aplicar'] ?? ''));
            if (! $sim) { $pulados++; continue; }
            if (count($sim) !== count($linhas)) {
                $falhas[] = [$chave, 'Só parte das partes marcada "sim": marque todas ou nenhuma.'];
                continue;
            }
            if (! isset($maes[$chave])) {
                $falhas[] = [$chave, 'A mãe não está no arquivo GeoJSON.'];
                continue;
            }
            $m = $maes[$chave];
            $lote = Lote::find((int) reset($linhas)['lote_id']);
            if (! $lote || $lote->chave !== $chave) {
                $falhas[] = [$chave, 'O lote_id da planilha não é mais deste lote.'];
                continue;
            }

            $sufixos = [];
            foreach ($m['partes'] as $i => $p) {
                $insc = InscricaoImobiliaria::normalizar($linhas[$p['ordem']]['inscricao_sugerida'] ?? null);
                if (! $insc || substr($insc, 0, 12) !== substr($lote->inscricao() ?? '', 0, 12)) {
                    $falhas[] = [$chave, "Parte {$p['ordem']}: inscrição vazia ou de outro lote."];
                    continue 2;
                }
                $sufixos[$i] = (int) substr($insc, 12);
            }

            $partes = $this->partesParaOServico($m['partes'], $sufixos);
            try {
                $novos = $svc->aplicar(null, $lote, $partes, false, 'poligonos',
                    self::JUSTIFICATIVA . ' Arquivo: ' . basename($this->argument('arquivo')) . '.');
                $feitos++;
                $this->line(sprintf('  %s → %s', $chave, implode(', ', array_map(fn ($l) => $l->numero_lote, $novos))));
            } catch (\Throwable $e) {
                $falhas[] = [$chave, $e instanceof \Illuminate\Validation\ValidationException
                    ? implode(' ', $e->validator->errors()->all()) : $e->getMessage()];
            }
        }

        $this->newLine();
        $this->info("Desmembrados: {$feitos}. Não marcados: {$pulados}. Com problema: " . count($falhas) . '.');
        if ($falhas) {
            $this->table(['Chave', 'Problema'], $falhas);
        }

        return $falhas ? self::FAILURE : self::SUCCESS;
    }

    // ── apoio ────────────────────────────────────────────────────

    /**
     * Lê o GeoJSON do conversor: mãe + partes em ordem (a derivada é a última).
     *
     * @return array<string,array{mae:array, partes:list<array{ordem:int, derivada:bool, area:float, geometry:array}>}>
     */
    private function lerArquivo(string $arquivo): array
    {
        $fc = json_decode(file_get_contents($arquivo), true);
        $maes = [];
        foreach ($fc['features'] ?? [] as $f) {
            $p = $f['properties'] ?? [];
            $chave = $p['chave_mae'] ?? null;
            if (! $chave) continue;
            if (($p['tipo'] ?? '') === 'mae') {
                $maes[$chave]['mae'] = $f['geometry'];
            } elseif (($p['tipo'] ?? '') === 'parte') {
                $maes[$chave]['partes'][] = ['ordem' => (int) $p['ordem'], 'derivada' => (bool) ($p['derivada'] ?? false),
                    'area' => (float) $p['area_m2'], 'geometry' => $f['geometry']];
            }
        }
        foreach ($maes as $k => &$m) {
            if (empty($m['mae']) || count($m['partes'] ?? []) < 2) { unset($maes[$k]); continue; }
            usort($m['partes'], fn ($a, $b) => $a['ordem'] <=> $b['ordem']);
        }
        return $maes;
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
        return number_format($v, $casas, ',', '');
    }

    private function ehSim(string $v): bool
    {
        return in_array(mb_strtolower(trim($v)), ['sim', 's', 'x', '1'], true);
    }

    /** CSV para o Excel brasileiro: `;`, UTF-8 com BOM. */
    private function gravarCsv(string $caminho, array $linhas): void
    {
        $f = fopen($caminho, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, self::COLUNAS, ';');
        foreach ($linhas as $l) {
            fputcsv($f, array_map(fn ($c) => $l[$c] ?? '', self::COLUNAS), ';');
        }
        fclose($f);
    }

    /** Lê a planilha devolvida pelo Excel (`;` ou `,`, com ou sem BOM). */
    private function lerCsv(string $caminho): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($caminho));
        $sep = substr_count(strtok($texto, "\n"), ';') >= substr_count(strtok($texto, "\n"), ',') ? ';' : ',';
        $f = fopen('php://memory', 'r+');
        fwrite($f, $texto);
        rewind($f);
        $cab = array_map('trim', fgetcsv($f, null, $sep));
        $saida = [];
        while (($l = fgetcsv($f, null, $sep)) !== false) {
            if (count($l) === count($cab)) $saida[] = array_combine($cab, $l);
        }
        fclose($f);
        return $saida;
    }
}
