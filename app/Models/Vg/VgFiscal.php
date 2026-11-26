<?php

namespace App\Models\Vg;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fiscal relacionado à vistoria, com a assinatura copiada ao assinar. */
class VgFiscal extends Model
{
    protected $table = 'vg_fiscais';
    protected $guarded = [];

    protected function casts(): array { return ['assinada_em' => 'datetime']; }

    public function vistoria(): BelongsTo { return $this->belongsTo(VgVistoria::class, 'vistoria_id'); }
    public function usuario(): BelongsTo  { return $this->belongsTo(User::class, 'user_id'); }
}
