<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rua, pelo nome (em maiúsculas), e os bairros por onde ela passa — a
 * relação rua↔bairro do Posturas. Escolhida a rua, o formulário sugere os
 * bairros dela; com um só, preenche sozinho.
 */
class Logradouro extends Model
{
    protected $guarded = [];

    public function vinculos(): HasMany
    {
        return $this->hasMany(LogradouroBairro::class);
    }

    /** O nome como a tabela guarda: sem espaço sobrando, em maiúsculas. */
    public static function normalizar(?string $nome): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string) $nome)));
    }

    /** @return list<string> os bairros vinculados, em ordem alfabética */
    public function bairros(): array
    {
        return $this->vinculos()->orderBy('bairro')->pluck('bairro')->all();
    }
}
