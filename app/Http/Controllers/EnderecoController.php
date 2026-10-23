<?php

namespace App\Http\Controllers;

use App\Models\CadastroBairro;
use App\Models\Logradouro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O autocomplete de endereço do Posturas (autocomplete.js de lá): a rua pelo
 * nome, com os bairros por onde ela passa; e os bairros — só os da rua
 * escolhida, quando ela tem algum ligado; senão, os da aba Bairros.
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
     * GET /api/enderecos/bairros?q=&rua= — a rua com bairros ligados sugere
     * SÓ eles (`da_rua`, cada um `sugerido`; com um só, a tela preenche
     * sozinha). Rua sem ligação, ou sem rua, sugere a aba Bairros inteira.
     *
     * Até 23/10/2026 os ligados vinham à frente da lista solta herdada do
     * Posturas (`bairros_endereco`), e a Av. Cuiabá sugeria a cidade toda.
     */
    public function bairros(Request $r): JsonResponse
    {
        $q = Logradouro::normalizar($r->query('q', ''));
        $daRua = ($rua = Logradouro::where('nome', Logradouro::normalizar($r->query('rua', '')))->first())
            ? $rua->bairros() : [];
        $casa = fn ($b) => $q === '' || str_contains($b, $q);

        return response()->json([
            'da_rua'  => $daRua !== [],
            'bairros' => $daRua
                ? collect($daRua)->filter($casa)->map(fn ($b) => ['nome' => $b, 'sugerido' => true])->values()
                : collect(CadastroBairro::nomesDeEndereco())->filter($casa)->map(fn ($b) => ['nome' => $b, 'sugerido' => false])->values(),
        ]);
    }
}
