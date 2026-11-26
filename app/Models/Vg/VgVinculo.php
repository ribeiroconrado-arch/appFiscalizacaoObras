<?php

namespace App\Models\Vg;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A que a vistoria está ligada: imóvel, pessoa, protocolo ou denúncia. */
class VgVinculo extends Model
{
    const TIPOS = ['imovel' => 'Imóvel', 'pessoa' => 'Pessoa', 'protocolo' => 'Protocolo', 'denuncia' => 'Denúncia'];

    protected $table = 'vg_vinculos';
    protected $guarded = [];

    protected function casts(): array { return ['depois' => 'boolean', 'ref_id' => 'integer']; }

    public function vistoria(): BelongsTo { return $this->belongsTo(VgVistoria::class, 'vistoria_id'); }
}
