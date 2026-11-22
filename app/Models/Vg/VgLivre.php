<?php

namespace App\Models\Vg;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Item livre: assunto, observação e fotos, em qualquer etapa ou depois de concluída. */
class VgLivre extends Model
{
    protected $table = 'vg_livres';
    protected $guarded = [];

    protected function casts(): array { return ['depois' => 'boolean']; }

    public function vistoria(): BelongsTo { return $this->belongsTo(VgVistoria::class, 'vistoria_id'); }
    public function autor(): BelongsTo    { return $this->belongsTo(User::class, 'user_id'); }
    public function fotos(): HasMany      { return $this->hasMany(VgArquivo::class, 'livre_id'); }
}
