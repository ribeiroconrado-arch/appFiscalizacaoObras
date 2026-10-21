<?php

namespace App\Http\Controllers;

use App\Models\BairroEndereco;
use App\Models\Logradouro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O autocomplete de endereço do Posturas (autocomplete.js de lá): a rua pelo
 * nome, com os bairros por onde ela passa; e os bairros, com os da rua
 * escolhida em primeiro lugar, marcados como sugeridos.
 */
class EnderecoController extends Controller
{
    /** GET /api/enderecos/logradouros?q= — até 30 ruas, cada uma com os bairros. */
    public function logradouros(Request $r): JsonResponse
    {
        $q = Logradouro::normalizar($r->query('q', ''));

        $ruas = Logradouro::with(['vinculos' => fn ($v) => $v->orderBy('bairro')])
            ->when($q !== '', fn ($b) => $b->where('nome', 'like', '%' . $q . '%'))
            ->orderByRaw('CASE WHEN nome LIKE ? THEN 0 ELSE 1 END', [$q . '%'])
            ->orderBy('nome')->limit(30)->get();

        return response()->json(['logradouros' => $ruas->map(fn (Logradouro $l) => [
            'nome' => $l->nome, 'bairros' => $l->vinculos->pluck('bairro'),
        ])]);
    }

    /**
     * GET /api/enderecos/bairros?q=&rua= — até 60 bairros. Os ligados à rua
     * vêm primeiro, com `sugerido`; com um só, a tela preenche sozinha.
     */
    public function bairros(Request $r): JsonResponse
    {
        $q = Logradouro::normalizar($r->query('q', ''));
        $daRua = ($rua = Logradouro::where('nome', Logradouro::normalizar($r->query('rua', '')))->first())
            ? $rua->bairros() : [];

        $outros = BairroEndereco::query()
            ->when($q !== '', fn ($b) => $b->where('nome', 'like', '%' . $q . '%'))
            ->whereNotIn('nome', $daRua)->orderBy('nome')->limit(60)->pluck('nome');

        $sugeridos = collect($daRua)->filter(fn ($b) => $q === '' || str_contains($b, $q));

        return response()->json([
            'bairros' => $sugeridos->map(fn ($b) => ['nome' => $b, 'sugerido' => true])
                ->concat($outros->map(fn ($b) => ['nome' => $b, 'sugerido' => false]))
                ->take(60)->values(),
        ]);
    }
}
