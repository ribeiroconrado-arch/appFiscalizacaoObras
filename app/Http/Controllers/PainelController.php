<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\Irregularidade;
use App\Models\Vistoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Painel — a tela que responde "o que mudou e o que precisa de mim?".
 *
 * Até aqui o sistema só respondia "onde estou e o que tem aqui?" (o mapa).
 * Tudo abaixo sai de dado real: nada é simulado. Onde um bloco ainda não tem
 * fonte de dados, ele simplesmente não aparece — melhor um painel menor e
 * verdadeiro do que um cheio de números inventados.
 */
class PainelController extends Controller
{
    /** GET /api/painel?dias=30 */
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'dias'   => ['nullable', 'integer', 'min:1', 'max:3650'],
            'bairro' => ['nullable', 'string', 'max:120'],
            'agente' => ['nullable', 'in:eu,todos'],
        ]);

        $dias   = $d['dias'] ?? 30;
        $desde  = now()->subDays($dias);
        $soMeu  = ($d['agente'] ?? 'todos') === 'eu';
        $uid    = $request->user()->id;

        $vistorias = Vistoria::query()->where('data_hora', '>=', $desde)
            ->when($soMeu, fn ($q) => $q->where('fiscal_id', $uid))
            ->when($d['bairro'] ?? null, fn ($q, $b) => $q->whereHas('lote', fn ($l) => $l->where('bairro', $b)));

        $documentos = Documento::query()->where('created_at', '>=', $desde)
            ->when($soMeu, fn ($q) => $q->where('agente_id', $uid));

        return response()->json([
            'periodo'   => ['dias' => $dias, 'desde' => $desde->format('d/m/Y')],
            'metricas'  => $this->metricas(clone $vistorias, clone $documentos),
            'atencao'   => $this->atencao($uid),
            'recentes'  => $this->recentes(),
            'por_tipo'  => $this->documentosPorTipo(clone $documentos),
            'irregularidades' => $this->irregularidadesFrequentes($desde),
            'bairros'   => DB::table('lotes')->distinct()->orderBy('bairro')->pluck('bairro'),
        ]);
    }

    /** @return array<string,array{n:int,rotulo:string,detalhe:?string}> */
    private function metricas($vistorias, $documentos): array
    {
        $irregulares = (clone $vistorias)->where('situacao', 'irregular')->count();

        return [
            'vistorias'   => ['n' => (clone $vistorias)->count(), 'rotulo' => 'Vistorias no período', 'detalhe' => null],
            'irregulares' => ['n' => $irregulares, 'rotulo' => 'Vistorias irregulares', 'detalhe' => null],
            'documentos'  => ['n' => (clone $documentos)->count(), 'rotulo' => 'Documentos emitidos', 'detalhe' => null],
            'rascunhos'   => ['n' => (clone $documentos)->where('status', 'rascunho')->count(),
                              'rotulo' => 'Rascunhos', 'detalhe' => 'não lavrados'],
        ];
    }

    /**
     * "Precisa de atenção": itens acionáveis, não estatística.
     * Cada linha aponta para algo que trava o processo se ninguém agir.
     *
     * @return list<array{titulo:string,detalhe:string,tag:?array,aba:?string}>
     */
    private function atencao(int $uid): array
    {
        $itens = [];

        // 1. Prazos vencendo ou vencidos, dos documentos do próprio usuário
        $prazos = Documento::query()
            ->where('agente_id', $uid)
            ->whereIn('status', ['lavrado'])
            ->where(function ($q) {
                $q->whereNotNull('prazo_ate')->orWhereNotNull('defesa_ate');
            })
            ->with('lote:id,bairro,quadra,numero_lote')
            ->get()
            ->filter(fn (Documento $doc) => $doc->situacaoPrazo() !== null)
            ->filter(function (Documento $doc) {
                [$txt] = $doc->situacaoPrazo();
                return str_contains($txt, 'venceu') || str_contains($txt, 'vence');
            });

        foreach ($prazos as $doc) {
            [$txt, $cls] = $doc->situacaoPrazo();
            $itens[] = [
                'titulo'  => $doc->numeroFormatado(),
                'detalhe' => sprintf('%s · Quadra %s · Lote %s',
                    $doc->rotuloTipo(), $doc->lote?->quadra ?? '—', $doc->lote?->numero_lote ?? '—'),
                'tag'     => ['texto' => $txt, 'classe' => $cls],
                'aba'     => 'documentos',
            ];
        }

        // 2. Vistorias irregulares sem nenhum documento no mesmo lote
        $semDoc = Vistoria::query()
            ->where('situacao', 'irregular')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('documentos')
                  ->whereColumn('documentos.vistoria_id', 'vistorias.id');
            })
            ->count();
        if ($semDoc > 0) {
            $itens[] = [
                'titulo'  => $semDoc . ' vistoria(s) irregular(es) sem documento',
                'detalhe' => 'Constatação registrada, ato administrativo não emitido',
                'tag'     => ['texto' => 'Sem documento', 'classe' => 'bd-er'],
                'aba'     => 'documentos',
            ];
        }

        // 3. Irregularidades sem enquadramento legal — bloqueiam a lavratura
        $semArtigo = Irregularidade::ativas()->whereDoesntHave('artigos')->count();
        if ($semArtigo > 0) {
            $itens[] = [
                'titulo'  => $semArtigo . ' irregularidade(s) sem artigo vinculado',
                'detalhe' => 'Sem fundamentação legal o sistema bloqueia a lavratura',
                'tag'     => ['texto' => 'Bloqueia auto', 'classe' => 'bd-al'],
                'aba'     => null,
            ];
        }

        return $itens;
    }

    /**
     * Alterações recentes vindas da AUDITORIA — a mesma trilha que responde
     * "quem fez o quê" no processo administrativo. Não é um log paralelo.
     *
     * @return list<array<string,mixed>>
     */
    private function recentes(): array
    {
        return DB::table('auditoria')
            ->orderByDesc('id')->limit(12)->get()
            ->map(fn ($a) => [
                'acao'     => $a->acao,
                'usuario'  => $a->usuario_nome ?? 'sistema',
                'alvo'     => $this->rotuloTabela($a->tabela) . ($a->descricao ? ' ' . $a->descricao : ''),
                'quando'   => $this->quando($a->created_at),
            ])->all();
    }

    private function rotuloTabela(string $t): string
    {
        return match ($t) {
            'documentos'     => 'documento',
            'vistorias'      => 'vistoria',
            'artigos'        => 'artigo',
            'legislacoes'    => 'lei',
            'evidencias'     => 'evidência',
            'users'          => 'usuário',
            default          => $t,
        };
    }

    /** "há 2 h", "ontem", "15/08" — mais legível que timestamp na lista. */
    private function quando(string $data): string
    {
        $d = \Carbon\Carbon::parse($data);
        if ($d->isToday())     { return $d->format('H:i'); }
        if ($d->isYesterday()) { return 'ontem'; }
        return $d->format('d/m');
    }

    /** @return list<array{rotulo:string,n:int}> */
    private function documentosPorTipo($documentos): array
    {
        $contagem = (clone $documentos)->select('tipo', DB::raw('COUNT(*) n'))
            ->groupBy('tipo')->pluck('n', 'tipo');

        $saida = [];
        foreach (Documento::TIPOS as $valor => $t) {
            if (($contagem[$valor] ?? 0) > 0) {
                $saida[] = ['rotulo' => $t[0], 'n' => (int) $contagem[$valor]];
            }
        }
        usort($saida, fn ($a, $b) => $b['n'] <=> $a['n']);
        return $saida;
    }

    /** @return list<array{rotulo:string,n:int}> */
    private function irregularidadesFrequentes(\Carbon\Carbon $desde): array
    {
        return DB::table('vistoria_irregularidades as vi')
            ->join('irregularidades as i', 'i.id', '=', 'vi.irregularidade_id')
            ->join('vistorias as v', 'v.id', '=', 'vi.vistoria_id')
            ->where('v.data_hora', '>=', $desde)
            ->select('i.descricao as rotulo', DB::raw('COUNT(*) as n'))
            ->groupBy('i.id', 'i.descricao')
            ->orderByDesc('n')->limit(6)
            ->get()->map(fn ($r) => ['rotulo' => $r->rotulo, 'n' => (int) $r->n])->all();
    }

    /**
     * GET /api/notificacoes — avisos ligados aos ATOS DO PRÓPRIO USUÁRIO.
     *
     * Calculado sob demanda, sem tabela de notificações: com este volume, uma
     * consulta é mais barata e sempre coerente com o estado real. Tabela só se
     * justifica quando houver notificação enviada por terceiro (Etapa 7).
     */
    public function notificacoes(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        $avisos = [];

        $docs = Documento::where('agente_id', $uid)
            ->whereIn('status', ['lavrado', 'rascunho'])
            ->with('lote:id,bairro,quadra,numero_lote')->get();

        foreach ($docs as $doc) {
            $imovel = sprintf('Quadra %s · Lote %s', $doc->lote?->quadra ?? '—', $doc->lote?->numero_lote ?? '—');

            if ($doc->status === 'rascunho') {
                $avisos[] = [
                    'titulo'  => 'Rascunho não lavrado',
                    'texto'   => $doc->rotuloTipo() . ' — ' . $imovel . '. Sem número enquanto não for lavrado.',
                    'quando'  => $doc->created_at->diffForHumans(),
                    'aba'     => 'documentos',
                ];
                continue;
            }
            if ($p = $doc->situacaoPrazo()) {
                [$txt, $cls] = $p;
                if ($cls !== 'bd-ok') {
                    $avisos[] = [
                        'titulo'  => $txt . ' — ' . $doc->numeroFormatado(),
                        'texto'   => $doc->rotuloTipo() . ' — ' . $imovel,
                        'quando'  => $doc->data_lavratura?->diffForHumans() ?? '',
                        'aba'     => 'documentos',
                    ];
                }
            }
        }

        return response()->json(['notificacoes' => $avisos, 'total' => count($avisos)]);
    }
}
