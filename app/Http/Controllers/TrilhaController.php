<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Services\DesfazerAlteracao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A trilha de alterações do cadastro — quem mexeu no quê, e o que mudou.
 *
 * O dado sempre existiu: `RegistraAuditoria` é um trait de eventos do Eloquent,
 * e por isso nenhuma das operações escapa dele. O que não existia era como
 * chegar até ele: havia as 12 linhas mais recentes no Painel, e mais nada. Uma
 * unificação empurra cinco linhas de uma vez — três atos e o feed já perdeu a
 * primeira.
 *
 * ── Por que só administrador e curador ──
 *
 * A trilha mostra o nome de quem fez cada coisa. Num órgão isso tem peso: não é
 * a mesma coisa que ver o que foi feito. O fiscal continua com a "Atividade
 * recente" do Painel, que é o resumo — não o dossiê.
 */
class TrilhaController extends Controller
{
    /** Quanto tempo depois do ato ele ainda pode ser desfeito. */
    public const DIAS_PARA_DESFAZER = 7;

    /**
     * As ações que a tela sabe reverter, e o que cada uma exige.
     *
     * Ficam aqui e não espalhadas em `if`s porque a mesma lista responde três
     * perguntas: o que o filtro oferece, se o botão acende, e o que a rota de
     * desfazer aceita. Três cópias divergem no primeiro ajuste.
     */
    public const REVERSIVEIS = ['corrigiu quadra', 'renumerou', 'unificou', 'excluiu'];

    public function index(Request $request): JsonResponse
    {
        if ($erro = $this->exigirPermissao($request)) {
            return $erro;
        }

        $d = $request->validate([
            'lote_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'acao'    => ['nullable', 'string', 'max:40'],
            'dias'    => ['nullable', 'integer', 'min:1', 'max:3650'],
            'busca'   => ['nullable', 'string', 'max:120'],
        ]);

        $q = DB::table('auditoria')->orderByDesc('id');

        if (! empty($d['lote_id'])) {
            $q->where('tabela', 'lotes')->where('registro_id', $d['lote_id']);
        }
        if (! empty($d['user_id'])) {
            $q->where('user_id', $d['user_id']);
        }
        if (! empty($d['acao'])) {
            $q->where('acao', $d['acao']);
        }
        if (! empty($d['dias'])) {
            $q->where('created_at', '>=', now()->subDays((int) $d['dias']));
        }
        if (! empty($d['busca'])) {
            $t = trim($d['busca']);
            $q->where(fn ($s) => $s->where('descricao', 'like', "%{$t}%")
                ->orWhere('usuario_nome', 'like', "%{$t}%"));
        }

        // O teto é do SERVIDOR, não da tela: sem ele, um filtro largo numa base
        // de anos traria tudo para o navegador de uma vez.
        $linhas = $q->limit(300)->get();

        // Os lotes citados, numa consulta só — e não uma por linha. Lote
        // apagado não vem: é justamente o caso em que a trilha é o único
        // lugar onde ele ainda aparece, e o rótulo sai do próprio registro.
        $idsLote = $linhas->where('tabela', 'lotes')->pluck('registro_id')->filter()->unique();
        $lotes = $idsLote->isEmpty()
            ? collect()
            : Lote::whereIn('id', $idsLote)->get()->keyBy('id');

        return response()->json([
            'linhas'  => $linhas->map(fn ($a) => $this->linha($a, $lotes))->values(),
            'truncou' => $linhas->count() === 300,
            'opcoes'  => [
                'acoes'   => DB::table('auditoria')->distinct()->orderBy('acao')->pluck('acao'),
                'pessoas' => DB::table('auditoria')->whereNotNull('user_id')
                    ->select('user_id', 'usuario_nome')->distinct()
                    ->orderBy('usuario_nome')->get(),
            ],
        ]);
    }

    /**
     * Uma linha da trilha, já com o que mudou de quê para quê.
     *
     * O antes/depois é o que a "Atividade recente" não mostra, e é ele que
     * responde a pergunta de quem abre a tela: não "houve uma alteração", mas
     * "a quadra era 12 e virou 510".
     *
     * @param  \Illuminate\Support\Collection<int,Lote>  $lotes
     * @return array<string,mixed>
     */
    private function linha(object $a, $lotes): array
    {
        $lote = $a->tabela === 'lotes' ? $lotes->get($a->registro_id) : null;
        $antes = json_decode($a->dados_anteriores ?? 'null', true) ?: [];
        $novos = json_decode($a->dados_novos ?? 'null', true) ?: [];

        // Campos que não são a alteração, e sim o carimbo dela: mostrá-los
        // faria toda linha dizer "updated_at mudou", que é ruído sobre ruído.
        $ruido = ['updated_at', 'created_at', 'chave', 'chave_identidade'];
        $mudou = [];
        foreach (array_keys($novos) as $c) {
            if (in_array($c, $ruido, true)) {
                continue;
            }
            $mudou[] = [
                'campo' => $c,
                'de'    => $this->curto($antes[$c] ?? null),
                'para'  => $this->curto($novos[$c] ?? null),
            ];
        }

        return [
            'id'       => $a->id,
            'quando'   => date('d/m/Y H:i', strtotime($a->created_at)),
            'acao'     => $a->acao,
            'tabela'   => $a->tabela,
            'registro' => $a->registro_id,
            'alvo'     => $lote?->rotulo() ?? ($a->descricao ?: '—'),
            'bairro'   => $lote?->bairro,
            'quem'     => $a->usuario_nome ?? 'sistema',
            'matricula' => $a->matricula,
            'mudou'    => array_slice($mudou, 0, 4),
            'desfazer' => $this->podeDesfazer($a),
        ];
    }

    /**
     * Se esta linha pode ser desfeita, ou por que não.
     *
     * Devolve sempre um motivo quando não pode: botão apagado sem explicação
     * manda a pessoa adivinhar, e a explicação é curta.
     *
     * @return array{pode: bool, motivo: string|null}
     */
    /**
     * Valor legível numa célula, e não o conteúdo inteiro.
     *
     * A trilha guarda o valor cru, e valor cru pode ser um data URL de PNG de
     * 300 KB — foi o que uma rubrica de perfil produziu antes de `assinatura`
     * entrar na lista de campos ocultos. Mesmo com a causa corrigida, as linhas
     * ANTIGAS continuam lá, e a tela tem de aguentá-las.
     */
    private function curto(mixed $v): mixed
    {
        if (! is_string($v) || mb_strlen($v) <= 60) {
            return $v;
        }
        return mb_substr($v, 0, 57) . chr(0xE2) . chr(0x80) . chr(0xA6);
    }

    private function podeDesfazer(object $a): array
    {
        if ($a->tabela !== 'lotes' || ! in_array($a->acao, self::REVERSIVEIS, true)) {
            return ['pode' => false, 'motivo' => null];
        }

        $dias = now()->diffInDays($a->created_at, true);
        if ($dias > self::DIAS_PARA_DESFAZER) {
            return ['pode' => false, 'motivo' => 'Passou de ' . self::DIAS_PARA_DESFAZER . ' dias'];
        }

        if ($a->acao === 'excluiu') {
            $g = DB::table('lotes_apagados')->where('lote_id', $a->registro_id)->first();
            if (! $g) {
                // Apagado antes de o desenho passar a ser guardado. Não é um
                // impedimento contornável: o polígono não existe em lugar
                // nenhum, e a tela precisa dizer isso em vez de falhar no clique.
                return ['pode' => false, 'motivo' => 'Apagado antes de o desenho ser guardado'];
            }
            if ($g->restaurado_em) {
                return ['pode' => false, 'motivo' => 'Já restaurado como lote ' . $g->restaurado_como];
            }
            return ['pode' => true, 'motivo' => null];
        }

        // Alteração cujo lote já não existe: não há onde escrever a volta.
        if (! Lote::whereKey($a->registro_id)->exists()) {
            return ['pode' => false, 'motivo' => 'O lote não existe mais'];
        }

        return ['pode' => true, 'motivo' => null];
    }

    /**
     * POST /api/trilha/{auditoria}/desfazer
     *
     * A reversão é ATO NOVO: a linha original fica, e esta operação grava a sua
     * própria — com o nome de quem desfez. Ver DesfazerAlteracao.
     */
    public function desfazer(Request $request, int $id, DesfazerAlteracao $svc): JsonResponse
    {
        if ($erro = $this->exigirPermissao($request)) {
            return $erro;
        }

        $a = DB::table('auditoria')->where('id', $id)->first();
        if (! $a) {
            return response()->json(['message' => 'Este registro não existe.'], 404);
        }

        // O MESMO teste que apagou o botão na tela, refeito aqui. A tela decide
        // o que mostrar; o servidor decide o que acontece — e quem chamar a rota
        // direto tem de encontrar a mesma regra.
        $checagem = $this->podeDesfazer($a);
        if (! $checagem['pode']) {
            return response()->json([
                'message' => $checagem['motivo'] ?: 'Esta alteração não tem reversão automática.',
            ], 422);
        }

        try {
            $msg = $svc->executar($a, $request->user()->id, $request->user()->name);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $msg]);
    }


    private function exigirPermissao(Request $request): ?JsonResponse
    {
        $u = $request->user();
        if ($u?->perfilEfetivo() === 'admin' || $u?->podeCurarCadastro()) {
            return null;
        }

        return response()->json([
            'message' => 'A trilha de alterações é do administrador e do curador do cadastro.',
        ], 403);
    }
}
