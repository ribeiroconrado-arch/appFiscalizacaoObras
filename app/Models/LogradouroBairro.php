<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um vínculo rua↔bairro. Ver Logradouro. */
class LogradouroBairro extends Model
{
    protected $table = 'logradouro_bairros';

    protected $guarded = [];

    public function logradouro(): BelongsTo
    {
        return $this->belongsTo(Logradouro::class);
    }
}
