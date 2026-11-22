<?php

namespace App\Http\Controllers;

use App\Cadastro\BairrosDoDesenho;
use App\Cadastro\TrechosDeRua;
use App\Models\RuaManual;
use App\Repositories\LoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\FundoDaCidade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
            'oculto'   => ! empty($d['oculto']),
            'excluido' => false,
            'user_id'  => $r->user()->id,
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
     *         excluir  o trecho some inteiro, linha e rótulo: o gerado ganha
     *                  um manual excluído (o "Gerar" o traria de volta); o
     *                  desenhado à mão, que não cobre gerado nenhum, é apagado
     *         voltar   apaga o manual (o item sem id é ignorado)
     */
    public function lote(Request $r): JsonResponse
    {
        if (! $this->podeEditar($r)) {
            return response()->json(['message' => 'Informar nome de rua é do curador do cadastro.'], 403);
        }
        $d = $r->validate([
            'acao'           => ['required', 'in:nome,ocultar,excluir,voltar'],
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

        // O desenhado à mão não tem gerado por baixo: excluí-lo é apagá-lo.
        $geradosDe = [];
        $desenhado = function (RuaManual $m) use (&$geradosDe): bool {
            $g = $geradosDe[$m->bairro] ??= DB::table('ruas_trechos')->where('bairro', $m->bairro)->get()
                ->map(fn ($l) => ['de' => [(float) $l->de_lat, (float) $l->de_lon], 'ate' => [(float) $l->ate_lat, (float) $l->ate_lon]])->all();
            $mm = ['de' => [(float) $m->de_lat, (float) $m->de_lon], 'ate' => [(float) $m->ate_lat, (float) $m->ate_lon]];
            foreach ($g as $x) {
                if (TrechosDeRua::cobre($mm, $x)) {
                    return false;
                }
            }

            return true;
        };

        $n = 0;
        DB::transaction(function () use ($d, $r, $manuais, $desenhado, &$n) {
            foreach ($d['itens'] as $i) {
                $existente = ! empty($i['id']) ? $manuais->get($i['id']) : null;
                if ($d['acao'] === 'excluir' && $existente && $desenhado($existente)) {
                    $existente->delete();
                    $n++;
                    continue;
                }
                if ($d['acao'] === 'voltar') {
                    if ($existente) {
                        $existente->delete();
                        $n++;
                    }
                    continue;
                }
                $dados = [
                    'nome'     => $d['acao'] === 'nome' ? $this->limpo($i['nome'] ?? $d['nome'] ?? null) : null,
                    'oculto'   => $d['acao'] === 'ocultar',
                    'excluido' => $d['acao'] === 'excluir',
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
            'excluir' => ($n === 1 ? '1 trecho excluído' : "{$n} trechos excluídos") . ' do mapa.',
            'voltar'  => "{$n} {$plural} de volta ao nome do cadastro.",
        };

        return response()->json([
            'message' => $msg,
            'ruas'    => collect($bairros)->mapWithKeys(fn ($b) => [$b => $this->lotes->ruasDoBairro($b)])->all(),
        ]);
    }

    /**
     * POST /api/ruas/osm — as vias COM NOME do OpenStreetMap num retângulo
     * (22/11/2026): a ferramenta Nomes de rua busca o nome que falta.
     *
     * Só passa adiante: nada é gravado (a cópia do Fundo da cidade é recortada
     * FORA dos bairros, e aqui o que interessa é justamente dentro deles). O
     * casamento com o trecho e com a lista do cadastro é da tela
     * (ruas-manuais.js); o que o curador aceitar vira nome à mão, como sempre.
     * A resposta fica um dia no cache, para o mesmo retângulo não ir duas vezes
     * ao Overpass.
     */
    public function osm(Request $r): JsonResponse
    {
        if (! $this->podeEditar($r)) {
            return response()->json(['message' => 'Buscar nome de rua é do curador do cadastro.'], 403);
        }
        $d = $r->validate([
            'caixa'   => ['required', 'array', 'size:4'],     // [sul, oeste, norte, leste]
            'caixa.*' => ['numeric', 'between:-180,180'],
        ]);
        [$s, $o, $n, $l] = array_map(fn ($v) => round((float) $v, 4), $d['caixa']);
        if ($n <= $s || $l <= $o || $n - $s > 0.08 || $l - $o > 0.08) {
            return response()->json(['message' => 'Área grande demais para buscar de uma vez. Aproxime o mapa.'], 422);
        }

        $vias = Cache::remember("ruas:osm:$s,$o,$n,$l", now()->addDay(), function () use ($s, $o, $n, $l) {
            $q = '[out:json][timeout:25];way["highway"]["name"](' . "$s,$o,$n,$l" . ');out tags geom;';
            // Um minuto no total: o servidor público às vezes não responde, e a
            // tela não pode ficar esperando quatro servidores lentos em fila.
            $ate = microtime(true) + 55;
            foreach (FundoDaCidade::SERVIDORES as $url) {
                $resta = (int) floor($ate - microtime(true));
                if ($resta < 8) {
                    break;
                }
                try {
                    $resp = Http::connectTimeout(6)->timeout(min(30, $resta))->asForm()
                        ->withHeaders(['User-Agent' => 'FiscalizacaoObras/1.0 (mapa municipal; conferencia de nomes de rua)'])
                        ->post($url, ['data' => $q]);
                    if ($resp->successful() && is_array($resp->json('elements'))) {
                        return collect($resp->json('elements'))
                            ->filter(fn ($e) => ! empty($e['tags']['name']) && count($e['geometry'] ?? []) > 1)
                            ->map(fn ($e) => [
                                'nome'   => $e['tags']['name'],
                                'pontos' => array_map(fn ($p) => [round($p['lat'], 7), round($p['lon'], 7)], $e['geometry']),
                            ])->values()->all();
                    }
                } catch (\Throwable) {
                    // o próximo servidor
                }
            }

            return null;   // null não fica no cache: tenta de novo no próximo pedido
        });

        if ($vias === null) {
            return response()->json(['message' => 'O OpenStreetMap não respondeu. Tente de novo em alguns minutos.'], 502);
        }

        return response()->json(['vias' => $vias]);
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
