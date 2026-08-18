<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Legislacao extends Model
{
    use RegistraAuditoria;

    protected $table = 'legislacoes';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }

    public function artigos(): HasMany
    {
        return $this->hasMany(Artigo::class);
    }

    public function scopeAtivas(Builder $q): Builder
    {
        return $q->where('ativa', true)->orderBy('nome');
    }

    /** "Lei Complementar 1/2023 — Código de Obras" */
    public function rotulo(): string
    {
        return trim($this->numero . ' — ' . $this->nome);
    }

    /**
     * Texto de ciência do documento, com o marcador {prazo} resolvido.
     *
     * O texto da notificação cita o prazo de CUMPRIMENTO, que varia por
     * documento — daí o marcador. O do auto cita o prazo de DEFESA, que é
     * fixo por lei e por isso pode ser inteiramente estático.
     */
    public function ciencia(string $tipo, ?int $prazoDias = null): ?string
    {
        $txt = in_array($tipo, Documento::COM_DEFESA, true)
            ? $this->ciencia_auto
            : $this->ciencia_notificacao;

        if (! $txt) {
            return null;
        }

        $prazo = $prazoDias === 0 ? 'de imediato' : 'no prazo de ' . $prazoDias . ' dias';

        return str_replace('{prazo}', $prazo, $txt);
    }
}
