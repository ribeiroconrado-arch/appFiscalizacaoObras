<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResponsavelTecnico extends Model
{
    protected $table = 'responsaveis_tecnicos';
    protected $guarded = [];

    public function obras(): HasMany { return $this->hasMany(Obra::class); }

    /** "Joao da Silva - CREA 12345" */
    public function rotulo(): string
    {
        $reg = $this->registro ? sprintf(' - %s %s', $this->conselho ?? '', $this->registro) : '';
        return trim($this->nome . $reg);
    }
}