<?php

namespace App\Http\Controllers;

use App\Cadastro\BairrosDoDesenho;
use App\Repositories\LoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
     * GET /api/mapa/google-sessao
     *
     * Token de sessão da Map Tiles API do Google.
     *
     * O Google não serve tile por URL fixa como Esri e Mapbox: é preciso
     * abrir uma sessão e repetir o token em cada requisição. O token dura
     * cerca de duas semanas, então é criado no SERVIDOR e guardado em cache —
     * se cada aparelho abrisse a própria sessão, seriam dezenas de chamadas
     * por dia à API só para começar a desenhar o mapa.
     *
     * A chave ainda vai para o navegador, porque a URL do tile a exige; o que
     * a protege é a restrição por referrer no console do Google.
     */
    public function googleSessao(): JsonResponse
    {
        $chave = config('gis.google_key');
        if (! $chave) {
            return response()->json(['message' => 'Google Maps não configurado.'], 404);
        }

        $dados = Cache::remember('mapa:google-sessao', now()->addDays(10), function () use ($chave) {
            $r = Http::timeout(15)
                ->post('https://tile.googleapis.com/v1/createSession?key=' . $chave, [
                    'mapType'  => 'satellite',
                    'language' => 'pt-BR',
                    'region'   => 'BR',
                ]);

            // Sem sessão não há tile. Devolver null mantém o mapa funcionando
            // com o satélite da Esri em vez de derrubar a tela.
            if (! $r->successful()) {
                return ['erro' => $r->json('error.message') ?? 'falha ao abrir sessão'];
            }

            return [
                'session'   => $r->json('session'),
                'expiry'    => $r->json('expiry'),
                'creditos'  => $this->creditosDaImagem($r->json('session'), $chave),
            ];
        });

        if (isset($dados['erro'])) {
            // Não fica em cache um erro que o administrador pode já ter
            // corrigido no console do Google.
            Cache::forget('mapa:google-sessao');
            return response()->json(['message' => $dados['erro']], 502);
        }

        return response()->json([
            'session'  => $dados['session'],
            // A chave que vai para o NAVEGADOR é outra: a do servidor fica
            // trancada por IP e não pode sair daqui. Ver config/gis.php.
            'key'      => config('gis.google_key_navegador'),
            'creditos' => $dados['creditos'] ?? null,
        ]);
    }

    /**
     * Crédito da imagem aérea: quem a produziu e de que ano ela é.
     *
     * ── Sobre a DATA ──
     *
     * A Map Tiles API não devolve a data de captura de cada imagem. Foi
     * conferido contra a API, não suposto: o endpoint `viewport` responde só
     * `copyright` e `maxZoomRects` — nada de `imageryDate`. O ano do copyright
     * é a informação de tempo que existe, e é ela que vai para a tela. Dizer
     * "imagem de 2026" quando o Google só afirma o ano do direito autoral já é
     * o limite do que o dado sustenta; inventar dia e mês seria pior do que
     * não mostrar nada, porque numa fiscalização a data da imagem é prova.
     *
     * ── Sobre a obrigação ──
     *
     * Exibir este crédito não é enfeite: os termos da API exigem a atribuição
     * que o `viewport` devolve, e ela muda conforme o fornecedor da imagem
     * naquela área (aqui, Airbus e Maxar). O "© Google" fixo que estava no
     * código não cumpria isso.
     *
     * Uma chamada por sessão, guardada junto dela: a área do município inteiro
     * tem o mesmo crédito, e refazer a consulta a cada movimento do mapa seria
     * uma requisição paga por arrasto de dedo.
     */
    private function creditosDaImagem(?string $sessao, string $chave): ?string
    {
        $ext = $this->lotes->extensao();
        if (! $sessao || ! $ext) {
            return null;
        }

        $r = Http::timeout(10)->get('https://tile.googleapis.com/tile/v1/viewport', [
            'session' => $sessao,
            'key'     => $chave,
            'zoom'    => 18,
            'north'   => $ext['norte'], 'south' => $ext['sul'],
            'east'    => $ext['leste'], 'west'  => $ext['oeste'],
        ]);

        // Sem crédito o mapa continua funcionando — mostra o "© Google" de
        // sempre. Falhar aqui derrubaria a camada inteira por causa de um
        // rodapé.
        return $r->successful() ? $r->json('copyright') : null;
    }

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

        // A tradução bairro-do-desenho → código-do-cadastro é lida UMA VEZ:
        // são milhares de feições por requisição, e uma consulta por lote
        // poria o mapa de joelhos.
        $bairros = new BairrosDoDesenho();

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
                //
                // DERIVADA das partes do lote, e não lida da coluna — que está
                // vazia nos 2.239. Era isso que fazia a ficha aberta pelo mapa
                // cair no montador do navegador, que escrevia 000 no lugar do
                // bairro que ele não tinha como conhecer.
                'inscricao'   => $bairros->inscricaoDe($l),
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
