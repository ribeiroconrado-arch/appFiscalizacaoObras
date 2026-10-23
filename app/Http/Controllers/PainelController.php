<?php

namespace App\Http\Controllers;

use App\Cadastro\BairrosDoDesenho;
use App\Models\Documento;
use App\Models\OrdemServico;
use App\Models\Protocolo;
use App\Models\Vistoria;
use App\Support\Modulo;
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
        // O painel é do módulo em que o usuário está. Vistoria, protocolo e
        // cadastro de lotes são de Obras; em Posturas esses blocos não entram.
        $modulo = Modulo::ativo($request->user());
        $obras  = $modulo === 'obras';

        $vistorias = Vistoria::query()->where('data_hora', '>=', $desde)
            ->when($soMeu, fn ($q) => $q->where('fiscal_id', $uid))
            ->when($d['bairro'] ?? null, fn ($q, $b) => $q->whereHas('lote', fn ($l) => $l->where('bairro', $b)));

        $documentos = Documento::query()->doModulo($modulo)->where('created_at', '>=', $desde)
            ->when($soMeu, fn ($q) => $q->where('agente_id', $uid));

        return response()->json([
            'modulo'    => $modulo,
            'periodo'   => ['dias' => $dias, 'desde' => $desde->format('d/m/Y')],
            'metricas'  => match (true) {
                $obras                  => $this->metricas(clone $vistorias, clone $documentos),
                $modulo === 'posturas'  => $this->metricasDePosturas(clone $documentos, $desde, $soMeu, $request->user()),
                default                 => $this->metricasSemVistoria(clone $documentos),
            },
            'atencao'   => $this->atencao($uid, $modulo),
            'recentes'  => $this->recentes($uid, $modulo),
            'por_tipo'  => $this->documentosPorTipo(clone $documentos, $modulo),
            'infracoes' => $obras ? $this->infracoesFrequentes($desde) : $this->artigosMaisCitados(clone $documentos),
            // So bairros com imovel ativo: bairro que so tem lote inativo nao
            // existe mais como opcao de filtro.
            'bairros'   => DB::table('lotes')->where('situacao', 'ativo')->where('em_revisao', false)
                ->distinct()->orderBy('bairro')->pluck('bairro'),
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
            'rascunhos'   => ['n' => (clone $documentos)->whereIn('status', ['rascunho', 'gravado'])->count(),
                              'rotulo' => 'Não lavrados', 'detalhe' => 'rascunhos e gravados'],
        ];
    }

    /**
     * Os números de um módulo sem vistoria (Posturas): só as peças. As
     * denúncias entram aqui quando a tela delas existir.
     *
     * @return array<string,array{n:int,rotulo:string,detalhe:?string}>
     */
    private function metricasSemVistoria($documentos): array
    {
        return [
            'documentos' => ['n' => (clone $documentos)->count(), 'rotulo' => 'Documentos emitidos', 'detalhe' => null],
            'rascunhos'  => ['n' => (clone $documentos)->whereIn('status', ['rascunho', 'gravado'])->count(),
                             'rotulo' => 'Não lavrados', 'detalhe' => 'rascunhos e gravados'],
            'em_defesa'  => ['n' => (clone $documentos)->where('status', 'em_defesa')->count(),
                             'rotulo' => 'Em defesa', 'detalhe' => null],
            'atendidos'  => ['n' => (clone $documentos)->where('status', 'atendido')->count(),
                             'rotulo' => 'Atendidos', 'detalhe' => null],
        ];
    }

    /**
     * Os números de Posturas: as denúncias vêm primeiro, porque são o
     * trabalho do dia — o que chegou no período e o que ainda espera
     * tratativa —, e depois as peças. "Pendentes" não tem período: denúncia
     * esquecida há dois meses continua sendo pendência hoje.
     */
    private function metricasDePosturas($documentos, $desde, bool $soMeu, \App\Models\User $u): array
    {
        $ativas = fn () => \App\Models\Denuncia::query()
            ->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', '<>', \App\Models\Denuncia::CANCELADO))
            ->when($soMeu, fn ($q) => $q->where(fn ($b) => $b->where('agente_id', $u->id)
                ->orWhere(fn ($c) => $c->whereNull('agente_id')->where('agente_nome', $u->name))));
        $pendentes = fn () => $ativas()->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', ''));
        // A hora da denúncia é local e sem fuso (como no AppPOSTURAS).
        $desdeLocal = $desde->copy()->setTimezone('America/Cuiaba')->format('Y-m-d H:i:s');
        $noPeriodo = $ativas()->where('data_hora', '>=', $desdeLocal)->count();
        $pendNoPeriodo = $pendentes()->where('data_hora', '>=', $desdeLocal)->count();

        return [
            'denuncias'  => ['n' => $noPeriodo, 'rotulo' => 'Denúncias registradas',
                             'detalhe' => $pendNoPeriodo ? $pendNoPeriodo . ' ainda sem tratativa' : 'sem as canceladas'],
            'pendentes'  => ['n' => $pendentes()->count(), 'rotulo' => 'Denúncias pendentes', 'detalhe' => 'sem tratativa, de qualquer data'],
            'documentos' => ['n' => (clone $documentos)->count(), 'rotulo' => 'Documentos emitidos',
                             'detalhe' => ($nl = (clone $documentos)->whereIn('status', ['rascunho', 'gravado'])->count())
                                 ? $nl . ' ainda não lavrado(s)' : 'todos lavrados'],
            'em_defesa'  => ['n' => (clone $documentos)->whereIn('status', ['lavrado', 'em_defesa'])->whereNotNull('defesa_ate')
                                ->where('defesa_ate', '>=', now()->toDateString())->count(),
                             'rotulo' => 'Autos em prazo de defesa', 'detalhe' => null],
        ];
    }

    /**
     * "Precisa de atenção": itens acionáveis, não estatística.
     * Cada linha aponta para algo que trava o processo se ninguém agir.
     *
     * @return list<array{titulo:string,detalhe:string,tag:?array,aba:?string}>
     */
    /**
     * O que trava o trabalho em Posturas:
     *  - denúncia pendente há mais de dois dias: atendida e sem tratativa
     *    anotada, ninguém sabe o que foi feito;
     *  - endereço reincidente — três ou mais denúncias em 30 dias —, que é o
     *    caso de passar da orientação para a notificação e o auto;
     *  - rascunho meu parado há mais de três dias, peça que não virou ato.
     *
     * @return list<array<string,mixed>>
     */
    private function atencaoDePosturas(int $uid): array
    {
        $itens = [];
        $agora = now('America/Cuiaba');
        $ativa = fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', '<>', \App\Models\Denuncia::CANCELADO);

        $atrasadas = \App\Models\Denuncia::query()->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', ''))
            ->where('data_hora', '<', $agora->copy()->subDays(2)->format('Y-m-d H:i:s'))->count();
        if ($atrasadas > 0) {
            $itens[] = [
                'titulo'  => $atrasadas . ' denúncia(s) sem tratativa há mais de 2 dias',
                'detalhe' => 'Registre o que foi feito: orientado, notificado, auto…',
                'tag'     => ['texto' => 'Pendente', 'classe' => 'bd-pe'],
                'aba'     => 'denuncias',
            ];
        }

        $reincidentes = \App\Models\Denuncia::query()->where($ativa)
            ->where('data_hora', '>=', $agora->copy()->subDays(30)->format('Y-m-d H:i:s'))
            ->whereNotNull('logradouro')
            ->selectRaw('logradouro, numero, count(*) as n')->groupBy('logradouro', 'numero')
            ->havingRaw('count(*) >= 3')->orderByDesc('n')->limit(5)->get();
        foreach ($reincidentes as $r) {
            $itens[] = [
                'titulo'  => trim($r->logradouro . ($r->numero ? ', ' . $r->numero : '')),
                'detalhe' => $r->n . ' denúncias nos últimos 30 dias',
                'tag'     => ['texto' => 'Reincidente', 'classe' => 'bd-er'],
                'aba'     => 'denuncias',
            ];
        }

        $rascunhos = Documento::query()->doModulo('posturas')->where('agente_id', $uid)->where('status', 'rascunho')
            ->where('created_at', '<', now()->subDays(3))->count();
        if ($rascunhos > 0) {
            $itens[] = [
                'titulo'  => $rascunhos . ' rascunho(s) parado(s) há mais de 3 dias',
                'detalhe' => 'Peça começada que ainda não virou ato',
                'tag'     => ['texto' => 'Rascunho', 'classe' => 'bd-in'],
                'aba'     => 'documentos',
            ];
        }

        return $itens;
    }

    private function atencao(int $uid, string $modulo): array
    {
        $itens = [];
        $obras = $modulo === 'obras';

        // 1. Prazos vencendo ou vencidos, dos documentos do próprio usuário
        $prazos = Documento::query()->doModulo($modulo)
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
                'chave'   => 'documento-prazo-' . $doc->id,
                // Em Posturas a peça é do local, não do lote: vai o endereço.
                'detalhe' => $obras
                    ? sprintf('%s · Quadra %s · Lote %s', $doc->rotuloTipo(), $doc->lote?->quadra ?? '—', $doc->lote?->numero_lote ?? '—')
                    : $doc->rotuloTipo() . ' · ' . ($doc->endereco ?: 'sem endereço') . ($doc->autuado_nome ? ' · ' . $doc->autuado_nome : ''),
                'tag'     => ['texto' => $txt, 'classe' => $cls],
                'aba'     => 'documentos',
            ];
        }

        // 2. Vistorias irregulares sem nenhum documento no mesmo lote (só Obras)
        $semDoc = ! $obras ? 0 : Vistoria::query()
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

        // 3. Ordens de serviço designadas a mim e ainda sem ciência.
        //
        // A OS nasceu depois deste painel e não aparecia em lugar nenhum aqui.
        // Sem ciência, ela é o caso mais claro de pendência: alguém determinou
        // um serviço e o sistema ainda não sabe se o fiscal ficou sabendo.
        $semCiencia = OrdemServico::query()->doModulo($modulo)
            ->whereIn('situacao', ['aberta', 'em_andamento'])
            ->whereHas('fiscais', fn ($f) => $f
                ->where('users.id', $uid)
                ->whereNull('os_fiscais.ciencia_em'))
            ->orderByDesc('id')->get();

        foreach ($semCiencia as $os) {
            $itens[] = [
                'titulo'  => $os->numeroFormatado(),
                'detalhe' => $os->objeto,
                'tag'     => ['texto' => 'Assinar ciência', 'classe' => 'bd-al'],
                'aba'     => 'protocolos',
            ];
        }

        // 4. Minhas ordens em curso com o prazo já vencido.
        //
        // `vencida()` é leitura do relógio sobre a situação gravada — a mesma
        // regra que pinta o selo na lista de OS, e não uma segunda contagem
        // escrita aqui.
        $vencidas = OrdemServico::query()->doModulo($modulo)
            ->whereIn('situacao', ['aberta', 'em_andamento'])
            ->whereHas('fiscais', fn ($f) => $f->where('users.id', $uid))
            ->with('jornadas')
            ->get()
            ->filter(fn (OrdemServico $os) => $os->vencida());

        foreach ($vencidas as $os) {
            $itens[] = [
                'titulo'  => $os->numeroFormatado(),
                'detalhe' => $os->objeto . ' · ' . $os->quandoRotulo(),
                'tag'     => $os->situacaoTag(),
                'aba'     => 'protocolos',
            ];
        }

        // Daqui para baixo é protocolo e cadastro de lotes: só Obras. Posturas
        // tem as suas pendências — as das denúncias.
        if (! $obras) {
            return $modulo === 'posturas' ? [...$itens, ...$this->atencaoDePosturas($uid)] : $itens;
        }

        // 5. Protocolos sob minha responsabilidade com prazo apertado.
        //
        // `situacaoPrazo()` devolve null quando o protocolo já foi decidido ou
        // não tem prazo — e é ela quem decide o que é apertado, para o painel
        // e a lista dizerem a mesma coisa.
        $meusProtocolos = Protocolo::query()
            ->where('responsavel_id', $uid)
            ->whereNotNull('prazo_resposta')
            ->with('lote:id,bairro,quadra,numero_lote')
            ->get();

        foreach ($meusProtocolos as $proto) {
            $prazo = $proto->situacaoPrazo();
            // Só o que aperta: "Prazo até 12/09" é informação, não pendência.
            if (! $prazo || $prazo[1] === 'bd-ok') { continue; }

            [$txt, $cls] = $prazo;
            $itens[] = [
                'titulo'  => 'Protocolo ' . $proto->numero,
                'detalhe' => trim(($proto->requerente_nome ?? 'Sem requerente')
                    . ' · Quadra ' . ($proto->lote?->quadra ?? '—')
                    . ' · Lote ' . ($proto->lote?->numero_lote ?? '—')),
                'tag'     => ['texto' => $txt, 'classe' => $cls],
                'aba'     => 'protocolos',
            ];
        }

        // 6. Bairro do desenho sem cadastro amarrado.
        //
        // ISTO É PENDÊNCIA POR CAUSA DE COMO O DEFEITO SE ESCONDE. Sem a
        // amarração, `BairrosDoDesenho::oficial` devolve o nome do desenho —
        // de propósito, porque coluna de bairro vazia seria pior. O preço é que
        // a falha não parece falha: a tela mostra "Residencial Buritis V" onde
        // devia mostrar "RESIDENCIAL BURITIS PRIMAVERA V - PRIME", e continua
        // parecendo certa. Pior: sem o código do bairro não há inscrição
        // imobiliária, e o imóvel some da busca por inscrição.
        //
        // Contamos pelos LOTES, e não pelos bairros do cadastro: bairro
        // cadastrado que ainda não foi desenhado não é pendência de ninguém.
        $doDesenho = new BairrosDoDesenho();
        $soltos = DB::table('lotes')
            ->where('situacao', 'ativo')
            ->whereNotNull('bairro')->where('bairro', '<>', '')
            ->select('bairro')->selectRaw('count(*) as lotes')
            ->groupBy('bairro')->get()
            ->filter(fn ($r) => $doDesenho->oficial($r->bairro) === $r->bairro);

        if ($soltos->isNotEmpty()) {
            $itens[] = [
                'titulo'  => $soltos->count() . ' bairro(s) do desenho sem cadastro',
                'detalhe' => $soltos->sum('lotes') . ' lote(s) sem inscrição imobiliária · '
                    . $soltos->pluck('bairro')->implode(', '),
                'tag'     => ['texto' => 'Sem inscrição', 'classe' => 'bd-al'],
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
    private function recentes(int $uid, string $modulo): array
    {
        $linhas = $this->auditoriaDoModulo($modulo)->orderByDesc('id')->limit(12)->get();

        // O registro de auditoria guarda só o id do documento; o número e o
        // tipo, que é o que identifica a peça para quem lê, moram na tabela
        // `documentos`. Uma consulta para todos de uma vez, não uma por linha.
        $idsDoc = $linhas->where('tabela', 'documentos')->pluck('registro_id')->filter()->unique();
        $docs = $idsDoc->isEmpty()
            ? collect()
            : Documento::whereIn('id', $idsDoc)->get()->keyBy('id');

        return $linhas->map(function ($a) use ($docs, $uid) {
            $doc = $a->tabela === 'documentos' ? $docs->get($a->registro_id) : null;

            return [
                // Título: o que foi mexido, identificável de relance.
                'titulo'   => $doc
                    ? $doc->numeroFormatado() . ' · ' . $doc->rotuloTipo()
                    : ucfirst($this->rotuloTabela($a->tabela)) . ($a->descricao ? ' ' . $a->descricao : ''),
                // Descrição: o que aconteceu, em frase.
                'detalhe'  => $this->fraseAcao($a->acao, $a->tabela),
                'usuario'  => $a->usuario_nome ?? 'sistema',
                // "por você" pesa menos que repetir o próprio nome na lista.
                'eu'       => $a->user_id !== null && (int) $a->user_id === $uid,
                'iniciais' => $this->iniciaisDe($a->usuario_nome),
                'quando'   => \Carbon\Carbon::parse($a->created_at)->format('d/m/Y'),
                'hora'     => \Carbon\Carbon::parse($a->created_at)->format('d/m/Y H:i'),
            ];
        })->all();
    }

    /**
     * A trilha recortada pelo módulo.
     *
     * Em Obras sai tudo como antes, menos peça e OS de outro módulo. Nos
     * demais, só o que é deles: as próprias peças e ordens — vistoria, lote e
     * protocolo são de Obras e não têm por que aparecer ali.
     */
    private function auditoriaDoModulo(string $modulo): \Illuminate\Database\Query\Builder
    {
        $idsDe = fn (string $tabela, bool $doModulo) => DB::table($tabela)
            ->where('modulo', $doModulo ? '=' : '<>', $modulo)->select('id');

        $q = DB::table('auditoria');

        if ($modulo === 'obras') {
            return $q->where(fn ($w) => $w
                ->whereNotIn('tabela', ['documentos', 'ordens_servico'])
                ->orWhere(fn ($d) => $d->where('tabela', 'documentos')->whereNotIn('registro_id', $idsDe('documentos', false)))
                ->orWhere(fn ($o) => $o->where('tabela', 'ordens_servico')->whereNotIn('registro_id', $idsDe('ordens_servico', false))));
        }

        return $q->where(fn ($w) => $w
            ->where(fn ($d) => $d->where('tabela', 'documentos')->whereIn('registro_id', $idsDe('documentos', true)))
            ->orWhere(fn ($o) => $o->where('tabela', 'ordens_servico')->whereIn('registro_id', $idsDe('ordens_servico', true))));
    }

    /** Ação da auditoria em frase, no gênero do que foi alterado. */
    private function fraseAcao(string $acao, string $tabela): string
    {
        $alvo = $this->rotuloTabela($tabela);
        $feminino = in_array($alvo, ['vistoria', 'lei', 'evidência'], true);

        $participio = match ($acao) {
            'criou'        => $feminino ? 'registrada' : 'registrado',
            'alterou'      => $feminino ? 'alterada' : 'alterado',
            'excluiu'      => $feminino ? 'excluída' : 'excluído',
            'lavrou'       => 'lavrado',
            'gravou'       => 'gravado',
            'cancelou'     => 'cancelado',
            'registrou defesa' => 'com defesa registrada',
            'deferiu defesa'   => 'com defesa deferida',
            'indeferiu defesa' => 'com defesa indeferida',
            'anulou'       => $feminino ? 'anulada' : 'anulado',
            'assinou'      => $feminino ? 'assinada' : 'assinado',
            'alterou prazo' => 'com prazo alterado',
            default        => $acao,
        };

        return ucfirst($alvo) . ' ' . $participio;
    }

    /** Iniciais para o avatar do feed — mesma regra de User::iniciais(). */
    private function iniciaisDe(?string $nome): string
    {
        if (! $nome) {
            return '··';
        }

        $partes = array_values(array_filter(
            preg_split('/\s+/', trim($nome)),
            fn ($p) => ! in_array(mb_strtolower($p), ['de', 'da', 'do', 'das', 'dos', 'e'], true)
        ));

        if (! $partes) {
            return '?';
        }

        return mb_strtoupper(mb_substr($partes[0], 0, 1)
            . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
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

    /** @return list<array{rotulo:string,n:int}> */
    private function documentosPorTipo($documentos, string $modulo): array
    {
        $contagem = (clone $documentos)->select('tipo', DB::raw('COUNT(*) n'))
            ->groupBy('tipo')->pluck('n', 'tipo');

        $saida = [];
        foreach (Documento::tiposDoModulo($modulo) as $valor => $t) {
            if (($contagem[$valor] ?? 0) > 0) {
                $saida[] = ['rotulo' => $t[0], 'n' => (int) $contagem[$valor]];
            }
        }
        usort($saida, fn ($a, $b) => $b['n'] <=> $a['n']);
        return $saida;
    }

    /**
     * As infrações mais constatadas no período: os artigos CITADOS nas
     * vistorias (parecer não conta), uma vez por vistoria.
     *
     * @return list<array{rotulo:string,n:int}>
     */
    private function infracoesFrequentes(\Carbon\Carbon $desde): array
    {
        return DB::table('vistoria_artigos as va')
            ->join('artigos as a', 'a.id', '=', 'va.artigo_id')
            ->join('vistorias as v', 'v.id', '=', 'va.vistoria_id')
            ->leftJoin('legislacoes as l', 'l.id', '=', 'a.legislacao_id')
            ->where('va.tipo', 'citacao')
            ->where('v.data_hora', '>=', $desde)
            ->select('a.numero', 'a.apelido', 'l.numero as lei', DB::raw('COUNT(DISTINCT va.vistoria_id) as n'))
            ->groupBy('a.id', 'a.numero', 'a.apelido', 'l.numero')
            ->orderByDesc('n')->limit(6)
            ->get()->map(fn ($r) => [
                'rotulo' => 'Art. ' . $r->numero . ($r->apelido ? ' — ' . $r->apelido : ($r->lei ? ' — ' . $r->lei : '')),
                'n'      => (int) $r->n,
            ])->all();
    }

    /**
     * Sem vistoria (Posturas), a infração mais frequente é o artigo mais
     * citado NAS PEÇAS do período, uma vez por peça.
     *
     * @return list<array{rotulo:string,n:int}>
     */
    private function artigosMaisCitados($documentos): array
    {
        $ids = (clone $documentos)->select('documentos.id');

        return DB::table('documento_artigos as da')
            ->whereIn('da.documento_id', $ids)
            ->select('da.numero', DB::raw('COUNT(DISTINCT da.documento_id) as n'))
            ->groupBy('da.numero')
            ->orderByDesc('n')->limit(6)
            ->get()->map(fn ($r) => ['rotulo' => 'Art. ' . $r->numero, 'n' => (int) $r->n])->all();
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

        $docs = Documento::doModulo()->where('agente_id', $uid)
            ->whereIn('status', ['lavrado', 'rascunho', 'gravado'])
            ->with('lote:id,bairro,quadra,numero_lote')->get();

        foreach ($docs as $doc) {
            $imovel = sprintf('Quadra %s · Lote %s', $doc->lote?->quadra ?? '—', $doc->lote?->numero_lote ?? '—');

            if ($doc->naoLavrado()) {
                $avisos[] = [
                    'leitura_id' => 'documento-' . $doc->id . '-' . $doc->status . '-' . $doc->updated_at?->timestamp,
                    'tipo' => 'rascunho',
                    'titulo'  => $doc->status === 'gravado' ? 'Documento gravado, ainda não lavrado' : 'Rascunho não gravado',
                    'texto'   => $doc->rotuloTipo() . ' — ' . $imovel . '. '
                        . ($doc->status === 'gravado' ? 'Nº ' . $doc->numeroFormatado() . ', aguardando a lavratura.' : 'Sem número enquanto não for gravado.'),
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
                        'chave'   => 'documento-prazo-' . $doc->id,
                        'leitura_id' => 'documento-prazo-' . $doc->id . '-' . $cls . '-' . $doc->updated_at?->timestamp,
                        'tipo' => 'prazo',
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
