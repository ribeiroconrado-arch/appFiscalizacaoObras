<?php

namespace App\Services;

use App\Models\Lote;
use App\Repositories\LoteRepository;
use App\Support\GeometriaPlana;
use Illuminate\Support\Facades\DB;

/**
 * Cria no mapa um lote que a importação não trouxe.
 *
 * O extrator suprime lote em silêncio quando o desenho não coopera — foi assim
 * que a Quadra 05 inteira do Jardim Europa IV, um lote único de 12.008 m²,
 * simplesmente não veio. Até agora o único conserto era corrigir o DWG e
 * reimportar o bairro, o que só o operador do QGIS consegue fazer.
 *
 * As provas abaixo existem porque desenhar é a operação de MENOR atrito e MAIOR
 * alcance do sistema: quatro toques criam um imóvel novo no cadastro.
 *
 * Sobre onde cada medida é feita: a validade do polígono e a área de UM
 * polígono vêm do banco, que acerta as duas em SRID 4326. A SOBREPOSIÇÃO vem
 * de App\Support\GeometriaPlana, porque o `ST_Intersection` do MySQL erra em
 * coordenada geográfica — reportou 215,5 m² de área comum entre dois lotes de
 * 214,47 m² que sequer se sobrepõem.
 */
class DesenhoDeLote
{
    public function __construct(private LoteRepository $lotes) {}

    private function toleradoM2(): float
    {
        return (float) config('gis.sobreposicao_tolerada_m2', 0.5);
    }

    /**
     * ONDE o contorno se cruza, para a tela marcar (24/10/2026): o ST_IsValid
     * do banco só diz sim ou não. Em metros, num plano em volta do primeiro
     * vértice: lados não vizinhos que se tocam e ESPINHO (a linha volta pelo
     * mesmo caminho num vértice). Vértice repetido o banco aceita e não entra.
     * A mesma conta da prancheta (cruzamentosDe, prancheta-cadastral.js).
     *
     * @param  list<array{0:float,1:float}>  $anel  [lon, lat], fechado ou não
     * @return list<array{0:float,1:float}>  [lon, lat]
     */
    public static function pontosDoCruzamento(array $anel): array
    {
        if (count($anel) > 1 && $anel[0] == end($anel)) {
            array_pop($anel);
        }
        $n = count($anel);
        if ($n < 3) {
            return [];
        }
        [$lon0, $lat0] = $anel[0];
        $kx = 111320 * cos(deg2rad($lat0));
        $ky = 110574;
        $m = array_map(fn ($c) => [($c[0] - $lon0) * $kx, ($c[1] - $lat0) * $ky], $anel);
        $geo = fn ($p) => [round($lon0 + $p[0] / $kx, 10), round($lat0 + $p[1] / $ky, 10)];
        $saida = [];
        $marcar = function ($p) use (&$saida) {
            foreach ($saida as $q) { if (hypot($q[0] - $p[0], $q[1] - $p[1]) < 0.01) return; }
            $saida[] = $p;
        };
        $cruz = fn ($a, $b) => $a[0] * $b[1] - $a[1] * $b[0];
        for ($k = 0; $k < $n; $k++) {
            $a = $m[($k - 1 + $n) % $n]; $v = $m[$k]; $b = $m[($k + 1) % $n];
            $u = [$v[0] - $a[0], $v[1] - $a[1]]; $w = [$b[0] - $v[0], $b[1] - $v[1]];
            $lu = hypot(...$u); $lw = hypot(...$w);
            if ($lw < 0.001) continue;   // vértice repetido: o banco aceita
            if ($lu >= 0.001 && ($u[0] * $w[0] + $u[1] * $w[1]) < 0 && abs($cruz($u, $w)) < 0.01 * $lu * $lw) { $marcar($v); }
        }
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) continue;   // o último lado encosta no primeiro
                $a = $m[$i]; $b = $m[($i + 1) % $n]; $c = $m[$j]; $d = $m[($j + 1) % $n];
                $r = [$b[0] - $a[0], $b[1] - $a[1]]; $s = [$d[0] - $c[0], $d[1] - $c[1]];
                $den = $cruz($r, $s);
                if (abs($den) < 1e-12) continue;
                $ca = [$c[0] - $a[0], $c[1] - $a[1]];
                $t = $cruz($ca, $s) / $den; $q = $cruz($ca, $r) / $den;
                if ($t >= -1e-9 && $t <= 1 + 1e-9 && $q >= -1e-9 && $q <= 1 + 1e-9) {
                    $marcar([$a[0] + $r[0] * $t, $a[1] + $r[1] * $t]);
                }
            }
        }

        // SEM PONTO NO PLANO, A CONTA DO BANCO (24/10/2026, faixa estreita de
        // 24.662 m²): o ST_IsValid confere em coordenada geográfica, onde cada
        // lado é um ARCO sobre a Terra e não uma reta — dois lados longos que
        // passam a 1 cm um do outro no plano se cruzam para o banco. Aqui o
        // cruzamento é procurado entre os arcos, numa esfera.
        if (! $saida) {
            foreach (self::cruzamentosNaEsfera($anel) as $c) {
                $marcar([($c[0] - $lon0) * $kx, ($c[1] - $lat0) * $ky]);
            }
        }
        // Nem assim: o banco usa o elipsoide e uma tolerância própria (recusou
        // lados a 1 cm no plano). Os lugares onde dois lados passam MAIS PERTO,
        // até 5 cm, são o melhor palpite — os 5 mais próximos.
        if (! $saida) {
            $pertos = [];
            $pe = function ($p, $a, $b) {
                $ab = [$b[0] - $a[0], $b[1] - $a[1]]; $l2 = $ab[0] ** 2 + $ab[1] ** 2;
                $t = $l2 ? max(0, min(1, (($p[0] - $a[0]) * $ab[0] + ($p[1] - $a[1]) * $ab[1]) / $l2)) : 0;
                $q = [$a[0] + $ab[0] * $t, $a[1] + $ab[1] * $t];

                return [hypot($p[0] - $q[0], $p[1] - $q[1]), $q];
            };
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 2; $j < $n; $j++) {
                    if ($i === 0 && $j === $n - 1) continue;
                    $a = $m[$i]; $b = $m[($i + 1) % $n]; $c = $m[$j]; $d = $m[($j + 1) % $n];
                    $perto = null;
                    foreach ([[$a, $c, $d], [$b, $c, $d], [$c, $a, $b], [$d, $a, $b]] as [$p, $x, $y]) {
                        [$dist, $q] = $pe($p, $x, $y);
                        if ($perto === null || $dist < $perto[0]) $perto = [$dist, [($p[0] + $q[0]) / 2, ($p[1] + $q[1]) / 2]];
                    }
                    if ($perto[0] < 0.05) $pertos[] = $perto;
                }
            }
            usort($pertos, fn ($p, $q) => $p[0] <=> $q[0]);
            foreach (array_slice($pertos, 0, 5) as $p) $marcar($p[1]);
        }

        return array_map($geo, $saida);
    }

    /**
     * Cruzamento entre lados NÃO vizinhos tomados como arcos de círculo máximo
     * (o caminho mais curto sobre a esfera entre dois vértices), como o banco
     * os enxerga em SRID 4326.
     *
     * @param  list<array{0:float,1:float}>  $anel  [lon, lat], aberto
     * @return list<array{0:float,1:float}>  [lon, lat]
     */
    private static function cruzamentosNaEsfera(array $anel): array
    {
        $v = fn ($c) => [cos(deg2rad($c[1])) * cos(deg2rad($c[0])), cos(deg2rad($c[1])) * sin(deg2rad($c[0])), sin(deg2rad($c[1]))];
        $x = fn ($a, $b) => [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
        $d = fn ($a, $b) => $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
        $p = array_map($v, $anel);
        $n = count($p);
        $saida = [];
        // O ponto P está no arco AB (de normal N = A×B) se fica entre A e B.
        $noArco = fn ($P, $A, $B, $N) => $d($x($A, $P), $N) >= -1e-15 && $d($x($P, $B), $N) >= -1e-15;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) continue;
                [$A, $B, $C, $D] = [$p[$i], $p[($i + 1) % $n], $p[$j], $p[($j + 1) % $n]];
                $N1 = $x($A, $B); $N2 = $x($C, $D); $L = $x($N1, $N2);
                $len = sqrt($d($L, $L));
                if ($len < 1e-18) continue;
                foreach ([1, -1] as $sinal) {
                    $P = [$L[0] / $len * $sinal, $L[1] / $len * $sinal, $L[2] / $len * $sinal];
                    if ($noArco($P, $A, $B, $N1) && $noArco($P, $C, $D, $N2)) {
                        $saida[] = [rad2deg(atan2($P[1], $P[0])), rad2deg(asin(max(-1, min(1, $P[2]))))];
                    }
                }
            }
        }

        return $saida;
    }

    /**
     * O que a tela mostra antes de gravar.
     *
     * @return array<string,mixed>
     */
    public function retrato(array $d, ?int $ignorar = null): array
    {
        $geojson = json_encode($d['geometry']);
        $area = $this->lotes->areaDoGeoJson($geojson);

        return [
            'area_m2'   => round($area, 2),
            // Com 4 casas, para a prancheta: diferenças de 0,01 m² contra o
            // cadastro só se acham assim. Grava-se a de 2 casas.
            'area_m2_exata' => round($area, 4),
            'vertices'  => max(0, count($d['geometry']['coordinates'][0] ?? []) - 1),
            'bairro'    => $d['bairro'],
            'quadra'    => $d['quadra'],
            'lote'      => $d['numero_lote'],
            'vizinhos'  => $this->vizinhos($geojson, $d['bairro'], $ignorar),
            'divergencia' => $this->divergencia($d, $area),
        ];
    }

    /**
     * Quanto o desenho difere da área da matrícula, em %.
     *
     * A tela já mostra isso enquanto se digita; aqui a conta é refeita porque
     * conferência que só existe no navegador não é conferência — e porque é
     * este número que vai para a prévia que o operador lê antes de gravar.
     *
     * Null quando não há área de matrícula: sem os dois lados não há
     * divergência, e devolver zero diria que eles batem.
     *
     * @param  array<string,mixed>  $d
     */
    private function divergencia(array $d, float $areaDesenho): ?float
    {
        $mat = $d['area_matricula_m2'] ?? null;
        if (! $mat || $mat <= 0) {
            return null;
        }

        return round(($areaDesenho / (float) $mat - 1) * 100, 2);
    }

    /**
     * Devolve a mensagem do impedimento, ou null se pode gravar.
     *
     * A ordem importa: as provas baratas e as de formato vêm antes das
     * espaciais, para o usuário receber a mensagem mais específica possível em
     * vez da primeira que der errado.
     */
    /**
     * `$ignorar` é o lote que está sendo REDESENHADO (Editar lote, na
     * pré-curadoria): ele não pode acusar a si mesmo de ocupar o mesmo chão
     * nem de já ter o mesmo número. Ver EdicaoDeLote.
     */
    public function impedimento(array $d, ?int $ignorar = null): ?string
    {
        $g = $d['geometry'] ?? null;

        // ── formato, antes de tocar no banco ──
        if (! is_array($g) || ($g['type'] ?? null) !== 'Polygon') {
            return 'O desenho precisa ser um polígono simples.';
        }

        $anel = $g['coordinates'][0] ?? [];
        if (count($g['coordinates'] ?? []) > 1) {
            return 'O desenho tem um vazio interno. Lote não tem buraco: refaça o contorno.';
        }
        if (count($anel) < 4) {
            return 'O desenho tem menos de três cantos.';
        }

        $p = $anel[0];
        $u = $anel[count($anel) - 1];
        if (abs($p[0] - $u[0]) > 1e-9 || abs($p[1] - $u[1]) > 1e-9) {
            return 'O contorno não fechou.';
        }

        $geojson = json_encode($g);

        // ST_GeomFromGeoJSON lança exceção em documento malformado. Sem o
        // try/catch isso viraria erro 500 — e o que o usuário fez de errado
        // foi desenhar, não quebrar o sistema.
        try {
            $tipo = $this->lotes->tipoDoGeoJson($geojson);
        } catch (\Throwable $e) {
            return 'O banco não aceitou o desenho: coordenada fora de faixa ou documento inválido.';
        }

        if ($tipo !== 'POLYGON') {
            return "O desenho virou {$tipo} — era para ser um polígono só.";
        }
        if (! $this->lotes->ehValido($geojson)) {
            return 'O contorno se cruza. Refaça sem passar uma linha por cima da outra.';
        }

        // ── tamanho ──
        $area = $this->lotes->areaDoGeoJson($geojson);
        $min = (float) config('gis.lote_area_min_m2', 20);
        // Lote GRANDE não é erro: área institucional, comercial de quadra
        // inteira. Acima de `lote_area_max_m2` só AVISA (avisoDeArea); recusa
        // só no tamanho em que o desenho com certeza saiu errado (30/10/2026).
        $bloqueio = (float) config('gis.lote_area_bloqueio_m2', 1000000);

        if ($area < $min) {
            return sprintf('O desenho tem %s m². Abaixo de %s m² não é lote urbano — '
                . 'provavelmente foi um toque acidental.',
                number_format($area, 2, ',', '.'), number_format($min, 0, ',', '.'));
        }
        if ($area > $bloqueio) {
            return sprintf('O desenho tem %s m². Acima de %s m² o contorno saiu errado — '
                . 'confira se ele não pegou a quadra ou o bairro inteiro.',
                number_format($area, 2, ',', '.'), number_format($bloqueio, 0, ',', '.'));
        }

        // ── identificação livre ──
        //
        // Provado ANTES de o índice único estourar, para a mensagem dizer qual
        // lote já existe em vez de devolver uma QueryException crua.
        $existe = DB::table('lotes')->where('bairro', $d['bairro'])
            ->where('quadra', $d['quadra'])->where('numero_lote', $d['numero_lote'])
            ->where('situacao', 'ativo')
            ->when($ignorar, fn ($q) => $q->where('id', '<>', $ignorar))->exists();

        if ($existe) {
            return "A quadra {$d['quadra']} do {$d['bairro']} já tem o lote {$d['numero_lote']}.";
        }

        // ── está no lugar certo ──
        $dist = $this->lotes->distanciaAoBairro($geojson, $d['bairro']);
        if ($dist === null) {
            return "Não há nenhum lote de {$d['bairro']} na base para comparar. "
                . 'Bairro novo entra por importação, não por desenho.';
        }
        if ($dist > (float) config('gis.desenho_distancia_max_m', 50)) {
            return sprintf('O desenho está a %s m do lote mais próximo de %s. '
                . 'Confira se o bairro informado é esse mesmo.',
                number_format($dist, 0, ',', '.'), $d['bairro']);
        }

        // ── não invade lote existente ──
        $sobre = $this->vizinhos($geojson, $d['bairro'], $ignorar);
        $invadidos = array_filter($sobre, fn ($v) => $v['area_comum'] > $this->toleradoM2());

        if ($invadidos) {
            $primeiro = reset($invadidos);
            return sprintf('O desenho invade %s m² do lote Q%s Lt%s%s. '
                . 'Dois imóveis não ocupam o mesmo chão.',
                number_format($primeiro['area_comum'], 2, ',', '.'),
                $primeiro['quadra'], $primeiro['lote'],
                count($invadidos) > 1 ? ' (e mais ' . (count($invadidos) - 1) . ')' : '');
        }

        return null;
    }

    /** @return list<string> */
    public function avisos(array $d): array
    {
        $avisos = [];
        $geojson = json_encode($d['geometry']);

        $quadraExiste = DB::table('lotes')->where('bairro', $d['bairro'])
            ->where('quadra', $d['quadra'])->where('situacao', 'ativo')->exists();

        if (! $quadraExiste) {
            $avisos[] = "A quadra {$d['quadra']} ainda não existe em {$d['bairro']}: "
                . 'este será o primeiro lote dela.';
        }

        // Encostar nos vizinhos é o normal num quarteirão. Não encostar em
        // ninguém costuma significar desenho solto no meio da rua.
        $vizinhos = $this->vizinhos($geojson, $d['bairro']);
        if (! $vizinhos) {
            $avisos[] = 'O desenho não encosta em nenhum lote existente. '
                . 'Confira se ele não ficou no meio da via.';
        }
        if ($gleba = $this->avisoDeArea($d)) {
            $avisos[] = $gleba;
        }

        return $avisos;
    }

    /**
     * Lote acima de `lote_area_max_m2` (20.000 m²): tamanho de gleba. Grava,
     * mas pede confirmação — a não ser que a área do CADASTRO confirme o
     * tamanho (até 5% de diferença): aí o lote grande é o lote de verdade.
     *
     * @param  array<string,mixed>  $d  geometry, bairro, quadra, numero_lote
     */
    public function avisoDeArea(array $d): ?string
    {
        $area = $this->lotes->areaDoGeoJson(json_encode($d['geometry']));
        $max = (float) config('gis.lote_area_max_m2', 20000);
        if ($area <= $max) {
            return null;
        }
        $cadastro = (new \App\Cadastro\AreaOficial())->doCadastro((object) [
            'bairro' => $d['bairro'] ?? null, 'quadra' => $d['quadra'] ?? null, 'numero_lote' => $d['numero_lote'] ?? null,
        ]);
        if ($cadastro && abs($area / $cadastro - 1) <= 0.05) {
            return null;
        }

        return sprintf('O desenho tem %s m², acima de %s m² — tamanho de gleba. %sConfira se é mesmo um lote só antes de salvar.',
            number_format($area, 2, ',', '.'), number_format($max, 0, ',', '.'),
            $cadastro ? 'O cadastro informa ' . number_format($cadastro, 2, ',', '.') . ' m². ' : '');
    }

    /**
     * Grava. Devolve o lote criado.
     *
     * A área vem do BANCO, não do desenho: é a mesma régua dos 2.239 lotes que
     * vieram da importação, e comparar área de lote medida por motores
     * diferentes produziria diferença sistemática sem causa aparente.
     */
    public function aplicar(array $d, int $usuarioId, string $usuarioNome): Lote
    {
        $geojson = json_encode($d['geometry']);
        $area = $this->lotes->areaDoGeoJson($geojson);

        return DB::transaction(function () use ($d, $geojson, $area, $usuarioNome) {
            $atributos = [
                'bairro'      => $d['bairro'],
                'quadra'      => $d['quadra'],
                'numero_lote' => $d['numero_lote'],
                'chave'       => $d['bairro'] . '|' . $d['quadra'] . '|' . $d['numero_lote'],
                'inscricao_imobiliaria' => $d['inscricao_imobiliaria'] ?? null,
                'area_gis_m2' => round($area, 2),

                // AS MEDIDAS DA MATRÍCULA entram como vieram, sem conferência
                // que barre. A área do registro NÃO substitui `area_gis_m2`:
                // a multa por m² continua saindo do desenho, que é o que o
                // sistema pode aferir. Guardar as duas é o que permite,
                // depois, saber que elas discordam.
                'frente_m'          => $d['frente_m'] ?? null,
                'fundos_m'          => $d['fundos_m'] ?? null,
                'lado_direito_m'    => $d['lado_direito_m'] ?? null,
                'lado_esquerdo_m'   => $d['lado_esquerdo_m'] ?? null,
                'area_matricula_m2' => $d['area_matricula_m2'] ?? null,

                'fonte'       => 'Desenho manual — ' . $usuarioNome,
                'origem'      => 'desenho',
                'situacao'    => 'ativo',
            ] + ImportacaoDeBairro::emRevisaoNoBairro($d['bairro']);

            $id = $this->lotes->criarComGeometria($atributos, $geojson);

            // O INSERT foi cru, então não disparou o evento `created` do
            // Eloquent. A trilha é registrada à mão — `registrarAuditoria` é
            // público na trait exatamente para este caso.
            $lote = Lote::find($id);
            $lote->registrarAuditoria('desenhou', null, $atributos);

            return $lote;
        });
    }

    /**
     * Lotes que o desenho toca, com a área comum medida FORA do banco.
     *
     * @return list<array<string,mixed>>
     */
    private function vizinhos(string $geojson, string $bairro, ?int $ignorar = null): array
    {
        $anel = json_decode($geojson, true)['coordinates'][0] ?? [];
        if (! $anel) {
            return [];
        }

        $lat = $anel[0][1];
        $lon = $anel[0][0];
        $meu = GeometriaPlana::projetar($anel, $lat, $lon);

        $saida = [];
        foreach ($this->lotes->candidatosASobrepor($geojson, $bairro, $ignorar ? [$ignorar] : []) as $c) {
            if (! $c->anel) {
                continue;
            }
            $r = GeometriaPlana::areaComum($meu, GeometriaPlana::projetar($c->anel, $lat, $lon));
            $saida[] = [
                'id'          => $c->id,
                'quadra'      => $c->quadra,
                'lote'        => $c->numero_lote,
                'area_comum'  => round($r['area'], 2),
                'confiavel'   => $r['confiavel'],
            ];
        }

        usort($saida, fn ($a, $b) => $b['area_comum'] <=> $a['area_comum']);

        return $saida;
    }
}
