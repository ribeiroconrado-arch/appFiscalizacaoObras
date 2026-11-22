<?php

namespace App\Cadastro;

use App\Models\ImportacaoLote;
use App\Models\Lote;
use App\Services\DesmembramentoDeLote;
use App\Services\EdicaoDeLote;
use App\Services\FracaoIdealDeLote;
use App\Services\UnificacaoDeLotes;
use App\Support\GeometriaPlana;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REVISÃO DE PARTE DE UM BAIRRO QUE JÁ EXISTE (21/11/2026).
 *
 * Diferente da etapa, que só acrescenta lotes, a revisão traz de novo parte
 * do bairro, corrigida: desenho acertado, lote desmembrado ou unificado,
 * número trocado. Esta classe compara o arquivo com o mapa e diz o que cada
 * lote do arquivo é, e o que muda no mapa:
 *
 *   igual        mesma quadra e número, mesmo desenho (IoU ≥ 90%): nada muda;
 *   desenho      o mesmo lote (cada um cobre ≥ 80% do outro), contorno novo;
 *   numero       o mesmo lote, com outra quadra ou número (a inscrição acompanha);
 *   desmembrado  um lote do mapa que o arquivo traz em 2 ou mais partes;
 *   unificado    2 ou mais lotes do mapa que o arquivo traz como um só;
 *   novo         não cobre nenhum lote: entra, como na etapa;
 *   duvidoso     sobreposição parcial, sem leitura segura: entra SEM número;
 *   sumiu        lote do mapa, numa quadra do arquivo, que o arquivo não traz.
 *
 * Só as quadras do arquivo são tocadas: o resto do bairro fica como está.
 *
 * NADA muda no mapa antes da publicação. As mudanças ficam guardadas na
 * importação e, ao publicar, cada uma passa pela ferramenta de sempre
 * (EdicaoDeLote, DesmembramentoDeLote, UnificacaoDeLotes), com as provas e a
 * trilha dela, uma a uma: a que falhar fica relatada, e as outras seguem.
 */
final class RevisaoDoBairro
{
    public const MESMO_DESENHO = 0.90;

    /** O "mesmo lote": cada um cobre ao menos isto do outro. */
    public const MESMO_LOTE = 0.80;

    /** Parte de um desmembramento (ou lote de uma unificação): dentro do outro ao menos isto. */
    public const DENTRO = 0.90;

    public const SOBREPOE = 0.10;

    /** Grupos que entram marcados; "sumiu" só se a pessoa marcar. */
    public const GRUPOS_PADRAO = ['desenho', 'numero', 'desmembrado', 'unificado', 'novo'];

    public const GRUPOS = ['desenho', 'numero', 'desmembrado', 'unificado', 'novo', 'sumiu'];

    private const TETO_DESENHO = 2500;

    /**
     * @param  list<array<string,mixed>>  $linhas  as de ImportacaoDeBairro::lerArquivo, já com o bairro
     * @param  list<string>               $grupos  os grupos marcados na tela
     * @return array<string,mixed>
     */
    public function classificar(array $linhas, string $bairro, array $grupos): array
    {
        $contagem = array_fill_keys(['igual', 'desenho', 'numero', 'desmembrado', 'unificado', 'novo', 'duvidoso', 'sumiu'], 0);
        $aneis = array_map(fn ($l) => json_decode($l['geojson'], true)['coordinates'][0] ?? [], $linhas);
        $todos = array_merge([], ...$aneis);
        $vazio = ['tipos' => array_fill(0, count($linhas), 'novo'), 'contagem' => $contagem, 'operacoes' => [],
            'duvidosos' => [], 'quadras' => [], 'desenho' => null, 'avisos' => ['vinculos' => 0, 'bloqueados' => 0]];
        if (! $todos) {
            return $vazio;
        }

        $lons = array_column($todos, 0);
        $lats = array_column($todos, 1);
        [$x0, $x1, $y0, $y1] = [min($lons), max($lons), min($lats), max($lats)];
        $latRef = ($y0 + $y1) / 2;
        $lonRef = ($x0 + $x1) / 2;
        $folga = 0.0005;
        $env = sprintf('POLYGON((%1$.7f %3$.7f,%2$.7f %3$.7f,%2$.7f %4$.7f,%1$.7f %4$.7f,%1$.7f %3$.7f))',
            $x0 - $folga, $x1 + $folga, $y0 - $folga, $y1 + $folga);

        // As quadras do arquivo: é o que a revisão cobre.
        $quadrasDoArquivo = [];
        foreach ($linhas as $l) {
            if ($l['quadra'] !== null) {
                $quadrasDoArquivo[(string) $l['quadra']] = true;
            }
        }

        // Os lotes ativos na área do arquivo, de qualquer bairro. As unidades
        // de fração ideal ficam de fora: estão DENTRO do terreno e confundiriam
        // a leitura; o terreno, se mudar, fica bloqueado (ver impedimento).
        $existentes = DB::select("SELECT id, bairro, quadra, numero_lote, area_gis_m2, ST_AsGeoJSON(geom, 8) AS g FROM lotes
                                   WHERE situacao = 'ativo' AND em_revisao = 0 AND terreno_id IS NULL
                                     AND MBRIntersects(geom, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))", [$env]);
        $proj = fn (array $anel) => GeometriaPlana::projetar($anel, $latRef, $lonRef);
        $caixa = function (array $p) {
            $xs = array_column($p, 0);
            $ys = array_column($p, 1);

            return [min($xs), max($xs), min($ys), max($ys)];
        };

        $E = [];
        $porChave = [];
        foreach ($existentes as $e) {
            $g = json_decode($e->g, true);
            $anel = ($g['type'] ?? '') === 'MultiPolygon' ? ($g['coordinates'][0][0] ?? []) : ($g['coordinates'][0] ?? []);
            if (count($anel) < 4) {
                continue;
            }
            $p = $proj($anel);
            $E[] = ['id' => (int) $e->id, 'bairro' => $e->bairro, 'quadra' => $e->quadra, 'lote' => $e->numero_lote,
                'area' => (float) $e->area_gis_m2, 'p' => $p, 'a' => GeometriaPlana::area($p), 'cx' => $caixa($p), 'anel' => $anel,
                'meu' => $e->bairro === $bairro];
            if ($e->bairro === $bairro && $e->quadra !== null && $e->numero_lote !== null) {
                $porChave[$e->quadra . '|' . $e->numero_lote] = count($E) - 1;
            }
        }

        $F = [];
        foreach ($linhas as $i => $l) {
            $p = count($aneis[$i]) >= 4 ? $proj($aneis[$i]) : [];
            $F[$i] = ['p' => $p, 'a' => $p ? GeometriaPlana::area($p) : 0.0, 'cx' => $p ? $caixa($p) : [0, 0, 0, 0],
                'chave' => $l['quadra'] !== null && $l['numero_lote'] !== null ? $l['quadra'] . '|' . $l['numero_lote'] : null];
        }

        // A área comum de cada par que se toca.
        $comum = [];   // [i][j] => m²
        $doE = [];     // [j][i] => m²
        foreach ($F as $i => $f) {
            if (! $f['p']) {
                continue;
            }
            foreach ($E as $j => $e) {
                if ($f['cx'][1] < $e['cx'][0] || $e['cx'][1] < $f['cx'][0] || $f['cx'][3] < $e['cx'][2] || $e['cx'][3] < $f['cx'][2]) {
                    continue;
                }
                $c = GeometriaPlana::areaComum($f['p'], $e['p'])['area'];
                if ($c > 0.01) {
                    $comum[$i][$j] = $c;
                    $doE[$j][$i] = $c;
                }
            }
        }
        $iou = fn (int $i, int $j) => ($c = $comum[$i][$j] ?? 0) > 0 ? $c / max(0.01, $F[$i]['a'] + $E[$j]['a'] - $c) : 0.0;

        $tipos = array_fill(0, count($linhas), null);
        $usadoE = [];
        $ops = [];
        $duvidosos = [];

        // 1. Igual: mesma chave, mesmo desenho.
        foreach ($F as $i => $f) {
            $j = $f['chave'] !== null ? ($porChave[$f['chave']] ?? null) : null;
            if ($j !== null && $iou($i, $j) >= self::MESMO_DESENHO) {
                $tipos[$i] = 'igual';
                $usadoE[$j] = true;
            }
        }

        // 2. O mesmo lote, com desenho e/ou número novos.
        foreach ($F as $i => $f) {
            if ($tipos[$i] !== null) {
                continue;
            }
            $melhor = null;
            foreach ($comum[$i] ?? [] as $j => $c) {
                if (isset($usadoE[$j]) || ! $E[$j]['meu']) {
                    continue;
                }
                if ($c / max(0.01, $f['a']) >= self::MESMO_LOTE && $c / max(0.01, $E[$j]['a']) >= self::MESMO_LOTE
                    && (! $melhor || $c > $melhor[1])) {
                    $melhor = [$j, $c];
                }
            }
            if (! $melhor) {
                continue;
            }
            $j = $melhor[0];
            $l = $linhas[$i];
            $mudaNumero = (string) $l['quadra'] !== (string) $E[$j]['quadra'] || (string) $l['numero_lote'] !== (string) $E[$j]['lote'];
            if ($mudaNumero && ($l['quadra'] === null || $l['numero_lote'] === null)) {
                continue;   // sem número no arquivo: não há para onde renumerar — fica duvidoso
            }
            $tipo = $mudaNumero ? 'numero' : 'desenho';
            $tipos[$i] = $tipo;
            $usadoE[$j] = true;
            $ops[] = ['tipo' => 'editar', 'grupo' => $tipo, 'lote_id' => $E[$j]['id'],
                'quadra' => $E[$j]['quadra'], 'lote' => $E[$j]['lote'], 'area' => $E[$j]['area'],
                'para_quadra' => (string) $l['quadra'], 'para_lote' => (string) $l['numero_lote'],
                'iguais' => (int) round($iou($i, $j) * 100), 'linhas' => [$i]];
        }

        // 3. Desmembrado: um lote do mapa com 2+ partes do arquivo dentro.
        foreach ($E as $j => $e) {
            if (isset($usadoE[$j]) || ! $e['meu']) {
                continue;
            }
            $partes = [];
            $soma = 0.0;
            foreach ($doE[$j] ?? [] as $i => $c) {
                if ($tipos[$i] === null && $c / max(0.01, $F[$i]['a']) >= self::DENTRO) {
                    $partes[] = $i;
                    $soma += $c;
                }
            }
            if (count($partes) >= 2 && $soma / max(0.01, $e['a']) >= self::DENTRO) {
                usort($partes, fn ($a, $b) => strnatcmp((string) $linhas[$a]['numero_lote'], (string) $linhas[$b]['numero_lote']));
                foreach ($partes as $i) {
                    $tipos[$i] = 'desmembrado';
                }
                $usadoE[$j] = true;
                $ops[] = ['tipo' => 'desmembrar', 'grupo' => 'desmembrado', 'lote_id' => $e['id'],
                    'quadra' => $e['quadra'], 'lote' => $e['lote'], 'area' => $e['area'], 'linhas' => $partes,
                    'partes' => array_map(fn ($i) => ['quadra' => $linhas[$i]['quadra'], 'lote' => $linhas[$i]['numero_lote']], $partes)];
            }
        }

        // 4. Unificado: um lote do arquivo com 2+ lotes do mapa dentro.
        foreach ($F as $i => $f) {
            if ($tipos[$i] !== null || $linhas[$i]['numero_lote'] === null) {
                continue;
            }
            $dentro = [];
            $soma = 0.0;
            foreach ($comum[$i] ?? [] as $j => $c) {
                if (! isset($usadoE[$j]) && $E[$j]['meu'] && $c / max(0.01, $E[$j]['a']) >= self::DENTRO) {
                    $dentro[] = $j;
                    $soma += $c;
                }
            }
            $mesmaQuadra = count(array_unique(array_map(fn ($j) => (string) $E[$j]['quadra'], $dentro))) === 1;
            if (count($dentro) >= 2 && $mesmaQuadra && $soma / max(0.01, $f['a']) >= self::DENTRO) {
                $tipos[$i] = 'unificado';
                foreach ($dentro as $j) {
                    $usadoE[$j] = true;
                }
                $ops[] = ['tipo' => 'unificar', 'grupo' => 'unificado',
                    'lote_ids' => array_map(fn ($j) => $E[$j]['id'], $dentro),
                    'lotes' => array_map(fn ($j) => ['id' => $E[$j]['id'], 'quadra' => $E[$j]['quadra'], 'lote' => $E[$j]['lote'], 'area' => $E[$j]['area']], $dentro),
                    'quadra' => $E[$dentro[0]]['quadra'],
                    'para_quadra' => (string) $linhas[$i]['quadra'], 'para_lote' => (string) $linhas[$i]['numero_lote'], 'linhas' => [$i]];
            }
        }

        // 5. O resto do arquivo: novo, se não toca ninguém; duvidoso, se toca.
        foreach ($F as $i => $f) {
            if ($tipos[$i] !== null) {
                continue;
            }
            $pior = null;
            foreach ($comum[$i] ?? [] as $j => $c) {
                $frac = $c / max(0.01, min($f['a'], $E[$j]['a']));
                if ($frac >= self::SOBREPOE && (! $pior || $frac > $pior[1])) {
                    $pior = [$j, $frac];
                }
            }
            $l = $linhas[$i];
            // O número já é de outro lote que continua: entra sem número.
            $dono = $f['chave'] !== null ? ($porChave[$f['chave']] ?? null) : null;
            if ($pior) {
                $o = $E[$pior[0]];
                $tipos[$i] = 'duvidoso';
                $duvidosos[] = ['indice' => $i, 'quadra' => $l['quadra'], 'lote' => $l['numero_lote'], 'outro_id' => $o['id'],
                    'detalhe' => 'cobre ' . (int) round($pior[1] * 100) . '% do Q ' . ($o['quadra'] ?? '—') . ' · L ' . ($o['lote'] ?? '—')
                        . ($o['meu'] ? '' : " ({$o['bairro']})") . ', sem leitura segura'];
            } elseif ($dono !== null) {
                $tipos[$i] = 'duvidoso';
                $duvidosos[] = ['indice' => $i, 'quadra' => $l['quadra'], 'lote' => $l['numero_lote'], 'outro_id' => $E[$dono]['id'],
                    'detalhe' => 'o número já é de outro lote do mapa, em outro lugar'];
            } else {
                $tipos[$i] = 'novo';
            }
        }

        // 6. O que sumiu: lote do mapa, nas quadras do arquivo, que nada do arquivo toca.
        $sumiram = [];
        foreach ($E as $j => $e) {
            if (isset($usadoE[$j]) || ! $e['meu'] || ! isset($quadrasDoArquivo[(string) $e['quadra']])) {
                continue;
            }
            $tocado = false;
            foreach ($doE[$j] ?? [] as $c) {
                if ($c / max(0.01, $e['a']) >= self::SOBREPOE) {
                    $tocado = true;
                    break;
                }
            }
            if (! $tocado) {
                $sumiram[] = $j;
                $ops[] = ['tipo' => 'inativar', 'grupo' => 'sumiu', 'lote_id' => $e['id'],
                    'quadra' => $e['quadra'], 'lote' => $e['lote'], 'area' => $e['area'], 'linhas' => []];
            }
        }

        foreach ($tipos as $t) {
            $contagem[$t]++;
        }
        $contagem['sumiu'] = count($sumiram);
        // Desmembrado e unificado contam "do mapa → no arquivo".
        $contagem['desmembrado_mapa'] = count(array_filter($ops, fn ($o) => $o['grupo'] === 'desmembrado'));
        $contagem['unificado_mapa'] = array_sum(array_map(fn ($o) => count($o['lote_ids']), array_filter($ops, fn ($o) => $o['grupo'] === 'unificado')));

        // Vínculos e bloqueios de cada mudança (a tela avisa; a publicação respeita).
        $avisos = ['vinculos' => 0, 'bloqueados' => 0];
        foreach ($ops as &$op) {
            $ids = $op['lote_ids'] ?? [$op['lote_id']];
            $op['impedimento'] = FracaoIdealDeLote::impedimentoDeCurar($ids, 'revisar pelo arquivo');
            $op['vinculos'] = DB::table('documentos')->whereIn('lote_id', $ids)->count()
                + DB::table('vistorias')->whereIn('lote_id', $ids)->count()
                + DB::table('obras')->whereIn('lote_id', $ids)->count();
            if ($op['impedimento']) {
                $avisos['bloqueados']++;
            } elseif ($op['vinculos'] > 0 && in_array($op['grupo'], $grupos, true)) {
                $avisos['vinculos']++;
            }
            // A geometria que vai valer: a do arquivo.
            if ($op['tipo'] === 'editar' || $op['tipo'] === 'unificar') {
                $op['geometria'] = json_decode($linhas[$op['linhas'][0]]['geojson'], true);
            } elseif ($op['tipo'] === 'desmembrar') {
                foreach ($op['linhas'] as $k => $i) {
                    $op['partes'][$k]['geometria'] = json_decode($linhas[$i]['geojson'], true);
                }
            }
        }
        unset($op);

        // As quadras que a revisão mexe (para a marcação do decreto).
        $quadras = [];
        foreach ($ops as $op) {
            if (in_array($op['grupo'], $grupos, true) && ! $op['impedimento']) {
                $quadras[(string) ($op['para_quadra'] ?? $op['quadra'])] = true;
            }
        }
        if (in_array('novo', $grupos, true)) {
            foreach ($linhas as $i => $l) {
                if ($tipos[$i] === 'novo' && $l['quadra'] !== null) {
                    $quadras[(string) $l['quadra']] = true;
                }
            }
        }
        $quadras = array_keys($quadras);
        usort($quadras, 'strnatcmp');

        $desenho = null;
        if (count($linhas) + count($E) <= self::TETO_DESENHO) {
            $r6 = fn (array $anel) => array_map(fn ($c) => [round($c[0], 6), round($c[1], 6)], $anel);
            $desenho = [
                'arquivo' => array_map(fn ($a, $t) => ['t' => $t, 'c' => $r6($a)], $aneis, $tipos),
                'mapa'    => array_values(array_map(fn ($e) => $r6($e['anel']), array_filter($E, fn ($e, $j) => ! in_array($j, $sumiram, true), ARRAY_FILTER_USE_BOTH))),
                'sumiu'   => array_map(fn ($j) => $r6($E[$j]['anel']), $sumiram),
            ];
        }

        return ['tipos' => $tipos, 'contagem' => $contagem, 'operacoes' => $ops, 'duvidosos' => $duvidosos,
            'quadras' => array_map('strval', $quadras), 'desenho' => $desenho, 'avisos' => $avisos];
    }

    /**
     * Na publicação: cada mudança guardada passa pela ferramenta de sempre,
     * uma a uma, na sua transação. A que não passa fica relatada.
     *
     * Ordem: o que sai (inativar) antes do que entra; unificar e desmembrar
     * antes de redesenhar; o número por último, quando os outros já saíram
     * do caminho.
     *
     * @return array{aplicadas: list<array<string,mixed>>, falhas: list<array<string,mixed>>}
     */
    public function aplicar(ImportacaoLote $imp): array
    {
        $ops = $imp->conferencia['revisao_operacoes'] ?? [];
        $ordem = ['sumiu' => 0, 'unificado' => 1, 'desmembrado' => 2, 'desenho' => 3, 'numero' => 4];
        usort($ops, fn ($a, $b) => ($ordem[$a['grupo']] ?? 9) <=> ($ordem[$b['grupo']] ?? 9));
        $motivo = 'Revisão do bairro pela importação nº ' . $imp->id
            . (! empty($imp->conferencia['etapa']['ato']['ato']) ? ' (' . $imp->conferencia['etapa']['ato']['ato'] . ')' : '')
            . ' — ' . $imp->arquivo_nome;

        $saida = ['aplicadas' => [], 'falhas' => []];
        foreach ($ops as $op) {
            $resumo = collect($op)->only(['tipo', 'grupo', 'lote_id', 'lote_ids', 'quadra', 'lote', 'para_quadra', 'para_lote'])->all();
            try {
                if ($op['impedimento'] ?? null) {
                    throw new \RuntimeException($op['impedimento']);
                }
                if ($mudou = $this->mudouDesdeALeitura($op)) {
                    throw new \RuntimeException($mudou);
                }
                $resumo['novos'] = DB::transaction(fn () => match ($op['tipo']) {
                    'editar'     => $this->editar($op),
                    'desmembrar' => $this->desmembrar($op, $motivo),
                    'unificar'   => $this->unificar($op, $motivo),
                    'inativar'   => $this->inativar($op),
                });
                $saida['aplicadas'][] = $resumo;
            } catch (Throwable $e) {
                $msg = $e instanceof \Illuminate\Validation\ValidationException
                    ? collect($e->errors())->flatten()->first() : $e->getMessage();
                $saida['falhas'][] = $resumo + ['motivo' => $msg];
            }
        }

        return $saida;
    }

    /** O lote do mapa mudou entre a leitura do arquivo e a publicação? */
    private function mudouDesdeALeitura(array $op): ?string
    {
        $antes = $op['tipo'] === 'unificar' ? $op['lotes'] : [['id' => $op['lote_id'], 'quadra' => $op['quadra'], 'lote' => $op['lote'], 'area' => $op['area']]];
        foreach ($antes as $a) {
            $l = DB::table('lotes')->where('id', $a['id'])->first(['situacao', 'quadra', 'numero_lote', 'area_gis_m2']);
            if (! $l || $l->situacao !== 'ativo') {
                return "O lote {$a['lote']} da quadra {$a['quadra']} foi inativado depois da leitura do arquivo.";
            }
            if ((string) $l->quadra !== (string) $a['quadra'] || (string) $l->numero_lote !== (string) $a['lote']
                || abs((float) $l->area_gis_m2 - (float) $a['area']) > 0.05) {
                return "O lote {$a['lote']} da quadra {$a['quadra']} mudou depois da leitura do arquivo: revise-o pela tela.";
            }
        }

        return null;
    }

    /**
     * Redesenha e/ou renumera. A prova de sobreposição com o vizinho fica de
     * fora: a revisão redesenha a quadra inteira, e o vizinho de agora pode
     * ser justamente o próximo a mudar.
     */
    private function editar(array $op): array
    {
        $svc = app(EdicaoDeLote::class);
        $lote = Lote::findOrFail($op['lote_id']);
        $d = ['geometry' => $op['geometria'], 'quadra' => $op['para_quadra'], 'numero_lote' => $op['para_lote']];
        if ($erro = $svc->impedimento($lote, $d, [], false)) {
            throw new \RuntimeException($erro);
        }
        $svc->aplicar($lote, $d);

        return [$lote->id];
    }

    /**
     * Desmembra com as partes do arquivo. Primeiro com todas desenhadas; se
     * a conta das áreas não fechar com a mãe (o arquivo também corrigiu o
     * contorno), a última sai do que sobra e ganha depois o desenho do arquivo.
     */
    private function desmembrar(array $op, string $motivo): array
    {
        $svc = app(DesmembramentoDeLote::class);
        $pai = Lote::findOrFail($op['lote_id']);
        $usados = $svc->identidade($pai)['usados'];
        $sufixos = [];
        for ($n = 1; count($sufixos) < count($op['partes']) && $n <= 999; $n++) {
            if (! in_array($n, $usados, true)) {
                $sufixos[] = $n;
            }
        }
        $partes = array_map(fn ($p, $k) => ['geometry' => $p['geometria'], 'desmembramento' => $sufixos[$k]],
            $op['partes'], array_keys($op['partes']));

        $derivar = false;
        if ($svc->impedimento(null, $pai, $partes, false, direto: true)) {
            $ultima = array_pop($partes);
            $partes[count($partes) - 1]['desmembramento_derivada'] = $ultima['desmembramento'];
            $derivar = true;
            if ($erro = $svc->impedimento(null, $pai, $partes, true, direto: true)) {
                throw new \RuntimeException($erro);
            }
        }
        $novos = $svc->aplicar(null, $pai, $partes, $derivar, 'poligonos', $motivo);

        if ($derivar) {
            $ult = $novos[count($novos) - 1];
            $this->ajustarContorno($ult, end($op['partes'])['geometria']);
        }

        return array_map(fn ($l) => $l->id, $novos);
    }

    private function unificar(array $op, string $motivo): array
    {
        $svc = app(UnificacaoDeLotes::class);
        if ($erro = $svc->impedimento(null, $op['lote_ids'], $op['para_lote'], direto: true)) {
            throw new \RuntimeException($erro);
        }
        $novo = $svc->aplicar(null, $op['lote_ids'], $op['para_lote'], null, $motivo);
        $this->ajustarContorno($novo, $op['geometria']);

        return [$novo->id];
    }

    private function inativar(array $op): array
    {
        $lote = Lote::findOrFail($op['lote_id']);
        $lote->update(['situacao' => 'inativo', 'inativado_em' => now()]);

        return [];
    }

    /** O lote recebe o desenho do arquivo, se o que ficou não é ele (IoU < 98%). */
    private function ajustarContorno(Lote $lote, array $geometria): void
    {
        $anel = app(\App\Repositories\LoteRepository::class)->anel($lote->id) ?? [];
        $alvo = $geometria['coordinates'][0] ?? [];
        if (count($anel) < 4 || count($alvo) < 4) {
            return;
        }
        $a = GeometriaPlana::projetar($anel, $anel[0][1], $anel[0][0]);
        $b = GeometriaPlana::projetar($alvo, $anel[0][1], $anel[0][0]);
        $c = GeometriaPlana::areaComum($a, $b)['area'];
        $iou = $c / max(0.01, GeometriaPlana::area($a) + GeometriaPlana::area($b) - $c);
        if ($iou >= 0.98) {
            return;
        }
        $svc = app(EdicaoDeLote::class);
        $d = ['geometry' => $geometria, 'quadra' => $lote->quadra, 'numero_lote' => $lote->numero_lote];
        if ($erro = $svc->impedimento($lote->fresh(), $d, [], false)) {
            throw new \RuntimeException($erro);
        }
        $svc->aplicar($lote->fresh(), $d);
    }

    /**
     * Com um decreto de alteração escolhido, as quadras revisadas ficam
     * marcadas como alteradas por ele (aparecem na camada Etapas).
     *
     * @param  list<string>  $quadras
     * @return list<string>  as marcadas agora
     */
    public function marcarQuadras(ImportacaoLote $imp, ?int $atoId, array $quadras): array
    {
        $dec = new DecretosDasQuadras();
        $cb = $dec->bairro($imp->bairro);
        $d = $dec->doBairro($cb);
        if (! $atoId || ! $cb || ! collect($d['atos'])->contains(fn ($a) => $a['id'] === $atoId && $a['papel'] === 'alterou')) {
            return [];
        }
        $marcadas = [];
        foreach ($quadras as $q) {
            $n = \App\Support\NumeroDoLote::normalizar((string) $q) ?? (string) $q;
            if (\App\Models\BairroAtoQuadra::where(['bairro_ato_id' => $atoId, 'quadra' => $n, 'papel' => 'alterou'])->exists()) {
                continue;
            }
            \App\Models\BairroAtoQuadra::create(['bairro_ato_id' => $atoId, 'quadra' => $n, 'papel' => 'alterou']);
            $marcadas[] = $n;
        }

        return $marcadas;
    }
}
