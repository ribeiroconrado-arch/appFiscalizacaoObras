<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Vistoria;
use App\Services\DesenhoDeLote;
use App\Services\DesmembramentoDeLote;
use App\Services\QuadraDeLotesSelecionados;
use App\Services\UnificacaoDeLotes;
use Illuminate\Http\JsonResponse;
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
            // O conteudo de cada parte nao se valida aqui: quem sabe recusar um
            // contorno que vaza, ou partes que nao cobrem o lote, e o servico —
            // e com mensagem que ensina, nao com "partes.0.geometry invalido".
            'partes.*.geometry' => ['required', 'array'],
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
