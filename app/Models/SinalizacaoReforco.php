<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O aviso de mais uma pessoa sobre algo que já estava sinalizado no imóvel.
 * Ver a migração 2026_10_23_000100_reforcos_da_sinalizacao.
 */
class SinalizacaoReforco extends Model
{
    protected $table = 'sinalizacao_reforcos';
    protected $guarded = [];

    public function sinalizacao(): BelongsTo
    {
        return $this->belongsTo(Sinalizacao::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
