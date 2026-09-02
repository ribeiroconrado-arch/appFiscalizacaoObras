<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma construção desenhada dentro do lote.
 *
 * A área somada das edificações é a área construída SUGERIDA na vistoria. Ela
 * não substitui a aferida em campo: uma é o que está desenhado, a outra é o
 * que o fiscal mediu com trena, e quando divergem a divergência é o assunto.
 */
class Edificacao extends Model
{
    use RegistraAuditoria;

    protected $table = 'edificacoes';

    protected $fillable = ['lote_id', 'area_m2', 'descricao'];

    protected $casts = ['area_m2' => 'float'];

    /** Geometria nunca vai para a trilha: é um blob que não se lê. */
    protected array $auditoriaOculta = ['geom'];

    /**
     * O SELECT nunca traz `geom`.
     *
     * Mesma razão do escopo de `Lote`: um polígono por linha em listagem
     * carrega megabytes que ninguém lê, e o Eloquent não sabe desserializar o
     * binário do MySQL de qualquer forma. Quem precisa da geometria pede o
     * GeoJSON explicitamente (ver EdificacaoController).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('sem_geometria', function (Builder $q) {
            $q->select('edificacoes.id', 'edificacoes.lote_id', 'edificacoes.area_m2',
                'edificacoes.descricao', 'edificacoes.created_at', 'edificacoes.updated_at');
        });
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /** O que a trilha de auditoria mostra no lugar do id. */
    protected function descricaoAuditoria(): ?string
    {
        return trim(($this->descricao ?: 'Edificação') . ' — '
            . number_format((float) $this->area_m2, 2, ',', '.') . ' m²');
    }
}
