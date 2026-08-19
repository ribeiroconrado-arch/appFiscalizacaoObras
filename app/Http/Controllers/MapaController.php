<?php

namespace App\Http\Controllers;

use App\Repositories\LoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API do mapa e da identificação por GPS.
 *
 * Substitui, no protótipo da Etapa 3, os dois únicos pontos que falavam com
 * dados: o GeoJSON estático e a busca espacial que rodava no navegador.
 */
class MapaController extends Controller
{
    public function __construct(private LoteRepository $lotes) {}

    /**
     * GET /api/mapa/lotes?bbox=oeste,sul,leste,norte
     *
     * Devolve só o que está na tela. Sem o recorte por bbox, o município
     * inteiro (23.662 polígonos) viria numa resposta só e travaria o aparelho
     * do fiscal — que é justamente o dispositivo alvo.
     */
    public function lotes(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'bbox' => ['required', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
        ], [
            'bbox.required' => 'Informe bbox=oeste,sul,leste,norte.',
            'bbox.regex'    => 'bbox deve ter 4 números: oeste,sul,leste,norte.',
        ]);

        [$oeste, $sul, $leste, $norte] = array_map('floatval', explode(',', $dados['bbox']));

        if ($oeste >= $leste || $sul >= $norte) {
            return response()->json(['message' => 'bbox invertido: oeste<leste e sul<norte.'], 422);
        }

        $limite = (int) config('gis.max_lotes');
        $linhas = $this->lotes->porBbox($oeste, $sul, $leste, $norte, $limite);

        $feicoes = array_map(fn ($l) => [
            'type'       => 'Feature',
            'geometry'   => json_decode($l->geojson),
            'properties' => [
                'id'          => $l->id,
                'bairro'      => $l->bairro,
                'quadra'      => $l->quadra,
                'numero_lote' => $l->numero_lote,
                'chave'       => $l->chave,
                // Código oficial do imóvel: é ele que o balão mostra quando
                // existe, no lugar da chave interna de integração.
                'inscricao'   => $l->inscricao_imobiliaria,
                'area_gis_m2' => (float) $l->area_gis_m2,
            ],
        ], $linhas);

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $feicoes,
            // O cliente precisa saber que a resposta bateu no teto, para avisar
            // o fiscal em vez de mostrar um mapa incompleto em silêncio.
            'truncado' => count($feicoes) >= $limite,
        ]);
    }

    /** GET /api/mapa/extensao — retângulo de toda a base, para o mapa abrir enquadrado. */
    public function extensao(): JsonResponse
    {
        return response()->json(['extensao' => $this->lotes->extensao(), 'total' => $this->lotes->total()]);
    }

    /**
     * POST /api/localizacao/identificar {lat, lon, accuracy}
     *
     * Implementa o fluxo do §9 do projeto:
     *   acerto direto → devolve o lote;
     *   sem acerto    → devolve candidatos ordenados, para o fiscal confirmar;
     *   nada perto    → diz isso, em vez de forçar um resultado qualquer.
     */
    public function identificar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'lat'      => ['required', 'numeric', 'between:-90,90'],
            'lon'      => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lat = (float) $d['lat'];
        $lon = (float) $d['lon'];

        // A tolerância acompanha a imprecisão que o próprio aparelho informou:
        // um GPS que se declara preciso a ±40 m não pode ser tratado como um
        // que se declara preciso a ±5 m.
        $tolerancia = min(
            max((float) ($d['accuracy'] ?? 0), (float) config('gis.tolerancia_min')),
            (float) config('gis.tolerancia_max')
        );

        if ($lote = $this->lotes->contendo($lat, $lon)) {
            return response()->json([
                'resultado'  => 'exato',
                'lote'       => $lote,
                'tolerancia' => $tolerancia,
            ]);
        }

        $candidatos = $this->lotes->proximos($lat, $lon, $tolerancia);

        return response()->json([
            'resultado'  => $candidatos ? 'candidatos' : 'nenhum',
            'candidatos' => $candidatos,
            'tolerancia' => $tolerancia,
        ]);
    }
}
