<?php

namespace App\Http\Controllers;

use App\Cadastro\BairrosDoDesenho;
use App\Models\RuaManual;
use App\Repositories\LoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Alteração cadastral → Nomes de rua: o curador informa (ou oculta) o nome de
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

    /**
     * POST /api/ruas/manuais/lote — a RUA INTEIRA de uma vez (22/11/2026).
     *
     * A tela junta os trechos (um por lado de quadra) numa rua só, e o nome é
     * dado à rua: aqui chegam todos os trechos dela, que podem atravessar mais
     * de um bairro. Cada um é gravado como seria sozinho — o que já é manual é
     * alterado, o gerado ganha um manual por cima —, tudo numa transação.
     *
     *   acao  nome     cada item leva `nome` próprio, ou o `nome` geral
     *         ocultar  o rótulo some do mapa
     *         voltar   apaga o manual (o item sem id é ignorado)
     */
    public function lote(Request $r): JsonResponse
    {
        if (! $this->podeEditar($r)) {
            return response()->json(['message' => 'Informar nome de rua é do curador do cadastro.'], 403);
        }
        $d = $r->validate([
            'acao'           => ['required', 'in:nome,ocultar,voltar'],
            'nome'           => ['nullable', 'string', 'max:180'],
            'itens'          => ['required', 'array', 'min:1', 'max:500'],
            'itens.*.id'     => ['nullable', 'integer'],
            'itens.*.bairro' => ['required', 'string', 'max:120'],
            'itens.*.nome'   => ['nullable', 'string', 'max:180'],
            'itens.*.de'     => ['required', 'array', 'size:2'],
            'itens.*.de.*'   => ['numeric', 'between:-180,180'],
            'itens.*.ate'    => ['required', 'array', 'size:2'],
            'itens.*.ate.*'  => ['numeric', 'between:-180,180'],
        ]);

        $bairros = array_values(array_unique(array_column($d['itens'], 'bairro')));
        $existem = DB::table('lotes')->whereIn('bairro', $bairros)->distinct()->pluck('bairro')->all();
        if ($faltam = array_diff($bairros, $existem)) {
            return response()->json(['message' => 'Bairro desconhecido: ' . implode(', ', $faltam) . '.'], 422);
        }

        if ($d['acao'] === 'nome') {
            foreach ($d['itens'] as $i) {
                $nome = $this->limpo($i['nome'] ?? $d['nome'] ?? null);
                if ($nome === null) {
                    return response()->json(['message' => 'Escolha o logradouro.'], 422);
                }
                if ($erro = $this->conferirNome($r, ['bairro' => $i['bairro'], 'nome' => $nome])) {
                    return $erro;
                }
            }
        }

        $ids = array_values(array_filter(array_column($d['itens'], 'id')));
        $manuais = RuaManual::whereKey($ids)->get()->keyBy('id');

        $n = 0;
        DB::transaction(function () use ($d, $r, $manuais, &$n) {
            foreach ($d['itens'] as $i) {
                $existente = ! empty($i['id']) ? $manuais->get($i['id']) : null;
                if ($d['acao'] === 'voltar') {
                    if ($existente) {
                        $existente->delete();
                        $n++;
                    }
                    continue;
                }
                $dados = [
                    'nome'    => $d['acao'] === 'ocultar' ? null : $this->limpo($i['nome'] ?? $d['nome'] ?? null),
                    'oculto'  => $d['acao'] === 'ocultar',
                    'user_id' => $r->user()->id,
                ];
                if ($existente) {
                    $existente->update($dados);
                } else {
                    RuaManual::create($dados + [
                        'bairro' => $i['bairro'],
                        'de_lat' => $i['de'][0], 'de_lon' => $i['de'][1], 'ate_lat' => $i['ate'][0], 'ate_lon' => $i['ate'][1],
                    ]);
                }
                $n++;
            }
        });

        $plural = $n === 1 ? 'trecho' : 'trechos';
        $msg = match ($d['acao']) {
            'nome'    => "Nome gravado em {$n} {$plural}.",
            'ocultar' => $n === 1 ? '1 trecho oculto.' : "{$n} trechos ocultos.",
            'voltar'  => "{$n} {$plural} de volta ao nome do cadastro.",
        };

        return response()->json([
            'message' => $msg,
            'ruas'    => collect($bairros)->mapWithKeys(fn ($b) => [$b => $this->lotes->ruasDoBairro($b)])->all(),
        ]);
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
