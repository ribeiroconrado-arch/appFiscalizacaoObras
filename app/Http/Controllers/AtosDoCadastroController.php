<?php

namespace App\Http\Controllers;

use App\Cadastro\AtosDoCadastro;
use App\Models\Lote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A tela do ato (desmembramento, unificação, fração ideal) e a tela
 * "Histórico" (20/11/2026). Só leitura: a troca de identidades continua em
 * IdentidadesController, que exige a curadoria.
 */
class AtosDoCadastroController extends Controller
{
    public function __construct(private AtosDoCadastro $atos) {}

    /** GET /api/atos?tipo=&busca=&pagina= */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tipo'   => ['nullable', 'in:desmembramento,unificacao,fracao_ideal,troca'],
            'busca'  => ['nullable', 'string', 'max:80'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->atos->lista($d['tipo'] ?? null, $d['busca'] ?? null, (int) ($d['pagina'] ?? 1)));
    }

    /** GET /api/atos/{ato} */
    public function mostrar(Request $r, int $ato): JsonResponse
    {
        $a = $this->atos->ato($ato);

        return $a
            ? response()->json($a + ['pode_trocar' => (bool) $r->user()?->podeCurarCadastro()])
            : response()->json(['message' => 'Este ato não existe (pode ter sido desfeito).'], 404);
    }

    /** GET /api/lotes/{lote}/ato — o ato de que o lote é parte (ou mãe). */
    public function doLote(Request $r, Lote $lote): JsonResponse
    {
        $id = $this->atos->atoDoLote($lote->id);

        return $id ? $this->mostrar($r, $id) : response()->json(['message' => 'Este lote não participou de nenhum ato.'], 404);
    }
}
