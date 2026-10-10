<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A DEFESA do auto — o mesmo procedimento do AppPOSTURAS, em dois passos:
 *
 *   1. PROTOCOLO. O autuado apresentou defesa: registra-se o número do
 *      protocolo, a data e, se houver, o arquivo. A peça passa a "em defesa".
 *      Registra quem lavrou o auto, ou o administrador.
 *
 *   2. JULGAMENTO. Só o ADMINISTRADOR. Resultado, data, o parecer e o arquivo
 *      da decisão.
 *        deferida   → a peça vira "defendido": deixa de valer e NÃO gera custa;
 *        indeferida → volta a "lavrado", agora apta à cobrança.
 *
 * Julgada, a defesa não se altera mais: a decisão é ato, como a lavratura.
 * As regras de quem pode o quê estão em Documento (podeProtocolarDefesa,
 * podeJulgarDefesa); aqui se confere e se grava.
 */
class DocumentoDefesaController extends Controller
{
    private const ARQUIVO = ['nullable', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'];

    private const MENSAGENS = [
        'anexo.max'       => 'O arquivo passa de 10 MB.',
        'anexo.mimetypes' => 'Só PDF ou foto (JPG, PNG, WEBP).',
    ];

    /** POST /api/documentos/{documento}/defesa — registra (ou corrige) o protocolo da defesa. */
    public function protocolar(Request $request, Documento $documento): JsonResponse
    {
        if (! $documento->podeProtocolarDefesa($request->user())) {
            return response()->json(['message' => 'A defesa deste documento não pode ser registrada por você.'], 403);
        }
        $d = $request->validate([
            'protocolo'      => ['required', 'string', 'max:60'],
            'data_protocolo' => ['required', 'date', 'before_or_equal:today'],
            'anexo'          => self::ARQUIVO,
        ], self::MENSAGENS + [
            'protocolo.required'             => 'Informe o número do protocolo da defesa.',
            'data_protocolo.required'        => 'Informe a data do protocolo.',
            'data_protocolo.before_or_equal' => 'A data do protocolo não pode ser futura.',
        ]);

        $defesa = $documento->defesa ?? [];
        $defesa['protocolo']      = trim($d['protocolo']);
        $defesa['data_protocolo'] = substr((string) $d['data_protocolo'], 0, 10);
        // Fora do prazo de defesa? Fica anotado; quem decide o que isso vale é o julgamento.
        $defesa['intempestiva']   = $documento->defesa_ate !== null
            && $defesa['data_protocolo'] > $documento->defesa_ate->format('Y-m-d');
        $defesa['registrado_por']    = $request->user()->id;
        $defesa['registrado_nome']   = $request->user()->name;
        $defesa['registrado_em']     = now()->format('Y-m-d H:i:s');
        if (isset($d['anexo'])) {
            $defesa['anexo'] = $this->guardar($documento, $d['anexo'], $defesa['anexo'] ?? null);
        }

        $documento->update(['defesa' => $defesa, 'status' => 'em_defesa']);

        return response()->json([
            'message' => 'Defesa registrada. O documento está em defesa.',
            'defesa'  => $documento->defesaParaTela($request->user()),
        ]);
    }

    /** POST /api/documentos/{documento}/defesa/julgamento — a decisão. Só o administrador. */
    public function julgar(Request $request, Documento $documento): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Só o administrador registra o julgamento da defesa.'], 403);
        }
        if (! $documento->podeJulgarDefesa($request->user())) {
            return response()->json(['message' => 'Este documento não tem defesa aguardando julgamento.'], 422);
        }
        $d = $request->validate([
            'resultado'      => ['required', Rule::in(['deferida', 'indeferida'])],
            'data_resultado' => ['required', 'date', 'before_or_equal:today'],
            'parecer'        => ['required', 'string', 'min:10', 'max:10000'],
            'anexo'          => self::ARQUIVO,
        ], self::MENSAGENS + [
            'resultado.required'             => 'Informe o resultado: deferida ou indeferida.',
            'data_resultado.required'        => 'Informe a data da decisão.',
            'data_resultado.before_or_equal' => 'A data da decisão não pode ser futura.',
            'parecer.required'               => 'Escreva o texto da decisão.',
            'parecer.min'                    => 'Escreva o texto da decisão com pelo menos 10 caracteres.',
        ]);

        $defesa = $documento->defesa ?? [];
        if (substr((string) $d['data_resultado'], 0, 10) < ($defesa['data_protocolo'] ?? '')) {
            return response()->json(['message' => 'A decisão não pode ser anterior ao protocolo da defesa.'], 422);
        }
        $defesa['resultado']      = $d['resultado'];
        $defesa['data_resultado'] = substr((string) $d['data_resultado'], 0, 10);
        $defesa['parecer']        = trim($d['parecer']);
        $defesa['julgado_por']    = $request->user()->id;
        $defesa['julgado_nome']   = $request->user()->name;
        $defesa['julgado_em']     = now()->format('Y-m-d H:i:s');
        if (isset($d['anexo'])) {
            $defesa['julgamento_anexo'] = $this->guardar($documento, $d['anexo'], null);
        }

        $documento->update([
            'defesa' => $defesa,
            // DEFERIDA: a peça deixa de valer. INDEFERIDA: volta a lavrada, apta.
            'status' => $d['resultado'] === 'deferida' ? 'defendido' : 'lavrado',
        ]);

        return response()->json([
            'message' => $d['resultado'] === 'deferida'
                ? 'Defesa deferida. O documento passa a "defendido" e não gera custa.'
                : 'Defesa indeferida. O documento volta a lavrado, apto.',
            'defesa'  => $documento->defesaParaTela($request->user()),
        ]);
    }

    /** GET /documentos/{documento}/defesa/arquivo/{qual} — o arquivo da defesa ou do julgamento. */
    public function arquivo(Documento $documento, string $qual)
    {
        $a = $documento->defesa[$qual === 'julgamento' ? 'julgamento_anexo' : 'anexo'] ?? null;
        abort_unless($a && Storage::disk('private')->exists($a['arquivo']), 404);

        return Storage::disk('private')->response($a['arquivo'], $a['nome'] ?: 'defesa',
            ['Content-Type' => $a['mime'] ?: 'application/octet-stream']);
    }

    /**
     * Guarda o arquivo no disco privado, na pasta da peça, e devolve o que a
     * coluna `defesa` registra dele. O arquivo anterior, se houver, sai.
     *
     * @param  array{arquivo:string}|null $anterior
     * @return array{arquivo:string, nome:string, mime:string}
     */
    private function guardar(Documento $documento, UploadedFile $arquivo, ?array $anterior): array
    {
        if ($anterior && ! empty($anterior['arquivo'])) {
            Storage::disk('private')->delete($anterior['arquivo']);
        }

        return [
            'arquivo' => $arquivo->store('documentos/' . $documento->id . '/defesa', 'private'),
            'nome'    => mb_substr($arquivo->getClientOriginalName(), 0, 255),
            'mime'    => $arquivo->getMimeType() ?: 'application/octet-stream',
        ];
    }
}
