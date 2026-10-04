<?php

namespace App\Http\Controllers;

use App\Support\Cnpj;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Consulta de CNPJ para preencher o autuado — o mesmo recurso do AppPOSTURAS.
 *
 * Lá o navegador chama a BrasilAPI direto. Aqui quem chama é o SERVIDOR: a
 * política de segurança das páginas (CabecalhosDeSeguranca) não deixa o
 * navegador falar com outro domínio, e abrir essa exceção por causa de um
 * campo seria afrouxar a página inteira. De quebra, a resposta fica em cache:
 * o mesmo CNPJ autuado duas vezes não vira duas idas lá fora.
 *
 * Só CNPJ. CPF não tem consulta pública — e nem deveria ter.
 */
class CnpjController extends Controller
{
    /** GET /api/cnpj/{cnpj} */
    public function mostrar(string $cnpj): JsonResponse
    {
        $digitos = preg_replace('/\D/', '', $cnpj);
        if (! Cnpj::valido($digitos)) {
            return response()->json(['message' => 'CNPJ inválido.'], 422);
        }

        $dados = Cache::remember('cnpj:' . $digitos, now()->addDay(), function () use ($digitos) {
            try {
                $r = Http::timeout(8)->acceptJson()->get('https://brasilapi.com.br/api/cnpj/v1/' . $digitos);
            } catch (\Throwable) {
                return null;   // fora do ar: não guarda a falha por um dia — ver abaixo
            }
            if (! $r->successful()) {
                return $r->status() === 404 ? [] : null;
            }
            $d = $r->json();

            return [
                'nome'       => $d['razao_social'] ?? null,
                'logradouro' => trim(($d['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($d['logradouro'] ?? '')) ?: null,
                'numero'     => $d['numero'] ?? null,
                'bairro'     => $d['bairro'] ?? null,
                'cidade'     => $d['municipio'] ?? null,
                'uf'         => $d['uf'] ?? null,
            ];
        });

        if ($dados === null) {
            Cache::forget('cnpj:' . $digitos);

            return response()->json(['message' => 'A consulta de CNPJ está fora do ar. Preencha manualmente.'], 503);
        }
        if ($dados === []) {
            return response()->json(['message' => 'Empresa não encontrada — preencha manualmente.'], 404);
        }

        return response()->json($dados);
    }
}
