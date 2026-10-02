<?php

namespace App\Http\Controllers;

use App\Cadastro\FonteDoCadastro;
use App\Cadastro\VinculoDoBairro;
use App\Cadastro\PlanilhaDoCadastro;
use App\Models\ImportacaoLote;
use App\Models\Lote;
use App\Services\PreCuradoriaDeLotes;
use App\Repositories\LoteRepository;
use App\Services\ConferenciaComCadastro;
use App\Services\ImportacaoDeBairro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importação de bairro pela tela — ver App\Services\ImportacaoDeBairro.
 *
 * QUEM PODE O QUÊ:
 *   curador do cadastro (interno ou externo)  envia, confere, exclui
 *   administrador                             publica
 *
 * Publicar é do administrador, e não de quem enviou, pela mesma razão de
 * qualquer liberação: quem sobe o bairro não é quem o libera para a cidade.
 */
class ImportacaoController extends Controller
{
    /** Teto do arquivo enviado, em KB. Um bairro grande dá poucos MB de GeoJSON. */
    private const TETO_KB = 30720;

    public function __construct(
        private ImportacaoDeBairro $importacao,
        private LoteRepository $lotes,
    ) {}

    private function exigirCurador(Request $r): ?JsonResponse
    {
        return $r->user()->podeCurarCadastro()
            ? null
            : response()->json(['message' => 'A importação de bairro é do curador do cadastro.'], 403);
    }

    /** Curador, e — se for rascunho — quem o carregou ou o administrador. */
    private function exigirCuradorDe(Request $r, ImportacaoLote $i): ?JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }

        return $i->visivelPara($r->user())
            ? null
            : response()->json(['message' => 'Este rascunho de importação é de outro curador e ainda não foi salvo.'], 404);
    }

    /** GET /api/importacoes */
    public function index(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }

        // Rascunho é de quem o carregou (e do administrador); os demais
        // curadores só o enxergam depois de salvo.
        $u = $r->user();
        $lista = ImportacaoLote::with('usuario:id,name')
            ->when(! $u->isAdmin(), fn ($q) => $q->where(fn ($w) => $w
                ->where('status', '<>', 'rascunho')->orWhere('user_id', $u->id)))
            ->orderByRaw("status = 'rascunho' DESC")->orderByDesc('id')->limit(100)->get()
            ->map(fn (ImportacaoLote $i) => $this->resumo($i));

        return response()->json([
            'importacoes' => $lista,
            'em_revisao'  => $lista->where('status', 'revisao')->count(),
            'pode_publicar' => $r->user()->isAdmin(),
        ]);
    }

    /** GET /api/importacoes/{importacao} — resumo, vínculos e onde fica no mapa. */
    public function mostrar(Request $r, ImportacaoLote $importacao): JsonResponse
    {
        if ($e = $this->exigirCuradorDe($r, $importacao)) { return $e; }

        return response()->json($this->resumo($importacao) + [
            'conferencia'          => $importacao->conferencia,
            'conferencia_cadastro' => $importacao->conferencia_cadastro,
            'conferencia_em_dia'   => $importacao->emAndamento()
                ? ! $this->importacao->mudouDepoisDaConferencia($importacao) : null,
            'vinculos'             => $importacao->status === 'excluida' ? [] : $this->importacao->vinculos($importacao),
            'extensao'             => $this->lotes->extensaoDaImportacao($importacao->id),
            'pre_curadoria'        => $this->preCuradoria($importacao),
            'vinculo'              => $importacao->status === 'excluida' ? null
                : app(VinculoDoBairro::class)->situacao($importacao->bairro, $importacao->id),
            'justificativa_publicacao' => $importacao->justificativa_publicacao,
            'motivo_exclusao'      => $importacao->motivo_exclusao,
            'pode_publicar'        => $r->user()->isAdmin(),
        ]);
    }

    /** POST /api/importacoes/conferir — lê o arquivo e diz o que ele traz. Nada é gravado. */
    public function conferirArquivo(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $arquivo = $this->arquivoEnviado($r);

        try {
            $conf = $this->importacao->conferirArquivo($arquivo->getRealPath(), $arquivo->getClientOriginalName());
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        unset($conf['hash']);

        return response()->json($conf);
    }

    /** POST /api/importacoes — carrega os lotes como RASCUNHO. */
    public function gravar(Request $r): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        $arquivo = $this->arquivoEnviado($r);

        try {
            $imp = $this->importacao->gravar($arquivo->getRealPath(), $arquivo->getClientOriginalName(), $r->user(),
                $r->integer('cadastro_bairro_id') ?: null);
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json([
            'message' => "{$imp->bairro} carregado como rascunho: {$imp->total_lotes} lotes. "
                . 'Faça a pré-curadoria e a conferência; a importação só vale depois de salva.',
            'id'      => $imp->id,
        ]);
    }

    /** POST /api/importacoes/{importacao}/bairro — liga o bairro a um bairro do cadastro. */
    public function vincularBairro(Request $r, ImportacaoLote $importacao, VinculoDoBairro $vinculo): JsonResponse
    {
        if ($e = $this->exigirCuradorDe($r, $importacao)) { return $e; }
        if (! $importacao->emAndamento()) {
            return response()->json(['message' => 'O bairro se vincula enquanto a importação não foi publicada.'], 422);
        }
        $d = $r->validate(['cadastro_bairro_id' => ['required', 'integer', 'exists:cadastro_bairros,id']],
            ['cadastro_bairro_id.required' => 'Escolha o bairro do cadastro.']);

        try {
            $b = $vinculo->vincular($importacao->bairro, (int) $d['cadastro_bairro_id'], $importacao->id);
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json(['message' => "{$importacao->bairro} vinculado a {$b->codigo} · {$b->rotulo()}."
            . ($importacao->conferido_em ? ' Confira com o cadastro de novo: o código do bairro mudou.' : '')]);
    }

    /** POST /api/importacoes/{importacao}/lotes/{lote}/numero — só na pré-curadoria. */
    public function numerarLote(Request $r, ImportacaoLote $importacao, Lote $lote, PreCuradoriaDeLotes $svc): JsonResponse
    {
        if ($e = $this->exigirCuradorDe($r, $importacao)) { return $e; }
        $d = $r->validate(['numero_lote' => ['required', 'string', 'max:20']],
            ['numero_lote.required' => 'Informe o número do lote.']);

        try {
            $l = $svc->numerar($importacao, $lote, $d['numero_lote']);
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json(['message' => 'Quadra ' . ($l->quadra ?? '—') . ": lote agora é o nº {$l->numero_lote}."]);
    }

    /** POST /api/importacoes/{importacao}/lotes/excluir — só na pré-curadoria. */
    public function excluirLotes(Request $r, ImportacaoLote $importacao, PreCuradoriaDeLotes $svc): JsonResponse
    {
        if ($e = $this->exigirCuradorDe($r, $importacao)) { return $e; }
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);

        try {
            $n = $svc->excluir($importacao, array_map('intval', $d['ids']));
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json(['message' => $n === 1 ? '1 lote excluído da importação.' : "{$n} lotes excluídos da importação."]);
    }

    /** POST /api/importacoes/{importacao}/salvar — o rascunho passa a valer. */
    public function salvar(Request $r, ImportacaoLote $importacao): JsonResponse
    {
        if ($e = $this->exigirCuradorDe($r, $importacao)) { return $e; }

        try {
            $this->importacao->salvar($importacao, $r->user());
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json(['message' => "Importação nº {$importacao->id} salva com {$importacao->total_lotes} lotes. Ela fica na lista, ainda não publicada."]);
    }

    /** POST /api/importacoes/{importacao}/descartar — o rascunho some, sem registro. */
    public function descartar(Request $r, ImportacaoLote $importacao): JsonResponse
    {
        if ($e = $this->exigirCuradorDe($r, $importacao)) { return $e; }

        try {
            $n = $this->importacao->descartar($importacao);
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json(['message' => "Rascunho de {$importacao->bairro} descartado: {$n} lotes removidos, sem registro."]);
    }

    /**
     * POST /api/importacoes/{importacao}/conferir-cadastro
     *
     * Sem arquivo, confere com o cadastro carregado; com uma planilha .xlsx,
     * confere com ela — só em memória.
     */
    public function conferirCadastro(Request $r, ImportacaoLote $importacao, ConferenciaComCadastro $conferencia): JsonResponse
    {
        if ($e = $this->exigirCuradorDe($r, $importacao)) { return $e; }
        if (! $importacao->emAndamento()) {
            return response()->json(['message' => 'Só se confere importação que ainda não foi publicada.'], 422);
        }

        $r->validate(['planilha' => ['nullable', 'file', 'max:' . self::TETO_KB],
                      'fonte'    => ['nullable', 'in:ultima,carregado']]);

        try {
            // Planilha anexada, a MESMA da última conferência (fonte=ultima,
            // sem arquivo), ou o cadastro carregado.
            [$fonte, $descricao] = $conferencia->fonteDoPedido($r, $importacao->conferencia_cadastro);
            $resultado = $conferencia->conferir($importacao, $fonte, $descricao);
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json($resultado);
    }

    /** GET /api/importacoes/{importacao}/divergencias.csv */
    public function divergenciasCsv(Request $r, ImportacaoLote $importacao): StreamedResponse|JsonResponse
    {
        if ($e = $this->exigirCuradorDe($r, $importacao)) { return $e; }

        $c = $importacao->conferencia_cadastro;
        if (! $c) {
            return response()->json(['message' => 'Esta importação ainda não foi conferida com o cadastro.'], 422);
        }

        $grupos = [
            'nao_encontrados' => 'No arquivo, não encontrado no cadastro',
            'inativos'        => 'Desenhado no mapa, inativo no cadastro',
            'sem_lote'        => 'No cadastro, sem lote no arquivo',
            'sem_inscricao'   => 'Sem quadra ou número — inscrição não se monta',
        ];

        return response()->streamDownload(function () use ($c, $grupos) {
            $out = fopen('php://output', 'w');
            // BOM: sem ele o Excel abre o CSV em ANSI e estraga os acentos.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Divergência', 'Inscrição', 'Quadra', 'Lote', 'Detalhe'], ';');
            foreach ($grupos as $chave => $rotulo) {
                foreach ($c[$chave] ?? [] as $l) {
                    fputcsv($out, [
                        $rotulo, $l['inscricao'] ?? '', $l['quadra'] ?? '', $l['lote'] ?? '',
                        $l['isencao'] ?? $l['endereco'] ?? '',
                    ], ';');
                }
            }
            fclose($out);
        }, "divergencias-importacao-{$importacao->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** POST /api/importacoes/{importacao}/publicar — só administrador. */
    public function publicar(Request $r, ImportacaoLote $importacao): JsonResponse
    {
        if (! $r->user()->isAdmin()) {
            return response()->json(['message' => 'Publicar a importação é do administrador.'], 403);
        }
        $d = $r->validate(['justificativa' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->importacao->publicar($importacao, $r->user(), $d['justificativa'] ?? null);
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json(['message' => "Importação nº {$importacao->id} publicada: os lotes já aparecem para todos."]);
    }

    /** POST /api/importacoes/{importacao}/excluir */
    public function excluir(Request $r, ImportacaoLote $importacao): JsonResponse
    {
        if ($e = $this->exigirCurador($r)) { return $e; }
        // Só a importação SALVA e não publicada se exclui — a regra mora no
        // serviço (ImportacaoDeBairro::excluir), que recusa rascunho e publicada.
        $d = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']],
            ['motivo.required' => 'Diga por que a importação está sendo excluída.',
             'motivo.min' => 'Descreva o motivo em ao menos 10 caracteres.']);

        try {
            $n = $this->importacao->excluir($importacao, $r->user(), $d['motivo']);
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->json(['message' => "Importação nº {$importacao->id} excluída: {$n} lotes apagados."]);
    }

    /** @return \Illuminate\Http\UploadedFile */
    private function arquivoEnviado(Request $r)
    {
        $r->validate(['arquivo' => ['required', 'file', 'max:' . self::TETO_KB]], [
            'arquivo.required' => 'Escolha o arquivo .geojson do bairro.',
            'arquivo.max'      => 'O arquivo passa de 30 MB. Envie um bairro por vez.',
        ]);
        $arq = $r->file('arquivo');
        $ext = strtolower($arq->getClientOriginalExtension());
        abort_unless(in_array($ext, ['geojson', 'json'], true), 422,
            'O arquivo precisa ser .geojson — converta o DWG com gis/tools/dxf_para_geojson.py.');

        return $arq;
    }

    /**
     * O que foi ajustado na pré-curadoria — o registro próprio da importação,
     * fora do Histórico do cadastro (ver Lote::tabelaDaAuditoria).
     *
     * @return array{total:int, ultimos:list<array<string,mixed>>}
     */
    private function preCuradoria(ImportacaoLote $i): array
    {
        $q = DB::table('auditoria')->where('importacao_id', $i->id)->where('tabela', 'lotes_em_revisao');

        return [
            'total'   => (clone $q)->count(),
            'ultimos' => $q->orderByDesc('id')->limit(15)->get()->map(fn ($a) => [
                'acao'      => $a->acao,
                'lote'      => $a->descricao,
                'usuario'   => $a->usuario_nome,
                'quando'    => date('d/m/Y H:i', strtotime($a->created_at)),
            ])->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function resumo(ImportacaoLote $i): array
    {
        $c = $i->conferencia_cadastro;

        return [
            'id'           => $i->id,
            'bairro'       => $i->bairro,
            'arquivo'      => $i->arquivo_nome,
            'lotes'        => $i->total_lotes,
            'status'       => $i->status,
            'status_rotulo' => ImportacaoLote::SITUACOES[$i->status] ?? $i->status,
            'enviado_por'  => $i->usuario?->name,
            'enviado_em'   => $i->created_at?->format('d/m/Y'),
            'salvo_em'     => $i->salvo_em?->format('d/m/Y'),
            'publicado_em' => $i->publicado_em?->format('d/m/Y'),
            'excluido_em'  => $i->excluido_em?->format('d/m/Y'),
            'divergencias' => $c ? (int) ($c['total_divergencias'] ?? 0) : null,
            'conferido_em' => $i->conferido_em?->format('d/m/Y H:i'),
        ];
    }
}
