<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um dia de trabalho marcado numa Ordem de Serviço, com o horário dele.
 *
 * Existe como LINHA porque o número de dias não se sabe de antemão e cada dia
 * tem horário próprio: "dia 12 das 8h às 12h; dia 19 das 14h às 18h" não cabe
 * num par de colunas sem inventar um intervalo que ninguém combinou.
 */
class OsJornada extends Model
{
    protected $table = 'os_jornadas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class);
    }

    /** "12/09/2026, das 08:00 às 12:00" — ou só a data, quando não há hora. */
    public function rotulo(): string
    {
        $dia = $this->data->format('d/m/Y');

        $ini = $this->hora_inicio ? substr($this->hora_inicio, 0, 5) : null;
        $fim = $this->hora_fim ? substr($this->hora_fim, 0, 5) : null;

        // Sem hora é ordem legítima ("dia 12"), e diferente de "o dia inteiro".
        if (! $ini && ! $fim) { return $dia; }
        if ($ini && $fim)     { return "{$dia}, das {$ini} às {$fim}"; }

        return $ini ? "{$dia}, a partir das {$ini}" : "{$dia}, até às {$fim}";
    }
}
