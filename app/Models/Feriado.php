<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feriado extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'date', 'recorrente' => 'boolean'];
    }

    public const TIPOS = [
        'nacional'     => 'Nacional',
        'estadual'     => 'Estadual',
        'municipal'    => 'Municipal',
        'facultativo'  => 'Ponto facultativo',
    ];

    /**
     * Todas as datas de feriado dentro de um intervalo de anos, já
     * expandindo os recorrentes para cada ano do intervalo.
     *
     * Usado por LavraturaService::somarDiasUteis() — precisa do conjunto
     * completo de datas, não do registro cru, porque um feriado recorrente
     * cadastrado uma vez (ex.: 25/12) tem de valer em todo exercício.
     *
     * @return array<string> datas em Y-m-d
     */
    public static function datasEntre(int $anoInicio, int $anoFim): array
    {
        $datas = [];

        foreach (static::all() as $f) {
            if (! $f->recorrente) {
                if ($f->data->year >= $anoInicio && $f->data->year <= $anoFim) {
                    $datas[] = $f->data->toDateString();
                }
                continue;
            }
            for ($ano = $anoInicio; $ano <= $anoFim; $ano++) {
                $datas[] = $f->data->setYear($ano)->toDateString();
            }
        }

        return array_unique($datas);
    }
}
