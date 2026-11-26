<?php

namespace App\Models\Vg;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PDF anexado ao criar a vistoria, ou foto tirada nela. Fica no disco `private`. */
class VgArquivo extends Model
{
    protected $table = 'vg_arquivos';
    protected $guarded = [];

    protected function casts(): array { return ['depois' => 'boolean', 'tamanho' => 'integer']; }

    public function vistoria(): BelongsTo { return $this->belongsTo(VgVistoria::class, 'vistoria_id'); }
    public function livre(): BelongsTo    { return $this->belongsTo(VgLivre::class, 'livre_id'); }
}
