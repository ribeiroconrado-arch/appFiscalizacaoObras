<?php

namespace App\Services;

use App\Models\Lote;
use App\Models\Protocolo;
use App\Repositories\LoteRepository;
use App\Support\GeometriaPlana;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Institui a FRAÇÃO IDEAL de um terreno: ele continua ativo, e dentro dele
 * nascem as unidades — cada uma com inscrição própria (a do terreno com a
 * variação 001, 002…), fração ideal em m² e, quando há, a área de uso
 * exclusivo desenhada no chão.
 *
 * ── O que difere do desmembramento ──
 *
 *   terreno       NÃO é baixado: é o "terreno em condomínio", e é nele que
 *                 fica o que é da área comum;
 *   desenho       cada unidade desenha a SUA área exclusiva, e não precisa
 *                 cobrir o terreno: o que sobra é a área comum. Apartamento
 *                 não tem desenho nenhum;
 *   a conta       é a soma das FRAÇÕES que tem de fechar com o terreno, e não
 *                 a soma das áreas desenhadas.
 *
 * ── Casas × prédio ──
 *
 * Fica no terreno (`condominio`) porque decide a área da multa (ver a memória
 * de cálculo do documento): em unidades individuais vale a área individual,
 * e por isso cada casa TEM de ter a sua área desenhada; no prédio vale a obra
 * toda, e o desenho é opcional.
 *
 * As medidas saem de GeometriaPlana, na mesma origem local, pelas mesmas
 * razões do DesmembramentoDeLote (o MySQL em SRID 4326 erra área).
 */
class FracaoIdealDeLote
{
    public const TIPOS = ['casas' => 'Unidades individuais (casas)', 'predio' => 'Prédio (apartamentos)'];

    public function __construct(
        private LoteRepository $lotes,
        private SucessaoDeLotes $sucessao,
    ) {}

    /**
     * Prefixo da inscrição e as variações já ocupadas — por qualquer lote ativo
     * que use a mesma inscrição-base (unidades de antes, partes de um
     * desmembramento). A 000 é o próprio terreno, e continua sendo.
     *
     * @return array{inscricao:?string, prefixo:?string, base:?string, usados:list<int>, sugestao:list<int>}
     */
    public function identidade(Lote $terreno, int $quantas = 2): array
    {
        $inscricao = $terreno->inscricao();
        $prefixo = $inscricao ? substr($inscricao, 0, 12) : null;
        $usados = [0];
        if ($prefixo) {
            $familia = Lote::query()->where('situacao', 'ativo')->where(function ($q) use ($terreno, $prefixo) {
                $q->where(fn ($x) => $x->where('bairro', $terreno->bairro)->where('quadra', $terreno->quadra))
                  ->orWhereRaw("REPLACE(inscricao_imobiliaria, '.', '') LIKE ?", [$prefixo . '%']);
            })->get();
            foreach ($familia as $l) {
                $n = $l->inscricao();
                if ($n && substr($n, 0, 12) === $prefixo) {
                    $usados[] = (int) substr($n, 12);
                }
            }
        }
        $usados = array_values(array_unique($usados));
        $sugestao = [];
        for ($v = 1; $v <= 999 && count($sugestao) < $quantas; $v++) {
            if (! in_array($v, $usados, true)) $sugestao[] = $v;
        }

        return ['inscricao' => InscricaoImobiliaria::formatar($inscricao), 'prefixo' => $prefixo,
            'base' => $terreno->numero_lote, 'usados' => $usados, 'sugestao' => $sugestao];
    }

    /**
     * A área da matrícula do terreno, lida da tabela: a coluna fica fora de
     * Lote::COLUNAS, e pelo modelo viria sempre nula.
     */
    public static function areaMatricula(int $loteId): ?float
    {
        $v = DB::table('lotes')->where('id', $loteId)->value('area_matricula_m2');

        return $v !== null && (float) $v > 0 ? (float) $v : null;
    }

    /** O número com que a unidade aparece no mapa e nas listas: "20-001". */
    public static function numeroDaUnidade(?string $numeroTerreno, int $variacao): string
    {
        return ($numeroTerreno ?? '') . '-' . str_pad((string) $variacao, 3, '0', STR_PAD_LEFT);
    }

    /** Folga de área — a mesma régua do desmembramento (acompanha o perímetro). */
    private function tolerado(float $area): float
    {
        return max((float) config('gis.sobreposicao_tolerada_m2', 0.5), 0.10 * sqrt(max($area, 1)));
    }

    /**
     * Por que este lote não pode ser mexido como lote comum, ou null.
     *
     * Usado por quem altera o desenho por fora deste ato — desmembrar, unificar,
     * renumerar, trocar a quadra, apagar —, para que unidade e terreno não se
     * descolem um do outro em silêncio.
     *
     * @param  list<int>  $ids
     */
    public static function impedimentoDeCurar(array $ids, string $acao): ?string
    {
        $l = DB::table('lotes')->whereIn('id', $ids)->whereNotNull('terreno_id')->first(['quadra', 'numero_lote']);
        if ($l) {
            return "O lote {$l->numero_lote} da quadra {$l->quadra} é uma unidade de fração ideal. "
                . "Para {$acao}, desfaça antes as frações do terreno.";
        }
        $t = DB::table('lotes as t')->whereIn('t.id', $ids)
            ->whereExists(fn ($q) => $q->from('lotes as u')->whereColumn('u.terreno_id', 't.id')->where('u.situacao', 'ativo'))
            ->first(['t.quadra', 't.numero_lote']);
        if ($t) {
            return "O lote {$t->numero_lote} da quadra {$t->quadra} é um terreno em condomínio, com unidades de fração ideal. "
                . "Para {$acao}, desfaça antes as frações.";
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $unidades  variacao, fracao_ideal_m2, geometry (Polygon ou null)
     * @return array<string,mixed>
     */
    public function retrato(Lote $terreno, array $unidades, ?string $condominio = null): array
    {
        $m = $this->medir($terreno, $unidades);
        $id = $this->identidade($terreno, max(2, count($unidades)));

        return [
            'terreno' => ['id' => $terreno->id, 'quadra' => $terreno->quadra, 'lote' => $terreno->numero_lote,
                'inscricao' => $id['inscricao'], 'area_desenho' => round($m['areaTerreno'], 2),
                'area_matricula' => self::areaMatricula($terreno->id)],
            'referencia'   => round($m['referencia'], 2),
            'soma_fracoes' => round($m['somaFracoes'], 2),
            'sobra'        => round($m['referencia'] - $m['somaFracoes'], 2),
            'area_comum'   => round(max(0, $m['areaTerreno'] - $m['somaExclusivas']), 2),
            'tolerado'     => round($this->tolerado($m['referencia']), 2),
            'condominio'   => $condominio,
            'unidades'     => array_map(fn ($u) => [
                'variacao'        => $u['variacao'],
                'numero_lote'     => $u['numero_lote'],
                'inscricao'       => $u['inscricao'],
                'area_exclusiva'  => $u['area'] !== null ? round($u['area'], 2) : null,
                'fracao_ideal_m2' => $u['fracao'],
                'percentual'      => $m['referencia'] > 0 && $u['fracao'] !== null ? round($u['fracao'] / $m['referencia'] * 100, 2) : null,
            ], $m['unidades']),
            'identidade' => $id,
        ];
    }

    /** @param list<array<string,mixed>> $unidades */
    public function impedimento(?Protocolo $protocolo, Lote $terreno, array $unidades, ?string $condominio, bool $direto = false): ?string
    {
        if ($erro = $this->sucessao->impedimentoDoProtocolo($protocolo, 'fracao_ideal', $direto)) {
            return $erro;
        }
        if ($terreno->situacao !== 'ativo') {
            return 'Este lote está inativo. Institua as frações no lote que o sucedeu.';
        }
        if ($erro = self::impedimentoDeCurar([$terreno->id], 'instituir novas frações')) {
            return $erro;
        }
        if (! isset(self::TIPOS[$condominio ?? ''])) {
            return 'Diga se o condomínio é de unidades individuais (casas) ou um prédio: é isso que decide a área da multa.';
        }

        $id = $this->identidade($terreno, count($unidades));
        if (! $id['prefixo']) {
            return 'O terreno precisa de uma inscrição válida antes de instituir as frações.';
        }
        if (count($unidades) < 2) {
            return 'Fração ideal pede ao menos duas unidades.';
        }

        $usados = $id['usados'];
        foreach ($unidades as $i => $u) {
            $n = $i + 1;
            $v = $u['variacao'] ?? null;
            if (filter_var($v, FILTER_VALIDATE_INT) === false || $v < 1 || $v > 999) {
                return "Informe para a unidade {$n} uma variação de 001 a 999 (a 000 é o terreno).";
            }
            if (in_array((int) $v, $usados, true)) {
                return 'A variação ' . str_pad((string) $v, 3, '0', STR_PAD_LEFT) . ' já está em uso nesta inscrição.';
            }
            $usados[] = (int) $v;
            if (! is_numeric($u['fracao_ideal_m2'] ?? null) || (float) $u['fracao_ideal_m2'] <= 0) {
                return "Informe a fração ideal da unidade {$n}, em m².";
            }
            $g = $u['geometry'] ?? null;
            if ($g === null && $condominio === 'casas') {
                return "Desenhe a área individual da unidade {$n}: em unidades individuais é por ela que a multa por área é calculada.";
            }
            if ($g !== null && ($erro = $this->impedimentoDoDesenho($g, $n))) {
                return $erro;
            }
        }

        $m = $this->medir($terreno, $unidades);
        if ($m['erro']) {
            return $m['erro'];
        }
        $tol = $this->tolerado($m['referencia']);
        $fmt = fn (float $x) => number_format($x, 2, ',', '.');

        foreach ($m['unidades'] as $i => $u) {
            if ($u['vaza'] > $tol) {
                return sprintf('A área da unidade %d passa %s m² para fora do terreno. Redesenhe dentro dele.', $i + 1, $fmt($u['vaza']));
            }
        }
        foreach ($m['sobreposicoes'] as $s) {
            if ($s['area'] > $tol) {
                return sprintf('As áreas das unidades %d e %d se sobrepõem em %s m². Área de uso exclusivo é de uma só unidade.',
                    $s['a'] + 1, $s['b'] + 1, $fmt($s['area']));
            }
        }
        if (abs($m['referencia'] - $m['somaFracoes']) > $tol) {
            return sprintf('As frações somam %s m², e o terreno tem %s m²%s — %s %s m². Tolerado: %s m².',
                $fmt($m['somaFracoes']), $fmt($m['referencia']),
                self::areaMatricula($terreno->id) !== null ? ' na matrícula' : '',
                $m['somaFracoes'] < $m['referencia'] ? 'faltam' : 'sobram',
                $fmt(abs($m['referencia'] - $m['somaFracoes'])), $fmt($tol));
        }

        $numeros = array_column($m['unidades'], 'numero_lote');
        $choque = DB::table('lotes')->where('bairro', $terreno->bairro)->where('quadra', $terreno->quadra)
            ->whereIn('numero_lote', $numeros)->where('situacao', 'ativo')->pluck('numero_lote');
        if ($choque->isNotEmpty()) {
            return "A quadra {$terreno->quadra} já tem o(s) lote(s) " . $choque->implode(', ') . '.';
        }

        return null;
    }

    /** @param list<array<string,mixed>> $unidades @return list<string> */
    public function avisos(Lote $terreno, array $unidades, ?string $condominio): array
    {
        $avisos = [];
        $semDesenho = count(array_filter($unidades, fn ($u) => empty($u['geometry'])));
        if ($condominio === 'predio' && $semDesenho) {
            $avisos[] = "{$semDesenho} unidade(s) sem área desenhada: aparecem só na ficha do terreno, não no mapa.";
        }
        $docs = DB::table('documentos')->where('lote_id', $terreno->id)->count();
        if ($docs) {
            $avisos[] = "O terreno tem {$docs} documento(s). Eles continuam nele: são da área do terreno, e não passam para nenhuma unidade.";
        }

        return $avisos;
    }

    /**
     * Executa. Devolve as unidades criadas.
     *
     * @param  list<array<string,mixed>>  $unidades
     * @return list<Lote>
     */
    public function aplicar(?Protocolo $protocolo, Lote $terreno, array $unidades, string $condominio,
                            ?string $justificativa = null, ?array $visualizacao = null): array
    {
        return DB::transaction(function () use ($protocolo, $terreno, $unidades, $condominio, $justificativa, $visualizacao) {
            $terreno = Lote::whereKey($terreno->id)->lockForUpdate()->firstOrFail();
            if ($protocolo) {
                $protocolo = Protocolo::whereKey($protocolo->id)->lockForUpdate()->firstOrFail();
            }
            if ($erro = $this->impedimento($protocolo, $terreno, $unidades, $condominio, direto: $protocolo === null)) {
                throw ValidationException::withMessages(['unidades' => $erro]);
            }
            $m = $this->medir($terreno, $unidades);
            $geomTerreno = DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [$terreno->id]);

            $criadas = [];
            foreach ($m['unidades'] as $u) {
                $atributos = [
                    'terreno_id'            => $terreno->id,
                    'bairro'                => $terreno->bairro,
                    'quadra'                => $terreno->quadra,
                    'numero_lote'           => $u['numero_lote'],
                    'inscricao_imobiliaria' => $u['inscricao_pura'],
                    'desmembramento'        => $u['variacao'],
                    'chave'                 => $terreno->bairro . '|' . $terreno->quadra . '|' . $u['numero_lote'],
                    'area_gis_m2'           => $u['area'] !== null ? round($u['area'], 2) : null,
                    'fracao_ideal_m2'       => round((float) $u['fracao'], 2),
                    'unidade_desenhada'     => $u['geojson'] !== null,
                    'fonte'                 => $protocolo
                        ? 'Fração ideal — protocolo ' . $protocolo->numero
                        : 'Fração ideal direta — sem protocolo',
                    'origem'                => 'fracao_ideal',
                    'situacao'              => 'ativo',
                    'em_revisao'            => (bool) $terreno->em_revisao,
                    'importacao_id'         => $terreno->importacao_id,
                ];
                $id = $this->lotes->criarComGeometria($atributos, $u['geojson'] ?? $geomTerreno);
                $nova = Lote::find($id);
                $nova->registrarAuditoria('criou', null, $atributos);
                $criadas[] = $nova;
            }

            $terreno->update(['condominio' => $condominio]);

            $this->sucessao->registrar('fracao_ideal', [], array_map(fn ($l) => $l->id, $criadas),
                $protocolo, 'fracao', $justificativa, $visualizacao, referencias: [$terreno->id]);

            $terreno->registrarAuditoria('instituiu frações', null, [
                'unidades'   => count($criadas),
                'condominio' => self::TIPOS[$condominio],
                'numeros'    => implode(', ', array_map(fn ($l) => $l->numero_lote, $criadas)),
            ]);

            return $criadas;
        });
    }

    // ── medida ───────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $unidades @return array<string,mixed> */
    private function medir(Lote $terreno, array $unidades): array
    {
        $anel = $this->lotes->anel($terreno->id) ?? [];
        if (! $anel) {
            return ['erro' => 'O terreno não tem geometria.', 'unidades' => [], 'areaTerreno' => 0.0,
                    'referencia' => 0.0, 'somaFracoes' => 0.0, 'somaExclusivas' => 0.0, 'sobreposicoes' => []];
        }
        $lat = $anel[0][1];
        $lon = $anel[0][0];
        $plTerreno = GeometriaPlana::projetar($anel, $lat, $lon);
        $areaTerreno = GeometriaPlana::area($plTerreno);
        $referencia = self::areaMatricula($terreno->id) ?? $areaTerreno;
        $prefixo = substr($terreno->inscricao() ?? '', 0, 12);

        $lista = [];
        $somaF = 0.0;
        $somaE = 0.0;
        foreach ($unidades as $u) {
            $v = filter_var($u['variacao'] ?? null, FILTER_VALIDATE_INT);
            $v = $v === false ? null : $v;
            $g = $u['geometry'] ?? null;
            $item = [
                'variacao'       => $v,
                'numero_lote'    => $v !== null ? self::numeroDaUnidade($terreno->numero_lote, $v) : null,
                'inscricao_pura' => $prefixo && $v !== null ? $prefixo . str_pad((string) $v, 3, '0', STR_PAD_LEFT) : null,
                'fracao'         => is_numeric($u['fracao_ideal_m2'] ?? null) ? (float) $u['fracao_ideal_m2'] : null,
                'geojson'        => $g ? json_encode($g) : null,
                'area'           => null, 'vaza' => 0.0, 'plano' => null,
            ];
            $item['inscricao'] = InscricaoImobiliaria::formatar($item['inscricao_pura']);
            $a = $g['coordinates'][0] ?? null;
            if ($a) {
                $item['plano'] = GeometriaPlana::projetar($a, $lat, $lon);
                $item['area'] = GeometriaPlana::area($item['plano']);
                $item['vaza'] = max(0.0, $item['area'] - GeometriaPlana::areaComum($item['plano'], $plTerreno)['area']);
                $somaE += $item['area'];
            }
            $somaF += $item['fracao'] ?? 0.0;
            $lista[] = $item;
        }

        $sobre = [];
        for ($i = 0; $i < count($lista); $i++) {
            for ($j = $i + 1; $j < count($lista); $j++) {
                if (! $lista[$i]['plano'] || ! $lista[$j]['plano']) continue;
                $r = GeometriaPlana::areaComum($lista[$i]['plano'], $lista[$j]['plano']);
                if ($r['area'] > 0) $sobre[] = ['a' => $i, 'b' => $j, 'area' => $r['area']];
            }
        }

        return ['erro' => null, 'unidades' => $lista, 'areaTerreno' => $areaTerreno, 'referencia' => $referencia,
                'somaFracoes' => $somaF, 'somaExclusivas' => $somaE, 'sobreposicoes' => $sobre];
    }

    /** As provas de formato de uma área desenhada — as mesmas da parte do desmembramento. */
    private function impedimentoDoDesenho(mixed $g, int $n): ?string
    {
        if (! is_array($g) || ($g['type'] ?? null) !== 'Polygon') return "A área da unidade {$n} não é um polígono.";
        if (count($g['coordinates'] ?? []) > 1) return "A área da unidade {$n} tem um vazio interno.";
        $anel = $g['coordinates'][0] ?? [];
        if (count($anel) < 4) return "A área da unidade {$n} tem menos de três cantos.";
        $a = $anel[0];
        $z = $anel[count($anel) - 1];
        if (abs($a[0] - $z[0]) > 1e-9 || abs($a[1] - $z[1]) > 1e-9) return "O contorno da unidade {$n} não fechou.";
        try {
            if (! $this->lotes->ehValido(json_encode($g))) return "O contorno da unidade {$n} se cruza.";
        } catch (\Throwable) {
            return "O banco não aceitou o desenho da unidade {$n}.";
        }

        return null;
    }
}
