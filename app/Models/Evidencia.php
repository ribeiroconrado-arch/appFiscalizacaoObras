<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evidencia extends Model
{
    use RegistraAuditoria;

    protected $table = 'evidencias';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data_hora' => 'datetime'];
    }

    public function vistoria(): BelongsTo { return $this->belongsTo(Vistoria::class); }
    public function autor(): BelongsTo    { return $this->belongsTo(User::class, 'criado_por'); }

    /**
     * Só o autor pode excluir a evidência já gravada — regra herdada do
     * AppPOSTURAS, onde **admin não é exceção**: a regra é de autoria, não de
     * perfil. Quem lavra responde pelo que anexou; ninguém apaga a prova
     * registrada por outro fiscal.
     */
    public function podeSerExcluidaPor(User $u): bool
    {
        return $this->criado_por !== null && $this->criado_por === $u->id;
    }
}
