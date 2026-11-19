<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma quadra marcada num ato do bairro (19/11/2026): `criou` (ampliação,
 * etapa — tira a quadra da aprovação) ou `alterou` (regularização,
 * retificação, alteração). Quadra sem marcação é da aprovação. Ver
 * App\Cadastro\DecretosDasQuadras.
 */
class BairroAtoQuadra extends Model
{
    use RegistraAuditoria;

    protected $table = 'bairro_ato_quadras';

    protected $fillable = ['bairro_ato_id', 'quadra', 'papel'];

    public function ato(): BelongsTo
    {
        return $this->belongsTo(BairroAto::class, 'bairro_ato_id');
    }

    protected function descricaoAuditoria(): ?string
    {
        return 'Quadra ' . $this->quadra . ' · ' . ($this->papel === 'criou' ? 'criada por ' : 'alterada por ')
            . ($this->ato?->ato ?? '#' . $this->bairro_ato_id);
    }
}
