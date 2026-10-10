<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\DocumentoAnexo;
use App\Models\Evidencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Os anexos próprios do documento (aba Anexos do formulário).
 *
 * Três listas: os anexos DA PEÇA, as fotos da vistoria vinculada e os anexos
 * da peça de origem — estas duas só para o fiscal escolher o que traz.
 *
 * Quem pode o quê:
 *   juntar    rascunho: o autor. Lavrada: o autor ou o administrador, e o
 *             anexo sai marcado "juntado depois". Anulada: ninguém.
 *   alterar   título, ordem e "sai na impressão": o autor da peça.
 *   excluir   exclusivamente o autor — com a peça lavrada, é quem a lavrou, e
 *             administrador não é exceção (DocumentoAnexo::podeSerExcluidoPor).
 */
class DocumentoAnexoController extends Controller
{
    /** GET /api/documentos/{documento}/anexos */
    public function index(Request $request, Documento $documento): JsonResponse
    {
        $u = $request->user();
        $proprios = $documento->anexos()->with('autor:id,name')->get();
        $jaTrazidos = fn (string $origem) => $proprios->where('origem', $origem)->pluck('origem_ref')->all();

        // Fotos da vistoria vinculada — o auto de infração não tem vistoria.
        $daVistoria = $documento->vistoria_id && $documento->tipo !== 'auto_infracao'
            ? Evidencia::where('vistoria_id', $documento->vistoria_id)->orderBy('ordem')->orderBy('id')->get()
                ->filter(fn (Evidencia $e) => str_starts_with((string) $e->mime, 'image/'))
                ->map(fn (Evidencia $e) => [
                    'id'     => $e->id,
                    'titulo' => $e->titulo ?: $e->nome_original,
                    'quando' => $e->data_hora?->format('d/m/Y H:i'),
                    // Para o carimbo: a foto da vistoria é preparada na tela
                    // (data, hora, posição e brasão) antes de entrar na peça.
                    'data'   => $e->data_hora?->format('Y-m-d H:i:s'),
                    'lat'    => $documento->vistoria?->latitude,
                    'lon'    => $documento->vistoria?->longitude,
                    'url'    => route('evidencia.arquivo', $e),
                    'usada'  => in_array($e->id, $jaTrazidos('vistoria'), true),
                ])->values()
            : collect();

        // Anexos da peça de origem (a notificação ou o embargo de que o auto nasceu).
        $origem = $documento->origem;
        $daOrigem = $origem
            ? $origem->anexos()->get()->map(fn (DocumentoAnexo $a) => [
                'id'     => $a->id,
                'titulo' => $a->titulo ?: $a->nome_original,
                'quando' => ($a->data_hora ?? $a->created_at)?->format('d/m/Y H:i'),
                'foto'   => $a->ehFoto(),
                'url'    => route('documento.anexo.arquivo', $a),
                'usada'  => in_array($a->id, $jaTrazidos('documento'), true),
            ])->values()
            : collect();

        return response()->json([
            'anexos'      => $proprios->map(fn (DocumentoAnexo $a) => $this->linha($a, $u)),
            'da_vistoria' => $daVistoria,
            'da_origem'   => $daOrigem,
            'vistoria'    => $documento->vistoria_id && $documento->tipo !== 'auto_infracao' ? $documento->vistoria?->numeroFormatado() : null,
            'origem'      => $origem ? $origem->rotuloTipo() . ' nº ' . $origem->numeroFormatado() : null,
            'pode_juntar' => $documento->podeJuntarAnexo($u),
            'pode_alterar' => ! $documento->encerrado() && $documento->agente_id === $u->id,
            'lavrado'     => ! $documento->naoLavrado(),
            'maximo'      => DocumentoAnexo::MAXIMO,
        ]);
    }

    /** POST /api/documentos/{documento}/anexos — foto (já preparada na tela) ou PDF. */
    public function store(Request $request, Documento $documento): JsonResponse
    {
        if ($recusa = $this->recusaAoJuntar($request, $documento)) {
            return $recusa;
        }
        $d = $request->validate([
            // A foto chega reduzida e com carimbo de data e marca d'água, postos
            // na tela; o PDF vai como está. 10 MB cobre os dois com folga.
            'arquivo'   => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf'],
            'titulo'    => ['nullable', 'string', 'max:160'],
            'data_hora' => ['nullable', 'date'],
            // A foto veio da vistoria vinculada, e foi preparada na tela como
            // qualquer outra: sobe uma imagem NOVA, carimbada, e o anexo guarda
            // de qual evidência ela saiu.
            'da_vistoria' => ['nullable', 'integer'],
        ], [
            'arquivo.max'       => 'O arquivo passa de 10 MB.',
            'arquivo.mimetypes' => 'Só foto (JPG, PNG, WEBP) ou PDF.',
        ]);

        $origem = ['origem' => 'proprio'];
        if (! empty($d['da_vistoria'])) {
            // As mesmas travas do "trazer": a evidência é da vistoria desta
            // peça, auto de infração não tem vistoria, e não entra duas vezes.
            $daPeca = $documento->tipo !== 'auto_infracao' && $documento->vistoria_id
                && Evidencia::where('vistoria_id', $documento->vistoria_id)->whereKey($d['da_vistoria'])->exists();
            if (! $daPeca) {
                return response()->json(['message' => 'Essa foto não é da vistoria vinculada a este documento.'], 422);
            }
            if ($documento->anexos()->where('origem', 'vistoria')->where('origem_ref', $d['da_vistoria'])->exists()) {
                return response()->json(['message' => 'Este já está no documento.'], 422);
            }
            $origem = ['origem' => 'vistoria', 'origem_ref' => $d['da_vistoria']];
        }

        $arquivo = $d['arquivo'];
        $caminho = $arquivo->store('documentos/' . $documento->id, 'private');

        $anexo = $this->criar($documento, $request, $origem + [
            'arquivo'       => $caminho,
            'mime'          => $arquivo->getMimeType() ?: 'application/octet-stream',
            'nome_original' => mb_substr($arquivo->getClientOriginalName(), 0, 255),
            'titulo'        => trim((string) ($d['titulo'] ?? '')) ?: pathinfo($arquivo->getClientOriginalName(), PATHINFO_FILENAME),
            'data_hora'     => $d['data_hora'] ?? now(),
        ]);

        return response()->json(['message' => 'Anexo juntado.', 'anexo' => $this->linha($anexo, $request->user())], 201);
    }

    /**
     * POST /api/documentos/{documento}/anexos/trazer — traz, por escolha, uma
     * foto da vistoria vinculada ou um anexo da peça de origem. O arquivo não
     * é copiado: o anexo aponta para o mesmo.
     */
    public function trazer(Request $request, Documento $documento): JsonResponse
    {
        if ($recusa = $this->recusaAoJuntar($request, $documento)) {
            return $recusa;
        }
        $d = $request->validate([
            'de' => ['required', Rule::in(['vistoria', 'documento'])],
            'id' => ['required', 'integer'],
        ]);
        if ($documento->anexos()->where('origem', $d['de'])->where('origem_ref', $d['id'])->exists()) {
            return response()->json(['message' => 'Este já está no documento.'], 422);
        }

        if ($d['de'] === 'vistoria') {
            // Auto de infração não se vincula a vistoria: nasce de notificação ou de embargo.
            $e = $documento->tipo !== 'auto_infracao' && $documento->vistoria_id
                ? Evidencia::where('vistoria_id', $documento->vistoria_id)->find($d['id']) : null;
            if (! $e) {
                return response()->json(['message' => 'Essa foto não é da vistoria vinculada a este documento.'], 422);
            }
            $dados = ['arquivo' => $e->arquivo, 'mime' => $e->mime, 'nome_original' => $e->nome_original,
                'titulo' => $e->titulo ?: $e->nome_original, 'data_hora' => $e->data_hora];
        } else {
            $a = $documento->origem_id ? DocumentoAnexo::where('documento_id', $documento->origem_id)->find($d['id']) : null;
            if (! $a) {
                return response()->json(['message' => 'Esse anexo não é da peça de origem deste documento.'], 422);
            }
            $dados = ['arquivo' => $a->arquivo, 'mime' => $a->mime, 'nome_original' => $a->nome_original,
                'titulo' => $a->titulo, 'data_hora' => $a->data_hora ?? $a->created_at];
        }

        $anexo = $this->criar($documento, $request, ['origem' => $d['de'], 'origem_ref' => $d['id']] + $dados);

        return response()->json(['message' => 'Anexo trazido para o documento.', 'anexo' => $this->linha($anexo, $request->user())], 201);
    }

    /** PATCH /api/documentos/anexos/{anexo} — título e "sai na impressão". */
    public function update(Request $request, DocumentoAnexo $anexo): JsonResponse
    {
        if (! $anexo->podeSerExcluidoPor($request->user())) {
            return response()->json(['message' => 'Só o autor do documento altera os anexos dele.'], 403);
        }
        $d = $request->validate([
            'titulo'  => ['sometimes', 'nullable', 'string', 'max:160'],
            'imprime' => ['sometimes', 'boolean'],
        ]);
        $anexo->update($d);

        return response()->json(['message' => 'Anexo atualizado.']);
    }

    /** POST /api/documentos/{documento}/anexos/ordem — a ordem em que saem na via impressa. */
    public function ordenar(Request $request, Documento $documento): JsonResponse
    {
        if ($documento->status === 'anulado' || $documento->agente_id !== $request->user()->id) {
            return response()->json(['message' => 'Só o autor do documento reordena os anexos dele.'], 403);
        }
        $d = $request->validate(['ids' => ['required', 'array', 'max:' . DocumentoAnexo::MAXIMO], 'ids.*' => ['integer']]);
        foreach ($d['ids'] as $ordem => $id) {
            $documento->anexos()->whereKey($id)->update(['ordem' => $ordem]);
        }

        return response()->json(['message' => 'Ordem gravada.']);
    }

    /** DELETE /api/documentos/anexos/{anexo} — só o autor; administrador não é exceção. */
    public function destroy(Request $request, DocumentoAnexo $anexo): JsonResponse
    {
        if (! $anexo->podeSerExcluidoPor($request->user())) {
            return response()->json(['message' => 'Só quem lavrou o documento pode excluir um anexo dele.'], 403);
        }
        // O arquivo só some se for deste anexo — o trazido aponta para o
        // arquivo da vistoria ou da peça de origem, que continuam com ele. E
        // mesmo o próprio fica se outra peça o trouxe.
        if ($anexo->donoDoArquivo()
            && ! DocumentoAnexo::where('arquivo', $anexo->arquivo)->where('id', '!=', $anexo->id)->exists()) {
            Storage::disk('private')->delete($anexo->arquivo);
        }
        $anexo->delete();

        return response()->json(['message' => 'Anexo excluído.']);
    }

    /** GET /documentos/anexos/{anexo}/arquivo — o arquivo, para quem está logado. */
    public function arquivo(DocumentoAnexo $anexo)
    {
        abort_unless(Storage::disk('private')->exists($anexo->arquivo), 404);

        return Storage::disk('private')->response($anexo->arquivo, $anexo->nome_original ?: 'anexo',
            ['Content-Type' => $anexo->mime ?: 'application/octet-stream']);
    }

    private function recusaAoJuntar(Request $request, Documento $documento): ?JsonResponse
    {
        if (! $documento->podeJuntarAnexo($request->user())) {
            return response()->json(['message' => 'Você não pode juntar anexo a este documento.'], 403);
        }
        if ($documento->anexos()->count() >= DocumentoAnexo::MAXIMO) {
            return response()->json(['message' => 'O documento já tem ' . DocumentoAnexo::MAXIMO . ' anexos, que é o limite.'], 422);
        }

        return null;
    }

    private function criar(Documento $documento, Request $request, array $dados): DocumentoAnexo
    {
        // A partir do primeiro anexo próprio, a peça deixa de imprimir as
        // fotos da vistoria por conta própria: passa a valer a escolha.
        if (! $documento->anexos_proprios) {
            $documento->forceFill(['anexos_proprios' => true])->saveQuietly();
        }

        return $documento->anexos()->create($dados + [
            'ordem'          => (int) $documento->anexos()->max('ordem') + 1,
            'imprime'        => true,
            // Entrou com a peça já lavrada: a juntada posterior aparece como tal.
            'juntado_depois' => ! $documento->naoLavrado(),
            'criado_por'     => $request->user()->id,
        ]);
    }

    /** @return array<string,mixed> */
    private function linha(DocumentoAnexo $a, $u): array
    {
        return [
            'id'      => $a->id,
            'titulo'  => $a->titulo ?: $a->nome_original,
            'quando'  => ($a->data_hora ?? $a->created_at)?->format('d/m/Y H:i'),
            'quem'    => $a->autor?->name,
            'foto'    => $a->ehFoto(),
            'url'     => route('documento.anexo.arquivo', $a),
            'origem'  => $a->origem,
            'imprime' => $a->imprime,
            'juntado_depois' => $a->juntado_depois,
            'pode_excluir'   => $a->podeSerExcluidoPor($u),
        ];
    }
}
