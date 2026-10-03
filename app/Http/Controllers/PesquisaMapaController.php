<?php

namespace App\Http\Controllers;

use App\Cadastro\BairrosDoDesenho;
use App\Models\Lote;
use App\Models\Sinalizacao;
use App\Models\Vistoria;
use App\Support\InscricaoImobiliaria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pesquisa do mapa por FILTROS COMBINADOS.
 *
 * A barra do mapa não tem mais campo de texto livre: quem pesquisa monta a
 * pergunta com filtros — onde (bairro, quadra, lote, inscrição), endereço
 * (rua e número) e pendências — e eles se SOMAM (um E o outro). Dentro de um
 * mesmo filtro de lista, as escolhas valem como OU.
 *
 * A Consulta (BuscaController::buscar) continua com a busca dela; aqui a
 * resposta é pensada para o mapa: até 2.000 ids para pintar, a caixa que os
 * envolve para enquadrar, e as primeiras linhas para a lista.
 */
class PesquisaMapaController extends Controller
{
    /** Teto do que se pinta no mapa, e do que se lista. */
    private const TETO_IDS = 2000;
    private const TETO_LISTA = 100;

    /** As pendências que a tela oferece. A chave viaja no pedido. */
    public const PENDENCIAS = [
        'sinalizacao'      => 'Sinalização aberta',
        'embargo'          => 'Embargo ativo',
        'prazo_vencido'    => 'Documento com prazo vencido',
        'prazo_a_vencer'   => 'Prazo vence em 7 dias',
        'obra_sem_vistoria' => 'Projeto aprovado sem vistoria',
        'lembrete'         => 'Lembrete vencido',
    ];

    /** "Nunca vistoriado" não é situação de vistoria — é a falta dela. */
    private const NUNCA = 'nunca';

    private ?BairrosDoDesenho $bairros = null;

    private function bairros(): BairrosDoDesenho
    {
        return $this->bairros ??= new BairrosDoDesenho();
    }

    /**
     * GET /api/mapa/pesquisa/opcoes — as listas dos seletores com busca.
     *
     * Bairros e ruas saem do que a pesquisa PODE achar: bairro com lote
     * publicado, e rua de bairro cujo cadastro foi carregado e amarrado.
     * Oferecer o resto seria oferecer uma busca que devolve vazio.
     */
    public function opcoes(Request $r): JsonResponse
    {
        $b = $this->bairros();
        $codigos = [];   // nome do desenho (chave) => código
        foreach (DB::table('cadastro_bairros')->whereNotNull('nome_gis')->get(['codigo', 'nome_gis']) as $cb) {
            $codigos[BairrosDoDesenho::chave($cb->nome_gis)] = $cb->codigo;
        }

        $bairros = [];
        foreach (DB::table('lotes')->where('situacao', 'ativo')->where('em_revisao', false)
            ->whereNotNull('bairro')->where('bairro', '<>', '')->distinct()->pluck('bairro') as $nome) {
            $oficial = $b->oficial($nome);
            $bairros[$oficial] ??= ['nome' => $oficial, 'codigo' => $codigos[BairrosDoDesenho::chave($nome)] ?? null];
        }
        ksort($bairros, SORT_NATURAL | SORT_FLAG_CASE);

        // Rua => bairros (oficiais) em que ela aparece, para a sugestão dizer onde fica.
        $porCodigo = [];
        foreach (DB::table('cadastro_bairros')->whereNotNull('nome_gis')->get(['codigo', 'nome_cadastro']) as $cb) {
            $porCodigo[ltrim((string) $cb->codigo, '0')] = $cb->nome_cadastro;
        }
        $ruas = [];
        if ($porCodigo) {
            $linhas = DB::table('cadastro_externo_imoveis')
                ->whereIn(DB::raw("TRIM(LEADING '0' FROM codigo_bairro)"), array_keys($porCodigo))
                ->whereNotNull('logradouro')->where('logradouro', '<>', '')
                ->selectRaw("logradouro, TRIM(LEADING '0' FROM codigo_bairro) AS cod")
                ->distinct()->orderBy('logradouro')->get();
            foreach ($linhas as $l) {
                $ruas[$l->logradouro][] = $porCodigo[$l->cod] ?? null;
            }
        }

        $interno = $r->user()->podeVerDocumentos();

        return response()->json([
            'bairros' => array_values($bairros),
            'ruas' => collect($ruas)->map(fn ($bs, $rua) => [
                'rua' => $rua, 'bairros' => array_values(array_unique(array_filter($bs))),
            ])->values(),
            // Pendência é conteúdo da fiscalização: só quem é de dentro filtra por ela.
            'pendencias' => $interno ? self::PENDENCIAS : [],
            'vistorias'  => $interno ? Vistoria::SITUACOES + [self::NUNCA => 'Nunca vistoriado'] : [],
        ]);
    }

    /** GET /api/mapa/pesquisa — os imóveis que atendem a TODOS os filtros. */
    public function pesquisar(Request $r): JsonResponse
    {
        $d = $r->validate([
            'bairros'      => ['nullable', 'array', 'max:40'],
            'bairros.*'    => ['string', 'max:160'],
            'quadra_de'    => ['nullable', 'integer', 'min:0'],
            'quadra_ate'   => ['nullable', 'integer', 'min:0'],
            'lote_de'      => ['nullable', 'integer', 'min:0'],
            'lote_ate'     => ['nullable', 'integer', 'min:0'],
            'inscricao'    => ['nullable', 'string', 'max:40'],
            'ruas'         => ['nullable', 'array', 'max:40'],
            'ruas.*'       => ['string', 'max:180'],
            'numero_modo'  => ['nullable', Rule::in(['exato', 'faixa', 'par', 'impar'])],
            'numero_de'    => ['nullable', 'integer', 'min:0'],
            'numero_ate'   => ['nullable', 'integer', 'min:0'],
            'pendencias'   => ['nullable', 'array'],
            'pendencias.*' => [Rule::in(array_keys(self::PENDENCIAS))],
            'pendencias_modo' => ['nullable', Rule::in(['qualquer', 'todas'])],
            'vistorias'    => ['nullable', 'array'],
            'vistorias.*'  => [Rule::in([...array_keys(Vistoria::SITUACOES), self::NUNCA])],
            'sem_pendencia' => ['nullable', 'boolean'],
        ]);

        $usaFiscalizacao = ! empty($d['pendencias']) || ! empty($d['vistorias']) || ! empty($d['sem_pendencia']);
        if ($usaFiscalizacao && ! $r->user()->podeVerDocumentos()) {
            return response()->json(['message' => 'Os filtros de pendência são de uso interno da fiscalização.'], 403);
        }

        $q = Lote::query()->publicados();
        if (! $this->aplicar($q, $d, $r)) {
            return response()->json(['message' => 'Escolha ao menos um filtro.'], 422);
        }

        $ids = (clone $q)->reorder()->limit(self::TETO_IDS + 1)->pluck('lotes.id');
        $truncado = $ids->count() > self::TETO_IDS;
        $ids = $ids->take(self::TETO_IDS)->values();

        $lotes = $q->orderBy('bairro')
            ->orderByRaw('CAST(quadra AS UNSIGNED)')->orderByRaw('CAST(numero_lote AS UNSIGNED)')
            ->limit(self::TETO_LISTA)->get();

        $pend = $r->user()->podeVerDocumentos() ? $this->pendenciasDe($lotes->pluck('id')->all(), $r) : [];
        $enderecos = $this->enderecosDe($lotes);

        return response()->json([
            'total'    => $ids->count(),
            'truncado' => $truncado,
            'ids'      => $ids,
            'caixa'    => $this->caixa($ids->all()),
            'imoveis'  => $lotes->map(fn (Lote $l) => [
                'id'        => $l->id,
                'bairro'    => $this->bairros()->oficial($l->bairro),
                'quadra'    => $l->quadra,
                'lote'      => $l->numero_lote,
                'inscricao' => $this->bairros()->inscricaoDe($l),
                'endereco'  => $enderecos[$l->id] ?? null,
                'pendencias' => $pend[$l->id] ?? [],
            ])->values(),
        ]);
    }

    /** @return bool se ao menos um filtro foi usado */
    private function aplicar(Builder $q, array $d, Request $r): bool
    {
        $usou = false;

        // Chega o nome OFICIAL; `lotes.bairro` guarda o do desenho.
        if (! empty($d['bairros'])) {
            $nomes = [];
            foreach ($d['bairros'] as $oficial) {
                $nomes = [...$nomes, ...$this->bairros()->nomesDeDesenhoDe($oficial)];
            }
            $q->whereIn('bairro', array_values(array_unique($nomes)));
            $usou = true;
        }

        foreach (['quadra' => 'quadra', 'lote' => 'numero_lote'] as $campo => $coluna) {
            $de = $d[$campo . '_de'] ?? null;
            $ate = $d[$campo . '_ate'] ?? null;
            if ($de === null && $ate === null) {
                continue;
            }
            // Só número: "12A" não entra em faixa nenhuma, em vez de valer 12.
            $q->whereRaw("{$coluna} REGEXP '^[0-9]+$'");
            if ($de !== null)  { $q->whereRaw("CAST({$coluna} AS UNSIGNED) >= ?", [(int) $de]); }
            if ($ate !== null) { $q->whereRaw("CAST({$coluna} AS UNSIGNED) <= ?", [(int) $ate]); }
            $usou = true;
        }

        // Inscrição pelo começo: "01.090.002" é a quadra 2 do bairro 90
        // inteira. Compara com a GRAVADA (lotes.inscricao_montada) ou a informada.
        if (! empty($d['inscricao'])) {
            $dig = preg_replace('/\D/', '', $d['inscricao']);
            if (strlen($dig) < 2) {
                $q->whereRaw('1 = 0');
            } else {
                $q->where(fn ($s) => $s->where('inscricao_montada', 'like', $dig . '%')
                    ->orWhereRaw("REPLACE(inscricao_imobiliaria, '.', '') LIKE ?", [$dig . '%']));
            }
            $usou = true;
        }

        // ── ENDEREÇO: vem do cadastro da prefeitura, não do desenho ──
        $temNumero = ! empty($d['numero_modo'])
            && (in_array($d['numero_modo'], ['par', 'impar'], true) || isset($d['numero_de']) || isset($d['numero_ate']));
        if (! empty($d['ruas']) || $temNumero) {
            $pares = $this->imoveisDoEndereco($d['ruas'] ?? [], $temNumero ? $d : []);
            if (! $pares) {
                $q->whereRaw('1 = 0');
            } else {
                $q->where(function ($s) use ($pares) {
                    foreach ($pares as [$nomes, $quadra, $lote]) {
                        $s->orWhere(fn ($x) => $x->whereIn('bairro', $nomes)
                            ->whereRaw("TRIM(LEADING '0' FROM quadra) = ?", [$quadra])
                            ->whereRaw("TRIM(LEADING '0' FROM numero_lote) = ?", [$lote]));
                    }
                });
            }
            $usou = true;
        }

        // ── PENDÊNCIAS ──
        if (! empty($d['pendencias'])) {
            $todas = ($d['pendencias_modo'] ?? 'qualquer') === 'todas';
            $q->where(function ($s) use ($d, $todas, $r) {
                foreach (array_unique($d['pendencias']) as $tipo) {
                    $sub = fn ($x) => $this->lotesCom($tipo, $x, $r);
                    $todas ? $s->where($sub) : $s->orWhere($sub);
                }
            });
            $usou = true;
        }

        if (! empty($d['sem_pendencia'])) {
            foreach (array_keys(self::PENDENCIAS) as $tipo) {
                $q->whereNot(fn ($x) => $this->lotesCom($tipo, $x, $r));
            }
            $usou = true;
        }

        // Situação da ÚLTIMA vistoria — ou nenhuma vistoria.
        if (! empty($d['vistorias'])) {
            $situacoes = array_values(array_diff($d['vistorias'], [self::NUNCA]));
            $nunca = in_array(self::NUNCA, $d['vistorias'], true);
            $q->where(function ($s) use ($situacoes, $nunca) {
                if ($situacoes) {
                    $s->orWhereIn('lotes.id', fn ($x) => $x->from('vistorias as v')->select('v.lote_id')
                        ->whereRaw('v.id = (SELECT MAX(v2.id) FROM vistorias v2 WHERE v2.lote_id = v.lote_id)')
                        ->whereIn('v.situacao', $situacoes));
                }
                if ($nunca) {
                    $s->orWhereNotIn('lotes.id', fn ($x) => $x->from('vistorias')->select('lote_id'));
                }
            });
            $usou = true;
        }

        return $usou;
    }

    /**
     * A condição "o lote tem a pendência $tipo", escrita sobre a consulta $q.
     *
     * Uma função só para filtrar E para rotular o resultado (pendenciasDe):
     * duas definições da mesma pendência acabariam divergindo.
     */
    private function lotesCom(string $tipo, $q, Request $r): void
    {
        $hoje = now()->toDateString();

        match ($tipo) {
            // As sinalizações que ESTE usuário vê: quem é de fora só vê as dele.
            'sinalizacao' => $q->whereIn('lotes.id', Sinalizacao::query()->visiveisPara($r->user())
                ->where('status', 'aberta')->where('tipo', '<>', 'lembrete')->select('lote_id')),
            'lembrete' => $q->whereIn('lotes.id', Sinalizacao::query()->visiveisPara($r->user())
                ->where('status', 'aberta')->where('tipo', 'lembrete')
                ->where(fn ($x) => $x->whereNull('lembrar_em')->orWhere('lembrar_em', '<=', $hoje))->select('lote_id')),
            // Embargo ativo: auto de embargo lavrado e ainda não anulado.
            'embargo' => $q->whereIn('lotes.id', fn ($x) => $x->from('documentos')->select('lote_id')
                ->where('tipo', 'auto_embargo')->whereIn('status', ['lavrado', 'atendido'])),
            // Lavrado e sem atendimento, com o prazo (de cumprimento ou de defesa) já passado.
            'prazo_vencido' => $q->whereIn('lotes.id', fn ($x) => $x->from('documentos')->select('lote_id')
                ->where('status', 'lavrado')
                ->where(fn ($p) => $p->where('prazo_ate', '<', $hoje)->orWhere('defesa_ate', '<', $hoje))),
            'prazo_a_vencer' => $q->whereIn('lotes.id', fn ($x) => $x->from('documentos')->select('lote_id')
                ->where('status', 'lavrado')
                ->where(fn ($p) => $p->whereBetween('prazo_ate', [$hoje, now()->addDays(7)->toDateString()])
                    ->orWhereBetween('defesa_ate', [$hoje, now()->addDays(7)->toDateString()]))),
            // Obra com alvará e nenhuma vistoria: projeto aprovado que ninguém foi conferir.
            'obra_sem_vistoria' => $q->whereIn('lotes.id', fn ($x) => $x->from('obras')->select('lote_id')
                    ->whereNotNull('alvara')->where('alvara', '<>', ''))
                ->whereNotIn('lotes.id', fn ($x) => $x->from('vistorias')->select('lote_id')),
        };
    }

    /**
     * As pendências de cada lote listado, para as etiquetas da linha.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array<int,string>> id do lote => rótulos
     */
    private function pendenciasDe(array $ids, Request $r): array
    {
        $saida = [];
        if (! $ids) {
            return $saida;
        }
        foreach (self::PENDENCIAS as $tipo => $rotulo) {
            $q = Lote::query()->withoutGlobalScopes()->whereIn('lotes.id', $ids);
            $q->where(fn ($x) => $this->lotesCom($tipo, $x, $r));
            foreach ($q->pluck('lotes.id') as $id) {
                $saida[$id][] = $rotulo;
            }
        }

        return $saida;
    }

    /**
     * Os imóveis do cadastro que estão nas ruas e no trecho de número pedidos,
     * como tripas `[nomes-de-desenho-do-bairro, quadra, lote]`.
     *
     * Só bairro AMARRADO: sem a amarração o par (quadra, lote) ficaria solto,
     * e ele existe em mais de um bairro da cidade.
     *
     * @param  array<int,string>  $ruas
     * @param  array<string,mixed>  $numero  vazio, ou com numero_modo/de/ate
     * @return array<int, array{0:array<int,string>, 1:string, 2:string}>
     */
    private function imoveisDoEndereco(array $ruas, array $numero): array
    {
        $porCodigo = [];
        foreach (DB::table('cadastro_bairros')->whereNotNull('nome_gis')->get() as $b) {
            $porCodigo[ltrim((string) $b->codigo, '0')][] = $b->nome_gis;
        }
        if (! $porCodigo) {
            return [];
        }

        $q = DB::table('cadastro_externo_imoveis')
            ->whereIn(DB::raw("TRIM(LEADING '0' FROM codigo_bairro)"), array_keys($porCodigo));

        // A rua vem da LISTA do cadastro (seletor), então casa exata.
        if ($ruas) {
            $q->whereIn('logradouro', $ruas);
        }

        if ($numero) {
            // Só número predial que é número: "S/N" e "12A" ficam de fora.
            $q->whereRaw("numero_predial REGEXP '^[0-9]+$'");
            $n = 'CAST(numero_predial AS UNSIGNED)';
            match ($numero['numero_modo']) {
                'exato' => $q->whereRaw("{$n} = ?", [(int) ($numero['numero_de'] ?? $numero['numero_ate'])]),
                'faixa' => $q
                    ->when(isset($numero['numero_de']), fn ($x) => $x->whereRaw("{$n} >= ?", [(int) $numero['numero_de']]))
                    ->when(isset($numero['numero_ate']), fn ($x) => $x->whereRaw("{$n} <= ?", [(int) $numero['numero_ate']])),
                'par'   => $q->whereRaw("{$n} % 2 = 0"),
                'impar' => $q->whereRaw("{$n} % 2 = 1"),
            };
        }

        // Teto de segurança: cada imóvel vira uma condição OR na consulta do lote.
        $pares = [];
        foreach ($q->limit(1500)->get(['codigo_bairro', 'quadra', 'lote']) as $l) {
            $cod = ltrim((string) $l->codigo_bairro, '0');
            if (isset($porCodigo[$cod])) {
                $pares[] = [$porCodigo[$cod], ltrim((string) $l->quadra, '0'), ltrim((string) $l->lote, '0')];
            }
        }

        return $pares;
    }

    /**
     * "Rua, número" de cada lote listado, lido do cadastro.
     *
     * @param  \Illuminate\Support\Collection<int,Lote>  $lotes
     * @return array<int,string> id do lote => endereço
     */
    private function enderecosDe($lotes): array
    {
        if ($lotes->isEmpty()) {
            return [];
        }
        $codigos = $this->bairros()->codigos();
        $chaves = [];   // "codigo|quadra|lote" => id do lote
        foreach ($lotes as $l) {
            $cod = $codigos[BairrosDoDesenho::chave($l->bairro)] ?? null;
            if ($cod !== null && $l->quadra !== null && $l->numero_lote !== null) {
                $chaves[ltrim((string) $cod, '0') . '|' . ltrim((string) $l->quadra, '0') . '|' . ltrim((string) $l->numero_lote, '0')] = $l->id;
            }
        }
        if (! $chaves) {
            return [];
        }

        $saida = [];
        $linhas = DB::table('cadastro_externo_imoveis')
            ->whereIn(DB::raw("CONCAT(TRIM(LEADING '0' FROM codigo_bairro), '|', TRIM(LEADING '0' FROM quadra), '|', TRIM(LEADING '0' FROM lote))"), array_keys($chaves))
            ->whereNotNull('logradouro')
            ->get(['codigo_bairro', 'quadra', 'lote', 'logradouro', 'numero_predial']);
        foreach ($linhas as $c) {
            $k = ltrim((string) $c->codigo_bairro, '0') . '|' . ltrim((string) $c->quadra, '0') . '|' . ltrim((string) $c->lote, '0');
            if (isset($chaves[$k]) && ! isset($saida[$chaves[$k]])) {
                $saida[$chaves[$k]] = trim($c->logradouro . ($c->numero_predial ? ', ' . ltrim((string) $c->numero_predial, '0') : ''));
            }
        }

        return $saida;
    }

    /**
     * A caixa [sul, oeste, norte, leste] que envolve os lotes achados, para o
     * mapa enquadrar o resultado. Pelo primeiro vértice de cada lote — em SRID
     * 4326 o MySQL guarda lat/long, então ST_X devolve a LATITUDE.
     *
     * @param  array<int,int>  $ids
     * @return array<int,float>|null
     */
    private function caixa(array $ids): ?array
    {
        if (! $ids) {
            return null;
        }
        $c = DB::selectOne(
            'SELECT MIN(ST_X(ST_PointN(ST_ExteriorRing(geom), 1))) AS sul, MAX(ST_X(ST_PointN(ST_ExteriorRing(geom), 1))) AS norte,
                    MIN(ST_Y(ST_PointN(ST_ExteriorRing(geom), 1))) AS oeste, MAX(ST_Y(ST_PointN(ST_ExteriorRing(geom), 1))) AS leste
               FROM lotes WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'
        );

        return $c && $c->sul !== null ? [(float) $c->sul, (float) $c->oeste, (float) $c->norte, (float) $c->leste] : null;
    }
}
