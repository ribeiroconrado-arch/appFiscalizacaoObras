<?php

namespace App\Cadastro;

use App\Models\BairroAto;
use App\Models\BairroAtoQuadra;
use App\Models\CadastroBairro;
use App\Support\NumeroDoLote;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QUE DECRETO CRIOU (E QUAIS ALTERARAM) CADA QUADRA — 19/11/2026.
 *
 * A regra dele: toda quadra é da APROVAÇÃO do loteamento até que um decreto
 * posterior (ampliação, etapa) a marque como sua. Loteamento de um decreto só
 * já está pronto sem marcar nada. Só as exceções ficam gravadas
 * (bairro_ato_quadras); o padrão é calculado na leitura, e por isso vale
 * também para quadra criada depois.
 *
 * O lote não guarda nada: herda da quadra dele.
 */
final class DecretosDasQuadras
{
    /** Os tipos de ato que CRIAM quadra e os que só a ALTERAM. Revogação não marca quadra. */
    public const CRIAM = ['aprovacao', 'ampliacao', 'etapa'];

    public const ALTERAM = ['regularizacao', 'retificacao', 'alteracao'];

    public static function papelDoTipo(string $tipo): ?string
    {
        return in_array($tipo, self::CRIAM, true) ? 'criou' : (in_array($tipo, self::ALTERAM, true) ? 'alterou' : null);
    }

    /** O bairro do cadastro pelo nome do desenho (lotes.bairro), ou null. */
    public function bairro(string $nomeDoDesenho): ?CadastroBairro
    {
        return CadastroBairro::where('nome_gis', $nomeDoDesenho)->first();
    }

    /**
     * Os atos do bairro que marcam quadra e as marcações.
     *
     * `padrao` é o id da aprovação mais antiga: dona de toda quadra sem
     * marcação de `criou`. `criou` é quadra => id do ato; `alterou`,
     * quadra => [ids].
     *
     * @return array{padrao:?int, atos:list<array<string,mixed>>, criou:array<string,int>, alterou:array<string,list<int>>}
     */
    public function doBairro(?CadastroBairro $b): array
    {
        $vazio = ['padrao' => null, 'atos' => [], 'criou' => [], 'alterou' => []];
        if (! $b) {
            return $vazio;
        }

        $atos = $b->atos()->get()->filter(fn (BairroAto $a) => self::papelDoTipo($a->tipo) !== null)->values();
        $padrao = $atos->first(fn (BairroAto $a) => $a->tipo === 'aprovacao');

        $criou = [];
        $alterou = [];
        $ids = $atos->pluck('id')->all();
        if ($ids) {
            foreach (DB::table('bairro_ato_quadras')->whereIn('bairro_ato_id', $ids)->orderBy('id')->get() as $m) {
                if ($m->papel === 'criou') {
                    $criou[$m->quadra] = (int) $m->bairro_ato_id;
                } else {
                    $alterou[$m->quadra][] = (int) $m->bairro_ato_id;
                }
            }
        }

        return [
            'padrao'  => $padrao?->id,
            'atos'    => $atos->map(fn (BairroAto $a) => [
                'id'      => $a->id,
                'tipo'    => $a->tipo,
                'rotulo'  => BairroAto::TIPOS[$a->tipo] ?? $a->tipo,
                'ato'     => $a->ato,
                'data'    => $a->data?->format('d/m/Y'),
                'area_m2' => $a->area_m2,
                'papel'   => self::papelDoTipo($a->tipo),
            ])->all(),
            'criou'   => $criou,
            'alterou' => $alterou,
        ];
    }

    /**
     * A camada "Etapas dos loteamentos" (20/11/2026): as quadras de todo
     * bairro com MAIS DE UM ato que cria quadra, cada uma com quem a criou.
     * Bairro de decreto único fica de fora: não há etapa a separar.
     *
     * @param  callable(string):bool  $visivel  o bairro do desenho vai ao mapa de quem pede?
     * @return array{bairros:array<string,array<string,mixed>>, type:string, features:list<array<string,mixed>>}
     */
    public function etapasDaCidade(callable $visivel): array
    {
        $bairros = [];
        $cadastros = CadastroBairro::whereNotNull('nome_gis')->where('nome_gis', '<>', '')->get();
        foreach ($cadastros as $b) {
            if (! $visivel($b->nome_gis)) {
                continue;
            }
            $d = $this->doBairro($b);
            if (count(array_filter($d['atos'], fn ($a) => $a['papel'] === 'criou')) < 2) {
                continue;
            }
            $bairros[$b->nome_gis] = $d;
        }

        $features = [];
        if ($bairros) {
            $marca = implode(',', array_fill(0, count($bairros), '?'));
            $quadras = DB::select("SELECT bairro, numero, lotes_contados, rotulo_lat, rotulo_lon,
                                          ST_Area(geom) AS area, ST_AsGeoJSON(geom, 6) AS geojson
                                     FROM quadras WHERE bairro IN ({$marca})", array_keys($bairros));
            foreach ($quadras as $q) {
                $d = $bairros[$q->bairro];
                $n = NumeroDoLote::normalizar((string) $q->numero) ?? (string) $q->numero;
                $marcado = $d['criou'][$n] ?? null;
                $features[] = [
                    'type'       => 'Feature',
                    'geometry'   => json_decode($q->geojson, true),
                    'properties' => [
                        'bairro'    => $q->bairro,
                        'quadra'    => $n,
                        'criou'     => $marcado ?? $d['padrao'],
                        'presumido' => $marcado === null,
                        'alterou'   => $d['alterou'][$n] ?? [],
                        'area'      => round((float) $q->area, 2),
                        'lotes'     => (int) $q->lotes_contados,
                        'rotulo'    => [(float) $q->rotulo_lat, (float) $q->rotulo_lon],
                    ],
                ];
            }
        }

        // Só o que a camada usa: a lista dos atos e o padrão.
        $bairros = array_map(fn ($d) => ['padrao' => $d['padrao'], 'atos' => $d['atos']], $bairros);

        return ['bairros' => $bairros, 'type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * Substitui as marcações do bairro pelas da tela. `criou` apontando para a
     * aprovação padrão não é gravado (é o próprio padrão). Cada ato precisa ser
     * do bairro e ter o papel certo.
     *
     * @param  array<string,int>        $criou    quadra => id do ato
     * @param  array<string,list<int>>  $alterou  quadra => ids
     */
    public function gravar(CadastroBairro $b, array $criou, array $alterou): void
    {
        $dados = $this->doBairro($b);
        $papelDe = [];
        foreach ($dados['atos'] as $a) {
            $papelDe[$a['id']] = $a['papel'];
        }

        $quer = [];   // "ato|quadra" => papel
        foreach ($criou as $q => $id) {
            $q = NumeroDoLote::normalizar((string) $q);
            $id = (int) $id;
            if ($q === null || $q === '') {
                continue;
            }
            if (($papelDe[$id] ?? null) !== 'criou') {
                throw ValidationException::withMessages(['criou' => 'Ato que não cria quadra deste bairro.']);
            }
            if ($id !== $dados['padrao']) {
                $quer[$id . '|' . $q] = 'criou';
            }
        }
        foreach ($alterou as $q => $ids) {
            $q = NumeroDoLote::normalizar((string) $q);
            if ($q === null || $q === '') {
                continue;
            }
            foreach ((array) $ids as $id) {
                $id = (int) $id;
                if (($papelDe[$id] ?? null) !== 'alterou') {
                    throw ValidationException::withMessages(['alterou' => 'Ato que não altera quadra deste bairro.']);
                }
                $quer[$id . '|' . $q] = 'alterou';
            }
        }

        DB::transaction(function () use ($papelDe, $quer) {
            $existentes = BairroAtoQuadra::whereIn('bairro_ato_id', array_keys($papelDe))->get();
            foreach ($existentes as $m) {
                $k = $m->bairro_ato_id . '|' . $m->quadra;
                if (isset($quer[$k])) {
                    unset($quer[$k]);      // já está gravada
                } else {
                    $m->delete();
                }
            }
            foreach ($quer as $k => $papel) {
                [$id, $q] = explode('|', $k, 2);
                BairroAtoQuadra::create(['bairro_ato_id' => (int) $id, 'quadra' => $q, 'papel' => $papel]);
            }
        });
    }
}
