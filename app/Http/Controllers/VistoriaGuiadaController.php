<?php

namespace App\Http\Controllers;

use App\Models\Vg\VgArquivo;
use App\Models\Vg\VgLivre;
use App\Models\Vg\VgModelo;
use App\Models\Vg\VgModeloVersao;
use App\Models\Vg\VgVistoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * VISTORIA GUIADA — PÁGINA DE TESTE (/vistorias-guiadas).
 *
 * Separada da vistoria de obra de propósito: tabelas `vg_*`, nenhuma ligação
 * com lote ou protocolo. Ver a migração `vistorias_guiadas_teste`.
 *
 * Quem faz o quê:
 *   - montar e publicar modelos: administrador;
 *   - criar e preencher vistorias: quem pode editar (`canEdit`);
 *   - ver: qualquer servidor interno (a rota já está no grupo `interno`).
 *
 * A tela confere as pendências para travar o "Próxima"; o servidor confere de
 * novo ao iniciar e ao concluir. A tela ajuda, o servidor decide.
 */
class VistoriaGuiadaController extends Controller
{
    const LOCAIS = ['nao', 'gps', 'mapa', 'gps_ou_mapa', 'gps_e_mapa'];
    const PASTA = 'vistorias-guiadas';

    public function pagina(Request $request)
    {
        $u = $request->user();
        return view('vistorias-guiadas', [
            'usuario' => ['nome' => $u->name, 'admin' => $u->isAdmin(), 'edita' => $u->canEdit()],
        ]);
    }

    // ══════════════ MODELOS ══════════════

    public function modelos(Request $request): JsonResponse
    {
        $admin = $request->user()->isAdmin();
        $q = VgModelo::query()->orderBy('nome');
        if (! $admin) {
            $q->where('ativo', true)->where('versao_atual', '>', 0);
        }

        return response()->json(['modelos' => $q->get()->map(function (VgModelo $m) use ($admin) {
            $vig = $m->versaoVigente();
            return [
                'id' => $m->id, 'nome' => $m->nome, 'ativo' => $m->ativo,
                'versao' => $m->versao_atual, 'alterado' => $m->rascunho_alterado,
                'rascunho' => $admin ? $m->rascunho : null,
                // O que a vistoria nova usa. Sem versão publicada, o modelo
                // não aparece para criar vistoria.
                'vigente' => $vig ? ['id' => $vig->id, 'versao' => $vig->versao, 'definicao' => $vig->definicao] : null,
            ];
        })]);
    }

    public function criarModelo(Request $request): JsonResponse
    {
        $this->soAdmin($request);
        $d = $request->validate(['nome' => ['required', 'string', 'max:120']]);
        $m = VgModelo::create([
            'nome' => $d['nome'], 'user_id' => $request->user()->id, 'rascunho_alterado' => true,
            'rascunho' => [
                'local' => 'gps', 'nao_foto' => true, 'conclusoes' => ['Regular', 'Com ressalvas', 'Irregular'],
                'anexos' => [],
                'etapas' => [['id' => 'e' . bin2hex(random_bytes(4)), 'titulo' => 'Primeira etapa', 'instrucao' => '',
                    'foto' => 'obrigatoria', 'foto_min' => 1, 'foto_dica' => '', 'anexo' => '',
                    'itens' => [['id' => 'i' . bin2hex(random_bytes(4)), 'rotulo' => 'Primeiro item', 'obrig' => true]]]],
            ],
        ]);
        return response()->json(['id' => $m->id], 201);
    }

    public function salvarModelo(Request $request, VgModelo $modelo): JsonResponse
    {
        $this->soAdmin($request);
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'ativo' => ['required', 'boolean'],
            'rascunho' => ['required', 'array'],
        ]);
        $modelo->update([
            'nome' => $d['nome'], 'ativo' => $d['ativo'],
            'rascunho' => $this->definicao($d['rascunho'], false),
            'rascunho_alterado' => true,
        ]);
        return response()->json(['ok' => true]);
    }

    public function publicar(Request $request, VgModelo $modelo): JsonResponse
    {
        $this->soAdmin($request);
        $def = $this->definicao($modelo->rascunho, true);

        $v = DB::transaction(function () use ($modelo, $def, $request) {
            $n = $modelo->versao_atual + 1;
            VgModeloVersao::create([
                'modelo_id' => $modelo->id, 'versao' => $n, 'nome' => $modelo->nome, 'definicao' => $def,
                'publicado_por' => $request->user()->id, 'publicado_em' => now(),
            ]);
            $modelo->update(['versao_atual' => $n, 'rascunho_alterado' => false, 'rascunho' => $def]);
            return $n;
        });
        return response()->json(['versao' => $v, 'message' => "Versão {$v} publicada."]);
    }

    /** Os três modelos do protótipo, já publicados — para a página de teste não abrir vazia. */
    public function exemplos(Request $request): JsonResponse
    {
        $this->soAdmin($request);
        $id = fn (string $p) => $p . bin2hex(random_bytes(4));
        $et = fn ($titulo, $instr, $foto, $min, $dica, $anexo, array $itens) => [
            'id' => $id('e'), 'titulo' => $titulo, 'instrucao' => $instr, 'foto' => $foto, 'foto_min' => $min,
            'foto_dica' => $dica, 'anexo' => $anexo,
            'itens' => array_map(fn ($r) => ['id' => $id('i'), 'rotulo' => ltrim($r, '?'), 'obrig' => $r[0] !== '?'], $itens),
        ];
        $arq = $id('a'); $hid = $id('a'); $arq2 = $id('a');
        $defs = [
            'Habite-se' => ['local' => 'gps_ou_mapa', 'nao_foto' => true,
                'conclusoes' => ['Apto ao habite-se', 'Apto com ressalvas', 'Não apto'],
                'anexos' => [['id' => $arq, 'nome' => 'Projeto arquitetônico aprovado', 'obrig' => true],
                             ['id' => $hid, 'nome' => 'Projeto hidrossanitário', 'obrig' => false]],
                'etapas' => [
                    $et('Identificação', 'Fotografe a fachada com o número visível.', 'obrigatoria', 1, 'Fachada com o número', '', ['Número do imóvel visível', 'Endereço confere com o protocolo']),
                    $et('Implantação', 'Leia a folha de implantação e compare com o que está construído.', 'obrigatoria', 1, 'Frente do lote', $arq, ['Recuos', 'Posição da edificação no lote', 'Área permeável']),
                    $et('Edificação', 'Confira pela planta baixa.', 'opcional', 1, 'Ambientes internos', $arq, ['Área construída compatível', 'Número de pavimentos', 'Divisão interna', '?Uso conforme o aprovado']),
                    $et('Calçada e acessibilidade', 'Percorra toda a testada.', 'obrigatoria', 2, 'Calçada inteira e rampa', '', ['Calçada executada', 'Faixa livre sem obstáculo', 'Rampa de veículos sem degrau']),
                    $et('Instalações', 'Peça para abrir a caixa de inspeção.', 'obrigatoria', 1, 'Caixa de inspeção', $hid, ['Ligação de esgoto (rede ou fossa)', 'Caixa de inspeção acessível', 'Água de chuva fora da calçada']),
                    $et('Obra concluída', 'Sem andaime, tapume ou entulho no passeio.', 'obrigatoria', 2, 'Frente e lateral', '', ['Obra concluída', 'Entulho removido do passeio']),
                ]],
            'Início de obra' => ['local' => 'gps', 'nao_foto' => true, 'conclusoes' => ['Regular', 'Com ressalvas', 'Irregular'],
                'anexos' => [['id' => $arq2, 'nome' => 'Projeto arquitetônico aprovado', 'obrig' => true]],
                'etapas' => [
                    $et('Placa e alvará', '', 'obrigatoria', 1, 'Placa da obra', '', ['Placa com responsável técnico', 'Alvará disponível na obra']),
                    $et('Locação', 'Compare o gabarito com a implantação.', 'obrigatoria', 1, 'Gabarito ou fundação', $arq2, ['Recuos', 'Posição no lote']),
                    $et('Canteiro', '', 'opcional', 1, '', '', ['Tapume no alinhamento', 'Material fora do passeio']),
                ]],
            'Averiguação de denúncia' => ['local' => 'gps', 'nao_foto' => false,
                'conclusoes' => ['Procedente', 'Improcedente', 'Não constatado'], 'anexos' => [], 'etapas' => []],
        ];

        DB::transaction(function () use ($defs, $request) {
            foreach ($defs as $nome => $def) {
                $def = $this->definicao($def, true);
                $m = VgModelo::create(['nome' => $nome, 'rascunho' => $def, 'rascunho_alterado' => false,
                    'versao_atual' => 1, 'user_id' => $request->user()->id]);
                VgModeloVersao::create(['modelo_id' => $m->id, 'versao' => 1, 'nome' => $nome, 'definicao' => $def,
                    'publicado_por' => $request->user()->id, 'publicado_em' => now()]);
            }
        });
        return response()->json(['message' => 'Modelos de exemplo carregados.']);
    }

    /**
     * Confere e normaliza a definição. No rascunho aceita frase vazia (o
     * administrador ainda está escrevendo); para publicar, não.
     */
    private function definicao(array $d, bool $publicar): array
    {
        $id = ['required', 'string', 'regex:/^[A-Za-z0-9]{1,40}$/'];
        Validator::make($d, [
            'local' => ['required', 'in:' . implode(',', self::LOCAIS)],
            'nao_foto' => ['required', 'boolean'],
            'conclusoes' => ['array', 'max:10'], 'conclusoes.*' => ['string', 'max:120'],
            'anexos' => ['array', 'max:10'],
            'anexos.*.id' => $id, 'anexos.*.nome' => ['nullable', 'string', 'max:160'], 'anexos.*.obrig' => ['boolean'],
            'etapas' => ['array', 'max:40'],
            'etapas.*.id' => $id,
            'etapas.*.titulo' => ['nullable', 'string', 'max:160'],
            'etapas.*.instrucao' => ['nullable', 'string', 'max:500'],
            'etapas.*.foto' => ['required', 'in:nao,opcional,obrigatoria'],
            'etapas.*.foto_min' => ['required', 'integer', 'min:1', 'max:20'],
            'etapas.*.foto_dica' => ['nullable', 'string', 'max:160'],
            'etapas.*.anexo' => ['nullable', 'string', 'max:40'],
            'etapas.*.itens' => ['array', 'max:60'],
            'etapas.*.itens.*.id' => $id,
            'etapas.*.itens.*.rotulo' => ['nullable', 'string', 'max:200'],
            'etapas.*.itens.*.obrig' => ['boolean'],
        ])->validate();

        $anexos = array_values(array_map(fn ($a) => ['id' => $a['id'], 'nome' => trim($a['nome'] ?? ''), 'obrig' => (bool) ($a['obrig'] ?? false)], $d['anexos'] ?? []));
        $idsAnexo = array_column($anexos, 'id');
        $etapas = array_values(array_map(fn ($e) => [
            'id' => $e['id'], 'titulo' => trim($e['titulo'] ?? ''), 'instrucao' => trim($e['instrucao'] ?? ''),
            'foto' => $e['foto'], 'foto_min' => (int) $e['foto_min'], 'foto_dica' => trim($e['foto_dica'] ?? ''),
            'anexo' => in_array($e['anexo'] ?? '', $idsAnexo, true) ? $e['anexo'] : '',
            'itens' => array_values(array_map(fn ($i) => ['id' => $i['id'], 'rotulo' => trim($i['rotulo'] ?? ''), 'obrig' => (bool) ($i['obrig'] ?? true)], $e['itens'] ?? [])),
        ], $d['etapas'] ?? []));
        $out = [
            'local' => $d['local'], 'nao_foto' => (bool) $d['nao_foto'],
            'conclusoes' => array_values(array_filter(array_map('trim', $d['conclusoes'] ?? []), 'strlen')),
            'anexos' => $anexos, 'etapas' => $etapas,
        ];

        if ($publicar) {
            $erros = [];
            if (! $out['conclusoes']) { $erros[] = 'Informe ao menos uma conclusão possível.'; }
            foreach ($anexos as $a) { if ($a['nome'] === '') { $erros[] = 'Há um anexo sem nome.'; break; } }
            foreach ($etapas as $k => $e) {
                if ($e['titulo'] === '') { $erros[] = 'A etapa ' . ($k + 1) . ' está sem título.'; }
                if (! $e['itens']) { $erros[] = 'A etapa "' . ($e['titulo'] ?: $k + 1) . '" não tem itens.'; }
                foreach ($e['itens'] as $i) {
                    if ($i['rotulo'] === '') { $erros[] = 'A etapa "' . ($e['titulo'] ?: $k + 1) . '" tem item sem texto.'; break; }
                }
            }
            if ($erros) { throw ValidationException::withMessages(['definicao' => $erros]); }
        }
        return $out;
    }

    // ══════════════ VISTORIAS ══════════════

    public function vistorias(): JsonResponse
    {
        $vs = VgVistoria::with(['versao', 'autor'])->withCount(['arquivos as anexos_count' => fn ($q) => $q->where('papel', 'anexo')])
            ->latest('id')->limit(200)->get();
        return response()->json(['vistorias' => $vs->map(fn (VgVistoria $v) => [
            'id' => $v->id, 'modelo' => $v->versao->nome, 'versao' => $v->versao->versao,
            'protocolo' => $v->protocolo, 'imovel' => $v->imovel, 'estado' => $v->estado,
            'anexos' => $v->anexos_count, 'autor' => $v->autor?->name, 'criada_em' => $v->created_at->format('d/m/Y H:i'),
            'conclusao' => $v->conclusao,
        ])]);
    }

    public function criarVistoria(Request $request): JsonResponse
    {
        $this->soQuemEdita($request);
        $d = $request->validate([
            'modelo_id' => ['required', 'integer'],
            'protocolo' => ['nullable', 'string', 'max:40'],
            'imovel' => ['required', 'string', 'max:255'],
            'anexos' => ['array'], 'anexos.*' => ['file', 'mimetypes:application/pdf', 'max:30720'],
            'extras' => ['array', 'max:10'], 'extras.*' => ['file', 'mimetypes:application/pdf', 'max:30720'],
        ], [], ['anexos.*' => 'anexo', 'extras.*' => 'PDF']);

        $modelo = VgModelo::where('ativo', true)->findOrFail($d['modelo_id']);
        $versao = $modelo->versaoVigente();
        abort_unless($versao, 422, 'Este modelo ainda não tem versão publicada.');

        $esperados = collect($versao->definicao['anexos'] ?? [])->keyBy('id');
        $recebidos = $request->file('anexos', []);
        $faltam = $esperados->filter(fn ($a) => $a['obrig'] && empty($recebidos[$a['id']]))->pluck('nome');
        if ($faltam->isNotEmpty()) {
            throw ValidationException::withMessages(['anexos' => 'Falta anexar: ' . $faltam->implode(', ') . '.']);
        }

        $gravados = [];
        try {
            $v = DB::transaction(function () use ($request, $d, $versao, $esperados, $recebidos, &$gravados) {
                $v = VgVistoria::create([
                    'versao_id' => $versao->id, 'protocolo' => $d['protocolo'] ?? null, 'imovel' => $d['imovel'],
                    'user_id' => $request->user()->id, 'respostas' => [],
                ]);
                foreach ($recebidos as $chave => $f) {
                    if (! $esperados->has($chave)) { continue; }
                    $gravados[] = $this->gravarArquivo($v, $f, ['papel' => 'anexo', 'anexo_chave' => $chave, 'nome' => $esperados[$chave]['nome']], $request);
                }
                foreach ($request->file('extras', []) as $f) {
                    $gravados[] = $this->gravarArquivo($v, $f, ['papel' => 'anexo', 'nome' => mb_substr(preg_replace('/\.pdf$/i', '', $f->getClientOriginalName()), 0, 160)], $request);
                }
                return $v;
            });
        } catch (\Throwable $e) {
            foreach ($gravados as $a) { Storage::disk('private')->delete($a->caminho); }
            throw $e;
        }
        return response()->json(['id' => $v->id, 'message' => 'Vistoria criada.'], 201);
    }

    public function mostrar(VgVistoria $vistoria): JsonResponse
    {
        return response()->json($this->vistoriaJson($vistoria->load(['versao', 'autor', 'arquivos', 'livres.autor'])));
    }

    /**
     * Grava o andamento: localização, respostas e o início. Chamado a cada
     * resposta (com espera curta na tela), então é leve e idempotente.
     */
    public function andamento(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soQuemEdita($request);
        abort_if($vistoria->concluida(), 422, 'A vistoria já foi concluída. Use Complementar para acrescentar itens.');
        $d = $request->validate([
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'], 'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_precisao' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'mapa_lat' => ['nullable', 'numeric', 'between:-90,90'], 'mapa_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'respostas' => ['nullable', 'array'],
            'iniciar' => ['nullable', 'boolean'],
        ]);
        $def = $vistoria->versao->definicao;

        $campos = [];
        // A localização é a da ABERTURA: depois de iniciada, não muda.
        if (! $vistoria->iniciada_em) {
            foreach (['gps_lat', 'gps_lng', 'gps_precisao', 'mapa_lat', 'mapa_lng'] as $k) {
                if ($request->has($k)) { $campos[$k] = $d[$k]; }
            }
        }
        if (array_key_exists('respostas', $d)) {
            $campos['respostas'] = $this->limparRespostas($def, $d['respostas'] ?? []);
        }
        if (! empty($d['iniciar']) && ! $vistoria->iniciada_em) {
            $tmp = (clone $vistoria)->fill($campos);
            abort_unless($this->localizacaoOk($def['local'], $tmp), 422, 'Falta a localização que este modelo exige.');
            $campos['iniciada_em'] = now();
            $campos['estado'] = 'em_andamento';
        }
        $vistoria->update($campos);
        return response()->json(['ok' => true, 'iniciada_em' => $vistoria->iniciada_em?->toIso8601String()]);
    }

    public function concluir(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soQuemEdita($request);
        abort_if($vistoria->concluida(), 422, 'A vistoria já foi concluída.');
        abort_unless($vistoria->iniciada_em, 422, 'A vistoria não foi iniciada.');
        $def = $vistoria->versao->definicao;
        $d = $request->validate(['conclusao' => ['required', 'string', \Illuminate\Validation\Rule::in($def['conclusoes'])]]);

        $vistoria->load('arquivos');
        $falta = [];
        foreach ($def['etapas'] as $e) {
            foreach ($this->pendencias($e, $def, $vistoria) as $p) { $falta[] = "{$e['titulo']}: {$p}"; }
        }
        if ($falta) {
            throw ValidationException::withMessages(['pendencias' => array_slice($falta, 0, 8)]);
        }
        $vistoria->update(['conclusao' => $d['conclusao'], 'estado' => 'concluida', 'concluida_em' => now()]);
        return response()->json(['message' => 'Vistoria concluída.']);
    }

    // ── fotos ──

    public function foto(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soQuemEdita($request);
        $d = $request->validate([
            'arquivo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'etapa' => ['nullable', 'string', 'max:40'], 'item' => ['nullable', 'string', 'max:40'],
            'livre_id' => ['nullable', 'integer'],
        ]);
        $def = $vistoria->versao->definicao;
        $livre = null;
        if (! empty($d['livre_id'])) {
            $livre = $vistoria->livres()->findOrFail($d['livre_id']);
            abort_if($vistoria->concluida() && ! $livre->depois, 422, 'Este item é anterior à conclusão e não muda mais.');
        } else {
            abort_if($vistoria->concluida(), 422, 'Depois de concluída, foto nova entra por um item livre.');
            abort_unless($vistoria->iniciada_em, 422, 'Inicie a vistoria antes de fotografar.');
            $etapas = collect($def['etapas'])->keyBy('id');
            abort_unless(! empty($d['etapa']) && $etapas->has($d['etapa']), 422, 'Etapa desconhecida.');
            if (! empty($d['item'])) {
                abort_unless(collect($etapas[$d['etapa']]['itens'])->contains('id', $d['item']), 422, 'Item desconhecido.');
                // Uma foto por "não confere": a nova substitui a anterior.
                $vistoria->arquivos()->where('item_chave', $d['item'])->get()->each(fn ($a) => $this->apagarArquivo($a));
            }
        }
        $a = $this->gravarArquivo($vistoria, $request->file('arquivo'), [
            'papel' => 'foto', 'nome' => 'Foto',
            'etapa_chave' => $livre ? $livre->etapa_chave : $d['etapa'],
            'item_chave' => $livre ? null : ($d['item'] ?? null),
            'livre_id' => $livre?->id, 'depois' => $vistoria->concluida(),
        ], $request);
        return response()->json($this->arquivoJson($a), 201);
    }

    public function apagarFoto(Request $request, VgArquivo $arquivo): JsonResponse
    {
        $this->soQuemEdita($request);
        abort_unless($arquivo->papel === 'foto', 422, 'Anexo da vistoria não se apaga por aqui.');
        abort_if($arquivo->vistoria->concluida() && ! $arquivo->depois, 422, 'A foto é anterior à conclusão e não sai mais.');
        $this->apagarArquivo($arquivo);
        return response()->json(['ok' => true]);
    }

    public function arquivo(VgArquivo $arquivo)
    {
        abort_unless(Storage::disk('private')->exists($arquivo->caminho), 404);
        return Storage::disk('private')->response($arquivo->caminho, $arquivo->nome_original ?: 'arquivo', ['Content-Type' => $arquivo->mime]);
    }

    // ── itens livres ──

    public function criarLivre(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soQuemEdita($request);
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:160'], 'observacao' => ['nullable', 'string', 'max:4000'],
            'etapa' => ['nullable', 'string', 'max:40'],
        ]);
        abort_unless($vistoria->iniciada_em, 422, 'Inicie a vistoria antes de registrar itens.');
        $etapa = $d['etapa'] ?? null;
        if ($etapa && ! collect($vistoria->versao->definicao['etapas'])->contains('id', $etapa)) { $etapa = null; }
        $l = $vistoria->livres()->create([
            'titulo' => $d['titulo'], 'observacao' => $d['observacao'] ?? null, 'etapa_chave' => $etapa,
            'depois' => $vistoria->concluida(), 'user_id' => $request->user()->id,
        ]);
        return response()->json($this->livreJson($l->load('autor')), 201);
    }

    public function apagarLivre(Request $request, VgLivre $livre): JsonResponse
    {
        $this->soQuemEdita($request);
        abort_if($livre->vistoria->concluida() && ! $livre->depois, 422, 'O item é anterior à conclusão e não sai mais.');
        $livre->fotos->each(fn ($a) => $this->apagarArquivo($a));
        $livre->delete();
        return response()->json(['ok' => true]);
    }

    // ══════════════ apoio ══════════════

    private function soAdmin(Request $r): void { abort_unless($r->user()->isAdmin(), 403, 'Só o administrador monta modelos.'); }
    private function soQuemEdita(Request $r): void { abort_unless($r->user()->canEdit(), 403, 'Seu perfil é só de consulta.'); }

    private function localizacaoOk(string $local, VgVistoria $v): bool
    {
        $gps = $v->gps_lat !== null && $v->gps_lng !== null;
        $mapa = $v->mapa_lat !== null && $v->mapa_lng !== null;
        return match ($local) {
            'nao' => true, 'gps' => $gps, 'mapa' => $mapa, 'gps_ou_mapa' => $gps || $mapa, 'gps_e_mapa' => $gps && $mapa,
        };
    }

    private function limparRespostas(array $def, array $r): array
    {
        $itens = collect($def['etapas'])->flatMap(fn ($e) => array_column($e['itens'], 'id'))->flip();
        $out = [];
        foreach ($r as $k => $x) {
            if (! isset($itens[$k]) || ! is_array($x)) { continue; }
            $v = in_array($x['v'] ?? null, ['sim', 'nao', 'na'], true) ? $x['v'] : null;
            $obs = is_string($x['obs'] ?? null) ? mb_substr($x['obs'], 0, 2000) : null;
            if ($v || $obs) { $out[$k] = ['v' => $v, 'obs' => $obs]; }
        }
        return $out;
    }

    /** O que falta na etapa — a mesma regra da tela (`pendencias` em vistorias-guiadas.js). */
    private function pendencias(array $e, array $def, VgVistoria $v): array
    {
        $r = $v->respostas ?? [];
        $fotos = $v->arquivos->where('papel', 'foto');
        $f = [];
        foreach ($e['itens'] as $i) {
            $x = $r[$i['id']] ?? [];
            if ($i['obrig'] && empty($x['v'])) { $f[] = $i['rotulo']; }
            if (($x['v'] ?? null) === 'nao') {
                if (trim($x['obs'] ?? '') === '') { $f[] = 'descrição de "' . $i['rotulo'] . '"'; }
                if ($def['nao_foto'] && ! $fotos->where('item_chave', $i['id'])->count()) { $f[] = 'foto de "' . $i['rotulo'] . '"'; }
            }
        }
        $n = $fotos->where('etapa_chave', $e['id'])->whereNull('item_chave')->whereNull('livre_id')->count();
        if ($e['foto'] === 'obrigatoria' && $n < $e['foto_min']) { $f[] = ($e['foto_min'] - $n) . ' foto(s) da etapa'; }
        return $f;
    }

    private function gravarArquivo(VgVistoria $v, $f, array $attrs, Request $r): VgArquivo
    {
        $caminho = $f->store(self::PASTA . '/' . $v->id, 'private');
        return $v->arquivos()->create($attrs + [
            'nome_original' => mb_substr($f->getClientOriginalName(), 0, 255), 'caminho' => $caminho,
            'mime' => $f->getMimeType(), 'tamanho' => $f->getSize(), 'user_id' => $r->user()->id,
        ]);
    }

    private function apagarArquivo(VgArquivo $a): void
    {
        Storage::disk('private')->delete($a->caminho);
        $a->delete();
    }

    private function arquivoJson(VgArquivo $a): array
    {
        return [
            'id' => $a->id, 'papel' => $a->papel, 'nome' => $a->nome, 'nome_original' => $a->nome_original,
            'anexo' => $a->anexo_chave, 'etapa' => $a->etapa_chave, 'item' => $a->item_chave, 'livre_id' => $a->livre_id,
            'tamanho' => $a->tamanho, 'depois' => $a->depois, 'url' => route('vg.arquivo', $a),
        ];
    }

    private function livreJson(VgLivre $l): array
    {
        return [
            'id' => $l->id, 'etapa' => $l->etapa_chave, 'titulo' => $l->titulo, 'observacao' => $l->observacao,
            'depois' => $l->depois, 'quando' => $l->created_at->format('d/m/Y H:i'), 'autor' => $l->autor?->name,
        ];
    }

    private function vistoriaJson(VgVistoria $v): array
    {
        return [
            'id' => $v->id, 'modelo' => $v->versao->nome, 'versao' => $v->versao->versao, 'definicao' => $v->versao->definicao,
            'protocolo' => $v->protocolo, 'imovel' => $v->imovel, 'estado' => $v->estado,
            'gps' => $v->gps_lat !== null ? ['lat' => $v->gps_lat, 'lng' => $v->gps_lng, 'prec' => $v->gps_precisao] : null,
            'mapa' => $v->mapa_lat !== null ? ['lat' => $v->mapa_lat, 'lng' => $v->mapa_lng] : null,
            'respostas' => (object) ($v->respostas ?? []), 'conclusao' => $v->conclusao,
            'iniciada_em' => $v->iniciada_em?->format('d/m/Y H:i'), 'concluida_em' => $v->concluida_em?->format('d/m/Y H:i'),
            'autor' => $v->autor?->name,
            'arquivos' => $v->arquivos->map(fn ($a) => $this->arquivoJson($a))->values(),
            'livres' => $v->livres->map(fn ($l) => $this->livreJson($l))->values(),
        ];
    }
}
