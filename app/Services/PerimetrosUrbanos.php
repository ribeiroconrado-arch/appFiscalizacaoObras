<?php

namespace App\Services;

use App\Models\PerimetroUrbano;
use App\Models\User;
use App\Support\GeometriaPlana;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * O PERÍMETRO URBANO e a sua atualização pela tela de Importações.
 *
 * O arquivo é o GeoJSON que o conversor gera do DWG (ver PERIMETRO-URBANO.md
 * no conversor): UMA feição com `"tipo": "perimetro"`, Polygon ou MultiPolygon
 * em 4326, furos permitidos (enclave rural dentro da cidade).
 *
 *   carregar   o arquivo vira RASCUNHO, já comparado com o vigente
 *   publicar   (administrador) o rascunho passa a valer; o vigente de antes
 *              vira ANTERIOR, com a vigência encerrada na véspera
 *   descartar  o rascunho sai sem deixar rastro além da trilha
 *
 * Nenhuma versão publicada é apagada nem redesenhada: a lei muda a linha, e o
 * que foi lavrado sob a linha antiga continua explicável por ela.
 *
 * ── As contas ──
 *
 * Área pela GeometriaPlana (régua da grade UTM, a mesma dos lotes). O que ENTRA
 * e o que SAI são diferenças feitas pelo MySQL no PLANO (SRID 0): em 4326 os
 * lados viram geodésicas e o resultado sai com pontos soltos, ruim de desenhar.
 * Para o perímetro, com lados de dezenas de metros, a diferença é desprezível.
 * Lote dentro ou fora é decidido em PHP, vértice a vértice, com um índice das
 * arestas por faixa (ver lotesFora): lote cortado pela linha conta como dentro
 * (é a linha que o fiscal vai conferir).
 */
class PerimetrosUrbanos
{
    /** Uma cidade: de 1 km² (um distrito pequeno) a 2.000 km² (bem mais que o município). */
    private const AREA_MIN_M2 = 1_000_000;
    private const AREA_MAX_M2 = 2_000_000_000;

    /** Folga, em graus (~30 km), em volta do retângulo da cidade (config/gis.php). */
    private const FOLGA_DA_REGIAO = 0.3;

    /** Quantos lotes e bairros a comparação lista um a um (o total vem sempre). */
    private const LISTA_MAX = 200;

    /**
     * Lê e confere o arquivo. Não grava nada.
     *
     * @return array{geometry: array, area_m2: float, area_m2_arquivo: ?float, partes: int,
     *               furos: int, vertices: int, fonte: ?string, camada: ?string, caixa: array}
     */
    public function lerArquivo(string $caminho): array
    {
        $gj = json_decode((string) file_get_contents($caminho), true);
        if (! is_array($gj) || ($gj['type'] ?? null) !== 'FeatureCollection') {
            throw new RuntimeException('O arquivo não é uma FeatureCollection GeoJSON.');
        }

        $feicoes = $gj['features'] ?? [];
        $doPerimetro = array_values(array_filter($feicoes, fn ($f) => self::ehDoPerimetro($f)));
        if (! $doPerimetro) {
            throw new RuntimeException('O arquivo não traz o perímetro urbano: falta a feição com "tipo": "perimetro". '
                . 'Gere o arquivo no conversor com a camada no papel "Perímetro urbano".');
        }
        if (count($doPerimetro) !== count($feicoes)) {
            throw new RuntimeException('O arquivo mistura o perímetro com outras feições (lotes, urbanismo). '
                . 'Exporte o perímetro sozinho no conversor e envie de novo.');
        }

        // Todas as partes num MultiPolygon só.
        $poligonos = [];
        foreach ($doPerimetro as $f) {
            $g = $f['geometry'] ?? null;
            match ($g['type'] ?? null) {
                'Polygon'      => $poligonos[] = $g['coordinates'],
                'MultiPolygon' => array_push($poligonos, ...$g['coordinates']),
                default        => throw new RuntimeException('O perímetro precisa ser polígono (Polygon ou MultiPolygon).'),
            };
        }

        $vertices = 0;
        $furos = 0;
        $caixa = [INF, INF, -INF, -INF];   // oeste, sul, leste, norte
        foreach ($poligonos as $i => $aneis) {
            if (! is_array($aneis) || ! $aneis) {
                throw new RuntimeException('Parte ' . ($i + 1) . ' do perímetro está vazia.');
            }
            foreach ($aneis as $k => $anel) {
                if (! is_array($anel) || count($anel) < 4) {
                    throw new RuntimeException('Parte ' . ($i + 1) . ' do perímetro tem um anel com menos de 4 pontos.');
                }
                foreach ($anel as $c) {
                    [$x, $y] = [(float) ($c[0] ?? NAN), (float) ($c[1] ?? NAN)];
                    if (! is_finite($x) || ! is_finite($y) || abs($x) > 180 || abs($y) > 90) {
                        throw new RuntimeException('As coordenadas não estão em graus (EPSG:4326) — parece UTM. '
                            . 'O conversor já reprojeta: gere o arquivo por ele.');
                    }
                    $caixa = [min($caixa[0], $x), min($caixa[1], $y), max($caixa[2], $x), max($caixa[3], $y)];
                }
                // Anel aberto por descuido: fecha (o MySQL exige primeiro = último).
                if ($anel[0] != end($anel)) {
                    $poligonos[$i][$k][] = $anel[0];
                }
                $vertices += count($anel);
                $furos += $k > 0 ? 1 : 0;
            }
        }

        $cidade = config('gis.perimetro_urbano');
        if (is_array($cidade) && count($cidade) === 4) {
            $f = self::FOLGA_DA_REGIAO;
            $longe = $caixa[2] < $cidade[0] - $f || $caixa[0] > $cidade[2] + $f
                  || $caixa[3] < $cidade[1] - $f || $caixa[1] > $cidade[3] + $f;
            if ($longe) {
                throw new RuntimeException('O perímetro não cai na região do município: confira se o DWG estava '
                    . 'georreferenciado (ou se faltou o deslocamento dx/dy no conversor).');
            }
        }

        $geometry = ['type' => 'MultiPolygon', 'coordinates' => $poligonos];
        $area = self::area($geometry);
        if ($area < self::AREA_MIN_M2 || $area > self::AREA_MAX_M2) {
            throw new RuntimeException(sprintf('A área do perímetro (%s ha) está fora do esperado para uma cidade: '
                . 'confira se a camada escolhida no conversor é mesmo o perímetro.', number_format($area / 10000, 2, ',', '.')));
        }
        if (! DB::scalar('SELECT ST_IsValid(ST_GeomFromGeoJSON(?, 1, 4326))', [json_encode($geometry)])) {
            throw new RuntimeException('O polígono do perímetro se cruza (autointerseção) ou tem partes sobrepostas. '
                . 'Corrija no DWG e converta de novo.');
        }

        $p = $doPerimetro[0]['properties'] ?? [];

        return [
            'geometry'        => $geometry,
            'area_m2'         => round($area, 2),
            'area_m2_arquivo' => isset($p['area_m2']) && is_numeric($p['area_m2']) ? (float) $p['area_m2'] : null,
            'partes'          => count($poligonos),
            'furos'           => $furos,
            'vertices'        => $vertices,
            'fonte'           => isset($p['fonte']) ? mb_substr((string) $p['fonte'], 0, 200) : null,
            'camada'          => isset($p['camada']) ? mb_substr((string) $p['camada'], 0, 200) : null,
            'caixa'           => $caixa,
        ];
    }

    /** A feição é do perímetro? (é o que a tela usa para trocar de fluxo) */
    public static function ehDoPerimetro(mixed $feicao): bool
    {
        return is_array($feicao) && mb_strtolower(trim((string) ($feicao['properties']['tipo'] ?? ''))) === 'perimetro';
    }

    /** O arquivo traz alguma feição de perímetro? Leitura rápida, sem conferir nada. */
    public static function arquivoEhDePerimetro(string $caminho): bool
    {
        $gj = json_decode((string) file_get_contents($caminho), true);

        return is_array($gj) && collect($gj['features'] ?? [])->contains(fn ($f) => self::ehDoPerimetro($f));
    }

    /**
     * Área de um Polygon/MultiPolygon em 4326, em m² de grade. Furos descontados.
     * Todos os anéis projetados na mesma origem.
     */
    public static function area(array $geometry): float
    {
        $poligonos = match ($geometry['type'] ?? null) {
            'Polygon'      => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            'GeometryCollection' => array_merge(...array_map(
                fn ($g) => match ($g['type'] ?? null) {
                    'Polygon' => [$g['coordinates']], 'MultiPolygon' => $g['coordinates'], default => [],
                }, $geometry['geometries'] ?? [[]])),
            default => [],
        };
        $origem = $poligonos[0][0][0] ?? null;
        if (! $origem) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($poligonos as $aneis) {
            foreach ($aneis as $k => $anel) {
                $a = GeometriaPlana::area(GeometriaPlana::projetar($anel, (float) $origem[1], (float) $origem[0]));
                $total += $k === 0 ? $a : -$a;
            }
        }

        return $total;
    }

    // ── versões ───────────────────────────────────────────────────

    public function vigente(): ?PerimetroUrbano
    {
        return PerimetroUrbano::where('situacao', 'vigente')->first();
    }

    /** A geometria gravada, como GeoJSON (array). */
    public function geometria(PerimetroUrbano $p): ?array
    {
        $gj = DB::table('perimetros_urbanos')->where('id', $p->id)->selectRaw('ST_AsGeoJSON(geom) g')->value('g');

        return $gj ? json_decode($gj, true) : null;
    }

    /**
     * Grava o arquivo como RASCUNHO, já comparado com o vigente.
     *
     * @param  array{lei_numero?:?string, lei_data?:?string, vigente_desde?:?string, observacao?:?string}  $lei
     */
    public function carregar(string $caminho, string $nomeArquivo, array $lei, User $u): PerimetroUrbano
    {
        $l = $this->lerArquivo($caminho);
        $comparacao = $this->comparar($l['geometry']);

        $id = DB::table('perimetros_urbanos')->insertGetId([
            'situacao'      => 'rascunho',
            'lei_numero'    => $lei['lei_numero'] ?? null,
            'lei_data'      => $lei['lei_data'] ?? null,
            'vigente_desde' => $lei['vigente_desde'] ?? null,
            'observacao'    => $lei['observacao'] ?? null,
            'area_m2'       => $l['area_m2'],
            'geom'          => DB::raw('ST_GeomFromGeoJSON(' . DB::getPdo()->quote(json_encode($l['geometry'])) . ', 1, 4326)'),
            'arquivo_nome'  => mb_substr($nomeArquivo, 0, 200),
            'fonte'         => $l['fonte'],
            'camada'        => $l['camada'],
            'comparacao'    => json_encode($comparacao, JSON_UNESCAPED_UNICODE),
            'user_id'       => $u->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $p = PerimetroUrbano::findOrFail($id);
        $p->registrarAuditoria('carregou', null, $p->only(['situacao', 'lei_numero', 'vigente_desde', 'area_m2', 'arquivo_nome', 'fonte']));

        return $p;
    }

    /** Dados da lei do rascunho (o que a tela deixa corrigir antes de publicar). */
    public function atualizarLei(PerimetroUrbano $p, array $lei): PerimetroUrbano
    {
        if ($p->situacao !== 'rascunho') {
            throw new RuntimeException('Só o rascunho tem a lei alterada. A versão publicada é registro da época.');
        }
        $p->update(array_intersect_key($lei, array_flip(['lei_numero', 'lei_data', 'vigente_desde', 'observacao'])));

        return $p;
    }

    /**
     * A comparação do rascunho com o vigente de AGORA: se o vigente mudou desde
     * que ela foi feita, refaz e guarda.
     */
    public function comparacaoAtual(PerimetroUrbano $p): array
    {
        $vig = $this->vigente();
        $c = $p->comparacao ?? [];
        if ($p->situacao === 'rascunho' && ($c['vigente_id'] ?? null) !== $vig?->id) {
            $c = $this->comparar($this->geometria($p));
            DB::table('perimetros_urbanos')->where('id', $p->id)
                ->update(['comparacao' => json_encode($c, JSON_UNESCAPED_UNICODE)]);
        }

        return $c;
    }

    /**
     * O que muda com o perímetro novo, contra o vigente (ou contra nada, na
     * primeira versão).
     *
     * @return array<string,mixed>
     */
    public function comparar(array $novo): array
    {
        $vig = $this->vigente();
        $geoVig = $vig ? $this->geometria($vig) : null;
        $idxNovo = self::indice($novo);
        $idxVig = $geoVig ? self::indice($geoVig) : null;

        [$foraNovo, $foraVig] = $this->lotesFora($idxNovo, $idxVig);
        $r = [
            'vigente_id'    => $vig?->id,
            'vigente'       => $vig ? ['id' => $vig->id, 'rotulo' => $vig->rotulo(), 'area_m2' => $vig->area_m2] : null,
            'area_entra_m2' => null,
            'area_sai_m2'   => null,
            'lotes_fora'    => count($foraNovo),
            'passam_fora'   => [],
            'passam_dentro' => [],
            'bairros'       => $this->bairrosNaLinha($idxNovo),
            'feita_em'      => now()->format('d/m/Y H:i'),
        ];

        if ($vig) {
            [$entra, $sai] = $this->diferencas(json_encode($novo), json_encode($geoVig));
            $r['area_entra_m2'] = round(self::area($entra), 2);
            $r['area_sai_m2'] = round(self::area($sai), 2);
            $r['passam_fora'] = $this->descreverLotes(array_diff_key($foraNovo, $foraVig));
            $r['passam_dentro'] = $this->descreverLotes(array_diff_key($foraVig, $foraNovo));
        } else {
            // Primeira versão: o que interessa é quem fica de fora.
            $r['passam_fora'] = $this->descreverLotes($foraNovo);
        }

        return $r;
    }

    /**
     * O que entra e o que sai, para desenhar: [novo − vigente, vigente − novo].
     *
     * @return array{0: array, 1: array}
     */
    public function diferencas(string $gjNovo, string $gjVigente): array
    {
        $dif = fn (string $a, string $b) => json_decode((string) DB::scalar(
            'SELECT ST_AsGeoJSON(ST_Difference(ST_GeomFromGeoJSON(?, 1, 0), ST_GeomFromGeoJSON(?, 1, 0)))', [$a, $b]
        ), true) ?? ['type' => 'GeometryCollection', 'geometries' => []];

        return [$dif($gjNovo, $gjVigente), $dif($gjVigente, $gjNovo)];
    }

    /**
     * Os lotes ATIVOS fora de cada perímetro (publicados e os de importação em
     * revisão: estes são a maior parte da cidade enquanto a curadoria anda).
     * Uma leitura dos lotes serve aos dois perímetros.
     *
     * Lote com algum vértice dentro conta como dentro — cortado pela linha é
     * dentro, como no ST_Intersects. Feito aqui, e não no MySQL: lá cada lote
     * percorre todas as arestas do perímetro (35 mil lotes × 2 mil vértices
     * levaram 6 s no plano e 21 s em 4326, medido em produção em 27/10/2026);
     * com o índice por faixa (`indice`) cada vértice olha meia dúzia de arestas.
     *
     * @return array{0: array<int,true>, 1: array<int,true>}  [fora do novo, fora do vigente]
     */
    private function lotesFora(array $idxNovo, ?array $idxVig): array
    {
        $foraNovo = $foraVig = [];
        $lotes = DB::table('lotes')->where('situacao', 'ativo')->whereNotNull('geom')
            ->selectRaw('id, ST_AsGeoJSON(geom) g')->orderBy('id')->cursor();
        foreach ($lotes as $l) {
            $anel = json_decode($l->g, true)['coordinates'][0] ?? [];
            if (! self::algumDentro($idxNovo, $anel)) { $foraNovo[$l->id] = true; }
            if ($idxVig && ! self::algumDentro($idxVig, $anel)) { $foraVig[$l->id] = true; }
        }

        return [$foraNovo, $foraVig];
    }

    // ── ponto no polígono, com índice ─────────────────────────────

    /** Faixas horizontais do índice: com ~2 mil vértices, poucas arestas por faixa. */
    private const FAIXAS = 1024;

    /**
     * Arestas do (Multi)Polygon agrupadas por faixa horizontal. Furos entram
     * como qualquer anel: na contagem par/ímpar do raio, ponto no furo é fora.
     *
     * @return array{y0: float, h: float, faixas: array<int, list<array{0:float,1:float,2:float,3:float}>>}
     */
    public static function indice(array $geometry): array
    {
        $poligonos = ($geometry['type'] ?? null) === 'Polygon' ? [$geometry['coordinates']] : ($geometry['coordinates'] ?? []);
        $arestas = [];
        $y0 = INF; $y1 = -INF;
        foreach ($poligonos as $aneis) {
            foreach ($aneis as $anel) {
                for ($i = 0, $n = count($anel) - 1; $i < $n; $i++) {
                    $a = $anel[$i]; $b = $anel[$i + 1];
                    $arestas[] = [(float) $a[0], (float) $a[1], (float) $b[0], (float) $b[1]];
                    $y0 = min($y0, $a[1], $b[1]); $y1 = max($y1, $a[1], $b[1]);
                }
            }
        }
        $h = max(($y1 - $y0) / self::FAIXAS, 1e-12);
        $faixas = [];
        foreach ($arestas as $e) {
            $de = (int) floor((min($e[1], $e[3]) - $y0) / $h);
            $ate = min((int) floor((max($e[1], $e[3]) - $y0) / $h), self::FAIXAS - 1);
            for ($k = $de; $k <= $ate; $k++) { $faixas[$k][] = $e; }
        }

        return ['y0' => $y0, 'h' => $h, 'faixas' => $faixas];
    }

    /** O ponto (lon, lat) está dentro? Raio para a direita, contagem par/ímpar. */
    public static function dentro(array $idx, float $x, float $y): bool
    {
        $k = (int) floor(($y - $idx['y0']) / $idx['h']);
        if ($k < 0 || $k >= self::FAIXAS) {
            return false;
        }
        $dentro = false;
        foreach ($idx['faixas'][$k] ?? [] as [$x1, $y1, $x2, $y2]) {
            if (($y1 > $y) !== ($y2 > $y) && $x < $x1 + ($y - $y1) * ($x2 - $x1) / ($y2 - $y1)) {
                $dentro = ! $dentro;
            }
        }

        return $dentro;
    }

    /** @param  list<array{0:float,1:float}>  $anel */
    private static function algumDentro(array $idx, array $anel): bool
    {
        foreach ($anel as $c) {
            if (self::dentro($idx, (float) $c[0], (float) $c[1])) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int,true>  $ids */
    private function descreverLotes(array $ids): array
    {
        $total = count($ids);
        $linhas = $total ? DB::table('lotes')->whereIn('id', array_slice(array_keys($ids), 0, 5000))
            ->orderBy('bairro')->orderByRaw('quadra + 0')->orderByRaw('numero_lote + 0')
            ->limit(self::LISTA_MAX)->get(['id', 'bairro', 'quadra', 'numero_lote'])
            ->map(fn ($l) => ['id' => $l->id, 'bairro' => $l->bairro, 'quadra' => $l->quadra, 'lote' => $l->numero_lote])
            ->all() : [];

        return ['total' => $total, 'lista' => $linhas];
    }

    /**
     * Bairros (pelo contorno gravado) que a linha corta — vértices dos dois
     * lados — e os que ficam inteiros fora.
     */
    private function bairrosNaLinha(array $idx): array
    {
        $cortados = $fora = [];
        foreach (DB::table('bairros')->whereNotNull('geom')->orderBy('nome')->selectRaw('nome, ST_AsGeoJSON(geom) g')->cursor() as $b) {
            $g = json_decode($b->g, true);
            $pontos = ($g['type'] ?? null) === 'MultiPolygon'
                ? array_merge(...array_merge(...$g['coordinates'])) : array_merge(...($g['coordinates'] ?? [[]]));
            $dentro = 0;
            foreach ($pontos as $c) { $dentro += self::dentro($idx, (float) $c[0], (float) $c[1]) ? 1 : 0; }
            if ($dentro === 0) { $fora[] = $b->nome; }
            elseif ($dentro < count($pontos)) { $cortados[] = $b->nome; }
        }

        return ['cortados' => $cortados, 'fora' => $fora];
    }

    /** Só o administrador. O vigente de antes vira anterior, encerrado na véspera. */
    public function publicar(PerimetroUrbano $p, User $u): void
    {
        DB::transaction(function () use ($p, $u) {
            $p = PerimetroUrbano::lockForUpdate()->findOrFail($p->id);
            if ($p->situacao !== 'rascunho') {
                throw new RuntimeException('Este perímetro já foi publicado.');
            }
            if (! $p->lei_numero || ! $p->vigente_desde) {
                throw new RuntimeException('Informe o número da lei e a data de início da vigência antes de publicar.');
            }
            $vig = PerimetroUrbano::where('situacao', 'vigente')->lockForUpdate()->first();
            if ($vig && $vig->vigente_desde && $p->vigente_desde->lte($vig->vigente_desde)) {
                throw new RuntimeException('A vigência do novo perímetro precisa começar depois da do atual ('
                    . $vig->vigente_desde->format('d/m/Y') . ').');
            }
            if ($vig) {
                $vig->update(['situacao' => 'anterior', 'vigente_ate' => Carbon::parse($p->vigente_desde)->subDay()]);
            }
            $p->update(['situacao' => 'vigente', 'publicado_por' => $u->id, 'publicado_em' => now()]);
        });
        Cache::forget('perimetro-urbano:retangulo');
    }

    /** O rascunho sai. Publicado não se apaga. */
    public function descartar(PerimetroUrbano $p): void
    {
        if ($p->situacao !== 'rascunho') {
            throw new RuntimeException('Perímetro publicado não se descarta: ele fica no histórico.');
        }
        $p->registrarAuditoria('descartou', $p->only(['lei_numero', 'vigente_desde', 'area_m2', 'arquivo_nome']), null);
        DB::table('perimetros_urbanos')->where('id', $p->id)->delete();
    }

    /**
     * O retângulo [oeste, sul, leste, norte] que o mapa usa para "ver tudo": o
     * do perímetro vigente; sem perímetro publicado, o do .env (config/gis.php).
     */
    public static function retanguloParaOMapa(): array
    {
        $reserva = config('gis.perimetro_urbano');
        try {
            return Cache::remember('perimetro-urbano:retangulo', now()->addHour(), function () use ($reserva) {
                if (! Schema::hasTable('perimetros_urbanos')) {
                    return $reserva;
                }
                // Pelo GeoJSON (lon, lat): o 4326 do MySQL guarda lat/lon por dentro.
                $gj = DB::table('perimetros_urbanos')->where('situacao', 'vigente')
                    ->selectRaw('ST_AsGeoJSON(geom) g')->value('g');
                $pontos = $gj ? array_merge(...array_merge(...(json_decode($gj, true)['coordinates'] ?? []))) : [];
                if (! $pontos) {
                    return $reserva;
                }
                $xs = array_column($pontos, 0);
                $ys = array_column($pontos, 1);

                return [min($xs), min($ys), max($xs), max($ys)];
            });
        } catch (\Throwable) {
            return $reserva;
        }
    }
}
