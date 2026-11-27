<?php

namespace App\Http\Controllers;

use App\Models\Artigo;
use App\Models\Contribuinte;
use App\Models\Denuncia;
use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\User;
use App\Models\Vg\VgArquivo;
use App\Models\Vg\VgFiscal;
use App\Models\Vg\VgItem;
use App\Models\Vg\VgLivre;
use App\Models\Vg\VgModelo;
use App\Models\Vg\VgModeloVersao;
use App\Models\Vg\VgVinculo;
use App\Models\Vg\VgVistoria;
use App\Services\CabecalhoOficial;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * VISTORIA GUIADA — PÁGINA DE TESTE (/vistorias-guiadas).
 *
 * Um motor só para qualquer vistoria — obra, terreno, via pública,
 * desmembramento, constatação — de Obras ou de Posturas. Por isso NENHUM campo
 * fixo: o que é próprio de um tipo de vistoria é item do modelo.
 *
 * Quem faz o quê:
 *   - montar e publicar modelos: administrador;
 *   - criar vistoria: quem pode editar (`canEdit`); quem cria entra como fiscal;
 *   - preencher, ligar, anexar, assinar: só os FISCAIS RELACIONADOS;
 *   - remover foto, anexo ou item livre: só quem o acrescentou;
 *   - ver e imprimir: qualquer servidor interno (a rota está no grupo `interno`).
 *
 * A tela confere as pendências para travar o "Próxima"; o servidor confere de
 * novo ao iniciar e ao concluir. A tela ajuda, o servidor decide.
 */
class VistoriaGuiadaController extends Controller
{
    const LOCAIS = ['nao', 'gps', 'mapa', 'gps_ou_mapa', 'gps_e_mapa'];
    const MODULOS = ['obras' => 'Obras', 'posturas' => 'Posturas'];
    const PASTA = 'vistorias-guiadas';
    const RESP = ['sim' => 'Confere', 'nao' => 'Não confere', 'na' => 'Não se aplica'];

    public function pagina(Request $request)
    {
        $u = $request->user();
        // A LISTA DAS VISTORIAS É A DA DOCUMENTOS (decisão dele, 27/11/2026):
        // a página só existe como janela do mapa, ou aberta numa vistoria.
        if (! $request->boolean('embed') && ! $request->has('v') && ! $request->has('nova')) {
            return redirect()->route('mapa');
        }
        return view('vistorias-guiadas', [
            // A página abre no módulo em que o usuário está: a lista e a
            // vistoria nova são desse módulo.
            'modulo' => \App\Support\Modulo::ativo($u),
            // Aberta numa janela do mapa (vistoria-guiada-ponte.js) ou em
            // Parâmetros › Vistorias: só o formulário, sem cabeçalho nem lista.
            'embutida' => $request->boolean('embed'),
            'usuario' => ['id' => $u->id, 'nome' => $u->name, 'admin' => $u->isAdmin(), 'edita' => $u->canEdit(),
                'lavra' => $u->podeLavrarDocumento(),
                'tem_assinatura' => (bool) $u->assinatura],
        ]);
    }

    // ══════════════ MODELOS ══════════════

    public function modelos(Request $request): JsonResponse
    {
        $admin = $request->user()->isAdmin();
        // Cada módulo tem os seus modelos: só os do módulo em que o usuário está.
        $q = VgModelo::query()->where('modulo', $this->modulo($request))->orderBy('nome');
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
            'nome' => $d['nome'], 'modulo' => $this->modulo($request), 'user_id' => $request->user()->id, 'rascunho_alterado' => true,
            'rascunho' => [
                'local' => 'gps', 'nao_foto' => true, 'conclusoes' => ['Regular', 'Com ressalvas', 'Irregular'],
                'vinculos' => [], 'anexos' => [],
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
        $this->doModulo($request, $modelo);
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
        $this->doModulo($request, $modelo);
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

    /** Os modelos do protótipo, já publicados — para a página de teste não abrir vazia. */
    public function exemplos(Request $request): JsonResponse
    {
        $this->soAdmin($request);
        $id = fn (string $p) => $p . bin2hex(random_bytes(4));
        $et = fn ($titulo, $instr, $foto, $min, $dica, $anexo, array $itens) => [
            'id' => $id('e'), 'titulo' => $titulo, 'instrucao' => $instr, 'foto' => $foto, 'foto_min' => $min,
            'foto_dica' => $dica, 'anexo' => $anexo,
            // Texto simples = item Confere; "?" na frente = opcional; array = item tipado.
            'itens' => array_map(fn ($r) => is_array($r) ? $r + ['id' => $id('i'), 'obrig' => true]
                : ['id' => $id('i'), 'rotulo' => ltrim($r, '?'), 'obrig' => $r[0] !== '?'], $itens),
        ];
        $arq = $id('a'); $hid = $id('a'); $arq2 = $id('a');
        $defs = [
            // A antiga "Fiscalização de obras", agora como modelo: os campos fixos
            // de antes (alvará, área, fase) viraram itens tipados.
            'Fiscalização de obra' => ['local' => 'gps_ou_mapa', 'nao_foto' => true, 'vinculos' => ['imovel'],
                'conclusoes' => ['Regular', 'Irregular', 'Em acompanhamento', 'Não localizado'], 'anexos' => [],
                'etapas' => [
                    $et('Identificação', 'Fotografe a fachada com o número visível.', 'obrigatoria', 1, 'Fachada', '', [
                        ['rotulo' => 'Quem atendeu', 'tipo' => 'opcoes', 'opcoes' => ['Proprietário', 'Responsável pela obra', 'Encarregado / pedreiro', 'Inquilino', 'Outro', 'Ninguém no local']],
                        '?Placa da obra com responsável técnico',
                    ]),
                    $et('Alvará', 'Peça o alvará e confira o número e a validade.', 'opcional', 1, 'Alvará', '', [
                        ['rotulo' => 'Alvará de construção', 'tipo' => 'opcoes', 'opcoes' => ['Possui alvará', 'Não possui alvará', 'Não verificado']],
                        ['rotulo' => 'Número do alvará', 'tipo' => 'texto', 'obrig' => false],
                        ['rotulo' => 'Vencimento do alvará', 'tipo' => 'data', 'obrig' => false],
                    ]),
                    $et('Obra', 'Meça a área construída; sem trena, estime e diga que é estimativa.', 'obrigatoria', 1, 'Obra inteira', '', [
                        ['rotulo' => 'Área construída', 'tipo' => 'numero', 'unidade' => 'm²', 'metodo' => true, 'papel' => 'area'],
                        ['rotulo' => 'Fase da obra', 'tipo' => 'opcoes', 'opcoes' => ['Fundação', 'Alvenaria', 'Cobertura', 'Acabamento', 'Concluída', 'Parada']],
                        'Recuos', 'Tapume e material fora do passeio',
                    ]),
                ]],
            'Habite-se' => ['local' => 'gps_ou_mapa', 'nao_foto' => true, 'vinculos' => ['imovel'],
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
            'Início de obra' => ['local' => 'gps', 'nao_foto' => true, 'vinculos' => ['imovel'], 'conclusoes' => ['Regular', 'Com ressalvas', 'Irregular'],
                'anexos' => [['id' => $arq2, 'nome' => 'Projeto arquitetônico aprovado', 'obrig' => true]],
                'etapas' => [
                    $et('Placa e alvará', '', 'obrigatoria', 1, 'Placa da obra', '', ['Placa com responsável técnico', 'Alvará disponível na obra']),
                    $et('Locação', 'Compare o gabarito com a implantação.', 'obrigatoria', 1, 'Gabarito ou fundação', $arq2, ['Recuos', 'Posição no lote']),
                    $et('Canteiro', '', 'opcional', 1, '', '', ['Tapume no alinhamento', 'Material fora do passeio']),
                ]],
            'Terreno sem limpeza' => ['local' => 'gps', 'nao_foto' => true, 'vinculos' => [], 'conclusoes' => ['Regular', 'Irregular'],
                'anexos' => [],
                'etapas' => [
                    $et('Terreno', 'Fotografe o terreno inteiro pela frente.', 'obrigatoria', 2, 'Frente e fundo do terreno', '', ['Mato abaixo do limite', 'Sem entulho ou lixo', 'Calçada desobstruída', '?Muro ou cerca na testada']),
                ]],
            'Averiguação de denúncia' => ['local' => 'gps', 'nao_foto' => false, 'vinculos' => ['denuncia'],
                'conclusoes' => ['Procedente', 'Improcedente', 'Não constatado'], 'anexos' => [], 'etapas' => []],
        ];

        // Cada exemplo é de um módulo; entram só os do módulo em que o
        // administrador está.
        $doModulo = ['Fiscalização de obra' => 'obras', 'Habite-se' => 'obras', 'Início de obra' => 'obras',
            'Terreno sem limpeza' => 'posturas', 'Averiguação de denúncia' => 'posturas'];
        $modulo = $this->modulo($request);
        $defs = array_filter($defs, fn ($nome) => $doModulo[$nome] === $modulo, ARRAY_FILTER_USE_KEY);

        $n = DB::transaction(function () use ($defs, $request, $modulo) {
            $n = 0;
            foreach ($defs as $nome => $def) {
                if (VgModelo::where('modulo', $modulo)->where('nome', $nome)->exists()) { continue; }   // não duplica o que já existe
                $def = $this->definicao($def, true);
                $n++;
                $m = VgModelo::create(['nome' => $nome, 'modulo' => $modulo, 'rascunho' => $def, 'rascunho_alterado' => false,
                    'versao_atual' => 1, 'user_id' => $request->user()->id]);
                VgModeloVersao::create(['modelo_id' => $m->id, 'versao' => 1, 'nome' => $nome, 'definicao' => $def,
                    'publicado_por' => $request->user()->id, 'publicado_em' => now()]);
            }
            return $n;
        });
        return response()->json(['message' => $n ? "{$n} modelo(s) de exemplo carregado(s)." : 'Os exemplos deste módulo já estão todos aqui.']);
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
            'vinculos' => ['array'], 'vinculos.*' => [Rule::in(array_keys(VgVinculo::TIPOS))],
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
            'etapas.*.itens.*.tipo' => ['nullable', Rule::in(array_keys(VgItem::TIPOS))],
            'etapas.*.itens.*.unidade' => ['nullable', 'string', 'max:12'],
            'etapas.*.itens.*.metodo' => ['nullable', 'boolean'],
            'etapas.*.itens.*.papel' => ['nullable', 'in:,area'],
            'etapas.*.itens.*.opcoes' => ['nullable', 'array', 'max:20'],
            'etapas.*.itens.*.opcoes.*' => ['nullable', 'string', 'max:80'],
        ])->validate();

        $anexos = array_values(array_map(fn ($a) => ['id' => $a['id'], 'nome' => trim($a['nome'] ?? ''), 'obrig' => (bool) ($a['obrig'] ?? false)], $d['anexos'] ?? []));
        $idsAnexo = array_column($anexos, 'id');
        $etapas = array_values(array_map(fn ($e) => [
            'id' => $e['id'], 'titulo' => trim($e['titulo'] ?? ''), 'instrucao' => trim($e['instrucao'] ?? ''),
            'foto' => $e['foto'], 'foto_min' => (int) $e['foto_min'], 'foto_dica' => trim($e['foto_dica'] ?? ''),
            'anexo' => in_array($e['anexo'] ?? '', $idsAnexo, true) ? $e['anexo'] : '',
            'itens' => array_values(array_map(fn ($i) => VgItem::normalizar($i), $e['itens'] ?? [])),
        ], $d['etapas'] ?? []));
        // Uma área da multa só: duas deixariam a lavratura sem saber qual vale.
        $achou = false;
        foreach ($etapas as &$e) {
            foreach ($e['itens'] as &$i) {
                if (($i['papel'] ?? '') === 'area') { if ($achou) { $i['papel'] = ''; } $achou = true; }
            }
        }
        unset($e, $i);
        $out = [
            'local' => $d['local'], 'nao_foto' => (bool) $d['nao_foto'],
            // Na ordem de TIPOS, sem repetição: é o que a tela lista.
            'vinculos' => array_values(array_intersect(array_keys(VgVinculo::TIPOS), $d['vinculos'] ?? [])),
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
                    array_push($erros, ...VgItem::errosAoPublicar($i, 'Etapa "' . ($e['titulo'] ?: $k + 1) . '"'));
                }
            }
            if ($erros) { throw ValidationException::withMessages(['definicao' => $erros]); }
        }
        return $out;
    }

    // ══════════════ VISTORIAS ══════════════

    public function vistorias(Request $request): JsonResponse
    {
        $vs = VgVistoria::with(['versao', 'autor', 'vinculos', 'fiscais.usuario'])
            ->where('modulo', \App\Support\Modulo::ativo($request->user()))
            ->withCount(['arquivos as anexos_count' => fn ($q) => $q->where('papel', 'anexo')])
            ->latest('id')->limit(200)->get();
        $eu = $request->user();
        return response()->json(['vistorias' => $vs->map(fn (VgVistoria $v) => [
            'id' => $v->id, 'numero' => $v->numeroFormatado(), 'modulo' => $v->modulo,
            'modelo' => $v->versao->nome, 'versao' => $v->versao->versao,
            'local' => $v->local, 'estado' => $v->estado,
            'vinculos' => $v->vinculos->map(fn ($l) => ['tipo' => $l->tipo, 'rotulo' => $l->rotulo])->values(),
            'fiscais' => $v->fiscais->map(fn ($f) => $f->usuario?->name)->filter()->values(),
            'minha' => $v->relacionado($eu),
            'anexos' => $v->anexos_count, 'criada_em' => $v->created_at->format('d/m/Y H:i'),
            'conclusao' => $v->conclusao,
        ])]);
    }

    public function criarVistoria(Request $request): JsonResponse
    {
        $this->soQuemEdita($request);
        $d = $request->validate([
            'modelo_id' => ['required', 'integer'],
            'local' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:4000'],
            // A sinalização de que a vistoria nasce: a conclusão a resolve.
            'sinalizacao_id' => ['nullable', 'integer', 'exists:sinalizacoes,id'],
            // Multipart: vínculos chegam como JSON numa string.
            'vinculos' => ['nullable', 'json'],
            'fiscais' => ['array', 'max:10'], 'fiscais.*' => ['integer'],
            'anexos' => ['array'], 'anexos.*' => ['file', 'mimetypes:application/pdf', 'max:30720'],
            'extras' => ['array', 'max:10'], 'extras.*' => ['file', 'mimetypes:application/pdf', 'max:30720'],
        ], [], ['anexos.*' => 'anexo', 'extras.*' => 'PDF']);

        // O modelo é do módulo em que o usuário está, e a vistoria é do módulo do modelo.
        $modelo = VgModelo::where('ativo', true)->where('modulo', $this->modulo($request))->findOrFail($d['modelo_id']);
        $d['modulo'] = $modelo->modulo;
        $versao = $modelo->versaoVigente();
        abort_unless($versao, 422, 'Este modelo ainda não tem versão publicada.');
        $def = $versao->definicao;

        // Os vínculos são resolvidos AQUI, no servidor: o rótulo vem do
        // cadastro, nunca do que a tela mandou.
        $vinc = collect(json_decode($d['vinculos'] ?? '[]', true) ?: [])
            ->filter(fn ($x) => is_array($x) && isset(VgVinculo::TIPOS[$x['tipo'] ?? '']) && is_numeric($x['id'] ?? null))
            ->unique(fn ($x) => $x['tipo'] . ':' . $x['id'])->take(20)
            ->map(fn ($x) => ['tipo' => $x['tipo'], 'ref_id' => (int) $x['id'], 'rotulo' => $this->rotuloVinculo($x['tipo'], (int) $x['id'])])
            ->values();

        $erros = [];
        foreach ($def['vinculos'] ?? [] as $tipo) {
            if (! $vinc->contains('tipo', $tipo)) { $erros[] = 'Este modelo exige ligar a vistoria a: ' . mb_strtolower(VgVinculo::TIPOS[$tipo]) . '.'; }
        }
        if ($vinc->isEmpty() && trim($d['local'] ?? '') === '') {
            $erros[] = 'Ligue a vistoria a algo (imóvel, pessoa, protocolo ou denúncia) ou descreva o local.';
        }
        $esperados = collect($def['anexos'] ?? [])->keyBy('id');
        $recebidos = $request->file('anexos', []);
        $faltam = $esperados->filter(fn ($a) => $a['obrig'] && empty($recebidos[$a['id']]))->pluck('nome');
        if ($faltam->isNotEmpty()) { $erros[] = 'Falta anexar: ' . $faltam->implode(', ') . '.'; }
        if ($erros) { throw ValidationException::withMessages(['vistoria' => $erros]); }

        $eu = $request->user();
        $fiscais = User::whereIn('id', $d['fiscais'] ?? [])->get()->filter(fn (User $u) => $u->canEdit())->pluck('id')
            ->push($eu->id)->unique();

        $gravados = [];
        try {
            $v = DB::transaction(function () use ($request, $d, $versao, $esperados, $recebidos, $vinc, $fiscais, $eu, &$gravados) {
                $ano = (int) now()->format('Y');
                $n = (int) VgVistoria::where('exercicio', $ano)->lockForUpdate()->max('numero') + 1;
                $v = VgVistoria::create([
                    'versao_id' => $versao->id, 'exercicio' => $ano, 'numero' => $n, 'modulo' => $d['modulo'],
                    'local' => trim($d['local'] ?? '') ?: null, 'descricao' => trim($d['descricao'] ?? '') ?: null,
                    'sinalizacao_id' => $d['sinalizacao_id'] ?? null,
                    'user_id' => $eu->id, 'respostas' => [],
                ]);
                foreach ($vinc as $x) { $v->vinculos()->create($x + ['user_id' => $eu->id]); }
                foreach ($fiscais as $uid) { $v->fiscais()->create(['user_id' => $uid]); }
                foreach ($recebidos as $chave => $f) {
                    if (! $esperados->has($chave)) { continue; }
                    $gravados[] = $this->gravarArquivo($v, $f, ['papel' => 'anexo', 'anexo_chave' => $chave, 'nome' => $esperados[$chave]['nome']], $request);
                }
                foreach ($request->file('extras', []) as $f) {
                    $gravados[] = $this->gravarArquivo($v, $f, ['papel' => 'anexo', 'nome' => $this->nomeDoPdf($f)], $request);
                }
                return $v;
            });
        } catch (\Throwable $e) {
            foreach ($gravados as $a) { Storage::disk('private')->delete($a->caminho); }
            throw $e;
        }
        return response()->json(['id' => $v->id, 'message' => 'Vistoria ' . $v->numeroFormatado() . ' criada.'], 201);
    }

    public function mostrar(Request $request, VgVistoria $vistoria): JsonResponse
    {
        return response()->json($this->vistoriaJson($vistoria, $request->user()));
    }

    /**
     * Grava o andamento: localização, respostas, descrição e o início.
     * Chamado a cada resposta (com espera curta na tela): leve e idempotente.
     */
    public function andamento(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soRelacionado($request, $vistoria);
        abort_if($vistoria->concluida(), 422, 'A vistoria já foi concluída. Use Complementar para acrescentar itens.');
        $d = $request->validate([
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'], 'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_precisao' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'mapa_lat' => ['nullable', 'numeric', 'between:-90,90'], 'mapa_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'respostas' => ['nullable', 'array'],
            'descricao' => ['nullable', 'string', 'max:4000'],
            'local' => ['nullable', 'string', 'max:255'],
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
        foreach (['descricao', 'local'] as $k) {
            if ($request->has($k)) { $campos[$k] = trim($d[$k] ?? '') ?: null; }
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
        $this->soRelacionado($request, $vistoria);
        abort_if($vistoria->concluida(), 422, 'A vistoria já foi concluída.');
        abort_unless($vistoria->iniciada_em, 422, 'A vistoria não foi iniciada.');
        $def = $vistoria->versao->definicao;
        $d = $request->validate(['conclusao' => ['required', 'string', Rule::in($def['conclusoes'])]]);

        $vistoria->load(['arquivos', 'vinculos']);
        $falta = $this->pendenciasGerais($def, $vistoria);
        foreach ($def['etapas'] as $e) {
            foreach ($this->pendencias($e, $def, $vistoria) as $p) { $falta[] = "{$e['titulo']}: {$p}"; }
        }
        if ($falta) {
            throw ValidationException::withMessages(['pendencias' => array_slice($falta, 0, 8)]);
        }
        $efeito = DB::transaction(function () use ($vistoria, $d, $request) {
            $vistoria->update(['conclusao' => $d['conclusao'], 'estado' => 'concluida', 'concluida_em' => now()]);
            return app(\App\Services\VistoriaGuiadaConcluida::class)->aoConcluir($vistoria, $request->user());
        });
        $extra = array_filter([
            $efeito['sinalizacoes'] ? $efeito['sinalizacoes'] . ' sinalização(ões) resolvida(s)' : null,
            $efeito['protocolos'] ? 'protocolo atendido' : null,
        ]);
        return response()->json(['message' => 'Vistoria concluída.' . ($extra ? ' ' . ucfirst(implode(' · ', $extra)) . '.' : '')]);
    }

    // ── fotos e anexos ──

    public function foto(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soRelacionado($request, $vistoria);
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
                // Uma foto por "não confere": a nova substitui a anterior —
                // desde que a anterior seja de quem está fotografando.
                $antes = $vistoria->arquivos()->where('item_chave', $d['item'])->get();
                abort_if($antes->contains(fn ($a) => $a->user_id !== $request->user()->id), 403, 'A foto deste item foi tirada por outro fiscal; só ele pode trocá-la.');
                $antes->each(fn ($a) => $this->apagarArquivo($a));
            }
        }
        $a = $this->gravarArquivo($vistoria, $request->file('arquivo'), [
            'papel' => 'foto', 'nome' => 'Foto',
            'etapa_chave' => $livre ? $livre->etapa_chave : $d['etapa'],
            'item_chave' => $livre ? null : ($d['item'] ?? null),
            'livre_id' => $livre?->id, 'depois' => $vistoria->concluida(),
        ], $request);
        return response()->json($this->arquivoJson($a, $request->user()), 201);
    }

    /** PDF juntado depois de criada: no lugar de um anexo do modelo, ou avulso. */
    public function anexar(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soRelacionado($request, $vistoria);
        $d = $request->validate([
            'arquivo' => ['required', 'file', 'mimetypes:application/pdf', 'max:30720'],
            'chave' => ['nullable', 'string', 'max:40'],
        ], [], ['arquivo' => 'PDF']);
        $esperados = collect($vistoria->versao->definicao['anexos'] ?? [])->keyBy('id');
        $chave = $d['chave'] ?? null;
        if ($chave) {
            abort_unless($esperados->has($chave), 422, 'Anexo desconhecido.');
            abort_if($vistoria->arquivos()->where('anexo_chave', $chave)->exists(), 422, 'Este anexo já foi juntado. Remova o atual antes.');
        }
        $f = $request->file('arquivo');
        $a = $this->gravarArquivo($vistoria, $f, [
            'papel' => 'anexo', 'anexo_chave' => $chave,
            'nome' => $chave ? $esperados[$chave]['nome'] : $this->nomeDoPdf($f), 'depois' => $vistoria->concluida(),
        ], $request);
        return response()->json($this->arquivoJson($a, $request->user()), 201);
    }

    /** Foto ou anexo: SÓ quem acrescentou remove. */
    public function apagarArquivoRota(Request $request, VgArquivo $arquivo): JsonResponse
    {
        $v = $arquivo->vistoria;
        $this->soRelacionado($request, $v);
        abort_unless($arquivo->user_id === $request->user()->id, 403, 'Só quem acrescentou este arquivo pode removê-lo.');
        abort_if($v->concluida() && ! $arquivo->depois, 422, 'O arquivo é anterior à conclusão e não sai mais.');
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
        $this->soRelacionado($request, $vistoria);
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
        return response()->json($this->livreJson($l->load('autor'), $request->user()), 201);
    }

    public function apagarLivre(Request $request, VgLivre $livre): JsonResponse
    {
        $this->soRelacionado($request, $livre->vistoria);
        abort_unless($livre->user_id === $request->user()->id, 403, 'Só quem criou o item pode removê-lo.');
        abort_if($livre->vistoria->concluida() && ! $livre->depois, 422, 'O item é anterior à conclusão e não sai mais.');
        $livre->fotos->each(fn ($a) => $this->apagarArquivo($a));
        $livre->delete();
        return response()->json(['ok' => true]);
    }

    // ── vínculos ──

    /** Busca para ligar: imóvel (inscrição ou bairro), pessoa, protocolo, denúncia. */
    public function buscarVinculo(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(VgVinculo::TIPOS))],
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);
        $q = trim($d['q']);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $dig = preg_replace('/\D/', '', $q);

        $r = match ($d['tipo']) {
            'imovel' => Lote::query()->publicados()
                ->where(fn ($w) => $w->where('inscricao_imobiliaria', 'like', $like)->orWhere('bairro', 'like', $like))
                ->orderBy('bairro')->limit(15)->get()
                ->map(fn (Lote $l) => ['id' => $l->id, 'rotulo' => $this->rotuloLote($l)]),
            'pessoa' => Contribuinte::query()
                ->where(fn ($w) => $w->where('nome', 'like', $like)->when(strlen($dig) >= 3, fn ($w) => $w->orWhere('cpf_cnpj', 'like', '%' . $dig . '%')))
                ->orderBy('nome')->limit(15)->get()
                ->map(fn (Contribuinte $c) => ['id' => $c->id, 'rotulo' => $this->rotuloPessoa($c)]),
            'protocolo' => Protocolo::query()
                ->where(fn ($w) => $w->where('numero', 'like', $like)->orWhere('requerente_nome', 'like', $like))
                ->orderByDesc('protocolado_em')->limit(15)->get()
                ->map(fn (Protocolo $p) => ['id' => $p->id, 'rotulo' => $this->rotuloProtocolo($p)]),
            'denuncia' => Denuncia::query()
                ->where(fn ($w) => $w->where('protocolo', 'like', $like)->orWhere('logradouro', 'like', $like))
                ->orderByDesc('data_hora')->limit(15)->get()
                ->map(fn (Denuncia $x) => ['id' => $x->id, 'rotulo' => $this->rotuloDenuncia($x)]),
        };
        return response()->json(['resultados' => $r->values()]);
    }

    /** GET /api/vistorias-guiadas/vinculos/rotulo?tipo=imovel&id=12 — o rótulo, como seria copiado ao ligar. */
    public function rotuloRota(Request $request): JsonResponse
    {
        $d = $request->validate(['tipo' => ['required', Rule::in(array_keys(VgVinculo::TIPOS))], 'id' => ['required', 'integer']]);
        return response()->json(['tipo' => $d['tipo'], 'id' => (int) $d['id'], 'rotulo' => $this->rotuloVinculo($d['tipo'], (int) $d['id'])]);
    }

    public function ligar(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soRelacionado($request, $vistoria);
        $d = $request->validate(['tipo' => ['required', Rule::in(array_keys(VgVinculo::TIPOS))], 'id' => ['required', 'integer']]);
        abort_if($vistoria->vinculos()->where('tipo', $d['tipo'])->where('ref_id', $d['id'])->exists(), 422, 'Já está ligado.');
        $l = $vistoria->vinculos()->create([
            'tipo' => $d['tipo'], 'ref_id' => $d['id'], 'rotulo' => $this->rotuloVinculo($d['tipo'], $d['id']),
            'depois' => $vistoria->concluida(), 'user_id' => $request->user()->id,
        ]);
        return response()->json($this->vinculoJson($l), 201);
    }

    public function desligar(Request $request, VgVinculo $vinculo): JsonResponse
    {
        $v = $vinculo->vistoria;
        $this->soRelacionado($request, $v);
        abort_if($v->concluida() && ! $vinculo->depois, 422, 'O vínculo é anterior à conclusão e não sai mais.');
        $vinculo->delete();
        return response()->json(['ok' => true]);
    }

    // ── fiscais e assinatura ──

    /** Agentes que podem ser relacionados a uma vistoria. */
    public function agentes(): JsonResponse
    {
        return response()->json(['agentes' => User::where('ativo', true)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canEdit())
            ->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->name, 'matricula' => $u->matricula])->values()]);
    }

    public function relacionar(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soRelacionado($request, $vistoria);
        $d = $request->validate(['user_id' => ['required', 'integer']]);
        $u = User::findOrFail($d['user_id']);
        abort_unless($u->canEdit(), 422, 'Este usuário não pode participar de vistoria.');
        $vistoria->fiscais()->firstOrCreate(['user_id' => $u->id]);
        return response()->json(['fiscais' => $this->fiscaisJson($vistoria->fresh('fiscais.usuario'), $request->user())]);
    }

    public function desrelacionar(Request $request, VgVistoria $vistoria, User $usuario): JsonResponse
    {
        $this->soRelacionado($request, $vistoria);
        $f = $vistoria->fiscais->firstWhere('user_id', $usuario->id);
        abort_unless($f, 404);
        abort_if($f->assinada_em, 422, 'Este fiscal já assinou o relatório.');
        abort_if($vistoria->fiscais->count() === 1, 422, 'A vistoria precisa de ao menos um fiscal.');
        $f->delete();
        return response()->json(['fiscais' => $this->fiscaisJson($vistoria->fresh('fiscais.usuario'), $request->user())]);
    }

    public function assinar(Request $request, VgVistoria $vistoria): JsonResponse
    {
        $this->soRelacionado($request, $vistoria);
        abort_unless($vistoria->concluida(), 422, 'Conclua a vistoria antes de assinar.');
        $u = $request->user();
        abort_unless($u->assinatura, 422, 'Cadastre a sua assinatura no perfil antes de assinar.');
        $f = $vistoria->fiscais->firstWhere('user_id', $u->id);
        abort_if($f->assinada_em, 422, 'Você já assinou.');
        $f->update(['assinatura' => $u->assinatura, 'assinada_em' => now()]);
        return response()->json(['message' => 'Relatório assinado.', 'fiscais' => $this->fiscaisJson($vistoria->fresh('fiscais.usuario'), $u)]);
    }

    // ── relatório ──

    public function relatorio(VgVistoria $vistoria)
    {
        return response()->view('impressao.vistoria-guiada', $this->montarRelatorio($vistoria, false) + ['navegador' => true]);
    }

    public function relatorioPdf(VgVistoria $vistoria)
    {
        $pdf = Pdf::loadView('impressao.vistoria-guiada', $this->montarRelatorio($vistoria, true) + ['navegador' => false])->setPaper('a4');
        return $pdf->stream(str_replace(['/', ' '], ['-', '-'], $vistoria->numeroFormatado()) . '.pdf');
    }

    /**
     * O relatório em itens numerados 1, 2, 3… na ordem do roteiro: cada etapa
     * abre um grupo, os itens do modelo vêm primeiro e os livres da etapa logo
     * depois, na mesma sequência. O que não pertence a etapa nenhuma (e o que
     * foi acrescentado depois de concluída) fecha a lista.
     */
    private function montarRelatorio(VgVistoria $v, bool $paraPdf): array
    {
        $v->loadMissing(['versao', 'arquivos', 'livres.autor', 'vinculos', 'fiscais.usuario']);
        $def = $v->versao->definicao;
        $r = $v->respostas ?? [];
        $img = function (VgArquivo $a) use ($paraPdf) {
            if (! $paraPdf) { return route('vg.arquivo', $a); }
            $d = Storage::disk('private');
            return $d->exists($a->caminho) ? 'data:' . $a->mime . ';base64,' . base64_encode($d->get($a->caminho)) : null;
        };
        $fotos = $v->arquivos->where('papel', 'foto');
        $livreItem = fn (VgLivre $l) => [
            'livre' => true, 'rotulo' => $l->titulo, 'resp' => null, 'obs' => $l->observacao,
            'nota' => $l->depois ? 'Acrescentado depois da conclusão, em ' . $l->created_at->format('d/m/Y H:i') . ($l->autor ? ' por ' . $l->autor->name : '') : null,
            'fotos' => $fotos->where('livre_id', $l->id)->map($img)->filter()->values()->all(),
        ];

        $artigos = VgItem::rotulosArtigos(VgItem::artigosCitados($r));
        $n = 0;
        $grupos = [];
        foreach ($def['etapas'] as $e) {
            $itens = [];
            foreach ($e['itens'] as $i) {
                $i = VgItem::normalizar($i);
                $x = $r[$i['id']] ?? null;
                $t = VgItem::textoResposta($i, $x);
                $itens[] = ['n' => ++$n, 'livre' => false, 'rotulo' => $i['rotulo'], 'tipo' => $i['tipo'],
                    // `v` só existe no confere: é a cor da marca no papel.
                    'resp' => $t !== null ? ['v' => $i['tipo'] === 'confere' ? $x['v'] : 'valor', 't' => $t] : null,
                    'obs' => $x['obs'] ?? null, 'nota' => null,
                    'artigos' => array_values(array_filter(array_map(fn ($id) => $artigos[$id] ?? null, $x['art'] ?? []))),
                    'exig' => $x['exig'] ?? null, 'prazo' => $x['prazo'] ?? null,
                    'fotos' => $fotos->where('item_chave', $i['id'])->map($img)->filter()->values()->all()];
            }
            foreach ($v->livres->where('etapa_chave', $e['id'])->where('depois', false) as $l) {
                $itens[] = ['n' => ++$n] + $livreItem($l);
            }
            $grupos[] = ['titulo' => $e['titulo'], 'itens' => $itens,
                'fotos' => $fotos->where('etapa_chave', $e['id'])->whereNull('item_chave')->whereNull('livre_id')->map($img)->filter()->values()->all()];
        }
        $outros = $v->livres->filter(fn ($l) => $l->depois || ! $l->etapa_chave || ! collect($def['etapas'])->contains('id', $l->etapa_chave));
        if ($outros->isNotEmpty()) {
            $grupos[] = ['titulo' => $def['etapas'] ? 'Outros itens constatados' : null,
                'itens' => $outros->values()->map(fn ($l) => ['n' => ++$n] + $livreItem($l))->all(), 'fotos' => []];
        }

        $cab = app(CabecalhoOficial::class);
        return [
            'v' => $v, 'def' => $def,
            'titulo' => 'RELATÓRIO DE VISTORIA', 'numero' => $v->numeroFormatado(),
            'orgao' => $cab->orgao($v->modulo), 'rodape' => $cab->rodape(), 'brasao' => $cab->brasao($paraPdf),
            'vinculos' => $v->vinculos->groupBy('tipo')->map(fn ($g, $t) => ['rotulo' => VgVinculo::TIPOS[$t], 'itens' => $g->pluck('rotulo')->all()])
                ->sortBy(fn ($x, $t) => array_search($t, array_keys(VgVinculo::TIPOS)))->values()->all(),
            'grupos' => $grupos,
            'anexos' => $v->arquivos->where('papel', 'anexo')->values(),
            'fiscais' => $v->fiscais->map(fn (VgFiscal $f) => ['nome' => $f->usuario?->name, 'matricula' => $f->usuario?->matricula,
                'assinatura' => $f->assinatura, 'em' => $f->assinada_em?->format('d/m/Y H:i')])->all(),
        ];
    }

    // ══════════════ apoio ══════════════

    private function soAdmin(Request $r): void { abort_unless($r->user()->isAdmin(), 403, 'Só o administrador monta modelos.'); }
    /** O módulo em que o usuário está — o dos modelos que ele vê e monta. */
    private function modulo(Request $r): string { return \App\Support\Modulo::ativo($r->user()); }
    /** Modelo de outro módulo não se altera daqui: troque de módulo antes. */
    private function doModulo(Request $r, VgModelo $m): void
    {
        abort_unless($m->modulo === $this->modulo($r), 404, 'Este modelo é de outro módulo.');
    }
    private function soQuemEdita(Request $r): void { abort_unless($r->user()->canEdit(), 403, 'Seu perfil é só de consulta.'); }

    private function soRelacionado(Request $r, VgVistoria $v): void
    {
        $v->loadMissing('fiscais');
        abort_unless($v->relacionado($r->user()), 403, 'Só os fiscais relacionados a esta vistoria podem alterá-la.');
    }

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
        $itens = VgItem::itensDe($def)->keyBy('id');
        // Só artigo que existe e é das leis do módulo ativo entra na citação.
        $pedidos = array_filter(array_map('intval', VgItem::artigosCitados(array_filter($r, 'is_array'))));
        $validos = $pedidos ? Artigo::whereIn('id', $pedidos)->whereHas('legislacao', fn ($l) => $l->doModulo())
            ->pluck('id')->flip()->all() : [];
        $out = [];
        foreach ($r as $k => $x) {
            if (! $itens->has($k)) { continue; }
            $limpo = VgItem::limpar($itens[$k], $x, $validos);
            if ($limpo) { $out[$k] = $limpo; }
        }
        return $out;
    }

    /** Anexos e vínculos que o modelo exige — podem ter sido removidos depois de criada. */
    private function pendenciasGerais(array $def, VgVistoria $v): array
    {
        $f = [];
        foreach ($def['anexos'] ?? [] as $a) {
            if ($a['obrig'] && ! $v->arquivos->where('anexo_chave', $a['id'])->count()) { $f[] = 'anexo "' . $a['nome'] . '"'; }
        }
        foreach ($def['vinculos'] ?? [] as $t) {
            if (! $v->vinculos->where('tipo', $t)->count()) { $f[] = 'vínculo com ' . mb_strtolower(VgVinculo::TIPOS[$t]); }
        }
        return $f;
    }

    /** O que falta na etapa — a mesma regra da tela (`pendencias` em vistorias-guiadas.js). */
    private function pendencias(array $e, array $def, VgVistoria $v): array
    {
        $r = $v->respostas ?? [];
        $fotos = $v->arquivos->where('papel', 'foto');
        $f = [];
        foreach ($e['itens'] as $i) {
            $i = VgItem::normalizar($i);
            array_push($f, ...VgItem::pendencias($i, $r[$i['id']] ?? null, (bool) $def['nao_foto'],
                $fotos->where('item_chave', $i['id'])->count() > 0));
        }
        $n = $fotos->where('etapa_chave', $e['id'])->whereNull('item_chave')->whereNull('livre_id')->count();
        if ($e['foto'] === 'obrigatoria' && $n < $e['foto_min']) { $f[] = ($e['foto_min'] - $n) . ' foto(s) da etapa'; }
        return $f;
    }

    // ── rótulos dos vínculos (copiados para a vistoria ao ligar) ──

    private function rotuloVinculo(string $tipo, int $id): string
    {
        return match ($tipo) {
            'imovel' => $this->rotuloLote(Lote::findOrFail($id)),
            'pessoa' => $this->rotuloPessoa(Contribuinte::findOrFail($id)),
            'protocolo' => $this->rotuloProtocolo(Protocolo::findOrFail($id)),
            'denuncia' => $this->rotuloDenuncia(Denuncia::findOrFail($id)),
        };
    }

    private function rotuloLote(Lote $l): string
    {
        return mb_substr(($l->inscricao_imobiliaria ? 'Inscrição ' . $l->inscricao_imobiliaria . ' · ' : '') . $l->rotuloCompleto(), 0, 255);
    }

    private function rotuloPessoa(Contribuinte $c): string
    {
        $doc = Denuncia::mascarar($c->cpf_cnpj);
        return mb_substr($c->nome . ($doc ? ' · ' . $doc : ''), 0, 255);
    }

    private function rotuloProtocolo(Protocolo $p): string
    {
        return mb_substr('Protocolo ' . $p->numero . ' · ' . $p->rotuloTipo() . ($p->requerente_nome ? ' · ' . $p->requerente_nome : ''), 0, 255);
    }

    private function rotuloDenuncia(Denuncia $d): string
    {
        return mb_substr('Denúncia ' . ($d->protocolo ?: '#' . $d->id) . ' · ' . $d->endereco() . ($d->data_hora ? ' · ' . \Illuminate\Support\Carbon::parse($d->data_hora)->format('d/m/Y') : ''), 0, 255);
    }

    // ── arquivos ──

    private function nomeDoPdf($f): string
    {
        return mb_substr(preg_replace('/\.pdf$/i', '', $f->getClientOriginalName()), 0, 160) ?: 'PDF';
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

    // ── JSON ──

    private function arquivoJson(VgArquivo $a, ?User $eu): array
    {
        return [
            'id' => $a->id, 'papel' => $a->papel, 'nome' => $a->nome, 'nome_original' => $a->nome_original,
            'anexo' => $a->anexo_chave, 'etapa' => $a->etapa_chave, 'item' => $a->item_chave, 'livre_id' => $a->livre_id,
            'tamanho' => $a->tamanho, 'depois' => $a->depois, 'url' => route('vg.arquivo', $a),
            'meu' => $eu && $a->user_id === $eu->id,
        ];
    }

    private function livreJson(VgLivre $l, ?User $eu): array
    {
        return [
            'id' => $l->id, 'etapa' => $l->etapa_chave, 'titulo' => $l->titulo, 'observacao' => $l->observacao,
            'depois' => $l->depois, 'quando' => $l->created_at->format('d/m/Y H:i'), 'autor' => $l->autor?->name,
            'meu' => $eu && $l->user_id === $eu->id,
        ];
    }

    private function vinculoJson(VgVinculo $l): array
    {
        return ['id' => $l->id, 'tipo' => $l->tipo, 'ref_id' => $l->ref_id, 'rotulo' => $l->rotulo, 'depois' => $l->depois];
    }

    private function fiscaisJson(VgVistoria $v, ?User $eu): array
    {
        return $v->fiscais->map(fn (VgFiscal $f) => [
            'id' => $f->user_id, 'nome' => $f->usuario?->name, 'matricula' => $f->usuario?->matricula,
            'assinada_em' => $f->assinada_em?->format('d/m/Y H:i'), 'eu' => $eu && $f->user_id === $eu->id,
        ])->values()->all();
    }

    private function vistoriaJson(VgVistoria $v, ?User $eu): array
    {
        $v->loadMissing(['versao', 'autor', 'arquivos', 'livres.autor', 'vinculos', 'fiscais.usuario']);
        return [
            'id' => $v->id, 'numero' => $v->numeroFormatado(), 'modulo' => $v->modulo,
            'modelo' => $v->versao->nome, 'versao' => $v->versao->versao, 'definicao' => $v->versao->definicao,
            'local' => $v->local, 'descricao' => $v->descricao, 'estado' => $v->estado,
            'gps' => $v->gps_lat !== null ? ['lat' => $v->gps_lat, 'lng' => $v->gps_lng, 'prec' => $v->gps_precisao] : null,
            'mapa' => $v->mapa_lat !== null ? ['lat' => $v->mapa_lat, 'lng' => $v->mapa_lng] : null,
            'respostas' => (object) ($v->respostas ?? []), 'conclusao' => $v->conclusao,
            // Os artigos citados, para a tela mostrar o rótulo sem nova busca.
            'artigos' => (object) VgItem::rotulosArtigos(VgItem::artigosCitados($v->respostas ?? [])),
            'iniciada_em' => $v->iniciada_em?->format('d/m/Y H:i'), 'concluida_em' => $v->concluida_em?->format('d/m/Y H:i'),
            'autor' => $v->autor?->name,
            'relacionado' => $v->relacionado($eu),
            'vinculos' => $v->vinculos->map(fn ($l) => $this->vinculoJson($l))->values(),
            'fiscais' => $this->fiscaisJson($v, $eu),
            'arquivos' => $v->arquivos->map(fn ($a) => $this->arquivoJson($a, $eu))->values(),
            'livres' => $v->livres->map(fn ($l) => $this->livreJson($l, $eu))->values(),
            'relatorio' => route('vg.relatorio', $v), 'relatorio_pdf' => route('vg.relatorio.pdf', $v),
        ];
    }
}
