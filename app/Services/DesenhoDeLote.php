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
     * O que a tela mostra antes de gravar.
     *
     * @return array<string,mixed>
     */
    public function retrato(array $d): array
    {
        $geojson = json_encode($d['geometry']);
        $area = $this->lotes->areaDoGeoJson($geojson);

        return [
            'area_m2'   => round($area, 2),
            'vertices'  => max(0, count($d['geometry']['coordinates'][0] ?? []) - 1),
            'bairro'    => $d['bairro'],
            'quadra'    => $d['quadra'],
            'lote'      => $d['numero_lote'],
            'vizinhos'  => $this->vizinhos($geojson, $d['bairro']),
        ];
    }

    /**
     * Devolve a mensagem do impedimento, ou null se pode gravar.
     *
     * A ordem importa: as provas baratas e as de formato vêm antes das
     * espaciais, para o usuário receber a mensagem mais específica possível em
     * vez da primeira que der errado.
     */
    public function impedimento(array $d): ?string
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
        $max = (float) config('gis.lote_area_max_m2', 20000);

        if ($area < $min) {
            return sprintf('O desenho tem %s m². Abaixo de %s m² não é lote urbano — '
                . 'provavelmente foi um toque acidental.',
                number_format($area, 2, ',', '.'), number_format($min, 0, ',', '.'));
        }
        if ($area > $max) {
            return sprintf('O desenho tem %s m². Acima de %s m² é gleba, não lote: '
                . 'área desse tamanho entra por aprovação de loteamento.',
                number_format($area, 2, ',', '.'), number_format($max, 0, ',', '.'));
        }

        // ── identificação livre ──
        //
        // Provado ANTES de o índice único estourar, para a mensagem dizer qual
        // lote já existe em vez de devolver uma QueryException crua.
        $existe = DB::table('lotes')->where('bairro', $d['bairro'])
            ->where('quadra', $d['quadra'])->where('numero_lote', $d['numero_lote'])
            ->where('situacao', 'ativo')->exists();

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
        $sobre = $this->vizinhos($geojson, $d['bairro']);
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

        return $avisos;
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
                'fonte'       => 'Desenho manual — ' . $usuarioNome,
                'origem'      => 'desenho',
                'situacao'    => 'ativo',
            ];

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
    private function vizinhos(string $geojson, string $bairro): array
    {
        $anel = json_decode($geojson, true)['coordinates'][0] ?? [];
        if (! $anel) {
            return [];
        }

        $lat = $anel[0][1];
        $lon = $anel[0][0];
        $meu = GeometriaPlana::projetar($anel, $lat, $lon);

        $saida = [];
        foreach ($this->lotes->candidatosASobrepor($geojson, $bairro) as $c) {
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
