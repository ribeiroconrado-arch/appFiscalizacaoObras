<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lote extends Model
{
    protected $table = 'lotes';
    protected $guarded = [];

    /**
     * Colunas seguras para o Eloquent.
     *
     * `geom` fica FORA de propósito: um `SELECT *` traria a geometria como
     * binário WKB, que estoura na serialização para JSON e ainda carrega
     * kilobytes por linha sem necessidade. Consulta espacial é assunto do
     * LoteRepository, que devolve GeoJSON quando o mapa precisa.
     */
    public const COLUNAS = [
        'id', 'bairro', 'quadra', 'numero_lote', 'chave',
        'inscricao_imobiliaria', 'area_gis_m2', 'fonte',
        'created_at', 'updated_at',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('sem_geometria', function (Builder $q) {
            $q->select(array_map(fn ($c) => 'lotes.' . $c, self::COLUNAS));
        });
    }

    public function vistorias(): HasMany
    {
        return $this->hasMany(Vistoria::class)->orderByDesc('data_hora');
    }

    public function obras(): HasMany
    {
        return $this->hasMany(Obra::class);
    }

    /** Rótulo curto usado em listas e títulos de modal. */
    public function rotulo(): string
    {
        return sprintf('Quadra %s · Lote %s', $this->quadra ?? '—', $this->numero_lote ?? '—');
    }
}
