<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Vistoria;
use App\Services\DesenhoDeLote;
use App\Services\DesmembramentoDeLote;
use App\Services\LotesApagados;
use App\Services\QuadraDeLotesSelecionados;
use App\Services\UnificacaoDeLotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Correções cadastrais feitas a partir do mapa.
 *
 * Só administrador: estas rotas alteram a IDENTIFICAÇÃO do imóvel, que é a
 * chave de integração com o cadastro imobiliário. Mudá-la muda a que imóvel um
 * auto lavrado se refere — é o mesmo argumento do QuarteiraoController, com
 * mais força, porque aqui se sobrescreve quadra que já estava preenchida.
 */
class CadastroLoteController extends Controller
{
    /**
     * POST /api/lotes/quadra-em-massa/previa
     *
     * Não altera nada; é POST porque a lista de ids não cabe confortavelmente
     * numa query string. Devolve sempre a mesma forma — impedimento, avisos e
     * retrato — para a tela não precisar reimplementar regra nenhuma.
     */
    public function previaQuadra(Request $request, QuadraDeLotesSelecionados $svc): JsonResponse
    {
        if ($erro = $this->recusarNaoAdmin($request)) {
            return $erro;
        }

        $d = $this->validar($request);
        $quadra = $svc->normalizar($d['quadra']);

        return response()->json([
            'impedimento' => $svc->impedimento($d['ids'], $quadra),
            'avisos'      => $svc->avisos($d['ids'], $quadra),
            'retrato'     => $svc->retrato($d['ids'], $quadra),
        ]);
    }

    /** POST /api/lotes/quadra-em-massa — grava. */
    public function aplicarQuadra(Request $request, QuadraDeLotesSelecionados $svc): JsonResponse
    {
        if ($erro = $this->recusarNaoAdmin($request)) {
            return $erro;
        }

        $d = $this->validar($request);
        $quadra = $svc->normalizar($d['quadra']);

        // A prévia já mostrou isto ao usuário, mas a prova roda de novo aqui:
        // entre ver a prévia e confirmar, outra pessoa pode ter mexido nos
        // mesmos lotes. Validação que só acontece na tela é decoração.
        if ($impedimento = $svc->impedimento($d['ids'], $quadra)) {
            return response()->json(['message' => $impedimento], 422);
        }

        $n = $svc->aplicar($d['ids'], $quadra);

        return response()->json([
            'quadra'    => $quadra,
            'alterados' => $n,
            'message'   => $n === 0
                ? "Nenhum lote mudou: todos já estavam na quadra {$quadra}."
                : "Quadra {$quadra} gravada em {$n} lote(s).",
        ]);
    }

    /**
     * POST /api/lotes/previa — o que o desenho vira, antes de gravar.
     *
     * `POST` apesar de não alterar nada: leva a geometria, que não cabe numa
     * query string.
     */
    public function previaDesenho(Request $request, DesenhoDeLote $svc): JsonResponse
    {
        if ($erro = $this->recusarNaoAdmin($request)) {
            return $erro;
        }

        $d = $this->validarDesenho($request);

        return response()->json([
            'impedimento' => $svc->impedimento($d),
            'avisos'      => $svc->avisos($d),
            'retrato'     => $svc->retrato($d),
        ]);
    }

    /** POST /api/lotes — cria o lote desenhado. */
    public function criarDesenho(Request $request, DesenhoDeLote $svc): JsonResponse
    {
        if ($erro = $this->recusarNaoAdmin($request)) {
            return $erro;
        }

        $d = $this->validarDesenho($request);

        // Conferido de novo aqui, e não só na prévia: entre ver a prévia e
        // confirmar, outra pessoa pode ter desenhado no mesmo lugar.
        if ($impedimento = $svc->impedimento($d)) {
            return response()->json(['message' => $impedimento], 422);
        }

        $u = $request->user();
        $lote = $svc->aplicar($d, $u->id, $u->name);

        return response()->json([
            'id'      => $lote->id,
            'chave'   => $lote->chave,
            'area'    => $lote->area_gis_m2,
            'message' => sprintf('Lote %s criado na quadra %s com %s m².',
                $lote->numero_lote, $lote->quadra,
                number_format((float) $lote->area_gis_m2, 2, ',', '.')),
        ], 201);
    }

    /**
     * POST /api/protocolos/{protocolo}/unificacao/previa
     *
     * O que a união vira, antes de executar. A permissão aqui é `canEdit()`,
     * e não `isAdmin()`, porque o portão do ato não é o perfil: é a VISTORIA
     * regular amarrada ao protocolo deferido. Quem foi a campo é o fiscal.
     */
    public function previaUnificacao(Request $request, Protocolo $protocolo, UnificacaoDeLotes $svc): JsonResponse
    {
        if ($erro = $this->recusarSemEdicao($request)) {
            return $erro;
        }

        $d = $request->validate([
            'ids'   => ['required', 'array', 'min:2'],
            'ids.*' => ['integer', 'exists:lotes,id'],
            'numero_lote' => ['nullable', 'string', 'max:20'],
        ]);

        $retrato = $svc->retrato($d['ids']);
        $numero = $d['numero_lote'] ?? $retrato['sugestao_lote'];

        return response()->json([
            'impedimento' => $svc->impedimento($protocolo, $d['ids'], $numero),
            'avisos'      => $svc->avisos($d['ids']),
            'retrato'     => $retrato,
        ]);
    }

    /** POST /api/protocolos/{protocolo}/unificacao — executa. */
    public function unificar(Request $request, Protocolo $protocolo, UnificacaoDeLotes $svc): JsonResponse
    {
        if ($erro = $this->recusarSemEdicao($request)) {
            return $erro;
        }

        $d = $request->validate([
            'ids'         => ['required', 'array', 'min:2'],
            'ids.*'       => ['integer', 'exists:lotes,id'],
            'numero_lote' => ['required', 'string', 'max:20'],
        ]);

        // A VISTORIA é o portão, não o protocolo direto: o ato altera o
        // terreno, e alguém tem de ter ido lá ver. Conferido aqui, no
        // servidor, porque validação que só acontece na tela é decoração.
        if ($erro = $this->recusarSemVistoria($protocolo)) {
            return $erro;
        }

        if ($impedimento = $svc->impedimento($protocolo, $d['ids'], $d['numero_lote'])) {
            return response()->json(['message' => $impedimento], 422);
        }

        $novo = $svc->aplicar($protocolo, $d['ids'], $d['numero_lote']);

        return response()->json([
            'id'      => $novo->id,
            'chave'   => $novo->chave,
            'message' => sprintf('%d lotes unificados no lote %s da quadra %s, com %s m².',
                count($d['ids']), $novo->numero_lote, $novo->quadra,
                number_format((float) $novo->area_gis_m2, 2, ',', '.')),
        ], 201);
    }

    /**
     * POST /api/protocolos/{protocolo}/desmembramento/previa
     *
     * O que as partes viram, antes de executar: área de cada uma, a soma, e
     * quanto sobra em relação ao lote de origem.
     */
    public function previaDesmembramento(Request $request, Protocolo $protocolo, DesmembramentoDeLote $svc): JsonResponse
    {
        if ($erro = $this->recusarSemEdicao($request)) {
            return $erro;
        }

        $d = $this->validarDesmembramento($request);
        $pai = Lote::findOrFail($d['lote_id']);

        return response()->json([
            'impedimento' => $svc->impedimento($protocolo, $pai, $d['partes'], $d['derivar_ultima']),
            'avisos'      => $svc->avisos($pai, $d['partes'], $d['derivar_ultima']),
            'retrato'     => $svc->retrato($pai, $d['partes'], $d['derivar_ultima']),
        ]);
    }

    /** POST /api/protocolos/{protocolo}/desmembramento — executa. */
    public function desmembrar(Request $request, Protocolo $protocolo, DesmembramentoDeLote $svc): JsonResponse
    {
        if ($erro = $this->recusarSemEdicao($request)) {
            return $erro;
        }

        $d = $this->validarDesmembramento($request);
        $pai = Lote::findOrFail($d['lote_id']);

        if ($erro = $this->recusarSemVistoria($protocolo)) {
            return $erro;
        }

        if ($impedimento = $svc->impedimento($protocolo, $pai, $d['partes'], $d['derivar_ultima'])) {
            return response()->json(['message' => $impedimento], 422);
        }

        $novos = $svc->aplicar($protocolo, $pai, $d['partes'], $d['derivar_ultima'], $d['modo'] ?? 'poligonos');

        return response()->json([
            'lotes'   => array_map(fn ($l) => [
                'id' => $l->id, 'numero_lote' => $l->numero_lote, 'area' => $l->area_gis_m2,
            ], $novos),
            'message' => sprintf('Lote %s desmembrado em %s.', $pai->numero_lote,
                implode(', ', array_map(fn ($l) => $l->numero_lote, $novos))),
        ], 201);
    }

    /** @return array<string,mixed> */
    private function validarDesmembramento(Request $request): array
    {
        /** @var array<string,mixed> $d */
        $d = $request->validate([
            'lote_id'        => ['required', 'integer', 'exists:lotes,id'],
            'derivar_ultima' => ['nullable', 'boolean'],
            'modo'           => ['nullable', 'string', 'max:20'],
            'partes'         => ['required', 'array', 'min:1', 'max:20'],
            // O conteudo GEOMÉTRICO de cada parte nao se valida aqui: quem sabe
            // recusar um contorno que vaza, ou partes que nao cobrem o lote, e o
            // servico — e com mensagem que ensina, nao com "partes.0.geometry
            // invalido".
            'partes.*.geometry' => ['required', 'array'],

            // OS DEMAIS CAMPOS DA PARTE PRECISAM ESTAR LISTADOS.
            //
            // `validate()` devolve só o que foi validado, e isso vale dentro de
            // cada item do array: com apenas `partes.*.geometry` declarado, o
            // que chegava ao serviço era a geometria e MAIS NADA — cada lote
            // novo nascia com `numero_lote` nulo, e o desmembramento produzia
            // partes sem número. É o mesmo defeito que derrubava o desenho de
            // lote, aqui em silêncio, porque nada estourava.
            'partes.*.numero_lote'            => ['nullable', 'string', 'max:20'],
            'partes.*.desmembramento'         => ['nullable', 'integer', 'min:0', 'max:999'],
            'partes.*.numero_lote_derivada'   => ['nullable', 'string', 'max:20'],
            'partes.*.desmembramento_derivada' => ['nullable', 'integer', 'min:0', 'max:999'],

            // As medidas da matrícula de cada parte (Etapa 2). Opcionais: no
            // desmembramento o registro costuma vir depois do ato.
            'partes.*.frente_m'          => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'partes.*.fundos_m'          => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'partes.*.lado_direito_m'    => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'partes.*.lado_esquerdo_m'   => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'partes.*.area_matricula_m2' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);

        $d['derivar_ultima'] = $d['derivar_ultima'] ?? true;

        return $d;
    }

    /**
     * O protocolo tem vistoria REGULAR que o fundamente?
     *
     * Sem esta prova, executar bastaria conhecer a URL — e o deferimento
     * sozinho não é fundamento: ele diz que o pedido procede no papel, a
     * vistoria diz que o papel bate com o chão.
     */
    private function recusarSemVistoria(Protocolo $protocolo): ?JsonResponse
    {
        // Lido pela COLUNA, nao pela relacao `$protocolo->vistoria`: o Eloquent
        // guarda a relacao em cache no objeto depois do primeiro acesso, e um
        // protocolo consultado ANTES de ganhar a vistoria continuaria
        // respondendo "sem vistoria" naquela instancia. Numa requisicao HTTP o
        // modelo nasce novo e o defeito nao apareceria — que e justamente o
        // tipo de armadilha que so surge meses depois.
        $vistoria = $protocolo->vistoria_id
            ? Vistoria::find($protocolo->vistoria_id)
            : null;

        if (! $vistoria) {
            return response()->json([
                'message' => 'O protocolo ' . $protocolo->numero . ' não tem vistoria. '
                    . 'Registre a vistoria do imóvel antes de executar o ato.',
            ], 422);
        }

        if ($vistoria->situacao !== 'regular') {
            return response()->json([
                'message' => 'A vistoria do protocolo ' . $protocolo->numero . ' está como "'
                    . $vistoria->situacaoRotulo() . '". Só vistoria Regular autoriza alterar '
                    . 'o cadastro do imóvel.',
            ], 422);
        }

        return null;
    }

    /**
     * ATOS DIRETOS — sem protocolo.
     *
     * O mapa vem de um DWG que nem sempre acompanha o cartório: há lote já
     * unificado ou desmembrado no mundo real e ainda inteiro no desenho. Não há
     * protocolo a esperar, porque não há nada a decidir — o ato só põe o
     * cadastro em dia com o que já aconteceu.
     *
     * Por isso o portão aqui é OUTRO: não é a vistoria (não se vai a campo para
     * conferir um ato já consumado em cartório), é a CURADORIA do cadastro. E o
     * ato exige justificativa escrita, que fica em `lote_atos.observacao` junto
     * com quem executou: sem protocolo, a responsabilidade é de quem assinou, e
     * ela precisa estar nomeada.
     */
    public function previaUnificacaoDireta(Request $request, UnificacaoDeLotes $svc): JsonResponse
    {
        if ($erro = $this->recusarSemCuradoria($request)) {
            return $erro;
        }

        $d = $request->validate([
            'ids'         => ['required', 'array', 'min:2'],
            'ids.*'       => ['integer', 'exists:lotes,id'],
            'numero_lote' => ['nullable', 'string', 'max:20'],
        ]);

        $retrato = $svc->retrato($d['ids']);
        $numero = $d['numero_lote'] ?? $retrato['sugestao_lote'];

        return response()->json([
            'impedimento' => $svc->impedimento(null, $d['ids'], $numero, direto: true),
            'avisos'      => $svc->avisos($d['ids']),
            'retrato'     => $retrato,
        ]);
    }

    /** POST /api/lotes/unificacao-direta — executa sem protocolo. */
    public function unificarDireto(Request $request, UnificacaoDeLotes $svc): JsonResponse
    {
        if ($erro = $this->recusarSemCuradoria($request)) {
            return $erro;
        }

        $d = $request->validate([
            'ids'           => ['required', 'array', 'min:2'],
            'ids.*'         => ['integer', 'exists:lotes,id'],
            'numero_lote'   => ['required', 'string', 'max:20'],
            'justificativa' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        if ($impedimento = $svc->impedimento(null, $d['ids'], $d['numero_lote'], direto: true)) {
            return response()->json(['message' => $impedimento], 422);
        }

        $novo = $svc->aplicar(null, $d['ids'], $d['numero_lote'], null, $d['justificativa']);

        return response()->json([
            'id'      => $novo->id,
            'chave'   => $novo->chave,
            'message' => sprintf('%d lotes unificados no lote %s da quadra %s, com %s m². '
                . 'Ato direto, sem protocolo.',
                count($d['ids']), $novo->numero_lote, $novo->quadra,
                number_format((float) $novo->area_gis_m2, 2, ',', '.')),
        ], 201);
    }

    /** POST /api/lotes/desmembramento-direto/previa */
    public function previaDesmembramentoDireto(Request $request, DesmembramentoDeLote $svc): JsonResponse
    {
        if ($erro = $this->recusarSemCuradoria($request)) {
            return $erro;
        }

        $d = $this->validarDesmembramento($request);
        $pai = Lote::findOrFail($d['lote_id']);

        return response()->json([
            'impedimento' => $svc->impedimento(null, $pai, $d['partes'], $d['derivar_ultima'], direto: true),
            'avisos'      => $svc->avisos($pai, $d['partes'], $d['derivar_ultima']),
            'retrato'     => $svc->retrato($pai, $d['partes'], $d['derivar_ultima']),
        ]);
    }

    /** POST /api/lotes/desmembramento-direto — executa sem protocolo. */
    public function desmembrarDireto(Request $request, DesmembramentoDeLote $svc): JsonResponse
    {
        if ($erro = $this->recusarSemCuradoria($request)) {
            return $erro;
        }

        $d = $this->validarDesmembramento($request);
        $justificativa = $request->validate([
            'justificativa' => ['required', 'string', 'min:10', 'max:500'],
        ])['justificativa'];

        $pai = Lote::findOrFail($d['lote_id']);

        if ($impedimento = $svc->impedimento(null, $pai, $d['partes'], $d['derivar_ultima'], direto: true)) {
            return response()->json(['message' => $impedimento], 422);
        }

        $novos = $svc->aplicar(null, $pai, $d['partes'], $d['derivar_ultima'],
                               $d['modo'] ?? 'poligonos', $justificativa);

        return response()->json([
            'lotes'   => array_map(fn ($l) => [
                'id' => $l->id, 'numero_lote' => $l->numero_lote, 'area' => $l->area_gis_m2,
            ], $novos),
            'message' => sprintf('Lote %s desmembrado em %s. Ato direto, sem protocolo.',
                $pai->numero_lote, implode(', ', array_map(fn ($l) => $l->numero_lote, $novos))),
        ], 201);
    }

    /**
     * DELETE /api/lotes/{lote} — apaga um lote RESIDUAL do desenho.
     *
     * Não é baixa: baixa é o que acontece com um lote que EXISTIU e deixou de
     * existir, e fica na sucessão para quem consultar anos depois. Aqui o lote
     * nunca existiu — é sobra da conversão do DWG, uma faixa de terra sem
     * quadra, sem número e sem dono. Guardá-la como "baixada" sujaria a
     * sucessão com um ato que não houve.
     *
     * Três travas, e nenhuma é de tela:
     *   1. curadoria do cadastro;
     *   2. a SENHA de quem está apagando, conferida aqui — um toque errado num
     *      celular em campo não pode apagar um lote;
     *   3. o lote não pode ter nada preso a ele. Lote com vistoria, peça,
     *      protocolo ou ato de sucessão não é resíduo: é história, e apagá-lo
     *      deixaria registros órfãos apontando para o vazio.
     */
    public function excluir(Request $request): JsonResponse
    {
        if ($erro = $this->recusarSemCuradoria($request)) {
            return $erro;
        }

        $d = $request->validate([
            'ids'    => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'  => ['integer', 'exists:lotes,id'],
            'senha'  => ['required', 'string'],
            'motivo' => ['required', 'string', 'min:10', 'max:300'],
        ]);

        if (! Hash::check($d['senha'], $request->user()->password)) {
            return response()->json([
                'message' => 'Senha incorreta. Nenhum lote foi apagado.',
            ], 422);
        }

        $lotes = Lote::whereIn('id', $d['ids'])->get();

        // TUDO OU NADA.
        //
        // Apagar três e deixar dois para trás, em silêncio, deixaria o fiscal
        // sem saber o que aconteceu com quais — e a operação não tem volta.
        // Recusar o lote inteiro e NOMEAR o que prende cada um é o que ele pode
        // resolver: tira aqueles da seleção e repete.
        $impedidos = [];
        foreach ($lotes as $lote) {
            if ($preso = $this->oQuePrende($lote)) {
                $impedidos[] = $this->identificar($lote) . ' (' . $preso . ')';
            }
        }

        if ($impedidos) {
            return response()->json([
                'message' => 'Nada foi apagado. Estes lotes não são resíduo: '
                    . implode('; ', $impedidos)
                    . ' Lote com história não se apaga — se ele deixou de existir, o '
                    . 'caminho é o desmembramento ou a unificação. Tire-os da seleção '
                    . 'e repita.',
            ], 422);
        }

        // Pelo Eloquent e um a um, para cada exclusão deixar a sua linha na
        // trilha de auditoria (ver App\Models\Concerns\RegistraAuditoria) — um
        // `delete()` em massa apagaria os lotes sem deixar quem, quando e qual.
        // E o DESENHO É GUARDADO ANTES, na mesma transação. A auditoria
        // registra todos os campos do lote menos `geom` — o escopo global
        // `sem_geometria` a tira de qualquer consulta do Eloquent —, e sem o
        // desenho a exclusão não tinha volta. Guardar e apagar acontecem
        // juntos: a cópia sem a exclusão deixa lixo, e a exclusão sem a cópia
        // é exatamente o que estamos consertando.
        $apagados = [];
        $guarda = app(LotesApagados::class);
        DB::transaction(function () use ($lotes, &$apagados, $guarda, $d) {
            foreach ($lotes as $lote) {
                $apagados[] = $this->identificar($lote);
                $guarda->guardar($lote, $d['motivo'] ?? null);
                $lote->delete();
            }
        });

        return response()->json([
            'message' => count($apagados) === 1
                ? sprintf('Lote apagado do desenho: %s. Motivo: %s', $apagados[0], $d['motivo'])
                : sprintf('%d lotes apagados do desenho. Motivo: %s', count($apagados), $d['motivo']),
            'apagados' => $apagados,
        ]);
    }

    /** Como o lote se identifica numa mensagem. */
    private function identificar(Lote $lote): string
    {
        return trim(sprintf('Quadra %s, Lote %s — %s',
            $lote->quadra ?: '—', $lote->numero_lote ?: '—', $lote->bairro ?: '—'));
    }

    /**
     * O que impede apagar este lote, em uma frase — ou null.
     *
     * Conta tudo antes de responder, em vez de parar no primeiro achado: quem
     * está limpando resíduo quer saber de uma vez o que há ali, e não descobrir
     * um impedimento por tentativa.
     */
    private function oQuePrende(Lote $lote): ?string
    {
        $presos = [];

        if ($n = Vistoria::where('lote_id', $lote->id)->count()) {
            $presos[] = $n . ' vistoria(s)';
        }
        if ($n = Documento::where('lote_id', $lote->id)->count()) {
            $presos[] = $n . ' documento(s)';
        }
        if ($n = Protocolo::where('lote_id', $lote->id)->count()) {
            $presos[] = $n . ' protocolo(s)';
        }
        if ($n = DB::table('lote_ato_lotes')->where('lote_id', $lote->id)->count()) {
            $presos[] = $n . ' ato(s) de sucessão';
        }

        return $presos ? 'há ' . implode(', ', $presos) . ' ligados a ele.' : null;
    }

    /** O portão dos atos que dispensam protocolo. */
    private function recusarSemCuradoria(Request $request): ?JsonResponse
    {
        if ($request->user()?->podeCurarCadastro()) {
            return null;
        }

        return response()->json([
            'message' => 'Só o curador do cadastro pode alterar o desenho sem protocolo.',
        ], 403);
    }

    private function recusarSemEdicao(Request $request): ?JsonResponse
    {
        if ($request->user()?->canEdit()) {
            return null;
        }

        return response()->json([
            'message' => 'Seu perfil não permite executar atos cadastrais.',
        ], 403);
    }

    /** @return array<string,mixed> */
    private function validarDesenho(Request $request): array
    {
        /** @var array<string,mixed> $d */
        $d = $request->validate([
            'bairro'      => ['required', 'string', 'max:120', 'exists:lotes,bairro'],
            'quadra'      => ['required', 'string', 'max:20'],
            'numero_lote' => ['required', 'string', 'max:20'],
            'inscricao_imobiliaria' => ['nullable', 'string', 'max:50'],
            'geometry'    => ['required', 'array'],
            // O conteúdo do polígono não se valida aqui: quem sabe recusar um
            // contorno que se cruza, ou pequeno demais, é o serviço — e com
            // mensagem que ensina, não com "geometry.coordinates.0 inválido".
            'geometry.type' => ['required', 'string'],
            // `coordinates` PRECISA estar listado, mesmo sem regra de conteúdo.
            //
            // `validate()` devolve só o que foi validado, e isso vale dentro do
            // array: declarando `geometry.type` e não `geometry.coordinates`, o
            // que chegava ao serviço era {"type":"Polygon"} — sem um único
            // ponto. O MySQL então recusava com "Missing required member
            // 'coordinates'" e a tela mostrava apenas "Server Error", num
            // desenho que o operador tinha acabado de fazer certo.
            'geometry.coordinates' => ['required', 'array'],

            // AS MEDIDAS DA MATRÍCULA. Todas opcionais: lote convertido do DWG
            // não tem nenhuma, e exigi-las aqui faria o operador preencher com
            // o que o desenho mediu — o que transformaria a conferência entre
            // as duas num espelho.
            //
            // O teto de 100.000 não é zelo: é para pegar o ponto decimal no
            // lugar errado, que é o erro de digitação de medida.
            'frente_m'          => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'fundos_m'          => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lado_direito_m'    => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lado_esquerdo_m'   => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'area_matricula_m2' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);

        $d['quadra'] = app(QuadraDeLotesSelecionados::class)->normalizar($d['quadra']);

        return $d;
    }

    /** @return array{ids: list<int>, quadra: string} */
    private function validar(Request $request): array
    {
        /** @var array{ids: list<int>, quadra: string} $d */
        $d = $request->validate([
            'ids'    => ['required', 'array', 'min:1', 'max:' . QuadraDeLotesSelecionados::MAXIMO],
            'ids.*'  => ['integer', 'exists:lotes,id'],
            'quadra' => ['required', 'string', 'max:20'],
        ]);

        return $d;
    }

    /**
     * Correção direta da base: exige CURADORIA CADASTRAL, não perfil.
     *
     * Era `isAdmin()`. Mudou porque administrar o sistema e responder pelo
     * cadastro são coisas diferentes: alterar a quadra de um lote ou desenhar
     * um imóvel muda a geometria que fundamenta o cálculo de área — e a área é
     * a base da multa. Quem cria usuários não é, por isso, quem pode redesenhar
     * o município.
     */
    private function recusarNaoAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->podeCurarCadastro()) {
            return null;
        }

        return response()->json([
            'message' => 'Alterar a identificação do imóvel exige a permissão de '
                . 'curadoria cadastral, concedida em Parâmetros > Usuários.',
        ], 403);
    }
}
