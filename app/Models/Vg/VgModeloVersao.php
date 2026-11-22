<?php

namespace App\Models\Vg;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Versão publicada de um modelo. Imutável: a vistoria aponta para ela. */
class VgModeloVersao extends Model
{
    protected $table = 'vg_modelo_versoes';
    protected $guarded = [];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['definicao' => 'array', 'publicado_em' => 'datetime', 'versao' => 'integer'];
    }

    public function modelo(): BelongsTo { return $this->belongsTo(VgModelo::class, 'modelo_id'); }
}
