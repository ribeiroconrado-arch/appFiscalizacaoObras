<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um bem apreendido no Auto de Apreensão (Posturas). */
class DocumentoItem extends Model
{
    protected $table = 'documento_itens';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantidade' => 'float'];
    }

    public function documento(): BelongsTo { return $this->belongsTo(Documento::class); }

    /** "2 UN" — a quantidade sem casas quando é inteira. */
    public function quantidadeTexto(): string
    {
        $q = $this->quantidade;
        $n = abs($q - round($q)) < 0.005 ? number_format($q, 0, ',', '.') : number_format($q, 2, ',', '.');

        return trim($n . ' ' . ($this->unidade ?? ''));
    }
}
