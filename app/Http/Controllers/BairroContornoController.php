<?php

namespace App\Http\Controllers;

use App\Cadastro\BairrosDoDesenho;
use App\Repositories\LoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * O contorno de cada bairro — a linha tracejada que o mapa mostra sempre, e que
 * vira o desenho principal quando o mapa está afastado demais para os lotes —
 * e, gerado junto, o contorno de cada QUADRA do bairro, que o mapa mostra na
 * escala do bairro no lugar das linhas de lote.
 *
 * O CÁLCULO acontece no navegador do curador (public/js/bairros-contorno.js):
 * união dos lotes e fechamento de R metros com JSTS. Aqui fica o que o
 * servidor faz bem — servir, conferir e gravar —, porque o ST_Buffer negativo
 * do MySQL corrompe multipolígono grande (ver LoteRepository).
 */
class BairroContornoController extends Controller
{
    /** Fração mínima dos lotes do bairro que o contorno precisa tocar. */
    private const COBERTURA_MINIMA = 0.99;

    /** Teto de quadras num envio — o maior loteamento da base tem pouco mais de 100. */
    private const MAX_QUADRAS = 2000;

    public function __construct(private LoteRepository $lotes) {}

    /** Quem gera: o curador, ou o administrador (é ele quem publica a importação). */
    private function podeGerar(Request $r): bool
    {
        return $r->user()->podeCurarCadastro() || $r->user()->isAdmin();
    }

    /**
     * GET /api/mapa/bairros — os contornos, para o mapa de todos.
     *
     * "Desatualizado" é calculado aqui, comparando o que o contorno contou com
     * os lotes de hoje: nenhuma ferramenta da curadoria precisa avisar.
     */
    public function index(Request $r): JsonResponse
    {
        $nomes = new BairrosDoDesenho();
        $situacao = $this->lotes->situacaoDosBairros();
        $quadras = $this->lotes->quadrasPorBairro();
        $curador = $this->podeGerar($r);

        $feicoes = [];
        $comContorno = [];
        foreach ($this->lotes->contornosDosBairros() as $b) {
            $s = $situacao[$b->nome] ?? null;
            $comContorno[$b->nome] = true;
            // Bairro só com lotes em revisão ainda não existe para quem não revisa.
            if (! $curador && (! $s || (int) $s->publicados === 0)) {
                continue;
            }
            $desatualizado = ! $s || (int) $s->ativos !== (int) $b->lotes_contados
                || ($s->alterado_em && $b->contorno_em && strtotime($s->alterado_em) > strtotime($b->contorno_em));

            $feicoes[] = [
                'type'       => 'Feature',
                'geometry'   => json_decode($b->geojson),
                'properties' => [
                    'nome'          => $b->nome,
                    'nome_oficial'  => $nomes->oficial($b->nome),
                    // Só rótulo do mapa; `nome` continua sendo a chave.
                    'apelido'       => $nomes->apelido($b->nome),
                    'area_ha'       => round((float) $b->area_m2 / 10000, 2),
                    'desatualizado' => $desatualizado,
                    'contorno_em'   => $b->contorno_em ? date('d/m/Y H:i', strtotime($b->contorno_em)) : null,
                    'raio_m'        => (float) $b->raio_m,
                    'isolados'      => json_decode($b->isolados ?? '[]', true) ?: [],
                    // O mapa só pede /api/mapa/quadras de bairro que as tem.
                    'quadras'       => $quadras[$b->nome] ?? 0,
                ],
            ];
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $feicoes,
            // Para a lista da curadoria: bairros com lote e ainda sem contorno.
            'sem_contorno' => $curador
                ? array_values(array_filter(array_keys($situacao), fn ($n) => ! isset($comContorno[$n])))
                : [],
        ]);
    }

    /** GET /api/bairros/lotes?bairro= — os lotes do bairro, para o cálculo no navegador. */
    public function lotes(Request $r): JsonResponse
    {
        if (! $this->podeGerar($r)) {
            return response()->json(['message' => 'Gerar o contorno do bairro é do curador do cadastro.'], 403);
        }
        $d = $r->validate(['bairro' => ['required', 'string', 'max:120']]);

        return response()->json([
            'bairro' => $d['bairro'],
            'lotes'  => array_map(fn ($l) => ['id' => (int) $l->id, 'quadra' => $l->quadra, 'geometry' => json_decode($l->geojson)],
                $this->lotes->lotesDoBairro($d['bairro'])),
            // O que a fusão não pode engolir: lotes de outros bairros em volta.
            'vizinhos' => array_map(fn ($l) => json_decode($l->geojson), $this->lotes->lotesVizinhos($d['bairro'])),
        ]);
    }

    /** POST /api/bairros/contorno — confere e grava o contorno calculado no navegador. */
    public function gravar(Request $r): JsonResponse
    {
        if (! $this->podeGerar($r)) {
            return response()->json(['message' => 'Gerar o contorno do bairro é do curador do cadastro.'], 403);
        }
        $d = $r->validate([
            'bairro'               => ['required', 'string', 'max:120', 'exists:lotes,bairro'],
            'geometry'             => ['required', 'array'],
            'geometry.type'        => ['required', 'in:MultiPolygon'],
            'geometry.coordinates' => ['required', 'array'],
            'raio_m'               => ['required', 'numeric', 'min:0', 'max:200'],
            'lotes_contados'       => ['required', 'integer', 'min:1'],
            'isolados'             => ['nullable', 'array'],
            'isolados.*'           => ['integer'],
            // As quadras vêm no mesmo envio: são do mesmo cálculo, sobre os
            // mesmos lotes, e gravadas juntas nunca ficam de idades diferentes.
            'quadras'                        => ['nullable', 'array', 'max:' . self::MAX_QUADRAS],
            'quadras.*.numero'               => ['required', 'string', 'max:20'],
            'quadras.*.geometry'             => ['required', 'array'],
            'quadras.*.geometry.type'        => ['required', 'in:MultiPolygon'],
            'quadras.*.geometry.coordinates' => ['required', 'array'],
            'quadras.*.rotulo'               => ['required', 'array', 'size:2'],
            'quadras.*.rotulo.*'             => ['numeric', 'between:-180,180'],
            'quadras.*.lotes'                => ['required', 'integer', 'min:1'],
        ]);

        $geojson = json_encode($d['geometry']);
        try {
            $c = $this->lotes->conferirContorno($d['bairro'], $geojson);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'O banco não aceitou o contorno: coordenada fora de faixa ou documento inválido.'], 422);
        }

        if (! $c['valido']) {
            return response()->json(['message' => 'O contorno calculado é inválido (linhas se cruzando). Tente outro raio.'], 422);
        }
        if ($c['total'] > 0 && ($c['total'] - $c['fora']) / $c['total'] < self::COBERTURA_MINIMA) {
            return response()->json(['message' => sprintf(
                'O contorno deixa de fora %d dos %d lotes do bairro. Aumente o raio ou corrija os lotes isolados.',
                $c['fora'], $c['total'])], 422);
        }

        $codigo = (new BairrosDoDesenho())->codigos()[BairrosDoDesenho::chave($d['bairro'])] ?? null;
        $q = DB::transaction(function () use ($d, $codigo, $geojson, $r) {
            $this->lotes->gravarContorno($d['bairro'], $codigo, $geojson, (float) $d['raio_m'],
                (int) $d['lotes_contados'], $d['isolados'] ?? [], $r->user()->id);

            // Sem `quadras` no envio (cliente antigo em cache), as gravadas ficam.
            return array_key_exists('quadras', $d)
                ? $this->lotes->gravarQuadras($d['bairro'], array_map(fn ($q) => [
                    'numero'  => $q['numero'],
                    'geojson' => json_encode($q['geometry']),
                    'lat'     => (float) $q['rotulo'][0],
                    'lon'     => (float) $q['rotulo'][1],
                    'lotes'   => (int) $q['lotes'],
                ], $d['quadras'] ?? []))
                : null;
        });

        $msg = sprintf('Contorno de %s gravado: %s ha', $d['bairro'], number_format($c['area_m2'] / 10000, 2, ',', '.'));
        if ($q) {
            $msg .= $q['gravadas'] === 1 ? ', com 1 quadra' : sprintf(', com %d quadras', $q['gravadas']);
            if ($q['invalidas']) {
                $msg .= sprintf('. %d quadra(s) com desenho inválido ficaram sem contorno: %s',
                    count($q['invalidas']), implode(', ', $q['invalidas']));
            }
        }

        return response()->json([
            'message' => $msg . '.',
            'area_ha' => round($c['area_m2'] / 10000, 2),
            'fora'    => $c['fora'],
            'quadras' => $q['gravadas'] ?? null,
            'quadras_invalidas' => $q['invalidas'] ?? [],
        ]);
    }

    /**
     * GET /api/mapa/quadras?bairro= — contorno e número das quadras de um
     * bairro, para o mapa na escala do bairro. Pedido por bairro (e não por
     * área) porque é assim que o mapa os guarda: um bairro já lido não volta
     * a ser pedido.
     */
    public function quadras(Request $r): JsonResponse
    {
        $d = $r->validate(['bairro' => ['required', 'string', 'max:120']]);

        // Bairro só com lotes em revisão ainda não existe para quem não revisa.
        if (! $this->podeGerar($r)) {
            $s = $this->lotes->situacaoDosBairros()[$d['bairro']] ?? null;
            if (! $s || (int) $s->publicados === 0) {
                return response()->json(['type' => 'FeatureCollection', 'features' => []]);
            }
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => array_map(fn ($q) => [
                'type'       => 'Feature',
                'geometry'   => json_decode($q->geojson),
                'properties' => ['numero' => $q->numero, 'rotulo' => [(float) $q->lat, (float) $q->lon]],
            ], $this->lotes->quadrasDoBairro($d['bairro'])),
        ]);
    }
}
