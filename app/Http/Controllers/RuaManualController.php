<?php

namespace App\Http\Controllers;

use App\Cadastro\BairrosDoDesenho;
use App\Models\RuaManual;
use App\Repositories\LoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correção cadastral → Nomes de rua: o curador informa (ou oculta) o nome de
 * um trecho de rua. Ver public/js/ruas-manuais.js.
 *
 * O nome é ESCOLHIDO da lista de logradouros do cadastro do bairro — digitado
 * à mão nasceria "R. ACACIAS" ao lado de "RUA DAS ACÁCIAS". Fora da lista, só
 * o administrador (rua que ainda não entrou no cadastro). Bairro sem cadastro
 * amarrado não tem lista, e aí o nome é livre.
 *
 * Toda resposta traz os trechos do bairro já recalculados: o mapa redesenha
 * sem novo pedido.
 */
class RuaManualController extends Controller
{
    public function __construct(private LoteRepository $lotes) {}

    private function podeEditar(Request $r): bool
    {
        return $r->user()->podeCurarCadastro() || $r->user()->isAdmin();
    }

    /** GET /api/ruas/logradouros?bairro= — a lista oficial do bairro, para o combo. */
    public function logradouros(Request $r): JsonResponse
    {
        if (! $this->podeEditar($r)) {
            return response()->json(['message' => 'Informar nome de rua é do curador do cadastro.'], 403);
        }
        $d = $r->validate(['bairro' => ['required', 'string', 'max:120']]);
        $codigo = $this->codigo($d['bairro']);

        return response()->json([
            'cadastro'    => $codigo !== null,
            'logradouros' => $codigo !== null ? $this->lotes->logradourosDoBairro($codigo) : [],
            'livre'       => $codigo === null || $r->user()->isAdmin(),
        ]);
    }

    /** POST /api/ruas/manuais — informa o nome de um trecho (ou o oculta). */
    public function criar(Request $r): JsonResponse
    {
        if (! $this->podeEditar($r)) {
            return response()->json(['message' => 'Informar nome de rua é do curador do cadastro.'], 403);
        }
        $d = $r->validate([
            'bairro' => ['required', 'string', 'max:120', 'exists:lotes,bairro'],
            'de'     => ['required', 'array', 'size:2'],
            'de.*'   => ['numeric', 'between:-180,180'],
            'ate'    => ['required', 'array', 'size:2'],
            'ate.*'  => ['numeric', 'between:-180,180'],
        ] + $this->regrasDoNome());

        if ($erro = $this->conferirNome($r, $d)) {
            return $erro;
        }

        RuaManual::create([
            'bairro' => $d['bairro'],
            'nome'   => ! empty($d['oculto']) ? null : $this->limpo($d['nome'] ?? null),
            'oculto' => ! empty($d['oculto']),
            'de_lat' => $d['de'][0], 'de_lon' => $d['de'][1], 'ate_lat' => $d['ate'][0], 'ate_lon' => $d['ate'][1],
            'user_id' => $r->user()->id,
        ]);

        return $this->resposta($d['bairro'], ! empty($d['oculto']) ? 'Trecho oculto.' : 'Nome de rua gravado.');
    }

    /** PUT /api/ruas/manuais/{rua} — troca o nome, ou oculta. */
    public function alterar(Request $r, RuaManual $rua): JsonResponse
    {
        if (! $this->podeEditar($r)) {
            return response()->json(['message' => 'Informar nome de rua é do curador do cadastro.'], 403);
        }
        $d = $r->validate($this->regrasDoNome()) + ['bairro' => $rua->bairro];
        if ($erro = $this->conferirNome($r, $d)) {
            return $erro;
        }

        $rua->update([
            'nome'    => ! empty($d['oculto']) ? null : $this->limpo($d['nome'] ?? null),
            'oculto'  => ! empty($d['oculto']),
            'user_id' => $r->user()->id,
        ]);

        return $this->resposta($rua->bairro, ! empty($d['oculto']) ? 'Trecho oculto.' : 'Nome de rua gravado.');
    }

    /** DELETE /api/ruas/manuais/{rua} — volta ao que o cadastro diz. */
    public function excluir(Request $r, RuaManual $rua): JsonResponse
    {
        if (! $this->podeEditar($r)) {
            return response()->json(['message' => 'Informar nome de rua é do curador do cadastro.'], 403);
        }
        $bairro = $rua->bairro;
        $rua->delete();

        return $this->resposta($bairro, 'O trecho voltou ao nome do cadastro.');
    }

    /** @return array<string,mixed> */
    private function regrasDoNome(): array
    {
        return [
            'nome'   => ['nullable', 'string', 'max:180', 'required_unless:oculto,true'],
            'oculto' => ['nullable', 'boolean'],
        ];
    }

    /** Nome fora da lista do cadastro do bairro: só administrador, ou bairro sem cadastro. */
    private function conferirNome(Request $r, array $d): ?JsonResponse
    {
        if (! empty($d['oculto']) || $r->user()->isAdmin()) {
            return null;
        }
        $codigo = $this->codigo($d['bairro']);
        if ($codigo === null) {
            return null;
        }
        $nome = $this->limpo($d['nome'] ?? null);
        $lista = array_map(fn ($n) => mb_strtoupper(trim($n)), $this->lotes->logradourosDoBairro($codigo));
        if ($nome === null || ! in_array(mb_strtoupper($nome), $lista, true)) {
            return response()->json(['message' => 'Escolha o logradouro na lista do cadastro do bairro. '
                . 'Rua que ainda não está no cadastro só o administrador informa.'], 422);
        }

        return null;
    }

    private function codigo(string $bairro): ?string
    {
        return (new BairrosDoDesenho())->codigos()[BairrosDoDesenho::chave($bairro)] ?? null;
    }

    private function limpo(?string $nome): ?string
    {
        $nome = $nome === null ? null : preg_replace('/\s+/u', ' ', trim($nome));

        return $nome === '' ? null : $nome;
    }

    private function resposta(string $bairro, string $mensagem): JsonResponse
    {
        return response()->json(['message' => $mensagem, 'bairro' => $bairro, 'ruas' => $this->lotes->ruasDoBairro($bairro)]);
    }
}
