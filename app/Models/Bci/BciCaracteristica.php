<?php

namespace App\Models\Bci;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um par chave/valor do quadro de características do BCI.
 * Sem timestamps: a linha é substituída inteira a cada consulta, e a data que
 * importa é a do imóvel (`consultado_em`).
 */
class BciCaracteristica extends Model
{
    protected $table = 'bci_caracteristicas';

    protected $guarded = [];

    public $timestamps = false;

    public function imovel(): BelongsTo { return $this->belongsTo(BciImovel::class, 'bci_imovel_id'); }
}
