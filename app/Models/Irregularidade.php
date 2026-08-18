<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Irregularidade extends Model
{
    protected $table = 'irregularidades';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function scopeAtivas(Builder $q): Builder
    {
        return $q->where('ativo', true)->orderBy('ordem')->orderBy('descricao');
    }

    /**
     * Artigos que enquadram esta irregularidade.
     *
     * Irregularidade sem artigo é constatação que não vira documento: o
     * sistema bloqueia a lavratura. É por isso que a tela de legislação
     * mostra quantas ainda estão sem enquadramento.
     */
    public function artigos(): BelongsToMany
    {
        return $this->belongsToMany(Artigo::class, 'artigo_irregularidade')->withTimestamps();
    }

    /** Classe da tag "Modelo D" por gravidade. */
    public function gravidadeBadge(): string
    {
        return match ($this->gravidade) {
            'grave' => 'bd-in',
            'media' => 'bd-pe',
            default => 'bd-ok',
        };
    }
}
