<?php

namespace App\Cadastro;

use App\Support\GeometriaPlana;
use Illuminate\Support\Facades\DB;

/**
 * IMPORTAR UMA ETAPA DE UM BAIRRO QUE JÁ EXISTE (21/11/2026).
 *
 * Loteamento implantado por etapas chega em arquivos separados, e o desenho
 * da etapa nova muitas vezes traz a antiga junto. Esta classe compara cada
 * lote do arquivo com o que já está no mapa e diz o que ele é:
 *
 *   existe  mesma quadra e número, e o mesmo desenho (IoU ≥ 90%): fica de fora;
 *   numero  mesma quadra e número, desenho diferente: conflito;
 *   sobre   número livre, mas cobre ≥ 10% de outro lote ativo: conflito;
 *   novo    entra.
 *
 * A medida é NO PLANO (GeometriaPlana), na mesma origem para todos: o MySQL
 * erra área e interseção em SRID 4326 com polígono grande (ver LoteRepository).
 * Abaixo de 10% de sobreposição é folga de digitalização, e o lote é novo.
 */
final class EtapaDoBairro
{
    public const MESMO_DESENHO = 0.90;

    public const SOBREPOE = 0.10;

    /** Sem o desenho de comparação acima disto: a resposta ficaria pesada demais. */
    private const TETO_DESENHO = 2500;

    /**
     * @param  list<array<string,mixed>>  $linhas  as de ImportacaoDeBairro::lerArquivo, já com o bairro da etapa
     * @return array{tipos: list<string>, contagem: array<string,int>, numero: list<array<string,mixed>>, sobre: list<array<string,mixed>>, desenho: ?array}
     */
    public function classificar(array $linhas, string $bairro): array
    {
        $aneis = array_map(fn ($l) => json_decode($l['geojson'], true)['coordinates'][0] ?? [], $linhas);
        $todos = array_merge(...array_map(fn ($a) => $a, $aneis ?: [[]]));
        $contagem = ['existe' => 0, 'novo' => 0, 'numero' => 0, 'sobre' => 0];
        if (! $todos) {
            return ['tipos' => [], 'contagem' => $contagem, 'numero' => [], 'sobre' => [], 'desenho' => null];
        }

        $lons = array_column($todos, 0);
        $lats = array_column($todos, 1);
        [$x0, $x1, $y0, $y1] = [min($lons), max($lons), min($lats), max($lats)];
        $latRef = ($y0 + $y1) / 2;
        $lonRef = ($x0 + $x1) / 2;
        $folga = 0.0005;   // ~50 m: pega o vizinho da borda
        $env = sprintf('POLYGON((%1$.7f %3$.7f,%2$.7f %3$.7f,%2$.7f %4$.7f,%1$.7f %4$.7f,%1$.7f %3$.7f))',
            $x0 - $folga, $x1 + $folga, $y0 - $folga, $y1 + $folga);

        // Os lotes ativos na área do arquivo, de qualquer bairro: sobreposição
        // com o vizinho também é conflito.
        $existentes = DB::select("SELECT id, bairro, quadra, numero_lote, ST_AsGeoJSON(geom, 8) AS g FROM lotes
                                   WHERE situacao = 'ativo'
                                     AND MBRIntersects(geom, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))", [$env]);
        $proj = fn (array $anel) => GeometriaPlana::projetar($anel, $latRef, $lonRef);
        $caixa = function (array $p) {
            $xs = array_column($p, 0);
            $ys = array_column($p, 1);

            return [min($xs), max($xs), min($ys), max($ys)];
        };
        $base = [];
        $porChave = [];
        foreach ($existentes as $e) {
            $g = json_decode($e->g, true);
            $anel = ($g['type'] ?? '') === 'MultiPolygon' ? ($g['coordinates'][0][0] ?? []) : ($g['coordinates'][0] ?? []);
            if (count($anel) < 4) {
                continue;
            }
            $p = $proj($anel);
            $base[] = ['id' => (int) $e->id, 'quadra' => $e->quadra, 'lote' => $e->numero_lote, 'bairro' => $e->bairro,
                'p' => $p, 'a' => GeometriaPlana::area($p), 'cx' => $caixa($p), 'anel' => $anel];
            if ($e->bairro === $bairro && $e->quadra !== null && $e->numero_lote !== null) {
                $porChave[$e->quadra . '|' . $e->numero_lote] = count($base) - 1;
            }
        }

        $comum = function (array $p, array $b): float {
            if ($p['cx'][1] < $b['cx'][0] || $b['cx'][1] < $p['cx'][0] || $p['cx'][3] < $b['cx'][2] || $b['cx'][3] < $p['cx'][2]) {
                return 0.0;
            }

            return GeometriaPlana::areaComum($p['p'], $b['p'])['area'];
        };

        $tipos = [];
        $numero = [];
        $sobre = [];
        foreach ($linhas as $i => $l) {
            if (count($aneis[$i]) < 4) {
                $tipos[] = 'novo';
                $contagem['novo']++;
                continue;
            }
            $pp = $proj($aneis[$i]);
            $p = ['p' => $pp, 'a' => GeometriaPlana::area($pp), 'cx' => $caixa($pp)];
            $tipo = 'novo';
            $chave = $l['quadra'] !== null && $l['numero_lote'] !== null ? $l['quadra'] . '|' . $l['numero_lote'] : null;

            if ($chave !== null && isset($porChave[$chave])) {
                $b = $base[$porChave[$chave]];
                $c = $comum($p, $b);
                $iou = $c > 0 ? $c / max(0.01, $p['a'] + $b['a'] - $c) : 0.0;
                $tipo = $iou >= self::MESMO_DESENHO ? 'existe' : 'numero';
                if ($tipo === 'numero') {
                    $numero[] = ['indice' => $i, 'quadra' => $l['quadra'], 'lote' => $l['numero_lote'],
                        'lote_id' => $b['id'], 'iguais' => (int) round($iou * 100)];
                }
            } else {
                $pior = null;
                foreach ($base as $b) {
                    $c = $comum($p, $b);
                    if ($c <= 0) {
                        continue;
                    }
                    $frac = $c / max(0.01, min($p['a'], $b['a']));
                    if ($frac >= self::SOBREPOE && (! $pior || $frac > $pior['frac'])) {
                        $pior = ['frac' => $frac, 'b' => $b];
                    }
                }
                if ($pior) {
                    $tipo = 'sobre';
                    $sobre[] = ['indice' => $i, 'quadra' => $l['quadra'], 'lote' => $l['numero_lote'],
                        'cobre_id' => $pior['b']['id'], 'cobre_quadra' => $pior['b']['quadra'], 'cobre_lote' => $pior['b']['lote'],
                        'cobre_bairro' => $pior['b']['bairro'], 'porcento' => (int) round($pior['frac'] * 100)];
                }
            }
            $tipos[] = $tipo;
            $contagem[$tipo]++;
        }

        // O desenho da comparação, para a tela: o arquivo por tipo e o mapa em cinza.
        $desenho = null;
        if (count($linhas) + count($base) <= self::TETO_DESENHO) {
            $r6 = fn (array $anel) => array_map(fn ($c) => [round($c[0], 6), round($c[1], 6)], $anel);
            $desenho = [
                'arquivo' => array_map(fn ($a, $t) => ['t' => $t, 'c' => $r6($a)], $aneis, $tipos),
                'mapa'    => array_map(fn ($b) => $r6($b['anel']), $base),
            ];
        }

        return ['tipos' => $tipos, 'contagem' => $contagem, 'numero' => $numero, 'sobre' => $sobre, 'desenho' => $desenho];
    }

    /**
     * Os bairros que podem receber uma etapa: os que têm lote ativo no mapa,
     * com os atos que criam quadra (para escolher o decreto da etapa).
     *
     * @return list<array{nome:string, lotes:int, atos:list<array<string,mixed>>}>
     */
    public function bairrosQueRecebem(): array
    {
        $lotes = DB::table('lotes')->where('situacao', 'ativo')->whereNotNull('bairro')
            ->selectRaw('bairro, COUNT(*) AS n')->groupBy('bairro')->pluck('n', 'bairro');
        $dec = new DecretosDasQuadras();
        $atos = [];
        foreach (\App\Models\CadastroBairro::whereIn('nome_gis', $lotes->keys())->get() as $b) {
            $d = $dec->doBairro($b);
            $atos[$b->nome_gis] = array_values(array_filter($d['atos'], fn ($a) => $a['papel'] === 'criou'));
        }

        return $lotes->map(fn ($n, $nome) => ['nome' => $nome, 'lotes' => (int) $n, 'atos' => $atos[$nome] ?? []])
            ->sortKeys()->values()->all();
    }

    /**
     * Na publicação: as quadras que só têm lotes desta importação são do
     * decreto da etapa (marcadas em "Quadras por decreto"); as que já tinham
     * lotes de antes ficam para a curadoria decidir.
     *
     * @return array{marcadas: list<string>, mistas: list<string>, ja_marcadas: list<string>}
     */
    public function marcarQuadras(\App\Models\ImportacaoLote $imp, ?int $atoId): array
    {
        $saida = ['marcadas' => [], 'mistas' => [], 'ja_marcadas' => []];
        $quadras = DB::table('lotes')->where('importacao_id', $imp->id)->where('situacao', 'ativo')
            ->whereNotNull('quadra')->distinct()->pluck('quadra')->all();
        if (! $quadras) {
            return $saida;
        }
        $outras = DB::table('lotes')->where('bairro', $imp->bairro)->where('situacao', 'ativo')
            ->whereIn('quadra', $quadras)->where(fn ($q) => $q->whereNull('importacao_id')->orWhere('importacao_id', '<>', $imp->id))
            ->distinct()->pluck('quadra')->flip();

        $dec = new DecretosDasQuadras();
        $cb = $dec->bairro($imp->bairro);
        $d = $dec->doBairro($cb);
        $valido = $atoId && $cb && collect($d['atos'])->contains(fn ($a) => $a['id'] === $atoId && $a['papel'] === 'criou');

        usort($quadras, 'strnatcmp');
        foreach ($quadras as $q) {
            $n = \App\Support\NumeroDoLote::normalizar((string) $q) ?? (string) $q;
            if ($outras->has($q)) {
                $saida['mistas'][] = $n;
            } elseif (! $valido || $atoId === $d['padrao']) {
                continue;   // sem decreto, ou o da aprovação: o padrão já cobre
            } elseif (isset($d['criou'][$n])) {
                $saida['ja_marcadas'][] = $n;
            } else {
                \App\Models\BairroAtoQuadra::create(['bairro_ato_id' => $atoId, 'quadra' => $n, 'papel' => 'criou']);
                $saida['marcadas'][] = $n;
            }
        }

        return $saida;
    }
}
