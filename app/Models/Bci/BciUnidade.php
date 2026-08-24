<?php

namespace App\Models\Bci;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma construção do imóvel.
 *
 * A ficha mostra ano, área construída e padrão — foi o que se pediu, e é o
 * que responde "o que está construído aí". Os pontos ficam gravados porque
 * são a origem do padrão no cadastro deles.
 */
class BciUnidade extends Model
{
    protected $table = 'bci_unidades';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['area_edificada_m2' => 'float'];
    }

    public function imovel(): BelongsTo { return $this->belongsTo(BciImovel::class, 'bci_imovel_id'); }
}
