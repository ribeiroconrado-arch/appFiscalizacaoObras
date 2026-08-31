<?php

namespace App\Services;

use App\Models\Lote;
use App\Models\Protocolo;
use App\Repositories\LoteRepository;
use App\Support\GeometriaPlana;
use Illuminate\Support\Facades\DB;

/**
 * Junta dois ou mais lotes num só — remembramento.
 *
 * Só a partir de protocolo de unificação DEFERIDO: o ato executa uma decisão
 * administrativa, e o deferimento é onde a motivação foi escrita.
 *
 * A prova que define a operação não é burocrática, é física: os lotes têm de
 * se ENCOSTAR. Dois terrenos separados por uma rua não formam um imóvel, e a
 * própria coluna `geom POLYGON` recusaria o resultado — a união de polígonos
 * disjuntos é um MULTIPOLYGON.
 */
class UnificacaoDeLotes
{
    /**
     * Folga para dois lotes serem considerados vizinhos, em metros.
     *
     * 10 cm, e não o 1,0 m que QuadraDoQuarteirao usa. Os números divergem
     * porque as perguntas divergem: lá se pergunta "estes lotes estão no mesmo
     * quarteirão?", e a rua tem 15 m, então 1 m é folga segura. Aqui se
     * pergunta "estes dois terrenos se encostam?", e 1 m aceitaria unificar
     * lotes com uma passagem de pedestres entre eles. 10 cm é a incerteza
     * posicional de um vértice digitalizado.
     */
    private const ADJACENCIA_M = 0.10;

    public function __construct(
        private LoteRepository $lotes,
        private SucessaoDeLotes $sucessao,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array<string,mixed>
     */
    public function retrato(array $ids): array
    {
        $lotes = $this->lotes($ids);
        $u = count($ids) >= 2 ? $this->lotes->uniao($ids) : null;

        $vinculos = [
            'documentos' => DB::table('documentos')->whereIn('lote_id', $ids)->count(),
            'vistorias'  => DB::table('vistorias')->whereIn('lote_id', $ids)->count(),
            'obras'      => DB::table('obras')->whereIn('lote_id', $ids)->count(),
        ];

        return [
            'lotes'        => $lotes->map(fn ($l) => [
                'id' => $l->id, 'quadra' => $l->quadra, 'lote' => $l->numero_lote,
                'area' => (float) $l->area_gis_m2,
            ])->values()->all(),
            'soma_area'    => round($lotes->sum(fn ($l) => (float) $l->area_gis_m2), 2),
            'area_uniao'   => $u ? round($u['area_m2'], 2) : null,
            'tipo_uniao'   => $u['tipo'] ?? null,
            'bairro'       => $lotes->first()->bairro ?? null,
            'quadra'       => $lotes->first()->quadra ?? null,
            'vinculos'     => $vinculos,
            'sugestao_lote' => $lotes->sortBy(fn ($l) => (int) $l->numero_lote)->first()->numero_lote ?? null,
        ];
    }

    /**
     * @param  list<int>  $ids
     */
    public function impedimento(?Protocolo $protocolo, array $ids, ?string $numeroLote, bool $direto = false): ?string
    {
        if ($erro = $this->sucessao->impedimentoDoProtocolo($protocolo, 'unificacao', $direto)) {
            return $erro;
        }

        $ids = array_values(array_unique($ids));
        if (count($ids) < 2) {
            return 'Selecione ao menos dois lotes para unificar.';
        }

        $lotes = $this->lotes($ids);
        if ($lotes->count() !== count($ids)) {
            return 'Algum lote da seleção não existe ou já foi baixado. Recarregue o mapa.';
        }

        if ($lotes->pluck('bairro')->unique()->count() > 1) {
            return 'A seleção mistura bairros.';
        }

        // Unificar através de quadra é remembramento COM mudança de quadra: a
        // identificação nova teria de sair arbitrariamente de uma das duas.
        // Recusar e mandar corrigir a quadra antes, pela correção em massa —
        // que existe justamente para isso.
        $quadras = $lotes->pluck('quadra')->unique();
        if ($quadras->count() > 1) {
            return 'A seleção mistura as quadras ' . $quadras->implode(' e ')
                . '. Unifique dentro de uma quadra; se a quadra de algum lote está errada, '
                . 'corrija-a antes pela seleção em massa.';
        }

        if (! $numeroLote) {
            return 'Informe o número do lote resultante.';
        }

        // ── a prova física ──
        if ($solto = $this->desencostado($ids)) {
            return "O lote {$solto} não encosta nos demais. Unificação exige terrenos contíguos.";
        }

        $u = $this->lotes->uniao($ids);
        if (! $u) {
            return 'Não foi possível calcular a união dos lotes.';
        }
        if ($u['tipo'] !== 'POLYGON') {
            return 'A união dos lotes não formou uma figura única — ela saiu como '
                . strtolower($u['tipo']) . '. Confira se todos se encostam de fato.';
        }
        if ($u['furos'] > 0) {
            return 'A união deixou um vazio no meio. Falta selecionar o lote que está '
                . 'cercado pelos outros.';
        }

        // ── a área fecha? ──
        //
        // Os dois lados medidos pela MESMA régua (GeometriaPlana), nunca
        // misturando com o ST_Area: réguas diferentes produzem 0,25% de
        // diferença fantasma, que num lote de 360 m² já consome metade da
        // tolerância sem nada ter acontecido.
        $conta = $this->conferirArea($ids, $u['geojson']);
        if ($conta['diferenca'] > $conta['tolerado']) {
            return sprintf('A união dá %s m², mas os lotes somam %s m² — diferença de %s m², '
                . 'acima dos %s m² tolerados. Provável sobreposição entre eles.',
                number_format($conta['uniao'], 2, ',', '.'),
                number_format($conta['soma'], 2, ',', '.'),
                number_format($conta['diferenca'], 2, ',', '.'),
                number_format($conta['tolerado'], 2, ',', '.'));
        }

        // ── a identificação nova está livre? ──
        //
        // Descontando os que serão baixados: unificar 05 e 06 e chamar o
        // resultado de "05" é a prática, e só funciona porque a baixa e a
        // criação acontecem na MESMA transação e o índice único ignora baixado.
        $choque = DB::table('lotes')->where('bairro', $lotes->first()->bairro)
            ->where('quadra', $lotes->first()->quadra)
            ->where('numero_lote', $numeroLote)
            ->where('situacao', 'ativo')->whereNotIn('id', $ids)->exists();

        if ($choque) {
            return "A quadra {$lotes->first()->quadra} já tem outro lote {$numeroLote} ativo.";
        }

        return null;
    }

    /** @param list<int> $ids @return list<string> */
    public function avisos(array $ids): array
    {
        $avisos = [];
        $r = $this->retrato($ids)['vinculos'];

        // Aviso, NUNCA impedimento: é a razão de os lotes não serem excluídos.
        // O histórico continua pendurado no lote baixado, e a ficha do
        // sucessor mostra de onde ele veio.
        $partes = [];
        foreach (['documentos' => 'documento', 'vistorias' => 'vistoria', 'obras' => 'obra'] as $k => $rot) {
            if ($r[$k]) { $partes[] = $r[$k] . ' ' . $rot . ($r[$k] > 1 ? 's' : ''); }
        }
        if ($partes) {
            $avisos[] = 'Os lotes a baixar têm ' . implode(', ', $partes)
                . '. Nada disso é apagado: continua no imóvel de origem, e a ficha do '
                . 'lote novo mostra a procedência.';
        }

        return $avisos;
    }

    /**
     * Executa. Devolve o lote resultante.
     *
     * @param  list<int>  $ids
     */
    public function aplicar(?Protocolo $protocolo, array $ids, string $numeroLote, ?int $desmembramento = null, ?string $justificativa = null): Lote
    {
        $lotes = $this->lotes($ids);
        $primeiro = $lotes->first();
        $u = $this->lotes->uniao($ids);

        return DB::transaction(function () use ($protocolo, $ids, $numeroLote, $desmembramento, $primeiro, $u, $justificativa) {
            // A baixa vem ANTES da criação: o índice único só ignora quem já
            // está baixado, e o número do lote novo costuma ser o de um dos
            // antigos. Fora de uma transação isto seria uma janela de
            // inconsistência; dentro dela, ninguém vê o estado intermediário.
            $atributos = [
                'bairro'         => $primeiro->bairro,
                'quadra'         => $primeiro->quadra,
                'numero_lote'    => $numeroLote,
                'desmembramento' => $desmembramento ?? 0,
                'chave'          => $primeiro->bairro . '|' . $primeiro->quadra . '|' . $numeroLote,
                'area_gis_m2'    => round($u['area_m2'], 2),
                'fonte'          => $protocolo
                    ? 'Unificação — protocolo ' . $protocolo->numero
                    : 'Unificação direta — sem protocolo',
                'origem'         => 'unificacao',
                'situacao'       => 'ativo',
            ];

            foreach (Lote::whereIn('id', $ids)->get() as $lote) {
                $lote->update(['situacao' => 'baixado', 'baixado_em' => now()]);
            }

            $id = $this->lotes->criarComGeometria($atributos, $u['geojson']);
            $novo = Lote::find($id);
            $novo->registrarAuditoria('unificou', null, $atributos);

            $this->sucessao->registrar('unificacao', $ids, [$id], $protocolo, null, $justificativa);

            return $novo;
        });
    }

    /**
     * O primeiro lote que não encosta em nenhum outro da seleção, ou null.
     *
     * Union-find sobre a adjacência: não basta cada um encostar em ALGUM, o
     * conjunto todo tem de formar um bloco só. Três lotes em que A toca B e C
     * toca D não formam um imóvel.
     *
     * @param  list<int>  $ids
     */
    private function desencostado(array $ids): ?string
    {
        $lista = implode(',', array_map('intval', $ids));
        $pares = DB::select(
            'SELECT a.id a, b.id b FROM lotes a JOIN lotes b ON b.id > a.id
              WHERE a.id IN (' . $lista . ') AND b.id IN (' . $lista . ')
                AND MBRIntersects(a.geom, b.geom)
                AND ST_Distance(a.geom, b.geom) <= ?',
            [self::ADJACENCIA_M]
        );

        $pai = [];
        $raiz = function (int $x) use (&$pai, &$raiz): int {
            while (($pai[$x] ?? $x) !== $x) { $pai[$x] = $pai[$pai[$x]] ?? $pai[$x]; $x = $pai[$x]; }
            return $x;
        };
        foreach ($pares as $p) {
            $ra = $raiz($p->a); $rb = $raiz($p->b);
            if ($ra !== $rb) { $pai[$ra] = $rb; }
        }

        $blocos = [];
        foreach ($ids as $id) { $blocos[$raiz($id)][] = $id; }
        if (count($blocos) === 1) {
            return null;
        }

        // Nomeia o lote do menor bloco: é o que a pessoa provavelmente clicou
        // por engano.
        usort($blocos, fn ($x, $y) => count($x) <=> count($y));
        $orfao = $blocos[0][0];
        $l = Lote::find($orfao);

        return $l ? $l->numero_lote : (string) $orfao;
    }

    /**
     * Compara a área da união com a soma das partes, tudo medido por
     * GeometriaPlana.
     *
     * @param  list<int>  $ids
     * @return array{uniao:float, soma:float, diferenca:float, tolerado:float}
     */
    private function conferirArea(array $ids, string $geojsonUniao): array
    {
        $ref = null;
        $soma = 0.0;

        foreach ($ids as $id) {
            $anel = $this->lotes->anel($id);
            if (! $anel) { continue; }
            $ref ??= [$anel[0][1], $anel[0][0]];
            $soma += GeometriaPlana::area(GeometriaPlana::projetar($anel, $ref[0], $ref[1]));
        }

        $gu = json_decode($geojsonUniao, true);
        $anelU = $gu['coordinates'][0] ?? [];
        $uniao = $anelU && $ref
            ? GeometriaPlana::area(GeometriaPlana::projetar($anelU, $ref[0], $ref[1]))
            : 0.0;

        // O erro de digitalização é posicional por vértice, então o erro de
        // área que ele produz acompanha o PERÍMETRO, não a área. Tolerância
        // percentual erraria nas duas pontas: 0,5% de 2 ha são 100 m², um lote
        // inteiro. A raiz da área acompanha o perímetro em formas compactas,
        // que é o caso do lote urbano.
        $tolerado = max(
            (float) config('gis.sobreposicao_tolerada_m2', 0.5),
            0.10 * sqrt(max($soma, 1))
        );

        return [
            'uniao'     => $uniao,
            'soma'      => $soma,
            'diferenca' => abs($uniao - $soma),
            'tolerado'  => $tolerado,
        ];
    }

    /** @param list<int> $ids */
    private function lotes(array $ids): \Illuminate\Support\Collection
    {
        return DB::table('lotes')->whereIn('id', $ids)->where('situacao', 'ativo')
            ->orderByRaw('CAST(numero_lote AS UNSIGNED)')
            ->get(['id', 'bairro', 'quadra', 'numero_lote', 'area_gis_m2']);
    }
}
