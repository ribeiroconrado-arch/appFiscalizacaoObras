<?php

namespace App\Models\Vg;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Uma vistoria guiada — página de teste. */
class VgVistoria extends Model
{
    protected $table = 'vg_vistorias';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'respostas' => 'array', 'iniciada_em' => 'datetime', 'concluida_em' => 'datetime',
            'gps_lat' => 'float', 'gps_lng' => 'float', 'mapa_lat' => 'float', 'mapa_lng' => 'float', 'gps_precisao' => 'integer',
        ];
    }

    public function versao(): BelongsTo   { return $this->belongsTo(VgModeloVersao::class, 'versao_id'); }
    public function autor(): BelongsTo    { return $this->belongsTo(User::class, 'user_id'); }
    public function arquivos(): HasMany   { return $this->hasMany(VgArquivo::class, 'vistoria_id'); }
    public function livres(): HasMany     { return $this->hasMany(VgLivre::class, 'vistoria_id'); }

    public function concluida(): bool { return $this->estado === 'concluida'; }
}
