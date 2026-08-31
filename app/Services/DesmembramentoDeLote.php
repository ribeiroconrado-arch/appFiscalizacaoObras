<?php

namespace App\Services;

use App\Models\Lote;
use App\Models\Protocolo;
use App\Repositories\LoteRepository;
use App\Support\GeometriaPlana;
use Illuminate\Support\Facades\DB;

/**
 * Divide um lote em duas ou mais partes.
 *
 * Só a partir de protocolo de desmembramento DEFERIDO e com vistoria REGULAR:
 * o deferimento diz que o pedido procede no papel, a vistoria diz que o papel
 * bate com o chão.
 *
 * ── Como as partes chegam aqui ──
 *
 * O operador desenha N−1 partes e a ÚLTIMA é derivada: `pai menos as
 * desenhadas`. Assim `∪ partes = pai` por construção, exato, sem depender de
 * tolerância — em vez de exigir que ele encaixe N polígonos com precisão de
 * centímetro. Quem quiser controlar todas as divisas desenha as N e desliga a
 * derivação; aí valem as três provas geométricas abaixo por inteiro.
 *
 * ── Onde cada medida é feita, e por quê ──
 *
 * Nenhuma medida de área sai do MySQL. Em SRID 4326 ele erra: o
 * `ST_Intersection` reportou 215,5 m² de área comum entre dois lotes de
 * 214,47 m² que não se sobrepõem, e o `ST_Union` devolve geometria certa com
 * área errada. Quem mede é App\Support\GeometriaPlana, conferida contra o
 * shapely (divergência máxima de 0,00005 m² em 60 lotes).
 *
 * Do banco vem só o que ele acerta: a validade de um polígono, a área de UM
 * polígono isolado — que alimenta `area_gis_m2`, para o lote novo ficar na
 * mesma régua dos 2.239 importados — e o `ST_Difference`, que foi conferido
 * (o complemento fechou exato: 663,58 + 748,50 = 1.412,08).
 *
 * A ressalva do ST_Difference: os operandos têm de vir de FONTE PRIMÁRIA — a
 * coluna ou o desenho do usuário. Subtrair um polígono que é ele próprio saída
 * de outra operação booleana devolve resultado errado, e silenciosamente. Por
 * isso o resultado da derivação é sempre CONFERIDO pela medida planar antes de
 * virar lote.
 */
class DesmembramentoDeLote
{
    public function __construct(
        private LoteRepository $lotes,
        private SucessaoDeLotes $sucessao,
    ) {}

    /**
     * Folga de área, em m².
     *
     * O erro de digitalização é posicional por vértice, então o erro de área
     * que ele produz acompanha o PERÍMETRO, não a área. Tolerância percentual
     * erraria nas duas pontas: 0,5% de 2 ha são 100 m², um lote inteiro. A
     * raiz da área acompanha o perímetro em formas compactas, que é o caso do
     * lote urbano — num lote de 360 m² dá 1,90 m²; num de 10.000, dá 10 m².
     */
    private function tolerado(float $areaReferencia): float
    {
        return max(
            (float) config('gis.sobreposicao_tolerada_m2', 0.5),
            0.10 * sqrt(max($areaReferencia, 1))
        );
    }

    /**
     * @param  list<array<string,mixed>>  $partes  cada uma com numero_lote, desmembramento, geometry
     * @return array<string,mixed>
     */
    public function retrato(Lote $pai, array $partes, bool $derivarUltima = true): array
    {
        $medidas = $this->medir($pai, $partes, $derivarUltima);

        return [
            'pai' => [
                'id' => $pai->id, 'quadra' => $pai->quadra, 'lote' => $pai->numero_lote,
                'area' => (float) $pai->area_gis_m2,
            ],
            'area_pai'   => round($medidas['areaPai'], 2),
            'partes'     => array_map(fn ($p) => [
                'numero_lote'    => $p['numero_lote'] ?? null,
                'desmembramento' => $p['desmembramento'] ?? null,
                'area'           => round($p['area'], 2),
                'derivada'       => $p['derivada'] ?? false,
            ], $medidas['partes']),
            'soma'       => round($medidas['soma'], 2),
            'sobra'      => round($medidas['sobra'], 2),
            'tolerado'   => round($this->tolerado($medidas['areaPai']), 2),
            'vinculos'   => [
                'documentos' => DB::table('documentos')->where('lote_id', $pai->id)->count(),
                'vistorias'  => DB::table('vistorias')->where('lote_id', $pai->id)->count(),
                'obras'      => DB::table('obras')->where('lote_id', $pai->id)->count(),
            ],
            'sugestao_desmembramento' => $this->proximoSufixo($pai),
        ];
    }

    /**
     * Devolve a mensagem do impedimento, ou null.
     *
     * @param  list<array<string,mixed>>  $partes
     */
    public function impedimento(?Protocolo $protocolo, Lote $pai, array $partes, bool $derivarUltima = true, bool $direto = false): ?string
    {
        if ($erro = $this->sucessao->impedimentoDoProtocolo($protocolo, 'desmembramento', $direto)) {
            return $erro;
        }

        if ($pai->situacao !== 'ativo') {
            return 'Este lote já foi baixado. Desmembre o sucessor dele.';
        }

        $minimo = $derivarUltima ? 1 : 2;
        if (count($partes) < $minimo) {
            return $derivarUltima
                ? 'Desenhe ao menos uma parte: a outra sai do que sobrar do lote.'
                : 'Desenhe ao menos duas partes.';
        }

        // ── formato de cada parte desenhada ──
        foreach ($partes as $i => $p) {
            if ($erro = $this->impedimentoDaParte($p, $i + 1)) {
                return $erro;
            }
        }

        $medidas = $this->medir($pai, $partes, $derivarUltima);

        if ($medidas['erro']) {
            return $medidas['erro'];
        }

        $tol = $this->tolerado($medidas['areaPai']);

        // ── A. cada parte cabe dentro do pai ──
        //
        // A prova é por ÁREA, não por ST_Within: ele devolve falso por 1 mm de
        // vazamento num vértice arredondado, e sem ST_Buffer não há como dar
        // folga na geometria. Medir quanto vaza é o substituto exato do buffer
        // que o MySQL não tem.
        foreach ($medidas['partes'] as $i => $p) {
            if ($p['vaza'] > $tol) {
                return sprintf('A parte %d passa %s m² para fora do lote. '
                    . 'Redesenhe dentro dos limites dele.',
                    $i + 1, number_format($p['vaza'], 2, ',', '.'));
            }
        }

        // ── B. as partes não se sobrepõem ──
        foreach ($medidas['sobreposicoes'] as $s) {
            if ($s['area'] > $tol) {
                return sprintf('As partes %d e %d se sobrepõem em %s m². '
                    . 'Dois imóveis não ocupam o mesmo chão.',
                    $s['a'] + 1, $s['b'] + 1, number_format($s['area'], 2, ',', '.'));
            }
        }

        // ── C. as partes cobrem o lote inteiro ──
        if (abs($medidas['sobra']) > $tol) {
            return sprintf('As partes somam %s m², e o lote tem %s m² — %s de %s m². '
                . 'Tolerado: %s m².',
                number_format($medidas['soma'], 2, ',', '.'),
                number_format($medidas['areaPai'], 2, ',', '.'),
                $medidas['sobra'] > 0 ? 'faltam' : 'sobram',
                number_format(abs($medidas['sobra']), 2, ',', '.'),
                number_format($tol, 2, ',', '.'));
        }

        // ── identificação de cada parte ──
        $numeros = [];
        foreach ($medidas['partes'] as $i => $p) {
            $n = $p['numero_lote'] ?? null;
            if (! $n) {
                return sprintf('Informe o número da parte %d.', $i + 1);
            }
            if (isset($numeros[$n])) {
                return "Duas partes com o mesmo número de lote ({$n}).";
            }
            $numeros[$n] = true;
        }

        // Descontando o próprio pai, que vai ser baixado no mesmo ato.
        $choque = DB::table('lotes')->where('bairro', $pai->bairro)
            ->where('quadra', $pai->quadra)->whereIn('numero_lote', array_keys($numeros))
            ->where('situacao', 'ativo')->where('id', '<>', $pai->id)->pluck('numero_lote');

        if ($choque->isNotEmpty()) {
            return "A quadra {$pai->quadra} já tem o(s) lote(s) " . $choque->implode(', ') . '.';
        }

        return null;
    }

    /** @param list<array<string,mixed>> $partes @return list<string> */
    public function avisos(Lote $pai, array $partes, bool $derivarUltima = true): array
    {
        $avisos = [];
        $medidas = $this->medir($pai, $partes, $derivarUltima);

        // Nada é apagado — é a razão de o pai não ser excluído.
        $r = [
            'documento' => DB::table('documentos')->where('lote_id', $pai->id)->count(),
            'vistoria'  => DB::table('vistorias')->where('lote_id', $pai->id)->count(),
            'obra'      => DB::table('obras')->where('lote_id', $pai->id)->count(),
        ];
        $partesTexto = [];
        foreach ($r as $rot => $n) {
            if ($n) { $partesTexto[] = "{$n} {$rot}" . ($n > 1 ? 's' : ''); }
        }
        if ($partesTexto) {
            $avisos[] = 'O lote a baixar tem ' . implode(', ', $partesTexto)
                . '. Nada disso é apagado: continua no imóvel de origem, e a ficha de '
                . 'cada parte mostra a procedência.';
        }

        foreach ($medidas['partes'] as $i => $p) {
            if ($p['area'] < (float) config('gis.lote_area_min_m2', 20)) {
                $avisos[] = sprintf('A parte %d tem só %s m².', $i + 1,
                    number_format($p['area'], 2, ',', '.'));
            }
            if (($p['numero_lote'] ?? null) === $pai->numero_lote) {
                $avisos[] = sprintf('A parte %d mantém o número %s do lote de origem. '
                    . 'Na prática municipal as partes recebem sufixo (%sA, %sB).',
                    $i + 1, $pai->numero_lote, $pai->numero_lote, $pai->numero_lote);
            }
        }

        return $avisos;
    }

    /**
     * Executa. Devolve os lotes criados.
     *
     * @param  list<array<string,mixed>>  $partes
     * @return list<Lote>
     */
    public function aplicar(
        ?Protocolo $protocolo,
        Lote $pai,
        array $partes,
        bool $derivarUltima = true,
        ?string $modo = null,
        ?string $justificativa = null,
    ): array {
        $medidas = $this->medir($pai, $partes, $derivarUltima);

        return DB::transaction(function () use ($protocolo, $pai, $medidas, $modo, $justificativa) {
            // A baixa vem ANTES da criação: o índice único de identificação só
            // ignora quem já está baixado, e uma das partes pode legitimamente
            // herdar o número do pai.
            $pai->update(['situacao' => 'baixado', 'baixado_em' => now()]);

            $criados = [];
            foreach ($medidas['partes'] as $p) {
                $atributos = [
                    'bairro'         => $pai->bairro,
                    'quadra'         => $pai->quadra,
                    'numero_lote'    => $p['numero_lote'],
                    'desmembramento' => (int) ($p['desmembramento'] ?? 0),
                    'chave'          => $pai->bairro . '|' . $pai->quadra . '|' . $p['numero_lote'],
                    // Área pelo BANCO, para ficar na mesma régua dos lotes importados.
                    'area_gis_m2'    => round($this->lotes->areaDoGeoJson($p['geojson']), 2),
                    'fonte'          => $protocolo
                        ? 'Desmembramento — protocolo ' . $protocolo->numero
                        : 'Desmembramento direto — sem protocolo',
                    'origem'         => 'desmembramento',
                    'situacao'       => 'ativo',
                ];

                $id = $this->lotes->criarComGeometria($atributos, $p['geojson']);
                $novo = Lote::find($id);
                // O INSERT é cru (geom é NOT NULL e só se escreve por expressão
                // SQL), então não dispara o evento do Eloquent.
                $novo->registrarAuditoria('desmembrou', null, $atributos);
                $criados[] = $novo;
            }

            $this->sucessao->registrar(
                'desmembramento', [$pai->id], array_map(fn ($l) => $l->id, $criados),
                $protocolo, $modo, $justificativa
            );

            return $criados;
        });
    }

    // ── medida ───────────────────────────────────────────────────

    /**
     * Mede tudo de uma vez: área do pai, de cada parte, o quanto cada uma
     * vaza, as sobreposições entre elas e a sobra.
     *
     * Todas as medidas saem de GeometriaPlana, na MESMA origem local — misturar
     * réguas produziria 0,25% de diferença fantasma, que num lote de 360 m² já
     * consome metade da tolerância sem nada ter acontecido.
     *
     * @param  list<array<string,mixed>>  $partes
     * @return array<string,mixed>
     */
    private function medir(Lote $pai, array $partes, bool $derivarUltima): array
    {
        $anelPai = $this->lotes->anel($pai->id) ?? [];
        if (! $anelPai) {
            return ['erro' => 'O lote de origem não tem geometria.', 'partes' => [],
                    'areaPai' => 0.0, 'soma' => 0.0, 'sobra' => 0.0, 'sobreposicoes' => []];
        }

        $lat = $anelPai[0][1];
        $lon = $anelPai[0][0];
        $proj = fn (array $anel) => GeometriaPlana::projetar($anel, $lat, $lon);
        $planoPai = $proj($anelPai);
        $areaPai = GeometriaPlana::area($planoPai);

        $lista = [];
        foreach ($partes as $p) {
            $anel = $p['geometry']['coordinates'][0] ?? [];
            if (! $anel) { continue; }
            $lista[] = [
                'numero_lote'    => $p['numero_lote'] ?? null,
                'desmembramento' => $p['desmembramento'] ?? null,
                'geojson'        => json_encode($p['geometry']),
                'anel'           => $anel,
                'derivada'       => false,
            ];
        }

        $erro = null;

        // ── a última parte, derivada do que sobrou ──
        if ($derivarUltima) {
            $resto = $this->lotes->diferenca($pai->id, array_column($lista, 'geojson'));

            if (! $resto) {
                $erro = 'As partes desenhadas cobrem o lote inteiro: não sobra nada para a última parte.';
            } elseif ($resto['tipo'] !== 'POLYGON') {
                $erro = 'O que sobrou do lote ficou partido em pedaços separados. '
                    . 'Desenhe cada parte explicitamente, sem derivar a última.';
            } elseif ($resto['furos'] > 0) {
                $erro = 'O que sobrou do lote tem um vazio no meio.';
            } else {
                $ultima = $partes[count($partes) - 1] ?? [];
                $lista[] = [
                    // O número da última parte vem do formulário como um item a
                    // mais, sem geometria — é a única que o operador não desenha.
                    'numero_lote'    => $ultima['numero_lote_derivada'] ?? null,
                    'desmembramento' => $ultima['desmembramento_derivada'] ?? null,
                    'geojson'        => $resto['geojson'],
                    'anel'           => json_decode($resto['geojson'], true)['coordinates'][0],
                    'derivada'       => true,
                ];
            }
        }

        $soma = 0.0;
        foreach ($lista as $i => $p) {
            $plano = $proj($p['anel']);
            $lista[$i]['plano'] = $plano;
            $lista[$i]['area'] = GeometriaPlana::area($plano);
            // Quanto da parte fica FORA do pai: área da parte menos a área
            // comum com o pai.
            $comum = GeometriaPlana::areaComum($plano, $planoPai);
            $lista[$i]['vaza'] = max(0.0, $lista[$i]['area'] - $comum['area']);
            $soma += $lista[$i]['area'];
        }

        $sobreposicoes = [];
        for ($i = 0; $i < count($lista); $i++) {
            for ($j = $i + 1; $j < count($lista); $j++) {
                $r = GeometriaPlana::areaComum($lista[$i]['plano'], $lista[$j]['plano']);
                if ($r['area'] > 0) {
                    $sobreposicoes[] = ['a' => $i, 'b' => $j, 'area' => $r['area']];
                }
            }
        }

        return [
            'erro'          => $erro,
            'areaPai'       => $areaPai,
            'partes'        => $lista,
            'soma'          => $soma,
            'sobra'         => $areaPai - $soma,
            'sobreposicoes' => $sobreposicoes,
        ];
    }

    /** Provas de formato de uma parte desenhada. */
    private function impedimentoDaParte(array $p, int $n): ?string
    {
        $g = $p['geometry'] ?? null;

        if (! is_array($g) || ($g['type'] ?? null) !== 'Polygon') {
            return "A parte {$n} não é um polígono.";
        }
        if (count($g['coordinates'] ?? []) > 1) {
            return "A parte {$n} tem um vazio interno. Lote não tem buraco.";
        }

        $anel = $g['coordinates'][0] ?? [];
        if (count($anel) < 4) {
            return "A parte {$n} tem menos de três cantos.";
        }

        $a = $anel[0];
        $z = $anel[count($anel) - 1];
        if (abs($a[0] - $z[0]) > 1e-9 || abs($a[1] - $z[1]) > 1e-9) {
            return "O contorno da parte {$n} não fechou.";
        }

        try {
            if (! $this->lotes->ehValido(json_encode($g))) {
                return "O contorno da parte {$n} se cruza.";
            }
        } catch (\Throwable $e) {
            return "O banco não aceitou o desenho da parte {$n}.";
        }

        return null;
    }

    /**
     * Próximo sufixo de desmembramento da inscrição (o último grupo de
     * 01.BBB.QQQ.LLLL.DDD). Continua a sequência quando o pai já é fruto de
     * um desmembramento anterior.
     */
    private function proximoSufixo(Lote $pai): int
    {
        $max = DB::table('lotes')->where('bairro', $pai->bairro)
            ->where('quadra', $pai->quadra)
            ->whereRaw('CAST(numero_lote AS UNSIGNED) = ?', [(int) $pai->numero_lote])
            ->max('desmembramento');

        return (int) $max + 1;
    }
}
