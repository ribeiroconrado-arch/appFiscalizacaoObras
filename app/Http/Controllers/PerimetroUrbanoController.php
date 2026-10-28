<?php

namespace App\Http\Controllers;

use App\Models\PerimetroUrbano;
use App\Services\PerimetrosUrbanos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Perímetro urbano pela tela de Importações — ver App\Services\PerimetrosUrbanos.
 *
 * QUEM PODE O QUÊ (o mesmo da importação de loteamento):
 *   qualquer usuário        vê o perímetro vigente no mapa
 *   curador do cadastro     carrega o rascunho, corrige a lei, descarta, vê o histórico
 *   administrador           publica
 */
class PerimetroUrbanoController extends Controller
{
    /** Teto do arquivo, em KB. Um perímetro detalhado dá poucas centenas de KB. */
    private const TETO_KB = 20480;

    public function __construct(private PerimetrosUrbanos $perimetros) {}

    private function exigirCurador(Request $r): ?JsonResponse
    {
        return $r->user()->podeCurarCadastro()
            ? null
            : response()->json(['message' => 'A atualização do perímetro urbano é do curador do cadastro.'], 403);
    }

    private function recusa(RuntimeException $ex): JsonResponse
    {
        return response()->json(['message' => $ex->getMessage()], 422);
    }

    /** GET /api/perimetros — todas as versões, a vigente e os rascunhos primeiro. */
    public function index(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }

        $lista = PerimetroUrbano::with('usuario:id,name')
            ->orderByRaw("FIELD(situacao, 'rascunho', 'vigente', 'anterior')")
            ->orderByDesc('vigente_desde')->orderByDesc('id')
            ->get()->map(fn ($p) => $this->resumo($p));

        return response()->json(['perimetros' => $lista, 'pode_publicar' => $r->user()->isAdmin()]);
    }

    /** POST /api/perimetros/conferir — lê o arquivo e compara, sem gravar. */
    public function conferir(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $arq = $this->arquivoEnviado($r);

        try {
            $l = $this->perimetros->lerArquivo($arq->getRealPath());
            $c = $this->perimetros->comparar($l['geometry']);
        } catch (RuntimeException $ex) {
            return $this->recusa($ex);
        }
        unset($l['geometry']);

        return response()->json(['arquivo' => $arq->getClientOriginalName()] + $l + ['comparacao' => $c]);
    }

    /** POST /api/perimetros — grava como rascunho. */
    public function carregar(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $arq = $this->arquivoEnviado($r);
        $lei = $this->dadosDaLei($r);

        try {
            $p = $this->perimetros->carregar($arq->getRealPath(), $arq->getClientOriginalName(), $lei, $r->user());
        } catch (RuntimeException $ex) {
            return $this->recusa($ex);
        }

        return response()->json(['message' => 'Perímetro carregado como rascunho. Confira no mapa e peça a publicação ao administrador.',
            'id' => $p->id], 201);
    }

    // ── pelo memorial descritivo ──────────────────────────────────

    /** POST /api/perimetros/memorial/conferir — lê e confere, sem gravar. */
    public function conferirMemorial(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $d = $this->dadosDoMemorial($r);

        try {
            $m = $this->perimetros->lerMemorial($d['texto'], $d['vertices'], $d['area'], $d['perimetro']);
            $comparacao = $m['geometry'] ? $this->perimetros->comparar($m['geometry']) : null;
        } catch (RuntimeException $ex) {
            return $this->recusa($ex);
        }
        unset($m['geometry']);

        return response()->json($m + ['comparacao' => $comparacao]);
    }

    /** POST /api/perimetros/memorial — grava como rascunho. */
    public function carregarMemorial(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $d = $this->dadosDoMemorial($r);

        try {
            $p = $this->perimetros->carregarMemorial($d['texto'], $d['vertices'], $d['area'], $d['perimetro'], $this->dadosDaLei($r), $r->user());
        } catch (RuntimeException $ex) {
            return $this->recusa($ex);
        }

        return response()->json(['message' => 'Perímetro gerado pelo memorial e carregado como rascunho.', 'id' => $p->id], 201);
    }

    /** GET /api/perimetros/{perimetro}/memorial — os vértices, para editar ou para a versão seguinte. */
    public function memorial(Request $r, PerimetroUrbano $perimetro): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }

        return response()->json([
            'id'          => $perimetro->id,
            'rotulo'      => $perimetro->rotulo(),
            'situacao'    => $perimetro->situacao,
            'vertices'    => $perimetro->vertices,
            'texto'       => $perimetro->memorial_texto,
            'conferencia' => $perimetro->conferencia_memorial,
        ]);
    }

    /** POST /api/perimetros/{perimetro}/memorial — o rascunho com os vértices corrigidos. */
    public function refazerMemorial(Request $r, PerimetroUrbano $perimetro): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $d = $this->dadosDoMemorial($r);

        try {
            $this->perimetros->refazerPeloMemorial($perimetro, $d['texto'], $d['vertices'], $d['area'], $d['perimetro']);
            if ($lei = array_filter($this->dadosDaLei($r), fn ($v) => $v !== null)) {
                $this->perimetros->atualizarLei($perimetro->fresh(), $lei);
            }
        } catch (RuntimeException $ex) {
            return $this->recusa($ex);
        }

        return response()->json(['message' => 'Rascunho refeito com os vértices corrigidos.']);
    }

    /** @return array{texto:?string, vertices:?array, area:?float, perimetro:?float} */
    private function dadosDoMemorial(Request $r): array
    {
        $d = $r->validate([
            'texto'                  => ['nullable', 'string', 'max:500000'],
            'vertices'               => ['nullable', 'array', 'max:5000'],
            'vertices.*.p'           => ['nullable', 'string', 'max:20'],
            'vertices.*.n'           => ['nullable', 'numeric'],
            'vertices.*.e'           => ['nullable', 'numeric'],
            'vertices.*.azimute'     => ['nullable', 'string', 'max:20'],
            'vertices.*.distancia'   => ['nullable', 'numeric'],
            'vertices.*.trecho'      => ['nullable', 'in:seca,corrego,serra,estrada'],
            'area_declarada_m2'      => ['nullable', 'numeric', 'min:0'],
            'perimetro_declarado_m'  => ['nullable', 'numeric', 'min:0'],
        ]);

        return [
            'texto'     => $d['texto'] ?? null,
            'vertices'  => $d['vertices'] ?? null,
            'area'      => isset($d['area_declarada_m2']) ? (float) $d['area_declarada_m2'] : null,
            'perimetro' => isset($d['perimetro_declarado_m']) ? (float) $d['perimetro_declarado_m'] : null,
        ];
    }

    /** GET /api/perimetros/{perimetro} — a ficha, com a comparação de agora. */
    public function mostrar(Request $r, PerimetroUrbano $perimetro): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }

        return response()->json($this->resumo($perimetro) + [
            'observacao' => $perimetro->observacao,
            'fonte'      => $perimetro->fonte,
            'camada'     => $perimetro->camada,
            'comparacao' => $this->perimetros->comparacaoAtual($perimetro),
            'origem'     => $perimetro->origem,
            // Sem a linha de fechamento (o primeiro vértice repetido no fim).
            'vertices_total' => is_array($v = $perimetro->vertices)
                ? count($v) - (count($v) > 3 && ($v[0]['p'] ?? null) === (end($v)['p'] ?? '') ? 1 : 0) : null,
            'conferencia_memorial' => $perimetro->conferencia_memorial,
            'pode_publicar' => $r->user()->isAdmin(),
        ]);
    }

    /** PATCH /api/perimetros/{perimetro} — a lei do rascunho. */
    public function atualizar(Request $r, PerimetroUrbano $perimetro): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }

        try {
            $this->perimetros->atualizarLei($perimetro, $this->dadosDaLei($r));
        } catch (RuntimeException $ex) {
            return $this->recusa($ex);
        }

        return response()->json(['message' => 'Dados da lei gravados.']);
    }

    /** GET /api/perimetros/{perimetro}/mapa — o perímetro, o vigente e o que entra e sai, para desenhar. */
    public function mapa(Request $r, PerimetroUrbano $perimetro): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }

        $novo = $this->perimetros->geometria($perimetro);
        $vig = $this->perimetros->vigente();
        $comparado = $vig && $vig->id !== $perimetro->id && $perimetro->situacao === 'rascunho' ? $vig : null;
        [$entra, $sai] = $comparado
            ? $this->perimetros->diferencas(json_encode($novo), json_encode($this->perimetros->geometria($comparado)))
            : [null, null];

        return response()->json([
            'perimetro' => $novo,
            'vigente'   => $comparado ? $this->perimetros->geometria($comparado) : null,
            'entra'     => $entra,
            'sai'       => $sai,
        ]);
    }

    /** POST /api/perimetros/{perimetro}/publicar — só administrador. */
    public function publicar(Request $r, PerimetroUrbano $perimetro): JsonResponse
    {
        if (! $r->user()->isAdmin()) {
            return response()->json(['message' => 'Publicar o perímetro urbano é do administrador.'], 403);
        }

        try {
            $this->perimetros->publicar($perimetro, $r->user());
        } catch (RuntimeException $ex) {
            return $this->recusa($ex);
        }

        return response()->json(['message' => 'Perímetro publicado: ' . $perimetro->fresh()->rotulo() . ' passa a valer no mapa.']);
    }

    /** POST /api/perimetros/{perimetro}/descartar — rascunho; quem carregou ou o administrador. */
    public function descartar(Request $r, PerimetroUrbano $perimetro): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        if ($perimetro->user_id !== $r->user()->id && ! $r->user()->isAdmin()) {
            return response()->json(['message' => 'Só quem carregou o rascunho, ou o administrador, o descarta.'], 403);
        }

        try {
            $this->perimetros->descartar($perimetro);
        } catch (RuntimeException $ex) {
            return $this->recusa($ex);
        }

        return response()->json(['message' => 'Rascunho do perímetro descartado.']);
    }

    /** GET /api/mapa/perimetro — o vigente, para a camada do mapa (todos os usuários). */
    public function vigente(): JsonResponse
    {
        $p = $this->perimetros->vigente();

        return response()->json($p ? [
            'rotulo'        => $p->rotulo(),
            'vigente_desde' => $p->vigente_desde?->format('d/m/Y'),
            'area_m2'       => $p->area_m2,
            'geometry'      => $this->perimetros->geometria($p),
        ] : ['geometry' => null]);
    }

    /** GET /api/mapa/area-visivel — município (IBGE) + perímetro vigente: o que a máscara do mapa deixa à vista. */
    public function areaVisivel(): JsonResponse
    {
        return response()->json(['geometry' => $this->perimetros->areaDoMapa()]);
    }

    // ─────────────────────────────────────────────────────────────

    /** @return \Illuminate\Http\UploadedFile */
    private function arquivoEnviado(Request $r)
    {
        $r->validate(['arquivo' => ['required', 'file', 'max:' . self::TETO_KB]], [
            'arquivo.required' => 'Escolha o arquivo .geojson do perímetro.',
            'arquivo.max'      => 'O arquivo passa de 20 MB: não parece um perímetro.',
        ]);
        $arq = $r->file('arquivo');
        abort_unless(in_array(strtolower($arq->getClientOriginalExtension()), ['geojson', 'json'], true), 422,
            'O arquivo precisa ser .geojson — gere-o no conversor, com a camada no papel "Perímetro urbano".');

        return $arq;
    }

    /** @return array<string,?string> */
    private function dadosDaLei(Request $r): array
    {
        return $r->validate([
            'lei_numero'    => ['nullable', 'string', 'max:40'],
            'lei_data'      => ['nullable', 'date'],
            'vigente_desde' => ['nullable', 'date'],
            'observacao'    => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function resumo(PerimetroUrbano $p): array
    {
        return [
            'id'            => $p->id,
            'situacao'      => $p->situacao,
            'situacao_rotulo' => PerimetroUrbano::SITUACOES[$p->situacao] ?? $p->situacao,
            'rotulo'        => $p->rotulo(),
            'lei_numero'    => $p->lei_numero,
            'lei_data'      => $p->lei_data?->format('Y-m-d'),
            'vigente_desde' => $p->vigente_desde?->format('Y-m-d'),
            'vigente_ate'   => $p->vigente_ate?->format('Y-m-d'),
            'area_m2'       => $p->area_m2,
            'arquivo'       => $p->arquivo_nome,
            'enviado_por'   => $p->usuario?->name,
            'enviado_em'    => $p->created_at?->format('d/m/Y H:i'),
            'publicado_em'  => $p->publicado_em?->format('d/m/Y H:i'),
            'meu'           => $p->user_id === auth()->id(),
        ];
    }
}
