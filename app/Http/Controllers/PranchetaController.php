<?php
namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Protocolo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PranchetaController extends Controller
{
    private function contexto(Request $r): array {
        $d = $r->validate([
            'tipo' => 'required|in:desmembramento,unificacao',
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'required|integer|distinct|exists:lotes,id',
            'protocolo_id' => 'nullable|integer|exists:protocolos,id',
        ]);
        if (! empty($d['protocolo_id'])) {
            abort_unless($r->user()->canEdit(), 403);
            $p = Protocolo::findOrFail($d['protocolo_id']);
            abort_unless($p->tipo === $d['tipo'] && in_array((int) $p->lote_id, array_map('intval', $d['ids']), true), 422, 'O protocolo não corresponde aos imóveis selecionados.');
        } else { abort_unless($r->user()->podeCurarCadastro(), 403); }
        $d['ids'] = array_map('intval', $d['ids']); sort($d['ids']); return $d;
    }
    private function chave(Request $r, array $d): string {
        return hash('sha256', json_encode([$r->user()->id, $d['tipo'], $d['ids'], $d['protocolo_id'] ?? null]));
    }
    public function carregar(Request $r) {
        $d = $this->contexto($r);
        $registro = DB::table('pranchetas_cadastrais')->where('chave', $this->chave($r, $d))->first();
        return response()->json(['estado' => $registro ? json_decode($registro->estado, true) : null,
            'identidade' => $d['tipo'] === 'desmembramento'
                ? app(\App\Services\DesmembramentoDeLote::class)->identidade(Lote::findOrFail($d['ids'][0])) : null]);
    }
    public function salvar(Request $r) {
        $d = $this->contexto($r);
        abort_if(Lote::whereIn('id', $d['ids'])->where('situacao', '<>', 'ativo')->exists(), 422, 'Um dos imóveis já foi inativado. Consulte a prancha finalizada.');
        $e = $r->validate(['estado' => 'required|array'])['estado'];
        $json = json_encode($e, JSON_THROW_ON_ERROR);
        abort_if(strlen($json) > 2000000, 422, 'O rascunho ultrapassa 2 MB.');
        DB::table('pranchetas_cadastrais')->upsert([[
            'user_id' => $r->user()->id, 'chave' => $this->chave($r, $d), 'estado' => $json,
            'created_at' => now(), 'updated_at' => now(),
        ]], ['chave'], ['estado', 'updated_at']);
        return response()->json(['message' => 'Prancheta salva.']);
    }
    public function historico(Request $r, Lote $lote) {
        abort_unless($r->user()->canEdit(), 403);
        $atos = DB::table('lote_atos')->whereIn('id', DB::table('lote_ato_lotes')->where('lote_id', $lote->id)->select('ato_id'))
            ->whereNotNull('visualizacao')->orderByDesc('id')->get();
        return response()->json(['pranchas' => $atos->map(fn ($a) => [
            'id' => $a->id, 'tipo' => $a->tipo, 'quando' => $a->created_at,
            'visualizacao' => json_decode($a->visualizacao, true),
        ])]);
    }
}
