<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\Evidencia;
use App\Models\Irregularidade;
use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Vistoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VistoriaController extends Controller
{
    /** Tamanho máximo por evidência (KB). Foto de celular moderno passa de 5 MB. */
    private const MAX_KB = 12288;

    /** Tipos aceitos. Validados por MIME real, não pela extensão do nome. */
    private const MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
        'application/pdf',
    ];

    /**
     * GET /api/lotes/{lote}/historico
     *
     * Histórico cronológico do imóvel (§20 do projeto). É o que o fiscal
     * consulta ANTES de vistoriar: saber que o lote já foi notificado no mês
     * passado muda o que ele vai fazer na visita de hoje.
     */
    public function historico(Lote $lote): JsonResponse
    {
        $vistorias = $lote->vistorias()
            ->with(['fiscal:id,name', 'irregularidades:id,codigo,descricao,gravidade'])
            ->withCount('evidencias')
            ->get()
            ->map(fn (Vistoria $v) => [
                'id'               => $v->id,
                'data_hora'        => $v->data_hora?->format('d/m/Y H:i'),
                'situacao'         => $v->situacao,
                'situacao_rotulo'  => $v->situacaoRotulo(),
                'situacao_badge'   => $v->situacaoBadge(),
                'fiscal'           => $v->fiscal?->name,
                'observacoes'      => $v->observacoes,
                'evidencias'       => $v->evidencias_count,
                'irregularidades'  => $v->irregularidades->map(fn ($i) => [
                    'codigo' => $i->codigo, 'descricao' => $i->descricao, 'gravidade' => $i->gravidade,
                ]),
            ]);

        // Linha do tempo: vistoria não é o único fato da vida do imóvel. O que
        // o fiscal precisa ver antes da visita é a sequência inteira — vistoria,
        // documento lavrado e requerimento do contribuinte —, porque é ela que
        // explica em que pé o processo está.
        $eventos = [];

        foreach ($vistorias as $v) {
            $eventos[] = [
                'tipo'    => 'vistoria',
                'quando'  => $v['data_hora'],
                'titulo'  => 'Vistoria — ' . $v['situacao_rotulo'],
                'detalhe' => $v['fiscal'] ? 'Fiscal: ' . $v['fiscal'] : null,
                'badge'   => ['texto' => $v['situacao_rotulo'], 'classe' => $v['situacao_badge']],
                'itens'   => collect($v['irregularidades'])->pluck('descricao')->all(),
                'obs'     => $v['observacoes'],
            ];
        }

        foreach (Documento::where('lote_id', $lote->id)->get() as $d) {
            [$sTxt, $sCls] = $d->statusBadge();
            $eventos[] = [
                'tipo'    => 'documento',
                'quando'  => ($d->data_lavratura ?? $d->created_at)?->format('d/m/Y H:i'),
                'titulo'  => $d->numeroFormatado() . ' — ' . $d->rotuloTipo(),
                'detalhe' => $d->autuado_nome ? 'Autuado: ' . $d->autuado_nome : null,
                'badge'   => ['texto' => $sTxt, 'classe' => $sCls],
                'itens'   => [],
                'obs'     => $d->descricao,
            ];
        }

        foreach (Protocolo::where('lote_id', $lote->id)->get() as $p) {
            [$sTxt, $sCls] = $p->situacaoBadge();
            $eventos[] = [
                'tipo'    => 'protocolo',
                'quando'  => $p->protocolado_em?->format('d/m/Y'),
                'titulo'  => $p->numero . ' — ' . $p->rotuloTipo(),
                'detalhe' => $p->requerente_nome ? 'Requerente: ' . $p->requerente_nome : null,
                'badge'   => ['texto' => $sTxt, 'classe' => $sCls],
                'itens'   => [],
                'obs'     => $p->objeto,
            ];
        }

        // Mais recente primeiro: o último fato é o que decide o que fazer hoje.
        usort($eventos, fn ($a, $b) => strcmp(
            $this->chaveOrdem($b['quando']), $this->chaveOrdem($a['quando'])
        ));

        return response()->json([
            'lote'      => $lote->only(['id', 'bairro', 'quadra', 'numero_lote', 'chave']),
            'vistorias' => $vistorias,
            'eventos'   => $eventos,
        ]);
    }

    /** "dd/mm/aaaa hh:mm" -> "aaaammddhhmm", para ordenar comparando texto. */
    private function chaveOrdem(?string $data): string
    {
        if (! $data) { return '0'; }
        $partes = explode(' ', $data);
        [$d, $m, $a] = array_pad(explode('/', $partes[0]), 3, '00');
        return $a . $m . $d . str_replace(':', '', $partes[1] ?? '0000');
    }

    /** GET /api/irregularidades — catálogo para montar o checklist. */
    public function catalogo(): JsonResponse
    {
        return response()->json(
            Irregularidade::ativas()->get(['id', 'codigo', 'descricao', 'gravidade'])
        );
    }

    /**
     * POST /api/lotes/{lote}/vistorias
     *
     * Grava a vistoria, as irregularidades marcadas e as fotos, tudo numa
     * transação: uma vistoria salva pela metade — sem as fotos que a
     * fundamentam — é pior do que vistoria nenhuma, porque parece completa.
     */
    public function store(Request $request, Lote $lote): JsonResponse
    {
        $u = $request->user();
        if (! $u->canEdit()) {
            return response()->json(['message' => 'Seu perfil não permite registrar vistorias.'], 403);
        }

        $d = $request->validate([
            'data_hora'          => ['required', 'date_format:Y-m-d\TH:i'],
            'situacao'           => ['required', Rule::in(array_keys(Vistoria::SITUACOES))],
            'observacoes'        => ['nullable', 'string', 'max:5000'],
            'latitude'           => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'          => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy'           => ['nullable', 'numeric', 'min:0'],
            'irregularidades'    => ['array'],
            'irregularidades.*'  => ['integer', 'exists:irregularidades,id'],
            'evidencias'         => ['array', 'max:20'],
            'evidencias.*'       => ['file', 'max:' . self::MAX_KB, 'mimetypes:' . implode(',', self::MIMES)],
            'titulos'            => ['array'],
            'titulos.*'          => ['nullable', 'string', 'max:160'],
        ], [
            'data_hora.date_format' => 'Informe data e hora da vistoria.',
            'evidencias.*.max'      => 'Cada arquivo deve ter no máximo 12 MB.',
            'evidencias.*.mimetypes' => 'Envie apenas imagens ou PDF.',
        ]);

        // Uma vistoria irregular sem nenhuma irregularidade marcada é um
        // registro que não sustenta documento nenhum depois. Barrar aqui evita
        // descobrir isso na hora de lavrar a notificação.
        if ($d['situacao'] === 'irregular' && empty($d['irregularidades'])) {
            return response()->json([
                'message' => 'Marque ao menos uma irregularidade para uma vistoria irregular.',
                'errors'  => ['irregularidades' => ['Selecione ao menos uma.']],
            ], 422);
        }

        $vistoria = DB::transaction(function () use ($request, $lote, $u, $d) {
            $v = Vistoria::create([
                'lote_id'     => $lote->id,
                'fiscal_id'   => $u->id,
                // Gravado como string local "ingênua" (aaaa-mm-ddThh:mm), sem
                // conversão de fuso — ver comentário na migration.
                'data_hora'   => str_replace('T', ' ', $d['data_hora']) . ':00',
                'situacao'    => $d['situacao'],
                'observacoes' => $d['observacoes'] ?? null,
                'latitude'    => $d['latitude'] ?? null,
                'longitude'   => $d['longitude'] ?? null,
                'accuracy'    => $d['accuracy'] ?? null,
            ]);

            if (! empty($d['irregularidades'])) {
                $v->irregularidades()->sync($d['irregularidades']);
            }

            foreach ($request->file('evidencias', []) as $i => $arquivo) {
                // Disco privado: foto de fiscalização mostra propriedade
                // privada e identifica pessoas.
                $caminho = $arquivo->store("evidencias/{$v->id}", 'private');
                Evidencia::create([
                    'vistoria_id'   => $v->id,
                    'tipo'          => str_starts_with($arquivo->getMimeType(), 'image/') ? 'foto' : 'documento',
                    'arquivo'       => $caminho,
                    'nome_original' => $arquivo->getClientOriginalName(),
                    'mime'          => $arquivo->getMimeType(),
                    'tamanho'       => $arquivo->getSize(),
                    'titulo'        => $d['titulos'][$i] ?? ('Evidência ' . ($i + 1)),
                    'latitude'      => $d['latitude'] ?? null,
                    'longitude'     => $d['longitude'] ?? null,
                    'data_hora'     => str_replace('T', ' ', $d['data_hora']) . ':00',
                    'criado_por'    => $u->id,
                ]);
            }

            return $v;
        });

        return response()->json([
            'message'  => 'Vistoria registrada.',
            'vistoria' => [
                'id'        => $vistoria->id,
                'data_hora' => $vistoria->data_hora?->format('d/m/Y H:i'),
            ],
        ], 201);
    }

    /**
     * GET /evidencias/{evidencia}/arquivo
     *
     * Serve o arquivo por rota autenticada. Nunca por link direto em
     * `public/`: quem descobrisse o caminho veria a foto sem login.
     */
    public function arquivo(Evidencia $evidencia)
    {
        abort_unless(Storage::disk('private')->exists($evidencia->arquivo), 404);

        return Storage::disk('private')->response(
            $evidencia->arquivo,
            $evidencia->nome_original,
            ['Content-Type' => $evidencia->mime ?? 'application/octet-stream']
        );
    }

    /** DELETE /api/evidencias/{evidencia} — só o autor, admin não é exceção. */
    public function excluirEvidencia(Request $request, Evidencia $evidencia): JsonResponse
    {
        if (! $evidencia->podeSerExcluidaPor($request->user())) {
            return response()->json([
                'message' => 'Só quem cadastrou a evidência pode excluí-la.',
            ], 403);
        }

        Storage::disk('private')->delete($evidencia->arquivo);
        $evidencia->delete();

        return response()->json(['message' => 'Evidência excluída.']);
    }
}
