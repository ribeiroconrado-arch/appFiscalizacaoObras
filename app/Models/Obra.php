<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Obra extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data_inicio' => 'date', 'area_construida' => 'float', 'area_terreno' => 'float'];
    }

    public const SITUACOES = [
        'nao_iniciada' => 'Nao iniciada',
        'em_andamento' => 'Em andamento',
        'paralisada'   => 'Paralisada',
        'concluida'    => 'Concluida',
        'embargada'    => 'Embargada',
        'irregular'    => 'Irregular',
    ];

    public function lote(): BelongsTo      { return $this->belongsTo(Lote::class); }
    public function vistorias(): HasMany   { return $this->hasMany(Vistoria::class); }

    public function responsavelTecnico(): BelongsTo
    {
        return $this->belongsTo(ResponsavelTecnico::class, 'responsavel_tecnico_id');
    }

    public function situacaoRotulo(): string
    {
        return self::SITUACOES[$this->situacao] ?? $this->situacao;
    }
}