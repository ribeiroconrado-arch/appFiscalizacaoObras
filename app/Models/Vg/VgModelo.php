<?php

namespace App\Models\Vg;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modelo de vistoria guiada — página de teste. Ver a migração `vistorias_guiadas_teste`. */
class VgModelo extends Model
{
    protected $table = 'vg_modelos';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rascunho' => 'array', 'ativo' => 'boolean', 'rascunho_alterado' => 'boolean', 'versao_atual' => 'integer'];
    }

    public function versoes(): HasMany { return $this->hasMany(VgModeloVersao::class, 'modelo_id'); }

    public function versaoVigente(): ?VgModeloVersao
    {
        return $this->versao_atual ? $this->versoes()->where('versao', $this->versao_atual)->first() : null;
    }
}
